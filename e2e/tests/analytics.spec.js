import { expect, test } from '@playwright/test';
import { ANALYTICS_BASE_URL, configEntry, dataLayer, eventEntries, stubGoogle } from './analytics-helpers.js';

test.describe('計測の設定がない環境（既定の app-e2e）', () => {
    test('どの画面でも Google へ通信しない', async ({ context, page }) => {
        const requests = await stubGoogle(context);

        for (const path of ['/', '/forecast?lat=27.75&lon=129.05', '/forecast?lat=abc&lon=1', '/no-such-page']) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');
        }

        expect(requests).toEqual([]);
    });
});

test.describe('計測が有効な環境（app-e2e-analytics）', () => {
    test.skip(!ANALYTICS_BASE_URL, 'E2E_ANALYTICS_BASE_URL が未設定');
    test.use({ baseURL: ANALYTICS_BASE_URL });

    test('入力文字列は page_location にも page_referrer にも入らない', async ({ context, page }) => {
        await stubGoogle(context);

        await page.goto('/forecast?lat=北緯二十七度&lon=129.05');
        await page.locator('.site-title a').click();
        await expect(page).toHaveURL(/\/$/);
        await page.waitForFunction(() => window.dataLayer?.length > 0);

        const origin = new URL(page.url()).origin;
        const config = await configEntry(page);
        expect(config[2].page_location).toBe(`${origin}/`);
        expect(config[2].page_referrer).toBe(`${origin}/forecast`);
        expect(config[2].screen_type).toBe('home');

        const layer = await dataLayer(page);
        expect(layer).not.toContain('北緯');
        expect(layer).not.toContain('%E5%8C%97');
    });

    test('予報画面は表示した地点と画面の種類を送り、gtag.js を 1 回だけ読み込む', async ({ context, page }) => {
        const requests = await stubGoogle(context);

        await page.goto('/forecast?lat=27.75&lon=129.05');
        await page.waitForFunction(() => window.dataLayer?.length > 0);

        const origin = new URL(page.url()).origin;
        const config = await configEntry(page);
        expect(config[2].page_location).toBe(`${origin}/forecast?lat=27.75&lon=129.05`);
        expect(config[2].screen_type).toBe('forecast');
        expect(config[2].allow_google_signals).toBe(false);
        expect(requests.filter((url) => url.includes('gtag/js?id=G-E2ETEST000'))).toHaveLength(1);
    });

    test('Google への通信が遮断されても、予報・お気に入り・フィードバックの導線は動く', async ({ context, page }) => {
        await stubGoogle(context, { blockAll: true });

        await page.goto('/forecast?lat=27.75&lon=129.05');
        await expect(page.locator('table tbody tr')).toHaveCount(9);

        await page.getByLabel('名前（省略可・30 文字以内）').fill('奄美沖');
        await page.getByRole('button', { name: 'お気に入りに保存' }).click();
        await expect(page.getByText('お気に入りに保存しました')).toBeVisible();

        await page.getByRole('link', { name: 'フィードバック' }).click();
        await expect(page).toHaveURL(/\/feedback/);
    });
});

test.describe('操作のイベント（app-e2e-analytics）', () => {
    test.skip(!ANALYTICS_BASE_URL, 'E2E_ANALYTICS_BASE_URL が未設定');
    test.use({ baseURL: ANALYTICS_BASE_URL });

    test('お気に入りの保存・削除は地点も名前も含めずに送る', async ({ context, page }) => {
        await stubGoogle(context);
        await page.goto('/forecast?lat=27.75&lon=129.05');
        await page.getByLabel('名前（省略可・30 文字以内）').fill('奄美沖');
        await page.getByRole('button', { name: 'お気に入りに保存' }).click();
        await expect(page.getByText('お気に入りに保存しました')).toBeVisible();

        // 確認ダイアログで取り消したときは、削除のイベントを送らない
        page.once('dialog', (dialog) => dialog.dismiss());
        await page.getByRole('button', { name: 'お気に入りから外す' }).click();
        expect((await eventEntries(page)).filter((entry) => entry[1] === 'favorite_delete')).toHaveLength(0);

        page.once('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'お気に入りから外す' }).click();
        await expect(page.getByText('お気に入りから削除しました')).toBeVisible();

        const events = await eventEntries(page);
        expect(events.filter((entry) => entry[1] === 'favorite_save')).toHaveLength(1);
        expect(events.filter((entry) => entry[1] === 'favorite_delete')).toHaveLength(1);
        const serialized = JSON.stringify(events);
        for (const forbidden of ['奄美沖', '27.75', '129.05']) {
            expect(serialized).not.toContain(forbidden);
        }
    });

    test('フィードバックのフォームを開く操作を送る', async ({ context, page }) => {
        await stubGoogle(context);
        // 外部のフォームへの遷移は外へ出さない
        await context.route('https://forms.example.test/**', (route) => route.abort());
        await page.goto('/feedback?lat=27.75&lon=129.05');
        await page.waitForFunction(() => window.dataLayer?.length > 0);

        const popup = context.waitForEvent('page');
        await page.getByRole('link', { name: 'フィードバックのフォームを開く（新しいタブ）' }).click();
        await (await popup).close();

        expect((await eventEntries(page)).filter((entry) => entry[1] === 'feedback_form_open')).toHaveLength(1);
    });
});

test.describe('計測を止める切り替え（app-e2e-analytics）', () => {
    test.skip(!ANALYTICS_BASE_URL, 'E2E_ANALYTICS_BASE_URL が未設定');
    test.use({ baseURL: ANALYTICS_BASE_URL });

    const googleRequests = (requests) => requests.filter((url) => url.includes('gtag/js'));

    test('計測しないに切り替えると以降の画面で Google へ通信せず、再開すると次の画面から通信する', async ({ context, page }) => {
        const requests = await stubGoogle(context);

        await page.goto('/');
        await page.getByRole('link', { name: '外部送信について' }).click();
        await expect(page).toHaveURL(/\/external-transmission$/);
        await expect(page.getByText('現在：このブラウザでは計測しています')).toBeVisible();

        await page.getByRole('button', { name: 'このブラウザでは計測しない' }).click();
        await expect(page.getByText('このブラウザでは計測しないように設定しました。')).toBeVisible();
        await expect(page.getByRole('button', { name: '計測を再開する' })).toBeVisible();
        expect(await page.evaluate(() => window['ga-disable-G-E2ETEST000'])).toBe(true);

        // 停止後に開く画面は、gtag.js を読み込まず dataLayer も作らない（SC-008）
        requests.length = 0;
        for (const path of ['/', '/forecast?lat=27.75&lon=129.05', '/feedback']) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');
            expect(await page.evaluate(() => typeof window.dataLayer)).toBe('undefined');
        }
        expect(requests).toEqual([]);

        // 止めていてもお気に入りは従来どおり動く
        await page.goto('/forecast?lat=27.75&lon=129.05');
        await page.getByRole('button', { name: 'お気に入りに保存' }).click();
        await expect(page.getByText('お気に入りに保存しました')).toBeVisible();

        await page.goto('/external-transmission');
        await expect(page.getByText('現在：このブラウザでは計測していません')).toBeVisible();

        await page.getByRole('button', { name: '計測を再開する' }).click();
        await expect(page.getByText('計測を再開しました。次に開いた画面から計測されます。')).toBeVisible();

        await page.goto('/');
        await page.waitForFunction(() => window.dataLayer?.length > 0);
        expect(googleRequests(requests)).toHaveLength(1);
    });

    test('localStorage が使えないときは保存できない旨を出し、予報は閲覧できる', async ({ context, page }) => {
        await stubGoogle(context);
        await page.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', {
                get() {
                    throw new DOMException('denied', 'SecurityError');
                },
            });
        });

        await page.goto('/external-transmission');
        await expect(page.getByText('このブラウザでは切り替えを保存できません。上記のアドオンをご利用ください。')).toBeVisible();
        await expect(page.getByRole('button', { name: /計測/ })).toHaveCount(0);

        await page.goto('/forecast?lat=27.75&lon=129.05');
        await expect(page.locator('table tbody tr')).toHaveCount(9);
    });

    test('JavaScript が無効なときは切り替えが見えず、アドオンの案内は読める', async ({ browser }) => {
        const context = await browser.newContext({ baseURL: ANALYTICS_BASE_URL, javaScriptEnabled: false });
        const page = await context.newPage();

        await page.goto('/external-transmission');

        await expect(page.locator('[data-analytics-optout]')).toBeHidden();
        await expect(page.getByRole('link', { name: /オプトアウト アドオン/ })).toBeVisible();
        await context.close();
    });
});

test.describe('計測を止めた後に残っている画面（app-e2e-analytics）', () => {
    test.skip(!ANALYTICS_BASE_URL, 'E2E_ANALYTICS_BASE_URL が未設定');
    test.use({ baseURL: ANALYTICS_BASE_URL });

    // bfcache からの復元や別タブのように、止める前から開いていた画面は、読み込み時の状態のままになる
    test('開いたままの画面で操作しても、停止後はイベントを送らない', async ({ context, page }) => {
        await stubGoogle(context);
        await page.goto('/forecast?lat=27.75&lon=129.05');
        await page.waitForFunction(() => window.dataLayer?.length > 0);

        await page.evaluate(() => window.localStorage.setItem('umiyomi.analytics.optOut', '1'));
        await page.getByRole('button', { name: 'お気に入りに保存' }).click();
        await expect(page.getByText('お気に入りに保存しました')).toBeVisible();

        expect(await eventEntries(page)).toEqual([]);
    });
});

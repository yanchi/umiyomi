import { expect, test } from '@playwright/test';
import { ANALYTICS_BASE_URL, eventEntries, stubGoogle } from './analytics-helpers.js';

// 現在地ボタンは Geolocation が使える安全なオリジン（HTTPS）でしか出ない。app-e2e-analytics は http なので、
// 起動オプション（--unsafely-treat-insecure-origin-as-secure）や isSecureContext の上書きでは位置の取得まで通らなかった。
// そこで https の仮のオリジンを作り、app-e2e-analytics へ転送して返す（skip にすると現在地のイベントを確かめられないため）
const SECURE_ORIGIN = 'https://umiyomi-e2e.test';

test.skip(!ANALYTICS_BASE_URL, 'E2E_ANALYTICS_BASE_URL が未設定');

const newContext = async (browser, options = {}) => {
    const context = await browser.newContext({ baseURL: SECURE_ORIGIN, ...options });
    await context.route(`${SECURE_ORIGIN}/**`, async (route) => {
        const response = await route.fetch({ url: route.request().url().replace(SECURE_ORIGIN, ANALYTICS_BASE_URL) });
        await route.fulfill({ response });
    });

    return context;
};

const locationEvents = async (page) => (await eventEntries(page)).filter((entry) => entry[1] === 'current_location');

test('成功は result だけを送り、取得した位置は送らない', async ({ browser }) => {
    const context = await newContext(browser, {
        permissions: ['geolocation'],
        geolocation: { latitude: 35.1, longitude: 139.2 },
    });
    await stubGoogle(context);
    const page = await context.newPage();
    await page.goto('/');
    await page.waitForFunction(() => window.dataLayer?.length > 0);

    await page.locator('[data-current-location-action="fill"]').first().click();
    await expect(page.locator('input[name="lat"]').first()).not.toHaveValue('');

    expect(await locationEvents(page)).toEqual([['event', 'current_location', { result: 'success' }]]);
    const serialized = JSON.stringify(await eventEntries(page));
    expect(serialized).not.toContain('35.1');
    expect(serialized).not.toContain('139.2');
    await context.close();
});

test('許可しないと failure を送る', async ({ browser }) => {
    const context = await newContext(browser, { permissions: [] });
    await stubGoogle(context);
    const page = await context.newPage();
    await page.goto('/');
    await page.waitForFunction(() => window.dataLayer?.length > 0);

    await page.locator('[data-current-location-action="fill"]').first().click();

    await expect.poll(() => locationEvents(page)).toEqual([['event', 'current_location', { result: 'failure' }]]);
    await context.close();
});

test('Google への通信が遮断されても、入力欄に現在地が入る', async ({ browser }) => {
    const context = await newContext(browser, {
        permissions: ['geolocation'],
        geolocation: { latitude: 35.1, longitude: 139.2 },
    });
    await stubGoogle(context, { blockAll: true });
    const page = await context.newPage();
    await page.goto('/');

    await page.locator('[data-current-location-action="fill"]').first().click();

    await expect(page.locator('input[name="lat"]').first()).not.toHaveValue('');
    await context.close();
});

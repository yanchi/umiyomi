import { expect, test } from '@playwright/test';

// トップに本文を足しても、入力欄が欠けずに最初の画面に収まること（007 SC-006）。見え方は実ブラウザでしか確かめられない
async function expectFormInFirstView(page) {
    await page.goto('/');

    await expect(page.getByLabel('緯度')).toBeInViewport({ ratio: 1 });
    await expect(page.getByLabel('経度')).toBeInViewport({ ratio: 1 });
    await expect(page.getByRole('button', { name: '予報を表示' })).toBeInViewport({ ratio: 1 });
}

test('トップを開いた直後、スクロールせずに入力欄と「予報を表示」が見える', async ({ page }) => {
    await expectFormInFirstView(page);
});

test('トップの本文は入力欄・お気に入りの後ろに表示される', async ({ page }) => {
    await page.goto('/');

    const heading = page.getByRole('heading', { level: 2, name: 'UMIYOMI でできること' });
    await heading.scrollIntoViewIfNeeded();
    await expect(heading).toBeVisible();
});

test.describe('375×667 の画面', () => {
    test.use({ viewport: { width: 375, height: 667 } });

    test('スクロールせずに入力欄と「予報を表示」が見える', async ({ page }) => {
        await expectFormInFirstView(page);
    });
});

import { expect, test } from '@playwright/test';

test('緯度経度を入力すると時間別の予報が表示される', async ({ page }) => {
    await page.goto('/');
    await page.getByLabel('緯度').fill('27.75');
    await page.getByLabel('経度').fill('129.05');
    await page.getByRole('button', { name: '予報を表示' }).click();

    await expect(page).toHaveURL(/\/forecast\?lat=27\.75&lon=129\.05/);
    await expect(page.locator('body')).toContainText('最終更新：2026/10/05 20:15');
    await expect(page.locator('table tbody tr')).toHaveCount(9);
});

test('範囲外の緯度はエラーが表示される', async ({ page }) => {
    await page.goto('/forecast?lat=95&lon=129.05');

    await expect(page.locator('#lat-error')).toContainText('緯度は -90〜90 の範囲で入力してください');
});

test('スマホ幅でも横スクロールが出ない', async ({ page }) => {
    await page.goto('/forecast?lat=27.75&lon=129.05');

    const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    expect(overflows).toBe(false);
});

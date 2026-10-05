import { expect, test } from '@playwright/test';

// お気に入りは JavaScript が localStorage に保存するので、実ブラウザでしか確かめられない
test('予報画面で保存したお気に入りがトップの一覧に出て、削除できる', async ({ page }) => {
    await page.goto('/forecast?lat=27.75&lon=129.05');
    await page.getByLabel('名前（省略可・30 文字以内）').fill('奄美沖');
    await page.getByRole('button', { name: 'お気に入りに保存' }).click();

    await expect(page.getByText('お気に入りに保存しました')).toBeVisible();
    await expect(page.getByText('お気に入りに保存済み：奄美沖')).toBeVisible();

    await page.reload();
    await expect(page.getByText('お気に入りに保存済み：奄美沖')).toBeVisible();

    await page.goto('/');
    await expect(page.locator('[data-favorites="manage-list"] [data-favorites-items]')).toContainText('奄美沖');

    await page.goto('/forecast?lat=27.75&lon=129.05');
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'お気に入りから外す' }).click();
    await expect(page.getByText('お気に入りから削除しました')).toBeVisible();
});

test('localStorage が使えなくても予報は閲覧できる', async ({ page }) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', {
            get() {
                throw new DOMException('denied', 'SecurityError');
            },
        });
    });
    await page.goto('/forecast?lat=27.75&lon=129.05');

    await expect(page.locator('table tbody tr')).toHaveCount(9);
});

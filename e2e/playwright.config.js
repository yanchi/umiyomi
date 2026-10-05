import { defineConfig, devices } from '@playwright/test';

// ベース URL は compose.yaml の e2e サービスが渡す。ブラウザから見るのは app-e2e（Fake Provider の test 環境）で、外部 API は呼ばない
export default defineConfig({
    testDir: './tests',
    // お気に入りは localStorage で、テストごとに新しいコンテキストになるので並列でも干渉しない
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://app-e2e',
        locale: 'ja-JP',
        timezoneId: 'Asia/Tokyo',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        // 出航前にスマホで見ることが多いので、スマホ幅でも同じテストを通す
        { name: 'mobile', use: { ...devices['iPhone 13'], browserName: 'chromium' } },
    ],
});

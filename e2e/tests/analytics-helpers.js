// analytics*.spec.js の共通部品。Google への通信を外へ出さないための準備と、dataLayer の取り出し
export const ANALYTICS_BASE_URL = process.env.E2E_ANALYTICS_BASE_URL;
const GOOGLE_HOSTS = /^https:\/\/([a-z0-9-]+\.)*(googletagmanager|google-analytics|doubleclick)\.(com|net)\//;

// Google へは一切通信させない。gtag.js は空のスタブで返し、それ以外（計測の送信）は遮断する。
// 実際に送られる内容は dataLayer で確かめ、テストが本物のプロパティを汚さないようにするため
export const stubGoogle = async (context, { blockAll = false } = {}) => {
    const requests = [];
    await context.route(GOOGLE_HOSTS, (route) => {
        const url = route.request().url();
        requests.push(url);
        if (!blockAll && url.includes('/gtag/js')) {
            return route.fulfill({ status: 200, contentType: 'text/javascript', body: '// stub' });
        }

        return route.abort();
    });

    return requests;
};

export const dataLayer = (page) =>
    page.evaluate(() => JSON.stringify(Array.from(window.dataLayer ?? [], (entry) => Array.from(entry))));

export const configEntry = async (page) => {
    const entries = JSON.parse(await dataLayer(page));

    return entries.find((entry) => entry[0] === 'config');
};

export const eventEntries = async (page) => JSON.parse(await dataLayer(page)).filter((entry) => entry[0] === 'event');

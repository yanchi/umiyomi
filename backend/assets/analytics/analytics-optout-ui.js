// 外部送信の案内画面の「計測を止める」切り替え。規則は analytics-rules.js、計測の停止は analytics.js に任せ、ここは表示とイベントだけを持つ。
// 文言は Twig に置き、ここでは hidden を切り替えるだけにする（favorites-ui.js・coordinate-input-ui.js と同じ流儀）
import { readOptOut, writeOptOut } from './analytics-rules.js';
import { stopTracking } from './analytics.js';

const show = (region, attribute, name) => {
    for (const element of region.querySelectorAll(`[${attribute}]`)) {
        element.hidden = element.getAttribute(attribute) !== name;
    }
};

const showAction = (region, name) => {
    for (const button of region.querySelectorAll('[data-analytics-optout-action]')) {
        button.hidden = button.dataset.analyticsOptoutAction !== name;
    }
};

const getStorage = () => {
    try {
        return window.localStorage ?? null;
    } catch {
        return null;
    }
};

export const initAnalyticsOptOut = (doc) => {
    const region = doc.querySelector('[data-analytics-optout]');
    if (region === null) {
        return;
    }
    region.hidden = false;

    const storage = getStorage();
    if (storage === null) {
        show(region, 'data-analytics-optout-state', 'unavailable');
        showAction(region, null);

        return;
    }

    const render = (optedOut) => {
        show(region, 'data-analytics-optout-state', optedOut ? 'stopped' : 'measuring');
        showAction(region, optedOut ? 'resume' : 'stop');
    };
    render(readOptOut(storage));

    const unavailable = () => {
        show(region, 'data-analytics-optout-state', 'unavailable');
        show(region, 'data-analytics-optout-message', null);
        showAction(region, null);
    };

    region.addEventListener('click', (event) => {
        const button = event.target.closest('[data-analytics-optout-action]');
        if (button === null) {
            return;
        }

        const stopping = button.dataset.analyticsOptoutAction === 'stop';
        if (!writeOptOut(storage, stopping)) {
            unavailable();

            return;
        }
        if (stopping) {
            // この画面で送っているものも止める。再開は次に開いた画面から（その場では gtag を読み込まない）
            stopTracking();
        }
        render(stopping);
        show(region, 'data-analytics-optout-message', stopping ? 'stopped' : 'resumed');
        region.querySelector(`[data-analytics-optout-action="${stopping ? 'resume' : 'stop'}"]`)?.focus();
    });
};

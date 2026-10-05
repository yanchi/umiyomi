import { eventPayload, pageLocation, pageReferrer, readOptOut } from './analytics-rules.js';

const GTAG_URL = 'https://www.googletagmanager.com/gtag/js?id=';

// 計測を始めていないとき（<meta> がない場合・計測を止めている場合）は track() を何もしない状態にする
let tracking = false;
let measurementId = null;

// localStorage へのアクセスは、プライベートモードなどで例外になるため、取得も含めて包む
const optedOut = () => {
    try {
        return readOptOut(window.localStorage);
    } catch {
        return false;
    }
};

// <meta name="umiyomi-analytics"> があるときだけ、gtag.js を読み込んで閲覧を送る。
// 読み込みに失敗（ブロックされた場合など）しても、画面の機能には影響させない
export const initAnalytics = (doc) => {
    const meta = doc.querySelector('meta[name="umiyomi-analytics"]');
    if (meta === null) {
        return;
    }

    const location = pageLocation(window.location.origin, meta.dataset.path);
    if (location === null) {
        return;
    }

    const id = meta.dataset.measurementId;

    // 止めている利用者には、gtag.js も dataLayer も作らない（SC-008）。ga-disable は念のための Google 側の停止
    if (optedOut()) {
        window[`ga-disable-${id}`] = true;

        return;
    }

    measurementId = id;
    window.dataLayer = window.dataLayer || [];
    // gtag.js は配列ではなく arguments オブジェクトを前提にするため、arguments をそのまま積む
    window.gtag = function () {
        window.dataLayer.push(arguments);
    };

    const config = {
        page_location: location,
        screen_type: meta.dataset.screen,
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
    };
    const referrer = pageReferrer(doc.referrer, window.location.origin);
    if (referrer !== null) {
        config.page_referrer = referrer;
    }

    window.gtag('js', new Date());
    window.gtag('config', id, config);

    const script = doc.createElement('script');
    script.async = true;
    script.src = GTAG_URL + encodeURIComponent(id);
    doc.head.appendChild(script);

    tracking = true;
    // 操作のイベントは、許可リストで縛った名前だけを属性から受け取る（eventPayload が名前を検査する）
    doc.addEventListener('click', (event) => {
        const source = event.target.closest('[data-analytics-click]');
        if (source !== null) {
            track(source.dataset.analyticsClick);
        }
    });
};

export const track = (name, params) => {
    // 計測を止める前から開いている画面（bfcache からの復元・別タブ）でも送らないよう、送る都度 localStorage を読み直す
    if (!tracking || optedOut()) {
        return;
    }

    const payload = eventPayload(name, params);
    if (payload !== null) {
        window.gtag('event', payload.name, payload.params);
    }
};

// 計測を止めた画面で、その場から送らなくする
export const stopTracking = () => {
    tracking = false;
    if (measurementId !== null) {
        window[`ga-disable-${measurementId}`] = true;
    }
};

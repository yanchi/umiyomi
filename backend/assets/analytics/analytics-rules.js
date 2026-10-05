// GA に渡す値の規則。入力文字列を送らないための許可リストをここに集め、node --test でテストする

// 同じオリジンのページはクエリを捨てる。利用者が入力した文字列は GET のクエリにしか入らないため。
// 別オリジン（検索エンジンなど）はこちらが付けた値ではないので、そのまま渡す
export const pageReferrer = (referrer, origin) => {
    if (referrer === '') {
        return null;
    }

    let url;
    try {
        url = new URL(referrer);
    } catch {
        return null;
    }

    return url.origin === origin ? origin + url.pathname : referrer;
};

// アドレスはサーバーが組み立てた data-path から作る。location.href を渡すと入力文字列が入りうるため。
// 別のオリジンを指せる形（// や https://）は受け付けない
export const pageLocation = (origin, path) => {
    if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) {
        return null;
    }

    return origin + path;
};

// 送ってよいイベントの許可リスト。呼び出し側が誤って地点・名前・現在地を渡しても、ここで落とす（FR-004・SC-002）。
// 各パラメーターに許す値も縛り、想定外の文字列が混ざらないようにする
const ALLOWED_EVENTS = new Map([
    ['favorite_save', {}],
    ['favorite_delete', {}],
    ['current_location', { result: ['success', 'failure'] }],
    ['feedback_form_open', {}],
]);

export const eventPayload = (name, params = {}) => {
    if (!ALLOWED_EVENTS.has(name)) {
        return null;
    }

    const allowedParams = ALLOWED_EVENTS.get(name);
    const sanitized = {};
    for (const [key, allowedValues] of Object.entries(allowedParams)) {
        if (!Object.hasOwn(params, key) || !allowedValues.includes(params[key])) {
            // 必須のパラメーターが欠けている・許可しない値のときは、イベントごと送らない
            return null;
        }
        sanitized[key] = params[key];
    }

    return { name, params: sanitized };
};

export const OPT_OUT_KEY = 'umiyomi.analytics.optOut';

// 読めないときに「計測する」を返すのは、選択を保存できない環境で、保存していない選択を推測しないため
export const readOptOut = (storage) => {
    try {
        return storage !== null && storage.getItem(OPT_OUT_KEY) === '1';
    } catch {
        return false;
    }
};

// 保存できたかを返す。画面は、保存できなかったときに「切り替えを保存できません」を出す
export const writeOptOut = (storage, optedOut) => {
    try {
        if (optedOut) {
            storage.setItem(OPT_OUT_KEY, '1');
        } else {
            storage.removeItem(OPT_OUT_KEY);
        }

        return true;
    } catch {
        return false;
    }
};

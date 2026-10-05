// 緯度・経度の入力欄まわりの DOM 操作。規則は position-format.js・location-request.js に任せ、ここは表示とイベントだけを持つ。
// 入力欄の文字列を座標として読まない（読み取りはサーバーだけ。FR-014）。文言は Twig に置き、ここでは hidden を切り替えるだけにする。
import { createLocationRequester } from './location-request.js';

const showMessage = (region, name) => {
    for (const element of region.querySelectorAll('[data-current-location-message]')) {
        element.hidden = element.dataset.currentLocationMessage !== name;
    }
};

// 現在地を入力欄に入れる流れ。送信は利用者が行う（FR-014）
const initCurrentLocation = (form, fields, onFilled) => {
    const region = form.querySelector('[data-current-location]');
    const button = region === null ? null : region.querySelector('[data-current-location-action="fill"]');
    // Geolocation は HTTPS（と localhost）でしか動かない。押しても必ず失敗するボタンは出さない（FR-018）
    if (region === null || button === null || !('geolocation' in navigator) || !window.isSecureContext) {
        return;
    }
    region.hidden = false;

    const requester = createLocationRequester({
        geolocation: navigator.geolocation,
        setTimer: (callback, milliseconds) => window.setTimeout(callback, milliseconds),
        clearTimer: (id) => window.clearTimeout(id),
    });

    button.addEventListener('click', async () => {
        // 取得を待つあいだに利用者が入力欄を書き換えたら上書きしない（FR-017）ため、取得を始める前の値を覚える
        const before = [fields.latitude.value, fields.longitude.value];
        const pending = requester.request();
        button.disabled = true;
        showMessage(region, 'loading');

        const result = await pending;
        // 取得中に押された分。先に始めた取得の表示を壊さないよう、何もしない
        if (result.status === 'busy') {
            return;
        }

        button.disabled = false;
        if (result.status === 'ok') {
            const unchanged = fields.latitude.value === before[0] && fields.longitude.value === before[1];
            if (unchanged) {
                fields.latitude.value = result.latitude;
                fields.longitude.value = result.longitude;
                onFilled();
            }
            showMessage(region, unchanged ? 'filled' : 'edited');

            return;
        }
        showMessage(region, result.status);
    });
};

// 入力欄が、画面を開いたときの文字列（value 属性＝defaultValue）から変わったら、表示中の一覧が入力欄の地点と異なることを知らせる（FR-021）
const initStaleNotice = (form, fields) => {
    const notice = form.querySelector('[data-coordinate-stale-notice]');
    if (notice === null) {
        return () => {};
    }

    const update = () => {
        notice.hidden = !Object.values(fields).some((field) => field.value !== field.defaultValue);
    };
    for (const field of Object.values(fields)) {
        field.addEventListener('input', update);
    }
    // 戻る操作でブラウザが入力値を復元した場合は input イベントが起きない
    window.addEventListener('pageshow', update);
    update();

    return update;
};

const initForm = (form) => {
    const latitude = form.querySelector('[data-coordinate-field="latitude"]');
    const longitude = form.querySelector('[data-coordinate-field="longitude"]');
    if (latitude === null || longitude === null) {
        return;
    }
    const fields = { latitude, longitude };

    const updateStaleNotice = initStaleNotice(form, fields);
    initCurrentLocation(form, fields, updateStaleNotice);
};

export const initCoordinateInput = (root) => {
    for (const form of root.querySelectorAll('[data-coordinate-input]')) {
        initForm(form);
    }
};

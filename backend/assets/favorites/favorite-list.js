// お気に入りの規則。DOM にも Storage にも依存させないのは、Node.js だけでテストできるようにするため（research R11）。
// 一覧は不変として扱い、変更する操作は元の配列を書き換えずに新しい配列を返す。

export const MAX_FAVORITES = 20;
export const MAX_NAME_LENGTH = 30;

const STORAGE_VERSION = 1;

// -0 は toFixed で "-0.00" になりうるため、0 に揃えてから書式化する
const toFixed2 = (value) => (value + 0).toFixed(2);

const isCoordinate = (value, limit) => typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= limit;

const round2 = (value) => Number(value.toFixed(2)) + 0;

export const favoriteKey = (latitude, longitude) => `${toFixed2(latitude)},${toFixed2(longitude)}`;

const keyOf = (favorite) => favoriteKey(favorite.latitude, favorite.longitude);

// 並び順は配列の順に頼らず、保存日時の降順（同時刻はキーの昇順）で決める。読み込み後に必ずこの順になるようにするため
const sortFavorites = (list) =>
    [...list].sort((a, b) => {
        const byTime = Date.parse(b.savedAt) - Date.parse(a.savedAt);
        if (byTime !== 0) {
            return byTime;
        }
        const keyA = keyOf(a);
        const keyB = keyOf(b);

        return keyA < keyB ? -1 : keyA > keyB ? 1 : 0;
    });

// maxlength は貼り付けた文字列を黙って切り詰めるため使わず、ここで数えて案内する（research R4）
export const normalizeName = (input) => {
    const name = input.trim();
    if (Array.from(name).length > MAX_NAME_LENGTH) {
        return { ok: false, error: 'name_too_long' };
    }

    return { ok: true, name };
};

export const displayName = (favorite) =>
    favorite.name === '' ? `${toFixed2(favorite.latitude)}, ${toFixed2(favorite.longitude)}` : favorite.name;

// 予報画面の地点表示（ForecastPageViewModelFactory::location）と同じ書式
export const coordinateLabel = (favorite) => {
    const latitude = favorite.latitude + 0;
    const longitude = favorite.longitude + 0;

    return `${latitude < 0 ? '南緯' : '北緯'} ${toFixed2(Math.abs(latitude))}° / ${longitude < 0 ? '西経' : '東経'} ${toFixed2(Math.abs(longitude))}°`;
};

// 名前を URL に含めない（FR-006）
export const forecastUrl = (favorite) => `/forecast?lat=${toFixed2(favorite.latitude)}&lon=${toFixed2(favorite.longitude)}`;

export const find = (list, latitude, longitude) => {
    const key = favoriteKey(latitude, longitude);

    return list.find((favorite) => keyOf(favorite) === key) ?? null;
};

// 判定の順序は 名前 → 重複 → 件数。名前の誤りは入力し直せば直るので先に知らせ、重複のときは上限の案内を出さないため
export const add = (list, { latitude, longitude, name }, now) => {
    const normalized = normalizeName(name);
    if (!normalized.ok) {
        return normalized;
    }
    if (find(list, latitude, longitude) !== null) {
        return { ok: false, error: 'duplicate' };
    }
    if (list.length >= MAX_FAVORITES) {
        return { ok: false, error: 'limit' };
    }

    return { ok: true, list: sortFavorites([{ latitude, longitude, name: normalized.name, savedAt: now.toISOString() }, ...list]) };
};

export const remove = (list, key) => {
    if (!list.some((favorite) => keyOf(favorite) === key)) {
        return { ok: false, error: 'not_found' };
    }

    return { ok: true, list: list.filter((favorite) => keyOf(favorite) !== key) };
};

// savedAt を変えないので、並び順も変わらない（FR-008）
export const rename = (list, key, name) => {
    const normalized = normalizeName(name);
    if (!normalized.ok) {
        return normalized;
    }
    if (!list.some((favorite) => keyOf(favorite) === key)) {
        return { ok: false, error: 'not_found' };
    }

    return { ok: true, list: list.map((favorite) => (keyOf(favorite) === key ? { ...favorite, name: normalized.name } : favorite)) };
};

// 手で書き換えられたり壊れたりしたデータは、読める項目だけを残す（spec Edge Cases）
const parseItem = (value) => {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return null;
    }
    const { latitude, longitude, name, savedAt } = value;
    if (!isCoordinate(latitude, 90) || !isCoordinate(longitude, 180)) {
        return null;
    }
    if (typeof name !== 'string' || typeof savedAt !== 'string' || Number.isNaN(Date.parse(savedAt))) {
        return null;
    }
    const normalized = normalizeName(name);
    if (!normalized.ok) {
        return null;
    }

    return { latitude: round2(latitude), longitude: round2(longitude), name: normalized.name, savedAt };
};

export const parse = (raw) => {
    if (typeof raw !== 'string') {
        return [];
    }
    let data;
    try {
        data = JSON.parse(raw);
    } catch {
        return [];
    }
    if (typeof data !== 'object' || data === null || data.version !== STORAGE_VERSION || !Array.isArray(data.items)) {
        return [];
    }

    // 並べてから先に出てきた（新しい）項目を残すと、重複の判定が 1 回の走査で済む
    const seen = new Set();
    const unique = [];
    for (const favorite of sortFavorites(data.items.map(parseItem).filter((item) => item !== null))) {
        const key = keyOf(favorite);
        if (!seen.has(key)) {
            seen.add(key);
            unique.push(favorite);
        }
    }

    return unique.slice(0, MAX_FAVORITES);
};

export const serialize = (list) =>
    JSON.stringify({
        version: STORAGE_VERSION,
        items: sortFavorites(list)
            .slice(0, MAX_FAVORITES)
            .map(({ latitude, longitude, name, savedAt }) => ({ latitude, longitude, name, savedAt })),
    });

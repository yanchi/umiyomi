// お気に入りの DOM 操作。規則は favorite-list.js、保存は favorite-store.js に任せ、ここは描画とイベントだけを持つ。
// 名前は利用者の入力なので、DOM には textContent / value / setAttribute だけで入れる（FR-014、research R5）。
import { add, coordinateLabel, displayName, favoriteKey, find, forecastUrl, remove, rename } from './favorite-list.js';
import { createFavoriteStore } from './favorite-store.js';

const FRAME_SELECTOR = '[data-favorites="manage-list"], [data-favorites="switch-list"], [data-favorites="save-panel"]';

// 状態要素は hidden 付きで出力されているので、表示するものを 1 つに決めるだけにする
const showState = (frame, name) => {
    for (const element of frame.querySelectorAll('[data-favorites-state]')) {
        element.hidden = element.dataset.favoritesState !== name;
    }
};

// name が null なら、すべてのメッセージを隠す
const showMessage = (frame, name) => {
    for (const element of frame.querySelectorAll('[data-favorites-message]')) {
        element.hidden = element.dataset.favoritesMessage !== name;
    }
};

const instantiate = (frame, templateName) => {
    const template = frame.querySelector(`template[data-favorites-template="${templateName}"]`);

    return template === null ? null : template.content.firstElementChild.cloneNode(true);
};

const setField = (element, field, text) => {
    const target = element.querySelector(`[data-favorites-field="${field}"]`);
    if (target !== null) {
        target.textContent = text;
    }
};

const getStorage = () => {
    try {
        return window.localStorage;
    } catch {
        // アクセスするだけで例外になる環境（サイトデータの拒否など）
        return null;
    }
};

const findByKey = (list, key) => list.find((favorite) => favoriteKey(favorite.latitude, favorite.longitude) === key);

// 名前を埋め込む文面（削除確認など）は Twig のひな形に {name} を置き、ここで置き換える。名前に $ が含まれても
// 置換パターンとして解釈されないよう、文字列ではなく関数で置き換える
const fillName = (template, name) => template.replace('{name}', () => name);

// 削除の流れ（確認 → 読み直し → 削除 → 保存）。戻り値は呼び出し側が表示を決めるための結果
const confirmAndRemove = (store, key, confirmTemplate) => {
    const favorite = findByKey(store.load(), key);
    if (favorite === undefined) {
        return 'not_found';
    }
    if (!window.confirm(fillName(confirmTemplate, displayName(favorite)))) {
        return 'cancelled';
    }

    // 確認のあいだに他のタブで変更されているかもしれないので、書き込みの直前に読み直す（research R10）
    const result = remove(store.load(), key);
    if (!result.ok) {
        return 'not_found';
    }

    return store.save(result.list) ? 'removed' : 'write_failed';
};

// 表示中の地点の座標は、サーバーが丸めた値を Number() で数値にするだけで、丸め直さない（research R2）
const readTarget = (panel) => ({
    latitude: Number(panel.dataset.latitude),
    longitude: Number(panel.dataset.longitude),
});

const renderItems = (frame, templateName, list, current) => {
    const items = frame.querySelector('[data-favorites-items]');
    items.replaceChildren();

    for (const favorite of list) {
        const item = instantiate(frame, templateName);
        const link = item.querySelector('a');
        link.setAttribute('href', forecastUrl(favorite));
        setField(item, 'name', displayName(favorite));
        setField(item, 'coordinate', coordinateLabel(favorite));
        item.dataset.favoritesKey = favoriteKey(favorite.latitude, favorite.longitude);

        // 表示中の地点は色だけに頼らず、「表示中」の文字と aria-current で示す（FR-005）
        const isCurrent = current !== null && item.dataset.favoritesKey === favoriteKey(current.latitude, current.longitude);
        if (isCurrent) {
            link.setAttribute('aria-current', 'page');
        }
        const marker = item.querySelector('[data-favorites-field="current"]');
        if (marker !== null) {
            marker.hidden = !isCurrent;
        }

        // 名前変更フォームの説明文の id は、項目ごとに一意でないと別の項目の説明に結び付いてしまう
        const renameMessage = item.querySelector('form [data-favorites-message="name_too_long"]');
        if (renameMessage !== null) {
            renameMessage.id = `favorite-rename-error-${items.children.length}`;
            item.querySelector('form input').setAttribute('aria-describedby', renameMessage.id);
        }

        items.append(item);
    }

    showState(frame, list.length === 0 ? 'empty' : 'ready');
};

const renderPanel = (panel, list) => {
    const saved = find(list, readTarget(panel).latitude, readTarget(panel).longitude);
    if (saved === null) {
        showState(panel, 'unsaved');

        return;
    }
    setField(panel, 'name', displayName(saved));
    showState(panel, 'saved');
};

const RENAME_CONTROLS = '[data-favorites-action="rename"], [data-favorites-action="remove"]';

const setRenaming = (item, renaming) => {
    item.querySelector('a').hidden = renaming;
    for (const control of item.querySelectorAll(RENAME_CONTROLS)) {
        control.hidden = renaming;
    }
    item.querySelector('form').hidden = !renaming;
};

const closeRename = (item) => {
    const input = item.querySelector('form input');
    input.removeAttribute('aria-invalid');
    showMessage(item.querySelector('form'), null);
    setRenaming(item, false);
    item.querySelector('[data-favorites-action="rename"]').focus();
};

const openRename = (item, favorite) => {
    const input = item.querySelector('form input');
    input.value = favorite.name;
    // 名前なしの項目は空欄から始め、空のままだと座標が名前になることをプレースホルダで示す
    input.placeholder = displayName({ ...favorite, name: '' });
    setRenaming(item, true);
    input.focus();
};

const setupManageList = (frame, store, render) => {
    const keyOf = (element) => element.closest('[data-favorites-key]').dataset.favoritesKey;

    frame.addEventListener('click', (event) => {
        const control = event.target.closest('[data-favorites-action]');
        if (control === null) {
            return;
        }
        const item = control.closest('[data-favorites-key]');

        switch (control.dataset.favoritesAction) {
            case 'rename': {
                // 別のタブで削除済みなら、開かずに一覧を最新にする
                const favorite = findByKey(store.load(), keyOf(control));
                if (favorite === undefined) {
                    render();

                    return;
                }
                openRename(item, favorite);

                return;
            }
            case 'cancel-rename':
                closeRename(item);

                return;
            case 'remove': {
                const outcome = confirmAndRemove(store, keyOf(control), frame.dataset.confirmRemove);
                if (outcome === 'cancelled') {
                    return;
                }
                render();
                showMessage(frame, outcome === 'write_failed' ? 'write_failed' : null);

                return;
            }
        }
    });

    frame.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.target;
        const input = form.querySelector('input');
        const key = keyOf(form);

        // 他のタブで変更されているかもしれないので、書き込みの直前に読み直す（research R10）
        const result = rename(store.load(), key, input.value);
        if (!result.ok && result.error === 'name_too_long') {
            input.setAttribute('aria-invalid', 'true');
            showMessage(form, 'name_too_long');
            input.focus();

            return;
        }
        if (!result.ok) {
            // not_found：別のタブで削除済み
            render();

            return;
        }
        if (!store.save(result.list)) {
            showMessage(frame, 'write_failed');

            return;
        }

        render();
        showMessage(frame, null);
        frame.querySelector(`[data-favorites-key="${CSS.escape(key)}"] [data-favorites-action="rename"]`)?.focus();
    });
};

const setupSavePanel = (panel, store, render) => {
    const form = panel.querySelector('form');
    const input = panel.querySelector('input[name="favorite-name"]');

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        // 他のタブで変更されているかもしれないので、書き込みの直前に読み直す（research R10）
        const result = add(store.load(), { ...readTarget(panel), name: input.value }, new Date());

        if (!result.ok && result.error === 'name_too_long') {
            input.setAttribute('aria-invalid', 'true');
            showMessage(panel, 'name_too_long');
            input.focus();

            return;
        }
        input.removeAttribute('aria-invalid');

        if (!result.ok) {
            // 別のタブで保存済みになっていた場合は、保存せず保存済みの表示に切り替える
            showMessage(panel, result.error === 'limit' ? 'limit' : null);
            render();

            return;
        }
        if (!store.save(result.list)) {
            showMessage(panel, 'write_failed');

            return;
        }

        input.value = '';
        render();
        showMessage(panel, 'saved');
    });

    panel.addEventListener('click', (event) => {
        if (event.target.closest('[data-favorites-action="remove"]') === null) {
            return;
        }

        const { latitude, longitude } = readTarget(panel);
        const outcome = confirmAndRemove(store, favoriteKey(latitude, longitude), panel.dataset.confirmRemove);
        if (outcome === 'cancelled') {
            return;
        }
        render();
        // 別のタブで削除済み（not_found）のときは、メッセージを出さず表示だけ更新する
        showMessage(panel, outcome === 'removed' ? 'removed' : outcome === 'write_failed' ? 'write_failed' : null);
    });
};

export const initFavorites = (root) => {
    const frames = root.querySelectorAll(FRAME_SELECTOR);
    if (frames.length === 0) {
        return;
    }

    // お気に入りで例外が起きても、サーバーが描画した予報の表示には影響させない（SC-004）
    try {
        const storage = getStorage();
        const store = storage === null ? null : createFavoriteStore(storage);
        if (store === null || !store.isAvailable()) {
            for (const frame of frames) {
                showState(frame, 'unavailable');
            }

            return;
        }

        const manageLists = root.querySelectorAll('[data-favorites="manage-list"]');
        const switchLists = root.querySelectorAll('[data-favorites="switch-list"]');
        const panels = root.querySelectorAll('[data-favorites="save-panel"]');
        const counts = root.querySelectorAll('[data-favorites="count"]');
        // 保存パネルがない（入力エラー）ときは、表示中の地点がないので印を付けない
        const current = panels.length === 0 ? null : readTarget(panels[0]);

        const render = () => {
            const list = store.load();
            for (const frame of manageLists) {
                renderItems(frame, 'manage-item', list, null);
            }
            for (const frame of switchLists) {
                renderItems(frame, 'switch-item', list, current);
            }
            for (const panel of panels) {
                renderPanel(panel, list);
            }
            for (const count of counts) {
                count.textContent = String(list.length);
            }
        };

        for (const frame of manageLists) {
            setupManageList(frame, store, render);
        }
        for (const panel of panels) {
            setupSavePanel(panel, store, render);
        }
        render();

        // 戻る操作で bfcache から復元されたときは、他の画面での保存・削除が反映されていないため描き直す
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                render();
            }
        });
    } catch (error) {
        console.error(error);
    }
};

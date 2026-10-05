// Storage を引数で受け取るのは、テストで偽の Storage（例外を投げるものを含む）を渡すため。window.localStorage は呼び出し側が渡す。
import { parse, serialize } from './favorite-list.js';

const KEY = 'umiyomi.favorites';
const PROBE_KEY = 'umiyomi.favorites.probe';

export const createFavoriteStore = (storage) => ({
    // プライベートブラウズやサイトデータの拒否では、アクセスするだけで例外になるため、実際に書いて確かめる
    isAvailable() {
        try {
            storage.setItem(PROBE_KEY, '1');
            storage.removeItem(PROBE_KEY);

            return true;
        } catch {
            return false;
        }
    },

    load() {
        try {
            return parse(storage.getItem(KEY));
        } catch {
            return [];
        }
    },

    // 容量超過などで書けなかったことを呼び出し側が利用者に伝えられるよう、例外ではなく false で返す
    save(list) {
        try {
            storage.setItem(KEY, serialize(list));

            return true;
        } catch {
            return false;
        }
    },
});

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { serialize } from '../../assets/favorites/favorite-list.js';
import { createFavoriteStore } from '../../assets/favorites/favorite-store.js';

const KEY = 'umiyomi.favorites';

const memoryStorage = () => {
    const map = new Map();

    return {
        map,
        getItem: (key) => (map.has(key) ? map.get(key) : null),
        setItem: (key, value) => {
            map.set(key, String(value));
        },
        removeItem: (key) => {
            map.delete(key);
        },
    };
};

const throwingStorage = () => {
    const fail = () => {
        throw new DOMException('denied', 'SecurityError');
    };

    return { getItem: fail, setItem: fail, removeItem: fail };
};

const quotaExceededStorage = () => ({
    ...memoryStorage(),
    setItem: () => {
        throw new DOMException('full', 'QuotaExceededError');
    },
});

const favorite = { latitude: 27.75, longitude: 129.05, name: 'テスト沖', savedAt: '2026-10-05T11:15:00.000Z' };

describe('isAvailable', () => {
    it('通常は true で、確認用のキーを残さない', () => {
        const storage = memoryStorage();
        assert.equal(createFavoriteStore(storage).isAvailable(), true);
        assert.equal(storage.map.size, 0);
    });

    it('例外が出れば false', () => {
        assert.equal(createFavoriteStore(throwingStorage()).isAvailable(), false);
    });

    it('書き込めない Storage でも false', () => {
        assert.equal(createFavoriteStore(quotaExceededStorage()).isAvailable(), false);
    });
});

describe('load', () => {
    it('キーがなければ空の一覧', () => {
        assert.deepEqual(createFavoriteStore(memoryStorage()).load(), []);
    });

    it('保存した一覧を読む', () => {
        const storage = memoryStorage();
        storage.setItem(KEY, serialize([favorite]));
        assert.deepEqual(createFavoriteStore(storage).load(), [favorite]);
    });

    it('壊れた JSON なら空の一覧', () => {
        const storage = memoryStorage();
        storage.setItem(KEY, '{');
        assert.deepEqual(createFavoriteStore(storage).load(), []);
    });

    it('getItem が例外を投げても空の一覧', () => {
        assert.deepEqual(createFavoriteStore(throwingStorage()).load(), []);
    });
});

describe('save', () => {
    it('umiyomi.favorites に serialize の結果を書いて true を返す', () => {
        const storage = memoryStorage();
        assert.equal(createFavoriteStore(storage).save([favorite]), true);
        assert.equal(storage.map.get(KEY), serialize([favorite]));
    });

    it('setItem が例外を投げたら false を返す', () => {
        assert.equal(createFavoriteStore(quotaExceededStorage()).save([favorite]), false);
    });
});

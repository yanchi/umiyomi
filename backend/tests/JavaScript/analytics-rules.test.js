import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { OPT_OUT_KEY, eventPayload, pageLocation, pageReferrer, readOptOut, writeOptOut } from '../../assets/analytics/analytics-rules.js';

const ORIGIN = 'https://example.com';

describe('pageReferrer', () => {
    it('空文字は null', () => {
        assert.equal(pageReferrer('', ORIGIN), null);
    });

    it('同じオリジンはクエリを捨てる', () => {
        assert.equal(pageReferrer('https://example.com/forecast?lat=北緯&lon=1', ORIGIN), 'https://example.com/forecast');
    });

    it('同じオリジンはハッシュも捨てる', () => {
        assert.equal(pageReferrer('https://example.com/forecast?lat=1#top', ORIGIN), 'https://example.com/forecast');
        assert.equal(pageReferrer('https://example.com/#top', ORIGIN), 'https://example.com/');
    });

    it('別オリジンはそのまま', () => {
        assert.equal(pageReferrer('https://www.google.com/', ORIGIN), 'https://www.google.com/');
    });

    it('スキーム違いは別オリジンとして扱いそのまま', () => {
        assert.equal(pageReferrer('http://example.com/', ORIGIN), 'http://example.com/');
    });

    it('URL として解釈できない文字列は null', () => {
        assert.equal(pageReferrer('not a url', ORIGIN), null);
    });
});

describe('pageLocation', () => {
    it('オリジンとパスを連結する', () => {
        assert.equal(pageLocation(ORIGIN, '/forecast?lat=27.75&lon=129.05'), 'https://example.com/forecast?lat=27.75&lon=129.05');
    });

    it('/ で始まらないパスは null', () => {
        assert.equal(pageLocation(ORIGIN, '//evil.example/'), null);
        assert.equal(pageLocation(ORIGIN, 'https://evil.example/'), null);
        assert.equal(pageLocation(ORIGIN, ''), null);
    });
});

describe('eventPayload', () => {
    it('パラメーターのないイベントは空のパラメーターで返す', () => {
        assert.deepEqual(eventPayload('favorite_save', {}), { name: 'favorite_save', params: {} });
        assert.deepEqual(eventPayload('favorite_delete'), { name: 'favorite_delete', params: {} });
        assert.deepEqual(eventPayload('feedback_form_open'), { name: 'feedback_form_open', params: {} });
    });

    it('現在地は成功・失敗だけを送る', () => {
        assert.deepEqual(eventPayload('current_location', { result: 'success' }), { name: 'current_location', params: { result: 'success' } });
        assert.deepEqual(eventPayload('current_location', { result: 'failure' }), { name: 'current_location', params: { result: 'failure' } });
        assert.equal(eventPayload('current_location', { result: 'denied' }), null);
        assert.equal(eventPayload('current_location', {}), null);
    });

    it('許可しないパラメーターは落とす', () => {
        assert.deepEqual(
            eventPayload('favorite_save', { latitude: 27.75, longitude: 129.05, name: '奄美沖' }),
            { name: 'favorite_save', params: {} },
        );
        assert.deepEqual(
            eventPayload('current_location', { result: 'success', latitude: 1 }),
            { name: 'current_location', params: { result: 'success' } },
        );
    });

    it('許可リストにない名前は送らない', () => {
        assert.equal(eventPayload('page_view', {}), null);
        assert.equal(eventPayload('favorite_rename', {}), null);
        assert.equal(eventPayload('__proto__', {}), null);
        assert.equal(eventPayload('constructor', {}), null);
    });

    it('params は Object.prototype 由来のキーを含まない', () => {
        const payload = eventPayload('current_location', { result: 'success', toString: 'x', __proto__: { polluted: 1 } });

        assert.deepEqual(Object.keys(payload.params), ['result']);
        assert.equal(Object.hasOwn(payload.params, 'polluted'), false);
    });
});

describe('オプトアウト', () => {
    const KEY = 'umiyomi.analytics.optOut';

    const fakeStorage = (initial = {}) => {
        const map = new Map(Object.entries(initial));
        const calls = [];

        return {
            calls,
            getItem: (key) => {
                calls.push(['getItem', key]);

                return map.has(key) ? map.get(key) : null;
            },
            setItem: (key, value) => {
                calls.push(['setItem', key, value]);
                map.set(key, String(value));
            },
            removeItem: (key) => {
                calls.push(['removeItem', key]);
                map.delete(key);
            },
        };
    };

    const throwingStorage = () => ({
        getItem: () => {
            throw new DOMException('denied', 'SecurityError');
        },
        setItem: () => {
            throw new DOMException('denied', 'SecurityError');
        },
        removeItem: () => {
            throw new DOMException('denied', 'SecurityError');
        },
    });

    it('キーは umiyomi.analytics.optOut', () => {
        assert.equal(OPT_OUT_KEY, KEY);
    });

    it('readOptOut は "1" のときだけ true', () => {
        assert.equal(readOptOut(fakeStorage({ [KEY]: '1' })), true);
        for (const value of [undefined, '0', 'true', '1 ', '']) {
            assert.equal(readOptOut(fakeStorage(value === undefined ? {} : { [KEY]: value })), false, String(value));
        }
    });

    it('storage が使えないときは計測する側（false）にする', () => {
        assert.equal(readOptOut(null), false);
        assert.equal(readOptOut(throwingStorage()), false);
    });

    it('writeOptOut は計測しないを "1" で保存し、再開はキーを消す', () => {
        const storage = fakeStorage();

        assert.equal(writeOptOut(storage, true), true);
        assert.deepEqual(storage.calls, [['setItem', KEY, '1']]);
        assert.equal(readOptOut(storage), true);

        assert.equal(writeOptOut(storage, false), true);
        assert.equal(readOptOut(storage), false);
        assert.ok(storage.calls.some((call) => call[0] === 'removeItem' && call[1] === KEY));
    });

    it('writeOptOut は保存できないとき false を返す', () => {
        assert.equal(writeOptOut(null, true), false);
        assert.equal(writeOptOut(throwingStorage(), true), false);
        assert.equal(writeOptOut(throwingStorage(), false), false);
    });

    it('お気に入りのキーに触れない', () => {
        const storage = fakeStorage();

        writeOptOut(storage, true);
        writeOptOut(storage, false);
        readOptOut(storage);

        assert.ok(storage.calls.every((call) => call[1] === KEY));
    });
});

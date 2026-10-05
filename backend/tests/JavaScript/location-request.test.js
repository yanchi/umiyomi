import assert from 'node:assert/strict';
import { describe, it, mock } from 'node:test';
import { createLocationRequester } from '../../assets/coordinate-input/location-request.js';

const PERMISSION_DENIED = 1;

// getCurrentPosition を記録し、テストから成功・失敗を後で呼べる偽の geolocation
const fakeGeolocation = () => {
    const calls = [];

    return {
        calls,
        getCurrentPosition(success, error, options) {
            calls.push({ success, error, options });
        },
    };
};

const createRequester = (geolocation, timeoutMs) => createLocationRequester({
    geolocation,
    timeoutMs,
    setTimer: setTimeout,
    clearTimer: clearTimeout,
});

const position = (latitude, longitude) => ({ coords: { latitude, longitude } });

// 時間切れの Promise を、まだ解決していないことを確かめるために使う
const pending = Symbol('pending');
const peek = async (promise) => Promise.race([promise, Promise.resolve(pending)]);

describe('createLocationRequester', () => {
    it('成功したら 2 桁にした位置を返し、高精度・キャッシュ利用なしで取得する', async () => {
        const geolocation = fakeGeolocation();
        const result = createRequester(geolocation).request();

        geolocation.calls[0].success(position(27.754, 129.0461));

        assert.deepEqual(await result, { status: 'ok', latitude: '27.75', longitude: '129.05' });
        assert.deepEqual(geolocation.calls[0].options, { enableHighAccuracy: false, maximumAge: 0 });
        // timeout はブラウザに任せず、独自のタイマーで数える
        assert.ok(!('timeout' in geolocation.calls[0].options));
    });

    it('許可されなかったときは denied', async () => {
        const geolocation = fakeGeolocation();
        const result = createRequester(geolocation).request();

        geolocation.calls[0].error({ code: PERMISSION_DENIED });

        assert.deepEqual(await result, { status: 'denied' });
    });

    for (const code of [2, 3, 99, undefined]) {
        it(`許可の拒否以外のエラー（code=${code}）は failed`, async () => {
            const geolocation = fakeGeolocation();
            const result = createRequester(geolocation).request();

            geolocation.calls[0].error({ code });

            assert.deepEqual(await result, { status: 'failed' });
        });
    }

    it('request() を呼んだ時点から 15 秒で failed になる', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        const geolocation = fakeGeolocation();
        const result = createRequester(geolocation).request();

        t.mock.timers.tick(14999);
        assert.equal(await peek(result), pending);

        t.mock.timers.tick(1);
        assert.deepEqual(await result, { status: 'failed' });
    });

    it('時間切れのあとに届いた成功・失敗は結果を変えない', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        const geolocation = fakeGeolocation();
        const requester = createRequester(geolocation);
        const result = requester.request();

        t.mock.timers.tick(15000);
        geolocation.calls[0].success(position(27.75, 129.05));
        geolocation.calls[0].error({ code: PERMISSION_DENIED });

        assert.deepEqual(await result, { status: 'failed' });
    });

    it('結果が出たあとはタイマーを止め、時間が過ぎても結果が変わらない', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        const geolocation = fakeGeolocation();
        const result = createRequester(geolocation).request();

        geolocation.calls[0].success(position(27.75, 129.05));
        t.mock.timers.tick(20000);

        assert.deepEqual(await result, { status: 'ok', latitude: '27.75', longitude: '129.05' });
    });

    it('取得中にもう一度呼ぶと busy で、重ねて取得しない', async () => {
        const geolocation = fakeGeolocation();
        const requester = createRequester(geolocation);
        const first = requester.request();

        assert.deepEqual(await requester.request(), { status: 'busy' });
        assert.equal(geolocation.calls.length, 1);

        geolocation.calls[0].success(position(27.75, 129.05));
        await first;
    });

    it('結果が出たあとは再び取得できる', async () => {
        const geolocation = fakeGeolocation();
        const requester = createRequester(geolocation);
        const first = requester.request();
        geolocation.calls[0].error({ code: PERMISSION_DENIED });
        await first;

        const second = requester.request();
        geolocation.calls[1].success(position(35.5, 139.5));

        assert.equal(geolocation.calls.length, 2);
        assert.deepEqual(await second, { status: 'ok', latitude: '35.50', longitude: '139.50' });
    });

    it('時間切れのあとも再び取得できる', async (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        const geolocation = fakeGeolocation();
        const requester = createRequester(geolocation);
        const first = requester.request();
        t.mock.timers.tick(15000);
        await first;

        const second = requester.request();
        geolocation.calls[1].success(position(35.5, 139.5));

        assert.deepEqual(await second, { status: 'ok', latitude: '35.50', longitude: '139.50' });
    });

    it('getCurrentPosition が例外を投げたら failed で、取得中の状態を残さない', async () => {
        const geolocation = {
            calls: 0,
            getCurrentPosition() {
                this.calls += 1;
                throw new Error('unavailable');
            },
        };
        const requester = createRequester(geolocation);

        assert.deepEqual(await requester.request(), { status: 'failed' });
        assert.deepEqual(await requester.request(), { status: 'failed' });
        assert.equal(geolocation.calls, 2);
    });
});

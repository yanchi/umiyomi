import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    MAX_FAVORITES,
    MAX_NAME_LENGTH,
    add,
    coordinateLabel,
    displayName,
    favoriteKey,
    find,
    forecastUrl,
    normalizeName,
    parse,
    remove,
    rename,
    serialize,
} from '../../assets/favorites/favorite-list.js';

const item = (latitude, longitude, name = '', savedAt = '2026-10-05T11:15:00.000Z') => ({ latitude, longitude, name, savedAt });

const raw = (items, version = 1) => JSON.stringify({ version, items });

describe('normalizeName', () => {
    it('前後の半角・全角スペースを除く', () => {
        assert.deepEqual(normalizeName('　テスト沖　'), { ok: true, name: 'テスト沖' });
        assert.deepEqual(normalizeName('  北の瀬 '), { ok: true, name: '北の瀬' });
    });

    it('空白だけなら名前なし（空文字列）になる', () => {
        assert.deepEqual(normalizeName('  　 '), { ok: true, name: '' });
        assert.deepEqual(normalizeName(''), { ok: true, name: '' });
    });

    it('30 文字は成功し、31 文字は name_too_long', () => {
        assert.equal(MAX_NAME_LENGTH, 30);
        assert.deepEqual(normalizeName('あ'.repeat(30)), { ok: true, name: 'あ'.repeat(30) });
        assert.deepEqual(normalizeName('あ'.repeat(31)), { ok: false, error: 'name_too_long' });
    });

    it('絵文字（サロゲートペア）を 1 文字として数える', () => {
        assert.deepEqual(normalizeName('🐟'.repeat(30)), { ok: true, name: '🐟'.repeat(30) });
        assert.deepEqual(normalizeName('🐟'.repeat(31)), { ok: false, error: 'name_too_long' });
    });

    it('空白を除いたあとの文字数で判定する', () => {
        assert.equal(normalizeName(` ${'あ'.repeat(30)} `).ok, true);
    });
});

describe('favoriteKey', () => {
    it('小数点以下 2 桁に揃える', () => {
        assert.equal(favoriteKey(27.75, 129.05), '27.75,129.05');
        assert.equal(favoriteKey(28.1, 129.3), '28.10,129.30');
    });

    it('-0 を "-0.00" にしない', () => {
        assert.equal(favoriteKey(-0, 129.05), '0.00,129.05');
    });
});

describe('displayName', () => {
    it('名前があればその名前を返す', () => {
        assert.equal(displayName(item(27.75, 129.05, 'テスト沖')), 'テスト沖');
    });

    it('名前が空なら座標を返す', () => {
        assert.equal(displayName(item(27.75, 129.05)), '27.75, 129.05');
        assert.equal(displayName(item(28.1, 129.3)), '28.10, 129.30');
        assert.equal(displayName(item(-0.5, -120)), '-0.50, -120.00');
    });
});

describe('coordinateLabel', () => {
    it('北緯・東経の書式で返す', () => {
        assert.equal(coordinateLabel(item(27.75, 129.05)), '北緯 27.75° / 東経 129.05°');
    });

    it('マイナスは南緯・西経を絶対値で返す', () => {
        assert.equal(coordinateLabel(item(-0.5, -120)), '南緯 0.50° / 西経 120.00°');
    });

    it('0 は北緯・東経として扱う（予報画面の地点表示と同じ）', () => {
        assert.equal(coordinateLabel(item(0, 0)), '北緯 0.00° / 東経 0.00°');
        assert.equal(coordinateLabel(item(-0, -0)), '北緯 0.00° / 東経 0.00°');
    });
});

describe('forecastUrl', () => {
    it('名前を含まない予報画面の URL を返す', () => {
        assert.equal(forecastUrl(item(27.75, 129.05, 'テスト沖')), '/forecast?lat=27.75&lon=129.05');
        assert.equal(forecastUrl(item(28.1, 129.3)), '/forecast?lat=28.10&lon=129.30');
    });
});

describe('find', () => {
    const list = [item(27.75, 129.05, 'テスト沖'), item(28.1, 129.3)];

    it('同じ地点があればその項目を返す', () => {
        assert.equal(find(list, 27.75, 129.05), list[0]);
        assert.equal(find(list, 28.1, 129.3), list[1]);
    });

    it('なければ null を返す', () => {
        assert.equal(find(list, 27.76, 129.05), null);
        assert.equal(find([], 27.75, 129.05), null);
    });
});

describe('parse', () => {
    it('キーがなければ空の一覧', () => {
        assert.deepEqual(parse(null), []);
    });

    it('JSON として読めなければ空の一覧', () => {
        assert.deepEqual(parse('{'), []);
        assert.deepEqual(parse(''), []);
    });

    it('version が 1 でなければ空の一覧', () => {
        assert.deepEqual(parse(raw([item(27.75, 129.05)], 2)), []);
        assert.deepEqual(parse(JSON.stringify({ items: [item(27.75, 129.05)] })), []);
    });

    it('items が配列でなければ空の一覧', () => {
        assert.deepEqual(parse(JSON.stringify({ version: 1, items: {} })), []);
        assert.deepEqual(parse(JSON.stringify({ version: 1 })), []);
        assert.deepEqual(parse('null'), []);
        assert.deepEqual(parse('[]'), []);
    });

    it('正しい項目はそのまま読む', () => {
        const items = [item(27.75, 129.05, 'テスト沖')];
        assert.deepEqual(parse(raw(items)), items);
    });

    it('不正な項目だけを捨てて、残りを読む', () => {
        const good = item(27.75, 129.05, 'テスト沖');
        const broken = [
            null,
            'x',
            [],
            { latitude: 'x', longitude: 129.05, name: '', savedAt: good.savedAt },
            { latitude: 91, longitude: 129.05, name: '', savedAt: good.savedAt },
            { latitude: -91, longitude: 129.05, name: '', savedAt: good.savedAt },
            { latitude: 27.75, longitude: 181, name: '', savedAt: good.savedAt },
            { latitude: Number.NaN, longitude: 129.05, name: '', savedAt: good.savedAt },
            { latitude: 27.75, longitude: 129.05, name: 123, savedAt: good.savedAt },
            { latitude: 27.75, longitude: 129.05, savedAt: good.savedAt },
            item(27.75, 129.05, 'あ'.repeat(31)),
            { latitude: 27.75, longitude: 129.05, name: '', savedAt: 'いつか' },
            { latitude: 27.75, longitude: 129.05, name: '', savedAt: 20261005 },
            { latitude: 27.75, longitude: 129.05, name: '' },
        ];
        assert.deepEqual(parse(raw([...broken, good])), [good]);
    });

    it('2 桁に丸まっていない座標を丸めて読む', () => {
        const [favorite] = parse(raw([item(27.7549, 129.0451, 'テスト沖')]));
        assert.equal(favorite.latitude, 27.75);
        assert.equal(favorite.longitude, 129.05);
    });

    it('丸めた結果が -0 になる座標を 0 にする', () => {
        const [favorite] = parse(raw([item(-0.001, 129.05)]));
        assert.ok(Object.is(favorite.latitude, 0));
    });

    it('同じ地点が複数あれば savedAt が新しい 1 件を残す', () => {
        const older = item(27.75, 129.05, '古い', '2026-10-01T00:00:00.000Z');
        const newer = item(27.7501, 129.0499, '新しい', '2026-10-03T00:00:00.000Z');
        assert.deepEqual(parse(raw([newer, older])), [{ ...newer, latitude: 27.75, longitude: 129.05 }]);
        assert.deepEqual(parse(raw([older, newer])), [{ ...newer, latitude: 27.75, longitude: 129.05 }]);
    });

    it('21 件以上なら新しい 20 件だけを返す', () => {
        const items = Array.from({ length: 25 }, (_, i) => item(10 + i, 120, '', new Date(Date.UTC(2026, 9, 1, 0, i)).toISOString()));
        const list = parse(raw(items));
        assert.equal(list.length, MAX_FAVORITES);
        assert.equal(list[0].latitude, 34);
        assert.equal(list[19].latitude, 15);
    });

    it('savedAt の降順に並べ、同時刻はキーの昇順にする', () => {
        const same = '2026-10-05T00:00:00.000Z';
        const items = [
            item(30, 130, 'b', same),
            item(20, 130, 'oldest', '2026-10-01T00:00:00.000Z'),
            item(10, 130, 'a', same),
            item(40, 130, 'newest', '2026-10-09T00:00:00.000Z'),
        ];
        assert.deepEqual(parse(raw(items)).map((f) => f.name), ['newest', 'a', 'b', 'oldest']);
    });

    it('名前の前後の空白を除いて読む', () => {
        assert.equal(parse(raw([item(27.75, 129.05, '  テスト沖 ')]))[0].name, 'テスト沖');
    });
});

describe('serialize', () => {
    it('{"version":1,"items":[...]} の形で書く', () => {
        const list = [item(27.75, 129.05, 'テスト沖')];
        assert.deepEqual(JSON.parse(serialize(list)), { version: 1, items: list });
    });

    it('parse で元の一覧に戻る', () => {
        const list = [
            item(28.1, 129.3, '', '2026-10-06T00:00:00.000Z'),
            item(27.75, 129.05, 'テスト沖', '2026-10-05T11:15:00.000Z'),
        ];
        assert.deepEqual(parse(serialize(list)), list);
    });

    it('空の一覧も書ける', () => {
        assert.deepEqual(parse(serialize([])), []);
    });
});

describe('add', () => {
    const now = new Date('2026-10-06T00:00:00.000Z');
    const target = { latitude: 27.75, longitude: 129.05, name: 'テスト沖' };

    const fullList = () =>
        Array.from({ length: MAX_FAVORITES }, (_, i) => item(10 + i, 120, '', new Date(Date.UTC(2026, 9, 1, 0, i)).toISOString()));

    it('先頭に追加し、savedAt に now を入れる', () => {
        const existing = [item(28.1, 129.3, '', '2026-10-05T00:00:00.000Z')];
        const result = add(existing, target, now);

        assert.equal(result.ok, true);
        assert.deepEqual(result.list[0], { ...target, savedAt: '2026-10-06T00:00:00.000Z' });
        assert.equal(result.list.length, 2);
        assert.equal(result.list[1], existing[0]);
    });

    it('名前の前後の空白を除く', () => {
        const result = add([], { ...target, name: '　テスト沖 ' }, now);
        assert.equal(result.list[0].name, 'テスト沖');
    });

    it('空欄の名前は空文字列で保存し、表示名は座標になる', () => {
        const result = add([], { ...target, name: '  ' }, now);

        assert.equal(result.ok, true);
        assert.equal(result.list[0].name, '');
        assert.equal(displayName(result.list[0]), '27.75, 129.05');
    });

    it('31 文字の名前は name_too_long', () => {
        assert.deepEqual(add([], { ...target, name: 'あ'.repeat(31) }, now), { ok: false, error: 'name_too_long' });
    });

    it('同じ地点があれば duplicate（名前が違っても）', () => {
        const existing = [item(27.75, 129.05, '別の名前')];
        assert.deepEqual(add(existing, target, now), { ok: false, error: 'duplicate' });
    });

    it('20 件ある状態では limit', () => {
        assert.deepEqual(add(fullList(), target, now), { ok: false, error: 'limit' });
    });

    it('判定の順序は 名前 → 重複 → 件数', () => {
        const full = fullList();
        const duplicated = { latitude: full[0].latitude, longitude: full[0].longitude };

        assert.deepEqual(add(full, { ...duplicated, name: '' }, now), { ok: false, error: 'duplicate' });
        assert.deepEqual(add(full, { ...duplicated, name: 'あ'.repeat(31) }, now), { ok: false, error: 'name_too_long' });
        assert.deepEqual(add(full, { ...target, name: 'あ'.repeat(31) }, now), { ok: false, error: 'name_too_long' });
    });

    it('別の地点に同じ名前を付けられる', () => {
        const existing = [item(28.1, 129.3, 'テスト沖')];
        assert.equal(add(existing, target, now).ok, true);
    });

    it('元の配列を変更しない', () => {
        const existing = [item(28.1, 129.3)];
        const snapshot = structuredClone(existing);
        add(existing, target, now);

        assert.deepEqual(existing, snapshot);
    });
});

describe('remove', () => {
    const list = [item(30, 130, 'a'), item(20, 130, 'b'), item(10, 130, 'c')];

    it('その地点だけを除き、残りの並び順を変えない', () => {
        const result = remove(list, favoriteKey(20, 130));

        assert.equal(result.ok, true);
        assert.deepEqual(result.list.map((f) => f.name), ['a', 'c']);
    });

    it('存在しないキーは not_found', () => {
        assert.deepEqual(remove(list, favoriteKey(1, 1)), { ok: false, error: 'not_found' });
    });

    it('元の配列を変更しない', () => {
        const snapshot = structuredClone(list);
        remove(list, favoriteKey(20, 130));

        assert.deepEqual(list, snapshot);
    });
});

describe('rename', () => {
    const list = [
        item(30, 130, 'a', '2026-10-03T00:00:00.000Z'),
        item(20, 130, 'b', '2026-10-02T00:00:00.000Z'),
        item(10, 130, '', '2026-10-01T00:00:00.000Z'),
    ];

    it('名前だけが変わり、座標・savedAt・並び順は変わらない', () => {
        const result = rename(list, favoriteKey(20, 130), '北の瀬');

        assert.equal(result.ok, true);
        assert.deepEqual(result.list, [list[0], { ...list[1], name: '北の瀬' }, list[2]]);
    });

    it('前後の空白を除く', () => {
        assert.equal(rename(list, favoriteKey(20, 130), '　北の瀬 ').list[1].name, '北の瀬');
    });

    it('空欄にすると名前なしになり、表示名は座標になる', () => {
        const result = rename(list, favoriteKey(20, 130), '  ');

        assert.equal(result.list[1].name, '');
        assert.equal(displayName(result.list[1]), '20.00, 130.00');
    });

    it('名前なしの項目に名前を付けられる', () => {
        assert.equal(rename(list, favoriteKey(10, 130), 'テスト沖').list[2].name, 'テスト沖');
    });

    it('31 文字は name_too_long', () => {
        assert.deepEqual(rename(list, favoriteKey(20, 130), 'あ'.repeat(31)), { ok: false, error: 'name_too_long' });
    });

    it('存在しないキーは not_found', () => {
        assert.deepEqual(rename(list, favoriteKey(1, 1), 'x'), { ok: false, error: 'not_found' });
    });

    it('元の配列を変更しない', () => {
        const snapshot = structuredClone(list);
        rename(list, favoriteKey(20, 130), '北の瀬');

        assert.deepEqual(list, snapshot);
    });
});

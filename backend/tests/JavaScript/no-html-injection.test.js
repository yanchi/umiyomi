import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

// 名前・入力欄の値は利用者の入力なので、HTML として解釈される API を使わない（002 FR-014 / research R5、003 research R10）。
// DOM の動作は自動テストできないため、使っていないことをソースで確認する
const FORBIDDEN = ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'];

// 現在地は保存も送信もしない（003 FR-015）。ブラウザの保存先・ネットワークの API を使っていないことをソースで確認する
const FORBIDDEN_FOR_LOCATION = ['localStorage', 'sessionStorage', 'indexedDB', 'fetch(', 'XMLHttpRequest', 'sendBeacon'];

const targets = [
    { name: 'assets/favorites', directory: new URL('../../assets/favorites/', import.meta.url), minimumFiles: 3, forbidden: FORBIDDEN },
    {
        name: 'assets/coordinate-input',
        directory: new URL('../../assets/coordinate-input/', import.meta.url),
        minimumFiles: 3,
        forbidden: [...FORBIDDEN, ...FORBIDDEN_FOR_LOCATION],
    },
];

for (const { name, directory, minimumFiles, forbidden } of targets) {
    describe(name, () => {
        const files = readdirSync(directory).filter((file) => file.endsWith('.js'));

        it('JavaScript のファイルを見つけられる', () => {
            assert.ok(files.length >= minimumFiles, files.join(', '));
        });

        for (const file of files) {
            it(`${file} は禁止された API を使わない`, () => {
                const source = readFileSync(new URL(file, directory), 'utf8');

                for (const api of forbidden) {
                    assert.ok(!source.includes(api), `${file} が ${api} を使っている`);
                }
            });
        }
    });
}

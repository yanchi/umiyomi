import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

const directory = new URL('../../assets/favorites/', import.meta.url);

// 名前は利用者の入力なので、HTML として解釈される API を使わない（FR-014、research R5）。
// DOM の動作は自動テストできないため、使っていないことをソースで確認する
const FORBIDDEN = ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'];

describe('assets/favorites', () => {
    const files = readdirSync(directory).filter((file) => file.endsWith('.js'));

    it('JavaScript のファイルを見つけられる', () => {
        assert.ok(files.length >= 3, files.join(', '));
    });

    for (const file of files) {
        it(`${file} は文字列を HTML として解釈する API を使わない`, () => {
            const source = readFileSync(new URL(file, directory), 'utf8');

            for (const api of FORBIDDEN) {
                assert.ok(!source.includes(api), `${file} が ${api} を使っている`);
            }
        });
    }
});

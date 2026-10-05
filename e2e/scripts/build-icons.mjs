// アイコンと共有用画像の書き出し（開発時に手で実行する。composer check・CI・本番イメージには入れない）。
// 実行：docker compose --profile e2e run --rm --build e2e node scripts/build-icons.mjs
// 追加の npm パッケージを入れずに済むよう、ラスタライズは e2e イメージの Chromium に任せる。
import { chromium } from '@playwright/test';
import { readFile, writeFile } from 'node:fs/promises';

const OUT_IMAGES = '/out/images';
const OUT_PUBLIC = '/out/public';

const browser = await chromium.launch();

// SVG を指定サイズの PNG にする。omitBackground を使うので、SVG 側に地がなければ透過になる
async function rasterize(svgPath, size) {
    const svg = await readFile(svgPath, 'utf8');
    const { width, height } = typeof size === 'number' ? { width: size, height: size } : size;
    const page = await browser.newPage({ viewport: { width, height } });
    await page.setContent(
        `<!doctype html><style>html,body{margin:0;background:transparent}svg{display:block;width:${width}px;height:${height}px}</style>${svg}`,
    );
    const png = await page.screenshot({ omitBackground: true, clip: { x: 0, y: 0, width, height } });
    await page.close();

    return png;
}

// PNG をそのまま包んだ ICO。ICONDIR（6 バイト）+ ICONDIRENTRY（16 バイト × 枚数）+ 画像データ
function buildIco(entries) {
    const header = Buffer.alloc(6);
    header.writeUInt16LE(0, 0);
    header.writeUInt16LE(1, 2);
    header.writeUInt16LE(entries.length, 4);

    let offset = 6 + 16 * entries.length;
    const dirs = entries.map(({ size, png }) => {
        const dir = Buffer.alloc(16);
        dir.writeUInt8(size, 0);
        dir.writeUInt8(size, 1);
        dir.writeUInt16LE(1, 4);
        dir.writeUInt16LE(32, 6);
        dir.writeUInt32LE(png.length, 8);
        dir.writeUInt32LE(offset, 12);
        offset += png.length;

        return dir;
    });

    return Buffer.concat([header, ...dirs, ...entries.map(({ png }) => png)]);
}

const iconSvg = '/out/images/icon.svg';

await writeFile(`${OUT_IMAGES}/icon-192.png`, await rasterize(iconSvg, 192));
await writeFile(`${OUT_IMAGES}/apple-touch-icon.png`, await rasterize('/e2e/icons/apple-touch-icon.svg', 180));
await writeFile(
    `${OUT_PUBLIC}/favicon.ico`,
    buildIco([
        { size: 16, png: await rasterize(iconSvg, 16) },
        { size: 32, png: await rasterize(iconSvg, 32) },
    ]),
);

await writeFile(`${OUT_IMAGES}/og-image.png`, await rasterize('/e2e/icons/og-image.svg', { width: 1200, height: 630 }));

await browser.close();

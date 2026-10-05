# Data Model: ファビコンと OG 情報

永続化するデータはない（FR-014）。Domain の概念も増えない。ここでは、画面ごとのメタ情報と静的ファイルの対応を定義する。

## ページ別のメタ情報

`base.html.twig` のブロックで表す。PHP の型は作らない（research R1）。

| 画面 | テンプレート | `title` | `meta_description` | `robots` |
|---|---|---|---|---|
| トップ | `home/index.html.twig` | `UMIYOMI｜風・波・うねりの予報を出航前に確認`（既定を上書き） | 共通の説明文 | 出さない（登録を許可） |
| 予報（200・422・429・503 すべて） | `forecast/index.html.twig` | `{page.location ?? '予報'} \| UMIYOMI`（既存のまま） | 共通の説明文 | `noindex` |
| フィードバック案内 | `feedback/index.html.twig` | `フィードバック \| UMIYOMI`（既存のまま） | `UMIYOMI へのご意見・ご要望の案内です。` | `noindex`（既存の `head_meta` と統合） |
| エラー | `bundles/TwigBundle/Exception/error.html.twig` | `エラー \| UMIYOMI` | 共通の説明文 | `noindex` |

- `og:title`・`og:description` は、それぞれ `title`・`meta_description` と同じ値を使う（ブロックを 2 度書かない。Twig の `block()` 関数で再利用する）。
- 予報画面で `page.location` が入るのは、予報（または 429・503 の地点）が決まったときだけ。値は丸めた数値から作った `北緯 27.75° / 東経 129.05°` の形で、利用者の入力文字列は含まない（FR-009、FR-010）。入力不正（422）では `location` が `null` になり、タイトルは「予報 | UMIYOMI」になる。

## 共通の `<head>` の要素

| 種類 | 値 |
|---|---|
| `og:site_name` | `UMIYOMI` |
| `og:type` | `website` |
| `og:locale` | `ja_JP` |
| `og:image` | `{site_origin}{asset('images/og-image.png')}`（絶対 URL） |
| `og:image:width` / `og:image:height` | `1200` / `630` |
| `og:image:alt` | `UMIYOMI のロゴ。風・波・うねりの予報を出航前に確認するサービス` |
| `twitter:card` | `summary_large_image` |
| `theme-color` | 付けない（ライト・ダークで見た目が変わるため。必要になってから足す） |

## 静的ファイル

| パス | 種類 | サイズ | 備考 |
|---|---|---|---|
| `backend/public/favicon.ico` | ICO（PNG 埋め込み） | 16px・32px | 固定パス。直接取りに来る環境向け |
| `backend/assets/images/icon.svg` | SVG | ベクター | `@media (prefers-color-scheme: dark)` を内包。ダークで背景に埋もれないよう、地を塗った角丸にする |
| `backend/assets/images/icon-192.png` | PNG | 192×192 | |
| `backend/assets/images/apple-touch-icon.png` | PNG | 180×180 | 不透明・全面塗り・マークは中央 70% |
| `backend/assets/images/og-image.png` | PNG | 1200×630 | 300KB 未満・端から 10% の余白 |

## 状態遷移

なし。

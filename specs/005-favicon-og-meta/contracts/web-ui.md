# Contract: 画面（`<head>`）と静的ファイル

新しいルート・Controller・Query はない。変わるのは全画面の `<head>` と、新しく公開される静的ファイルの URL だけ。

## 全画面の `<head>`（`base.html.twig`）

```html
<title>{title}</title>
<meta name="description" content="{meta_description}">
<meta name="robots" content="noindex">                 <!-- トップだけ出さない -->

<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="{asset: images/icon.svg}" type="image/svg+xml">
<link rel="icon" href="{asset: images/icon-192.png}" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="{asset: images/apple-touch-icon.png}">

<meta property="og:site_name" content="UMIYOMI">
<meta property="og:type" content="website">
<meta property="og:locale" content="ja_JP">
<meta property="og:title" content="{title}">
<meta property="og:description" content="{meta_description}">
<meta property="og:image" content="{site_origin}{asset: images/og-image.png}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="UMIYOMI のロゴ。風・波・うねりの予報を出航前に確認するサービス">
<meta name="twitter:card" content="summary_large_image">
```

- 属性値は Twig の自動エスケープに任せる。`|raw` は使わない。
- `og:url`・`theme-color`・`manifest` は出さない（research R5・R6、data-model）。
- 既存の `feedback/index.html.twig` の `<meta name="referrer" content="no-referrer">` と `noindex` は維持する。`robots` は base のブロックで出すので、`head_meta` には `referrer` だけを残す（`noindex` が二重にならないように）。

## ページ別の文言

| 画面 | `title` | `meta_description` |
|---|---|---|
| トップ | UMIYOMI｜風・波・うねりの予報を出航前に確認 | 緯度・経度を入力すると、風・波・うねりの時間別予報を確認できます。出航の判断には、気象庁などの警報・注意報も確認してください。 |
| 予報 | `{北緯 27.75° / 東経 129.05°} \| UMIYOMI`。地点がなければ `予報 \| UMIYOMI` | 上と同じ |
| フィードバック案内 | フィードバック \| UMIYOMI | UMIYOMI へのご意見・ご要望の案内です。 |
| エラー | エラー \| UMIYOMI | 上の共通の説明文と同じ |

禁止する語（SC-004）：「安全です」「出航できます」「問題ありません」。Functional Test で全画面の `<head>` を検査する。

## 静的ファイルの URL

| URL | 返すもの | 備考 |
|---|---|---|
| `/favicon.ico` | `image/vnd.microsoft.icon`（または `image/x-icon`） | 固定パス。ハッシュなし |
| `/assets/images/icon-{hash}.svg` | `image/svg+xml` | ダーク対応 |
| `/assets/images/icon-192-{hash}.png` | `image/png` | |
| `/assets/images/apple-touch-icon-{hash}.png` | `image/png` | |
| `/assets/images/og-image-{hash}.png` | `image/png` | `og:image` の参照先 |

- いずれも Symfony の Controller・回数制限・Provider を通らない（FR-012）。dev では AssetMapper の開発用ルート、prod では `asset-map:compile` 済みの静的ファイルとして返る。
- ハッシュは内容から決まる。画像を差し替えると URL が変わる。

## 検索エンジンへの公開範囲

| 画面 | `<meta name="robots">` | 備考 |
|---|---|---|
| トップ | なし | 本番の nginx の `X-Robots-Tag: noindex, nofollow` が残っている間は登録されない（plan の判断点 4） |
| 予報・フィードバック案内・エラー | `noindex` | 共有プレビュー（OG）は機能する |

`robots.txt` は作らない。

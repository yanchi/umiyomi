# Contract: 公開 URL・`<head>`・トップの本文・Web サーバー

`{origin}` は `DEFAULT_URI` の末尾の `/` を除いた値（本番は `https://umiyomi.isl-mentor.com`）。Host ヘッダーは使わない。

## 新しいルート

| URL | メソッド | 応答 | ヘッダー |
|---|---|---|---|
| `/robots.txt` | GET | 200 | `Content-Type: text/plain; charset=UTF-8`、`X-Robots-Tag: noindex` |
| `/sitemap.xml` | GET | 200 | `Content-Type: application/xml; charset=UTF-8`、`X-Robots-Tag: noindex` |

- どちらもクエリを使わない（Request を受け取らない）。予報の取得・回数制限・計測を通らない
- `HEAD` は Symfony が `GET` と同じく扱う

### `/robots.txt`

```text
User-agent: *
Disallow:

Sitemap: {origin}/sitemap.xml
```

### `/sitemap.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{origin}/</loc>
    </url>
</urlset>
```

- 登録対象ページ（トップ）だけ。`lastmod`・`changefreq`・`priority` は出さない

## `<head>` の変更

### `base.html.twig`

```twig
{% block robots %}<meta name="robots" content="noindex">{% endblock %}   {# 既存のまま #}
{% block canonical %}{% endblock %}                                       {# 新規：既定は出さない #}
{% block structured_data %}{% endblock %}                                 {# 新規：既定は出さない #}
```

### 画面ごと

| 画面 | `robots` | `canonical` | JSON-LD | `description` |
|---|---|---|---|---|
| トップ `/`（クエリの有無を問わない） | なし | `<link rel="canonical" href="{origin}/">` | `WebSite`（下記） | トップ専用（下記） |
| 予報 `/forecast`（200 のほか 422・429・503 を含む） | `noindex` | なし | なし | 共通（005 のまま） |
| フィードバック案内 `/feedback` | `noindex` | なし | なし | 005 のまま |
| 外部送信の案内 `/external-transmission` | `noindex` | なし | なし | 006 のまま |
| エラー（404・500 など） | `noindex` | なし | なし | 共通（005 のまま） |

- タイトルは全画面で変えない。トップ：「UMIYOMI｜風・波・うねりの予報を出航前に確認」
- `og:description` は 005 のとおり `description` と同じ値になる（トップだけ新しい説明文になる）
- `og:url` は引き続き出さない

### トップの説明文（`meta_description` の上書き）

> 出航前に、指定した緯度・経度の風速・風向・波高・波向・波周期・うねりの時間別予報を一覧で確認できます。出航の判断には、気象庁などの警報・注意報もあわせて確認してください。

全角 120 文字以内（FR-010）。

### トップの JSON-LD

```html
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"UMIYOMI","alternateName":"ウミヨミ","url":"{origin}/","description":"{トップの説明文}","inLanguage":"ja"}</script>
```

- Twig で配列を組み、`json_encode(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)|raw` で出す
- `offers`・`aggregateRating`・`review`・`Organization` など、画面にない情報は含めない（FR-011）
- `description` はトップの説明文と同じ文字列（二重管理しないよう、テンプレートで同じ変数を使う）

## トップの本文

### 見出しの構造（FR-009）

```text
h1  UMIYOMI ／ 風・波・うねりの予報を出航前に確認   ← h1 は 1 つだけ
    （既存のリード文）
    （入力欄：forecast/_form.html.twig。変更なし）
h2  お気に入り                                   ← 既存。変更なし
h2  UMIYOMI でできること                          ← 新規（section.home-guide 内）
h2  使い方                                       ← 新規
h2  ご利用にあたって                              ← 新規
```

### h1

```html
<h1 class="site-title">
    <a href="/">UMIYOMI</a>
    <span class="site-title__tagline">風・波・うねりの予報を出航前に確認</span>
</h1>
<p class="lead">緯度・経度を入力すると、風・波・うねりの時間別予報を表示します。</p>
```

- 予報・案内・エラー画面の見出しは変えない

### 本文（案。文言の最終確認は人間のレビュー）

**UMIYOMI でできること**

> UMIYOMI（ウミヨミ）は、指定した緯度・経度の風・波・うねりの予報を、時間ごとの一覧で確認できる Web サービスです。およそ 3 日先までの海況の変化を、出航前に数値で確かめられます。会員登録なしで使えます。
>
> 予報画面に表示する項目：
> - 風：風速（m/s）・突風（m/s）・風向
> - 波：波高（m）・波向・波周期（秒）
> - うねり：うねり高さ（m）・うねり向き・うねり周期（秒）
>
> 予報画面には、予報を取得した日時を「最終更新」として表示します。

**使い方**

> 1. 予報を見たい地点の緯度・経度を入力します（例：緯度 27.75、経度 129.05）。度分（27°45.0'N）の形や、「緯度, 経度」の貼り付けでも入力できます。
> 2. 「予報を表示」を押すと、風・波・うねりの時間別の予報が表示されます。
> 3. よく見る地点は、予報画面でお気に入りに保存できます。お気に入りはこのブラウザに保存され、次からはトップのお気に入りから開けます。

**ご利用にあたって**

> UMIYOMI の予報は、出航を判断するための材料の一つです。航海の安全を保証するものではありません。出航前には、気象庁などが発表する警報・注意報もあわせて確認してください。

### 本文の制約

- `section.home-guide` の中に `a[href]` を置かない（予報画面へのリンク・特定の地点を勧める表現を含めない。FR-007a）
- 断定表現（「安全です」「出航できます」「問題ありません」）を含めない（FR-012）
- サーバーで描画する静的な文章。`hidden` を付けず、JavaScript・localStorage に依存しない
- 375×667（および e2e の iPhone 13）で、スクロールなしに緯度・経度の入力欄と「予報を表示」ボタンが見える（SC-006）

## Web サーバー（`deploy/nginx/umiyomi.conf`）

| 応答 | 変更前 | 変更後 |
|---|---|---|
| アプリへのプロキシ（`location /`） | `X-Robots-Tag: noindex, nofollow` | 付けない（アプリの meta に任せる） |
| certbot のチャレンジ（`/.well-known/acme-challenge/`） | `X-Robots-Tag: noindex, nofollow` | `X-Robots-Tag: noindex`（`always`） |

VPS 上の反映は運営者が deploy/README.md の手順で行う。

## 変えないもの

- 予報・案内・エラー画面の `robots`（`noindex`）・タイトル・説明文・OG
- localStorage の形式（002・006）、計測の設定（006）
- 予報の取得・キャッシュ・回数制限

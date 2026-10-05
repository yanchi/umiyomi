# Data Model: 検索エンジン対策（SEO）

この機能は永続化するデータを持たない（FR-014）。テーブル・localStorage のキー・PHP の ViewModel は増やさない。
spec の Key Entities は、テンプレートと設定値で次のように表す。

## 公開アドレスの基準（origin）

| 項目 | 内容 |
|---|---|
| 出所 | 環境変数 `DEFAULT_URI`（既存の Twig global `site_origin`。005 で導入） |
| 値の例 | 本番 `https://umiyomi.isl-mentor.com`／開発・テスト `http://localhost`／`verify-prod.sh` `https://verify.example.com` |
| 規則 | 末尾の `/` を除いて使う（`site_origin\|trim('/', 'right')`。005 の og:image と同じ）。Host ヘッダー・リクエストのクエリは使わない（FR-006） |

## 登録対象ページ

| ページ | ルート名 | 正規のアドレス | 一覧（sitemap）に載せる | `robots` |
|---|---|---|---|---|
| トップ | `app_home` | `{origin}/` | ○ | なし（登録可） |
| 予報 | `app_forecast` | なし | × | `noindex` |
| フィードバック案内 | `app_feedback` | なし | × | `noindex` |
| 外部送信の案内 | `app_external_transmission` | なし | × | `noindex` |
| エラー | （TwigBundle） | なし | × | `noindex` |
| `robots.txt`・`sitemap.xml` | 新規 | なし | × | `X-Robots-Tag: noindex`（ヘッダー） |

- 登録対象は現時点でトップの 1 件だけなので、一覧を PHP の配列・設定値として持たない。`sitemap.xml.twig` と `home/index.html.twig` がそれぞれ `path('app_home')` を使う
- 新しい画面は、`base.html.twig` の既定（`robots` は `noindex`、`canonical`・`structured_data` は空）により、何もしなければ登録対象にならない
- 登録対象を増やすとき（MVP の範囲外）は、そのテンプレートで `robots`・`canonical` を上書きし、`sitemap.xml.twig` に追加する

## 正規のアドレス

- `{origin}` + `path('app_home')`（= `{origin}/`）
- クエリ・フラグメントを含まない。`/?utm_source=...` で開いても同じ値（US2-3）

## 構造化データ（WebSite）

| プロパティ | 値 | 画面上の対応 |
|---|---|---|
| `@type` | `WebSite` | — |
| `name` | `UMIYOMI` | h1 |
| `alternateName` | `ウミヨミ` | 本文「UMIYOMI（ウミヨミ）」 |
| `url` | `{origin}/` | canonical と同じ |
| `description` | トップの説明文 | `<meta name="description">` と同じ文字列 |
| `inLanguage` | `ja` | `<html lang="ja">` |

## 状態の遷移

なし。ただし本番の公開状態は、運営者の Web サーバー設定の反映で次のように変わる（spec Edge Cases）。

| 状態 | アプリ | nginx の `X-Robots-Tag` | トップ | その他の画面 |
|---|---|---|---|---|
| 現在 | 006 まで | 全応答に `noindex, nofollow` | 登録されない | 登録されない |
| アプリだけデプロイ済み | 007 | 全応答に `noindex, nofollow` | 登録されない（robots.txt・sitemap は取得できる） | 登録されない |
| nginx だけ反映済み（順番を誤った場合） | 006 まで | チャレンジだけ | 登録可（robots.txt は 404 ＝制限なし扱い） | `noindex`（meta） |
| 公開後 | 007 | チャレンジだけ | 登録可 | `noindex`（meta） |

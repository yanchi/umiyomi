# Contract: 計測の設定・送る値・フッター・外部送信の案内画面

**Feature**: 006-analytics-tag | **Date**: 2026-10-05

ルートを 1 つ追加する（`GET /external-transmission`）。既存の `GET /`・`GET /forecast`・`GET /feedback` の Query・ステータスは変えない。画面・URL 設計と外部送信の内容（Security）は人間のレビュー対象。

## 環境変数

| 名前 | 既定 | 内容 |
|---|---|---|
| `GA_MEASUREMENT_ID` | 空 | GA4 の測定 ID（`G-` で始まる）。`^G-[A-Z0-9]{4,20}$` に合わないときは計測しない（FR-006） |

設定する場所：`backend/.env`（空）、`backend/.env.test`（空）、`compose.prod.yml`（`${GA_MEASUREMENT_ID:-}`）、`deploy/.env.production.example`（空＋説明）、`compose.yaml` の `app-e2e-analytics` だけ `G-E2ETEST000`。

## `<head>`（全画面共通、`base.html.twig`）

計測が有効（`analytics.enabled`）で、テンプレートが `analytics_page` を決めたときだけ出す。それ以外は何も出さない（`<script>` も追加しない）。

```html
<meta name="umiyomi-analytics"
      data-measurement-id="G-XXXXXXXXXX"
      data-screen="forecast"
      data-path="/forecast?lat=27.75&amp;lon=129.05">
```

| 画面 | `data-screen` | `data-path` |
|---|---|---|
| `GET /` | `home` | `/` |
| `GET /forecast`（200：予報あり・古い予報の代替表示） | `forecast` | `/forecast?lat=27.75&lon=129.05` |
| `GET /forecast`（503：取得失敗） | `forecast_unavailable` | `/forecast?lat=27.75&lon=129.05` |
| `GET /forecast`（429：回数制限） | `rate_limited` | `/forecast?lat=27.75&lon=129.05` |
| `GET /forecast`（422：入力不正） | `invalid_input` | `/forecast` |
| `GET /feedback`（200） | `feedback` | `/feedback` |
| `GET /external-transmission`（200） | `external_transmission` | `/external-transmission` |
| エラー画面（404 など） | `error` | リクエストのパス（クエリなし。例：`/no-such-page`） |

- `GET /forecast` の 303（正規化のリダイレクト）は画面を描画しないので出さない
- `data-path` に、利用者の入力文字列（入力不正の `lat`・`lon`、案内画面の `input_lat`・`input_lon`）・`updated`・`ignored` を含めない（FR-005）

## ブラウザから Google へ送る値

`gtag.js` の読み込み：`https://www.googletagmanager.com/gtag/js?id=<測定 ID>` を `async` で差し込む。次のどれかに当たるときは差し込まない。

- `<meta name="umiyomi-analytics">` がない
- localStorage の `umiyomi.analytics.optOut` が `"1"`
- JavaScript が無効

### page_view（画面を開くたびに 1 回）

```js
gtag('js', new Date());
gtag('config', 'G-XXXXXXXXXX', {
    page_location: 'https://umiyomi.isl-mentor.com/forecast?lat=27.75&lon=129.05',
    page_referrer: 'https://umiyomi.isl-mentor.com/',   // 同じオリジンはクエリを捨てる。空なら付けない
    screen_type: 'forecast',
    allow_google_signals: false,
    allow_ad_personalization_signals: false,
});
```

### 操作のイベント

| 操作 | 呼び出し | 送るタイミング |
|---|---|---|
| お気に入りの保存 | `gtag('event', 'favorite_save')` | 保存に成功し「お気に入りに保存しました」を出すとき |
| お気に入りの削除 | `gtag('event', 'favorite_delete')` | 削除に成功し「お気に入りから削除しました」を出すとき（トップの一覧・予報画面のどちらからでも） |
| 現在地ボタン | `gtag('event', 'current_location', {result: 'success'})` / `{result: 'failure'}` | 位置の取得に成功した／失敗（拒否・タイムアウト・取得不可）したとき。取得中の連打（`busy`）は送らない |
| フィードバックフォームを開く | `gtag('event', 'feedback_form_open')` | 案内画面の「フィードバックのフォームを開く（新しいタブ）」を押したとき |

イベントに緯度・経度・お気に入りの名前・入力文字列・取得した現在地を含めない（FR-004）。

## フッター（全画面共通、`base.html.twig`）

```html
<footer class="site-footer">
    <a class="site-footer__feedback" href="/feedback">フィードバック</a>
    <a class="site-footer__external-transmission" href="/external-transmission">外部送信について</a>
    <a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a>
</footer>
```

- 「外部送信について」は、計測の設定の有無に関わらず全画面に出す（FR-009）。外部送信の案内画面自身では出さない（自分自身へのリンクになるため）
- 同じタブで開く。スマホ幅で押せるよう、フィードバックのリンクと同じく高さ 44px 以上を確保する

## `GET /external-transmission`

### Query

なし（付いていても無視する）。

### Response

| 状況 | Status | 内容 |
|---|---|---|
| 常に | 200 | 外部送信の案内画面 |

- 予報の取得（UseCase・キャッシュ・回数制限）は行わない（FR-012）
- `<meta name="robots" content="noindex">`（base の既定）

### 画面の構成（上から順に）

```text
UMIYOMI（トップ画面へのリンク）
外部送信について                                              ← h1
UMIYOMI は、サービスの利用状況を把握して改善に役立てるため、
アクセス解析サービスを利用し、閲覧中のブラウザから次の情報を送信しています。

送信先
  Google LLC（Google アナリティクス）

送信される情報
  ・閲覧した画面の種類とアドレス（予報画面では、表示した地点の緯度・経度を含みます）
  ・お気に入りの保存・削除、現在地ボタン、フィードバックのフォームを開く操作の回数
    （お気に入りの地点・名前、取得した現在地は送信しません）
  ・直前に見ていたページのアドレス（UMIYOMI 内のページはクエリを除きます）
  ・端末・ブラウザの種類、画面の大きさ、言語、おおよその地域
  ・Cookie に保存される、ブラウザを識別するための ID
  緯度・経度の入力欄に入力した文字列、フィードバックのフォームに入る内容は送信しません。

利用目的
  閲覧数・利用者数の把握、よく使われる機能・よく見られる海域の把握、不具合の発見など、
  サービスの改善のため。広告の配信には利用しません。

Google によるデータの取り扱い
  Google のプライバシーポリシー（https://policies.google.com/privacy?hl=ja）
  Google のサービスを使用するサイトやアプリから収集した情報の Google による使用
  （https://policies.google.com/technologies/partner-sites?hl=ja）

計測を止める方法                                              ← h2
  ┌ このブラウザでの計測 ─────────────────────────────┐   ← 計測が有効な環境・JavaScript 有効時だけ表示
  │ 現在：このブラウザでは計測しています                    │
  │ [このブラウザでは計測しない]                           │
  └──────────────────────────────────────────┘
  切り替えはこのブラウザだけに保存され、サーバーには送信しません。

  Google が提供する「Google アナリティクス オプトアウト アドオン」
  （https://tools.google.com/dlpage/gaoptout?hl=ja）を使うと、
  UMIYOMI を含むすべてのサイトで Google アナリティクスによる計測を止められます。

← トップへ戻る
```

切り替えの表示（`data-analytics-optout` の領域。初期は `hidden`、JavaScript が状態に応じて表示）：

| 状態 | 表示 | ボタン |
|---|---|---|
| 計測中 | 「現在：このブラウザでは計測しています」 | 「このブラウザでは計測しない」 |
| 停止中 | 「現在：このブラウザでは計測していません」 | 「計測を再開する」 |
| 切り替え直後（停止） | 「このブラウザでは計測しないように設定しました。」（`role="status"`） | 「計測を再開する」 |
| 切り替え直後（再開） | 「計測を再開しました。次に開いた画面から計測されます。」（`role="status"`） | 「このブラウザでは計測しない」 |
| 保存できない | 「このブラウザでは切り替えを保存できません。上記のアドオンをご利用ください。」 | なし |

- 計測の設定がない環境：切り替えの領域を出さず、「この環境では現在、アクセス解析による計測を行っていません。」を表示する（FR-014）
- JavaScript が無効：切り替えは出ない（`hidden` のまま）。アドオンの案内は読める
- 文言に「安全です」「問題ありません」等の断定を含めない（FR-010）

## 案内画面（004）への追加

`feedback/index.html.twig` のフォームを開くリンクに `data-analytics-click="feedback_form_open"` を付ける。`href`・`target`・`rel` は変えない。

## GA4 管理画面側の設定（運営者が行う。UMIYOMI の実装外）

公開前に必ず設定し、quickstart の手順で確かめる（research R6）。

| 項目 | 設定 |
|---|---|
| データストリーム → 拡張計測機能 | 「ページビュー」以外（スクロール数・離脱クリック・サイト内検索・フォームの操作・動画エンゲージメント・ファイルのダウンロード）をすべて無効。ページビューの詳細設定で「ブラウザの履歴イベントに基づくページの変更」も無効 |
| データの収集 → Google シグナル | 無効 |
| データ保持 | 2 か月 |
| サービス間のリンク | Google 広告などの広告サービスとリンクしない |
| カスタム定義 | イベントスコープのカスタムディメンション `screen_type`・`result` を登録 |

**離脱クリックを有効にすると、フィードバックのフォームへのリンク（事前入力付き。入力文字列を含みうる）が `link_url` として送られる**。コードからは止められないため、この設定は必須。

# Data Model: アクセス解析の導入

サーバー側に新しい保存（DB・キャッシュ・ファイル）は作らない（FR-011）。Domain・Application は変更しない。
ここでは、Presentation 層の値・ブラウザに保存する値・Google アナリティクスへ送る値の形を定める。

## 計測の設定（`AnalyticsTag`）

`App\Presentation\Web\Analytics\AnalyticsTag`（readonly クラス。Twig の global `analytics`）

| 項目 | 型 | 内容 |
|---|---|---|
| `measurementId`（コンストラクタ引数） | string | 環境変数 `GA_MEASUREMENT_ID`。既定は空 |
| `isEnabled()` | bool | `measurementId` が `^G-[A-Z0-9]{4,20}$` に一致するとき true |
| `measurementId()` | string | 有効なときの ID。無効なときに呼ぶと `LogicException`（004 の `FeedbackFormLink::urlFor` と同じ流儀） |

検証規則：空・前後の空白・小文字・`G-` 以外の接頭辞（`UA-` など）は無効。無効なら `<meta>` を出さない（FR-006）。

## 画面の計測情報（`analytics_page`）

各テンプレートが Twig 変数 `analytics_page` を `{screen: string, path: string}` で決め、`base.html.twig` が `<meta>` に出す。

| 画面 | テンプレート | `screen` | `path` | 値の出どころ |
|---|---|---|---|---|
| トップ | `home/index.html.twig` | `home` | `/` | 固定 |
| 予報表示（Fresh・Stale） | `forecast/index.html.twig` | `forecast` | `/forecast?lat=…&lon=…` | `page.analyticsScreen`・`page.analyticsQuery` |
| 予報取得失敗（Unavailable） | 同上 | `forecast_unavailable` | 同上 | 同上 |
| 回数制限（RateLimited） | 同上 | `rate_limited` | 同上 | 同上 |
| 入力不正 | 同上 | `invalid_input` | `/forecast` | `analyticsQuery` が空 |
| フィードバック案内 | `feedback/index.html.twig` | `feedback` | `/feedback` | 固定（クエリは付けない） |
| 外部送信の案内 | `external_transmission/index.html.twig` | `external_transmission` | `/external-transmission` | 固定 |
| エラー | `bundles/TwigBundle/Exception/error.html.twig` | `error` | `app.request.pathInfo` | リクエストのパス（クエリなし） |

`base.html.twig` の既定（どのテンプレートも決めなかったとき）は `{screen: 'error', path: '/'}` とせず、**`<meta>` を出さない**。新しい画面を足したときに、入力文字列入りのアドレスを既定で送らないため（許可リスト方式。research R3）。

### `ForecastPageViewModel` への追加

| フィールド | 型 | 内容 |
|---|---|---|
| `analyticsScreen` | `'forecast'\|'forecast_unavailable'\|'rate_limited'\|'invalid_input'` | `ForecastPageViewModelFactory` が `ForecastStatus` と入力の妥当性から決める |
| `analyticsQuery` | `array{lat: string, lon: string}\|array{}` | 受け付けた（正規化済みの）緯度・経度。入力不正のときは空配列。`ignored` は含めない |

対応：`createForInvalidInput()` → `invalid_input`・空。`create()` → `ForecastStatus::Fresh`・`Stale` は `forecast`、`Unavailable` は `forecast_unavailable`、`RateLimited` は `rate_limited`。

## ページに出す計測の設定（HTML）

計測が有効で、かつテンプレートが `analytics_page` を決めたときだけ、`<head>` に 1 つ出す。

```html
<meta name="umiyomi-analytics"
      data-measurement-id="G-XXXXXXXXXX"
      data-screen="forecast"
      data-path="/forecast?lat=27.75&amp;lon=129.05">
```

属性値は Twig の自動エスケープを通す。`data-path` は `path()` で組み立てる（文字列連結しない）。

## 閲覧の記録（GA4 の page_view）

`analytics.js` が `gtag('config', <ID>, {...})` で送る。

| パラメーター | 値 | 備考 |
|---|---|---|
| `page_location` | `location.origin` + `data-path` | 既定の現在アドレスを上書き（research R3） |
| `page_referrer` | `document.referrer` を整形したもの | 同じオリジン → `origin + pathname`、別オリジン → そのまま、空 → 付けない（research R4） |
| `screen_type` | `data-screen` | カスタムディメンション（イベントスコープ）として登録 |
| `allow_google_signals` | `false` | FR-003 |
| `allow_ad_personalization_signals` | `false` | FR-003 |

`page_title`（`document.title`）・端末・ブラウザ・言語・画面サイズ・Cookie の識別子（`client_id`）は GA4 が既定で送る。タイトルは 005 で入力文字列を含まないことをテスト済み（`HeadMetaTest`）。

## 操作の記録（GA4 のイベント）

`track(name, params)` が許可リストで絞ってから `gtag('event', name, params)` を呼ぶ。

| `name` | 許可するパラメーター | 値 |
|---|---|---|
| `favorite_save` | なし | — |
| `favorite_delete` | なし | — |
| `current_location` | `result` | `success` \| `failure` |
| `feedback_form_open` | なし | — |

規則：
- 許可リストにない `name` は送らない（戻り値 null）。
- 許可リストにないパラメーター、許可された値以外（`result: 'denied'` など）は落とす。`current_location` で `result` が不正なら送らない。
- イベントにも `config` の `page_location`・`page_referrer` が付く（入力文字列は含まれない）。

## 計測を止めた状態（ブラウザ）

| 項目 | 値 |
|---|---|
| 保存先 | `window.localStorage` |
| キー | `umiyomi.analytics.optOut` |
| 値 | `"1"`（計測しない）。キーがない＝計測する（既定） |
| 読み取り | `"1"` のときだけ true。それ以外の値・読み取りの例外は false（計測する） |
| 書き込み | 「計測しない」→ `setItem(key, "1")`、「再開する」→ `removeItem(key)`。例外なら失敗を返し、画面は「保存できません」を表示 |

お気に入り（`umiyomi.favorites`、002）の形式は変えない。

### 状態遷移（外部送信の案内画面の切り替え）

```text
          [このブラウザでは計測しない]
 計測中 ───────────────────────────────▶ 停止中
   ▲     （保存成功。その画面の gtag も ga-disable で止める）   │
   │                                                          │
   └──────────────────────────────────────────────────────────┘
          [計測を再開する]（保存成功。次に開いた画面から計測）

 保存に失敗・localStorage が使えない → 「切り替えを保存できません」＋ Google のオプトアウト アドオンの案内
 計測の設定がない環境 → 切り替えを出さず「この環境では計測を行っていません」
```

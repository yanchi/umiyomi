# Data Model: フィードバック導線

**Feature**: 004-feedback-channel | **Date**: 2026-10-05

UMIYOMI はフィードバックの内容を受け取らず、保存もしない（FR-001）。テーブル・localStorage のキーは増えない。
ここで定めるのは、案内画面が受け取る値（文脈）、外部フォームへ渡す事前入力の文章、それを扱う PHP の型である。
すべて `App\Presentation\Web` 配下（[research R1](research.md#r1-層の置き場所)）。

## 文脈（FeedbackContext）

案内画面へ来た元の画面の状態。`GET /feedback` の Query から 1 つに決まる（[research R4](research.md#r4-案内画面が受け取る値queryの形と検証)）。

| 種類 | 元の画面 | 持つ値 | 戻り先 |
|---|---|---|---|
| `None` | トップ画面、または値が正しくないリンク | なし | `/`（「トップ画面に戻る」） |
| `Forecast` | 予報画面（予報あり・代替表示・取得失敗・回数制限） | 緯度・経度（正規形の文字列）、最終更新（`YYYY/MM/DD HH:mm` または null） | `/forecast?lat=&lon=`（「予報画面に戻る」） |
| `RejectedInput` | 入力を受け付けなかった予報画面（422） | 緯度欄・経度欄の文字列（各 100 文字まで。片方は空でもよい） | `/forecast?lat=<緯度欄>&lon=<経度欄>`（「入力画面に戻る」） |

検証の規則（FR-016）：

- 緯度・経度：`^-?\d{1,3}\.\d{2}$`、緯度 -90〜90・経度 -180〜180。両方が正しいときだけ使う
- 最終更新：`YYYY/MM/DD HH:mm` で、実在する日時。地点がないときは使わない
- 入力の文字列：正しい UTF-8。制御文字（`\p{Cc}`）は半角空白に置き換え、先頭 100 文字まで。2 欄とも空なら `None`
- 正しくない値は捨てる。エラーにしない

## 事前入力の文章

フォームの 1 つの欄（`FEEDBACK_FORM_PREFILL_FIELD`）に入れる 1 行の文章。案内画面にも同じ内容を表示する（FR-008、FR-015）。

| 文脈 | 文章 |
|---|---|
| `None` | なし（欄を付けない） |
| `Forecast`（最終更新あり） | `緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00` |
| `Forecast`（最終更新なし） | `緯度 27.75・経度 129.05` |
| `RejectedInput` | `受け付けられなかった入力：緯度欄「北緯二十七度」、経度欄「129.05」`（空の欄は `（空）`） |

- お気に入りの一覧・端末・ブラウザの情報は入れない（FR-010、spec Assumptions）
- 案内画面での表示は、`Forecast` は「緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00 がフォームに入ります」のような案内文、
  `RejectedInput` は欄ごとの引用（`<blockquote>`）で、UMIYOMI の案内文と区別する（FR-017）

## PHP の型

### `Presentation/Web/Input/FeedbackContextParser`（新規）

```php
public function parse(string $latitude, string $longitude, string $updated, string $inputLatitude, string $inputLongitude): FeedbackContext
```

- 上記の検証の規則と、文脈を決める順序（`Forecast` → `RejectedInput` → `None`）を持つ
- `public const int MAX_INPUT_LENGTH = 100;`（予報画面がリンクを作るときにも使う）

### `Presentation/Web/Input/FeedbackContext`（新規、final readonly）

| プロパティ | 型 | `None` | `Forecast` | `RejectedInput` |
|---|---|---|---|---|
| `type` | `FeedbackContextType`（enum：`None` / `Forecast` / `RejectedInput`） | | | |
| `latitude` / `longitude` | `?string` | null | `27.75` など | null |
| `lastUpdated` | `?string` | null | `2026/10/05 09:00` または null | null |
| `inputLatitude` / `inputLongitude` | `?string` | null | null | 文字列（空文字を含む） |

不正な組み合わせを作れないよう、名前付きコンストラクタ `none()` / `forecast()` / `rejectedInput()` だけで作る。

### `Presentation/Web/Feedback/FeedbackFormLink`（新規、サービス・Twig global `feedback_form`）

```php
public function __construct(string $formUrl, string $prefillField)  // %env(FEEDBACK_FORM_URL)% / %env(FEEDBACK_FORM_PREFILL_FIELD)%
public function isAvailable(): bool       // 両方が設定され、URL が https:// で始まり # を含まない
public function urlFor(?string $prefill): string  // 未設定なら LogicException
```

- `urlFor` は [research R3](research.md#r3-事前入力の-url-の組み立て) のとおり文字列で Query を足す。`$prefill` が null なら設定された URL をそのまま返す

### `Presentation/Web/ViewModel/FeedbackPageViewModel`（新規、final readonly）

| プロパティ | 型 | 内容 |
|---|---|---|
| `formUrl` | `string` | 事前入力付きの外部フォームの URL |
| `prefillSummary` | `?string` | `Forecast` のときの「…がフォームに入ります」 |
| `quotedInputs` | `?array{latitude: string, longitude: string}` | `RejectedInput` のときの引用する文字列（空の欄は `（空）`） |
| `backRoute` / `backParameters` / `backLabel` | `string` / `array<string, string>` / `string` | 戻るリンク（R6） |

`FeedbackPageViewModelFactory::create(FeedbackContext): FeedbackPageViewModel` が、事前入力の文章・案内文・戻り先を決める（`FeedbackFormLink` を使う）。

### `ForecastPageViewModel`（変更）

- `feedbackQuery: array<string, string>` を追加する。`ForecastPageViewModelFactory` が作る：

| 予報画面の状態 | `feedbackQuery` |
|---|---|
| 予報あり（新しい予報・代替表示） | `lat`・`lon`（`favoriteTarget` と同じ `%.2f`）、`updated`（`fetchedAt` の `Y/m/d H:i`。「最終更新」の表示と同じ） |
| 取得失敗・回数制限 | `lat`・`lon` |
| 入力エラー（422） | `input_lat`・`input_lon`（入力欄の文字列の先頭 `MAX_INPUT_LENGTH` 文字） |

### 設定

| 環境変数 | `.env`（既定） | `.env.test` | 本番 |
|---|---|---|---|
| `FEEDBACK_FORM_URL` | 空（リンクを出さない） | `https://forms.example.test/feedback?usp=pp_url` | 運営者が用意したフォームの URL |
| `FEEDBACK_FORM_PREFILL_FIELD` | 空 | `entry.1000` | フォームの事前入力の欄の名前 |

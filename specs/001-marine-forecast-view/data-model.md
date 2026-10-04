# Data Model: 地点指定による海況予報の時間別表示

**Feature**: 001-marine-forecast-view | **Date**: 2026-10-05

サーバー側の永続化（DB）はない。予報は Symfony Cache に最大 24 時間保持するだけ（research R4）。
以下はレイヤーごとのオブジェクトと、レイヤーをまたぐときの変換。

```text
Open-Meteo JSON ──(Infrastructure: OpenMeteo*Response)──> Provider 固有 DTO ──(OpenMeteoResponseMapper)──> Domain: MarineForecast
Domain: MarineForecast ──(Application: ViewMarineForecast)──> Application DTO: MarineForecastResult
Application DTO ──(Presentation: ForecastPageViewModelFactory)──> ViewModel ──> Twig
```

Deptrac の設定により Presentation は Domain を参照できない。Controller・Twig が扱うのは Application の DTO と Presentation の ViewModel だけ。

---

## Domain（`src/Domain/Marine/`）

Symfony・Open-Meteo に依存しない純粋な PHP。

### Coordinate（Value Object）

| フィールド | 型 | ルール |
|---|---|---|
| latitude | float | -90 ≤ x ≤ 90。範囲外なら `InvalidArgumentException` |
| longitude | float | -180 ≤ x ≤ 180。同上 |

- 生成時に小数点以下 2 桁へ丸めた値を保持する（同一地点の判定、提供元への問い合わせ、表示のすべてで同じ値を使うため）
- `equals(Coordinate)`、`key(): string`（例 `27.75_129.05`。キャッシュキーに使う）
- Latitude / Longitude を別の VO にはしない（組で初めて意味を持ち、個別のロジックがないため）

### CompassPoint（enum）

16 方位：`N, NNE, NE, ENE, E, ESE, SE, SSE, S, SSW, SW, WSW, W, WNW, NW, NNW`

- `fromDegrees(float $degrees): self`：「来る方向」の角度（0° = 北）を 16 方位に変換する。
  360 で割った余りを使い（負の値・360 以上も受け付ける）、各方位は中心 ±11.25°。境界値は時計回り側の方位に含める
  （例：11.25° → NNE、348.75° → N）
- 日本語の表示名（北、北北東…）は Presentation の責務。Domain は方位の識別子だけを持つ

### HourlyForecast

1 つの時刻の予報値。すべての数値は欠けることがある（null）。値の単位は名前で表す。

| フィールド | 型 | 単位 / 意味 |
|---|---|---|
| time | DateTimeImmutable | 予報の対象時刻（UTC、正時） |
| windSpeed | ?float | 10m 平均風速 m/s |
| windGust | ?float | 突風（最大瞬間風速）m/s |
| windDirection | ?float | 風が来る方向（度） |
| waveHeight | ?float | 波高 m |
| waveDirection | ?float | 波が来る方向（度） |
| wavePeriod | ?float | 波周期 秒 |
| swellHeight | ?float | うねりの高さ m |
| swellDirection | ?float | うねりが来る方向（度） |
| swellPeriod | ?float | うねりの周期 秒 |

- 風速・波高などを個別の VO にはしない（値の検証・変換ロジックがなく、ラップするだけになるため。原則 II）
- 負の風速・波高、NaN は Infrastructure の変換時に null（欠損）として扱い、Domain には入れない

### Availability（enum）

項目グループ（風 / 波・うねり）ごとの取得状況（research R3）。

| 値 | 意味 |
|---|---|
| `Available` | 取得できた（一部の時刻が欠けていてもよい） |
| `NotProvidedAtLocation` | 取得はできたが、全時刻の値が null（内陸など） |
| `FetchFailed` | 提供元から取得できなかった |

### MarineForecast（Entity 相当。永続化はしない）

ある地点について、ある時点に取得した予報のまとまり。

| フィールド | 型 | 説明 |
|---|---|---|
| coordinate | Coordinate | 地点 |
| fetchedAt | DateTimeImmutable | 提供元から取得した日時（UTC）。「最終更新」に表示する |
| wind | Availability | 風グループの取得状況 |
| sea | Availability | 波・うねりグループの取得状況 |
| hours | list\<HourlyForecast\> | 時刻の昇順。重複なし |

不変条件：
- `wind` と `sea` が両方 `FetchFailed` の MarineForecast は作らない（それは取得失敗で、Provider が例外を投げる）
- `FetchFailed` / `NotProvidedAtLocation` のグループに属する値は、すべての HourlyForecast で null

ポリシー（定数として MarineForecast に置く）：

| 名前 | 値 | 用途 |
|---|---|---|
| REUSE_PERIOD | 1 時間 | `isReusableAt(now)`：`now < fetchedAt + 1h` なら取得し直さない（FR-014） |
| FALLBACK_PERIOD | 24 時間 | `isFallbackUsableAt(now)`：`now < fetchedAt + 24h` なら取得失敗時に代替表示できる（FR-013） |

- `nextRefetchAt(): DateTimeImmutable` = fetchedAt + REUSE_PERIOD（FR-018）
- `isComplete(): bool` = どちらのグループも `FetchFailed` でない。完全な予報だけをキャッシュに保存する

### ForecastPeriod（Value Object）

Provider に問い合わせる期間（research R10）。

| フィールド | 型 | ルール |
|---|---|---|
| from | DateTimeImmutable | UTC の正時 |
| to | DateTimeImmutable | UTC の正時。`from < to` |

- 不正なら `InvalidArgumentException`
- `startingAt(DateTimeImmutable $now, int $hours): self`：`now` を正時に切り捨てた時刻から `hours` 時間後まで

### ForecastTimeline（Domain Service）

`select(MarineForecast $forecast, DateTimeImmutable $now, DateTimeZone $displayTimeZone): list<HourlyForecast>`

表示する時刻を選ぶ（FR-003、research R6）。

1. `h0` = `now` を `displayTimeZone` の正時に切り捨てた時刻
2. `h0 ≤ time < h0 + 24h`：すべて
3. `h0 + 24h ≤ time ≤ h0 + 72h`：`displayTimeZone` での時が 3 の倍数のものだけ
4. それ以外（過去・72 時間より先）は含めない

### FetchFailure（例外）

`MarineForecastProvider` が「風・波・うねりのどちらも取得できなかった」ときに投げる。原因（タイムアウト / HTTP エラー / 形式不正）をメッセージに含める（ログ用。画面には出さない）。

---

## Application（`src/Application/Marine/`）

### Port

| インターフェース | メソッド | 実装（Infrastructure） |
|---|---|---|
| `MarineForecastProvider` | `forecast(Coordinate, ForecastPeriod): MarineForecast`（全体失敗時は `FetchFailure`） | `OpenMeteoMarineForecastProvider` |
| `MarineForecastCache` | `find(Coordinate): ?MarineForecast` / `save(MarineForecast): void` | `SymfonyMarineForecastCache` |
| `FetchRateLimiter` | `tryConsume(string $clientKey): bool` | `SymfonyFetchRateLimiter` |
| `Clock` | `now(): DateTimeImmutable` | `SystemClock`（symfony/clock） |

### UseCase：ViewMarineForecast

Input `ViewMarineForecastInput`：

| フィールド | 型 | 説明 |
|---|---|---|
| latitude | float | Presentation で検証済みの値 |
| longitude | float | 同上 |
| clientKey | string | 問い合わせ回数を数える単位（接続元 IP）。HTTP の Request は渡さない |

処理（判断の順序が FR-019 の「再利用できる予報は回数に数えない」を実現する）：

```text
now      = Clock.now()
cached   = Cache.find(coordinate)
if cached && cached.isReusableAt(now):           → Fresh(cached)
if !RateLimiter.tryConsume(clientKey):           → RateLimited
try:
    fetched = Provider.forecast(coordinate, ForecastPeriod.startingAt(now, 73))
    if fetched.isComplete(): Cache.save(fetched)
    → Fresh(fetched)
catch FetchFailure:
    if cached && cached.isFallbackUsableAt(now): → Stale(cached)
    → Unavailable
```

`Fresh` / `Stale` の予報は `ForecastTimeline.select(forecast, now, Asia/Tokyo)` で表示する時刻に絞ってから DTO に変換する。

### Output DTO（`src/Application/Marine/DTO/`）

Presentation が Domain を参照せずに済むよう、スカラーと `DateTimeImmutable`（日本時間）だけで構成する。

**MarineForecastResult**

| フィールド | 型 | 説明 |
|---|---|---|
| status | `ForecastStatus` enum | `Fresh` / `Stale` / `Unavailable` / `RateLimited` |
| forecast | ?MarineForecastView | `Fresh` / `Stale` のときだけ値がある |

**MarineForecastView**

| フィールド | 型 | 説明 |
|---|---|---|
| latitude / longitude | float | 小数点以下 2 桁に丸めた値 |
| fetchedAt | DateTimeImmutable | 日本時間 |
| nextRefetchAt | DateTimeImmutable | 日本時間（`Stale` のときも値は持つが、表示はしない） |
| wind | `GroupAvailability` enum | `Available` / `NotProvidedAtLocation` / `FetchFailed`（Domain の enum を写した Application 側の enum） |
| sea | `GroupAvailability` enum | 同上 |
| hours | list\<HourlyForecastView\> | 表示する時刻だけ（ForecastTimeline 適用後） |

**HourlyForecastView**

| フィールド | 型 | 説明 |
|---|---|---|
| time | DateTimeImmutable | 日本時間 |
| windSpeed, windGust | ?float | m/s |
| windDirection | ?string | 16 方位の識別子（`NNE` など）。角度が null なら null |
| waveHeight, swellHeight | ?float | m |
| waveDirection, swellDirection | ?string | 16 方位の識別子 |
| wavePeriod, swellPeriod | ?float | 秒 |

---

## Infrastructure の Provider 固有 DTO（`src/Infrastructure/Marine/OpenMeteo/`）

Open-Meteo の形式をそのまま写した読み取り専用の DTO。Infrastructure の外には出さない（原則 III）。

| DTO | フィールド |
|---|---|
| `OpenMeteoWeatherResponse` | `time: list<int>`（unixtime）、`windSpeed10m`、`windGusts10m`、`windDirection10m`：`list<?float>` |
| `OpenMeteoMarineResponse` | `time: list<int>`、`waveHeight`、`waveDirection`、`wavePeriod`、`swellWaveHeight`、`swellWaveDirection`、`swellWavePeriod`：`list<?float>` |

- `fromArray(array): self` で JSON を検証する。必要なキーがない・配列の長さが `time` と違う・数値でも null でもない値がある場合は形式不正として例外を投げ、Provider はその API を「取得に失敗」として扱う
- `OpenMeteoResponseMapper::toDomain(Coordinate, DateTimeImmutable $fetchedAt, ?OpenMeteoWeatherResponse, ?OpenMeteoMarineResponse): MarineForecast`：
  null は取得失敗のグループ。負の値・NaN は欠損（null）にし、全時刻 null のグループは `NotProvidedAtLocation` にする（research R2）。2 つの時刻を突き合わせて HourlyForecast を作る

---

## Presentation（`src/Presentation/Web/`）

### CoordinateQuery（Input）

`CoordinateQueryParser::parse(?string $latitude, ?string $longitude): CoordinateQuery`

| フィールド | 型 | 説明 |
|---|---|---|
| rawLatitude / rawLongitude | string | 入力欄に戻す元の文字列 |
| latitude / longitude | ?float | 検証を通った値。エラーがあれば null |
| errors | array\<'latitude'\|'longitude', string\> | 項目ごとのエラーメッセージ |

ルールは research R7。

### ForecastPageViewModel

Twig に渡す唯一のオブジェクト。表示用に整形済みの文字列だけを持つ（Twig 側で計算・分岐を増やさないため）。

| フィールド | 型 | 例 / 説明 |
|---|---|---|
| form | 入力欄の値・エラー | `27.75` / `緯度は -90〜90 の範囲で入力してください` |
| notice | ?Notice | 種別（`stale` / `unavailable` / `rate_limited`）と文言 |
| location | ?string | `北緯 27.75° / 東経 129.05°`（南緯・西経も同様） |
| lastUpdated | ?string | `最終更新：2026/10/05 20:15（21:15以降に再取得）`。`Stale` のときは括弧部分なし |
| groupMessages | list\<string\> | `この地点では波・うねりの予報が得られません` / `波・うねりの予報を取得できませんでした` など |
| table | ?ForecastTable | 下記 |

**ForecastTable**

- `columns`：各列の `dateLabel`（`10/06(火)`。日付が変わる列と最初の列だけ）、`timeLabel`（`06:00`）、`startsNewDay`（bool）、`startsThreeHourly`（bool）
- `rows`：9 行（風速 / 突風 / 風向 / 波高 / 波向 / 波周期 / うねり高さ / うねり向き / うねり周期）。各行は `label`（単位付き。`風速 (m/s)`）と列ごとの `cells`（文字列）
- 欠損値は `—`（FR-011）。取得状況が `Available` でないグループの行は表示しない（代わりに groupMessages を出す）
- 数値の書式：風速・突風・波高・うねり高さは小数点以下 1 桁、周期は小数点以下 1 桁、向きは日本語の 16 方位名

### 状態と画面の対応

| UseCase の status | HTTP | notice | 一覧 | 最終更新 |
|---|---|---|---|---|
| （入力エラー） | 422 | なし（項目ごとのエラー） | なし | なし |
| Fresh | 200 | なし | あり | あり（再取得時刻付き） |
| Stale | 200 | stale：`最新の予報を取得できませんでした。表示中は YYYY/MM/DD HH:mm 時点の予報です` | あり（現在時刻以降） | あり（再取得時刻なし） |
| Unavailable | 503 | unavailable：`予報を取得できませんでした。時間をおいて再度お試しください` | なし | なし |
| RateLimited | 429 | rate_limited：`しばらく待ってから再度お試しください` | なし | なし |

安全に関する注意書き（FR-009）は、予報ページのすべての状態で一覧の直前に表示する。

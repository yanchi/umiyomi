---

description: "Task list for 001-marine-forecast-view"
---

# Tasks: 地点指定による海況予報の時間別表示

**Input**: Design documents from `/specs/001-marine-forecast-view/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-routes.md, quickstart.md

**Tests**: Constitution 原則 V の重点領域（Domain Logic、Value Object、UseCase、ViewModel/DTO 変換、Open-Meteo レスポンス変換、Cache、時刻処理、座標 validation、主要経路の Functional Test）はテストを必須とし、実装より先に書いて失敗することを確認する。通常のテストは Fake Provider を使い、実 API を呼ぶテストは `tests/External` に分離する。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1, US2, US3）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`、名前空間は `App\` = `backend/src/`、`App\Tests\` = `backend/tests/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- 各ファイルは `declare(strict_types=1);`。コメントは日本語で「なぜ」だけを書く
- Presentation から Domain を参照しない（Deptrac）。Deptrac の設定は変更しない
- UI 文言に「安全です」「出航できます」「問題ありません」のような断定を入れない（FR-010）
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 追加パッケージと、全ストーリーが使う設定

- [ ] T001 `docker compose exec php composer require symfony/clock symfony/rate-limiter` を実行し、Flex レシピが生成した設定ファイルを確認する（`backend/composer.json`、`backend/symfony.lock`、レシピが作る `backend/config/packages/*.yaml`）
- [ ] T002 [P] `backend/.env` に `OPEN_METEO_WEATHER_URL=https://api.open-meteo.com` と `OPEN_METEO_MARINE_URL=https://marine-api.open-meteo.com` を追加する（有料プランへの切り替えや取得失敗の手動再現を設定だけで行うため。plan「Structure Decision」）
- [ ] T003 [P] `backend/assets/app.js` から雛形の `console.log` を削除する（`import './styles/app.css';` は残す）
- [ ] T004 `backend/config/packages/framework.yaml` に `http_client.scoped_clients` として `open_meteo_weather.client`（`base_uri: '%env(OPEN_METEO_WEATHER_URL)%'`）と `open_meteo_marine.client`（`base_uri: '%env(OPEN_METEO_MARINE_URL)%'`）を追加し、どちらも `timeout: 4` と `max_duration: 4` を設定する（SC-002 の 5 秒以内のため、research R1）
- [ ] T005 [P] `backend/config/packages/cache.yaml` に専用プール `cache.marine_forecast`（`default_lifetime: 86400`。24 時間 = FALLBACK_PERIOD）を追加し、`when@test` で `cache.marine_forecast` の adapter を `cache.adapter.array` にする（research R4）

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: すべてのストーリーが使う Domain、Port、DTO、テスト用の Fake と時計、共通レイアウト

**⚠️ CRITICAL**: このフェーズが終わるまでユーザーストーリーに着手しない

### Tests for Foundational (Domain) ⚠️

> 先に書き、失敗することを確認してから実装する

- [ ] T006 [P] `backend/tests/Unit/Domain/Marine/CoordinateTest.php`：境界値（緯度 ±90、経度 ±180）を受け付ける / 範囲外（90.01、-180.01 など）で `InvalidArgumentException` / 小数点以下 2 桁への丸め（27.123456789 → 27.12、27.755 → 27.76 など）/ `equals()` / `key()` が `27.75_129.05`・`-0.50_-120.00` の形式になる
- [ ] T007 [P] `backend/tests/Unit/Domain/Marine/CompassPointTest.php`：0° → N、11.24° → N、11.25° → NNE、22.5° → NNE、348.75° → N、348.74° → NNW、360° → N、-11.25° → N（時計回り側）、-22.5° → NNW、720° → N など、16 方位すべての中心値と境界値（data-model「CompassPoint」）
- [ ] T008 [P] `backend/tests/Unit/Domain/Marine/ForecastPeriodTest.php`：`startingAt(now, 73)` が now を UTC の正時に切り捨てた時刻から 73 時間後までになる / `from >= to` や正時でない値で `InvalidArgumentException`
- [ ] T009 [P] `backend/tests/Unit/Domain/Marine/MarineForecastTest.php`：`isReusableAt()`（fetchedAt + 1h の直前は true、ちょうどは false）/ `isFallbackUsableAt()`（+24h で同様）/ `nextRefetchAt()` = fetchedAt + 1h / `isComplete()`（どちらかが `FetchFailed` なら false、`NotProvidedAtLocation` は true）/ 不変条件違反（両グループ `FetchFailed`、`FetchFailed`・`NotProvidedAtLocation` のグループに値がある、時刻が昇順でない・重複）で `InvalidArgumentException`
- [ ] T010 [P] `backend/tests/Unit/Domain/Marine/ForecastTimelineTest.php`：`displayTimeZone = Asia/Tokyo` で、now = JST 20:15 のとき h0 = 20:00 / h0〜h0+23h はすべての時刻 / h0+24h〜h0+72h は JST の時が 3 の倍数の時刻だけ（h0+72h ちょうどを含む）/ h0 より前と h0+72h より後は含まない / 予報データにない時刻は列を作らない（取得から時間が経った前回予報で末尾が短くなる）/ 合計列数が 24 + 16 = 40 前後になること（h0 の時刻によって 3 時間ごとの列数が変わる点も確認）

### Implementation for Foundational

- [ ] T011 [P] `backend/src/Domain/Marine/Coordinate.php`：final readonly の VO。生成時に範囲検証と小数点以下 2 桁への丸めを行い、`latitude()` / `longitude()` / `equals()` / `key()` を持つ（data-model「Coordinate」）
- [ ] T012 [P] `backend/src/Domain/Marine/CompassPoint.php`：16 方位の backed enum（値は `N`, `NNE` … の文字列）と `fromDegrees(float): self`。日本語名は持たない
- [ ] T013 [P] `backend/src/Domain/Marine/Availability.php`：enum `Available` / `NotProvidedAtLocation` / `FetchFailed`
- [ ] T014 [P] `backend/src/Domain/Marine/HourlyForecast.php`：final readonly。`time`（UTC の正時）と 9 つの `?float`（windSpeed, windGust, windDirection, waveHeight, waveDirection, wavePeriod, swellHeight, swellDirection, swellPeriod）。風速などは VO にしない
- [ ] T015 [P] `backend/src/Domain/Marine/FetchFailure.php`：`RuntimeException` を継承した例外。原因（ログ用）をメッセージに持つ
- [ ] T016 [P] `backend/src/Domain/Marine/ForecastPeriod.php`：`from` / `to`（UTC の正時、from < to）と `startingAt(DateTimeImmutable $now, int $hours): self`
- [ ] T017 `backend/src/Domain/Marine/MarineForecast.php`：coordinate、fetchedAt（UTC）、wind / sea（Availability）、hours（list<HourlyForecast>）と不変条件の検証、定数 `REUSE_PERIOD`（1 時間）/ `FALLBACK_PERIOD`（24 時間）、`isReusableAt()` / `isFallbackUsableAt()` / `nextRefetchAt()` / `isComplete()`（T011, T013, T014 に依存）
- [ ] T018 `backend/src/Domain/Marine/ForecastTimeline.php`：`select(MarineForecast, DateTimeImmutable $now, DateTimeZone $displayTimeZone): list<HourlyForecast>`（data-model「ForecastTimeline」、research R6。T017 に依存）
- [ ] T019 [P] `backend/src/Application/Marine/Port/MarineForecastProvider.php`（`forecast(Coordinate, ForecastPeriod): MarineForecast`、全体失敗時は `FetchFailure`）、`backend/src/Application/Marine/Port/MarineForecastCache.php`（`find(Coordinate): ?MarineForecast` / `save(MarineForecast): void`）、`backend/src/Application/Marine/Port/Clock.php`（`now(): DateTimeImmutable`。PSR-20 は Deptrac で Application から参照できないため自前で定義する）を作成する
- [ ] T020 [P] `backend/src/Application/Marine/DTO/` に `ForecastStatus.php`（enum `Fresh` / `Stale` / `Unavailable` / `RateLimited`）、`GroupAvailability.php`（enum。Domain の Availability を写す）、`HourlyForecastView.php`、`MarineForecastView.php`、`MarineForecastResult.php` を作成する。スカラーと日本時間の `DateTimeImmutable` だけで構成し、Domain の型を持たない（data-model「Output DTO」）
- [ ] T021 [P] `backend/src/Infrastructure/Marine/Clock/SystemClock.php`：Application の `Clock` を `Symfony\Component\Clock\ClockInterface` で実装し、UTC の `DateTimeImmutable` を返す
- [ ] T022 [P] テスト用の部品を `backend/tests/Support/` に作成する：`FixedClock.php`（`Clock` 実装。`setNow()` / `advance()` で時刻を進められる）、`MarineForecastBuilder.php`（座標・fetchedAt・期間・Availability・各値を指定して MarineForecast を組み立てる）、`FakeMarineForecastProvider.php`（`MarineForecastProvider` 実装。既定では受け取った座標・期間の全時刻に決まった値を入れ、fetchedAt を Clock の現在時刻にした完全な予報を返す。`willFail()` で `FetchFailure`、`willReturnSeaAvailability()` で波・うねりのグループを `NotProvidedAtLocation` / `FetchFailed` にできる。呼ばれた回数を `callCount()` で返す）
- [ ] T023 `backend/config/services.yaml` に `when@test` を追加し、`App\Application\Marine\Port\MarineForecastProvider` を `App\Tests\Support\FakeMarineForecastProvider` に、`App\Application\Marine\Port\Clock` を `App\Tests\Support\FixedClock` にエイリアスする（どちらも Functional Test から取り出せるよう public。FixedClock の初期時刻は `2026-10-05T11:15:00Z` = JST 20:15。T022 に依存）
- [ ] T024 [P] `backend/templates/base.html.twig` を書き換える：`<html lang="ja">`、`<meta name="viewport" content="width=device-width, initial-scale=1">`、タイトルの既定値を `UMIYOMI`、Symfony 雛形の favicon を削除、`<footer>` に `<a href="https://open-meteo.com/">Weather data by Open-Meteo.com</a>` の帰属表示（research R1、contracts「画面の構成」8）

**Checkpoint**: Domain のテストが通り、`composer check` が通る。ユーザーストーリーに着手できる

---

## Phase 3: User Story 1 - 緯度・経度を入力して時間別予報を見る (Priority: P1) 🎯 MVP

**Goal**: 有効な緯度・経度を入力すると、Open-Meteo から取得した（または 1 時間以内に取得済みの）予報が、行＝項目・列＝時刻の表で、地点・最終更新（再取得時刻付き）・安全の注意書きとともに表示される

**Independent Test**: `/` で緯度 27.75・経度 129.05 を送信し、`/forecast?lat=27.75&lon=129.05` に 9 行の一覧、`北緯 27.75° / 東経 129.05°`、`最終更新：2026/10/05 20:15（21:15以降に再取得）`、注意書きが表示されることを確認する（Functional Test は Fake Provider を使う）

### Tests for User Story 1 ⚠️

> 先に書き、失敗することを確認してから実装する

- [ ] T025 [P] [US1] Open-Meteo の JSON フィクスチャを `backend/tests/Support/Fixtures/OpenMeteo/weather.json`・`marine.json`（27.75, 129.05 の 73 時間分。`timeformat=unixtime` の形式。一部の時刻に null と負の値を含める）と `marine_inland.json`（波・うねりの全項目が null）として作成し、`backend/tests/Unit/Infrastructure/Marine/OpenMeteo/OpenMeteoWeatherResponseTest.php`・`OpenMeteoMarineResponseTest.php` で `fromArray()` を検証する：正常なフィクスチャを読める / `hourly` や必要なキーがない、値配列の長さが `time` と違う、数値でも null でもない値がある場合に例外
- [ ] T026 [P] [US1] `backend/tests/Unit/Infrastructure/Marine/OpenMeteo/OpenMeteoResponseMapperTest.php`：2 つの DTO から時刻を突き合わせて HourlyForecast を作る（UTC の unixtime → `DateTimeImmutable`）/ 負の値・NaN は null / 波・うねりの全時刻が null なら sea = `NotProvidedAtLocation` / DTO が null のグループは `FetchFailed` で値はすべて null / fetchedAt と coordinate がそのまま入る
- [ ] T027 [P] [US1] `backend/tests/Unit/Infrastructure/Marine/OpenMeteo/OpenMeteoMarineForecastProviderTest.php`（成功時）：`MockHttpClient` で、Weather API に `/v1/forecast`・Marine API に `/v1/marine` が呼ばれ、クエリが `latitude=27.75`、`longitude=129.05`、`timezone=GMT`、`timeformat=unixtime`、`start_hour` / `end_hour`（UTC の `YYYY-MM-DDTHH:00`）、hourly の項目名（research R1）、風だけ `wind_speed_unit=ms` になること / レスポンスから MarineForecast が作られ fetchedAt が Clock の現在時刻になること
- [ ] T028 [P] [US1] `backend/tests/Unit/Infrastructure/Marine/Cache/SymfonyMarineForecastCacheTest.php`：`ArrayAdapter` で save → find が同じ予報を返す / キーが `marine_forecast.v1.27.75_129.05`（負の座標 `-0.50_-120.00` も）/ 未保存は null / 想定外の型が入っていたら null / 27.7500 と 27.75 は同じ予報を返す
- [ ] T029 [P] [US1] `backend/tests/Unit/Application/Marine/ViewMarineForecastTest.php`（取得と再利用）：FakeMarineForecastProvider・FixedClock・`SymfonyMarineForecastCache(ArrayAdapter)` を使い、未取得の地点は Provider を `ForecastPeriod::startingAt(now, 73)` で呼んで `Fresh` を返しキャッシュに保存する / 59 分後は Provider を呼ばず同じ fetchedAt の予報を返す / 60 分後は取得し直す / 返る hours は ForecastTimeline 適用後で時刻が日本時間 / 向きが `NNE` などの識別子に変換され、角度が null なら null / `nextRefetchAt` が fetchedAt + 1h
- [ ] T030 [P] [US1] `backend/tests/Unit/Presentation/Web/Input/CoordinateQueryParserTest.php`（research R7 の全ルール）：`27.75` / `-27.75` / `+27.75` / `.5` / `27.` を受け付ける / 前後の半角・全角空白を除く / 全角数字・全角ピリオド・`－`・`−`・全角プラスを変換する / 境界値 ±90・±180 を受け付ける / 範囲外は「緯度は -90〜90 の範囲で入力してください」「経度は -180〜180 の範囲で入力してください」/ 未入力・`abc`・`27,75`・`1e1` は「緯度を数値（-90〜90）で入力してください」「経度を数値（-180〜180）で入力してください」/ `N27.75`・`27°45'` は形式エラーに「十進数（例：27.75）で入力してください」を添える / エラーは項目ごとで、両方エラーなら両方出る / rawLatitude・rawLongitude は正規化前の文字列のまま
- [ ] T031 [P] [US1] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php`（Fresh）：location が `北緯 27.75° / 東経 129.05°`（南緯・西経、0 の扱いも）/ lastUpdated が `最終更新：2026/10/05 20:15（21:15以降に再取得）`、日付が変わる場合は `（10/06 00:30以降に再取得）` / columns の `timeLabel`（`06:00`）、`dateLabel`（最初の列と日付が変わる列だけ `10/06(火)`）、`startsNewDay`、`startsThreeHourly`（3 時間ごとの最初の列だけ true）/ rows が 9 行で、ラベルが `風速 (m/s)`・`突風 (m/s)`・`風向`・`波高 (m)`・`波向`・`波周期 (秒)`・`うねり高さ (m)`・`うねり向き`・`うねり周期 (秒)` / 数値は小数点以下 1 桁、向きは日本語の 16 方位名（北、北北東…）/ 欠損値は `—` で、0 と表示しない（FR-011）
- [ ] T032 [US1] `backend/tests/Functional/ForecastPageTest.php`（`WebTestCase`。`$client->disableReboot()` で Fake とキャッシュをリクエスト間で保つ）：`GET /` が 200 で、`GET /forecast` へ送る緯度・経度の入力欄と「予報を表示」ボタンがある / `GET /forecast?lat=27.75&lon=129.05` が 200 で、地点、`最終更新：2026/10/05 20:15（21:15以降に再取得）`、注意書き「この予報は航海の安全を保証するものではありません。出航前に気象庁などが発表する警報・注意報もあわせて確認してください。」、9 行の表、フッターの Open-Meteo 帰属表示がある / 同じ URL を 2 回開いても Fake の `callCount()` が 1（SC-006）

### Implementation for User Story 1

- [ ] T033 [P] [US1] `backend/src/Infrastructure/Marine/OpenMeteo/OpenMeteoWeatherResponse.php`：`time: list<int>`、`windSpeed10m` / `windGusts10m` / `windDirection10m`：`list<?float>` と、形式を検証する `fromArray(array): self`（data-model「Provider 固有 DTO」）
- [ ] T034 [P] [US1] `backend/src/Infrastructure/Marine/OpenMeteo/OpenMeteoMarineResponse.php`：`time`、`waveHeight` / `waveDirection` / `wavePeriod` / `swellWaveHeight` / `swellWaveDirection` / `swellWavePeriod` と `fromArray(array): self`
- [ ] T035 [US1] `backend/src/Infrastructure/Marine/OpenMeteo/OpenMeteoResponseMapper.php`：`toDomain(Coordinate, DateTimeImmutable $fetchedAt, ?OpenMeteoWeatherResponse, ?OpenMeteoMarineResponse): MarineForecast`（T033, T034 に依存）
- [ ] T036 [US1] `backend/src/Infrastructure/Marine/OpenMeteo/OpenMeteoMarineForecastProvider.php`：`open_meteo_weather.client` / `open_meteo_marine.client` を `#[Target]` で注入し、2 つのリクエストを先に両方発行してからレスポンスを読む（HttpClient の非同期で並列化）。DTO → Mapper で MarineForecast を返す。この段階では、どちらかの取得に失敗したら `FetchFailure` を投げる（部分取得の扱いは US2 の T055 で入れる。T035 に依存）
- [ ] T037 [P] [US1] `backend/src/Infrastructure/Marine/Cache/SymfonyMarineForecastCache.php`：`cache.marine_forecast` プールを `#[Target]` で注入し、`getItem()` / `save()` で読み書きする（`get()` のコールバック方式は「期限切れだが代替には使える」を表せないため使わない。research R4）。読み出した値が MarineForecast でなければ null
- [ ] T038 [US1] `backend/src/Application/Marine/UseCase/ViewMarineForecastInput.php`（latitude、longitude、clientKey）と `backend/src/Application/Marine/UseCase/ViewMarineForecast.php` を作成する：Clock → Cache.find → 再利用できれば `Fresh` / できなければ Provider で取得し、`isComplete()` なら保存して `Fresh`。`Fresh` の予報は `ForecastTimeline::select(forecast, now, Asia/Tokyo)` で絞り、DTO（日本時間、方位は CompassPoint の値）へ変換する。clientKey はこの段階では使わない（回数制限と取得失敗の分岐は US2 の T056 で入れる。T017〜T020 に依存）
- [ ] T039 [P] [US1] `backend/src/Presentation/Web/Input/CoordinateQuery.php` と `backend/src/Presentation/Web/Input/CoordinateQueryParser.php`：`parse(?string $latitude, ?string $longitude): CoordinateQuery`（research R7）
- [ ] T040 [P] [US1] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModel.php` と `backend/src/Presentation/Web/ViewModel/ForecastTable.php`：表示用に整形済みの文字列だけを持つ readonly クラス（form、notice、location、lastUpdated、groupMessages、table。data-model「ForecastPageViewModel」）。列・行は PHPStan の array shape で型付けした配列で持ち、plan にないクラスを増やさない
- [ ] T041 [US1] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php`：`CoordinateQuery` と `MarineForecastResult` から ViewModel を作る。方位の識別子 → 日本語名、日付・時刻の書式、行ラベル、数値の書式、欠損の `—` を担う（T039, T040 に依存）
- [ ] T042 [P] [US1] `backend/src/Presentation/Web/Controller/HomeController.php`（`GET /`、route `app_home`）と `backend/templates/home/index.html.twig`、共通の入力フォーム `backend/templates/forecast/_form.html.twig`（`method="get"`、`action="{{ path('app_forecast') }}"`、`name="lat"` / `name="lon"`、全角数字を入力できるよう `type="text"` + `inputmode="decimal"`、ラベル付き、ボタン「予報を表示」）
- [ ] T043 [US1] `backend/src/Presentation/Web/Controller/ForecastController.php`（`GET /forecast`、route `app_forecast`）：`lat` / `lon` を Parser に渡す → `ViewMarineForecastInput(latitude, longitude, $request->getClientIp() ?? 'unknown')` で UseCase を呼ぶ → Factory で ViewModel に変換 → `forecast/index.html.twig` を描画する。Domain のロジックを書かない（入力エラー時の 422 と状態ごとのステータスは US2 の T058 で入れる。T038, T041 に依存）
- [ ] T044 [US1] `backend/templates/forecast/index.html.twig` と `backend/templates/forecast/_table.html.twig`：contracts「画面の構成」の順（フォーム → 地点 → 最終更新 → 注意書き → 一覧）。表は `<div class="forecast-table-wrapper">` で囲み、`<thead>` に日付の行と時刻の行、各行の項目名は `<th scope="row">`、日付が変わる列・3 時間ごとの最初の列にクラスを付け、3 時間ごとの最初の列に「3時間ごと」を表示する。Twig では計算・分岐を増やさず ViewModel の文字列を出すだけにする
- [ ] T045 [US1] `backend/assets/styles/app.css`：表のラッパーに `overflow-x: auto`、項目名の列に `position: sticky; left: 0;` と背景色、日付が変わる列の左に太い境界線、3 時間ごとの最初の列の左に別の境界線、数値に `font-variant-numeric: tabular-nums`、幅 360px でページ全体が横にはみ出さないレイアウト（research R8、FR-016）
- [ ] T046 [US1] `docker compose exec php composer check` を通し、ブラウザ（DevTools で 360px）で quickstart の手順 1〜4 を確認する

**Checkpoint**: US1 だけで、有効な地点の予報表示・1 時間の再利用・スマホ幅の表示が動く

---

## Phase 4: User Story 2 - 入力ミスや取得失敗のときに次にすべきことが分かる (Priority: P2)

**Goal**: 入力エラー（422）、取得失敗（前回予報の代替表示 200 / 前回なし 503）、回数制限（429）、波・うねりが得られない地点、一部の項目だけ取得できない場合に、原因と次にすべきことが分かる画面を出し、誤った情報や空の一覧を予報として見せない

**Independent Test**: `lat=95`、`lon=abc`、空欄で 422 と項目ごとのメッセージが出て一覧が出ないこと、Fake Provider を失敗させたときに 503 / 前回予報の警告付き表示になること、31 地点目で 429 になることを Functional Test で確認する

### Tests for User Story 2 ⚠️

> 先に書き、失敗することを確認してから実装する

- [ ] T047 [P] [US2] `backend/tests/Unit/Infrastructure/Marine/OpenMeteo/OpenMeteoMarineForecastProviderTest.php` に失敗時のケースを追加する：片方の API がタイムアウト・接続エラー・HTTP 500・HTTP 400（`{"error":true,"reason":"..."}`）・形式不正のとき、そのグループだけ `FetchFailed` でもう片方は値が入る / 両方失敗で `FetchFailure` / `marine_inland.json` で sea = `NotProvidedAtLocation`
- [ ] T048 [P] [US2] `backend/tests/Unit/Infrastructure/Marine/RateLimit/SymfonyFetchRateLimiterTest.php`：`InMemoryStorage` の sliding_window（30 回 / 10 分）で 30 回目まで true、31 回目は false / 別のキーは独立して数える
- [ ] T049 [P] [US2] `backend/tests/Unit/Application/Marine/ViewMarineForecastTest.php` に追加する（回数制限は `backend/tests/Support/FakeFetchRateLimiter.php` を作って使う）：再利用できる予報がなく回数制限に達していれば `RateLimited` で Provider を呼ばない / 回数制限に達していても再利用できる予報は `Fresh` で返し、回数を消費しない / 新規取得のときだけ 1 回消費する / `FetchFailure` で 24 時間以内の前回予報があれば `Stale`（hours は現在時刻以降。fetchedAt は前回のまま）/ 前回予報が 24 時間以上前、またはない場合は `Unavailable` で forecast は null / 部分的な予報（片方 `FetchFailed`）は `Fresh` で返すがキャッシュに保存しない / 回数制限に達していて前回予報が 24 時間以内にあっても `RateLimited`（research R5）
- [ ] T050 [P] [US2] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` に追加する：`Stale` の notice が `最新の予報を取得できませんでした。表示中は 2026/10/05 17:15 時点の予報です` で、lastUpdated に再取得時刻の括弧がない / `Unavailable` の notice が `予報を取得できませんでした。時間をおいて再度お試しください`、table・location・lastUpdated が null / `RateLimited` の notice が `しばらく待ってから再度お試しください`、table が null / sea が `NotProvidedAtLocation` なら groupMessages に `この地点では波・うねりの予報が得られません` が入り、波・うねりの 6 行が出ない / sea・wind が `FetchFailed` ならそれぞれ `波・うねりの予報を取得できませんでした` / `風の予報を取得できませんでした` が入り、該当行が出ない / 入力エラーのとき form にエラーメッセージと元の文字列が入り、table が null
- [ ] T051 [US2] `backend/tests/Functional/ForecastPageTest.php` に追加する：`lat=95&lon=129.05` が 422 で緯度の範囲メッセージが出て表がない / `lon=abc` と `lon=`（空欄）が 422 で経度のメッセージが出て、入力欄に `abc` が残る / Fake を `willFail()` にして未取得の地点が 503 で取得失敗メッセージ、表なし / 取得済みの地点で FixedClock を 3 時間進めて Fake を失敗させると 200 で、警告が表より前にあり `2026/10/05 20:15 時点の予報です` を含む / 異なる 30 地点を開いたあと 31 地点目が 429 で表なし、そのあとでも取得済みの地点は 200 / 波・うねりを `NotProvidedAtLocation` にした Fake で 200、風の行があり「この地点では波・うねりの予報が得られません」が出る

### Implementation for User Story 2

- [ ] T052 [P] [US2] `backend/src/Application/Marine/Port/FetchRateLimiter.php`：`tryConsume(string $clientKey): bool`
- [ ] T053 [P] [US2] `backend/config/packages/rate_limiter.yaml` に `provider_fetch`（`policy: sliding_window`、`limit: 30`、`interval: '10 minutes'`）を定義する。上限値はここだけで調整できるようにする。`when@test` では状態がリクエスト間で残り、テスト間で混ざらないストレージにする（例：`cache_pool` に array adapter のプールを指定）（research R5）
- [ ] T054 [US2] `backend/src/Infrastructure/Marine/RateLimit/SymfonyFetchRateLimiter.php`：`provider_fetch` の `RateLimiterFactoryInterface` を注入し、`create($clientKey)->consume(1)->isAccepted()` を返す（T052, T053 に依存）
- [ ] T055 [US2] `backend/src/Infrastructure/Marine/OpenMeteo/OpenMeteoMarineForecastProvider.php` を変更する：API ごとに `TransportExceptionInterface`（タイムアウト・接続エラー）、HTTP 4xx/5xx、JSON の解析失敗、DTO の形式不正を捕まえてそのグループを null（= `FetchFailed`）として Mapper に渡す。両方失敗なら原因を含めた `FetchFailure` を投げる。失敗の原因は `LoggerInterface` に warning で記録する（画面には出さない）
- [ ] T056 [US2] `backend/src/Application/Marine/UseCase/ViewMarineForecast.php` を data-model「UseCase：ViewMarineForecast」の処理順どおりに変更する：再利用判定 → `FetchRateLimiter::tryConsume(clientKey)` → 取得 → `FetchFailure` なら `isFallbackUsableAt()` で `Stale` / `Unavailable`（T052 に依存）
- [ ] T057 [US2] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` を変更する：status ごとの notice（種別 `stale` / `unavailable` / `rate_limited` と文言）、`Stale` の lastUpdated から再取得時刻を外す、groupMessages、`Available` でないグループの行を除く、入力エラー時のフォーム（data-model「状態と画面の対応」）
- [ ] T058 [US2] `backend/src/Presentation/Web/Controller/ForecastController.php` を変更する：入力エラーなら UseCase を呼ばずに 422 で描画する / UseCase の status を 200（Fresh・Stale）・503（Unavailable）・429（RateLimited、`Retry-After` は付けない）に対応させる（contracts「Response」）
- [ ] T059 [US2] `backend/templates/forecast/index.html.twig`・`backend/templates/forecast/_form.html.twig`・`backend/assets/styles/app.css` を変更する：notice をフォームの直後・地点より前に `role="alert"` で一覧より目立つ形で表示する / groupMessages を注意書きと一覧の間に表示する / 項目ごとのエラーを入力欄の下に表示して `aria-describedby` と `aria-invalid` で結び付ける / 注意書きは表がない状態も含めて予報ページのすべての状態で表示する（data-model「状態と画面の対応」）
- [ ] T060 [US2] `docker compose exec php composer check` を通し、quickstart の手順 5〜8 をブラウザで確認する（取得失敗は `.env.local` で `OPEN_METEO_*_URL` を存在しないホストに向けて再現できる）

**Checkpoint**: US1 と US2 が両方動く。どの失敗でも空の一覧や古い予報を最新として見せない

---

## Phase 5: User Story 3 - 同じ地点の予報を開き直す (Priority: P3)

**Goal**: 予報ページの URL（`/forecast?lat=..&lon=..`）を再読み込み・ブックマーク・別タブで開くと、同じ地点の予報と入力済みのフォームが表示され、予報ページから別の地点に切り替えられる

**Independent Test**: `/forecast?lat=27.75&lon=129.05` を新しいクライアントで開き直し、同じ地点の予報と入力欄の値が表示されることを確認する

### Tests for User Story 3 ⚠️

- [ ] T061 [US3] `backend/tests/Functional/ForecastPageTest.php` に追加する：`/forecast?lat=27.75&lon=129.05` を 2 回開くと同じ地点・同じ最終更新で、入力欄の値が `27.75` / `129.05` / 予報ページのフォームから `lat=35.00&lon=139.80` を送信すると `/forecast?lat=35.00&lon=139.80` に移り地点表示が切り替わる / `lat=27.7500&lon=129.05` は `27.75` と同じキャッシュを使い Fake の `callCount()` が増えない / `lat=２７．７５` は入力欄に全角のまま残り、`北緯 27.75°` の予報が表示される / 未知のクエリパラメータを付けても同じ表示になる

### Implementation for User Story 3

- [ ] T062 [US3] `backend/templates/forecast/_form.html.twig` と `backend/src/Presentation/Web/Controller/ForecastController.php` を確認・修正し、T061 を通す：フォームが `GET /forecast` へ送ること、入力欄に正規化前の文字列（`rawLatitude` / `rawLongitude`）を戻すこと、正規化した URL へリダイレクトしないこと（contracts「Query」）

**Checkpoint**: すべてのユーザーストーリーが単独で動く

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: 複数のストーリーにまたがる確認と仕上げ

- [ ] T063 [P] `backend/tests/External/OpenMeteoMarineForecastProviderTest.php`：実際の Open-Meteo を呼び、海上の地点（27.75, 129.05）で wind・sea とも `Available` で 73 時間分の時刻がある / 内陸の地点（36.65, 138.18）で sea が `NotProvidedAtLocation`、wind が `Available`（`composer test:external` でだけ実行される）
- [ ] T064 [P] `backend/tests/Functional/ForecastPageTest.php` に、トップ・Fresh・Stale・Unavailable・RateLimited・入力エラーのすべての画面で「安全です」「出航できます」「問題ありません」を含まないこと、予報ページのすべての状態で安全の注意書きがあること、表がある状態では必ず `最終更新：YYYY/MM/DD HH:mm` の形式があることを data provider で確認するテストを追加する（SC-005、FR-007、FR-009、FR-010）
- [ ] T065 [P] ファイルが置かれたディレクトリの `.gitkeep` を削除する（`backend/src/Domain/Marine/.gitkeep`、`backend/src/Application/Marine/.gitkeep`、`backend/src/Infrastructure/Marine/.gitkeep`、`backend/src/Presentation/Web/Controller/.gitkeep`、`backend/tests/Unit/.gitkeep`、`backend/tests/External/.gitkeep`）
- [ ] T066 `docker compose exec php composer check` と `docker compose exec php composer test:external` を実行し、すべて通ることを確認する
- [ ] T067 `specs/001-marine-forecast-view/quickstart.md` の「3. ブラウザでの確認」の 9 項目を、PC 幅と 360px 幅の両方で確認する

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**：依存なし。すぐに始められる
- **Foundational (Phase 2)**：Setup の完了が前提（T021 は T001 の symfony/clock、T023 は T022 に依存）。すべてのストーリーをブロックする
- **US1 (Phase 3)**：Foundational の完了が前提
- **US2 (Phase 4)**：US1 の Provider・UseCase・Factory・Controller・テンプレートを拡張するため、US1 の完了が前提
- **US3 (Phase 5)**：US1 の完了が前提。US2 とは独立（US2 と並行してもよいが、`ForecastPageTest.php` と `_form.html.twig` を両方が触るので、同じ人が順に進めるほうが衝突しない）
- **Polish (Phase 6)**：すべてのストーリーの完了が前提

### User Story Dependencies

```text
Setup → Foundational → US1 (MVP) ─┬→ US2 ─┐
                                  └→ US3 ─┴→ Polish
```

### Within Each User Story

- テストを先に書いて失敗させる → Infrastructure の DTO / Mapper → Provider・Cache → UseCase → Presentation の Input / ViewModel → Controller → テンプレート / CSS
- 各フェーズの最後に `composer check`

### Parallel Opportunities

- Setup：T002、T003、T005 は並列（T004 と T005 は別ファイル）
- Foundational：テスト T006〜T010 はすべて並列。実装 T011〜T016、T019〜T022、T024 は並列。T017 → T018 は順番
- US1：テスト T025〜T031 はすべて並列。実装 T033・T034・T037・T039・T040・T042 は並列。T035 → T036、T038、T041 → T043 → T044 は順番
- US2：テスト T047〜T050 は並列。実装 T052・T053 は並列。T055〜T059 は US1 のファイルを変更するので順番

---

## Parallel Example: User Story 1

```bash
# US1 のテストをまとめて書く（すべて別ファイル）
Task: "T025 Open-Meteo の JSON フィクスチャと OpenMeteo*ResponseTest"
Task: "T026 OpenMeteoResponseMapperTest"
Task: "T027 OpenMeteoMarineForecastProviderTest（成功時）"
Task: "T028 SymfonyMarineForecastCacheTest"
Task: "T029 ViewMarineForecastTest（取得と再利用）"
Task: "T030 CoordinateQueryParserTest"
Task: "T031 ForecastPageViewModelFactoryTest（Fresh）"

# 互いに依存しない実装をまとめて進める
Task: "T033 OpenMeteoWeatherResponse.php"
Task: "T034 OpenMeteoMarineResponse.php"
Task: "T037 SymfonyMarineForecastCache.php"
Task: "T039 CoordinateQuery / CoordinateQueryParser"
Task: "T040 ForecastPageViewModel / ForecastTable"
Task: "T042 HomeController と _form.html.twig"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1: Setup
2. Phase 2: Foundational（Domain のテストと実装、Port、DTO、テスト用の Fake）
3. Phase 3: US1
4. **STOP and VALIDATE**：Functional Test と quickstart 手順 1〜4 で、有効な地点の予報が表示されることを確認する
5. ただし US1 単独では、入力エラーや取得失敗のときに適切なメッセージが出ない。利用者に公開するのは US2 まで終えてからにする（誤った情報を予報として見せないため。spec US2「Why this priority」）

### Incremental Delivery

1. Setup + Foundational → 土台が整う
2. US1 → 予報の表示と再利用（内部で確認）
3. US2 → 入力エラー・取得失敗・回数制限・部分取得の表示（ここで公開できる状態）
4. US3 → URL での開き直しを保証
5. Polish → 実 API のテスト、断定表現の横断チェック、quickstart の確認

---

## Notes

- [P] は別ファイルで、未完了のタスクに依存しないもの
- 実装前の人間のレビュー：plan.md の Domain 設計・画面/URL 設計・外部 API Provider・Security（回数制限）と「レビューで判断してほしい点」1〜5 は 2026-10-05 に承認済み。Open-Meteo の無料 API は非商用に限られるため、検証中は広告・課金を入れない
- タスクごと、または論理的なまとまりごとにコミットする
- 各 Checkpoint でそのストーリーを単独で確認する

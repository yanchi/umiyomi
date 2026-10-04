# Implementation Plan: 地点指定による海況予報の時間別表示

**Branch**: `001-marine-forecast-view` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/001-marine-forecast-view/spec.md`

## Summary

利用者がブラウザで緯度・経度を入力すると、Symfony が Open-Meteo の Weather API（風）と Marine API（波・うねり）を
サーバー側から並列に呼び、現在時刻から 72 時間先までの予報を「行＝項目、列＝時刻」の表で Twig 描画する。
予報は丸めた座標ごとに Symfony Cache へ保存し、1 時間は再利用、取得失敗時は 24 時間以内のものを警告付きで代替表示する。
提供元への新規取得は接続元 IP ごとに 10 分 30 回までに制限する（symfony/rate-limiter）。

レイヤーは Domain（座標・方位・予報・表示時刻の選択）/ Application（UseCase と Port、表示用 DTO）/
Infrastructure（Open-Meteo・キャッシュ・回数制限・時計の実装）/ Presentation（入力の解釈、ViewModel、Twig）に分け、
Open-Meteo 固有の形式は Infrastructure の Provider 固有 DTO と Mapper に閉じ込める。複数 Provider の比較・切り替え（方針の Phase 2 以降）の仕組みは今は作らない（research R10）。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）
**Primary Dependencies**: Symfony 8.1（FrameworkBundle、TwigBundle、HttpClient、Cache、AssetMapper）。追加：`symfony/clock`、`symfony/rate-limiter`
**Storage**: なし（DB は導入しない）。予報と回数制限のカウンターは Symfony Cache（dev/prod はファイルシステム、test は in-memory。複数台構成時は Redis）
**Testing**: PHPUnit 13（unit / functional / external の 3 スイート）、`MockHttpClient`、`ArrayAdapter`、`WebTestCase`
**Target Platform**: Linux サーバー（Docker）。利用者はスマホ・PC のブラウザ（幅 360px 以上）
**Project Type**: Web サービス（サーバーサイドレンダリング）
**Performance Goals**: 取得済みの地点は 1 秒以内、未取得の地点は 5 秒以内に一覧表示（SC-002）。提供元への 2 つの呼び出しは並列、合計タイムアウト 4 秒
**Constraints**: 外部 API はサーバーからのみ呼ぶ / 同一地点は 1 時間に 1 回まで取得 / 新規取得は接続元ごとに 10 分 30 回まで / JavaScript なしで動く / 断定的な安全表現を出さない
**Scale/Scope**: 画面 2（トップ・予報）、ルート 2、表示列は最大 24 + 16 = 40 列 × 9 行

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | Web（Twig）のみ。JSON API・PWA・地図指定・アカウントなし。DB なし。お気に入りは別機能。追加パッケージは要件（時刻固定・回数制限）に直結する 2 つだけ |
| II. レイヤー境界と依存方向 | ✅ | Domain は純粋 PHP。Controller は入力解釈 → UseCase → ViewModel 変換 → 描画のみ。UseCase の Input は float と接続元キー（文字列）で、Request を渡さない。Presentation は Application の DTO だけを扱い Domain を参照しない（Deptrac で強制）。VO は Coordinate（範囲検証・丸め）、ForecastPeriod（期間の検証）、CompassPoint（角度変換）だけ |
| III. 外部海況 API の隔離とキャッシュ | ✅ | Port `MarineForecastProvider::forecast(Coordinate, ForecastPeriod)` を Application に定義し、Open-Meteo の実装・Provider 固有 DTO・Mapper は Infrastructure に置く（[海況API・開発フェーズ方針](../../docs/MARINE_API_STRATEGY.md) の境界どおり）。丸めた座標ごとに Symfony Cache で 1 時間再利用 |
| IV. 判断材料の提示と断定の禁止 | ✅ | 時刻ごとの数値だけを示し、傾向要約・良し悪しの判定は作らない。全状態で「最終更新」（Stale 含む）と注意書きを表示。断定表現がないことを Functional Test で確認 |
| V. 重点領域のテスト | ✅ | Coordinate・CompassPoint・MarineForecast・ForecastTimeline・UseCase・Open-Meteo 変換・Provider・Cache・入力パーサー・ViewModel 変換を Unit、主要経路を Functional、実 API を External に分離 |
| ワークフロー（人間のレビュー） | ✅ 承認済み（2026-10-05） | 本 plan は Domain 設計・画面/URL 設計・外部 API Provider・Security（回数制限）を含むため、実装前に人間の承認を得た。下記 1〜5 はすべて記載どおりで承認 |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。

### レビューで判断してほしい点（2026-10-05 承認：すべて記載どおり）

1. **Open-Meteo の利用条件**（research R1）：無料 API は非商用限定。フェーズ方針の Phase 1（ユーザー検証）は無料枠で進め、課金開始（Phase 3）前に切り替える前提。徳之島周辺などでの検証利用が非商用の範囲に収まるか
2. **回数制限の超過時に前回の予報を出さない**（research R5）：spec の文言どおりにしたが、24 時間以内の前回予報を警告付きで出す方が親切という考え方もある
3. **部分的な予報をキャッシュしない**（research R3）：波だけ取得失敗した場合、次の表示で再取得する（回数制限には数える）
4. **陸地の判定**（research R2）：沿岸の陸地は Open-Meteo が近くの海の格子を選ぶため波が表示される。内陸だけ「得られません」になる
5. **URL**（contracts/web-routes.md）：`/forecast?lat=..&lon=..`。入力された文字列をそのまま URL に残し、正規化リダイレクトはしない

## Project Structure

### Documentation (this feature)

```text
specs/001-marine-forecast-view/
├── plan.md              # This file
├── research.md          # Phase 0: 提供元・キャッシュ・回数制限・時刻・入力・表示の決定事項
├── data-model.md        # Phase 1: レイヤーごとのオブジェクトと変換
├── quickstart.md        # Phase 1: 動作確認手順と本番前の確認事項
├── contracts/
│   └── web-routes.md    # Phase 1: 画面・URL・ステータスコード
├── checklists/
│   └── requirements.md  # /speckit.specify で作成済み
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code

```text
backend/
├── config/
│   ├── packages/
│   │   ├── cache.yaml               # cache.marine_forecast プール（24h）。test は array
│   │   ├── framework.yaml           # http_client の scoped_clients（open_meteo_weather / open_meteo_marine、timeout）
│   │   └── rate_limiter.yaml        # provider_fetch: sliding_window 30 / 10 minutes。test は in-memory
│   └── services.yaml                # when@test で Provider・Clock を Fake に差し替え
├── src/
│   ├── Domain/Marine/
│   │   ├── Coordinate.php
│   │   ├── CompassPoint.php
│   │   ├── Availability.php
│   │   ├── HourlyForecast.php
│   │   ├── MarineForecast.php
│   │   ├── ForecastPeriod.php
│   │   ├── ForecastTimeline.php
│   │   └── FetchFailure.php
│   ├── Application/Marine/
│   │   ├── Port/
│   │   │   ├── MarineForecastProvider.php
│   │   │   ├── MarineForecastCache.php
│   │   │   ├── FetchRateLimiter.php
│   │   │   └── Clock.php
│   │   ├── UseCase/
│   │   │   ├── ViewMarineForecast.php
│   │   │   └── ViewMarineForecastInput.php
│   │   └── DTO/
│   │       ├── MarineForecastResult.php
│   │       ├── ForecastStatus.php
│   │       ├── GroupAvailability.php
│   │       ├── MarineForecastView.php
│   │       └── HourlyForecastView.php
│   ├── Infrastructure/Marine/
│   │   ├── OpenMeteo/
│   │   │   ├── OpenMeteoMarineForecastProvider.php
│   │   │   ├── OpenMeteoWeatherResponse.php     # Provider 固有 DTO
│   │   │   ├── OpenMeteoMarineResponse.php      # Provider 固有 DTO
│   │   │   └── OpenMeteoResponseMapper.php      # DTO → Domain
│   │   ├── Cache/SymfonyMarineForecastCache.php
│   │   ├── RateLimit/SymfonyFetchRateLimiter.php
│   │   └── Clock/SystemClock.php
│   └── Presentation/Web/
│       ├── Controller/
│       │   ├── HomeController.php
│       │   └── ForecastController.php
│       ├── Input/
│       │   ├── CoordinateQuery.php
│       │   └── CoordinateQueryParser.php
│       └── ViewModel/
│           ├── ForecastPageViewModel.php
│           ├── ForecastTable.php
│           └── ForecastPageViewModelFactory.php
├── templates/
│   ├── base.html.twig               # lang="ja"、viewport、タイトル、フッター（Open-Meteo 帰属表示）
│   ├── home/index.html.twig
│   └── forecast/
│       ├── index.html.twig
│       ├── _form.html.twig
│       └── _table.html.twig
├── assets/
│   ├── app.js                       # 雛形の console.log を削除
│   └── styles/app.css               # 一覧の横スクロール・項目名列の固定・日付/間隔の境界線
└── tests/
    ├── Support/                     # Fake Provider、固定 Clock、MarineForecast のビルダー、Open-Meteo の JSON フィクスチャ
    ├── Unit/
    │   ├── Domain/Marine/           # Coordinate, CompassPoint, ForecastPeriod, MarineForecast, ForecastTimeline
    │   ├── Application/Marine/      # ViewMarineForecast（Fresh / 再利用 / Stale / Unavailable / RateLimited / 部分取得）
    │   ├── Infrastructure/Marine/   # OpenMeteo*Response, OpenMeteoResponseMapper, OpenMeteoMarineForecastProvider, SymfonyMarineForecastCache
    │   └── Presentation/Web/        # CoordinateQueryParser, ForecastPageViewModelFactory
    ├── Functional/
    │   └── ForecastPageTest.php     # HTTP → Controller → UseCase → Fake Provider → HTML（各ステータス、断定表現なし）
    └── External/
        └── OpenMeteoMarineForecastProviderTest.php   # 実 API：海上と内陸の地点
```

**Structure Decision**: 既存の `backend/` の Symfony アプリに、CLAUDE.md の `src/{Domain,Application,Infrastructure,Presentation}/Marine/...` に沿って追加する
（Presentation は既存の `Presentation/Web/Controller` に合わせ `Presentation/Web/` 配下）。Port は Domain の型を受け渡すが、
キャッシュ・回数制限・時計はアプリケーションの関心事なので、Provider も含めて Application の `Port/` にまとめる。
Open-Meteo の URL は `.env` の `OPEN_METEO_WEATHER_URL` / `OPEN_METEO_MARINE_URL` から scoped client に渡し、
有料プランへの切り替えや取得失敗の手動再現を設定だけで行えるようにする。Deptrac の設定は変更しない。

## Complexity Tracking

違反なし。

# Research: 地点指定による海況予報の時間別表示

**Feature**: 001-marine-forecast-view | **Date**: 2026-10-05

Technical Context に NEEDS CLARIFICATION は残っていない。以下は、仕様を実装に落とすときに判断が必要だった点の調査結果と決定事項。
Open-Meteo の挙動は 2026-10-05 に実 API へ問い合わせて確認した。

---

## R1. 予報提供元と取得パラメータ

**Decision**: Open-Meteo の 2 つの API をサーバー側から並列に呼ぶ。

| 用途 | エンドポイント | hourly パラメータ |
|---|---|---|
| 風 | `https://api.open-meteo.com/v1/forecast` | `wind_speed_10m`, `wind_gusts_10m`, `wind_direction_10m` |
| 波・うねり | `https://marine-api.open-meteo.com/v1/marine` | `wave_height`, `wave_direction`, `wave_period`, `swell_wave_height`, `swell_wave_direction`, `swell_wave_period` |

共通パラメータ：`latitude`, `longitude`（小数点以下 2 桁に丸めた値）, `timezone=GMT`, `timeformat=unixtime`,
`start_hour` / `end_hour`（UTC の `YYYY-MM-DDTHH:00`）。風は `wind_speed_unit=ms`。

- 取得範囲は「現在時刻を正時に切り捨てた時刻」から **+73 時間**。予報は最大 1 時間再利用する（R4）ため、
  再利用中に現在時刻が 1 時間進んでも 72 時間先の列まで埋まるようにする
- 時刻は UTC の unixtime で受け取り、日本時間への変換は自前で行う。`timezone=Asia/Tokyo` に任せると、
  文字列の時刻をパースするときにタイムゾーンの取り違えが起きうるため、境界を UTC に統一する
- 風向・波向・うねりの向きは Open-Meteo ではいずれも「来る方向」（0° = 北から）。FR-005 の表現と一致するので変換は不要
- 2 つの呼び出しは Symfony HttpClient の非同期レスポンスを使い並列に行う（SC-002 の 5 秒以内のため）

**Rationale**: 無料で利用でき、風（GFS/JMA 等の best_match）と波・うねり（波浪モデル）を同一形式の時系列で返す。
実際の問い合わせでも `wind_speed_10m`（m/s）、`wave_height`（m）、`wave_period`（s）などが期待どおり返った。

**Alternatives considered**:
- `forecast_hours` / `past_hours` 指定：開始時刻が提供元の現在時刻に依存し、テストで固定しにくいため不採用
- 気象庁の GPV を直接扱う：GRIB2 のデコードや格子の扱いが必要で MVP には重い

**利用条件とフェーズ方針**: Open-Meteo の無料 API は**非商用利用**に限られ、帰属表示（CC BY 4.0）が必要。
[海況API・開発フェーズ方針](../../docs/MARINE_API_STRATEGY.md) の Phase 1（無料/低コスト API でユーザー検証）に従い、
MVP の開発・検証は無料枠で進め、画面のフッターに「Weather data by Open-Meteo.com」のリンクを表示する。
課金を始める（Phase 3）前に商用プラン等へ切り替える。ベース URL は HttpClient のスコープ付きクライアント設定に置き、
切り替え時に Provider のコードを変えずに済むようにする。

---

## R2. 波・うねりの予報が提供されない地点（FR-012）

**Decision**: Marine API が HTTP 200 を返し、取得範囲の**波・うねりの全項目がすべて null** の場合を
「この地点では波・うねりの予報が提供されない」と判定する。

**確認結果**:
- 内陸（長野 36.65, 138.18）：HTTP 200、`wave_height` / `swell_wave_height` がすべて `null`
- 沿岸の陸地（東京駅 35.68, 139.77）：Marine API の既定 `cell_selection=sea` により近くの海の格子
  （35.625, 139.875）が選ばれ、値が返る。港や岸近くの地点を指定しても波の予報が得られるので、この既定のままとする
- 範囲外の緯度：HTTP 400 と `{"error":true,"reason":"..."}`。入力は事前に validation するので通常は起きないが、
  起きた場合は取得失敗として扱う

**Rationale**: 一部の時刻だけ null の場合は欠損（FR-011、「—」表示）で、全時刻 null だけを「提供されない」とする。
1 時刻でも値があれば提供される地点とみなす。

**Alternatives considered**: 返ってきた格子の `elevation` > 0 で陸地判定 → 東京駅の例では elevation 16 でも
海の格子の値が返っており、判定に使えない。

---

## R3. 一部の項目だけ取得できなかった場合（Edge Case）

**Decision**: 風（Weather API）と波・うねり（Marine API）を**項目グループ**として扱い、グループごとに
「取得できた / この地点では提供されない / 取得に失敗した」を持つ。

| 風 | 波・うねり | 結果 |
|---|---|---|
| 取得できた | 取得できた / 提供されない | 完全な予報。キャッシュに保存して 1 時間再利用する |
| 取得できた | 取得に失敗 | 部分的な予報。表示するがキャッシュには保存しない（次の表示で再取得を試みる） |
| 取得に失敗 | 取得できた | 同上 |
| 取得に失敗 | 取得に失敗 | 取得失敗。24 時間以内の前回の予報があれば警告付きで表示（FR-013） |

「取得に失敗」は、タイムアウト・接続エラー・HTTP 4xx/5xx・JSON 形式の不一致（必要なキーがない、時刻配列と値配列の長さが違う）を含む。

**Rationale**: 部分的な予報を 1 時間再利用すると、その間ずっと波の欄が「取得できませんでした」のままになる。
保存しないことで、次の表示で自然に回復する。部分的な予報の表示は新規取得なので、問い合わせ回数（R5）には数える。

**Alternatives considered**:
- どちらかが失敗したら全体を失敗扱い：spec の Edge Case（取得できた項目は表示する）に反する
- 部分的な予報の代わりに、24 時間以内の完全な前回の予報を優先表示：古い風の値と新しい波の値の混在を避けるには
  どちらか一方を選ぶ必要があり、「最新を取得できた項目は最新で見せる」ほうが spec の意図に近い。複雑さも増すため不採用

---

## R4. キャッシュ（FR-014, FR-013, SC-006）

**Decision**: Symfony Cache の専用プール `cache.marine_forecast` に、丸めた座標ごとに **1 件の予報（取得日時付き）** を
**有効期限 24 時間**で保存する。再利用できるか（1 時間以内）、前回の予報として使えるか（24 時間以内）は、
保存した取得日時と現在時刻からアプリケーション側で判定する。

- キー：`marine_forecast.v1.{lat}_{lon}`（小数点以下 2 桁の文字列。例 `27.75_129.05`、`-0.50_-120.00`）。
  `v1` は保存形式を変えたときに古いデータを読まないための版
- 保存するのは「完全な予報」（R3）だけ
- 読み出した値が想定外の型・壊れたデータなら、キャッシュなしとして扱う
- dev / prod はファイルシステムアダプター（既定）。本番で複数台にするときは Redis に切り替える（設定変更のみ）。
  test 環境は ArrayAdapter

**Rationale**: 「1 時間で再取得、24 時間は失敗時の代替として残す」を 1 件のデータで表せる。TTL を 2 種類のキーに分けると、
片方だけ消えたときの整合を考える必要が出る。Symfony Cache の `get()`（コールバック方式）はヒット/ミスしか表せず、
「期限切れだが代替には使える」を扱えないため、`getItem()` / `save()` を使う。

**Alternatives considered**:
- HTTP レスポンス（Open-Meteo の JSON）をキャッシュ：前回の予報の判定に Open-Meteo 固有形式を Application が知る必要があり、原則 III に反する
- PostgreSQL に保存：MVP では永続化対象がなく導入しない方針（CLAUDE.md）

**既知の制約**: 同じ未取得の地点に同時に複数の問い合わせが来ると、提供元へ複数回取得しうる（キャッシュの同時実行制御なし）。
MVP の利用規模では提供元の制限（R1）に影響しないため、ロックは入れない。

---

## R5. 問い合わせ回数の制限（FR-019）

**Decision**: `symfony/rate-limiter` の **sliding_window**（30 回 / 10 分）を使い、
**「再利用できる予報がなく、提供元への新規取得が必要」と UseCase が判断したときにだけ**1 回消費する。

- キー：接続元の IP アドレス（`Request::getClientIp()`）。Controller が文字列として UseCase の Input に渡す
- 上限値・期間は `config/packages/rate_limiter.yaml` に置き、コードや画面を変えずに調整できるようにする（spec の Assumptions）
- 上限を超えたら新規取得はせず、FR-019 のメッセージを表示する。このとき 24 時間以内の前回の予報があっても表示しない
  （spec は取得失敗時の代替表示だけを定めており、上限超過時は「予報一覧は表示しない」と定めているため）
- 上限超過の判定は Application の Port（`FetchRateLimiter`）として定義し、Symfony RateLimiter への依存は Infrastructure に閉じ込める
- ストレージは既定の `cache.rate_limiter`（ファイルシステム）。test 環境は in-memory
- **本番で必要な設定**：リバースプロキシ / ロードバランサーの後ろに置く場合、`framework.trusted_proxies` を設定しないと
  全利用者が同じ IP（プロキシの IP）として数えられる。デプロイ先が決まった時点で設定する（quickstart に記載）

**Rationale**: 回数を数える条件（キャッシュの有無）は UseCase しか知らないため、Controller やイベントリスナーで
一律に数える方式では FR-019 の「再利用できる予報がある地点は数えない」を満たせない。sliding_window は
fixed_window の窓の境目で最大 2 倍まで通ってしまう問題がない。

**Alternatives considered**:
- Web サーバー（Caddy）側の制限：キャッシュの有無を知らないため不可
- Cookie / セッション単位：Cookie を消せば回避でき、提供元の保護にならない

---

## R6. 時刻の扱い（FR-003, FR-006, FR-018）

**Decision**:
- 内部の時刻はすべて UTC の `DateTimeImmutable`。表示用の日本時間（`Asia/Tokyo`）への変換は Application の DTO 生成時に行う
- 表示する時刻の選び方（Domain の `ForecastTimeline`）：
  1. 基準時刻 `h0` = 現在時刻を日本時間の正時に切り捨てた時刻（日本時間は UTC+9 の整数時間なので UTC の切り捨てと同じ）
  2. `h0` ≤ t < `h0 + 24h` はすべての時刻
  3. `h0 + 24h` ≤ t ≤ `h0 + 72h` は、日本時間の時が 3 で割り切れる時刻だけ
  4. 予報データにない時刻は列を作らない（前回の予報を代替表示するときは末尾が短くなる）
- 次に取得し直せる時刻 = 取得日時 + 1 時間。最終更新と日本時間の日付が違うときだけ `MM/DD HH:mm` で表示する
- 現在時刻は Application の Port `Clock` から得る。Infrastructure で `symfony/clock` を使って実装し、テストでは固定時刻を使う

**Rationale**: 日本は夏時間がなく UTC+9 固定だが、タイムゾーン名 `Asia/Tokyo` で変換しておけば将来の表示タイムゾーン変更にも対応しやすい。
Deptrac の設定で Application は `Psr\` を含む Framework レイヤーに依存できないため、PSR-20 の `ClockInterface` を直接使わず自前の Port を定義する。

**Alternatives considered**: Controller で現在時刻を取って Input に入れる → 再利用判定やテストでの時刻固定が UseCase の外に漏れるため不採用。

---

## R7. 入力の解釈と validation（FR-002, Edge Cases）

**Decision**: Presentation 層の `CoordinateQueryParser` で文字列を正規化・検証してから UseCase に float を渡す。Symfony Form は使わない。

- 正規化：前後の空白（全角空白含む）を除去、全角数字・全角ピリオド・全角マイナス（`－`、`−`）・全角プラスを半角に変換
- 受け付ける形式：`^[+-]?(\d+(\.\d*)?|\.\d+)$`。`N27.75`、`27°45'`、`27,75`、指数表記は受け付けない
- 範囲：緯度 -90〜90、経度 -180〜180（境界を含む）
- エラーメッセージは項目ごと：未入力・形式不正は「経度を数値（-180〜180）で入力してください」、範囲外は「緯度は -90〜90 の範囲で入力してください」
  （度分秒などの記号を含む場合は「十進数（例：27.75）で入力してください」を添える）
- 入力値は正規化前の文字列のまま入力欄に戻す
- Domain の `Coordinate` も範囲を検証する（不正な状態を作らないため）。Presentation の検証を通った値なら例外にはならない

**Rationale**: 項目 2 つの GET フォームで、全角変換など独自の正規化が中心になる。Form コンポーネントは
CSRF・データマッパー・テーマなどの仕組みが今回の要件に対して大きく、パーサー 1 クラスのほうが単体テストしやすい。

**Alternatives considered**: `#[MapQueryString]` + Validator：検証失敗時に 422 例外になり、入力を残したまま同じ画面で項目ごとのメッセージを出すには例外処理側で再描画する必要があり、かえって複雑になる。

---

## R8. スマホ幅の一覧表示（FR-016, SC-003）

**Decision**: JavaScript なしの CSS だけで実現する。

- 表を `overflow-x: auto` のラッパーで囲み、表だけを横スクロールさせる。ページには `<meta name="viewport" content="width=device-width, initial-scale=1">` を入れる
- 項目名の列（`<th scope="row">`）に `position: sticky; left: 0;` と背景色を付けて左端に固定する
- 日付の行を時刻の行の上に置き、日付が変わる列の左に太い境界線を付ける（FR-006）
- 3 時間ごとに切り替わる最初の列の左に別の境界線と「3時間ごと」の表示を入れる（Edge Case）
- 数値は `font-variant-numeric: tabular-nums` で桁をそろえる

**Rationale**: 行＝項目、列＝時刻（Clarifications）の表は `position: sticky` で十分実現でき、主要ブラウザで対応済み。
既存の AssetMapper（`assets/styles/app.css`）にそのまま書ける。

---

## R9. テストの分け方（原則 V）

**Decision**:
- `tests/Unit`：Domain（Coordinate、CompassPoint、MarineForecast、ForecastTimeline）、UseCase（Fake の Port を使用）、
  Open-Meteo レスポンス変換（JSON フィクスチャ）、Provider（`MockHttpClient` でリクエスト内容とエラー処理）、
  キャッシュ（`ArrayAdapter`）、入力パーサー、ViewModel 変換
- `tests/Functional`：`WebTestCase` で `/` と `/forecast` を叩く。test 環境では `MarineForecastProvider` を
  `tests/` 配下の Fake 実装に差し替え、時刻も固定する。断定表現（「安全です」「出航できます」「問題ありません」）が
  どの画面にも含まれないことも確認する（SC-005）
- `tests/External`：実際の Open-Meteo を呼び、海上の地点・内陸の地点で期待した形のデータが得られることを確認する
  （`composer test:external` でだけ実行）

---

## R10. Provider の境界（[海況API・開発フェーズ方針](../../docs/MARINE_API_STRATEGY.md)）

**Decision**: 方針の「External API → Provider固有DTO → Mapper → Domain Model」の境界を Phase 1 から守る。
ただし Phase 2 以降の複数 Provider 化のための仕組みは今は作らない。

- Port のシグネチャは方針どおり `forecast(Coordinate, ForecastPeriod): MarineForecast`。`ForecastPeriod` は
  開始 < 終了・正時であることを保証する Domain の Value Object（不正な期間を Provider に渡さないため）
- Open-Meteo の JSON はまず `OpenMeteoWeatherResponse` / `OpenMeteoMarineResponse`（Infrastructure の Provider 固有 DTO）に
  変換し、ここで形式（必要なキーの有無、時刻配列と値配列の長さ）を検証する。`OpenMeteoResponseMapper` は
  DTO から Domain の `MarineForecast` を組み立てることだけを担う。JSON の形式チェックと単位・欠損・取得状況の
  解釈が分かれ、それぞれ単体テストしやすい
- 作らないもの：`MarineForecastService`（複数 Provider の選択・統合）、Provider の比較機能、Stormglass 等の実装。
  Port 1 つ・実装 1 つで足り、2 つ目の Provider を足すときに Port の実装を追加するだけで済む構造になっている（原則 I の YAGNI）

**Alternatives considered**: JSON の配列から直接 Domain を組み立てる（当初案）→ Provider 固有の形式チェックと
Domain への解釈が 1 クラスに混ざり、方針の境界に反するため変更した。

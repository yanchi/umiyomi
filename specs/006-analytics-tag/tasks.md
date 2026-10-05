---

description: "Task list for 006-analytics-tag"
---

# Tasks: アクセス解析の導入

**Input**: Design documents from `/specs/006-analytics-tag/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-ui.md, quickstart.md

**Tests**: ViewModel 変換（`analyticsScreen`・`analyticsQuery`）、座標の扱い（正規化済みの値だけを送り、入力文字列を送らない）、主要な HTTP 経路が Constitution 原則 V に当たるため、Unit・Functional Test を必須とし、実装より先に書いて失敗することを確認する。
送る値の規則（referrer の整形・イベントの許可リスト・オプトアウトの読み書き）は `node --test`、実ブラウザでの通信の有無（SC-002・SC-003・SC-004・SC-008）は Playwright で確かめる。e2e は CI では動かさず手元で実行する。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1〜US3）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- Domain・Application・Infrastructure・Deptrac の設定・Dockerfile・CI・nginx の設定は変更しない。新しい Composer / npm パッケージ・PHP 拡張を追加しない（plan）
- Google のインラインの gtag スニペット・GTM・Consent Mode・同意バナーを使わない。`<script>` をテンプレートに書かない（research R1・R7）
- `page_location` は必ずサーバーが組み立てた `data-path` から作る。`location.href`・`location.search` を GA に渡さない（research R3）
- 利用者の入力文字列・お気に入りの地点と名前・現在地の位置・`updated`・`ignored` を、`<meta>`・`page_location`・`page_referrer`・イベントに含めない（FR-004・FR-005）
- Twig では値を自動エスケープで出し、`|raw` を使わない。`data-path` は `path()` で組み立て、文字列連結しない
- localStorage のキーは `umiyomi.analytics.optOut`（値 `"1"`）。`umiyomi.favorites`（002）の形式は変えない。localStorage へのアクセスはすべて try/catch で包む
- 文言は contracts/web-ui.md の文字列をそのまま使う。「安全です」「出航できます」「問題ありません」のような断定を入れない（FR-010、原則 IV）
- コメントは日本語で「なぜ」だけを書く
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 新しいパッケージ・拡張は追加しない（plan.md）。変更前の基準を確認するだけ

- [X] T001 `docker compose up -d` のあと `docker compose exec php composer check` を実行し、変更前に全部通ることを確かめる（失敗するなら先に原因を報告して止まる）

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 全ストーリーが使う計測の設定（`AnalyticsTag`・環境変数・Twig の global）と、e2e で計測 ID 付きのアプリを起動する枠。ここが終わるまでストーリーの作業に入らない

- [X] T002 [P] `backend/tests/Unit/Presentation/Web/Analytics/AnalyticsTagTest.php` を新規作成する。DataProvider で、有効：`G-ABCD1234`・`G-E2ETEST000`、無効：空文字・`' G-ABCD1234'`（前後の空白）・`g-abcd1234`（小文字）・`UA-12345-1`・`G-ABC`（4 文字未満）・`G-` + 21 文字・`G-ABCD1234"><script>` を検査する。有効なら `measurementId()` が同じ値、無効なら `isEnabled()` が false で `measurementId()` が `LogicException` を投げること。この時点では失敗することを確認する（data-model.md「計測の設定」）
- [X] T003 `backend/src/Presentation/Web/Analytics/AnalyticsTag.php` を新規作成する。`final readonly class AnalyticsTag`、コンストラクタ引数 `string $measurementId`、`isEnabled(): bool`（`preg_match('/^G-[A-Z0-9]{4,20}$/', ...)`）、`measurementId(): string`（無効なら `LogicException('Google Analytics is not configured.')`）。クラスの PHPDoc に、全画面の `<head>` から使うため Twig の global にすることと、ID を外部の URL に組み込むため形式を検査すること（research R2）を日本語で書く。`FeedbackFormLink`（`backend/src/Presentation/Web/Feedback/FeedbackFormLink.php`）と同じ書き方にそろえる。T002 が通ることを確認する
- [X] T004 計測の設定をつなぐ：`backend/config/services.yaml` に `App\Presentation\Web\Analytics\AnalyticsTag:` の `arguments: { $measurementId: '%env(GA_MEASUREMENT_ID)%' }` を `FeedbackFormLink` の定義の下に追加し、`backend/config/packages/twig.yaml` の `twig.globals` に `analytics: '@App\Presentation\Web\Analytics\AnalyticsTag'` を追加する。`backend/.env` の `FEEDBACK_FORM_PREFILL_FIELD=` の下に `GA_MEASUREMENT_ID=` を追加し、`backend/.env.test` の末尾に `GA_MEASUREMENT_ID=''` を追加する（テストの既定は計測しない。FR-006）
- [X] T005 [P] `compose.prod.yml` の `app.environment` の `FEEDBACK_FORM_PREFILL_FIELD` の下に `GA_MEASUREMENT_ID: ${GA_MEASUREMENT_ID:-}` を追加し、`:?` にしない理由（測定 ID の発行とデプロイを切り離す。未設定なら何も読み込まない）を日本語コメントで書く。`deploy/.env.production.example` の末尾に `GA_MEASUREMENT_ID=`（空）と、「GA4 の測定 ID（G- で始まる）。空・形式が違うときは計測しない。設定する前に deploy/README.md の『アクセス解析を設定する』の GA4 管理画面の設定を済ませること」の説明コメントを追加する
- [X] T006 [P] `compose.yaml` に、`app-e2e` と同じ構成（`profiles: [e2e]`、`build: ./backend`、`APP_ENV: test`、`APP_DEBUG: "1"`、同じ volumes と healthcheck）で `GA_MEASUREMENT_ID: G-E2ETEST000` を足したサービス `app-e2e-analytics` を追加する。`e2e` サービスの `depends_on` に `app-e2e-analytics: { condition: service_healthy }` を足し、`environment` に `E2E_ANALYTICS_BASE_URL: http://app-e2e-analytics` を足す。コメントに「既定の app-e2e は計測しない（FR-006）。計測の挙動を確かめるテストだけがこちらを使い、Google への通信は Playwright で全てスタブ・遮断するので外へは出ない」と書く（research R9）
- [X] T007 `docker compose exec php composer check` を通す（DI コンテナの lint で `AnalyticsTag` が解決できること）

**Checkpoint**: `analytics` が全テンプレートから参照でき、既定（空）では何も変わらない

---

## Phase 3: User Story 1 - 運営者が画面ごとの閲覧数と利用者数を確認できる (Priority: P1) 🎯 MVP

**Goal**: 計測が有効な環境で、全画面の閲覧が画面の種類（8 種）とサーバーが組み立てたアドレスつきで GA4 に送られる。未設定の環境では何も読み込まない

**Independent Test**: `AnalyticsMarkupTest`・`AnalyticsUnconfiguredTest`・`analytics-rules.test.js` が通り、e2e で、既定の app-e2e では Google への通信が 0 件、app-e2e-analytics では `dataLayer` の `config` に正しい `page_location`・`page_referrer`・`screen_type` が入る

### Tests for User Story 1（先に書いて失敗することを確認する）

- [X] T008 [P] [US1] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` にテストを追加する：`createForInvalidInput()` → `analyticsScreen` が `invalid_input`・`analyticsQuery` が `[]`。`create()` で `ForecastStatus::Fresh`・`Stale` → `forecast`、`Unavailable` → `forecast_unavailable`、`RateLimited` → `rate_limited`、いずれも `analyticsQuery` が `['lat' => <正規化済みの緯度>, 'lon' => <正規化済みの経度>]` で、1 行入力で片方の欄を無視したとき（003 の `ignoredField` あり）も `ignored` を含まないこと。既存テストのクエリ・結果の組み立て方（ヘルパー）を流用する（data-model.md「ForecastPageViewModel への追加」）
- [X] T009 [P] [US1] `backend/tests/Functional/AnalyticsMarkupTest.php` を新規作成する。`FeedbackUnconfiguredTest` と同じく、クライアントを作る前に `$_ENV['GA_MEASUREMENT_ID']`・`$_SERVER['GA_MEASUREMENT_ID']` を `G-TEST1234` に書き換え、`tearDown()` で戻す。404 の画面は `HeadMetaTest` と同じく debug を切ったカーネルと `catchExceptions(true)` で描画させ、`setUpBeforeClass()` で `var/cache/test` を消す。検査：(1) contracts/web-ui.md の表の全画面（`/`、`/forecast?lat=27.75&lon=129.05` の 200・Fake Provider を失敗させた 503・回数制限の 429、`/forecast?lat=北緯二十七度&lon=129.05` の 422、`/feedback`、`/feedback?lat=27.75&lon=129.05&updated=2026/10/05 09:00`、`/feedback?input_lat=<script>&input_lon=abc`、`/no-such-page?x=1` の 404）で `meta[name="umiyomi-analytics"]` がちょうど 1 つあり、`data-measurement-id`・`data-screen`・`data-path` が表のとおり（404 は `/no-such-page`）、(2) 入力不正・案内画面に `"><script>alert(1)</script>`・`'; DROP`・500 文字の文字列・`北緯二十七度` を渡しても、`data-path` と `<head>` 全体にそれらが含まれないこと、(3) HTML に `<script` で始まる Google のタグ（`googletagmanager`）が含まれないこと（読み込みは JS が行う）、(4) 外部 API の呼び出し回数が、予報画面以外で 0 回のまま（Fake Provider の呼び出し回数）。503・429 の作り方は既存の `ForecastPageTest` に合わせる。この時点では失敗することを確認する（`/external-transmission` の行は US3 で追加する）
- [X] T010 [P] [US1] `backend/tests/Functional/AnalyticsUnconfiguredTest.php` を新規作成する。DataProvider で `GA_MEASUREMENT_ID` を `''`・`UA-12345-1`・`g-abcd1234` にして、`/`・`/forecast?lat=27.75&lon=129.05`・`/forecast?lat=abc&lon=1`・`/feedback` で `meta[name="umiyomi-analytics"]` が 0 個、HTML に `googletagmanager`・`google-analytics` の文字列が含まれず、予報画面の表（`table`）が従来どおり出ることを検査する（FR-006・SC-003）。環境変数の差し替えと戻し方は `FeedbackUnconfiguredTest` と同じにする
- [X] T011 [P] [US1] `backend/tests/JavaScript/analytics-rules.test.js` を新規作成する（`node --test`、既存の `favorite-store.test.js` の書き方に合わせる）。`pageReferrer(referrer, origin)` について：空文字 → `null`、同じオリジンの `https://example.com/forecast?lat=北緯&lon=1` → `https://example.com/forecast`、同じオリジンのハッシュ付き → ハッシュも捨てる、別オリジンの `https://www.google.com/` → そのまま、`http://example.com/`（スキーム違い）は別オリジンとして扱いそのまま、URL として解釈できない文字列 → `null`。`pageLocation(origin, path)` について：`('https://example.com', '/forecast?lat=27.75&lon=129.05')` → 連結した文字列、`path` が `/` で始まらない（`//evil.example/`・`https://evil.example/`・空）→ `null`。この時点では失敗することを確認する

### Implementation for User Story 1

- [X] T012 [US1] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModel.php` にコンストラクタ引数 `public string $analyticsScreen = 'invalid_input'` と `public array $analyticsQuery = []` を末尾に追加し、PHPDoc に型（`'forecast'|'forecast_unavailable'|'rate_limited'|'invalid_input'`、`array{lat: string, lon: string}|array{}`）と「計測に送ってよい値だけ。入力不正のときは空（FR-005）」を書く。`backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` の `createForInvalidInput()` と `create()` の 3 か所の `new ForecastPageViewModel(...)` に、`ForecastStatus` から決めた `analyticsScreen` と、`analyticsQuery` を渡す。`analyticsQuery` は `$this->favoriteTarget($result)` の `latitude`・`longitude` から `['lat' => ..., 'lon' => ...]` として作る（既存の `feedbackQuery()` と同じ値。画面に出している地点の書式と食い違わせないため）。`createForInvalidInput()` では `invalid_input` と `[]` を渡す。判定は `match` で書き、Twig に分岐を持たせない理由（ViewModel 変換で Unit Test する）を 1 行コメントに書く。T008 が通ることを確認する
- [X] T013 [US1] `backend/templates/base.html.twig` の `{% block head_meta %}{% endblock %}` の直前に、`{% if analytics.enabled and analytics_page is defined %}` のときだけ `<meta name="umiyomi-analytics" data-measurement-id="{{ analytics.measurementId }}" data-screen="{{ analytics_page.screen }}" data-path="{{ analytics_page.path }}">` を出す。テンプレートが `analytics_page` を決めなかった画面では出さない理由（新しい画面で入力文字列入りのアドレスを既定で送らないため。research R3・data-model.md）を Twig コメントで書く（`strict_variables` のテスト環境でも `is defined` で落ちないこと）
- [X] T014 [P] [US1] 各テンプレートの先頭（`{% extends %}` の直後、既存の `{% set feedback_query %}` と同じ位置）に `analytics_page` を決める：`backend/templates/home/index.html.twig` は `{% set analytics_page = {screen: 'home', path: path('app_home')} %}`、`backend/templates/feedback/index.html.twig` は `{screen: 'feedback', path: path('app_feedback')}`（クエリを付けない理由：入力文字列が入るため）、`backend/templates/bundles/TwigBundle/Exception/error.html.twig` は `{screen: 'error', path: app.request.pathInfo}`（クエリを捨てる理由：フォームの入力は GET のクエリにしか入らないため。research R3）
- [X] T015 [US1] `backend/templates/forecast/index.html.twig` の先頭（`{% set feedback_query = page.feedbackQuery %}` の下）に `{% set analytics_page = {screen: page.analyticsScreen, path: path('app_forecast', page.analyticsQuery)} %}` を追加する。T009・T010 が（`/external-transmission` 以外）通ることを確認する
- [X] T016 [P] [US1] `backend/assets/analytics/analytics-rules.js` を新規作成する（DOM・`window` に依存しない純粋関数の ES Module。research R4）。`export const pageReferrer = (referrer, origin) => ...`（`new URL()` で解釈し、同じオリジンなら `origin + pathname`、別オリジンならそのまま、空・解釈できないなら `null`）、`export const pageLocation = (origin, path) => ...`（`path` が `/` で始まり `//` で始まらないときだけ `origin + path`、それ以外は `null`）。先頭のコメントに「GA に渡す値の規則。入力文字列を送らないための許可リストをここに集め、node --test でテストする」と書く。T011 が通ることを確認する
- [X] T017 [US1] `backend/assets/analytics/analytics.js` を新規作成する。`export const initAnalytics = (doc) => {...}`：`meta[name="umiyomi-analytics"]` がなければ何もしない。あれば `pageLocation(location.origin, meta.dataset.path)` が `null` のときも何もしない。それ以外は `window.dataLayer = window.dataLayer || []`、`window.gtag = function () { window.dataLayer.push(arguments); }`（`arguments` を push する理由：gtag.js が配列ではなく arguments オブジェクトを前提にするため）を定義し、`gtag('js', new Date())`、`gtag('config', id, { page_location, page_referrer（null なら付けない）, screen_type: meta.dataset.screen, allow_google_signals: false, allow_ad_personalization_signals: false })` を呼んでから、`https://www.googletagmanager.com/gtag/js?id=` + `encodeURIComponent(id)` の `<script async>` を `document.head` に追加する。読み込みの失敗（ブロック）で例外を出さないこと（`onerror` を付けない＝何もしない）。オプトアウトの判定は US3（T034）で足すので、ここでは関数の先頭に差し込める形にしておく。`backend/assets/app.js` の import と呼び出しの先頭に `initAnalytics(document)` を追加する（他の初期化より先にする理由：後続の操作のイベントが `config` の後に積まれるようにするため）
- [X] T018 [P] [US1] `e2e/playwright.config.js` は変更せず、`e2e/tests/analytics.spec.js` を新規作成する。先頭に、`context.route(/^https:\/\/([a-z0-9-]+\.)*(googletagmanager|google-analytics|doubleclick)\.(com|net)\//, ...)` で、`gtag/js` は空の JavaScript（`// stub`、`content-type: text/javascript`）で返し、それ以外は `abort()` する共通の準備と、Google への要求の URL を配列に記録するヘルパーを書く（外へ出さない理由をコメントに書く）。テスト：(1) 既定の `baseURL`（app-e2e）で `/`・`/forecast?lat=27.75&lon=129.05`・`/forecast?lat=abc&lon=1`・`/no-such-page` を開き、Google への要求が 0 件（SC-003）、(2) `process.env.E2E_ANALYTICS_BASE_URL` の `/forecast?lat=北緯二十七度&lon=129.05` を開いてから、ヘッダーの「UMIYOMI」のリンク（`.site-title a`。予報画面に「トップへ戻る」のリンクは無い）で `/` に移り、`page.evaluate(() => JSON.stringify(Array.from(window.dataLayer, (entry) => Array.from(entry))))` で取り出した `config` の `page_location` が `<origin>/`、`page_referrer` が `<origin>/forecast`、`screen_type` が `home` で、`dataLayer` 全体に `北緯`・`%E5%8C%97`（北の URL エンコード）が含まれないこと、(3) 同じく `/forecast?lat=27.75&lon=129.05` の `config` の `page_location` が `<origin>/forecast?lat=27.75&lon=129.05`・`screen_type` が `forecast` で、`gtag/js?id=G-E2ETEST000` の要求が 1 件だけあること、(4) Google への要求を `gtag/js` も含めてすべて `abort()` にした状態で、`/forecast?lat=27.75&lon=129.05` の表が 9 行出ること、名前を付けてお気に入りに保存でき「お気に入りに保存しました」が出ること、フッターの「フィードバック」から案内画面に移れること（FR-007・SC-004。入力補助は T022(2) と同じ起動オプションのテストで遮断時も確かめる）
- [X] T019 [US1] `scripts/verify-prod.sh` の「2. 画面とアセット」に、計測 ID なしで起動しているので `HOME_HTML` に `umiyomi-analytics` が含まれない（`ok '計測 ID 未設定では計測の設定を出さない' ... 'なし'`）ことと、`/forecast?lat=N27&lon=` の HTML にも含まれないことの確認を、フィードバックの未設定の確認の下に追加する。`app.js` が読み込む `analytics/analytics-*.js` の ES Module が compile 済みで 200 を返すことも、入力補助の JS と同じ書き方で確認する（FR-006・research R9）
- [X] T020 [US1] `docker compose exec php composer check`、`docker compose --profile e2e run --rm --build e2e`、`bash scripts/verify-prod.sh` を通す。既存の e2e（`favorites.spec.js`・`forecast.spec.js`）も通ること

**Checkpoint**: US1 単独で閲覧の計測が動く。ただし**案内画面（US3）ができるまで本番に `GA_MEASUREMENT_ID` を設定しない**（外部送信の公表が先。spec の User Story 3）

---

## Phase 4: User Story 2 - 運営者が主要な操作の回数とよく見られる海域を確認できる (Priority: P2)

**Goal**: お気に入りの保存・削除、現在地ボタン（成功・失敗）、フィードバックのフォームを開く操作を、許可リストで絞ったイベントとして送る。地点・名前・入力文字列・現在地は送らない。よく見られる海域は US1 の `page_location` で分かる

**Independent Test**: `analytics-rules.test.js` のイベントのテストが通り、e2e で 4 種類の操作の後の `dataLayer` に、許可したイベントだけがパラメーターなし（現在地は `result` だけ）で入る

### Tests for User Story 2（先に書いて失敗することを確認する）

- [X] T021 [P] [US2] `backend/tests/JavaScript/analytics-rules.test.js` に `eventPayload(name, params)` のテストを追加する：`('favorite_save', {})`・`('favorite_delete')`・`('feedback_form_open')` → `{ name, params: {} }`、`('current_location', { result: 'success' })`・`{ result: 'failure' }` → そのまま、`('current_location', { result: 'denied' })`・`('current_location', {})` → `null`、`('favorite_save', { latitude: 27.75, longitude: 129.05, name: '奄美沖' })` → `{ name: 'favorite_save', params: {} }`（許可しないパラメーターを落とす）、`('current_location', { result: 'success', latitude: 1 })` → `latitude` を落とす、許可リスト外の名前（`'page_view'`・`'favorite_rename'`・`'__proto__'`）→ `null`。戻り値の `params` が `Object.prototype` 由来のキーを含まないこと
- [X] T022 [P] [US2] `e2e/tests/analytics.spec.js` に、`E2E_ANALYTICS_BASE_URL` でのテストを追加する：(1) `/forecast?lat=27.75&lon=129.05` で名前「奄美沖」を付けてお気に入りに保存 → 削除（`dialog` を accept）し、`dataLayer` に `['event', 'favorite_save']` と `['event', 'favorite_delete']` が 1 件ずつあり、`dataLayer` の event エントリーのどれにも `奄美沖`・`27.75`・`129.05` が含まれないこと（`config` の `page_location` は除いて検査する）。削除の確認ダイアログで dismiss したときは `favorite_delete` が増えないこと、(2) 現在地ボタンは `isSecureContext` が必要で、http の app-e2e-analytics ではそのままだと出ない。この `describe` だけ `test.use({ launchOptions: { args: ['--unsafely-treat-insecure-origin-as-secure=http://app-e2e-analytics'] } })` を付け（理由をコメントに書く。skip にしない）、`context.grantPermissions(['geolocation'])` と `context.setGeolocation({ latitude: 35.1, longitude: 139.2 })` で `/` の「現在地」ボタンを押して `['event', 'current_location', { result: 'success' }]` があり、`35.1`・`139.2` がどの event エントリーにも含まれないこと。許可しない（`grantPermissions([])`）コンテキストでは `['event', 'current_location', { result: 'failure' }]` があること。Google への要求をすべて `abort()` にしたコンテキストでも、ボタンで入力欄に緯度・経度が入ること（SC-004）、(3) `/feedback?lat=27.75&lon=129.05` の「フィードバックのフォームを開く（新しいタブ）」を押し（新しいタブは `context.waitForEvent('page')` で受けて閉じる。外部フォームへの遷移は `context.route` で abort してよい）、`['event', 'feedback_form_open']` が 1 件あること

### Implementation for User Story 2

- [X] T023 [US2] `backend/assets/analytics/analytics-rules.js` に `export const eventPayload = (name, params = {}) => ...` を追加する。許可リストは `Object.freeze` した `Map`（`favorite_save: {}`、`favorite_delete: {}`、`current_location: { result: ['success', 'failure'] }`、`feedback_form_open: {}`）で持ち、`Object.hasOwn` を使って名前・パラメーター・値を検査する。必須パラメーター（`current_location` の `result`）がないときは `null`。許可リストをここに集める理由（呼び出し側が誤って地点を渡しても送らない。FR-004・SC-002）をコメントに書く。T021 が通ることを確認する
- [X] T024 [US2] `backend/assets/analytics/analytics.js` に `export const track = (name, params) => {...}` を追加する：`initAnalytics` が計測を始めていないとき（`<meta>` なし・オプトアウト中）は何もしない（モジュール内の変数で状態を持つ）。`eventPayload()` が `null` なら送らない。送るときは `window.gtag('event', payload.name, payload.params)`。さらに `initAnalytics` で、`doc` の `click` を委譲で拾い、`event.target.closest('[data-analytics-click]')` の `data-analytics-click` の値で `track()` を呼ぶ（名前は許可リストで縛られる。research R5）
- [X] T025 [P] [US2] `backend/assets/favorites/favorites-ui.js` で `import { track } from '../analytics/analytics.js'` し、保存に成功して `showMessage(panel, 'saved')` を呼ぶ直前に `track('favorite_save')`、予報画面の保存パネル（`outcome === 'removed'` の分岐、299 行付近）とトップ・切り替え一覧の削除（`confirmAndRemove` の結果が `removed` のとき、199 行付近）で `track('favorite_delete')` を呼ぶ。`cancelled`・`not_found`・`write_failed` では呼ばない。地点・名前を引数に渡さない
- [X] T026 [P] [US2] `backend/assets/coordinate-input/coordinate-input-ui.js` で `import { track } from '../analytics/analytics.js'` し、現在地ボタンのクリック処理で、`result.status === 'busy'` の早期 return の後に、`result.status === 'ok'` なら `track('current_location', { result: 'success' })`、それ以外なら `track('current_location', { result: 'failure' })` を呼ぶ。取得した緯度・経度を渡さない（FR-004・spec US2 の 5）
- [X] T027 [P] [US2] `backend/templates/feedback/index.html.twig` の `a.feedback-open` に `data-analytics-click="feedback_form_open"` を追加する。`href`・`target`・`rel` は変えない（contracts/web-ui.md「案内画面（004）への追加」）。`backend/tests/Functional/FeedbackPageTest.php` に、この属性があることの検査を 1 つ追加する
- [X] T028 [US2] `docker compose exec php composer check` と `docker compose --profile e2e run --rm --build e2e` を通す（T021・T022 が通ること、既存の `favorites.spec.js` が通ること）

**Checkpoint**: US1 + US2 で、閲覧と 4 種類の操作が計測される

---

## Phase 5: User Story 3 - 利用者が計測について知ることができる (Priority: P2)

**Goal**: 全画面のフッターから「外部送信について」の案内画面を開け、送信先・送る情報・目的・止める方法が分かる。「このブラウザでは計測しない」に切り替えると、以降どの画面でも `gtag.js` を読み込まない

**Independent Test**: `ExternalTransmissionPageTest` と `analytics-rules.test.js` のオプトアウトのテストが通り、e2e で「計測しない」に切り替えた後の画面で Google への要求が 0 件、再開すると次の画面から要求が出る

### Tests for User Story 3（先に書いて失敗することを確認する）

- [X] T029 [P] [US3] `backend/tests/Functional/ExternalTransmissionPageTest.php` を新規作成する。検査：(1) `/external-transmission` が 200 で、h1「外部送信について」、送信先「Google LLC（Google アナリティクス）」、送信される情報の各項目（「表示した地点の緯度・経度を含みます」「Cookie に保存される、ブラウザを識別するための ID」「緯度・経度の入力欄に入力した文字列、フィードバックのフォームに入る内容は送信しません。」を含む）、利用目的、「広告の配信には利用しません。」、Google のプライバシーポリシー・パートナーサイトのページ・オプトアウト アドオン（`https://tools.google.com/dlpage/gaoptout?hl=ja`）へのリンクが contracts/web-ui.md の文言どおりにあること（FR-009）、(2) 本文に「安全です」「出航できます」「問題ありません」が含まれないこと（FR-010。`HeadMetaTest::ASSERTIVE_PHRASES` と同じ語）、(3) `/`・`/forecast?lat=27.75&lon=129.05`・`/forecast?lat=abc&lon=1`・`/feedback`・404 の画面のフッターに `a.site-footer__external-transmission[href="/external-transmission"]` が 1 つあり、`/external-transmission` 自身には無いこと（SC-006）、(4) `.env.test` の既定（計測 ID なし）では `[data-analytics-optout]` が無く「この環境では現在、アクセス解析による計測を行っていません。」が出ること、`GA_MEASUREMENT_ID=G-TEST1234` にすると `[data-analytics-optout][hidden]` があり（JS が出す前提で初期は hidden）、3 状態の文言の要素（contracts の表）がすべて `hidden` で含まれ、未設定の文言は出ないこと、(5) `/external-transmission?lat=1&x=<script>` でもクエリが HTML に出ず 200 であること、Fake Provider の呼び出しが 0 回であること（FR-012）
- [X] T030 [P] [US3] `backend/tests/Functional/AnalyticsMarkupTest.php` の画面の表に `/external-transmission` → `data-screen="external_transmission"`・`data-path="/external-transmission"` を追加する
- [X] T031 [P] [US3] `backend/tests/JavaScript/analytics-rules.test.js` にオプトアウトのテストを追加する。偽の storage（`getItem`・`setItem`・`removeItem` を持つオブジェクト）で：`readOptOut(storage)` は `"1"` のときだけ true、`null`・`"0"`・`"true"`・`"1 "` は false、`storage` が `null` は false、`getItem` が例外を投げると false。`writeOptOut(storage, true)` は `setItem('umiyomi.analytics.optOut', '1')` して true を返す、`writeOptOut(storage, false)` は `removeItem` して true を返す、例外・`storage` が `null` なら false を返す。`umiyomi.favorites` に触れないこと
- [X] T032 [P] [US3] `e2e/tests/analytics.spec.js` にテストを追加する（`E2E_ANALYTICS_BASE_URL`）：(1) `/` のフッターの「外部送信について」を押すと案内画面に移り、切り替えに「現在：このブラウザでは計測しています」が出ている、(2) 「このブラウザでは計測しない」を押すと「このブラウザでは計測しないように設定しました。」とボタン「計測を再開する」が出て、`window['ga-disable-G-E2ETEST000']` が true、(3) 続けて `/`・`/forecast?lat=27.75&lon=129.05`・`/feedback` を開き、Google への要求が 0 件で `window.dataLayer` が未定義（SC-008）、その状態でお気に入りの保存が従来どおり動く、(4) リロードしても案内画面が「現在：このブラウザでは計測していません」、(5) 「計測を再開する」を押し「計測を再開しました。次に開いた画面から計測されます。」が出た後、`/` を開くと `gtag/js` の要求が 1 件出る、(6) `addInitScript` で localStorage へのアクセスを例外にした状態で案内画面を開くと「このブラウザでは切り替えを保存できません。上記のアドオンをご利用ください。」が出てボタンが無く、`/forecast?lat=27.75&lon=129.05` の表が 9 行出る、(7) `javaScriptEnabled: false` のコンテキストで案内画面を開くと、切り替えが見えずアドオンのリンクは見える

### Implementation for User Story 3

- [X] T033 [US3] `backend/assets/analytics/analytics-rules.js` に `export const OPT_OUT_KEY = 'umiyomi.analytics.optOut'`、`export const readOptOut = (storage) => ...`、`export const writeOptOut = (storage, optedOut) => ...` を追加する（try/catch で包み、例外は false。localStorage が使えないときに「計測する」を返す理由：選択を保存できない環境で、保存していない選択を推測しないため。research R7・plan のレビュー 5.）。T031 が通ることを確認する
- [X] T034 [US3] `backend/assets/analytics/analytics.js` の `initAnalytics` の先頭（`<meta>` を読んだ直後、`dataLayer` を作る前）で、`window.localStorage` の取得を try/catch で包んで `readOptOut()` を呼び、true なら `window['ga-disable-' + id] = true` を設定して何もせずに終わる（`dataLayer` も作らず `gtag.js` も差し込まない。SC-008）。`export const stopTracking = () => {...}` を追加し、計測中なら `window['ga-disable-' + id] = true` を設定し、以降の `track()` を何もしない状態にする
- [X] T035 [US3] `backend/src/Presentation/Web/Controller/ExternalTransmissionController.php` を新規作成する。`#[Route('/external-transmission', name: 'app_external_transmission', methods: ['GET'])]`、`__invoke(): Response` で `external_transmission/index.html.twig` を描画するだけ（`HomeController` と同じ書き方。Request を受け取らない＝クエリを使わない）
- [X] T036 [US3] `backend/templates/external_transmission/index.html.twig` を新規作成する。`base.html.twig` を継承し、`{% set analytics_page = {screen: 'external_transmission', path: path('app_external_transmission')} %}`、`title` は「外部送信について | UMIYOMI」、`meta_description` は「UMIYOMI が利用しているアクセス解析と、送信している情報・利用目的・計測を止める方法の案内です。」、フッターの自己リンクを消すためのブロック（T038 で作る `footer_external_transmission`）を空で上書きする。本文は contracts/web-ui.md「画面の構成」の文言どおりに、`page-header`（トップへのリンク）・h1・導入文・「送信先」「送信される情報」「利用目的」「Google によるデータの取り扱い」「計測を止める方法」の h2 と本文、トップへ戻るリンクを並べる。外部リンクは `rel="noopener"` を付けて同じタブで開く。「計測を止める方法」の中で、`{% if analytics.enabled %}` なら `<section class="analytics-optout" data-analytics-optout hidden aria-labelledby=...>` に、状態の文言（`data-analytics-optout-state="measuring"`・`"stopped"`・`"unavailable"`、各 `hidden`）、切り替え直後の通知（`data-analytics-optout-message="stopped"`・`"resumed"`、`role="status"`、`hidden`）、ボタン（`data-analytics-optout-action="stop"`「このブラウザでは計測しない」・`"resume"`「計測を再開する」、`type="button"`・`hidden`）と「切り替えはこのブラウザだけに保存され、サーバーには送信しません。」を置く。`{% else %}` なら「この環境では現在、アクセス解析による計測を行っていません。」を出す。アドオンの案内は常に出す
- [X] T037 [US3] `backend/assets/analytics/analytics-optout-ui.js` を新規作成する。`export const initAnalyticsOptOut = (doc) => {...}`：`[data-analytics-optout]` がなければ何もしない。localStorage が取得できない（例外・`null`）なら領域を表示して `unavailable` の状態だけを見せる。それ以外は領域を表示し、`readOptOut()` に応じて `measuring`／`stopped` の状態とボタンを見せる。「計測しない」：`writeOptOut(storage, true)` が成功したら `stopTracking()` を呼び、`stopped` の状態と `stopped` の通知を見せ、「計測を再開する」にフォーカスを移す。「再開する」：`writeOptOut(storage, false)` が成功したら `measuring` の状態と `resumed` の通知を見せる（その場では gtag を読み込まない。spec US3 の 4）。保存に失敗したら `unavailable` を見せる。文言は Twig に置き、ここでは `hidden` の切り替えだけを行う（`favorites-ui.js`・`coordinate-input-ui.js` と同じ流儀）。`backend/assets/app.js` で `initAnalytics(document)` の直後に `initAnalyticsOptOut(document)` を呼ぶ
- [X] T038 [US3] `backend/templates/base.html.twig` のフッターで、`footer_feedback` ブロックの直後に `{% block footer_external_transmission %}<a class="site-footer__external-transmission" href="{{ path('app_external_transmission') }}">外部送信について</a>{% endblock %}` を追加する（計測の設定の有無に関わらず出す理由：FR-009 は全画面からたどり着けることを求めるため）。`backend/assets/styles/app.css` の `.site-footer__feedback` のルールを `.site-footer__feedback, .site-footer__external-transmission` にまとめ（高さ 44px 以上・行を分ける）、案内画面の見出し・リスト・`.analytics-optout`（枠線・余白、ボタンは既存のボタンの見た目に合わせ、スマホ幅で横にはみ出さない）のスタイルを足す
- [X] T039 [US3] `scripts/verify-prod.sh` の「2. 画面とアセット」に、`/external-transmission` が 200 であること、計測 ID なしでは「この環境では現在、アクセス解析による計測を行っていません。」が出ること、トップのフッターに `site-footer__external-transmission` があることの確認を追加する
- [X] T040 [US3] `docker compose exec php composer check`、`docker compose --profile e2e run --rm --build e2e`、`bash scripts/verify-prod.sh` を通す（T029〜T032 が通ること）

**Checkpoint**: 全ストーリーがそろい、本番に計測 ID を設定できる状態

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T041 [P] `deploy/README.md` の「フィードバックのフォームを設定する」の後に「## アクセス解析を設定する」を追加する（plan のレビュー 4. の条件 (a)・(b)）。内容：計測 ID を設定するまで計測しない（未設定のままデプロイしてよい）、案内画面（US3）を含む版がデプロイされてから設定すること、手順（1. GA4 のプロパティとウェブのデータストリームを作る。管理者権限は運営者 1 人、他の人には閲覧権限だけ、2. 拡張計測機能は「ページビュー」以外をすべて無効、ページビューの詳細設定の「ブラウザの履歴イベントに基づくページの変更」も無効、**離脱クリックを有効にするとフィードバックのフォームの URL（入力文字列を含みうる）が送られるので必ず無効**、3. Google シグナル無効、広告サービスとリンクしない、データ保持 2 か月、4. カスタムディメンション（イベントスコープ）`screen_type`・`result` を登録、5. `/opt/umiyomi/.env.production` に `GA_MEASUREMENT_ID` を追記して `docker compose -f compose.prod.yml --env-file .env.production up -d`、6. 本番で GA4 のリアルタイムと開発者ツールで `dl`・`dr`・イベントに入力文字列が含まれないことを確かめる）、「管理画面の設定を後から変えるときは、外部送信の案内の内容と合っているかを確かめる」、計測をやめるときは空にして再起動。詳細は `specs/006-analytics-tag/quickstart.md` を参照するリンクを置く
- [X] T042 [P] `docs/SPEC.md` に、アクセス解析（GA4）を導入したこと・送る情報の範囲・外部送信の案内画面（`/external-transmission`）の記述が必要な節（画面一覧・外部サービスなど）があれば、spec の内容に合わせて最小限追記する。該当する節が無ければ変更しない
- [X] T043 `docker compose exec php composer cs:fix` のあと `docker compose exec php composer check`、`docker compose --profile e2e run --rm --build e2e`、`bash scripts/verify-prod.sh` をすべて通す
- [ ] T044 quickstart.md の「手動確認（人間のレビュー）」の 1〜6 を、`backend/.env.local` にテスト用プロパティの `GA_MEASUREMENT_ID` を置いて行い、結果を報告する（確認が終わったら `.env.local` から消す）。テスト用プロパティが無い場合は、ローカルでの確認をせず、手動確認を人間のレビューに回すと報告する（存在しないプロパティ宛てに Google へ送信しないため）

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 依存なし
- **Foundational (Phase 2)**: Setup の後。全ストーリーをブロックする
- **US1 (Phase 3)**: Foundational の後
- **US2 (Phase 4)**: US1 の `analytics.js`（T017）と `analytics-rules.js`（T016）に依存する
- **US3 (Phase 5)**: US1 の `analytics.js`（T017）に依存する（オプトアウトの判定を差し込むため）。US2 とは独立で、US2 と並行して進められる（`analytics.js`・`analytics-rules.js`・`analytics.spec.js` は両方が編集するので、同時に触らないよう順番を決める）
- **Polish (Phase 6)**: 全ストーリーの後

### 本番への計測 ID の設定

US1 だけで計測は動くが、外部送信の公表（US3）より先に本番で計測を始めない。本番に `GA_MEASUREMENT_ID` を設定するのは、US3 を含む版がデプロイされ、T041 の手順で GA4 管理画面の設定を終えてから。

### Within Each User Story

- テストを先に書き、失敗することを確認してから実装する
- PHP（ViewModel・Controller）→ Twig → JavaScript → e2e の順
- 各ストーリーの最後に `composer check`（と e2e・verify-prod）を通す

### Parallel Opportunities

- Phase 2：T002・T005・T006 は別ファイルで並行できる（T003 は T002 の後、T004 は T003 の後）
- US1：T008・T009・T010・T011 のテストは並行して書ける。T014 と T016 は並行できる。T018（e2e）は T017 と並行して書ける
- US2：T021・T022 は並行。T025・T026・T027 は別ファイルで並行できる（T023・T024 の後）
- US3：T029〜T032 は並行。T035 は T033・T034 と並行できる

---

## Parallel Example: User Story 1

```text
# テストを並行して書く
Task: "T008 ForecastPageViewModelFactoryTest に analyticsScreen・analyticsQuery のテストを追加"
Task: "T009 AnalyticsMarkupTest を新規作成"
Task: "T010 AnalyticsUnconfiguredTest を新規作成"
Task: "T011 analytics-rules.test.js（referrer・page_location）を新規作成"

# 実装のうち独立しているもの
Task: "T014 home・feedback・error のテンプレートに analytics_page"
Task: "T016 analytics-rules.js の pageReferrer・pageLocation"
```

## Parallel Example: User Story 2

```text
Task: "T025 favorites-ui.js で保存・削除の成功時に track()"
Task: "T026 coordinate-input-ui.js で現在地の成功・失敗に track()"
Task: "T027 feedback/index.html.twig に data-analytics-click"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1・2 を終える
2. Phase 3（US1）を終え、Functional・node・e2e・verify-prod で確かめる
3. **ここで止めてレビューできる**が、本番に計測 ID は設定しない（US3 が公表の前提）

### Incremental Delivery

1. Setup + Foundational → 計測の設定が通る（既定では何も変わらない）
2. US1 → 閲覧の計測（未設定の本番にデプロイしてよい）
3. US3 → 案内画面とオプトアウト（ここで本番に計測 ID を設定できる）
4. US2 → 操作のイベント
5. Polish → deploy README（GA4 管理画面の必須設定）・SPEC.md・最終確認

US2 と US3 は同じ P2。本番で計測を始めるために必要なのは US3 なので、1 人で進めるときは US3 → US2 の順でもよい（タスク ID の順は spec の記載順に合わせている）。

---

## Notes

- [P] = 別ファイルで、未完了のタスクに依存しない
- [Story] でユーザーストーリーとの対応を追える
- テストが失敗することを確認してから実装する
- タスクまたは論理的なまとまりごとにコミットする
- e2e で Google への通信を必ずスタブ・遮断する。`E2E_ANALYTICS_BASE_URL` を使うテストで、`context.route` の準備を忘れない

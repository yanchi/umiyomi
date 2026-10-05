---

description: "Task list for 004-feedback-channel"
---

# Tasks: フィードバック導線

**Input**: Design documents from `/specs/004-feedback-channel/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-ui.md, quickstart.md

**Tests**: Constitution 原則 V の重点領域（受け取る値の検証＝座標 validation に当たるもの・ViewModel 変換・HTTP の主要経路）に当たるため、テストを必須とし、実装より先に書いて失敗することを確認する。
文脈の検証・外部フォームの URL・ViewModel は PHPUnit の Unit、フッター・案内画面・未設定は Functional、本番イメージでの未設定は `scripts/verify-prod.sh` で確認する。
実際の外部フォーム・新しいタブ・Referer・スマホ幅は quickstart の手動確認で担保する。JavaScript は追加しないので `tests/JavaScript` は変えない。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1〜US3）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`、名前空間は `App\` = `backend/src/`、`App\Tests\` = `backend/tests/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- PHP は `declare(strict_types=1);`、新規クラスは `final readonly class`（enum・Controller を除く。Controller は既存と同じ `final class ... extends AbstractController`）。コメントは日本語で「なぜ」だけを書く
- すべて `backend/src/Presentation/Web/` に置く。Domain・Application・Infrastructure、Deptrac・Dockerfile・CI の設定は変更しない（research R1）。UseCase は作らない
- `FeedbackController` は UseCase・キャッシュ・回数制限を注入しない（FR-013）
- フィードバックの内容を受け取る・保存するルート・フォームを作らない（FR-001）。新しい Composer / npm パッケージ・JavaScript を追加しない
- Twig では値を自動エスケープで出し、`|raw` を使わない（contracts/web-ui.md）
- 文言は contracts/web-ui.md・data-model.md の文字列を一字一句そのまま使う。「安全です」「出航できます」「問題ありません」のような断定を入れない（FR-005、原則 IV）
- 外部フォームの URL は `parse_str` / `http_build_query` で分解・再構築しない（research R3。`entry.1000` の `.` が `_` に変わるため）
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 新しいパッケージ・拡張は追加しない（plan.md）。変更前の基準を確認するだけ

- [ ] T001 ブランチ `004-feedback-channel` で `docker compose up -d` のあと `docker compose exec php composer check` がすべて通ることを確認する（失敗した場合は既存の問題として先に報告し、この機能の作業と混ぜない）

**Checkpoint**: 変更前の `composer check` が通っている

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 全ストーリーが使う「フォームの設定と URL の組み立て」「案内画面が受け取る値の検証（文脈）」と、その設定

**⚠️ CRITICAL**: このフェーズが終わるまで、どのユーザーストーリーにも着手しない

### Tests for Foundational ⚠️

> 先に書き、失敗することを確認してから実装する

- [ ] T002 [P] `backend/tests/Unit/Presentation/Web/Feedback/FeedbackFormLinkTest.php` を作成し、`FeedbackFormLink` を次で確認する（data-model.md・research R2/R3）：
  - `isAvailable()`：URL `https://forms.example.test/feedback?usp=pp_url` + 欄 `entry.1000` → true。URL が空・欄が空・両方空 → false。`http://forms.example.test/feedback` → false。`https://forms.example.test/feedback#top` → false
  - `urlFor(null)`：設定された URL をそのまま返す（`https://forms.example.test/feedback?usp=pp_url`）
  - `urlFor('緯度 27.75・経度 129.05')`：`?` を含む URL には `&`、含まない URL（`https://tally.so/r/XXX`）には `?` でつなぐ。期待値は `'https://forms.example.test/feedback?usp=pp_url&entry.1000=' . rawurlencode('緯度 27.75・経度 129.05')`
  - 欄の名前 `entry.1000` の `.` がそのまま残る（`entry_1000` にならない）。文章中の `&`・`=`・`#`・空白（`%20`）・日本語がエンコードされる
  - 未設定のときの `urlFor()` は `\LogicException`
- [ ] T003 [P] `backend/tests/Unit/Presentation/Web/Input/FeedbackContextParserTest.php` を作成し、`FeedbackContextParser::parse($lat, $lon, $updated, $inputLat, $inputLon)` を DataProvider で確認する（data-model.md「検証の規則」、research R4）：
  - 文脈の決まり方：`'27.75','129.05','2026/10/05 09:00','',''` → `Forecast`（lastUpdated `'2026/10/05 09:00'`）。`'27.75','129.05','','',''` → `Forecast`（lastUpdated null）。地点と入力の文字列が両方来たら `Forecast`（inputLatitude / inputLongitude は null）。`'','','','北緯二十七度','129.05'` → `RejectedInput`。全部空 → `None`
  - 緯度・経度の形と範囲：`'90.00'`・`'-90.00'`・`'180.00'`・`'-180.00'`・`'0.00'` は有効。`'90.01'`・`'-180.01'`・`'27.7'`・`'27.750'`・`'27'`・`'+27.75'`・`' 27.75'`・`'２７．７５'`・`'1000.00'`・`'27.75N'` は無効。片方だけ有効（`'27.75','abc'`）→ 地点を使わず、入力の文字列がなければ `None`
  - `updated`：`'2026/02/30 09:00'`・`'2026/10/05 24:00'`・`'2026-10-05 09:00'`・`'2026/10/5 09:00'`・`'任意の文章'` → 地点は使い、lastUpdated は null。地点がないときの `updated` だけ → `None`
  - 入力の文字列：101 文字（`str_repeat('あ', 101)`）→ 先頭 100 文字（`mb_strlen` が 100）。100 文字はそのまま。制御文字（`"27\n75"`、`"\t"`、`"\x7f"`）→ 半角空白に置き換え。不正な UTF-8（`"\xff"`）→ その欄は空として扱う。片方の欄だけ空でも `RejectedInput`（空の欄は `''`）。2 欄とも空 → `None`
  - `FeedbackContextParser::MAX_INPUT_LENGTH` が 100

### Implementation for Foundational

- [ ] T004 [P] `backend/src/Presentation/Web/Input/FeedbackContextType.php` に enum `FeedbackContextType`（`None` / `Forecast` / `RejectedInput`）を作成する
- [ ] T005 [P] `backend/src/Presentation/Web/Input/FeedbackContext.php` を作成する：`type`・`latitude`・`longitude`・`lastUpdated`・`inputLatitude`・`inputLongitude`（data-model.md の表）。コンストラクタは private にし、名前付きコンストラクタ `none()` / `forecast(string $latitude, string $longitude, ?string $lastUpdated)` / `rejectedInput(string $inputLatitude, string $inputLongitude)` だけで作る（不正な組み合わせを作れないようにする）
- [ ] T006 `backend/src/Presentation/Web/Input/FeedbackContextParser.php` を作成し、T003 を通す：`public const int MAX_INPUT_LENGTH = 100;`、緯度・経度は `^-?\d{1,3}\.\d{2}$` かつ範囲内、`updated` は `DateTimeImmutable::createFromFormat('!Y/m/d H:i', ...)` で読んで同じ書式に戻して一致するものだけ、入力の文字列は `mb_check_encoding` → `preg_replace('/\p{Cc}/u', ' ', ...)` → `mb_substr(..., 0, 100)`。文脈は `Forecast` → `RejectedInput` → `None` の順で決める。不正な値は例外にせず捨てる。コメントに「なぜ 003 の `CoordinateQueryParser` を使わず正規形だけを受け付けるか」（research R4）を書く（T004・T005 の後）
- [ ] T007 [P] `backend/src/Presentation/Web/Feedback/FeedbackFormLink.php` を作成し、T002 を通す：`__construct(string $formUrl, string $prefillField)`、`isAvailable()`（両方が空でなく、URL が `https://` で始まり `#` を含まない）、`urlFor(?string $prefill)`（未設定なら `\LogicException`、null なら URL をそのまま、そうでなければ `str_contains($url, '?') ? '&' : '?'` と `rawurlencode($field) . '=' . rawurlencode($prefill)` を文字列で足す）。コメントに `http_build_query` を使わない理由（research R3）を書く
- [ ] T008 設定を追加する（T007 の後）：
  - `backend/.env` に `###> feedback ###` ブロックで `FEEDBACK_FORM_URL=` と `FEEDBACK_FORM_PREFILL_FIELD=`（空。未設定ならリンクを出さない旨のコメント）
  - `backend/.env.test` に `FEEDBACK_FORM_URL='https://forms.example.test/feedback?usp=pp_url'` と `FEEDBACK_FORM_PREFILL_FIELD='entry.1000'`
  - `backend/config/services.yaml` に `App\Presentation\Web\Feedback\FeedbackFormLink` の `arguments: { $formUrl: '%env(FEEDBACK_FORM_URL)%', $prefillField: '%env(FEEDBACK_FORM_PREFILL_FIELD)%' }`
  - `backend/config/packages/twig.yaml` に `globals: { feedback_form: '@App\Presentation\Web\Feedback\FeedbackFormLink' }`
- [ ] T009 `docker compose exec php composer check` を通す

**Checkpoint**: 文脈の検証と外部フォームの URL の組み立てが Unit Test で確認でき、Twig から `feedback_form` を参照できる

---

## Phase 3: User Story 1 - どの画面からでもフィードバックの送り先にたどり着ける (Priority: P1) 🎯 MVP

**Goal**: 全画面のフッターに「フィードバック」のリンクを出し、同じタブで案内画面（`GET /feedback`）を開き、そこから外部フォームを新しいタブで開ける。案内画面から元の画面へ戻れる。フォームが未設定ならリンクを出さず `/feedback` は 404

**Independent Test**: トップ画面・予報画面（200/503/429/422）のフッターのリンクから案内画面が開き、フォームを開くリンクが 1 つだけ `target="_blank" rel="noopener noreferrer"` で出ていること、戻るリンクで同じ地点の予報画面に戻れること、未設定ではリンクが出ないことを Functional Test で確認する

### Tests for User Story 1 ⚠️

> 先に書き、失敗することを確認してから実装する

- [ ] T010 [P] [US1] `backend/tests/Unit/Presentation/Web/ViewModel/FeedbackPageViewModelFactoryTest.php` を作成し、`FeedbackPageViewModelFactory::create()`（`FeedbackFormLink` は T002 と同じ例の値で生成）の戻り先を確認する（research R6）：
  - `FeedbackContext::none()` → `backRoute` `'app_home'`・`backParameters` `[]`・`backLabel` `'トップ画面に戻る'`・`formUrl` は設定された URL のまま・`prefillSummary` null・`quotedInputs` null
  - `forecast('27.75', '129.05', '2026/10/05 09:00')` → `'app_forecast'`・`['lat' => '27.75', 'lon' => '129.05']`・`'予報画面に戻る'`
  - `rejectedInput('北緯二十七度', '129.05')` → `'app_forecast'`・`['lat' => '北緯二十七度', 'lon' => '129.05']`・`'入力画面に戻る'`
- [ ] T011 [P] [US1] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` に `feedbackQuery` の確認を追加する：新しい予報・代替表示・取得失敗（`Unavailable`）・回数制限（`RateLimited`）で `lat`・`lon` が `favoriteTarget` と同じ `%.2f` の文字列（`'27.75'`・`'129.05'`、-0.0 は `'0.00'`）。`createForInvalidInput()` は `[]`（`updated`・`input_*` は US3 で追加する）
- [ ] T012 [P] [US1] `backend/tests/Functional/FeedbackPageTest.php` を作成する（既存の `ForecastPageTest` と同じく `FakeMarineForecastProvider` の `willFail()`・`callCount()` を使う）：
  - トップ画面：`footer a.site-footer__feedback` が 1 つあり、`href` が `/feedback`、文言が「フィードバック」、`target` 属性がない。Open-Meteo の表記も残っている
  - 予報画面 200（`/forecast?lat=27.75&lon=129.05`）：フッターのリンクの `href` が `/feedback?lat=27.75&lon=129.05` で始まる。503（`willFail()` で過去の予報なし）・429（既存の回数制限のテストと同じ手順）でも `lat`・`lon` 付きのリンクがある。422（`/forecast?lat=abc&lon=129.05`）でもフッターにリンクがある
  - `GET /feedback`：200、`h1` が「フィードバック」、`a.feedback-open` がちょうど 1 つで `href` が `https://forms.example.test/feedback?usp=pp_url`、`target="_blank"`、`rel` に `noopener` と `noreferrer` を含む。`<meta name="referrer" content="no-referrer">` と `<meta name="robots" content="noindex">` がある。案内画面自身のフッターには `a.site-footer__feedback` がない
  - 戻るリンク：`/feedback` → `href="/"`・「トップ画面に戻る」。`/feedback?lat=27.75&lon=129.05` → `href="/forecast?lat=27.75&lon=129.05"`・「予報画面に戻る」。予報画面のフッターのリンクを `$client->click()` で辿り、戻るリンクを辿ると同じ地点の予報画面（200）になる
  - `/feedback`（Query あり・なし）を開いても `callCount()` が 0 のまま（FR-013）
  - `POST /feedback` は 405（フィードバックを受け取らない。FR-001）
- [ ] T013 [P] [US1] `backend/tests/Functional/FeedbackUnconfiguredTest.php` を作成する（research R8）：`setUp` で `createClient()` の前に `$_ENV` / `$_SERVER` の `FEEDBACK_FORM_URL`・`FEEDBACK_FORM_PREFILL_FIELD` を空文字にし（元の値を退避）、`tearDown` で戻す。トップ画面・予報画面（200）に `a.site-footer__feedback` がなく、予報画面は従来どおり 200 で表示される（FR-009）。`/feedback` と `/feedback?lat=27.75&lon=129.05` が 404。片方だけ設定した場合（URL だけ）も同じ

### Implementation for User Story 1

- [ ] T014 [P] [US1] `backend/src/Presentation/Web/ViewModel/FeedbackPageViewModel.php` を作成する：`formUrl`・`prefillSummary`（`?string`）・`quotedInputs`（`array{latitude: string, longitude: string}|null`）・`backRoute`・`backParameters`（`array<string, string>`）・`backLabel`（data-model.md）
- [ ] T015 [US1] `backend/src/Presentation/Web/ViewModel/FeedbackPageViewModelFactory.php` を作成し、T010 を通す：`FeedbackFormLink` を注入し、`create(FeedbackContext $context): FeedbackPageViewModel` で文脈ごとの戻り先を `match` で決める。この段階では `formUrl` は `urlFor(null)`、`prefillSummary`・`quotedInputs` は null（US3 で埋める）（T014 の後）
- [ ] T016 [US1] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModel.php` に `public array $feedbackQuery = []`（`@param array<string, string> $feedbackQuery` とコメント：フッターのフィードバックのリンクに付ける Query）を最後の引数として追加し、`ForecastPageViewModelFactory::create()` の 2 つの経路（予報なし・あり）で `favoriteTarget` と同じ値から `['lat' => ..., 'lon' => ...]` を渡す。`createForInvalidInput()` は既定の `[]` のまま。T011 を通す
- [ ] T017 [US1] `backend/src/Presentation/Web/Controller/FeedbackController.php` を作成する：`#[Route('/feedback', name: 'app_feedback', methods: ['GET'])]`、`FeedbackFormLink`・`FeedbackContextParser`・`FeedbackPageViewModelFactory` を注入。`isAvailable()` が false なら `throw $this->createNotFoundException()`（FR-012）。`$request->query->getString()` で `lat`・`lon`・`updated`・`input_lat`・`input_lon` を読み、`feedback/index.html.twig` に `page` だけを渡す（T015 の後）
- [ ] T018 [US1] `backend/templates/base.html.twig` を変更する：`<head>` の `<title>` の後に空の `{% block head_meta %}{% endblock %}` を追加。フッターの Open-Meteo のリンクより前に `{% block footer_feedback %}{% if feedback_form.available %}<a class="site-footer__feedback" href="{{ path('app_feedback', feedback_query|default({})) }}">フィードバック</a>{% endif %}{% endblock %}` を追加（`target` を付けない。FR-014）
- [ ] T019 [US1] `backend/templates/forecast/index.html.twig` のブロックの外（`{% extends %}` の直後）に `{% set feedback_query = page.feedbackQuery %}` を追加し、コメントで「親テンプレートのフッターのリンクに表示中の地点を渡すため」（research R5）を書く
- [ ] T020 [US1] `backend/templates/feedback/index.html.twig` を作成する（contracts/web-ui.md「画面の構成」）：`{% extends 'base.html.twig' %}`、`title` は `フィードバック | UMIYOMI`、`head_meta` に `<meta name="referrer" content="no-referrer">` と `<meta name="robots" content="noindex">`、`footer_feedback` を空で上書き。本文は `header.page-header` の「UMIYOMI」（トップへのリンク）、`h1`「フィードバック」、リード文「不具合・要望・予報の値についての気づきなどを、外部のフォームで受け付けています。」、`<a class="feedback-open" href="{{ page.formUrl }}" target="_blank" rel="noopener noreferrer">フィードバックのフォームを開く（新しいタブ）</a>`、`<a class="feedback-back" href="{{ path(page.backRoute, page.backParameters) }}">← {{ page.backLabel }}</a>`。T012・T013 を通す（T017〜T019 の後）
- [ ] T021 [P] [US1] `backend/assets/styles/app.css` に追加する：`.site-footer__feedback` は `display: inline-block`・上下の余白で高さ 44px 以上・Open-Meteo の表記とは別の行（フッター内の要素を縦に並べる）。`.feedback-open` はボタンの見た目で `display: inline-block`・高さ 44px 以上・`max-width: 100%`・折り返し可。`.feedback-back` も指で押せる高さにする。360px 幅で横にはみ出さない（FR-002、SC-004）
- [ ] T022 [US1] `docker compose exec php composer check` を通す

**Checkpoint**: US1 の受け入れシナリオ 1〜3・5・6 が Functional Test と quickstart 1・2・4・8 で確認できる（4 の「送れたことが分かる」はフォームサービス側）。この時点で MVP としてデプロイできる

---

## Phase 4: User Story 2 - 緊急の連絡先や出航可否の相談窓口と誤解しない (Priority: P2)

**Goal**: 案内画面の、フォームを開く操作より前に 3 つの案内（返信を約束しない・緊急通報は 118 番・公式の警報・注意報の確認）を出し、返信用の連絡先が任意であることを示す

**Independent Test**: `/feedback` の HTML に 3 つの案内があり、`a.feedback-open` より前に出ていること、断定表現がないことを Functional Test で確認する

### Tests for User Story 2 ⚠️

- [ ] T023 [US2] `backend/tests/Functional/FeedbackPageTest.php` に追加する（T012 の後。同じファイル）：
  - `/feedback` に `.feedback-notes` があり、見出し「送る前にご確認ください」と次の 3 つの文言を一字一句含む：「返信や対応をお約束するものではありません。」「海上での事件・事故の緊急通報は、海上保安庁（118 番）へ連絡してください。このフォームでは受け付けていません。」「出航の判断には、気象庁などが発表する警報・注意報もあわせて確認してください。」
  - HTML 上で 3 つの案内の位置が `a.feedback-open` より前（`strpos` で比較。FR-004）
  - `a.feedback-open` の後に「返信用の連絡先の記入は任意です。」がある（FR-007）
  - `/feedback`・`/feedback?lat=27.75&lon=129.05&updated=2026%2F10%2F05%2009%3A00`・`/feedback?input_lat=abc`・トップ画面のフッターの HTML に「安全です」「出航できます」「問題ありません」を含まない（DataProvider。FR-005、SC-005）

### Implementation for User Story 2

- [ ] T024 [US2] `backend/templates/feedback/index.html.twig` のリード文の後・`a.feedback-open` の前に `<section class="feedback-notes" aria-labelledby="feedback-notes-title">`（`h2#feedback-notes-title`「送る前にご確認ください」と 3 項目の `ul`）を追加し、`a.feedback-open` の直後に `<p class="feedback-contact-note">返信用の連絡先の記入は任意です。</p>` を追加する。T023 を通す
- [ ] T025 [US2] `backend/assets/styles/app.css` に `.feedback-notes`（枠で囲み、既存の `.notice` と同じ系統の色。360px で折り返す）を追加する
- [ ] T026 [US2] `docker compose exec php composer check` を通す

**Checkpoint**: US2 の受け入れシナリオ 1〜4 が Functional Test で確認できる

---

## Phase 5: User Story 3 - 予報画面からの不具合報告で、どの地点・いつの予報の話か伝わる (Priority: P3)

**Goal**: 予報画面から来たときは地点・最終更新日時を、取得失敗からは地点だけを、入力エラーからは入力した文字列を、案内画面に表示し、外部フォームの 1 つの欄に事前入力する。決まった形でない値は表示も事前入力もしない

**Independent Test**: 予報画面（200/503/422）のフッターのリンクから開いた案内画面に事前入力の内容が表示され、`a.feedback-open` の `href` にその文章が `entry.1000=` で付いていること、不正な値・トップ画面からでは何も付かないことを Functional Test で確認する

### Tests for User Story 3 ⚠️

> 先に書き、失敗することを確認してから実装する

- [ ] T027 [P] [US3] `backend/tests/Unit/Presentation/Web/ViewModel/FeedbackPageViewModelFactoryTest.php` に追加する（data-model.md「事前入力の文章」）：
  - `forecast('27.75', '129.05', '2026/10/05 09:00')` → `formUrl` が `'https://forms.example.test/feedback?usp=pp_url&entry.1000=' . rawurlencode('緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00')`、`prefillSummary` が「緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00 がフォームに入ります。」、`quotedInputs` null
  - `forecast('-27.75', '129.05', null)` → 文章「緯度 -27.75・経度 129.05」、`prefillSummary`「緯度 -27.75・経度 129.05 がフォームに入ります。」
  - `rejectedInput('北緯二十七度', '129.05')` → 文章「受け付けられなかった入力：緯度欄「北緯二十七度」、経度欄「129.05」」、`prefillSummary` null、`quotedInputs` `['latitude' => '北緯二十七度', 'longitude' => '129.05']`。`rejectedInput('', 'abc')` → 文章・引用とも緯度欄は「（空）」
  - `none()` → `formUrl` は設定された URL のまま（事前入力の欄を付けない）
- [ ] T028 [P] [US3] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` の `feedbackQuery` の期待を更新する：新しい予報・代替表示では `updated` が `fetchedAt->format('Y/m/d H:i')`（「最終更新：」の表示と同じ値）。取得失敗・回数制限は `lat`・`lon` だけ（`updated` なし）。`createForInvalidInput()` は `['input_lat' => 生の緯度欄, 'input_lon' => 生の経度欄]`、101 文字の入力は `mb_substr` で先頭 `FeedbackContextParser::MAX_INPUT_LENGTH` 文字
- [ ] T029 [P] [US3] `backend/tests/Functional/FeedbackPageTest.php` に追加する（T023 の後。同じファイル）：
  - 予報画面 200 のフッターのリンクを `click()` → 案内画面の `.feedback-prefill` に `.last-updated` と同じ日時を含む「緯度 27.75・経度 129.05、最終更新 YYYY/MM/DD HH:mm がフォームに入ります。」と「送る前にフォームで確認でき、送りたくない場合は消せます。」があり、`a.feedback-open` の `href` が `entry.1000=` と同じ文章のエンコードで終わる（SC-003）
  - 503 から辿る → 「緯度 27.75・経度 129.05 がフォームに入ります。」（最終更新なし。FR-015）
  - 422（`/forecast?lat=北緯二十七度&lon=129.05`）から辿る → `blockquote.feedback-quote` が 2 つで、それぞれ「北緯二十七度」「129.05」。`href` に `entry.1000=` が付く。戻るリンクで同じ 422 の画面になる
  - 不正な値は無視する（FR-016、SC-003）：`/feedback?lat=91.00&lon=129.05&updated=任意の文章` → `.feedback-prefill` も `blockquote` もなく、`href` は設定された URL のまま、3 つの案内とフォームを開くリンクは出る。`/feedback?lat=27.75&lon=129.05&updated=任意の文章` → 最終更新なしの文章。HTML に「任意の文章」が出ない
  - エスケープ（FR-017）：`/feedback?input_lat=<script>alert(1)</script>` → `blockquote` の中に `script` 要素がなく、テキストが `<script>alert(1)</script>`
  - 101 文字の `input_lat` → 引用は先頭 100 文字
  - トップ画面から辿る → `.feedback-prefill`・`blockquote` がなく、`href` は設定された URL のまま（US3 シナリオ 3）
  - 外部へ渡す値は事前入力の文章だけ（FR-010）：予報画面 200 から辿ったときの `a.feedback-open` の `href` が、設定された URL に `&entry.1000=` と文章のエンコードを足したものと完全に一致する（お気に入り・端末などほかの値が付いていない）

### Implementation for User Story 3

- [ ] T030 [US3] `backend/src/Presentation/Web/ViewModel/FeedbackPageViewModelFactory.php` に事前入力の文章（`Forecast`：`緯度 %s・経度 %s` と、最終更新があれば `、最終更新 %s`。`RejectedInput`：`受け付けられなかった入力：緯度欄「%s」、経度欄「%s」`、空の欄は `（空）`）を作る処理を追加し、`formUrl` を `urlFor(文章)`、`prefillSummary`（`Forecast` のとき `文章 . ' がフォームに入ります。'`）、`quotedInputs`（`RejectedInput` のとき）を埋める。T027 を通す
- [ ] T031 [US3] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` の `feedbackQuery` を変更する：予報があるときは `updated`（`$forecast->fetchedAt->format('Y/m/d H:i')`）を足す。`createForInvalidInput()` で `input_lat`・`input_lon` に `CoordinateQuery` の `rawLatitude`・`rawLongitude` を `mb_substr(..., 0, FeedbackContextParser::MAX_INPUT_LENGTH)` で渡す。T028 を通す
- [ ] T032 [US3] `backend/templates/feedback/index.html.twig` の `.feedback-notes` の後・`a.feedback-open` の前に追加する：`page.prefillSummary` があれば `<p class="feedback-prefill">{{ page.prefillSummary }}</p>`。`page.quotedInputs` があれば `<div class="feedback-prefill">` に「受け付けられなかった次の入力がフォームに入ります。」と、「緯度欄に入力した文字列」「経度欄に入力した文字列」の見出しごとの `<blockquote class="feedback-quote">`。どちらかがあるときだけ「送る前にフォームで確認でき、送りたくない場合は消せます。」。`|raw` を使わない。T029 を通す
- [ ] T033 [P] [US3] `backend/assets/styles/app.css` に `.feedback-prefill` と `.feedback-quote`（左の罫線で UMIYOMI の文と区別し、`overflow-wrap: anywhere` で 100 文字の連続した文字列でも 360px で横にはみ出さない）を追加する
- [ ] T034 [US3] `docker compose exec php composer check` を通す

**Checkpoint**: US3 の受け入れシナリオ 1〜5 が Functional Test と quickstart 3・5・6 で確認できる

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: 本番の設定（research R9）、文書との整合、全体の確認

- [ ] T035 [P] `compose.prod.yml` の `php` サービスの `environment` に `FEEDBACK_FORM_URL: ${FEEDBACK_FORM_URL:-}` と `FEEDBACK_FORM_PREFILL_FIELD: ${FEEDBACK_FORM_PREFILL_FIELD:-}` を追加する（`:?` にしない。未設定でもデプロイできる理由をコメントで書く）
- [ ] T036 [P] `deploy/.env.production.example` に、説明のコメント付きで空の `FEEDBACK_FORM_URL=` と `FEEDBACK_FORM_PREFILL_FIELD=` を追加する（https:// の URL であること、両方そろわないとリンクを出さないこと、欄の名前の調べ方は quickstart を参照）
- [ ] T037 [P] `deploy/README.md` に、フィードバックのフォームを設定する手順を追加する：(1) 運営者が外部フォームを `specs/004-feedback-channel/contracts/web-ui.md`「外部フォーム側の設定」のとおりに用意する（種類：不具合・要望・予報の値についての気づき・その他を必須（FR-006）、本文を必須、返信用の連絡先を任意（FR-007）、表示中の情報の欄を任意、ログイン・アカウントなしで送れる、回答者のメールアドレスを収集しない）、(2) `.env.production` に 2 つを追記して `up -d` で反映する。詳細は `specs/004-feedback-channel/quickstart.md`「本番への設定」を参照する
- [ ] T038 [P] `scripts/verify-prod.sh` の「2. 画面とアセット」に、未設定の本番イメージでトップ画面の HTML に `site-footer__feedback` がないこと、`/feedback` が 404 であることの `ok` を追加する
- [ ] T039 [P] `backend/src/Presentation/Web/{Input,Feedback,ViewModel,Controller}/` の新規・変更クラスとテンプレートのコメントを見直し、「何をしているか」だけのコメントを削り、「なぜ」（Presentation に置く理由・正規形だけを受け付ける理由・`http_build_query` を使わない理由・Referer を渡さない理由など）が残っていることを確認する
- [ ] T040 `docker compose exec php composer check` を通す（cs:fix・PHPStan level max・deptrac・lint:symfony・composer:lint・PHPUnit・`node --test`）。Deptrac の違反があれば設定を緩めず依存の向きを直す
- [ ] T041 `bash scripts/verify-prod.sh` で本番用イメージを起動し、T038 の確認を含めてすべて通ることを確かめる
- [ ] T042 quickstart.md の「ブラウザでの確認」1〜9 を `backend/.env.dev.local`（git に入れない）に試用のフォームを設定して http://localhost:8000 で実施する。実際のフォームの用意と設定の確認（種類が選べる（FR-006）・連絡先を空で送れる（FR-007）・ログインを求められない・送れたことが表示される）、本番の URL での確認、iPhone でのスマホ幅の確認は、人間が行う項目として PR の説明に残す

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 依存なし
- **Foundational (Phase 2)**: Phase 1 の後。すべてのユーザーストーリーをブロックする（`FeedbackFormLink`・`FeedbackContextParser`・設定・Twig global）
- **US1 (Phase 3)**: Phase 2 の後。MVP
- **US2 (Phase 4)**: US1 の後（US1 が作る `feedback/index.html.twig`・`FeedbackPageTest.php` に追記する）。US3 とは独立
- **US3 (Phase 5)**: US1 の後（`FeedbackPageViewModelFactory`・`feedbackQuery`・案内画面の上に足す）。テンプレート上の位置が `.feedback-notes` の後なので、US2 の後に行うと編集が単純
- **Polish (Phase 6)**: 必要なストーリーがすべて終わった後。T035〜T038 は Phase 2 の後ならいつでもよい

### Within Each User Story

- テストを先に書き、失敗することを確認してから実装する
- Input / Feedback → ViewModel → Controller → Twig → CSS の順

### 同じファイルを編集するタスク（並列にしない）

- `feedback/index.html.twig`：T020 → T024 → T032
- `FeedbackPageTest.php`：T012 → T023 → T029
- `FeedbackPageViewModelFactory.php`：T015 → T030 / `FeedbackPageViewModelFactoryTest.php`：T010 → T027
- `ForecastPageViewModelFactory.php`：T016 → T031 / `ForecastPageViewModelFactoryTest.php`：T011 → T028
- `app.css`：T021 → T025 → T033

### Parallel Opportunities

- Phase 2：T002・T003（テスト 2 ファイル）、T004・T005・T007（enum・文脈・フォームの URL）
- US1：T010〜T013（テスト 4 ファイル）、T014 と T016、T021 は T017〜T020 と並列
- US3：T027〜T029（テスト 3 ファイル）、T033 は T030〜T032 と並列
- Polish：T035〜T039

---

## Parallel Example: Foundational

```text
# テストをまとめて書く（別ファイル）
Task: "T002 FeedbackFormLinkTest"
Task: "T003 FeedbackContextParserTest"

# 実装
Task: "T004 FeedbackContextType"
Task: "T005 FeedbackContext"
Task: "T007 FeedbackFormLink"
```

## Parallel Example: User Story 1

```text
# テストをまとめて書く（別ファイル）
Task: "T010 FeedbackPageViewModelFactoryTest（戻り先）"
Task: "T011 ForecastPageViewModelFactoryTest に feedbackQuery を追加"
Task: "T012 FeedbackPageTest（フッター・案内画面・戻るリンク）"
Task: "T013 FeedbackUnconfiguredTest"

# 実装：ViewModel と CSS は並列にできる
Task: "T014 FeedbackPageViewModel"
Task: "T016 ForecastPageViewModel の feedbackQuery"
Task: "T021 app.css のフッターのリンク・フォームを開くボタン"
```

## Parallel Example: User Story 3

```text
Task: "T027 FeedbackPageViewModelFactoryTest に事前入力の文章を追加"
Task: "T028 ForecastPageViewModelFactoryTest の feedbackQuery を更新"
Task: "T029 FeedbackPageTest に事前入力・不正な値・エスケープを追加"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1（基準の確認）→ Phase 2（フォームの URL・文脈の検証・設定）
2. Phase 3（US1：フッター → 案内画面 → 外部フォーム、戻るリンク、未設定時の非表示）
3. **STOP and VALIDATE**：US1 の Independent Test、`composer check`
4. この時点で、どの画面からでもフィードバックを送れる（SC-001・SC-002）。ただし US2 の案内がないまま本番でフォームを設定しない（緊急の連絡先と誤解されうるため、US2 までをそろえてから `FEEDBACK_FORM_URL` を設定する）

### Incremental Delivery

1. Phase 2 → 設定と検証の土台（画面の変化なし）
2. US1 → 導線（MVP。未設定のままなら本番の画面は変わらない）
3. US2 → 緊急時・出航判断の案内（本番でフォームを設定してよい状態）
4. US3 → 地点・最終更新・入力の文字列の事前入力
5. Polish → 本番の設定・verify-prod・ブラウザ確認

各ストーリーの完了ごとに `composer check` を通し、前のストーリーの動作を壊していないことを確認する。

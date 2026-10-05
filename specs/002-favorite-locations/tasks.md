---

description: "Task list for 002-favorite-locations"
---

# Tasks: お気に入り地点の保存と呼び出し

**Input**: Design documents from `/specs/002-favorite-locations/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-ui.md, quickstart.md

**Tests**: Constitution 原則 V の重点領域に当たるものはテストを必須とし、実装より先に書いて失敗することを確認する。この機能では、お気に入りの規則（名前の正規化と上限・重複判定・件数上限・並び順・読み込み時の破損項目の除外・保存形式）を `node --test`、`MarineForecastResult` の座標と `favoriteTarget` の変換を PHPUnit の Unit、画面の枠・`data-*` 属性・文言を Functional Test で確認する。DOM の動作（描画・イベント）は quickstart の手動確認で担保する（research R11）。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1, US2, US3）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`、名前空間は `App\` = `backend/src/`、`App\Tests\` = `backend/tests/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- PHP は `declare(strict_types=1);`。JavaScript は ES2022 の ES Module（ビルドなし・npm パッケージなし）。コメントは日本語で「なぜ」だけを書く
- お気に入りの内容をサーバーに送らない（FR-010 / SC-006）。お気に入りのためのルート・Controller・UseCase・Domain Object を作らない（research R1）
- 名前を DOM に入れるときは `textContent` / `value` / `setAttribute` だけを使い、`innerHTML` / `insertAdjacentHTML` を使わない（FR-014、research R5）
- UI 文言は Twig に置き、JavaScript は `hidden` の切り替えと `{name}` の置き換えだけをする（research R6）。「安全です」「出航できます」「問題ありません」のような断定を入れない（FR-013）
- 丸めの規則は Domain の `Coordinate` の 1 か所に保つ。JavaScript で入力値を丸め直さない（research R2。読み込み時に手で書き換えられた値を 2 桁に丸めるのは例外。data-model.md「保存形式」）
- Presentation から Domain を参照しない（Deptrac）。Deptrac の設定は変更しない
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: JavaScript のテスト環境（Node.js 20、`composer test:js`、CI）を用意する（research R11）

- [X] T001 `backend/Dockerfile` に Debian の `nodejs` パッケージ（`apt-get install -y --no-install-recommends nodejs` のあと `rm -rf /var/lib/apt/lists/*`）を追加し、テスト実行のためだけに入れる理由をコメントに書く。`backend/Dockerfile.prod` は変更しない。`docker compose build php && docker compose up -d` のあと `docker compose exec php node --version` が 20.x を返すことを確認する
- [X] T002 [P] `backend/composer.json` の `scripts` に `"test:js": "node --test tests/JavaScript/"` を追加し、`check` の `@test` の後ろに `@test:js` を足す。`scripts-descriptions` に `"test:js": "お気に入りの規則などの JavaScript をテストする"` を追加する（テストファイルは T004・T005 で作るため、`composer check` はそれまで失敗してよい）
- [X] T003 [P] `.github/workflows/ci.yml` の `php` ジョブに、`actions/setup-node`（他の actions と同じく最新のメジャーバージョン）で `node-version: '20'` をセットアップするステップと、PHPUnit の後ろに `composer test:js` を実行するステップ（名前「JavaScript tests (node --test)」）を追加する。`verify-prod` ジョブ・本番用イメージには Node.js を入れない

**Checkpoint**: 開発用コンテナで `node --version` が 20.x。CI の定義に JavaScript のテストが入っている

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 全ストーリーが使う、お気に入りの規則のうち読み込み・表示に関わる部分（`favorite-list.js`）、保存（`favorite-store.js`）、UI の初期化と「使えない環境」の扱い（`favorites-ui.js`）、端末内保存の注意書き（Twig）

**⚠️ CRITICAL**: このフェーズが終わるまで、どのユーザーストーリーにも着手しない

### Tests for Foundational ⚠️

> 先に書き、失敗することを確認してから実装する

- [X] T004 [P] `backend/tests/JavaScript/favorite-list.test.js` を作成し（`node:test` と `node:assert/strict`、`../../assets/favorites/favorite-list.js` を import）、次を確認する：
  - `normalizeName`：前後の半角・全角スペースを除く（`'　テスト沖　'` → `'テスト沖'`）、空白だけなら `''`、30 コードポイントは成功・31 コードポイントは `name_too_long`、絵文字（サロゲートペア）を 1 文字として数える（`'🐟'.repeat(30)` は成功）
  - `favoriteKey(latitude, longitude)`：`27.75, 129.05` → `'27.75,129.05'`、`28.1, 129.3` → `'28.10,129.30'`
  - `displayName(favorite)`：名前があればその名前、空なら `'27.75, 129.05'` / `'28.10, 129.30'` / `'-0.50, -120.00'`
  - `coordinateLabel(favorite)`：`'北緯 27.75° / 東経 129.05°'`、マイナスは `'南緯 0.50° / 西経 120.00°'`（`backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` の地点表示と同じ書式。0 の扱いも合わせる）
  - `forecastUrl(favorite)`：`'/forecast?lat=27.75&lon=129.05'`（名前を含まない）
  - `find(list, latitude, longitude)`：一致すれば該当の項目、なければ `null`
  - `parse(raw)`：data-model.md「保存形式」の表のすべての行（`null`、`'{'`、`version` が 2、`items` が配列でない、項目がオブジェクトでない、座標の型・範囲外、`name` が文字列でない・31 文字以上、`savedAt` が日時として読めない項目だけを捨てる、2 桁に丸まっていない座標を丸める、同じキーは `savedAt` が新しい方を残す、21 件以上は新しい 20 件）、結果が `savedAt` の降順（同時刻はキーの昇順）
  - `serialize(list)`：`{"version":1,"items":[...]}` の形で、`parse(serialize(list))` が元の一覧と等しい
- [X] T005 [P] `backend/tests/JavaScript/favorite-store.test.js` を作成し、`Map` を使う偽の `Storage`（`getItem` / `setItem` / `removeItem`）と、どのメソッドも例外を投げる偽の `Storage`、`setItem` だけが `QuotaExceededError` 相当の例外を投げる偽の `Storage` で次を確認する：`isAvailable()` が通常は `true`・例外時は `false` で、確認用のキーを残さない／`load()` がキーなしで空・壊れた JSON で空・`getItem` の例外で空／`save(list)` が `umiyomi.favorites` に `serialize` の結果を書いて `true`、`setItem` の例外で `false`

### Implementation for Foundational

- [X] T006 `backend/assets/favorites/favorite-list.js` を作成し、DOM に依存しない純粋関数として `MAX_FAVORITES = 20`、`MAX_NAME_LENGTH = 30`、`normalizeName(input)`（`{ok: true, name}` か `{ok: false, error: 'name_too_long'}` を返す。文字数は `Array.from(name).length`。research R4）、`favoriteKey`、`displayName`、`coordinateLabel`、`forecastUrl`、`find`、`parse`、`serialize` を export する。並び順は配列の順に依存させず、`savedAt` の降順・同時刻はキーの昇順で決める内部関数にまとめる。元の配列を変更しない。T004 が通ることを確認する
- [X] T007 `backend/assets/favorites/favorite-store.js` を作成し、`createFavoriteStore(storage)` が `{isAvailable, load, save}` を返すようにする（キー `umiyomi.favorites`、確認用キー `umiyomi.favorites.probe`。data-model.md「FavoriteStore」）。`Storage` は引数で受け取り、`window.localStorage` を直接参照しない（テストで偽の `Storage` を渡すため）。T005 が通ることを確認する
- [X] T008 [P] `backend/templates/favorites/_notes.html.twig` を作成し、端末内保存の注意書き「お気に入りはこの端末のこのブラウザにだけ保存され、他の端末とは共有されません。ブラウザのデータを消去すると消えます。」（FR-012）、`hidden` 付きの `data-favorites-state="unavailable"` の要素と、同じ文言の `<noscript>`「この環境ではお気に入りを保存・表示できません。緯度・経度を入力すれば予報は表示できます。」（FR-011、research R9）を出力する。トップ画面の一覧・予報画面の折りたたみ一覧の両方から include できる形にする
- [X] T009 `backend/assets/favorites/favorites-ui.js` を作成し、`initFavorites(root)` を export する。`[data-favorites="manage-list"]` / `[data-favorites="switch-list"]` / `[data-favorites="save-panel"]` のどれもなければ何もしない。`window.localStorage` の取得自体を `try` で囲み、`createFavoriteStore(...).isAvailable()` が `false` なら各枠の `data-favorites-state="unavailable"` だけを表示して、保存パネル・一覧は出さない。初期化全体を `try` / `catch` で囲み、例外時は `console.error` に出して予報の表示に影響させない（SC-004）。共通の小さな関数として、枠の中の `[data-favorites-state]` を 1 つだけ表示する関数、`[data-favorites-message]` を 1 つだけ表示する（または全部隠す）関数、`<template data-favorites-template="…">` を `cloneNode(true)` で複製する関数、`{name}` を置き換える関数を置く（contracts/web-ui.md「Twig と JavaScript の取り決め」）
- [X] T010 `backend/assets/app.js` で `./favorites/favorites-ui.js` から `initFavorites` を import し、`initFavorites(document)` を呼ぶ（ES Module は defer されるため DOMContentLoaded を待たない）。`docker compose exec php bin/console debug:asset-map` に `favorites/*.js` が出ることを確認する

**Checkpoint**: `composer test:js` と `composer check` が通る。どの画面にもまだ枠がないため、画面の見た目は変わらない。ユーザーストーリーに着手できる

---

## Phase 3: User Story 1 - 表示中の地点をお気に入りに保存し、一覧から開き直す (Priority: P1) 🎯 MVP

**Goal**: 予報画面の保存パネルで、名前（30 文字以内・省略可）を付けて表示中の地点を保存でき、トップ画面の一覧と予報画面の折りたたみ一覧から、その地点の予報画面（`/forecast?lat=..&lon=..`）を開き直せる。保存済みの地点では「お気に入りに保存済み：{名前}」と表示する。取得失敗（503）・回数制限（429）・前回予報の代替表示でも保存でき、入力エラー（422）では保存パネルを出さない

**Independent Test**: `/forecast?lat=27.75&lon=129.05` で名前「テスト沖」を付けて保存し、ブラウザを開き直したトップ画面の一覧に「テスト沖」と `北緯 27.75° / 東経 129.05°` が出て、それを選ぶと同じ予報画面が開くこと（quickstart「US1」1〜8）。自動テストでは、`add` の規則（node --test）、全ステータスで丸め済み座標が結果に入ること（Unit）、保存パネルの `data-latitude="27.75"` などの枠（Functional）を確認する

### Tests for User Story 1 ⚠️

> 先に書き、失敗することを確認してから実装する

- [X] T011 [P] [US1] `backend/tests/JavaScript/favorite-list.test.js` に `add(list, {latitude, longitude, name}, now)` のテストを追加する：先頭に追加され `savedAt` が `now.toISOString()`、名前の前後の空白を除く、空欄の名前は `''` で保存され `displayName` が座標になる、判定順が「名前（`name_too_long`）→ 重複（`duplicate`）→ 件数（`limit`）」であること（20 件ある状態で同じ地点を保存すると `duplicate`、31 文字の名前では `name_too_long`）、別の地点に同じ名前を付けられる、元の配列が変わらない
- [X] T012 [P] [US1] `backend/tests/Unit/Application/Marine/ViewMarineForecastTest.php` に、入力 `27.7549, 129.0501` で Fresh / Stale / Unavailable / RateLimited のどの結果でも `$result->latitude === 27.75`・`$result->longitude === 129.05` になるテストを追加する（既存の Fake・固定時計の組み立てを使う）
- [X] T013 [P] [US1] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` を新しい名前付きコンストラクタの引数に合わせて直し、`favoriteTarget` のテストを追加する：Fresh / Stale / Unavailable / RateLimited で `['latitude' => '27.75', 'longitude' => '129.05']`、`28.1, 129.3` で `'28.10'` / `'129.30'`、`-0.5, -120.0` で `'-0.50'` / `'-120.00'`、`createForInvalidInput()` で `null`
- [X] T014 [P] [US1] `backend/tests/Functional/FavoritesMarkupTest.php` を作成し（`ForecastPageTest` の `setUp()`・Fake Provider・キャッシュプールの clear に倣う）、次を確認する：
  - `GET /`：入力フォームの後ろに `[data-favorites="manage-list"]` があり、見出し「お気に入り」、注意書き（FR-012）、`hidden` の `empty` 状態の文言「お気に入りはまだありません。予報画面で表示中の地点を保存できます。」、`<noscript>` の文言、`<template data-favorites-template="manage-item">` が出る
  - `GET /forecast?lat=27.7549&lon=129.0501`（200）：入力フォームの後ろ・予報の一覧より前に `open` なしの `<details>` があり、`<summary>` に `[data-favorites="count"]`、中に `[data-favorites="switch-list"]` と `<template data-favorites-template="switch-item">`。地点・最終更新の後ろに `[data-favorites="save-panel"][data-latitude="27.75"][data-longitude="129.05"]` があり、名前の入力欄のラベル「名前（省略可・30 文字以内）」、プレースホルダ「例：27.75, 129.05」、ボタン「お気に入りに保存」、`hidden` の `saved` / `unsaved` 状態と `saved` / `name_too_long` / `limit` / `write_failed` のメッセージ、`role="status"` の領域が出る。名前の入力欄に `maxlength` がない（research R4）
  - Fake Provider を失敗させた 503 と、回数制限の 429 でも保存パネルが出る
  - `GET /forecast?lat=95&lon=129.05`（422）では保存パネルが出ず、折りたたみ一覧は出る
  - トップ画面と予報画面（200 / 503 / 429 / 422）の HTML 全体に「安全です」「出航できます」「問題ありません」が含まれない（FR-013）

### Implementation for User Story 1

- [X] T015 [US1] `backend/assets/favorites/favorite-list.js` に `add(list, {latitude, longitude, name}, now)` を追加する。成功時は `{ok: true, list}`、失敗時は `{ok: false, error}`（`name_too_long` / `duplicate` / `limit`）を返す（data-model.md「FavoriteList」）。T011 が通ることを確認する
- [X] T016 [US1] `backend/src/Application/Marine/DTO/MarineForecastResult.php` に `public float $latitude` と `public float $longitude` を追加し、`fresh` / `stale` は予報と座標、`unavailable` / `rateLimited` は座標だけを受け取る形にする（data-model.md §2。予報がない結果でも保存パネルに座標を渡すため、とコメントに書く）
- [X] T017 [US1] `backend/src/Application/Marine/UseCase/ViewMarineForecast.php` で、生成した `Coordinate` の `latitude()` / `longitude()`（丸め済み）をすべての `MarineForecastResult` の生成に渡す。Domain は変更しない。T012 が通ることを確認する（T016 に依存）
- [X] T018 [US1] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModel.php` に `favoriteTarget`（`array{latitude: string, longitude: string}|null`）を追加し、`backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` の `create()` で `sprintf('%.2f', ...)` により作る。`createForInvalidInput()` では `null`。T013 が通ることを確認する（T016 に依存）
- [X] T019 [P] [US1] `backend/templates/favorites/_manage_list.html.twig` を作成する：`<section>` に見出し「お気に入り」、`_notes.html.twig` の include、`hidden` の `data-favorites-state="empty"`（US1-6 の文言）と `data-favorites-state="ready"`、その中の `<ul data-favorites="manage-list">`、`hidden` の `data-favorites-message="write_failed"`、`<template data-favorites-template="manage-item">`（`<li>` の中に予報画面へのリンク。リンクの中に表示名の要素と座標の要素を `data-favorites-field="name"` / `data-favorites-field="coordinate"` で置く）。予報の数値は出さない（FR-013）
- [X] T020 [P] [US1] `backend/templates/favorites/_switch_list.html.twig` を作成する：`<details class="favorites-switch">`（`open` なし）、`<summary>` は「お気に入り（<span data-favorites="count">0</span> 件）」をサーバー側で出力（research R8）、中に `_notes.html.twig` の include、`hidden` の `empty` / `ready` 状態、`<ul data-favorites="switch-list">`、`<template data-favorites-template="switch-item">`（予報画面へのリンクと、`hidden` の「表示中」の印 `data-favorites-field="current"`）。名前変更・削除のボタンは置かない（FR-016）
- [X] T021 [P] [US1] `backend/templates/favorites/_save_panel.html.twig` を作成する（引数 `target`）：`data-favorites="save-panel" data-latitude="{{ target.latitude }}" data-longitude="{{ target.longitude }}"`、`hidden` の `data-favorites-state="unsaved"`（`<form>`、ラベル「名前（省略可・30 文字以内）」の入力欄、プレースホルダ「例：{{ target.latitude }}, {{ target.longitude }}」、`maxlength` なし、ボタン「お気に入りに保存」、入力欄の下の `hidden` の `data-favorites-message="name_too_long"`「名前は 30 文字以内で入力してください」を `aria-describedby` で結ぶ）、`hidden` の `data-favorites-state="saved"`（「お気に入りに保存済み：」と `data-favorites-field="name"`）、`role="status"` の領域に `saved`「お気に入りに保存しました」、`limit`、`write_failed` のメッセージ（文言は contracts/web-ui.md「状態ごとのメッセージ」のとおり）、`hidden` の `unavailable` 状態（T008 と同じ文言）
- [X] T022 [US1] `backend/templates/home/index.html.twig` で、入力フォームの include のすぐ下に `favorites/_manage_list.html.twig` を include する（T019 に依存）
- [X] T023 [US1] `backend/templates/forecast/index.html.twig` で、入力フォームのすぐ下（警告より上）に `favorites/_switch_list.html.twig` を、地点・最終更新の後ろ（注意書きの上）に `{% if page.favoriteTarget %}` で `favorites/_save_panel.html.twig` を include する（contracts/web-ui.md「予報画面の構成」。T018・T020・T021 に依存）。T014 が通ることを確認する
- [X] T024 [US1] `backend/assets/favorites/favorites-ui.js` に US1 の描画と保存を実装する：
  - 一覧の描画：`load()` の結果から `manage-item` / `switch-item` を複製し、リンクの `href` に `forecastUrl`、`data-favorites-field="name"` に `displayName`、`coordinate` に `coordinateLabel` を `textContent` で入れる。0 件なら `empty`、1 件以上なら `ready` を表示し、`[data-favorites="count"]` に件数を入れる
  - 予報画面の折りたたみ一覧：保存パネルの `data-latitude` / `data-longitude` と同じ地点の項目のリンクに `aria-current="page"` を付け、「表示中」を表示する（422 で保存パネルがないときは付けない）
  - 保存パネル：`find` で保存済みなら `saved`（名前は `displayName`）、未保存なら `unsaved` を表示する。送信時は `preventDefault` し、直前に `load()` で読み直してから `add`（research R10）。`name_too_long` は入力欄に `aria-invalid="true"` を付けてメッセージを表示、`limit` はメッセージを表示、`duplicate`（別タブで保存済み）は保存せず `saved` の表示に切り替える。`save()` が `false` なら `write_failed`。成功したら `saved` の表示と「お気に入りに保存しました」を出し、折りたたみ一覧と件数をその場で描き直す
  - `data-latitude` / `data-longitude` は `Number()` で数値にするだけで、丸め直さない（research R2）
- [X] T025 [P] [US1] `backend/assets/styles/app.css` に、トップ画面の一覧・予報画面の折りたたみ一覧（`<summary>` の 1 行表示）・保存パネルのスタイルを追加する。名前と座標は `overflow-wrap: anywhere` で折り返し、入力欄とボタンは幅 360px で折り返して横にはみ出さないようにする（FR-015）。「表示中」の項目は色だけに頼らず区別できるようにする
- [X] T026 [US1] `docker compose exec php composer check` を通し、quickstart「US1」1〜8 と「エッジケース」のうち名前の上限・前後の空白・名前の表示（`<b>x</b>"&'`）・名前の重複・件数の上限・壊れたデータ・JSON 全体が壊れている・取得失敗でも保存・入力エラー・localStorage が使えない・JavaScript 無効・複数タブ・サーバーへの送信なし をブラウザで確認する

**Checkpoint**: US1 だけで、保存・トップ画面と予報画面からの呼び出し・保存済みの表示・使えない環境での案内が動く（MVP の完成条件「お気に入り地点保存」を満たす）

---

## Phase 4: User Story 2 - 不要になったお気に入りを削除する (Priority: P2)

**Goal**: トップ画面の一覧の「削除」と、予報画面の保存パネルの「お気に入りから外す」で、確認（`window.confirm`）のあとお気に入りを削除できる。削除は再読み込み後も戻らない

**Independent Test**: お気に入りを 2 件保存した状態でトップ画面から 1 件を削除し（確認でキャンセルすると残る）、再読み込みしても 1 件だけであること、予報画面で「お気に入りから外す」と未保存の表示に戻ること（quickstart「US2・US3」2・3）

### Tests for User Story 2 ⚠️

> 先に書き、失敗することを確認してから実装する

- [X] T027 [P] [US2] `backend/tests/JavaScript/favorite-list.test.js` に `remove(list, key)` のテストを追加する：その地点だけが除かれ、残りの並び順が変わらない、存在しないキーは `not_found`、元の配列が変わらない
- [X] T028 [P] [US2] `backend/tests/Functional/FavoritesMarkupTest.php` に追加する：トップ画面の `manage-item` テンプレートに「削除」ボタン（`data-favorites-action="remove"`）があり、一覧の枠に `data-confirm-remove="『{name}』をお気に入りから削除しますか？"` がある。予報画面の保存パネルの `saved` 状態に「お気に入りから外す」ボタンと同じ `data-confirm-remove`、`hidden` の `removed` メッセージ「お気に入りから削除しました」がある。予報画面の `switch-item` テンプレートには削除ボタンがない（FR-016）

### Implementation for User Story 2

- [X] T029 [US2] `backend/assets/favorites/favorite-list.js` に `remove(list, key)` を追加する（`{ok: true, list}` / `{ok: false, error: 'not_found'}`）。T027 が通ることを確認する
- [X] T030 [P] [US2] `backend/templates/favorites/_manage_list.html.twig` の `manage-item` テンプレートに「削除」ボタン（`type="button"`、`data-favorites-action="remove"`）を、一覧の枠に `data-confirm-remove` を追加する
- [X] T031 [P] [US2] `backend/templates/favorites/_save_panel.html.twig` の `saved` 状態に「お気に入りから外す」ボタン（`type="button"`、`data-favorites-action="remove"`）を、パネルに `data-confirm-remove` を、`role="status"` の領域に `removed` メッセージを追加する
- [X] T032 [US2] `backend/assets/favorites/favorites-ui.js` に削除を実装する：トップ画面の一覧はイベント委譲で「削除」を受け、`data-confirm-remove` の `{name}` を `displayName` で置き換えて `window.confirm`（research R7）。OK なら `load()` で読み直して `remove` → `save()`。失敗時は `write_failed` を表示し、成功時は一覧を描き直す（0 件なら `empty`）。予報画面の「お気に入りから外す」も同じ流れで、成功したら `unsaved` の表示と「お気に入りから削除しました」を出し、折りたたみ一覧と件数を描き直す。`not_found`（別タブで削除済み）は削除済みとして表示を更新する
- [X] T033 [US2] `docker compose exec php composer check` を通し、quickstart「US2・US3」2・3 をブラウザで確認する

**Checkpoint**: US1 と US2 が両方動く。削除は確認つきで、再読み込み後も戻らない

---

## Phase 5: User Story 3 - お気に入りの名前を変更する (Priority: P3)

**Goal**: トップ画面の一覧で「名前を変更」を押すと、その項目がその場で入力欄と「保存」「キャンセル」に切り替わり、名前だけを変更できる（緯度・経度・並び順・`savedAt` は変わらない）。空欄にすると座標が名前の代わりになる

**Independent Test**: 名前が空の「27.75, 129.05」を「テスト沖」に変更し、一覧の位置が変わらないこと、再読み込み後も「テスト沖」であること、空欄にすると「27.75, 129.05」に戻ること（quickstart「US2・US3」1）

### Tests for User Story 3 ⚠️

> 先に書き、失敗することを確認してから実装する

- [X] T034 [P] [US3] `backend/tests/JavaScript/favorite-list.test.js` に `rename(list, key, name)` のテストを追加する：名前だけが変わり、座標・`savedAt`・並び順は変わらない、前後の空白を除く、空欄にすると `''` になり `displayName` が座標になる、31 文字は `name_too_long`、存在しないキーは `not_found`、元の配列が変わらない
- [X] T035 [P] [US3] `backend/tests/Functional/FavoritesMarkupTest.php` に追加する：トップ画面の `manage-item` テンプレートに「名前を変更」ボタンと、`hidden` の名前変更フォーム（ラベル付きの入力欄で `maxlength` なし、「保存」「キャンセル」、`name_too_long` のメッセージ）がある。予報画面の `switch-item` テンプレートに名前変更のボタンがない（FR-016）

### Implementation for User Story 3

- [X] T036 [US3] `backend/assets/favorites/favorite-list.js` に `rename(list, key, name)` を追加する（`{ok: true, list}` / `{ok: false, error: 'name_too_long' | 'not_found'}`）。T034 が通ることを確認する
- [X] T037 [US3] `backend/templates/favorites/_manage_list.html.twig` の `manage-item` テンプレートに「名前を変更」ボタン（`data-favorites-action="rename"`）と、`hidden` の名前変更フォーム（ラベル「名前（省略可・30 文字以内）」の入力欄、「保存」ボタン、「キャンセル」ボタン（`data-favorites-action="cancel-rename"`）、`aria-describedby` で結んだ `name_too_long` のメッセージ）を追加する。`aria-describedby` の id は JavaScript が項目ごとに一意にする
- [X] T038 [US3] `backend/assets/favorites/favorites-ui.js` に名前変更を実装する：「名前を変更」で、その項目のリンクとボタンを隠して名前変更フォームを表示し、入力欄に現在の名前（空なら空欄）を入れ、プレースホルダに座標（`displayName` の空欄時の形）を設定してフォーカスする。送信時は `load()` で読み直してから `rename` → `save()`。`name_too_long` は `aria-invalid` とメッセージ、`write_failed` は一覧のメッセージを表示し、成功したら一覧を描き直す（並び順は変えない）。「キャンセル」で元の表示に戻し、「名前を変更」ボタンにフォーカスを戻す。`not_found`（別タブで削除済み）は一覧を描き直す
- [X] T039 [P] [US3] `backend/assets/styles/app.css` に名前変更フォームのスタイルを追加し、幅 360px で入力欄・「保存」「キャンセル」が折り返して横にはみ出さないようにする
- [X] T040 [US3] `docker compose exec php composer check` を通し、quickstart「US2・US3」1 をブラウザで確認する

**Checkpoint**: すべてのユーザーストーリーが単独で動く

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: 複数のストーリーにまたがる確認と仕上げ

- [X] T041 [P] `backend/tests/JavaScript/no-html-injection.test.js` を作成し、`backend/assets/favorites/*.js` のソースに `innerHTML` / `outerHTML` / `insertAdjacentHTML` / `document.write` が含まれないことを確認する（FR-014、research R5 を機械的に守るため）
- [X] T042 [P] `CLAUDE.md` の「コマンド」に `docker compose exec php composer test:js   # JavaScript のテスト（node --test）` を追加し、`composer check` の対象に含まれることが分かるようにする。テストの置き場所に `backend/tests/JavaScript` を追加する
- [X] T043 quickstart「スマホ幅」をブラウザで確認する：幅 360px、お気に入り 20 件・30 文字の名前を含む状態で、トップ画面の一覧（名前変更フォームを開いた状態を含む）、予報画面の折りたたみ一覧・保存パネルでページ全体が横にはみ出さない（FR-015 / SC-005）。閉じた折りたたみ一覧が予報の一覧を押し下げないこと（FR-005）も確認する
- [X] T044 `bash scripts/verify-prod.sh` を実行し、本番用イメージで AssetMapper が `assets/favorites/*.js` をコンパイルして配信し、importmap の相対 import が解決されることを確認する。本番用イメージに Node.js が入っていないことを確認する
- [X] T045 `docker compose exec php composer check` を最後にもう一度通し、計画から変えた点があれば、このファイルの末尾に「実装時に計画から変えた点」として記録する

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**：依存なし。すぐに始められる
- **Foundational (Phase 2)**：Setup の完了が前提（`node --test` を動かすため）。すべてのストーリーをブロックする
- **US1 (Phase 3)**：Foundational の完了が前提
- **US2 (Phase 4)**：US1 の一覧・保存パネルのテンプレートと `favorites-ui.js` の描画を拡張するため、US1 の完了が前提
- **US3 (Phase 5)**：US1 の完了が前提。US2 とは独立だが、`_manage_list.html.twig`・`favorites-ui.js`・`favorite-list.js` と各テストファイルを両方が触るので、US2 → US3 の順に進めるほうが衝突しない
- **Polish (Phase 6)**：すべてのストーリーの完了が前提

### User Story Dependencies

```text
Setup → Foundational → US1 (MVP) ─┬→ US2 ─┐
                                  └→ US3 ─┴→ Polish
```

### Within Each User Story

- テストを先に書いて失敗させる → `favorite-list.js` の規則 → PHP の DTO / UseCase / ViewModel（US1 のみ）→ Twig の枠 → `favorites-ui.js` → CSS
- 各フェーズの最後に `composer check` と quickstart の該当手順

### Parallel Opportunities

- Setup：T002、T003 は並列（T001 とも別ファイルだが、動作確認は T001 のイメージを使う）
- Foundational：テスト T004、T005 は並列。T008 は T006・T007 と並列。T009 は T007 に、T010 は T009 に依存
- US1：テスト T011〜T014 はすべて並列。実装 T015・T016・T019・T020・T021・T025 は並列。T016 → T017、T016 → T018 → T023、T019 → T022、T015 → T024 は順番
- US2：テスト T027、T028 は並列。T030、T031 は並列。T029 → T032
- US3：テスト T034、T035 は並列。T036 → T038、T037 → T038。T039 は並列
- Polish：T041、T042 は並列

---

## Parallel Example: User Story 1

```bash
# US1 のテストをまとめて書く（すべて別ファイル）
Task: "T011 favorite-list.test.js に add のテスト"
Task: "T012 ViewMarineForecastTest に丸め済み座標のテスト"
Task: "T013 ForecastPageViewModelFactoryTest に favoriteTarget のテスト"
Task: "T014 FavoritesMarkupTest（トップ・予報 200/503/429/422 の枠）"

# 互いに依存しない実装をまとめて進める
Task: "T015 favorite-list.js の add"
Task: "T016 MarineForecastResult に座標を追加"
Task: "T019 _manage_list.html.twig"
Task: "T020 _switch_list.html.twig"
Task: "T021 _save_panel.html.twig"
Task: "T025 app.css（一覧・折りたたみ・保存パネル）"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1: Setup（Node.js 20、`composer test:js`、CI）
2. Phase 2: Foundational（規則の読み込み・表示部分、保存、UI の初期化、注意書き）
3. Phase 3: US1
4. **STOP and VALIDATE**：`composer check` と quickstart「US1」・エッジケースで、保存・呼び出し・使えない環境での案内を確認する
5. US1 だけで MVP の完成条件「お気に入り地点保存」を満たす。ただし削除がないと 20 件の上限に達したときに先へ進めないため、利用者に公開するのは US2 まで終えてからにする（spec US2「Why this priority」）

### Incremental Delivery

1. Setup + Foundational → 規則と保存の土台（画面は変わらない）
2. US1 → 保存と呼び出し（内部で確認）
3. US2 → 削除（ここで公開できる状態）
4. US3 → 名前変更
5. Polish → `innerHTML` 不使用の機械的な確認、CLAUDE.md、スマホ幅・本番相当の確認

---

## Notes

- [P] は別ファイルで、未完了のタスクに依存しないもの
- 実装前の人間のレビュー：plan.md「レビューで判断してほしい点」1〜6（JavaScript のテスト環境、画面構成、`MarineForecastResult` への座標追加、localStorage の保存形式、`window.confirm`、名前の文字数の数え方）は 2026-10-05 に承認済み
- タスクごと、または論理的なまとまりごとにコミットする
- 各 Checkpoint でそのストーリーを単独で確認する

## 実装時に計画から変えた点

- `MarineForecastResult::fresh()` / `stale()` は、座標を引数で受け取らず `MarineForecastView` の緯度・経度から取る（予報と座標が食い違う状態を作れないようにするため）。`unavailable()` / `rateLimited()` だけが座標を受け取る（T016）
- `[data-favorites="manage-list"]` は `<ul>` ではなく、見出し・注意書き・状態・一覧を含む `<section>`（枠）に付けた。一覧の `<ul>` は `data-favorites-items`。contracts/web-ui.md の「枠」の定義に合わせた（T019・T020）
- 名前変更フォームの入力欄は、`<label>` で入力欄を包む形にした（`for` の id が不要になるため）。`aria-describedby` の id だけを JavaScript が項目ごとに付け替える（T037）
- 保存パネルの「保存済み」に「お気に入りから外す」ボタンを置くため、`saved` の状態要素を `<p>` から `<div>` にした（T031）
- CSS に `[hidden] { display: none !important; }` を足した。`display` を指定した要素（`.favorites-link` など）でも、JavaScript の `hidden` の切り替えが効くようにするため
- 戻る操作（bfcache からの復元）で他の画面の保存・削除が反映されるよう、`pageshow`（`persisted`）で一覧を描き直す処理を足した
- 保存パネルのためのブラウザ確認は、手動ではなく headless Chrome の使い捨てハーネス（同一オリジンの iframe を操作。リポジトリには含めない）で行った。確認したのは保存・重複・上限・壊れたデータ・422・削除・名前変更・localStorage が使えない・書き込み失敗・360px での横はみ出し

## 未確認

なし。T043 は 2026-10-05 に利用者が実機のブラウザ（幅 360px）で確認した。

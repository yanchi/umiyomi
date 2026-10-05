---

description: "Task list for 005-favicon-og-meta"
---

# Tasks: ファビコンと OG 情報

**Input**: Design documents from `/specs/005-favicon-og-meta/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-ui.md, quickstart.md

**Tests**: 全画面の `<head>` の検査（FR-009 の入力の混入なし＝座標のフォーマットに関わる、主要な HTTP 経路）が Constitution 原則 V に当たるため、Functional Test を必須とし、実装より先に書いて失敗することを確認する。
本番イメージでの配信は `scripts/verify-prod.sh`、見た目・共有プレビューは quickstart の手動確認で担保する。PHP のクラスを増やさないので Unit Test は追加しない。JavaScript の変更は `scripts/build-icons.mjs`（開発時のみ）だけで、`tests/JavaScript` は変えない。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1〜US3）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- 変更は `backend/templates/`・`backend/assets/images/`・`backend/public/favicon.ico`・`backend/config/packages/twig.yaml`・テスト・スクリプト・ドキュメントだけ。Domain・Application・Infrastructure・Deptrac・Dockerfile・CI の設定、nginx の設定（`deploy/nginx/umiyomi.conf`）は変更しない。PHP のクラスは増やさない（research R1）
- 新しい Composer / npm パッケージ・PHP 拡張・manifest・Service Worker・動的な画像・`robots.txt`・`og:url`・`theme-color` を作らない（plan、research R2・R5・R6）
- Twig では値を自動エスケープで出し、`|raw` を使わない（contracts/web-ui.md）
- 文言は contracts/web-ui.md・data-model.md の文字列を一字一句そのまま使う。「安全です」「出航できます」「問題ありません」のような断定を入れない（FR-008、原則 IV）
- 予報の数値・最終更新日時・利用者の入力文字列を `<head>` に出さない（FR-007、FR-009、FR-010）
- コメントは日本語で「なぜ」だけを書く
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 新しいパッケージ・拡張は追加しない（plan.md）。変更前の基準を確認するだけ

- [ ] T001 `docker compose up -d` のあと `docker compose exec php composer check` を実行し、変更前に全部通ることを確かめる（失敗するなら先に原因を報告して止まる）

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 全ストーリーが使う `base.html.twig` のブロックの枠と、`site_origin`、エラー画面。ここが終わるまでストーリーの作業に入らない

- [ ] T002 `backend/config/packages/twig.yaml` の `twig.globals` に `site_origin: '%env(DEFAULT_URI)%'` を追加する。`DEFAULT_URI` を使う理由（Host ヘッダーに依存せず他人のドメインを OG 画像に埋め込ませない。research R6）を日本語コメントで 1 行書く
- [ ] T003 `backend/templates/base.html.twig` の `<head>` に、ブロック `meta_description`（既定は共通の説明文：「緯度・経度を入力すると、風・波・うねりの時間別予報を確認できます。出航の判断には、気象庁などの警報・注意報も確認してください。」）と `robots`（既定は `<meta name="robots" content="noindex">`。新しい画面が意図せず公開されないよう既定を「登録しない」にする理由をコメントに書く）を用意し、`<meta name="description" content="{{ block('meta_description') }}">` と `robots` ブロックを `<title>` の直後に出す。`head_meta` ブロックは残す（research R1・R5、contracts/web-ui.md）
- [ ] T004 `backend/templates/feedback/index.html.twig` の `head_meta` ブロックから `<meta name="robots" content="noindex">` を削除し `<meta name="referrer" content="no-referrer">` だけ残す（`robots` が base の既定で出るので二重にならないようにする。コメントも実態に合わせて直す）
- [ ] T005 `backend/templates/bundles/TwigBundle/Exception/error.html.twig` を新規作成する。`base.html.twig` を継承し、`title` ブロックは「エラー | UMIYOMI」、本文は `status_code` に応じた「ページが見つかりません」（404）／「エラーが発生しました」（その他）とトップへのリンクだけ。例外メッセージ・入力・スタックトレースは出さない（FR-010、research R7）。500 でもフッターの `feedback_form` の評価で描画が落ちないことは T007 のテストで確かめる。落ちる場合だけ、`error.html.twig` で `{% block footer_feedback %}{% endblock %}` を空にして上書きする（`feedback/index.html.twig` と同じ手。Twig のグローバルは設定で常に定義されるので `is defined` のガードは効かない）。dev の例外画面（`error_page`・`exception_full`）は変更しない
- [ ] T006 `docker compose exec php composer check` を通す（テンプレートの lint と既存の Functional Test が壊れていないこと。既存テストが `<title>` や `robots` の数を検査していて落ちたら、仕様どおりの形にテストを直す）

**Checkpoint**: ブロックの枠・`site_origin`・エラー画面が揃い、既存の画面が壊れていない

---

## Phase 3: User Story 1 - タブ・ブックマークで UMIYOMI だと分かる (Priority: P1) 🎯 MVP

**Goal**: すべての画面（トップ・予報・フィードバック案内・エラー）で、UMIYOMI 専用のアイコンがタブ・ブックマーク・ホーム画面に出る

**Independent Test**: どの画面を開いてもタブに専用アイコンが出る。ライト・ダークの両方で判別できる。ホーム画面追加で切り取られない（SC-001、SC-003）

### Tests for User Story 1

> **先にテストを書き、失敗することを確認してから実装する**

- [ ] T007 [US1] `backend/tests/Functional/HeadMetaTest.php` を新規作成し、アイコンの検査を書く。`FeedbackPageTest` と同じ構成（`WebTestCase`、`setUp` で `cache.marine_forecast`・`cache.rate_limiter` を clear、Fake Provider を使う）で、トップ・予報（200・422・429・503）・フィードバック案内・404・500 の各画面（`#[DataProvider]` で列挙）について、`<link rel="icon" href="/favicon.ico" sizes="32x32">`・`rel="icon" type="image/svg+xml"`・`rel="icon" type="image/png" sizes="192x192"`・`rel="apple-touch-icon"` の 4 つがあることを確認する（SC-001、SC-006）。429・503 の再現方法は `ForecastPageTest` に倣う。404 は存在しない URL で、500 は想定外の例外を投げる Fake Provider で再現する。WebTestCase は既定で例外を再スローするので、500 のテストでは `$client->catchExceptions(true)` を使い、テストクラスの冒頭に、エラー画面が `error.html.twig` で描画される条件（test 環境の `APP_DEBUG` と `error_controller` の扱い。dev の例外画面ではなく本番相当の画面になること）を確かめた結果を日本語コメントで書く。描画されない場合は `_error/500` ルート（`framework.router` の `_error` 設定を test でだけ有効にする）など、設定を足さずに済む手段を選び、理由を書く
- [ ] T008 [US1] `backend/tests/Functional/HeadMetaTest.php` に、アイコンの URL の実在を確認するテストを追加する。`<link>` の `asset()` の URL が 200 で `image/svg+xml`・`image/png` を返すことと、その間 Fake Provider が 0 回しか呼ばれないこと（FR-012）を確認する。`/favicon.ico` は `public/` の静的ファイルで WebTestCase では返らないので、ファイルの存在と ICO のヘッダー（16px・32px の 2 エントリ）の検査にとどめ、HTTP の確認は T025 の `verify-prod.sh` に任せる（理由をコメントに書く）。あわせて、PNG の寸法（`icon-192.png` は 192×192、`apple-touch-icon.png` は 180×180 かつ不透明）を `getimagesize` などで確かめる（FR-002）。このテストは T012 のあとに通る

### Implementation for User Story 1

- [ ] T009 [P] [US1] `backend/assets/images/icon.svg` を手書きで作る。濃い青の角丸の地に白い波 2 本（plan の判断点 1）。`@media (prefers-color-scheme: dark)` を内包し、ダークでも地を塗ったまま背景に埋もれないようにする。16px で輪郭が判別できる太さにする。既存の他社ロゴと紛らわしい形を避ける（spec Assumptions）
- [ ] T010 [P] [US1] `e2e/icons/apple-touch-icon.svg`（ビルド用の元データ。`backend/assets/` の外に置くので AssetMapper で配信されない。`scripts/` 直下ではなく `e2e/` 配下にするのは、Playwright が `/e2e/node_modules` にあり、スクリプトと同じ場所に置かないと解決できないため）を手書きで作る。180×180・角丸にせず全面を塗り、マークは中央 70% に収める（research R2。OS が角を丸める）。OG 画像の元 SVG は T016 で作る
- [ ] T011 [US1] `e2e/scripts/build-icons.mjs` を新規作成する。e2e イメージの Playwright Chromium（`@playwright/test` から import）で SVG を PNG にラスタライズし、`/out/images/icon-192.png`（192×192、`/out/images/icon.svg` から）・`/out/images/apple-touch-icon.png`（180×180・不透明、`/e2e/icons/apple-touch-icon.svg` から）を書き出す。さらに `icon.svg` から 16px・32px の PNG を作り、PNG を埋め込んだ ICO（ICONDIR + ICONDIRENTRY のヘッダーを手で書く数十行）として `/out/public/favicon.ico` に書き出す。`/out/images` と `/out/public` は T012 でマウントする `backend/assets/images` と `backend/public`。追加の npm パッケージは使わない。開発時に手で実行するもので `composer check`・CI・本番イメージには入れない（research R8）
- [ ] T012 [US1] `compose.yaml` の `e2e` サービスの `volumes` に `./e2e/scripts:/e2e/scripts`・`./e2e/icons:/e2e/icons`・`./backend/assets/images:/out/images`・`./backend/public:/out/public` を追記する（`e2e` プロファイルだけの変更で、通常の起動・`app-e2e` には影響しない。既存の `e2e/tests` のマウントと同じ書き方）。そのうえで `docker compose --profile e2e run --rm --build e2e node scripts/build-icons.mjs` を実行して `icon-192.png`・`apple-touch-icon.png`・`favicon.ico` を生成する。書き出した画像を Read で開いて、切れていない・潰れていないことを目視で確認する。生成物は**コミット対象**。Linux ではマウント先の生成物がコンテナの root 所有になりうるので、所有者を確認し、必要なら `chown` する
- [ ] T013 [US1] `backend/templates/base.html.twig` の `<head>` に、contracts/web-ui.md のとおりアイコンの `<link>` 4 つを追加する（`/favicon.ico` は固定パス、他の 3 つは `asset('images/icon.svg')` など。`apple-touch-icon` は 180×180 の PNG）。`manifest` と `theme-color` は出さない。表示を遅くしないため（FR-013）、`<link rel="stylesheet">`・`<script>` を増やさない。T007・T008 のテストが通ることを確認する
- [ ] T014 [US1] `docker compose exec php composer check` を通す

**Checkpoint**: 全画面でタブのアイコンが出て、US1 を単独で確認できる（MVP）

---

## Phase 4: User Story 2 - URL を貼ったときにサービスの内容が伝わる (Priority: P1)

**Goal**: URL をチャットアプリ・SNS に貼ると、サービス名・短い説明・画像がプレビューに出る

**Independent Test**: トップ・予報の URL の `<head>` に OG・Twitter Card が揃い、`og:image` が完全なアドレスで、断定表現と入力文字列が入らない（SC-002、SC-004、SC-006）

### Tests for User Story 2

> **先にテストを書き、失敗することを確認してから実装する**

- [ ] T015 [US2] `backend/tests/Functional/HeadMetaTest.php` に OG の検査を追加する。T007 と同じ画面の一覧について、`og:site_name`（`UMIYOMI`）・`og:type`（`website`）・`og:locale`（`ja_JP`）・`og:title`（`<title>` と同じ値）・`og:description`（`description` と同じ値）・`og:image`・`og:image:width`（`1200`）・`og:image:height`（`630`）・`og:image:alt`（「UMIYOMI のロゴ。風・波・うねりの予報を出航前に確認するサービス」）・`twitter:card`（`summary_large_image`）があること、`og:image` が `http://` か `https://` で始まる完全なアドレスで、`{site_origin}/assets/images/og-image-` で始まり（`site_origin` と `asset()` の連結で `//` や欠落が出ないこと。テストでは `http://localhost/assets/images/og-image-`）、`.png` で終わること（FR-006）、`og:url`・`theme-color`・`manifest` がないことを確認する。さらに、全画面の `<title>`・description・`og:*`・`og:image:alt` に「安全です」「出航できます」「問題ありません」が含まれないこと（SC-004）と、`/forecast?lat=<script>alert(1)</script>&lon=…`・100 文字を超える入力・引用符（`"` `'`）を含む入力を渡しても、入力の文字列が `<head>` に出ないこと（FR-009、FR-010）を `#[DataProvider]` で確認する

### Implementation for User Story 2

- [ ] T016 [P] [US2] `e2e/icons/og-image.svg` を手書きで作る。1200×630、マーク・「UMIYOMI」・キャッチコピー「風・波・うねりの予報を、出航前に。」だけを含み、端から 10% の余白を取る（LINE・X・Slack の切り取りに耐える）。地点・数値・日時・断定表現を入れない（research R3）。日本語の文字は Chromium が OS のフォントで描く
- [ ] T017 [US2] `e2e/scripts/build-icons.mjs` に、`/e2e/icons/og-image.svg` を 1200×630 の PNG にして `/out/images/og-image.png` に書き出す処理を足し、T012 と同じコマンドで実行して生成する。ファイルサイズが 300KB 未満であることを確認し（超えるなら色数を減らすなど SVG 側を単純にする）、画像を Read で開いて文字が欠けていない・余白が取れていることを目視で確認する。生成物は**コミット対象**。`HeadMetaTest.php` に、`og-image.png` が 1200×630 で 300KB 未満であることの検査も足す（FR-005）
- [ ] T018 [US2] `backend/templates/base.html.twig` の `<head>` に、contracts/web-ui.md のとおり OG・Twitter Card の `<meta>` を追加する。`og:title` は `block('title')`、`og:description` は `block('meta_description')` を使い、ブロックを 2 度書かない。`og:image` は `site_origin|trim('/', 'right') ~ asset('images/og-image.png')`。`og:image:alt` は固定の文言。`og:url` は付けない。T015 のテストが通ることを確認する
- [ ] T019 [US2] `docker compose exec php composer check` を通す

**Checkpoint**: 全画面で共有プレビュー用の情報が揃い、US1・US2 がどちらも単独で確認できる

---

## Phase 5: User Story 3 - 画面ごとに適切なタイトル・説明が付く (Priority: P2)

**Goal**: 画面ごとの内容に合ったタイトル・説明が付き、トップだけ検索エンジンに登録される設定になる

**Independent Test**: 各画面の title・description・robots が data-model.md の表どおりで、予報のタイトルに地点の座標が入り、入力不正のときは「予報 | UMIYOMI」になる

### Tests for User Story 3

> **先にテストを書き、失敗することを確認してから実装する**

- [ ] T020 [US3] `backend/tests/Functional/HeadMetaTest.php` にページ別の検査を追加する。トップ：`<title>` が「UMIYOMI｜風・波・うねりの予報を出航前に確認」、`meta name="robots"` がない（FR-011）。予報（200）：`<title>` が `北緯 … / 東経 … | UMIYOMI` の形で、description は共通の説明文、`robots` が `noindex`。予報（422）：`<title>` が「予報 | UMIYOMI」。予報（429・503）：`robots` が `noindex` で、タイトルは地点が決まっていれば `北緯 … / 東経 … | UMIYOMI`、なければ「予報 | UMIYOMI」（data-model.md のとおり。どちらになるかは `ForecastPageTest` の既存の再現手順で確かめて期待値を固定する）、予報の数値・最終更新日時が含まれない（FR-007）。フィードバック案内：`<title>` が「フィードバック | UMIYOMI」、description が「UMIYOMI へのご意見・ご要望の案内です。」、`robots` の `noindex` がちょうど 1 つ、`referrer` が `no-referrer`。404・500：`<title>` が「エラー | UMIYOMI」、`robots` が `noindex`、本文にエラーの詳細・入力文字列がない（FR-010）

### Implementation for User Story 3

- [ ] T021 [P] [US3] `backend/templates/home/index.html.twig` に、`title` ブロック（「UMIYOMI｜風・波・うねりの予報を出航前に確認」）と、空の `robots` ブロック（トップだけ登録を許可するため meta を出さない。本番の nginx の `X-Robots-Tag` が残る間は登録されないことを日本語コメントで書く）を追加する
- [ ] T022 [P] [US3] `backend/templates/feedback/index.html.twig` に `meta_description` ブロック（「UMIYOMI へのご意見・ご要望の案内です。」）を追加する。`title` は既存のまま
- [ ] T023 [US3] `backend/templates/forecast/index.html.twig` を確認し、`title` が既存のまま `{{ page.location ?? '予報' }} | UMIYOMI` で、`meta_description` と `robots` は base の既定（共通の説明文・`noindex`）を使っていることを確かめる（変更が要らなければ変えない）。T020 のテストが通ることを確認する
- [ ] T024 [US3] `docker compose exec php composer check` を通す

**Checkpoint**: 全ストーリーが揃う

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: 本番イメージでの配信確認、公開手順の追記、最終確認

- [ ] T025 `scripts/verify-prod.sh` に、トップの HTML から `icon.svg`・`icon-192`・`apple-touch-icon`・`og-image` の URL と `og:image` の絶対 URL を取り出し、それぞれ 200 と `Content-Type: image/*`（SVG は `image/svg+xml`）を確認する処理を足す。あわせて `/favicon.ico` が 200 で `image/vnd.microsoft.icon` か `image/x-icon` であること、予報・存在しない URL のページにも同じアイコンの `<link>` があることを確認する。既存の CSS・JS の確認（`CSS_PATH`・`JS_PATH` の書き方）に揃える（research R9）。`bash scripts/verify-prod.sh` を実行して通ることを確かめる
- [ ] T026 [P] `deploy/README.md` の「公開するとき」に、nginx の `X-Robots-Tag: noindex, nofollow` の行を消すと、トップだけが検索エンジンの登録対象になり（予報・フィードバック案内・エラーはアプリの `noindex` で登録されない）、共有プレビュー（OG）は消さなくても機能することを追記する。nginx の設定自体は変更しない（plan の判断点 4）
- [ ] T027 [P] `CLAUDE.md` の Active Technologies に 005 の行が既にあることを確認する（確認だけ。変更は不要）
- [ ] T028 `docker compose exec php composer check` と `bash scripts/verify-prod.sh` を最後に通し、`docker compose --profile e2e run --rm --build e2e` で既存のブラウザテストが壊れていないことを確かめる
- [ ] T029 `specs/005-favicon-og-meta/quickstart.md` の手動確認 1〜3（タブ・ライト／ダーク・16px の判別・ホーム画面追加で切り取られない）を実施する。4（LINE・X・Slack・Facebook のプレビュー）は公開後でないとできないので、未実施として人間に引き継ぐ。デザイン（アイコンと OG 画像）の最終承認も人間が行う（spec Assumptions）。すべて終わったら、この tasks.md のチェックボックスを更新する

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: すぐ始められる
- **Foundational (Phase 2)**: Setup のあと。全ストーリーをブロックする
- **US1・US2・US3 (Phase 3〜5)**: Foundational のあと。同じ `base.html.twig`・`HeadMetaTest.php` を触るので、**優先度順（US1 → US2 → US3）に 1 人で進める**のが安全
- **Polish (Phase 6)**: 全ストーリーのあと

### User Story Dependencies

- **US1**: Foundational のみに依存。単独で確認できる（MVP）
- **US2**: Foundational のみに依存。`og:image` の画像生成は US1 の `build-icons.mjs` に処理を足す（T011 → T017）が、アイコンの表示とは独立に確認できる
- **US3**: Foundational のみに依存。文言の上書きだけで、US1・US2 の `<head>` には触らない

### Within Each User Story

- テスト（失敗を確認）→ 画像・元データ → 書き出し → テンプレート → `composer check`
- T010・T011 → T012 → T013（T008 の寸法検査は T012 のあとに通る）、T016 → T017 → T018

### Parallel Opportunities

- T009・T010（別ファイルの SVG）、T016（US2 の元データは US1 の作業中でも作れる）、T021・T022（別テンプレート）、T026・T027（別ドキュメント）は並列にできる
- `HeadMetaTest.php`（T007・T008・T015・T020）と `base.html.twig`（T003・T013・T018）は同じファイルなので順番に進める

---

## Parallel Example: User Story 1

```text
# 元データの SVG は別ファイルなので同時に作れる
Task: "T009 backend/assets/images/icon.svg を手書きで作る"
Task: "T010 e2e/icons/apple-touch-icon.svg を手書きで作る"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1・2 を終える（枠・`site_origin`・エラー画面）
2. Phase 3（US1）：全画面にアイコンが出る
3. **止めて確認**：タブ・ホーム画面の見た目を人間がレビューする（デザインの承認）

### Incremental Delivery

1. Setup + Foundational → 枠が揃う
2. US1 → アイコン（MVP）
3. US2 → 共有プレビュー
4. US3 → 画面ごとの文言と検索エンジンへの登録範囲
5. Polish → 本番イメージの確認・公開手順の追記

### 注意

- アイコンと画像のデザインは提案であり、最終的な見た目の承認は人間（plan の判断点 1・2）
- 人間のレビュー対象（画面・URL 設計、検索エンジンへの公開範囲）は plan で承認を求める項目として挙がっている。承認前に実装へ進まない

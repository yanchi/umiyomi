---

description: "Task list for 007-seo-optimization"
---

# Tasks: 検索エンジン対策（SEO）

**Input**: Design documents from `/specs/007-seo-optimization/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-ui.md, quickstart.md

**Tests**: 主要な HTTP 経路（HTTP → Controller → 描画）が Constitution 原則 V に当たるため、Functional Test を必須とし、実装より先に書いて失敗することを確認する。
トップの入力欄が最初の画面に収まること（SC-006）は Playwright、本番イメージ・vhost での挙動（FR-016）は `scripts/verify-prod.sh` で確かめる。e2e は CI では動かさず手元で実行する。
Search Console・リッチリザルトテストでの確認（SC-001〜SC-004）は公開後に運営者が手動で行う（quickstart.md）。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1〜US4）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- Domain・Application・Infrastructure・Deptrac の設定・Dockerfile・CI・`compose*.yml` は変更しない。新しい Composer / npm パッケージ・PHP 拡張・外部サービスの読み込みを追加しない。PHP の ViewModel・サービス・設定項目も増やさない（plan）
- アドレスはすべて Twig global `site_origin`（`DEFAULT_URI`）を `site_origin|trim('/', 'right')` にして組み立てる。Host ヘッダー・`app.request`・リクエストのクエリを使わない（FR-006、research R1・R4）
- 新しい Controller は Request を受け取らず、UseCase・Provider・回数制限を通らない（contracts「新しいルート」）
- `|raw` は JSON-LD の 1 か所だけ。そこでは `json_encode` に `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP` を必ず付ける（research R5）
- 文言は contracts/web-ui.md の文字列をそのまま使う。「安全です」「出航できます」「問題ありません」のような断定を入れない（FR-012、原則 IV）
- トップの本文（`section.home-guide`）に `a[href]` を置かず、特定の海域・地点を勧める表現を入れない（FR-007a）
- サイト所有確認用の meta タグ・HTML ファイルを追加しない（FR-017）
- localStorage の形式（002・006）・計測の設定（006）は変えない（FR-014）
- コメントは日本語で「なぜ」だけを書く
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 新しいパッケージ・拡張は追加しない（plan.md）。変更前の基準を確認するだけ

- [X] T001 `docker compose up -d` のあと `docker compose exec php composer check` を実行し、変更前に全部通ることを確かめる（失敗するなら先に原因を報告して止まる）

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: US2（canonical）と US3（JSON-LD）が上書きする `<head>` のブロックを用意する。既定は空なので、この時点では全画面の出力は変わらない

- [X] T002 `backend/templates/base.html.twig` の `{% block robots %}...{% endblock %}` の直後に `{% block canonical %}{% endblock %}` と `{% block structured_data %}{% endblock %}` を追加する。直前に「robots と同じく既定は出さない。新しい画面が意図せず正規のアドレス・構造化データを持たないよう、登録対象の画面だけが上書きする（noindex と canonical の矛盾も避ける）」旨を日本語コメントで書く（research R4、data-model「登録対象ページ」）
- [X] T003 `docker compose exec php composer check` を通す（既存の HeadMetaTest などが変わらず通ること）

**Checkpoint**: 全テンプレートで `canonical`・`structured_data` を上書きできる。出力は従来と同じ

---

## Phase 3: User Story 1 - 検索した人が検索結果から UMIYOMI を見つけて使い始められる (Priority: P1) 🎯 MVP

**Goal**: トップだけが登録可能な状態で返り（nginx の全体の `X-Robots-Tag` を外す）、トップに h1 のキャッチ・検索結果向けの説明文・サービス内容の本文が表示される。入力欄は最初の画面に収まったまま

**Independent Test**: `HomeGuideTest`・`HeadMetaTest` が通り、e2e（desktop・mobile・375×667）でスクロールせずに入力欄と「予報を表示」が見え、`verify-prod.sh` でトップに `X-Robots-Tag` も `meta robots` もなく、他の画面には `noindex` が付くことを確かめる

### Tests for User Story 1 ⚠️

> 先に書き、実装前に失敗することを確認する

- [X] T004 [P] [US1] `backend/tests/Functional/HomeGuideTest.php` を新規作成する（既存の `backend/tests/Functional/FavoritesMarkupTest.php` と同じ書き方。Fake Provider を使い、トップの表示で Provider の呼び出しが 0 回であることも確かめる）。`GET /` について次を検査する：
  - `h1` がちょうど 1 つで、`h1.site-title a` の文字が「UMIYOMI」、`h1 .site-title__tagline` の文字が「風・波・うねりの予報を出航前に確認」
  - `h2` の文字が順に「お気に入り」（既存の見出しの文言に合わせる）「UMIYOMI でできること」「使い方」「ご利用にあたって」で、後ろの 3 つが `section.home-guide` の中にある
  - `section.home-guide` が、入力欄（`form`）とお気に入り（`favorites/_manage_list.html.twig` の要素）より文書順で後ろにある
  - `section.home-guide` の文字に「UMIYOMI（ウミヨミ）」「風速」「風向」「波高」「波向」「波周期」「うねり」「緯度 27.75、経度 129.05」「航海の安全を保証するものではありません」「気象庁」「警報・注意報」「最終更新」が含まれる（FR-007 (a)〜(d)）
  - `section.home-guide a[href]` が 0 個（FR-007a）
  - `section.home-guide` とその祖先に `hidden` 属性がない（JavaScript・localStorage に依存しない。spec Edge Cases）
  - 本文・h1・リード文に「安全です」「出航できます」「問題ありません」を含まない（FR-012）
- [X] T005 [P] [US1] `backend/tests/Functional/HeadMetaTest.php` を更新する：
  - クラス定数 `HOME_DESCRIPTION` に contracts/web-ui.md「トップの説明文」の文字列を追加し、`testHomeMeta` でトップの `meta[name="description"]` が `HOME_DESCRIPTION` と一致すること、`mb_strlen` が 120 以下であること、`og:description` と一致することを検査する（FR-010）。他の画面のテスト（`testForecastMeta` など）は `COMMON_DESCRIPTION` のまま
  - `testOpenGraphOnEveryScreen` などがトップにも `COMMON_DESCRIPTION` を期待している箇所があれば、トップだけ `HOME_DESCRIPTION` に変える
  - `screens()` に外部送信の案内（`'external transmission' => ['external_transmission']`。`open()` に `/external-transmission` を開く分岐を足す）を追加し、`meta[name="robots"][content="noindex"]` が 1 つあることを検査するテスト（`testExternalTransmissionMeta`）を足す（quickstart「robots」、FR-001。現状は `screens()` にも `ExternalTransmissionPageTest` にも robots の検査がない）
  - `testHomeMeta` の `meta robots` が 0 個の検査は維持する
  - 実装前に失敗することを確認する
- [X] T006 [P] [US1] `e2e/tests/home.spec.js` を新規作成する（既存の `e2e/tests/favorites.spec.js` と同じ書き方）。トップを開いた直後、スクロールせずに緯度・経度の入力欄と「予報を表示」ボタンが `toBeInViewport()` であること（desktop・mobile の両 project で動く）。加えて `test.describe` 内で `test.use({ viewport: { width: 375, height: 667 } })` にした同じ検査を 1 つ置く（SC-006）。「UMIYOMI でできること」の見出しが表示されていること（`toBeVisible()` をスクロール後に）も確かめる

### Implementation for User Story 1

- [X] T007 [US1] `backend/templates/home/index.html.twig` を contracts/web-ui.md「トップの本文」のとおりに変更する：
  - `{% set home_description = '出航前に、指定した緯度・経度の風速・風向・波高・波向・波周期・うねりの時間別予報を一覧で確認できます。出航の判断には、気象庁などの警報・注意報もあわせて確認してください。' %}` をファイル上部（`analytics_page` の下）に置き、`{% block meta_description %}{{ home_description }}{% endblock %}` で上書きする（US3 の JSON-LD でも同じ変数を使うため。contracts「トップの JSON-LD」）
  - h1 を `<h1 class="site-title"><a href="{{ path('app_home') }}">UMIYOMI</a> <span class="site-title__tagline">風・波・うねりの予報を出航前に確認</span></h1>` にする。リード文は変えない
  - `{{ include('favorites/_manage_list.html.twig') }}` の後ろに `<section class="home-guide" aria-label="UMIYOMI について">` を追加し、h2「UMIYOMI でできること」（段落 2 つ・項目のリスト・最終更新の段落）、h2「使い方」（`<ol>` の 3 手順）、h2「ご利用にあたって」（段落 1 つ）を contracts の文言どおりに書く。リンク（`<a>`）は置かない
  - `{% block robots %}{% endblock %}` の上のコメントを「トップだけ検索エンジンへの登録を許可するため meta を出さない」に改め、nginx の `X-Robots-Tag` が残る前提の記述を消す
- [X] T008 [US1] `backend/assets/styles/app.css` の `.site-title` の近くに、`.site-title__tagline`（`display: block`、h1 より小さい文字・通常の太さ。スマホ幅で 1 行に収まる大きさ）と `.home-guide`（入力欄・お気に入りとの間の余白、`h2`・`ul`・`ol` の余白。既存の見出し・本文の見た目に合わせる）を少量足す。最初の画面の高さを増やさないよう、tagline の行の高さを詰める（SC-006）
- [X] T009 [US1] `deploy/nginx/umiyomi.conf` を contracts「Web サーバー」のとおりに変更する：server 全体の `add_header X-Robots-Tag "noindex, nofollow" always;` とその上のコメントを消し、`location ^~ /.well-known/acme-challenge/` に `add_header X-Robots-Tag "noindex" always;` を足す。コメントに「アプリの画面は meta robots で登録を制御する（トップだけ登録可）。nginx が直接 200 で中身を返すのはチャレンジのファイルだけなので、そこにだけ付ける（502/504 は登録されず、301 は転送先で判断される）」旨を書く（research R8）
- [X] T010 [US1] `scripts/verify-prod.sh` の「5. ホストの nginx の vhost」を変更する（FR-016、research R12）：
  - `contains '検索エンジンに載せない (X-Robots-Tag)' ...` と `contains '404 にも付く' ...` を削除し、`ok 'トップに X-Robots-Tag が付かない'`（`$VIA_VHOST` に `X-Robots-Tag` が含まれないこと。`grep -qi` で あり／なし を出す既存の書き方）に置き換える
  - certbot のチャレンジのパスに `X-Robots-Tag: noindex` が付くことを確かめる：`curl -s -o /dev/null -D - -H "Host: ${HOST}" "http://127.0.0.1:${VHOST_PORT}/.well-known/acme-challenge/verify"` の応答（ファイルがないので 404。`always` により付く）に `X-Robots-Tag: noindex` が含まれること
  - 「vhost を本当に通して、検索エンジンに載せないヘッダーが付くか確かめる」のコメントを、新しい確認内容に合わせて改める
- [X] T011 [US1] `scripts/verify-prod.sh` の「2. 画面とアセット」に、本番イメージでの robots の確認を足す（FR-016）：トップ（`$HOME_HTML`）に `name="robots"` がないこと、予報の入力不正（`$BASE/forecast?lat=N27&lon=`。外部 API を呼ばない 422）・外部送信の案内（`$BASE/external-transmission`）・404（`$BASE/nope`）の HTML に `<meta name="robots" content="noindex">` があること。フィードバック案内はフォーム未設定で 404 のため対象外である旨を 1 行コメントで書く
- [X] T012 [US1] `docker compose exec php composer check` を通し（T004・T005 が通ること）、`docker compose --profile e2e run --rm --build e2e` で T006 が desktop・mobile とも通ること、`bash scripts/verify-prod.sh` が全部成功することを確かめる

**Checkpoint**: トップだけが登録可能になり、本文・見出し・説明文がそろう。ここだけでもマージ・公開できる（MVP）

---

## Phase 4: User Story 2 - 検索エンジンが登録すべきページと正規のアドレスを正しく理解できる (Priority: P2)

**Goal**: `/robots.txt`（すべて許可＋サイトマップの場所）と `/sitemap.xml`（トップ 1 件）を `DEFAULT_URI` から返し、トップにだけパラメーターなしの canonical を出す

**Independent Test**: `SeoTest` の robots.txt・sitemap・canonical の検査が通り、`verify-prod.sh` で `https://verify.example.com` を基準にしたアドレスが返ることを確かめる

### Tests for User Story 2 ⚠️

> 先に書き、実装前に失敗することを確認する

- [ ] T013 [US2] `backend/tests/Functional/SeoTest.php` を新規作成する（`HeadMetaTest` と同じく Fake Provider を使い、各リクエストで Provider の呼び出し回数を確かめる。テスト環境の `DEFAULT_URI` は `http://localhost`）：
  - `GET /robots.txt`：200、`Content-Type` が `text/plain; charset=UTF-8`、`X-Robots-Tag` が `noindex`、本文が contracts のとおり `User-agent: *` / 空の `Disallow:` / `Sitemap: http://localhost/sitemap.xml` を含み、値を持つ `Disallow:` 行がない、Provider 呼び出し 0 回
  - `GET /sitemap.xml`：200、`Content-Type` が `application/xml; charset=UTF-8`、`X-Robots-Tag` が `noindex`、`simplexml_load_string` で読め、名前空間 `http://www.sitemaps.org/schemas/sitemap/0.9` の `url` が 1 件で `loc` が `http://localhost/`、`lastmod`・`changefreq`・`priority` を含まない、Provider 呼び出し 0 回
  - canonical（DataProvider）：`/` と `/?utm_source=x&lat=1` で `head link[rel="canonical"]` が 1 つで `href` が `http://localhost/`（クエリが混ざらない。US2-3）
  - canonical がない（DataProvider）：予報 200（`/forecast?lat=27.75&lon=129.05`）・422（入力不正）・429・503・`/feedback`（フォーム設定済みの環境。既存の `FeedbackPageTest` の準備に合わせる）・`/external-transmission`・404・500 で `link[rel="canonical"]` が 0 個（research R4）。429・503・500 の再現方法は既存の `HeadMetaTest::open()` に合わせる
  - Host ヘッダーを `evil.example.com` にして `/robots.txt`・`/sitemap.xml`・`/` を取得しても、アドレスが `http://localhost` のまま（FR-006）
  - 実装前に失敗することを確認する

### Implementation for User Story 2

- [ ] T014 [P] [US2] `backend/templates/seo/robots.txt.twig` を新規作成する。contracts「`/robots.txt`」の 4 行（`User-agent: *`、`Disallow:`、空行、`Sitemap: {{ site_origin|trim('/', 'right') }}{{ path('app_sitemap') }}`）だけを出す。Twig のコメントで「予報画面を Disallow にするとクローラーが noindex を読めず、アドレスだけが載るため、どの画面も禁止しない」旨を書く（research R2）。twig-cs-fixer で末尾の改行などが崩れないか確かめる
- [ ] T015 [P] [US2] `backend/templates/seo/sitemap.xml.twig` を新規作成する。contracts「`/sitemap.xml`」の XML を出し、`<loc>` は `{{ site_origin|trim('/', 'right') }}{{ path('app_home') }}`。`lastmod` などを出さない理由（正確な更新日時を持たないため）を Twig のコメントで書く（research R3）
- [ ] T016 [P] [US2] `backend/src/Presentation/Web/Controller/RobotsTxtController.php` を新規作成する。`HomeController` と同じ形の `final class RobotsTxtController extends AbstractController`、`#[Route('/robots.txt', name: 'app_robots_txt', methods: ['GET'])]`、`__invoke(): Response` で `seo/robots.txt.twig` を描画し、`Content-Type: text/plain; charset=UTF-8` と `X-Robots-Tag: noindex` を付けた Response を返す。クラスの PHPDoc に、`public/` の静的ファイルにせずルートにする理由（`DEFAULT_URI` から作り、環境ごとのアドレスにするため。research R1）と `X-Robots-Tag` を付ける理由（登録対象はトップだけ。research R9）を日本語で書く
- [ ] T017 [P] [US2] `backend/src/Presentation/Web/Controller/SitemapController.php` を新規作成する。T016 と同じ形で `#[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]`、`seo/sitemap.xml.twig` を描画し、`Content-Type: application/xml; charset=UTF-8` と `X-Robots-Tag: noindex` を付ける
- [ ] T018 [US2] `backend/templates/home/index.html.twig` に `{% block canonical %}<link rel="canonical" href="{{ site_origin|trim('/', 'right') }}{{ path('app_home') }}">{% endblock %}` を追加する。コメントに「リクエストのクエリを使わず、utm などのパラメーター付きで開いてもパラメーターなしのトップを指す」旨を書く（research R4）
- [ ] T019 [US2] `scripts/verify-prod.sh` の「2. 画面とアセット」に、T011 の robots の確認の後ろで次を足す（FR-016、research R12）：
  - トップの canonical が `https://${HOST}/`（`<link rel="canonical" href="https://${HOST}/">` を含む）
  - `/robots.txt` が 200、`head_content_type` が `text/plain; charset=UTF-8`、本文に `Sitemap: https://${HOST}/sitemap.xml` を含む
  - `/sitemap.xml` が 200、`head_content_type` が `application/xml; charset=UTF-8`、本文に `<loc>https://${HOST}/</loc>` を含む
  - `head_content_type` は既存の定義より後ろで使うか、定義をこの確認より前に移す
- [ ] T020 [US2] `docker compose exec php composer check` を通し（T013 が通ること）、`bash scripts/verify-prod.sh` が全部成功することを確かめる

**Checkpoint**: クローラー向けの案内・ページ一覧・正規のアドレスがそろう。US1 と独立に確かめられる

---

## Phase 5: User Story 3 - 検索結果でサービスの種類が伝わる (Priority: P3)

**Goal**: トップにだけ `WebSite` の JSON-LD を出し、サイト名・説明・正規のアドレスを機械的に読み取れるようにする

**Independent Test**: `SeoTest` の JSON-LD の検査が通る。公開後にリッチリザルトテスト・Schema Markup Validator でエラー 0 件（SC-004。quickstart の手動確認）

### Tests for User Story 3 ⚠️

- [ ] T021 [US3] `backend/tests/Functional/SeoTest.php` に JSON-LD の検査を追加する（T013 の後）：
  - トップ（`/` と `/?utm_source=x`）で `script[type="application/ld+json"]` がちょうど 1 つ、`json_decode(..., flags: JSON_THROW_ON_ERROR)` でき、`@context` が `https://schema.org`、`@type` が `WebSite`、`name` が `UMIYOMI`、`alternateName` が `ウミヨミ`、`url` が `http://localhost/`、`inLanguage` が `ja`、`description` が `head meta[name="description"]` の `content` と一致する（data-model「構造化データ」）
  - キーが上記の 7 つだけで、`offers`・`aggregateRating`・`review` などを含まない（FR-011）
  - 生の HTML（`$client->getResponse()->getContent()`）の JSON-LD 部分に `</` や生の `<`・`&` が現れない（`JSON_HEX_TAG`・`JSON_HEX_AMP`）
  - `description` に「安全です」「出航できます」「問題ありません」を含まない（FR-012）
  - T013 の「canonical がない」DataProvider の全画面で `script[type="application/ld+json"]` が 0 個
  - 実装前に失敗することを確認する

### Implementation for User Story 3

- [ ] T022 [US3] `backend/templates/home/index.html.twig` に `{% block structured_data %}` を追加し、`{'@context': 'https://schema.org', '@type': 'WebSite', name: 'UMIYOMI', alternateName: 'ウミヨミ', url: site_origin|trim('/', 'right') ~ path('app_home'), description: home_description, inLanguage: 'ja'}` を `json_encode(constant('JSON_UNESCAPED_UNICODE') b-or constant('JSON_UNESCAPED_SLASHES') b-or constant('JSON_HEX_TAG') b-or constant('JSON_HEX_AMP'))|raw` で `<script type="application/ld+json">` に出す。直前のコメントに「HTML の自動エスケープでは `"` が `&quot;` になり JSON が壊れるので raw にする。値は固定の文言と設定値だけで利用者の入力は入らず、HEX_TAG・HEX_AMP で `</script>` から抜け出せない」「WebApplication は料金・評価が必須で、画面にない情報を作ることになるため WebSite だけにする」旨を書く（research R5）。`description` は T007 の `home_description` を使い、文言を二重に書かない。`docker compose exec php composer check` を通す（T021 が通ること）

**Checkpoint**: トップの構造化データがそろう。US1・US2 と独立に確かめられる

---

## Phase 6: User Story 4 - 運営者が検索での登録・表示状況を確認できる (Priority: P3)

**Goal**: 運営者が、本番の nginx の反映・反映前後の確認・Search Console の所有確認（DNS の TXT）・サイトマップの送信・URL 検査・元に戻す方法を手順書だけで行える

**Independent Test**: deploy/README.md の手順だけを読んで、quickstart.md「公開の操作」の 1〜9 と「元に戻すとき」が実行できる内容になっていることを確かめる（実際の操作はマージ・デプロイ後に運営者が行う）

### Implementation for User Story 4

- [ ] T023 [US4] `deploy/README.md` の「公開するとき」の節を「検索エンジンへの公開」に置き換える（FR-013、quickstart「公開の操作」、research R8・R10）。README の書き換えはこのタスクにまとめる（nginx の反映は運営者がマージ後に行うので、US1 の時点で README だけ公開後の書き方にしない）。次を含める：
  - 冒頭（「公開するまでは vhost が `X-Robots-Tag: noindex, nofollow` を付けて…」）と「DNS と nginx」の手順 5（noindex が付いていることを確かめる）を、公開後の状態（トップだけ登録対象・他の画面はアプリの `noindex`。nginx はチャレンジのパスにだけ `noindex`）に合わせて改め、「検索エンジンへの公開」の節への参照にする
  - 前提：007 が master にマージ・デプロイされていること（`curl -s https://umiyomi.isl-mentor.com/robots.txt` が `Sitemap:` を返す）。デプロイ前に nginx を変えても、トップが登録可能になり robots.txt が 404 になるだけで他の画面は `noindex` のまま（data-model「状態の遷移」）
  - 反映前の確認（`X-Robots-Tag: noindex, nofollow` が付いている）
  - VPS の `/etc/nginx/sites-available/umiyomi` の変更：80・443（certbot が写した）両方の server から `add_header X-Robots-Tag "noindex, nofollow" always;` を消し、`/.well-known/acme-challenge/` の location に `add_header X-Robots-Tag "noindex" always;` を足す → `sudo nginx -t && sudo systemctl reload nginx`
  - 反映後の確認：quickstart.md 5. の `curl` 6 本と期待する結果
  - Search Console：ドメイン プロパティ `umiyomi.isl-mentor.com`（親ドメイン `isl-mentor.com` にしない理由を 1 行）→ 表示された TXT レコードを DNS の `umiyomi.isl-mentor.com` に追加（A レコードと共存できる）→ 確認。アプリに確認用の meta タグ・ファイルを置かないこと（FR-017）
  - サイトマップ `https://umiyomi.isl-mentor.com/sitemap.xml` の送信、URL 検査（トップは「登録可能」→ インデックス登録をリクエスト。予報・外部送信の案内・404、設定済みならフィードバック案内は「noindex タグによって除外」）、リッチリザルトテスト・Schema Markup Validator でエラー 0 件
  - 公開後の定期確認（2〜4 週間後の「ページ」で登録済みがトップだけ・予報画面 0 件、3 か月以内の GA4 の Organic Search）
  - Bing Webmaster Tools は任意で、Search Console からインポートできる旨を 1 行
  - 元に戻すとき（server に `add_header X-Robots-Tag "noindex, nofollow" always;` を戻して reload。急ぐときは Search Console の「削除」）
  - 既存の「共有プレビュー（OG）は `X-Robots-Tag` があっても機能する」旨の記述は、公開後の状態に合わない部分を整理する
- [ ] T024 [US4] `deploy/README.md` 全体と `deploy/nginx/umiyomi.conf` の冒頭コメントを読み直し、「公開するまで」「公開するとき」を前提にした記述が残っていないこと、T023 の内容と矛盾しないことを確かめる（FR-013）

**Checkpoint**: 手順書だけで公開と効果測定の準備ができる

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 全体の整合と最終確認

- [ ] T025 [P] `docker compose up -d` の開発環境で quickstart.md「手元での手動確認」を行う：`curl -i http://localhost:8000/robots.txt`・`/sitemap.xml`、`/?utm_source=test` の canonical・JSON-LD・robots、ブラウザの 375×667 で入力欄と「予報を表示」がスクロールなしで見えること、お気に入りの後に本文の 3 見出しが出ること、JavaScript を無効にしても本文が表示されること
- [ ] T026 [P] `CLAUDE.md`・`docs/` などのドキュメントに「公開前は全画面 noindex」の前提の記述が残っていないか `grep -rn "X-Robots-Tag\|公開するまで\|公開するとき" --include='*.md' . | grep -v '^\./specs/'` で確かめ、残っていれば公開後の状態に合わせて直す。`specs/` 配下（005・006 など）は当時の判断の記録なので直さない
- [ ] T027 最終確認：`docker compose exec php composer check`、`docker compose --profile e2e run --rm --build e2e`、`bash scripts/verify-prod.sh` がすべて通ることを確かめ、結果（成功・失敗の件数）を報告する

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 依存なし
- **Foundational (Phase 2)**: Setup の後。US2・US3 のブロックの上書きの前提
- **US1 (Phase 3)**: Foundational の後（US1 自体はブロックを使わないが、`home/index.html.twig` の変更の衝突を避けるため先に終える）
- **US2 (Phase 4)**: Foundational の後。`home/index.html.twig`（T018）と `verify-prod.sh`（T019）は US1 の T007・T011 と同じファイルなので、US1 の後に行う
- **US3 (Phase 5)**: US1 の T007（`home_description`）と US2 の T013（`SeoTest.php` と DataProvider）の後
- **US4 (Phase 6)**: US1 の T009 の後（nginx の変更内容を手順書に書く）。US2・US3 とは独立
- **Polish (Phase 7)**: 全ストーリーの後

### User Story Dependencies

- **US1 (P1)**: 他のストーリーに依存しない
- **US2 (P2)**: 機能としては独立。同じファイルを触るため US1 の後に行う
- **US3 (P3)**: `home_description`（US1）と `SeoTest.php`（US2）を使う
- **US4 (P3)**: nginx の変更（US1）を手順書に書く。robots.txt・sitemap（US2）の URL を手順で使うが、手順書の作成自体は US2 の実装を待たない

### Within Each User Story

- テストを先に書き、失敗することを確かめてから実装する
- テンプレート → Controller → `verify-prod.sh` → `composer check` の順
- 同じファイル（`home/index.html.twig`・`verify-prod.sh`・`SeoTest.php`・`deploy/README.md`）を触るタスクは順番に行う

### Parallel Opportunities

- US1：T004・T005・T006（別ファイルのテスト）。実装は T007 → T008 と T009 → T010 → T011 が別系統なので、T007・T009 は並行できる
- US2：T014・T015・T016・T017（テンプレート 2 つと Controller 2 つ。別ファイル）
- US4（T023・T024）は US2・US3 と並行できる
- Polish：T025・T026

---

## Parallel Example: User Story 1

```bash
# テストを同時に書く:
Task: "HomeGuideTest を backend/tests/Functional/HomeGuideTest.php に新規作成"
Task: "HeadMetaTest のトップの説明文と外部送信の案内の robots を backend/tests/Functional/HeadMetaTest.php で更新"
Task: "入力欄が最初の画面に収まる e2e を e2e/tests/home.spec.js に新規作成"

# 別系統の実装を同時に進める:
Task: "トップの h1・説明文・本文を backend/templates/home/index.html.twig に追加"
Task: "全体の X-Robots-Tag を deploy/nginx/umiyomi.conf から外す"
```

## Parallel Example: User Story 2

```bash
Task: "robots.txt のテンプレートを backend/templates/seo/robots.txt.twig に新規作成"
Task: "sitemap のテンプレートを backend/templates/seo/sitemap.xml.twig に新規作成"
Task: "RobotsTxtController を backend/src/Presentation/Web/Controller/RobotsTxtController.php に新規作成"
Task: "SitemapController を backend/src/Presentation/Web/Controller/SitemapController.php に新規作成"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1・2 を終える
2. Phase 3（US1）を終える
3. **止めて確かめる**：`composer check`・e2e・`verify-prod.sh` が通り、トップだけが登録可能・本文が表示されること
4. この時点でもマージ・デプロイ・nginx の反映で、トップが検索エンジンに登録されうる（robots.txt がなくても Google は「制限なし」と扱う）

### Incremental Delivery

1. Setup + Foundational → ブロックの準備
2. US1 → トップが登録可能（MVP）
3. US2 → robots.txt・sitemap・canonical で登録の速さと正確さを上げる
4. US3 → 検索結果のサイト名表示
5. US4 → 運営者の手順書。マージ後に運営者が nginx を反映し、Search Console を設定する
6. 1 つの PR にまとめる場合も、公開の操作（nginx の反映）は US4 の手順書に従いマージ・デプロイ後に行う

---

## Notes

- [P] は別ファイルで、未完了のタスクに依存しないもの
- 実装前に、plan.md「レビューで判断してほしい点」1〜6 の承認を得ていること（画面・URL 設計、本番 Web サーバー設定）
- 本文の文言（contracts/web-ui.md「本文（案）」）を実装中に変えたくなったら、先に contracts を直して人間のレビューを受ける
- 各タスクまたは論理的なまとまりごとにコミットする

# Research: 検索エンジン対策（SEO）

spec の NEEDS CLARIFICATION はない（Clarifications で 3 点決定済み）。以下は計画で決めた設計判断の記録。

## R1. `robots.txt` と `sitemap.xml` の返し方

- **Decision**: Symfony のルート（`/robots.txt`・`/sitemap.xml`）として `Presentation/Web/Controller` に invokable Controller を 2 つ足し、Twig テンプレート（`seo/robots.txt.twig`・`seo/sitemap.xml.twig`）で描画する。アドレスは既存の Twig global `site_origin`（`DEFAULT_URI`）から作る
- **Rationale**:
  - FR-006：アドレスを本番の公開アドレス基準で作り、Host ヘッダーを使わない。005 で OG 画像のために作った `site_origin` と同じ仕組みに乗せれば、正規のアドレス・ページ一覧・構造化データの 3 つが 1 つの設定（`DEFAULT_URI`）から決まる
  - 開発（`http://localhost`）・e2e（`DEFAULT_URI` は `.env` の既定）・`verify-prod.sh`（`https://verify.example.com`）では、それぞれの環境の値になり、本番のアドレスとして混ざらない（US2-4）。`verify-prod.sh` で「`DEFAULT_URI` から作られていること」を確かめられる
  - prod の FrankenPHP は `public/` に実ファイルがなければ `index.php` に回すので、ルートで返せる
  - 外部 API・回数制限・Provider を通らない（FR-015 とは無関係に軽い）
- **Alternatives considered**:
  - `public/robots.txt`・`public/sitemap.xml` に本番のドメインを直書き：最も単純だが、本番のドメインが `DEFAULT_URI` と二重管理になり、`verify-prod.sh` で本番構成の値を検証できない。開発環境でも本番のアドレスを返す
  - `symfony/...` の SEO バンドル（例：sitemap 生成のバンドル）：対象ページが 1 件で、新しいパッケージを追加しない方針（spec Assumptions）にも反する

## R2. `robots.txt` の内容

- **Decision**:

  ```text
  User-agent: *
  Disallow:

  Sitemap: {site_origin}/sitemap.xml
  ```

  `Content-Type: text/plain; charset=UTF-8`。どの画面の取得も禁止しない
- **Rationale**: FR-003・spec Edge Cases（予報画面を Disallow にすると `noindex` を読めず、アドレスだけ載る）。空の `Disallow:` は 1994 年の原仕様から全クローラーが「すべて許可」と解釈する書き方
- **Alternatives considered**: `Allow: /` — Google・Bing は解釈するが、`Allow` は後から入った拡張。結果は同じなので原仕様の書き方にする

## R3. `sitemap.xml` の内容

- **Decision**: `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">` に `<url><loc>{site_origin}/</loc></url>` を 1 件だけ。`lastmod`・`changefreq`・`priority` は出さない。`Content-Type: application/xml; charset=UTF-8`
- **Rationale**:
  - FR-004：登録対象（トップだけ）を正規のアドレスで列挙する
  - Google は `changefreq`・`priority` を無視し、`lastmod` は「正確な場合だけ」使う。トップの内容の更新日時を正しく出す仕組みはなく、デプロイ日時などを出すと不正確な値になる
- **Alternatives considered**: `lastmod` にデプロイ時刻を入れる — 内容が変わらないデプロイでも更新扱いになり、Google に「不正確な lastmod」と見なされうる

## R4. 正規のアドレス（canonical）を出す画面

- **Decision**: `<link rel="canonical">` は**トップだけ**に出す（`{site_origin}/`。クエリなし）。予報・フィードバック案内・外部送信の案内・エラー画面には出さない。`base.html.twig` に空の `canonical` ブロックを用意し、トップのテンプレートだけが上書きする
- **Rationale**:
  - FR-005 は「登録しない画面に示すかどうかは計画で決めてよい」としている。`noindex` と `canonical` を同じページに出すのは、Google が「矛盾したシグナル」として避けるよう案内している組み合わせ
  - 予報画面に canonical を出すなら正規化済みの自分自身（`/forecast?lat=27.75&lon=129.05`）しかありえないが、`noindex` なので効果がなく、入力不正・429・503 の画面で何を指すかの規則が増えるだけ
  - 「新しい画面が意図せず登録されない」という 005 の既定（`robots` ブロックの既定が `noindex`）と同じく、canonical も既定は「出さない」にし、登録対象の画面だけが明示的に出す
  - `utm_*` などのパラメーターつきでトップを開いても、canonical はパラメーターなしのトップを指す（US2-3）。テンプレートでリクエストのクエリを一切使わないので、混入しない
- **Alternatives considered**:
  - 全画面に自分自身の canonical を出す：上記の矛盾。予報画面の入力不正時に入力文字列が head に入る経路も増える（005 FR-009 の考え方に反する）
  - `og:url` も出す：005 で出さないと決め、HeadMetaTest も「出さない」を検査している。共有プレビューのまとめに必要になったら別途

## R5. 構造化データ（FR-011・US3）

- **Decision**: トップだけに JSON-LD を 1 つ出す。型は `WebSite` のみ

  ```json
  {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "name": "UMIYOMI",
    "alternateName": "ウミヨミ",
    "url": "{site_origin}/",
    "description": "{トップの説明文と同じ文}",
    "inLanguage": "ja"
  }
  ```

  Twig で配列を組み、`json_encode`（`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP`）して `|raw` で `<script type="application/ld+json">` に入れる
- **Rationale**:
  - Google の「サイト名」は `WebSite` の `name`・`alternateName`・`url` をトップから読む。検索結果のサイト名表示（US3）に直接効くのはこれだけ
  - `alternateName`「ウミヨミ」は、トップの本文にも「UMIYOMI（ウミヨミ）」と表示して、画面と矛盾しないようにする（FR-011）
  - `WebApplication` / `SoftwareApplication` は、Google のリッチリザルトの必須項目が `offers`（料金）と `aggregateRating` または `review`（評価）で、出せば料金・評価という画面にない情報を作ることになり（FR-011 違反）、出さなければリッチリザルトテストでエラーになる（SC-004 違反）。そのため使わない
  - `JSON_HEX_TAG`・`JSON_HEX_AMP` で `<`・`>`・`&` をエスケープするので、`</script>` で抜け出せない。値は固定の文言と `site_origin`（設定値）だけで、利用者の入力は入らない。HTML の自動エスケープを通すと `"` が `&quot;` になり JSON が壊れるため、ここだけ `|raw` を使う
- **Alternatives considered**:
  - `Organization`（ロゴ・運営者）：運営者の情報を画面に出していないので、出すと画面との矛盾になる
  - 構造化データを PHP（ViewModel）で組み立てる：値が固定で、Twig の `json_encode` で足りる。PHP のクラスを増やさない（005 R1 と同じ判断）
  - Microdata（HTML の属性）：既存のマークアップに属性が散らばり、本文の変更で壊れやすい。Google も JSON-LD を推奨

## R6. トップの本文（FR-007〜FR-010）

- **Decision**:
  - 見出しの構造：`h1`（「UMIYOMI」＋その下に小さく「風・波・うねりの予報を出航前に確認」）→ 既存のリード文 → 入力欄 → 既存の `h2` お気に入り → 新しい `section.home-guide` に `h2` 3 つ（「UMIYOMI でできること」「使い方」「ご利用にあたって」）
  - 文言は [contracts/web-ui.md](contracts/web-ui.md) の案のとおり。入力例は既存のプレースホルダーと同じ「緯度 27.75、経度 129.05」を文章で示し、リンクにしない（FR-007(c)・FR-007a）
  - 本文は Twig で静的に出す（JavaScript・localStorage に依存しない。spec Edge Cases）
- **Rationale**:
  - FR-008・SC-006：本文を入力欄・お気に入りより後ろに置けば、最初の画面の構成は h1 のキャッチの 1 行分しか変わらない。iPhone 13（390×664 の表示領域）・375×667 で入力欄と「予報を表示」がスクロールなしで見えることを e2e で確かめる
  - お気に入りを本文より前にするのは、繰り返し使う人がトップで最初に使うのがお気に入りだから。初めての人は、お気に入りが空の案内（1 行）の後に本文を読む
  - FR-009：ページの最上位の見出しを 1 つにし、h1 に「サービス名とできること」を含める。予報・案内画面の見出しは変えない
  - 表示する数値の種類は、予報画面の行の名前（風速・突風・風向・波高・波向・波周期・うねり高さ・うねり向き・うねり周期）に合わせる。spec の 6 種類（風速・風向・波高・波向・波周期・うねり）を含み、実際の画面と矛盾しない
  - 予報の期間は UseCase の取得時間（73 時間）から「およそ 3 日先まで」とする
- **Alternatives considered**:
  - 本文を入力欄の前に置く：SC-006 を満たせない
  - 「よくある質問」の形式＋`FAQPage` の構造化データ：Google は FAQ のリッチリザルトを政府・医療系の権威あるサイトに限定しており、効果がない。文章量が増えてトップが重くなる
  - 本文を `<details>` で折りたたむ：初めての人が数値の種類を見落とす（SC-005）

## R7. トップのタイトルと説明文（FR-010）

- **Decision**:
  - タイトルは現状維持：「UMIYOMI｜風・波・うねりの予報を出航前に確認」（26 文字。Google の表示幅に収まる）
  - 説明文はトップだけ上書きする：「出航前に、指定した緯度・経度の風速・風向・波高・波向・波周期・うねりの時間別予報を一覧で確認できます。出航の判断には、気象庁などの警報・注意報もあわせて確認してください。」（約 90 文字。FR-010 の 120 文字以内）
  - 他の画面の説明文（005 の共通の説明文）は変えない
- **Rationale**: 検索結果で「出航前」「風・波・うねり」「どの数値が見られるか」が説明文から読み取れるようにする。他の画面は `noindex` で検索結果に出ないので、共有プレビューの文言を変える理由がない
- **Alternatives considered**: 共通の説明文自体を差し替える — 予報画面の共有プレビュー（005）の文言まで変わり、005 の契約・テストの変更範囲が広がる

## R8. 本番の Web サーバー設定（FR-002・FR-013）

- **Decision**:
  - `deploy/nginx/umiyomi.conf` の server 全体の `add_header X-Robots-Tag "noindex, nofollow" always;` を消す
  - 代わりに `location ^~ /.well-known/acme-challenge/` にだけ `add_header X-Robots-Tag "noindex" always;` を足す
  - VPS 上の `/etc/nginx/sites-available/umiyomi`（certbot が 443 の server に写した行を含む）の変更は、運営者が手順書（deploy/README.md）に従って行う
- **Rationale**:
  - spec Edge Cases「アプリが返さない応答が検索結果に載らない」：nginx が直接返すのは、アプリ停止時の 502/504（Google は 5xx を登録しない）、HTTP→HTTPS の 301（転送先で判断される）、certbot のチャレンジファイルの 3 種類。最後だけは 200 で中身のあるテキストを返しうるので、そこだけ `noindex` を残す
  - アプリの応答は、トップ以外すべて `<meta name="robots" content="noindex">` を持ち（005）、`robots.txt`・`sitemap.xml` は HTML ではないので、ヘッダーで一律に付ける必要がなくなる
  - 反映の順番（spec Edge Cases）：アプリのデプロイ前に nginx を変えても、トップが登録可能になり `robots.txt` が 404 になるだけ（Google は 404 の robots.txt を「制限なし」と扱う）。デプロイ後に変えるまでは従来どおり全画面が登録されない。手順書ではデプロイ後の反映を案内する
- **Alternatives considered**:
  - アプリ側で `noindex` の画面に `X-Robots-Tag` ヘッダーも付ける：meta と二重管理になる。HTML 以外で登録したくない応答（`sitemap.xml`）だけ R9 で付ける
  - nginx で `location = /` 以外に `X-Robots-Tag` を付ける：`robots.txt`・`sitemap.xml`・アセットの扱いを nginx とアプリの両方で管理することになる

## R9. `robots.txt`・`sitemap.xml` 自体の登録

- **Decision**: 両方の応答に `X-Robots-Tag: noindex` ヘッダーを付ける（Controller で付与）
- **Rationale**: FR-001「登録対象はトップだけ」。`sitemap.xml` はまれに通常のページとして登録されることがあり、ヘッダーで除外するのが Google の案内する方法。`robots.txt` には実害はないが、同じ扱いにして規則を単純にする
- **Alternatives considered**: 付けない — 実害は小さいが、SC-003 の「登録されているのはトップだけ」の確認で例外が出る

## R10. Search Console の所有確認とサイトの登録（FR-013・FR-017）

- **Decision**: Search Console で **ドメイン プロパティ `umiyomi.isl-mentor.com`** を作り、表示された TXT レコードを `umiyomi.isl-mentor.com` に足して確認する。所有確認後、サイトマップ `https://umiyomi.isl-mentor.com/sitemap.xml` を送信し、URL 検査でトップ・予報・案内・エラー画面を確かめる。手順は deploy/README.md に書く
- **Rationale**:
  - Clarifications で DNS（TXT）に決定済み。アプリの画面・ファイルに確認用のタグを足さない（FR-017）
  - 親ドメイン `isl-mentor.com` のドメイン プロパティにすると、同じドメインの他サービス（shipinfo など）のデータまで同じプロパティに入る。UMIYOMI のサブドメインに限ったプロパティにする
  - TXT レコードは A レコードと同じ名前に共存できる（CNAME ではないため）
- **Alternatives considered**:
  - HTML ファイル・meta タグでの確認：FR-017 で除外
  - Bing Webmaster Tools：Search Console からのインポートで追加できるが、spec の主対象は Google（Assumptions）。手順書に「任意」として 1 行だけ触れる

## R11. クローラーによる予報画面の取得（FR-015）

- **Decision**: 変更しない。クローラーも既存のキャッシュと接続元ごとの回数制限（10 分 30 回）の範囲で扱う
- **Rationale**: 回数制限を超えたクローラーには 429 が返り、Google は 429 を「取得の頻度を下げる合図」として扱う。429・422・503 の画面も `noindex` を持つ（005 の HeadMetaTest で確認済み）。クローラー判定（User-Agent）を足すと偽装への対策まで必要になり、MVP の範囲を超える

## R12. 本番構成の自動確認（FR-016）

- **Decision**: `scripts/verify-prod.sh` を次のように変える
  - 5. の vhost の確認：トップに `X-Robots-Tag` が**付かない**こと、`/.well-known/acme-challenge/` には `noindex` が付くこと、に置き換える
  - 2. の画面の確認に追加：トップに `meta robots` がなく、canonical が `https://verify.example.com/`（`DEFAULT_URI`）であること。予報（入力不正の 422。外部 API を呼ばない）・外部送信の案内・404 に `noindex` があること。`/robots.txt` が `text/plain` で `Sitemap: https://verify.example.com/sitemap.xml` を含むこと。`/sitemap.xml` が `application/xml` で `<loc>https://verify.example.com/</loc>` を含むこと
  - フィードバック案内は、`verify-prod.sh` がフォーム未設定で起動する（/feedback は 404）ため対象外。Functional Test で確認する
- **Rationale**: FR-016 そのもの。本番イメージ（`asset-map:compile`・`cache:warmup` 済み・prod の Twig）で、`DEFAULT_URI` から正しく組み立てられることを確かめる

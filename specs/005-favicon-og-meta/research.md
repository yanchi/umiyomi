# Research: ファビコンと OG 情報

Technical Context に NEEDS CLARIFICATION は残っていない。以下は設計上の判断の記録。

## R1: メタ情報の置き場所（PHP クラスを作るか）

- **Decision**: `base.html.twig` に `<head>` の共通部分を持ち、`title`・`meta_description`・`robots`・`og_image_alt` のブロックを各テンプレートが上書きする。PHP のクラス（ViewModel・Factory・Twig 拡張）は増やさない。
- **Rationale**: 画面ごとの値は固定の文言か、既存の `page.location`（浮動小数点から整形済み。利用者の入力文字列ではない）だけ。値の組み立てにロジックがないので、クラスを作ると YAGNI に反する。`page.location` は `ForecastPageViewModelFactory` が丸めた数値から作るため、FR-009 を満たす。
- **Alternatives**: `PageMeta` の ViewModel を作る → 値が固定文言ばかりで、型を増やすだけ。将来 JSON API で共有用情報を返す要求は今はない。

## R2: アイコンの種類

- **Decision**: 次の 4 つ。manifest は作らない。
  | ファイル | 用途 | `<link>` |
  |---|---|---|
  | `public/favicon.ico`（16・32px 入り） | 古いブラウザ、`/favicon.ico` を直接取りに来るクローラー | `rel="icon" href="/favicon.ico" sizes="32x32"` |
  | `assets/images/icon.svg` | 最近のブラウザのタブ。ダークテーマ対応 | `rel="icon" type="image/svg+xml"` |
  | `assets/images/icon-192.png` | Android のホーム画面追加・検索結果 | `rel="icon" type="image/png" sizes="192x192"` |
  | `assets/images/apple-touch-icon.png`（180×180・不透明） | iOS のホーム画面。OS が角を丸めるので、角丸にせず全面を塗る | `rel="apple-touch-icon"` |
- **Rationale**: SVG はサイズを選ばず鮮明で、`prefers-color-scheme` を中に書けるので FR-003 のダーク対応を 1 ファイルで満たせる。ただし SVG 非対応の環境と iOS のために PNG・ICO が必要。ホーム画面では余白を含めて切り取られない（FR-002）ように、`apple-touch-icon` は全面を塗り、マークを中央の 70% に収める。
- **Alternatives**: `site.webmanifest` + maskable アイコン → PWA の入口であり MVP の対象外。Android の Chrome は manifest がなくても 192px の PNG を使う。

## R3: 共有用画像

- **Decision**: 1200×630 の PNG を固定 1 枚（`assets/images/og-image.png`、300KB 未満）。内容はマーク・「UMIYOMI」・キャッチコピー「風・波・うねりの予報を、出航前に。」。端から 10% の余白を取り、LINE・X・Slack の切り取りに耐える。`og:image:width/height/alt` を併記する。Twitter Card は `summary_large_image`。
- **Rationale**: 1.91:1 は Facebook・LINE・Slack・X の大きな画像の共通の最大公約数。PNG は文字が滲まない。画像内のキャッチコピーに断定を含めない（「出航前に」は確認のタイミングを言うだけ）。
- **Alternatives**: SVG の OG 画像 → 多くのクローラーが非対応。地点ごとの動的画像 → spec の Assumptions で対象外。

## R4: 文言

- **Decision**: contracts/web-ui.md の表のとおり。共通の説明文は「緯度・経度を入力すると、風・波・うねりの時間別予報を確認できます。出航の判断には、気象庁などの警報・注意報も確認してください。」
- **Rationale**: 予報を確認するサービスであることと、公式情報の確認の案内だけを述べる（FR-008）。最終更新日時・数値を載せない（クローラーのキャッシュに古い値が残るため）。予報画面の説明文も固定にして、地点の数値を含めない。
- **Alternatives**: 予報画面の説明に「波高 1.2m」などを入れる → 古い値が長く残り、判断を誤らせうる（原則 IV）。

## R5: 検索エンジンへの登録

- **Decision**: `base.html.twig` の `robots` ブロックの既定を `noindex` にし、`home/index.html.twig` だけ空にして上書きする（meta 自体を出さない）。予報・フィードバック案内・エラー画面は既定の `noindex` のまま。OG 情報は全画面で出す（FR-011）。
- **Rationale**: 既定を「登録しない」にしておくと、新しい画面を足したときに意図せず公開されない。`robots.txt` で Disallow にはしない（deploy の nginx 設定のコメントと同じ理由：クローラーが `noindex` を読めなくなる）。
- **注意**: 現在の本番の nginx は `X-Robots-Tag: noindex, nofollow` を全体に付けており、公開するときに運営者が消す手順になっている（`deploy/README.md`）。その行がある間は、トップの `index` は効かない。この機能はその設定を変更せず、README に「消すとトップだけ登録される」ことを追記する。
- **Alternatives**: HTTP ヘッダー（`X-Robots-Tag`）をアプリから返す → nginx との二重管理になる。

## R6: 画像の完全なアドレス

- **Decision**: `twig.yaml` の globals に `site_origin: '%env(DEFAULT_URI)%'` を足し、テンプレートで `site_origin|trim('/', 'right') ~ asset('images/og-image.png')` とする。
- **Rationale**: `DEFAULT_URI` は本番で必須（`compose.prod.yml` の `:?`）として設定済みで、新しい設定項目が要らない（YAGNI）。リクエストの Host から作る `absolute_url()` は、信頼するプロキシの設定ミスや不正な Host ヘッダーで、他人のドメインを OG 画像に埋め込ませる経路になりうる（Security）。
- **Trade-off**: 開発の `DEFAULT_URI=http://localhost` はポートがなく、開発サーバー（:8000）では画像のアドレスが食い違う。共有プレビューは本番でしか使わないので許容する（spec Assumptions）。
- **Alternatives**: `absolute_url(asset(...))` → 上記の理由で却下。`og:url` は付けない（クエリつきの URL を二重に管理しない。各サービスは取得した URL を使う）。

## R7: エラー画面

- **Decision**: `templates/bundles/TwigBundle/Exception/error.html.twig` を追加し、`base.html.twig` を継承する。本文は「ページが見つかりません／エラーが発生しました」とトップへのリンクだけ。タイトルは「エラー | UMIYOMI」。エラーの詳細・入力・例外メッセージは出さない（FR-010）。dev の例外画面（`error_page`・`exception_full`）は変更しない。
- **Rationale**: 現状は Symfony 既定のエラー画面でアイコンも OG もなく、FR-001・SC-001 を満たせない。TwigBundle の上書きテンプレートは Symfony の標準の仕組みで、設定が要らない。404・500 は `base.html.twig` のフッター（`feedback_form`）などのグローバルに依存するので、グローバルが使えない 500 のときに描画が壊れないよう、`error.html.twig` では `base.html.twig` を使うが、test で 404 と 500 の両方を確認する。
- **Note**: 予報の失敗（422・429・503）は `forecast/index.html.twig` で描画されるため、既に base 経由でメタ情報が付く。

## R8: 画像とアイコンの作り方

- **Decision**: `icon.svg` と OG 画像の元の SVG を手書きでリポジトリに置き、`scripts/build-icons.mjs` が既存の e2e イメージの Chromium（Playwright）で PNG に書き出す。`favicon.ico` は PNG（32px）を ICO のヘッダーで包むだけの数十行を同じスクリプトに書く。書き出した PNG・ICO は**コミットする**。スクリプトは開発時に手で実行するもので、`composer check`・CI・本番イメージには入れない。
- **Rationale**: ラスタライズのための追加パッケージ（ImageMagick・sharp など）を入れずに済む。日本語の文字は Chromium が OS のフォントで描くので、スクリプトは Docker の e2e イメージ内で実行して結果を固定する。成果物がコミットされるので、本番のビルドは変わらない。
- **Alternatives**: デザインツールで手作業 → 再現できず、差し替え時に面倒。実行時に生成 → 動的画像は対象外で、実行時の依存が増える。

## R9: 配信とキャッシュ

- **Decision**: アイコン・画像は AssetMapper の `asset()` で参照する。本番は `asset-map:compile` 済みで、ファイル名にハッシュが付くので長期キャッシュできる。`/favicon.ico` は固定パスなのでブラウザの既定のキャッシュに任せ、`<link rel="icon" href="/favicon.ico">` を併記する。
- **Rationale**: ハッシュ付きの URL なら、差し替え後は新しい URL に切り替わる（spec Edge Cases）。静的ファイルの配信は FrankenPHP が行い、Symfony の Controller・回数制限を通らない（FR-012）。
- **検証**: `scripts/verify-prod.sh` に、トップの HTML から OG 画像・アイコンの URL を取り出して 200 と `Content-Type: image/*` を確認する処理を足す（既存の CSS・JS の確認と同じ書き方）。

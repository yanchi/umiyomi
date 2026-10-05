# Research: フィードバック導線

**Feature**: 004-feedback-channel | **Date**: 2026-10-05

spec の Clarifications で、送り先（外部フォーム）・導線の形（フッター → 案内画面 → 新しいタブでフォーム）・事前入力・受け取る値の形は決まっている。
ここでは、それを既存の Symfony + Twig の構成にどう載せるかを決める。Technical Context に NEEDS CLARIFICATION は残っていない。

## R1. 層の置き場所

- **Decision**: すべて `Presentation/Web` に置く（Controller・Input・ViewModel と、フォームの URL を組み立てる `Presentation/Web/Feedback`）。Domain・Application・Infrastructure は変更しない。UseCase も作らない
- **Rationale**: フィードバックの内容を UMIYOMI は受け取らず（FR-001）、海況の判断も外部 API もない。案内画面がするのは「Query の検証 → 表示用の文言とリンクを作る」だけで、Domain の概念ではない。`HomeController` と同じく UseCase なしで描画する。
  アプリ化のときは、アプリ側でフォームの URL を開けばよく、サーバーの UseCase を共有する必要がない
- **Alternatives considered**:
  - `Application/Feedback` に UseCase を置く：中身が URL の組み立てだけで、Twig 以外の利用者もいない。原則 I（YAGNI）に反する
  - Infrastructure に「フォームサービスの Provider」を置く：外部へのリクエストは発生しない（ブラウザが開くだけ）ので Port にする理由がない

## R2. 外部フォームの設定と、事前入力の欄の数

- **Decision**: 環境変数を 2 つにする。`FEEDBACK_FORM_URL`（フォームを開く URL。`https://` で始まり `#` を含まない）と
  `FEEDBACK_FORM_PREFILL_FIELD`（事前入力に使う Query パラメータ名。Google フォームなら `entry.123456789`）。
  地点・最終更新日時・入力の文字列は、**1 つの欄**に 1 行の文章として入れる（[data-model.md](data-model.md) の「事前入力の文章」）。
  どちらかが空・形が不正なら「未設定」とみなし、フッターのリンクを出さず、`/feedback` は 404 を返す（FR-012）
- **Rationale**:
  - 欄を「緯度」「経度」「最終更新」「緯度欄の入力」「経度欄の入力」に分けると、設定が 5 つ増え、フォーム側の欄の作成と対応づけの誤りが起きやすい。
    読むのは運営者（人）なので、1 行の文章で十分に再現・調査できる
  - 特定のサービスの API や SDK は使わず「URL の Query に `<欄の名前>=<値>` を足すと事前入力される」ことだけを前提にする。Google フォーム・Tally など多くのサービスがこの形で事前入力できる（spec Assumptions の前提条件）
  - 事前入力ができないと User Story 3 が成り立たないので、欄の名前は URL と同じく必須の設定にする（片方だけの中途半端な状態を作らない）
- **Alternatives considered**:
  - 項目ごとに欄を分ける：上記のとおり設定が増える。集計の必要が出たら、そのときに分ける
  - URL のテンプレート（`...&entry.1={context}`）を 1 つの環境変数にする：文字列置換で URL エンコードの扱いを誤りやすい
  - フォームの URL をコード（定数）に書く：開発環境・CI・本番で出し分けられず、FR-012（未設定ならリンクを出さない）を満たせない

## R3. 事前入力の URL の組み立て

- **Decision**: 設定された URL に、`?` があれば `&`、なければ `?` をつなぎ、`rawurlencode(欄の名前) . '=' . rawurlencode(文章)` を文字列として足す。
  URL を `parse_str` / `http_build_query` で分解・再構築しない
- **Rationale**: PHP の `parse_str` は Query の名前の `.` を `_` に変える（`entry.123` → `entry_123`）ため、Google フォームの事前入力が効かなくなる。
  設定された URL（`usp=pp_url` など運営者が付けた Query）をそのまま残すためにも、文字列で末尾に足すのが確実
- **Alternatives considered**: `http_build_query` で全体を作り直す：上記の `.` の問題と、既存の Query の順序・エンコードが変わる問題がある

## R4. 案内画面が受け取る値（Query）の形と検証

- **Decision**: `GET /feedback` の Query を次の 5 つにする（[contracts/web-ui.md](contracts/web-ui.md)）。

  | パラメータ | 受け付ける形（FR-016） |
  |---|---|
  | `lat` / `lon` | `^-?\d{1,3}\.\d{2}$`（予報画面の URL と同じ正規形）で、緯度 -90〜90・経度 -180〜180 |
  | `updated` | `YYYY/MM/DD HH:mm`。`DateTimeImmutable::createFromFormat('!Y/m/d H:i')` で読み、同じ書式に戻して一致する（2026/02/30 などを除く） |
  | `input_lat` / `input_lon` | 正しい UTF-8 の文字列。制御文字は空白に置き換え、先頭 100 文字（`mb_substr`）まで |

  どの「文脈」として扱うかは、次の順で 1 つに決める。
  1. `lat` と `lon` が両方とも正しい → **予報の地点**（`updated` が正しければ最終更新も使う）
  2. 1 でなく、`input_lat` / `input_lon` の少なくとも一方が空でない → **受け付けなかった入力**
  3. どちらでもない → **文脈なし**（トップ画面から来たときと同じ）

  正しくない値は黙って捨てる。エラーの表示もしない（案内とフォームを開く操作は常に出す）。
  `lat` だけ正しく `lon` が不正、のような片方だけの地点は使わない。`updated` は地点がないときは使わない
- **Rationale**:
  - 案内画面へのリンクは誰でも作れる（spec Clarifications）。範囲内の数値・決まった書式に限れば、任意の文章を「UMIYOMI の案内」に見せかけて表示させることができない。
    入力の文字列だけは任意の文字列を許すしかないので、FR-017 の引用の形で表示し、Twig の自動エスケープに任せる
  - 正規形だけを受け付けるのは、予報画面が作るリンクがいつもこの形であり、003 の表記の読み取り（度分など）を案内画面に持ち込む必要がないため
  - 「予報の地点」と「受け付けなかった入力」が同時に来ることは UMIYOMI のリンクでは起こらない。手で作られた場合に備えて、決まった順で片方だけ使う
- **Alternatives considered**:
  - 003 の `CoordinateQueryParser` で読む：度分・1 行の貼り付けまで受け付けてしまい、FR-016 の「決まった形」より広い
  - 署名（HMAC）付きのリンクにして改ざんを検出する：受け付ける形を限れば被害は「別の地点がフォームに入る」程度で、利用者は送る前に見て消せる。鍵の管理に見合わない
  - 予報画面の文脈をセッションに入れて渡す：MVP はセッションを使っていない。戻るリンクや再読み込みで消える

## R5. フッターのリンクに文脈を渡す方法

- **Decision**:
  - `base.html.twig` のフッターに、`feedback_form.available` のときだけ `<a href="{{ path('app_feedback', feedback_query|default({})) }}">フィードバック</a>` を出す。
    `feedback_form` は Twig の global（`FeedbackFormLink` サービス）
  - 予報画面のテンプレートは、ブロックの外で `{% set feedback_query = page.feedbackQuery %}` として親テンプレートに渡す。
    `feedbackQuery` は `ForecastPageViewModelFactory` が作る（予報あり：`lat`・`lon`・`updated`、取得失敗・回数制限：`lat`・`lon`、入力エラー：`input_lat`・`input_lon`）
  - 案内画面では `{% block footer_feedback %}{% endblock %}` で自分自身へのリンクを消す
- **Rationale**: 地点・最終更新の書式（`%.2f`・`Y/m/d H:i`）は、画面に出している値と同じ所（ViewModel の Factory）で作ると食い違わない。
  Twig の子テンプレートのブロック外の `set` は親テンプレートの描画にも渡る。トップ画面は何もしなくても `{}` になる。
  `strict_variables`（test）でも `default` フィルターは未定義を許す
- **Alternatives considered**:
  - Controller から `feedbackQuery` を別の変数で渡す：`page` と同じ値を 2 か所で渡すことになる
  - JavaScript で表示中の地点を読み取ってリンクを書き換える：JavaScript が使えない環境で US3 が成り立たず、FR-011 の考え方（localStorage・JS に依存しない）からも外れる
  - `Referer` ヘッダーから元の画面を推測する：ブラウザの設定で送られないことがあり、戻るリンク（FR-014）が不安定になる

## R6. 戻るリンク（FR-003、FR-014）

- **Decision**: 文脈ごとに戻り先を決める。予報の地点 → `/forecast?lat=<lat>&lon=<lon>`、受け付けなかった入力 → `/forecast?lat=<input_lat>&lon=<input_lon>`（同じ 422 の画面が開く）、文脈なし → `/`。
  文言は「予報画面に戻る」「入力画面に戻る」「トップ画面に戻る」
- **Rationale**: 予報画面の URL は `lat`・`lon` だけで決まる（001・003）ので、同じ地点の予報画面に戻せる。予報はキャッシュから返るので、戻っても外部 API への問い合わせは通常増えない。
  お気に入りは localStorage にあり、画面の移動で消えない（FR-003）
- **Alternatives considered**: `history.back()`：JavaScript が必要。新しいタブで案内画面を開いた場合などに戻り先がない

## R7. 外部フォームを開くリンク（FR-014、FR-018）

- **Decision**: `<a href="…" target="_blank" rel="noopener noreferrer">` にする。加えて案内画面の `<head>` に `<meta name="referrer" content="no-referrer">` と `<meta name="robots" content="noindex">` を出す
- **Rationale**: `noreferrer` で案内画面のアドレス（地点・入力の文字列を含む）が `Referer` として外部サービスへ渡らない（FR-018）。`<meta>` は、リンクの属性を将来誤って消したときの二重の備え。
  `noopener` で開いたフォーム側から元のタブを操作させない（新しいタブでフォームを閉じても案内画面が残る、US1 の 6）。
  案内画面の URL は任意の値を含みうるので、検索エンジンに載せない
- **Alternatives considered**: サイト全体に `Referrer-Policy: no-referrer` を付ける：Open-Meteo へのリンクなど他の画面にも影響する。必要になったら別途検討する。
  サーバーでリダイレクトしてフォームを開く（`/feedback/open`）：ブラウザの新しいタブで開くだけで足り、ルートを増やす理由がない

## R8. テストで「未設定」の状態を作る方法

- **Decision**: `.env.test` に例の値（`https://forms.example.test/feedback?usp=pp_url`、`entry.1000`）を置く。未設定の Functional Test では、`createClient()` の前に `$_ENV` / `$_SERVER` の 2 つの変数を空にし、`tearDown` で戻す
- **Rationale**: `%env()%` は実行時に読まれるので、コンテナを作り直さずに未設定を再現できる。テスト用の別の環境（`APP_ENV`）を増やさない
- **Alternatives considered**: テストコンテナで `FeedbackFormLink` を差し替える：Twig の global として先に生成されると差し替えが効かない

## R9. 本番の設定

- **Decision**: `compose.prod.yml` の `environment` に `FEEDBACK_FORM_URL: ${FEEDBACK_FORM_URL:-}` と `FEEDBACK_FORM_PREFILL_FIELD: ${FEEDBACK_FORM_PREFILL_FIELD:-}` を足し、
  `deploy/.env.production.example` に説明付きで空の行を足す。未設定でも起動できる（`:?` にしない）。
  `scripts/verify-prod.sh` に「未設定ではフッターにリンクがなく、`/feedback` が 404」の確認を 1 つ足す
- **Rationale**: フォームの用意（運営者の作業）とデプロイを切り離す。フォームの用意前にデプロイしても、リンク先のない導線は出ない（FR-012）。
  `.env` に空の既定値を置くので、Symfony の「環境変数がない」エラーにもならない
- **Alternatives considered**: 必須（`:?`）にする：フォームを用意するまでデプロイできなくなる

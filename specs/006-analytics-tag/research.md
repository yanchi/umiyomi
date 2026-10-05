# Research: アクセス解析の導入

Technical Context に NEEDS CLARIFICATION は残っていない（計測サービス・計測範囲・緯度経度の扱い・同意バナー・オプトアウトは spec の Clarifications で決定済み）。以下は設計上の判断の記録。

## R1: 計測タグの読み込み方（インラインの gtag スニペットを使うか）

- **Decision**: Google が案内するインラインの `<script>` スニペットは使わない。`base.html.twig` は計測の設定を `<meta name="umiyomi-analytics" data-*>` として出すだけにし、既存の `app.js`（ES Module）から `assets/analytics/analytics.js` が読み取って、条件を満たすときだけ `gtag.js` を `async` の `<script>` として差し込む。`window.dataLayer` と `gtag()` もこのモジュールで定義する。
- **Rationale**:
  - 「計測しない」にしたブラウザで読み込みそのものを止める（FR-014・SC-008）には、読み込みの前に localStorage を見る必要がある。HTML に直接 `<script src=gtag.js>` を書くと、止める前に通信が始まる。
  - ES Module は defer 相当で実行され、`gtag.js` は `async` で差し込むので、HTML の解析・表示を待たせない（FR-007・SC-005）。
  - インラインスクリプトを増やさないので、将来 CSP を入れるときに `unsafe-inline` や nonce を求められない。
  - JavaScript が無効なら何も読み込まれず、予報の閲覧はサーバー描画のまま動く（FR-008）。
- **Alternatives**:
  - Google のスニペットをそのまま `<head>` に置く → オプトアウトの判定前に読み込みが始まる。インラインスクリプトが増える。
  - Google タグ マネージャー（GTM）→ 管理画面から任意のタグを足せてしまい、送る情報をコードレビューで固定できない（FR-004・FR-005 の担保が崩れる）。
  - サーバー側から Measurement Protocol で送る → 端末・流入元・再訪の識別がブラウザの Cookie に依存するため、Clarifications の「Cookie で利用者を識別する方式」と合わない。

## R2: 計測の設定（環境変数と有効・無効の判定）

- **Decision**: 環境変数 `GA_MEASUREMENT_ID` を新設する。`App\Presentation\Web\Analytics\AnalyticsTag`（readonly クラス）が値を受け取り、`^G-[A-Z0-9]{4,20}$` に一致するときだけ有効とする。Twig の global `analytics` として全画面から参照する（004 の `feedback_form` と同じ流儀）。`.env`・`.env.test`・`compose.prod.yml`（`${GA_MEASUREMENT_ID:-}`）・`deploy/.env.production.example` の既定は空。
- **Rationale**:
  - FR-006：未設定・不正な値なら `<meta>` を出さず、JavaScript も何も読み込まない。形式を検査するのは、値を `<meta>` 経由で外部の URL（`gtag/js?id=`）に組み込むため、想定外の文字列を通さないため。
  - 開発・テスト（Functional・e2e の既定の app-e2e）・`scripts/verify-prod.sh` はすべて空のまま動くので、本番の計測データに混ざらない。
  - `:?` にしないのは、計測 ID の発行とデプロイを切り離すため（004 の `FEEDBACK_FORM_URL` と同じ）。
- **Alternatives**: `APP_ENV=prod` のときだけ有効にする → `verify-prod.sh` が prod で動くため、本番イメージの起動確認から送信してしまう。ID をコードに直書きする → 環境ごとに切り替えられない。

## R3: 画面の種類の区別と、送るアドレス（page_location）の組み立て

- **Decision**: GA4 が既定で送る `page_location`（現在のアドレス全体）を使わず、サーバーが組み立てた値で上書きする。各画面は Twig 変数 `analytics_page`（`{screen, path}`）を決め、`base.html.twig` がそれを `<meta>` の `data-screen`・`data-path` に出す。JavaScript は `location.origin + path` を `page_location` とし、`screen_type` を page_view のパラメーターに付ける。

  | 画面 | `screen_type` | `path`（クエリはここに書いたものだけ） |
  |---|---|---|
  | トップ | `home` | `/` |
  | 予報表示（新しい予報・古い予報の代替表示） | `forecast` | `/forecast?lat={正規化済み}&lon={正規化済み}` |
  | 予報取得失敗 | `forecast_unavailable` | `/forecast?lat=…&lon=…` |
  | 回数制限 | `rate_limited` | `/forecast?lat=…&lon=…` |
  | 入力不正 | `invalid_input` | `/forecast` |
  | フィードバック案内 | `feedback` | `/feedback` |
  | 外部送信の案内 | `external_transmission` | `/external-transmission` |
  | エラー（404 など） | `error` | リクエストのパス（クエリを除く） |

- **Rationale**:
  - FR-005：入力不正の画面（`/forecast?lat=<入力文字列>`）・フィードバック案内（`?input_lat=…`）は、アドレスに利用者の入力文字列が入る。既定の `page_location` を使うと、それがそのまま送られる。サーバーが「送ってよいもの」だけで組み立てれば、除去漏れの心配がない（許可リスト方式）。
  - 予報の 3 状態（表示・取得失敗・回数制限）は、緯度・経度が範囲内の数値として受け付けられた後の結果なので、正規化済みの値を含めてよい（FR-005）。`ignored` パラメーター（003）は計測に不要なので入れない。
  - 予報画面の `screen_type` の判定は `ForecastPageViewModelFactory`（Unit Test 済みの既存の変換）で行い、ViewModel に `analyticsScreen` と `analyticsQuery` を足す。Twig に分岐を書かない（Controller・Twig にロジックを持たせない原則）。
  - エラー画面でパスを残すのは、壊れたリンク（古い共有 URL など）を把握するため。フォームは GET のクエリで送るので、入力文字列はクエリにしか入らない。クエリは捨てる。
  - `screen_type` を別パラメーターにするのは、`page_location` だけでは「予報表示」と「取得失敗」を区別できない（同じアドレス）ため（FR-002・spec の User Story 1 の 2）。GA4 の管理画面でイベントスコープのカスタムディメンションとして登録する（quickstart）。
- **Alternatives**:
  - ブラウザ側で現在のアドレスからクエリを取り除く（拒否リスト方式）→ 入力不正の画面と正常な予報画面の区別がブラウザ側にないので、緯度・経度を残すか捨てるかを決められない。
  - 全画面で仮想パス（`/_screen/forecast` など）を使う → GA4 の標準のページレポートが実際の URL と対応しなくなる。
  - 予報画面の緯度・経度をカスタムパラメーター（`latitude`・`longitude`）で送る → `page_location` で地点ごとの閲覧数が見られるので不要（YAGNI）。

## R4: 参照元（page_referrer）と、後続のイベントに付くアドレス

- **Decision**: `gtag('config', id, {...})` に `page_location`・`page_referrer` を渡す。`page_referrer` は `document.referrer` を次の規則で整える：同じオリジンなら `origin + pathname`（クエリを捨てる）、別オリジンならそのまま、空なら送らない。規則は DOM に依存しない純粋関数（`analytics-rules.js`）にし、`node --test` でテストする。
- **Rationale**:
  - GA4 は次の画面の page_view に直前のアドレス（`document.referrer`）を付ける。入力不正の画面から「トップへ戻る」を押すと、入力文字列を含むアドレスが referrer として送られてしまう（FR-005・SC-002）。
  - 同じオリジンの referrer を落とすと流入元の分析には影響しない（流入元は別オリジンの referrer で判定される）。予報画面から予報画面への referrer の緯度・経度も捨てるが、地点の分析は page_location で足りる。
  - 別オリジンの referrer は、ブラウザ既定の `strict-origin-when-cross-origin` によりオリジンだけになっているのが普通で、UMIYOMI の入力は含まれない。
  - `config` に渡した値は、同じページで後から送るイベント（お気に入りの保存など）にも使われる。イベントの記録に入力文字列入りのアドレスが付くことも防げる。
- **Alternatives**: サイト全体に `<meta name="referrer" content="strict-origin">` を置く → 同じオリジン間の referrer もオリジンだけになり、GA4 の referrer 以外（今後のアクセスログ等）にも影響が広がる。GA に渡す値だけを直す方が影響範囲が狭い。

## R5: 操作の記録（イベント）

- **Decision**: 次の 4 種類だけを、許可リストで送る。名前は GA4 の命名規則（英小文字・アンダースコア）に従う。

  | 操作 | イベント名 | パラメーター | 送る場所 |
  |---|---|---|---|
  | お気に入りの保存 | `favorite_save` | なし | `favorites-ui.js`：保存に成功した後 |
  | お気に入りの削除 | `favorite_delete` | なし | `favorites-ui.js`：削除が `removed` になった後（キャンセル・失敗では送らない） |
  | 現在地ボタン | `current_location` | `result`: `success` \| `failure` | `coordinate-input-ui.js`：取得の結果が出た後（`busy` は送らない） |
  | フィードバックフォームを開く | `feedback_form_open` | なし | 案内画面のリンクに `data-analytics-click="feedback_form_open"` を付け、`analytics.js` がクリックを拾う |

  `analytics.js` は `track(name, params)` を公開し、許可リストにない名前・パラメーターは送らない（`analytics-rules.js` の純粋関数で判定し、`node --test` でテストする）。計測が無効・オプトアウト中なら何もしない。
- **Rationale**:
  - FR-004：地点・名前・入力文字列を引数に取れない形にする。呼び出し側が誤って `{latitude}` を渡しても許可リストで落ちる（SC-002 の担保をテストできる形にする）。
  - お気に入りの名前の変更・削除のキャンセルは spec の対象外なので送らない。
  - 「現在地」の失敗理由（拒否・タイムアウト・取得不可）は spec が求めていないので、成功・失敗の 2 値にまとめる。
  - フィードバックのリンクは `target="_blank"` で今の画面が残るので、クリック時に送っても取りこぼさない。宣言的な data 属性にすると、テンプレートの変更だけで対象を追加できる（ただし名前は許可リストで縛る）。
- **Alternatives**: UI モジュールから `CustomEvent` を投げ、`analytics.js` が拾う → モジュール間の結び付きは弱くなるが、イベント名が文字列で散らばり、許可リストのテストと二重管理になる。直接 `track()` を呼ぶ方が単純。

## R6: GA4 側の設定とコード側の固定値（広告機能・拡張計測）

- **Decision**:
  - コード側：`gtag('config')` に `allow_google_signals: false`、`allow_ad_personalization_signals: false` を常に付ける。
  - 管理画面側（運営者。quickstart の手順に書き、公開前のチェックリストにする）：Google シグナル無効、広告リンク（Google 広告など）を作らない、データ保持期間 2 か月、**拡張計測は「ページビュー」以外をすべて無効**（スクロール・離脱クリック・サイト内検索・フォームの操作・動画・ファイルのダウンロード・ブラウザの履歴に基づくページ変更）、カスタムディメンション `screen_type`・`result` の登録。
- **Rationale**:
  - FR-003：広告関連の機能を使わない。コードと管理画面の両方で止める。
  - **拡張計測の「離脱クリック」は必ず無効にする**。フィードバック案内画面の外部フォームへのリンク（004）は、事前入力のため URL に緯度・経度や**受け付けなかった入力文字列**を含む。離脱クリックが有効だと、その URL が `link_url` として送られ、FR-005・SC-002 に反する。gtag の API で拡張計測をコードから止める公式の手段はないため、管理画面の設定で止め、公開前に DebugView で確認する（quickstart）。
  - 「サイト内検索」「フォームの操作」も、クエリやフォームの情報を送りうるので止める。「履歴に基づくページ変更」は、画面遷移がすべて通常の読み込みなので不要で、二重計上の原因になる。
- **Alternatives**: フィードバックのリンクを、UMIYOMI 内の転送用 URL 経由にする → 004 の画面・URL 設計の変更になり、この機能の範囲を超える。管理画面の設定で止めるのが最小。
- **残るリスク**：運営者が後から管理画面で拡張計測を有効にすると、コードの変更なしに送信内容が増える。deploy/README の運用メモと quickstart のチェックリストに明記し、人間のレビューで扱う。

## R7: 「このブラウザでは計測しない」（オプトアウト）

- **Decision**:
  - localStorage のキー `umiyomi.analytics.optOut` に文字列 `"1"` を保存する。値がなければ「計測する」（FR-014 の既定）。お気に入りのキー `umiyomi.favorites`（002）とは別にする（FR-011）。
  - 読み込みの判定：`analytics.js` は、`<meta>` があり、かつオプトアウトの値が `"1"` でないときだけ `gtag.js` を差し込む。localStorage が使えない（例外になる）環境では、保存された選択を読めないので**既定どおり計測する**（ブラウザ側の拒否やトラッキング防止はそのまま効く）。
  - 外部送信の案内画面に切り替えを置く。状態は 3 つ：「計測しています」（ボタン：このブラウザでは計測しない）、「計測していません」（ボタン：計測を再開する）、「切り替えを保存できません」（localStorage が使えない。Google のオプトアウト アドオンの案内を出す）。JavaScript が無効なら切り替えは出さず、アドオンの案内だけが見える。
  - 案内画面で「計測しない」に切り替えたら、その画面ですでに読み込まれた gtag も止めるため `window['ga-disable-<ID>'] = true` を設定する（Google が案内する無効化のフラグ）。再開は「次に開いた画面から」（spec の User Story 3 の 4）なので、その場では読み込まない。
  - 既存の `_ga` Cookie は削除しない。
- **Rationale**:
  - SC-008：止めたブラウザでは `gtag.js` の取得そのものが起きないので、通信は 0 件になる。
  - 判定と保存は DOM に依存しない純粋関数（`readOptOut(storage)`・`writeOptOut(storage, value)`）にし、`node --test` で例外・不正値を含めてテストする（002 の `favorite-store.js` と同じ流儀）。
  - Cookie を消さないのは、送信が止まれば識別子は使われず、GA4 が付ける Cookie 名（`_ga_<コンテナ ID>`）の削除を正しく行うにはドメイン属性の推測が要り、失敗しても利用者に見えないため。spec の要求（読み込み・送信をしない）は満たす。
- **Alternatives**: Cookie にオプトアウトを保存する → サーバーへ毎回送られ、FR-011 の「サーバーには送らない」に反する。Consent Mode（`gtag('consent', 'default', {analytics_storage: 'denied'})`）で止める → Cookie なしの通信（ping）が残り、SC-008 の「通信 0 件」を満たさない。

## R8: 外部送信の案内画面

- **Decision**: 新しい画面 `GET /external-transmission`（ルート名 `app_external_transmission`）。`Presentation/Web/Controller/ExternalTransmissionController` が固定のテンプレート `external_transmission/index.html.twig` を返す。全画面のフッターに「外部送信について」のリンクを常に出す（計測の設定がない環境でも出す）。記載内容は contracts/web-ui.md。計測の設定がない環境では「この環境では計測を行っていません」を表示し、切り替えは出さない（FR-014 の後段）。`robots` は base の既定（noindex）のまま。
- **Rationale**:
  - FR-009・SC-006：フッターから 1 回の操作でたどり着ける。
  - 電気通信事業法の外部送信規律の公表事項（送信先・送信される情報・利用目的）に、オプトアウトの方法を加える。
  - 画面の文言は固定で、利用者の入力も予報値も扱わない。ViewModel は作らず、Twig の global `analytics` の有効・無効だけで表示を分ける（005 の R1 と同じ判断）。
  - URL を `/privacy` にしないのは、プライバシーポリシー全体の整備が対象外（spec の Assumptions）で、将来の `/privacy` と役割が衝突しないようにするため。
- **Alternatives**: フッターに説明を直接書く → オプトアウトの切り替えと説明が全画面に載り、スマホで本文より長くなる。モーダル → JavaScript 無効の環境で読めない。

## R9: テストの分け方

- **Decision**:
  - **PHPUnit Unit**：`AnalyticsTagTest`（空・形式違反・正しい ID）、`ForecastPageViewModelFactoryTest` に `analyticsScreen`・`analyticsQuery` の検査を追加（4 状態＋入力不正）。
  - **PHPUnit Functional**：`AnalyticsMarkupTest`（ID を設定した状態で全画面の `<meta name="umiyomi-analytics">` の `data-screen`・`data-path` を検査。入力不正・フィードバック案内に特殊文字や長い文字列を渡しても `data-path`・`<head>` に混入しないこと）、`AnalyticsUnconfiguredTest`（`.env.test` の既定＝空で `<meta>` が出ず、`googletagmanager` の文字列が HTML に現れないこと、案内画面に切り替えが出ないこと）、案内画面とフッターのリンクの検査、案内の文言に禁止語（「安全です」等）がないこと（FR-010）。ID の切り替えは `FeedbackUnconfiguredTest` と同じく `$_ENV`/`$_SERVER` を作り替える。
  - **node --test**：`analytics-rules.test.js`（referrer の整形・イベントの許可リスト・オプトアウトの読み書き・例外を投げる storage）。
  - **e2e（Playwright）**：`compose.yaml` に計測 ID を設定した `app-e2e-analytics`（`GA_MEASUREMENT_ID=G-E2ETEST000`）を profile `e2e` で足す。`analytics.spec.js` は Google のドメインへの通信をすべて Playwright でスタブ・遮断し（外へは出ない）、(1) 既定の app-e2e で全画面を開いても Google への通信が 0 件、(2) app-e2e-analytics で `dataLayer` に入った `page_location`・`page_referrer`・イベントに入力文字列・お気に入りの名前・緯度経度（イベント側）が含まれない、(3) 「計測しない」に切り替えると以降の画面で `gtag.js` の取得が 0 件、再開すると次の画面から取得される、(4) Google への通信を遮断しても予報・お気に入り・入力補助・フィードバック導線が動く、を確かめる。
  - **verify-prod.sh**：本番イメージを ID なしで起動し、HTML に `umiyomi-analytics` が出ないこと、`/external-transmission` が 200 を返すことを確かめる。
- **Rationale**: spec の FR-006（e2e の既定は計測しない）を守るため、既定の app-e2e には ID を入れない。計測の挙動は ID が要るので別サービスに分け、さらに通信をスタブするので本番の計測データにも Google にも届かない。SC-002・SC-003・SC-004・SC-008 を自動テストで確かめられる。
- **Alternatives**: 既定の app-e2e に ID を入れて全テストで通信を遮断する → FR-006 の「e2e は既定で計測しない」の文言に反し、遮断を忘れたテストが Google に送る恐れがある。e2e を書かず Functional と node だけにする → オプトアウトで「読み込みが 0 件」になること（SC-008）は実ブラウザでしか確かめられない。

## R10: 共有プレビュー・検索エンジンのクローラー

- **Decision**: 追加の対策はしない。
- **Rationale**: 共有プレビューの取得元（LINE・X・Slack など）と多くのクローラーは JavaScript を実行しないため、`gtag.js` を読み込まない。JavaScript を実行する主要な検索エンジンのクローラー・既知のボットは、GA4 が自動で除外する。spec の Edge Cases の「大きく水増しされない」を満たす。
- **Alternatives**: User-Agent でサーバー側から `<meta>` を出し分ける → 判定の保守が必要になり、GA4 の自動除外と重複する。

## R11: ブラウザの戻る・進む（bfcache）で復元された画面

- **Decision**: bfcache から復元された画面は、閲覧として数えない。`pageshow` で page_view を送り直す処理は作らない。
- **Rationale**:
  - 復元された画面では ES Module が再実行されないため、page_view は送られない。新しく読み込んだ画面（地点を変えて開く・お気に入りから開く・フォームで送る）は、すべて通常の読み込みなので 1 回ずつ数えられ、spec の Edge Cases の「アドレスが変わるたびに二重・欠落なく」はこの範囲で満たす。
  - 戻る操作で同じ画面をもう一度見ることは、新しい予報を確かめる操作ではない。数えなくても、MVP 後に何を作るかの判断（spec の背景）には影響しにくい。
  - 送り直しを作ると、bfcache が効かない環境（通常の再読み込みになる）との違いで二重に数えないための判定が必要になる。GA4 の「ブラウザの履歴イベントに基づくページの変更」は二重計上を避けるため無効にしている（research R6）。
- **Alternatives**: `pageshow` の `event.persisted` が true のときに `gtag('event', 'page_view')` を送る → 実装は小さいが、上記の判定とテストが増える。必要になったら、`analytics.js` に足すだけで後から追加できる。

# Implementation Plan: アクセス解析の導入

**Branch**: `006-analytics-tag` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/006-analytics-tag/spec.md`

## Summary

Google アナリティクス（GA4）で、全画面の閲覧（画面の種類つき）と 4 種類の操作（お気に入りの保存・削除、現在地ボタンの成功・失敗、フィードバックのフォームを開く）を計測する。
あわせて、全画面のフッターから開ける「外部送信について」の案内画面（`/external-transmission`）を作り、送信先・送る情報・目的・止める方法と、このブラウザでの計測を止める切り替えを置く。

計測の設定は環境変数 `GA_MEASUREMENT_ID` だけで切り替え、未設定なら何も読み込まない。
`base.html.twig` は `<meta name="umiyomi-analytics">` に測定 ID・画面の種類・送ってよいアドレスを出すだけで、インラインスクリプトは書かない。既存の `app.js` から読み込む `analytics.js` が、オプトアウトを確かめてから `gtag.js` を非同期で差し込む。
GA4 が既定で送る現在のアドレス・直前のアドレスには入力文字列が入りうるため、**サーバーが許可リスト方式で組み立てたアドレス**（予報画面だけ正規化済みの緯度・経度を含む）と、クエリを捨てた referrer で上書きする。操作のイベントも許可リストで名前・パラメーターを縛る。

Domain・Application・Infrastructure は変更しない。Presentation に `AnalyticsTag`（設定）と `ExternalTransmissionController` を足し、`ForecastPageViewModel` に画面の種類を 2 フィールド足す。サーバー側の保存は作らない。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）、JavaScript（ES2022 の ES Module。ビルドなし）
**Primary Dependencies**: 既存の Symfony 8.1（FrameworkBundle、TwigBundle、AssetMapper）。新しい Composer / npm パッケージ・PHP 拡張は追加しない。ブラウザが実行時に Google の `gtag.js`（`www.googletagmanager.com`）を読み込む（計測が有効な環境だけ）
**Storage**: なし（FR-011）。ブラウザの localStorage に `umiyomi.analytics.optOut`（`"1"`）を追加。お気に入りの形式（002）は変えない
**Testing**: PHPUnit（Unit：`AnalyticsTag`・ViewModel 変換、Functional：全画面の計測の設定・未設定・案内画面）、`node --test`（referrer の整形・イベントの許可リスト・オプトアウトの読み書き）、Playwright（計測 ID 付きの `app-e2e-analytics` を追加。Google への通信はスタブ・遮断）、`scripts/verify-prod.sh`（ID なしで計測タグが出ない）
**Target Platform**: スマホ・PC のブラウザ
**Project Type**: Web サービス（サーバーサイドレンダリング）
**Performance Goals**: 表示の完了を計測の読み込みが待たせない（ES Module ＝ defer 相当、`gtag.js` は `async`）。計測のためにサーバーの処理・外部 API の呼び出しを増やさない
**Constraints**: 入力文字列・お気に入りの内容を送らない（FR-004・FR-005）/ 未設定・オプトアウトで通信 0 件（SC-003・SC-008）/ ブロックされても全機能が動く（FR-007・FR-008）/ 広告機能を使わない（FR-003）/ 断定表現なし（FR-010）/ 同意バナーなし（FR-013）
**Scale/Scope**: PHP 新規 2（`AnalyticsTag`・`ExternalTransmissionController`）と変更 2（`ForecastPageViewModel`・同 Factory）、Twig 新規 1（案内画面）と変更 5（base・home・forecast・feedback・error。お気に入り・入力欄のテンプレートは変更なし）、JS 新規 3（`analytics.js`・`analytics-rules.js`・`analytics-optout-ui.js`）と変更 3（`app.js`・`favorites-ui.js`・`coordinate-input-ui.js`）、CSS 少量、設定（`services.yaml`・`twig.yaml`・`.env`・`.env.test`・`compose.yaml`・`compose.prod.yml`・`deploy/.env.production.example`）、テスト（Unit 2・Functional 3・JS 1・e2e 1）、`verify-prod.sh`・deploy README の追記

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | 計測タグ・固定の案内画面・オプトアウトの切り替えだけ。集計画面・同意管理ツール・A/B テスト・ヒートマップ・GTM は作らない（spec Assumptions、research R1）。テーブル・サーバー側の保存なし。設定項目は測定 ID 1 つだけ。アカウント機能・共有・SNS に触れない |
| II. レイヤー境界と依存方向 | ✅ | 変更は `Presentation/Web`（設定クラス・Controller・ViewModel・Twig）と `assets/` だけ。Controller は固定テンプレートを返すだけ。画面の種類の判定は既存の `ForecastPageViewModelFactory`（ViewModel 変換）で行い、Twig・Controller に分岐を書かない。UseCase・Domain は触らない。Deptrac の設定は変更しない |
| III. 外部海況 API の隔離とキャッシュ | ✅ | 海況 API・キャッシュ・回数制限の経路を通らない（FR-012）。案内画面は UseCase を呼ばない。ブラウザから外部へ出るのは GA4 への計測だけで、海況 API は引き続きサーバーからのみ呼ぶ |
| IV. 判断材料の提示と断定の禁止 | ✅ | 案内画面の文言に断定を含めない（FR-010）。Functional Test で禁止語を検査する。予報の表示・最終更新日時・免責文言は変更しない。海況の判定ロジックは変更しない |
| V. 重点領域のテスト | ✅ | ViewModel 変換（`analyticsScreen`・`analyticsQuery`）を Unit Test。座標の扱い（正規化済みの値だけを送る・入力文字列を送らない）を Functional・e2e で確認。Functional で HTTP → Controller → 描画結果を確認。外部 API は呼ばず、e2e でも Google への通信はスタブ・遮断する |
| ワークフロー（人間のレビュー） | ✅ 承認済み（2026-10-05） | **Security**（外部への情報送信・Cookie・オプトアウト）と**画面・URL 設計**（`/external-transmission`、フッターのリンク）に該当する。下記「レビューで判断してほしい点」は、7 項目とも推奨どおりで承認された（4. は条件付き）。Domain 設計・DB Schema・外部海況 API Provider・課金・海況判断ロジックの変更はない |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。e2e 用のサービスを 1 つ足す（`app-e2e-analytics`）が、FR-006（e2e は既定で計測しない）を守ったまま SC-008 を実ブラウザで確かめるための最小の追加（research R9）。

### レビューで判断してほしい点

各項目の「推奨」は plan 作成者の提案。**2026-10-05 のレビューで、7 項目とも推奨どおりに決定した**（4. の条件 (a)・(b) を含む）。

1. **送るアドレスの組み立て方**（research R3・R4）：GA4 既定の現在アドレス・直前アドレスを使わず、サーバーが画面ごとに許可した値（予報画面だけ正規化済みの `lat`・`lon`）と、UMIYOMI 内はクエリを捨てた referrer で上書きする。エラー画面はパスを送り、クエリは捨てる
   - **推奨**：案のとおり承認。許可リスト方式なので、新しい画面やクエリが増えても入力文字列が既定で送られることはない
   - 別案：エラー画面もパスを捨てて固定値（`/`）にする。パスに個人情報を入れたリンクを踏まされる心配を消せる代わりに、壊れたリンクの把握ができなくなる。MVP では不要と判断
2. **画面の種類**（contracts）：`home` / `forecast` / `forecast_unavailable` / `rate_limited` / `invalid_input` / `feedback` / `external_transmission` / `error` の 8 種。古い予報の代替表示（Stale）は `forecast` に含める
   - **推奨**：案のとおり承認。Stale は予報の表を表示している画面なので、利用者から見れば「予報表示」。取得失敗の頻度は Open-Meteo 側の障害の把握が目的で、それは `forecast_unavailable` とアプリのログで足りる
   - 別案：`forecast_stale` を分ける。必要になったら ViewModel の変換を 1 行足すだけで後から追加できる
3. **イベント名**（research R5）：`favorite_save` / `favorite_delete` / `current_location`（`result: success|failure`）/ `feedback_form_open`。許可リスト外は送らない
   - **推奨**：案のとおり承認。GA4 の推奨イベント名（`login`・`search` など）とは重ならない独自名なので、GA4 側で意味を誤って解釈されることもない
4. **GA4 管理画面の必須設定**（research R6、contracts の最後）：**拡張計測の「離脱クリック」を必ず無効にする**。フィードバックのフォームへのリンクの URL（004 の事前入力）に入力文字列が入りうるが、コードからは止められない。運営者の公開前チェックリストに入れ、DebugView で確認する。後から管理画面の設定を変えると送信内容が増えるリスクが残る
   - **推奨**：条件付きで承認。条件は次の 2 つ：(a) deploy/README.md の「公開するとき」に GA4 の必須設定を手順として書く（quickstart への参照だけにしない）、(b) GA4 の管理画面の権限は運営者 1 人（管理者）に限り、他の人には閲覧権限だけを渡す
   - 別案：フィードバックのリンクを UMIYOMI 内の転送用 URL 経由にして、コードで止める。004 の画面・URL 設計が変わるので、この機能では行わない。リスクが受け入れられない場合は、別の機能として切り出す
5. **オプトアウト**（research R7）：localStorage `umiyomi.analytics.optOut = "1"`。localStorage が使えない環境では既定どおり**計測する**（選択を保存できないため。案内画面ではアドオンを案内）。既存の `_ga` Cookie は消さない
   - **推奨**：案のとおり承認。localStorage が使えない環境の多くは、サイトデータ（Cookie を含む）そのものを拒否しており、その場合は GA4 も Cookie を保存できない。同意バナーを出さない方針（FR-013）とも合う
   - 別案：localStorage が使えないときは計測しない。安全側だが、計測できる利用者が減り、どの環境で計測が欠けているかも分からなくなる
6. **案内画面の URL と文言**（research R8、contracts）：`GET /external-transmission`、フッターの「外部送信について」は計測の設定がなくても全画面に出す。文言案は contracts/web-ui.md
   - **推奨**：URL と、フッターに常に出すことは承認。文言は、公開前に運営者が総務省の外部送信規律のガイドライン（公表事項：送信される情報の内容・送信先・利用目的）と照らし合わせて最終確認する。実装は contracts の文言案で進め、確認後の修正はテンプレートの文言だけで済むようにする
7. **e2e の構成**（research R9）：`compose.yaml` に `app-e2e-analytics`（`GA_MEASUREMENT_ID=G-E2ETEST000`）を追加し、Google への通信は Playwright で全てスタブ・遮断する
   - **推奨**：案のとおり承認。既定の app-e2e は計測しないまま（FR-006）で、計測の挙動を確かめるテストだけが ID 付きのサービスを使う。e2e は CI では動かさず手元で実行する（`.github/workflows/ci.yml` に e2e のジョブはない）ので、CI の実行時間は変わらない。手元の e2e が、コンテナ 1 つの起動ぶん（数秒〜十数秒）長くなる

## Project Structure

### Documentation (this feature)

```text
specs/006-analytics-tag/
├── plan.md              # This file
├── research.md          # Phase 0: 判断の記録（R1〜R11）
├── data-model.md        # Phase 1: 設定・画面の計測情報・送る値・オプトアウトの状態
├── quickstart.md        # Phase 1: 自動テスト・手動確認・公開時の手順（GA4 管理画面の設定を含む）
├── contracts/
│   └── web-ui.md        # Phase 1: <meta> の契約・送る値・フッター・案内画面・GA4 管理画面の設定
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code (repository root)

```text
backend/
├── .env / .env.test                          # 変更：GA_MEASUREMENT_ID=（空）
├── config/
│   ├── services.yaml                         # 変更：AnalyticsTag に %env(GA_MEASUREMENT_ID)%
│   └── packages/twig.yaml                    # 変更：global analytics
├── src/Presentation/Web/
│   ├── Analytics/AnalyticsTag.php            # 新規：測定 ID の形式検査と有効・無効
│   ├── Controller/ExternalTransmissionController.php   # 新規：GET /external-transmission
│   └── ViewModel/
│       ├── ForecastPageViewModel.php         # 変更：analyticsScreen・analyticsQuery
│       └── ForecastPageViewModelFactory.php  # 変更：上記の値を決める
├── templates/
│   ├── base.html.twig                        # 変更：<meta name="umiyomi-analytics">、フッターのリンク
│   ├── home/index.html.twig                  # 変更：analytics_page
│   ├── forecast/index.html.twig              # 変更：analytics_page（ViewModel から）
│   ├── feedback/index.html.twig              # 変更：analytics_page、data-analytics-click
│   ├── external_transmission/index.html.twig # 新規：外部送信の案内と切り替え
│   └── bundles/TwigBundle/Exception/error.html.twig    # 変更：analytics_page
├── assets/
│   ├── app.js                                # 変更：initAnalytics・initAnalyticsOptOut を最初に呼ぶ
│   ├── analytics/
│   │   ├── analytics-rules.js                # 新規：referrer の整形・イベントの許可リスト・オプトアウトの読み書き（純粋関数）
│   │   ├── analytics.js                      # 新規：<meta> を読み gtag.js を差し込む、track()、data-analytics-click
│   │   └── analytics-optout-ui.js            # 新規：案内画面の切り替えの表示と操作
│   ├── favorites/favorites-ui.js             # 変更：保存・削除の成功時に track()
│   ├── coordinate-input/coordinate-input-ui.js   # 変更：現在地の成功・失敗で track()
│   └── styles/app.css                        # 変更：フッターのリンク・案内画面
└── tests/
    ├── Unit/Presentation/Web/Analytics/AnalyticsTagTest.php            # 新規
    ├── Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php   # 変更
    ├── Functional/AnalyticsMarkupTest.php                              # 新規
    ├── Functional/AnalyticsUnconfiguredTest.php                        # 新規
    ├── Functional/ExternalTransmissionPageTest.php                     # 新規
    └── JavaScript/analytics-rules.test.js                              # 新規

e2e/tests/analytics.spec.js                   # 新規：送る値・オプトアウト・遮断時の動作
compose.yaml                                  # 変更：app-e2e-analytics（profile e2e）、e2e から参照する URL
compose.prod.yml                              # 変更：GA_MEASUREMENT_ID: ${GA_MEASUREMENT_ID:-}
deploy/.env.production.example                # 変更：GA_MEASUREMENT_ID の説明
deploy/README.md                              # 変更：計測 ID の反映、「公開するとき」に GA4 管理画面の必須設定の手順（レビュー 4. の条件 (a)）
scripts/verify-prod.sh                        # 変更：ID なしで計測タグが出ない・案内画面が 200
```

**Structure Decision**: 既存の Web サービスの構成（`backend/` 配下の Symfony + Twig、AssetMapper の ES Module）にそのまま載せる。計測は Presentation とブラウザ側だけで完結させ、UseCase・Domain に計測の概念を持ち込まない（アプリ化のときは Flutter 側で別の計測を選べる）。
JavaScript は既存の流儀どおり、DOM に依存しない規則（`analytics-rules.js`）と DOM 操作（`analytics.js`・`analytics-optout-ui.js`）に分け、規則を `node --test` でテストする。

## Complexity Tracking

違反なし。

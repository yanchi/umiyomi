# Quickstart: アクセス解析の導入

## 自動テスト

```sh
docker compose exec php composer check                 # PHPStan・Deptrac・lint・PHPUnit・node --test をまとめて
docker compose exec php vendor/bin/phpunit --filter Analytics
docker compose exec php composer test:js                # analytics-rules.test.js を含む
docker compose --profile e2e run --rm --build e2e       # analytics.spec.js を含む（Google への通信はスタブ・遮断）
bash scripts/verify-prod.sh                             # 本番イメージ（ID なし）で計測タグが出ないこと
```

| 確かめること | どこで |
|---|---|
| 測定 ID の形式検査（空・`UA-`・小文字・空白は無効） | `tests/Unit/Presentation/Web/Analytics/AnalyticsTagTest.php` |
| 予報画面の `analyticsScreen`・`analyticsQuery`（4 状態＋入力不正） | `tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` |
| 全画面の `data-screen`・`data-path`、入力文字列が混入しない（FR-002・FR-005） | `tests/Functional/AnalyticsMarkupTest.php` |
| 未設定で `<meta>`・`googletagmanager` が出ない（FR-006・SC-003） | `tests/Functional/AnalyticsUnconfiguredTest.php` |
| 案内画面の内容・フッターのリンク・禁止語なし（FR-009・FR-010） | `tests/Functional/ExternalTransmissionPageTest.php` |
| referrer の整形・イベントの許可リスト・オプトアウトの読み書き | `tests/JavaScript/analytics-rules.test.js` |
| 実ブラウザ：送る値・オプトアウトで通信 0 件・遮断しても全機能が動く（SC-002・SC-004・SC-008） | `e2e/tests/analytics.spec.js` |

## ローカルで計測の動きを見る（任意）

本番の計測データに混ざらないよう、**本番とは別の GA4 プロパティ**（テスト用）の ID を使う。

```sh
echo 'GA_MEASUREMENT_ID=G-テスト用ID' >> backend/.env.local   # .env.local は git に入らない。終わったら消す
```

ブラウザの開発者ツールで、`window.dataLayer` の中身と `google-analytics.com/g/collect` へのリクエストの `dl`（page_location）・`dr`（page_referrer）・`ep.screen_type` を確認する。GA4 の「DebugView」で見るときは、Chrome 拡張「Google Analytics Debugger」を使う。

## 手動確認（人間のレビュー）

1. フッターの「外部送信について」から案内画面を開き、送信先・送る情報・目的・止める方法が読めること、断定的な文言がないこと
2. スマホ幅（375px）で、案内画面とフッターが崩れないこと
3. 案内画面で「このブラウザでは計測しない」→ 別の画面を開き、開発者ツールの Network に `googletagmanager.com`・`google-analytics.com` への通信がないこと。「計測を再開する」→ 次に開いた画面から通信が出ること
4. 広告ブロッカーを有効にした状態で、予報の表示・お気に入りの保存と削除・現在地ボタン・フィードバック導線が動くこと
5. 入力不正の画面（例：`/forecast?lat=北緯二十七度&lon=129.05`）を開き、そこからヘッダーの「UMIYOMI」でトップへ移る・フッターのフィードバック → フォームを開く、まで操作して、`dl`・`dr`・すべてのイベントに入力文字列が含まれないこと
6. 表示の速さ（SC-005）：開発者ツールの Network で、`gtag/js` の取得が `DOMContentLoaded` より後に始まり、表示を待たせていないこと。スマホの回線（Network の「Fast 4G」など）でトップ・予報画面を開き、計測の設定がないときと比べて、表が出るまでの時間に体感できる差がないこと

## 公開するとき（運営者）

1. GA4 のプロパティとウェブのデータストリームを作り、測定 ID（`G-` で始まる）を控える。プロパティの管理者権限は運営者 1 人に限り、他の人には閲覧権限だけを渡す（拡張計測などの設定を変えられる人を絞るため）
2. **GA4 の管理画面の設定**（contracts/web-ui.md の「GA4 管理画面側の設定」）。特に：
   - 拡張計測機能：ページビュー以外をすべて無効（**離脱クリックは必ず無効**。フィードバックのフォームへのリンクの URL に入力文字列が入りうるため）。「ブラウザの履歴イベントに基づくページの変更」も無効
   - Google シグナル無効、広告サービスとのリンクなし、データ保持 2 か月
   - カスタムディメンション（イベントスコープ）`screen_type`・`result` を登録
3. VPS の `/opt/umiyomi/.env.production` に `GA_MEASUREMENT_ID=G-…` を追記し、`docker compose -f compose.prod.yml --env-file .env.production up -d` で反映する（手順は deploy/README.md）
4. 本番で各画面を 1 回ずつ開き、GA4 の「リアルタイム」で `screen_type` ごとに閲覧が出ること（SC-001）。4 種類の操作がイベントとして出ること（SC-007）
5. 本番で手動確認の 5. を行い、`dl`・`dr`・`link_url`（出ていないこと）を確かめる
6. 計測をやめるときは `GA_MEASUREMENT_ID` を空にして再起動する。案内画面は「この環境では現在、計測を行っていません」の表示になる

**拡張計測の設定を後から変えると、コードの変更なしに送る情報が増える。** 設定を変えるときは、外部送信の案内の内容と合っているかを確認すること。

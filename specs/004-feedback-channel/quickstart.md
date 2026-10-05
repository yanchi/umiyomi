# Quickstart: フィードバック導線

**Feature**: 004-feedback-channel | **Date**: 2026-10-05

## 自動テスト

```sh
docker compose up -d
docker compose exec php composer check     # cs・PHPStan・Deptrac・lint・PHPUnit・node --test
bash scripts/verify-prod.sh                # 未設定の本番イメージでリンクが出ず /feedback が 404
```

主に確かめること：

| テスト | 内容 |
|---|---|
| `Unit/Presentation/Web/Input/FeedbackContextParserTest` | 文脈の決まり方（Forecast → RejectedInput → None）、緯度・経度の書式と範囲の境目（90.00 / 90.01 / -180.00）、`updated` の書式と実在しない日付、100 文字の切り詰め（マルチバイト）、制御文字、不正な UTF-8 |
| `Unit/Presentation/Web/Feedback/FeedbackFormLinkTest` | 未設定・`http://`・`#` 付きは利用不可、`?` の有無での連結、`entry.1000` の `.` が残る、日本語・`&`・`=` のエンコード |
| `Unit/Presentation/Web/ViewModel/FeedbackPageViewModelFactoryTest` | 事前入力の文章・案内文・引用・`（空）`・戻り先 |
| `Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest` | 予報あり・代替表示・503・429・422 ごとの `feedbackQuery` |
| `Functional/FeedbackPageTest` | トップ・予報画面（200/503/429/422）のフッターのリンク、案内画面の 3 つの案内がフォームを開く操作より前にあること、事前入力の URL、`target`・`rel`・`meta referrer`、不正な値の無視、`<script>` を含む入力がエスケープされること、予報を取得しないこと（Fake Provider の呼び出し 0 回）、断定表現がないこと |
| `Functional/FeedbackUnconfiguredTest` | 未設定でフッターにリンクがなく、`/feedback` が 404 |

## ブラウザでの確認（開発環境）

開発環境の `.env` では未設定なので、`backend/.env.dev.local`（git に入れない）に試用のフォームを設定する。

```sh
# backend/.env.dev.local
FEEDBACK_FORM_URL="https://docs.google.com/forms/d/e/<フォームID>/viewform?usp=pp_url"
FEEDBACK_FORM_PREFILL_FIELD="entry.<欄のID>"
```

Google フォームの欄の ID は、フォームの編集画面の「事前入力した URL を取得」で表示中の情報の欄に値を入れて取得した URL の `entry.<数字>` で分かる。

1. http://localhost:8000/ を開く → フッターに「フィードバック」がある → 押すと同じタブで案内画面が開き、地点の表示がない
2. 「フォームを開く」→ 新しいタブでフォームが開き、表示中の情報の欄が空。ログインを求められない。返信用の連絡先を空にして送れ、送れたことが表示される
3. `/forecast?lat=27.75&lon=129.05` → フッターの「フィードバック」→ 案内画面に「緯度 27.75・経度 129.05、最終更新 …がフォームに入ります」→ フォームの欄に同じ文章が入っていて、消して送れる
4. フォームのタブを閉じる → 案内画面のタブが残っている → 「予報画面に戻る」で同じ地点の予報画面。お気に入りの一覧が変わっていない
5. 緯度欄に `北緯二十七度` と入れて「予報を表示」→ 422 の画面のフッターから案内画面 → 入力した文字列が引用の形で表示され、フォームにも入る → 「入力画面に戻る」で同じ 422 の画面
6. `/feedback?lat=91.00&lon=129.05&updated=任意の文章` → 地点も文章も表示されず、案内とフォームを開く操作は出る
7. ブラウザの開発者ツールのネットワークで、フォームを開いたリクエストに `Referer` が付いていない
8. スマホ幅（360px）でトップ・予報画面を最後までスクロール → フッターのリンクが他の要素と重ならず、横スクロールが出ない。案内画面も同様
9. プライベートブラウズ（localStorage が使えない環境を含む）でも 1〜5 が同じように動く

## 本番への設定

1. 運営者が外部フォームを用意する（[contracts/web-ui.md](contracts/web-ui.md) の「外部フォーム側の設定」）
2. VPS の `/opt/umiyomi/.env.production` に `FEEDBACK_FORM_URL` と `FEEDBACK_FORM_PREFILL_FIELD` を追記する（[deploy/README.md](../../deploy/README.md)）
3. `docker compose -f compose.prod.yml --env-file .env.production up -d` で反映し、上記 1〜8 を本番の URL で確認する

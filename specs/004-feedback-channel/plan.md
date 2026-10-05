# Implementation Plan: フィードバック導線

**Branch**: `004-feedback-channel` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/004-feedback-channel/spec.md`

## Summary

全画面のフッターに「フィードバック」のリンクを置き、UMIYOMI 内の案内画面（`GET /feedback`）を経て、運営者が用意した外部フォームを新しいタブで開けるようにする。
UMIYOMI はフィードバックの内容を受け取らず、保存しない。

案内画面は、返信を約束しないこと・緊急通報は海上保安庁（118 番）へ・公式の警報・注意報の確認の 3 点を、フォームを開く操作より前に表示する。
予報画面から来たときは、表示中の地点の緯度・経度と最終更新日時を、受け付けなかった入力から来たときは入力した文字列を、案内画面に表示し、
外部フォームの 1 つの欄に Query で事前入力する。案内画面が受け取る値は決まった形のものだけを使い、入力の文字列は引用の形で表示する。
フォームは `rel="noopener noreferrer"` で開き、案内画面のアドレスを外部へ渡さない。

すべて `Presentation/Web` に置き、Domain・Application・Infrastructure は変更しない。フォームの URL と事前入力の欄の名前は環境変数で設定し、未設定ならリンクを出さない。
JavaScript は追加しない。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）
**Primary Dependencies**: 既存の Symfony 8.1（FrameworkBundle、TwigBundle、AssetMapper）。新しい Composer / npm パッケージ・PHP 拡張は追加しない
**Storage**: なし（フィードバックの内容・文脈は保存しない。localStorage の形式（002）も変えない）
**Testing**: PHPUnit（文脈の検証・フォームの URL・ViewModel を Unit、フッター・案内画面・未設定を Functional）、`scripts/verify-prod.sh`（本番イメージで未設定時の挙動）、ブラウザでの手動確認（quickstart。実際のフォーム・スマホ幅）
**Target Platform**: スマホ・PC のブラウザ（幅 360px 以上）。サーバーは既存の Linux（Docker）
**Project Type**: Web サービス（サーバーサイドレンダリング）
**Performance Goals**: 案内画面は外部への問い合わせなしで描画する。予報の取得回数を増やさない（FR-013）
**Constraints**: フィードバックを受け取らない・保存しない / JavaScript・localStorage に依存しない（FR-011）/ 受け取る値は決まった形のみ（FR-016）/ 外部へ Referer を渡さない（FR-018）/ 断定的な安全表現を出さない / 360px で横にはみ出さない
**Scale/Scope**: ルート追加 1、PHP クラス新規 7（Controller・Parser・Context・enum・FormLink・ViewModel・Factory）と変更 2、Twig 新規 1・変更 2、CSS、設定（`.env`・`.env.test`・`services.yaml`・`twig.yaml`・`compose.prod.yml`・`.env.production.example`・`verify-prod.sh`）

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | 外部フォームへのリンクと案内画面だけ。フィードバックの受信・保存・メール送信・テーブル・アカウント・共有・SNS を作らない。迷惑な送信への対策もフォームサービスに任せる（spec Clarifications）。事前入力の欄を 1 つにして設定を最小にする（research R2） |
| II. レイヤー境界と依存方向 | ✅ | Domain の概念・UseCase がないため、すべて Presentation に置く（research R1）。Controller は「Request → 文脈の検証（Input）→ ViewModel → 描画」。Twig には ViewModel だけを渡す。Deptrac の設定は変更しない |
| III. 外部海況 API の隔離とキャッシュ | ✅ | 案内画面は UseCase・キャッシュ・回数制限を呼ばない（FR-013）。Functional Test で Fake Provider の呼び出し 0 回を確かめる。外部フォームはブラウザが開くだけで、サーバーから外部へ問い合わせない |
| IV. 判断材料の提示と断定の禁止 | ✅ | 案内画面に「出航の判断には公式な警報・注意報も確認」を出す（FR-004）。新しい文言に断定表現を含めず、Functional Test で確かめる。緊急通報先（118 番）を示し、フォームが緊急の連絡先と誤解されないようにする。海況の判定ロジックは変更しない |
| V. 重点領域のテスト | ✅ | 座標 validation に当たる文脈の検証（範囲・書式・日時・切り詰め）と ViewModel 変換を Unit で、HTTP → Controller → 描画の主要経路を Functional で確認する。外部フォームは通常のテストで開かない |
| ワークフロー（人間のレビュー） | ✅ | 画面・URL 設計（新しいルート・Query・フッター）と Security（外部から作られうるリンクの値の扱い・Referer）を含むため、実装前に承認を得る（下記 1〜7 は 2026-10-05 に承認済み）。Domain 設計・DB Schema・外部海況 API Provider・海況判断ロジックの変更はない。下記「レビューで判断してほしい点」を参照 |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。

### レビューで判断してほしい点（2026-10-05 にすべて承認済み）

1. **URL と Query**（contracts/web-ui.md）：`GET /feedback?lat=&lon=&updated=` / `?input_lat=&input_lon=`。`updated` は `2026/10/05 09:00` をそのまま URL エンコードする。
   フォームが未設定なら `/feedback` は 404
2. **事前入力の欄を 1 つにする**（research R2）：地点・最終更新・入力の文字列を 1 行の文章（例：`緯度 27.75・経度 129.05、最終更新 2026/10/05 09:00`）にして、フォームの 1 つの欄に入れる。
   設定は `FEEDBACK_FORM_URL` と `FEEDBACK_FORM_PREFILL_FIELD` の 2 つ（両方そろわないと未設定扱い）
3. **受け取る値の検証**（research R4）：緯度・経度は予報画面の URL と同じ正規形（`27.75`）だけ。片方だけ正しい地点・地点のない `updated` は使わない。
   地点と入力の文字列が両方来たら地点を優先する。不正な値はエラーにせず黙って捨てる。署名付きのリンクにはしない
4. **Security / プライバシー**（research R7）：フォームを開くリンクは `target="_blank" rel="noopener noreferrer"`、案内画面に `<meta name="referrer" content="no-referrer">` と `noindex`。
   サイト全体の `Referrer-Policy` は変えない
5. **戻るリンク**（research R6）：受け付けなかった入力から来たときは、入力した文字列で `/forecast` を開き直す（通常は同じ 422 の画面。100 文字を超えていた入力は切り詰めた文字列になり、別の画面になることがある。research R6 の Note）
6. **画面構成と文言**（contracts/web-ui.md）：フッターのリンクは Open-Meteo の表記と別の行で高さ 44px 以上。案内画面の 3 つの案内の文言、「返信用の連絡先の記入は任意です」の表示
7. **本番の設定**（research R9）：環境変数は任意（未設定でもデプロイできる）。`verify-prod.sh` に未設定時の確認を 1 つ足す

## Project Structure

### Documentation (this feature)

```text
specs/004-feedback-channel/
├── plan.md              # This file
├── research.md          # Phase 0: 層・設定と欄の数・URL の組み立て・Query の検証・フッターへの文脈の渡し方・戻り先・Referer・テストの未設定・本番の設定
├── data-model.md        # Phase 1: 文脈・事前入力の文章・PHP の型・設定
├── quickstart.md        # Phase 1: 自動テストとブラウザでの確認、本番への設定
├── contracts/
│   └── web-ui.md        # Phase 1: フッター・GET /feedback・画面の構成・外部フォームの URL・フォーム側の設定
├── checklists/
│   └── requirements.md  # /speckit.specify で作成済み
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code

```text
backend/
├── .env                                         # FEEDBACK_FORM_URL= / FEEDBACK_FORM_PREFILL_FIELD=（空）
├── .env.test                                    # 例の値
├── config/
│   ├── services.yaml                            # FeedbackFormLink に %env()% を渡す
│   └── packages/twig.yaml                       # global feedback_form
├── src/Presentation/Web/
│   ├── Controller/FeedbackController.php        # 新規：GET /feedback。未設定なら 404
│   ├── Feedback/FeedbackFormLink.php            # 新規：設定の判定と事前入力付き URL
│   ├── Input/
│   │   ├── FeedbackContextParser.php            # 新規：Query → FeedbackContext（FR-016）
│   │   ├── FeedbackContext.php                  # 新規
│   │   └── FeedbackContextType.php              # 新規 enum：None / Forecast / RejectedInput
│   └── ViewModel/
│       ├── FeedbackPageViewModel.php            # 新規
│       ├── FeedbackPageViewModelFactory.php     # 新規：事前入力の文章・案内文・引用・戻り先
│       ├── ForecastPageViewModel.php            # feedbackQuery を追加
│       └── ForecastPageViewModelFactory.php     # feedbackQuery を作る
├── templates/
│   ├── base.html.twig                           # フッターのリンク（block footer_feedback）、block head_meta
│   ├── forecast/index.html.twig                 # {% set feedback_query = page.feedbackQuery %}
│   └── feedback/index.html.twig                 # 新規：案内画面
├── assets/styles/app.css                        # フッターのリンク・案内・引用・フォームを開くボタン（360px）
└── tests/
    ├── Unit/Presentation/Web/
    │   ├── Input/FeedbackContextParserTest.php                  # 新規
    │   ├── Feedback/FeedbackFormLinkTest.php                    # 新規
    │   └── ViewModel/
    │       ├── FeedbackPageViewModelFactoryTest.php             # 新規
    │       └── ForecastPageViewModelFactoryTest.php             # feedbackQuery
    └── Functional/
        ├── FeedbackPageTest.php                 # 新規：フッター・案内画面・事前入力・不正な値・エスケープ・予報を取得しない・断定表現なし
        └── FeedbackUnconfiguredTest.php         # 新規：未設定でリンクなし・404
compose.prod.yml                                 # FEEDBACK_FORM_* を任意で渡す
deploy/.env.production.example                  # FEEDBACK_FORM_* の説明
deploy/README.md                                 # フォームの設定手順を一言
scripts/verify-prod.sh                           # 未設定でリンクなし・/feedback が 404
```

**Structure Decision**: 既存の `backend/` の Symfony アプリに追加する。Query の検証は既存の `Presentation/Web/Input`、表示用の変換は `Presentation/Web/ViewModel` に置き、
外部フォームの URL の組み立てだけを `Presentation/Web/Feedback` に分ける（Twig global として全画面のフッターから使うため）。
Domain・Application・Infrastructure、Deptrac・Dockerfile・CI の設定は変更しない。JavaScript を追加しないので `tests/JavaScript` も変わらない。

## Complexity Tracking

違反なし。

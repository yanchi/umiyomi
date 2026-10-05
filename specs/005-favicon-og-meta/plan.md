# Implementation Plan: ファビコンと OG 情報

**Branch**: `005-favicon-og-meta` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/005-favicon-og-meta/spec.md`

## Summary

全画面の `<head>` に、UMIYOMI 専用のアイコン（SVG・ICO・PNG）と、説明文・OG 情報（Open Graph / Twitter Card）・検索エンジン向けの設定を出す。
共有用の画像は、ロゴ・サービス名・キャッチコピーだけを含む固定の 1 枚（1200×630）にする。動的な画像は作らない。

すべて `Presentation/Web`（`base.html.twig` と各画面のテンプレート）と静的ファイルで完結させ、Domain・Application・Infrastructure は変更しない。
画面ごとの違い（タイトル・説明・検索エンジンへの登録可否）は、`base.html.twig` のブロックを各テンプレートが上書きして表す。PHP のクラスは増やさない。
画像の完全なアドレスは、本番で設定済みの `DEFAULT_URI` から作る（Host ヘッダーに依存しない）。
アイコンと画像は AssetMapper の配信（ファイル名に内容のハッシュが付く）を使うので、差し替えれば URL が変わり、古いキャッシュの問題が起きない。
`/favicon.ico` だけは慣習的に直接取りに来るため、`public/` に固定のパスで置く。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）。Twig テンプレートと静的ファイル（SVG・PNG・ICO）が中心
**Primary Dependencies**: 既存の Symfony 8.1（TwigBundle、AssetMapper）。新しい Composer / npm パッケージ・PHP 拡張は追加しない。画像の書き出しは開発時だけ、既存の e2e イメージ（Playwright の Chromium）を使う（実行時・CI には入れない）
**Storage**: なし（FR-014）。localStorage の形式（002）も変えない
**Testing**: PHPUnit の Functional Test（全画面の `<head>` の検査）、`scripts/verify-prod.sh`（本番イメージで画像・アイコンが 200 で返ること）、既存の e2e は変更なし、ブラウザ・共有プレビューでの手動確認（quickstart）
**Target Platform**: スマホ・PC のブラウザ、各チャットアプリ・SNS のクローラー
**Project Type**: Web サービス（サーバーサイドレンダリング）
**Performance Goals**: 画面の表示に必須の取得を増やさない（アイコンは `<link>` で非同期に取られ、OG 画像はクローラーしか取らない）。OG 画像は 300KB 未満を目安にする
**Constraints**: 外部 API・回数制限の対象にしない（FR-012）/ 断定表現を使わない（FR-008）/ 利用者の入力文字列を含めない（FR-009、FR-010）/ 画像の参照先は完全なアドレス（FR-006）
**Scale/Scope**: PHP の変更なし（`config/packages/twig.yaml` に global 1 つ）、Twig 変更 4（base・home・forecast・feedback）と新規 1（エラー画面）、静的ファイル 5（SVG・ICO・PNG×3）、Functional Test 1〜2 ファイル、`verify-prod.sh` と deploy README の追記

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | 共有ボタン・SNS 連携・PWA（manifest・Service Worker）・動的な画像・多言語は作らない。ホーム画面追加用のアイコンだけ整える（research R2）。テーブル・保存なし。新しい PHP クラス・設定項目を作らない |
| II. レイヤー境界と依存方向 | ✅ | `Presentation/Web` のテンプレートと静的ファイルだけの変更。UseCase・Domain は触らない。Twig に渡す値は既存の ViewModel（`page.location`）だけで、Domain Entity を渡さない。Deptrac の設定は変更しない |
| III. 外部海況 API の隔離とキャッシュ | ✅ | アイコン・画像は静的ファイルで、Provider・キャッシュ・回数制限を通らない（FR-012）。Functional Test で、画像・アイコンの取得で Fake Provider が 0 回しか呼ばれないことを確かめる |
| IV. 判断材料の提示と断定の禁止 | ✅ | 説明文・画像内の文言に断定を含めず、警報・注意報の確認を併せて案内する（research R4）。Functional Test で禁止語を検査する。最終更新日時・数値は共有用情報に載せない（spec Edge Cases）。海況の判定ロジックは変更しない |
| V. 重点領域のテスト | ✅ | 時刻処理・座標 validation・変換ロジックの追加はない。ただし FR-009（入力が共有用情報に入らない）は座標のフォーマットに関わるため、Functional Test で、不正な入力・特殊文字・長い文字列を `/forecast` に渡して `<head>` に混入しないことを確認する |
| ワークフロー（人間のレビュー） | ✅ | 該当する領域は「画面・URL 設計」（新しい公開 URL：`/favicon.ico`・`/assets/...`）と、検索エンジンへの公開範囲（Security に近い）。下記「レビューで判断してほしい点」で承認を求める。Domain 設計・DB Schema・外部 API Provider・海況判断ロジックの変更はない |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。

### レビューで判断してほしい点

1. **アイコンの構成**（research R2）：`/favicon.ico`（32px・`public/` に固定）、`icon.svg`（ダークテーマ対応）、`icon-192.png`、`apple-touch-icon.png`（180px・不透明）。manifest は作らない（PWA の対象外）。デザインは「濃い青の角丸の地に、白い波 2 本」を提案する。最終的な見た目の承認は人間
2. **共有用画像**（research R3）：1200×630 の PNG 固定 1 枚。内容は「ロゴ・UMIYOMI・キャッチコピー」だけ。代替テキストは「UMIYOMI のロゴ。風・波・うねりの予報を出航前に確認するサービス」
3. **文言**（contracts/web-ui.md）：ページごとの `<title>`・`description`・OG 文言の案。FR-008 に反しないかの確認
4. **検索エンジン**（research R5）：トップだけ `index`、その他は `<meta name="robots" content="noindex">`。**注意**：本番の nginx は公開前のため `X-Robots-Tag: noindex, nofollow` を全体に付けている（`deploy/nginx/umiyomi.conf`）。この機能ではこれを変更しない。トップの登録許可は、運営者がその行を消して公開したときに初めて効く
5. **完全なアドレスの作り方**（research R6）：`DEFAULT_URI`（本番で設定済み）から作る。開発（`http://localhost`、ポート 8000 なし）では画像のアドレスが実際と食い違うが、開発・テストでは許容する（spec Assumptions）
6. **OG 画像・アイコンの作り方**（research R8）：SVG を手書きし、PNG・ICO は e2e の Chromium で書き出して**ファイルとしてコミットする**。ビルド手順は本番に持ち込まない

## Project Structure

### Documentation (this feature)

```text
specs/005-favicon-og-meta/
├── plan.md              # This file
├── research.md          # Phase 0: 判断の記録（R1〜R9）
├── data-model.md        # Phase 1: ページごとのメタ情報と静的ファイルの一覧
├── quickstart.md        # Phase 1: 自動テストと手動確認、公開時の手順
├── contracts/
│   └── web-ui.md        # Phase 1: <head> の契約・ページ別の値・ファイルの URL
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code (repository root)

```text
backend/
├── assets/
│   └── images/
│       ├── icon.svg                  # 新規：ダークテーマ対応のアイコン（元データ）
│       ├── icon-192.png              # 新規：Android のホーム画面など
│       ├── apple-touch-icon.png      # 新規：iOS のホーム画面（180×180・不透明）
│       └── og-image.png              # 新規：共有用画像（1200×630）
├── public/
│   └── favicon.ico                   # 新規：16/32px を含む ICO。直接取りに来る古いブラウザ・クローラー向け
├── config/packages/twig.yaml         # 変更：global site_origin（DEFAULT_URI）
├── templates/
│   ├── base.html.twig                # 変更：アイコン・description・OG・robots を出す。ページ別のブロックを用意
│   ├── home/index.html.twig          # 変更：robots を index にする
│   ├── forecast/index.html.twig      # 変更：既存の title を流用（OG の title にも使う）
│   ├── feedback/index.html.twig      # 変更：説明文を上書き
│   └── bundles/TwigBundle/Exception/
│       └── error.html.twig           # 新規：404 などのエラー画面を base に載せる（research R7）
└── tests/Functional/
    └── HeadMetaTest.php              # 新規：全画面のアイコン・OG・robots・禁止語・入力の混入なし

e2e/
├── scripts/build-icons.mjs           # 新規：SVG → PNG・ICO の書き出し（開発時のみ。research R8）
└── icons/                            # 新規：元の SVG（apple-touch-icon.svg・og-image.svg）
scripts/
└── verify-prod.sh                    # 変更：本番イメージで OG 画像・アイコンが 200 で返ること
deploy/README.md                      # 変更：公開時に noindex を外すと何が変わるかを追記
```

**Structure Decision**: 既存の Web サービスの構成（`backend/` 配下の Symfony + Twig）にそのまま載せる。メタ情報は Twig のブロックで表し、PHP の ViewModel・サービスは増やさない（research R1）。
画像・アイコンは AssetMapper の管理下（`assets/images/`）に置き、`/favicon.ico` だけ `public/` に置く。

## Complexity Tracking

違反なし。

# Implementation Plan: 検索エンジン対策（SEO）

**Branch**: `007-seo-optimization` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/007-seo-optimization/spec.md`

## Summary

トップ画面だけを検索エンジンの登録対象として公開する。
アプリ側では、`/robots.txt`（どの画面も禁止せず、ページ一覧の場所を示す）と `/sitemap.xml`（トップ 1 件）をルートとして返し、トップにだけ正規のアドレス（canonical）と `WebSite` の構造化データ（JSON-LD）を出す。アドレスはすべて既存の `site_origin`（`DEFAULT_URI`）から作り、Host ヘッダーを使わない。
トップには、入力欄・お気に入りの後ろに「できること・表示する数値・使い方（入力例は文章だけ）・安全を保証しないことと警報・注意報の確認」の本文を足し、h1 にサービスの内容を含める。トップの説明文を、表示する数値の種類が分かる文に差し替える。

公開の操作として、リポジトリの nginx の vhost から全体の `X-Robots-Tag: noindex, nofollow` を外し（certbot のチャレンジだけ `noindex` を残す）、`verify-prod.sh` の確認を「トップだけ登録可・他は `noindex`・robots.txt / sitemap が取得できる」に改める。VPS 上の反映と Search Console（DNS の TXT で所有確認）の手順を deploy/README.md に書く。

Domain・Application・Infrastructure は変更しない。Presentation に Controller を 2 つ（robots.txt・sitemap）足すだけで、ViewModel・設定項目・パッケージは増やさない。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）。Twig テンプレートが中心。nginx の設定ファイルとシェルスクリプト
**Primary Dependencies**: 既存の Symfony 8.1（FrameworkBundle、TwigBundle、AssetMapper）。新しい Composer / npm パッケージ・PHP 拡張・外部サービスの読み込みは追加しない
**Storage**: なし（FR-014）。localStorage の形式（002・006）も変えない
**Testing**: PHPUnit の Functional Test（robots.txt・sitemap・canonical・JSON-LD・トップの本文・既存の HeadMetaTest の更新）、Playwright（トップの入力欄が最初の画面に収まること。desktop・mobile）、`scripts/verify-prod.sh`（本番イメージ・vhost での robots / canonical / robots.txt / sitemap / `X-Robots-Tag`）、Search Console・リッチリザルトテストでの手動確認（quickstart）
**Target Platform**: 検索エンジンのクローラー（主に Googlebot）、スマホ・PC のブラウザ
**Project Type**: Web サービス（サーバーサイドレンダリング）
**Performance Goals**: トップに足すのは静的な文章と 1 つの JSON-LD だけで、取得するリソース・サーバーの処理を増やさない。robots.txt・sitemap は外部 API・キャッシュ・回数制限を通らない
**Constraints**: アドレスは `DEFAULT_URI` から（FR-006）/ 登録対象はトップだけ（FR-001）/ 予報画面へのリンク・地点の推奨なし（FR-007a）/ 断定表現なし（FR-012）/ 画面にない情報を構造化データに入れない（FR-011）/ 所有確認用の情報をアプリに入れない（FR-017）/ 入力欄が最初の画面に収まる（SC-006）
**Scale/Scope**: PHP 新規 2（`RobotsTxtController`・`SitemapController`）、Twig 新規 2（`seo/robots.txt.twig`・`seo/sitemap.xml.twig`）と変更 2（`base.html.twig`・`home/index.html.twig`）、CSS 少量、テスト（Functional 新規 2・更新 1、e2e 1）、`deploy/nginx/umiyomi.conf`・`scripts/verify-prod.sh`・`deploy/README.md` の変更

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | 地域・港別の入口ページ、SNS 告知、多言語、PWA は作らない（spec Assumptions）。テーブル・保存なし。登録対象の一覧を PHP の配列・設定項目として持たない（1 件だけのため。data-model） |
| II. レイヤー境界と依存方向 | ✅ | `Presentation/Web` の Controller 2 つとテンプレートだけ。Controller は Request を受け取らず、UseCase・Domain を呼ばない。Twig に Domain Entity を渡さない。Deptrac の設定は変更しない |
| III. 外部海況 API の隔離とキャッシュ | ✅ | robots.txt・sitemap・トップは Provider を呼ばない（Functional Test で呼び出し 0 回を確認）。クローラーが予報画面を取得しても既存のキャッシュ・回数制限の範囲（FR-015、research R11）。クローラー専用の扱いは足さない |
| IV. 判断材料の提示と断定の禁止 | ✅ | 本文・説明文・構造化データに断定表現を入れず、安全を保証しないことと警報・注意報の確認を本文と説明文で案内する（FR-007(d)・FR-012）。Functional Test で禁止語を検査する。海況の判定ロジックは変更しない |
| V. 重点領域のテスト | ✅ | 重点領域（Domain・UseCase・変換・時刻・座標）の変更はない。Functional Test で HTTP → Controller → 描画の経路（robots.txt・sitemap・トップ）を確認し、本番構成は `verify-prod.sh` で確認する |
| ワークフロー（人間のレビュー） | ⚠️ 要承認 | 「画面・URL 設計」（新しい公開 URL `/robots.txt`・`/sitemap.xml`、トップの見出し・本文）と、検索エンジンへの公開範囲の変更・本番 Web サーバー設定（Security に近い）に該当する。下記「レビューで判断してほしい点」で承認を求める。Domain 設計・DB Schema・外部 API Provider・海況判断ロジックの変更はない |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。「要承認」はゲートの違反ではなく、実装前に人間のレビューを待つ対象であることを示す。

### レビューで判断してほしい点

1. **公開の範囲と方法**（research R8・contracts）：nginx の server 全体の `X-Robots-Tag: noindex, nofollow` を消し、certbot のチャレンジのパスにだけ `noindex` を残す。マージ後、運営者が VPS の vhost を手順書どおりに直して reload した時点でトップが登録可能になる。順番を誤っても、トップが登録可能になるだけで他の画面は meta の `noindex` で守られる（data-model「状態の遷移」）
2. **robots.txt・sitemap をアプリのルートで返す**（research R1〜R3・R9）：`public/` の静的ファイルではなく、`DEFAULT_URI` から作る。どの画面も Disallow にしない。sitemap はトップ 1 件、`lastmod` なし。両方に `X-Robots-Tag: noindex` を付ける
3. **canonical はトップだけ**（research R4）：`noindex` の画面には canonical を出さない（矛盾したシグナルを避ける）。FR-005 の「登録しない画面に示すかどうかは計画で決めてよい」に基づく判断
4. **構造化データは `WebSite` だけ**（research R5）：`WebApplication` は料金・評価が必須項目のため使わない。`alternateName` に「ウミヨミ」を入れ、本文にも「UMIYOMI（ウミヨミ）」と表示する。JSON-LD だけ `|raw`（`JSON_HEX_TAG`・`JSON_HEX_AMP` でエスケープ）
5. **トップの見出し・本文・説明文の文言**（contracts/web-ui.md、research R6・R7）：h1 にキャッチを足す。本文はお気に入りの後ろ。「会員登録なしで使えます」「およそ 3 日先まで」の表現、入力例（27.75 / 129.05）の示し方。FR-012 に反しないかの確認
6. **Search Console のプロパティ**（research R10）：親ドメイン `isl-mentor.com` ではなく、ドメイン プロパティ `umiyomi.isl-mentor.com`（TXT レコードはそのサブドメインに置く）

## Project Structure

### Documentation (this feature)

```text
specs/007-seo-optimization/
├── plan.md              # This file
├── research.md          # Phase 0: 判断の記録（R1〜R12）
├── data-model.md        # Phase 1: 登録対象ページ・正規のアドレス・構造化データ・公開状態の遷移
├── quickstart.md        # Phase 1: 自動テスト・手動確認・公開の操作
├── contracts/
│   └── web-ui.md        # Phase 1: 新しい URL・<head>・トップの本文・nginx の契約
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code (repository root)

```text
backend/
├── src/Presentation/Web/Controller/
│   ├── RobotsTxtController.php       # 新規：GET /robots.txt（text/plain、X-Robots-Tag: noindex）
│   └── SitemapController.php         # 新規：GET /sitemap.xml（application/xml、X-Robots-Tag: noindex）
├── templates/
│   ├── base.html.twig                # 変更：canonical・structured_data のブロック（既定は空）
│   ├── home/index.html.twig          # 変更：h1 のキャッチ、説明文の上書き、canonical、JSON-LD、本文（section.home-guide）
│   └── seo/
│       ├── robots.txt.twig           # 新規
│       └── sitemap.xml.twig          # 新規
├── assets/styles/app.css             # 変更：h1 のキャッチと本文の見た目（少量）
└── tests/Functional/
    ├── SeoTest.php                   # 新規：robots.txt・sitemap・canonical・JSON-LD（全画面）
    ├── HomeGuideTest.php             # 新規：トップの見出し構造・本文・禁止事項
    └── HeadMetaTest.php              # 変更：トップの説明文、外部送信の案内を robots の対象に追加

e2e/tests/
└── home.spec.js                      # 新規：入力欄と「予報を表示」が最初の画面に収まる（desktop・mobile、375×667）

deploy/
├── nginx/umiyomi.conf                # 変更：全体の X-Robots-Tag を削除、チャレンジのパスにだけ noindex
└── README.md                         # 変更：「公開するとき」を「検索エンジンへの公開」に改め、反映・確認・Search Console の手順
scripts/
└── verify-prod.sh                    # 変更：X-Robots-Tag の確認を置き換え、robots / canonical / robots.txt / sitemap を確認
```

**Structure Decision**: 既存の Web サービスの構成（`backend/` 配下の Symfony + Twig）にそのまま載せる。新しいルートは既存と同じ invokable Controller（`Presentation/Web/Controller`）にし、画面ごとの違いは 005 と同じく `base.html.twig` のブロックの上書きで表す。PHP の ViewModel・サービス・設定項目は増やさない（research R1・R5）。

## Complexity Tracking

違反なし。

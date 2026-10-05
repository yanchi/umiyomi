# Implementation Plan: お気に入り地点の保存と呼び出し

**Branch**: `002-favorite-locations` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/002-favorite-locations/spec.md`

## Summary

予報画面で表示中の地点を、名前（30 文字以内・省略可）を付けてブラウザの localStorage に保存し、トップ画面の一覧と予報画面の折りたたみ一覧から
既存の予報 URL（`/forecast?lat=..&lon=..`）を開き直せるようにする。削除（確認あり）と名前変更はトップ画面で行う。

お気に入りの内容はサーバーに送らない（FR-010）ため、保存・一覧・削除・名前変更はすべてブラウザの ES Module（AssetMapper で配信、ビルドなし）で行う。
サーバー側は、Twig で UI の枠・文言・`<template>` を出力し、予報画面で表示中の地点の丸め済み座標を `data-*` 属性で渡すだけにする
（そのために Application の `MarineForecastResult` に座標を足す）。お気に入りの規則（名前・件数・重複・並び順・破損データの除外）は DOM に依存しない
JavaScript モジュールにまとめ、Node.js 標準のテストランナーでテストする。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）、JavaScript（ES2022 の ES Module。ビルドなし）
**Primary Dependencies**: 既存の Symfony 8.1（TwigBundle、AssetMapper）。新しい Composer / npm パッケージは追加しない。開発用イメージと CI に Node.js 20（テスト実行のみ）
**Storage**: ブラウザの localStorage（キー `umiyomi.favorites`、JSON 1 件）。サーバー側の保存なし
**Testing**: `node --test`（お気に入りの規則・保存形式）、PHPUnit（ViewModel 変換・UseCase の結果・Functional で HTML の枠と文言）、ブラウザでの手動確認（quickstart）
**Target Platform**: スマホ・PC のブラウザ（幅 360px 以上、ES Module と `<details>` に対応するもの）。サーバーは既存の Linux（Docker）
**Project Type**: Web サービス（サーバーサイドレンダリング + 部分的な JavaScript）
**Performance Goals**: 保存・削除・名前変更・一覧の描画は体感で即時（最大 20 件）。SC-001（2 回以内の操作）・SC-002（30 秒以内）は画面構成で満たす
**Constraints**: お気に入りの内容をサーバーに送らない（SC-006）/ localStorage や JavaScript が使えなくても予報の閲覧は動く（SC-004）/ 名前を HTML として解釈しない / 断定的な安全表現を出さない / 360px で横にはみ出さない
**Scale/Scope**: 画面 2（既存のトップ・予報）の追加要素、ルート追加 0、JavaScript モジュール 3、お気に入り最大 20 件

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | MVP 完成条件 4 の「地点をお気に入り保存」そのもの。localStorage に保存し、サーバー側のテーブル・ルート・API を作らない。地図指定・同期・共有・オフライン閲覧は含めない。新しいパッケージを追加しない |
| II. レイヤー境界と依存方向 | ✅ | お気に入りのための Domain / UseCase は作らない（サーバーで扱う対象がない。research R1）。座標の受け渡しは Application の DTO に丸め済みの値を足し、Presentation は DTO だけを参照する（Deptrac の設定は変更しない）。丸めの規則は Domain の `Coordinate` の 1 か所のまま（research R2） |
| III. 外部海況 API の隔離とキャッシュ | ✅ | 外部 API の呼び出し・キャッシュには触れない。JavaScript は外部 API を呼ばず、既存の予報 URL へのリンクを作るだけ |
| IV. 判断材料の提示と断定の禁止 | ✅ | お気に入り一覧に予報の数値・海況の判定を出さない（FR-013）。文言はすべて Twig に置き、断定表現がないことを Functional Test で確認する（research R6）。予報画面の「最終更新」の表示は変えない |
| V. 重点領域のテスト | ✅ | お気に入りの規則（名前の正規化と上限・重複判定・件数上限・並び順・破損データの除外）は座標 validation と Domain Logic に相当するため `node --test` で自動テストする。`MarineForecastResult` の座標と `favoriteTarget` の変換は PHPUnit の Unit、画面の枠は Functional。DOM 操作は手動確認（quickstart） |
| ワークフロー（人間のレビュー） | ⏳ 承認待ち | 画面・URL 設計（画面構成の変更）を含むため、実装前に承認を得る。Domain 設計・DB Schema・外部 API Provider・Security・海況判断ロジックの変更はない。下記「レビューで判断してほしい点」を参照 |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。

### レビューで判断してほしい点

1. **JavaScript のテスト環境の追加**（research R11）：開発用イメージに Debian の `nodejs`（20.x）を入れ、`composer test:js`（`node --test`）を `composer check` と CI に加える。
   npm・`package.json` は持ち込まない。本番用イメージには入れない。ブラウザを動かす E2E（Playwright 等）は見送り、DOM の動作は手動確認とする
2. **画面構成**（contracts/web-ui.md）：予報画面の保存パネルを「地点・最終更新」のすぐ下（注意書きの上）に置く。折りたたみ一覧は入力フォームのすぐ下（警告より上）。
   名前変更はトップ画面の一覧の項目をその場で入力欄に切り替える
3. **`MarineForecastResult` に座標を追加**（research R2 / data-model.md）：取得失敗・回数制限の画面でも保存できるようにするための Application DTO の変更。Domain は変えない
4. **localStorage の保存形式**（research R3）：キー `umiyomi.favorites`、`{"version":1,"items":[...]}`。空欄の名前は空文字列で保存し、表示時に座標を出す。
   壊れた項目は読み込み時に捨て、次の書き込みで消える
5. **削除の確認に `window.confirm` を使う**（research R7）：見た目はブラウザ標準になる
6. **名前の文字数はコードポイント数**（research R4）：`maxlength` は付けず、31 文字以上は保存時に案内する。結合文字を含む絵文字などは 2 文字以上に数えられうる

## Project Structure

### Documentation (this feature)

```text
specs/002-favorite-locations/
├── plan.md              # This file
├── research.md          # Phase 0: 処理の置き場所・座標の受け渡し・保存形式・文字数・表示の安全性・テスト方法
├── data-model.md        # Phase 1: Favorite / FavoriteList / 保存形式 / PHP 側の変更
├── quickstart.md        # Phase 1: 自動テストとブラウザでの確認手順
├── contracts/
│   └── web-ui.md        # Phase 1: 画面構成・メッセージ・Twig と JavaScript の取り決め
├── checklists/
│   └── requirements.md  # /speckit.specify で作成済み
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code

```text
backend/
├── Dockerfile                           # nodejs を追加（開発用のみ。Dockerfile.prod は変更しない）
├── composer.json                        # scripts に test:js を追加し、check に含める
├── src/
│   ├── Application/Marine/
│   │   ├── DTO/MarineForecastResult.php       # latitude / longitude を追加
│   │   └── UseCase/ViewMarineForecast.php     # 結果に Coordinate の丸め済みの値を渡す
│   └── Presentation/Web/ViewModel/
│       ├── ForecastPageViewModel.php          # favoriteTarget を追加
│       └── ForecastPageViewModelFactory.php   # favoriteTarget を作る
├── templates/
│   ├── home/index.html.twig             # フォームの下にお気に入り一覧を追加
│   ├── forecast/index.html.twig         # 折りたたみ一覧と保存パネルを追加
│   └── favorites/
│       ├── _notes.html.twig             # 端末内保存の注意書き・使えないときの文言（noscript 含む）
│       ├── _manage_list.html.twig       # トップ画面の一覧（名前変更・削除）と項目の <template>
│       ├── _switch_list.html.twig       # 予報画面の <details> 一覧と項目の <template>
│       └── _save_panel.html.twig        # 予報画面の保存パネル
├── assets/
│   ├── app.js                           # favorites-ui.js を読み込んで初期化
│   ├── favorites/
│   │   ├── favorite-list.js             # 規則（純粋関数）：正規化・検証・add/rename/remove/find・parse/serialize・表示名
│   │   ├── favorite-store.js            # Storage の読み書き・利用可否の判定
│   │   └── favorites-ui.js              # DOM：枠の検出・描画・イベント
│   └── styles/app.css                   # お気に入りの一覧・パネル・折りたたみ（360px で折り返す）
└── tests/
    ├── JavaScript/
    │   ├── favorite-list.test.js        # node --test
    │   └── favorite-store.test.js       # 偽の Storage（例外を投げるものを含む）
    ├── Unit/
    │   ├── Application/Marine/ViewMarineForecastTest.php                 # 全ステータスで丸め済み座標が入る
    │   └── Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php # favoriteTarget の書式・422 で null
    └── Functional/
        └── FavoritesMarkupTest.php      # トップ・予報（200/503/429/422）の枠・data-* 属性・文言・断定表現なし

.github/workflows/ci.yml                 # php ジョブに Node.js 20 のセットアップと node --test を追加
```

**Structure Decision**: 既存の `backend/` の Symfony アプリに追加する。お気に入りはサーバーで扱わないため、PHP の新しいレイヤー・クラスは作らず、
既存の DTO・ViewModel に座標を 1 つ通すだけにする。JavaScript は `assets/favorites/` にまとめ、規則（`favorite-list.js`）・保存（`favorite-store.js`）・DOM（`favorites-ui.js`）に分けて、
前の 2 つを Node.js でテストできるようにする。テストは AssetMapper の配信対象外の `tests/JavaScript/` に置く。Deptrac の設定は変更しない。

## Complexity Tracking

違反なし。

# Implementation Plan: 緯度・経度の入力サポート

**Branch**: `003-coordinate-input-support` | **Date**: 2026-10-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/003-coordinate-input-support/spec.md`

## Summary

緯度・経度の入力欄で、十進数に加えて「緯度, 経度」の 1 行の貼り付け・度分・度分秒・方角の文字（N/S/E/W、北緯など）・全角を受け付け、
入力欄の近くの「現在地を入力」で端末の現在地を入力欄に入れられるようにする。

読み取りはサーバー（`Presentation/Web/Input`）だけで行う。1 欄の文字列を読む `CoordinateNotationParser` を新設し、既存の `CoordinateQueryParser` が
2 欄の組み合わせ（FR-004）・順序の誤り（FR-022）・範囲を判定する。FR-023 の正確な四捨五入は、浮動小数点を使わず数字の文字列から整数で求める（bcmath なし）。
有効だが正規形（`27.75`）でない入力は、予報を取得せずに正規形の URL へ 303 リダイレクトし、入力欄・地点・URL を十進数にそろえる（FR-010）。
Domain・Application は変更しない。

現在地は、ブラウザの ES Module（ビルドなし）から Geolocation API をボタン押下時にだけ呼び、2 桁に丸めて入力欄に入れる。
同じ JavaScript が、予報画面で入力欄が開いたときの文字列から変わったことを検知して案内を出す（FR-021）。保存パネルには保存される地点を表示する（FR-025）。

## Technical Context

**Language/Version**: PHP 8.4（FrankenPHP、Docker）、JavaScript（ES2022 の ES Module。ビルドなし）
**Primary Dependencies**: 既存の Symfony 8.1（FrameworkBundle、TwigBundle、AssetMapper）。新しい Composer / npm パッケージ・PHP 拡張は追加しない
**Storage**: なし（入力文字列・現在地は保存しない。localStorage の形式（002）も変えない）
**Testing**: PHPUnit（表記の読み取り・2 欄の組み合わせ・ViewModel・Functional でリダイレクトと文言）、`node --test`（現在地の取得・2 桁の書式）、ブラウザでの手動確認（quickstart。iPhone のキーボードと Geolocation を含む）
**Target Platform**: スマホ・PC のブラウザ（幅 360px 以上）。現在地は HTTPS（本番）と localhost でだけ動く。サーバーは既存の Linux（Docker）
**Project Type**: Web サービス（サーバーサイドレンダリング + 部分的な JavaScript）
**Performance Goals**: 読み取りは 1 欄 100 文字以内で即時。リダイレクトは 1 往復増えるが予報の取得は 1 回のまま。現在地は 15 秒で打ち切る（SC-005 の 30 秒以内）
**Constraints**: 読み取りの規則をブラウザに持たない / JavaScript・位置情報が使えなくても入力と予報の表示は動く（SC-006）/ 現在地を自動で取得・送信・保存しない / 断定的な安全表現を出さない / 360px で横にはみ出さない
**Scale/Scope**: ルート追加 0、PHP クラス新規 5（パーサー・結果・値・enum 2）と変更 6、Twig 変更 3、JavaScript モジュール 3

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原則 | 判定 | 根拠 |
|---|---|---|
| I. MVP スコープの厳守 | ✅ | MVP 完成条件 1 の「ブラウザで緯度・経度を入力」を使いやすくするもの。地図指定・地名検索・共有リンクの解決・航路・現在地の追跡は含めない（spec Assumptions）。テーブル・API・パッケージを追加しない |
| II. レイヤー境界と依存方向 | ✅ | 読み取りは Presentation の Input に置き、UseCase には従来どおり検証済みの `float` を渡す（research R1）。Domain・Application は変更しない。Controller は「パース → リダイレクトまたは UseCase → ViewModel → 描画」のまま。Deptrac の設定は変更しない |
| III. 外部海況 API の隔離とキャッシュ | ✅ | 外部 API・キャッシュに触れない。リダイレクト元では UseCase を呼ばないので、回数制限にも数えない。JavaScript は外部 API を呼ばない（Geolocation はブラウザの API） |
| IV. 判断材料の提示と断定の禁止 | ✅ | 新しい文言（エラー・通知・現在地の案内）に断定表現を含めない。現在地が陸地の内側の場合の注意（FR-019）を出す。「最終更新」の表示は変えない。海況の判定ロジックは変更しない |
| V. 重点領域のテスト | ✅ | 座標 validation に当たる表記の読み取り・正確な丸め・範囲・2 欄の組み合わせを PHPUnit の Unit で網羅する（SC-002 の表・丸めの境目を含む）。HTTP → リダイレクト → 予報画面の経路を Functional で確認する。現在地の取得規則（15 秒・重ねない・拒否と失敗の区別）は `node --test`。DOM は手動確認 |
| ワークフロー（人間のレビュー） | ✅ | 画面・URL 設計（URL の正規化リダイレクト、フォームの構成）と Security（端末の位置情報の扱い）を含むため、実装前に承認を得る（下記 1〜7 は 2026-10-05 に承認済み）。Domain 設計・DB Schema・外部 API Provider・海況判断ロジックの変更はない。下記「レビューで判断してほしい点」を参照 |

**Post-design re-check（Phase 1 後）**: 違反なし。Complexity Tracking は空。

### レビューで判断してほしい点（2026-10-05 にすべて承認済み）

1. **正規形の URL への 303 リダイレクトと `ignored` パラメータ**（research R5、contracts/web-ui.md）：十進数以外・1 行の文字列・`27.7500` のような入力は、
   予報を取得せずに `/forecast?lat=27.75&lon=129.05` へリダイレクトする。001 の「正規化した URL へのリダイレクトはしない」を変更する。
   1 行の文字列でもう一方の欄を使わなかったときは `&ignored=lon` を付けて通知する（`ignored` 付きの URL を再読み込みすると通知が再び出る）
2. **読み取りを Presentation に置き、Domain を変えない**（research R1）：アプリ化のときに同じ表記をサーバーで読む必要が出たら Application へ移す
3. **正確な丸めを bcmath なしで整数計算する**（research R2）：PHP 拡張を追加しない。001 の十進数入力も `(float)` 変換から同じ方法に切り替える
   （`27.7549999999999999` が 27.75 になるなど、桁の多い入力で 001 と結果が変わりうる）
4. **spec より細かく決めた文法**（research R3・R4、data-model.md）：
   - iPhone のスマート句読点（`’` `”`）、`º`、`''`（秒）、方角の小文字、`d`（度）を受け付ける
   - 最後の単位の記号は省略可（`27°45.0N`）
   - 1 行の 2 つの値は、方角の文字が「両方にある」か「両方にない」かに限る（`N27 45.0` を緯度 27・経度 45 と読まないため）
   - 度分秒で分に小数がある値（`27°45.5'30"`）は受け付けない
5. **入力欄の属性**（research R7）：`inputmode="decimal"` を外し、`autocapitalize` / `autocorrect` を off、`spellcheck="false"` にする。十進数でも文字のキーボードが出る
6. **位置情報の扱い（Security / プライバシー）**（research R8）：ボタン押下時にだけ取得し、2 桁（約 1km）に丸めて入力欄に入れるだけ。サーバーへは利用者が送信したときだけ渡る。
   保存しない。HTTPS（と localhost）でだけボタンを表示する。15 秒は押した時点から数えるので、許可の確認ダイアログで 15 秒以上迷うと取得できなかった扱いになる（もう一度押せば取得できる）
7. **画面構成**（contracts/web-ui.md）：フォームの下に「現在地を入力」・入力の案内・食い違いの案内を置く。`ignored` の通知はフォームのすぐ下。保存パネルに「保存される地点」を追加

## Project Structure

### Documentation (this feature)

```text
specs/003-coordinate-input-support/
├── plan.md              # This file
├── research.md          # Phase 0: 層・正確な丸め・正規化・文法・リダイレクト・エラー・入力欄の属性・現在地・食い違いの表示・JS の構成
├── data-model.md        # Phase 1: 受け付ける表記（文法）・2 欄の組み合わせ・PHP の型・JS モジュールの入出力
├── quickstart.md        # Phase 1: 自動テストとブラウザ（スマホ）での確認手順
├── contracts/
│   └── web-ui.md        # Phase 1: Query・リダイレクト・フォームの構成・メッセージ・Twig と JS の取り決め
├── checklists/
│   └── requirements.md  # /speckit.specify で作成済み
└── tasks.md             # Phase 2（/speckit.tasks で作成）
```

### Source Code

```text
backend/
├── src/Presentation/Web/
│   ├── Controller/
│   │   ├── ForecastController.php               # needsRedirect() なら 303。ignored を parser に渡す
│   │   └── HomeController.php                   # form に staleNotice: null を追加
│   ├── Input/
│   │   ├── CoordinateNotationParser.php         # 新規：1 欄の文字列 → ParsedField（正規化・文法・正確な丸め）
│   │   ├── ParsedField.php                      # 新規：Empty / Single / Pair / Invalid
│   │   ├── Angle.php                            # 新規：hundredths・軸・範囲判定（丸める前の値）・正規形
│   │   ├── NotationError.php                    # 新規 enum：読み取りの失敗の原因
│   │   ├── Axis.php                             # 新規 enum：Latitude / Longitude
│   │   ├── CoordinateQueryParser.php            # 2 欄の組み合わせ・軸・範囲・順序・文言。(float) 変換をやめる
│   │   └── CoordinateQuery.php                  # canonicalLatitude / canonicalLongitude / ignoredField / needsRedirect()
│   └── ViewModel/
│       ├── ForecastPageViewModel.php            # inputNotice、Form.staleNotice、FavoriteTarget.label
│       └── ForecastPageViewModelFactory.php     # 上記を作る。地点の書式を予報の有無に関係なく作れるようにする
├── templates/
│   ├── forecast/_form.html.twig                 # 入力欄の属性・data-*・現在地・入力の案内・食い違いの案内
│   ├── forecast/index.html.twig                 # 入力の通知
│   └── favorites/_save_panel.html.twig          # 保存される地点
├── assets/
│   ├── app.js                                   # initCoordinateInput を追加
│   ├── coordinate-input/
│   │   ├── position-format.js                   # 2 桁の文字列にする（純粋関数）
│   │   ├── location-request.js                  # Geolocation・15 秒・重ねない（DOM なし）
│   │   └── coordinate-input-ui.js               # ボタン・メッセージ・取得中の書き換え検知・食い違いの案内
│   └── styles/app.css                           # 現在地・案内・通知（360px で折り返す）
└── tests/
    ├── Unit/Presentation/Web/
    │   ├── Input/CoordinateNotationParserTest.php       # 新規：表記ごとの読み取り・正規化・丸めの境目・範囲・各エラー
    │   ├── Input/CoordinateQueryParserTest.php          # 2 欄の組み合わせ・ignored・swapped・正規形・needsRedirect。001 のヒントの期待を更新
    │   └── ViewModel/ForecastPageViewModelFactoryTest.php # inputNotice・staleNotice・favoriteTarget.label
    ├── Functional/
    │   ├── ForecastPageTest.php                 # 303 と Location・ignored の通知・422 の文言・従来の全角入力の期待をリダイレクトに更新
    │   └── CoordinateInputMarkupTest.php        # 新規：入力欄の属性・現在地の領域（hidden）・案内・食い違いの案内の有無・保存される地点・断定表現なし
    └── JavaScript/
        ├── position-format.test.js              # 新規
        ├── location-request.test.js             # 新規：成功・拒否・失敗・15 秒（mock.timers）・遅れて届いた結果・busy
        └── no-html-injection.test.js            # 対象に assets/coordinate-input/ を追加
```

**Structure Decision**: 既存の `backend/` の Symfony アプリに追加する。表記の読み取りは既存の `Presentation/Web/Input` に集め、Domain・Application・Infrastructure は変更しない。
JavaScript は 002 と同じく `assets/<機能>/` に置き、規則（`position-format.js`）・外部とのやり取り（`location-request.js`）・DOM（`coordinate-input-ui.js`）に分けて、前の 2 つを Node.js でテストする。
Deptrac・Dockerfile・CI の設定は変更しない（`composer test:js` は `tests/JavaScript/` 全体を実行するので新しいテストも含まれる）。

## Complexity Tracking

違反なし。

# Research: お気に入り地点の保存と呼び出し

**Feature**: 002-favorite-locations | **Date**: 2026-10-05

Technical Context に NEEDS CLARIFICATION は残っていない。以下は、仕様を実装に落とすときに判断が必要だった点の決定事項。
前提として 001-marine-forecast-view（`GET /` と `GET /forecast?lat=..&lon=..`）が実装済み。

---

## R1. お気に入りの処理をどこで行うか

**Decision**: お気に入りの保存・読み込み・削除・名前変更・一覧の描画は、すべてブラウザの JavaScript（AssetMapper で配信する ES Module）で行う。
サーバー（Symfony）は、お気に入り UI の「枠」となる HTML と、予報画面で表示中の地点（丸めた緯度・経度）を `data-*` 属性で渡すだけにする。
お気に入りのためのルート・Controller・UseCase・Domain Object は作らない。

**Rationale**:
- FR-010 / SC-006：お気に入りの内容をサーバーに送ってはならない。サーバーで扱う余地がない
- 原則 I：localStorage に保存し、サーバー側にテーブルを作らない
- お気に入りの「規則」（30 文字・20 件・重複判定）はブラウザ内でしか評価できない。PHP 側に同じ規則を重複して置くと食い違う
- アプリ化（Flutter）のときは端末内保存を Flutter 側で作り直すため、今サーバーに抽象化を置いても再利用されない（YAGNI）

**Alternatives considered**:
- PHP の Domain に `FavoriteLocation` を作り、JSON で受け渡す：サーバーに送らない以上、使う場面がない
- Stimulus（symfony/stimulus-bundle）を導入する：1 機能のために依存とお作法を増やすほどの UI 量ではない。素の ES Module で足りる

---

## R2. 表示中の地点の座標をどう JavaScript に渡すか

**Decision**: `MarineForecastResult`（Application の DTO）に、UseCase が生成した `Coordinate` の丸め済み緯度・経度（`float`）を常に持たせる。
`ForecastPageViewModelFactory` がそれを `'%.2f'` の文字列にして `ForecastPageViewModel::$favoriteTarget` に入れ、
Twig が保存パネルの `data-latitude` / `data-longitude` に出力する。入力エラー（422）のときは `favoriteTarget` を `null` にして保存パネルを出さない。

**Rationale**:
- 同一地点の判定は「小数点以下 2 桁に丸めた値」（FR-003、spec Key Entities の Coordinate と同じ）。丸めの規則を `Coordinate` の 1 か所に保つため、
  JavaScript で入力値を丸め直さない。JavaScript は受け取った文字列を数値にして使うだけ
- 取得失敗（503）・回数制限（429）でも保存できる必要がある（spec Edge Cases）。現状の `MarineForecastResult` は予報がないと座標を持たないので、結果側に座標を足す
- Presentation は Domain を参照できない（Deptrac）。ViewModel で `round()` を書くと丸め規則が二重になる

**Alternatives considered**:
- JavaScript で URL の `lat` / `lon` を読み、`Math.round` で丸める：全角数字などの入力正規化（001 research R7）と丸め規則（PHP の `round` は 0.5 を 0 から遠い方へ丸める）を JavaScript で再実装することになる
- ViewModel Factory で `round($query->latitude, 2)`：上記のとおり丸め規則の重複

---

## R3. localStorage の保存形式

**Decision**: キー `umiyomi.favorites` に、次の JSON を 1 つ保存する（詳細は [data-model.md](data-model.md)）。

```json
{"version":1,"items":[{"latitude":27.75,"longitude":129.05,"name":"テスト沖","savedAt":"2026-10-05T11:15:00.000Z"}]}
```

- `name` は前後の空白を除いた入力値。空欄で保存したときは空文字列で保存し、表示時に「27.75, 129.05」を代わりに出す
- 並び順は `savedAt` の降順で決める（配列の順に依存しない）。名前変更では `savedAt` を変えない（FR-008）
- 読み込み時に、形式が不正な項目・範囲外の座標・31 文字以上の名前・重複した地点（新しい方を残す）を捨て、残りだけを表示する（spec Edge Cases の「一部が壊れている」）
- JSON 全体が壊れている場合は空の一覧として扱う。次に保存・削除・名前変更したときに、読めた項目だけで上書きする

**Rationale**:
- `version` を持たせ、将来形式を変えるとき（アプリ化での移行など）に旧形式を判別できるようにする
- 1 キーにまとめると、保存・削除が 1 回の `setItem` で済み、途中で失敗して不整合になる状態がない
- 名前を空文字列で持つと、「名前を付けていない」状態を保てる。名前変更の入力欄も空から始められる

**Alternatives considered**:
- 項目ごとに別キー：件数上限・並び順の判定に全キーの走査が必要になり、途中失敗で不整合が起きうる
- IndexedDB：20 件・数 KB の用途には過剰。非同期 API で実装が増える
- Cookie：毎リクエストでサーバーに送られるため FR-010 / SC-006 に反する

---

## R4. 名前の文字数の数え方と入力の扱い

**Decision**:
- 前後の空白（`String.prototype.trim()`。全角スペースを含む）を除いてから判定する。除いて空なら「名前なし」
- 文字数は Unicode のコードポイント数（`Array.from(name).length`）で数え、30 を超えたら保存せず「名前は 30 文字以内で入力してください」を表示する
- 入力欄に `maxlength` は付けない

**Rationale**:
- `maxlength` と `String.length` は UTF-16 単位で数えるため、絵文字などで利用者の感覚とずれる。さらに `maxlength` は貼り付けた文字列を黙って切り詰め、
  spec の「保存せず、30 文字以内で入力するよう案内する」と異なる挙動になる
- 結合文字・異体字セレクタを 1 文字として数える（書記素単位）には `Intl.Segmenter` が要るが、30 文字はスマホでの見え方の目安（spec Assumptions）なので、
  コードポイントで十分。上限付近での多少のずれは許容する

**Alternatives considered**: `Intl.Segmenter` による書記素単位のカウント — 正確だが、この用途に対して実装とテストの手間が見合わない。

---

## R5. 名前の表示と安全性（FR-014）

**Decision**: 名前を DOM に入れるときは `textContent` / `value` / `setAttribute` だけを使い、`innerHTML` / `insertAdjacentHTML` を使わない。
一覧の項目の HTML は Twig の `<template>` 要素に置き、JavaScript は `cloneNode` して文字列を差し込む。

**Rationale**: 名前に `<`・`>`・`"` などが含まれても、文字として表示され、マークアップとして解釈されない。HTML の構造と UI 文言を Twig 側に集められ、
twig-cs-fixer と Functional Test の対象になる。

---

## R6. UI 文言の置き場所

**Decision**: お気に入り機能の UI 文言（ボタン名、案内、エラーメッセージ、削除確認の文面）は Twig に書く。
状態ごとのメッセージは `hidden` 属性付きの要素として出力し、JavaScript は表示・非表示を切り替えるだけにする。
件数や名前を埋め込む文面（削除確認など）は、`data-*` 属性に `{name}` を含むひな形を置き、JavaScript が置き換える。

**Rationale**: 文言を 1 か所（Twig）に集めると、断定表現がないこと（FR-013、原則 IV）を Functional Test でまとめて確認できる。
JavaScript に文言を散らすと、PHP のテストでは確認できない。

---

## R7. 削除の確認方法

**Decision**: 削除（トップ画面の一覧・予報画面の「お気に入りから外す」とも）の前に `window.confirm()` で「『テスト沖』をお気に入りから削除しますか？」と確認する。

**Rationale**: スマホを含む全ブラウザで標準のダイアログが出て、キーボード・スクリーンリーダーでも操作できる。独自のモーダルはフォーカス管理などの実装が増える。
FR-007 / US2-2 の「誤操作で消さないよう確認がある」を最小の実装で満たす。

**Alternatives considered**: `<dialog>` 要素による独自の確認 — 見た目は揃えられるが、この機能の段階では不要。元に戻す（Undo）表示 — 状態管理が増える。

---

## R8. 予報画面のお気に入り一覧の折りたたみ

**Decision**: `<details>` / `<summary>` を使い、`open` 属性を付けずに出力する（最初は閉じた状態）。`<summary>` はサーバー側で常に出力し、件数は JavaScript が書き込む。

**Rationale**:
- JavaScript なしで開閉でき、キーボード・スクリーンリーダーでも標準の挙動になる
- 閉じているあいだは `<summary>` の 1 行だけなので、件数が増えても予報一覧が下に押し出されない（FR-005）
- `<summary>` をサーバー側で出すことで、JavaScript の実行後に予報一覧の位置がずれない

---

## R9. localStorage が使えない環境・JavaScript が無効な環境

**Decision**:
- 起動時に `localStorage` へテスト用のキーを書いて消し、例外が出たら「使えない」と判定する。使えない場合は、お気に入りの枠の中に
  「この環境ではお気に入りを保存・表示できません。緯度・経度を入力すれば予報は表示できます」を出し、保存パネル・一覧は出さない（FR-011）
- JavaScript が無効な環境には、同じ文言を `<noscript>` で出す
- 保存時の `setItem` が容量超過などで失敗したら、保存しなかったことを表示する
- お気に入りの処理で例外が起きても、予報の表示には影響しないよう、初期化全体を `try` で囲む（SC-004）。予報の表示はサーバー側の HTML で完結しており、JavaScript に依存しない

**Rationale**: プライベートブラウズや、サイトデータの保存を拒否する設定では `localStorage` へのアクセス自体が例外になる。001 の予報画面は JavaScript なしで動く設計なので、
お気に入りが使えないことで閲覧が止まる経路はない。

---

## R10. 複数タブ

**Decision**: `storage` イベントによる他タブの変更の反映はしない。各画面は読み込み時に localStorage を読み、操作のたびに最新の内容を読み直してから書き込む。

**Rationale**: spec は「再読み込みで反映されればよい」。ただし、古い一覧を持ったまま書き込むと他タブの保存を消してしまうため、書き込みの直前に読み直す。

---

## R11. JavaScript のテスト

**Decision**:
- お気に入りの規則（名前の正規化・30 文字・20 件・重複判定・並び順・読み込み時の破損項目の除外・保存形式）を、DOM に依存しない ES Module
  （`assets/favorites/favorite-list.js`）と、`Storage` を引数で受け取る保存モジュール（`assets/favorites/favorite-store.js`）に分ける
- これらを Node.js 標準のテストランナー（`node --test`、npm パッケージなし）でテストする。テストは `backend/tests/JavaScript/` に置く（AssetMapper の配信対象外）
- 開発用イメージ（`backend/Dockerfile`）に Debian の `nodejs` パッケージ（trixie で 20.x）を追加し、`composer test:js` で実行できるようにする。`composer check` にも含める
- CI の `php` ジョブに `node --test` のステップを足す（ubuntu-latest には Node.js が入っている。`actions/setup-node` で 20 に揃える）
- 本番用イメージ（`Dockerfile.prod`）には Node.js を入れない。AssetMapper はビルド不要のため
- DOM の操作（描画・イベント）は、quickstart の手動確認と、Functional Test による HTML の枠（`data-*` 属性・`<template>`・文言）の確認で担保する

**Rationale**: お気に入りの規則は、001 でいう Domain Logic・Value Object・座標 validation に当たり、原則 V で自動テストが必須の領域。
それを担うのが JavaScript なので、JavaScript のテストが要る。Node.js の標準テストランナーなら `package.json`・npm 依存・ビルドを持ち込まずに済む。

**Alternatives considered**:
- ブラウザを動かす E2E（Playwright / Symfony Panther）：DOM まで確認できるが、ブラウザと依存が増え、CI も重くなる。MVP の段階では見送る
- PHP の Functional Test だけ：規則の多くが JavaScript にあるため、原則 V を満たせない
- Vitest / Jest：npm と `package.json` の管理が増える。標準のテストランナーで足りる

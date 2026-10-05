---

description: "Task list for 003-coordinate-input-support"
---

# Tasks: 緯度・経度の入力サポート

**Input**: Design documents from `/specs/003-coordinate-input-support/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/web-ui.md, quickstart.md

**Tests**: Constitution 原則 V の重点領域（座標 validation・ViewModel 変換・HTTP の主要経路）に当たるため、テストを必須とし、実装より先に書いて失敗することを確認する。
表記の読み取り・正確な丸め・範囲・2 欄の組み合わせは PHPUnit の Unit、HTTP → リダイレクト → 予報画面と文言・マークアップは Functional、
現在地の取得規則と 2 桁の書式は `node --test` で確認する。DOM の動作（ボタン・メッセージ・食い違いの案内）は quickstart の手動確認で担保する（research R10）。

**Organization**: ユーザーストーリーごとにフェーズを分け、各ストーリーを単独で実装・テストできるようにする。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 並列に実行できる（別ファイルで、未完了のタスクに依存しない）
- **[Story]**: 対応するユーザーストーリー（US1〜US4）
- パスはリポジトリ直下からの相対パス。Symfony 本体は `backend/`、名前空間は `App\` = `backend/src/`、`App\Tests\` = `backend/tests/`

## 実装時の共通ルール

- コマンドはリポジトリ直下で `docker compose exec php ...` として実行する（CLAUDE.md）
- PHP は `declare(strict_types=1);`、新規クラスは `final readonly class`（enum を除く）。JavaScript は ES2022 の ES Module（ビルドなし・npm パッケージなし）。コメントは日本語で「なぜ」だけを書く
- 表記の読み取りは `backend/src/Presentation/Web/Input/` だけに置く。Domain（`Coordinate`）・Application（UseCase・DTO）・Infrastructure は変更しない。新しいクラスは Symfony に依存させない（research R1）
- 正確な丸め（FR-023）は浮動小数点を使わず、数字の文字列から整数の hundredths を求める（research R2 の表の式）。`(float)` 変換・`round()`・bcmath を使わない
- JavaScript は入力欄の文字列を座標として読まない（読み取りはサーバーだけ）。文言は Twig に置き、JavaScript は `hidden` / `disabled` の切り替えと入力欄への値の設定だけをする（contracts/web-ui.md「文言の制約」）
- DOM には `textContent` / `value` / `setAttribute` だけを使い、`innerHTML` などを使わない
- 文言は contracts/web-ui.md の表の文字列を一字一句そのまま使う。「安全です」「出航できます」「問題ありません」のような断定を入れない（原則 IV）
- Presentation から Domain を参照しない。Deptrac の設定は変更しない
- 各フェーズの最後で `docker compose exec php composer check` を通す

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 新しいパッケージ・拡張・設定は追加しない（plan.md）。変更前の基準を確認するだけ

- [X] T001 ブランチ `003-coordinate-input-support` で `docker compose up -d` のあと `docker compose exec php composer check` がすべて通ることを確認する（失敗した場合は既存の問題として先に報告し、この機能の作業と混ぜない）

**Checkpoint**: 変更前の `composer check` が通っている

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 全ストーリーが使う「1 欄の値 1 つ（十進数・方角の文字）」の読み取り、正確な丸め、正規形の URL への 303 リダイレクト

**⚠️ CRITICAL**: このフェーズが終わるまで、どのユーザーストーリーにも着手しない

### Tests for Foundational ⚠️

> 先に書き、失敗することを確認してから実装する

- [X] T002 [P] `backend/tests/Unit/Presentation/Web/Input/AngleTest.php` を作成し、`Angle` を次で確認する：
  - `toFloat()`：hundredths 2776 → `27.76`、-2776 → `-27.76`、0 → `0.0`（`hundredths / 100`）
  - `toCanonicalString()`：2776 → `'27.76'`、-505 → `'-5.05'`、0 → `'0.00'`、12905 → `'129.05'`、`-0.00` を出さない（hundredths 0 で符号付きの入力から作っても `'0.00'`）
  - `isWithin(90)`：`wholeDegrees` 89 + `hasFraction` true → true、90 + false → true、90 + true → false（90.004 を丸めで範囲内に入れない）、91 → false。`isWithin(180)` も同様
- [X] T003 [P] `backend/tests/Unit/Presentation/Web/Input/CoordinateNotationParserTest.php` を作成し、値 1 つ（十進数）を DataProvider で確認する。期待は `ParsedField` の `kind`・`first->hundredths`・`first->axis`・`error`：
  - 正規化（research R3）：`' 27.75 '`、`'２７．７５'`、全角空白（U+3000）・タブで囲んだ値、`'－27.75'`、`'−27.75'`（U+2212）、`'＋27.75'`、`'(27.75)'`、`'（27.75）'` → Single
  - 十進数の形：`'27'`、`'27.'`、`'.5'`（50）、`'+27.75'`、`'-27.75'`（-2775）、`'27.75°'`、`'27.75 °'`（度の記号付きは十進数。FR-005）
  - 正確な丸め（FR-023、research R2）：`'27.755'` → 2776、`'-27.755'` → -2776、`'27.754'` → 2775、`'27.7549999999999999'` → 2775、`'27.7500'` → 2775、`'0.005'` → 1、`'-0.004'` → 0、`'129.045'` → 12905
  - 方角の文字（FR-006）：`'27.75N'`、`'N27.75'`、`'N 27.75'`、`'27.75 n'`、`'Ｎ27.75'`、`'北緯27.75'` → 2775・`Axis::Latitude`。`'27.75S'`・`'南緯27.75'` → -2775・Latitude。`'129.05E'`・`'東経129.05'` → Longitude、`'129.05W'`・`'西経129.05'` → -12905・Longitude。方角の文字がなければ `axis` は null
  - `wholeDegrees` / `hasFraction`：`'90'` → 90 / false、`'90.004'` → 90 / true、`'90.000'` → 90 / false、`'12345.6'` → 999（4 桁以上は 999 に寄せる）
  - エラー：`''`・`'   '`・`'()'` → Empty（`error` は `NotationError::Empty`）、101 コードポイント（例：`str_repeat('1', 101)`、全角 101 文字）→ `TooLong`、100 コードポイントは TooLong にならない、不正な UTF-8（`"\xff"`）→ `Unreadable`、`'abc'`・`'27.75.1'`・`'1e5'`・`'N'` → `Unreadable`、`'N27.75S'` → `ConflictingDirections`、`'-27.75N'`・`'+27.75N'` → `SignAndDirection`
- [X] T004 [P] `backend/tests/Unit/Presentation/Web/Input/CoordinateQueryParserTest.php` を更新する：
  - 既存の `DECIMAL_HINT`（「十進数（例：27.75）で入力してください」）を前提にした期待を削除し、contracts/web-ui.md の文言（`unreadable` は「緯度を読み取れませんでした。入力例：27.75 / 27.75, 129.05 / 27°45.0'N / 27°45'00"N」、`empty` は「経度を入力してください」、`out_of_range` は「緯度は -90〜90 の範囲で入力してください」「経度は -180〜180 の範囲で入力してください」）に置き換える
  - `axis_mismatch`：緯度欄 `'27.75E'` → 「緯度には N（北緯）か S（南緯）を付けてください。E・W は経度に使います」、経度欄 `'129.05N'` → 「経度には E（東経）か W（西経）を付けてください。N・S は緯度に使います」
  - `sign_and_direction`・`conflicting_directions`・`too_long` の文言（`{欄}` が欄の名前になる）
  - 範囲は丸める前の値で判定：`'90'` は有効、`'90.004'` は `out_of_range`、`'-180'` は有効、`'180.001'` は `out_of_range`
  - 正規形と `needsRedirect()`：`'27.75'` / `'129.05'` → canonical `'27.75'` / `'129.05'`・needsRedirect false・ignoredField null。`'27.7500'`、`'27.755'`（→ `'27.76'`）、`'２７．７５'`、`' 27.75'`、`'27.75N'`、`'+27.75'`、`'-0.00'`（→ `'0.00'`）→ needsRedirect true。`'0.00'` は false。エラーのとき canonical は null・needsRedirect false
  - `latitude` / `longitude` の float は `Angle::toFloat()` の値（`'27.755'` → `27.76`）
- [X] T005 [P] `backend/tests/Functional/ForecastPageTest.php` を更新する：
  - `testFullWidthInputIsKeptInFormWithoutRedirect` を、`/forecast?lat=２７．７５&lon=129.05`（`rawurlencode`）が 303 で `Location: /forecast?lat=27.75&lon=129.05` になり、リダイレクト先の `#lat` の value が `27.75` になる確認に書き換え、名前も合わせて変える
  - `testEquivalentCoordinatesShareCachedForecast`：`lat=27.7500` は 303 になり、リダイレクト元では予報を取得しない（Fake Provider の呼び出し回数が増えない）ことを確認するよう更新する
  - 追加：`/forecast?lat=27.755&lon=129.05` → 303・`Location: /forecast?lat=27.76&lon=129.05`
  - 追加：リダイレクト元では回数制限にも数えない（30 回の 303 のあとに新しい地点を開いても 429 にならない）
  - 追加：正規形の URL（`lat=27.75&lon=129.05`）は 200 でリダイレクトしない（リダイレクトが繰り返されない）
  - 追加：`/forecast?lat=27.75E&lon=129.05` → 422、緯度欄のエラーに「E・W は経度に使います」を含み、`#lat` の value に `27.75E` が残る

### Implementation for Foundational

- [X] T006 [P] `backend/src/Presentation/Web/Input/Axis.php` に enum `Axis`（`Latitude` / `Longitude`）を作成する
- [X] T007 [P] `backend/src/Presentation/Web/Input/NotationError.php` に enum `NotationError` を作成し、data-model.md の 16 個（`Empty` / `TooLong` / `Unreadable` / `Url` / `ConflictingDirections` / `SignAndDirection` / `DegreeNotInteger` / `MinuteSecondRange` / `AxisMismatch` / `OutOfRange` / `TooManyValues` / `UnitlessDegreeMinutes` / `PairDirectionMixed` / `PairSameAxis` / `SwappedOrder` / `BothPairs`）をすべて定義する（文言は持たない。欄の名前を埋め込む `CoordinateQueryParser` が変換する）
- [X] T008 [P] `backend/src/Presentation/Web/Input/FieldKind.php` に enum `FieldKind`（`Empty` / `Single` / `Pair` / `Invalid`）を、`backend/src/Presentation/Web/Input/ParsedField.php` に `ParsedField`（`kind`・`?Angle $first`・`?Angle $second`・`?NotationError $error`・`bool $looksLikePair`）を作成する。不正な組み合わせを作れないよう、`empty()` / `single(Angle)` / `pair(Angle, Angle)` / `invalid(NotationError, bool $looksLikePair = false)` の名前付きコンストラクタにし、`Empty` の `error` は `NotationError::Empty` にする
- [X] T009 [P] `backend/src/Presentation/Web/Input/Angle.php` に `Angle`（`int $hundredths`・`?Axis $axis`・`int $wholeDegrees`・`bool $hasFraction`）を作成し、`isWithin(int $limit)`（`wholeDegrees < limit || (wholeDegrees === limit && !hasFraction)`）・`toFloat()`（`hundredths / 100`）・`toCanonicalString()`（`sprintf('%.2f')`、`-0.00` は `0.00`）を実装する。T002 を通す
- [X] T010 `backend/src/Presentation/Web/Input/CoordinateNotationParser.php` を作成し、`parse(string $raw): ParsedField` のうち次を実装する（T003 を通す。度分・度分秒・1 行は後のストーリーで足すので、`body` の読み取りは表記ごとのメソッドに分けておく）：
  - 100 コードポイント超 → TooLong（`mb_strlen` の前に `mb_check_encoding` で不正な UTF-8 を Unreadable にする）
  - research R3 の対応表をすべて `strtr` の定数として持つ（度分秒の記号・`''` → `"` も含めてここで入れる。Unicode の NFKC は使わない）。前後の空白の除去、全体を囲む括弧 1 組の除去、空 → Empty
  - data-model.md「値 1 つ」の `[dir] [sign] body [dir]` のうち、`body = decimal ␣* ["°"]` を読む。方角の文字の前後両方 → ConflictingDirections、方角と符号の両方 → SignAndDirection、それ以外の不一致 → Unreadable
  - 十進数の hundredths：`100·D + f1f2 + (f3 ≥ 5 ? 1 : 0)` を大きさで求めてから符号を付ける（0 から遠い方向への四捨五入）。整数部は先頭の 0 を除いた桁数が 4 以上なら `wholeDegrees` を 999 にし、`int` の桁あふれを防ぐ
- [X] T011 `backend/src/Presentation/Web/Input/CoordinateQuery.php` に `?string $canonicalLatitude`・`?string $canonicalLongitude`・`'latitude'|'longitude'|null $ignoredField` を追加し、`needsRedirect(): bool`（有効で、`rawLatitude !== canonicalLatitude || rawLongitude !== canonicalLongitude`。1 行の文字列を使った場合もここで真になる）を実装する。PHPDoc の型を更新する
- [X] T012 `backend/src/Presentation/Web/Input/CoordinateQueryParser.php` を書き換える：`FULL_WIDTH`・`DECIMAL_PATTERN`・`DEGREE_NOTATION_PATTERN`・`DECIMAL_HINT` と `(float)` 変換を削除し、`CoordinateNotationParser` をコンストラクタで受け取って 2 欄をそれぞれ読む。シグネチャを `parse(?string $latitude, ?string $longitude, ?string $ignored = null)` にする（`$ignored` はこのフェーズでは未使用でよい）。軸の検査（緯度欄に `Axis::Longitude` → AxisMismatch、逆も）・範囲の検査（`isWithin(90)` / `isWithin(180)`）を行い、`NotationError` → contracts/web-ui.md の文言への変換を 1 つのメソッド（`match`）にまとめる（全原因の文言をここで定義してよい）。class の PHPDoc の「全角変換などの独自の正規化が中心になるため」を現状に合わせて直す。T004 を通す
- [X] T013 `backend/src/Presentation/Web/Controller/ForecastController.php` で `$request->query->getString('ignored')` も parser に渡し、`$query->needsRedirect()` のとき UseCase を呼ばずに `redirectToRoute('app_forecast', ['lat' => canonicalLatitude, 'lon' => canonicalLongitude] + (ignoredField があれば ['ignored' => 'lat'|'lon']), Response::HTTP_SEE_OTHER)` を返す。リダイレクト元で予報を取得しない理由（回数制限に数えないため）をコメントに書く。T005 を通す
- [X] T014 `backend/config/services.yaml` の autowire で `CoordinateNotationParser` が `CoordinateQueryParser` に注入されることを `docker compose exec php composer lint:symfony` で確認し、`docker compose exec php composer check` を通す（deptrac で Presentation → Domain の参照がないことを含む）

**Checkpoint**: 十進数（全角・方角の文字・度の記号を含む）の 1 欄ずつの入力が正確に丸められ、正規形でなければ 303 で十進数の URL にそろう。001・002 の既存テストが通る

---

## Phase 3: User Story 1 - 地図アプリでコピーした「緯度, 経度」をそのまま貼り付ける (Priority: P1) 🎯 MVP

**Goal**: 片方の欄に貼り付けた「緯度, 経度」の 1 行から地点を読み取り、十進数の URL・入力欄にそろえて予報を表示する。もう一方の欄の値を使わなかったことを知らせ、順序が逆の可能性を案内する

**Independent Test**: トップ画面の緯度欄に「27.75, 129.05」を貼り付けて送信し、`/forecast?lat=27.75&lon=129.05` の予報画面が表示され、入力欄に `27.75` と `129.05` が分かれて入ることを確認する

### Tests for User Story 1 ⚠️

- [X] T015 [P] [US1] `backend/tests/Unit/Presentation/Web/Input/CoordinateNotationParserTest.php` に値 2 つ（data-model.md「値 2 つ」）の DataProvider を追加する：
  - Pair になる：`'27.75, 129.05'`、`'27.75,129.05'`、`'27.75 129.05'`、`'27.75、129.05'`、`'27.75，129.05'`、`'(27.75, 129.05)'`、`'２７．７５，１２９．０５'`、`'27.75000° N, 129.05000° E'`（iPhone の「マップ」）、`'N27.75 E129.05'`、`'27.75N 129.05E'`、`'129.05E 27.75N'`（first/second は文字列の順のまま、axis は E・N）、`'-33.86, 151.21'`、`'35.00, 45.00'`
  - `looksLikePair` が true の Invalid：`'27.75, 129.05, 10'` → TooManyValues、`'27 45.0 129 03.0'`・`'27 45 129 3'` → UnitlessDegreeMinutes、`'N27 45.0'`・`'27.75N, 129.05'` → PairDirectionMixed、`'27N 129N'`・`'27.75E 129.05W'` → PairSameAxis、`'27.75, abc'` → Unreadable
  - Single のまま：`'27.75'`、`'27.75 N'`、`'N 27.75'`（方角の文字と数値の間の空白で Pair にしない）
- [X] T016 [P] [US1] `backend/tests/Unit/Presentation/Web/Input/CoordinateQueryParserTest.php` に 2 欄の組み合わせ（data-model.md の表 4）を追加する：
  - 緯度欄 `'27.75, 129.05'` / 経度欄 `''` → 27.75 / 129.05、canonical `'27.75'` / `'129.05'`、ignoredField null、needsRedirect true
  - 緯度欄 `'35.10, 139.20'` / 経度欄 `'129.05'` → 35.10 / 139.20、ignoredField `'longitude'`。経度欄が `'abc'` でもエラーにしない
  - 緯度欄 `''` / 経度欄 `'27.75, 129.05'` → 27.75 / 129.05。緯度欄 `'10'` / 経度欄 `'27.75, 129.05'` → ignoredField `'latitude'`
  - 両方 `'27.75, 129.05'` → 両方の欄に both_pairs の文言
  - 1 行の中身の誤り：緯度欄 `'27.75, 129.05, 10'` / 経度欄 `'129.05'` → 緯度欄だけに too_many_values の文言、経度欄にエラーなし
  - 方角の文字で軸を割り当てる：`'129.05E 27.75N'` → 27.75 / 129.05
  - `'129.05, 27.75'` → 緯度欄に swapped_order の文言（入れ替えない。FR-022）。`'35.00, 45.00'` → 35.00 / 45.00（入れ替えない。US1-5）
  - `'95.00, 200.00'` → 緯度欄に「緯度は -90〜90 の範囲で入力してください」だけ（入れ替えても範囲外なので swapped_order にしない。両方とも範囲外なら緯度の文言を出す）。`'27.75, 200'` → 「経度は -180〜180 の範囲で入力してください」、`'95, 129'`（入れ替えると 129 が緯度の範囲外なので swapped_order にしない）→ 「緯度は -90〜90 の範囲で入力してください」を、どちらも 1 行の入っている欄に出す
  - `'N95 E129'` は方角の文字があるので swapped_order にせず out_of_range
  - `$ignored`：正規形の入力（`'35.10'` / `'139.20'`）と `$ignored = 'lon'` → ignoredField `'longitude'`、`'lat'` → `'latitude'`、`'foo'` → null。正規形でない入力のときは `$ignored` を使わない
- [X] T017 [P] [US1] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` に `inputNotice` を追加する：ignoredField `'longitude'` → 「この地点は緯度欄の「緯度, 経度」から読み取りました。経度欄に入っていた値は使っていません」、`'latitude'` → 経度欄・緯度欄を入れ替えた文言、null → null、入力エラー（`createForInvalidInput`）→ null
- [X] T018 [P] [US1] `backend/tests/Functional/ForecastPageTest.php` に US1 の経路を追加する：
  - `lat=27.75, 129.05&lon=`（`rawurlencode`）→ 303 `Location: /forecast?lat=27.75&lon=129.05`、`followRedirect()` 後に 200・`#lat` = `27.75`・`#lon` = `129.05`
  - `lat=27.75000° N, 129.05000° E&lon=` → 同上
  - `lat=35.10, 139.20&lon=129.05` → 303 `Location: /forecast?lat=35.10&lon=139.20&ignored=lon`、リダイレクト先に `role="status"` の入力の通知（「経度欄に入っていた値は使っていません」）が出る。`ignored` のない URL では出ない
  - `lat=&lon=27.75, 129.05` → 303 `Location: /forecast?lat=27.75&lon=129.05`
  - 両方に 1 行 → 422、両方の欄に「「緯度, 経度」はどちらか一方の欄だけに入力してください」
  - `lat=129.05, 27.75&lon=` → 422、「緯度と経度の順序が逆になっている可能性があります」、`#lat` に入力が残る
  - `lat=35.00, 45.00&lon=` → 303 → 「北緯 35.00° / 東経 45.00°」の予報画面

### Implementation for User Story 1

- [X] T019 [US1] `backend/src/Presentation/Web/Input/CoordinateNotationParser.php` に値 2 つの読み取りを追加する（T015 を通す）：空白で単純に分割せず、文字列全体を data-model.md の `pair` の文法（正規表現）で読む。値の中の空白（US2 の `27° 45.0' N`）と区切りの空白を区別するためで、US2（T027）で `body` の種類を増やしても分け方を変えずに済むよう、値 1 つの正規表現を部品として組み立てる。区切り（カンマ、または空白で区切られた 2 つ以上の値）があれば `looksLikePair = true` とし、カンマで 3 つ以上 → TooManyValues、記号のない数字が 3〜4 個 → UnitlessDegreeMinutes、それ以外の 3 つ以上 → TooManyValues。方角の文字が前置か後置かは文字列の先頭が方角の文字かどうかで決め、`N 27.75` / `27.75 N` を 1 つの値として扱う。片方だけ方角 → PairDirectionMixed、同じ軸 → PairSameAxis。各値は Phase 2 の値 1 つの読み取りを使う
- [X] T020 [US1] `backend/src/Presentation/Web/Input/CoordinateQueryParser.php` に data-model.md の表 4（2 欄の組み合わせ）・方角の文字による軸の割り当て・swapped_order（方角の文字がなく、先頭が緯度の範囲外で、入れ替えると両方とも範囲内のとき）・1 行の範囲外を範囲外の方の名前で出す処理・`$ignored`（正規形の入力のときだけ `lat` / `lon` を ignoredField に変換）を実装する。エラーは 1 行の文字列が入っている欄に出し、使わなかった欄は読まない。T016 を通す
- [X] T021 [US1] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModel.php` に `?string $inputNotice` を追加し、`backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` の `create()` で `CoordinateQuery::ignoredField` から contracts/web-ui.md の文言を作る（`createForInvalidInput()` は null）。T017 を通す
- [X] T022 [US1] `backend/templates/forecast/index.html.twig` のフォームの直後（お気に入り一覧の前）に、`page.inputNotice` があるときだけ `<p class="input-notice" role="status">` で通知を出す。T018 を通し、`docker compose exec php composer check` を通す

**Checkpoint**: US1 の Independent Test と受け入れシナリオ 1〜5 が通る。地図アプリからの貼り付け 1 回と送信 1 回で予報を表示できる（SC-001）

---

## Phase 4: User Story 2 - 度分・度分秒の表記で入力する (Priority: P2)

**Goal**: 度分・度分秒・ハイフン区切りの表記を十進数に正確に変換し、スマホで記号・方角の文字を手入力できるようにする

**Independent Test**: 緯度欄に「27°45.0'N」、経度欄に「129°03.0'E」を入力して送信し、`/forecast?lat=27.75&lon=129.05` の予報画面が表示されることを確認する

### Tests for User Story 2 ⚠️

- [X] T023 [P] [US2] `backend/tests/Unit/Presentation/Web/Input/CoordinateNotationParserTest.php` に度分・度分秒の DataProvider を追加する（SC-002 の表。期待は hundredths）：
  - 度分：`"27°45.0'N"`、`"27°45'N"`、`"27°45.0N"`（最後の記号を省略）、`"27° 45.0' N"`、`"N27°45.0'"`、`'27度45分'`、`'北緯27度45分'`、`"27d45.0'"`、`"27º45.0’"`（`º` とスマート句読点）、`'27°45.0′'`、`'２７°４５．０′'` → 2775。`"129°03.0'E"`・`'東経129度3分'` → 12905
  - 度分秒：`"27°45'00\"N"`、`"27°45'00''N"`、`'27°45′00″N'`、`'27°45’00”N'`、`'27度45分0秒'`、`"27°45'00N"`（秒の記号を省略）→ 2775
  - ハイフン区切り：`'N27-45.0'`・`'27-45.0N'` → 2775、`'N27-45-00'` → 2775、`'E129-03.0'` → 12905、`'27-45.0'`（方角の文字なし）→ Unreadable
  - 丸めの境目（FR-023、research R2）：`"27°45.3'"` → 2776、`"S27°45.3'"` → -2776、`"27°45'18\""` → 2776、`"27°45.29'"` → 2775（= 27.75483…）、`"27°45'17.9\""` → 2775、`"27°45'17.95\""` → 2775（= 27.754986…）、`"0°0.3'"` → 1、`"0°0'18\""` → 1
  - 符号・方角：`"S27°45.0'"`・`"27°45.0'S"`・`"-27°45.0'"` → -2775、`"W129°03.0'"` → -12905、`"-27°45'N"` → SignAndDirection
  - エラー：`"27.5°30'"` → DegreeNotInteger、`"27°75'N"`・`"27°60'"`・`"27°45'60\""` → MinuteSecondRange、`"27°45.5'30\""`（度分秒で分に小数）→ Unreadable
  - 範囲用の値：`"90°00'00\""` → wholeDegrees 90・hasFraction false、`"90°00.1'"` → hasFraction true
  - 1 行：`"N27°45.0' E129°03.0'"`、`"27°45.0'N 129°03.0'E"`、`"27°45.0'N, 129°03.0'E"`、`"27° 45.0' N 129° 03.0' E"`、`"27°45'00\" 129°03'00\""` → Pair（2775 / 12905）。`"27° 45.0'"` は Single（値の中の空白を区切りにしない）
- [X] T024 [P] [US2] `backend/tests/Unit/Presentation/Web/Input/CoordinateQueryParserTest.php` に、度分の範囲（`"90°00.1'N"` → 緯度の out_of_range、`"180°00'00\"W"` は有効）、degree_not_integer・minute_second_range の文言、`"27°45'E"` を緯度欄に入れたときの axis_mismatch を追加する
- [X] T025 [P] [US2] `backend/tests/Functional/ForecastPageTest.php` に US2 の経路を追加する：`lat=27°45.0'N&lon=129°03.0'E` → 303 `Location: /forecast?lat=27.75&lon=129.05`、`lat=27°45'00"N&lon=129°03'00"E` → 同上、`lat=N27°45.0' E129°03.0'&lon=` → 同上、`lat=S27°45.3'&lon=W129°03.0'` → `?lat=-27.76&lon=-129.05`、リダイレクト先の地点表示が十進数（「南緯 27.76° / 西経 129.05°」）で `#lat` の value が `-27.76`（US2-6）、`lat=27°75'N&lon=129.05` → 422「分・秒は 0 以上 60 未満で入力してください」
- [X] T026 [P] [US2] `backend/tests/Functional/CoordinateInputMarkupTest.php` を新規作成し（`FavoritesMarkupTest.php` と同じ構成）、トップ画面と予報画面（`/forecast?lat=27.75&lon=129.05`）の `#lat` / `#lon` が `type="text"`・`autocomplete="off"`・`autocapitalize="off"`・`autocorrect="off"`・`spellcheck="false"` で、`inputmode` 属性を持たないことを確認する（FR-020、research R7）

### Implementation for User Story 2

- [X] T027 [US2] `backend/src/Presentation/Web/Input/CoordinateNotationParser.php` に data-model.md の `body` のうち度分（`int ° decimal [']`）・度分秒（`int ° int ' decimal ["]`）・ハイフン区切り（方角の文字が必須）を追加する。hundredths は research R2 の式（度分 `100·D + floor((10·Mi + m1 + 3) / 6)`、度分秒 `100·D + floor((120·Mi + 2·Si + 36 + (s1 ≥ 5 ? 1 : 0)) / 72)`）で求め、式の根拠（下の桁が結果に影響しない理由）を短くコメントに書く。`hasFraction` は分・秒の数字に 0 以外があるか。度が整数でない → DegreeNotInteger、分・秒が 60 以上 → MinuteSecondRange、度分秒の分に小数 → Unreadable。記号のない数字の並びを度分と推測しない（FR-005）。T023・T024 を通す
- [X] T028 [US2] `backend/templates/forecast/_form.html.twig` の 2 つの入力欄から `inputmode="decimal"` を外し、`autocapitalize="off" autocorrect="off" spellcheck="false"` を付ける。先頭の Twig コメントを、記号・方角の文字・カンマを手入力するため文字のキーボードにする理由（FR-020）に書き換える。T025・T026 を通し、`docker compose exec php composer check` を通す

**Checkpoint**: US2 の受け入れシナリオ 1〜6 が通る。丸めの境目を含めて手計算の値と一致する（SC-002）

---

## Phase 5: User Story 3 - 受け付けられない入力のときに、どう直せばよいか分かる (Priority: P3)

**Goal**: 受け付ける表記の案内を常に表示し、読み取れない入力（リンクを含む）・長すぎる入力の原因と直し方を欄の近くに出す

**Independent Test**: 緯度欄に「北緯二十七度」を入れて送信し、予報が表示されず、入力できる表記の例を含む案内が緯度欄の近くに出て、入力した文字列が欄に残ることを確認する

### Tests for User Story 3 ⚠️

- [X] T029 [P] [US3] `backend/tests/Unit/Presentation/Web/Input/CoordinateNotationParserTest.php` に `'https://maps.app.goo.gl/xxxx'`・`'http://example.com/?q=27.75,129.05'`・`'HTTPS://…'` → `NotationError::Url`、`'北緯二十七度'` → Unreadable を追加する
- [X] T030 [P] [US3] `backend/tests/Unit/Presentation/Web/Input/CoordinateQueryParserTest.php` に、url の文言（「リンクからは地点を読み取れません。地図アプリで緯度・経度をコピーして貼り付けてください」）、unitless_degree_minutes・pair_direction_mixed・pair_same_axis・too_many_values（入力例付き）の文言、101 文字の too_long の文言を追加する（contracts/web-ui.md の表どおり）
- [X] T031 [P] [US3] `backend/tests/Functional/CoordinateInputMarkupTest.php` に、トップ画面・予報画面・422 の画面のフォームの下に入力の案内（FR-012「十進数（27.75）のほか、「緯度, 経度」の貼り付け（27.75, 129.05）、度分（27°45.0'N）、度分秒（27°45'00"N）で入力できます。南緯・西経はマイナスの値にするか、S・W を付けてください。」）が出ること、旧文言「十進数で入力してください。」が出ないことを追加する
- [X] T032 [P] [US3] `backend/tests/Functional/ForecastPageTest.php` に、`lat=北緯二十七度&lon=129.05` → 422・緯度欄のエラーに「入力例：27.75 / 27.75, 129.05 / 27°45.0'N / 27°45'00"N」を含み `aria-describedby="lat-error"`・`#lat` に入力が残る（US3-1・US3-3）、`lat=https://maps.app.goo.gl/xxxx&lon=` → 422「リンクからは地点を読み取れません」、`lat=` に 101 文字 → 422「入力が長すぎます」、`lat=N27.75S&lon=129.05` → 422「方角の文字が複数あります」（US3-2）を追加する

### Implementation for User Story 3

- [X] T033 [US3] `backend/src/Presentation/Web/Input/CoordinateNotationParser.php` で、正規化の前に `http://` / `https://`（大文字小文字を区別しない）で始まる入力を `NotationError::Url` にする（100 文字の判定より後、その他の読み取りより前）。T029 を通す
- [X] T034 [US3] `backend/src/Presentation/Web/Input/CoordinateQueryParser.php` の文言変換が contracts/web-ui.md の全原因と一字一句一致することを確認し、足りない文言・入力例の付け忘れを直す。T030 を通す
- [X] T035 [US3] `backend/templates/forecast/_form.html.twig` の `<p class="form-hint">`（送信ボタンの後ろのまま。US4 の T044 で現在地の領域をボタンとこの案内の間に入れる）の文言を、contracts/web-ui.md の「入力の案内」に置き換える。T031・T032 を通し、`docker compose exec php composer check` を通す

**Checkpoint**: US3 の受け入れシナリオ 1〜4 が通る。案内だけで入力を直せる（SC-003）

---

## Phase 6: User Story 4 - 現在地の緯度・経度を入力欄に入れる (Priority: P4)

**Goal**: 「現在地を入力」で端末の現在地を 2 桁の十進数で入力欄に入れる（送信は利用者）。予報画面では入力欄が表示中の地点と食い違うことを案内し、保存パネルに保存される地点を示す

**Independent Test**: 位置情報を許可した端末でトップ画面の「現在地を入力」を押し、入力欄に現在地の 2 桁の値が入り、送信するまで予報が表示されないことを確認する

### Tests for User Story 4 ⚠️

- [X] T036 [P] [US4] `backend/tests/JavaScript/position-format.test.js` を作成し（`node:test`・`node:assert/strict`、`../../assets/coordinate-input/position-format.js` を import）、`formatPosition` を確認する：`{ latitude: 27.754, longitude: 129.0461 }` → `{ latitude: '27.75', longitude: '129.05' }`、`-33.8688` → `'-33.87'`、`-0.004` → `'0.00'`、`0` → `'0.00'`、`-0` → `'0.00'`、`90` → `'90.00'`、`-180` → `'-180.00'`
- [X] T037 [P] [US4] `backend/tests/JavaScript/location-request.test.js` を作成し、偽の `geolocation`（`getCurrentPosition(success, error, options)` を記録・後から呼べるもの）と `node:test` の `mock.timers`（`setTimeout` を有効にし、`setTimer` / `clearTimer` に `setTimeout` / `clearTimeout` を渡す）で `createLocationRequester` を確認する：
  - 成功 → `{ status: 'ok', latitude: '27.75', longitude: '129.05' }`（`formatPosition` を通す）、options が `{ enableHighAccuracy: false, maximumAge: 0 }` で `timeout` を含まない
  - `error.code === 1`（PERMISSION_DENIED）→ `{ status: 'denied' }`、`code` 2・3・その他 → `{ status: 'failed' }`
  - 14,999ms では未解決、15,000ms で `{ status: 'failed' }`。時間切れのあとに届いた成功・失敗は無視される（結果が変わらない）
  - 取得中に `request()` をもう一度呼ぶと即座に `{ status: 'busy' }` で、`getCurrentPosition` は 1 回しか呼ばれない。結果が出たあとは再び取得できる
  - `getCurrentPosition` が例外を投げた場合 → `{ status: 'failed' }`（取得中の状態が残らない）
- [X] T038 [P] [US4] `backend/tests/JavaScript/no-html-injection.test.js` の対象を `assets/favorites/` と `assets/coordinate-input/` の両方にする（ディレクトリごとに `describe` を分け、`coordinate-input` は 3 ファイル以上を見つけることを確認する）。あわせて `assets/coordinate-input/` のファイルが `localStorage`・`sessionStorage`・`indexedDB`・`fetch(`・`XMLHttpRequest`・`sendBeacon` を使わないことを確認する（FR-015：現在地を保存・送信しない。DOM の動作は自動テストできないため、ソースで確認する）
- [X] T039 [P] [US4] `backend/tests/Unit/Presentation/Web/ViewModel/ForecastPageViewModelFactoryTest.php` に追加する：
  - `form.staleNotice`：予報一覧があるとき「入力欄の地点の予報はまだ表示していません。表示中の一覧は 北緯 27.75° / 東経 129.05° の予報です。「予報を表示」を押すと、入力欄の地点の予報に切り替わります。」、予報一覧がないとき（503・429）と入力エラーのときは null
  - `favoriteTarget.label`：`北緯 27.75° / 東経 129.05°`、マイナスは `南緯 0.50° / 西経 120.00°`。予報が得られない 503・429 でも `MarineForecastResult` の緯度・経度から作られる
- [X] T040 [P] [US4] `backend/tests/Functional/CoordinateInputMarkupTest.php` に追加する：
  - トップ画面・予報画面のフォームに `data-coordinate-input`、`#lat` に `data-coordinate-field="latitude"`、`#lon` に `data-coordinate-field="longitude"`
  - `[data-current-location]` が `hidden` 付きで出力され、中に `type="button"` の `[data-current-location-action="fill"]`（「現在地を入力」）、`role="status"` の中に `data-current-location-message` の `loading` / `filled` / `denied` / `failed` / `edited` が各 1 つ `hidden` 付きで contracts/web-ui.md の文言どおりにある、FR-019 の案内がある
  - `[data-coordinate-stale-notice]`：予報一覧のある予報画面では `hidden` 付きで 1 つ（表示中の地点を含む文言）、トップ画面・422・503・429 の画面にはない
  - 予報画面の保存パネルに「保存される地点：北緯 27.75° / 東経 129.05°（表示中の地点）」
  - トップ画面・予報画面・422 の画面のどれにも「安全です」「出航できます」「問題ありません」を含まない

### Implementation for User Story 4

- [X] T041 [P] [US4] `backend/assets/coordinate-input/position-format.js` に `formatPosition({ latitude, longitude })` を作成する（`Math.abs(x).toFixed(2)` に符号を付け、`'0.00'` に負号を付けない。`toFixed` を使う理由をコメントに書く。research R8）。T036 を通す
- [X] T042 [US4] `backend/assets/coordinate-input/location-request.js` に `createLocationRequester({ geolocation, timeoutMs = 15000, setTimer, clearTimer })` を作成し、`request()` を data-model.md の結果型で実装する（15 秒は `request()` の時点から独自のタイマーで数え、`getCurrentPosition` の `timeout` に頼らない理由をコメントに書く。DOM に依存しない）。T037 を通す
- [X] T043 [US4] `backend/src/Presentation/Web/ViewModel/ForecastPageViewModel.php` の `@phpstan-type Form` に `staleNotice: string|null`、`FavoriteTarget` に `label: string` を追加し、`backend/src/Presentation/Web/ViewModel/ForecastPageViewModelFactory.php` で、地点の書式（既存の `location()` と同じ `北緯 27.75° / 東経 129.05°`）を緯度・経度の float から作る private メソッドに切り出して `location`・`staleNotice`（予報一覧があるときだけ）・`favoriteTarget.label` で共有する。`backend/src/Presentation/Web/Controller/HomeController.php` の `form` に `'staleNotice' => null` を足す。T039 を通す
- [X] T044 [US4] `backend/templates/forecast/_form.html.twig` を contracts/web-ui.md「入力フォーム」の順に組み直す：`<form>` に `data-coordinate-input`、入力欄に `data-coordinate-field`、送信ボタンの後ろに `hidden` 付きの `<div class="current-location" data-current-location>`（ボタン・`role="status"` のメッセージ 5 つ・FR-019 の案内）、その後ろに入力の案内（US3）、最後に `form.staleNotice` があるときだけ `<p class="stale-notice" data-coordinate-stale-notice hidden>`。トップ画面は `form` を渡さずに include しているので、`form.staleNotice` は `HomeController` の値で null になることを確認する
- [X] T045 [US4] `backend/templates/favorites/_save_panel.html.twig` の先頭に「保存される地点：{{ target.label }}（表示中の地点）」を出す（FR-025。入力欄を書き換えても変わらないよう Twig の値だけで出す）
- [X] T046 [US4] `backend/assets/coordinate-input/coordinate-input-ui.js` に `initCoordinateInput(document)` を作成する（research R8・R9、contracts/web-ui.md「Twig と JavaScript の取り決め」）：
  - `[data-coordinate-input]` のフォームごとに初期化。`'geolocation' in navigator && window.isSecureContext` のときだけ `[data-current-location]` の `hidden` を外す
  - ボタン押下で `request()` を呼ぶ前に 2 つの入力欄の `value` を覚え、ボタンを `disabled`・`loading` を表示。結果で `ok` かつ入力欄が変わっていなければ値を設定して `filled`、変わっていれば `edited`、`denied` / `failed` はそれぞれの文言。`busy` は何もしない。終わったら `disabled` を外す。メッセージは常に 1 つだけ表示する
  - `[data-coordinate-stale-notice]` があれば、`input` イベント・現在地の入力後・`pageshow` で、どちらかの欄の `value !== defaultValue` なら表示、両方同じなら隠す
  - 入力欄の文字列を座標として読まない。文言を組み立てない
- [X] T047 [US4] `backend/assets/app.js` で `initCoordinateInput` を import して `initFavorites(document)` と同じく `initCoordinateInput(document)` を呼ぶ。`backend/assets/styles/app.css` に `.current-location`・`.input-notice`・`.stale-notice`・保存される地点の見た目を追加し、長い文言・ボタンが 360px 幅で折り返して横にはみ出さないようにする（`overflow-wrap: anywhere` など）。T038・T040 を通し、`docker compose exec php composer check` を通す

**Checkpoint**: US4 の受け入れシナリオ 1〜9 が quickstart「US4：現在地」の手順で確認できる。位置情報を拒否・利用できない環境でも入力と予報の表示ができる（SC-006）

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 001 の契約の更新、文書との整合、全体の確認

- [X] T048 [P] `specs/001-marine-forecast-view/contracts/web-routes.md` の「入力された文字列はそのまま URL に残る（正規化した URL へのリダイレクトはしない）」の箇所に、003 で正規形の URL への 303 リダイレクトに変更したことと [specs/003-coordinate-input-support/contracts/web-ui.md](../../003-coordinate-input-support/contracts/web-ui.md) への参照を追記する（001 の元の記述は消さずに変更点として残す）
- [X] T049 [P] `backend/src/Presentation/Web/Input/` の新規クラス・`CoordinateQueryParser` のコメントを見直し、「何をしているか」だけのコメントを削り、「なぜ」（Presentation に置く理由・整数で丸める理由・方角の文字の有無をそろえる理由など）が残っていることを確認する
- [X] T050 `docker compose exec php composer check` を通す（cs:fix・PHPStan level max・deptrac・lint:symfony・composer:lint・PHPUnit・`node --test`）。Deptrac の違反があれば設定を緩めず依存の向きを直す
- [X] T051 `bash scripts/verify-prod.sh` で本番用イメージを起動し、`/forecast?lat=27%C2%B045.0%27N&lon=129%C2%B003.0%27E` が 303 で十進数の URL になり、アセット（`coordinate-input/*.js`）が配信されることを確かめる
- [ ] T052 quickstart.md の「ブラウザでの確認」（US1〜US4・360px 幅）を http://localhost:8000 で実施し、iPhone での確認（文字のキーボード・スマート句読点・自動修正なし・Geolocation）は本番（HTTPS）デプロイ後に人間が行う項目として PR の説明に残す

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 依存なし
- **Foundational (Phase 2)**: Phase 1 の後。すべてのユーザーストーリーをブロックする（値 1 つの読み取り・正確な丸め・303 リダイレクト）
- **US1 (Phase 3)**: Phase 2 の後。MVP
- **US2 (Phase 4)**: Phase 2 の後。US1 とは独立（1 行の度分 `N27°45.0' E129°03.0'` のテストだけは US1 の値 2 つの読み取りを使うので、US1 の後に通す）
- **US3 (Phase 5)**: Phase 2 の後。値 2 つのエラー文言のテスト（T030 の一部）は US1 の後に通す
- **US4 (Phase 6)**: Phase 2 の後。サーバー側の読み取りに依存しない（JavaScript・ViewModel・Twig）。`_form.html.twig` は US2（T028）・US3（T035）と同じファイルなので、順に編集する
- **Polish (Phase 7)**: 必要なストーリーがすべて終わった後

### Within Each User Story

- テストを先に書き、失敗することを確認してから実装する
- `CoordinateNotationParser` → `CoordinateQueryParser` → ViewModel → Twig / Controller の順
- JavaScript は `position-format.js` → `location-request.js` → `coordinate-input-ui.js` → `app.js` の順

### 同じファイルを編集するタスク（並列にしない）

- `CoordinateNotationParser.php`：T010 → T019 → T027 → T033
- `CoordinateQueryParser.php`：T012 → T020 → T034
- `_form.html.twig`：T028 → T035 → T044
- `CoordinateNotationParserTest.php` / `CoordinateQueryParserTest.php` / `ForecastPageTest.php` / `CoordinateInputMarkupTest.php`：ストーリーの順に追記する（別ストーリーのテストタスクを同時に書かない）

### Parallel Opportunities

- Phase 2：T002〜T005（テスト 4 ファイル）、T006〜T009（enum・値・結果の 4 ファイル）
- US1：T015〜T018（テスト 4 ファイル）
- US2：T023〜T026
- US3：T029〜T032
- US4：T036〜T040（テスト 5 ファイル）、T041 は T043〜T045（PHP・Twig）と並列にできる
- Phase 2 完了後、US4 の JavaScript（T036〜T038・T041・T042）は US1〜US3 と並列に進められる
- Polish：T048・T049

---

## Parallel Example: User Story 1

```text
# テストをまとめて書く（別ファイル）
Task: "T015 CoordinateNotationParserTest に値 2 つの DataProvider を追加"
Task: "T016 CoordinateQueryParserTest に 2 欄の組み合わせを追加"
Task: "T017 ForecastPageViewModelFactoryTest に inputNotice を追加"
Task: "T018 ForecastPageTest に US1 の経路を追加"
```

## Parallel Example: User Story 4

```text
# テストをまとめて書く（別ファイル）
Task: "T036 position-format.test.js"
Task: "T037 location-request.test.js"
Task: "T038 no-html-injection.test.js の対象を追加"
Task: "T039 ForecastPageViewModelFactoryTest に staleNotice・label を追加"
Task: "T040 CoordinateInputMarkupTest に現在地・食い違い・保存される地点を追加"

# 実装：JavaScript の純粋関数と PHP の ViewModel は並列にできる
Task: "T041 position-format.js"
Task: "T043 ForecastPageViewModel / Factory / HomeController"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1（基準の確認）→ Phase 2（値 1 つ・正確な丸め・303）
2. Phase 3（US1：「緯度, 経度」の貼り付け）
3. **STOP and VALIDATE**：US1 の Independent Test、`composer check`
4. この時点で、地図アプリからの貼り付けで予報を見られる（SC-001）

### Incremental Delivery

1. Phase 2 → 十進数の正確な丸めと URL の正規化（001 の挙動の改善）
2. US1 → 貼り付け（MVP）
3. US2 → 度分・度分秒、スマホでの手入力
4. US3 → 案内とエラーの仕上げ
5. US4 → 現在地・食い違いの案内・保存される地点
6. Polish → 001 の契約の更新・本番イメージ・ブラウザ確認

各ストーリーの完了ごとに `composer check` を通し、前のストーリーの動作を壊していないことを確認する。

# Data Model: 緯度・経度の入力サポート

**Feature**: 003-coordinate-input-support | **Date**: 2026-10-05

保存するデータはない（入力された文字列も現在地も保存しない）。この文書は、入力文字列の読み取りに使う PHP の型と、受け付ける表記の文法を定める。
Domain（`Coordinate`）・Application（`ViewMarineForecastInput`・DTO）・localStorage の形式（002）は変更しない。

## 受け付ける表記

### 1. 正規化（読み取りの前）

1. 入力の文字数（コードポイント）が 100 を超える → `too_long`（読み取らない。FR-024）。UTF-8 として不正 → `unreadable`
2. [research R3](research.md#r3-文字の正規化) の対応表で文字を置き換える
3. 前後の空白を除き、全体が `(` と `)` で囲まれていれば 1 組だけ外す
4. 空 → `empty`

### 2. 値 1 つ（Angle）

```text
angle      = [dir] ␣* [sign] ␣* body ␣* [dir]          ; dir は前後どちらか一方だけ
body       = decimal ␣* ["°"]                           ; 十進数（度の記号は任意）
           | int ␣* "°" ␣* decimal ␣* ["'"]             ; 度分（分の記号は省略可）
           | int ␣* "°" ␣* int ␣* "'" ␣* decimal ␣* ['"']  ; 度分秒（秒の記号は省略可）
           | int "-" decimal                            ; ハイフン区切りの度分（dir 必須）
           | int "-" int "-" decimal                    ; ハイフン区切りの度分秒（dir 必須）
decimal    = digit+ ["." digit*] | "." digit+
int        = digit+
sign       = "+" | "-"
dir        = "N" | "S" | "E" | "W"
␣          = 空白
```

| 規則 | 違反したとき |
|---|---|
| 前と後ろの両方に方角の文字がある（例：`N27.75S`） | `conflicting_directions` |
| 方角の文字と符号の両方がある（例：`-27°45'N`） | `sign_and_direction` |
| 度分・度分秒の度が整数でない（例：`27.5°30'`） | `degree_not_integer` |
| 度分秒の分が整数でない（例：`27°45.5'30"`） | `unreadable`（度分か度分秒のどちらかで入力するよう案内） |
| 分・秒が 0 以上 60 未満でない（例：`27°75'N`） | `minute_second_range` |
| ハイフン区切りに方角の文字がない（例：`27-45.0`） | `unreadable` |
| 緯度として読む値に `E` / `W`、経度として読む値に `N` / `S` | `axis_mismatch` |
| 範囲外（緯度 -90〜90、経度 -180〜180。丸める前の正確な値で判定） | `out_of_range` |

方角の文字がなければ、正の値は北緯・東経、マイナスの値は南緯・西経（FR-006）。`S` / `W` はマイナスにする。

### 3. 値 2 つ（1 行の緯度・経度）

```text
pair = angle ␣* "," ␣* angle
     | angle ␣+ angle
```

| 規則 | 違反したとき |
|---|---|
| 値が 3 つ以上（例：`27.75, 129.05, 10`） | `too_many_values` |
| 単位の記号のない数字が 3〜4 個並ぶ（例：`27 45.0 129 03.0`）。または、方角の文字・小数点・記号のない整数 2 つが空白だけで区切られている（例：`27 45`。度分の書き間違いと区別できない） | `unitless_degree_minutes`（記号を付けるよう案内） |
| 方角の文字・小数点・記号のない整数 2 つが、空白なしのカンマで区切られている（例：`27,75`。小数点のカンマの書き間違いと区別できない。`35, 139` のようにカンマのあとに空白があれば受け付ける） | `decimal_comma` |
| 片方の値にだけ方角の文字がある（例：`N27 45.0`） | `pair_direction_mixed` |
| 両方の方角が同じ軸（例：`27N 129N`） | `pair_same_axis` |
| 方角の文字がなく、先頭が緯度の範囲外で、入れ替えると両方とも範囲内（例：`129.05, 27.75`） | `swapped_order`（入れ替えない。FR-022） |
| 上記以外で範囲外 | `out_of_range`（緯度・経度のどちらが範囲外かを示す。両方とも範囲外なら緯度を示す。1 欄に出せる文言は 1 つで、緯度を直せば次の送信で経度の誤りが分かるため） |

- 両方に方角の文字があれば、`N` / `S` の値を緯度、`E` / `W` の値を経度にする（順序は問わない。spec Edge Cases）
- 両方になければ、常に先頭を緯度、2 番目を経度にする（FR-003）。`35.00, 45.00` は入れ替えずにそのまま受け付ける（US1-5）
- 方角の文字が前置か後置かは、文字列の先頭が方角の文字かどうかで決める（research R4）

`http://` / `https://` で始まる → `url`。上記のどれにも当てはまらない → `unreadable`。

### 4. 2 欄の組み合わせ（FR-004）

| 緯度欄 | 経度欄 | 結果 |
|---|---|---|
| 値 1 つ | 値 1 つ | 緯度欄を緯度、経度欄を経度として読む（従来どおり） |
| 値 2 つ | 空 | 緯度欄の 1 行から読む |
| 値 2 つ | 空以外（値 2 つ以外） | 緯度欄の 1 行から読み、経度欄は使わない（`ignored = longitude`） |
| 空 | 値 2 つ | 経度欄の 1 行から読む |
| 空以外（値 2 つ以外） | 値 2 つ | 経度欄の 1 行から読み、緯度欄は使わない（`ignored = latitude`） |
| 値 2 つ | 値 2 つ | 両方の欄に `both_pairs`（どちらか一方だけにするよう案内） |

「値 2 つ」かどうかは、正規化後の文字列が `pair` の形をしているか（区切りのカンマがある、または空白で区切られた値が 2 つ以上ある）で判定する。
値の中にも空白を入れてよい（`27° 45.0' N`）ため、空白で単純に分割せず、文字列全体を `pair` の文法で読む。
単位の記号・方角の文字の続き（`°` の後の分、`'` の後の秒、値の後ろの方角の文字）は同じ値に含め、それ以外の空白を区切りとみなす
（例：`27° 45.0' N 129° 03.0' E` は値 2 つ、`N 27.75` は値 1 つ）。
1 行の形をしているが中身に誤りがある場合（範囲外・3 つ以上など）は、その欄にエラーを出し、もう一方の欄は読まない。
使わなかった欄にどんな文字列が入っていても、エラーにはしない（FR-004）。

## PHP の型（`App\Presentation\Web\Input`）

### `CoordinateNotationParser`（新規）

1 欄の文字列を読む。状態を持たない `final readonly class`。Symfony に依存しない。

```php
public function parse(string $raw): ParsedField
```

### `ParsedField`（新規、`final readonly class`）

1 欄の読み取り結果。次のどれか 1 つ。

| フィールド | 型 | 意味 |
|---|---|---|
| `kind` | `FieldKind`（enum：`Empty` / `Single` / `Pair` / `Invalid`） | 読み取りの結果の種類 |
| `first` | `?Angle` | `Single` の値、または `Pair` の 1 つ目 |
| `second` | `?Angle` | `Pair` の 2 つ目 |
| `error` | `?NotationError` | `Invalid` のときの原因 |
| `looksLikePair` | `bool` | `Invalid` でも 1 行の形をしていたか（FR-004 の判定に使う） |

### `Angle`（新規、`final readonly class`）

丸める前の正確な値から求めた、読み取り済みの 1 つの値。浮動小数点を持たず整数で表す（research R2）。

| フィールド | 型 | 意味 |
|---|---|---|
| `hundredths` | `int` | 符号付き、小数点以下 2 桁に丸めた値 × 100（例：27.76 → 2776、南緯 27.76 → -2776） |
| `axis` | `?Axis`（enum：`Latitude` / `Longitude`） | 方角の文字から決まる軸。方角の文字がなければ `null` |
| `wholeDegrees` | `int` | 丸める前の値の整数部（度）の大きさ。範囲の判定に使う（4 桁以上は 999 に寄せて桁あふれを防ぐ） |
| `hasFraction` | `bool` | 整数部より下の桁（小数部・分・秒）に 0 以外の数字があるか。範囲の判定に使う |

範囲の判定は丸める前の値で行う必要があるため（90.004 を丸めで範囲内に入れない）、`Angle` は丸めた値とは別に上の 2 つを持つ。

```php
public function isWithin(int $limit): bool   // 丸める前の |値| <= $limit（90 または 180）
public function toFloat(): float             // $this->hundredths / 100
public function toCanonicalString(): string  // sprintf('%.2f')、-0.00 は 0.00
```

`isWithin($limit)` は `wholeDegrees < $limit || (wholeDegrees === $limit && !hasFraction)`（research R2 の範囲の検証）。

### `NotationError`（新規、enum）

`Empty` / `TooLong` / `Unreadable` / `Url` / `ConflictingDirections` / `SignAndDirection` / `DegreeNotInteger` / `MinuteSecondRange` /
`AxisMismatch` / `OutOfRange` / `TooManyValues` / `UnitlessDegreeMinutes` / `DecimalComma` / `PairDirectionMixed` / `PairSameAxis` / `SwappedOrder` / `BothPairs`

文言への変換は `CoordinateQueryParser` が行う（欄の名前「緯度」「経度」を埋め込むため）。文言は [contracts/web-ui.md](contracts/web-ui.md)。

### `CoordinateQueryParser`（変更）

```php
public function parse(?string $latitude, ?string $longitude, ?string $ignored = null): CoordinateQuery
```

- 2 欄をそれぞれ `CoordinateNotationParser` で読み、上記「2 欄の組み合わせ」に従って緯度・経度を決める
- 軸の検査（`axis_mismatch`）・範囲の検査・順序の検査（`swapped_order`）を行う
- `$ignored` はリダイレクト先の URL の `ignored` パラメータ。`lat` / `lon` 以外は無視する。リダイレクトが必要な入力（正規形でない）のときは使わない

### `CoordinateQuery`（変更）

| フィールド | 型 | 変更 |
|---|---|---|
| `rawLatitude` / `rawLongitude` | `string` | そのまま（422 のとき入力欄に戻す） |
| `latitude` / `longitude` | `?float` | そのまま。値は `Angle::toFloat()`（2 桁に丸め済み） |
| `errors` | `array<'latitude'\|'longitude', string>` | そのまま。新しい原因の文言が入る |
| `canonicalLatitude` / `canonicalLongitude` | `?string` | **追加**。正規形（`%.2f`）。エラーなら `null` |
| `ignoredField` | `'latitude'\|'longitude'\|null` | **追加**。1 行の文字列を使って、もう一方の欄の値を使わなかった欄 |

```php
public function isValid(): bool          // 従来どおり
public function needsRedirect(): bool    // 有効で、raw が正規形と異なる（1 行の文字列を使った場合を含む）
```

`needsRedirect()` が真のとき、Controller は `canonicalLatitude` / `canonicalLongitude`（と `ignoredField` があれば `ignored=lat|lon`）で 303 リダイレクトする。

### `ForecastPageViewModel`（変更）

| フィールド | 変更 |
|---|---|
| `form` | `Form` 型に `staleNotice: string\|null` を追加（FR-021 の案内。予報一覧を表示するときだけ。表示中の地点を含む文言） |
| `inputNotice` | **追加** `?string`。`ignoredField` があるときの通知（FR-004） |
| `favoriteTarget` | `FavoriteTarget` 型に `label: string` を追加（FR-025。`北緯 27.75° / 東経 129.05°`） |

地点の書式（`北緯 27.75° / 東経 129.05°`）は既存の `location()` と同じものを、予報の有無に関係なく `MarineForecastResult` の緯度・経度から作る（503・429 でも保存パネルに出すため）。

### `HomeController`（変更）

`form` に `staleNotice: null` を足す（トップ画面には予報一覧がない）。

## JavaScript（`assets/coordinate-input/`）

保存するデータはない。モジュールの入出力だけを定める。

### `position-format.js`

```js
formatPosition({ latitude: number, longitude: number }) → { latitude: string, longitude: string }
// 例：{ latitude: 27.754, longitude: 129.0461 } → { latitude: '27.75', longitude: '129.05' }
// -0.004 → '0.00'（'-0.00' にしない）
```

### `location-request.js`

```js
createLocationRequester({ geolocation, timeoutMs = 15000, setTimer, clearTimer })
  .request() → Promise<
      { status: 'ok', latitude: string, longitude: string }
    | { status: 'denied' }      // PERMISSION_DENIED
    | { status: 'failed' }      // 取得不能・時間切れ（15 秒）・その他のエラー
    | { status: 'busy' }        // 取得中にもう一度呼ばれた（重ねて取得しない）
  >
```

- 15 秒は `request()` を呼んだ時点から数える。時間切れのあとに届いた結果は捨てる
- `geolocation.getCurrentPosition` には `{ enableHighAccuracy: false, maximumAge: 0 }` を渡す

### `coordinate-input-ui.js`

`initCoordinateInput(document)`：`[data-coordinate-input]` のフォームごとに、現在地のボタンと FR-021 の案内を初期化する。
取得中に入力欄が変わったかどうかの判定（FR-017）はここで行う（`request()` を呼ぶ前の値と、結果が届いたときの値を比べる）。

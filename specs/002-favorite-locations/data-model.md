# Data Model: お気に入り地点の保存と呼び出し

**Feature**: 002-favorite-locations | **Date**: 2026-10-05

お気に入りはブラウザの localStorage にだけ存在する。サーバー側（PHP）のモデルの変更は、表示中の地点の座標を画面に渡すための 2 点だけ。

---

## 1. ブラウザ側（JavaScript）

### Favorite（お気に入り地点）

| フィールド | 型 | 規則 |
|---|---|---|
| `latitude` | number | -90〜90。小数点以下 2 桁に丸めた値（サーバーから受け取った値をそのまま使う） |
| `longitude` | number | -180〜180。同上 |
| `name` | string | 前後の空白を除いた値。0〜30 文字（コードポイント数）。空文字列は「名前なし」 |
| `savedAt` | string | 保存した日時（ISO 8601、UTC）。並び順にだけ使う。名前変更では変えない |

- **同一性**：`latitude.toFixed(2) + ',' + longitude.toFixed(2)` のキーで判定する。名前は判定に使わない（spec Edge Cases）
- **表示名**：`name` が空なら `"{latitude.toFixed(2)}, {longitude.toFixed(2)}"`（例：`27.75, 129.05`）
- **座標の表示**：一覧では `北緯 27.75° / 東経 129.05°` の形（予報画面の地点表示と同じ。南緯・西経はマイナスの値を絶対値で表示）
- **予報画面の URL**：`/forecast?lat={latitude.toFixed(2)}&lon={longitude.toFixed(2)}`（FR-006。名前は URL に含めない）

### FavoriteList（お気に入りの一覧）

`Favorite` の配列。不変条件：

- 同じキーの `Favorite` は 1 件まで
- 最大 20 件
- `savedAt` の降順（同時刻はキーの昇順で安定させる）

操作（`assets/favorites/favorite-list.js` の純粋関数。元の配列を変えず、新しい配列か失敗理由を返す）：

| 操作 | 成功 | 失敗理由 |
|---|---|---|
| `add(list, {latitude, longitude, name}, now)` | 先頭に追加した一覧 | `name_too_long`（31 文字以上）/ `duplicate`（同じ地点がある）/ `limit`（20 件ある） |
| `rename(list, key, name)` | 名前だけ変えた一覧（並び順・座標・`savedAt` は不変） | `name_too_long` / `not_found` |
| `remove(list, key)` | その地点を除いた一覧 | `not_found` |
| `find(list, latitude, longitude)` | 一致する `Favorite` または `null` | — |

判定の順序（`add`）：名前 → 重複 → 件数。名前の誤りは入力し直せば直るので先に知らせ、重複のときは上限の案内を出さない。

### 保存形式（localStorage）

- キー：`umiyomi.favorites`
- 値：

```json
{
  "version": 1,
  "items": [
    {"latitude": 27.75, "longitude": 129.05, "name": "テスト沖", "savedAt": "2026-10-05T11:15:00.000Z"},
    {"latitude": 28.1, "longitude": 129.3, "name": "", "savedAt": "2026-10-04T09:00:00.000Z"}
  ]
}
```

読み込み（`parse`）の規則（spec Edge Cases「一部が壊れている」）：

| 状態 | 扱い |
|---|---|
| キーがない | 空の一覧 |
| JSON として読めない / `version` が 1 でない / `items` が配列でない | 空の一覧 |
| 項目がオブジェクトでない、または型・範囲が不正（座標が範囲外、`name` が文字列でないか 31 文字以上、`savedAt` が日時として読めない） | その項目だけ捨てる |
| 座標が 2 桁に丸まっていない | 2 桁に丸めて読む（手で書き換えられた値を、丸めた地点として扱う） |
| 同じキーの項目が複数 | `savedAt` が新しい 1 件を残す |
| 21 件以上 | 新しい 20 件を表示する（書き込み時に 21 件目以降は保存しない） |

書き込み（`serialize`）は常に `version: 1` の形で、不変条件を満たした一覧だけを書く。

### FavoriteStore（`assets/favorites/favorite-store.js`）

`Storage` を引数で受け取る（テストでは偽の `Storage` を渡す）。

| メソッド | 振る舞い |
|---|---|
| `isAvailable()` | テスト用キーの `setItem` → `removeItem` が例外なく済めば `true` |
| `load()` | `getItem` → `parse`。例外時は空の一覧 |
| `save(list)` | `setItem(serialize(list))`。例外（容量超過など）なら `false` を返す |

複数タブへの配慮：画面の操作のたびに `load()` で読み直してから `add` / `rename` / `remove` を適用して `save()` する（research R10）。

---

## 2. サーバー側（PHP）の変更

### Application：`MarineForecastResult`（変更）

| フィールド | 型 | 変更 |
|---|---|---|
| `status` | `ForecastStatus` | 既存 |
| `forecast` | `?MarineForecastView` | 既存 |
| `latitude` | `float` | **追加**。UseCase が生成した `Coordinate` の丸め済みの値。全ステータスで値がある |
| `longitude` | `float` | **追加**。同上 |

名前付きコンストラクタ（`fresh` / `stale` / `unavailable` / `rateLimited`）に座標を渡す形にする。`unavailable` / `rateLimited` は座標だけを受け取る。
Domain（`Coordinate`）は変更しない。

### Presentation：`ForecastPageViewModel`（変更）

| フィールド | 型 | 変更 |
|---|---|---|
| `favoriteTarget` | `array{latitude: string, longitude: string}\|null` | **追加**。`'%.2f'` で書式化した丸め済み座標（例：`27.75`、`-0.50`）。入力エラーのときは `null` |

`ForecastPageViewModelFactory`：
- `create()`：`$result->latitude` / `$result->longitude` から `favoriteTarget` を作る（Fresh / Stale / Unavailable / RateLimited のすべて）
- `createForInvalidInput()`：`favoriteTarget` は `null`

### 変更しないもの

- Domain 層（`Coordinate` を含む）
- ルート（`/`、`/forecast`）、Controller の処理の流れ、ステータスコード
- Cache・回数制限・外部 API Provider
- `HomeController`（お気に入りの枠は Twig だけで出せる）

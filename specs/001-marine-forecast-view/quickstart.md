# Quickstart: 地点指定による海況予報の時間別表示

**Feature**: 001-marine-forecast-view

実装後に、この機能が動いていることを確かめる手順。コマンドはリポジトリ直下で実行する。

## 1. 準備

```sh
docker compose up -d
docker compose exec php composer install
```

この機能で追加するパッケージ（実装時に Flex レシピ経由で入れる）：

```sh
docker compose exec php composer require symfony/clock symfony/rate-limiter
```

## 2. 自動テスト

```sh
docker compose exec php composer check          # lint・PHPStan・Deptrac・unit/functional テスト（外部 API は呼ばない）
docker compose exec php composer test:external  # 実際の Open-Meteo を呼ぶテスト（任意。ネットワークが必要）
```

## 3. ブラウザでの確認

http://localhost:8000 を開き、スマホ幅（DevTools で 360px）でも確認する。

| # | 操作 | 期待する結果 | 対応 |
|---|---|---|---|
| 1 | 緯度 `27.75`・経度 `129.05` で「予報を表示」 | `/forecast?lat=27.75&lon=129.05` に移り、9 行の一覧、地点、`最終更新：…（HH:mm以降に再取得）`、注意書きが表示される | US1-1〜3 |
| 2 | 一覧を横にスクロール | 項目名の列が左端に残り、ページ全体は横にはみ出さない | US1-4, FR-016 |
| 3 | 一覧の 24 列目付近を見る | 1 時間ごとから 3 時間ごと（日本時間 00/03/06…時）に切り替わり、境目が分かる。日付が変わる列も分かる | FR-003, FR-006 |
| 4 | 1 のページを再読み込み | 「最終更新」が変わらない（提供元へ再取得していない） | FR-014, SC-006 |
| 5 | 緯度 `95` | 「緯度は -90〜90 の範囲で入力してください」、一覧なし | US2-1 |
| 6 | 経度 `abc` / 空欄 | 「経度を数値（-180〜180）で入力してください」、入力値が残る | US2-2 |
| 7 | 緯度 `２７．７５`（全角）・経度 ` 129.05 ` | 1 と同じ地点の予報が表示される | Edge Case |
| 8 | 緯度 `36.65`・経度 `138.18`（長野・内陸） | 風の一覧と「この地点では波・うねりの予報が得られません」 | US2-5, FR-012 |
| 9 | 1 の URL を別のタブで開く | 同じ地点の予報と入力済みのフォーム | US3-1 |

取得失敗・前回予報の代替表示・回数制限の画面は、外部 API や時刻を操作する必要があるため Functional Test で確認する
（`tests/Functional/ForecastPageTest.php`）。手で確かめる場合は、`.env.local` で Open-Meteo のベース URL を
存在しないホストに向けると取得失敗を再現できる。

## 4. 本番に出す前の確認事項

- **Open-Meteo の利用条件**：無料 API は非商用に限られる。課金を始める前（フェーズ方針の Phase 3）に商用プラン等へ切り替える（research R1）
- **trusted_proxies**：リバースプロキシの後ろに置く場合は `framework.trusted_proxies` を設定しないと、
  問い合わせ回数の制限が全利用者で共有されてしまう（research R5）
- **キャッシュ**：複数台構成にする場合は `cache.marine_forecast` と `cache.rate_limiter` を Redis に切り替える（research R4）

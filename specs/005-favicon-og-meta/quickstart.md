# Quickstart: ファビコンと OG 情報

## 自動テスト

```sh
docker compose exec php composer check          # 静的解析・テスト・lint すべて
docker compose exec php vendor/bin/phpunit --filter HeadMetaTest
bash scripts/verify-prod.sh                     # 本番イメージで OG 画像・アイコンが 200 で返ること
```

`HeadMetaTest` が確認すること：

- トップ・予報（200・422・429・503）・フィードバック案内・404・500 のすべてで、アイコンの `<link>` と OG・Twitter Card の `<meta>` がある（SC-001、SC-006）
- `og:image` が `http(s)://` で始まる完全なアドレスである（FR-006）
- トップだけ `robots` がなく、他は `noindex`（FR-011）
- 説明文・タイトル・画像の代替テキストに「安全です」「出航できます」「問題ありません」を含まない（SC-004）
- `/forecast?lat=<script>&lon=...`・100 文字を超える入力・引用符を含む入力を渡しても、入力の文字列が `<head>` に出ない（FR-009、FR-010）
- アイコン・OG 画像の取得で、Fake Provider が 0 回しか呼ばれない（FR-012）

## 画像・アイコンの作り直し（デザインを変えるときだけ）

```sh
docker compose --profile e2e run --rm --build e2e node scripts/build-icons.mjs
```

元の SVG から PNG・ICO を書き出す。書き出した `backend/assets/images/*.png` と `backend/public/favicon.ico` は**コミットする**。

## 手動確認（人間のレビュー）

1. `docker compose up -d` → http://localhost:8000 を開き、ブラウザのタブにアイコンが出ることを確認する（ライト・ダークの両方。16px で輪郭が判別できる）
2. 予報画面・フィードバック案内・存在しない URL（例：`/xxxx`）でも同じアイコンが出ることを確認する
3. iPhone / Android のブラウザで「ホーム画面に追加」し、アイコンが切り取られず他のアプリと並んで判別できることを確認する（SC-003）
4. 公開後に、各サービスのプレビューで確認する：LINE（トークに URL を貼る）・X の Card Validator 相当・Slack・Facebook のシェアデバッガー。トップ・予報の両方でタイトル・説明・画像が出ること（SC-002）
5. 開発では `DEFAULT_URI=http://localhost`（ポートなし）のため、`og:image` の実在確認は本番相当の環境で行う

## 公開するとき（運営者）

トップを検索エンジンに登録したいときは、`deploy/README.md` の「公開するとき」の手順どおり、nginx の `X-Robots-Tag: noindex, nofollow` の行を消す。
消すと、トップだけが登録対象になる（予報・フィードバック案内・エラーはアプリの `noindex` で登録されない）。

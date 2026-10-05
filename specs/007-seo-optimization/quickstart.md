# Quickstart: 検索エンジン対策（SEO）

## 自動テスト

```sh
docker compose exec php composer check                # PHPUnit（SeoTest・HomeGuideTest・HeadMetaTest）ほか全部
docker compose --profile e2e run --rm --build e2e     # トップの入力欄が最初の画面に収まること（SC-006）
bash scripts/verify-prod.sh                           # 本番イメージ・vhost での robots / canonical / robots.txt / sitemap
```

確かめること：

| 対象 | テスト | 確認内容 |
|---|---|---|
| `/robots.txt` | Functional `SeoTest` | 200、`text/plain`、`Disallow:` が空、`Sitemap: http://localhost/sitemap.xml`、`X-Robots-Tag: noindex`、Provider 呼び出し 0 回 |
| `/sitemap.xml` | Functional `SeoTest` | 200、`application/xml`、XML として読める、`<loc>` が `http://localhost/` の 1 件だけ、`X-Robots-Tag: noindex` |
| canonical | Functional `SeoTest` | トップ（`/`・`/?utm_source=x&lat=1`）で `http://localhost/` が 1 つ。予報（200・422・429・503）・フィードバック・外部送信・404・500 で 0 個 |
| JSON-LD | Functional `SeoTest` | トップに 1 つ、`json_decode` でき、`@type`・`name`・`alternateName`・`url`・`description`・`inLanguage` が契約どおり。`description` が meta と一致。`offers`・`aggregateRating` を含まない。他の画面に 0 個 |
| robots | Functional `HeadMetaTest`（既存） | トップに `meta robots` なし、他の全画面に `noindex`（005 の検査をそのまま維持し、外部送信の案内を対象に足す） |
| トップの説明文 | Functional `HeadMetaTest` | トップ専用の説明文、120 文字以内、`og:description` と一致 |
| トップの本文 | Functional `HomeGuideTest` | h1 が 1 つ、h2 の構成、数値の種類・入力例・警報・注意報の案内を含む、`section.home-guide` に `a[href]` がない、`hidden` がない、断定表現なし |
| 入力欄の位置 | e2e（desktop・mobile） | トップを開いた直後、スクロールせずに緯度・経度の入力欄と「予報を表示」がビューポート内にある（375×667 でも） |
| 本番構成 | `verify-prod.sh` | トップに `X-Robots-Tag` も `meta robots` もない、canonical が `https://verify.example.com/`、予報（422）・外部送信の案内・404 に `noindex`、robots.txt・sitemap の内容と型、チャレンジのパスにだけ `X-Robots-Tag: noindex` |

## 手元での手動確認

```sh
docker compose up -d
curl -i http://localhost:8000/robots.txt
curl -i http://localhost:8000/sitemap.xml
curl -s 'http://localhost:8000/?utm_source=test' | grep -E 'canonical|ld\+json|name="robots"'
```

開発環境の `DEFAULT_URI` は `http://localhost`（ポートなし）なので、アドレスにポート 8000 が付かない。開発では許容する（005 と同じ）。

ブラウザで http://localhost:8000/ を開き、次を確かめる：

- スマホ幅（開発者ツールで 375×667）で、入力欄と「予報を表示」がスクロールせずに見える
- お気に入りの一覧の後に「UMIYOMI でできること」「使い方」「ご利用にあたって」が表示される
- JavaScript を無効にしても本文が表示される

## 公開の操作（マージ・デプロイ後に運営者が行う）

手順の正本は [deploy/README.md](../../deploy/README.md)「検索エンジンへの公開」。概要：

1. master へのマージで 007 がデプロイされたことを確かめる（`curl -s https://umiyomi.isl-mentor.com/robots.txt` が `Sitemap:` を返す）
2. 反映前の確認：`curl -sI https://umiyomi.isl-mentor.com/ | grep -i x-robots-tag` → `noindex, nofollow` が付いている
3. VPS の `/etc/nginx/sites-available/umiyomi` から `add_header X-Robots-Tag "noindex, nofollow" always;` を消す（443 の server に写っている行も）。`/.well-known/acme-challenge/` の location に `add_header X-Robots-Tag "noindex" always;` を足す
4. `sudo nginx -t && sudo systemctl reload nginx`
5. 反映後の確認：
   - `curl -sI https://umiyomi.isl-mentor.com/ | grep -i x-robots-tag` → 何も出ない
   - `curl -s https://umiyomi.isl-mentor.com/ | grep -c 'name="robots"'` → `0`
   - `curl -s 'https://umiyomi.isl-mentor.com/forecast?lat=N27&lon=' | grep 'name="robots"'` → `noindex`
   - `curl -s https://umiyomi.isl-mentor.com/external-transmission | grep 'name="robots"'` → `noindex`
   - `curl -s https://umiyomi.isl-mentor.com/nope | grep 'name="robots"'` → `noindex`
   - `curl -s https://umiyomi.isl-mentor.com/ | grep canonical` → `https://umiyomi.isl-mentor.com/`
6. Search Console：ドメイン プロパティ `umiyomi.isl-mentor.com` を追加 → 表示された TXT レコードを DNS の `umiyomi.isl-mentor.com` に追加 → 確認
7. Search Console：サイトマップ `https://umiyomi.isl-mentor.com/sitemap.xml` を送信
8. Search Console の URL 検査（SC-001）：
   - `https://umiyomi.isl-mentor.com/` → 「登録可能」（公開テスト）→「インデックス登録をリクエスト」
   - `https://umiyomi.isl-mentor.com/forecast?lat=27.75&lon=129.05`・`/external-transmission`・`/nope` → 「noindex タグによって除外」
   - フィードバックのフォームを設定済みなら `/feedback` も同様
9. リッチリザルトテスト・Schema Markup Validator でトップを検査し、エラー 0 件（SC-004）

## 公開後の確認（定期）

- 2〜4 週間後：Search Console の「ページ」で、登録済みがトップだけ、予報画面が 0 件（SC-002・SC-003）。「UMIYOMI」で検索してトップが出る
- 3 か月以内：GA4 の「集客」で、セッションのデフォルト チャネル グループ「Organic Search」が記録される（SC-007）

## 元に戻すとき

検索結果から外したいときは、nginx に `add_header X-Robots-Tag "noindex, nofollow" always;` を server に戻して reload する（アプリの変更は戻さなくてよい）。すでに登録されたページを急いで消すときは、Search Console の「削除」も使う。

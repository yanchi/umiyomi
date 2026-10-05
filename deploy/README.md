# 本番デプロイ（さくら VPS）

master に push すると、CI（`.github/workflows/ci.yml`）がテストと本番等価スモークテスト（`scripts/verify-prod.sh`）を通したあと、次の順に動く。

1. `build-image`: 本番用イメージ（`backend/Dockerfile.prod`）をビルドして `ghcr.io/yanchi/umiyomi/app` に push する（タグは `latest` とコミット SHA）
2. `deploy`: VPS に SSH して `compose.prod.yml` を転送し、SHA 指定のイメージで `docker compose up -d` する。healthy になるまで確かめる

公開 URL は **`https://umiyomi.isl-mentor.com`**。検索エンジンの登録対象はトップだけで、他の画面はアプリが返す `<meta name="robots" content="noindex">` で登録しない。vhost は certbot のチャレンジのパスにだけ `X-Robots-Tag: noindex` を付ける（下の「検索エンジンへの公開」）。

## 構成

```
インターネット ─ ホストの nginx（80/443・TLS） ─ 127.0.0.1:8003 ─ app（FrankenPHP、コンテナ内は 8080）
                                                                    └ ボリューム share（var/share）
```

- DB は無い。予報のキャッシュと取得回数制限のカウンターは `var/share/pools`（ボリューム `share`）に置く。再デプロイしても残る
- 外部API（Open-Meteo）はコンテナから直接呼ぶ。VPS から外向きの HTTPS が通ること
- 接続元ごとの取得回数制限は `X-Forwarded-For` から接続元を決める。vhost の `proxy_set_header` を消さないこと（全員が同じ接続元に見え、サイト全体で 10 分 30 回しか取得できなくなる）

## 初回だけ手でやること

### VPS

```bash
sudo mkdir -p /opt/umiyomi && sudo chown rocky:rocky /opt/umiyomi
cd /opt/umiyomi
# deploy/.env.production.example を元に値を埋める
vi .env.production && chmod 600 .env.production
```

デプロイユーザーは `rocky`（Rocky Linux 8）。`docker` を実行できること（docker グループ）。

デプロイ鍵は UMIYOMI 専用に作る（ShipInfo とは分ける。漏れたときに片方だけ止められるように）。

```bash
# 手元で
ssh-keygen -t ed25519 -C 'umiyomi deploy (GitHub Actions)' -f umiyomi_deploy -N ''
# 公開鍵（umiyomi_deploy.pub）を VPS の rocky の ~/.ssh/authorized_keys に追記する
# 秘密鍵（umiyomi_deploy）は下の DEPLOY_SSH_KEY に入れる
```

### DNS と nginx

1. DNS に A レコード `umiyomi.isl-mentor.com → <VPS の IP>`（`ship.isl-mentor.com` と同じ VPS）を足す
2. `deploy/nginx/umiyomi.conf` の `DOMAIN` を `umiyomi.isl-mentor.com` に置き換えて `/etc/nginx/sites-available/umiyomi` に置き、`sites-enabled/umiyomi.conf` からリンクする（shipinfo-v2 と同じ形）
3. `sudo nginx -t && sudo systemctl reload nginx`
4. `sudo certbot --nginx -d umiyomi.isl-mentor.com`
5. 「検索エンジンへの公開」の「反映後の確認」で、トップに `X-Robots-Tag` が付かず、他の画面が `noindex` であることを確かめる

2026-10-05 時点で、VPS の準備（`/opt/umiyomi`・`.env.production`・UMIYOMI 専用デプロイ鍵の登録）、DNS・vhost・certbot（証明書は certbot が自動で更新する）、GitHub の `production` environment（master のみ）と下の Secrets・Variable の登録はすべて済んでいる。

### GitHub（Settings → Environments → `production`）

| 種類 | 名前 | 値 |
| --- | --- | --- |
| Secret | `DEPLOY_SSH_KEY` | UMIYOMI 専用デプロイ鍵の秘密鍵 |
| Secret | `DEPLOY_KNOWN_HOSTS` | VPS のホスト鍵（手元で確かめた `ssh-keyscan <ホスト>` の結果） |
| Secret | `DEPLOY_HOST` | `ship.isl-mentor.com` |
| Secret | `DEPLOY_USER` | `rocky` |
| Variable | `DEPLOY_URL` | `https://umiyomi.isl-mentor.com`（Environments の画面にリンクが出るだけ） |

`production` に「Deployment branches: master のみ」を設定しておくと、他のブランチからシークレットを使えない。

### VPS で使用中のポート

ShipInfoV2 の `deploy/README.md`（2026-10-03 時点）より。足すときはここも直す。

| ポート | 使っているもの |
| --- | --- |
| 0.0.0.0:8000 | 旧 ShipInfo のスクレイパー |
| 127.0.0.1:8001 | keiei-copilot |
| 127.0.0.1:8002 | ShipInfoV2 |
| **127.0.0.1:8003** | **UMIYOMI** |
| 127.0.0.1:8080 | 旧 ShipInfo の Web |
| 127.0.0.1:8081 | isl-mentor.com |
| 127.0.0.1:3000 | ai-task-manager |

## 検索エンジンへの公開

トップだけを検索エンジンの登録対象にする（007）。予報・フィードバック案内・外部送信の案内・エラー画面は、アプリが返す `<meta name="robots" content="noindex">` で登録しない。
`robots.txt`・`sitemap.xml` はアプリが `DEFAULT_URI` から作って返す。
LINE・X・Slack などの共有プレビュー（OG）は登録の有無に関係なく機能する。

### 前提

007 が master にマージ・デプロイされていること。`curl -s https://umiyomi.isl-mentor.com/robots.txt` が `Sitemap:` の行を返すことで確かめる。
デプロイ前に nginx を変えても、トップが登録可能になり `robots.txt` が 404 になるだけで、他の画面は `noindex` のまま。

### 反映前の確認

```bash
curl -sI https://umiyomi.isl-mentor.com/ | grep -i x-robots-tag   # → X-Robots-Tag: noindex, nofollow
```

### nginx の vhost を変える

VPS の `/etc/nginx/sites-available/umiyomi` を `deploy/nginx/umiyomi.conf` に合わせる。

1. 80・443（certbot が写した）両方の server から `add_header X-Robots-Tag "noindex, nofollow" always;` を消す
2. `location ^~ /.well-known/acme-challenge/` に `add_header X-Robots-Tag "noindex" always;` を足す（nginx が直接 200 で中身を返すのはこのパスだけのため）
3. `sudo nginx -t && sudo systemctl reload nginx`

### 反映後の確認

```bash
curl -sI https://umiyomi.isl-mentor.com/ | grep -i x-robots-tag                          # → 何も出ない
curl -s https://umiyomi.isl-mentor.com/ | grep -c 'name="robots"'                         # → 0
curl -s 'https://umiyomi.isl-mentor.com/forecast?lat=N27&lon=' | grep 'name="robots"'     # → noindex
curl -s https://umiyomi.isl-mentor.com/external-transmission | grep 'name="robots"'       # → noindex
curl -s https://umiyomi.isl-mentor.com/nope | grep 'name="robots"'                        # → noindex
curl -s https://umiyomi.isl-mentor.com/ | grep canonical                                  # → https://umiyomi.isl-mentor.com/
```

### Search Console

1. ドメイン プロパティ `umiyomi.isl-mentor.com` を追加する。親ドメイン `isl-mentor.com` にすると、同じドメインの他サービス（shipinfo など）のデータまで同じプロパティに入るため、サブドメインに限る
2. 表示された TXT レコードを DNS の `umiyomi.isl-mentor.com` に追加して確認する（A レコードと同じ名前に共存できる）。
   アプリに確認用の meta タグ・HTML ファイルは置かない
3. サイトマップ `https://umiyomi.isl-mentor.com/sitemap.xml` を送信する
4. URL 検査：
   - `https://umiyomi.isl-mentor.com/` → 公開 URL をテストして「登録可能」→「インデックス登録をリクエスト」
   - `https://umiyomi.isl-mentor.com/forecast?lat=27.75&lon=129.05`・`/external-transmission`・`/nope` → 「noindex タグによって除外」。フィードバックのフォームを設定済みなら `/feedback` も同様
5. [リッチリザルトテスト](https://search.google.com/test/rich-results)と [Schema Markup Validator](https://validator.schema.org/) でトップを検査し、エラーが 0 件であることを確かめる

Bing Webmaster Tools は任意。使うときは Search Console からサイトをインポートできる。

### 公開後の定期確認

- 2〜4 週間後：Search Console の「ページ」で、登録済みがトップだけ、予報画面が 0 件であること。「UMIYOMI」で検索してトップが出ること
- 3 か月以内：GA4 の「集客」で、セッションのデフォルト チャネル グループ「Organic Search」が記録されること

### 元に戻すとき

検索結果から外したいときは、VPS の vhost の両方の server に `add_header X-Robots-Tag "noindex, nofollow" always;` を戻して `sudo nginx -t && sudo systemctl reload nginx` する（アプリの変更は戻さなくてよい）。
すでに登録されたページを急いで消すときは、Search Console の「削除」も使う。

## フィードバックのフォームを設定する

フォームを設定するまで、フッターにフィードバックのリンクは出ない（未設定のままデプロイしてよい）。
案内画面の「緊急通報は 118 番」などの案内（US2）を含む版がデプロイされてから設定すること。

1. 運営者が外部フォームを [`specs/004-feedback-channel/contracts/web-ui.md`](../specs/004-feedback-channel/contracts/web-ui.md)「外部フォーム側の設定」のとおりに用意する。
   種類（不具合・要望・予報の値についての気づき・その他）を必須、本文を必須、返信用の連絡先を任意、表示中の情報の欄を任意にする。
   ログイン・アカウントなしで送れ、回答者のメールアドレスを収集しない設定にする
2. VPS の `/opt/umiyomi/.env.production` に `FEEDBACK_FORM_URL` と `FEEDBACK_FORM_PREFILL_FIELD` を追記し、`docker compose -f compose.prod.yml --env-file .env.production up -d` で反映する

欄の名前の調べ方と確認手順は [`specs/004-feedback-channel/quickstart.md`](../specs/004-feedback-channel/quickstart.md)「本番への設定」を参照。

## アクセス解析を設定する

`GA_MEASUREMENT_ID` を設定するまで、計測も Google への通信もしない（未設定のままデプロイしてよい）。
外部送信の案内画面（`/external-transmission`）を含む版がデプロイされてから設定すること。

1. GA4 のプロパティとウェブのデータストリームを作り、測定 ID（`G-` で始まる）を控える。管理者権限は運営者 1 人に限り、他の人には閲覧権限だけを渡す
2. データストリームの「拡張計測機能」は「ページビュー」以外をすべて無効にする。ページビューの詳細設定の「ブラウザの履歴イベントに基づくページの変更」も無効にする。
   **離脱クリックを有効にすると、フィードバックのフォームの URL（入力文字列を含みうる）が送られるので、必ず無効にする**
3. Google シグナルを無効にし、広告サービスとリンクしない。データ保持は 2 か月にする
4. カスタムディメンション（イベントスコープ）`screen_type`・`result` を登録する
5. VPS の `/opt/umiyomi/.env.production` に `GA_MEASUREMENT_ID` を追記し、`docker compose -f compose.prod.yml --env-file .env.production up -d` で反映する
6. 本番で GA4 のリアルタイムと開発者ツールの Network で、`dl`・`dr`・イベントに入力文字列が含まれないことを確かめる

管理画面の設定を後から変えるときは、外部送信の案内の内容と合っているかを確かめる。
計測をやめるときは `GA_MEASUREMENT_ID` を空にして再起動する。

詳細と手動確認は [`specs/006-analytics-tag/quickstart.md`](../specs/006-analytics-tag/quickstart.md) を参照。

## ロールバック

デプロイは失敗しても自動では元に戻らない。「起動を確認」のステップが失敗した時点で、前のコンテナはすでに新しいイメージに置き換わっている。デプロイが失敗したら、ログで原因を確かめたうえで、次の手順で直前に動いていたコミットの SHA に戻す。

```bash
cd /opt/umiyomi
export APP_IMAGE=ghcr.io/yanchi/umiyomi/app:<戻したいコミットの SHA>
docker compose -f compose.prod.yml --env-file .env.production up -d
```

イメージが private のときは、pull するのに `docker login ghcr.io`（`read:packages` の PAT）が要る（GHCR のパッケージの公開範囲は GitHub の Packages の設定で確かめる）。VPS に直近 5 世代のイメージが残っていれば pull せずに戻せる。

## 手元で本番構成を確かめる

```bash
bash scripts/verify-prod.sh
```

本番用イメージをビルドして使い捨てのプロジェクト（`umiyomi-verify`）で起動し、終わったら消す。CI でも全 PR で動く。

## やってはいけないこと

- `docker image prune -a`: 同居している他サービスのロールバック用イメージまで消える
- `docker compose -f compose.prod.yml down -v`: 予報キャッシュと回数制限のカウンターが消える（提供元の障害時に代替表示ができなくなる）

# 本番デプロイ（さくら VPS）

master に push すると、CI（`.github/workflows/ci.yml`）がテストと本番等価スモークテスト（`scripts/verify-prod.sh`）を通したあと、次の順に動く。

1. `build-image`: 本番用イメージ（`backend/Dockerfile.prod`）をビルドして `ghcr.io/yanchi/umiyomi/app` に push する（タグは `latest` とコミット SHA）
2. `deploy`: VPS に SSH して `compose.prod.yml` を転送し、SHA 指定のイメージで `docker compose up -d` する。healthy になるまで確かめる

公開 URL は **`https://umiyomi.isl-mentor.com`**。公開するまでは vhost が `X-Robots-Tag: noindex, nofollow` を付けて検索エンジンに載せない（下の「公開するとき」）。

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
5. `curl -sI https://umiyomi.isl-mentor.com/ | grep -i x-robots-tag` で noindex が付いていることを確かめる

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

## 公開するとき

1. `deploy/nginx/umiyomi.conf` の `add_header X-Robots-Tag ...` を消し、VPS の `/etc/nginx/sites-available/umiyomi` からも消す（certbot が足した 443 の server にも写っている）
2. `sudo nginx -t && sudo systemctl reload nginx`
3. `scripts/verify-prod.sh` の X-Robots-Tag の確認を外す

## フィードバックのフォームを設定する

フォームを設定するまで、フッターにフィードバックのリンクは出ない（未設定のままデプロイしてよい）。
案内画面の「緊急通報は 118 番」などの案内（US2）を含む版がデプロイされてから設定すること。

1. 運営者が外部フォームを [`specs/004-feedback-channel/contracts/web-ui.md`](../specs/004-feedback-channel/contracts/web-ui.md)「外部フォーム側の設定」のとおりに用意する。
   種類（不具合・要望・予報の値についての気づき・その他）を必須、本文を必須、返信用の連絡先を任意、表示中の情報の欄を任意にする。
   ログイン・アカウントなしで送れ、回答者のメールアドレスを収集しない設定にする
2. VPS の `/opt/umiyomi/.env.production` に `FEEDBACK_FORM_URL` と `FEEDBACK_FORM_PREFILL_FIELD` を追記し、`docker compose -f compose.prod.yml --env-file .env.production up -d` で反映する

欄の名前の調べ方と確認手順は [`specs/004-feedback-channel/quickstart.md`](../specs/004-feedback-channel/quickstart.md)「本番への設定」を参照。

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

#!/usr/bin/env bash
#
# 本番等価スモークテスト。
#
# 本番用イメージをビルドして compose.prod.yml を実際に起動し、本番でしか通らない経路を通す。
# PHPUnit は APP_ENV=test で動くので、次のような不具合は拾えない:
#   - 本番イメージに入れ忘れた拡張・ファイル、prod でだけ読む設定の誤り、アセットのコンパイル漏れ
#   - trusted_proxies が効かず、全員がプロキシの IP に見えて取得回数制限を共有してしまう
#   - var/share（予報キャッシュ・回数制限）に書けない、再デプロイで消える
#
# どの変更が本番構成に効くかはファイル名では決められない（framework.yaml の 1 行でも壊れる）ので、
# 全 PR で動かす。外部API（Open-Meteo）は呼ばない。
#
set -euo pipefail

cd "$(dirname "$0")/.."

# 本番（umiyomi）とは別のプロジェクト名にする。
# 取り違えて本番のボリュームを消さないよう、ここは固定
readonly PROJECT='umiyomi-verify'
readonly PORT="${VERIFY_PORT:-18103}"
readonly APP_IMAGE='umiyomi-app:verify'
# vhost の構文チェック用。latest だと nginx の更新で結果が勝手に変わるので固定する
readonly NGINX_IMAGE='nginx:1.29-alpine'
readonly BASE="http://127.0.0.1:${PORT}"
readonly HOST='verify.example.com'
# ホストの nginx の vhost を動かすコンテナ（6. で使う）
readonly VHOST_CONTAINER="${PROJECT}-host-nginx"
readonly VHOST_PORT="${VERIFY_VHOST_PORT:-18102}"

# readonly と同時に代入すると mktemp の失敗が set -e で拾えないので分ける
ENV_FILE="$(mktemp -t umiyomi-verify-env.XXXXXX)"
readonly ENV_FILE

PASSED=0
FAILED=0

# ---------------------------------------------------------------------------
# 出力
# ---------------------------------------------------------------------------

if [ -t 1 ]; then
    readonly C_OK=$'\033[32m'; readonly C_NG=$'\033[31m'
    readonly C_HEAD=$'\033[1m'; readonly C_OFF=$'\033[0m'
else
    readonly C_OK=''; readonly C_NG=''; readonly C_HEAD=''; readonly C_OFF=''
fi

section() { printf '\n%s── %s%s\n' "$C_HEAD" "$1" "$C_OFF"; }

# ok <説明> <実際> <期待>
ok() {
    if [ "$2" = "$3" ]; then
        printf '  %s✓%s %-44s %s\n' "$C_OK" "$C_OFF" "$1" "$2"
        PASSED=$((PASSED + 1))
    else
        printf '  %s✗%s %-44s %s (期待: %s)\n' "$C_NG" "$C_OFF" "$1" "$2" "$3"
        FAILED=$((FAILED + 1))
    fi
}

# contains <説明> <対象文字列> <含まれるべき部分文字列>
contains() {
    case "$2" in
        *"$3"*)
            printf '  %s✓%s %-44s %s\n' "$C_OK" "$C_OFF" "$1" "$3"
            PASSED=$((PASSED + 1)) ;;
        *)
            printf '  %s✗%s %-44s %s を含まない\n' "$C_NG" "$C_OFF" "$1" "$3"
            printf '      実際: %s\n' "$2"
            FAILED=$((FAILED + 1)) ;;
    esac
}

compose() {
    docker compose -p "$PROJECT" -f compose.prod.yml --env-file "$ENV_FILE" "$@"
}

app_exec() { compose exec -T app "$@"; }

http_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

health_of() {
    docker inspect -f '{{.State.Health.Status}}' "${PROJECT}-$1-1" 2>/dev/null || echo 'なし'
}

# healthy になるまで最大 2 分待つ
wait_healthy() {
    for _ in $(seq 1 40); do
        [ "$(health_of "$1")" = 'healthy' ] && return 0
        sleep 3
    done
    return 1
}

# healthy にならないまま進むと後続が総崩れになり、本当の原因が埋もれる。ここで打ち切る
abort_unhealthy() {
    printf '  %s✗%s コンテナが healthy にならなかった (app=%s)\n' "$C_NG" "$C_OFF" "$(health_of app)"
    printf '\n  直近のログ:\n'
    compose logs --tail=40 app 2>&1 | sed 's/^/    /'
    FAILED=$((FAILED + 1))
    exit 1
}

cleanup() {
    local status=$?
    section '後片付け'
    # -v を付けてよいのは、これが使い捨てのプロジェクト（$PROJECT）だから
    docker rm -f "$VHOST_CONTAINER" >/dev/null 2>&1 || true
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    rm -f "$ENV_FILE"
    echo '  完了'
    exit "$status"
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# 準備
# ---------------------------------------------------------------------------

section '準備'

cat > "$ENV_FILE" <<EOF
APP_IMAGE=${APP_IMAGE}
APP_PORT=${PORT}
APP_SECRET=$(openssl rand -hex 32)
DEFAULT_URI=https://${HOST}
EOF
echo '  使い捨ての .env を生成'

# 直前の失敗などで残っている場合に備える
compose down -v --remove-orphans >/dev/null 2>&1 || true

echo '  本番イメージをビルド中...'
docker build -q -f backend/Dockerfile.prod -t "$APP_IMAGE" backend >/dev/null
echo '  ビルド完了'

# ---------------------------------------------------------------------------
# 1. compose.prod.yml で起動できるか
# ---------------------------------------------------------------------------

section '1. compose.prod.yml による起動'

if ! compose up -d >/dev/null 2>&1; then
    printf '  %s✗%s 起動に失敗した\n' "$C_NG" "$C_OFF"
    compose logs --tail=40 app 2>&1 | sed 's/^/    /' || true
    FAILED=$((FAILED + 1))
    exit 1
fi

wait_healthy app || abort_unhealthy

ok 'app が healthy' "$(health_of app)" 'healthy'
ok 'prod で動いている（デバッグ無効）' \
   "$(app_exec printenv APP_ENV APP_DEBUG 2>/dev/null | tr -d '[:space:]')" 'prod0'
ok 'root で動いていない' \
   "$([ "$(app_exec id -u 2>/dev/null | tr -d '[:space:]')" != '0' ] && echo 非root || echo root)" '非root'

# ---------------------------------------------------------------------------
# 2. 画面とアセット
# ---------------------------------------------------------------------------

section '2. 画面とアセット'

HOME_HTML="$(curl -s "$BASE/")"
ok 'トップ /' "$(http_code "$BASE/")" '200'
ok '存在しないページは 404' "$(http_code "$BASE/nope")" '404'
# フィードバックのフォームは未設定で起動するので、リンクを出さず /feedback も 404（004）
ok 'フォーム未設定ではフッターにフィードバックのリンクがない' \
   "$(printf '%s' "$HOME_HTML" | grep -q 'site-footer__feedback' && echo あり || echo なし)" 'なし'
ok 'フォーム未設定では /feedback が 404' "$(http_code "$BASE/feedback")" '404'
# 計測 ID なしで起動しているので、計測の設定を出さない（006）
ok '計測 ID 未設定では計測の設定を出さない' \
   "$(printf '%s' "$HOME_HTML" | grep -q 'umiyomi-analytics' && echo あり || echo なし)" 'なし'
ok '計測 ID 未設定では入力不正の画面にも計測の設定を出さない' \
   "$(curl -s "$BASE/forecast?lat=N27&lon=" | grep -q 'umiyomi-analytics' && echo あり || echo なし)" 'なし'
# 外部送信の案内（006）。計測 ID なしでも全画面から開ける
ok '外部送信の案内 /external-transmission' "$(http_code "$BASE/external-transmission")" '200'
ok '計測 ID 未設定では案内画面に計測しない旨を出す' \
   "$(curl -s "$BASE/external-transmission" | grep -q 'この環境では現在、アクセス解析による計測を行っていません。' && echo あり || echo なし)" 'あり'
ok 'トップのフッターに外部送信の案内へのリンクがある' \
   "$(printf '%s' "$HOME_HTML" | grep -q 'site-footer__external-transmission' && echo あり || echo なし)" 'あり'
ok '.env は配信しない' \
   "$([ "$(http_code "$BASE/.env")" != '200' ] && echo 配信しない || echo 配信される)" '配信しない'
ok 'プロファイラが無い' "$(http_code "$BASE/_profiler")" '404'

# asset-map:compile 済みでないと、prod では /assets/ 配下が 404 になり画面が崩れる
CSS_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/[^"]+\.css' | head -1 || true)"
ok 'CSS が読み込まれる' \
   "$([ -n "$CSS_PATH" ] && http_code "$BASE$CSS_PATH" || echo 'link なし')" '200'
JS_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/app-[^"]+\.js' | head -1 || true)"
ok 'JS が読み込まれる' \
   "$([ -n "$JS_PATH" ] && http_code "$BASE$JS_PATH" || echo 'script なし')" '200'

# 緯度・経度の入力補助（003）。app.js が読み込む ES Module が compile 済みでないと、現在地のボタンが prod でだけ動かない
INPUT_JS_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/coordinate-input/coordinate-input-ui-[^"]+\.js' | head -1 || true)"
ok '入力補助の JS が読み込まれる' \
   "$([ -n "$INPUT_JS_PATH" ] && http_code "$BASE$INPUT_JS_PATH" || echo 'importmap になし')" '200'

# アクセス解析（006）。同じく compile 済みでないと、計測が prod でだけ動かない
ANALYTICS_JS_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/analytics/analytics-[^"]+\.js' | head -1 || true)"
ok '計測の JS が読み込まれる' \
   "$([ -n "$ANALYTICS_JS_PATH" ] && http_code "$BASE$ANALYTICS_JS_PATH" || echo 'importmap になし')" '200'

# アイコンと共有用画像（005）。ハッシュ付きの URL が compile 済みで返らないと、タブのアイコンと共有プレビューが prod でだけ壊れる
head_content_type() { curl -s -o /dev/null -D - "$1" | tr -d '\r' | awk -F': ' 'tolower($1) == "content-type" { print $2 }'; }
meta_content() { printf '%s' "$1" | grep -oE "<meta property=\"$2\" content=\"[^\"]+\"" | head -1 | sed -E 's/.*content="([^"]+)"/\1/' || true; }

ICON_SVG_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/images/icon-[^"]+\.svg' | head -1 || true)"
ICON_PNG_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/images/icon-192-[^"]+\.png' | head -1 || true)"
APPLE_ICON_PATH="$(printf '%s' "$HOME_HTML" | grep -oE '/assets/images/apple-touch-icon-[^"]+\.png' | head -1 || true)"
OG_IMAGE_URL="$(meta_content "$HOME_HTML" 'og:image')"
# og:image は DEFAULT_URI（検証用のドメイン）から作った完全なアドレスなので、取得はパスだけを手元のポートへ向ける
OG_IMAGE_PATH="${OG_IMAGE_URL#https://${HOST}}"
OG_IMAGE_PREFIX="https://${HOST}/assets/images/og-image-"
ok 'og:image は DEFAULT_URI から作った完全なアドレス' \
   "$([ "${OG_IMAGE_URL#"$OG_IMAGE_PREFIX"}" != "$OG_IMAGE_URL" ] && [ "${OG_IMAGE_URL%.png}" != "$OG_IMAGE_URL" ] && echo OK || echo "$OG_IMAGE_URL")" 'OK'
ok 'icon.svg' "$([ -n "$ICON_SVG_PATH" ] && head_content_type "$BASE$ICON_SVG_PATH" || echo 'link なし')" 'image/svg+xml'
ok 'icon-192.png' "$([ -n "$ICON_PNG_PATH" ] && head_content_type "$BASE$ICON_PNG_PATH" || echo 'link なし')" 'image/png'
ok 'apple-touch-icon.png' "$([ -n "$APPLE_ICON_PATH" ] && head_content_type "$BASE$APPLE_ICON_PATH" || echo 'link なし')" 'image/png'
ok 'og-image.png' "$([ -n "$OG_IMAGE_PATH" ] && head_content_type "$BASE$OG_IMAGE_PATH" || echo 'meta なし')" 'image/png'
ok '/favicon.ico' "$(http_code "$BASE/favicon.ico")" '200'
case "$(head_content_type "$BASE/favicon.ico")" in
    image/vnd.microsoft.icon|image/x-icon) FAVICON_TYPE='ico' ;;
    *) FAVICON_TYPE="$(head_content_type "$BASE/favicon.ico")" ;;
esac
ok '/favicon.ico の Content-Type' "$FAVICON_TYPE" 'ico'
# エラー画面（TwigBundle の error.html.twig）にも同じアイコンが付く
ok '404 の画面にもアイコンの link がある' \
   "$(curl -s "$BASE/nope" | grep -q 'rel="apple-touch-icon"' && echo あり || echo なし)" 'あり'
ok '予報の画面にもアイコンの link がある' \
   "$(curl -s "$BASE/forecast?lat=N27&lon=" | grep -q 'rel="apple-touch-icon"' && echo あり || echo なし)" 'あり'

# 度分の入力は予報を取得せずに十進数の URL へ 303 でリダイレクトする（外部 API は呼ばない）
REDIRECT_URL="$BASE/forecast?lat=27%C2%B045.0%27N&lon=129%C2%B003.0%27E"
ok '度分の入力は 303' "$(http_code "$REDIRECT_URL")" '303'
ok '十進数の URL へリダイレクトする' \
   "$(curl -s -D - -o /dev/null "$REDIRECT_URL" | tr -d '\r' | awk -F': ' 'tolower($1) == "location" { print $2 }')" \
   '/forecast?lat=27.75&lon=129.05'

# ---------------------------------------------------------------------------
# 3. リバースプロキシの裏での接続元
#    回数制限は接続元 IP ごとにかかる。trusted_proxies が効いていないと全員がプロキシの IP に見える。
#    回数制限そのものを HTTP で確かめるには Open-Meteo を呼ぶ必要があるので、
#    本番の設定で起動したカーネルで接続元の解決だけを確かめる
# ---------------------------------------------------------------------------

section '3. リバースプロキシの裏での接続元'

# client_ip <REMOTE_ADDR> <X-Forwarded-For>
client_ip() {
    # 本番コンテナの中で展開したいので、シングルクォートのままでよい
    # shellcheck disable=SC2016
    app_exec php -r '
        require "vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env");
        (new App\Kernel("prod", false))->boot();
        $server = ["REMOTE_ADDR" => $argv[1]];
        if ("" !== $argv[2]) { $server["HTTP_X_FORWARDED_FOR"] = $argv[2]; }
        echo Symfony\Component\HttpFoundation\Request::create("/", "GET", [], [], [], $server)->getClientIp();
    ' "$1" "$2" 2>/dev/null | tr -d '[:space:]'
}

# 接続元には公開アドレスを使う。203.0.113.0/24 などの文書用アドレスは Symfony がプライベート扱いにするため、
# 信頼する範囲を広げすぎたときに見分けられない
ok 'ホストの nginx 経由なら本当の接続元' "$(client_ip 172.18.0.1 '8.8.8.8')" '8.8.8.8'
# 接続元が X-Forwarded-For を自分で付けても、右端（ホストの nginx が付けた値）が使われる
ok '偽装した X-Forwarded-For は無視される' "$(client_ip 172.18.0.1 '1.2.3.4, 8.8.8.8')" '8.8.8.8'
# 対照実験。プロキシ以外から直接来たときは X-Forwarded-For を信じない。
# これが通らないなら、上の確認は trusted_proxies の有無を見分けられていない
ok '直接の接続では X-Forwarded-For を信じない' "$(client_ip 1.1.1.1 '8.8.8.8')" '1.1.1.1'

# ---------------------------------------------------------------------------
# 4. 再デプロイをまたいだ var/share の保持
# ---------------------------------------------------------------------------

section '4. 再デプロイ後の var/share の保持'

ok 'var/share に書き込める' \
   "$(app_exec sh -c 'echo persist > var/share/verify-persist && echo 書ける' 2>/dev/null | tr -d '[:space:]')" '書ける'

# -v は付けない。本番の再デプロイと同じ操作にする
compose down >/dev/null 2>&1
compose up -d >/dev/null 2>&1
wait_healthy app || abort_unhealthy

ok 'var/share のファイルが残っている' \
   "$(app_exec cat var/share/verify-persist 2>/dev/null | tr -d '[:space:]')" 'persist'

# ---------------------------------------------------------------------------
# 5. ホストの nginx の vhost
# ---------------------------------------------------------------------------

section '5. ホストの nginx の vhost'

# 読み取り専用でマウントした先は書き換えられないので、置き換えながら別の場所に書き出す
VHOST_CHECK="$(docker run --rm -v "$PWD/deploy/nginx/umiyomi.conf:/tmp/vhost.conf:ro" \
    "$NGINX_IMAGE" sh -c \
    "sed 's/DOMAIN/${HOST}/' /tmp/vhost.conf > /etc/nginx/conf.d/default.conf && nginx -t" 2>&1 || true)"

contains '構文が正しい' "$VHOST_CHECK" 'syntax is ok'

# vhost を本当に通して、検索エンジンに載せないヘッダーが付くか確かめる。
# proxy_pass の先（ホストの 127.0.0.1:8003）を compose のネットワーク上の app に差し替えて動かす
docker rm -f "$VHOST_CONTAINER" >/dev/null 2>&1 || true
docker run -d --name "$VHOST_CONTAINER" --network "${PROJECT}_default" -p "127.0.0.1:${VHOST_PORT}:80" \
    -v "$PWD/deploy/nginx/umiyomi.conf:/tmp/vhost.conf:ro" \
    "$NGINX_IMAGE" sh -c \
    "sed -e 's/DOMAIN/${HOST}/' -e 's#http://127.0.0.1:[0-9]*#http://app:8080#' /tmp/vhost.conf > /etc/nginx/conf.d/default.conf && exec nginx -g 'daemon off;'" >/dev/null
for _ in $(seq 1 20); do
    [ "$(http_code -H "Host: ${HOST}" "http://127.0.0.1:${VHOST_PORT}/")" = '200' ] && break
    sleep 1
done

VIA_VHOST="$(curl -s -o /dev/null -D - -H "Host: ${HOST}" "http://127.0.0.1:${VHOST_PORT}/")"
contains 'vhost 経由でトップが返る' "$VIA_VHOST" ' 200'
contains '検索エンジンに載せない (X-Robots-Tag)' "$VIA_VHOST" 'X-Robots-Tag: noindex, nofollow'
# エラーページにも付くこと（always が無いと 2xx/3xx 以外では付かない）
contains '404 にも付く' \
    "$(curl -s -o /dev/null -D - -H "Host: ${HOST}" "http://127.0.0.1:${VHOST_PORT}/nope")" 'X-Robots-Tag: noindex, nofollow'

# ---------------------------------------------------------------------------

section '結果'
printf '  成功 %d / 失敗 %d\n' "$PASSED" "$FAILED"

if [ "$FAILED" -gt 0 ]; then
    printf '\n%s本番等価スモークテストに失敗した。このままマージするとデプロイが壊れる。%s\n' "$C_NG" "$C_OFF"
    exit 1
fi

printf '\n%s本番構成で動くことを確かめた。%s\n' "$C_OK" "$C_OFF"

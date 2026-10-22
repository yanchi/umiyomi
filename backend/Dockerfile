# 開発用イメージ。FrankenPHP を使うのは、PHP と Web サーバーを1コンテナで動かせて nginx/php-fpm の構成管理が不要になるため
FROM dunglas/frankenphp:1-php8.4

# CVE-2026-109618・CVE-2026-109619（PHP2Shell。未認証の RCE）の修正は 8.4.27 から。
# 移動タグのままにして今後のパッチも取り込みつつ、キャッシュに古いベースイメージが残っていたらビルドを止める
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' \
    || { echo "PHP $(php -r 'echo PHP_VERSION;') is vulnerable; rebuild with --pull" >&2; exit 1; }

RUN install-php-extensions intl opcache zip apcu \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# nodejs は JavaScript のテスト（node --test）を動かすためだけに入れる。AssetMapper はビルド不要なので本番用イメージには入れない
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:80

WORKDIR /app

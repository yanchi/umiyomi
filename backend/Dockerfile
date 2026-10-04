# 開発用イメージ。FrankenPHP を使うのは、PHP と Web サーバーを1コンテナで動かせて nginx/php-fpm の構成管理が不要になるため
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions intl opcache zip apcu \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    SERVER_NAME=:80

WORKDIR /app

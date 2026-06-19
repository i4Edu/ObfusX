FROM composer:2 AS composer

FROM php:8.3-cli-alpine

LABEL org.opencontainers.image.source="https://github.com/i4Edu/ObfusX"

RUN apk add --no-cache zlib \
    && php -m | grep -q '^openssl$' \
    && php -m | grep -q '^zlib$'

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

ENTRYPOINT ["php", "bin/obfusx"]

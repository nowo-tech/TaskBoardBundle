FROM php:8.2-cli-alpine

# pdo_mysql / pdo_pgsql: the real-database suites of `make test-with-db` (REQ-TEST-005).
RUN apk add --no-cache git unzip bash libzip-dev libpq-dev nodejs npm \
    && docker-php-ext-install -j$(nproc) zip pdo_mysql pdo_pgsql

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN npm install -g pnpm@10

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"

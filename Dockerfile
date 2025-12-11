FROM dunglas/frankenphp:1-php8.4-alpine AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock /app/

RUN composer install --no-dev --optimize-autoloader

FROM dunglas/frankenphp:1-php8.4-alpine AS production

ARG VERSION=1.0.0
ENV APP_VERSION=$VERSION

COPY Caddyfile /etc/frankenphp/Caddyfile

COPY --from=build /app/vendor /app/vendor

RUN mkdir -p /app/storage/mcp-sessions /app/storage/cache \
 && chown -R www-data:www-data /app/storage

COPY --chown=www-data:www-data . /app

EXPOSE 80

HEALTHCHECK CMD wget -q --spider http://localhost/ || exit 1
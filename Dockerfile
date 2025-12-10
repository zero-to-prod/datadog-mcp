FROM dunglas/frankenphp:1-php8.4-alpine AS build

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock app/ ./

RUN composer install --no-dev --optimize-autoloader

FROM dunglas/frankenphp:1-php8.4-alpine AS production

WORKDIR /app

COPY --from=build /app/vendor ./vendor
COPY --chown=www-data:www-data . .
COPY Caddyfile /etc/frankenphp/Caddyfile

RUN mkdir -p storage/mcp-sessions storage/cache \
 && chown -R www-data:www-data storage

EXPOSE 80

HEALTHCHECK CMD wget -q --spider http://localhost/ || exit 1
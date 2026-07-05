FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./
RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
RUN composer dump-autoload --optimize

FROM composer:2 AS assets
WORKDIR /app
RUN apk add --no-cache nodejs npm
COPY --from=vendor /app ./
RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.ts tsconfig.json ./
COPY public ./public
RUN APP_ENV=production APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= npm run build

FROM php:8.4-fpm-alpine
WORKDIR /var/www/html

RUN apk add --no-cache bash icu-dev libpq-dev nginx oniguruma-dev \
    && docker-php-ext-install intl opcache pcntl pdo_pgsql \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-larasend.ini
COPY docker/entrypoint.sh /usr/local/bin/larasend-entrypoint
COPY docker/server.sh /usr/local/bin/larasend-server
RUN chmod +x /usr/local/bin/larasend-entrypoint \
    && chmod +x /usr/local/bin/larasend-server \
    && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && mkdir -p /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["larasend-entrypoint"]
CMD ["larasend-server"]

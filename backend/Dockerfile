FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip intl gd bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000
# artisan serve is single-threaded by default; let PHP's built-in server fork workers so parallel API calls don't queue
ENV PHP_CLI_SERVER_WORKERS=4
# opcache keeps compiled PHP in memory instead of re-parsing every file on each request
RUN { echo "opcache.enable=1"; echo "opcache.enable_cli=1"; echo "opcache.validate_timestamps=0"; echo "opcache.memory_consumption=128"; } > /usr/local/etc/php/conf.d/opcache.ini \
    && docker-php-ext-install opcache

# cache config/routes/views, migrate, then serve on the port Render gives us
CMD php artisan storage:link --force; \
    php artisan config:cache && php artisan route:cache && php artisan view:cache && \
    php artisan migrate --force && \
    php artisan serve --host=0.0.0.0 --port=${PORT:-10000}

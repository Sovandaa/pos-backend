FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git unzip libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Prime vendor cache for faster first boot; code volume is mounted at runtime
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress || true

# Set permissions for Laravel storage and cache directories
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache \
    && mkdir -p bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

EXPOSE 8000
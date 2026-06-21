FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libcurl4-openssl-dev libxml2-dev libonig-dev libssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install -j$(nproc) pdo_mysql mysqli mbstring xml curl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .
RUN chown -R www-data:www-data uploads && chmod -R 755 uploads
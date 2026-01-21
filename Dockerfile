# syntax=docker/dockerfile:1.6
FROM composer:2 AS composer

FROM php:8.3-fpm

# 1) OS deps + build tools + libs utk ekstensi
RUN --mount=type=cache,target=/var/cache/apt \
  --mount=type=cache,target=/var/lib/apt/lists \
  apt-get update && apt-get install -y --no-install-recommends \
  git curl unzip zip ca-certificates \
  build-essential autoconf pkg-config \
  libpq-dev libzip-dev libicu-dev \
  libjpeg62-turbo-dev libpng-dev libfreetype6-dev \
  libgmp-dev \
  chromium \
  libasound2 libatk-bridge2.0-0 libatk1.0-0 libcups2 libdrm2 libxkbcommon0 \
  libgtk-3-0 libnss3 libxcomposite1 libxdamage1 libxfixes3 libxcb1 libxrandr2 \
  libgbm1 libpango-1.0-0 libcairo2 \
  fonts-liberation \
  && rm -rf /var/lib/apt/lists/*

# Composer binary from official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# 2) Ekstensi: GD perlu flags; intl perlu libicu-dev; zip perlu libzip-dev
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
  && docker-php-ext-install -j$(nproc) \
  gd exif opcache pdo_mysql pdo_pgsql pgsql pcntl zip mysqli gmp bcmath intl

# 3) Redis via PECL (butuh autoconf/make dari build-essential)
RUN pecl install redis && docker-php-ext-enable redis

# (Opsional) Node.js LTS, kalau perlunode /var/www/html/scripts/get-token.js
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
  && apt-get update && apt-get install -y --no-install-recommends nodejs \
  && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# opsional: install puppeteer sekali (global atau di /var/www/html/scripts)
WORKDIR /var/www/html/scripts
RUN npm init -y && npm i puppeteer

WORKDIR /var/www/html
COPY . .

RUN mkdir storage

RUN chown -R www-data:www-data storage bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]

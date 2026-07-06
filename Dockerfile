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
  postgresql-client \
  libjpeg62-turbo-dev libpng-dev libfreetype6-dev \
  libgmp-dev \
  chromium \
  libasound2 libatk-bridge2.0-0 libatk1.0-0 libcups2 libdrm2 libxkbcommon0 \
  libgtk-3-0 libnss3 libxcomposite1 libxdamage1 libxfixes3 libxcb1 libxrandr2 \
  libgbm1 libpango-1.0-0 libcairo2 \
  fonts-liberation \
  nginx \
  supervisor \
  && rm -rf /var/lib/apt/lists/*

# Composer binary from official image
COPY --from=composer /usr/bin/composer /usr/bin/composer

# 2) Ekstensi PHP
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
  && docker-php-ext-install -j$(nproc) \
  gd exif opcache pdo_mysql pdo_pgsql pgsql pcntl zip mysqli gmp bcmath intl

# 3) Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# 4) Node.js LTS
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
  && apt-get update && apt-get install -y --no-install-recommends nodejs \
  && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# 5) Setup supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 6) Setup php-fpm pool: listen di TCP port 9000
RUN sed -i 's/listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/www.conf

# 7) nginx config, hapus default site Debian agar tidak konflik
RUN rm -f /etc/nginx/sites-enabled/default
COPY nginx.conf /etc/nginx/conf.d/default.conf

WORKDIR /var/www
COPY . .

RUN mkdir -p storage \
    storage/framework/views \
    storage/framework/sessions \
    storage/framework/cache/data \
    storage/logs \
    bootstrap/cache

# 8) Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 9) Install puppeteer untuk scripts/ (path sesuai basepath Laravel)
RUN cd scripts && npm ci

# 10) Build frontend assets (Vite/Filament)
RUN npm ci && npm run build

# 11) Storage symlink untuk public file access
RUN APP_ENV=local php artisan storage:link

RUN chown -R www-data:www-data storage bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

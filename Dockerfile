# syntax=docker/dockerfile:1
# Kawaii Mandala — imagen para el build pack "Docker Compose" de Coolify.

# --- Etapa 1: assets de Vite (public/build esta en .gitignore) ---------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json .npmrc ./
RUN npm install --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# --- Etapa 2: base PHP (gd para el escalado, mbstring/gd para mPDF) ----------
FROM php:8.4-fpm-alpine AS base
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mbstring gd intl bcmath exif opcache pcntl zip \
    && apk add --no-cache nginx supervisor tzdata curl \
    && rm -rf /var/cache/apk/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

# --- Etapa 3: dependencias y codigo -------------------------------------------
FROM base AS build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- Runtime ------------------------------------------------------------------
FROM base AS runtime
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kawaii.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-kawaii.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p /var/log/supervisor /run/nginx /var/www/html/storage /var/www/html/bootstrap/cache \
       /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/fastcgi /var/lib/nginx/tmp/proxy \
       /var/lib/nginx/tmp/uwsgi /var/lib/nginx/tmp/scgi \
    && chown -R www-data:www-data /var/lib/nginx /run/nginx /var/www/html/storage /var/www/html/bootstrap/cache

ENV CONTAINER_ROLE=app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

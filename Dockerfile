# YouTube to MP3 Converter: web server, queue worker and scheduler in one image,
# with yt-dlp, FFmpeg and Deno included. See README → "Run with Docker".

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22
ARG DENO_VERSION=2.9.4

# ---- Frontend assets -------------------------------------------------------
FROM node:${NODE_VERSION}-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---- Deno (JavaScript runtime yt-dlp uses for YouTube) --------------------
FROM denoland/deno:bin-${DENO_VERSION} AS deno

# ---- Application -----------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ca-certificates curl unzip \
    && docker-php-ext-install pdo_mysql pcntl \
    && rm -rf /var/lib/apt/lists/*

# Official standalone yt-dlp build: can update itself (Settings → yt-dlp → Update)
RUN curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux -o /usr/local/bin/yt-dlp \
    && chmod a+rx /usr/local/bin/yt-dlp

COPY --from=deno /deno /usr/local/bin/deno
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && rm -f public/hot bootstrap/cache/*.php \
    && mkdir -p storage/app/temp storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chmod +x docker/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    YOUTUBE_DOWNLOAD_PATH=/downloads \
    PHP_CLI_SERVER_WORKERS=4

VOLUME ["/data", "/downloads"]
EXPOSE 8000

ENTRYPOINT ["docker/entrypoint.sh"]

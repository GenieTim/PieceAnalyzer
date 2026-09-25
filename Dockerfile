# syntax=docker/dockerfile:1

# Stage 1: Build frontend assets
FROM node:22-alpine AS assets_builder

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

COPY assets ./assets
COPY webpack.config.js ./

RUN yarn build

# Stage 2: Production FrankenPHP container
FROM dunglas/frankenphp:1-php8.4-bookworm AS runner

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    acl \
    file \
    gettext \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN install-php-extensions \
    @composer \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    intl \
    zip \
    opcache \
    apcu \
    bcmath

# Copy custom PHP and Caddy configurations
COPY docker/php.ini $PHP_INI_DIR/conf.d/app.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Production environment defaults
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80

# Copy composer files for optimal layer caching
COPY composer.json composer.lock symfony.lock ./

# Install production vendor dependencies
RUN composer install --no-dev --prefer-dist --no-progress --no-scripts --optimize-autoloader

# Copy the rest of the application
COPY . ./

# Copy compiled frontend assets from assets_builder stage
COPY --from=assets_builder /app/public/build ./public/build

# Optimize class loading
RUN composer dump-autoload --classmap-authoritative --no-dev && \
    composer run-script --no-dev post-install-cmd

# Set up runtime directories and ownership
RUN mkdir -p var/cache var/log data && \
    chown -R www-data:www-data var data public/build

EXPOSE 80

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

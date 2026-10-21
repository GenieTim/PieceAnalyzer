# syntax=docker/dockerfile:1

# Stage 1: Build frontend assets
FROM node:22-alpine AS assets_builder

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

COPY assets ./assets
COPY scripts ./scripts
COPY extension ./extension
COPY webpack.config.js ./

RUN yarn build

# Stage 2: Production FrankenPHP container
FROM dunglas/frankenphp:1-php8.4.27-bookworm AS runner

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

# Ensure a default .env exists for build-time cache warming
RUN test -f .env || cp .env.example .env

# Copy compiled frontend assets from assets_builder stage
COPY --from=assets_builder /app/public/build ./public/build

# Optimize class loading and build extension packages
RUN composer dump-autoload --classmap-authoritative --no-dev && \
    composer run-script --no-dev post-install-cmd && \
    php bin/console app:extension:build

# Set up runtime directories and ownership
RUN mkdir -p var/cache var/log data public/downloads && \
    chown -R www-data:www-data var data public/build public/downloads

EXPOSE 80

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

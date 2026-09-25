#!/bin/sh
set -e

# First arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
	set -- frankenphp run "$@"
fi

if [ "$1" = "frankenphp" ] || [ "$1" = "bin/console" ]; then
    # Ensure var/cache, var/log, data directories exist
    mkdir -p var/cache var/log data

    # Fix permissions for www-data if running as root
    if [ "$(id -u)" = "0" ]; then
        chown -R www-data:www-data var data public/build 2>/dev/null || true
    fi

    # Initialize / update database schema automatically if AUTO_DB_INIT is enabled (default: 1)
    if [ "${AUTO_DB_INIT:-1}" = "1" ]; then
        echo ">> Checking and initializing database schema..."
        case "$DATABASE_URL" in
            sqlite:*)
                # SQLite creates the file automatically on schema update
                ;;
            *)
                echo ">> Waiting for database server to be ready..."
                for i in $(seq 1 30); do
                    if php bin/console doctrine:database:create --if-not-exists --no-interaction >/dev/null 2>&1; then
                        break
                    fi
                    sleep 1
                done
                php bin/console doctrine:database:create --if-not-exists --no-interaction 2>&1 || true
                ;;
        esac
        php bin/console doctrine:schema:update --force --no-interaction 2>&1 || true
    fi

    # Warm up cache if in prod
    if [ "$APP_ENV" = "prod" ]; then
        php bin/console cache:clear --no-warmup 2>&1 || true
        php bin/console cache:warmup 2>&1 || true
    fi
fi

exec "$@"

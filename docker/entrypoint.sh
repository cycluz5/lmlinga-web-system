#!/bin/sh
set -e

cd /var/www/html

# Start Ollama in the background (same container as the app).
ollama serve >/var/log/ollama.log 2>&1 &
tries=0
until ollama list >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "WARNING: Ollama did not become ready after 30s; continuing anyway." >&2
        break
    fi
    sleep 1
done

# Local embedding model — pulled automatically since it's small and not gated
# behind Ollama Cloud sign-in. The chat model (gemma4:31b-cloud) is an Ollama
# Cloud model: run `docker compose exec app ollama signin` once after startup
# to authenticate; it is not pulled locally.
if command -v ollama >/dev/null 2>&1 && ollama list >/dev/null 2>&1; then
    ollama pull nomic-embed-text >/var/log/ollama-pull.log 2>&1 || \
        echo "WARNING: failed to pull nomic-embed-text; check /var/log/ollama-pull.log" >&2
fi

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Wait for MySQL when using it.
if [ "${DB_CONNECTION:-mysql}" = "mysql" ] && [ -n "$DB_HOST" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
    tries=0
    until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            echo "ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 1
    done
fi

# Named volumes start empty; recreate Laravel's storage skeleton.
mkdir -p storage/app/public storage/app/private \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"

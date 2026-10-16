#!/usr/bin/env bash
# Prepares the database and runs the web server, queue worker and scheduler.
set -euo pipefail

cd /app
mkdir -p /data /downloads

# One app key per installation, kept with the data so sessions survive rebuilds
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s /data/app.key ]; then
        php artisan key:generate --show --no-interaction > /data/app.key
    fi
    export APP_KEY="$(cat /data/app.key)"
fi

if [ "${DB_CONNECTION}" = "sqlite" ] && [ ! -f "${DB_DATABASE}" ]; then
    touch "${DB_DATABASE}"
fi

php artisan migrate --force --no-interaction

# Default settings on the first start only (later changes come from the Settings page)
if [ ! -f /data/.seeded ]; then
    php artisan db:seed --force --no-interaction
    touch /data/.seeded
fi

php artisan optimize --no-interaction

# Restart the worker and scheduler if they ever stop
(while true; do php artisan queue:work --sleep=2 --tries=3 --no-interaction || true; sleep 2; done) &
(while true; do php artisan schedule:work --no-interaction || true; sleep 5; done) &

echo "AudioFetch is running on http://localhost:${APP_PORT:-8000}"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-interaction

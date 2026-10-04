#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [ ! -f .env ]; then cp .env.example .env; fi
mkdir -p database storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
touch database/database.sqlite
docker compose build
docker compose run --rm --no-deps -u www-data app composer install --no-interaction
if ! grep -q '^APP_KEY=base64:.' .env; then
    docker compose run --rm --no-deps -u www-data app php artisan key:generate
fi
docker compose run --rm --no-deps -u www-data app php artisan migrate --force
docker compose run --rm node npm ci
docker compose run --rm node npm run build
# Every default-profile service: the scheduler drains the GitHub push queue and prunes the activity log.
docker compose up -d

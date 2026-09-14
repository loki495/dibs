#!/usr/bin/env bash
set -e

# Every artisan call here runs as www-data, never root -- a root-run migrate/seed leaves
# files root-owned and unwritable by the www-data-run Apache workers that actually serve
# requests (see docs/architecture.md's earlier root-owned-cache incidents for exactly this
# class of bug).
run_as_www_data() {
    su -s /bin/sh www-data -c "$1"
}

if [ "${DIBS_DEMO_MODE:-false}" = "true" ]; then
    # Demo data isn't real user data -- rebuilding the template fresh on every boot
    # (migrate:fresh + reseed, see BuildDemoTemplateCommand) keeps each deploy stateless by
    # design, matching ResolveDemoDatabase's own per-visitor-copy model. No volume needed.
    run_as_www_data "php artisan demo:build-template"
else
    # Non-demo (a real self-hoster's own deployment) -- Laravel doesn't create a missing
    # SQLite file on its own, so migrate would just fail against a fresh volume otherwise.
    db_path="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    if [ ! -f "$db_path" ]; then
        mkdir -p "$(dirname "$db_path")"
        touch "$db_path"
        chown www-data:www-data "$db_path"
    fi
    run_as_www_data "php artisan migrate --force"
fi

exec "$@"

FROM composer:2 AS composer
FROM php:8.5-apache AS base
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/setup-dev-container.sh /tmp/setup.sh
RUN bash /tmp/setup.sh
WORKDIR /var/www/html

# Browser (Pest + Playwright) tests only — adds Node.js + a ~300MB Chromium binary on
# top of `base`. Kept out of `base` itself since that image also serves the public demo
# (see CLAUDE.md's "Public demo hosting") and shouldn't carry a JS runtime + browser
# binary just for dev-time tests. Run with: composer pest:browser
FROM base AS test
# Runs as www-data (docker-compose.yml pins app-test's `user:` to it) rather than root
# so it can't leave root-owned files under the bind-mounted repo — bitten by exactly
# that once already: a root-run app-test container silently wrote root-owned entries
# into .phpstan/cache (shared via the same bind mount as `app`), which then made a
# later `composer phpstan` (run as www-data, like every other PHP tooling command)
# fail with "Could not write to cache file". Playwright's default browser cache
# (/root/.cache/ms-playwright) is unreachable as a non-root user, so it's redirected
# here to somewhere www-data actually owns, outside /var/www/html so it survives the
# bind mount shadowing the image's own filesystem at container start.
ENV PLAYWRIGHT_BROWSERS_PATH=/var/www/.cache/ms-playwright
COPY docker/setup-test-container.sh /tmp/setup-test.sh
RUN bash /tmp/setup-test.sh

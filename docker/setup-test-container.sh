#!/usr/bin/env bash
set -e

# Node.js is required to run the project's node_modules/.bin/playwright binary —
# Pest's browser plugin shells out to it directly, not via any PHP-native driver.
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y --no-install-recommends nodejs
rm -rf /var/lib/apt/lists/*

# Bake the Chromium binary + its OS-level deps (fonts, libnss3, ...) into the image at
# build time via a throwaway npx-fetched Playwright CLI, pinned to the exact version
# pestphp/pest-plugin-browser requires (PlaywrightNpmServer::PLAYWRIGHT_VERSION) and
# package.json/package-lock.json declare — Pest's browser plugin refuses to run
# against a mismatched Playwright version. Re-pin all three together if a future
# pest-plugin-browser upgrade bumps its required version. The browser cache lands
# under /root/.cache/ms-playwright, outside the bind-mounted /var/www/html, so it
# survives even though the project directory itself gets shadowed by the volume mount
# at container start.
npx -y playwright@1.59.1 install --with-deps chromium
chown -R www-data:www-data "$PLAYWRIGHT_BROWSERS_PATH"

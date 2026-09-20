#!/usr/bin/env bash
set -euo pipefail
apt-get update
apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev libicu-dev openssh-client
rm -rf /var/lib/apt/lists/*
# sockets: required merely by *having* pestphp/pest-plugin-browser in composer.json,
# even on a machine that never runs a browser test — the plugin's Plugin::boot()
# registers a global afterEach hook (see docker/setup-test-container.sh) that eagerly
# allocates a port via socket_create_listen() after every single Pest test, everywhere.
# Without this extension here, the entire suite errors out with "Call to undefined
# function ...socket_create_listen()" the moment the package is required, since
# vendor/ (and therefore this behavior) is shared with app-test via the same
# bind-mounted project directory.
docker-php-ext-install pdo_sqlite zip intl pcntl sockets
# PCOV for `composer pest --coverage` (lighter/faster than Xdebug for coverage-only use)
pecl install pcov
echo 'extension=pcov.so' > /usr/local/etc/php/conf.d/pcov.ini
usermod -u 1000 www-data
groupmod -g 1000 www-data
sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf
printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/todo.conf
a2enconf todo
a2enmod rewrite headers
printf 'memory_limit=512M\nupload_max_filesize=20M\npost_max_size=20M\n' > /usr/local/etc/php/conf.d/todo.ini

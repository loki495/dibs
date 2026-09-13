#!/usr/bin/env bash
set -euo pipefail
apt-get update
apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev libicu-dev openssh-client
rm -rf /var/lib/apt/lists/*
docker-php-ext-install pdo_sqlite zip intl pcntl
usermod -u 1000 www-data
groupmod -g 1000 www-data
sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf
printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/todo.conf
a2enconf todo
a2enmod rewrite headers
printf 'memory_limit=512M\nupload_max_filesize=20M\npost_max_size=20M\n' > /usr/local/etc/php/conf.d/todo.ini

#!/bin/sh
# Calage: gives the mounted data/ and i/ folders to PHP, then starts Apache.
# Their .htaccess files are put back by Calage itself when missing (Calage\Storage\Protection).
set -e

chown www-data:www-data /var/www/html/data /var/www/html/i

exec docker-php-entrypoint "$@"

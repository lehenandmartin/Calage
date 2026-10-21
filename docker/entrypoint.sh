#!/bin/sh
# Calage: prepares the data/ and i/ volumes, then starts Apache.
set -e

root=/var/www/html
for dir in data i; do
    # A fresh bind mount is empty: put back the protection files (.htaccess, probe.txt).
    for file in /usr/local/share/calage/$dir/.htaccess /usr/local/share/calage/$dir/*; do
        [ -f "$file" ] || continue
        [ -e "$root/$dir/$(basename "$file")" ] || cp -p "$file" "$root/$dir/"
    done
    # PHP (www-data) writes the database, config.php and the hosted images.
    chown www-data:www-data "$root/$dir"
done

exec docker-php-entrypoint "$@"

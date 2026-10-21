# Calage: PHP 8 + Apache, the same setup as shared hosting (.htaccess files included).
FROM php:8.4-apache

# Extensions: pdo_sqlite, mbstring and openssl ship with the image; zip is required, intl recommended.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev libicu-dev; \
    docker-php-ext-install zip intl opcache; \
    # Keep only the runtime libraries of the new extensions.
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    find /usr/local -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); print "*" so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# mod_rewrite and mod_headers, and .htaccess files honored (they protect the internal folders).
RUN a2enmod rewrite headers remoteip \
    && sed -ri 's#AllowOverride None#AllowOverride All#' /etc/apache2/apache2.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/calage.ini"
COPY docker/apache.conf /etc/apache2/conf-enabled/calage.conf

# Code owned by root, read-only for PHP; only data/ and i/ are writable (see the entrypoint).
COPY . /var/www/html/
# The .htaccess and probe files of data/ and i/, put back by the entrypoint when an empty mounted folder hides them.
RUN rm -rf /var/www/html/docker \
    && chown root:root /var/www/html && chmod 755 /var/www/html \
    && chown www-data:www-data /var/www/html/data /var/www/html/i \
    && mkdir -p /usr/local/share/calage \
    && cp -a /var/www/html/data /usr/local/share/calage/data \
    && cp -a /var/www/html/i /usr/local/share/calage/i

COPY docker/entrypoint.sh /usr/local/bin/calage-entrypoint
RUN chmod +x /usr/local/bin/calage-entrypoint

# config.php lives in the mounted data/ folder, so the setup wizard and the Settings page survive a new container.
ENV CALAGE_CONFIG=/var/www/html/data/config.php

VOLUME ["/var/www/html/data", "/var/www/html/i"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS -o /dev/null http://localhost/ || exit 1

ENTRYPOINT ["calage-entrypoint"]
CMD ["apache2-foreground"]

FROM php:8.2-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsqlite3-dev \
    && docker-php-source extract \
    && docker-php-ext-install curl \
    && docker-php-ext-install pdo_sqlite \
    && if [ -f /usr/src/php/ext/sqlite3/config0.m4 ] && [ ! -f /usr/src/php/ext/sqlite3/config.m4 ]; then cp /usr/src/php/ext/sqlite3/config0.m4 /usr/src/php/ext/sqlite3/config.m4; fi \
    && docker-php-ext-install sqlite3 \
    && docker-php-source delete \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/dyndns-entrypoint

RUN chmod +x /usr/local/bin/dyndns-entrypoint \
    && mkdir -p /var/www/html/data /var/www/html/export \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/export

ENTRYPOINT ["/usr/local/bin/dyndns-entrypoint"]

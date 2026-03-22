FROM php:8.3-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsqlite3-dev \
    && docker-php-ext-install curl \
    && docker-php-ext-install pdo_sqlite \
    && docker-php-ext-install sqlite3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/dyndns-entrypoint

RUN chmod +x /usr/local/bin/dyndns-entrypoint \
    && mkdir -p /var/www/html/data /var/www/html/export \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/export

ENTRYPOINT ["/usr/local/bin/dyndns-entrypoint"]

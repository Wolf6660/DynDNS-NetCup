#!/bin/sh
set -eu

APP_DIR="/var/www/html"
DATA_DIR="${APP_DATA_DIR:-$APP_DIR/data}"
EXPORT_DIR="${APP_EXPORT_DIR:-$APP_DIR/export}"
DB_PATH="${APP_DB_PATH:-$DATA_DIR/dyndns.sqlite}"
IMPORT_DB_FROM="${IMPORT_DB_FROM:-/import/dyndns.sqlite}"
DOC_ROOT="${APACHE_DOCUMENT_ROOT:-$APP_DIR/public}"

mkdir -p "$DATA_DIR" "$EXPORT_DIR"

if [ ! -f "$DB_PATH" ] && [ -f "$IMPORT_DB_FROM" ]; then
  echo "Importiere bestehende Datenbank aus $IMPORT_DB_FROM"
  cp "$IMPORT_DB_FROM" "$DB_PATH"
fi

chown -R www-data:www-data "$DATA_DIR" "$EXPORT_DIR"

if [ -n "$DOC_ROOT" ]; then
  sed -ri "s!/var/www/html!$APP_DIR!g" /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf
  sed -ri "s!DocumentRoot ${APP_DIR}/[^[:space:]]+!DocumentRoot ${DOC_ROOT}!g" /etc/apache2/sites-available/000-default.conf
  sed -ri "s!DocumentRoot ${APP_DIR}!DocumentRoot ${DOC_ROOT}!g" /etc/apache2/sites-available/000-default.conf
  sed -ri "s!<Directory ${APP_DIR}/[^>]+>!<Directory ${DOC_ROOT}>!g" /etc/apache2/apache2.conf
  sed -ri "s!<Directory ${APP_DIR}>!<Directory ${DOC_ROOT}>!g" /etc/apache2/apache2.conf
fi

exec apache2-foreground

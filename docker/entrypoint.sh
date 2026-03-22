#!/bin/sh
set -eu

APP_DIR="/var/www/html"
DATA_DIR="${APP_DATA_DIR:-$APP_DIR/data}"
EXPORT_DIR="${APP_EXPORT_DIR:-$APP_DIR/export}"
DB_PATH="${APP_DB_PATH:-$DATA_DIR/dyndns.sqlite}"
IMPORT_DB_FROM="${IMPORT_DB_FROM:-/import/dyndns.sqlite}"

mkdir -p "$DATA_DIR" "$EXPORT_DIR"

if [ ! -f "$DB_PATH" ] && [ -f "$IMPORT_DB_FROM" ]; then
  echo "Importiere bestehende Datenbank aus $IMPORT_DB_FROM"
  cp "$IMPORT_DB_FROM" "$DB_PATH"
fi

chown -R www-data:www-data "$DATA_DIR" "$EXPORT_DIR"

exec apache2-foreground

<?php

function app_base_path(): string
{
    return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
}

function app_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function app_env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function app_data_dir(): string
{
    return app_env('APP_DATA_DIR', app_base_path() . '/data');
}

function app_export_dir(): string
{
    return app_env('APP_EXPORT_DIR', app_base_path() . '/export');
}

function app_db_path(): string
{
    return app_env('APP_DB_PATH', app_data_dir() . '/dyndns.sqlite');
}

function ensure_runtime_dirs(): void
{
    foreach ([app_data_dir(), app_export_dir()] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Verzeichnis kann nicht erstellt werden: $dir");
        }
    }
}

function ensure_domains_table(SQLite3 $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fqdn TEXT NOT NULL UNIQUE,
            record_id TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            active INTEGER DEFAULT 1,
            last_ip TEXT,
            last_update TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            note TEXT,
            zone TEXT,
            hostname TEXT,
            record_type TEXT,
            token_enc TEXT,
            record_id_a INTEGER,
            record_id_aaaa INTEGER,
            docker_wan_update INTEGER DEFAULT 0,
            docker_wan_interval_minutes INTEGER DEFAULT 5,
            docker_wan_last_run_at TEXT
        )
    ");
}

function ensure_domains_schema(SQLite3 $db): void
{
    ensure_domains_table($db);

    $columns = [];
    $result = $db->query("PRAGMA table_info(domains)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[$row['name']] = true;
    }

    $wantedColumns = [
        'updated_at' => 'ALTER TABLE domains ADD COLUMN updated_at TEXT',
        'note' => 'ALTER TABLE domains ADD COLUMN note TEXT',
        'zone' => 'ALTER TABLE domains ADD COLUMN zone TEXT',
        'hostname' => 'ALTER TABLE domains ADD COLUMN hostname TEXT',
        'record_type' => 'ALTER TABLE domains ADD COLUMN record_type TEXT',
        'token_enc' => 'ALTER TABLE domains ADD COLUMN token_enc TEXT',
        'record_id_a' => 'ALTER TABLE domains ADD COLUMN record_id_a INTEGER',
        'record_id_aaaa' => 'ALTER TABLE domains ADD COLUMN record_id_aaaa INTEGER',
        'provider_synced_at' => 'ALTER TABLE domains ADD COLUMN provider_synced_at TEXT',
        'docker_wan_update' => 'ALTER TABLE domains ADD COLUMN docker_wan_update INTEGER DEFAULT 0',
        'docker_wan_interval_minutes' => 'ALTER TABLE domains ADD COLUMN docker_wan_interval_minutes INTEGER DEFAULT 5',
        'docker_wan_last_run_at' => 'ALTER TABLE domains ADD COLUMN docker_wan_last_run_at TEXT',
    ];

    foreach ($wantedColumns as $column => $sql) {
        if (!isset($columns[$column])) {
            $db->exec($sql);
        }
    }
}

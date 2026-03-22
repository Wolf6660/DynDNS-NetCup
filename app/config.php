<?php

require_once __DIR__ . '/bootstrap.php';

return [
    'db_path' => app_db_path(),
    'data_dir' => app_data_dir(),
    'export_dir' => app_export_dir(),
    'signing_secret' => app_env('APP_SIGNING_SECRET', 'CHANGE_ME_SIGNING_SECRET'),
    'token_master_key' => app_env('APP_TOKEN_MASTER_KEY', 'CHANGE_ME_TOKEN_MASTER_KEY'),
    'base_zone' => app_env('APP_BASE_ZONE', ''),
    'update_url' => app_env('DYNDNS_UPDATE_URL', ''),
];

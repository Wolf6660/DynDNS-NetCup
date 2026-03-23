<?php

require_once __DIR__ . '/bootstrap.php';

return [
    'db_path' => app_db_path(),
    'data_dir' => app_data_dir(),
    'export_dir' => app_export_dir(),
    'signing_secret' => app_env('APP_SIGNING_SECRET', 'CHANGE_ME_SIGNING_SECRET'),
    'token_master_key' => app_env('APP_TOKEN_MASTER_KEY', 'CHANGE_ME_TOKEN_MASTER_KEY'),
    'base_zone' => app_env('APP_BASE_ZONE', ''),
    'dyndns_mode' => app_env('DYNDNS_MODE', 'netcup_webspace'),
    'update_url' => app_env('DYNDNS_UPDATE_URL', ''),
    'trust_proxy_headers' => app_env_bool('TRUST_PROXY_HEADERS', false),
    'debug_ip_endpoint' => app_env_bool('DEBUG_IP_ENDPOINT', false),
    'wan_ip_lookup_url' => app_env('WAN_IP_LOOKUP_URL', 'https://api.ipify.org'),
];

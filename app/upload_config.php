<?php

require_once __DIR__ . '/bootstrap.php';

return [
    'method' => strtolower((string)app_env('UPLOAD_METHOD', 'ftp')),
    'remote' => [
        'json_path' => app_env('UPLOAD_REMOTE_JSON_PATH', '/data/dyndns_config.json'),
        'sig_path'  => app_env('UPLOAD_REMOTE_SIG_PATH', '/data/dyndns_config.sig'),
        'json_url'  => app_env('WEBDAV_JSON_URL'),
        'sig_url'   => app_env('WEBDAV_SIG_URL'),
    ],
    'ftp' => [
        'host' => app_env('FTP_HOST', ''),
        'port' => (int)app_env('FTP_PORT', '21'),
        'ssl' => app_env_bool('FTP_SSL', false),
        'user' => app_env('FTP_USER', ''),
        'pass' => app_env('FTP_PASS', ''),
        'passive' => app_env_bool('FTP_PASSIVE', true),
    ],
    'webdav' => [
        'user' => app_env('WEBDAV_USER', ''),
        'pass' => app_env('WEBDAV_PASS', ''),
    ],
];

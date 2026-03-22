<?php

require_once __DIR__ . '/bootstrap.php';

return [
    'endpoint' => app_env('NETCUP_ENDPOINT', 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON'),
    'customer_number' => app_env('NETCUP_CUSTOMER_NUMBER', ''),
    'api_key' => app_env('NETCUP_API_KEY', ''),
    'api_password' => app_env('NETCUP_API_PASSWORD', ''),
    'client_request_id_prefix' => app_env('NETCUP_CLIENT_REQUEST_ID_PREFIX', 'DockerDynDNS'),
];

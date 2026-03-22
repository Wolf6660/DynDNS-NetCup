<?php

return [
    'endpoint' => getenv('NETCUP_SERVER_ENDPOINT') ?: 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON',
    'customer_number' => getenv('NETCUP_SERVER_CUSTOMER_NUMBER') ?: '',
    'api_key' => getenv('NETCUP_SERVER_API_KEY') ?: '',
    'api_password' => getenv('NETCUP_SERVER_API_PASSWORD') ?: '',
];

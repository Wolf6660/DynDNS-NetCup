<?php
require __DIR__ . '/../app/netcup_api.php';
header('Content-Type: text/plain; charset=utf-8');

$cfg = netcup_cfg();
echo "OK\n";
echo "endpoint: " . $cfg['endpoint'] . "\n";
echo "customer_number present: " . (empty($cfg['customer_number']) ? "NO" : "YES") . "\n";
echo "api_key present: " . (empty($cfg['api_key']) ? "NO" : "YES") . "\n";
echo "api_password present: " . (empty($cfg['api_password']) ? "NO" : "YES") . "\n";

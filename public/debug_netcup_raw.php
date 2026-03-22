<?php
require __DIR__ . '/../app/netcup_api.php';
header('Content-Type: text/plain; charset=utf-8');

$cfg = netcup_cfg();

$payload = [
  'action' => 'login',
  'param' => [
    'customernumber'  => (string)$cfg['customer_number'],
    'apikey'          => (string)$cfg['api_key'],
    'apipassword'     => (string)$cfg['api_password'],
    'clientrequestid' => netcup_client_request_id(),
  ]
];

$ch = curl_init($cfg['endpoint']);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_USERAGENT => 'SynologyDynDNS/1.0',
  CURLOPT_TIMEOUT => 25,
]);

$raw = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errNo = curl_errno($ch);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP: $http\n";
echo "curl_errno: $errNo\n";
echo "curl_error: $err\n";
echo "raw_len: " . strlen((string)$raw) . "\n";
echo "raw_first_500:\n";
echo substr((string)$raw, 0, 500) . "\n";
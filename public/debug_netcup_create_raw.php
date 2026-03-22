<?php
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../app/netcup_api.php';
$cfg = netcup_cfg();

$zone = 'example.de';
$host = $_GET['host'] ?? 'testapi';
$dest = $_GET['dest'] ?? '0.0.0.0';

echo "zone=$zone host=$host dest=$dest\n\n";

$sid = netcup_login();
echo "login ok\n";

$payload = [
  'action' => 'createDnsRecords',
  'param' => [
    'customernumber'  => (string)$cfg['customer_number'],
    'apikey'          => (string)$cfg['api_key'],
    'apisessionid'    => (string)$sid,
    'clientrequestid' => netcup_client_request_id(),

    // HIER: bitte erst dnszone testen
    'dnszone'         => $zone,

    'dnsrecordset' => [[
      'hostname' => $host,
      'type' => 'A',
      'destination' => $dest,
      'priority' => 0
    ]]
  ]
];

$ch = curl_init($cfg['endpoint']);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Accept: application/json',
    'Expect:'
  ],
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

netcup_logout($sid);

echo "HTTP: $http\n";
echo "curl_errno: $errNo\n";
echo "curl_error: $err\n";
echo "raw_len: " . strlen((string)$raw) . "\n";
echo "raw_first_500:\n";
echo substr((string)$raw, 0, 500) . "\n";

<?php
require __DIR__ . '/../app/netcup_api.php';
header('Content-Type: text/plain; charset=utf-8');

$zone = (netcup_cfg()['base_zone'] ?? null); // falls du es dort NICHT hast, unten manuell setzen
if (!$zone) $zone = 'example.de';    // <- notfalls hart setzen

$host = $_GET['host'] ?? 'testapi';
$dest = $_GET['dest'] ?? '0.0.0.0';

$sid = netcup_login();
try {
    $resp = netcup_create_dns_record($sid, $zone, $host, 'A', $dest);
    echo "CREATE OK\n";
    print_r($resp);
} finally {
    netcup_logout($sid);
}

<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL:\n{$e['message']}\nFile: {$e['file']}\nLine: {$e['line']}\n";
    }
});

require __DIR__ . '/../app/netcup_api.php';
header('Content-Type: text/plain; charset=utf-8');

$zone = 'example.de'; // <- fest, damit es sicher stimmt
$host = $_GET['host'] ?? 'testapi';
$dest = $_GET['dest'] ?? '0.0.0.0';

echo "zone=$zone host=$host dest=$dest\n\n";

try {
    $sid = netcup_login();
    echo "login ok, sid=" . substr($sid, 0, 6) . "...\n";

    try {
        $resp = netcup_create_dns_record($sid, $zone, $host, 'A', $dest);
        echo "CREATE CALL DONE\n";
        print_r($resp);

        $rid = netcup_find_record_id($sid, $zone, $host, 'A', $dest);
        echo "\nFOUND RECORD ID: $rid\n";
    } finally {
        netcup_logout($sid);
        echo "\nlogout done\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION:\n" . $e->getMessage() . "\n";
}

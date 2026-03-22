<?php
require_once __DIR__ . '/../app/helpers.php';
$config = require __DIR__ . '/../app/config.php';

$db = db();

// Nur aktive Domains exportieren
$res = $db->query("
    SELECT fqdn, record_id, token_hash, active, zone, hostname, record_type,
           COALESCE(record_id_a, 0) AS record_id_a,
           COALESCE(record_id_aaaa, 0) AS record_id_aaaa
    FROM domains
    WHERE active = 1
    ORDER BY fqdn ASC
");

$domains = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $domains[] = [
        'fqdn' => $row['fqdn'],
        'record_id' => $row['record_id'],
        // wir speichern bewusst nur den Hash – Token selbst bleibt geheim
        'token_sha256' => $row['token_hash'],
        'active' => true,
        'zone' => (string)($row['zone'] ?? ''),
        'hostname' => (string)($row['hostname'] ?? ''),
        'record_type' => (string)($row['record_type'] ?? 'A'),
        'record_id_a' => (int)($row['record_id_a'] ?? 0),
        'record_id_aaaa' => (int)($row['record_id_aaaa'] ?? 0),
    ];
}

$payload = [
    'version' => 1,
    'generated_at' => gmdate('c'),
    'domains' => $domains
];

// Stabiler JSON-Output
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    http_response_code(500);
    echo "JSON Fehler";
    exit;
}

// Signatur (HMAC über den exakten JSON-String!)
$sigRaw = hash_hmac('sha256', $json, $config['signing_secret'], true);
$sigB64 = rtrim(strtr(base64_encode($sigRaw), '+/', '-_'), '=');

// Zielpfade außerhalb public
$base = realpath(__DIR__ . '/..');
$outDir = $base . '/export';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

file_put_contents($outDir . '/dyndns_config.json', $json);
file_put_contents($outDir . '/dyndns_config.sig', $sigB64);

echo "OK: Export geschrieben nach " . h($outDir) . "\n";
echo "<br>Files:\n<ul>";
echo "<li>" . h($outDir . '/dyndns_config.json') . "</li>";
echo "<li>" . h($outDir . '/dyndns_config.sig') . "</li>";
echo "</ul>";

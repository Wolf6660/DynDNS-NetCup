<?php

require_once __DIR__ . '/helpers.php';

function build_export_payload(SQLite3 $db): array {
    $res = $db->query("
        SELECT fqdn, record_id, token_hash, active
        FROM domains
        WHERE active = 1
        ORDER BY fqdn ASC
    ");

    $domains = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $domains[] = [
            'fqdn' => $row['fqdn'],
            'record_id' => $row['record_id'],
            'token_sha256' => $row['token_hash'],
            'active' => true,
        ];
    }

    return [
        'version' => 1,
        'generated_at' => gmdate('c'),
        'domains' => $domains
    ];
}

function write_export_files(string $secret): array {
    $db = db();
    $payload = build_export_payload($db);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException("JSON encode failed");
    }

    $sigRaw = hash_hmac('sha256', $json, $secret, true);
    $sigB64 = rtrim(strtr(base64_encode($sigRaw), '+/', '-_'), '=');

    $config = require __DIR__ . '/config.php';
    $outDir = (string)$config['export_dir'];
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }

    $jsonFile = $outDir . '/dyndns_config.json';
    $sigFile  = $outDir . '/dyndns_config.sig';

    file_put_contents($jsonFile, $json);
    file_put_contents($sigFile,  $sigB64);

    return [
        'out_dir' => $outDir,
        'json_file' => $jsonFile,
        'sig_file' => $sigFile,
        'domain_count' => count($payload['domains']),
    ];
}

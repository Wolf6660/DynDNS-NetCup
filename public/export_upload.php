<?php
require_once __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/exporter.php';
require __DIR__ . '/../app/uploader.php';

$config = require __DIR__ . '/../app/config.php';
$uploadCfg = require __DIR__ . '/../app/upload_config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $result = write_export_files($config['signing_secret']);

    echo "Export OK\n";
    echo "Domains: " . $result['domain_count'] . "\n";
    echo "JSON: " . $result['json_file'] . "\n";
    echo "SIG : " . $result['sig_file'] . "\n\n";

    if ($result['domain_count'] === 0) {
        echo "Hinweis: Keine aktiven Domains -> es wird trotzdem hochgeladen (leere Liste).\n\n";
    }

    $method = strtolower(trim($uploadCfg['method'] ?? ''));
    if ($method === 'ftp') {
        upload_via_ftp($uploadCfg, $result['json_file'], $result['sig_file']);
        echo "Upload OK (FTP)\n";
    } elseif ($method === 'webdav') {
        upload_via_webdav($uploadCfg, $result['json_file'], $result['sig_file']);
        echo "Upload OK (WebDAV)\n";
    } else {
        throw new RuntimeException("Unbekannte Upload-Methode: {$uploadCfg['method']}");
    }

    echo "\nFertig.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "FEHLER: " . $e->getMessage() . "\n";
}

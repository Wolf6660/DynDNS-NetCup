<?php

require_once __DIR__ . '/helpers.php';

function autosync_now(SQLite3 $db): array
{
    $appCfg = require __DIR__ . '/config.php';
    $upCfg  = require __DIR__ . '/upload_config.php';

    if (empty($appCfg['signing_secret'])) {
        throw new RuntimeException("autosync: config.php missing key: signing_secret");
    }
    $signingSecret = (string)$appCfg['signing_secret'];

    if (empty($upCfg['method'])) {
        throw new RuntimeException("autosync: upload_config.php missing key: method");
    }
    if ((string)$upCfg['method'] !== 'ftp') {
        throw new RuntimeException("autosync: aktuell wird nur method=ftp unterstützt");
    }

    $remoteJson = $upCfg['remote']['json_path'] ?? null;
    $remoteSig  = $upCfg['remote']['sig_path'] ?? null;
    if (empty($remoteJson) || empty($remoteSig)) {
        throw new RuntimeException("autosync: upload_config.php remote json_path/sig_path fehlt");
    }

    $ftp = $upCfg['ftp'] ?? [];
    foreach (['host','user','pass'] as $k) {
        if (empty($ftp[$k])) {
            throw new RuntimeException("autosync: upload_config.php ftp.$k fehlt");
        }
    }

    $ftpHost = (string)$ftp['host'];
    $ftpPort = (int)($ftp['port'] ?? 21);
    $ftpSSL  = (bool)($ftp['ssl'] ?? false);
    $ftpUser = (string)$ftp['user'];
    $ftpPass = (string)$ftp['pass'];
    $passive = (bool)($ftp['passive'] ?? true);

    // Export dir
    $exportDir = (string)($appCfg['export_dir'] ?? app_export_dir());
    if (!is_dir($exportDir) && !mkdir($exportDir, 0775, true)) {
        throw new RuntimeException("autosync: export dir kann nicht erstellt werden: $exportDir");
    }

    $jsonPath = $exportDir . '/dyndns_config.json';
    $sigPath  = $exportDir . '/dyndns_config.sig';

    // Domains aus DB lesen
    $res = $db->query("
        SELECT
            id, fqdn, record_id, token_hash, active,
            last_ip, last_update, note,
            zone, hostname, record_type
        FROM domains
        ORDER BY fqdn ASC
    ");

    $domains = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $domains[] = [
            'id'         => (int)$row['id'],
            'fqdn'       => (string)$row['fqdn'],
            'record_id'  => (string)$row['record_id'],
            'token_sha256' => (string)$row['token_hash'],
            'active'     => (int)$row['active'] === 1,
            'last_ip'    => (string)($row['last_ip'] ?? ''),
            'last_update'=> (string)($row['last_update'] ?? ''),
            'note'       => (string)($row['note'] ?? ''),
            'zone'       => (string)($row['zone'] ?? ''),
            'hostname'   => (string)($row['hostname'] ?? ''),
            'record_type'=> (string)($row['record_type'] ?? 'A'),
        ];
    }

    $payload = [
        'version'      => 1,
        'generated_at' => gmdate('c'),
        'domains'      => $domains,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException("autosync: JSON encode fehlgeschlagen");

    if (file_put_contents($jsonPath, $json) === false) {
        throw new RuntimeException("autosync: JSON write failed: $jsonPath");
    }

    $sig = b64u_enc(hash_hmac('sha256', $json, $signingSecret, true));
    if (file_put_contents($sigPath, $sig) === false) {
        throw new RuntimeException("autosync: SIG write failed: $sigPath");
    }

    // Upload per cURL FTP
    $scheme = $ftpSSL ? 'ftps' : 'ftp';

    ftp_curl_upload($scheme, $ftpHost, $ftpPort, $ftpUser, $ftpPass, $jsonPath, (string)$remoteJson, $passive);
    ftp_curl_upload($scheme, $ftpHost, $ftpPort, $ftpUser, $ftpPass, $sigPath,  (string)$remoteSig,  $passive);

    return [
        'ok' => true,
        'count' => count($domains),
        'local_json' => $jsonPath,
        'local_sig'  => $sigPath,
        'remote_json' => (string)$remoteJson,
        'remote_sig'  => (string)$remoteSig,
    ];
}

function ftp_curl_upload(
    string $scheme,
    string $host,
    int $port,
    string $user,
    string $pass,
    string $localPath,
    string $remotePath,
    bool $passive
): void {
    if (!file_exists($localPath)) {
        throw new RuntimeException("autosync: file missing: $localPath");
    }

    $fp = fopen($localPath, 'rb');
    if (!$fp) throw new RuntimeException("autosync: cannot open: $localPath");

    $remotePath = '/' . ltrim($remotePath, '/');
    $url = sprintf('%s://%s:%d%s', $scheme, $host, $port, $remotePath);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $fp,
        CURLOPT_INFILESIZE => filesize($localPath),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FTP_USE_EPSV => $passive ? 1 : 0,
    ]);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err   = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($resp === false || $errNo !== 0) {
        throw new RuntimeException("autosync: FTP upload failed ($remotePath): $errNo $err");
    }
}

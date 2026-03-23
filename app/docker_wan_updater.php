<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/dyndns_update.php';

function docker_wan_fetch_public_ipv4(): string
{
    $cfg = require __DIR__ . '/config.php';
    $url = trim((string)($cfg['wan_ip_lookup_url'] ?? 'https://api.ipify.org'));
    if ($url === '') {
        throw new RuntimeException('WAN_IP_LOOKUP_URL fehlt in der Konfiguration.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Accept: text/plain'],
        CURLOPT_USERAGENT => 'DockerDynDNS/1.0',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("WAN-IP-Abfrage fehlgeschlagen: $err");
    }

    $ip = trim((string)$body);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException("WAN-IP-Abfrage lieferte keine gueltige IPv4: $ip");
    }

    return $ip;
}

function docker_wan_due_domains(SQLite3 $db): array
{
    $res = $db->query("
        SELECT id, fqdn, record_id,
               COALESCE(zone, '') AS zone,
               COALESCE(record_type, '') AS record_type,
               COALESCE(record_id_a, 0) AS record_id_a,
               COALESCE(record_id_aaaa, 0) AS record_id_aaaa,
               COALESCE(docker_wan_interval_minutes, 5) AS docker_wan_interval_minutes,
               COALESCE(docker_wan_last_run_at, '') AS docker_wan_last_run_at
        FROM domains
        WHERE active = 1
          AND COALESCE(docker_wan_update, 0) = 1
        ORDER BY id DESC
    ");

    $domains = [];
    $now = time();

    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $interval = (int)($row['docker_wan_interval_minutes'] ?? 5);
        if ($interval < 1 || $interval > 15) {
            $interval = 5;
        }

        $lastRun = trim((string)($row['docker_wan_last_run_at'] ?? ''));
        if ($lastRun !== '') {
            $lastRunTs = strtotime($lastRun);
            if ($lastRunTs !== false && ($now - $lastRunTs) < ($interval * 60)) {
                continue;
            }
        }

        $domains[] = $row;
    }

    return $domains;
}

function docker_wan_mark_run(SQLite3 $db, int $id): void
{
    $stmt = $db->prepare("
        UPDATE domains
        SET docker_wan_last_run_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function docker_wan_process_due_domains(): array
{
    $db = db();
    $domains = docker_wan_due_domains($db);

    if ($domains === []) {
        return ['updated' => 0, 'skipped' => 0, 'ip' => '', 'domains' => []];
    }

    $wanIp = docker_wan_fetch_public_ipv4();
    $updated = 0;
    $skipped = 0;
    $updatedDomains = [];

    foreach ($domains as $row) {
        $hasARecord = ((int)($row['record_id_a'] ?? 0) > 0)
            || (((string)($row['record_type'] ?? '')) !== 'AAAA' && (string)($row['record_id'] ?? '') !== '');

        if (!$hasARecord || (string)($row['zone'] ?? '') === '') {
            $skipped++;
            docker_wan_mark_run($db, (int)$row['id']);
            continue;
        }

        dyndns_update_record($row, $wanIp);
        dyndns_mark_local_update($row, $wanIp);
        docker_wan_mark_run($db, (int)$row['id']);
        $updated++;
        $updatedDomains[] = (string)$row['fqdn'];
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'ip' => $wanIp, 'domains' => $updatedDomains];
}

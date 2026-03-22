<?php

require_once __DIR__ . '/netcup_api.php';

function dyndns_fail(string $msg, int $code = 403): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function dyndns_signing_secret(): string
{
    $config = require __DIR__ . '/config.php';
    $secret = (string)($config['signing_secret'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('signing_secret fehlt');
    }

    return $secret;
}

function dyndns_export_files(): array
{
    $config = require __DIR__ . '/config.php';
    $exportDir = (string)($config['export_dir'] ?? app_export_dir());

    return [
        'json' => $exportDir . '/dyndns_config.json',
        'sig' => $exportDir . '/dyndns_config.sig',
    ];
}

function dyndns_load_signed_config(string $jsonFile, string $sigFile, string $signingSecret): array
{
    $json = @file_get_contents($jsonFile);
    $sig  = @file_get_contents($sigFile);

    if ($json === false || $sig === false) {
        throw new RuntimeException('config not available');
    }

    $calcSig = rtrim(strtr(
        base64_encode(hash_hmac('sha256', $json, $signingSecret, true)),
        '+/', '-_'
    ), '=');

    if (!hash_equals(trim($sig), $calcSig)) {
        throw new RuntimeException('invalid config signature');
    }

    $config = json_decode($json, true);
    if (!is_array($config)) {
        throw new RuntimeException('invalid config json');
    }

    return $config;
}

function dyndns_find_domain_by_token(array $config, string $token): ?array
{
    $tokenHash = hash('sha256', $token);

    foreach (($config['domains'] ?? []) as $domain) {
        if (($domain['active'] ?? false) && hash_equals((string)($domain['token_sha256'] ?? ''), $tokenHash)) {
            return $domain;
        }
    }

    return null;
}

function dyndns_client_ip(): string
{
    $config = require __DIR__ . '/config.php';

    $ip = $_GET['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

    if (!empty($config['trust_proxy_headers'])) {
        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));

        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    break;
                }
            }
        } elseif ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
            $ip = $realIp;
        }
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('invalid ip');
    }

    return $ip;
}

function dyndns_target_record_ids(array $domain, string $ip): array
{
    $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $legacyId = isset($domain['record_id']) ? (string)$domain['record_id'] : '';
    $recordIdA = (int)($domain['record_id_a'] ?? 0);
    $recordIdAAAA = (int)($domain['record_id_aaaa'] ?? 0);
    $recordType = strtoupper((string)($domain['record_type'] ?? 'A'));

    if ($isIpv6) {
        if ($recordIdAAAA > 0) {
            return [(string)$recordIdAAAA];
        }
        if ($legacyId !== '' && $recordType === 'AAAA') {
            return [$legacyId];
        }
    } else {
        if ($recordIdA > 0) {
            return [(string)$recordIdA];
        }
        if ($legacyId !== '' && $recordType !== 'AAAA') {
            return [$legacyId];
        }
    }

    return [];
}

function dyndns_update_record(array $domain, string $ip): void
{
    $zone = (string)($domain['zone'] ?? '');
    $targetIds = dyndns_target_record_ids($domain, $ip);

    if ($zone === '' || count($targetIds) === 0) {
        throw new RuntimeException('incomplete domain configuration');
    }

    $sid = netcup_login();
    try {
        $records = netcup_info_dns_records($sid, $zone);
        $updated = false;

        foreach ($records as &$record) {
            if (isset($record['id']) && in_array((string)$record['id'], $targetIds, true)) {
                $record['destination'] = $ip;
                $record['deleterecord'] = false;
                $updated = true;
            }
        }
        unset($record);

        if (!$updated) {
            throw new RuntimeException('record not found at provider');
        }

        netcup_update_dns_records($sid, $zone, $records);
    } finally {
        netcup_logout($sid);
    }
}

function dyndns_mark_local_update(array $domain, string $ip): void
{
    if (!isset($domain['fqdn'])) {
        return;
    }

    $db = db();
    $stmt = $db->prepare("
        UPDATE domains
        SET last_ip = :ip,
            last_update = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE fqdn = :fqdn
    ");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':fqdn', (string)$domain['fqdn'], SQLITE3_TEXT);
    $stmt->execute();
}

function handle_dyndns_update_request(string $jsonFile, string $sigFile, string $signingSecret): void
{
    $token = (string)($_GET['token'] ?? '');
    if ($token === '') {
        dyndns_fail('missing token', 400);
    }

    try {
        $config = dyndns_load_signed_config($jsonFile, $sigFile, $signingSecret);
        $domain = dyndns_find_domain_by_token($config, $token);
        if ($domain === null) {
            dyndns_fail('invalid or disabled token', 403);
        }

        $ip = dyndns_client_ip();
        dyndns_update_record($domain, $ip);
        dyndns_mark_local_update($domain, $ip);

        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK ' . ($domain['fqdn'] ?? 'domain') . ' updated to ' . $ip;
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $code = match ($message) {
            'config not available' => 500,
            'invalid config signature' => 500,
            'invalid config json' => 500,
            'invalid ip' => 400,
            'record not found at provider' => 500,
            'incomplete domain configuration' => 500,
            default => 500,
        };
        dyndns_fail($message, $code);
    } catch (Throwable $e) {
        dyndns_fail('dns update failed', 500);
    }
}

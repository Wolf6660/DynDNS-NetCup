<?php

function dyndns_fail(string $msg, int $code = 403): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
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
    $ip = $_GET['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('invalid ip');
    }

    return $ip;
}

function dyndns_target_record_ids_local(array $domain, string $ip): array
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

function netcup_call_local(string $action, array $param, array $netcup): array
{
    $payload = json_encode([
        'action' => $action,
        'param' => $param,
    ]);

    $ch = curl_init($netcup['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $res = curl_exec($ch);
    if ($res === false) {
        throw new RuntimeException(curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid Netcup response');
    }

    return $data;
}

function dyndns_update_record_local(array $domain, string $ip, array $netcup): void
{
    $targetIds = dyndns_target_record_ids_local($domain, $ip);
    $zone = (string)($domain['zone'] ?? '');
    if (count($targetIds) === 0 || $zone === '') {
        throw new RuntimeException('incomplete domain configuration');
    }

    $login = netcup_call_local('login', [
        'customernumber' => (string)$netcup['customer_number'],
        'apikey' => (string)$netcup['api_key'],
        'apipassword' => (string)$netcup['api_password'],
        'clientrequestid' => bin2hex(random_bytes(8)),
    ], $netcup);

    $sid = $login['responsedata']['apisessionid'] ?? null;
    if (!$sid) {
        throw new RuntimeException('netcup login failed');
    }

    try {
        $info = netcup_call_local('infoDnsRecords', [
            'domainname' => $zone,
            'customernumber' => (string)$netcup['customer_number'],
            'apikey' => (string)$netcup['api_key'],
            'apisessionid' => (string)$sid,
            'clientrequestid' => bin2hex(random_bytes(8)),
        ], $netcup);

        $records = $info['responsedata']['dnsrecords'] ?? [];
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

        netcup_call_local('updateDnsRecords', [
            'domainname' => $zone,
            'customernumber' => (string)$netcup['customer_number'],
            'apikey' => (string)$netcup['api_key'],
            'apisessionid' => (string)$sid,
            'clientrequestid' => bin2hex(random_bytes(8)),
            'dnsrecordset' => [
                'dnsrecords' => array_values($records),
            ],
        ], $netcup);
    } finally {
        @netcup_call_local('logout', [
            'customernumber' => (string)$netcup['customer_number'],
            'apikey' => (string)$netcup['api_key'],
            'apisessionid' => (string)$sid,
            'clientrequestid' => bin2hex(random_bytes(8)),
        ], $netcup);
    }
}

function handle_dyndns_update_request_local(string $jsonFile, string $sigFile, string $signingSecret, array $netcup): void
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
        dyndns_update_record_local($domain, $ip, $netcup);

        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK ' . ($domain['fqdn'] ?? 'domain') . ' updated to ' . $ip;
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $code = match ($message) {
            'config not available' => 500,
            'invalid config signature' => 500,
            'invalid config json' => 500,
            'invalid ip' => 400,
            'incomplete domain configuration' => 500,
            'netcup login failed' => 500,
            'record not found at provider' => 500,
            default => 500,
        };
        dyndns_fail($message, $code);
    } catch (Throwable $e) {
        dyndns_fail('dns update failed', 500);
    }
}

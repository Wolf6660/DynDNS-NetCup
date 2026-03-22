<?php

/**
 * Lädt Netcup Konfiguration.
 * Erwartet Datei: /app/netcup_config.php
 * Keys: endpoint, customer_number, api_key, api_password
 */
function netcup_cfg(): array {
    $cfg = require __DIR__ . '/netcup_config.php';
    foreach (['endpoint','customer_number','api_key','api_password'] as $k) {
        if (!isset($cfg[$k]) || $cfg[$k] === '') {
            throw new RuntimeException("netcup_config.php missing key: $k");
        }
    }
    return $cfg;
}

/**
 * Netcup verlangt clientrequestid mit bestimmten Constraints.
 * Wir bauen eine alphanumerische ID <= 32 Zeichen.
 */
function netcup_client_request_id(): string {
    $cfg = netcup_cfg();
    $prefix = (string)($cfg['client_request_id_prefix'] ?? 'SynologyDynDNS');

    // Nur alphanumerisch erlauben
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'SynologyDynDNS';

    // Zufallsanteil (hex ist alphanumerisch)
    $rand = bin2hex(random_bytes(8)); // 16 chars

    // Max. 32 Zeichen insgesamt
    return substr($prefix . $rand, 0, 32);
}

/**
 * Zentrale Netcup API Call Funktion.
 */
function netcup_api_call(string $action, array $param): array {
    $cfg = netcup_cfg();

    $payload = [
        'action' => $action,
        'param'  => $param,
    ];

    $ch = curl_init($cfg['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Expect:' // <- verhindert 100-continue Probleme
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'SynologyDynDNS/1.0',
        CURLOPT_TIMEOUT => 25,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL Fehler: $err");
    }

    $raw = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($raw === false) {
        curl_close($ch);
        throw new RuntimeException("cURL Fehler ($errNo): $err");
    }

if (trim($raw) === '') {
    // Netcup liefert in manchen Fällen (z.B. create/delete) HTTP 200 ohne Body.
    // Wir geben dann eine Minimal-Antwort zurück, damit der Caller per infoDnsRecords verifizieren kann.
    return [
        'status' => 'success',
        'statuscode' => (int)$http,
        'shortmessage' => 'Empty body (treated as success)',
        'longmessage' => 'Netcup returned HTTP 200 with an empty body. Verify via infoDnsRecords.',
        'responsedata' => []
    ];
}

    curl_close($ch);
    
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Ungültige JSON Antwort (HTTP $http): " . substr($raw, 0, 300));
    }

    // Netcup liefert i.d.R. statuscode/shortmessage/longmessage bei Fehlern
    if (isset($data['statuscode']) && (int)$data['statuscode'] >= 4000) {
        $short = (string)($data['shortmessage'] ?? 'error');
        $long  = (string)($data['longmessage'] ?? '');
        throw new RuntimeException("Netcup API Fehler {$data['statuscode']}: $short $long");
    }

    // Manche Antworten enthalten "status":"error" ohne statuscode
    if (isset($data['status']) && (string)$data['status'] === 'error') {
        $short = (string)($data['shortmessage'] ?? 'error');
        $long  = (string)($data['longmessage'] ?? '');
        throw new RuntimeException("Netcup API Fehler: $short $long");
    }

    return $data;
}

/**
 * Alias/Wrapper: in deinem Code wurde netcup_call() verwendet.
 * Damit müssen wir nicht alles umbauen.
 */
function netcup_call(string $action, array $param): array {
    return netcup_api_call($action, $param);
}

function netcup_login(): string {
    $cfg = netcup_cfg();

    $resp = netcup_api_call('login', [
        'customernumber'  => (string)$cfg['customer_number'],
        'apikey'          => (string)$cfg['api_key'],
        'apipassword'     => (string)$cfg['api_password'],
        'clientrequestid' => netcup_client_request_id(),
    ]);

    $sid = $resp['responsedata']['apisessionid'] ?? null;
    if (!$sid) {
        throw new RuntimeException("Login OK, aber keine apisessionid erhalten.");
    }
    return (string)$sid;
}

function netcup_logout(string $sessionId): void {
    $cfg = netcup_cfg();
    try {
        netcup_api_call('logout', [
            'customernumber'  => (string)$cfg['customer_number'],
            'apikey'          => (string)$cfg['api_key'],
            'apisessionid'    => (string)$sessionId,
            'clientrequestid' => netcup_client_request_id(),
        ]);
    } catch (Throwable $e) {
        // Logout-Fehler ignorieren (Session läuft ohnehin ab)
    }
}

/**
 * DNS Records einer Zone abfragen.
 */
function netcup_info_dns_records(string $sessionId, string $zone): array {
    $cfg = netcup_cfg();

    $resp = netcup_api_call('infoDnsRecords', [
        // Netcup nutzt hier "domainname"
        'domainname'      => (string)$zone,
        'customernumber'  => (string)$cfg['customer_number'],
        'apikey'          => (string)$cfg['api_key'],
        'apisessionid'    => (string)$sessionId,
        'clientrequestid' => netcup_client_request_id(),
    ]);

    $records = $resp['responsedata']['dnsrecords'] ?? [];
    return is_array($records) ? $records : [];
}

/**
 * Erstellt einen DNS Record in einer Zone.
 * Hostname: z.B. "test"
 * Type: "A"
 * Destination: "0.0.0.0" oder echte IPv4
 */
function netcup_create_dns_record(string $sid, string $zone, string $hostname, string $type, string $destination): void {
    $hostnameNorm = strtolower(rtrim($hostname, '.'));
    $type = strtoupper($type);

    $records = netcup_info_dns_records($sid, $zone);

    // Existiert schon?
    foreach ($records as $rec) {
        $rh = strtolower(rtrim((string)($rec['hostname'] ?? ''), '.'));
        $rt = strtoupper((string)($rec['type'] ?? ''));
        $rd = trim((string)($rec['destination'] ?? ''));
        if ($rh === $hostnameNorm && $rt === $type && $rd === trim($destination)) {
            // schon vorhanden -> nix tun
            return;
        }
    }

    // Neu hinzufügen
    $records[] = [
        'hostname'     => $hostnameNorm,
        'type'         => $type,
        'destination'  => (string)$destination,
        'priority'     => 0,
        'deleterecord' => false,
    ];

    netcup_update_dns_records($sid, $zone, $records);
}

function netcup_delete_dns_record(string $sid, string $zone, int $recordId): void {
    $records = netcup_info_dns_records($sid, $zone);

    $found = false;
    foreach ($records as &$rec) {
        if (isset($rec['id']) && (int)$rec['id'] === (int)$recordId) {
            $rec['deleterecord'] = true;
            $found = true;
            break;
        }
    }
    unset($rec);

    if (!$found) {
        // Ist schon weg → als Erfolg behandeln
        return;
    }

    netcup_update_dns_records($sid, $zone, $records);
}

function netcup_update_dns_records(string $sessionId, string $zone, array $dnsrecords): array {
    $cfg = netcup_cfg();

    // Netcup erwartet dnsrecordset: { dnsrecords: [...] }   [oai_citation:2‡netcup Community](https://forum.netcup.de/netcup-anwendungen/ccp-customer-control-panel/13363-dns-api-falsche-formatierung-der-dns-records/?utm_source=chatgpt.com)
    $resp = netcup_api_call('updateDnsRecords', [
        'domainname'      => (string)$zone,
        'customernumber'  => (string)$cfg['customer_number'],
        'apikey'          => (string)$cfg['api_key'],
        'apisessionid'    => (string)$sessionId,
        'clientrequestid' => netcup_client_request_id(),
        'dnsrecordset'    => [
            'dnsrecords' => array_values($dnsrecords),
        ],
    ]);

    return $resp;
}
/**
 * Findet die Record-ID nach dem Erstellen über infoDnsRecords.
 * Wenn mehrere passen, wird die höchste ID genommen.
 */
function netcup_find_record_id(string $sid, string $zone, string $hostname, string $type, string $destination): int
{
    $hostnameNorm = strtolower(rtrim($hostname, '.'));
    $destNorm = trim($destination);

    // Netcup kann ein paar Sekunden brauchen
    $attempts = 6;

    for ($i = 1; $i <= $attempts; $i++) {
        $records = netcup_info_dns_records($sid, $zone);

        $matches = [];

        foreach ($records as $rec) {
            if (
                isset($rec['id'], $rec['hostname'], $rec['type'], $rec['destination']) &&
                strtolower(rtrim((string)$rec['hostname'], '.')) === $hostnameNorm &&
                (string)$rec['type'] === $type &&
                trim((string)$rec['destination']) === $destNorm
            ) {
                $matches[] = (int)$rec['id'];
            }
        }

        // Fallback: hostname + type
        if (!$matches) {
            foreach ($records as $rec) {
                if (
                    isset($rec['id'], $rec['hostname'], $rec['type']) &&
                    strtolower(rtrim((string)$rec['hostname'], '.')) === $hostnameNorm &&
                    (string)$rec['type'] === $type
                ) {
                    $matches[] = (int)$rec['id'];
                }
            }
        }

        if ($matches) {
            rsort($matches);
            return $matches[0];
        }

        sleep(1);
    }

    throw new RuntimeException(
        "Record nicht gefunden nach Erstellung (zone=$zone host=$hostname type=$type)."
    );
}
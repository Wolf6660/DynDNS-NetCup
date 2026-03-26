<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/autosync.php';
require_once __DIR__ . '/../app/dyndns_update.php';
require_once __DIR__ . '/../app/docker_wan_updater.php';
define('ENABLE_MANUAL_CREATE', false);

// -------------------- Auto-Sync Wrapper --------------------
function do_autosync(SQLite3 $db, array &$flash): void {
    try {
        $r = autosync_now($db);
        if (!isset($flash['type'])) $flash['type'] = 'ok';
        $flash['msg'] = ($flash['msg'] ?? '') . " | Auto-Sync OK ({$r['count']} Domains)";
    } catch (Throwable $e) {
        $flash = [
            'type' => 'error',
            'msg'  => ($flash['msg'] ?? '') . " | Auto-Sync FEHLER: " . $e->getMessage()
        ];
    }
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "FATAL/SHUTDOWN ERROR:\n";
        echo $e['type'] . " - " . $e['message'] . "\n";
        echo "File: " . $e['file'] . "\n";
        echo "Line: " . $e['line'] . "\n";
    }
});

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/netcup_api.php';

// -------------------- Refresh DNS IPs from Netcup --------------------
function refresh_current_ips_from_netcup(): array {
    $db = db();

    $res = $db->query("
        SELECT id, fqdn, record_id, COALESCE(zone,'') AS zone
        FROM domains
        ORDER BY id DESC
    ");

    $rows = [];
    $zones = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
        if (!empty($r['zone'])) $zones[$r['zone']] = true;
    }

    if (count($rows) === 0) {
        return ['updated' => 0, 'skipped' => 0, 'note' => 'Keine Domains vorhanden.'];
    }

    if (count($zones) === 0) {
        return [
            'updated' => 0,
            'skipped' => count($rows),
            'note' => 'Keine zone gespeichert. Bitte Domains per Netcup-Import anlegen (import_netcup.php) oder zone nachtragen.'
        ];
    }

    $sid = netcup_login();
    try {
        $zoneMaps = [];
        foreach (array_keys($zones) as $zone) {
            $recs = netcup_info_dns_records($sid, $zone);
            $map = [];
            foreach ($recs as $rec) {
                $id = $rec['id'] ?? null;
                if ($id === null) continue;
                $map[(string)$id] = (string)($rec['destination'] ?? '');
            }
            $zoneMaps[$zone] = $map;
        }
    } finally {
        netcup_logout($sid);
    }

    $updated = 0;
    $skipped = 0;

    foreach ($rows as $r) {
        $zone = (string)$r['zone'];
        $rid  = (string)$r['record_id'];

        if ($zone === '' || !isset($zoneMaps[$zone][$rid])) {
            $skipped++;
            continue;
        }

        $dest = $zoneMaps[$zone][$rid];

        $stmt = $db->prepare("
            UPDATE domains
            SET last_ip = :ip,
                provider_synced_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':ip', $dest, SQLITE3_TEXT);
        $stmt->bindValue(':id', (int)$r['id'], SQLITE3_INTEGER);
        $stmt->execute();
        $updated++;
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'note' => 'OK'];
}

// -------------------- Main --------------------
$db = db();
$flash = null;
$newToken = null;
$shownToken = null;
$cfg = require __DIR__ . '/../app/config.php';

$action = (string)($_POST['action'] ?? '');

// Debug-Log POST (kann bleiben)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(__DIR__ . '/../data/post_debug.log',
        date('c').' '.print_r($_POST,true)."\n", FILE_APPEND
    );
}

// -------------------- Actions --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    try {

        // ---- Sync now (manual button) ----
        if ($action === 'sync') {
            $r = autosync_now($db);
            $flash = ['type' => 'ok', 'msg' => "Auto-Sync OK ({$r['count']} Domains)"];
        }

        // ---- Refresh (pull current IPs from Netcup into local DB) ----
        elseif ($action === 'refresh') {
            $r = refresh_current_ips_from_netcup();
            $wan = docker_wan_process_due_domains();
            $flash = [
                'type' => 'ok',
                'msg' => "Netcup-Stand: {$r['updated']} aktualisiert, {$r['skipped']} übersprungen ({$r['note']}) | Docker-WAN: {$wan['updated']} aktualisiert, {$wan['skipped']} übersprungen" . (($wan['ip'] ?? '') !== '' ? " (IPv4 {$wan['ip']})" : '')
            ];
            do_autosync($db, $flash);
        }

        // ---- Create A record at Netcup + create local entry + token ----
        elseif ($action === 'create_netcup_a') {
            $cfg = require __DIR__ . '/../app/config.php';
            $zone = trim((string)($cfg['base_zone'] ?? ''));
            if ($zone === '') throw new RuntimeException("base_zone fehlt in config.php");

            $host = strtolower(trim((string)($_POST['host'] ?? '')));
            $dest = trim((string)($_POST['dest'] ?? '0.0.0.0'));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($host === '' || !preg_match('/^[a-z0-9-]{1,63}$/i', $host)) {
                throw new RuntimeException("Host ungültig. Erlaubt: a-z 0-9 - (max 63).");
            }
            if (!filter_var($dest, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException("Destination muss eine gültige IPv4 sein (z.B. 0.0.0.0).");
            }

            $fqdn = $host . '.' . $zone;

            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM domains WHERE fqdn = :fqdn");
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $c = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
            if ($c > 0) throw new RuntimeException("FQDN existiert bereits lokal: $fqdn");

            $sid = netcup_login();
            try {
                netcup_create_dns_record($sid, $zone, $host, 'A', $dest);
                $recordIdA = netcup_find_record_id($sid, $zone, $host, 'A', $dest);
            } finally {
                netcup_logout($sid);
            }

            $newToken = random_token(48);
            $hash = token_hash($newToken);
            $enc  = encrypt_token_local($newToken);

            $stmt = $db->prepare('
                INSERT INTO domains (fqdn, record_id, record_id_a, token_hash, token_enc, active, note, zone, hostname, record_type, last_ip, updated_at)
                VALUES (:fqdn, :record_id, :rid_a, :token_hash, :token_enc, 1, :note, :zone, :hostname, :rtype, :last_ip, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $stmt->bindValue(':record_id', (int)$recordIdA, SQLITE3_INTEGER); // legacy
            $stmt->bindValue(':rid_a', (int)$recordIdA, SQLITE3_INTEGER);
            $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
            $stmt->bindValue(':note', $note, SQLITE3_TEXT);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':hostname', $host, SQLITE3_TEXT);
            $stmt->bindValue(':rtype', 'A', SQLITE3_TEXT);
            $stmt->bindValue(':last_ip', $dest, SQLITE3_TEXT);
            $stmt->execute();

            $flash = ['type' => 'ok', 'msg' => "Netcup A-Record erstellt: $fqdn (Record-ID $recordIdA). Token wird unten angezeigt."];
            do_autosync($db, $flash);
        }

        // ---- Create AAAA record at Netcup + create local entry + token ----
        elseif ($action === 'create_netcup_aaaa') {
            $cfg = require __DIR__ . '/../app/config.php';
            $zone = trim((string)($cfg['base_zone'] ?? ''));
            if ($zone === '') throw new RuntimeException("base_zone fehlt in config.php");

            $host = strtolower(trim((string)($_POST['host'] ?? '')));
            $dest = trim((string)($_POST['dest'] ?? '::'));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($host === '' || !preg_match('/^[a-z0-9-]{1,63}$/i', $host)) {
                throw new RuntimeException("Host ungültig. Erlaubt: a-z 0-9 - (max 63).");
            }
            if (!filter_var($dest, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new RuntimeException("Destination muss eine gültige IPv6 sein (z.B. ::).");
            }

            $fqdn = $host . '.' . $zone;

            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM domains WHERE fqdn = :fqdn");
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $c = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
            if ($c > 0) throw new RuntimeException("FQDN existiert bereits lokal: $fqdn");

            $sid = netcup_login();
            try {
                netcup_create_dns_record($sid, $zone, $host, 'AAAA', $dest);
                $recordIdAAAA = netcup_find_record_id($sid, $zone, $host, 'AAAA', $dest);
            } finally {
                netcup_logout($sid);
            }

            $newToken = random_token(48);
            $hash = token_hash($newToken);
            $enc  = encrypt_token_local($newToken);

            $stmt = $db->prepare('
                INSERT INTO domains (fqdn, record_id, record_id_aaaa, token_hash, token_enc, active, note, zone, hostname, record_type, last_ip, updated_at)
                VALUES (:fqdn, :record_id, :rid_aaaa, :token_hash, :token_enc, 1, :note, :zone, :hostname, :rtype, :last_ip, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $stmt->bindValue(':record_id', (int)$recordIdAAAA, SQLITE3_INTEGER); // legacy fallback
            $stmt->bindValue(':rid_aaaa', (int)$recordIdAAAA, SQLITE3_INTEGER);
            $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
            $stmt->bindValue(':note', $note, SQLITE3_TEXT);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':hostname', $host, SQLITE3_TEXT);
            $stmt->bindValue(':rtype', 'AAAA', SQLITE3_TEXT);
            $stmt->bindValue(':last_ip', $dest, SQLITE3_TEXT);
            $stmt->execute();

            $flash = ['type' => 'ok', 'msg' => "Netcup AAAA-Record erstellt: $fqdn (Record-ID $recordIdAAAA). Token wird unten angezeigt."];
            do_autosync($db, $flash);
        }

        // ---- Create A + AAAA at Netcup + create local entry + token ----
        elseif ($action === 'create_netcup_both') {
            $cfg = require __DIR__ . '/../app/config.php';
            $zone = trim((string)($cfg['base_zone'] ?? ''));
            if ($zone === '') throw new RuntimeException("base_zone fehlt in config.php");

            $host  = strtolower(trim((string)($_POST['host'] ?? '')));
            $dest4 = trim((string)($_POST['dest4'] ?? '0.0.0.0'));
            $dest6 = trim((string)($_POST['dest6'] ?? '::'));
            $note  = trim((string)($_POST['note'] ?? ''));

            if ($host === '' || !preg_match('/^[a-z0-9-]{1,63}$/i', $host)) {
                throw new RuntimeException("Host ungültig. Erlaubt: a-z 0-9 - (max 63).");
            }
            if (!filter_var($dest4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException("IPv4 ungültig (z.B. 0.0.0.0).");
            }
            if (!filter_var($dest6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new RuntimeException("IPv6 ungültig (z.B. ::).");
            }

            $fqdn = $host . '.' . $zone;

            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM domains WHERE fqdn = :fqdn");
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $c = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
            if ($c > 0) throw new RuntimeException("FQDN existiert bereits lokal: $fqdn");

            $sid = netcup_login();
            try {
                netcup_create_dns_record($sid, $zone, $host, 'A', $dest4);
                $recordIdA = netcup_find_record_id($sid, $zone, $host, 'A', $dest4);

                netcup_create_dns_record($sid, $zone, $host, 'AAAA', $dest6);
                $recordIdAAAA = netcup_find_record_id($sid, $zone, $host, 'AAAA', $dest6);
            } finally {
                netcup_logout($sid);
            }

            $newToken = random_token(48);
            $hash = token_hash($newToken);
            $enc  = encrypt_token_local($newToken);

            $stmt = $db->prepare('
                INSERT INTO domains (fqdn, record_id, record_id_a, record_id_aaaa, token_hash, token_enc, active, note, zone, hostname, record_type, last_ip, updated_at)
                VALUES (:fqdn, :record_id, :rid_a, :rid_aaaa, :token_hash, :token_enc, 1, :note, :zone, :hostname, :rtype, :last_ip, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $stmt->bindValue(':record_id', (int)$recordIdA, SQLITE3_INTEGER); // legacy = A
            $stmt->bindValue(':rid_a', (int)$recordIdA, SQLITE3_INTEGER);
            $stmt->bindValue(':rid_aaaa', (int)$recordIdAAAA, SQLITE3_INTEGER);
            $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
            $stmt->bindValue(':note', $note, SQLITE3_TEXT);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':hostname', $host, SQLITE3_TEXT);
            $stmt->bindValue(':rtype', 'A', SQLITE3_TEXT);
            $stmt->bindValue(':last_ip', $dest4, SQLITE3_TEXT);
            $stmt->execute();

            $flash = ['type' => 'ok', 'msg' => "Netcup A+AAAA erstellt: $fqdn (A=$recordIdA / AAAA=$recordIdAAAA). Token wird unten angezeigt."];
            do_autosync($db, $flash);
        }

        // ---- Actions that require ID ----
        elseif (in_array($action, ['toggle', 'delete', 'rotate', 'showtoken', 'test', 'toggle_docker_wan', 'update_note'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException("Ungültige ID");

            if ($action === 'toggle') {
                $stmt = $db->prepare("UPDATE domains SET active = CASE active WHEN 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                $flash = ['type' => 'ok', 'msg' => 'Status geändert.'];
                do_autosync($db, $flash);
            }

            if ($action === 'delete') {
                $stmt = $db->prepare("
                    SELECT fqdn, record_id, zone,
                           COALESCE(record_id_a, 0) AS record_id_a,
                           COALESCE(record_id_aaaa, 0) AS record_id_aaaa
                    FROM domains
                    WHERE id = :id
                ");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if (!$row) throw new RuntimeException("Eintrag nicht gefunden.");

                $zone = (string)($row['zone'] ?? '');
                $recordIds = [];
                foreach ([
                    (int)($row['record_id'] ?? 0),
                    (int)($row['record_id_a'] ?? 0),
                    (int)($row['record_id_aaaa'] ?? 0),
                ] as $rid) {
                    if ($rid > 0 && !in_array($rid, $recordIds, true)) {
                        $recordIds[] = $rid;
                    }
                }

                if ($zone === '' || count($recordIds) === 0) {
                    throw new RuntimeException("Zone oder Record-ID fehlt – Netcup-Löschung nicht möglich.");
                }

                $sid = netcup_login();
                try {
                    foreach ($recordIds as $recordId) {
                        netcup_delete_dns_record($sid, $zone, $recordId);
                    }
                } finally {
                    netcup_logout($sid);
                }

                $stmt = $db->prepare("DELETE FROM domains WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();

                $flash = ['type' => 'ok', 'msg' => "DNS-Record und lokaler Eintrag gelöscht: {$row['fqdn']}"];
                do_autosync($db, $flash);
            }

            if ($action === 'rotate') {
                $newToken = random_token(48);
                $hash = token_hash($newToken);
                $enc  = encrypt_token_local($newToken);

                $stmt = $db->prepare("
                    UPDATE domains
                    SET token_hash = :token_hash,
                        token_enc  = :token_enc,
                        active = 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
                $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();

                if ($db->changes() !== 1) {
                    throw new RuntimeException("Rotate: Update hat keinen Datensatz geändert (ID stimmt nicht?)");
                }

                $flash = ['type' => 'ok', 'msg' => 'Token erneuert. Neuer Token wird unten angezeigt.'];
                do_autosync($db, $flash);
            }

            if ($action === 'showtoken') {
                $newToken = null;

                $beforeHash = (string)$db->querySingle("SELECT token_hash FROM domains WHERE id = " . (int)$id);

                $stmt = $db->prepare("SELECT fqdn, COALESCE(token_enc,'') AS token_enc FROM domains WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if (!$row) throw new RuntimeException("Eintrag nicht gefunden.");

                if (($row['token_enc'] ?? '') === '') {
                    $shownToken = null;
                    $flash = ['type' => 'error', 'msg' => "Für {$row['fqdn']} ist kein anzeigbarer Token gespeichert (token_enc leer). Bitte einmal 'Token erneuern' drücken."];
                } else {
                    $shownToken = decrypt_token_local($row['token_enc']);
                    $afterHash = (string)$db->querySingle("SELECT token_hash FROM domains WHERE id = " . (int)$id);

                    if (!hash_equals($beforeHash, $afterHash)) {
                        throw new RuntimeException("BUG: Token wurde beim Anzeigen verändert! (Form/Action verwechselt oder Rotate läuft mit)");
                    }

                    $flash = ['type' => 'ok', 'msg' => "Token für {$row['fqdn']} wird unten angezeigt."];
                }
            }

            if ($action === 'test') {
                $stmt = $db->prepare("SELECT fqdn, COALESCE(token_enc,'') AS token_enc FROM domains WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if (!$row) throw new RuntimeException("Eintrag nicht gefunden.");
                if (($row['token_enc'] ?? '') === '') throw new RuntimeException("Kein Token gespeichert (token_enc leer). Erst 'Token erneuern' drücken.");
                if (empty($cfg['update_url'])) throw new RuntimeException("DYNDNS_UPDATE_URL fehlt in der Konfiguration.");

                $token = decrypt_token_local($row['token_enc']);
                $separator = str_contains((string)$cfg['update_url'], '?') ? '&' : '?';
                $url = (string)$cfg['update_url'] . $separator . 'token=' . urlencode($token);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_HTTPHEADER => ['Accept: text/plain'],
                ]);
                $body = curl_exec($ch);
                $err  = curl_error($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($body === false) throw new RuntimeException("Test fehlgeschlagen: $err");

                $short = trim((string)$body);
                if (strlen($short) > 220) $short = substr($short, 0, 220) . '...';

                if ($code >= 200 && $code < 300) {
                    $flash = ['type' => 'ok', 'msg' => "Test OK ({$row['fqdn']}) HTTP $code: $short"];
                } else {
                    $flash = ['type' => 'error', 'msg' => "Test FEHLER ({$row['fqdn']}) HTTP $code: $short"];
                }
            }

            if ($action === 'toggle_docker_wan') {
                $enabled = isset($_POST['docker_wan_update']) ? 1 : 0;
                $interval = (int)($_POST['docker_wan_interval_minutes'] ?? 5);
                if ($interval < 1 || $interval > 15) {
                    $interval = 5;
                }
                $stmt = $db->prepare("
                    UPDATE domains
                    SET docker_wan_update = :enabled,
                        docker_wan_interval_minutes = :interval,
                        docker_wan_last_run_at = CASE WHEN :enabled = 1 THEN NULL ELSE docker_wan_last_run_at END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->bindValue(':enabled', $enabled, SQLITE3_INTEGER);
                $stmt->bindValue(':interval', $interval, SQLITE3_INTEGER);
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                $flash = [
                    'type' => 'ok',
                    'msg' => $enabled === 1
                        ? "Docker-WAN-Aktualisierung fuer die Domain aktiviert (alle {$interval} Minute" . ($interval === 1 ? '' : 'n') . ').'
                        : 'Docker-WAN-Aktualisierung fuer die Domain deaktiviert.'
                ];
            }

            if ($action === 'update_note') {
                $note = trim((string)($_POST['note'] ?? ''));
                $stmt = $db->prepare("
                    UPDATE domains
                    SET note = :note,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->bindValue(':note', $note, SQLITE3_TEXT);
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                $flash = ['type' => 'ok', 'msg' => 'Notiz gespeichert.'];
            }
        }

        else {
            throw new RuntimeException("Unbekannte action: $action");
        }

    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'Aktion fehlgeschlagen: ' . $e->getMessage()];
    }
}

// -------------------- Manual create (Host oder FQDN) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_domain'])) {
    $input = trim((string)($_POST['fqdn'] ?? ''));
    $recordId = trim((string)($_POST['record_id'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    $baseZone = trim((string)($cfg['base_zone'] ?? ''));

    if ($input === '') {
        $flash = ['type' => 'error', 'msg' => 'Bitte Host oder FQDN ausfüllen.'];
    } else {
        if (strpos($input, '.') === false) {
            if ($baseZone === '') {
                $flash = ['type' => 'error', 'msg' => 'base_zone fehlt in config.php (für Host-Eingabe ohne Punkt).'];
            } elseif (!preg_match('/^[a-z0-9-]{1,63}$/i', $input)) {
                $flash = ['type' => 'error', 'msg' => 'Host ungültig. Erlaubt: Buchstaben/Zahlen/Bindestrich (max. 63).'];
            } else {
                $fqdn = strtolower($input) . '.' . $baseZone;
            }
        } else {
            $fqdn = $input;
        }
    }

    if (!$flash) {
        if ($recordId === '') {
            $flash = ['type' => 'error', 'msg' => 'Bitte Record-ID ausfüllen. (Automatische Erstellung kommt im nächsten Schritt)'];
        } elseif (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $fqdn)) {
            $flash = ['type' => 'error', 'msg' => 'FQDN sieht ungültig aus (z.B. test.example.de).'];
        } else {
            $newToken = random_token(48);
            $hash = token_hash($newToken);
            $enc  = encrypt_token_local($newToken);

            try {
                $stmt = $db->prepare('
                    INSERT INTO domains (fqdn, record_id, token_hash, token_enc, active, note, updated_at)
                    VALUES (:fqdn, :record_id, :token_hash, :token_enc, 1, :note, CURRENT_TIMESTAMP)
                ');
                $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
                $stmt->bindValue(':record_id', (int)$recordId, SQLITE3_INTEGER);
                $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
                $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
                $stmt->bindValue(':note', $note, SQLITE3_TEXT);
                $stmt->execute();

                $flash = ['type' => 'ok', 'msg' => 'Domain angelegt. Token wird unten angezeigt.'];
                do_autosync($db, $flash);
            } catch (Throwable $e) {
                $newToken = null;
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $flash = ['type' => 'error', 'msg' => 'Diese FQDN existiert bereits.'];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Fehler: ' . $e->getMessage()];
                }
            }
        }
    }
}

// -------------------- List --------------------
$res = $db->query('
    SELECT id, fqdn,
           record_id,
           COALESCE(record_id_a, 0)    AS record_id_a,
           COALESCE(record_id_aaaa, 0) AS record_id_aaaa,
           active, last_ip, last_update, created_at,
           COALESCE(docker_wan_update, 0) AS docker_wan_update,
           COALESCE(docker_wan_interval_minutes, 5) AS docker_wan_interval_minutes,
           COALESCE(docker_wan_last_run_at, "") AS docker_wan_last_run_at,
           COALESCE(provider_synced_at, "") AS provider_synced_at,
           COALESCE(updated_at, "") AS updated_at,
           COALESCE(note, "") AS note,
           COALESCE(zone, "") AS zone
    FROM domains
    ORDER BY id DESC
');

$domains = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $domains[] = $row;
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DynDNS Admin – Domains</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; }
    label { display:block; font-size: 13px; margin-bottom: 6px; color: #333; }
    input { padding: 10px; border: 1px solid #bbb; border-radius: 8px; min-width: 260px; }
    input[type="checkbox"] { min-width: auto; width: 16px; height: 16px; padding: 0; border-radius: 4px; }
    select { padding: 10px; border: 1px solid #bbb; border-radius: 8px; }
    button { padding: 9px 12px; border: 0; border-radius: 8px; cursor: pointer; }
    button.primary { background: #111; color: #fff; }
    button.warn { background: #f3f3f3; }
    button.danger { background: #ffecec; }
    .flash-ok { background: #eaffea; border: 1px solid #9ad59a; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
    .flash-err { background: #ffecec; border: 1px solid #e4a0a0; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
    .badge { display:inline-block; padding: 3px 8px; border-radius: 999px; font-size: 12px; }
    .on { background: #eaffea; border:1px solid #9ad59a; }
    .off { background: #ffecec; border:1px solid #e4a0a0; }
    code { word-break: break-all; }
    .muted { color:#666; font-size: 12px; }
    form.inline { display:inline; margin:0; }
    details.card > summary { cursor: pointer; }
    details.card[open] > summary { margin-bottom: 10px; }
  </style>
</head>
<body>

<h2>DynDNS Admin – Domains</h2>

<div class="card">
  <h3>Konfiguration</h3>
  <div class="row">
    <div>
      <label>Base-Zone</label>
      <code><?= h((string)($cfg['base_zone'] ?? '')) ?: 'nicht gesetzt' ?></code>
    </div>
    <div>
      <label>DynDNS-Modus</label>
      <code><?= h((string)($cfg['dyndns_mode'] ?? '')) ?: 'nicht gesetzt' ?></code>
    </div>
    <div>
      <label>DynDNS Update-URL</label>
      <code><?= h((string)($cfg['update_url'] ?? '')) ?: 'nicht gesetzt' ?></code>
    </div>
  </div>
  <div class="muted" style="margin-top:8px;">
    Die Update-URL wird für die Test-Funktion verwendet. Je nach Betriebsart trägst du hier entweder deinen Netcup-Webspace-Link oder die Docker-API-URL ein.
  </div>
</div>

<?php if ($flash): ?>
  <div class="<?= ($flash['type'] === 'ok') ? 'flash-ok' : 'flash-err' ?>">
    <?= h($flash['msg']) ?>
  </div>
<?php endif; ?>

<?php if ($newToken): ?>
  <div class="card" style="margin-top:14px;">
    <strong>Token:</strong>
    <p><code><?= h($newToken) ?></code></p>
  </div>
<?php endif; ?>

<?php if ($shownToken): ?>
  <div class="card" style="margin-top:14px;">
    <strong>Token anzeigen:</strong>
    <p><code><?= h($shownToken) ?></code></p>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Neue Domain anlegen (empfohlen)</h3>
  <div class="muted" style="margin-bottom:10px;">
    Erstellt direkt einen IPv4- und einen IPv6-Eintrag bei Netcup und erzeugt danach den passenden DynDNS-Token.
  </div>
  <form method="post" onsubmit="return confirm('A und AAAA bei Netcup anlegen?');">
    <input type="hidden" name="action" value="create_netcup_both">
    <div class="row">
      <div>
        <label>Host (nur vorne, z.B. test)</label>
        <input name="host" placeholder="test" required>
      </div>
      <div>
        <label>Start-IP IPv4 (A)</label>
        <input name="dest4" placeholder="0.0.0.0" value="0.0.0.0" required>
      </div>
      <div>
        <label>Start-IP IPv6 (AAAA)</label>
        <input name="dest6" placeholder="::" value="::" required>
      </div>
      <div>
        <label>Notiz (optional)</label>
        <input name="note" placeholder="z.B. FritzBox + IPv6 Script">
      </div>
    </div>
    <div style="margin-top:12px;">
      <button class="primary" type="submit">Domain anlegen und Token erzeugen</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Aktuellen Stand abrufen</h3>
  <div class="muted" style="margin-bottom:10px;">
    Liest den aktuellen DNS-Stand von Netcup ein und zeigt dir, welche IP dort momentan wirklich gesetzt ist.
  </div>
  <form method="post" onsubmit="return confirm('Aktuelle DNS-IP von Netcup laden?');">
    <input type="hidden" name="action" value="refresh">
    <button class="primary" type="submit">Aktuelle IP von Netcup abrufen</button>
  </form>
  <div class="muted" style="margin-top:8px;">
    Hinweis: Für die Aktualisierung wird die Spalte <code>zone</code> benötigt (Netcup-Import setzt sie automatisch).
  </div>
  <div class="muted" style="margin-top:6px;">
    Zusätzlich aktualisiert dieser Button alle aktuell fälligen Einträge mit aktivierter Docker-WAN-Option direkt auf die aktuelle öffentliche IPv4 des Docker-Hosts.
  </div>
  <div class="muted" style="margin-top:6px;">
    DynDNS-Modus:
    <?php if (($cfg['dyndns_mode'] ?? '') === 'local_api'): ?>
      Echte Client-Updates werden separat erkannt und unter <code>Letzte DynDNS-Aktualisierung</code> angezeigt.
    <?php else: ?>
      In der Webspace-Variante kann das System echte Client-Aufrufe nicht direkt sehen. <code>Letzte DynDNS-Aktualisierung</code> bleibt deshalb nur bei lokalen Tests oder lokaler API aussagekräftig.
    <?php endif; ?>
  </div>
</div>

<details class="card">
  <summary><strong>Erweitert</strong></summary>

  <div class="muted" style="margin:10px 0 14px 0;">
    Diese Funktionen brauchst du nur in Sonderfällen, für Wartung oder wenn du bewusst von der Standardanlage abweichen willst.
  </div>

  <div class="card" style="margin-bottom:12px;">
    <h3>Export und Upload</h3>
    <div class="muted" style="margin-bottom:10px;">
      Erstellt die Exportdateien neu und lädt sie zum Zielsystem hoch. Für die Netcup-Webspace-Variante ist das wichtig, wenn du Änderungen sofort veröffentlichen willst.
    </div>
    <form method="post" class="inline" onsubmit="return confirm('Jetzt Auto-Sync ausführen?');">
      <input type="hidden" name="action" value="sync">
      <button class="primary" type="submit">Jetzt exportieren und hochladen</button>
    </form>
    <div class="muted" style="margin-top:8px;">
      Tipp: Auto-Sync läuft auch automatisch nach Create/Delete/Rotate.
    </div>
  </div>

  <div class="card" style="margin-bottom:12px;">
    <h3>Nur IPv4 anlegen</h3>
    <div class="muted" style="margin-bottom:10px;">
      Nur für Sonderfälle, wenn du bewusst ausschließlich einen IPv4-Eintrag ohne IPv6 anlegen willst.
    </div>
    <form method="post" onsubmit="return confirm('A-Record bei Netcup anlegen?');">
      <input type="hidden" name="action" value="create_netcup_a">
      <div class="row">
        <div>
          <label>Host (nur vorne, z.B. test)</label>
          <input name="host" placeholder="test" required>
        </div>
        <div>
          <label>Start-IP (IPv4, z.B. 0.0.0.0)</label>
          <input name="dest" placeholder="0.0.0.0" value="0.0.0.0" required>
        </div>
        <div>
          <label>Notiz (optional)</label>
          <input name="note" placeholder="z.B. FritzBox Büro">
        </div>
      </div>
      <div style="margin-top:12px;">
        <button class="primary" type="submit">IPv4-Domain anlegen und Token erzeugen</button>
      </div>
      <div class="muted" style="margin-top:8px;">
        Zone aus <code>base_zone</code>: <code><?= h((string)($cfg['base_zone'] ?? '')) ?></code>
      </div>
    </form>
  </div>

  <div class="card" style="margin-bottom:12px;">
    <h3>Nur IPv6 anlegen</h3>
    <div class="muted" style="margin-bottom:10px;">
      Nur für Sonderfälle, wenn du bewusst ausschließlich einen IPv6-Eintrag ohne IPv4 anlegen willst.
    </div>
    <form method="post" onsubmit="return confirm('AAAA-Record bei Netcup anlegen?');">
      <input type="hidden" name="action" value="create_netcup_aaaa">
      <div class="row">
        <div>
          <label>Host (nur vorne, z.B. test)</label>
          <input name="host" placeholder="test" required>
        </div>
        <div>
          <label>Start-IP (IPv6, z.B. ::)</label>
          <input name="dest" placeholder="::" value="::" required>
        </div>
        <div>
          <label>Notiz (optional)</label>
          <input name="note" placeholder="z.B. UniFi IPv6">
        </div>
      </div>
      <div style="margin-top:12px;">
        <button class="primary" type="submit">IPv6-Domain anlegen und Token erzeugen</button>
      </div>
    </form>
  </div>

  <h3>Manuell anlegen</h3>
  <div class="muted" style="margin-bottom:10px;">
    Nur für Experten. Damit kannst du einen Eintrag manuell mit bestehender Record-ID anlegen, ohne ihn vorher automatisch bei Netcup erstellen zu lassen.
  </div>
  <form method="post">
    <input type="hidden" name="create_domain" value="1">
    <div class="row">
      <div>
        <label>Host oder FQDN (z.B. test oder test.example.de)</label>
        <input name="fqdn" placeholder="test" required>
      </div>
      <div>
        <label>Netcup Record-ID (DNS Record ID)</label>
        <input name="record_id" placeholder="123456" required>
      </div>
      <div>
        <label>Notiz (optional)</label>
        <input name="note" placeholder="z.B. FritzBox Wohnzimmer">
      </div>
    </div>
    <div style="margin-top:12px;">
      <button class="primary" type="submit">Manuell anlegen und Token erzeugen</button>
    </div>
  </form>
</details>

<div class="card">
  <h3>Vorhandene Domains</h3>

  <?php if (count($domains) === 0): ?>
    <p>Noch keine Einträge.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Status</th>
          <th>FQDN</th>
          <th>Record-IDs</th>
          <th>Letzte IP</th>
          <th>Letzte DynDNS-Aktualisierung</th>
          <th>Zuletzt mit Netcup abgeglichen</th>
          <th>Zone</th>
          <th>Notiz</th>
          <th>Docker-WAN</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($domains as $d): ?>
          <tr>
            <td>
              <span class="badge <?= ((int)$d['active'] === 1) ? 'on' : 'off' ?>">
                <?= ((int)$d['active'] === 1) ? 'aktiv' : 'deaktiviert' ?>
              </span>
              <div class="muted">ID: <?= (int)$d['id'] ?></div>
            </td>
            <td><?= h($d['fqdn']) ?></td>
            <td>
              <div><strong>A:</strong> <?= h((string)((int)($d['record_id_a'] ?? 0))) ?></div>
              <div><strong>AAAA:</strong> <?= h((string)((int)($d['record_id_aaaa'] ?? 0))) ?></div>
              <div class="muted">legacy: <?= h((string)($d['record_id'] ?? '')) ?></div>
            </td>
            <td><?= h($d['last_ip'] ?? '') ?></td>
            <td><?= h($d['last_update'] ?? '') ?></td>
            <td><?= h($d['provider_synced_at'] ?? '') ?></td>
            <td><?= h($d['zone'] ?? '') ?></td>
            <td><?= h($d['note'] ?? '') ?></td>
            <td>
              <form class="inline" method="post">
                <input type="hidden" name="action" value="toggle_docker_wan">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <label style="display:flex; align-items:center; gap:6px; margin:0 0 6px 0;">
                  <input type="checkbox" name="docker_wan_update" value="1" <?= ((int)($d['docker_wan_update'] ?? 0) === 1) ? 'checked' : '' ?> onchange="this.form.submit()">
                  <span>mit Docker-WAN pflegen</span>
                </label>
                <label style="display:block; margin:0;">
                  <select name="docker_wan_interval_minutes" onchange="this.form.submit()" style="padding:6px 8px; border:1px solid #bbb; border-radius:8px;">
                    <?php for ($minute = 1; $minute <= 15; $minute++): ?>
                      <option value="<?= $minute ?>" <?= ((int)($d['docker_wan_interval_minutes'] ?? 5) === $minute) ? 'selected' : '' ?>>
                        alle <?= $minute ?> Minute<?= $minute === 1 ? '' : 'n' ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </label>
              </form>
              <div class="muted">Nur fuer interne Heimnetz-Clients gedacht.</div>
              <div class="muted">Letzter Docker-WAN-Lauf: <?= h($d['docker_wan_last_run_at'] ?? '') ?></div>
            </td>
            <td>
              <form class="inline" method="post" onsubmit="return confirm('Status wirklich umschalten?');">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="warn" type="submit"><?= ((int)$d['active'] === 1) ? 'Deaktivieren' : 'Aktivieren' ?></button>
              </form>

              <form class="inline" method="post" onsubmit="return confirm('Token wirklich erneuern? Alte Geräte funktionieren danach NICHT mehr.');">
                <input type="hidden" name="action" value="rotate">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="warn" type="submit">Token erneuern</button>
              </form>

              <form class="inline" method="post">
                <input type="hidden" name="action" value="showtoken">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="warn" type="submit">Token anzeigen</button>
              </form>

              <form class="inline" method="post" onsubmit="var note = prompt('Notiz bearbeiten', <?= json_encode((string)($d['note'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>); if (note === null) return false; this.note.value = note;">
                <input type="hidden" name="action" value="update_note">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="note" value="">
                <button class="warn" type="submit">Notiz bearbeiten</button>
              </form>

              <form class="inline" method="post" onsubmit="return confirm('Test ausführen? (ruft Netcup-Update-Endpoint auf)');">
                <input type="hidden" name="action" value="test">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="warn" type="submit">Update testen</button>
              </form>

              <form class="inline" method="post"
                onsubmit="return confirm('ACHTUNG!\\n\\nDer DNS-Record wird bei Netcup GELÖSCHT und kann nicht wiederhergestellt werden.\\n\\nFortfahren?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="danger" type="submit">Löschen (Netcup)</button>
              </form>

              <div class="muted">Geändert: <?= h($d['updated_at'] ?? '') ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</body>
</html>

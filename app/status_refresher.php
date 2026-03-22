<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/netcup_api.php';

function refresh_current_ips(): array {
    $db = db();

    // Domains laden (wir brauchen zone + record_id)
    $res = $db->query("
        SELECT id, fqdn, record_id, COALESCE(zone,'') AS zone
        FROM domains
        ORDER BY id DESC
    ");

    $rows = [];
    $zones = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
        if ($r['zone'] !== '') $zones[$r['zone']] = true;
    }

    if (count($zones) === 0) {
        return ['updated'=>0, 'skipped'=>count($rows), 'note'=>'Keine zone gespeichert (bitte über Netcup-Import importieren oder zone nachtragen).'];
    }

    $sid = netcup_login();
    try {
        // pro Zone alle Records holen
        $zoneRecords = [];
        foreach (array_keys($zones) as $z) {
            $recs = netcup_info_dns_records($sid, $z);
            // Map: record_id -> destination
            $map = [];
            foreach ($recs as $rec) {
                if (!isset($rec['id'])) continue;
                $map[(string)$rec['id']] = (string)($rec['destination'] ?? '');
            }
            $zoneRecords[$z] = $map;
        }
    } finally {
        netcup_logout($sid);
    }

    $updated = 0;
    $skipped = 0;

    foreach ($rows as $r) {
        $zone = $r['zone'];
        $rid  = (string)$r['record_id'];

        if ($zone === '' || !isset($zoneRecords[$zone][$rid])) {
            $skipped++;
            continue;
        }

        $dest = $zoneRecords[$zone][$rid];

        $stmt = $db->prepare("
            UPDATE domains
            SET last_ip = :ip,
                last_update = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':ip', $dest, SQLITE3_TEXT);
        $stmt->bindValue(':id', (int)$r['id'], SQLITE3_INTEGER);
        $stmt->execute();
        $updated++;
    }

    return ['updated'=>$updated, 'skipped'=>$skipped, 'note'=>'OK'];
}

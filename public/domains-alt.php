<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../app/autosync.php';
define('ENABLE_MANUAL_CREATE', false);

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
require __DIR__ . '/../app/netcup_api.php';

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
                last_update = CURRENT_TIMESTAMP,
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

// Actions: toggle/delete/rotate/showtoken/refresh
if (in_array($action, ['toggle', 'delete', 'rotate', 'showtoken', 'sync', 'test'], true)) {
    $action = (string)($_POST['action'] ?? '');

    // DEBUG (optional) – kannst du später wieder entfernen
    file_put_contents(
        __DIR__ . '/../data/post_debug.log',
        date('c') . ' ' . print_r($_POST, true) . "\n",
        FILE_APPEND
    );

    try {
            if ($action === 'create_netcup_a') {
            $cfg = require __DIR__ . '/../app/config.php';
            $zone = trim((string)($cfg['base_zone'] ?? ''));
            if ($zone === '') throw new RuntimeException("base_zone fehlt in config.php");

            $host = strtolower(trim($_POST['host'] ?? ''));
            $dest = trim($_POST['dest'] ?? '0.0.0.0');
            $note = trim($_POST['note'] ?? '');

            if ($host === '' || !preg_match('/^[a-z0-9-]{1,63}$/i', $host)) {
                throw new RuntimeException("Host ungültig. Erlaubt: a-z 0-9 - (max 63).");
            }
            if (!filter_var($dest, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new RuntimeException("Destination muss eine gültige IPv4 sein (z.B. 0.0.0.0).");
            }

            $fqdn = $host . '.' . $zone;

            // lokal schon vorhanden?
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM domains WHERE fqdn = :fqdn");
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $c = (int)$stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'];
            if ($c > 0) {
                throw new RuntimeException("FQDN existiert bereits lokal: $fqdn");
            }

            // Netcup: Record anlegen + Record-ID ermitteln
            $sid = netcup_login();
            try {
                netcup_create_dns_record($sid, $zone, $host, 'A', $dest);
                $recordId = netcup_find_record_id($sid, $zone, $host, 'A', $dest);
            } finally {
                netcup_logout($sid);
            }

            // Token erzeugen & lokal speichern
            $newToken = random_token(48);
            $hash = token_hash($newToken);
            $enc  = encrypt_token_local($newToken);

            $stmt = $db->prepare('
                INSERT INTO domains (fqdn, record_id, token_hash, token_enc, active, note, zone, hostname, record_type, last_ip, updated_at)
                VALUES (:fqdn, :record_id, :token_hash, :token_enc, 1, :note, :zone, :hostname, :rtype, :last_ip, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $stmt->bindValue(':record_id', (string)$recordId, SQLITE3_TEXT);
            $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
            $stmt->bindValue(':note', $note, SQLITE3_TEXT);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':hostname', $host, SQLITE3_TEXT);
            $stmt->bindValue(':rtype', 'A', SQLITE3_TEXT);
            $stmt->bindValue(':last_ip', $dest, SQLITE3_TEXT);
            $stmt->execute();

            $flash = ['type' => 'ok', 'msg' => "Netcup A-Record erstellt: $fqdn (Record-ID $recordId). Token wird angezeigt."];
            do_autosync($db, $flash);
        }

        if ($action === 'refresh') {
            if ($action === 'create_netcup_a') {
    $cfg = require __DIR__ . '/../app/config.php';
    $zone = trim((string)($cfg['base_zone'] ?? ''));
    if ($zone === '') throw new RuntimeException("base_zone fehlt in config.php");

    $host = strtolower(trim($_POST['host'] ?? ''));
    $dest = trim($_POST['dest'] ?? '0.0.0.0');
    $note = trim($_POST['note'] ?? '');

    if ($host === '' || !preg_match('/^[a-z0-9-]{1,63}$/i', $host)) {
        throw new RuntimeException("Host ungültig. Erlaubt: a-z 0-9 - (max 63).");
    }
    if (!filter_var($dest, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException("Destination muss eine gültige IPv4 sein (z.B. 0.0.0.0).");
    }

    // Prüfen ob fqdn schon existiert
    $fqdn = $host . '.' . $zone;
    $exists = $db->querySingle("SELECT COUNT(*) FROM domains WHERE fqdn = " . "'" . SQLite3::escapeString($fqdn) . "'");
    if ((int)$exists > 0) {
        throw new RuntimeException("FQDN existiert bereits lokal: $fqdn");
    }

    // Netcup: Record anlegen + ID ermitteln
    $sid = netcup_login();
    try {
        netcup_create_dns_record($sid, $zone, $host, 'A', $dest);
        $recordId = netcup_find_record_id($sid, $zone, $host, 'A', $dest);
    } finally {
        netcup_logout($sid);
    }

    // Token erzeugen & lokal speichern
    $newToken = random_token(48);
    $hash = token_hash($newToken);
    $enc  = encrypt_token_local($newToken);

    $stmt = $db->prepare('
        INSERT INTO domains (fqdn, record_id, token_hash, token_enc, active, note, zone, hostname, record_type, last_ip, updated_at)
        VALUES (:fqdn, :record_id, :token_hash, :token_enc, 1, :note, :zone, :hostname, :rtype, :last_ip, CURRENT_TIMESTAMP)
    ');
    $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
    $stmt->bindValue(':record_id', (string)$recordId, SQLITE3_TEXT);
    $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
    $stmt->bindValue(':note', $note, SQLITE3_TEXT);
    $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
    $stmt->bindValue(':hostname', $host, SQLITE3_TEXT);
    $stmt->bindValue(':rtype', 'A', SQLITE3_TEXT);
    $stmt->bindValue(':last_ip', $dest, SQLITE3_TEXT);
    $stmt->execute();

    $flash = ['type' => 'ok', 'msg' => "Netcup A-Record erstellt: $fqdn (Record-ID $recordId). Token wird unten angezeigt."];
}

            $r = refresh_current_ips_from_netcup();
            $flash = ['type' => 'ok', 'msg' => "Aktualisiert: {$r['updated']}, übersprungen: {$r['skipped']} ({$r['note']})"];
        }

        if (in_array($action, ['toggle', 'delete', 'rotate', 'showtoken'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException("Ungültige ID");
            }

            if ($action === 'toggle') {
                $stmt = $db->prepare("UPDATE domains SET active = CASE active WHEN 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                $flash = ['type' => 'ok', 'msg' => 'Status geändert.'];
            }

            if ($action === 'delete') {
                // Domain-Daten laden
                $stmt = $db->prepare("
                    SELECT fqdn, record_id, zone
                    FROM domains
                    WHERE id = :id
                ");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if (!$row) {
                    throw new RuntimeException("Eintrag nicht gefunden.");
                }

                $zone = (string)$row['zone'];
                $recordId = (int)$row['record_id'];

                if ($zone === '' || $recordId <= 0) {
                    throw new RuntimeException("Zone oder Record-ID fehlt – Netcup-Löschung nicht möglich.");
                }

                // Netcup: DNS-Record löschen
                $sid = netcup_login();
                try {
                    netcup_delete_dns_record($sid, $zone, $recordId);
                } finally {
                    netcup_logout($sid);
                }

                // Lokal löschen
                $stmt = $db->prepare("DELETE FROM domains WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();

                $flash = [
                    'type' => 'ok',
                    'msg'  => "DNS-Record und lokaler Eintrag gelöscht: {$row['fqdn']}"
                ];
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
                // Wichtig: damit nicht aus Versehen der "Neuer Token"-Block angezeigt wird
                $newToken = null;

                $beforeHash = (string)$db->querySingle("SELECT token_hash FROM domains WHERE id = " . (int)$id);

                $stmt = $db->prepare("SELECT fqdn, COALESCE(token_enc,'') AS token_enc FROM domains WHERE id = :id");
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if (!$row) {
                    throw new RuntimeException("Eintrag nicht gefunden.");
                }

                if ($row['token_enc'] === '') {
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
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => 'Aktion fehlgeschlagen: ' . $e->getMessage()];
    }
}

// Create domain (Host oder FQDN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_domain'])) {
    $input = trim($_POST['fqdn'] ?? '');
    $recordId = trim($_POST['record_id'] ?? '');
    $note = trim($_POST['note'] ?? '');

    $cfg = require __DIR__ . '/../app/config.php';
    $baseZone = trim((string)($cfg['base_zone'] ?? ''));

    // Host -> FQDN umwandeln
    if ($input === '') {
        $flash = ['type' => 'error', 'msg' => 'Bitte Host oder FQDN ausfüllen.'];
    } else {
        if (strpos($input, '.') === false) {
            // Host-Eingabe
            if ($baseZone === '') {
                $flash = ['type' => 'error', 'msg' => 'base_zone fehlt in config.php (für Host-Eingabe ohne Punkt).'];
            } elseif (!preg_match('/^[a-z0-9-]{1,63}$/i', $input)) {
                $flash = ['type' => 'error', 'msg' => 'Host ungültig. Erlaubt: Buchstaben/Zahlen/Bindestrich (max. 63).'];
            } else {
                $fqdn = strtolower($input) . '.' . $baseZone;
            }
        } else {
            // Vollständige FQDN-Eingabe
            $fqdn = $input;
        }
    }

    // Weiter mit Validierung + Insert (nur wenn noch kein Flash gesetzt wurde)
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
                $stmt->bindValue(':record_id', $recordId, SQLITE3_TEXT);
                $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
                $stmt->bindValue(':token_enc', $enc, SQLITE3_TEXT);
                $stmt->bindValue(':note', $note, SQLITE3_TEXT);
                $stmt->execute();

                $flash = ['type' => 'ok', 'msg' => 'Domain angelegt. Token wird unten angezeigt.'];
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

// List
$res = $db->query('
    SELECT id, fqdn, record_id, active, last_ip, last_update, created_at,
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
  </style>
</head>
<body>

<h2>DynDNS Admin – Domains</h2>
<?php if ($flash): ?>
  <div class="<?= $flash['type'] === 'ok' ? 'flash-ok' : 'flash-err' ?>">
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
  <h3>Netcup A-Record anlegen</h3>
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
      <button class="primary" type="submit">Bei Netcup anlegen + Token erzeugen</button>
    </div>

    <div class="muted" style="margin-top:8px;">
      Zone wird automatisch aus <code>base_zone</code> genommen: <code><?= h((require __DIR__ . '/../app/config.php')['base_zone'] ?? '') ?></code>
    </div>
  </form>
</div>

<div class="card">
  <h3>Status / Aktualisierung</h3>
  <form method="post" onsubmit="return confirm('Aktuelle DNS-IP von Netcup laden?');">
    <input type="hidden" name="action" value="refresh">
    <button class="primary" type="submit">Aktualisieren (IP von Netcup holen)</button>
  </form>
  <div class="muted" style="margin-top:8px;">
    Hinweis: Für die Aktualisierung wird die Spalte <code>zone</code> benötigt (Netcup-Import setzt sie automatisch).
  </div>
</div>

<details class="card">
  <summary><strong>Manuell anlegen (Experten)</strong></summary>

<div class="card">
  <h3>Neue Subdomain hinzufügen</h3>

  <?php if ($flash): ?>
    <div class="<?= $flash['type'] === 'ok' ? 'flash-ok' : 'flash-err' ?>">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>
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
      <button class="primary" type="submit">Anlegen & Token generieren</button>
    </div>
  </form>
    </div>
    </details>
  <?php if ($newToken): ?>
    <div class="card">
      <strong>Neuer Token:</strong>
      <p><code><?= h($newToken) ?></code></p>
    </div>
  <?php endif; ?>

  <?php if ($shownToken): ?>
    <div class="card">
      <strong>Token anzeigen:</strong>
      <p><code><?= h($shownToken) ?></code></p>
    </div>
  <?php endif; ?>
</div>

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
          <th>Record-ID</th>
          <th>Letzte IP</th>
          <th>Letztes Update</th>
          <th>Zone</th>
          <th>Notiz</th>
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
            <td><?= h($d['record_id']) ?></td>
            <td><?= h($d['last_ip'] ?? '') ?></td>
            <td><?= h($d['last_update'] ?? '') ?></td>
            <td><?= h($d['zone'] ?? '') ?></td>
            <td><?= h($d['note'] ?? '') ?></td>
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


              <<form class="inline" method="post">
                <input type="hidden" name="action" value="showtoken">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="warn" type="submit">Token anzeigen</button>
              </form>


              <form class="inline" method="post"
                onsubmit="return confirm('ACHTUNG!\n\nDer DNS-Record wird bei Netcup GELÖSCHT und kann nicht wiederhergestellt werden.\n\nFortfahren?');">
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

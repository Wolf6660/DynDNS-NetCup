<?php
require_once __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/netcup_api.php';

$db = db();
$flash = null;
$zone = trim($_GET['zone'] ?? '');
$records = [];
$sessionId = null;
$importedToken = null;

function fqdn_from(string $hostname, string $zone): string {
    $hostname = trim($hostname);
    if ($hostname === '@' || $hostname === '' ) return $zone;
    return $hostname . '.' . $zone;
}

try {
    // Import action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
        $zone = trim($_POST['zone'] ?? '');
        $recordId = trim($_POST['record_id'] ?? '');
        $hostname = trim($_POST['hostname'] ?? '');
        $type = trim($_POST['type'] ?? '');

        if ($zone === '' || $recordId === '' || $type === '') {
            throw new RuntimeException("Fehlende Felder beim Import.");
        }

        $fqdn = fqdn_from($hostname, $zone);

        // Token erzeugen (wird nur einmal angezeigt)
        $importedToken = random_token(48);
        $hash = token_hash($importedToken);

        // Insert or update (falls FQDN schon existiert)
        $stmt = $db->prepare("SELECT id FROM domains WHERE fqdn = :fqdn LIMIT 1");
        $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
        $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($existing && isset($existing['id'])) {
            $stmt = $db->prepare("
                UPDATE domains
                SET record_id = :record_id,
                    token_hash = :token_hash,
                    active = 1,
                    zone = :zone,
                    hostname = :hostname,
                    record_type = :rtype,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->bindValue(':record_id', $recordId, SQLITE3_TEXT);
            $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':hostname', $hostname, SQLITE3_TEXT);
            $stmt->bindValue(':rtype', $type, SQLITE3_TEXT);
            $stmt->bindValue(':id', (int)$existing['id'], SQLITE3_INTEGER);
            $stmt->execute();

            $flash = ['type'=>'ok','msg'=>"Aktualisiert: $fqdn (Token neu, aktiv)"];
        } else {
            $stmt = $db->prepare("
                INSERT INTO domains (fqdn, record_id, token_hash, active, zone, hostname, record_type, updated_at)
                VALUES (:fqdn, :record_id, :token_hash, 1, :zone, :hostname, :rtype, CURRENT_TIMESTAMP)
            ");
            $stmt->bindValue(':fqdn', $fqdn, SQLITE3_TEXT);
            $stmt->bindValue(':record_id', $recordId, SQLITE3_TEXT);
            $stmt->bindValue(':token_hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':hostname', $hostname, SQLITE3_TEXT);
            $stmt->bindValue(':rtype', $type, SQLITE3_TEXT);
            $stmt->execute();

            $flash = ['type'=>'ok','msg'=>"Importiert: $fqdn (aktiv)"];
        }
    }

    // Load records if zone provided
    if ($zone !== '') {
        $sessionId = netcup_login();
        $records = netcup_info_dns_records($sessionId, $zone);
        netcup_logout($sessionId);

        // Nur A/AAAA anzeigen (DynDNS-relevant)
        $records = array_values(array_filter($records, function($r) {
            $t = strtoupper(trim($r['type'] ?? ''));
            return in_array($t, ['A','AAAA'], true);
        }));
    }
} catch (Throwable $e) {
    $flash = ['type'=>'error','msg'=>$e->getMessage()];
    if ($sessionId) netcup_logout($sessionId);
}

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DynDNS Admin – Netcup Import</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
    input { padding: 10px; border: 1px solid #bbb; border-radius: 8px; min-width: 280px; }
    button { padding: 9px 12px; border: 0; border-radius: 8px; cursor: pointer; }
    button.primary { background: #111; color: #fff; }
    .flash-ok { background: #eaffea; border: 1px solid #9ad59a; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
    .flash-err { background: #ffecec; border: 1px solid #e4a0a0; padding: 10px; border-radius: 8px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
    code { word-break: break-all; }
    .muted { color:#666; font-size: 12px; }
    form.inline { display:inline; margin:0; }
  </style>
</head>
<body>

<h2>Netcup DNS Records importieren</h2>

<div class="card">
  <?php if ($flash): ?>
    <div class="<?= $flash['type']==='ok' ? 'flash-ok':'flash-err' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>

  <form method="get">
    <label class="muted">Domain / Zone (z.B. example.de)</label><br>
    <input name="zone" value="<?= h($zone) ?>" placeholder="example.de" required>
    <button class="primary" type="submit">Records laden</button>
  </form>

  <div class="muted" style="margin-top:10px;">
    Hinweis: Es werden nur A/AAAA Records angezeigt.
  </div>

  <?php if ($importedToken): ?>
    <div class="card" style="margin-top:14px;">
      <strong>Neuer Token (nur einmal sichtbar – jetzt kopieren!):</strong>
      <p><code><?= h($importedToken) ?></code></p>
    </div>
  <?php endif; ?>
</div>

<?php if ($zone !== ''): ?>
  <div class="card">
    <h3>Records für <?= h($zone) ?></h3>

    <?php if (count($records) === 0): ?>
      <p>Keine A/AAAA Records gefunden.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>FQDN</th>
            <th>Type</th>
            <th>Record ID</th>
            <th>Ziel</th>
            <th>Import</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r):
            $hostname = (string)($r['hostname'] ?? '');
            $type = strtoupper((string)($r['type'] ?? ''));
            $rid = (string)($r['id'] ?? '');
            $dest = (string)($r['destination'] ?? '');
            $fqdn = fqdn_from($hostname, $zone);
          ?>
            <tr>
              <td><?= h($fqdn) ?><div class="muted">hostname: <?= h($hostname) ?></div></td>
              <td><?= h($type) ?></td>
              <td><?= h($rid) ?></td>
              <td><?= h($dest) ?></td>
              <td>
                <form class="inline" method="post" onsubmit="return confirm('Importieren/aktualisieren und neuen Token erzeugen?');">
                  <input type="hidden" name="action" value="import">
                  <input type="hidden" name="zone" value="<?= h($zone) ?>">
                  <input type="hidden" name="record_id" value="<?= h($rid) ?>">
                  <input type="hidden" name="hostname" value="<?= h($hostname) ?>">
                  <input type="hidden" name="type" value="<?= h($type) ?>">
                  <button class="primary" type="submit">Import</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p class="muted">
  Tipp: Nach dem Import findest du den Eintrag auch in <code>domains.php</code> und kannst ihn dort deaktivieren/löschen/Token rotieren.
</p>

</body>
</html>

<?php
require_once __DIR__ . '/../app/helpers.php';

header('Content-Type: text/plain; charset=utf-8');

$db = db();

// Spalten anzeigen
echo "COLUMNS:\n";
$res = $db->query("PRAGMA table_info(domains)");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  echo "- {$row['name']}\n";
}

echo "\nLAST 10 rows token_enc length:\n";
$res = $db->query("SELECT id, fqdn, length(COALESCE(token_enc,'')) AS len FROM domains ORDER BY id DESC LIMIT 10");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  echo "{$row['id']}  {$row['fqdn']}  token_enc_len={$row['len']}\n";
}

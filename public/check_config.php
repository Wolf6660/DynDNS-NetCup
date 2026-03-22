<?php
ini_set('display_errors','1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$path = realpath(__DIR__ . '/../app/config.php');
echo "CONFIG PATH: " . $path . "\n";
echo "CONFIG FILESIZE: " . filesize($path) . "\n\n";

echo "FIRST 200 CHARS:\n";
$raw = file_get_contents($path);
echo substr($raw, 0, 200) . "\n\n";

echo "TRY REQUIRE...\n";
$cfg = require $path;
echo "OK. Keys: " . implode(', ', array_keys($cfg)) . "\n";

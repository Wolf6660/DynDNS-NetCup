<?php
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/domains.php';
$cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
echo shell_exec($cmd);

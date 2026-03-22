<?php
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../app/autosync.php';

$db = new SQLite3(__DIR__ . '/../data/dyndns.sqlite');

$r = autosync_now($db);
print_r($r);
<?php

$config = require __DIR__ . '/config.php';

try {
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('DB Fehler: ' . $e->getMessage());
}

return $pdo;

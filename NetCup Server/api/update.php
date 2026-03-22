<?php

$base = realpath(__DIR__ . '/..');
$dataDir = $base . '/data';
$secDir = $base . '/sec';
$libDir = $base . '/lib';

require_once $libDir . '/dyndns_update.php';

$configFile = $dataDir . '/dyndns_config.json';
$sigFile = $dataDir . '/dyndns_config.sig';
$signingSecret = (string)((require $secDir . '/signing.php')['signing_secret'] ?? '');
$netcup = require $secDir . '/netcup_env.php';

if ($signingSecret === '') {
    dyndns_fail('missing signing secret', 500);
}

handle_dyndns_update_request_local($configFile, $sigFile, $signingSecret, $netcup);

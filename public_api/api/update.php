<?php

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/dyndns_update.php';

$files = dyndns_export_files();
$secret = dyndns_signing_secret();

handle_dyndns_update_request($files['json'], $files['sig'], $secret);

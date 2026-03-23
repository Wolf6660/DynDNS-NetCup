<?php

require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/dyndns_update.php';

$config = require __DIR__ . '/../../app/config.php';

if (empty($config['debug_ip_endpoint'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

try {
    $details = dyndns_client_ip_details();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'selected_ip' => $details['selected_ip'],
        'remote_addr' => $details['remote_addr'],
        'forwarded' => $details['forwarded'],
        'x_forwarded_for' => $details['x_forwarded_for'],
        'x_real_ip' => $details['x_real_ip'],
        'true_client_ip' => $details['true_client_ip'],
        'cf_connecting_ip' => $details['cf_connecting_ip'],
        'candidates' => $details['candidates'],
        'valid_candidates' => $details['valid_candidates'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

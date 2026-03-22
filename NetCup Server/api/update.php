<?php
// --- CONFIG ---
$base = realpath(__DIR__ . '/..');
$dataDir = $base . '/data';
$secDir  = $base . '/sec';

$configFile = $dataDir . '/dyndns_config.json';
$sigFile    = $dataDir . '/dyndns_config.sig';

// Secret laden
$signingSecret = (require $secDir . '/signing.php')['signing_secret'];

// --- Helpers ---
function fail(string $msg, int $code = 403) {
    http_response_code($code);
    echo $msg;
    exit;
}

// --- Token ---
$token = $_GET['token'] ?? '';
if ($token === '') {
    fail('missing token', 400);
}
$tokenHash = hash('sha256', $token);

// --- Load & verify config ---
$json = @file_get_contents($configFile);
$sig  = @file_get_contents($sigFile);

if (!$json || !$sig) {
    fail('config not available', 500);
}

$calcSig = rtrim(strtr(
    base64_encode(hash_hmac('sha256', $json, $signingSecret, true)),
    '+/', '-_'
), '=');

if (!hash_equals(trim($sig), $calcSig)) {
    fail('invalid config signature', 500);
}

$config = json_decode($json, true);
if (!is_array($config)) {
    fail('invalid config json', 500);
}

// --- Find domain ---
$domain = null;
foreach ($config['domains'] as $d) {
    if (($d['active'] ?? false) && hash_equals($d['token_sha256'], $tokenHash)) {
        $domain = $d;
        break;
    }
}

if (!$domain) {
    fail('invalid or disabled token', 403);
}

// --- Determine IP ---
$ip = $_GET['ip'] ?? $_SERVER['REMOTE_ADDR'];
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    fail('invalid ip', 400);
}

// --- Netcup API ---
$netcup = require $secDir . '/netcup_env.php';

function netcup_call($action, $param, $netcup) {
    $payload = json_encode([
        'action' => $action,
        'param'  => $param
    ]);

    $ch = curl_init($netcup['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $res = curl_exec($ch);
    if ($res === false) {
        throw new RuntimeException(curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid Netcup response');
    }
    return $data;
}

// --- Login ---
$login = netcup_call('login', [
    'customernumber' => (string)$netcup['customer_number'],
    'apikey'         => (string)$netcup['api_key'],
    'apipassword'    => (string)$netcup['api_password'],
    'clientrequestid'=> bin2hex(random_bytes(8)),
], $netcup);

$sid = $login['responsedata']['apisessionid'] ?? null;
if (!$sid) {
    fail('netcup login failed', 500);
}

// --- Update record ---
try {
    netcup_call('updateDnsRecords', [
        'customernumber' => (string)$netcup['customer_number'],
        'apikey'         => (string)$netcup['api_key'],
        'apisessionid'   => (string)$sid,
        'clientrequestid'=> bin2hex(random_bytes(8)),
        'dnsrecordset'   => [
            [
                'id'          => (int)$domain['record_id'],
                'destination' => $ip
            ]
        ]
    ], $netcup);
} catch (Throwable $e) {
    fail('dns update failed', 500);
}

// --- Logout (optional) ---
@netcup_call('logout', [
    'customernumber' => (string)$netcup['customer_number'],
    'apikey'         => (string)$netcup['api_key'],
    'apisessionid'   => (string)$sid,
    'clientrequestid'=> bin2hex(random_bytes(8)),
], $netcup);

// --- OK ---
echo "OK {$domain['fqdn']} updated to $ip";

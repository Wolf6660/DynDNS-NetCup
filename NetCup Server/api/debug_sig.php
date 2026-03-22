<?php
$base = realpath(__DIR__ . '/..');   // wenn /api/update.php bei dir direkt unter der Domain liegt
$configFile = $base . '/data/dyndns_config.json';
$sigFile    = $base . '/data/dyndns_config.sig';

$signingSecret = 'HIER_DAS_GLEICHE_SECRET_WIE_AUF_DER_SYNOLOGY';

header('Content-Type: text/plain; charset=utf-8');

$json = @file_get_contents($configFile);
$sig  = trim((string)@file_get_contents($sigFile));

echo "configFile: $configFile\n";
echo "sigFile   : $sigFile\n";
echo "json bytes: " . ($json === false ? 'READ_FAIL' : strlen($json)) . "\n";
echo "sig file  : " . ($sig === '' ? 'EMPTY/READ_FAIL' : $sig) . "\n";

if ($json === false) exit;

$calcSig = rtrim(strtr(
    base64_encode(hash_hmac('sha256', $json, $signingSecret, true)),
    '+/', '-_'
), '=');

echo "calc sig  : $calcSig\n";
echo "match     : " . (hash_equals($sig, $calcSig) ? 'YES' : 'NO') . "\n";
echo "json sha256: " . hash('sha256', $json) . "\n";

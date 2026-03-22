<?php

require_once __DIR__ . '/bootstrap.php';

function db_path(): string {
    return app_db_path();
}

function db(): SQLite3 {
    static $db = null;
    if ($db instanceof SQLite3) return $db;

    ensure_runtime_dirs();
    $db = new SQLite3(db_path());
    $db->enableExceptions(true);
    ensure_domains_schema($db);
    return $db;
}

function random_token(int $length = 48): string {
    // Base64url ohne + / =
    $raw = random_bytes((int)ceil($length * 0.75) + 1);
    $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return substr($b64, 0, $length);
}

function token_hash(string $token): string {
    return hash('sha256', $token);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function b64u_enc(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64u_dec(string $txt): string {
    $txt = strtr($txt, '-_', '+/');
    $pad = strlen($txt) % 4;
    if ($pad) $txt .= str_repeat('=', 4 - $pad);
    return base64_decode($txt);
}

function encrypt_token_local(string $token): string {
    $cfg = require __DIR__ . '/config.php';
    $key = hash('sha256', $cfg['token_master_key'], true); // 32 bytes
    $iv  = random_bytes(12); // GCM nonce
    $tag = '';
    $cipher = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException("Token encryption failed");
    return b64u_enc($iv) . '.' . b64u_enc($tag) . '.' . b64u_enc($cipher);
}

function decrypt_token_local(string $enc): string {
    $cfg = require __DIR__ . '/config.php';
    $key = hash('sha256', $cfg['token_master_key'], true);

    $parts = explode('.', $enc);
    if (count($parts) !== 3) throw new RuntimeException("Invalid token_enc format");

    [$ivB, $tagB, $ctB] = $parts;
    $iv  = b64u_dec($ivB);
    $tag = b64u_dec($tagB);
    $ct  = b64u_dec($ctB);

    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException("Token decrypt failed (wrong key?)");
    return $plain;
}

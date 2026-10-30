<?php
// includes/crypto.php

require_once __DIR__ . '/../config/secret.php';

function app_encryption_key(): string
{
    $key = base64_decode(defined('APP_ENC_KEY') ? APP_ENC_KEY : '', true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Encryption key is missing or invalid.');
    }
    return $key;
}

/** Returns base64( iv[12] . tag[16] . ciphertext ) */
function encrypt_secret(string $plain): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return base64_encode($iv . $tag . $cipher);
}

/** Returns plaintext, or null if the payload is invalid/tampered/wrong key. */
function decrypt_secret(string $payload): ?string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        app_encryption_key(),
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    return $plain === false ? null : $plain;
}

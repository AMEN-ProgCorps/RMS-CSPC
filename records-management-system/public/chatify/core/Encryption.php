<?php
// =============================================================================
// core/Encryption.php — AES-256-GCM Message Encryption Helper
// =============================================================================
// Usage:
//   require_once __DIR__ . '/../config/encryption.php';
//   require_once __DIR__ . '/Encryption.php';
//
//   $cipher = encryptMessage('Hello, World!');
//   $plain  = decryptMessage($cipher);
// =============================================================================

/**
 * Encrypt a plaintext message string using AES-256-GCM.
 *
 * Stored format (base64-encoded):
 *   [ 12-byte IV ][ 16-byte GCM tag ][ variable-length ciphertext ]
 *
 * @param  string $plaintext  The raw message to encrypt.
 * @return string|false       Base64-encoded ciphertext, or false on failure.
 */
function encryptMessage(string $plaintext): string|false
{
    if (!defined('CHAT_ENCRYPTION_KEY') || !defined('CHAT_CIPHER')) {
        trigger_error('Encryption constants not loaded. Include config/encryption.php first.', E_USER_ERROR);
        return false;
    }

    $iv  = random_bytes(12);  // GCM standard: 96-bit nonce
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        CHAT_CIPHER,
        CHAT_ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        CHAT_GCM_TAG_LENGTH
    );

    if ($ciphertext === false) {
        return false;
    }

    // Pack: IV (12) + Tag (16) + Ciphertext
    return base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypt a ciphertext that was produced by encryptMessage().
 *
 * @param  string $encoded  Base64-encoded blob from encryptMessage().
 * @return string|false     Plaintext on success, false if decryption fails
 *                          (wrong key, tampered data, or invalid format).
 */
function decryptMessage(string $encoded): string|false
{
    if (!defined('CHAT_ENCRYPTION_KEY') || !defined('CHAT_CIPHER')) {
        trigger_error('Encryption constants not loaded. Include config/encryption.php first.', E_USER_ERROR);
        return false;
    }

    $raw = base64_decode($encoded, true);
    if ($raw === false) {
        return false; // not valid base64
    }

    // Minimum length: 12 (IV) + 16 (tag) + 1 (at least 1 ciphertext byte)
    if (strlen($raw) < 29) {
        return false;
    }

    $iv         = substr($raw, 0,  12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        CHAT_CIPHER,
        CHAT_ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    return $plaintext; // false if authentication tag mismatch (tampered)
}

/**
 * Safely decrypt a stored message, returning a fallback string on failure.
 * Use this in rendering loops so a single bad record doesn't break the chat.
 *
 * @param  string $encoded   Encrypted blob from JSON storage.
 * @param  string $fallback  Returned if decryption fails.
 * @return string
 */
function safeDecrypt(string $encoded, string $fallback = '[encrypted message]'): string
{
    $result = decryptMessage($encoded);
    return ($result !== false && $result !== '') ? $result : $fallback;
}

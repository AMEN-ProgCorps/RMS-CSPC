<?php
// =============================================================================
// config/encryption.php - AES-256-GCM Message Encryption Configuration
// =============================================================================
// WARNING: DO NOT COMMIT THIS FILE. Add config/ to your .gitignore.
// =============================================================================
//
// Generate a strong key:
//   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
//
// The result is a 64-character hex string. Paste it as CHAT_ENCRYPTION_KEY_HEX.
// The binary form is derived at runtime with hex2bin().
// =============================================================================

define('CHAT_ENCRYPTION_KEY_HEX',
    '7d8e9c7e5c7a5f8f2a8b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f'
);

// Derived binary key (32 bytes = 256 bits)
define('CHAT_ENCRYPTION_KEY', hex2bin(CHAT_ENCRYPTION_KEY_HEX));

// Cipher suite - GCM provides both confidentiality and authentication
define('CHAT_CIPHER', 'aes-256-gcm');

// GCM tag length in bytes (16 = 128-bit authentication tag - maximum)
define('CHAT_GCM_TAG_LENGTH', 16);

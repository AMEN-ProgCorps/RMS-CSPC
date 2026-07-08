<?php
// =============================================================================
// bootstrap.php - Shared autoloader for all chat endpoints
// =============================================================================
// Include this at the top of every endpoint file INSTEAD of the old
// copy-pasted isValidSession() function.
//
// Usage in every endpoint:
//   require_once __DIR__ . '/bootstrap.php';
//   session_start();
//   Auth::require();
//   session_write_close();
// =============================================================================

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/encryption.php';
require_once __DIR__ . '/core/Encryption.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/UserResolver.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/GlobalChatManager.php';
require_once __DIR__ . '/core/ConversationManager.php';

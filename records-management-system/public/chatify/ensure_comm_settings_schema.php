<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/Database.php';

try {
    $pdo = Database::getConnection();
    $pdo->exec("
        ALTER TABLE account_details ADD COLUMN IF NOT EXISTS allow_typing_preview BOOLEAN DEFAULT FALSE;
        ALTER TABLE account_details ADD COLUMN IF NOT EXISTS allow_see_typing_preview BOOLEAN DEFAULT FALSE;
        ALTER TABLE account_details ADD COLUMN IF NOT EXISTS allow_live_draft_preview BOOLEAN DEFAULT FALSE;
    ");
    echo "SCHEMA_UPDATED_SUCCESSFULLY\n";
} catch (Throwable $e) {
    echo "SCHEMA_ERROR: " . $e->getMessage() . "\n";
}

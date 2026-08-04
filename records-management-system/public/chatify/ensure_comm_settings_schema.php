<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/Database.php';

try {
    $pdo = Database::getConnection();
    $pdo->exec("
        ALTER TABLE account_details ADD COLUMN IF NOT EXISTS allow_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE account_details ADD COLUMN IF NOT EXISTS allow_see_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE account_details DROP COLUMN IF EXISTS allow_live_draft_preview;

        -- Alter default values to TRUE for the typing preview columns
        ALTER TABLE account_details ALTER COLUMN allow_typing_preview SET DEFAULT TRUE;
        ALTER TABLE account_details ALTER COLUMN allow_see_typing_preview SET DEFAULT TRUE;

        -- Update existing records to TRUE
        UPDATE account_details SET allow_typing_preview = TRUE WHERE allow_typing_preview IS NULL;
        UPDATE account_details SET allow_see_typing_preview = TRUE WHERE allow_see_typing_preview IS NULL;
    ");
    echo "SCHEMA_UPDATED_SUCCESSFULLY\n";
} catch (Throwable $e) {
    echo "SCHEMA_ERROR: " . $e->getMessage() . "\n";
}

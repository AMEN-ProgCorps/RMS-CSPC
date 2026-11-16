<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/Database.php';

try {
    $pdo   = Database::getConnection();
    $table = Database::t('account_details');
    $pdo->exec("
        ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS allow_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS allow_see_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE {$table} DROP COLUMN IF EXISTS allow_live_draft_preview;

        -- Alter default values to TRUE for the typing preview columns
        ALTER TABLE {$table} ALTER COLUMN allow_typing_preview SET DEFAULT TRUE;
        ALTER TABLE {$table} ALTER COLUMN allow_see_typing_preview SET DEFAULT TRUE;

        -- Update existing records to TRUE
        UPDATE {$table} SET allow_typing_preview = TRUE WHERE allow_typing_preview IS NULL;
        UPDATE {$table} SET allow_see_typing_preview = TRUE WHERE allow_see_typing_preview IS NULL;

        -- User verification badge: managed by Super Admin via User Verification modal
        ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS is_messify_verified BOOLEAN DEFAULT FALSE;
        UPDATE {$table} SET is_messify_verified = FALSE WHERE is_messify_verified IS NULL;
    ");
    echo "SCHEMA_UPDATED_SUCCESSFULLY\n";
} catch (Throwable $e) {
    echo "SCHEMA_ERROR: " . $e->getMessage() . "\n";
}

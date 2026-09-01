<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/core/Database.php';

try {
    $pdo = Database::getConnection();
    $pdo->exec("
        ALTER TABLE ' . Database::t('account_details') . ' ADD COLUMN IF NOT EXISTS allow_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE ' . Database::t('account_details') . ' ADD COLUMN IF NOT EXISTS allow_see_typing_preview BOOLEAN DEFAULT TRUE;
        ALTER TABLE ' . Database::t('account_details') . ' DROP COLUMN IF EXISTS allow_live_draft_preview;

        -- Alter default values to TRUE for the typing preview columns
        ALTER TABLE ' . Database::t('account_details') . ' ALTER COLUMN allow_typing_preview SET DEFAULT TRUE;
        ALTER TABLE ' . Database::t('account_details') . ' ALTER COLUMN allow_see_typing_preview SET DEFAULT TRUE;

        -- Update existing records to TRUE
        UPDATE ' . Database::t('account_details') . ' SET allow_typing_preview = TRUE WHERE allow_typing_preview IS NULL;
        UPDATE ' . Database::t('account_details') . ' SET allow_see_typing_preview = TRUE WHERE allow_see_typing_preview IS NULL;

        -- User verification badge: managed by Super Admin via User Verification modal
        ALTER TABLE ' . Database::t('account_details') . ' ADD COLUMN IF NOT EXISTS is_chatify_verified BOOLEAN DEFAULT FALSE;
        UPDATE ' . Database::t('account_details') . ' SET is_chatify_verified = FALSE WHERE is_chatify_verified IS NULL;

        -- Chatify custom theme color
        ALTER TABLE ' . Database::t('account_details') . ' ADD COLUMN IF NOT EXISTS chat_theme_color VARCHAR(20) DEFAULT \'#8ba888\';
        UPDATE ' . Database::t('account_details') . ' SET chat_theme_color = \'#8ba888\' WHERE chat_theme_color IS NULL OR chat_theme_color = \'\';
    ");
    echo "SCHEMA_UPDATED_SUCCESSFULLY\n";
} catch (Throwable $e) {
    echo "SCHEMA_ERROR: " . $e->getMessage() . "\n";
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW view_user_unread_chats AS
            SELECT 
                u.account_id,
                COALESCE(SUM(CASE WHEN cc.user_1 = u.account_id THEN cc.unread_user_1 ELSE cc.unread_user_2 END), 0)::BIGINT AS total_unread
            FROM (
                SELECT user_1 AS account_id FROM chat_conversations WHERE is_active = true
                UNION
                SELECT user_2 AS account_id FROM chat_conversations WHERE is_active = true
            ) u
            JOIN chat_conversations cc 
                ON (cc.user_1 = u.account_id OR cc.user_2 = u.account_id)
               AND cc.is_active = true
            GROUP BY u.account_id;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS view_user_unread_chats;");
    }
};

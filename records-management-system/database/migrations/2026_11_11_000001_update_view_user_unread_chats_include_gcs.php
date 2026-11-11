<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates view_user_unread_chats to include:
     * 1. Direct Messages (messify_chat_conversations)
     * 2. Custom Group Chats (messify_chat_custom_group_members + messify_chat_messages)
     * 3. Office Group Chats (messify_chat_office_gc_reads + messify_chat_messages)
     */
    public function up(): void
    {
        $hasConvs = Schema::hasTable('messify_chat_conversations');
        $hasGroupMembers = Schema::hasTable('messify_chat_custom_group_members');
        $hasMessages = Schema::hasTable('messify_chat_messages');
        $hasOfficeReads = Schema::hasTable('messify_chat_office_gc_reads');

        if (!$hasConvs) {
            return;
        }

        if ($hasGroupMembers && $hasMessages && $hasOfficeReads) {
            DB::statement("
                CREATE OR REPLACE VIEW view_user_unread_chats AS
                WITH all_unreads AS (
                    -- 1. Direct 1-on-1 conversations
                    SELECT 
                        u.account_id,
                        (CASE WHEN cc.user_1 = u.account_id THEN cc.unread_user_1 ELSE cc.unread_user_2 END)::BIGINT AS unread_count
                    FROM messify_chat_conversations cc
                    CROSS JOIN LATERAL (VALUES (cc.user_1), (cc.user_2)) AS u(account_id)
                    WHERE cc.is_active = true

                    UNION ALL

                    -- 2. Custom Group Chats (GCs)
                    SELECT 
                        m.account_id,
                        COUNT(msg.id)::BIGINT AS unread_count
                    FROM messify_chat_custom_group_members m
                    JOIN messify_chat_messages msg
                        ON msg.conv_id = ('gc_' || m.group_id::text)
                       AND msg.status = 'active'
                       AND msg.sender_id != m.account_id
                       AND (
                           (m.last_read_msg_id > 0 AND msg.id > m.last_read_msg_id)
                           OR
                           (COALESCE(m.last_read_msg_id, 0) <= 0 AND m.joined_at IS NOT NULL AND msg.created_at >= m.joined_at)
                       )
                    GROUP BY m.account_id, m.group_id

                    UNION ALL

                    -- 3. Office Group Chats
                    SELECT 
                        ogr.account_id,
                        COUNT(msg.id)::BIGINT AS unread_count
                    FROM messify_chat_office_gc_reads ogr
                    JOIN messify_chat_messages msg
                        ON msg.conv_id = ('office_' || ogr.office_id::text)
                       AND msg.status = 'active'
                       AND msg.sender_id != ogr.account_id
                       AND msg.id > COALESCE(ogr.last_read_msg_id, 0)
                    GROUP BY ogr.account_id, ogr.office_id
                )
                SELECT 
                    account_id,
                    COALESCE(SUM(unread_count), 0)::BIGINT AS total_unread
                FROM all_unreads
                GROUP BY account_id;
            ");
        } else {
            // Fallback for environments where GC tables have not been generated yet
            DB::statement("
                CREATE OR REPLACE VIEW view_user_unread_chats AS
                SELECT 
                    u.account_id,
                    COALESCE(SUM(CASE WHEN cc.user_1 = u.account_id THEN cc.unread_user_1 ELSE cc.unread_user_2 END), 0)::BIGINT AS total_unread
                FROM (
                    SELECT user_1 AS account_id FROM messify_chat_conversations WHERE is_active = true
                    UNION
                    SELECT user_2 AS account_id FROM messify_chat_conversations WHERE is_active = true
                ) u
                JOIN messify_chat_conversations cc 
                    ON (cc.user_1 = u.account_id OR cc.user_2 = u.account_id)
                   AND cc.is_active = true
                GROUP BY u.account_id;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messify_chat_conversations')) {
            DB::statement("
                CREATE OR REPLACE VIEW view_user_unread_chats AS
                SELECT 
                    u.account_id,
                    COALESCE(SUM(CASE WHEN cc.user_1 = u.account_id THEN cc.unread_user_1 ELSE cc.unread_user_2 END), 0)::BIGINT AS total_unread
                FROM (
                    SELECT user_1 AS account_id FROM messify_chat_conversations WHERE is_active = true
                    UNION
                    SELECT user_2 AS account_id FROM messify_chat_conversations WHERE is_active = true
                ) u
                JOIN messify_chat_conversations cc 
                    ON (cc.user_1 = u.account_id OR cc.user_2 = u.account_id)
                   AND cc.is_active = true
                GROUP BY u.account_id;
            ");
        }
    }
};

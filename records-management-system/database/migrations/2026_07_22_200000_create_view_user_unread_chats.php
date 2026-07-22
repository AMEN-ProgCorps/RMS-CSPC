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
                cm.receiver_id AS account_id,
                COUNT(*) AS total_unread
            FROM chat_messages cm
            LEFT JOIN chat_read_markers crm 
                ON crm.conv_id = cm.conv_id 
               AND crm.account_id = cm.receiver_id
            LEFT JOIN chat_messages last_read_msg 
                ON last_read_msg.msg_uuid = crm.last_msg_uuid
            WHERE cm.receiver_id IS NOT NULL
              AND cm.sender_id != cm.receiver_id
              AND (
                  crm.last_msg_uuid IS NULL 
                  OR cm.id > last_read_msg.id
              )
            GROUP BY cm.receiver_id;
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

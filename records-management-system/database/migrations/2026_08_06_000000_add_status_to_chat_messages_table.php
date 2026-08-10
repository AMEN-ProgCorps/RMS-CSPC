<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-message `status` column to chat_messages.
 * ============================================================
 * Before this, whether a message was "cleared" lived only in
 * chat_conversations (is_active + cleared_at cutoff timestamp), and
 * chat_messages itself had no indicator at all — every row always looked
 * the same whether the conversation had been cleared or not.
 *
 * Now each row is explicitly 'active' or 'inactive':
 *   - New messages are inserted as 'active' (see ConversationManager::insertMessage()).
 *   - /clear (deleteConversation()'s admin branch) flips every currently-active
 *     row in that conversation to 'inactive'. Rows are NEVER deleted by /clear —
 *     chat_messages stays the single source of truth.
 *   - /backup (backupAll()/backupGlobal()) only ever archives 'inactive' rows,
 *     and moves them out (copy into chatify_chat_backup, then delete from
 *     chat_messages) — so a conversation that hasn't been cleared is never
 *     touched by backup, and running /backup twice in a row with nothing new
 *     cleared in between finds zero rows to move.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_messages', 'status')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->string('status', 10)->default('active')->after('msg_type');
            });
        }

        // Backfill: anything already hidden by the old chat_conversations
        // cleared_at cutoff should start life as 'inactive' here too, so the
        // switch-over doesn't suddenly resurface old cleared messages.
        DB::statement("
            UPDATE chat_messages m
            SET status = 'inactive'
            FROM chat_conversations c
            WHERE c.conv_id = m.conv_id
              AND c.cleared_at IS NOT NULL
              AND m.created_at <= c.cleared_at
              AND m.status = 'active'
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS idx_chat_messages_status ON chat_messages (conv_id, status)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_chat_messages_status');
        if (Schema::hasColumn('chat_messages', 'status')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
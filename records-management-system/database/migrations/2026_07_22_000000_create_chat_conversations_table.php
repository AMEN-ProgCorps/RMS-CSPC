<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chatify Conversations Metadata Table Migration
     * ============================================================
     * Creates a high-performance 1-row-per-conversation table to store
     * recent conversation metadata (last message, last timestamp, unread counters).
     *
     * This decouples sidebar contact listing from scanning the millions of rows
     * in `chat_messages`, enabling sub-5ms query times at 20M+ message scale.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table) {
                // Canonical conversation ID: '{min_user_id}_{max_user_id}' (e.g., '2_5')
                $table->string('conv_id', 30)->primary();

                // Participant IDs (user_1 is always min(a,b), user_2 is always max(a,b))
                $table->unsignedInteger('user_1');
                $table->foreign('user_1', 'fk_cc_user1')
                      ->references('id')
                      ->on('account')
                      ->onDelete('cascade');

                $table->unsignedInteger('user_2');
                $table->foreign('user_2', 'fk_cc_user2')
                      ->references('id')
                      ->on('account')
                      ->onDelete('cascade');

                // Latest message metadata
                $table->text('last_message');
                $table->string('last_msg_type', 10)->default('text');
                $table->string('last_msg_uuid', 32)->nullable();
                $table->timestampTz('last_message_time', 6);

                // Unread counters for each participant
                $table->unsignedInteger('unread_user_1')->default(0);
                $table->unsignedInteger('unread_user_2')->default(0);

                // Soft-delete / "clear chat" support: messages are never removed
                // from chat_messages when a conversation is cleared — instead the
                // conversation is flagged inactive and stamped with a cutoff time
                // so the UI can hide everything up to that point.
                $table->boolean('is_active')->default(true);
                $table->timestampTz('cleared_at', 6)->nullable()->default(null);

                $table->timestampsTz(6);
            });

            // ── PostgreSQL Composite Indexes for Sidebar Lookup ──────────────────
            // User 1 sidebar query: WHERE user_1 = :id ORDER BY last_message_time DESC
            DB::statement('CREATE INDEX idx_chat_conv_user1 ON chat_conversations (user_1, last_message_time DESC)');

            // User 2 sidebar query: WHERE user_2 = :id ORDER BY last_message_time DESC
            DB::statement('CREATE INDEX idx_chat_conv_user2 ON chat_conversations (user_2, last_message_time DESC)');
        }

        // ── Zero-downtime Backfill from existing chat_messages ──────────────────
        // Populate chat_conversations using DISTINCT ON (conv_id) from current chat_messages
        DB::statement("
            INSERT INTO chat_conversations (
                conv_id,
                user_1,
                user_2,
                last_message,
                last_msg_type,
                last_msg_uuid,
                last_message_time,
                unread_user_1,
                unread_user_2,
                created_at,
                updated_at
            )
            SELECT DISTINCT ON (conv_id)
                conv_id,
                LEAST(sender_id, receiver_id) AS user_1,
                GREATEST(sender_id, receiver_id) AS user_2,
                message AS last_message,
                msg_type AS last_msg_type,
                msg_uuid AS last_msg_uuid,
                created_at AS last_message_time,
                0 AS unread_user_1,
                0 AS unread_user_2,
                created_at AS created_at,
                created_at AS updated_at
            FROM chat_messages
            WHERE conv_id != 'global'
              AND receiver_id IS NOT NULL
            ORDER BY conv_id, created_at DESC, id DESC
            ON CONFLICT (conv_id) DO NOTHING
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chatify PostgreSQL Migration
 * ============================================================
 * Moves all Chatify message/reaction/read-marker storage from
 * flat JSON files on the filesystem into the existing PostgreSQL
 * database used by the RMS application.
 *
 * Tables created:
 *   chat_messages      — global + private messages (unified)
 *   chat_reactions     — per-message emoji reactions
 *   chat_read_markers  — per-user read position per conversation
 *
 * Existing table untouched:
 *   chat_notifications — already in PostgreSQL, no changes needed
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── chat_messages ────────────────────────────────────────────────────
        // Stores both global messages (conv_id = 'global') and private DMs
        // (conv_id = '{min_id}_{max_id}', e.g. '2_5').
        // Message content remains AES-256-GCM encrypted (same as the old JSON).
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Conversation bucket: 'global' | '{min}_{max}'
            $table->string('conv_id', 30)->index();

            // Sender (always set)
            $table->unsignedInteger('sender_id');
            $table->foreign('sender_id', 'fk_cm_sender')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');

            // Receiver: NULL for global messages, set for private DMs
            $table->unsignedInteger('receiver_id')->nullable();
            $table->foreign('receiver_id', 'fk_cm_receiver')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');

            // Encrypted ciphertext (AES-256-GCM, same as before)
            $table->text('message');

            // 'text' or 'upload'
            $table->string('msg_type', 10)->default('text');

            // Microsecond-resolution timestamp for ordering
            // (matches existing 'Y-m-d H:i:s.u' format stored in old JSON)
            $table->timestampsTz(6);  // created_at + updated_at with microseconds

            // Stable UUID used by reactions and read markers.
            // Format: 'msg_' + 16 hex chars (matches old bin2hex(random_bytes(8))).
            $table->string('msg_uuid', 32)->unique();
        });

        // Composite index: primary access pattern for loading a conversation page
        // SELECT ... WHERE conv_id = ? ORDER BY created_at ASC LIMIT 100
        DB::statement('CREATE INDEX idx_chat_conv_sent ON chat_messages (conv_id, created_at ASC)');

        // Supporting indexes for delete and notification queries
        DB::statement('CREATE INDEX idx_chat_sender   ON chat_messages (sender_id)');
        DB::statement('CREATE INDEX idx_chat_receiver ON chat_messages (receiver_id) WHERE receiver_id IS NOT NULL');

        // ── chat_reactions ───────────────────────────────────────────────────
        // One row per (message, user) — a user can only have one active emoji
        // per message. Toggle logic: same emoji = remove, different = replace.
        Schema::create('chat_reactions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('msg_uuid', 32)->index();
            $table->foreign('msg_uuid', 'fk_cr_message')
                  ->references('msg_uuid')
                  ->on('chat_messages')
                  ->onDelete('cascade');

            // The reacting user
            $table->unsignedInteger('account_id');
            $table->foreign('account_id', 'fk_cr_account')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');

            $table->string('emoji', 8);

            // One reaction per user per message
            $table->unique(['msg_uuid', 'account_id'], 'uq_reaction_user_msg');

            $table->timestampTz('reacted_at')->useCurrent();
        });

        // ── chat_read_markers ────────────────────────────────────────────────
        // Tracks the last message each user has read in each conversation.
        // UPSERT pattern: INSERT … ON CONFLICT (conv_id, account_id) DO UPDATE.
        Schema::create('chat_read_markers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('conv_id', 30);

            $table->unsignedInteger('account_id');
            $table->foreign('account_id', 'fk_crm_account')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');

            // UUID of the last message seen by this user in this conversation
            $table->string('last_msg_uuid', 32)->nullable();

            $table->timestampTz('updated_at')->useCurrent();

            // One marker per (conversation, user)
            $table->unique(['conv_id', 'account_id'], 'uq_read_marker');
        });

        DB::statement('CREATE INDEX idx_read_marker_conv_acct ON chat_read_markers (conv_id, account_id)');
    }

    public function down(): void
    {
        // Drop in dependency order
        Schema::table('chat_reactions', function (Blueprint $table) {
            $table->dropForeign('fk_cr_message');
            $table->dropForeign('fk_cr_account');
        });
        Schema::dropIfExists('chat_reactions');

        Schema::table('chat_read_markers', function (Blueprint $table) {
            $table->dropForeign('fk_crm_account');
        });
        Schema::dropIfExists('chat_read_markers');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign('fk_cm_sender');
            $table->dropForeign('fk_cm_receiver');
        });
        Schema::dropIfExists('chat_messages');
    }
};

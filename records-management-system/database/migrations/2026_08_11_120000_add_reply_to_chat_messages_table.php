<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds real "reply to a message" support to messify_chat_messages.
 * ============================================================
 * Before this, "reply" was only a UI trick: openReplyForContainer() in
 * app-part3.js grabbed a text snippet and glued it onto the front of the
 * outgoing message ("Replying to: \"...\"\n<message>") before sending. It was
 * never stored as a real relationship, so there was nothing durable to
 * render — every reply was just plain text baked into the message body,
 * with the original sender's name never attached to it.
 *
 * This migration adds one column, `reply_to_msg_uuid`, that points at the
 * `msg_uuid` of the message being replied to. Both Global Chat and DMs share
 * this same `messify_chat_messages` table (bucketed by conv_id), so one column
 * covers both.
 *
 * Design notes:
 *   - Nullable: most messages are not replies.
 *   - FK -> messify_chat_messages.msg_uuid, ON DELETE SET NULL: if the original
 *     message is later moved out of messify_chat_messages entirely (see
 *     /backup — backupAll()/backupGlobal() in messify_chat_backup's
 *     migration, which DELETEs archived rows), the reply row is NOT
 *     deleted or orphaned with a dangling reference — it just quietly loses
 *     its quoted preview and renders as a normal message going forward.
 *   - No separate "reply snippet" / "reply sender" columns on purpose:
 *     the app already looks up the parent row by msg_uuid at read time
 *     (same pattern as messify_chat_reactions/messify_chat_read_markers), decrypts its
 *     `message` for the quoted preview, and — per product requirement —
 *     never displays the original sender's name, only the quoted bubble.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messify_chat_messages', 'reply_to_msg_uuid')) {
            Schema::table('messify_chat_messages', function (Blueprint $table) {
                $table->string('reply_to_msg_uuid', 32)
                      ->nullable()
                      ->after('msg_uuid');
            });

            Schema::table('messify_chat_messages', function (Blueprint $table) {
                $table->foreign('reply_to_msg_uuid', 'fk_cm_reply_to')
                      ->references('msg_uuid')
                      ->on('messify_chat_messages')
                      ->onDelete('set null');
            });
        }

        // Lets "load the parent message for this reply" queries use an
        // index instead of a sequential scan once reply volume grows.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_chat_messages_reply_to ON messify_chat_messages (reply_to_msg_uuid)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_chat_messages_reply_to');

        if (Schema::hasColumn('messify_chat_messages', 'reply_to_msg_uuid')) {
            Schema::table('messify_chat_messages', function (Blueprint $table) {
                $table->dropForeign('fk_cm_reply_to');
                $table->dropColumn('reply_to_msg_uuid');
            });
        }
    }
};
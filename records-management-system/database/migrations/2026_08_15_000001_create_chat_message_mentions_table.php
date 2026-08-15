<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates chat_message_mentions.
 * ============================================================
 * Before this, an @mention picked in the compose box (mention_search.php /
 * selectMentionUser() in app-part1.js) lived only in the browser's
 * `activeMentions` array for as long as it took to fire notify.php — once
 * the message was sent, nothing tied "this message mentioned that user"
 * back to the row in chat_messages. This table makes that relationship
 * durable, the same way chat_reactions/chat_read_markers do for their
 * own per-message, per-user facts.
 *
 * One row per (message, mentioned user). Written by
 * GlobalChatManager::insertMessage() right after the message itself is
 * saved (see send.php's mentioned_ids param), which is also what now
 * drives the notification (see core/ChatNotifier.php) instead of the
 * client firing notify.php itself post-send.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_message_mentions')) {
            Schema::create('chat_message_mentions', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->string('msg_uuid', 32)->nullable();
                $table->foreign('msg_uuid', 'fk_cmm_message')
                      ->references('msg_uuid')
                      ->on('chat_messages')
                      ->onDelete('cascade');

                $table->unsignedInteger('sender_account_id')->nullable();
                $table->foreign('sender_account_id', 'fk_cmm_sender')
                      ->references('id')
                      ->on('account')
                      ->onDelete('cascade');

                $table->unsignedInteger('mentioned_account_id');
                $table->foreign('mentioned_account_id', 'fk_cmm_account')
                      ->references('id')
                      ->on('account')
                      ->onDelete('cascade');

                $table->text('message_snippet')->nullable();
                $table->boolean('is_seen')->default(false);

                $table->timestampTz('created_at')->useCurrent();
            });
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_chat_message_mentions_account_unseen
             ON chat_message_mentions (mentioned_account_id, is_seen, created_at DESC)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_chat_message_mentions_account');

        if (Schema::hasTable('chat_message_mentions')) {
            Schema::table('chat_message_mentions', function (Blueprint $table) {
                $table->dropForeign('fk_cmm_message');
                $table->dropForeign('fk_cmm_account');
            });
            Schema::dropIfExists('chat_message_mentions');
        }
    }
};

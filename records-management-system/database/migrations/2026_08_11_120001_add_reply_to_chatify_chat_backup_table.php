<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors reply_to_msg_uuid onto chatify_chat_backup so that a reply's
 * "what was this replying to" fact isn't lost the moment a conversation
 * is cleared and backed up.
 *
 * No foreign key here — same reasoning as the rest of this table
 * (see 2026_07_16_000000_create_chatify_chat_backup_table.php): the backup
 * table must survive even after the referenced messages/users are gone,
 * so this is a plain unconstrained column, purely for the admin audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chatify_chat_backup', 'reply_to_msg_uuid')) {
            Schema::table('chatify_chat_backup', function (Blueprint $table) {
                $table->string('reply_to_msg_uuid', 32)
                      ->nullable()
                      ->after('msg_uuid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatify_chat_backup', 'reply_to_msg_uuid')) {
            Schema::table('chatify_chat_backup', function (Blueprint $table) {
                $table->dropColumn('reply_to_msg_uuid');
            });
        }
    }
};
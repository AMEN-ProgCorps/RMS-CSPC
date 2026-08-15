<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration cleanup: chat_notifications table is obsolete.
 * Notifications now read directly from chat_message_mentions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_chat_notifications_recipient_unseen');
        if (Schema::hasTable('chat_notifications')) {
            Schema::dropIfExists('chat_notifications');
        }
    }

    public function down(): void
    {
    }
};

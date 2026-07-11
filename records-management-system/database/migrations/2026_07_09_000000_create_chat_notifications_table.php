<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create chat_notifications table
        Schema::create('chat_notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('sender_account_id');
            $table->unsignedInteger('recipient_account_id');
            $table->string('message', 250)->nullable();
            $table->boolean('is_seen')->default(0);
            $table->dateTime('created_at');
            
            // Index
            $table->index(['recipient_account_id', 'is_seen'], 'idx_recipient_seen');
            
            // Foreign keys
            $table->foreign('sender_account_id', 'fk_chat_notif_sender')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');
                  
            $table->foreign('recipient_account_id', 'fk_chat_notif_recipient')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');
        });

        // Register Chatify subsystem
        DB::statement("
            INSERT INTO subsystems (subsystem_name, subsystem_version, is_active, created_at, update_at)
            SELECT 'Chatify', '1.0.0', 1, NOW(), NOW()
            WHERE NOT EXISTS (SELECT 1 FROM subsystems WHERE subsystem_name = 'Chatify')
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_notifications', function (Blueprint $table) {
            $table->dropForeign('fk_chat_notif_sender');
            $table->dropForeign('fk_chat_notif_recipient');
        });

        Schema::dropIfExists('chat_notifications');

        DB::statement("
            DELETE FROM subsystems WHERE subsystem_name = 'Chatify'
        ");
    }
};
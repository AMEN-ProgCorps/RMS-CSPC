<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chatify Legal Agreements Migration
 * ============================================================
 * Tracks which accounts have accepted the Chatify legal/usage
 * authorization. One record per account (upsert on re-accept).
 *
 * Table: chatify_legal_agreements
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatify_legal_agreements', function (Blueprint $table) {
            $table->increments('id');

            // The user who accepted
            $table->unsignedInteger('account_id')->unique();
            $table->foreign('account_id', 'fk_cla_account')
                  ->references('id')
                  ->on('account')
                  ->onDelete('cascade');

            // When they agreed
            $table->timestampTz('agreed_at')->useCurrent();

            // IP and user-agent for audit purposes
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatify_legal_agreements', function (Blueprint $table) {
            $table->dropForeign('fk_cla_account');
        });
        Schema::dropIfExists('chatify_legal_agreements');
    }
};

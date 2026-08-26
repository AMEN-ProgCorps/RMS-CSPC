<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DCS Activity Logs
 *
 * Logged actions (initial set):
 *   register_document  — new document registered
 *   update_document    — document registration updated
 *   delete_document    — soft-deleted to Recycle Bin
 *   restore_document   — restored from Recycle Bin
 *   apply_stamp        — stamp applied to scanned copy
 *   remove_stamp       — stamp removed / original restored
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_activity_logs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedInteger('account_id');
            $table->foreign('account_id', 'fk_dal_account')
                ->references('id')
                ->on('account')
                ->onDelete('cascade');

            $table->string('action', 40)->index();
            $table->string('target_id', 100)->nullable();
            $table->jsonb('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
        });

        DB::statement('CREATE INDEX idx_dal_account_created ON dcs_activity_logs (account_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::table('dcs_activity_logs', function (Blueprint $table) {
            $table->dropForeign('fk_dal_account');
        });
        Schema::dropIfExists('dcs_activity_logs');
    }
};

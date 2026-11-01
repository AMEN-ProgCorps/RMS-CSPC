<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field-level edit history for DCS registrations (who / when / old → new).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_document_edit_history')) {
            return;
        }

        Schema::create('dcs_document_edit_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action', 32)->default('update');
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_edit_history');
    }
};

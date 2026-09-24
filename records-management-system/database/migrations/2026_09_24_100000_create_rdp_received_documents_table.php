<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rdp_received_documents')) {
            Schema::create('rdp_received_documents', function (Blueprint $table) {
                $table->id();
                $table->string('source_subsystem')->index();
                $table->string('document_code')->index();
                $table->string('document_title');
                $table->text('description')->nullable();
                $table->string('origin_office')->nullable();
                $table->string('target_office')->nullable();
                $table->date('date_received')->nullable();
                $table->string('file_path')->nullable();
                $table->string('file_name')->nullable();
                $table->string('document_id_handler')->nullable();
                $table->string('status')->default('pending')->index();
                $table->unsignedBigInteger('appraised_record_id')->nullable();
                $table->unsignedBigInteger('sent_by_user')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('appraised_record_id')->references('id')->on('rdp_record')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rdp_received_documents');
    }
};

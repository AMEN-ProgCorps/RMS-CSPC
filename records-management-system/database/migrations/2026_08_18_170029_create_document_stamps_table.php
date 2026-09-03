<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_document_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')
                ->constrained('dcs_document_requests')
                ->cascadeOnDelete();
            $table->string('file_key', 50);
            $table->string('file_path', 500);
            $table->string('stamp_type', 50);
            $table->string('position', 30)->default('auto');
            $table->boolean('all_pages')->default(true);
            $table->unsignedInteger('stamped_by');
            $table->foreign('stamped_by')->references('id')->on('account');
            $table->timestamp('stamped_at');
            $table->timestamps();

            $table->unique(['document_request_id', 'file_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_stamps');
    }
};

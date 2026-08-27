<?php
// 008 — document_requests

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')
                  ->constrained('dcs_version_type');
            $table->foreignId('doc_type_id')
                  ->constrained('dcs_doc_types');
            $table->foreignId('sub_type_id')->nullable()
                  ->constrained('dcs_doc_types');
            $table->enum('approval_status', ['applicable', 'not_applicable'])->nullable();
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('account');
            $table->foreign('updated_by')->references('id')->on('account');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();
            $table->foreign('deleted_by')->references('id')->on('account')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_requests');
    }
};
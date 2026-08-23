<?php
// 012 — document_change_notice

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_document_change_notice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->nullable()
                  ->constrained('dcs_checklist_types');
            $table->foreignId('version_id')->nullable()
                  ->constrained('dcs_version_type');
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->foreignId('doc_type_id')->nullable()
                  ->constrained('dcs_doc_types');
            $table->string('dcn_no', 100)->nullable();
            $table->date('dcn_date')->nullable();
            $table->date('dcn_receipt_date')->nullable();
            $table->time('dcn_receipt_time')->nullable();
            $table->unsignedInteger('office_id')->nullable();
            $table->string('scanned_dcn')->nullable();
            $table->text('brief_purpose')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('office_id')->references('id')->on('office')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_change_notice');
    }
};
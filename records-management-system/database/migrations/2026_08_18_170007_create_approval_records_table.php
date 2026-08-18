<?php
// 009 — approval_records

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_approval_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->nullable()
                  ->constrained('dcs_checklist_types');
            $table->foreignId('version_id')->nullable()
                  ->constrained('dcs_version_type');
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->foreignId('doc_type_id')->nullable()
                  ->constrained('dcs_doc_types');
            $table->foreignId('approval_body_id')->nullable()
                  ->constrained('dcs_approval_body');
            $table->date('approval_date')->nullable();
            $table->string('approval_no', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_approval_records');
    }
};

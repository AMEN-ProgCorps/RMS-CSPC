<?php
// 014 — document_distribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_document_distribution', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->nullable()
                  ->constrained('dcs_checklist_types');
            $table->foreignId('version_id')->nullable()
                  ->constrained('dcs_version_type');
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->foreignId('doc_type_id')->nullable()
                  ->constrained('dcs_doc_types');
            $table->date('doc_distribution_date_actual')->nullable();
            $table->time('doc_distribution_time_actual')->nullable();
            $table->date('doc_distribution_date_file')->nullable();
            $table->time('doc_distribution_time_file')->nullable();
            $table->integer('time_spent')->nullable();
            $table->text('remarks')->nullable();
            $table->string('scanned_distribution')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_distribution');
    }
};
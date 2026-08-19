<?php
// 018 — masterlist_registration

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_masterlist_registration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->nullable()
                  ->constrained('dcs_checklist_types');
            $table->foreignId('version_id')->nullable()
                  ->constrained('dcs_version_type');
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->foreignId('doc_type_id')->nullable()
                  ->constrained('dcs_doc_types');
            $table->string('doc_no', 100)->nullable();
            $table->date('doc_receipt_date')->nullable();
            $table->time('doc_receipt_time')->nullable();
            $table->date('doc_registered_date')->nullable();
            $table->time('doc_registered_time')->nullable();
            $table->integer('time_spent')->nullable();
            $table->string('doc_title')->nullable();
            $table->date('effectivity_date')->nullable();
            $table->integer('revise_no')->nullable();
            $table->integer('no_pages')->nullable();
            $table->string('originator_name')->nullable();
            $table->date('deadline')->nullable();
            $table->text('brief_purpose')->nullable();
            $table->string('scanned_masterlist')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_masterlist_registration');
    }
};

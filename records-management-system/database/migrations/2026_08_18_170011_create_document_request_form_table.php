<?php
// 010 — document_request_form

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_document_request_form', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->string('drf_no', 100)->nullable();
            $table->date('drf_date')->nullable();
            $table->date('drf_receipt_date')->nullable();
            $table->time('drf_receipt_time')->nullable();
            $table->string('doc_title')->nullable();
            $table->string('scanned_drf')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_request_form');
    }
};

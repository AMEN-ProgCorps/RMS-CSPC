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
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->string('dcn_no', 100)->nullable();
            $table->date('dcn_date')->nullable();
            $table->date('dcn_receipt_date')->nullable();
            $table->time('dcn_receipt_time')->nullable();
            $table->string('scanned_dcn')->nullable();
            $table->text('brief_purpose')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_document_change_notice');
    }
};

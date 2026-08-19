<?php
// 011 — drf_offices

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_drf_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_form_id')
                  ->constrained('dcs_document_request_form')
                  ->cascadeOnDelete();
            $table->unsignedInteger('office_id');
            $table->foreign('office_id')->references('id')->on('office')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_drf_offices');
    }
};
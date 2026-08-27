<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_report_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('pdf_path');
            $table->string('preview_path')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_report_templates');
    }
};

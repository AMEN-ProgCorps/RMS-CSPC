<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_generated_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_token', 32)->unique();
            $table->string('category', 64);
            $table->string('sub_category', 64)->nullable();
            $table->string('title');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('format', 10)->default('pdf');
            $table->unsignedInteger('row_count')->default(0);
            $table->string('office_code', 64)->nullable();
            $table->json('filters')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('period', 32)->nullable();
            $table->unsignedInteger('generated_by');
            $table->foreign('generated_by')->references('id')->on('account');
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index('office_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_generated_reports');
    }
};

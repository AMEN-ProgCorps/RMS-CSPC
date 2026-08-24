<?php
// 015 — distribution_offices

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_distribution_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')
                  ->constrained('dcs_document_distribution')
                  ->cascadeOnDelete();
            $table->unsignedInteger('office_id');
            $table->foreign('office_id')->references('id')->on('office');
            $table->integer('copies')->nullable();
            $table->date('distribution_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_distribution_offices');
    }
};

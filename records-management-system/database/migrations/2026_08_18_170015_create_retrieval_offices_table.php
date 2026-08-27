<?php
// 017 — retrieval_offices

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_retrieval_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retrieval_id')
                  ->constrained('dcs_document_retrieval')
                  ->cascadeOnDelete();
            $table->unsignedInteger('office_id');
            $table->foreign('office_id')->references('id')->on('office');
            $table->integer('copies')->nullable();
            $table->string('retrieval_status', 20)->default('pending');
            $table->date('retrieval_date')->nullable();

            $table->unique(['retrieval_id', 'office_id'], 'dcs_ret_offices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_retrieval_offices');
    }
};
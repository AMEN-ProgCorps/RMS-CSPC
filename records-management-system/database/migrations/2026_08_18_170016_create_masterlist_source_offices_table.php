<?php
// 020 — masterlist_source_offices

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_masterlist_source_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masterlist_id')
                  ->constrained('dcs_masterlist_registration')
                  ->cascadeOnDelete();
            $table->unsignedInteger('office_id')->nullable();
            $table->foreign('office_id')->references('id')->on('office')->nullOnDelete();
            $table->timestamps();
            $table->index('masterlist_id');
            $table->unique(['masterlist_id', 'office_id'], 'dcs_ml_source_offices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_masterlist_source_offices');
    }
};
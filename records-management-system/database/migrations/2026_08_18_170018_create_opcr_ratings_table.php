<?php
// 021 — opcr_ratings (already used standard id — no changes)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_opcr_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')
                ->constrained('dcs_document_requests')
                ->cascadeOnDelete();
            $table->string('sub_type');
            $table->decimal('rating_q', 5, 2)->nullable();
            $table->decimal('rating_e', 5, 2)->nullable();
            $table->decimal('rating_t', 5, 2)->nullable();
            $table->decimal('rating_a', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'sub_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_opcr_ratings');
    }
};
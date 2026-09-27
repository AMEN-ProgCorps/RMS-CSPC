<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_doc_no_shifts')) {
            return;
        }

        Schema::create('dcs_doc_no_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('series_key', 150);
            $table->string('inserted_doc_no', 100);
            $table->json('mappings');
            $table->boolean('rename_letters')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_doc_no_shifts');
    }
};

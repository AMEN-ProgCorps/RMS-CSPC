<?php
// 019 — masterlist_related_docs (renamed from 'related_documents' to match the model)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_masterlist_related_docs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masterlist_id')
                  ->constrained('dcs_masterlist_registration')
                  ->cascadeOnDelete();
            $table->foreignId('related_doc_id')
                  ->constrained('dcs_masterlist_registration')
                  ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['masterlist_id', 'related_doc_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_masterlist_related_docs');
    }
};
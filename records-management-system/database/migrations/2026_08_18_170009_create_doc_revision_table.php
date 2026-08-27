<?php
// 013 — doc_revision

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_doc_revision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dcn_id')->nullable()
                  ->constrained('dcs_document_change_notice')
                  ->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('document_no', 100)->nullable();
            $table->date('effectivity_date')->nullable();
            $table->integer('revision_no')->nullable();
            $table->string('scanned_copy')->nullable();
            $table->text('brief_purpose')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_doc_revision');
    }
};
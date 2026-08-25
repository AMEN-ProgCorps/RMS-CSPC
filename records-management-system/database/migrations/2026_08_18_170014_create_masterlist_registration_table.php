<?php
// 018 — masterlist_registration

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dcs_masterlist_registration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()
                  ->constrained('dcs_document_requests');
            $table->foreignId('doc_type_id')->nullable()
                  ->constrained('dcs_doc_types');
            $table->string('doc_no', 100)->nullable();
            $table->string('revised_from_doc_no', 100)->nullable();
            $table->date('doc_receipt_date')->nullable();
            $table->time('doc_receipt_time')->nullable();
            $table->date('doc_registered_date')->nullable();
            $table->time('doc_registered_time')->nullable();
            $table->integer('time_spent')->nullable();
            $table->string('doc_title')->nullable();
            $table->date('effectivity_date')->nullable();
            $table->integer('revise_no')->default(0);
            // latest = current tip, obsolete = prior revision, archived = soft-deleted/archived request
            $table->enum('revision_status', ['latest', 'obsolete', 'archived'])->default('latest');
            $table->integer('no_pages')->nullable();
            $table->string('originator_name')->nullable();
            $table->date('deadline')->nullable();
            $table->text('brief_purpose')->nullable();
            $table->text('keywords')->nullable();
            $table->string('scanned_masterlist')->nullable();
            $table->unsignedInteger('created_by');
            $table->foreign('created_by')->references('id')->on('account');
            $table->timestamps();

            $table->index(['doc_no', 'revision_status'], 'dcs_ml_doc_no_revision_status_idx');
        });

        // Unique only among active (non-archived) rows so an archived doc_no can be reused.
        // On restore, app blocks if the same active key already exists.
        DB::statement("
            CREATE UNIQUE INDEX dcs_ml_doc_no_revise_type_active_unique
            ON dcs_masterlist_registration (doc_no, revise_no, doc_type_id)
            WHERE revision_status IN ('latest', 'obsolete')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_masterlist_registration');
    }
};

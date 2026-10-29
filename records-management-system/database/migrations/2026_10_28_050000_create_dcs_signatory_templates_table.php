<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dcs_signatory_templates')) {
            Schema::table('dcs_signatory_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('dcs_signatory_templates', 'doc_type_id')) {
                    $table->unsignedBigInteger('doc_type_id')->nullable()->after('doc_type_kind')->index();
                }
                if (! Schema::hasColumn('dcs_signatory_templates', 'effective_from')) {
                    $table->date('effective_from')->nullable()->after('doc_type_id');
                }
                if (! Schema::hasColumn('dcs_signatory_templates', 'effective_until')) {
                    $table->date('effective_until')->nullable()->after('effective_from');
                }
            });

            try {
                Schema::table('dcs_signatory_templates', function (Blueprint $table) {
                    $table->dropUnique('dcs_sig_templates_form_kind_unique');
                });
            } catch (\Throwable) {
            }

            return;
        }

        Schema::create('dcs_signatory_templates', function (Blueprint $table) {
            $table->id();
            $table->string('form_kind', 16); // drf | dcn
            $table->string('doc_type_kind', 16)->nullable(); // internal | external fallback
            $table->unsignedBigInteger('doc_type_id')->nullable()->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->json('payload');
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dcs_signatory_templates');
    }
};

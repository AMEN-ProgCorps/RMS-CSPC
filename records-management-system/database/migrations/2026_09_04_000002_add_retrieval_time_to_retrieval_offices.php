<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-office retrieval date already exists; add time so a Retrieved office
 * can record when that office submitted the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_retrieval_offices')) {
            return;
        }

        Schema::table('dcs_retrieval_offices', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date')) {
                $table->date('retrieval_date')->nullable();
            }
            if (! Schema::hasColumn('dcs_retrieval_offices', 'retrieval_time')) {
                $table->time('retrieval_time')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_retrieval_offices')
            || ! Schema::hasColumn('dcs_retrieval_offices', 'retrieval_time')) {
            return;
        }

        Schema::table('dcs_retrieval_offices', function (Blueprint $table) {
            $table->dropColumn('retrieval_time');
        });
    }
};

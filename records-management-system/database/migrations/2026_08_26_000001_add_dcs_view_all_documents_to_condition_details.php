<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror DTS/RDP “view all offices” clearance for DCS.
 * Without this flag, DCS lists are limited to the user’s office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            if (!Schema::hasColumn('condition_details', 'dcs_view_all_documents')) {
                $table->boolean('dcs_view_all_documents')->default(false)->after('can_access_dcs');
            }
        });

        DB::table('condition_details')
            ->where('is_sadm', true)
            ->update(['dcs_view_all_documents' => true]);
    }

    public function down(): void
    {
        Schema::table('condition_details', function (Blueprint $table) {
            if (Schema::hasColumn('condition_details', 'dcs_view_all_documents')) {
                $table->dropColumn('dcs_view_all_documents');
            }
        });
    }
};

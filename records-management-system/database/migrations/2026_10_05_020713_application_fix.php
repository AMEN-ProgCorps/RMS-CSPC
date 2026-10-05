<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        Schema::table('dts_transaction_details', function (Blueprint $table) use ($officeTable) {
            if (!Schema::hasColumn('dts_transaction_details', 'apply_to_office')) {
                $table->string('apply_to_office')->nullable()->after('originated_from');
                $table->foreign('apply_to_office')->references('office_code')->on($officeTable)->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            if (Schema::hasColumn('dts_transaction_details', 'apply_to_office')) {
                $table->dropForeign(['apply_to_office']);
                $table->dropColumn('apply_to_office');
            }
        });
    }
};
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
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            $columnsToDrop = [];
            
            if (Schema::hasColumn('dts_transaction_details', 'requestor_name')) {
                $columnsToDrop[] = 'requestor_name';
            }
            if (Schema::hasColumn('dts_transaction_details', 'requestor_label')) {
                $columnsToDrop[] = 'requestor_label';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dts_transaction_details', function (Blueprint $table) {
            if (!Schema::hasColumn('dts_transaction_details', 'requestor_name')) {
                $table->string('requestor_name')->nullable()->after('originated_from');
            }
            if (!Schema::hasColumn('dts_transaction_details', 'requestor_label')) {
                $table->text('requestor_label')->nullable()->after('requestor_name');
            }
        });
    }
};

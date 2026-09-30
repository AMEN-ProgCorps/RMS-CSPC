<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * flow_name was accidentally created as UNIQUE on dts_transaction_flow, so
     * creating a second custom flow that reuses an existing name failed with:
     * SQLSTATE[23505] duplicate key value violates unique constraint
     * "dts_transaction_flow_flow_name_unique".
     *
     * Flows are still uniquely identified by flow_code and id.
     */
    public function up(): void
    {
        if (! Schema::hasTable('dts_transaction_flow')) {
            return;
        }

        if (! Schema::hasIndex('dts_transaction_flow', 'dts_transaction_flow_flow_name_unique')) {
            return;
        }

        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->dropUnique('dts_transaction_flow_flow_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: will fail if duplicate flow_name rows exist at that time.
     */
    public function down(): void
    {
        if (! Schema::hasTable('dts_transaction_flow')) {
            return;
        }

        if (Schema::hasIndex('dts_transaction_flow', 'dts_transaction_flow_flow_name_unique')) {
            return;
        }

        Schema::table('dts_transaction_flow', function (Blueprint $table) {
            $table->unique('flow_name');
        });
    }
};

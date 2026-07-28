<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('dts_transaction_flow')) {
            if (!Schema::hasColumn('dts_transaction_flow', 'referenced_flow')) {
                Schema::table('dts_transaction_flow', function (Blueprint $table) {
                    $table->string('referenced_flow', 255)->nullable()->after('flow_for');
                });
            }

            // Backfill existing system flow records where flow_name starts with "Flow for "
            $systemFlows = DB::table('dts_transaction_flow')
                ->where('flow_name', 'like', 'Flow for %')
                ->get();

            foreach ($systemFlows as $flow) {
                // Find if there is a matching user flow or fallback to Test Run / original flow name
                $userFlow = DB::table('dts_transaction_flow')
                    ->where('added_by', $flow->added_by)
                    ->where('flow_use', $flow->flow_use)
                    ->where('flow_name', 'not like', 'Flow for %')
                    ->orderBy('id', 'desc')
                    ->first();

                $referencedName = $userFlow ? $userFlow->flow_name : 'Test Run';

                DB::table('dts_transaction_flow')
                    ->where('id', $flow->id)
                    ->update(['referenced_flow' => $referencedName]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('dts_transaction_flow')) {
            if (Schema::hasColumn('dts_transaction_flow', 'referenced_flow')) {
                Schema::table('dts_transaction_flow', function (Blueprint $table) {
                    $table->dropColumn('referenced_flow');
                });
            }
        }
    }
};

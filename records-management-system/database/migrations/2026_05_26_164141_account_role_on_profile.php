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
        // Only modify `account` if it exists
        if (Schema::hasTable('account')) {
            Schema::table('account', function (Blueprint $table) {
                // add `account_role` column only if missing
                if (! Schema::hasColumn('account', 'account_role')) {
                    $table->unsignedInteger('account_role')->nullable();

                    // Add foreign key to `condition_key` table only if that table exists
                    if (Schema::hasTable('condition_key')) {
                        $table->foreign('account_role')
                              ->references('id')
                              ->on('condition_key')
                              ->nullOnDelete();
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('account')) {
            Schema::table('account', function (Blueprint $table) {
                if (Schema::hasColumn('account', 'account_role')) {
                    try {
                        $table->dropForeign(['account_role']);
                    } catch (\Exception $e) {
                        // ignore if foreign key does not exist or has different name
                    }

                    $table->dropColumn('account_role');
                }
            });
        }
    }
};

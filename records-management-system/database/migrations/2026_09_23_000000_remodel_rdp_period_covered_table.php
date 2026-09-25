<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('rdp_period_covered')) {
            // 1. Add date_covered if not already present
            if (!Schema::hasColumn('rdp_period_covered', 'date_covered')) {
                Schema::table('rdp_period_covered', function (Blueprint $table) {
                    $table->string('date_covered')->nullable()->after('period_owner');
                });
            }

            // 2. Migrate existing start_at / ends_at data if columns exist
            if (Schema::hasColumn('rdp_period_covered', 'start_at') || Schema::hasColumn('rdp_period_covered', 'ends_at')) {
                DB::statement("
                    UPDATE rdp_period_covered 
                    SET date_covered = CASE 
                        WHEN start_at IS NOT NULL AND ends_at IS NOT NULL THEN TO_CHAR(start_at, 'YYYY-MM-DD') || ' - ' || TO_CHAR(ends_at, 'YYYY-MM-DD')
                        WHEN start_at IS NOT NULL THEN TO_CHAR(start_at, 'YYYY-MM-DD')
                        WHEN ends_at IS NOT NULL THEN TO_CHAR(ends_at, 'YYYY-MM-DD')
                        ELSE date_covered
                    END
                    WHERE date_covered IS NULL
                ");

                // Drop start_at and ends_at
                Schema::table('rdp_period_covered', function (Blueprint $table) {
                    $colsToDrop = [];
                    if (Schema::hasColumn('rdp_period_covered', 'start_at')) {
                        $colsToDrop[] = 'start_at';
                    }
                    if (Schema::hasColumn('rdp_period_covered', 'ends_at')) {
                        $colsToDrop[] = 'ends_at';
                    }
                    if (!empty($colsToDrop)) {
                        $table->dropColumn($colsToDrop);
                    }
                });
            }

            // 3. Rename updated_at to modified_at, or add modified_at
            if (Schema::hasColumn('rdp_period_covered', 'updated_at') && !Schema::hasColumn('rdp_period_covered', 'modified_at')) {
                Schema::table('rdp_period_covered', function (Blueprint $table) {
                    $table->renameColumn('updated_at', 'modified_at');
                });
            } elseif (!Schema::hasColumn('rdp_period_covered', 'modified_at')) {
                Schema::table('rdp_period_covered', function (Blueprint $table) {
                    $table->timestamp('modified_at')->nullable();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_period_covered')) {
            Schema::table('rdp_period_covered', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_period_covered', 'start_at')) {
                    $table->timestamp('start_at')->nullable();
                }
                if (!Schema::hasColumn('rdp_period_covered', 'ends_at')) {
                    $table->timestamp('ends_at')->nullable();
                }
                if (Schema::hasColumn('rdp_period_covered', 'modified_at') && !Schema::hasColumn('rdp_period_covered', 'updated_at')) {
                    $table->renameColumn('modified_at', 'updated_at');
                }
                if (Schema::hasColumn('rdp_period_covered', 'date_covered')) {
                    $table->dropColumn('date_covered');
                }
            });
        }
    }
};

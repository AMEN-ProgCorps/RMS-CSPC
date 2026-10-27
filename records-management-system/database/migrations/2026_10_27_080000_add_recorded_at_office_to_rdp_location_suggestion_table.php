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
        if (Schema::hasTable('rdp_location_suggestion')) {
            // 1. Make suggestion_from nullable so office-level locations can be saved without requiring a specific series
            try {
                DB::statement('ALTER TABLE rdp_location_suggestion ALTER COLUMN suggestion_from DROP NOT NULL;');
            } catch (\Throwable $e) {
                // Ignore if already nullable or not pgsql
            }

            // 2. Add recorded_at_office column
            $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

            Schema::table('rdp_location_suggestion', function (Blueprint $table) use ($officeTable) {
                if (!Schema::hasColumn('rdp_location_suggestion', 'recorded_at_office')) {
                    $table->string('recorded_at_office', 50)->nullable()->after('location_type');
                }
            });

            // Add foreign key constraint if office table exists
            try {
                Schema::table('rdp_location_suggestion', function (Blueprint $table) use ($officeTable) {
                    $table->foreign('recorded_at_office')
                        ->references('office_code')
                        ->on($officeTable)
                        ->onDelete('cascade');
                });
            } catch (\Throwable $e) {
                // Foreign key might already exist or table constraint differs
            }

            // 3. Backfill distinct past locations from rdp_record
            try {
                if (Schema::hasTable('rdp_record')) {
                    $pastLocations = DB::table('rdp_record')
                        ->select('records_location', 'office_own', 'record_series_id')
                        ->whereNotNull('records_location')
                        ->where('records_location', '<>', '')
                        ->distinct()
                        ->get();

                    foreach ($pastLocations as $past) {
                        $locName = trim($past->records_location);
                        if ($locName === '') continue;

                        $lower = strtolower($locName);
                        $type = 'Office';
                        if (str_contains($lower, 'cabinet')) {
                            $type = 'Cabinet';
                        } elseif (str_contains($lower, 'shelf')) {
                            $type = 'Shelf';
                        } elseif (str_contains($lower, 'room') || str_contains($lower, 'office')) {
                            $type = 'Room / Facility';
                        } elseif (str_contains($lower, 'box') || str_contains($lower, 'folder') || str_contains($lower, 'drawer')) {
                            $type = 'Box / Container';
                        }

                        $exists = DB::table('rdp_location_suggestion')
                            ->whereRaw('LOWER(location_name) = ?', [strtolower($locName)])
                            ->where(function ($q) use ($past) {
                                if (!empty($past->office_own)) {
                                    $q->where('recorded_at_office', $past->office_own);
                                } else {
                                    $q->whereNull('recorded_at_office');
                                }
                            })
                            ->exists();

                        if (!$exists) {
                            DB::table('rdp_location_suggestion')->insert([
                                'location_name'      => $locName,
                                'suggestion_from'    => $past->record_series_id,
                                'location_type'      => $type,
                                'recorded_at_office' => $past->office_own,
                                'created_at'         => now(),
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-critical backfill failure
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_location_suggestion') && Schema::hasColumn('rdp_location_suggestion', 'recorded_at_office')) {
            Schema::table('rdp_location_suggestion', function (Blueprint $table) {
                try {
                    $table->dropForeign(['recorded_at_office']);
                } catch (\Throwable $e) {}
                $table->dropColumn('recorded_at_office');
            });
        }
    }
};

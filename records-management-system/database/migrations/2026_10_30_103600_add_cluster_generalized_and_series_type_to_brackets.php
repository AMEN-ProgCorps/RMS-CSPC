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
        // 1. Add series_type_id to rdp_record_series_brackets
        if (Schema::hasTable('rdp_record_series_brackets')) {
            Schema::table('rdp_record_series_brackets', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_record_series_brackets', 'series_type_id')) {
                    $table->unsignedBigInteger('series_type_id')->nullable()->after('id');
                }
            });

            // Associate existing 3 brackets with CSPC NAP (series_type_id = 2)
            try {
                DB::table('rdp_record_series_brackets')
                    ->whereNull('series_type_id')
                    ->update(['series_type_id' => 2]);
            } catch (\Throwable $e) {}
        }

        // 2. Add main_bracket_id to rdp_office_bracket and make sub_bracket_id nullable
        if (Schema::hasTable('rdp_office_bracket')) {
            try {
                DB::statement('ALTER TABLE rdp_office_bracket ALTER COLUMN sub_bracket_id DROP NOT NULL');
            } catch (\Throwable $e) {}

            Schema::table('rdp_office_bracket', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_office_bracket', 'main_bracket_id')) {
                    $table->unsignedBigInteger('main_bracket_id')->nullable()->after('id');
                }
            });

            // Backfill main_bracket_id for existing records from their sub_bracket
            try {
                if (Schema::hasTable('rdp_record_series_sub_brackets')) {
                    $links = DB::table('rdp_office_bracket')->whereNull('main_bracket_id')->get();
                    foreach ($links as $l) {
                        if ($l->sub_bracket_id) {
                            $mbId = DB::table('rdp_record_series_sub_brackets')->where('id', $l->sub_bracket_id)->value('main_bracket_id');
                            if ($mbId) {
                                DB::table('rdp_office_bracket')->where('id', $l->id)->update(['main_bracket_id' => $mbId]);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }
        // 3. Add is_locked to rdp_record_series_type
        if (Schema::hasTable('rdp_record_series_type')) {
            Schema::table('rdp_record_series_type', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_record_series_type', 'is_locked')) {
                    $table->boolean('is_locked')->default(false)->after('is_active');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record_series_type') && Schema::hasColumn('rdp_record_series_type', 'is_locked')) {
            Schema::table('rdp_record_series_type', function (Blueprint $table) {
                $table->dropColumn('is_locked');
            });
        }
        if (Schema::hasTable('rdp_office_bracket') && Schema::hasColumn('rdp_office_bracket', 'main_bracket_id')) {
            Schema::table('rdp_office_bracket', function (Blueprint $table) {
                $table->dropColumn('main_bracket_id');
            });
        }

        if (Schema::hasTable('rdp_record_series_brackets') && Schema::hasColumn('rdp_record_series_brackets', 'series_type_id')) {
            Schema::table('rdp_record_series_brackets', function (Blueprint $table) {
                $table->dropColumn('series_type_id');
            });
        }
    }
};

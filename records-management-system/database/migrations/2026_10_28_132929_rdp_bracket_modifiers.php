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

        // 1. Create sub-bracket entity table linked to main bracket
        if (!Schema::hasTable('rdp_record_series_sub_brackets')) {
            Schema::create('rdp_record_series_sub_brackets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('main_bracket_id')->nullable();
                $table->string('sub_name');
                $table->unsignedBigInteger('office_manager_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('main_bracket_id')
                    ->references('id')
                    ->on('rdp_record_series_brackets')
                    ->onDelete('cascade');
            });
        }

        // 2. Create office-to-sub-bracket mapping table
        if (!Schema::hasTable('rdp_office_bracket')) {
            Schema::create('rdp_office_bracket', function (Blueprint $table) use ($officeTable) {
                $table->id();
                $table->unsignedBigInteger('sub_bracket_id');
                $table->string('office_code');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('sub_bracket_id')
                    ->references('id')
                    ->on('rdp_record_series_sub_brackets')
                    ->onDelete('cascade');

                if (Schema::hasTable($officeTable)) {
                    $table->foreign('office_code')
                        ->references('office_code')
                        ->on($officeTable)
                        ->onDelete('cascade');
                }
            });
        }

        // 3. Add sub_bracket_id to rdp_record_series (preserve recorded_at_office for compatibility with pending approval workflow)
        if (Schema::hasTable('rdp_record_series')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                if (!Schema::hasColumn('rdp_record_series', 'sub_bracket_id')) {
                    $table->unsignedBigInteger('sub_bracket_id')->nullable()->after('bracket_id');
                    $table->foreign('sub_bracket_id')
                        ->references('id')
                        ->on('rdp_record_series_sub_brackets')
                        ->onDelete('set null');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rdp_record_series') && Schema::hasColumn('rdp_record_series', 'sub_bracket_id')) {
            Schema::table('rdp_record_series', function (Blueprint $table) {
                $table->dropForeign(['sub_bracket_id']);
                $table->dropColumn('sub_bracket_id');
            });
        }

        Schema::dropIfExists('rdp_office_bracket');
        Schema::dropIfExists('rdp_record_series_sub_brackets');
    }
};

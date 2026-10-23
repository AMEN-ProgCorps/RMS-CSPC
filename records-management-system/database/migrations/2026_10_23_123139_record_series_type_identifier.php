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
        Schema::create('rdp_record_series_type_id', function (Blueprint $table) {
            $table->id();
            $table->text('identifier_name');
            $table->text('shorten_identifier_value');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('rdp_record_series_type_id')->insert([
            ['identifier_name' => 'General Records Disposition Schedule', 'shorten_identifier_value' => 'GRDS'],
            ['identifier_name' => 'Agency Records Disposition Schedule', 'shorten_identifier_value' => 'ARDS'],
        ]);

        Schema::table('rdp_record_series_type', function (Blueprint $table) {
            $table->unsignedBigInteger('series_type_id')->nullable()->after('is_active');
            // nullOnDelete - NOT cascade. rdp_record_series already cascades from
            // rdp_record_series_type, so a cascade here would mean deleting one
            // identifier row (e.g. GRDS) wipes every type that references it AND
            // all of their record series (181 CSPC + 530 NAP rows).
            $table->foreign('series_type_id')
                ->references('id')
                ->on('rdp_record_series_type_id')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rdp_record_series_type', function (Blueprint $table) {
            $table->dropForeign(['series_type_id']);
            $table->dropColumn('series_type_id');
        });

        Schema::dropIfExists('rdp_record_series_type_id');
    }
};

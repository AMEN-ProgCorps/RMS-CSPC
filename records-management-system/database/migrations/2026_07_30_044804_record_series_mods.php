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
        Schema::create('rdp_record_series_type', function (Blueprint $table) {
            $table->id();
            $table->string('type_name');
            $table->string('shorted_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('rdp_record_series_brackets', function (Blueprint $table) {
            $table->id();
            $table->string('bracket_name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('rdp_record_series', function (Blueprint $table) {
            $table->unsignedBigInteger('bracket_id')->nullable()->after('parent_id');
            $table->unsignedBigInteger('series_type')->nullable()->after('bracket_id');
            $table->foreign('bracket_id')->references('id')->on('rdp_record_series_brackets')->onDelete('cascade');
            $table->foreign('series_type')->references('id')->on('rdp_record_series_type')->onDelete('cascade');
        });

        Schema::table('rdp_record', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('office_own');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rdp_record', function (Blueprint $table) {
            $table->dropColumn('is_draft');
        });

        Schema::table('rdp_record_series', function (Blueprint $table) {
            $table->dropForeign(['bracket_id']);
            $table->dropForeign(['series_type']);
            $table->dropColumn(['bracket_id', 'series_type']);
        });

        Schema::dropIfExists('rdp_record_series_brackets');
        Schema::dropIfExists('rdp_record_series_type');
    }
};

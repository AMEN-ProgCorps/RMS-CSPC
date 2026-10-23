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
            $table->timestamp()->useCurrent();
        });
        DB::table('rdp_record_series_type_id')->insert([
            'identifier_name' => 'General Records Disposition Schedule',
            'shorten_identifier_value' => 'GRDS'
        ]);
        DB::table('rdp_record_series_type_id')->insert([
            'identifier_name' => 'Agency Records Disposition Schedule',
            'shorten_identifier_value' => 'ARDS'
        ]);
        Schema::table('rdp_record_series_type',function(Blueprint $table){
            $table->unsignedBigInteger('series_type_id')->nullable()->after('is_active');
            $table->foreign('series_type_id')->references('id')->on('rdp_record_series_type_id')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

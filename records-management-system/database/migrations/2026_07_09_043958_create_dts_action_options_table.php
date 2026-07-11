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
        Schema::create('dts_action_options', function (Blueprint $table) {
            $table->id();
            $table->string('option_name')->unique();
            $table->timestamps();
        });

        // Seed initial options
        DB::table('dts_action_options')->insert([
            ['option_name' => 'For Approval', 'created_at' => now(), 'updated_at' => now()],
            ['option_name' => 'For Signature', 'created_at' => now(), 'updated_at' => now()],
            ['option_name' => 'For Recommendation', 'created_at' => now(), 'updated_at' => now()],
            ['option_name' => 'For Endorsement', 'created_at' => now(), 'updated_at' => now()],
            ['option_name' => 'For Revision', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dts_action_options');
    }
};

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
        DB::table('rdp_remark_key_triggers')->insert([
            ['key_word' => 'If', 'is_action_before' => true],
            ['key_word' => 'After', 'is_action_before' => false]]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rdp_remark_key_triggers')->whereIn('key_word', ['If', 'After'])->delete();
    }
};

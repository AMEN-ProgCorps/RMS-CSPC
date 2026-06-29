<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('office')->updateOrInsert(
            ['office_code' => '[H]'],
            ['office_name' => 'Cluster Head', 'is_active' => true]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('office')->where('office_code', '[H]')->delete();
    }
};

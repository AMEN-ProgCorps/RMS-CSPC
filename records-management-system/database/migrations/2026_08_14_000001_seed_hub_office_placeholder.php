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
        $existing = DB::table('office')->where('office_code', '[HUB]')->first();
        if (!$existing) {
            $maxId = (DB::table('office')->max('id') ?? 0) + 1;
            DB::table('office')->insert([
                'id' => $maxId,
                'office_code' => '[HUB]',
                'office_name' => 'Office Hub [Multi-Receiving]',
                'is_active' => true,
            ]);

            // Sync sequence if pgsql
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('office', 'id'), coalesce(max(id), 1)) FROM office;");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('office')->where('office_code', '[HUB]')->delete();
    }
};

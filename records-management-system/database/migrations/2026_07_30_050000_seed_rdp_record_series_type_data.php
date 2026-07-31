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
        $types = [
            
            [
                'type_name'    => 'National Archives of the Philippines',
                'shorted_type' => 'PH-NAP',
                'is_active'    => true,
            ],
            [
                'type_name'    => 'Camarines Sur Polytechnic Colleges',
                'shorted_type' => 'CSPC',
                'is_active'    => true,
            ],
            [
                'type_name'    => 'No Labels',
                'shorted_type' => 'OTHERS',
                'is_active'    => true,
            ],
        ];

        foreach ($types as $type) {
            DB::table('rdp_record_series_type')->updateOrInsert(
                ['shorted_type' => $type['shorted_type']],
                array_merge($type, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rdp_record_series_type')
            ->whereIn('shorted_type', [ 'PH-NAP', 'CSPC', 'OTHERS'])
            ->delete();
    }
};

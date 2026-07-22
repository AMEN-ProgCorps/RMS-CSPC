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
        // 1. Records Medium (rdp_recorded_value)
        $recordsMedia = [
            ['medium_name' => 'Audio Tapes', 'description' => 'Audio Recordings and Magnetic Tapes'],
            ['medium_name' => 'CD', 'description' => 'Compact Disc Storage'],
            ['medium_name' => 'Drawings', 'description' => 'Architectural & Engineering Blueprints/Drawings'],
            ['medium_name' => 'DVD', 'description' => 'Digital Versatile Disc Storage'],
            ['medium_name' => 'Electronic', 'description' => 'Digital Files & Electronic Records'],
            ['medium_name' => 'Floppy Disks', 'description' => 'Legacy Magnetic Floppy Disk Storage'],
            ['medium_name' => 'Maps', 'description' => 'Cartographic Maps & Charts'],
            ['medium_name' => 'Microfilm', 'description' => 'Microfilm & Microfiche Formats'],
            ['medium_name' => 'Paper', 'description' => 'Physical Paper Documents & Files'],
            ['medium_name' => 'Photographs', 'description' => 'Still Photography Prints & Negatives'],
            ['medium_name' => 'Video Tapes', 'description' => 'Video Recordings & Cassettes'],
        ];

        foreach ($recordsMedia as $medium) {
            DB::table('rdp_recorded_value')->updateOrInsert(
                ['medium_name' => $medium['medium_name']],
                array_merge($medium, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // 2. Restrictions (rdp_restriction_type)
        $restrictions = [
            ['restriction_value' => 'Confidential'],
            ['restriction_value' => 'Open Access'],
            ['restriction_value' => 'Restricted'],
            ['restriction_value' => 'Secret'],
            ['restriction_value' => 'Top Secret'],
        ];

        foreach ($restrictions as $restriction) {
            DB::table('rdp_restriction_type')->updateOrInsert(
                ['restriction_value' => $restriction['restriction_value']],
                array_merge($restriction, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // 3. Frequency of Use (rdp_frequence_use)
        $frequencies = [
            ['freq_type' => 'Annually'],
            ['freq_type' => 'As needs arises'],
            ['freq_type' => 'Daily'],
            ['freq_type' => 'Monthly'],
            ['freq_type' => 'Quarterly'],
            ['freq_type' => 'Semi-Annually'],
            ['freq_type' => 'Weekly'],
        ];

        foreach ($frequencies as $freq) {
            DB::table('rdp_frequence_use')->updateOrInsert(
                ['freq_type' => $freq['freq_type']],
                array_merge($freq, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // 4. Time Value (rdp_time_value)
        $timeValues = [
            ['char_value' => 'T', 'description' => 'Temporary'],
            ['char_value' => 'P', 'description' => 'Permanent'],
        ];

        foreach ($timeValues as $tv) {
            DB::table('rdp_time_value')->updateOrInsert(
                ['char_value' => $tv['char_value']],
                array_merge($tv, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // 5. Utility Value (rdp_utility_medium)
        $utilityValues = [
            ['utility_name' => 'Administrative', 'description' => 'Adm – Administrative'],
            ['utility_name' => 'Archival', 'description' => 'Arc – Archival'],
            ['utility_name' => 'Fiscal', 'description' => 'F – Fiscal'],
            ['utility_name' => 'Legal', 'description' => 'L – Legal'],
        ];

        foreach ($utilityValues as $uv) {
            DB::table('rdp_utility_medium')->updateOrInsert(
                ['utility_name' => $uv['utility_name']],
                array_merge($uv, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // 6. Volume Conversions (rdp_volume_conversion)
        $conversions = [
            ['value_standard' => '1 Cubic Meter', 'value_converted' => '35.315 Cubic Feet', 'is_active' => true],
            ['value_standard' => '1 Archival Box', 'value_converted' => '1.5 Cubic Feet', 'is_active' => true],
            ['value_standard' => '1 Transfer Box', 'value_converted' => '1.0 Cubic Feet', 'is_active' => true],
        ];

        foreach ($conversions as $conv) {
            DB::table('rdp_volume_conversion')->updateOrInsert(
                ['value_standard' => $conv['value_standard']],
                array_merge($conv, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('rdp_recorded_value')->whereIn('medium_name', [
            'Audio Tapes', 'CD', 'Drawings', 'DVD', 'Electronic',
            'Floppy Disks', 'Maps', 'Microfilm', 'Paper', 'Photographs', 'Video Tapes'
        ])->delete();

        DB::table('rdp_restriction_type')->whereIn('restriction_value', [
            'Confidential', 'Open Access', 'Restricted', 'Secret', 'Top Secret'
        ])->delete();

        DB::table('rdp_frequence_use')->whereIn('freq_type', [
            'Annually', 'As needs arises', 'Daily', 'Monthly', 'Quarterly', 'Semi-Annually', 'Weekly'
        ])->delete();

        DB::table('rdp_time_value')->whereIn('char_value', ['T', 'P'])->delete();

        DB::table('rdp_utility_medium')->whereIn('utility_name', [
            'Administrative', 'Archival', 'Fiscal', 'Legal'
        ])->delete();

        DB::table('rdp_volume_conversion')->whereIn('value_standard', [
            '1 Cubic Meter', '1 Archival Box', '1 Transfer Box'
        ])->delete();
    }
};

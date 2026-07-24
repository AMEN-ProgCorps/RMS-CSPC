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

        // 6. Volume Values & Conversions (rdp_volume_value & rdp_volume_conversion)
        $volumeUnits = [
            'Pages', 'Folder', 'Box', 'Cubic Meter', 'Cubic Feet', 'Archival Box', 'Transfer Box'
        ];

        $unitIds = [];
        foreach ($volumeUnits as $unitName) {
            $existing = DB::table('rdp_volume_value')->where('value_standard', $unitName)->first();
            if ($existing) {
                $unitIds[$unitName] = $existing->volume_id;
            } else {
                $unitIds[$unitName] = DB::table('rdp_volume_value')->insertGetId([
                    'value_standard'     => $unitName,
                    'cur_used_standard'  => false,
                    'cur_used_converted' => false,
                    'is_active'          => true,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ], 'volume_id');
            }
        }

        // Seed 100 Pages = 1 Folder Rule
        if (isset($unitIds['Pages'], $unitIds['Folder'])) {
            $convId = DB::table('rdp_volume_conversion')->insertGetId([
                'amount_standard' => 100,
                'value_standard'  => $unitIds['Pages'],
                'amount_converted'=> 1,
                'value_converted' => $unitIds['Folder'],
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            DB::table('rdp_volume_value')->where('volume_id', $unitIds['Pages'])->update(['cur_used_standard' => true]);
            DB::table('rdp_volume_value')->where('volume_id', $unitIds['Folder'])->update(['cur_used_converted' => true]);
        }

        // 7. Seed Predefined Record Series with Retention Periods
        $predefinedSeries = [
            [
                'series_title'   => 'Gate Passes',
                'active_period'  => '6 Months',
                'storage_period' => '1 Year',
                'total_period'   => '1 Year 6 Months',
                'remarks'        => 'Dispose after retention',
            ],
            [
                'series_title'   => 'Receipts',
                'active_period'  => '1 Year',
                'storage_period' => '4 Years',
                'total_period'   => '5 Years',
                'remarks'        => 'Audit required before disposal',
            ],
            [
                'series_title'   => 'Financial Statements',
                'active_period'  => 'Permanent',
                'storage_period' => 'Permanent',
                'total_period'   => 'Permanent',
                'remarks'        => 'Permanent record',
            ],
            [
                'series_title'   => 'Minutes of Meetings',
                'active_period'  => 'Permanent',
                'storage_period' => 'Permanent',
                'total_period'   => 'Permanent',
                'remarks'        => 'Permanent record',
            ],
            [
                'series_title'   => 'Appraisal Records',
                'active_period'  => '3 Years',
                'storage_period' => '2 Years',
                'total_period'   => '5 Years',
                'remarks'        => 'Review upon expiration',
            ],
        ];

        foreach ($predefinedSeries as $seriesData) {
            $retentionId = DB::table('rdp_retention_period')->insertGetId([
                'active_period'  => $seriesData['active_period'],
                'storage_period' => $seriesData['storage_period'],
                'total_period'   => $seriesData['total_period'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            DB::table('rdp_record_series')->updateOrInsert(
                ['series_title' => $seriesData['series_title']],
                [
                    'parent_id'                  => null,
                    'retention_period'           => $retentionId,
                    'is_retention_period_permanent' => strtolower($seriesData['active_period']) === 'permanent',
                    'remarks'                    => $seriesData['remarks'],
                    'created_at'                 => now(),
                    'updated_at'                 => now(),
                ]
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

        DB::table('rdp_volume_conversion')->truncate();
        DB::table('rdp_volume_value')->truncate();

        DB::table('rdp_record_series')->whereIn('series_title', [
            'Gate Passes', 'Receipts', 'Financial Statements', 'Minutes of Meetings', 'Appraisal Records'
        ])->delete();
    }
};

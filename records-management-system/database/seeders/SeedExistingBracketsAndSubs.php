<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedExistingBracketsAndSubs extends Seeder
{
    public function run(): void
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $knownAliases = [
            'BOARD/COLLEGE SECRETARY'    => 'Board Secretary',
            'REGISTRAR'                  => 'SRR',
            'LIBRARY'                    => 'LRD',
            'STUDENT AFFAIRS'            => 'SASO',
            'Guidance'                   => 'Guidance',
            'Dev'                        => 'Dev',
            'HUMAN RESOURCE MANAGEMENT'  => 'HRMDU',
            'RECORD OFFICE'              => 'RFOIU',
            'SUPPLY AND PROPERTY'        => 'SPMU',
            'COLLECTION OF DISBURSEMENT' => 'Cashier',
            'Budget'                     => 'Budget',
            'Accounting'                 => 'Accounting',
            'INTERNAL AUDIT'             => 'IAU',
            'CLINIC'                     => 'HSU',
            'ACADEMIC DEPARTMENT'        => 'VPAA',
        ];

        $groups = DB::table('rdp_record_series')
            ->whereNotNull('bracket_id')
            ->whereNotNull('recorded_at_office')
            ->where('recorded_at_office', '!=', '')
            ->select('bracket_id', 'recorded_at_office')
            ->distinct()
            ->get();

        foreach ($groups as $g) {
            $bracketId = (int) $g->bracket_id;
            $officeName = trim((string) $g->recorded_at_office);
            if (empty($officeName)) continue;

            $sub = DB::table('rdp_record_series_sub_brackets')
                ->where('main_bracket_id', $bracketId)
                ->whereRaw('LOWER(sub_name) = ?', [mb_strtolower($officeName)])
                ->first();

            if (!$sub) {
                $subId = DB::table('rdp_record_series_sub_brackets')->insertGetId([
                    'main_bracket_id' => $bracketId,
                    'sub_name'        => $officeName,
                    'is_active'       => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            } else {
                $subId = $sub->id;
            }

            if (Schema::hasColumn('rdp_record_series', 'sub_bracket_id')) {
                DB::table('rdp_record_series')
                    ->where('bracket_id', $bracketId)
                    ->where('recorded_at_office', $g->recorded_at_office)
                    ->update(['sub_bracket_id' => $subId]);
            }

            if (Schema::hasTable('rdp_office_bracket') && Schema::hasTable($officeTable)) {
                $matchedCode = $knownAliases[$officeName] ?? null;
                if (!$matchedCode) {
                    $matchedCode = DB::table($officeTable)
                        ->where(function($q) use ($officeName) {
                            $q->whereRaw('LOWER(office_code) = ?', [mb_strtolower($officeName)])
                              ->orWhereRaw('LOWER(office_name) = ?', [mb_strtolower($officeName)]);
                        })
                        ->value('office_code');
                }

                if ($matchedCode) {
                    $exists = DB::table('rdp_office_bracket')
                        ->where('sub_bracket_id', $subId)
                        ->where('office_code', $matchedCode)
                        ->exists();

                    if (!$exists) {
                        DB::table('rdp_office_bracket')->insert([
                            'sub_bracket_id' => $subId,
                            'office_code'    => $matchedCode,
                            'is_active'      => true,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                    }
                }
            }
        }

        // President office general records
        $presSub = DB::table('rdp_record_series_sub_brackets')
            ->where('main_bracket_id', 1)
            ->whereRaw('LOWER(sub_name) = ?', [mb_strtolower('Executive & Presidential Records')])
            ->first();

        if (!$presSub) {
            $presSubId = DB::table('rdp_record_series_sub_brackets')->insertGetId([
                'main_bracket_id' => 1,
                'sub_name'        => 'Executive & Presidential Records',
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } else {
            $presSubId = $presSub->id;
        }

        if (Schema::hasColumn('rdp_record_series', 'sub_bracket_id')) {
            DB::table('rdp_record_series')
                ->where('bracket_id', 1)
                ->where(function ($q) {
                    $q->whereNull('recorded_at_office')->orWhere('recorded_at_office', '');
                })
                ->update(['sub_bracket_id' => $presSubId]);
        }

        if (Schema::hasTable('rdp_office_bracket') && Schema::hasTable($officeTable)) {
            $presExists = DB::table('rdp_office_bracket')
                ->where('sub_bracket_id', $presSubId)
                ->where('office_code', 'President')
                ->exists();

            if (!$presExists) {
                DB::table('rdp_office_bracket')->insert([
                    'sub_bracket_id' => $presSubId,
                    'office_code'    => 'President',
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }
}

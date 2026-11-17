<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service to automatically detect, synchronize, and heal Record Series Sub-Brackets
 * and Office mappings from the recorded_at_office values in rdp_record_series.
 */
class RdpBracketSyncService
{
    /**
     * Known mapping aliases from legacy or display office names to sys_office office_codes.
     */
    public static array $knownAliases = [
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

    /**
     * Checks if all required tables and columns are present.
     */
    public static function enabled(): bool
    {
        return Schema::hasTable('rdp_record_series')
            && Schema::hasTable('rdp_record_series_brackets')
            && Schema::hasTable('rdp_record_series_sub_brackets')
            && Schema::hasTable('rdp_office_bracket');
    }

    /**
     * Synchronizes all record series across all main brackets into sub-brackets
     * and maps their respective offices.
     *
     * @return array [synced_subs => int, linked_series => int, linked_offices => int]
     */
    public static function syncAllBrackets(): array
    {
        if (!self::enabled()) {
            return ['synced_subs' => 0, 'linked_series' => 0, 'linked_offices' => 0];
        }

        $mainBracketIds = DB::table('rdp_record_series_brackets')->pluck('id')->toArray();
        $totalSyncedSubs = 0;
        $totalLinkedSeries = 0;
        $totalLinkedOffices = 0;

        foreach ($mainBracketIds as $mId) {
            $res = self::syncMainBracket((int) $mId);
            $totalSyncedSubs += $res['synced_subs'];
            $totalLinkedSeries += $res['linked_series'];
            $totalLinkedOffices += $res['linked_offices'];
        }

        return [
            'synced_subs'    => $totalSyncedSubs,
            'linked_series'  => $totalLinkedSeries,
            'linked_offices' => $totalLinkedOffices,
        ];
    }

    /**
     * Synchronizes sub-brackets and office associations for a specific Main Bracket.
     *
     * @param int $mainBracketId
     * @return array
     */
    public static function syncMainBracket(int $mainBracketId): array
    {
        if (!self::enabled()) {
            return ['synced_subs' => 0, 'linked_series' => 0, 'linked_offices' => 0];
        }

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $syncedSubs = 0;
        $linkedSeries = 0;
        $linkedOffices = 0;

        // 1. Group by distinct recorded_at_office for this bracket
        $groups = DB::table('rdp_record_series')
            ->where('bracket_id', $mainBracketId)
            ->whereNotNull('recorded_at_office')
            ->where('recorded_at_office', '!=', '')
            ->select('recorded_at_office')
            ->distinct()
            ->get();

        foreach ($groups as $g) {
            $officeName = trim((string) $g->recorded_at_office);
            if (empty($officeName)) {
                continue;
            }

            // Find or create sub-bracket
            $sub = DB::table('rdp_record_series_sub_brackets')
                ->where('main_bracket_id', $mainBracketId)
                ->whereRaw('LOWER(TRIM(sub_name)) = ?', [mb_strtolower($officeName)])
                ->first();

            if (!$sub) {
                $subId = DB::table('rdp_record_series_sub_brackets')->insertGetId([
                    'main_bracket_id' => $mainBracketId,
                    'sub_name'        => $officeName,
                    'is_active'       => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
                $syncedSubs++;
            } else {
                $subId = $sub->id;
            }

            // Link series rows with matching recorded_at_office to this sub_bracket_id
            if (Schema::hasColumn('rdp_record_series', 'sub_bracket_id')) {
                $updated = DB::table('rdp_record_series')
                    ->where('bracket_id', $mainBracketId)
                    ->where('recorded_at_office', $g->recorded_at_office)
                    ->where(function ($q) use ($subId) {
                        $q->whereNull('sub_bracket_id')->orWhere('sub_bracket_id', '!=', $subId);
                    })
                    ->update(['sub_bracket_id' => $subId]);

                $linkedSeries += $updated;
            }

            // Auto-link office mapping in rdp_office_bracket
            $matchedCode = self::resolveOfficeCode($officeName, $officeTable);
            if ($matchedCode) {
                $exists = DB::table('rdp_office_bracket')
                    ->where('sub_bracket_id', $subId)
                    ->where('office_code', $matchedCode)
                    ->exists();

                if (!$exists) {
                    $insertData = [
                        'sub_bracket_id' => $subId,
                        'office_code'    => $matchedCode,
                        'is_active'      => true,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];

                    if (Schema::hasColumn('rdp_office_bracket', 'main_bracket_id')) {
                        $insertData['main_bracket_id'] = $mainBracketId;
                    }

                    DB::table('rdp_office_bracket')->insert($insertData);
                    $linkedOffices++;
                }
            }
        }

        // 2. Special handling for President office cluster (#1) with null or empty recorded_at_office
        if ($mainBracketId === 1) {
            $hasUnassignedPres = DB::table('rdp_record_series')
                ->where('bracket_id', 1)
                ->where(function ($q) {
                    $q->whereNull('recorded_at_office')->orWhere('recorded_at_office', '');
                })
                ->exists();

            if ($hasUnassignedPres) {
                $presSub = DB::table('rdp_record_series_sub_brackets')
                    ->where('main_bracket_id', 1)
                    ->whereRaw('LOWER(TRIM(sub_name)) = ?', [mb_strtolower('Executive & Presidential Records')])
                    ->first();

                if (!$presSub) {
                    $presSubId = DB::table('rdp_record_series_sub_brackets')->insertGetId([
                        'main_bracket_id' => 1,
                        'sub_name'        => 'Executive & Presidential Records',
                        'is_active'       => true,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                    $syncedSubs++;
                } else {
                    $presSubId = $presSub->id;
                }

                if (Schema::hasColumn('rdp_record_series', 'sub_bracket_id')) {
                    $updated = DB::table('rdp_record_series')
                        ->where('bracket_id', 1)
                        ->where(function ($q) {
                            $q->whereNull('recorded_at_office')->orWhere('recorded_at_office', '');
                        })
                        ->where(function ($q) use ($presSubId) {
                            $q->whereNull('sub_bracket_id')->orWhere('sub_bracket_id', '!=', $presSubId);
                        })
                        ->update(['sub_bracket_id' => $presSubId]);

                    $linkedSeries += $updated;
                }

                $presOfficeExists = DB::table('rdp_office_bracket')
                    ->where('sub_bracket_id', $presSubId)
                    ->where('office_code', 'President')
                    ->exists();

                if (!$presOfficeExists && self::officeExists('President', $officeTable)) {
                    $insertData = [
                        'sub_bracket_id' => $presSubId,
                        'office_code'    => 'President',
                        'is_active'      => true,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                    if (Schema::hasColumn('rdp_office_bracket', 'main_bracket_id')) {
                        $insertData['main_bracket_id'] = 1;
                    }
                    DB::table('rdp_office_bracket')->insert($insertData);
                    $linkedOffices++;
                }
            }
        }

        return [
            'synced_subs'    => $syncedSubs,
            'linked_series'  => $linkedSeries,
            'linked_offices' => $linkedOffices,
        ];
    }

    /**
     * Resolves or creates a sub-bracket ID for a given main bracket and office string.
     *
     * @param int|null $mainBracketId
     * @param string|null $officeName
     * @return int|null
     */
    public static function resolveSubBracketId(?int $mainBracketId, ?string $officeName): ?int
    {
        if (!$mainBracketId || !self::enabled()) {
            return null;
        }

        $officeName = trim((string) $officeName);
        if (empty($officeName)) {
            if ($mainBracketId === 1) {
                return DB::table('rdp_record_series_sub_brackets')
                    ->where('main_bracket_id', 1)
                    ->whereRaw('LOWER(TRIM(sub_name)) = ?', [mb_strtolower('Executive & Presidential Records')])
                    ->value('id');
            }
            return null;
        }

        $sub = DB::table('rdp_record_series_sub_brackets')
            ->where('main_bracket_id', $mainBracketId)
            ->whereRaw('LOWER(TRIM(sub_name)) = ?', [mb_strtolower($officeName)])
            ->first();

        if ($sub) {
            return $sub->id;
        }

        // Auto-create sub-bracket
        $subId = DB::table('rdp_record_series_sub_brackets')->insertGetId([
            'main_bracket_id' => $mainBracketId,
            'sub_name'        => $officeName,
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Auto-assign office if match found
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $matchedCode = self::resolveOfficeCode($officeName, $officeTable);
        if ($matchedCode) {
            $insertData = [
                'sub_bracket_id' => $subId,
                'office_code'    => $matchedCode,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
            if (Schema::hasColumn('rdp_office_bracket', 'main_bracket_id')) {
                $insertData['main_bracket_id'] = $mainBracketId;
            }
            DB::table('rdp_office_bracket')->insert($insertData);
        }

        return $subId;
    }

    /**
     * Helper to resolve an office code from name/alias.
     */
    public static function resolveOfficeCode(string $officeName, string $officeTable): ?string
    {
        if (isset(self::$knownAliases[$officeName])) {
            $alias = self::$knownAliases[$officeName];
            if (self::officeExists($alias, $officeTable)) {
                return $alias;
            }
        }

        if (!Schema::hasTable($officeTable)) {
            return null;
        }

        // Try exact match on office_code
        $code = DB::table($officeTable)
            ->whereRaw('LOWER(office_code) = ?', [mb_strtolower($officeName)])
            ->value('office_code');

        if ($code) {
            return $code;
        }

        // Try exact match on office_name
        $code = DB::table($officeTable)
            ->whereRaw('LOWER(office_name) = ?', [mb_strtolower($officeName)])
            ->value('office_code');

        if ($code) {
            return $code;
        }

        // Try substring / like match
        $code = DB::table($officeTable)
            ->where(function ($q) use ($officeName) {
                $lower = mb_strtolower($officeName);
                $q->whereRaw('LOWER(office_code) LIKE ?', ['%' . $lower . '%'])
                  ->orWhereRaw('LOWER(office_name) LIKE ?', ['%' . $lower . '%']);
            })
            ->value('office_code');

        return $code ?: null;
    }

    /**
     * Checks if an office code exists in the office table.
     */
    public static function officeExists(string $officeCode, string $officeTable): bool
    {
        if (!Schema::hasTable($officeTable)) {
            return true;
        }

        return DB::table($officeTable)
            ->whereRaw('LOWER(office_code) = ?', [mb_strtolower($officeCode)])
            ->exists();
    }
}

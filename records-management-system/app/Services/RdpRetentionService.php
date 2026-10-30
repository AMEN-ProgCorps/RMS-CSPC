<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RdpRetentionService
{
    /**
     * Parse date covered string into a Carbon date instance.
     * Supports formats like '2024-09-25', '2_APRIL_2025', '25_September_2024', or standalone year '2024'.
     */
    public static function parseDateCovered(?string $dateStr): ?Carbon
    {
        if (empty($dateStr) || $dateStr === '—') {
            return null;
        }

        $clean = trim(str_replace('_', ' ', $dateStr));

        // 1. Standard ISO Y-m-d
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $clean)) {
            try {
                return Carbon::parse($clean)->startOfDay();
            } catch (\Exception $e) {
                // fallback
            }
        }

        // 2. Day Month Year (e.g. '2 APRIL 2025' or '25 September 2024')
        if (preg_match('/^(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})$/', $clean, $m)) {
            try {
                return Carbon::parse("{$m[1]} {$m[2]} {$m[3]}")->startOfDay();
            } catch (\Exception $e) {
                // fallback
            }
        }

        // 3. Month Year (e.g. 'April 2025')
        if (preg_match('/^([a-zA-Z]+)\s+(\d{4})$/', $clean, $m)) {
            try {
                return Carbon::parse("1 {$m[1]} {$m[2]}")->startOfDay();
            } catch (\Exception $e) {
                // fallback
            }
        }

        // 4. Standalone Year (e.g. '2025')
        if (preg_match('/(19\d\d|20\d\d)/', $clean, $m)) {
            try {
                return Carbon::createFromDate((int)$m[1], 1, 1)->startOfDay();
            } catch (\Exception $e) {
                // fallback
            }
        }

        return null;
    }

    /**
     * Parse a retention period duration string into months.
     * E.g. "1 year" -> 12, "2 years" -> 24, "6 months" -> 6, "Permanent" -> null.
     */
    public static function parseDurationMonths(?string $durationStr): ?int
    {
        if (empty($durationStr)) {
            return null;
        }

        $str = strtolower(trim($durationStr));

        if (str_contains($str, 'perm') || $str === 'p') {
            return null; // permanent = null
        }

        $totalMonths = 0;
        $found = false;

        // Match years (e.g. 1 year, 2.5 years, 1 yr, 1y)
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:year|yr|y)s?/i', $str, $m)) {
            $totalMonths += (int)round(((float)$m[1]) * 12);
            $found = true;
        }

        // Match months (e.g. 6 months, 3 mo, 3m)
        if (preg_match('/(\d+)\s*(?:month|mo|m)s?/i', $str, $m)) {
            $totalMonths += (int)$m[1];
            $found = true;
        }

        // Match days if any (e.g. 30 days)
        if (preg_match('/(\d+)\s*(?:day|d)s?/i', $str, $m)) {
            $totalMonths += (int)ceil(((int)$m[1]) / 30);
            $found = true;
        }

        // If simple integer like "1" or "2"
        if (!$found && is_numeric($str)) {
            $totalMonths = ((int)$str) * 12;
            $found = true;
        }

        return $found ? $totalMonths : null;
    }

    /**
     * Parse the end date of a period covered into a Carbon date instance (end of day).
     * If an end date is not explicitly provided, uses the end boundary of the start date
     * (e.g. standalone year 2022 -> 2022-12-31 23:59:59, 'May 2024' -> 2024-05-31 23:59:59).
     */
    public static function parsePeriodEndDate(?string $startDate, ?string $endDate = null): ?Carbon
    {
        $target = !empty(trim((string)$endDate)) ? trim((string)$endDate) : trim((string)$startDate);
        if (empty($target) || $target === '—') {
            return null;
        }

        // Check if target is a range string like "2022-01-01 - 2022-12-31" or "Jan 2022 - Dec 2022"
        if (preg_match('/^(.*?)\s+(?:-|to)\s+(.*)$/i', $target, $rm)) {
            $target = trim($rm[2]);
        }

        $clean = trim(str_replace('_', ' ', $target));

        // 1. Standard ISO Y-m-d
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $clean, $m)) {
            try {
                return Carbon::createFromDate((int)$m[1], (int)$m[2], (int)$m[3])->endOfDay();
            } catch (\Throwable) {}
        }

        // 2. Day Month Year (e.g. '31 December 2024')
        if (preg_match('/^(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})$/', $clean, $m)) {
            try {
                return Carbon::parse("{$m[1]} {$m[2]} {$m[3]}")->endOfDay();
            } catch (\Throwable) {}
        }

        // 3. Month Year (e.g. 'Dec 2022', 'May 2024') -> end of that month
        if (preg_match('/^([a-zA-Z]+)\s+(\d{4})$/', $clean, $m)) {
            try {
                return Carbon::parse("1 {$m[1]} {$m[2]}")->endOfMonth()->endOfDay();
            } catch (\Throwable) {}
        }

        // 4. Standalone Year (e.g. '2022') -> end of that year (Dec 31)
        if (preg_match('/^(19\d\d|20\d\d)$/', $clean, $m)) {
            try {
                return Carbon::createFromDate((int)$m[1], 12, 31)->endOfDay();
            } catch (\Throwable) {}
        }

        // Fallback parse
        try {
            return Carbon::parse($clean)->endOfDay();
        } catch (\Throwable) {}

        return null;
    }

    /**
     * Parse a retention period duration string into whole years.
     * E.g. "5 YEARS" -> 5, "1 YEAR" -> 1, "Permanent" -> null.
     */
    public static function parseDurationYears(?string $durationStr): ?int
    {
        if (empty($durationStr)) {
            return null;
        }

        $str = strtolower(trim($durationStr));

        if (str_contains($str, 'perm') || $str === 'p') {
            return null; // permanent = null
        }

        // 1. Direct number of years (e.g. "5 YEARS", "1 YEAR", "2.5 years", "1 yr")
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:year|yr|y)s?/i', $str, $m)) {
            return (int)round((float)$m[1]);
        }

        // 2. Number of months (e.g. "6 MONTHS") -> round to years
        if (preg_match('/(\d+)\s*(?:month|mo|m)s?/i', $str, $m)) {
            $mCount = (int)$m[1];
            return $mCount > 0 ? max(1, (int)round($mCount / 12)) : 0;
        }

        // 3. Simple integer
        if (is_numeric($str)) {
            return (int)$str;
        }

        return null;
    }

    /**
     * Extract the Record Period year (closing / end year) from a date or range string.
     * E.g. "2020-05-15" -> 2020, "2018 - 2021" -> 2021, "2024" -> 2024.
     */
    public static function parsePeriodYear(?string $startDate, ?string $endDate = null): ?int
    {
        $target = !empty(trim((string)$endDate)) ? trim((string)$endDate) : trim((string)$startDate);
        if (empty($target) || $target === '—') {
            return null;
        }

        // Check if target is a range string like "2018 - 2021" or "2018 to 2021"
        if (preg_match('/^(.*?)\s+(?:-|to)\s+(.*)$/i', $target, $rm)) {
            $target = trim($rm[2]);
        }

        $clean = trim(str_replace('_', ' ', $target));

        // 1. Match 4-digit year (19xx or 20xx)
        if (preg_match('/(19\d{2}|20\d{2})/', $clean, $m)) {
            return (int)$m[1];
        }

        // 2. Fallback parse with Carbon
        try {
            $carbon = Carbon::parse($clean);
            return (int)$carbon->year;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Calculate the disposal cutoff year based on the formula:
     * <current_year> - 1 - <total_retention_period>
     * Records with Record Period Year <= Cutoff Year are eligible for disposal.
     */
    public static function calculateDisposalCutoffYear(
        ?string $totalPeriod = null,
        ?string $activePeriod = null,
        ?string $storagePeriod = null,
        ?Carbon $now = null
    ): ?int {
        $years = self::parseDurationYears($totalPeriod);
        if ($years === null) {
            $yActive = self::parseDurationYears($activePeriod) ?? 0;
            $yStorage = self::parseDurationYears($storagePeriod) ?? 0;
            $years = ($yActive + $yStorage) > 0 ? ($yActive + $yStorage) : null;
        }

        if ($years === null || $years < 0) {
            return null;
        }

        $currentYear = $now ? (int)$now->year : (int)Carbon::now()->year;
        return $currentYear - 1 - $years;
    }

    /**
     * Determine if a specific period is expired based on the year formula:
     * <current_year> - 1 - <total_retention_period>
     * A record is expired if its Record Period Year <= Cutoff Year.
     */
    public static function isPeriodExpired(
        ?string $startDate,
        ?string $endDate = null,
        ?string $totalPeriod = null,
        ?string $activePeriod = null,
        ?string $storagePeriod = null,
        bool $isPermanent = false,
        ?Carbon $now = null
    ): bool {
        if ($isPermanent) {
            return false;
        }

        if (strtolower(trim($totalPeriod ?? '')) === 'permanent') {
            return false;
        }

        $recordYear = self::parsePeriodYear($startDate, $endDate);
        if (!$recordYear) {
            return false;
        }

        $cutoffYear = self::calculateDisposalCutoffYear($totalPeriod, $activePeriod, $storagePeriod, $now);
        if ($cutoffYear === null) {
            return false;
        }

        return ($recordYear <= $cutoffYear);
    }

    /**
     * Determine if a record is expired.
     */
    public static function isRecordExpired(
        ?string $dateCovered,
        ?string $totalPeriod,
        ?string $activePeriod = null,
        ?string $storagePeriod = null,
        bool $isPermanent = false,
        ?string $dateCoveredEnd = null,
        ?Carbon $now = null
    ): bool {
        return self::isPeriodExpired(
            $dateCovered,
            $dateCoveredEnd,
            $totalPeriod,
            $activePeriod,
            $storagePeriod,
            $isPermanent,
            $now
        );
    }

    /**
     * Resolve immunity state, effective retention periods, and expiration for a record or series.
     */
    public static function resolveImmunity(
        ?string $origActive,
        ?string $origStorage,
        ?string $origTotal,
        bool $isPerm,
        ?string $remarks,
        $keyWordId = null,
        $isActionBefore = null,
        ?string $remActive = null,
        ?string $remStorage = null,
        ?string $remTotal = null,
        ?string $actionDate = null
    ): object {
        $hasRemarks = !empty(trim((string)$remarks));
        $hasTrigger = !empty($keyWordId);
        $hasImmunity = $hasRemarks && $hasTrigger;

        $hasActionDate = !empty(trim((string)$actionDate)) && trim((string)$actionDate) !== '—';
        $isActionBeforeBool = filter_var($isActionBefore, FILTER_VALIDATE_BOOLEAN);

        $hasRemarkRetentionConfigured = (
            !empty(trim((string)$remActive)) ||
            !empty(trim((string)$remStorage)) ||
            !empty(trim((string)$remTotal))
        );

        $usesRemarkRetention = false;
        $isImmune = false;
        $actionDateTriggered = false;

        if (!$hasImmunity) {
            $effectiveActive = $origActive;
            $effectiveStorage = $origStorage;
            $effectiveTotal = $origTotal;
            $effectiveIsPerm = $isPerm;
        } else {
            if ($hasActionDate) {
                // Action date has data -> triggers immunity switch!
                $actionDateTriggered = true;
                if ($isActionBeforeBool) {
                    // Was Remark Retention first -> now switched to Original Retention!
                    $usesRemarkRetention = false;
                    $isImmune = false;
                    $effectiveActive = $origActive;
                    $effectiveStorage = $origStorage;
                    $effectiveTotal = $origTotal;
                    $effectiveIsPerm = $isPerm;
                } else {
                    // Was Original Retention first -> now switched to Remark Retention!
                    $usesRemarkRetention = true;
                    $isImmune = !$hasRemarkRetentionConfigured;
                    $effectiveActive = $remActive;
                    $effectiveStorage = $remStorage;
                    $effectiveTotal = $remTotal;
                    $effectiveIsPerm = false;
                }
            } else {
                // Action date is blank -> stays in initial configured state!
                if ($isActionBeforeBool) {
                    // Remark Retention first
                    $usesRemarkRetention = true;
                    $isImmune = !$hasRemarkRetentionConfigured;
                    $effectiveActive = $remActive;
                    $effectiveStorage = $remStorage;
                    $effectiveTotal = $remTotal;
                    $effectiveIsPerm = false;
                } else {
                    // Remark Retention later -> waiting for action trigger (e.g. "After settled")!
                    // Record is IMMUNE from disposal until action date is provided
                    $usesRemarkRetention = false;
                    $isImmune = true;
                    $effectiveActive = $origActive;
                    $effectiveStorage = $origStorage;
                    $effectiveTotal = $origTotal;
                    $effectiveIsPerm = $isPerm;
                }
            }
        }

        return (object)[
            'has_immunity'                 => $hasImmunity,
            'uses_remark_retention'        => $usesRemarkRetention,
            'is_immune'                    => $isImmune,
            'action_date_triggered'        => $actionDateTriggered,
            'has_remark_config'            => $hasRemarkRetentionConfigured,
            'effective_active'             => $effectiveActive,
            'effective_storage'            => $effectiveStorage,
            'effective_total'              => $effectiveTotal,
            'effective_is_perm'            => $effectiveIsPerm,
            'original_active'              => $origActive,
            'original_storage'             => $origStorage,
            'original_total'               => $origTotal,
            'original_is_perm'             => $isPerm,
            'remark_active'                => $remActive,
            'remark_storage'               => $remStorage,
            'remark_total'                 => $remTotal,
        ];
    }

    /**
     * Check if a period is expired considering the immunity state.
     */
    public static function isPeriodExpiredWithImmunity(
        ?string $startDate,
        ?string $endDate,
        object $immunityObj,
        ?string $actionDate = null,
        ?Carbon $now = null
    ): bool {
        if ($immunityObj->is_immune) {
            return false;
        }

        if ($immunityObj->effective_is_perm) {
            return false;
        }

        // If action date triggered the switch, calculate expiration from action date
        $baseStart = ($immunityObj->action_date_triggered && !empty($actionDate) && $actionDate !== '—') ? $actionDate : $startDate;
        $baseEnd = ($immunityObj->action_date_triggered && !empty($actionDate) && $actionDate !== '—') ? $actionDate : $endDate;

        return self::isPeriodExpired(
            $baseStart,
            $baseEnd,
            $immunityObj->effective_total,
            $immunityObj->effective_active,
            $immunityObj->effective_storage,
            $immunityObj->effective_is_perm,
            $now
        );
    }

    /**
     * Alias for isPeriodExpiredWithImmunity.
     */
    public static function isRecordExpiredWithImmunity(
        ?string $startDate,
        ?string $endDate,
        object $immunityObj,
        ?string $actionDate = null,
        ?Carbon $now = null
    ): bool {
        return self::isPeriodExpiredWithImmunity($startDate, $endDate, $immunityObj, $actionDate, $now);
    }

    /**
     * Scan and synchronize the transferred_to_nap3 flag on all active records.
     * Returns the count of newly transferred records.
     */
    public static function syncTransferredRecords(?string $officeCode = null, ?Carbon $now = null): int
    {
        // 1. Fetch active records with their series retention, remarks logic, and triggers
        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_retention_period as sub_ret', 'rdp_record_series.retention_period', '=', 'sub_ret.id')
            ->leftJoin('rdp_retention_period as parent_ret', 'parent.retention_period', '=', 'parent_ret.id')
            ->leftJoin('rdp_remarks_logic as sub_rem', 'rdp_record_series.remarks', '=', 'sub_rem.id')
            ->leftJoin('rdp_remarks_logic as parent_rem', 'parent.remarks', '=', 'parent_rem.id')
            ->leftJoin('rdp_remark_key_triggers as sub_trig', 'sub_rem.key_word_id', '=', 'sub_trig.id')
            ->leftJoin('rdp_remark_key_triggers as parent_trig', 'parent_rem.key_word_id', '=', 'parent_trig.id')
            ->select([
                'rdp_record.id',
                'rdp_record.action_date',
                'rdp_record.ispartof_batch',
                'rdp_record.transferred_to_nap3',
                'rdp_record_series.is_retention_period_permanent as sub_perm',
                'parent.is_retention_period_permanent as parent_perm',
                'sub_ret.active_period as sub_active',
                'sub_ret.storage_period as sub_storage',
                'sub_ret.total_period as sub_total',
                'parent_ret.active_period as parent_active',
                'parent_ret.storage_period as parent_storage',
                'parent_ret.total_period as parent_total',
                DB::raw('COALESCE(sub_rem.remarks_value, parent_rem.remarks_value) as eff_remarks'),
                DB::raw('COALESCE(sub_rem.key_word_id, parent_rem.key_word_id) as eff_key_word_id'),
                DB::raw('COALESCE(sub_rem.active_period_backup, parent_rem.active_period_backup) as eff_rem_active'),
                DB::raw('COALESCE(sub_rem.storage_period_backup, parent_rem.storage_period_backup) as eff_rem_storage'),
                DB::raw('COALESCE(sub_rem.total_period_backup, parent_rem.total_period_backup) as eff_rem_total'),
                DB::raw('COALESCE(sub_trig.is_action_before, parent_trig.is_action_before) as eff_is_action_before'),
            ])
            ->where('rdp_record.is_draft', false)
            ->where('rdp_record.is_active', true);

        if ($officeCode) {
            $query->where('rdp_record.office_own', $officeCode);
        }

        $records = $query->get();
        if ($records->isEmpty()) {
            return 0;
        }

        $periods = DB::table('rdp_period_covered')
            ->whereIn('period_owner', $records->pluck('id'))
            ->get()
            ->groupBy('period_owner');

        $toTransferIds = [];
        $toUnsetIds = [];

        foreach ($records as $r) {
            $isPerm = (bool)($r->sub_perm ?? false) || (bool)($r->parent_perm ?? false);

            $effActive = $r->sub_active ?: $r->parent_active;
            $effStorage = $r->sub_storage ?: $r->parent_storage;
            $effTotal = $r->sub_total ?: $r->parent_total;

            $immunity = self::resolveImmunity(
                $effActive,
                $effStorage,
                $effTotal,
                $isPerm,
                $r->eff_remarks,
                $r->eff_key_word_id,
                $r->eff_is_action_before,
                $r->eff_rem_active,
                $r->eff_rem_storage,
                $r->eff_rem_total,
                $r->action_date
            );

            $recPeriods = $periods[$r->id] ?? collect();
            $isBatch = (bool)($r->ispartof_batch ?? false) || $recPeriods->count() > 1;

            if ($immunity->is_immune) {
                $expired = false;
            } elseif ($recPeriods->isEmpty()) {
                $expired = false;
            } elseif ($isBatch) {
                // Batch record: check if all periods are expired
                $expiredCount = 0;
                foreach ($recPeriods as $p) {
                    if (self::isPeriodExpiredWithImmunity($p->date_covered, $p->date_covered_end, $immunity, $r->action_date, $now)) {
                        $expiredCount++;
                    }
                }
                $expired = ($expiredCount === $recPeriods->count());
            } else {
                $p = $recPeriods->first();
                $expired = self::isPeriodExpiredWithImmunity($p->date_covered, $p->date_covered_end, $immunity, $r->action_date, $now);
            }

            if ($expired && !(bool)$r->transferred_to_nap3) {
                $toTransferIds[] = $r->id;
            } elseif (!$expired && (bool)$r->transferred_to_nap3) {
                // If retention extended or immunity protected
                $toUnsetIds[] = $r->id;
            }
        }

        if (!empty($toTransferIds)) {
            DB::table('rdp_record')->whereIn('id', $toTransferIds)->update([
                'transferred_to_nap3'    => true,
                'transferred_to_nap3_at' => now(),
            ]);
        }

        if (!empty($toUnsetIds)) {
            DB::table('rdp_record')->whereIn('id', $toUnsetIds)->update([
                'transferred_to_nap3'    => false,
                'transferred_to_nap3_at' => null,
            ]);
        }

        return count($toTransferIds);
    }
}

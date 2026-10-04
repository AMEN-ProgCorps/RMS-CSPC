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
     * Determine if a specific period is expired based on its start/end dates and retention parameters.
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

        $endCarbon = self::parsePeriodEndDate($startDate, $endDate);
        if (!$endCarbon) {
            return false;
        }

        $months = self::parseDurationMonths($totalPeriod);
        if ($months === null) {
            $mActive = self::parseDurationMonths($activePeriod) ?? 0;
            $mStorage = self::parseDurationMonths($storagePeriod) ?? 0;
            $months = ($mActive + $mStorage) > 0 ? ($mActive + $mStorage) : null;
        }

        if ($months === null || $months <= 0) {
            return false;
        }

        $expirationDate = $endCarbon->copy()->addMonths($months);
        $checkDate = $now ?? Carbon::now();

        return $expirationDate->lte($checkDate);
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
     * Scan and synchronize the transferred_to_nap3 flag on all active records.
     * Returns the count of newly transferred records.
     */
    public static function syncTransferredRecords(?string $officeCode = null, ?Carbon $now = null): int
    {
        // 1. Fetch active records with their series retention and batch indicators
        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_retention_period as sub_ret', 'rdp_record_series.retention_period', '=', 'sub_ret.id')
            ->leftJoin('rdp_retention_period as parent_ret', 'parent.retention_period', '=', 'parent_ret.id')
            ->select([
                'rdp_record.id',
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

            $recPeriods = $periods[$r->id] ?? collect();
            $isBatch = (bool)($r->ispartof_batch ?? false) || $recPeriods->count() > 1;

            if ($recPeriods->isEmpty()) {
                $expired = false;
            } elseif ($isBatch) {
                // Batch record: check if all periods are expired
                $expiredCount = 0;
                foreach ($recPeriods as $p) {
                    if (self::isPeriodExpired($p->date_covered, $p->date_covered_end, $effTotal, $effActive, $effStorage, $isPerm, $now)) {
                        $expiredCount++;
                    }
                }
                $expired = ($expiredCount === $recPeriods->count());
            } else {
                $p = $recPeriods->first();
                $expired = self::isPeriodExpired($p->date_covered, $p->date_covered_end, $effTotal, $effActive, $effStorage, $isPerm, $now);
            }

            if ($expired && !(bool)$r->transferred_to_nap3) {
                $toTransferIds[] = $r->id;
            } elseif (!$expired && (bool)$r->transferred_to_nap3) {
                // If retention extended or dates changed
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

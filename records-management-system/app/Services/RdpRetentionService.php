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
     * Determine if a record is expired.
     */
    public static function isRecordExpired(
        ?string $dateCovered,
        ?string $totalPeriod,
        ?string $activePeriod = null,
        ?string $storagePeriod = null,
        bool $isPermanent = false
    ): bool {
        if ($isPermanent) {
            return false;
        }

        if (strtolower(trim($totalPeriod ?? '')) === 'permanent') {
            return false;
        }

        $date = self::parseDateCovered($dateCovered);
        if (!$date) {
            return false;
        }

        // Prefer totalPeriod, or sum active + storage
        $months = self::parseDurationMonths($totalPeriod);
        if ($months === null) {
            $mActive = self::parseDurationMonths($activePeriod) ?? 0;
            $mStorage = self::parseDurationMonths($storagePeriod) ?? 0;
            $months = ($mActive + $mStorage) > 0 ? ($mActive + $mStorage) : null;
        }

        if ($months === null || $months <= 0) {
            return false;
        }

        // Expiration date = date covered + retention months
        $expirationDate = $date->copy()->addMonths($months);

        return $expirationDate->lte(Carbon::now());
    }

    /**
     * Scan and synchronize the transferred_to_nap3 flag on all active records.
     * Returns the count of newly transferred records.
     */
    public static function syncTransferredRecords(?string $officeCode = null): int
    {
        // 1. Fetch active records with their series retention and date covered
        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_retention_period as sub_ret', 'rdp_record_series.retention_period', '=', 'sub_ret.id')
            ->leftJoin('rdp_retention_period as parent_ret', 'parent.retention_period', '=', 'parent_ret.id')
            ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
            ->select([
                'rdp_record.id',
                'rdp_record.transferred_to_nap3',
                'rdp_record_series.is_retention_period_permanent as sub_perm',
                'parent.is_retention_period_permanent as parent_perm',
                'sub_ret.active_period as sub_active',
                'sub_ret.storage_period as sub_storage',
                'sub_ret.total_period as sub_total',
                'parent_ret.active_period as parent_active',
                'parent_ret.storage_period as parent_storage',
                'parent_ret.total_period as parent_total',
                'rdp_period_covered.date_covered',
            ])
            ->where('rdp_record.is_draft', false)
            ->where('rdp_record.is_active', true);

        if ($officeCode) {
            $query->where('rdp_record.office_own', $officeCode);
        }

        $records = $query->get();

        $toTransferIds = [];
        $toUnsetIds = [];

        foreach ($records as $r) {
            $isPerm = (bool)($r->sub_perm ?? false) || (bool)($r->parent_perm ?? false);

            $effActive = $r->sub_active ?: $r->parent_active;
            $effStorage = $r->sub_storage ?: $r->parent_storage;
            $effTotal = $r->sub_total ?: $r->parent_total;

            $expired = self::isRecordExpired(
                $r->date_covered,
                $effTotal,
                $effActive,
                $effStorage,
                $isPerm
            );

            if ($expired && !(bool)$r->transferred_to_nap3) {
                $toTransferIds[] = $r->id;
            } elseif (!$expired && (bool)$r->transferred_to_nap3) {
                // If retention extended or date changed in the future
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

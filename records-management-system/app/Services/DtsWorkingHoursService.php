<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DtsWorkingHoursService
{
    protected static ?bool $cachedEnabled = null;
    protected static ?string $cachedStart = null;
    protected static ?string $cachedEnd = null;
    protected static ?string $cachedDays = null;

    /**
     * Clear the in-memory cache for settings.
     */
    public static function clearCache(): void
    {
        self::$cachedEnabled = null;
        self::$cachedStart = null;
        self::$cachedEnd = null;
        self::$cachedDays = null;
    }

    /**
     * Retrieve the settings table name.
     */
    protected static function getSettingsTable(): string
    {
        return Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
    }

    /**
     * Check if working hours restriction is enabled.
     */
    public static function isEnabled(): bool
    {
        if (self::$cachedEnabled !== null) {
            return self::$cachedEnabled;
        }

        try {
            $val = DB::table(self::getSettingsTable())
                ->where('key', 'dts_working_hours_enabled')
                ->value('value');
            self::$cachedEnabled = ($val === 'true');
        } catch (\Throwable) {
            self::$cachedEnabled = false;
        }

        return self::$cachedEnabled;
    }

    /**
     * Get configured start time in HH:mm format (default: '06:00').
     */
    public static function getStartHour(): string
    {
        if (self::$cachedStart !== null) {
            return self::$cachedStart;
        }

        try {
            $val = DB::table(self::getSettingsTable())
                ->where('key', 'dts_working_hours_start')
                ->value('value');
            self::$cachedStart = !empty($val) ? trim($val) : '06:00';
        } catch (\Throwable) {
            self::$cachedStart = '06:00';
        }

        return self::$cachedStart;
    }

    /**
     * Get configured end time in HH:mm format (default: '17:00').
     */
    public static function getEndHour(): string
    {
        if (self::$cachedEnd !== null) {
            return self::$cachedEnd;
        }

        try {
            $val = DB::table(self::getSettingsTable())
                ->where('key', 'dts_working_hours_end')
                ->value('value');
            self::$cachedEnd = !empty($val) ? trim($val) : '17:00';
        } catch (\Throwable) {
            self::$cachedEnd = '17:00';
        }

        return self::$cachedEnd;
    }

    /**
     * Get configured working days: 'mon-fri' or 'all' (default: 'mon-fri').
     */
    public static function getWorkingDays(): string
    {
        if (self::$cachedDays !== null) {
            return self::$cachedDays;
        }

        try {
            $val = DB::table(self::getSettingsTable())
                ->where('key', 'dts_working_hours_days')
                ->value('value');
            self::$cachedDays = (!empty($val) && in_array(strtolower(trim($val)), ['mon-fri', 'all']))
                ? strtolower(trim($val))
                : 'mon-fri';
        } catch (\Throwable) {
            self::$cachedDays = 'mon-fri';
        }

        return self::$cachedDays;
    }

    /**
     * Determine if a given date is a working day based on settings.
     */
    public static function isWorkingDay(Carbon|string|null $date = null): bool
    {
        $c = $date ? Carbon::parse($date) : Carbon::now();
        $daysConfig = self::getWorkingDays();

        if ($daysConfig === 'all') {
            return true;
        }

        // 'mon-fri': Monday (1) to Friday (5)
        return !$c->isWeekend();
    }

    /**
     * Check if the given timestamp falls within active working hours.
     * If the restriction is disabled, this always returns true.
     */
    public static function isWithinWorkingHours(Carbon|string|null $time = null): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        $now = $time ? Carbon::parse($time) : Carbon::now();

        if (!self::isWorkingDay($now)) {
            return false;
        }

        $startParts = explode(':', self::getStartHour());
        $endParts = explode(':', self::getEndHour());

        $startToday = $now->copy()->setTime((int) ($startParts[0] ?? 6), (int) ($startParts[1] ?? 0), 0);
        $endToday = $now->copy()->setTime((int) ($endParts[0] ?? 17), (int) ($endParts[1] ?? 0), 0);

        return $now->betweenIncluded($startToday, $endToday);
    }

    /**
     * Gate for station processing actions (Receive, Forward, Proceed, Complete, Return).
     */
    public static function canProcessStationAction(?Carbon $time = null): bool
    {
        return self::isWithinWorkingHours($time);
    }

    /**
     * Calculate the next timestamp when working hours begin.
     */
    public static function getNextWorkingHoursStart(Carbon|string|null $from = null): Carbon
    {
        $current = $from ? Carbon::parse($from) : Carbon::now();
        $startParts = explode(':', self::getStartHour());
        $startHour = (int) ($startParts[0] ?? 6);
        $startMin = (int) ($startParts[1] ?? 0);

        $endParts = explode(':', self::getEndHour());
        $endHour = (int) ($endParts[0] ?? 17);
        $endMin = (int) ($endParts[1] ?? 0);

        $candidate = $current->copy()->setTime($startHour, $startMin, 0);

        // If today is a working day and current time is before start time, it starts today
        if (self::isWorkingDay($current) && $current->lessThan($candidate)) {
            return $candidate;
        }

        // Otherwise look forward day by day until next working day
        $next = $current->copy()->addDay()->setTime($startHour, $startMin, 0);
        while (!self::isWorkingDay($next)) {
            $next->addDay();
        }

        return $next;
    }

    /**
     * Formatted human-readable working hours window notice.
     */
    public static function getWorkingHoursWindowNotice(): string
    {
        $startFmt = Carbon::createFromFormat('H:i', self::getStartHour())->format('g:i A');
        $endFmt = Carbon::createFromFormat('H:i', self::getEndHour())->format('g:i A');
        $daysFmt = self::getWorkingDays() === 'all' ? 'All Days' : 'Monday – Friday';

        return "{$startFmt} to {$endFmt} ({$daysFmt})";
    }

    /**
     * Formatted notice for creation form outside working hours.
     */
    public static function getCreationNoticeBanner(): string
    {
        $window = self::getWorkingHoursWindowNotice();
        return "Notice: You are attempting to create a transaction outside of working hours ({$window}). This transaction may be created, but it will be sent starting in the working hours.";
    }

    /**
     * Formatted restriction notice when attempting station actions outside working hours.
     */
    public static function getStationActionRestrictionNotice(): string
    {
        $window = self::getWorkingHoursWindowNotice();
        return "Station processing actions (Receive, Forward, Proceed, Complete, Return) are restricted outside of configured working hours ({$window}). You may view documents, but transactions cannot be processed until working hours commence.";
    }

    /**
     * Calculate active working seconds elapsed between two timestamps.
     * When disabled, returns full 24/7 calendar elapsed seconds.
     */
    public static function calculateWorkingSeconds(Carbon|string|null $startDate, Carbon|string|null $endDate = null): int
    {
        if (!$startDate) {
            return 0;
        }

        $start = Carbon::parse($startDate);
        $end = $endDate ? Carbon::parse($endDate) : Carbon::now();

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        if (!self::isEnabled()) {
            return (int) $start->diffInSeconds($end);
        }

        $startParts = explode(':', self::getStartHour());
        $endParts = explode(':', self::getEndHour());
        $startH = (int) ($startParts[0] ?? 6);
        $startM = (int) ($startParts[1] ?? 0);
        $endH = (int) ($endParts[0] ?? 17);
        $endM = (int) ($endParts[1] ?? 0);

        $totalSeconds = 0;
        $dayCursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($dayCursor->lessThanOrEqualTo($endDay)) {
            if (self::isWorkingDay($dayCursor)) {
                $winStart = $dayCursor->copy()->setTime($startH, $startM, 0);
                $winEnd = $dayCursor->copy()->setTime($endH, $endM, 0);

                if ($winEnd->greaterThan($winStart)) {
                    $effectiveStart = $start->greaterThan($winStart) ? $start->copy() : $winStart;
                    $effectiveEnd = $end->lessThan($winEnd) ? $end->copy() : $winEnd;

                    if ($effectiveEnd->greaterThan($effectiveStart)) {
                        $totalSeconds += $effectiveStart->diffInSeconds($effectiveEnd);
                    }
                }
            }
            $dayCursor->addDay();
        }

        return $totalSeconds;
    }

    /**
     * Calculate daily working window in seconds.
     */
    public static function getDailyWorkingSeconds(): int
    {
        $startParts = explode(':', self::getStartHour());
        $endParts = explode(':', self::getEndHour());
        $startH = (int) ($startParts[0] ?? 6);
        $startM = (int) ($startParts[1] ?? 0);
        $endH = (int) ($endParts[0] ?? 17);
        $endM = (int) ($endParts[1] ?? 0);

        $dummy = Carbon::today();
        $wStart = $dummy->copy()->setTime($startH, $startM, 0);
        $wEnd = $dummy->copy()->setTime($endH, $endM, 0);

        $diff = (int) $wStart->diffInSeconds($wEnd);
        return $diff > 0 ? $diff : 86400;
    }

    /**
     * Calculate active elapsed days between two timestamps.
     * When working hours restriction is enabled, 1 day = 1 full daily working window.
     */
    public static function calculateWorkingDays(Carbon|string|null $startDate, Carbon|string|null $endDate = null): int
    {
        if (!$startDate) {
            return 0;
        }

        if (!self::isEnabled()) {
            $s = Carbon::parse($startDate);
            $e = $endDate ? Carbon::parse($endDate) : Carbon::now();
            return (int) abs($s->diffInDays($e));
        }

        $seconds = self::calculateWorkingSeconds($startDate, $endDate);
        $dailySecs = self::getDailyWorkingSeconds();

        return (int) floor($seconds / $dailySecs);
    }

    /**
     * Calculate active elapsed minutes between two timestamps.
     */
    public static function calculateWorkingMinutes(Carbon|string|null $startDate, Carbon|string|null $endDate = null): int
    {
        $seconds = self::calculateWorkingSeconds($startDate, $endDate);
        return (int) round($seconds / 60);
    }

    /**
     * Format elapsed active working time into a human-readable duration string.
     * E.g. "2 days 4 hours 12 minutes" or "3 hours 20 minutes" or "less than a minute".
     */
    public static function calculateDurationString(Carbon|string|null $startDate, Carbon|string|null $endDate = null): string
    {
        if (!$startDate) {
            return 'N/A';
        }

        $seconds = self::calculateWorkingSeconds($startDate, $endDate);
        if ($seconds < 60) {
            return 'less than a minute';
        }

        if (self::isEnabled()) {
            $dailySecs = self::getDailyWorkingSeconds();
            $days = (int) floor($seconds / $dailySecs);
            $remaining = $seconds % $dailySecs;
            $hours = (int) floor($remaining / 3600);
            $mins = (int) floor(($remaining % 3600) / 60);
        } else {
            $days = (int) floor($seconds / 86400);
            $remaining = $seconds % 86400;
            $hours = (int) floor($remaining / 3600);
            $mins = (int) floor(($remaining % 3600) / 60);
        }

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' ' . Str::plural('day', $days);
        }
        if ($hours > 0) {
            $parts[] = $hours . ' ' . Str::plural('hour', $hours);
        }
        if ($mins > 0) {
            $parts[] = $mins . ' ' . Str::plural('minute', $mins);
        }

        return empty($parts) ? 'less than a minute' : implode(' ', $parts);
    }

    /**
     * Dispatches any transactions that were created outside working hours
     * once active working hours commence.
     */
    public static function dispatchPendingOutsideWorkingHoursTransactions(): int
    {
        if (!self::isWithinWorkingHours()) {
            return 0;
        }

        // Find transactions marked with pending working hours dispatch
        $pendingTrans = DB::table('dts_transactions as dt')
            ->leftJoin('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->where('dt.pending_working_hours_dispatch', true)
            ->whereNotIn('dt.status', ['completed', 'cancelled'])
            ->select('dt.*', 'dtd.transaction_flow', 'dtd.control_number')
            ->get();

        $dispatchedCount = 0;

        foreach ($pendingTrans as $trans) {
            // Check if auto-forward is enabled
            $autoFwdSetting = DB::table(self::getSettingsTable())
                ->where('key', 'dts_auto_forward_created_transaction')
                ->value('value');

            if ($autoFwdSetting !== 'false') {
                // Get flow and sequence
                $flow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $trans->transaction_flow)
                    ->first();

                if ($flow) {
                    $steps = DB::table('dts_sequence_list')
                        ->where('control_id', $flow->id)
                        ->orderBy('sequence_ranking', 'asc')
                        ->get();

                    if ($steps->count() > 1 && $trans->sequence === 1) {
                        $nextStep = $steps->get(1);
                        $nextOffice = $nextStep ? $nextStep->office_code : $trans->current_office;

                        // Advance sequence to step 2
                        DB::table('dts_transactions')
                            ->where('transaction_id', $trans->transaction_id)
                            ->update([
                                'sequence' => 2,
                                'current_office' => $nextOffice,
                                'pending_working_hours_dispatch' => false,
                            ]);

                        // Add log entry
                        $logTable = Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
                        DB::table($logTable)->insert([
                            'transaction_id' => $trans->transaction_id,
                            'office_code' => $trans->current_office,
                            'notes' => 'Dispatched automatically at start of working hours',
                            'type' => 'forwarded',
                            'date_in' => now(),
                            'date_out' => now(),
                            'performed_by' => 1,
                        ]);

                        $dispatchedCount++;
                        continue;
                    }
                }
            }

            // Unflag even if not forwarded
            DB::table('dts_transactions')
                ->where('transaction_id', $trans->transaction_id)
                ->update(['pending_working_hours_dispatch' => false]);
        }

        return $dispatchedCount;
    }
}

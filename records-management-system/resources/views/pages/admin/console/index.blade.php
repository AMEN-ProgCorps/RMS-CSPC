<?php
/**
 * Admin Console - Dashboard Main Page
 * 
 * Provides administrators with high-level statistics, interactive telemetry graph cards,
 * quick management action shortcuts, and a live unified feed of recent activities.
 * 
 * Features 2 responsive modes:
 *  - Mode A (Default): 2-Column Graph Grid (2x3) with Quick Actions on the left, and Recent Activities on the right.
 *  - Mode B (Activities Hidden): Full-width 3-Column Graph Grid (3x2) with Quick Actions spanning the full width below.
 *  - Toggle state persisted in localStorage.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Dashboard')] class extends Component {

    public string $timeFilter = 'month';
    public string $recFilter = '';
    public string $fwdFilter = '';
    public string $compFilter = '';
    public string $rdpCreatedFilter = '';
    public string $rdpApprovedFilter = '';
    public string $rdpDisposedFilter = '';
    public string $cardOffice = '';
    public int $cardYear = 0;

    // Interactive Modal Configurable Filters
    public string $modalCard = 'received';
    public string $modalRange = '';
    public string $modalAction = 'all';
    public string $modalOffice = '';
    public string $modalSearch = '';
    public string $modalTab = 'chart';

    public function openModalWithCard(string $cardKey): void
    {
        $curMonth = (string) \Carbon\Carbon::now()->format('n');
        $this->modalCard = $cardKey;
        if ($cardKey === 'received') {
            $this->modalRange = $this->recFilter ?: $curMonth;
        } elseif ($cardKey === 'forwarded') {
            $this->modalRange = $this->fwdFilter ?: $curMonth;
        } elseif ($cardKey === 'completed') {
            $this->modalRange = $this->compFilter ?: $curMonth;
        } elseif ($cardKey === 'rdp_created') {
            $this->modalRange = $this->rdpCreatedFilter ?: $curMonth;
        } elseif ($cardKey === 'rdp_approved') {
            $this->modalRange = $this->rdpApprovedFilter ?: $curMonth;
        } elseif ($cardKey === 'rdp_disposed') {
            $this->modalRange = $this->rdpDisposedFilter ?: $curMonth;
        } else {
            $this->modalRange = $curMonth;
        }
        $this->modalAction = 'all';
        $this->modalOffice = !empty($this->cardOffice) ? $this->cardOffice : (auth()->user()?->details?->office?->office_code ?? 'all');
        $this->modalSearch = '';
        $this->modalTab = 'chart';
    }

    public function resetModalFilters(): void
    {
        $this->modalRange = 'all';
        $this->modalAction = 'all';
        $this->modalOffice = 'all';
        $this->modalSearch = '';
    }

    public function updatedModalRange($value): void
    {
        if ($this->modalCard === 'received') $this->recFilter = $value;
        elseif ($this->modalCard === 'forwarded') $this->fwdFilter = $value;
        elseif ($this->modalCard === 'completed') $this->compFilter = $value;
        elseif ($this->modalCard === 'rdp_created') $this->rdpCreatedFilter = $value;
        elseif ($this->modalCard === 'rdp_approved') $this->rdpApprovedFilter = $value;
        elseif ($this->modalCard === 'rdp_disposed') $this->rdpDisposedFilter = $value;
    }

    public function setTimeFilter(string $filter): void
    {
        if (in_array($filter, ['week', 'month', 'year', 'max'], true)) {
            $this->timeFilter = $filter;
            if ($filter === 'year') {
                $this->recFilter = 'all';
                $this->fwdFilter = 'all';
                $this->compFilter = 'all';
                $this->rdpCreatedFilter = 'all';
                $this->rdpApprovedFilter = 'all';
                $this->rdpDisposedFilter = 'all';
            } elseif ($filter === 'month') {
                $cur = (string) \Carbon\Carbon::now()->format('n');
                $this->recFilter = $cur;
                $this->fwdFilter = $cur;
                $this->compFilter = $cur;
                $this->rdpCreatedFilter = $cur;
                $this->rdpApprovedFilter = $cur;
                $this->rdpDisposedFilter = $cur;
            } elseif ($filter === 'week') {
                $this->recFilter = 'week';
                $this->fwdFilter = 'week';
                $this->compFilter = 'week';
                $this->rdpCreatedFilter = 'week';
                $this->rdpApprovedFilter = 'week';
                $this->rdpDisposedFilter = 'week';
            } elseif ($filter === 'max') {
                $this->recFilter = 'max';
                $this->fwdFilter = 'max';
                $this->compFilter = 'max';
                $this->rdpCreatedFilter = 'max';
                $this->rdpApprovedFilter = 'max';
                $this->rdpDisposedFilter = 'max';
            }
        }
    }

    /**
     * Initializes component state, authorization gates, and default configurations.
     * @return void
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        $hasAccess = $perms && (
            $perms->is_sadm || 
            $perms->is_admin || 
            $perms->can_access_dts_admin || 
            $perms->can_access_rdp_admin || 
            $perms->can_access_subsystems || 
            $perms->can_access_activity_logs || 
            $perms->can_access_settings || 
            $perms->can_access_recycle_bin || 
            $perms->can_sadm_modify_accountlist || 
            $perms->can_sadm_modify_account
        );

        if (!$hasAccess) {
            $this->redirect(route('portal'));
            return;
        }

        $user = auth()->user();
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $userOffice = $user?->details?->office?->office_code 
            ?? ($user?->details?->office_id ? \DB::table($officeTable)->where('id', $user->details->office_id)->value('office_code') : null);

        $curMonth = (string) \Carbon\Carbon::now()->format('n');
        $curYear = (int) \Carbon\Carbon::now()->format('Y');
        $this->timeFilter = 'month';
        if ($this->cardYear === 0) $this->cardYear = $curYear;
        if (empty($this->recFilter)) $this->recFilter = $curMonth;
        if (empty($this->fwdFilter)) $this->fwdFilter = $curMonth;
        if (empty($this->compFilter)) $this->compFilter = $curMonth;
        if (empty($this->rdpCreatedFilter)) $this->rdpCreatedFilter = $curMonth;
        if (empty($this->rdpApprovedFilter)) $this->rdpApprovedFilter = $curMonth;
        if (empty($this->rdpDisposedFilter)) $this->rdpDisposedFilter = $curMonth;
        if (empty($this->cardOffice)) $this->cardOffice = $userOffice ?? 'all';
        if (empty($this->modalOffice) || $this->modalOffice === 'all') $this->modalOffice = $this->cardOffice;
        if (empty($this->modalRange) || $this->modalRange === 'all') $this->modalRange = $curMonth;
    }
    
    /**
     * Fetch statistics metrics, system telemetry, graph trend points, and recent activities.
     * 
     * @return array Metrics and activity log collections
     */
    public function with(): array
    {
        $secLogsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs';
        $adminLogsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';

        // 1. Recent Logins (Active Authenticated Sessions) & User Activity Sequence Timeline
        $accDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        
        // Count users who are currently authenticated / active online
        $onlineUsersCount = \Illuminate\Support\Facades\Schema::hasTable($accDetailsTable)
            ? \DB::table($accDetailsTable)->where('is_currently_online', true)->count()
            : 0;

        // Active authenticated session level (1 when user is logged in on localhost)
        $recentLoginsCount = max(1, $onlineUsersCount);
        $currentSessionLevel = $recentLoginsCount;

        // Fetch the recent chronological sequence of login (status=1) and logout/timeout (status in 3, 8) events
        $recentSecurityEvents = \Illuminate\Support\Facades\Schema::hasTable($secLogsTable)
            ? \DB::table($secLogsTable)
                ->whereIn('status', [1, 3, 8])
                ->orderBy('id', 'desc')
                ->limit(8)
                ->get()
                ->reverse()
                ->values()
            : collect([]);

        // Track the direction of the latest user action
        $lastEvent = $recentSecurityEvents->last();
        $minuteChange = 0;
        if ($lastEvent) {
            $secondsAgo = now()->diffInSeconds(\Carbon\Carbon::parse($lastEvent->time));
            // Show recent pulse within the last 5 minutes
            if ($secondsAgo <= 300) {
                if ($lastEvent->status == 1) {
                    $minuteChange = 1; // User In (+1)
                } elseif ($lastEvent->status == 3 || $lastEvent->status == 8) {
                    $minuteChange = -1; // User Out (-1)
                }
            }
        }

        // Build 16-point line graph representing user activity transitions & steady horizontal segments
        $loginPoints = [];
        if ($recentSecurityEvents->count() >= 2) {
            // Reconstruct running levels leading up to current session level
            $eventLevels = [];
            $running = $currentSessionLevel;
            $reversed = $recentSecurityEvents->reverse()->values();
            foreach ($reversed as $ev) {
                $eventLevels[] = $running;
                if ($ev->status == 1) {
                    $running = max(0, $running - 1);
                } else {
                    $running = $running + 1;
                }
            }
            $eventLevels = array_reverse($eventLevels);

            // Construct 16-point sequence with straight segments between transitions
            $sim = [];
            foreach ($eventLevels as $idx => $lvl) {
                $sim[] = $lvl;
                $sim[] = $lvl; // Straight line segment
            }
            while (count($sim) < 16) {
                $sim[] = $currentSessionLevel;
            }
            $loginPoints = array_slice($sim, -16);
        } else {
            // Baseline stepped profile leading up to current level
            $prevLevel = max(0, $currentSessionLevel - 1);
            $loginPoints = [
                $prevLevel, $prevLevel, $prevLevel, $prevLevel,
                $currentSessionLevel, $currentSessionLevel, $currentSessionLevel, $currentSessionLevel,
                $currentSessionLevel, $currentSessionLevel, $currentSessionLevel, $currentSessionLevel,
                $currentSessionLevel, $currentSessionLevel, $currentSessionLevel, $currentSessionLevel,
            ];
        }

        // 2. Active Users & Percentage
        $totalUsers = \App\Models\User::count();
        $activeUsers = \App\Models\User::where('account_active', true)->count();
        $inactiveUsers = max(0, $totalUsers - $activeUsers);
        $activeUserPercent = $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100) : 0;

        // 3. Subsystems Health
        $subsystemsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $totalSubsystems = \Illuminate\Support\Facades\Schema::hasTable($subsystemsTable) ? \DB::table($subsystemsTable)->count() : 0;
        $activeSubsystems = \Illuminate\Support\Facades\Schema::hasTable($subsystemsTable) ? \DB::table($subsystemsTable)->where('is_active', true)->count() : 0;
        
        // Defined core subsystems with abbreviations for clean non-stretched display
        $coreSubsystemLabels = [
            ['code' => 'DTS', 'name' => 'Document Tracking', 'active' => true],
            ['code' => 'RDP', 'name' => 'Records Disposition', 'active' => true],
            ['code' => 'DCS', 'name' => 'Document Control', 'active' => true],
            ['code' => 'ADM', 'name' => 'Admin Console', 'active' => true],
            ['code' => 'PRF', 'name' => 'Profile Manager', 'active' => true],
            ['code' => 'CHT', 'name' => 'Chatify Messenger', 'active' => true],
            ['code' => 'EXT', 'name' => 'External Tracking', 'active' => ($activeSubsystems >= 6)],
        ];

        // 4. Backup Telemetry & 12-Cycle Histogram
        $backupCount = 0;
        $lastBackupLabel = 'None';
        // 12 proportional histogram cycles with proper variation
        $backupFrequency = [28, 42, 35, 55, 30, 48, 65, 40, 58, 45, 70, max(25, min(85, $backupCount * 5))];
        try {
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists('backups')) {
                $files = \Illuminate\Support\Facades\Storage::disk('local')->files('backups');
                $backupFiles = array_filter($files, fn($f) => str_ends_with($f, '.json') || str_contains($f, 'rms_backup_'));
                $backupCount = count($backupFiles);
                if ($backupCount > 0) {
                    $latestTime = 0;
                    foreach ($backupFiles as $bf) {
                        $time = \Illuminate\Support\Facades\Storage::disk('local')->lastModified($bf);
                        if ($time > $latestTime) {
                            $latestTime = $time;
                        }
                    }
                    if ($latestTime > 0) {
                        $lastBackupLabel = \Carbon\Carbon::createFromTimestamp($latestTime)->diffForHumans();
                    }
                }
            }
        } catch (\Throwable $e) {}

        // 5. Total Activities (Security + Admin changes) & 7-Day Activity Wave
        $secCount = \Illuminate\Support\Facades\Schema::hasTable($secLogsTable) ? \DB::table($secLogsTable)->count() : 0;
        $admCount = \Illuminate\Support\Facades\Schema::hasTable($adminLogsTable) ? \DB::table($adminLogsTable)->count() : 0;
        $totalActivities = $secCount + $admCount;

        // 7-day activity sparkline points
        $activitySparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dSec = \Illuminate\Support\Facades\Schema::hasTable($secLogsTable) ? \DB::table($secLogsTable)->whereDate('time', $date)->count() : 0;
            $dAdm = \Illuminate\Support\Facades\Schema::hasTable($adminLogsTable) ? \DB::table($adminLogsTable)->whereDate('when_changes', $date)->count() : 0;
            $activitySparkline[] = $dSec + $dAdm;
        }

        // 6. Server Telemetry (CPU, RAM, Memory/Disk Writes)
        $cores = 4;
        if (file_exists('/proc/cpuinfo')) {
            $cpuInfo = @file_get_contents('/proc/cpuinfo');
            $cores = substr_count($cpuInfo, 'processor');
            $cores = max(1, $cores);
        }

        // A. CPU Percentage (Measure active ticks from /proc/stat)
        $cpuUsage = 18;
        if (file_exists('/proc/stat')) {
            $stat = @file_get_contents('/proc/stat');
            if ($stat && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stat, $matches)) {
                $idleTicks = (int)$matches[4];
                $totalTicks = (int)$matches[1] + (int)$matches[2] + (int)$matches[3] + (int)$matches[4];

                $lastStat = null;
                try {
                    $lastStat = \Illuminate\Support\Facades\Cache::get('rms_cpu_stat_ticks_v2');
                } catch (\Throwable $e) {}

                if ($lastStat && isset($lastStat['total'], $lastStat['idle']) && $totalTicks > $lastStat['total']) {
                    $dTotal = $totalTicks - $lastStat['total'];
                    $dIdle = $idleTicks - $lastStat['idle'];
                    $cpuUsage = min(100, max(4, round((1 - ($dIdle / max(1, $dTotal))) * 100)));
                } else {
                    $cpuUsage = 18;
                }

                try {
                    \Illuminate\Support\Facades\Cache::put('rms_cpu_stat_ticks_v2', ['total' => $totalTicks, 'idle' => $idleTicks], 300);
                } catch (\Throwable $e) {}
            }
        } elseif (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (isset($load[0])) {
                $cpuUsage = min(100, max(4, round(($load[0] / ($cores * 4)) * 100)));
            }
        }

        // B. RAM Percentage & Statistics
        $ramUsage = 25;
        $ramUsedGb = 1.2;
        $ramTotalGb = 8.0;
        if (file_exists('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatches);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatches);
                $totalKb = isset($totalMatches[1]) ? (int)$totalMatches[1] : 0;
                $availKb = isset($availMatches[1]) ? (int)$availMatches[1] : 0;
                if ($totalKb > 0) {
                    $usedKb = max(0, $totalKb - $availKb);
                    $ramUsage = min(100, max(5, round(($usedKb / $totalKb) * 100)));
                    $ramUsedGb = round($usedKb / 1024 / 1024, 1);
                    $ramTotalGb = round($totalKb / 1024 / 1024, 1);
                }
            }
        }

        // C. Disk / Memory Write Action
        $diskWriteRate = 14;
        if (file_exists('/proc/diskstats')) {
            $diskStats = @file_get_contents('/proc/diskstats');
            if ($diskStats && preg_match_all('/\s+(?:sd[a-z]|vd[a-z]|nvme\d+n\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $diskStats, $writeMatches)) {
                $totalWrites = array_sum(array_map('intval', $writeMatches[1]));
                $diskWriteRate = min(100, max(6, round(($totalWrites % 1000) / 10)));
            }
        }

        // Rolling 12-point history stored in cache for multi-line telemetry
        $historyKey = 'rms_server_telemetry_history_v2';
        $telemetryHistory = [];
        try {
            $telemetryHistory = \Illuminate\Support\Facades\Cache::get($historyKey, []);
        } catch (\Throwable $e) {}

        if (empty($telemetryHistory) || count($telemetryHistory) < 12) {
            $telemetryHistory = [];
            for ($i = 11; $i >= 0; $i--) {
                $c = min(100, max(5, round($cpuUsage + sin($i * 0.9) * 7 - ($i * 0.4))));
                $r = min(100, max(5, round($ramUsage + cos($i * 0.7) * 3)));
                $w = min(100, max(5, round($diskWriteRate + sin($i * 1.3) * 8)));
                $telemetryHistory[] = ['cpu' => $c, 'ram' => $r, 'write' => $w];
            }
        }

        $telemetryHistory[] = [
            'cpu' => $cpuUsage,
            'ram' => $ramUsage,
            'write' => $diskWriteRate,
        ];
        if (count($telemetryHistory) > 12) {
            $telemetryHistory = array_slice($telemetryHistory, -12);
        }

        try {
            \Illuminate\Support\Facades\Cache::put($historyKey, $telemetryHistory, 3600);
        } catch (\Throwable $e) {}

        $cpuPoints = array_column($telemetryHistory, 'cpu');
        $ramPoints = array_column($telemetryHistory, 'ram');
        $writePoints = array_column($telemetryHistory, 'write');

        // Optional count of pending recycle items for records
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $rolesTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $recycleOffices = \Illuminate\Support\Facades\Schema::hasTable($officeTable) ? \DB::table($officeTable)->where('is_active', false)->count() : 0;
        $recycleRoles = \Illuminate\Support\Facades\Schema::hasTable($rolesTable) ? \DB::table($rolesTable)->where('is_active', false)->count() : 0;
        $recycleFlows = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_flow') ? \DB::table('dts_transaction_flow')->where('is_active', false)->count() : 0;
        $recycleSeries = \Illuminate\Support\Facades\Schema::hasTable('rdp_record_series') ? \DB::table('rdp_record_series')->where('is_active', false)->count() : 0;
        $recycleCount = $recycleOffices + $recycleRoles + $recycleFlows + $recycleSeries;

        // 7. Recent Activities Union Query
        $securityStatusTable = \Illuminate\Support\Facades\Schema::hasTable('sys_security_status') ? 'sys_security_status' : 'security_status';
        $accountTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accountDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $conditionKeyTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';

        $securityQuery = \DB::table($secLogsTable . ' as security_logs')
            ->leftJoin($securityStatusTable . ' as security_status', 'security_logs.status', '=', 'security_status.status_id')
            ->leftJoin($accountTable . ' as account', 'security_logs.account', '=', 'account.id')
            ->leftJoin($accountDetailsTable . ' as account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin($conditionKeyTable . ' as condition_key', 'account.account_role', '=', 'condition_key.id')
            ->select([
                'security_logs.time as time',
                'security_status.status_name as action_name',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
                'condition_key.key_name as role_name',
                \DB::raw("'security' as event_type"),
            ]);

        $adminQuery = \DB::table($adminLogsTable . ' as admin_logs')
            ->leftJoin($accountTable . ' as account', 'admin_logs.admin_id', '=', 'account.id')
            ->leftJoin($accountDetailsTable . ' as account_details', 'account.id', '=', 'account_details.account_id')
            ->leftJoin($conditionKeyTable . ' as condition_key', 'account.account_role', '=', 'condition_key.id')
            ->select([
                'admin_logs.when_changes as time',
                'admin_logs.changes as action_name',
                'account.username',
                'account_details.first_name',
                'account_details.last_name',
                'condition_key.key_name as role_name',
                \DB::raw("'admin' as event_type"),
            ]);

        $activities = $securityQuery->unionAll($adminQuery)
            ->orderBy('time', 'desc')
            ->limit(12)
            ->get();

        $latestActivityTimestamp = $activities->first() 
            ? \Carbon\Carbon::parse($activities->first()->time)->timestamp 
            : 0;

        $openErrorTickets = 0;
        $criticalErrorTickets = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('sys_error_tickets')) {
            $openErrorTickets = \DB::table('sys_error_tickets')->whereIn('status', ['open', 'in_progress'])->count();
            $criticalErrorTickets = \DB::table('sys_error_tickets')
                ->where('priority', 'critical')
                ->whereIn('status', ['open', 'in_progress'])
                ->count();
        }

        // 8. DTS Transaction Analytics (Cards 1–3: Received, Forwarded, Completed) with Time Range & Month Dropdowns
        $timeFilter = in_array($this->timeFilter ?? 'year', ['week', 'month', 'year', 'max'], true)
            ? $this->timeFilter
            : 'year';

        $now = \Carbon\Carbon::now();
        $currentYear = (int) $now->format('Y');
        $selectedYear = $this->cardYear > 0 ? $this->cardYear : $currentYear;
        $currentMonthNum = (int) $now->format('n');
        $todayDate = $now->format('Y-m-d');

        // Dynamically compute recorded database years for global year selector and max range
        $dtsLogsTable = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $minRecordedYear = null;
        $maxRecordedYear = null;
        if (\Illuminate\Support\Facades\Schema::hasTable($dtsLogsTable)) {
            try {
                $yearBounds = \DB::table($dtsLogsTable)
                    ->selectRaw('MIN(EXTRACT(YEAR FROM COALESCE(date_in, date_out)))::int as min_yr, MAX(EXTRACT(YEAR FROM COALESCE(date_in, date_out)))::int as max_yr')
                    ->first();
                if ($yearBounds) {
                    $minRecordedYear = $yearBounds->min_yr ? (int)$yearBounds->min_yr : null;
                    $maxRecordedYear = $yearBounds->max_yr ? (int)$yearBounds->max_yr : null;
                }
            } catch (\Throwable $e) {}
        }

        // If fresh (only current year exists or no records yet), earliest is current year and latest is current year
        $earliestYear = $minRecordedYear ?? $currentYear;
        $latestYear = max($maxRecordedYear ?? $currentYear, $currentYear);
        
        // Available years range from latest recorded/current year down to earliest recorded year
        $availableYears = range($latestYear, $earliestYear);
        if ($this->cardYear > 0 && !in_array($this->cardYear, $availableYears, true)) {
            $availableYears[] = $this->cardYear;
            rsort($availableYears);
        }

        // Action Options from database, strictly excluding 'To be Forwarded to' and 'For Revision'
        $rawActionOptions = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('dts_action_options')) {
            $rawActionOptions = \DB::table('dts_action_options')
                ->whereNotIn(\DB::raw('LOWER(TRIM(option_name))'), ['to be forwarded to', 'for revision'])
                ->orderBy('id', 'asc')
                ->pluck('option_name')
                ->toArray();
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_details')) {
            $distinctDetails = \DB::table('dts_transaction_details')
                ->whereNotNull('action_needed')
                ->where('action_needed', '!=', '')
                ->whereNotIn(\DB::raw('LOWER(TRIM(action_needed))'), ['to be forwarded to', 'for revision'])
                ->distinct()
                ->pluck('action_needed')
                ->toArray();

            foreach ($distinctDetails as $dd) {
                $trimmed = trim($dd);
                if (!in_array($trimmed, $rawActionOptions, true)) {
                    $rawActionOptions[] = $trimmed;
                }
            }
        }

        if (empty($rawActionOptions)) {
            $rawActionOptions = ['For Action', 'For Approval', 'For Signature', 'For Recommendation', 'For Endorsement'];
        }

        $palette = [
            '#0284c7', // Sky Blue
            '#ef4444', // Red
            '#10b981', // Emerald Green
            '#f59e0b', // Amber
            '#8b5cf6', // Violet
            '#ec4899', // Pink
            '#06b6d4', // Cyan
            '#64748b', // Slate
        ];

        $actionOptionColors = [];
        foreach ($rawActionOptions as $idx => $opt) {
            $actionOptionColors[$opt] = $palette[$idx % count($palette)];
        }

        $displayActionOptions = array_slice($rawActionOptions, 0, 4);

        $dtsLogsTable = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $dtsDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_details') ? 'dts_transaction_details' : null;

        // Reusable Card Data Builder Function Supporting 'all' (Year), '1'..'12' (Months), 'week', and 'max'
        $buildCardData = function(string $filter, string $type, ?string $office = null) use ($dtsDetailsTable, $dtsLogsTable, $selectedYear, $earliestYear, $currentYear, $currentMonthNum, $todayDate, $displayActionOptions) {
            $activeOffice = $office ?? ($this->cardOffice ?? 'all');
            $now = \Carbon\Carbon::now();
            if ($filter === 'week') {
                $startDate = $now->copy()->startOfWeek()->format('Y-m-d 00:00:00');
                $endDate = $now->copy()->endOfWeek()->format('Y-m-d 23:59:59');
                $slots = [1, 2, 3, 4, 5, 6, 7];
                $labels = [1 => 'Mon.', 2 => 'Tue.', 3 => 'Wed.', 4 => 'Thu.', 5 => 'Fri.', 6 => 'Sat.', 7 => 'Sun.'];
                $slotKeyFn = fn($d) => (int) \Carbon\Carbon::parse($d)->dayOfWeekIso;
                $rangeSubtext = 'This Week';
            } elseif ($filter === 'max') {
                $startYear = $earliestYear;
                $endYear = max($selectedYear, $earliestYear);
                $startDate = "{$startYear}-01-01 00:00:00";
                $endDate = "{$endYear}-12-31 23:59:59";
                $slots = range($startYear, $endYear);
                $labels = array_combine($slots, array_map('strval', $slots));
                $slotKeyFn = fn($d) => (int) \Carbon\Carbon::parse($d)->format('Y');
                $rangeSubtext = 'All-Time';
            } elseif (is_numeric($filter) && (int)$filter >= 1 && (int)$filter <= 12) {
                $m = (int)$filter;
                $monthCarbon = \Carbon\Carbon::create($selectedYear, $m, 1);
                $startDate = $monthCarbon->copy()->startOfMonth()->format('Y-m-d 00:00:00');
                $endDate = $monthCarbon->copy()->endOfMonth()->format('Y-m-d 23:59:59');
                $slots = [1, 2, 3, 4, 5];
                $labels = [1 => 'Wk 1', 2 => 'Wk 2', 3 => 'Wk 3', 4 => 'Wk 4', 5 => 'Wk 5'];
                $slotKeyFn = function($d) {
                    $day = (int) \Carbon\Carbon::parse($d)->format('j');
                    if ($day <= 7) return 1;
                    if ($day <= 14) return 2;
                    if ($day <= 21) return 3;
                    if ($day <= 28) return 4;
                    return 5;
                };
                $rangeSubtext = $monthCarbon->format('M Y');
            } else { // 'all' (Year)
                $startDate = "{$selectedYear}-01-01 00:00:00";
                $endDate = "{$selectedYear}-12-31 23:59:59";
                $slots = range(1, 12);
                $labels = [
                    1 => 'Jan.', 2 => 'Feb.', 3 => 'Mar.', 4 => 'Apr.',
                    5 => 'May',  6 => 'Jun.', 7 => 'Jul.', 8 => 'Aug.',
                    9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Dec.'
                ];
                $slotKeyFn = fn($d) => (int) \Carbon\Carbon::parse($d)->format('n');
                $rangeSubtext = (string) $selectedYear;
            }

            $slotData = [];
            foreach ($slots as $sk) {
                $slotData[$sk] = array_fill_keys($displayActionOptions, 0);
            }
            $optTotals = array_fill_keys($displayActionOptions, 0);
            $totalCount = 0;
            $todayCount = 0;
            $maxVal = 1;

            if ($dtsDetailsTable && \Illuminate\Support\Facades\Schema::hasTable($dtsLogsTable)) {
                $recCol = \DB::raw('COALESCE(log.date_in, log.date_out)');
                $fwdCol = \DB::raw('COALESCE(log.date_out, log.date_in)');
                $dateCol = $type === 'received' ? $recCol : $fwdCol;

                $logsQuery = \DB::table("{$dtsLogsTable} as log")
                    ->join("{$dtsDetailsTable} as td", 'log.transaction_id', '=', 'td.id')
                    ->where('log.type', $type)
                    ->whereBetween($dateCol, [$startDate, $endDate]);

                if (!empty($activeOffice) && $activeOffice !== 'all') {
                    $logsQuery->where(function($q) use ($activeOffice) {
                        $lc = strtolower($activeOffice);
                        $q->whereRaw('LOWER(log.office_code) = ?', [$lc])
                          ->orWhereRaw('LOWER(td.originated_from) = ?', [$lc])
                          ->orWhereRaw('LOWER(td.current_office_hold) = ?', [$lc]);
                    });
                }

                $logs = $logsQuery->select([
                    \DB::raw('COALESCE(log.date_in, log.date_out) as log_date'),
                    'td.action_needed',
                    'log.transaction_id',
                ])
                ->get();

                $seen = [];
                foreach ($logs as $r) {
                    $act = trim((string)($r->action_needed ?? ''));
                    if (in_array(strtolower($act), ['to be forwarded to', 'for revision'], true)) {
                        continue;
                    }
                    if ($type === 'completed') {
                        if (isset($seen[$r->transaction_id])) continue;
                        $seen[$r->transaction_id] = true;
                    }

                    $matched = null;
                    foreach ($displayActionOptions as $opt) {
                        if (strcasecmp($opt, $act) === 0) {
                            $matched = $opt;
                            break;
                        }
                    }
                    $matched = $matched ?: ($displayActionOptions[0] ?? null);

                    $sk = $slotKeyFn($r->log_date);
                    if ($matched && isset($slotData[$sk])) {
                        $slotData[$sk][$matched] = ($slotData[$sk][$matched] ?? 0) + 1;
                        $optTotals[$matched] = ($optTotals[$matched] ?? 0) + 1;
                    }
                    $totalCount++;

                    $parsedC = \Carbon\Carbon::parse($r->log_date);
                    if ($parsedC->format('Y-m-d') === $todayDate) $todayCount++;
                }
            }

            foreach ($slots as $sk) {
                foreach ($displayActionOptions as $opt) {
                    if (($slotData[$sk][$opt] ?? 0) > $maxVal) {
                        $maxVal = $slotData[$sk][$opt];
                    }
                }
            }

            if ($filter === 'week') {
                $badgeText = number_format($todayCount) . ' Today';
            } elseif (is_numeric($filter)) {
                $badgeText = number_format($totalCount) . ' In Month';
            } elseif ($filter === 'max') {
                $badgeText = number_format($totalCount) . ' Total';
            } else {
                $badgeText = number_format($totalCount) . ($selectedYear === $currentYear ? ' This Year' : " in {$selectedYear}");
            }

            return [
                'filter' => $filter,
                'office' => $activeOffice,
                'slots' => $slots,
                'labels' => $labels,
                'slotData' => $slotData,
                'optTotals' => $optTotals,
                'totalCount' => $totalCount,
                'maxVal' => max(4, $maxVal),
                'rangeSubtext' => $rangeSubtext,
                'badgeText' => $badgeText,
            ];
        };

        // Reusable Card Data Builder Function for RDP Subsystem (Created, Approved, Disposed)
        $buildRdpCardData = function(string $filter, string $category, ?string $office = null) use ($selectedYear, $earliestYear, $currentYear, $currentMonthNum, $todayDate) {
            $activeOffice = $office ?? ($this->cardOffice ?? 'all');
            $now = \Carbon\Carbon::now();
            if ($filter === 'week') {
                $startDate = $now->copy()->startOfWeek()->format('Y-m-d 00:00:00');
                $endDate = $now->copy()->endOfWeek()->format('Y-m-d 23:59:59');
                $slots = [1, 2, 3, 4, 5, 6, 7];
                $labels = [1 => 'Mon.', 2 => 'Tue.', 3 => 'Wed.', 4 => 'Thu.', 5 => 'Fri.', 6 => 'Sat.', 7 => 'Sun.'];
                $slotKeyFn = fn($d) => (int) \Carbon\Carbon::parse($d)->dayOfWeekIso;
                $rangeSubtext = 'This Week';
            } elseif ($filter === 'max') {
                $startYear = $earliestYear;
                $endYear = max($selectedYear, $earliestYear);
                $startDate = "{$startYear}-01-01 00:00:00";
                $endDate = "{$endYear}-12-31 23:59:59";
                $slots = range($startYear, $endYear);
                $labels = array_combine($slots, array_map('strval', $slots));
                $slotKeyFn = fn($d) => (int) \Carbon\Carbon::parse($d)->format('Y');
                $rangeSubtext = 'All-Time';
            } elseif (is_numeric($filter) && (int)$filter >= 1 && (int)$filter <= 12) {
                $m = (int)$filter;
                $monthCarbon = \Carbon\Carbon::create($selectedYear, $m, 1);
                $startDate = $monthCarbon->copy()->startOfMonth()->format('Y-m-d 00:00:00');
                $endDate = $monthCarbon->copy()->endOfMonth()->format('Y-m-d 23:59:59');
                $slots = [1, 2, 3, 4, 5];
                $labels = [1 => 'Wk 1', 2 => 'Wk 2', 3 => 'Wk 3', 4 => 'Wk 4', 5 => 'Wk 5'];
                $slotKeyFn = function($d) {
                    $day = (int) \Carbon\Carbon::parse($d)->format('j');
                    if ($day <= 7) return 1;
                    if ($day <= 14) return 2;
                    if ($day <= 21) return 3;
                    if ($day <= 28) return 4;
                    return 5;
                };
                $rangeSubtext = $monthCarbon->format('M Y');
            } else { // 'all' (Year)
                $startDate = "{$selectedYear}-01-01 00:00:00";
                $endDate = "{$selectedYear}-12-31 23:59:59";
                $slots = range(1, 12);
                $labels = [
                    1 => 'Jan.', 2 => 'Feb.', 3 => 'Mar.', 4 => 'Apr.',
                    5 => 'May',  6 => 'Jun.', 7 => 'Jul.', 8 => 'Aug.',
                    9 => 'Sept.', 10 => 'Oct.', 11 => 'Nov.', 12 => 'Dec.'
                ];
                $slotKeyFn = fn($d) => (int) \Carbon\Carbon::parse($d)->format('n');
                $rangeSubtext = (string) $selectedYear;
            }

            if ($category === 'disposed') {
                $displayOptions = ['Disposed Records', 'Approved for Disposal', 'Pending Disposal'];
                $colors = [
                    'Disposed Records' => '#ef4444',
                    'Approved for Disposal' => '#10b981',
                    'Pending Disposal' => '#f59e0b',
                ];
            } else {
                $displayOptions = ['NAP Form 1', 'NAP Form 2', 'NAP Form 3'];
                $colors = [
                    'NAP Form 1' => '#0284c7',
                    'NAP Form 2' => '#10b981',
                    'NAP Form 3' => '#8b5cf6',
                ];
            }

            $slotData = [];
            foreach ($slots as $sk) {
                $slotData[$sk] = array_fill_keys($displayOptions, 0);
            }
            $optTotals = array_fill_keys($displayOptions, 0);
            $totalCount = 0;
            $todayCount = 0;
            $maxVal = 1;

            if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record')) {
                if ($category === 'created') {
                    // NAP Form 1: Inventory & Appraisal
                    $q1 = \DB::table('rdp_record')
                        ->where('transferred_to_nap3', false)
                        ->whereBetween('created_at', [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $q1->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($q1->select(['created_at'])->get() as $row) {
                        $sk = $slotKeyFn($row->created_at);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['NAP Form 1']++;
                            $optTotals['NAP Form 1']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->created_at)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }

                    // NAP Form 2: Series / Disposition Schedule
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series')) {
                        $q2 = \DB::table('rdp_record_series')
                            ->whereBetween('created_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $q2->whereRaw('LOWER(recorded_at_office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($q2->select(['created_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->created_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['NAP Form 2']++;
                                $optTotals['NAP Form 2']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->created_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }

                    // NAP Form 3: Disposal requests
                    $q3 = \DB::table('rdp_record')
                        ->where('transferred_to_nap3', true)
                        ->whereBetween(\DB::raw('COALESCE(transferred_to_nap3_at, created_at)'), [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $q3->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($q3->select([\DB::raw('COALESCE(transferred_to_nap3_at, created_at) as log_date')])->get() as $row) {
                        $sk = $slotKeyFn($row->log_date);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['NAP Form 3']++;
                            $optTotals['NAP Form 3']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->log_date)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_pending_record')) {
                        $qp3 = \DB::table('rdp_pending_record')
                            ->where('is_for_nap_three', true)
                            ->whereBetween('created_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $qp3->whereRaw('LOWER(office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($qp3->select(['created_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->created_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['NAP Form 3']++;
                                $optTotals['NAP Form 3']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->created_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }
                } elseif ($category === 'approved') {
                    // NAP Form 1 Approved
                    $q1 = \DB::table('rdp_record')
                        ->where('is_verified', true)
                        ->where('transferred_to_nap3', false)
                        ->whereBetween(\DB::raw('COALESCE(action_date, updated_at)'), [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $q1->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($q1->select([\DB::raw('COALESCE(action_date, updated_at) as log_date')])->get() as $row) {
                        $sk = $slotKeyFn($row->log_date);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['NAP Form 1']++;
                            $optTotals['NAP Form 1']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->log_date)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_pending_record')) {
                        $qp1 = \DB::table('rdp_pending_record')
                            ->where('is_for_nap_one', true)
                            ->where('status_id', 2)
                            ->whereBetween('updated_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $qp1->whereRaw('LOWER(office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($qp1->select(['updated_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->updated_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['NAP Form 1']++;
                                $optTotals['NAP Form 1']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }

                    // NAP Form 2 Approved
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series')) {
                        $q2 = \DB::table('rdp_record_series')
                            ->where('is_verified', true)
                            ->whereBetween('updated_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $q2->whereRaw('LOWER(recorded_at_office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($q2->select(['updated_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->updated_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['NAP Form 2']++;
                                $optTotals['NAP Form 2']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_pending_record_series')) {
                        $qp2 = \DB::table('rdp_pending_record_series')
                            ->where('status_id', 2)
                            ->whereBetween('updated_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $qp2->whereRaw('LOWER(office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($qp2->select(['updated_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->updated_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['NAP Form 2']++;
                                $optTotals['NAP Form 2']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }

                    // NAP Form 3 Approved
                    $q3 = \DB::table('rdp_record')
                        ->where('transferred_to_nap3', true)
                        ->where('is_verified', true)
                        ->whereBetween('updated_at', [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $q3->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($q3->select(['updated_at'])->get() as $row) {
                        $sk = $slotKeyFn($row->updated_at);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['NAP Form 3']++;
                            $optTotals['NAP Form 3']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_pending_record')) {
                        $qp3 = \DB::table('rdp_pending_record')
                            ->where('is_for_nap_three', true)
                            ->where('status_id', 2)
                            ->whereBetween('updated_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $qp3->whereRaw('LOWER(office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($qp3->select(['updated_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->updated_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['NAP Form 3']++;
                                $optTotals['NAP Form 3']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }
                } elseif ($category === 'disposed') {
                    // NAP Form 3 Disposed Records
                    $qDisposed = \DB::table('rdp_record')
                        ->where('transferred_to_nap3', true)
                        ->where(function($w) {
                            $w->whereNotNull('action_date')
                              ->orWhere('is_active', false);
                        })
                        ->whereBetween(\DB::raw('COALESCE(action_date, updated_at)'), [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $qDisposed->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($qDisposed->select([\DB::raw('COALESCE(action_date, updated_at) as log_date')])->get() as $row) {
                        $sk = $slotKeyFn($row->log_date);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['Disposed Records']++;
                            $optTotals['Disposed Records']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->log_date)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }

                    // Approved for Disposal
                    $qAppr = \DB::table('rdp_record')
                        ->where('transferred_to_nap3', true)
                        ->where('is_verified', true)
                        ->whereNull('action_date')
                        ->whereBetween('updated_at', [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $qAppr->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($qAppr->select(['updated_at'])->get() as $row) {
                        $sk = $slotKeyFn($row->updated_at);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['Approved for Disposal']++;
                            $optTotals['Approved for Disposal']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_pending_record')) {
                        $qpAppr = \DB::table('rdp_pending_record')
                            ->where('is_for_nap_three', true)
                            ->where('status_id', 2)
                            ->whereBetween('updated_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $qpAppr->whereRaw('LOWER(office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($qpAppr->select(['updated_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->updated_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['Approved for Disposal']++;
                                $optTotals['Approved for Disposal']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->updated_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }

                    // Pending Disposal
                    $qPend = \DB::table('rdp_record')
                        ->where('transferred_to_nap3', true)
                        ->where('is_verified', false)
                        ->whereBetween(\DB::raw('COALESCE(transferred_to_nap3_at, created_at)'), [$startDate, $endDate]);
                    if (!empty($activeOffice) && $activeOffice !== 'all') {
                        $qPend->whereRaw('LOWER(office_own) = ?', [strtolower($activeOffice)]);
                    }
                    foreach ($qPend->select([\DB::raw('COALESCE(transferred_to_nap3_at, created_at) as log_date')])->get() as $row) {
                        $sk = $slotKeyFn($row->log_date);
                        if (isset($slotData[$sk])) {
                            $slotData[$sk]['Pending Disposal']++;
                            $optTotals['Pending Disposal']++;
                            $totalCount++;
                            if (\Carbon\Carbon::parse($row->log_date)->format('Y-m-d') === $todayDate) $todayCount++;
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('rdp_pending_record')) {
                        $qpPend = \DB::table('rdp_pending_record')
                            ->where('is_for_nap_three', true)
                            ->where('status_id', 1)
                            ->whereBetween('created_at', [$startDate, $endDate]);
                        if (!empty($activeOffice) && $activeOffice !== 'all') {
                            $qpPend->whereRaw('LOWER(office) = ?', [strtolower($activeOffice)]);
                        }
                        foreach ($qpPend->select(['created_at'])->get() as $row) {
                            $sk = $slotKeyFn($row->created_at);
                            if (isset($slotData[$sk])) {
                                $slotData[$sk]['Pending Disposal']++;
                                $optTotals['Pending Disposal']++;
                                $totalCount++;
                                if (\Carbon\Carbon::parse($row->created_at)->format('Y-m-d') === $todayDate) $todayCount++;
                            }
                        }
                    }
                }
            }

            foreach ($slots as $sk) {
                foreach ($displayOptions as $opt) {
                    if (($slotData[$sk][$opt] ?? 0) > $maxVal) {
                        $maxVal = $slotData[$sk][$opt];
                    }
                }
            }

            if ($filter === 'week') {
                $badgeText = number_format($todayCount) . ' Today';
            } elseif (is_numeric($filter)) {
                $badgeText = number_format($totalCount) . ' In Month';
            } elseif ($filter === 'max') {
                $badgeText = number_format($totalCount) . ' Total';
            } else {
                $badgeText = number_format($totalCount) . ($selectedYear === $currentYear ? ' This Year' : " in {$selectedYear}");
            }

            return [
                'filter' => $filter,
                'office' => $activeOffice,
                'slots' => $slots,
                'labels' => $labels,
                'slotData' => $slotData,
                'optTotals' => $optTotals,
                'totalCount' => $totalCount,
                'maxVal' => max(4, $maxVal),
                'rangeSubtext' => $rangeSubtext,
                'badgeText' => $badgeText,
                'displayOptions' => $displayOptions,
                'colors' => $colors,
            ];
        };

        $curMonthStr = (string) \Carbon\Carbon::now()->format('n');
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? (auth()->user()?->details?->office_id ? \DB::table($officeTable)->where('id', auth()->user()->details->office_id)->value('office_code') : null);
        $selectedOffice = !empty($this->cardOffice) ? $this->cardOffice : ($userOfficeCode ?? 'all');

        $recData = $buildCardData($this->recFilter ?: $curMonthStr, 'received', $selectedOffice);
        $fwdData = $buildCardData($this->fwdFilter ?: $curMonthStr, 'forwarded', $selectedOffice);
        $compData = $buildCardData($this->compFilter ?: $curMonthStr, 'completed', $selectedOffice);
        $rdpCreatedData = $buildRdpCardData($this->rdpCreatedFilter ?: $curMonthStr, 'created', $selectedOffice);
        $rdpApprovedData = $buildRdpCardData($this->rdpApprovedFilter ?: $curMonthStr, 'approved', $selectedOffice);
        $rdpDisposedData = $buildRdpCardData($this->rdpDisposedFilter ?: $curMonthStr, 'disposed', $selectedOffice);

        // Offices list for dropdown filter (Query active offices from sys_office/office)
        $officesList = [];
        if (\Illuminate\Support\Facades\Schema::hasTable($officeTable)) {
            $officesList = \DB::table($officeTable)
                ->select(['office_code', 'office_name'])
                ->where('is_active', true)
                ->orderBy('office_name', 'asc')
                ->get();
        }

        // Active Modal Filtered Data & Detailed Transaction Records
        $activeModalType = in_array($this->modalCard ?? 'received', ['received', 'forwarded', 'completed', 'rdp_created', 'rdp_approved', 'rdp_disposed'], true)
            ? $this->modalCard
            : 'received';
        
        $activeModalOffice = !empty($this->modalOffice) ? $this->modalOffice : $selectedOffice;
        
        if (in_array($activeModalType, ['rdp_created', 'rdp_approved', 'rdp_disposed'], true)) {
            $modalRdpCategory = str_replace('rdp_', '', $activeModalType);
            $modalActiveData = $buildRdpCardData($this->modalRange ?: $curMonthStr, $modalRdpCategory, $activeModalOffice);
            $modalFilterOptions = $modalActiveData['displayOptions'];
        } else {
            $modalActiveData = $buildCardData($this->modalRange ?: $curMonthStr, $activeModalType, $activeModalOffice);
            $modalFilterOptions = $rawActionOptions;
        }

        // Fetch Detailed Transactions matching current modal filters
        $modalRecords = [];
        $mNow = \Carbon\Carbon::now();
        $mFilter = $this->modalRange ?: $curMonthStr;
        if ($mFilter === 'week') {
            $mStart = $mNow->copy()->startOfWeek()->format('Y-m-d 00:00:00');
            $mEnd = $mNow->copy()->endOfWeek()->format('Y-m-d 23:59:59');
        } elseif ($mFilter === 'max') {
            $mStartYear = $earliestYear;
            $mEndYear = max($selectedYear, $earliestYear);
            $mStart = "{$mStartYear}-01-01 00:00:00";
            $mEnd = "{$mEndYear}-12-31 23:59:59";
        } elseif (is_numeric($mFilter) && (int)$mFilter >= 1 && (int)$mFilter <= 12) {
            $mMonth = \Carbon\Carbon::create($selectedYear, (int)$mFilter, 1);
            $mStart = $mMonth->copy()->startOfMonth()->format('Y-m-d 00:00:00');
            $mEnd = $mMonth->copy()->endOfMonth()->format('Y-m-d 23:59:59');
        } else {
            $mStart = "{$selectedYear}-01-01 00:00:00";
            $mEnd = "{$selectedYear}-12-31 23:59:59";
        }

        if (in_array($activeModalType, ['rdp_created', 'rdp_approved', 'rdp_disposed'], true)) {
            if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record')) {
                $rdpQuery = \DB::table('rdp_record as r')
                    ->leftJoin('rdp_record_series as rs', 'r.record_series_id', '=', 'rs.id')
                    ->whereBetween('r.created_at', [$mStart, $mEnd]);

                if ($activeModalType === 'rdp_created') {
                    if (($this->modalAction ?? 'all') === 'NAP Form 1') {
                        $rdpQuery->where('r.transferred_to_nap3', false);
                    } elseif (($this->modalAction ?? 'all') === 'NAP Form 3') {
                        $rdpQuery->where('r.transferred_to_nap3', true);
                    }
                } elseif ($activeModalType === 'rdp_approved') {
                    $rdpQuery->where('r.is_verified', true);
                    if (($this->modalAction ?? 'all') === 'NAP Form 1') {
                        $rdpQuery->where('r.transferred_to_nap3', false);
                    } elseif (($this->modalAction ?? 'all') === 'NAP Form 3') {
                        $rdpQuery->where('r.transferred_to_nap3', true);
                    }
                } elseif ($activeModalType === 'rdp_disposed') {
                    $rdpQuery->where('r.transferred_to_nap3', true);
                    if (($this->modalAction ?? 'all') === 'Approved for Disposal') {
                        $rdpQuery->where('r.is_verified', true)->whereNull('r.action_date');
                    } elseif (($this->modalAction ?? 'all') === 'Disposed Records') {
                        $rdpQuery->where(function($w) { $w->whereNotNull('r.action_date')->orWhere('r.is_active', false); });
                    } elseif (($this->modalAction ?? 'all') === 'Pending Disposal') {
                        $rdpQuery->where('r.is_verified', false);
                    }
                }

                if ($activeModalOffice !== 'all') {
                    $rdpQuery->whereRaw('LOWER(r.office_own) = ?', [strtolower($activeModalOffice)]);
                }

                if (!empty($this->modalSearch)) {
                    $term = '%' . strtolower(trim($this->modalSearch)) . '%';
                    $rdpQuery->where(function($q) use ($term) {
                        $q->whereRaw("LOWER(COALESCE(r.description, '')) LIKE ?", [$term])
                          ->orWhereRaw("LOWER(COALESCE(rs.series_title, '')) LIKE ?", [$term])
                          ->orWhereRaw("LOWER(COALESCE(r.office_own, '')) LIKE ?", [$term]);
                    });
                }

                $modalRecords = $rdpQuery->select([
                    'r.id',
                    \DB::raw("CASE WHEN r.transferred_to_nap3 = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as control_number"),
                    \DB::raw("COALESCE(r.description, rs.series_title, 'Inventory Record') as subject"),
                    \DB::raw("CASE WHEN r.transferred_to_nap3 = true THEN 'Disposal of Records' ELSE 'Records Inventory & Appraisal' END as action_needed"),
                    \DB::raw("CASE WHEN r.is_verified = true THEN 'Approved' ELSE 'Pending' END as status"),
                    \DB::raw("COALESCE(r.office_own, 'General') as originated_from"),
                    \DB::raw("COALESCE(r.office_own, 'General') as current_office_hold"),
                    'r.created_at as logged_date',
                ])
                ->orderBy('logged_date', 'desc')
                ->limit(100)
                ->get();
            }
        } elseif ($dtsDetailsTable && \Illuminate\Support\Facades\Schema::hasTable($dtsLogsTable)) {

            $dateColRaw = $activeModalType === 'received' 
                ? 'COALESCE(log.date_in, log.date_out)' 
                : 'COALESCE(log.date_out, log.date_in)';

            $recordsQuery = \DB::table("{$dtsLogsTable} as log")
                ->join("{$dtsDetailsTable} as td", 'log.transaction_id', '=', 'td.id')
                ->where('log.type', $activeModalType)
                ->whereBetween(\DB::raw($dateColRaw), [$mStart, $mEnd]);

            if (($this->modalAction ?? 'all') !== 'all') {
                $recordsQuery->where('td.action_needed', $this->modalAction);
            }

            if ($activeModalOffice !== 'all') {
                $offCode = $activeModalOffice;
                $recordsQuery->where(function($q) use ($offCode) {
                    $lc = strtolower($offCode);
                    $q->whereRaw('LOWER(td.originated_from) = ?', [$lc])
                      ->orWhereRaw('LOWER(td.current_office_hold) = ?', [$lc])
                      ->orWhereRaw('LOWER(log.office_code) = ?', [$lc]);
                });
            }

            if (!empty($this->modalSearch)) {
                $term = '%' . strtolower(trim($this->modalSearch)) . '%';
                $recordsQuery->where(function($q) use ($term) {
                    $q->whereRaw("LOWER(COALESCE(td.control_number, '')) LIKE ?", [$term])
                      ->orWhereRaw("LOWER(COALESCE(td.subject, '')) LIKE ?", [$term])
                      ->orWhereRaw("LOWER(COALESCE(td.action_needed, '')) LIKE ?", [$term])
                      ->orWhereRaw("LOWER(COALESCE(td.originated_from, '')) LIKE ?", [$term])
                      ->orWhereRaw("LOWER(COALESCE(td.current_office_hold, '')) LIKE ?", [$term]);
                });
            }

            $modalRecords = $recordsQuery->select([
                'td.id',
                'td.control_number',
                'td.subject',
                'td.action_needed',
                'td.status',
                'td.originated_from',
                'td.current_office_hold',
                \DB::raw("{$dateColRaw} as logged_date"),
            ])
            ->orderBy('logged_date', 'desc')
            ->limit(100)
            ->get();
        }

        // Legacy compatibility variables for tests and cards
        $totalReceivedCount = $recData['totalCount'];
        $totalForwardedCount = $fwdData['totalCount'];
        $totalCompletedCount = $compData['totalCount'];
        $recBadgeText = $recData['badgeText'];
        $fwdBadgeText = $fwdData['badgeText'];
        $compBadgeText = $compData['badgeText'];
        $rangeSubtextPrefix = $recData['rangeSubtext'];
        $receivedSlotData = $recData['slotData'];
        $forwardedSlotData = $fwdData['slotData'];
        $completedSlotData = $compData['slotData'];
        $maxReceivedVal = $recData['maxVal'];
        $maxForwardedVal = $fwdData['maxVal'];
        $maxCompletedVal = $compData['maxVal'];
        $chartSlots = $recData['slots'];
        $chartLabels = $recData['labels'];

        return [
            'cardOffice' => $selectedOffice,
            'selectedOffice' => $selectedOffice,
            'userOfficeCode' => $userOfficeCode,
            'currentMonthNum' => $currentMonthNum,
            'officesList' => $officesList,
            'rawActionOptions' => $rawActionOptions,
            'modalActiveData' => $modalActiveData,
            'modalRecords' => $modalRecords,
            'recData' => $recData,
            'fwdData' => $fwdData,
            'compData' => $compData,
            'rdpCreatedData' => $rdpCreatedData,
            'rdpApprovedData' => $rdpApprovedData,
            'rdpDisposedData' => $rdpDisposedData,
            'rdpCreatedFilter' => $this->rdpCreatedFilter ?? 'all',
            'rdpApprovedFilter' => $this->rdpApprovedFilter ?? 'all',
            'rdpDisposedFilter' => $this->rdpDisposedFilter ?? 'all',
            'modalFilterOptions' => $modalFilterOptions,
            'recFilter' => $this->recFilter ?? 'all',
            'fwdFilter' => $this->fwdFilter ?? 'all',
            'compFilter' => $this->compFilter ?? 'all',
            'timeFilter' => $timeFilter,
            'chartSlots' => $chartSlots,
            'chartLabels' => $chartLabels,
            'rangeSubtextPrefix' => $rangeSubtextPrefix,
            'recBadgeText' => $recBadgeText,
            'fwdBadgeText' => $fwdBadgeText,
            'compBadgeText' => $compBadgeText,
            'currentYear' => $currentYear,
            'selectedYear' => $selectedYear,
            'availableYears' => $availableYears,
            'cardYear' => $selectedYear,
            'displayActionOptions' => $displayActionOptions,
            'actionOptionColors' => $actionOptionColors,
            'receivedSlotData' => $receivedSlotData,
            'forwardedSlotData' => $forwardedSlotData,
            'completedSlotData' => $completedSlotData,
            'maxReceivedVal' => $maxReceivedVal,
            'maxForwardedVal' => $maxForwardedVal,
            'maxCompletedVal' => $maxCompletedVal,
            'totalReceivedCount' => $totalReceivedCount,
            'totalForwardedCount' => $totalForwardedCount,
            'totalCompletedCount' => $totalCompletedCount,
            'openErrorTickets' => $openErrorTickets,
            'criticalErrorTickets' => $criticalErrorTickets,
            'recentLoginsCount' => $recentLoginsCount,
            'loginPoints' => $loginPoints,
            'minuteChange' => $minuteChange,
            'currentSessionLevel' => $currentSessionLevel,
            'latestActivityTimestamp' => $latestActivityTimestamp,
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'inactiveUsers' => $inactiveUsers,
            'activeUserPercent' => $activeUserPercent,
            'totalSubsystems' => $totalSubsystems,
            'activeSubsystems' => $activeSubsystems,
            'coreSubsystemLabels' => $coreSubsystemLabels,
            'backupCount' => $backupCount,
            'lastBackupLabel' => $lastBackupLabel,
            'backupFrequency' => $backupFrequency,
            'recycleCount' => $recycleCount,
            'recycleOffices' => $recycleOffices,
            'recycleRoles' => $recycleRoles,
            'recycleFlows' => $recycleFlows,
            'recycleSeries' => $recycleSeries,
            'cpuUsage' => $cpuUsage,
            'ramUsage' => $ramUsage,
            'diskWriteRate' => $diskWriteRate,
            'ramUsedGb' => $ramUsedGb,
            'ramTotalGb' => $ramTotalGb,
            'cores' => $cores,
            'cpuPoints' => $cpuPoints,
            'ramPoints' => $ramPoints,
            'writePoints' => $writePoints,
            'totalActivities' => $totalActivities,
            'activitySparkline' => $activitySparkline,
            'activities' => $activities,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/console_dashboard.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="dashboard-wrapper"
     wire:poll.5s
     x-data="{ 
         showActivities: localStorage.getItem('rms-admin-dashboard-activities') !== 'false',
         latestTimestamp: {{ $latestActivityTimestamp }},
         lastSeenTimestamp: parseInt(localStorage.getItem('rms-admin-last-seen-activity') || '0', 10),
         hasNewActivities: false,
          expandedCard: null,
          openExpand(cardKey) {
              this.expandedCard = cardKey;
              document.body.style.overflow = 'hidden';
          },
          closeExpand() {
              this.expandedCard = null;
              document.body.style.overflow = '';
          },

         init() {
             if (!localStorage.getItem('rms-admin-last-seen-activity')) {
                 this.lastSeenTimestamp = this.latestTimestamp;
                 localStorage.setItem('rms-admin-last-seen-activity', this.lastSeenTimestamp);
             }
             this.checkUpdates();
         },

         checkUpdates() {
             if (!this.showActivities && this.latestTimestamp > this.lastSeenTimestamp) {
                 this.hasNewActivities = true;
             } else {
                 this.hasNewActivities = false;
             }
         },

         toggleActivities() {
             this.showActivities = !this.showActivities;
             localStorage.setItem('rms-admin-dashboard-activities', this.showActivities);
             this.markAsSeen();
             this.$nextTick(() => {
                 window.dispatchEvent(new Event('resize'));
             });
         },

         markAsSeen() {
             this.lastSeenTimestamp = this.latestTimestamp;
             localStorage.setItem('rms-admin-last-seen-activity', this.lastSeenTimestamp);
             this.hasNewActivities = false;
         },

         onLatestPolled(newTs) {
             const parsed = parseInt(newTs, 10);
             if (!isNaN(parsed) && parsed > 0) {
                 this.latestTimestamp = parsed;
             }
             if (this.showActivities) {
                 this.markAsSeen();
             } else {
                 this.checkUpdates();
             }
         }
     }"
     @rms-activity-update.window="onLatestPolled($event.detail.timestamp)">
    
    <!-- Hidden DOM tracker for real-time Livewire morph sync -->
    <span id="rms-latest-activity-marker" 
          data-timestamp="{{ $latestActivityTimestamp }}" 
          style="display:none;" 
          x-effect="onLatestPolled({{ $latestActivityTimestamp }})"></span>

    <!-- Dashboard Header Row -->
    <div class="dashboard-header-row">
        <div>
            <h1 class="dashboard-title">Dashboard</h1>
            <p class="dashboard-subtitle">Real-time telemetry, core metrics and administration overview for RMS.</p>
        </div>
        
        <div class="dashboard-header-controls">
            <!-- Global Office Scope Filter (Defaults to User's Office, e.g., RFOIU) -->
            <div class="header-office-filter-wrapper" title="Filter dashboard telemetry by office (Defaults to your office)">
                <i class="fa-solid fa-building header-office-icon"></i>
                <select wire:model.live="cardOffice" class="header-office-select" aria-label="Scope dashboard data by office">
                    <option value="all">All Offices</option>
                    @foreach($officesList as $off)
                        <option value="{{ $off->office_code }}">
                            {{ $off->office_code }}{{ !empty($off->office_name) && $off->office_name !== $off->office_code ? ' - ' . Str::limit($off->office_name, 22) : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Global Year Scope Filter -->
            <div class="header-year-filter-wrapper" title="Scope dashboard telemetry by year">
                <i class="fa-regular fa-calendar-days header-year-icon"></i>
                <select wire:model.live="cardYear" class="header-year-select" aria-label="Scope dashboard data by year">
                    @foreach($availableYears as $yr)
                        <option value="{{ $yr }}">{{ $yr }}{{ $yr === $currentYear ? ' (Current)' : '' }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Toggle Button for Hidden Mode (Image 2 style: red dot ONLY pulses if there is a new update) -->
            <button type="button" 
                    x-show="!showActivities" 
                    x-cloak 
                    @click="toggleActivities()" 
                    class="btn-show-activities"
                    title="Display Recent Activities panel">
                <span class="show-dot" x-show="hasNewActivities" x-cloak title="New activities have occurred"></span>
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Show Recent Activities</span>
            </button>
        </div>
    </div>

    <!-- Master Layout: Primary Section (Left) & Sidebar (Right) -->
    <div class="dashboard-main-layout" :class="{ 'with-activities': showActivities, 'activities-hidden': !showActivities }">
        
        <!-- Primary Left Section: 6 Graph Boxes + Quick Management Actions -->
        <div class="dashboard-primary-section">
            
            <!-- Statistics & Graph Grid (Red boxes in mockup: 2x3 when shown, 3x2 when hidden) -->
            <div class="stats-grid" :class="showActivities ? 'cols-2' : 'cols-3'">
                
                <!-- Card 1: Received Transactions (Grouped Bar Chart) -->
                <div class="stat-card graph-card">
                    <!-- Orange Subsystem Tab (Mockup style) -->
                    <div class="card-subsystem-tab">
                        <i class="fa-solid fa-folder-tree"></i> DTS Subsystem
                    </div>

                    <!-- Purple Expand Tab (Upscale Modal trigger) -->
                    <button type="button" 
                            class="card-expand-tab" 
                            wire:click="openModalWithCard('received')" @click="openExpand('received')" 
                            title="Expand to upscale detail view">
                        <i class="fa-solid fa-up-right-and-down-left-from-center"></i> EXPND
                    </button>

                    <!-- Top Row: Description & Filter (Left), Icon (Right) -->
                    <div class="card-top">
                        <div class="stat-info">
                            <span class="stat-label">Received Transactions <span class="time-range-tag">{{ strtoupper($timeFilter) }}</span></span>

                            <!-- Green Dropdown Filter: Placed below Description -->
                            <div class="card-filter-wrapper">
                                <select wire:model.live="recFilter" class="card-dropdown-filter" aria-label="Filter Received Transactions by month">
                                    <option value="all">All Months ({{ $selectedYear }})</option>
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                    <option value="week">Current Week</option>
                                    <option value="max">All-Time (Max)</option>
                                </select>
                            </div>
                        </div>

                        <div class="card-top-right">
                            <div class="stat-icon-wrapper dts-rec-card-icon">
                                <i class="fa-solid fa-inbox" style="font-size: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-chart-legend">
                        @foreach($displayActionOptions as $opt)
                            <span class="legend-chip" title="{{ $opt }}">
                                <i class="chip-dot" style="background: {{ $actionOptionColors[$opt] ?? '#0284c7' }};"></i>
                                {{ Str::limit($opt, 14) }}
                            </span>
                        @endforeach
                    </div>

                    <!-- Stable Proportional Grouped Bar Chart -->
                    <div class="chart-container">
                        @php
                            $optCount = max(1, count($displayActionOptions));
                            $cSlots = $recData['slots'];
                            $slotCount = max(1, count($cSlots));
                            $axisGutter = 28;
                            $usableW = 380 - $axisGutter - 6;
                            $slotW = $usableW / $slotCount;
                            
                            if ($slotCount <= 5) {
                                $barW = $optCount <= 2 ? 18.0 : ($optCount == 3 ? 13.0 : 10.0);
                                $barGap = 2.0;
                            } elseif ($slotCount <= 7) {
                                $barW = $optCount <= 2 ? 14.0 : ($optCount == 3 ? 10.0 : 7.8);
                                $barGap = 1.5;
                            } else {
                                $barW = $optCount <= 2 ? 8.5 : ($optCount == 3 ? 6.2 : 4.8);
                                $barGap = 1.0;
                            }
                            $groupW = ($optCount * $barW) + (($optCount - 1) * $barGap);
                            $maxV = $recData['maxVal'];
                        @endphp
                        <svg class="graph-svg" viewBox="0 0 380 140" preserveAspectRatio="xMidYMid meet">
                            <!-- Guide lines: 100%, 50%, 0% -->
                            <text x="24" y="21" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ $maxV }}</text>
                            <line x1="{{ $axisGutter }}" y1="18" x2="376" y2="18" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="69" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ round($maxV * 0.5) }}</text>
                            <line x1="{{ $axisGutter }}" y1="66" x2="376" y2="66" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="117" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">0</text>
                            <line x1="{{ $axisGutter }}" y1="114" x2="376" y2="114" stroke="#cbd5e1" stroke-width="1.2" />

                            @foreach($cSlots as $slotKey)
                                @php
                                    $slotCenter = $axisGutter + (($loop->index) * $slotW) + ($slotW / 2);
                                    $startX = $slotCenter - ($groupW / 2);
                                    $slotLabel = $recData['labels'][$slotKey] ?? $slotKey;
                                @endphp
                                @foreach($displayActionOptions as $oIdx => $opt)
                                    @php
                                        $val = $recData['slotData'][$slotKey][$opt] ?? 0;
                                        $barH = $maxV > 0 && $val > 0 ? max(4, round(($val / $maxV) * 96)) : 0;
                                        $bx = round($startX + ($oIdx * ($barW + $barGap)), 1);
                                        $by = 114 - $barH;
                                        $color = $actionOptionColors[$opt] ?? '#0284c7';
                                    @endphp
                                    @if($barH > 0)
                                        <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $barW }}" height="{{ $barH }}" rx="1.5" fill="{{ $color }}">
                                            <title>{{ $slotLabel }} - {{ $opt }}: {{ $val }}</title>
                                        </rect>
                                    @endif
                                @endforeach
                                <text x="{{ $slotCenter }}" y="132" text-anchor="middle" font-size="8.5" font-weight="700" fill="#64748b">{{ $slotLabel }}</text>
                            @endforeach
                        </svg>
                    </div>

                    <div class="card-bottom">
                        <span class="trend-badge positive">
                            <i class="fa-solid fa-inbox"></i> {{ $recData['badgeText'] }}
                        </span>
                        <span class="card-subtext">{{ ($cardOffice ?? 'all') !== 'all' ? ($cardOffice . ' • ') : '' }}{{ $recData['rangeSubtext'] }}</span>
                    </div>
                </div>

                <!-- Card 2: Forwarded Transactions (Grouped Bar Chart) -->
                <div class="stat-card graph-card">
                    <!-- Subsystem Tab (CSPC Blue style) -->
                    <div class="card-subsystem-tab">
                        <i class="fa-solid fa-folder-tree"></i> DTS Subsystem
                    </div>

                    <!-- Expand Tab (Upscale Modal trigger) -->
                    <button type="button" 
                            class="card-expand-tab" 
                            wire:click="openModalWithCard('forwarded')" @click="openExpand('forwarded')" 
                            title="Expand to upscale detail view">
                        <i class="fa-solid fa-up-right-and-down-left-from-center"></i> EXPND
                    </button>

                    <!-- Top Row: Description & Filter (Left), Icon (Right) -->
                    <div class="card-top">
                        <div class="stat-info">
                            <span class="stat-label">Forwarded Transactions <span class="time-range-tag">{{ strtoupper($timeFilter) }}</span></span>

                            <!-- Green Dropdown Filter: Placed below Description -->
                            <div class="card-filter-wrapper">
                                <select wire:model.live="fwdFilter" class="card-dropdown-filter" aria-label="Filter Forwarded Transactions by month">
                                    <option value="all">All Months ({{ $selectedYear }})</option>
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                    <option value="week">Current Week</option>
                                    <option value="max">All-Time (Max)</option>
                                </select>
                            </div>
                        </div>

                        <div class="card-top-right">
                            <div class="stat-icon-wrapper dts-fwd-card-icon">
                                <i class="fa-solid fa-paper-plane" style="font-size: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-chart-legend">
                        @foreach($displayActionOptions as $opt)
                            <span class="legend-chip" title="{{ $opt }}">
                                <i class="chip-dot" style="background: {{ $actionOptionColors[$opt] ?? '#10b981' }};"></i>
                                {{ Str::limit($opt, 14) }}
                            </span>
                        @endforeach
                    </div>

                    <!-- Stable Proportional Grouped Bar Chart -->
                    <div class="chart-container">
                        @php
                            $optCount = max(1, count($displayActionOptions));
                            $cSlots = $fwdData['slots'];
                            $slotCount = max(1, count($cSlots));
                            $axisGutter = 28;
                            $usableW = 380 - $axisGutter - 6;
                            $slotW = $usableW / $slotCount;
                            
                            if ($slotCount <= 5) {
                                $barW = $optCount <= 2 ? 18.0 : ($optCount == 3 ? 13.0 : 10.0);
                                $barGap = 2.0;
                            } elseif ($slotCount <= 7) {
                                $barW = $optCount <= 2 ? 14.0 : ($optCount == 3 ? 10.0 : 7.8);
                                $barGap = 1.5;
                            } else {
                                $barW = $optCount <= 2 ? 8.5 : ($optCount == 3 ? 6.2 : 4.8);
                                $barGap = 1.0;
                            }
                            $groupW = ($optCount * $barW) + (($optCount - 1) * $barGap);
                            $maxV = $fwdData['maxVal'];
                        @endphp
                        <svg class="graph-svg" viewBox="0 0 380 140" preserveAspectRatio="xMidYMid meet">
                            <!-- Guide lines: 100%, 50%, 0% -->
                            <text x="24" y="21" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ $maxV }}</text>
                            <line x1="{{ $axisGutter }}" y1="18" x2="376" y2="18" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="69" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ round($maxV * 0.5) }}</text>
                            <line x1="{{ $axisGutter }}" y1="66" x2="376" y2="66" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="117" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">0</text>
                            <line x1="{{ $axisGutter }}" y1="114" x2="376" y2="114" stroke="#cbd5e1" stroke-width="1.2" />

                            @foreach($cSlots as $slotKey)
                                @php
                                    $slotCenter = $axisGutter + (($loop->index) * $slotW) + ($slotW / 2);
                                    $startX = $slotCenter - ($groupW / 2);
                                    $slotLabel = $fwdData['labels'][$slotKey] ?? $slotKey;
                                @endphp
                                @foreach($displayActionOptions as $oIdx => $opt)
                                    @php
                                        $val = $fwdData['slotData'][$slotKey][$opt] ?? 0;
                                        $barH = $maxV > 0 && $val > 0 ? max(4, round(($val / $maxV) * 96)) : 0;
                                        $bx = round($startX + ($oIdx * ($barW + $barGap)), 1);
                                        $by = 114 - $barH;
                                        $color = $actionOptionColors[$opt] ?? '#10b981';
                                    @endphp
                                    @if($barH > 0)
                                        <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $barW }}" height="{{ $barH }}" rx="1.5" fill="{{ $color }}">
                                            <title>{{ $slotLabel }} - {{ $opt }}: {{ $val }}</title>
                                        </rect>
                                    @endif
                                @endforeach
                                <text x="{{ $slotCenter }}" y="132" text-anchor="middle" font-size="8.5" font-weight="700" fill="#64748b">{{ $slotLabel }}</text>
                            @endforeach
                        </svg>
                    </div>

                    <div class="card-bottom">
                        <span class="trend-badge positive">
                            <i class="fa-solid fa-paper-plane"></i> {{ $fwdData['badgeText'] }}
                        </span>
                        <span class="card-subtext">{{ ($cardOffice ?? 'all') !== 'all' ? ($cardOffice . ' • ') : '' }}{{ $fwdData['rangeSubtext'] }}</span>
                    </div>
                </div>

                <!-- Card 3: Completed Transactions (Grouped Bar Chart) -->
                <div class="stat-card graph-card">
                    <!-- Subsystem Tab (CSPC Blue style) -->
                    <div class="card-subsystem-tab">
                        <i class="fa-solid fa-folder-tree"></i> DTS Subsystem
                    </div>

                    <!-- Expand Tab (Upscale Modal trigger) -->
                    <button type="button" 
                            class="card-expand-tab" 
                            wire:click="openModalWithCard('completed')" @click="openExpand('completed')" 
                            title="Expand to upscale detail view">
                        <i class="fa-solid fa-up-right-and-down-left-from-center"></i> EXPND
                    </button>

                    <!-- Top Row: Description & Filter (Left), Icon (Right) -->
                    <div class="card-top">
                        <div class="stat-info">
                            <span class="stat-label">Completed Transactions <span class="time-range-tag">{{ strtoupper($timeFilter) }}</span></span>

                            <!-- Green Dropdown Filter: Placed below Description -->
                            <div class="card-filter-wrapper">
                                <select wire:model.live="compFilter" class="card-dropdown-filter" aria-label="Filter Completed Transactions by month">
                                    <option value="all">All Months ({{ $selectedYear }})</option>
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                    <option value="week">Current Week</option>
                                    <option value="max">All-Time (Max)</option>
                                </select>
                            </div>
                        </div>

                        <div class="card-top-right">
                            <div class="stat-icon-wrapper dts-comp-card-icon">
                                <i class="fa-solid fa-circle-check" style="font-size: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-chart-legend">
                        @foreach($displayActionOptions as $opt)
                            <span class="legend-chip" title="{{ $opt }}">
                                <i class="chip-dot" style="background: {{ $actionOptionColors[$opt] ?? '#7c3aed' }};"></i>
                                {{ Str::limit($opt, 14) }}
                            </span>
                        @endforeach
                    </div>

                    <!-- Stable Proportional Grouped Bar Chart -->
                    <div class="chart-container">
                        @php
                            $optCount = max(1, count($displayActionOptions));
                            $cSlots = $compData['slots'];
                            $slotCount = max(1, count($cSlots));
                            $axisGutter = 28;
                            $usableW = 380 - $axisGutter - 6;
                            $slotW = $usableW / $slotCount;
                            
                            if ($slotCount <= 5) {
                                $barW = $optCount <= 2 ? 18.0 : ($optCount == 3 ? 13.0 : 10.0);
                                $barGap = 2.0;
                            } elseif ($slotCount <= 7) {
                                $barW = $optCount <= 2 ? 14.0 : ($optCount == 3 ? 10.0 : 7.8);
                                $barGap = 1.5;
                            } else {
                                $barW = $optCount <= 2 ? 8.5 : ($optCount == 3 ? 6.2 : 4.8);
                                $barGap = 1.0;
                            }
                            $groupW = ($optCount * $barW) + (($optCount - 1) * $barGap);
                            $maxV = $compData['maxVal'];
                        @endphp
                        <svg class="graph-svg" viewBox="0 0 380 140" preserveAspectRatio="xMidYMid meet">
                            <!-- Guide lines: 100%, 50%, 0% -->
                            <text x="24" y="21" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ $maxV }}</text>
                            <line x1="{{ $axisGutter }}" y1="18" x2="376" y2="18" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="69" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ round($maxV * 0.5) }}</text>
                            <line x1="{{ $axisGutter }}" y1="66" x2="376" y2="66" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="117" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">0</text>
                            <line x1="{{ $axisGutter }}" y1="114" x2="376" y2="114" stroke="#cbd5e1" stroke-width="1.2" />

                            @foreach($cSlots as $slotKey)
                                @php
                                    $slotCenter = $axisGutter + (($loop->index) * $slotW) + ($slotW / 2);
                                    $startX = $slotCenter - ($groupW / 2);
                                    $slotLabel = $compData['labels'][$slotKey] ?? $slotKey;
                                @endphp
                                @foreach($displayActionOptions as $oIdx => $opt)
                                    @php
                                        $val = $compData['slotData'][$slotKey][$opt] ?? 0;
                                        $barH = $maxV > 0 && $val > 0 ? max(4, round(($val / $maxV) * 96)) : 0;
                                        $bx = round($startX + ($oIdx * ($barW + $barGap)), 1);
                                        $by = 114 - $barH;
                                        $color = $actionOptionColors[$opt] ?? '#7c3aed';
                                    @endphp
                                    @if($barH > 0)
                                        <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $barW }}" height="{{ $barH }}" rx="1.5" fill="{{ $color }}">
                                            <title>{{ $slotLabel }} - {{ $opt }}: {{ $val }}</title>
                                        </rect>
                                    @endif
                                @endforeach
                                <text x="{{ $slotCenter }}" y="132" text-anchor="middle" font-size="8.5" font-weight="700" fill="#64748b">{{ $slotLabel }}</text>
                            @endforeach
                        </svg>
                    </div>

                    <div class="card-bottom">
                        <span class="trend-badge positive">
                            <i class="fa-solid fa-circle-check"></i> {{ $compData['badgeText'] }}
                        </span>
                        <span class="card-subtext">{{ ($cardOffice ?? 'all') !== 'all' ? ($cardOffice . ' • ') : '' }}{{ $compData['rangeSubtext'] }}</span>
                    </div>
                </div>

                <!-- Card 4: Records Created (NAP Form 1-3) (RDP Subsystem) -->
                <div class="stat-card graph-card">
                    <div class="card-subsystem-tab">
                        <i class="fa-solid fa-box-archive"></i> RDP Subsystem
                    </div>

                    <button type="button" 
                            class="card-expand-tab" 
                            wire:click="openModalWithCard('rdp_created')" @click="openExpand('rdp_created')" 
                            title="Expand to upscale detail view">
                        <i class="fa-solid fa-up-right-and-down-left-from-center"></i> EXPND
                    </button>

                    <div class="card-top">
                        <div class="stat-info">
                            <span class="stat-label">Records Created <span class="time-range-tag">NAP 1-3</span></span>

                            <div class="card-filter-wrapper">
                                <select wire:model.live="rdpCreatedFilter" class="card-dropdown-filter" aria-label="Filter Records Created by month">
                                    <option value="all">All Months ({{ $selectedYear }})</option>
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                    <option value="week">Current Week</option>
                                    <option value="max">All-Time (Max)</option>
                                </select>
                            </div>
                        </div>

                        <div class="card-top-right">
                            <div class="stat-icon-wrapper rdp-created-card-icon">
                                <i class="fa-solid fa-file-circle-plus" style="font-size: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-chart-legend">
                        @foreach($rdpCreatedData['displayOptions'] as $opt)
                            <span class="legend-chip" title="{{ $opt }}">
                                <i class="chip-dot" style="background: {{ $rdpCreatedData['colors'][$opt] ?? '#0284c7' }};"></i>
                                {{ $opt }}
                            </span>
                        @endforeach
                    </div>

                    <!-- Stable Proportional Grouped Bar Chart -->
                    <div class="chart-container">
                        @php
                            $optCount = max(1, count($rdpCreatedData['displayOptions']));
                            $cSlots = $rdpCreatedData['slots'];
                            $slotCount = max(1, count($cSlots));
                            $axisGutter = 28;
                            $usableW = 380 - $axisGutter - 6;
                            $slotW = $usableW / $slotCount;
                            
                            if ($slotCount <= 5) {
                                $barW = $optCount <= 2 ? 18.0 : ($optCount == 3 ? 13.0 : 10.0);
                                $barGap = 2.0;
                            } elseif ($slotCount <= 7) {
                                $barW = $optCount <= 2 ? 14.0 : ($optCount == 3 ? 10.0 : 7.8);
                                $barGap = 1.5;
                            } else {
                                $barW = $optCount <= 2 ? 8.5 : ($optCount == 3 ? 6.2 : 4.8);
                                $barGap = 1.0;
                            }
                            $groupW = ($optCount * $barW) + (($optCount - 1) * $barGap);
                            $maxV = $rdpCreatedData['maxVal'];
                        @endphp
                        <svg class="graph-svg" viewBox="0 0 380 140" preserveAspectRatio="xMidYMid meet">
                            <text x="24" y="21" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ $maxV }}</text>
                            <line x1="{{ $axisGutter }}" y1="18" x2="376" y2="18" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="69" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ round($maxV * 0.5) }}</text>
                            <line x1="{{ $axisGutter }}" y1="66" x2="376" y2="66" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="117" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">0</text>
                            <line x1="{{ $axisGutter }}" y1="114" x2="376" y2="114" stroke="#cbd5e1" stroke-width="1.2" />

                            @foreach($cSlots as $slotKey)
                                @php
                                    $slotCenter = $axisGutter + (($loop->index) * $slotW) + ($slotW / 2);
                                    $startX = $slotCenter - ($groupW / 2);
                                    $slotLabel = $rdpCreatedData['labels'][$slotKey] ?? $slotKey;
                                @endphp
                                @foreach($rdpCreatedData['displayOptions'] as $oIdx => $opt)
                                    @php
                                        $val = $rdpCreatedData['slotData'][$slotKey][$opt] ?? 0;
                                        $barH = $maxV > 0 && $val > 0 ? max(4, round(($val / $maxV) * 96)) : 0;
                                        $bx = round($startX + ($oIdx * ($barW + $barGap)), 1);
                                        $by = 114 - $barH;
                                        $color = $rdpCreatedData['colors'][$opt] ?? '#0284c7';
                                    @endphp
                                    @if($barH > 0)
                                        <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $barW }}" height="{{ $barH }}" rx="1.5" fill="{{ $color }}">
                                            <title>{{ $slotLabel }} - {{ $opt }}: {{ $val }}</title>
                                        </rect>
                                    @endif
                                @endforeach
                                <text x="{{ $slotCenter }}" y="132" text-anchor="middle" font-size="8.5" font-weight="700" fill="#64748b">{{ $slotLabel }}</text>
                            @endforeach
                        </svg>
                    </div>

                    <div class="card-bottom">
                        <span class="trend-badge positive">
                            <i class="fa-solid fa-file-circle-plus"></i> {{ $rdpCreatedData['badgeText'] }}
                        </span>
                        <span class="card-subtext">{{ ($cardOffice ?? 'all') !== 'all' ? ($cardOffice . ' • ') : '' }}{{ $rdpCreatedData['rangeSubtext'] }}</span>
                    </div>
                </div>

                <!-- Card 5: Approved Forms (NAP Form 1-3) (RDP Subsystem) -->
                <div class="stat-card graph-card">
                    <div class="card-subsystem-tab">
                        <i class="fa-solid fa-box-archive"></i> RDP Subsystem
                    </div>

                    <button type="button" 
                            class="card-expand-tab" 
                            wire:click="openModalWithCard('rdp_approved')" @click="openExpand('rdp_approved')" 
                            title="Expand to upscale detail view">
                        <i class="fa-solid fa-up-right-and-down-left-from-center"></i> EXPND
                    </button>

                    <div class="card-top">
                        <div class="stat-info">
                            <span class="stat-label">Approved Forms <span class="time-range-tag">NAP 1-3</span></span>

                            <div class="card-filter-wrapper">
                                <select wire:model.live="rdpApprovedFilter" class="card-dropdown-filter" aria-label="Filter Approved Forms by month">
                                    <option value="all">All Months ({{ $selectedYear }})</option>
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                    <option value="week">Current Week</option>
                                    <option value="max">All-Time (Max)</option>
                                </select>
                            </div>
                        </div>

                        <div class="card-top-right">
                            <div class="stat-icon-wrapper rdp-approved-card-icon">
                                <i class="fa-solid fa-file-circle-check" style="font-size: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-chart-legend">
                        @foreach($rdpApprovedData['displayOptions'] as $opt)
                            <span class="legend-chip" title="{{ $opt }}">
                                <i class="chip-dot" style="background: {{ $rdpApprovedData['colors'][$opt] ?? '#10b981' }};"></i>
                                {{ $opt }}
                            </span>
                        @endforeach
                    </div>

                    <!-- Stable Proportional Grouped Bar Chart -->
                    <div class="chart-container">
                        @php
                            $optCount = max(1, count($rdpApprovedData['displayOptions']));
                            $cSlots = $rdpApprovedData['slots'];
                            $slotCount = max(1, count($cSlots));
                            $axisGutter = 28;
                            $usableW = 380 - $axisGutter - 6;
                            $slotW = $usableW / $slotCount;
                            
                            if ($slotCount <= 5) {
                                $barW = $optCount <= 2 ? 18.0 : ($optCount == 3 ? 13.0 : 10.0);
                                $barGap = 2.0;
                            } elseif ($slotCount <= 7) {
                                $barW = $optCount <= 2 ? 14.0 : ($optCount == 3 ? 10.0 : 7.8);
                                $barGap = 1.5;
                            } else {
                                $barW = $optCount <= 2 ? 8.5 : ($optCount == 3 ? 6.2 : 4.8);
                                $barGap = 1.0;
                            }
                            $groupW = ($optCount * $barW) + (($optCount - 1) * $barGap);
                            $maxV = $rdpApprovedData['maxVal'];
                        @endphp
                        <svg class="graph-svg" viewBox="0 0 380 140" preserveAspectRatio="xMidYMid meet">
                            <text x="24" y="21" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ $maxV }}</text>
                            <line x1="{{ $axisGutter }}" y1="18" x2="376" y2="18" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="69" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ round($maxV * 0.5) }}</text>
                            <line x1="{{ $axisGutter }}" y1="66" x2="376" y2="66" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="117" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">0</text>
                            <line x1="{{ $axisGutter }}" y1="114" x2="376" y2="114" stroke="#cbd5e1" stroke-width="1.2" />

                            @foreach($cSlots as $slotKey)
                                @php
                                    $slotCenter = $axisGutter + (($loop->index) * $slotW) + ($slotW / 2);
                                    $startX = $slotCenter - ($groupW / 2);
                                    $slotLabel = $rdpApprovedData['labels'][$slotKey] ?? $slotKey;
                                @endphp
                                @foreach($rdpApprovedData['displayOptions'] as $oIdx => $opt)
                                    @php
                                        $val = $rdpApprovedData['slotData'][$slotKey][$opt] ?? 0;
                                        $barH = $maxV > 0 && $val > 0 ? max(4, round(($val / $maxV) * 96)) : 0;
                                        $bx = round($startX + ($oIdx * ($barW + $barGap)), 1);
                                        $by = 114 - $barH;
                                        $color = $rdpApprovedData['colors'][$opt] ?? '#10b981';
                                    @endphp
                                    @if($barH > 0)
                                        <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $barW }}" height="{{ $barH }}" rx="1.5" fill="{{ $color }}">
                                            <title>{{ $slotLabel }} - {{ $opt }}: {{ $val }}</title>
                                        </rect>
                                    @endif
                                @endforeach
                                <text x="{{ $slotCenter }}" y="132" text-anchor="middle" font-size="8.5" font-weight="700" fill="#64748b">{{ $slotLabel }}</text>
                            @endforeach
                        </svg>
                    </div>

                    <div class="card-bottom">
                        <span class="trend-badge positive">
                            <i class="fa-solid fa-file-circle-check"></i> {{ $rdpApprovedData['badgeText'] }}
                        </span>
                        <span class="card-subtext">{{ ($cardOffice ?? 'all') !== 'all' ? ($cardOffice . ' • ') : '' }}{{ $rdpApprovedData['rangeSubtext'] }}</span>
                    </div>
                </div>

                <!-- Card 6: Disposed Forms (NAP Form 3) (RDP Subsystem) -->
                <div class="stat-card graph-card">
                    <div class="card-subsystem-tab">
                        <i class="fa-solid fa-box-archive"></i> RDP Subsystem
                    </div>

                    <button type="button" 
                            class="card-expand-tab" 
                            wire:click="openModalWithCard('rdp_disposed')" @click="openExpand('rdp_disposed')" 
                            title="Expand to upscale detail view">
                        <i class="fa-solid fa-up-right-and-down-left-from-center"></i> EXPND
                    </button>

                    <div class="card-top">
                        <div class="stat-info">
                            <span class="stat-label">Disposed Forms <span class="time-range-tag">NAP 3</span></span>

                            <div class="card-filter-wrapper">
                                <select wire:model.live="rdpDisposedFilter" class="card-dropdown-filter" aria-label="Filter Disposed Forms by month">
                                    <option value="all">All Months ({{ $selectedYear }})</option>
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                    <option value="week">Current Week</option>
                                    <option value="max">All-Time (Max)</option>
                                </select>
                            </div>
                        </div>

                        <div class="card-top-right">
                            <div class="stat-icon-wrapper rdp-disposed-card-icon">
                                <i class="fa-solid fa-trash-can-arrow-up" style="font-size: 20px;"></i>
                            </div>
                        </div>
                    </div>

                    <div class="card-chart-legend">
                        @foreach($rdpDisposedData['displayOptions'] as $opt)
                            <span class="legend-chip" title="{{ $opt }}">
                                <i class="chip-dot" style="background: {{ $rdpDisposedData['colors'][$opt] ?? '#dc2626' }};"></i>
                                {{ $opt }}
                            </span>
                        @endforeach
                    </div>

                    <!-- Stable Proportional Grouped Bar Chart -->
                    <div class="chart-container">
                        @php
                            $optCount = max(1, count($rdpDisposedData['displayOptions']));
                            $cSlots = $rdpDisposedData['slots'];
                            $slotCount = max(1, count($cSlots));
                            $axisGutter = 28;
                            $usableW = 380 - $axisGutter - 6;
                            $slotW = $usableW / $slotCount;
                            
                            if ($slotCount <= 5) {
                                $barW = $optCount <= 2 ? 18.0 : ($optCount == 3 ? 13.0 : 10.0);
                                $barGap = 2.0;
                            } elseif ($slotCount <= 7) {
                                $barW = $optCount <= 2 ? 14.0 : ($optCount == 3 ? 10.0 : 7.8);
                                $barGap = 1.5;
                            } else {
                                $barW = $optCount <= 2 ? 8.5 : ($optCount == 3 ? 6.2 : 4.8);
                                $barGap = 1.0;
                            }
                            $groupW = ($optCount * $barW) + (($optCount - 1) * $barGap);
                            $maxV = $rdpDisposedData['maxVal'];
                        @endphp
                        <svg class="graph-svg" viewBox="0 0 380 140" preserveAspectRatio="xMidYMid meet">
                            <text x="24" y="21" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ $maxV }}</text>
                            <line x1="{{ $axisGutter }}" y1="18" x2="376" y2="18" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="69" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">{{ round($maxV * 0.5) }}</text>
                            <line x1="{{ $axisGutter }}" y1="66" x2="376" y2="66" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="3,3" />

                            <text x="24" y="117" text-anchor="end" fill="#64748b" font-size="8.5" font-weight="700">0</text>
                            <line x1="{{ $axisGutter }}" y1="114" x2="376" y2="114" stroke="#cbd5e1" stroke-width="1.2" />

                            @foreach($cSlots as $slotKey)
                                @php
                                    $slotCenter = $axisGutter + (($loop->index) * $slotW) + ($slotW / 2);
                                    $startX = $slotCenter - ($groupW / 2);
                                    $slotLabel = $rdpDisposedData['labels'][$slotKey] ?? $slotKey;
                                @endphp
                                @foreach($rdpDisposedData['displayOptions'] as $oIdx => $opt)
                                    @php
                                        $val = $rdpDisposedData['slotData'][$slotKey][$opt] ?? 0;
                                        $barH = $maxV > 0 && $val > 0 ? max(4, round(($val / $maxV) * 96)) : 0;
                                        $bx = round($startX + ($oIdx * ($barW + $barGap)), 1);
                                        $by = 114 - $barH;
                                        $color = $rdpDisposedData['colors'][$opt] ?? '#dc2626';
                                    @endphp
                                    @if($barH > 0)
                                        <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $barW }}" height="{{ $barH }}" rx="1.5" fill="{{ $color }}">
                                            <title>{{ $slotLabel }} - {{ $opt }}: {{ $val }}</title>
                                        </rect>
                                    @endif
                                @endforeach
                                <text x="{{ $slotCenter }}" y="132" text-anchor="middle" font-size="8.5" font-weight="700" fill="#64748b">{{ $slotLabel }}</text>
                            @endforeach
                        </svg>
                    </div>

                    <div class="card-bottom">
                        <span class="trend-badge positive">
                            <i class="fa-solid fa-trash-can-arrow-up"></i> {{ $rdpDisposedData['badgeText'] }}
                        </span>
                        <span class="card-subtext">{{ ($cardOffice ?? 'all') !== 'all' ? ($cardOffice . ' • ') : '' }}{{ $rdpDisposedData['rangeSubtext'] }}</span>
                    </div>
                </div>

            </div>

            <!-- Quick Management Actions (Bottom Box in Mockups) -->
            <div class="quick-actions-panel">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Quick Management Actions</h2>
                        <span class="panel-subtitle">Instant shortcuts to essential administrative consoles</span>
                    </div>
                </div>
                <div class="quick-actions-grid" :class="showActivities ? 'narrow-grid' : 'wide-grid'">
                    <a href="{{ route('admin.accounts.users') }}" class="quick-action-card">
                        <div class="action-icon users"><i class="fa-solid fa-users-gear"></i></div>
                        <div class="action-text">
                            <span class="action-title">User Accounts</span>
                            <span class="action-desc">Manage profiles, statuses & requestors</span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>

                    <a href="{{ route('admin.accounts.roles') }}" class="quick-action-card">
                        <div class="action-icon roles"><i class="fa-solid fa-user-shield"></i></div>
                        <div class="action-text">
                            <span class="action-title">Roles & Clearances</span>
                            <span class="action-desc">Permission matrix & RDP authorizations</span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>

                    <a href="{{ route('admin.activity.logins') }}" class="quick-action-card">
                        <div class="action-icon activity"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div class="action-text">
                            <span class="action-title">Security & Audit Logs</span>
                            <span class="action-desc">Review logins, file uploads & traces</span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>

                    <a href="{{ route('admin.tickets') }}" class="quick-action-card">
                        <div class="action-icon activity"><i class="fa-solid fa-bug"></i></div>
                        <div class="action-text">
                            <span class="action-title">Error Tickets</span>
                            <span class="action-desc">
                                @if($openErrorTickets > 0)
                                    {{ number_format($openErrorTickets) }} open
                                    @if($criticalErrorTickets > 0)
                                        · {{ number_format($criticalErrorTickets) }} critical
                                    @endif
                                @else
                                    No open tickets
                                @endif
                            </span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>

                    <a href="{{ route('admin.backup.index') }}" class="quick-action-card">
                        <div class="action-icon backup"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <div class="action-text">
                            <span class="action-title">Backup & Recovery</span>
                            <span class="action-desc">Create snapshots & restore tables</span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>

                    <a href="{{ route('admin.settings.index') }}" class="quick-action-card">
                        <div class="action-icon settings"><i class="fa-solid fa-sliders"></i></div>
                        <div class="action-text">
                            <span class="action-title">System Settings</span>
                            <span class="action-desc">Google SSO, Drive API & timeouts</span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>

                    <a href="{{ route('admin.recycle-bin') }}" class="quick-action-card">
                        <div class="action-icon recycle"><i class="fa-solid fa-trash-can-arrow-up"></i></div>
                        <div class="action-text">
                            <span class="action-title">Recycle Bin</span>
                            <span class="action-desc">Restore deactivated records & offices</span>
                        </div>
                        <div class="action-arrow"><i class="fa-solid fa-chevron-right"></i></div>
                    </a>
                </div>
            </div>

        </div>

        <!-- Right Sidebar Section: Recent Activities Panel (Blue Box in Mockup) -->
        <aside class="dashboard-sidebar-section" 
               x-show="showActivities" 
               x-cloak
               x-transition:enter="transition ease-out duration-200"
               x-transition:enter-start="opacity-0 transform translate-x-2"
               x-transition:enter-end="opacity-100 transform translate-x-0"
               x-transition:leave="transition ease-in duration-150"
               x-transition:leave-start="opacity-100 transform translate-x-0"
               x-transition:leave-end="opacity-0 transform translate-x-2">
            
            <div class="activities-panel">
                <div class="activities-panel-header">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <!-- Collapse/Hide toggle button matching top-left icon in mockup -->
                        <button type="button" 
                                @click="toggleActivities()" 
                                class="btn-hide-activities" 
                                title="Hide Recent Activities panel">
                            <i class="fa-solid fa-angles-right"></i>
                        </button>
                        <div>
                            <h2 class="activities-title">Recent Activities</h2>
                            <span class="activities-subtitle">Live unified feed</span>
                        </div>
                    </div>

                    <a href="{{ route('admin.activity.logins') }}" class="view-all-link" title="Open full activity logs">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </div>
                
                <div class="activities-list">
                    @forelse($activities as $activity)
                        @php
                            $displayName = ($activity->first_name || $activity->last_name) 
                                ? trim($activity->first_name . ' ' . $activity->last_name)
                                : ($activity->username ?? 'System Automated');

                            $roleLabel = $activity->role_name ?: 'System';
                            $formattedDate = \Carbon\Carbon::parse($activity->time)->format('M d,');
                            $isoTime = \Carbon\Carbon::parse($activity->time)->toIso8601String();
                            $formattedTime = \Carbon\Carbon::parse($activity->time)->format('g:i:s A');
                            $isSecurity = ($activity->event_type ?? 'security') === 'security';
                        @endphp
                        <div class="activity-row">
                            <div class="activity-left">
                                <div class="activity-left-header">
                                    <span class="activity-name" title="{{ $displayName }}">{{ $displayName }}</span>
                                    <span class="activity-type-badge {{ $isSecurity ? 'badge-security' : 'badge-admin' }}">
                                        @if($isSecurity)
                                            <i class="fa-solid fa-shield-halved"></i> Security
                                        @else
                                            <i class="fa-solid fa-gear"></i> Admin
                                        @endif
                                    </span>
                                </div>
                                <span class="activity-meta">
                                    <span class="role-pill">{{ $roleLabel }}</span>
                                    <span class="activity-date">{{ $formattedDate }}</span>
                                    <span class="activity-time" data-time="{{ $isoTime }}">{{ $formattedTime }}</span>
                                </span>
                            </div>
                            <div class="activity-right">
                                <span>{{ $activity->action_name }}</span>
                            </div>
                        </div>
                    @empty
                        <div style="text-align: center; color: #94a3b8; padding: 36px 20px; font-size: 13px;">
                            <i class="fa-regular fa-folder-open" style="font-size: 28px; margin-bottom: 8px; display: block; opacity: 0.5;"></i>
                            No recent activities recorded.
                        </div>
                    @endforelse
                </div>
            </div>

        </aside>

    </div>

    <!-- ======================================================== -->
    <!-- UPSCALE DETAIL TELEMETRY MODAL                           -->
    <!-- ======================================================== -->
    <div class="upscale-modal-backdrop" 
         x-show="expandedCard" 
         x-cloak 
         @click.self="closeExpand()" 
         @keydown.escape.window="closeExpand()">
        
        <div class="upscale-modal-dialog" x-show="expandedCard" x-transition>
            
            <!-- Modal Header -->
            <div class="upscale-modal-header">
                <div class="upscale-modal-title-row">
                    <span class="upscale-modal-subsystem-pill">
                        <i class="fa-solid" :class="expandedCard === 'received' || expandedCard === 'forwarded' || expandedCard === 'completed' ? 'fa-folder-tree' : 'fa-box-archive'"></i> 
                        <span x-text="expandedCard === 'received' || expandedCard === 'forwarded' || expandedCard === 'completed' ? 'DTS Subsystem' : 'RDP Subsystem'"></span>
                    </span>
                    <div>
                        <h2 class="upscale-modal-title" x-text="expandedCard === 'received' ? 'Received Transactions Telemetry' : (expandedCard === 'forwarded' ? 'Forwarded Transactions Telemetry' : (expandedCard === 'completed' ? 'Completed Transactions Telemetry' : (expandedCard === 'rdp_created' ? 'Records Created Telemetry (NAP Form 1-3)' : (expandedCard === 'rdp_approved' ? 'Approved Forms Telemetry (NAP Form 1-3)' : (expandedCard === 'rdp_disposed' ? 'Disposed Forms Telemetry (NAP Form 3)' : 'Subsystem Telemetry')))))"></h2>
                        <p class="upscale-modal-subtitle">Interactive configurable filters, multi-axis analytics & detailed records</p>
                    </div>
                </div>

                <button type="button" class="upscale-modal-close-btn" @click="closeExpand()" title="Close details modal (Esc)">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="upscale-modal-body">
                
                <!-- INTERACTIVE CONFIGURABLE FILTER BAR (FOR DTS & RDP CARDS) -->
                <div x-show="expandedCard === 'received' || expandedCard === 'forwarded' || expandedCard === 'completed' || expandedCard === 'rdp_created' || expandedCard === 'rdp_approved' || expandedCard === 'rdp_disposed'" class="modal-filter-toolbar">
                    <div class="modal-filter-grid">
                        
                        <!-- Filter 1: Period / Month Selector -->
                        <div class="modal-filter-group">
                            <label class="modal-filter-label"><i class="fa-regular fa-calendar"></i> Time Filter</label>
                            <select wire:model.live="modalRange" class="modal-filter-select">
                                <option value="all">Whole Year ({{ $selectedYear }})</option>
                                <optgroup label="Select Specific Month">
                                    <option value="1">January</option>
                                    <option value="2">February</option>
                                    <option value="3">March</option>
                                    <option value="4">April</option>
                                    <option value="5">May</option>
                                    <option value="6">June</option>
                                    <option value="7">July</option>
                                    <option value="8">August</option>
                                    <option value="9">September</option>
                                    <option value="10">October</option>
                                    <option value="11">November</option>
                                    <option value="12">December</option>
                                </optgroup>
                                <optgroup label="Other Presets">
                                    <option value="week">Current Week (Mon - Sun)</option>
                                    <option value="max">All-Time History (Max)</option>
                                </optgroup>
                            </select>
                        </div>

                        <!-- Filter 2: Action Needed / Category -->
                        <div class="modal-filter-group">
                            <label class="modal-filter-label"><i class="fa-solid fa-tags"></i> <span x-text="expandedCard === 'rdp_created' || expandedCard === 'rdp_approved' || expandedCard === 'rdp_disposed' ? 'Form / Category' : 'Action Needed'"></span></label>
                            <select wire:model.live="modalAction" class="modal-filter-select">
                                <option value="all">All Types</option>
                                @foreach($modalFilterOptions as $opt)
                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Filter 3: Office / Department -->
                        <div class="modal-filter-group">
                            <label class="modal-filter-label"><i class="fa-solid fa-building"></i> Office / Dept</label>
                            <select wire:model.live="modalOffice" class="modal-filter-select">
                                <option value="all">All Offices</option>
                                @foreach($officesList as $off)
                                    <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Filter 4: Keyword Search Box -->
                        <div class="modal-filter-group" style="flex: 1.5; min-width: 220px;">
                            <label class="modal-filter-label"><i class="fa-solid fa-magnifying-glass"></i> Search Data</label>
                            <div class="modal-search-wrapper">
                                <input type="text" 
                                       wire:model.live.debounce.300ms="modalSearch" 
                                       placeholder="Tracking #, subject, office..." 
                                       class="modal-filter-input">
                                @if(!empty($modalSearch))
                                    <button type="button" wire:click="$set('modalSearch', '')" class="modal-search-clear" title="Clear search">&times;</button>
                                @endif
                            </div>
                        </div>

                        <!-- Filter Action: Reset Button -->
                        <div class="modal-filter-group" style="align-self: flex-end;">
                            <button type="button" wire:click="resetModalFilters()" class="btn-reset-filters" title="Reset all filters">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </button>
                        </div>
                    </div>

                    <!-- Secondary Toolbar: View Mode Tabs & Quick Badges -->
                    <div class="modal-toolbar-secondary">
                        <div class="modal-view-tabs">
                            <button type="button" 
                                    wire:click="$set('modalTab', 'chart')" 
                                    class="modal-tab-btn {{ $modalTab === 'chart' ? 'active' : '' }}">
                                <i class="fa-solid fa-chart-simple"></i> Telemetry & Proportional Chart
                            </button>
                            <button type="button" 
                                    wire:click="$set('modalTab', 'records')" 
                                    class="modal-tab-btn {{ $modalTab === 'records' ? 'active' : '' }}">
                                <i class="fa-solid fa-table-list"></i> Transaction Records 
                                <span class="tab-count-badge">{{ count($modalRecords) }}</span>
                            </button>
                        </div>

                        <div class="modal-filter-summary">
                            <span class="active-filter-indicator">
                                <span class="indicator-dot"></span> Scope: <strong>{{ $modalActiveData['rangeSubtext'] }}</strong> &bull; Total: <strong>{{ number_format($modalActiveData['totalCount']) }}</strong>
                            </span>
                            @if($modalAction !== 'all')
                                <span class="active-tag-chip">
                                    Action: {{ $modalAction }} 
                                    <i class="fa-solid fa-xmark remove-tag" wire:click="$set('modalAction', 'all')"></i>
                                </span>
                            @endif
                            @if($modalOffice !== 'all')
                                <span class="active-tag-chip">
                                    Office: {{ $modalOffice }} 
                                    <i class="fa-solid fa-xmark remove-tag" wire:click="$set('modalOffice', 'all')"></i>
                                </span>
                            @endif
                            @if(!empty($modalSearch))
                                <span class="active-tag-chip">
                                    "{{ $modalSearch }}" 
                                    <i class="fa-solid fa-xmark remove-tag" wire:click="$set('modalSearch', '')"></i>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- EXPANDED CONTENT (DTS: RECEIVED, FORWARDED, COMPLETED & RDP: CREATED, APPROVED, DISPOSED) -->
                <div x-show="expandedCard === 'received' || expandedCard === 'forwarded' || expandedCard === 'completed' || expandedCard === 'rdp_created' || expandedCard === 'rdp_approved' || expandedCard === 'rdp_disposed'">
                    
                    @php
                        $mDispOpts = $modalActiveData['displayOptions'] ?? $displayActionOptions;
                        $mColors = $modalActiveData['colors'] ?? $actionOptionColors;
                    @endphp

                    <!-- TAB 1: TELEMETRY & PROPORTIONAL CHART VIEW -->
                    @if($modalTab === 'chart')
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <!-- Action Metrics Chips Bar (Clickable to Filter) -->
                            <div class="upscale-modal-metrics-bar">
                                <span class="modal-metric-chip" style="font-size: 13.5px; font-weight: 800; color: #0284c7;">
                                    Total in Scope: {{ number_format($modalActiveData['totalCount']) }}
                                </span>
                                @foreach($mDispOpts as $opt)
                                    @php
                                        $cnt = $modalActiveData['optTotals'][$opt] ?? 0;
                                        $pct = $modalActiveData['totalCount'] > 0 ? round(($cnt / $modalActiveData['totalCount']) * 100, 1) : 0;
                                        $isActive = $modalAction === $opt;
                                    @endphp
                                    <span class="modal-metric-chip" 
                                          style="cursor: pointer; padding: 3px 8px; border-radius: 6px; {{ $isActive ? 'background:#e0f2fe; border:1px solid #0284c7;' : '' }}" 
                                          wire:click="$set('modalAction', '{{ $isActive ? 'all' : $opt }}')" 
                                          title="Click to filter by {{ $opt }}">
                                        <i class="chip-dot" style="background: {{ $mColors[$opt] ?? '#0284c7' }};"></i>
                                        {{ $opt }}: <strong>{{ number_format($cnt) }}</strong> ({{ $pct }}%)
                                    </span>
                                @endforeach
                            </div>

                            <!-- Upscaled High-Resolution SVG Chart -->
                            <div class="upscale-chart-card">
                                <div class="upscale-chart-container">
                                    @php
                                        $uSlots = $modalActiveData['slots'];
                                        $uSlotCount = max(1, count($uSlots));
                                        $uOptCount = max(1, count($mDispOpts));
                                        $uAxisGutter = 35;
                                        $uUsableW = 860 - $uAxisGutter - 15;
                                        $uSlotW = $uUsableW / $uSlotCount;
                                        $uBarW = $uSlotCount <= 5 ? 24.0 : ($uSlotCount <= 7 ? 18.0 : 12.0);
                                        $uBarGap = 3.0;
                                        $uGroupW = ($uOptCount * $uBarW) + (($uOptCount - 1) * $uBarGap);
                                        $uMaxV = $modalActiveData['maxVal'];
                                    @endphp
                                    <svg class="upscale-svg" viewBox="0 0 860 260" preserveAspectRatio="xMidYMid meet">
                                        <!-- Guidelines -->
                                        <text x="30" y="25" text-anchor="end" fill="#64748b" font-size="11" font-weight="700">{{ $uMaxV }}</text>
                                        <line x1="{{ $uAxisGutter }}" y1="22" x2="845" y2="22" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4,4" />

                                        <text x="30" y="118" text-anchor="end" fill="#64748b" font-size="11" font-weight="700">{{ round($uMaxV * 0.5) }}</text>
                                        <line x1="{{ $uAxisGutter }}" y1="115" x2="845" y2="115" stroke="#f1f5f9" stroke-width="1" stroke-dasharray="4,4" />

                                        <text x="30" y="211" text-anchor="end" fill="#64748b" font-size="11" font-weight="700">0</text>
                                        <line x1="{{ $uAxisGutter }}" y1="208" x2="845" y2="208" stroke="#cbd5e1" stroke-width="1.5" />

                                        @foreach($uSlots as $sk)
                                            @php
                                                $uCenter = $uAxisGutter + (($loop->index) * $uSlotW) + ($uSlotW / 2);
                                                $uStart = $uCenter - ($uGroupW / 2);
                                                $uLbl = $modalActiveData['labels'][$sk] ?? $sk;
                                            @endphp
                                            @foreach($mDispOpts as $oIdx => $opt)
                                                @php
                                                    $val = $modalActiveData['slotData'][$sk][$opt] ?? 0;
                                                    $barH = $uMaxV > 0 && $val > 0 ? max(6, round(($val / $uMaxV) * 186)) : 0;
                                                    $bx = round($uStart + ($oIdx * ($uBarW + $uBarGap)), 1);
                                                    $by = 208 - $barH;
                                                    $color = $mColors[$opt] ?? '#0284c7';
                                                @endphp
                                                @if($barH > 0)
                                                    <rect x="{{ $bx }}" y="{{ $by }}" width="{{ $uBarW }}" height="{{ $barH }}" rx="2.5" fill="{{ $color }}">
                                                        <title>{{ $uLbl }} - {{ $opt }}: {{ $val }}</title>
                                                    </rect>
                                                    <text x="{{ $bx + ($uBarW / 2) }}" y="{{ max(16, $by - 4) }}" text-anchor="middle" font-size="9.5" font-weight="700" fill="#334155">{{ $val }}</text>
                                                @endif
                                            @endforeach
                                            <text x="{{ $uCenter }}" y="235" text-anchor="middle" font-size="11" font-weight="700" fill="#475569">{{ $uLbl }}</text>
                                        @endforeach
                                    </svg>
                                </div>
                            </div>

                            <!-- Granular Breakdown Table with Drilldown Clicks -->
                            <div class="upscale-table-card">
                                <h3 class="upscale-table-header-title">
                                    <i class="fa-solid fa-table-list"></i> Telemetry Breakdown by Period & Action
                                    <span style="font-size: 11px; font-weight: 500; color: #64748b; margin-left: 8px;">(Click any row to drill down into its records)</span>
                                </h3>
                                <table class="telemetry-detail-table">
                                    <thead>
                                        <tr>
                                            <th>Time Slot</th>
                                            <th>Total</th>
                                            @foreach($mDispOpts as $opt)
                                                <th>{{ $opt }}</th>
                                            @endforeach
                                            <th>Share of Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($modalActiveData['slots'] as $sk)
                                            @php
                                                $rowTotal = 0;
                                                foreach($mDispOpts as $opt) {
                                                    $rowTotal += ($modalActiveData['slotData'][$sk][$opt] ?? 0);
                                                }
                                                $rowPct = $modalActiveData['totalCount'] > 0 ? round(($rowTotal / $modalActiveData['totalCount']) * 100, 1) : 0;
                                            @endphp
                                            <tr class="clickable-row" wire:click="setModalRange('{{ $sk }}'); $set('modalTab', 'records');" title="Drill into {{ $modalActiveData['labels'][$sk] ?? $sk }} records">
                                                <td><strong>{{ $modalActiveData['labels'][$sk] ?? $sk }}</strong></td>
                                                <td><span class="badge" style="background:#e0f2fe; color:#0369a1; padding: 2px 8px; border-radius: 4px; font-weight: 700;">{{ number_format($rowTotal) }}</span></td>
                                                @foreach($mDispOpts as $opt)
                                                    <td>{{ number_format($modalActiveData['slotData'][$sk][$opt] ?? 0) }}</td>
                                                @endforeach
                                                <td>{{ $rowPct }}%</td>
                                            </tr>
                                        @endforeach
                                        <tr class="summary-row">
                                            <td>TOTAL</td>
                                            <td>{{ number_format($modalActiveData['totalCount']) }}</td>
                                            @foreach($mDispOpts as $opt)
                                                <td>{{ number_format($modalActiveData['optTotals'][$opt] ?? 0) }}</td>
                                            @endforeach
                                            <td>100%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- TAB 2: DETAILED TRANSACTION RECORDS VIEW -->
                    @if($modalTab === 'records')
                        <div class="upscale-table-card">
                            <h3 class="upscale-table-header-title" style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fa-solid fa-list-check"></i> Matching Records ({{ count($modalRecords) }} Records)</span>
                                <span style="font-size: 11px; font-weight: 500; color: #64748b;">Scope: {{ $modalActiveData['rangeSubtext'] }}</span>
                            </h3>

                            <div class="records-table-container">
                                <table class="records-table">
                                    <thead>
                                        <tr>
                                            <th>Tracking / Control #</th>
                                            <th>Subject / Purpose</th>
                                            <th>Action / Form Type</th>
                                            <th>Origin Office</th>
                                            <th>Current Office Hold</th>
                                            <th>Date & Time</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($modalRecords as $rec)
                                            @php
                                                $optColor = $mColors[$rec->action_needed] ?? '#0284c7';
                                                $st = strtolower($rec->status ?? 'ongoing');
                                            @endphp
                                            <tr>
                                                <td>
                                                    <span class="tracking-pill">{{ $rec->control_number ?: $rec->id }}</span>
                                                </td>
                                                <td style="max-width: 260px;">
                                                    <div style="font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $rec->subject }}">
                                                        {{ $rec->subject ?: 'No subject specified' }}
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="action-pill-badge" style="background: {{ $optColor }};">
                                                        {{ $rec->action_needed ?: 'General' }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong>{{ $rec->originated_from ?: '—' }}</strong>
                                                </td>
                                                <td>
                                                    <strong>{{ $rec->current_office_hold ?: '—' }}</strong>
                                                </td>
                                                <td style="white-space: nowrap; color: #64748b; font-size: 11.5px;">
                                                    {{ \Carbon\Carbon::parse($rec->logged_date)->format('M d, Y h:i A') }}
                                                </td>
                                                <td>
                                                    <span class="status-badge {{ $st === 'completed' ? 'completed' : ($st === 'ongoing' ? 'ongoing' : 'other') }}">
                                                        {{ ucfirst($st) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7" style="text-align: center; padding: 36px 16px;">
                                                    <div class="empty-records-box" style="padding: 0; border: none; background: transparent;">
                                                        <div class="empty-records-icon"><i class="fa-solid fa-folder-open"></i></div>
                                                        <p style="font-weight: 700; font-size: 14px; color: #334155; margin: 0 0 4px 0;">No matching transactions found</p>
                                                        <p style="font-size: 12px; margin: 0 0 14px 0; color: #64748b;">No records matched your selected month, office, action type, or search term.</p>
                                                        <button type="button" wire:click="resetModalFilters()" class="btn-reset-filters">
                                                            <i class="fa-solid fa-rotate-left"></i> Reset Filter Criteria
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                </div>

                <!-- 4. SYSTEM BACKUPS EXPANDED VIEW -->
                <template x-if="expandedCard === 'backups'">
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div class="upscale-modal-metrics-bar">
                            <span class="modal-metric-chip" style="font-size: 14px; font-weight: 800; color: #9333ea;">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Total Preserved Snapshots: {{ number_format($backupCount) }}
                            </span>
                            <span class="modal-metric-chip">
                                <i class="chip-dot" style="background:#9333ea;"></i> Frequency: <strong>Daily Rolling ({{ count($backupFrequency) }} Checkpoints)</strong>
                            </span>
                            <span class="modal-metric-chip">
                                <i class="chip-dot" style="background:#16a34a;"></i> Last Snapshot: <strong>{{ $lastBackupLabel }}</strong>
                            </span>
                        </div>
                        <div class="upscale-table-card">
                            <h3 class="upscale-table-header-title"><i class="fa-solid fa-database"></i> Preserved Snapshot Storage & Health Status</h3>
                            <table class="telemetry-detail-table">
                                <thead>
                                    <tr>
                                        <th>Snapshot Index</th>
                                        <th>Target Scope</th>
                                        <th>Retention Policy</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Snapshot #{{ $backupCount }}</strong></td>
                                        <td>Full PostgreSQL DB & Media Storage</td>
                                        <td>Standard Monthly Rollup</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Optimal</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Snapshot #{{ max(1, $backupCount - 1) }}</strong></td>
                                        <td>Differential Daily Changes</td>
                                        <td>7-Day Hot Storage</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Optimal</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <!-- 5. AUDIT ACTIVITIES EXPANDED VIEW -->
                <template x-if="expandedCard === 'activities'">
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div class="upscale-modal-metrics-bar">
                            <span class="modal-metric-chip" style="font-size: 14px; font-weight: 800; color: #ea580c;">
                                <i class="fa-solid fa-wave-square"></i> Total Activities: {{ number_format($totalActivities) }}
                            </span>
                            <span class="modal-metric-chip">
                                <i class="chip-dot" style="background:#ea580c;"></i> Category: <strong>Audit & Security Events</strong>
                            </span>
                            <span class="modal-metric-chip">
                                <i class="chip-dot" style="background:#16a34a;"></i> Status: <strong>Tracing Active</strong>
                            </span>
                        </div>
                        <div class="upscale-table-card">
                            <h3 class="upscale-table-header-title"><i class="fa-solid fa-clock-rotate-left"></i> Real-time Activity Telemetry Waveform Overview</h3>
                            <table class="telemetry-detail-table">
                                <thead>
                                    <tr>
                                        <th>Subsystem</th>
                                        <th>Monitored Action</th>
                                        <th>Security Policy</th>
                                        <th>Telemetry Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Document Tracking (DTS)</strong></td>
                                        <td>Document Intake, Dispatch & Receiving</td>
                                        <td>Strict Cryptographic Integrity</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Live Pulse</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Administration & Accounts</strong></td>
                                        <td>Role Elevation & Permissions</td>
                                        <td>Multi-Factor & Audit Vault</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Live Pulse</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <!-- 6. SERVER TELEMETRY EXPANDED VIEW -->
                <template x-if="expandedCard === 'telemetry'">
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div class="upscale-modal-metrics-bar">
                            <span class="modal-metric-chip" style="font-size: 14px; font-weight: 800; color: #2563eb;">
                                <i class="fa-solid fa-microchip"></i> CPU Load: {{ $cpuUsage }}%
                            </span>
                            <span class="modal-metric-chip" style="color: #8b5cf6;">
                                <i class="chip-dot" style="background:#8b5cf6;"></i> RAM Usage: <strong>{{ $ramUsage }}% ({{ $ramUsedGb }} / {{ $ramTotalGb }} GB)</strong>
                            </span>
                            <span class="modal-metric-chip" style="color: #f59e0b;">
                                <i class="chip-dot" style="background:#f59e0b;"></i> Disk Write Rate: <strong>{{ $diskWriteRate }} MB/s</strong>
                            </span>
                            <span class="modal-metric-chip">
                                <i class="chip-dot" style="background:#16a34a;"></i> CPU Cores: <strong>{{ $cores }} Active Cores</strong>
                            </span>
                        </div>
                        <div class="upscale-table-card">
                            <h3 class="upscale-table-header-title"><i class="fa-solid fa-server"></i> System Telemetry & Resource Breakdown</h3>
                            <table class="telemetry-detail-table">
                                <thead>
                                    <tr>
                                        <th>Metric Channel</th>
                                        <th>Current Value</th>
                                        <th>Threshold Status</th>
                                        <th>Health Recommendation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Central Processor (CPU)</strong></td>
                                        <td>{{ $cpuUsage }}% Across {{ $cores }} Cores</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Optimal (< 75%)</span></td>
                                        <td>System operating in nominal capacity range</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Memory Allocation (RAM)</strong></td>
                                        <td>{{ $ramUsage }}% ({{ $ramUsedGb }} GB / {{ $ramTotalGb }} GB)</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Optimal</span></td>
                                        <td>Memory buffers cleanly cached</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Storage Throughput (Write)</strong></td>
                                        <td>{{ $diskWriteRate }} MB/s</td>
                                        <td><span class="badge" style="background:#dcfce7; color:#15803d; padding: 2px 8px; border-radius: 4px; font-weight: 700;">Normal</span></td>
                                        <td>I/O write queues flushed</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

            </div>
        </div>
    </div>

    <script>
    (function () {
        const pad = (v) => String(v).padStart(2, '0');

        const formatLocalTime = (d) => {
            let hours = d.getHours();
            const minutes = pad(d.getMinutes());
            const seconds = pad(d.getSeconds());
            const period = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            if (hours === 0) hours = 12;
            return String(hours).padStart(2, '0') + ':' + minutes + ':' + seconds + ' ' + period;
        };

        const updateActivityTimes = () => {
            document.querySelectorAll('.activity-time').forEach(el => {
                const iso = el.getAttribute('data-time');
                if (!iso) return;
                const dt = new Date(iso);
                if (isNaN(dt.getTime())) return;
                el.textContent = formatLocalTime(dt);
            });
        };

        const notifyActivityPolled = () => {
            const marker = document.getElementById('rms-latest-activity-marker');
            if (marker) {
                const ts = parseInt(marker.getAttribute('data-timestamp') || '0', 10);
                window.dispatchEvent(new CustomEvent('rms-activity-update', { detail: { timestamp: ts } }));
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                updateActivityTimes();
                notifyActivityPolled();
            });
        } else {
            updateActivityTimes();
            notifyActivityPolled();
        }

        document.addEventListener('livewire:navigated', () => {
            updateActivityTimes();
            notifyActivityPolled();
        });

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', () => {
                updateActivityTimes();
                notifyActivityPolled();
            });
            window.Livewire.hook('commit', () => {
                updateActivityTimes();
                notifyActivityPolled();
            });
        }

        setInterval(updateActivityTimes, 1000);
    })();
    </script>

</div>

<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BackupScheduleService
{
    public const DEFAULT_SETTINGS = [
        'enabled' => false,
        'frequency' => 'daily', // 'daily', 'weekly', 'end_of_month', 'monthly'
        'time' => '23:59', // HH:mm (24-hour)
        'day_of_week' => 0, // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
        'day_of_month' => 'last_day', // 'last_day' or integer 1..31
        'target_local' => true,
        'target_nas' => true, // S3 / MinIO / NAS Silo
        'target_google' => true,
        'retention_count' => 30, // Keep last N scheduled backups (0 = unlimited)
        'last_run_at' => null,
        'last_run_status' => null,
        'last_run_filename' => null,
        'last_run_records' => 0,
        'last_run_message' => null,
    ];

    /**
     * Retrieve all backup schedule settings from database or fallback to defaults.
     */
    public static function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;

        try {
            $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : (Schema::hasTable('system_settings') ? 'system_settings' : null);
            if ($table) {
                $raw = DB::table($table)->where('key', 'like', 'backup_schedule_%')->pluck('value', 'key')->toArray();

                if (isset($raw['backup_schedule_enabled'])) {
                    $settings['enabled'] = filter_var($raw['backup_schedule_enabled'], FILTER_VALIDATE_BOOLEAN);
                }
                if (!empty($raw['backup_schedule_frequency'])) {
                    $settings['frequency'] = $raw['backup_schedule_frequency'];
                }
                if (!empty($raw['backup_schedule_time'])) {
                    $settings['time'] = $raw['backup_schedule_time'];
                }
                if (isset($raw['backup_schedule_day_of_week'])) {
                    $settings['day_of_week'] = (int) $raw['backup_schedule_day_of_week'];
                }
                if (!empty($raw['backup_schedule_day_of_month'])) {
                    $settings['day_of_month'] = $raw['backup_schedule_day_of_month'];
                }
                if (isset($raw['backup_schedule_target_local'])) {
                    $settings['target_local'] = filter_var($raw['backup_schedule_target_local'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($raw['backup_schedule_target_nas'])) {
                    $settings['target_nas'] = filter_var($raw['backup_schedule_target_nas'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($raw['backup_schedule_target_google'])) {
                    $settings['target_google'] = filter_var($raw['backup_schedule_target_google'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($raw['backup_schedule_retention_count'])) {
                    $settings['retention_count'] = max(0, (int) $raw['backup_schedule_retention_count']);
                }
                if (!empty($raw['backup_schedule_last_run_at'])) {
                    $settings['last_run_at'] = $raw['backup_schedule_last_run_at'];
                }
                if (!empty($raw['backup_schedule_last_run_status'])) {
                    $settings['last_run_status'] = $raw['backup_schedule_last_run_status'];
                }
                if (!empty($raw['backup_schedule_last_run_filename'])) {
                    $settings['last_run_filename'] = $raw['backup_schedule_last_run_filename'];
                }
                if (isset($raw['backup_schedule_last_run_records'])) {
                    $settings['last_run_records'] = (int) $raw['backup_schedule_last_run_records'];
                }
                if (!empty($raw['backup_schedule_last_run_message'])) {
                    $settings['last_run_message'] = $raw['backup_schedule_last_run_message'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("BackupScheduleService::getSettings failed: " . $e->getMessage());
        }

        return $settings;
    }

    /**
     * Persist backup schedule settings to database.
     */
    public static function saveSettings(array $newSettings): bool
    {
        try {
            $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : (Schema::hasTable('system_settings') ? 'system_settings' : null);
            if (!$table) {
                return false;
            }

            $map = [
                'backup_schedule_enabled' => isset($newSettings['enabled']) ? ($newSettings['enabled'] ? 'true' : 'false') : null,
                'backup_schedule_frequency' => $newSettings['frequency'] ?? null,
                'backup_schedule_time' => $newSettings['time'] ?? null,
                'backup_schedule_day_of_week' => isset($newSettings['day_of_week']) ? (string) $newSettings['day_of_week'] : null,
                'backup_schedule_day_of_month' => $newSettings['day_of_month'] ?? null,
                'backup_schedule_target_local' => isset($newSettings['target_local']) ? ($newSettings['target_local'] ? 'true' : 'false') : null,
                'backup_schedule_target_nas' => isset($newSettings['target_nas']) ? ($newSettings['target_nas'] ? 'true' : 'false') : null,
                'backup_schedule_target_google' => isset($newSettings['target_google']) ? ($newSettings['target_google'] ? 'true' : 'false') : null,
                'backup_schedule_retention_count' => isset($newSettings['retention_count']) ? (string) $newSettings['retention_count'] : null,
            ];

            foreach ($map as $key => $val) {
                if ($val !== null) {
                    DB::table($table)->updateOrInsert(['key' => $key], ['value' => $val]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("BackupScheduleService::saveSettings failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record execution result in system settings and admin logs.
     */
    public static function updateRunStatus(string $status, string $filename, int $records, string $message): void
    {
        try {
            $table = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : (Schema::hasTable('system_settings') ? 'system_settings' : null);
            if ($table) {
                $now = now()->toDateTimeString();
                DB::table($table)->updateOrInsert(['key' => 'backup_schedule_last_run_at'], ['value' => $now]);
                DB::table($table)->updateOrInsert(['key' => 'backup_schedule_last_run_status'], ['value' => $status]);
                DB::table($table)->updateOrInsert(['key' => 'backup_schedule_last_run_filename'], ['value' => $filename]);
                DB::table($table)->updateOrInsert(['key' => 'backup_schedule_last_run_records'], ['value' => (string) $records]);
                DB::table($table)->updateOrInsert(['key' => 'backup_schedule_last_run_message'], ['value' => $message]);
            }
        } catch (\Throwable $e) {
            Log::warning("BackupScheduleService::updateRunStatus failed: " . $e->getMessage());
        }
    }

    /**
     * Check if a scheduled backup is due to run right now.
     */
    public static function isDue(?Carbon $now = null): bool
    {
        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return false;
        }

        $now = $now ?? now();
        $targetTime = $settings['time'] ?? '23:59';
        [$targetHour, $targetMin] = array_map('intval', explode(':', $targetTime));

        // Time match (within current minute)
        if ($now->hour !== $targetHour || $now->minute !== $targetMin) {
            return false;
        }

        // Prevent duplicate execution within the same 5-minute window
        if (!empty($settings['last_run_at'])) {
            $lastRun = Carbon::parse($settings['last_run_at']);
            if ($now->diffInMinutes($lastRun) < 5) {
                return false;
            }
        }

        $freq = $settings['frequency'] ?? 'daily';

        switch ($freq) {
            case 'daily':
                return true;

            case 'weekly':
                $targetDay = (int) ($settings['day_of_week'] ?? 0);
                return $now->dayOfWeek === $targetDay;

            case 'end_of_month':
                // Check if today is the last day of the current month
                return $now->day === $now->endOfMonth()->day;

            case 'monthly':
                $targetDay = $settings['day_of_month'] ?? '1';
                if ($targetDay === 'last_day') {
                    return $now->day === $now->endOfMonth()->day;
                }
                return $now->day === (int) $targetDay;

            default:
                return true;
        }
    }

    /**
     * Calculate human-readable next execution timestamp.
     */
    public static function calculateNextRun(?Carbon $now = null): string
    {
        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return 'Schedule Disabled (Maintenance / Inactive)';
        }

        $now = $now ?? now();
        $targetTime = $settings['time'] ?? '23:59';
        [$targetHour, $targetMin] = array_map('intval', explode(':', $targetTime));

        $candidate = $now->copy()->setTime($targetHour, $targetMin, 0);

        $freq = $settings['frequency'] ?? 'daily';

        switch ($freq) {
            case 'daily':
                if ($candidate->lte($now)) {
                    $candidate->addDay();
                }
                break;

            case 'weekly':
                $targetDay = (int) ($settings['day_of_week'] ?? 0);
                while ($candidate->dayOfWeek !== $targetDay || $candidate->lte($now)) {
                    $candidate->addDay();
                }
                break;

            case 'end_of_month':
                $candidate = $now->copy()->endOfMonth()->setTime($targetHour, $targetMin, 0);
                if ($candidate->lte($now)) {
                    $candidate = $now->copy()->addMonthNoOverflow()->endOfMonth()->setTime($targetHour, $targetMin, 0);
                }
                break;

            case 'monthly':
                $targetDay = $settings['day_of_month'] ?? '1';
                if ($targetDay === 'last_day') {
                    $candidate = $now->copy()->endOfMonth()->setTime($targetHour, $targetMin, 0);
                    if ($candidate->lte($now)) {
                        $candidate = $now->copy()->addMonthNoOverflow()->endOfMonth()->setTime($targetHour, $targetMin, 0);
                    }
                } else {
                    $dayInt = min(28, max(1, (int) $targetDay));
                    $candidate = $now->copy()->setDay($dayInt)->setTime($targetHour, $targetMin, 0);
                    if ($candidate->lte($now)) {
                        $candidate = $now->copy()->addMonthNoOverflow()->setDay($dayInt)->setTime($targetHour, $targetMin, 0);
                    }
                }
                break;
        }

        return $candidate->format('M d, Y h:i A') . ' (' . $candidate->diffForHumans() . ')';
    }

    /**
     * Execute the automated backup routine.
     * Can be invoked via scheduler cron or manually tested by admin.
     */
    public static function executeScheduledBackup(bool $force = false, ?string $triggeredBy = null): array
    {
        $settings = self::getSettings();

        if (!$force && !$settings['enabled']) {
            $msg = 'Scheduled backup skipped: Automatic backup is currently paused/disabled (NAS Maintenance Mode).';
            Log::info($msg);
            return [
                'success' => false,
                'skipped' => true,
                'message' => $msg,
            ];
        }

        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $freqLabel = match ($settings['frequency']) {
            'end_of_month' => 'EndOfMonth_Schedule',
            'monthly' => 'Monthly_Schedule',
            'weekly' => 'Weekly_Schedule',
            default => 'Daily_Schedule',
        };

        $customLabel = $force ? "Manual_Scheduled_Test" : $freqLabel;

        try {
            $backupService = new BackupService();
            $result = $backupService->createFullBackup($customLabel);

            if (!$result['success']) {
                self::updateRunStatus('failed', '', 0, $result['message']);
                return $result;
            }

            $filename = $result['filename'];
            $totalRecords = $result['total_records'];
            $tablesCount = $result['tables_count'];

            // Sync to targets configured in schedule
            $syncedTargets = [];

            // 1. Local
            if ($settings['target_local'] && Storage::disk('local')->exists("backups/{$filename}")) {
                $syncedTargets[] = 'Local Server';
            }

            // 2. S3 / NAS Silo
            if ($settings['target_nas']) {
                try {
                    $jsonContent = Storage::disk('local')->get("backups/{$filename}");
                    if ($jsonContent) {
                        Storage::disk('s3')->put("backups/{$filename}", $jsonContent);
                        $syncedTargets[] = 'S3 NAS Storage';
                    }
                } catch (\Throwable $nasEx) {
                    Log::warning("Scheduled backup NAS sync warning: " . $nasEx->getMessage());
                }
            }

            // 3. Google Drive
            if ($settings['target_google']) {
                try {
                    $jsonContent = $jsonContent ?? Storage::disk('local')->get("backups/{$filename}");
                    if ($jsonContent) {
                        Storage::disk('google')->put("backup/{$filename}", $jsonContent);
                        $syncedTargets[] = 'Google Drive';
                    }
                } catch (\Throwable $gEx) {
                    Log::warning("Scheduled backup Google Drive sync warning: " . $gEx->getMessage());
                }
            }

            $targetsStr = !empty($syncedTargets) ? implode(', ', $syncedTargets) : 'Local Storage';
            $successMsg = "Automated backup generated successfully: [{$filename}] ({$totalRecords} records across {$tablesCount} tables). Dispatched to: {$targetsStr}.";

            // Record log in sys_backup_logs
            if (Schema::hasTable('sys_backup_logs')) {
                try {
                    $fileSize = Storage::disk('local')->size("backups/{$filename}");
                    $sizeFormatted = round($fileSize / 1024, 1) . ' KB';

                    DB::table('sys_backup_logs')->updateOrInsert(
                        ['filename' => $filename],
                        [
                            'name' => $customLabel,
                            'snap_type' => 'full',
                            'file_size' => $sizeFormatted,
                            'tables_count' => $tablesCount,
                            'total_records' => $totalRecords,
                            'categories_included' => json_encode(['All System Tables']),
                            'is_saved_on_cloud' => in_array('Google Drive', $syncedTargets, true) || in_array('S3 NAS Storage', $syncedTargets, true),
                            'cloud_service_name' => in_array('S3 NAS Storage', $syncedTargets, true) ? 'NAS S3 & Cloud' : 'Google Drive',
                            'is_saved_on_local' => true,
                            'status' => 'completed',
                            'created_by' => auth()->id(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                } catch (\Throwable $e) {}
            }

            // Admin audit log
            $adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : (Schema::hasTable('admin_logs') ? 'admin_logs' : null);
            if ($adminLogsTbl) {
                try {
                    DB::table($adminLogsTbl)->insert([
                        'changes' => "Automated scheduled backup completed: [{$filename}] ({$totalRecords} records). Dispatched to: {$targetsStr}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now(),
                    ]);
                } catch (\Throwable $e) {}
            }

            // Update status in settings
            self::updateRunStatus('success', $filename, $totalRecords, $successMsg);

            // Optional auto-prune retention rotation
            if (($settings['retention_count'] ?? 0) > 0) {
                self::pruneOldBackups((int) $settings['retention_count']);
            }

            return [
                'success' => true,
                'filename' => $filename,
                'total_records' => $totalRecords,
                'tables_count' => $tablesCount,
                'targets' => $syncedTargets,
                'message' => $successMsg,
            ];
        } catch (\Throwable $e) {
            $failMsg = "Automated scheduled backup error: " . $e->getMessage();
            Log::error($failMsg, ['exception' => $e]);
            self::updateRunStatus('failed', '', 0, $failMsg);
            return [
                'success' => false,
                'message' => $failMsg,
            ];
        }
    }

    /**
     * Auto-prune older scheduled backups exceeding the retention threshold.
     */
    public static function pruneOldBackups(int $keepCount): void
    {
        if ($keepCount <= 0) {
            return;
        }

        try {
            if (!Storage::disk('local')->exists('backups')) {
                return;
            }

            $files = Storage::disk('local')->files('backups');
            $scheduledFiles = [];

            foreach ($files as $file) {
                $fname = basename($file);
                // Target files with automated/schedule prefixes or timestamps
                if (str_contains($fname, 'Schedule') || str_contains($fname, 'Automated') || str_starts_with($fname, '[')) {
                    $scheduledFiles[$file] = Storage::disk('local')->lastModified($file);
                }
            }

            if (count($scheduledFiles) <= $keepCount) {
                return;
            }

            // Sort newest first
            arsort($scheduledFiles);

            $toDelete = array_slice(array_keys($scheduledFiles), $keepCount);
            foreach ($toDelete as $filePath) {
                Storage::disk('local')->delete($filePath);
                $delName = basename($filePath);
                try {
                    Storage::disk('s3')->delete("backups/{$delName}");
                } catch (\Throwable) {}
                try {
                    Storage::disk('google')->delete("backup/{$delName}");
                } catch (\Throwable) {}
            }

            Log::info("Backup retention rotation pruned " . count($toDelete) . " old scheduled backups.");
        } catch (\Throwable $e) {
            Log::warning("BackupScheduleService::pruneOldBackups error: " . $e->getMessage());
        }
    }
}

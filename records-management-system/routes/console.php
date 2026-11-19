<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dcs:purge-recycle-bin', function () {
    $docs = \App\Helpers\RegisterUpdateHelper::purgeExpiredRecycleBin();
    $settings = \App\Helpers\SettingsRecycleHelper::purgeExpired();
    $this->info("Purged {$docs} expired Recycle Bin document(s) and {$settings} settings item(s).");
})->purpose('Permanently delete expired DCS Recycle Bin documents and settings (Admin Recycle Bin)');

Schedule::command('dcs:purge-recycle-bin')->daily();

// Automated Backup Scheduler (evaluates DB schedule frequency: daily at 23:59, weekly, or end-of-month)
// Runs every minute but BackupScheduleService::isDue() internally checks the configured time/frequency
// and prevents duplicate execution within a 5-minute window — so actual backup only fires once per schedule.
// runInBackground() prevents the scheduler process from blocking while the backup executes.
Schedule::command('backup:scheduled-run')->everyMinute()->withoutOverlapping()->runInBackground();

// DTS Working Hours Auto-Dispatch Scheduler
Artisan::command('dts:dispatch-working-hours', function () {
    $count = \App\Services\DtsWorkingHoursService::dispatchPendingOutsideWorkingHoursTransactions();
    $this->info("Dispatched {$count} pending outside-working-hours transaction(s).");
})->purpose('Dispatch transactions held during non-working hours once working hours start');

Schedule::command('dts:dispatch-working-hours')->everyFiveMinutes()->withoutOverlapping();

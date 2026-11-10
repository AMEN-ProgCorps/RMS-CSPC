<?php

namespace App\Console\Commands;

use App\Services\BackupScheduleService;
use Illuminate\Console\Command;

class RunScheduledBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:scheduled-run {--force : Force execute even if not due or disabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute automated scheduled backups to local, NAS (S3), and Google Drive storage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (!$force && !BackupScheduleService::isDue()) {
            $this->line('Scheduled backup is not due at this time or is currently disabled (NAS Maintenance Mode).');
            return Command::SUCCESS;
        }

        $this->info('Starting automated scheduled backup routine...');
        $result = BackupScheduleService::executeScheduledBackup($force, 'Scheduler');

        if ($result['success']) {
            $this->info($result['message']);
            return Command::SUCCESS;
        }

        if (!empty($result['skipped'])) {
            $this->warn($result['message']);
            return Command::SUCCESS;
        }

        $this->error($result['message'] ?? 'Unknown backup failure.');
        return Command::FAILURE;
    }
}

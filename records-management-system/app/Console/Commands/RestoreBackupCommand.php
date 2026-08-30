<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Services\BackupService;

class RestoreBackupCommand extends Command
{
    protected $signature = 'rms:restore-backup {filename? : The backup JSON filename to restore} {--latest : Restore the latest backup available}';
    protected $description = 'Safely restore the RMS CSPC database from a JSON backup file';

    public function handle(): int
    {
        $filename = $this->argument('filename');
        $isLatest = $this->option('latest');

        $backupService = new BackupService();

        if ($isLatest || empty($filename)) {
            $files = Storage::disk('local')->files('backups');
            if (empty($files)) {
                $this->error('No backup files found in local storage.');
                return Command::FAILURE;
            }

            // Sort newest first
            usort($files, function ($a, $b) {
                return Storage::disk('local')->lastModified($b) <=> Storage::disk('local')->lastModified($a);
            });

            $filename = basename($files[0]);
            $this->info("Selected latest backup: {$filename}");
        }

        $this->info("Starting restore from [{$filename}]...");

        $result = $backupService->restoreBackupFile($filename, function ($message, $percent) {
            $this->line("[{$percent}%] {$message}");
        });

        if ($result['success']) {
            $this->info("SUCCESS: {$result['message']}");
            return Command::SUCCESS;
        } else {
            $this->error("FAILED: {$result['message']}");
            return Command::FAILURE;
        }
    }
}

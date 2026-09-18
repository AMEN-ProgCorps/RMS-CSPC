<?php

namespace App\Console\Commands;

use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MigrateFolderArchitectureCommand extends Command
{
    protected $signature = 'storage:migrate-architecture
                            {--dry-run : Preview changes without modifying files or database records}';

    protected $description = 'Migrate storage folders and database paths to the subsystem-first architecture (<subsystem>/<office>/...)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('===========================================================');
        $this->info('  RMS-CSPC Folder Architecture Migration (Subsystem-First) ');
        $this->info('===========================================================');
        if ($dryRun) {
            $this->warn('MODE: DRY-RUN (No files will be moved, no records modified)');
        } else {
            $this->alert('MODE: LIVE EXECUTION (Moving files & updating database records)');
        }

        $docsUpdated = 0;
        $dtsUpdated = 0;
        $dcsUpdated = 0;
        $localFilesMoved = 0;
        $googleFilesMoved = 0;

        // 1. Process sys_document_data records
        $this->info("\n[1/3] Scanning sys_document_data & dts_transactions...");
        $docTable = Schema::hasTable('sys_document_data') ? 'sys_document_data' : (Schema::hasTable('document_data') ? 'document_data' : null);

        if ($docTable) {
            $docs = DB::table($docTable)
                ->whereNotNull('document_path')
                ->where('document_path', '!=', '')
                ->get(['id', 'document_id', 'document_path']);

            foreach ($docs as $doc) {
                $oldPath = $doc->document_path;
                $newPath = DocumentStorageService::toSubsystemFirstPath($oldPath);

                if ($newPath && $newPath !== $oldPath) {
                    $this->line(" [Doc] {$oldPath} -> {$newPath}");

                    if (!$dryRun) {
                        // Move on local disk
                        $oldLocal = DocumentStorageService::localUploadsPath($oldPath);
                        $newLocal = DocumentStorageService::localUploadsPath($newPath);
                        if (Storage::disk('local')->exists($oldLocal)) {
                            $dir = dirname($newLocal);
                            if (!Storage::disk('local')->exists($dir)) {
                                Storage::disk('local')->makeDirectory($dir);
                            }
                            Storage::disk('local')->move($oldLocal, $newLocal);
                            $localFilesMoved++;
                        }

                        // Move on Google Drive
                        try {
                            if (Storage::disk('google')->exists($oldPath)) {
                                $dir = dirname($newPath);
                                try {
                                    Storage::disk('google')->makeDirectory($dir);
                                } catch (\Throwable) {}
                                Storage::disk('google')->move($oldPath, $newPath);
                                $googleFilesMoved++;
                            }
                        } catch (\Throwable $e) {
                            $this->warn("  Google Drive move warning ({$oldPath}): " . $e->getMessage());
                        }

                        // Update sys_document_data
                        DB::table($docTable)->where('id', $doc->id)->update(['document_path' => $newPath]);
                        $docsUpdated++;

                        // Update dts_transactions doc_dir
                        if (Schema::hasTable('dts_transactions') && Schema::hasColumn('dts_transactions', 'doc_dir')) {
                            $affected = DB::table('dts_transactions')
                                ->where('doc_dir', $oldPath)
                                ->update(['doc_dir' => $newPath]);
                            $dtsUpdated += $affected;
                        }
                    } else {
                        $docsUpdated++;
                    }
                }
            }
        }

        // 2. Process DCS tables
        $this->info("\n[2/3] Scanning DCS scanned documents & generated reports...");
        $dcsSources = DocumentStorageService::DCS_SCAN_SOURCES;
        $dcsSources[] = ['table' => 'dcs_document_stamps', 'column' => 'file_path', 'category' => 'stamps'];
        $dcsSources[] = ['table' => 'dcs_generated_reports', 'column' => 'file_path', 'category' => 'generated_reports'];

        foreach ($dcsSources as $source) {
            $table = $source['table'];
            $column = $source['column'];

            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->get(['id', $column]);

            foreach ($rows as $row) {
                $oldPath = (string) $row->{$column};
                $newPath = DocumentStorageService::toSubsystemFirstPath($oldPath);

                if ($newPath && $newPath !== $oldPath) {
                    $this->line(" [{$table}.{$column}] {$oldPath} -> {$newPath}");

                    if (!$dryRun) {
                        // Move on local disk
                        $oldLocal = DocumentStorageService::localUploadsPath($oldPath);
                        $newLocal = DocumentStorageService::localUploadsPath($newPath);
                        if (Storage::disk('local')->exists($oldLocal)) {
                            $dir = dirname($newLocal);
                            if (!Storage::disk('local')->exists($dir)) {
                                Storage::disk('local')->makeDirectory($dir);
                            }
                            Storage::disk('local')->move($oldLocal, $newLocal);
                            $localFilesMoved++;
                        }

                        // Move on Google Drive
                        try {
                            if (Storage::disk('google')->exists($oldPath)) {
                                $dir = dirname($newPath);
                                try {
                                    Storage::disk('google')->makeDirectory($dir);
                                } catch (\Throwable) {}
                                Storage::disk('google')->move($oldPath, $newPath);
                                $googleFilesMoved++;
                            }
                        } catch (\Throwable $e) {
                            $this->warn("  Google Drive move warning ({$oldPath}): " . $e->getMessage());
                        }

                        DB::table($table)->where('id', $row->id)->update([$column => $newPath]);
                        $dcsUpdated++;
                    } else {
                        $dcsUpdated++;
                    }
                }
            }
        }

        // 3. Physical local uploads directory scan for orphan files
        $this->info("\n[3/3] Scanning local uploads folder for unlinked files...");
        try {
            $allFiles = Storage::disk('local')->allFiles('uploads');
            foreach ($allFiles as $file) {
                // $file is relative to disk root: uploads/ICTO/DTS/filename.pdf
                $relative = substr($file, strlen('uploads/'));
                $converted = DocumentStorageService::toSubsystemFirstPath($relative);

                if ($converted && $converted !== $relative) {
                    $newFile = 'uploads/' . $converted;
                    if (!$dryRun) {
                        $newDir = dirname($newFile);
                        if (!Storage::disk('local')->exists($newDir)) {
                            Storage::disk('local')->makeDirectory($newDir);
                        }
                        if (Storage::disk('local')->exists($file) && !Storage::disk('local')->exists($newFile)) {
                            Storage::disk('local')->move($file, $newFile);
                            $localFilesMoved++;
                            $this->line(" [Local Disk File] {$file} -> {$newFile}");
                        }
                    } else {
                        $this->line(" [Local Disk File - dry-run] {$file} -> {$newFile}");
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warn("Local uploads scan note: " . $e->getMessage());
        }

        $this->info("\n===========================================================");
        $this->info("Migration Summary" . ($dryRun ? " (DRY-RUN)" : " (COMPLETED)") . ":");
        $this->line(" - sys_document_data rows: {$docsUpdated}");
        $this->line(" - dts_transactions linked rows: {$dtsUpdated}");
        $this->line(" - DCS tables rows: {$dcsUpdated}");
        $this->line(" - Local cache files relocated: {$localFilesMoved}");
        $this->line(" - Google Drive files relocated: {$googleFilesMoved}");
        $this->info("===========================================================\n");

        return Command::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\DocumentStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateDcsLegacyScans extends Command
{
    protected $signature = 'dcs:migrate-legacy-scans
                            {--dry-run : List legacy paths without migrating}
                            {--delete-public : Remove public/scans files after successful migration}
                            {--office=GENERAL : Office folder name for migrated files}';

    protected $description = 'Migrate DCS files from public/scans/... to Google Drive ({OFFICE}/DCS/{category}/...)';

    /** @var list<array{table: string, column: string, category: string}> */
    private const SCAN_SOURCES = DocumentStorageService::DCS_SCAN_SOURCES;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deletePublic = (bool) $this->option('delete-public');
        $office = strtoupper(Str::slug((string) $this->option('office'), '_')) ?: 'GENERAL';

        $this->info('Scanning DCS tables for legacy public/scans/ paths...');
        $this->line("Target office folder: {$office}");

        $legacyPaths = $this->collectLegacyPaths();
        if ($legacyPaths === []) {
            $this->info('No legacy scans/ paths found.');

            return Command::SUCCESS;
        }

        $this->info('Found ' . count($legacyPaths) . ' unique legacy path(s).');

        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($legacyPaths as $legacyPath => $category) {
            if (! DocumentStorageService::isLegacyPublicScanPath($legacyPath)) {
                $skipped++;
                continue;
            }

            if (! Storage::disk('public')->exists($legacyPath)) {
                $this->warn("Missing on public disk: {$legacyPath}");
                $failed++;
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] {$legacyPath} → DCS/{$category}/");
                continue;
            }

            $newPath = DocumentStorageService::migrateLegacyScanToDrive($legacyPath, $category, null, $office);
            if ($newPath === null) {
                $this->error("Failed to migrate: {$legacyPath}");
                $failed++;
                continue;
            }

            $updated = $this->replacePathReferences($legacyPath, $newPath);
            $this->line("Migrated {$legacyPath} → {$newPath} ({$updated} row(s) updated)");
            $migrated++;

            if ($deletePublic) {
                Storage::disk('public')->delete($legacyPath);
            }
        }

        if ($dryRun) {
            $this->info('Dry run complete. Re-run without --dry-run to migrate.');
        } else {
            $this->info("Done. Migrated: {$migrated}, skipped: {$skipped}, failed: {$failed}.");
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array<string, string> legacyPath => category */
    private function collectLegacyPaths(): array
    {
        $paths = [];

        foreach (self::SCAN_SOURCES as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }

            $values = DB::table($source['table'])
                ->whereNotNull($source['column'])
                ->where($source['column'], 'like', 'scans/%')
                ->distinct()
                ->pluck($source['column']);

            foreach ($values as $path) {
                $path = ltrim(str_replace('\\', '/', (string) $path), '/');
                if ($path !== '') {
                    $paths[$path] = $source['category'];
                }
            }
        }

        return $paths;
    }

    private function replacePathReferences(string $legacyPath, string $newPath): int
    {
        $updated = 0;

        foreach (self::SCAN_SOURCES as $source) {
            if (! Schema::hasTable($source['table']) || ! Schema::hasColumn($source['table'], $source['column'])) {
                continue;
            }

            $updated += DB::table($source['table'])
                ->where($source['column'], $legacyPath)
                ->update([$source['column'] => $newPath]);
        }

        return $updated;
    }
}

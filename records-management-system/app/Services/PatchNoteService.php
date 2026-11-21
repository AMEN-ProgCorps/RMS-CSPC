<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PatchNoteService
{
    /**
     * Known subsystems registry with their database names and cache keys.
     */
    public static function getSubsystemsMap(): array
    {
        return [
            'dts' => [
                'code' => 'DTS',
                'name' => 'Document Tracking System',
                'cache' => 'subsystem_ver_dts',
                'color' => '#003699',
            ],
            'rdp' => [
                'code' => 'RDP',
                'name' => 'Records Disposition Program',
                'cache' => 'subsystem_ver_rdp',
                'color' => '#0284c7',
            ],
            'admin' => [
                'code' => 'ADMIN',
                'name' => 'Admin Console',
                'cache' => 'subsystem_ver_admin_console',
                'color' => '#475569',
            ],
            'profile' => [
                'code' => 'PROFILE',
                'name' => 'Profile Manager',
                'cache' => 'subsystem_ver_profile',
                'color' => '#64748b',
            ],
            'all' => [
                'code' => 'ALL',
                'name' => 'All Subsystems',
                'cache' => null,
                'color' => '#10b981',
            ],
        ];
    }

    /**
     * Path to the master patch notes markdown file.
     */
    public static function getPatchFilePath(): string
    {
        $dir = resource_path('patch-notes');
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0755, true, true);
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'README.md';
        if (!is_file($file)) {
            file_put_contents($file, "# System Release Notes & Patch Updates\n\nAll notable changes across the system are documented here.\n");
        }

        return $file;
    }

    /**
     * Get raw markdown content.
     */
    public static function getRawContent(): string
    {
        $path = self::getPatchFilePath();
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Save raw markdown content to disk.
     */
    public static function saveRawContent(string $content): bool
    {
        $path = self::getPatchFilePath();
        
        // Create backup of previous content
        if (is_file($path)) {
            $backupPath = resource_path('patch-notes/README.backup.md');
            @copy($path, $backupPath);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        return (bool) file_put_contents($path, $normalized);
    }

    /**
     * Parse the patch notes markdown into structured releases.
     *
     * @return array<int, array{
     *     version: string,
     *     clean_version: string,
     *     subsystem_code: string,
     *     subsystem_name: string,
     *     date: string,
     *     is_latest: bool,
     *     summary: ?string,
     *     categories: array<int, array{name: string, type: string, items: array<int, array{label: string, text: string, raw: string}>}>,
     *     raw: string
     * }>
     */
    public static function getReleases(?string $subsystemFilter = null): array
    {
        $content = self::getRawContent();
        if (trim($content) === '') {
            return [];
        }

        $lines = explode("\n", $content);
        $releases = [];
        $currentRelease = null;
        $currentCategory = null;
        $summaryLines = [];
        $subMap = self::getSubsystemsMap();

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Version Heading:
            // Matches: ## [DTS v2.4.2] - 2026-11-21 or ## [v2.4.2] - 2026-11-21 (DTS) or ## [v2.4.2] - 2026-11-21
            if (preg_match('/^##\s+\[?(?:([a-zA-Z0-9_\-]+)\s+)?(v?[\d\.]+(?:-[a-zA-Z0-9\.]+)?)\]?(?:\s*-\s*([0-9]{4}-[0-9]{2}-[0-9]{2}))?(?:\s*\(([^)]+)\))?/i', $trimmed, $m)) {
                if ($currentRelease !== null) {
                    if ($currentCategory !== null) {
                        $currentRelease['categories'][] = $currentCategory;
                        $currentCategory = null;
                    }
                    if (!empty($summaryLines) && empty($currentRelease['summary'])) {
                        $currentRelease['summary'] = trim(implode("\n", $summaryLines));
                        $summaryLines = [];
                    }
                    $releases[] = $currentRelease;
                }

                $prefixSub = !empty($m[1]) ? trim($m[1]) : (!empty($m[4]) ? trim($m[4]) : 'ALL');
                $subKey = strtolower($prefixSub);
                $subCode = $subMap[$subKey]['code'] ?? strtoupper($prefixSub);
                $subName = $subMap[$subKey]['name'] ?? ($subCode === 'ALL' ? 'All Subsystems' : $subCode);

                $version = trim($m[2]);
                $cleanVer = ltrim($version, 'vV');
                $date = isset($m[3]) ? trim($m[3]) : '';

                $currentRelease = [
                    'version' => str_starts_with(strtolower($version), 'v') ? $version : 'v' . $version,
                    'clean_version' => $cleanVer,
                    'subsystem_code' => $subCode,
                    'subsystem_name' => $subName,
                    'date' => $date,
                    'is_latest' => false,
                    'summary' => null,
                    'categories' => [],
                    'raw' => $line . "\n",
                ];
                $summaryLines = [];
                continue;
            }

            if ($currentRelease === null) {
                continue;
            }

            $currentRelease['raw'] .= $line . "\n";

            // Category Heading: e.g. ### Added, ### Fixed, ### Changed, ### Security, etc.
            if (preg_match('/^###\s+([A-Za-z0-9_\-\s]+)/', $trimmed, $cm)) {
                if ($currentCategory !== null) {
                    $currentRelease['categories'][] = $currentCategory;
                }
                if (!empty($summaryLines) && empty($currentRelease['summary'])) {
                    $currentRelease['summary'] = trim(implode("\n", $summaryLines));
                    $summaryLines = [];
                }

                $catName = trim($cm[1]);
                $catType = strtolower($catName);
                if (str_contains($catType, 'add') || str_contains($catType, 'feat') || str_contains($catType, 'new')) {
                    $normType = 'added';
                } elseif (str_contains($catType, 'fix') || str_contains($catType, 'bug') || str_contains($catType, 'patch')) {
                    $normType = 'fixed';
                } elseif (str_contains($catType, 'change') || str_contains($catType, 'update') || str_contains($catType, 'improv')) {
                    $normType = 'changed';
                } elseif (str_contains($catType, 'secur')) {
                    $normType = 'security';
                } elseif (str_contains($catType, 'deprecat')) {
                    $normType = 'deprecated';
                } elseif (str_contains($catType, 'remov')) {
                    $normType = 'removed';
                } else {
                    $normType = 'info';
                }

                $currentCategory = [
                    'name' => $catName,
                    'type' => $normType,
                    'items' => [],
                ];
                continue;
            }

            // List item: - **Label**: Text or - Text or * Text
            if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $im)) {
                $itemText = trim($im[1]);
                $label = '';
                $text = $itemText;

                if (preg_match('/^\*\*([^*]+)\*\*:\s*(.*)$/', $itemText, $lm)) {
                    $label = trim($lm[1]);
                    $text = trim($lm[2]);
                }

                $item = [
                    'label' => $label,
                    'text' => $text,
                    'raw' => $itemText,
                ];

                if ($currentCategory !== null) {
                    $currentCategory['items'][] = $item;
                } else {
                    if ($currentCategory === null) {
                        $currentCategory = [
                            'name' => 'General',
                            'type' => 'info',
                            'items' => [],
                        ];
                    }
                    $currentCategory['items'][] = $item;
                }
                continue;
            }

            // Prose line (could be release summary)
            if ($currentCategory === null && $trimmed !== '' && !str_starts_with($trimmed, '---')) {
                $summaryLines[] = $trimmed;
            }
        }

        if ($currentRelease !== null) {
            if ($currentCategory !== null) {
                $currentRelease['categories'][] = $currentCategory;
            }
            if (!empty($summaryLines) && empty($currentRelease['summary'])) {
                $currentRelease['summary'] = trim(implode("\n", $summaryLines));
            }
            $releases[] = $currentRelease;
        }

        if (!empty($releases)) {
            $releases[0]['is_latest'] = true;
        }

        if (!empty($subsystemFilter) && strtoupper($subsystemFilter) !== 'ALL') {
            $sf = strtoupper(trim($subsystemFilter));
            return array_values(array_filter($releases, fn($r) => $r['subsystem_code'] === $sf || $r['subsystem_code'] === 'ALL'));
        }

        return $releases;
    }

    /**
     * Get the latest release (optionally filtered by subsystem).
     */
    public static function getLatestRelease(?string $subsystem = null): ?array
    {
        $releases = self::getReleases($subsystem);
        return $releases[0] ?? null;
    }

    /**
     * Insert a new release entry into the markdown file and automatically sync database & cache.
     */
    public static function createRelease(
        string $version,
        string $date,
        array $itemsByCategory,
        ?string $summary = null,
        string $subsystemCode = 'ALL',
        bool $syncDatabase = true
    ): bool {
        $content = self::getRawContent();
        $version = trim($version);
        if (!str_starts_with(strtolower($version), 'v')) {
            $version = 'v' . $version;
        }
        $date = trim($date) ?: now()->format('Y-m-d');
        $subsystemCode = strtoupper(trim($subsystemCode));

        $headerTag = ($subsystemCode !== 'ALL' && $subsystemCode !== '') ? "[{$subsystemCode} {$version}]" : "[{$version}]";
        $newBlock = "\n## {$headerTag} - {$date}\n";
        
        if (!empty($summary)) {
            $newBlock .= trim($summary) . "\n\n";
        }

        foreach ($itemsByCategory as $category => $items) {
            if (empty($items)) continue;
            $newBlock .= "### " . ucfirst($category) . "\n";
            foreach ($items as $it) {
                $it = trim($it);
                if ($it === '') continue;
                if (!str_starts_with($it, '- ')) {
                    $it = "- " . $it;
                }
                $newBlock .= $it . "\n";
            }
            $newBlock .= "\n";
        }

        $newBlock .= "---\n";

        // Find position after main H1 and preamble
        if (preg_match('/^(#[^\n]+\n(?:[^\n#]+\n)*)/', $content, $matches)) {
            $header = $matches[1];
            $rest = substr($content, strlen($header));
            $finalContent = rtrim($header) . "\n\n" . trim($newBlock) . "\n\n" . ltrim($rest);
        } else {
            $finalContent = "# System Release Notes & Patch Updates\n\n" . trim($newBlock) . "\n\n" . $content;
        }

        $saved = self::saveRawContent($finalContent);

        // Sync Subsystem database table & invalidate cache
        if ($saved && $syncDatabase && $subsystemCode !== 'ALL') {
            self::syncSubsystemVersion($subsystemCode, $version);
        }

        return $saved;
    }

    /**
     * Synchronize a specific subsystem's version in the sys_subsystems table and bust its cache.
     */
    public static function syncSubsystemVersion(string $subsystemCode, string $version): bool
    {
        $map = self::getSubsystemsMap();
        $key = strtolower(trim($subsystemCode));
        if (!isset($map[$key]) || empty($map[$key]['cache'])) {
            return false;
        }

        $info = $map[$key];
        $cleanVer = ltrim(trim($version), 'vV');
        $subsystemsTbl = Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $versionsLogTbl = Schema::hasTable('sys_subsystem_versions_log') ? 'sys_subsystem_versions_log' : 'subsystem_versions_log';

        try {
            $subsystem = DB::table($subsystemsTbl)->where('subsystem_name', $info['name'])->first();
            if ($subsystem) {
                DB::table($subsystemsTbl)->where('subsystem_id', $subsystem->subsystem_id)->update([
                    'subsystem_version' => $cleanVer,
                    'update_at' => now(),
                ]);

                DB::table($versionsLogTbl)->insert([
                    'subsystem_key' => $subsystem->subsystem_id,
                    'version_change' => $cleanVer,
                    'changes_on' => now(),
                ]);
            }

            Cache::forget($info['cache']);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Clear all subsystem version caches across all layouts.
     */
    public static function clearAllSubsystemVersionCaches(): void
    {
        Cache::forget('subsystem_ver_dts');
        Cache::forget('subsystem_ver_rdp');
        Cache::forget('subsystem_ver_admin_console');
        Cache::forget('subsystem_ver_profile');
    }
}

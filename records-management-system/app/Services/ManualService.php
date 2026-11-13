<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ManualService
{
    /**
     * Get parsed manual data for the current route (or specific route name).
     * Returns null if no manual exists for this route.
     *
     * @return array{title: string, purpose: string, sections: array, callouts: array, line_count: int}|null
     */
    public static function getManualForCurrentRoute(?string $routeName = null): ?array
    {
        $routeName = $routeName ?: optional(request()->route())->getName();
        if (!$routeName) {
            return null;
        }

        // DCS office intake vs admin/full controllers share some routes (e.g. dashboard).
        // Prefer role-specific manuals when present: dcs.office.md / dcs.admin.md,
        // or route-named variants like dcs.dashboard.office.md.
        if (str_starts_with($routeName, 'dcs')) {
            $role = self::dcsManualRoleSuffix();
            if ($role !== null) {
                $roleManual = self::getManualByName($routeName.'.'.$role);
                if ($roleManual) {
                    return $roleManual;
                }
                if (in_array($routeName, ['dcs', 'dcs.dashboard'], true)) {
                    $roleManual = self::getManualByName('dcs.'.$role);
                    if ($roleManual) {
                        return $roleManual;
                    }
                }
            }
        }

        return self::getManualByName($routeName);
    }

    /**
     * office = limited DCS / office intake; admin = full DCS controller.
     */
    protected static function dcsManualRoleSuffix(): ?string
    {
        if (! class_exists(\App\Helpers\RegisterQueryHelper::class)) {
            return null;
        }

        if (\App\Helpers\RegisterQueryHelper::isLimitedDcsUser()) {
            return 'office';
        }

        if (\App\Helpers\RegisterQueryHelper::isFullDcsUser()) {
            return 'admin';
        }

        return null;
    }

    /**
     * In-memory file index cache to prevent disk-thrashing RecursiveDirectoryIterator scans.
     */
    protected static ?array $fileIndexCache = null;

    /**
     * Build or return cached map of all manual markdown files.
     */
    public static function getFileIndex(): array
    {
        if (self::$fileIndexCache !== null) {
            return self::$fileIndexCache;
        }

        $baseDir = resource_path('manuals');
        $index = [
            'by_path' => [],       // relative path -> full path
            'by_name' => [],       // filename -> full path
            'by_base' => [],       // name without .md -> full path
            'by_modal_base' => [], // name without .modal.md -> full path
            'all_files' => [],
        ];

        if (!is_dir($baseDir)) {
            return self::$fileIndexCache = $index;
        }

        $rdi = new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $rii = new \RecursiveIteratorIterator($rdi);

        foreach ($rii as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $filename = strtolower($file->getFilename());
                if ($filename === 'nav-guides.md') {
                    continue;
                }

                $fullPath = $file->getPathname();
                $relPath = str_replace([$baseDir . DIRECTORY_SEPARATOR, $baseDir . '/'], '', $fullPath);
                $relClean = str_replace('\\', '/', $relPath);
                $relLower = strtolower($relClean);

                $baseName = preg_replace('/\.md$/i', '', $filename);
                $modalBase = preg_replace('/\.modal$/i', '', $baseName);

                $index['by_path'][$relLower] = $fullPath;
                $index['by_name'][$filename] = $fullPath;
                $index['by_base'][$baseName] = $fullPath;
                $index['by_modal_base'][$modalBase] = $fullPath;

                $index['all_files'][] = [
                    'name' => $file->getFilename(),
                    'relative_path' => $relClean,
                    'full_path' => $fullPath,
                    'size' => $file->getSize(),
                    'updated_at' => $file->getMTime(),
                    'base_name' => $baseName,
                    'modal_base' => $modalBase,
                ];
            }
        }

        return self::$fileIndexCache = $index;
    }

    /**
     * Clear the static in-memory file index cache.
     */
    public static function clearFileIndexCache(): void
    {
        self::$fileIndexCache = null;
        self::$parsedManualCache = [];
        \Illuminate\Support\Facades\Cache::forget('rms_all_manual_files_meta');
    }

    /**
     * Get parsed manual data by name, identifier, or relative path (e.g. 'dts.create.internal', 'modal.dts.transaction.details').
     */
    public static function getManualByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $baseDir = resource_path('manuals');
        if (!is_dir($baseDir)) {
            return null;
        }

        $normalized = trim(str_replace(['\\', '/'], '.', $name), '.');
        $normalizedLower = strtolower($normalized);
        $segments = explode('.', $normalized);
        $subsystem = strtolower($segments[0] ?? '');

        $index = self::getFileIndex();

        // 1. Check explicit custom mappings (mappings.json)
        $mappings = self::getManualMapping();
        $mappedPath = $mappings[$name] ?? ($mappings[$normalized] ?? null);
        if ($mappedPath !== null) {
            $mappedPath = trim((string) $mappedPath);
            // If explicitly disabled or unassigned by admin, return null
            if ($mappedPath === '' || $mappedPath === 'none' || $mappedPath === 'disabled') {
                return null;
            }
            $cleanMapped = strtolower(ltrim(str_replace('\\', '/', $mappedPath), '/'));
            $targetFull = $index['by_path'][$cleanMapped]
                ?? ($index['by_name'][$cleanMapped]
                ?? ($baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $mappedPath), DIRECTORY_SEPARATOR)));
            if (is_file($targetFull)) {
                return self::parseManualFile($targetFull);
            }
        }

        // 2. Fast in-memory index exact lookup (zero disk syscalls)
        $foundPath = $index['by_modal_base'][$normalizedLower]
            ?? ($index['by_base'][$normalizedLower]
            ?? ($index['by_path'][$normalizedLower . '.md']
            ?? ($index['by_name'][$normalizedLower . '.md']
            ?? ($index['by_path'][$subsystem . '/' . $normalizedLower . '.md']
            ?? ($index['by_path']['modal/' . $subsystem . '/' . $normalizedLower . '.modal.md']
            ?? ($index['by_path']['modal/' . $subsystem . '/' . $normalizedLower . '.md']
            ?? null))))));

        if ($foundPath && is_file($foundPath)) {
            return self::parseManualFile($foundPath);
        }

        // 3. Fallback to candidate paths check only if not found in index
        $candidates = [
            $baseDir . DIRECTORY_SEPARATOR . $subsystem . DIRECTORY_SEPARATOR . $normalized . '.md',
            $baseDir . DIRECTORY_SEPARATOR . $normalized . '.md',
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . $subsystem . DIRECTORY_SEPARATOR . $normalized . '.modal.md',
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . $subsystem . DIRECTORY_SEPARATOR . $normalized . '.md',
            $baseDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $normalized) . '.modal.md',
            $baseDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $normalized) . '.md',
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, preg_replace('/^modal\./i', '', $normalized)) . '.modal.md',
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, preg_replace('/^modal\./i', '', $normalized)) . '.md',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return self::parseManualFile($candidate);
            }
        }

        // 4. Fuzzy match using in-memory list (zero disk I/O)
        $cleanNeedle = str_replace(['_', '-'], '.', $normalizedLower);
        foreach ($index['all_files'] as $f) {
            $mb = $f['modal_base'];
            $b = $f['base_name'];
            if ($mb === $cleanNeedle || $b === $cleanNeedle || str_ends_with($mb, '.' . $cleanNeedle) || str_ends_with($cleanNeedle, '.' . $mb)) {
                return self::parseManualFile($f['full_path']);
            }
        }

        return null;
    }

    /**
     * Parse a markdown manual file into structured lines, sections, and callouts.
     * Cached in static memory per request to prevent redundant file reads.
     */
    protected static array $parsedManualCache = [];

    public static function parseManualFile(string $filePath): ?array
    {
        if (!is_file($filePath)) {
            return null;
        }

        if (isset(self::$parsedManualCache[$filePath])) {
            return self::$parsedManualCache[$filePath];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $title = '';
        $purpose = '';
        $sections = [];
        $currentSection = null;
        $callouts = [];
        $activeCallout = null;
        $lineCount = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($activeCallout) {
                    $callouts[] = $activeCallout;
                    $activeCallout = null;
                }
                continue;
            }

            // Document Title
            if (str_starts_with($trimmed, '# ')) {
                if ($activeCallout) {
                    $callouts[] = $activeCallout;
                    $activeCallout = null;
                }
                $title = trim(substr($trimmed, 2));
                continue;
            }

            // Blockquotes / Callouts / Purpose
            if (str_starts_with($trimmed, '>')) {
                $calloutText = trim(ltrim($trimmed, '> '));
                if (preg_match('/^\[!(NOTE|TIP|INFO)\]\s*(.*)$/i', $calloutText, $m)) {
                    if ($activeCallout) {
                        $callouts[] = $activeCallout;
                    }
                    $type = strtolower($m[1]) === 'tip' ? 'tip' : 'note';
                    $activeCallout = ['type' => $type, 'title' => ucfirst(strtolower($m[1])), 'text' => self::stripManualMarkdown(trim($m[2]))];
                } elseif (preg_match('/^\[!(WARNING|CAUTION|ALERT)\]\s*(.*)$/i', $calloutText, $m)) {
                    if ($activeCallout) {
                        $callouts[] = $activeCallout;
                    }
                    $activeCallout = ['type' => 'warning', 'title' => ucfirst(strtolower($m[1])), 'text' => self::stripManualMarkdown(trim($m[2]))];
                } elseif ($activeCallout !== null) {
                    $activeCallout['text'] = trim($activeCallout['text'] . ' ' . self::stripManualMarkdown($calloutText));
                } elseif (empty($purpose)) {
                    $purpose = preg_replace('/^(Purpose:\s*)/i', '', self::stripManualMarkdown($calloutText));
                } else {
                    $callouts[] = ['type' => 'note', 'title' => 'Note', 'text' => self::stripManualMarkdown($calloutText)];
                }
                continue;
            } else {
                if ($activeCallout) {
                    $callouts[] = $activeCallout;
                    $activeCallout = null;
                }
            }

            // Section Header (## Step X)
            if (str_starts_with($trimmed, '## ')) {
                if ($currentSection) {
                    $sections[] = $currentSection;
                }
                $currentSection = [
                    'title' => trim(substr($trimmed, 3)),
                    'items' => [],
                ];
                continue;
            }

            // Bullet points / numbered list lines
            if (str_starts_with($trimmed, '- ') || str_starts_with($trimmed, '* ') || preg_match('/^\d+\.\s/', $trimmed)) {
                $cleanText = preg_replace('/^([\\-\\+]|\\*\\s+|\\d+\\.)\\s*/', '', $trimmed);
                if (!$currentSection) {
                    $currentSection = ['title' => 'Instructions', 'items' => []];
                }

                // Check for bold/plain label like "**Field Name**: description" or "Field Name: description"
                $label = '';
                $desc = $cleanText;
                if (preg_match('/^\*\*(.*?)\*\*:\s*(.*)$/', $cleanText, $matches)) {
                    $label = $matches[1];
                    $desc = $matches[2];
                } elseif (preg_match('/^([^:\n]{1,80}):\s+(.+)$/', $cleanText, $matches)) {
                    $label = trim($matches[1]);
                    $desc = $matches[2];
                }

                $label = self::stripManualMarkdown($label);
                $desc = self::stripManualMarkdown($desc);

                $currentSection['items'][] = [
                    'label' => $label,
                    'text' => $desc,
                    'raw' => $cleanText,
                ];
                $lineCount++;
                continue;
            }

            // Regular paragraph line inside active section
            if ($currentSection) {
                $currentSection['items'][] = [
                    'label' => '',
                    'text' => $trimmed,
                    'raw' => $trimmed,
                ];
                $lineCount++;
            }
        }

        if ($activeCallout) {
            $callouts[] = $activeCallout;
        }

        if ($currentSection) {
            $sections[] = $currentSection;
        }

        $rawContent = @file_get_contents($filePath) ?: '';
        $baseDir = resource_path('manuals');
        $relPath = str_replace([$baseDir . DIRECTORY_SEPARATOR, $baseDir . '/'], '', $filePath);

        $parsed = [
            'title' => $title ?: 'Page Manual',
            'purpose' => $purpose,
            'sections' => $sections,
            'callouts' => $callouts,
            'line_count' => $lineCount,
            'file_path' => $filePath,
            'relative_path' => str_replace('\\', '/', $relPath),
            'raw_content' => $rawContent,
            'file_size' => filesize($filePath) ?: 0,
            'updated_at' => filemtime($filePath) ?: time(),
        ];

        return self::$parsedManualCache[$filePath] = $parsed;
    }

    /**
     * Remove markdown bold markers so the page guide UI never shows literal **.
     */
    protected static function stripManualMarkdown(string $text): string
    {
        $text = preg_replace('/\*\*(.*?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/__(.*?)__/s', '$1', $text) ?? $text;

        return str_replace(['**', '__'], '', $text);
    }

    /**
     * Get key-value navigation tooltips from nav-guides.md.
     * Cached for performance.
     *
     * @return array<string, string>
     */
    public static function getNavGuides(): array
    {
        return Cache::remember('rms_nav_guides_cache', 3600, function () {
            $path = resource_path('manuals/nav-guides.md');
            if (!is_file($path)) {
                return [];
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                return [];
            }

            $guides = [];
            $currentKey = null;

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                if (str_starts_with($trimmed, '# ') || str_starts_with($trimmed, '## ')) {
                    $currentKey = trim(ltrim($trimmed, '# '));
                    continue;
                }

                if ($currentKey && !isset($guides[$currentKey])) {
                    $guides[$currentKey] = $trimmed;
                }
            }

            return $guides;
        });
    }

    /**
     * Get path to the JSON mapping file.
     * Prefers writable storage/app/manuals/mappings.json, falls back to resources/manuals/mappings.json.
     */
    public static function getMappingsFilePath(): string
    {
        $storagePath = storage_path('app/manuals/mappings.json');
        if (is_file($storagePath)) {
            return $storagePath;
        }

        return resource_path('manuals/mappings.json');
    }

    /**
     * Get all custom manual mappings from storage, resources, and database.
     *
     * @return array<string, string> Key: route or modal name, Value: relative path in manuals/ or 'disabled'
     */
    public static function getManualMapping(): array
    {
        $mappings = [];

        // 1. Initial base seed from resource_path (if present)
        $resPath = resource_path('manuals/mappings.json');
        if (is_file($resPath)) {
            try {
                $seed = json_decode((string) @file_get_contents($resPath), true);
                if (is_array($seed)) {
                    $mappings = $seed;
                }
            } catch (\Throwable) {}
        }

        // 2. Dynamic runtime overrides from storage_path
        $storagePath = storage_path('app/manuals/mappings.json');
        if (is_file($storagePath)) {
            try {
                $custom = json_decode((string) @file_get_contents($storagePath), true);
                if (is_array($custom)) {
                    $mappings = array_merge($mappings, $custom);
                }
            } catch (\Throwable) {}
        }

        // 3. Fallback/Sync from database if available
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sys_page_manuals')) {
                $dbCustom = \Illuminate\Support\Facades\DB::table('sys_page_manuals')
                    ->where('is_custom_mapped', true)
                    ->pluck('relative_path', 'target_key')
                    ->all();
                foreach ($dbCustom as $k => $v) {
                    $mappings[$k] = $v ?? 'disabled';
                }
            }
        } catch (\Throwable) {}

        return $mappings;
    }

    /**
     * Save manual mappings safely to storage_path and resource_path without throwing permission errors.
     */
    public static function saveManualMapping(array $mappings): bool
    {
        $encoded = json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $written = false;

        // 1. Always save to storage_path (configured as writable for web process in Laravel)
        try {
            $storageDir = storage_path('app/manuals');
            if (!is_dir($storageDir)) {
                @mkdir($storageDir, 0775, true);
            }
            $storageFile = $storageDir . DIRECTORY_SEPARATOR . 'mappings.json';
            if (@file_put_contents($storageFile, $encoded) !== false) {
                $written = true;
            }
        } catch (\Throwable) {}

        // 2. Also try updating resources/manuals/mappings.json if permissions allow (local/dev)
        try {
            $resPath = resource_path('manuals/mappings.json');
            $resDir = dirname($resPath);
            if (!is_dir($resDir)) {
                @mkdir($resDir, 0775, true);
            }
            if (is_writable($resDir) || (file_exists($resPath) && is_writable($resPath))) {
                if (@file_put_contents($resPath, $encoded) !== false) {
                    $written = true;
                }
            }
        } catch (\Throwable) {}

        return $written;
    }

    /**
     * Assign a manual file to a specific page or modal.
     */
    public static function assignManual(string $targetKey, ?string $fileRelativePath): bool
    {
        $targetKey = trim($targetKey);
        if ($targetKey === '') {
            return false;
        }

        $mappings = self::getManualMapping();
        $isDisable = empty($fileRelativePath) || $fileRelativePath === 'disabled' || $fileRelativePath === 'none';
        if ($isDisable) {
            $mappings[$targetKey] = 'disabled';
        } else {
            $mappings[$targetKey] = ltrim(str_replace('\\', '/', $fileRelativePath), '/');
        }

        $saved = self::saveManualMapping($mappings);
        $dbUpdated = false;

        // Also sync to database table if available (permanent persistence)
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('sys_page_manuals')) {
                \Illuminate\Support\Facades\DB::table('sys_page_manuals')->updateOrInsert(
                    ['target_key' => $targetKey],
                    [
                        'relative_path' => $isDisable ? null : $mappings[$targetKey],
                        'is_custom_mapped' => true,
                        'parsed_content' => null, // clear cached parse
                        'updated_at' => now(),
                    ]
                );
                $dbUpdated = true;
            }
        } catch (\Throwable) {}

        return $saved || $dbUpdated;
    }

    /**
     * Remove / unassign manual for a specific target.
     */
    public static function unassignManual(string $targetKey): bool
    {
        return self::assignManual($targetKey, 'disabled');
    }

    /**
     * Reset manual for target back to default auto-discovery.
     */
    public static function resetToDefaultManual(string $targetKey): bool
    {
        $targetKey = trim($targetKey);
        $mappings = self::getManualMapping();
        if (isset($mappings[$targetKey])) {
            unset($mappings[$targetKey]);
            $saved = self::saveManualMapping($mappings);

            // Re-resolve default from file index into DB
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('sys_page_manuals')) {
                    $index = self::getFileIndex();
                    $keyLower = strtolower($targetKey);
                    $found = $index['by_modal_base'][$keyLower]
                        ?? ($index['by_base'][$keyLower]
                        ?? ($index['by_path'][$keyLower . '.md']
                        ?? ($index['by_name'][$keyLower . '.md'] ?? null)));
                    $rel = null;
                    if ($found) {
                        $baseDir = resource_path('manuals');
                        $rel = str_replace('\\', '/', str_replace([$baseDir . DIRECTORY_SEPARATOR, $baseDir . '/'], '', $found));
                    }

                    \Illuminate\Support\Facades\DB::table('sys_page_manuals')
                        ->where('target_key', $targetKey)
                        ->update([
                            'relative_path' => $rel,
                            'is_custom_mapped' => false,
                            'parsed_content' => null,
                            'updated_at' => now(),
                        ]);
                }
            } catch (\Throwable) {}

            return $saved;
        }
        return true;
    }

    /**
     * Synchronize and seed all known system targets and their matching manual guides into sys_page_manuals table.
     */
    public static function syncDatabaseCatalog(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('sys_page_manuals')) {
            return 0;
        }

        $allTargets = self::getAllSystemTargets();
        $mappings = self::getManualMapping();
        $index = self::getFileIndex();
        $baseDir = resource_path('manuals');
        $synced = 0;

        foreach ($allTargets as $t) {
            $key = $t['key'];
            $keyLower = strtolower($key);
            $cleanModalKey = preg_replace('/^modal\./i', '', $keyLower);

            $isExplicitlyDisabled = isset($mappings[$key]) && in_array($mappings[$key], ['disabled', 'none', '']);
            $assignedFile = null;
            $isCustomMapped = false;

            if (!$isExplicitlyDisabled) {
                if (isset($mappings[$key]) && !empty($mappings[$key])) {
                    $assignedFile = $mappings[$key];
                    $isCustomMapped = true;
                } else {
                    $found = $index['by_modal_base'][$cleanModalKey]
                        ?? ($index['by_modal_base'][$keyLower]
                        ?? ($index['by_base'][$cleanModalKey]
                        ?? ($index['by_base'][$keyLower]
                        ?? ($index['by_path'][$keyLower . '.md']
                        ?? ($index['by_name'][$keyLower . '.md']
                        ?? ($index['by_path'][$cleanModalKey . '.md']
                        ?? ($index['by_name'][$cleanModalKey . '.md'] ?? null)))))));
                    if ($found) {
                        $assignedFile = str_replace('\\', '/', str_replace([$baseDir . DIRECTORY_SEPARATOR, $baseDir . '/'], '', $found));
                    }
                }
            }

            $lineCount = 0;
            if ($assignedFile) {
                $full = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $assignedFile), DIRECTORY_SEPARATOR);
                if (is_file($full)) {
                    $lines = @file($full, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $lineCount = is_array($lines) ? count($lines) : 0;
                }
            }

            \Illuminate\Support\Facades\DB::table('sys_page_manuals')->updateOrInsert(
                ['target_key' => $key],
                [
                    'target_name' => $t['name'],
                    'subsystem' => $t['subsystem'],
                    'type' => $t['type'],
                    'relative_path' => $assignedFile,
                    'title' => $t['name'],
                    'purpose' => $t['description'],
                    'line_count' => $lineCount,
                    'is_custom_mapped' => $isCustomMapped,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $synced++;
        }

        return $synced;
    }

    /**
     * Get raw markdown string of a manual file.
     */
    public static function getManualFileRaw(string $relativePath): ?string
    {
        $baseDir = resource_path('manuals');
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        if (!is_file($fullPath)) {
            return null;
        }
        return file_get_contents($fullPath) ?: '';
    }

    /**
     * Save/update raw markdown content of a manual file.
     */
    public static function saveManualFileContent(string $relativePath, string $content): bool
    {
        try {
            $baseDir = resource_path('manuals');
            $fullPath = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@file_put_contents($fullPath, $content) !== false) {
                return true;
            }
        } catch (\Throwable) {}

        try {
            $storageDir = storage_path('app/manuals');
            $fullPath = $storageDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            return @file_put_contents($fullPath, $content) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create a new manual file.
     *
     * @return string|null The created relative path, or null on failure.
     */
    public static function createManualFile(string $subsystem, string $fileName, string $content): ?string
    {
        $subsystem = strtolower(trim($subsystem));
        $fileName = trim($fileName);
        if (!str_ends_with(strtolower($fileName), '.md')) {
            $fileName .= '.md';
        }

        $baseDir = resource_path('manuals');
        $relativeDir = $subsystem ? $subsystem : '';
        $targetDir = $relativeDir ? $baseDir . DIRECTORY_SEPARATOR . $relativeDir : $baseDir;

        try {
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            $fullPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
            if (@file_put_contents($fullPath, $content) !== false) {
                $rel = $relativeDir ? ($relativeDir . '/' . $fileName) : $fileName;
                return str_replace('\\', '/', $rel);
            }
        } catch (\Throwable) {}

        // Fallback to storage/app/manuals
        try {
            $storageBase = storage_path('app/manuals');
            $targetDir = $relativeDir ? $storageBase . DIRECTORY_SEPARATOR . $relativeDir : $storageBase;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $fullPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
            if (@file_put_contents($fullPath, $content) !== false) {
                $rel = $relativeDir ? ($relativeDir . '/' . $fileName) : $fileName;
                return str_replace('\\', '/', $rel);
            }
        } catch (\Throwable) {}

        return null;
    }

    /**
     * Delete a manual markdown file.
     */
    public static function deleteManualFile(string $relativePath): bool
    {
        $baseDir = resource_path('manuals');
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        if (is_file($fullPath)) {
            $ok = @unlink($fullPath);
            // Also clean up mappings pointing to this deleted file
            $relClean = str_replace('\\', '/', ltrim($relativePath, '/\\'));
            $mappings = self::getManualMapping();
            $changed = false;
            foreach ($mappings as $k => $v) {
                if ($v === $relClean) {
                    unset($mappings[$k]);
                    $changed = true;
                }
            }
            if ($changed) {
                self::saveManualMapping($mappings);
            }
            return $ok;
        }
        return false;
    }

    /**
     * Get all manual files (.md) in resources/manuals.
     * Uses cache to avoid repeated file system checks on every Livewire update.
     *
     * @return array<int, array{name: string, relative_path: string, full_path: string, size: int, updated_at: int, subsystem: string, line_count: int, title: string, purpose: string}>
     */
    public static function getAllManualFiles(): array
    {
        return Cache::remember('rms_all_manual_files_meta', 3600, function () {
            $index = self::getFileIndex();
            $files = [];

            foreach ($index['all_files'] as $f) {
                $segments = explode('/', $f['relative_path']);
                $subsystem = 'common';
                if (count($segments) > 1) {
                    $subsystem = $segments[0] === 'modal' && isset($segments[1]) ? $segments[1] : $segments[0];
                }

                // Fast header read without loading all lines
                $title = $f['name'];
                $purpose = '';
                $lineCount = 0;
                $handle = @fopen($f['full_path'], 'r');
                if ($handle) {
                    $linesRead = 0;
                    while (($line = fgets($handle)) !== false) {
                        $trimmed = trim($line);
                        if ($trimmed !== '') {
                            $lineCount++;
                        }
                        if ($linesRead < 15) {
                            if (str_starts_with($trimmed, '# ') && $title === $f['name']) {
                                $title = trim(substr($trimmed, 2));
                            }
                            if (preg_match('/^>\s*Purpose:\s*(.*)$/i', $trimmed, $m)) {
                                $purpose = $m[1];
                            }
                            $linesRead++;
                        }
                    }
                    fclose($handle);
                }

                $files[] = [
                    'name' => $f['name'],
                    'relative_path' => $f['relative_path'],
                    'full_path' => $f['full_path'],
                    'size' => $f['size'],
                    'updated_at' => $f['updated_at'],
                    'subsystem' => strtoupper($subsystem),
                    'line_count' => $lineCount,
                    'title' => $title,
                    'purpose' => $purpose,
                ];
            }

            usort($files, fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));
            return $files;
        });
    }

    /**
     * Get comprehensive catalogue of all known system targets (pages and modals).
     *
     * @return array<int, array{key: string, name: string, type: 'page'|'modal', subsystem: string, route: ?string, description: string}>
     */
    public static function getAllSystemTargets(): array
    {
        return [
            // ── Document Tracking System (DTS) ──
            ['key' => 'dts', 'name' => 'DTS Dashboard', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts', 'description' => 'Main Document Tracking Overview and metrics'],
            ['key' => 'dts.incoming', 'name' => 'Incoming Transactions', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.incoming', 'description' => 'Pending documents dispatched to your office awaiting receipt'],
            ['key' => 'dts.received', 'name' => 'Received Documents', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.received', 'description' => 'Documents in your custody currently being processed'],
            ['key' => 'dts.forwarded', 'name' => 'Forwarded Documents', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.forwarded', 'description' => 'Transactions that left your office sent to other units'],
            ['key' => 'dts.my-transactions', 'name' => 'My Transactions', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.my-transactions', 'description' => 'Transactions initiated by your logged-in account'],
            ['key' => 'dts.receive', 'name' => 'Quick Receive Form', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.receive', 'description' => 'Manual tracking number entry to receive incoming documents'],
            ['key' => 'dts.scanner', 'name' => 'Scanner Terminal', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.scanner', 'description' => 'Barcode / QR code scanning terminal for instant processing'],
            ['key' => 'dts.create.internal', 'name' => 'Create Internal Document', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.create.internal', 'description' => 'Inter-office routing communications across CSPC campus'],
            ['key' => 'dts.create.external', 'name' => 'Create External Document', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.create.external', 'description' => 'Outbound or inbound communications with external entities'],
            ['key' => 'dts.create.issuances', 'name' => 'Create Issuances', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.create.issuances', 'description' => 'Special Orders, Office Orders, and Memorandum issuances'],
            ['key' => 'dts.create.application-letters', 'name' => 'Create Application Letter', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.create.application-letters', 'description' => 'Formal student and employee applications and endorsements'],
            ['key' => 'dts.list.internal', 'name' => 'Internal Masterlist', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.list.internal', 'description' => 'Comprehensive directory of internal transaction records'],
            ['key' => 'dts.list.external', 'name' => 'External Masterlist', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.list.external', 'description' => 'Comprehensive directory of external transaction records'],
            ['key' => 'dts.list.issuances', 'name' => 'Issuances Masterlist', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.list.issuances', 'description' => 'Archive and directory of official administrative issuances'],
            ['key' => 'dts.list.application-letters', 'name' => 'Applications Masterlist', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.list.application-letters', 'description' => 'Archive and registry of official application communications'],
            ['key' => 'dts.history.internal', 'name' => 'Internal Audit History', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.history.internal', 'description' => 'Audit logs and life transitions of internal documents'],
            ['key' => 'dts.history.external', 'name' => 'External Audit History', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.history.external', 'description' => 'Audit logs and timeline of external document tracking'],
            ['key' => 'dts.history.issuances', 'name' => 'Issuances Audit History', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.history.issuances', 'description' => 'Audit history of official memoranda and orders'],
            ['key' => 'dts.history.application-letters', 'name' => 'Applications Audit History', 'type' => 'page', 'subsystem' => 'DTS', 'route' => 'dts.history.application-letters', 'description' => 'Audit log entries for submitted application letters'],
            ['key' => 'modal.dts.transaction.details', 'name' => 'Transaction Details Modal', 'type' => 'modal', 'subsystem' => 'DTS', 'route' => null, 'description' => 'Modal showing full tracking status, timeline, and actions'],
            ['key' => 'modal.dts.forward.transaction', 'name' => 'Forward Transaction Modal', 'type' => 'modal', 'subsystem' => 'DTS', 'route' => null, 'description' => 'Modal for routing a document to its next office recipient'],
            ['key' => 'modal.dts.receive.transaction', 'name' => 'Receive Confirmation Modal', 'type' => 'modal', 'subsystem' => 'DTS', 'route' => null, 'description' => 'Modal for accepting custody of an incoming transaction'],
            ['key' => 'modal.dts.forward.hub', 'name' => 'Forward to HUB Modal', 'type' => 'modal', 'subsystem' => 'DTS', 'route' => null, 'description' => 'Modal for dispatching records through the Central Document HUB'],
            ['key' => 'modal.dts.export.print', 'name' => 'Export & Print Reports Modal', 'type' => 'modal', 'subsystem' => 'DTS', 'route' => null, 'description' => 'Dynamic filtering modal for PDF and CSV export / printing'],

            // ── Records Disposition Program (RDP) ──
            ['key' => 'rdp', 'name' => 'RDP Dashboard', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp', 'description' => 'Records Disposition Program Overview and retention summaries'],
            ['key' => 'rdp.add-records.inventory-and-appraisal', 'name' => 'Inventory & Appraisal Form', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.add-records.inventory-and-appraisal', 'description' => 'Registration and appraisal of newly catalogued record series'],
            ['key' => 'rdp.add-records.records-and-disposition-schedule', 'name' => 'RDS Schedule Form', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.add-records.records-and-disposition-schedule', 'description' => 'Records and Disposition Schedule series assignment'],
            ['key' => 'rdp.draft.inventory-and-appraisal', 'name' => 'Draft Inventory & Appraisal', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.draft.inventory-and-appraisal', 'description' => 'Unfinalized inventory records awaiting appraisal'],
            ['key' => 'rdp.draft.records-and-disposition-schedule', 'name' => 'Draft Disposition Schedule', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.draft.records-and-disposition-schedule', 'description' => 'Draft schedule templates and retention calculations'],
            ['key' => 'rdp.pending.list', 'name' => 'Pending Records Masterlist', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.pending.list', 'description' => 'Records undergoing review and appraisal queue'],
            ['key' => 'rdp.pending.for-approval', 'name' => 'Records For Approval', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.pending.for-approval', 'description' => 'Records submitted for administrator and NAP approval'],
            ['key' => 'rdp.manage-files', 'name' => 'Manage Files & Dispositions', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.manage-files', 'description' => 'Archived records lifecycle, storage locations, and disposal'],
            ['key' => 'rdp.received-documents.dcs', 'name' => 'Received DCS Documents', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.received-documents.dcs', 'description' => 'Controlled document registrations received from DCS'],
            ['key' => 'rdp.received-documents.dts', 'name' => 'Received DTS Documents', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.received-documents.dts', 'description' => 'Completed DTS transactions archived into records custody'],
            ['key' => 'rdp.received-documents.record-requests', 'name' => 'Received Record Requests', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.received-documents.record-requests', 'description' => 'Office intake requests and retrieval applications'],
            ['key' => 'rdp.references.show', 'name' => 'References & Archives', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.references.show', 'description' => 'National Archives of the Philippines circulars and reference files'],
            ['key' => 'rdp.reports.master-lifecycle', 'name' => 'Master Lifecycle Report', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.reports.master-lifecycle', 'description' => 'Comprehensive lifecycle matrix and retention periods report'],
            ['key' => 'rdp.reports.nap-form-1', 'name' => 'NAP Form 1 Report', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.reports.nap-form-1', 'description' => 'Official NAP Form No. 1 (Records Inventory and Appraisal)'],
            ['key' => 'rdp.reports.nap-form-2', 'name' => 'NAP Form 2 Report', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.reports.nap-form-2', 'description' => 'Official NAP Form No. 2 (Records Disposition Schedule)'],
            ['key' => 'rdp.reports.nap-form-3', 'name' => 'NAP Form 3 Report', 'type' => 'page', 'subsystem' => 'RDP', 'route' => 'rdp.reports.nap-form-3', 'description' => 'Official NAP Form No. 3 (Request for Disposal of Records)'],
            ['key' => 'modal.rdp.record.series', 'name' => 'Record Series Modal', 'type' => 'modal', 'subsystem' => 'RDP', 'route' => null, 'description' => 'Modal for creating or configuring record series classification'],
            ['key' => 'modal.rdp.cluster.creation', 'name' => 'Cluster Creation Modal', 'type' => 'modal', 'subsystem' => 'RDP', 'route' => null, 'description' => 'Modal for batch clustering related record series'],
            ['key' => 'modal.rdp.duplicate.offices', 'name' => 'Duplicate Offices Modal', 'type' => 'modal', 'subsystem' => 'RDP', 'route' => null, 'description' => 'Modal for resolving multi-office record duplications'],

            // ── Document Control System (DCS) ──
            ['key' => 'dcs', 'name' => 'DCS Dashboard', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs', 'description' => 'Document Control System dashboard and review notifications'],
            ['key' => 'dcs.register.index', 'name' => 'Register Masterlist', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.register.index', 'description' => 'Institutional controlled documents register and upload'],
            ['key' => 'dcs.register.edit', 'name' => 'Edit Masterlist Document', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.register.edit', 'description' => 'Correction and revision form for registered documents'],
            ['key' => 'dcs.office.drf-index', 'name' => 'DRF Office Requests', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.drf-index', 'description' => 'Document Request Forms directory for departments'],
            ['key' => 'dcs.office.drf-create', 'name' => 'Create DRF Request', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.drf-create', 'description' => 'Form to request new creation, revision, or adoption of document'],
            ['key' => 'dcs.office.drf-edit', 'name' => 'Edit DRF Request', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.drf-edit', 'description' => 'Update or resubmit unlocked DRF request'],
            ['key' => 'dcs.office.dcn-index', 'name' => 'DCN Office Notices', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.dcn-index', 'description' => 'Document Change Notices masterlist and distribution'],
            ['key' => 'dcs.office.dcn-create', 'name' => 'Create DCN Notice', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.dcn-create', 'description' => 'Form to announce document changes, obsolescence, or updates'],
            ['key' => 'dcs.office.dcn-edit', 'name' => 'Edit DCN Notice', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.dcn-edit', 'description' => 'Update or resubmit unlocked DCN notice'],
            ['key' => 'dcs.office.incoming', 'name' => 'Office Incoming Intakes', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.incoming', 'description' => 'Controlled document copies distributed to your unit'],
            ['key' => 'dcs.office.documents', 'name' => 'Office Controlled Documents', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.office.documents', 'description' => 'Active controlled documents in custody of your office'],
            ['key' => 'dcs.review', 'name' => 'Review & Stamping Desk', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.review', 'description' => 'Central review terminal with PDF watermarking and QR stamping'],
            ['key' => 'dcs.history', 'name' => 'DCS Audit History', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.history', 'description' => 'Complete audit history of masterlist document modifications'],
            ['key' => 'dcs.reports', 'name' => 'DCS Reports', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.reports', 'description' => 'Distribution logs and masterlist reports generation'],
            ['key' => 'dcs.recycle-bin', 'name' => 'DCS Recycle Bin', 'type' => 'page', 'subsystem' => 'DCS', 'route' => 'dcs.recycle-bin', 'description' => 'Soft-deleted masterlist registrations and recovery'],
            ['key' => 'modal.dcs.drf.preview', 'name' => 'DRF Preview Modal', 'type' => 'modal', 'subsystem' => 'DCS', 'route' => null, 'description' => 'Modal showing printable DRF request sheet and signatures'],
            ['key' => 'modal.dcs.stamping.config', 'name' => 'PDF Stamping Config Modal', 'type' => 'modal', 'subsystem' => 'DCS', 'route' => null, 'description' => 'Modal for configuring dynamic header stamps and watermarks'],

            // ── Admin Console ──
            ['key' => 'admin.console', 'name' => 'Admin Console Dashboard', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.console', 'description' => 'System administrator main dashboard and operational health'],
            ['key' => 'admin.tickets', 'name' => 'Error Tickets & Popouts', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.tickets', 'description' => 'Central error triage, exception inspector, and user feedback'],
            ['key' => 'admin.manuals.index', 'name' => 'Page Manuals Manager', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.manuals.index', 'description' => 'Assign, edit, audit, and remove interactive page & modal manuals'],
            ['key' => 'admin.accounts.users', 'name' => 'Users Management', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.accounts.users', 'description' => 'User account provisioning, roles, and office assignments'],
            ['key' => 'admin.accounts.roles', 'name' => 'Roles & Permissions Matrix', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.accounts.roles', 'description' => 'Role configurations and fine-grained subsystem permissions'],
            ['key' => 'admin.accounts.offices', 'name' => 'Offices & Units Management', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.accounts.offices', 'description' => 'Campus offices, departments, units, and campus codes'],
            ['key' => 'admin.activity.logins', 'name' => 'Login Activity Logs', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.activity.logins', 'description' => 'User authentication audit logs, IP addresses, and sessions'],
            ['key' => 'admin.activity.account-changes', 'name' => 'Account Changes Audit', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.activity.account-changes', 'description' => 'Modifications to user profiles, roles, and office changes'],
            ['key' => 'admin.activity.file-uploads', 'name' => 'File Upload Logs', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.activity.file-uploads', 'description' => 'Audit logs of files uploaded to Google Drive, NAS, or Local'],
            ['key' => 'admin.activity.notifications', 'name' => 'Notification Logs', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.activity.notifications', 'description' => 'Delivery history of system notifications and alerts'],
            ['key' => 'admin.subsystems.add', 'name' => 'Add Subsystem', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.subsystems.add', 'description' => 'Subsystem registration and module installation'],
            ['key' => 'admin.subsystems.activate', 'name' => 'Activate Subsystems', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.subsystems.activate', 'description' => 'Module enablement across university portal'],
            ['key' => 'admin.subsystems.deactivate', 'name' => 'Deactivate Subsystems', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.subsystems.deactivate', 'description' => 'Temporarily suspend access to specific modules'],
            ['key' => 'admin.dts.transaction-flows', 'name' => 'DTS Flow Paths Config', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.dts.transaction-flows', 'description' => 'Manage automated flow steps and sequential routing rules'],
            ['key' => 'admin.dts.action-options', 'name' => 'DTS Action Options', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.dts.action-options', 'description' => 'Configure action dropdown options for transaction routing'],
            ['key' => 'admin.rdp.conversions', 'name' => 'RDP Volume Conversions', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.rdp.conversions', 'description' => 'Conversion factors for physical storage (meters to volume)'],
            ['key' => 'admin.rdp.record-series', 'name' => 'RDP Record Series Catalog', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.rdp.record-series', 'description' => 'Master records disposition schedule series definitions'],
            ['key' => 'admin.rdp.brackets', 'name' => 'RDP Retention Brackets', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.rdp.brackets', 'description' => 'Manage legal retention rules and disposition classifications'],
            ['key' => 'admin.settings.index', 'name' => 'System & Cloud Settings', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.settings.index', 'description' => 'Google Drive, S3/NAS, SSO, and global system configurations'],
            ['key' => 'admin.server-settings.current', 'name' => 'Server & Clustering', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.server-settings.current', 'description' => 'Node cluster health, services, and live server topology'],
            ['key' => 'admin.server-settings.diagnostic', 'name' => 'Server Diagnostics', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.server-settings.diagnostic', 'description' => 'Real-time telemetry, memory, CPU, and database health'],
            ['key' => 'admin.server-settings.terminal', 'name' => 'Server Host Terminal', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.server-settings.terminal', 'description' => 'Interactive Docker socket shell for host administration'],
            ['key' => 'admin.backup.index', 'name' => 'Backup & Recovery', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.backup.index', 'description' => 'Automated database backups, manual dumps, and restoration'],
            ['key' => 'admin.recycle-bin', 'name' => 'Admin Global Recycle Bin', 'type' => 'page', 'subsystem' => 'ADMIN', 'route' => 'admin.recycle-bin', 'description' => 'Global archive of soft-deleted records across all subsystems'],

            // ── Portal & Profile ──
            ['key' => 'portal', 'name' => 'Subsystem Portal', 'type' => 'page', 'subsystem' => 'COMMON', 'route' => 'portal', 'description' => 'Main institutional access point and application launcher'],
            ['key' => 'profile.index', 'name' => 'User Profile', 'type' => 'page', 'subsystem' => 'COMMON', 'route' => 'profile.index', 'description' => 'Personal account information, details, and active sessions'],
            ['key' => 'profile.settings', 'name' => 'Profile Preferences', 'type' => 'page', 'subsystem' => 'COMMON', 'route' => 'profile.settings', 'description' => 'Keybindings, audio alerts, and dark mode preferences'],
        ];
    }
}

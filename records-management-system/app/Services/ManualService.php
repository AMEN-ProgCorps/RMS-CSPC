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

        return self::getManualByName($routeName);
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
        $segments = explode('.', $normalized);
        $subsystem = strtolower($segments[0] ?? '');

        // 1. Check explicit custom mappings (mappings.json)
        $mappings = self::getManualMapping();
        $mappedPath = $mappings[$name] ?? ($mappings[$normalized] ?? null);
        if ($mappedPath !== null) {
            $mappedPath = trim((string) $mappedPath);
            // If explicitly disabled or unassigned by admin, return null
            if ($mappedPath === '' || $mappedPath === 'none' || $mappedPath === 'disabled') {
                return null;
            }
            $targetFull = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $mappedPath), DIRECTORY_SEPARATOR);
            if (is_file($targetFull)) {
                return self::parseManualFile($targetFull);
            }
        }

        // Direct candidate lookups based on organized directory structure
        $candidates = [
            // Subsystem folder: e.g. manuals/dts/dts.create.internal.md
            $baseDir . DIRECTORY_SEPARATOR . $subsystem . DIRECTORY_SEPARATOR . $normalized . '.md',
            // Direct path: e.g. manuals/dts.create.internal.md
            $baseDir . DIRECTORY_SEPARATOR . $normalized . '.md',
            // Modal path with prefix: e.g. manuals/modal/dts/transaction.details.modal.md
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . $subsystem . DIRECTORY_SEPARATOR . $normalized . '.modal.md',
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . $subsystem . DIRECTORY_SEPARATOR . $normalized . '.md',
            // Sub-path nested: e.g. manuals/modal/dts/transaction.details.modal.md
            $baseDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $normalized) . '.modal.md',
            $baseDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $normalized) . '.md',
            // If passed modal.xxx, try without 'modal.'
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, preg_replace('/^modal\./i', '', $normalized)) . '.modal.md',
            $baseDir . DIRECTORY_SEPARATOR . 'modal' . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, preg_replace('/^modal\./i', '', $normalized)) . '.md',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return self::parseManualFile($candidate);
            }
        }

        // Fuzzy search in manuals directory (helpful for short modal keys like 'transaction.details' or 'record.series')
        $needle = strtolower(str_replace(['_', '-'], '.', $normalized));
        $rdi = new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $rii = new \RecursiveIteratorIterator($rdi);

        foreach ($rii as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $filename = strtolower($file->getFilename());
                $nameWithoutExt = str_replace('.md', '', $filename);
                $nameWithoutModal = str_replace('.modal', '', $nameWithoutExt);

                if (
                    $nameWithoutExt === $needle ||
                    $nameWithoutModal === $needle ||
                    str_ends_with($nameWithoutModal, '.' . $needle) ||
                    str_ends_with($needle, '.' . $nameWithoutModal)
                ) {
                    return self::parseManualFile($file->getPathname());
                }
            }
        }

        return null;
    }

    /**
     * Parse a markdown manual file into structured lines, sections, and callouts.
     */
    public static function parseManualFile(string $filePath): ?array
    {
        if (!is_file($filePath)) {
            return null;
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
                    $activeCallout = ['type' => $type, 'title' => ucfirst(strtolower($m[1])), 'text' => trim($m[2])];
                } elseif (preg_match('/^\[!(WARNING|CAUTION|ALERT)\]\s*(.*)$/i', $calloutText, $m)) {
                    if ($activeCallout) {
                        $callouts[] = $activeCallout;
                    }
                    $activeCallout = ['type' => 'warning', 'title' => ucfirst(strtolower($m[1])), 'text' => trim($m[2])];
                } elseif ($activeCallout !== null) {
                    $activeCallout['text'] = trim($activeCallout['text'] . ' ' . $calloutText);
                } elseif (empty($purpose)) {
                    $purpose = preg_replace('/^(Purpose:\s*)/i', '', $calloutText);
                } else {
                    $callouts[] = ['type' => 'note', 'title' => 'Note', 'text' => $calloutText];
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

                // Check for bold label like "**Field Name**: description"
                $label = '';
                $desc = $cleanText;
                if (preg_match('/^\*\*(.*?)\*\*:\s*(.*)$/', $cleanText, $matches)) {
                    $label = $matches[1];
                    $desc = $matches[2];
                }

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

        return [
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
     */
    public static function getMappingsFilePath(): string
    {
        return resource_path('manuals/mappings.json');
    }

    /**
     * Get all custom manual mappings from mappings.json.
     *
     * @return array<string, string> Key: route or modal name, Value: relative path in manuals/ or 'disabled'
     */
    public static function getManualMapping(): array
    {
        $path = self::getMappingsFilePath();
        if (!is_file($path)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($path), true);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Save manual mappings to mappings.json.
     */
    public static function saveManualMapping(array $mappings): bool
    {
        $path = self::getMappingsFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return file_put_contents($path, json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
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
        if (empty($fileRelativePath) || $fileRelativePath === 'disabled' || $fileRelativePath === 'none') {
            $mappings[$targetKey] = 'disabled';
        } else {
            $mappings[$targetKey] = ltrim(str_replace('\\', '/', $fileRelativePath), '/');
        }

        return self::saveManualMapping($mappings);
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
            return self::saveManualMapping($mappings);
        }
        return true;
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
        $baseDir = resource_path('manuals');
        $fullPath = $baseDir . DIRECTORY_SEPARATOR . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return file_put_contents($fullPath, $content) !== false;
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

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $fullPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($fullPath, $content) !== false) {
            $rel = $relativeDir ? ($relativeDir . '/' . $fileName) : $fileName;
            return str_replace('\\', '/', $rel);
        }

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
     *
     * @return array<int, array{name: string, relative_path: string, full_path: string, size: int, updated_at: int, subsystem: string, line_count: int, title: string}>
     */
    public static function getAllManualFiles(): array
    {
        $baseDir = resource_path('manuals');
        if (!is_dir($baseDir)) {
            return [];
        }

        $files = [];
        $rdi = new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $rii = new \RecursiveIteratorIterator($rdi);

        foreach ($rii as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $filename = $file->getFilename();
                // Exclude system navigation guides
                if (strtolower($filename) === 'nav-guides.md') {
                    continue;
                }

                $fullPath = $file->getPathname();
                $relPath = str_replace([$baseDir . DIRECTORY_SEPARATOR, $baseDir . '/'], '', $fullPath);
                $relPathClean = str_replace('\\', '/', $relPath);

                $segments = explode('/', $relPathClean);
                $subsystem = 'common';
                if (count($segments) > 1) {
                    $subsystem = $segments[0] === 'modal' && isset($segments[1]) ? $segments[1] : $segments[0];
                }

                $parsed = self::parseManualFile($fullPath);

                $files[] = [
                    'name' => $filename,
                    'relative_path' => $relPathClean,
                    'full_path' => $fullPath,
                    'size' => $file->getSize(),
                    'updated_at' => $file->getMTime(),
                    'subsystem' => strtoupper($subsystem),
                    'line_count' => $parsed['line_count'] ?? 0,
                    'title' => $parsed['title'] ?? $filename,
                    'purpose' => $parsed['purpose'] ?? '',
                ];
            }
        }

        usort($files, fn($a, $b) => strcmp($a['relative_path'], $b['relative_path']));
        return $files;
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

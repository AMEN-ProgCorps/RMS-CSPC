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

        return [
            'title' => $title ?: 'Page Manual',
            'purpose' => $purpose,
            'sections' => $sections,
            'callouts' => $callouts,
            'line_count' => $lineCount,
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
}

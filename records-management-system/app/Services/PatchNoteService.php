<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PatchNoteService
{
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
     *     date: string,
     *     is_latest: bool,
     *     summary: ?string,
     *     categories: array<int, array{name: string, type: string, items: array<int, array{label: string, text: string, raw: string}>}>,
     *     raw: string
     * }>
     */
    public static function getReleases(): array
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

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Version Heading: e.g. ## [v2.4.2] - 2026-11-21 or ## v2.4.2 or ## [2.4.2]
            if (preg_match('/^##\s+\[?(v?[\d\.]+(?:-[a-zA-Z0-9\.]+)?)\]?(?:\s*-\s*([0-9]{4}-[0-9]{2}-[0-9]{2}))?/i', $trimmed, $m)) {
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

                $version = trim($m[1]);
                $cleanVer = ltrim($version, 'vV');
                $date = isset($m[2]) ? trim($m[2]) : '';

                $currentRelease = [
                    'version' => str_starts_with(strtolower($version), 'v') ? $version : 'v' . $version,
                    'clean_version' => $cleanVer,
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
                // Header preamble before the first release
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
                    // Item outside explicit category heading
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

        return $releases;
    }

    /**
     * Get the newest / latest release metadata.
     */
    public static function getLatestRelease(): ?array
    {
        $releases = self::getReleases();
        return $releases[0] ?? null;
    }

    /**
     * Insert a new release entry into the markdown file.
     */
    public static function createRelease(
        string $version,
        string $date,
        array $itemsByCategory,
        ?string $summary = null
    ): bool {
        $content = self::getRawContent();
        $version = trim($version);
        if (!str_starts_with(strtolower($version), 'v')) {
            $version = 'v' . $version;
        }
        $date = trim($date) ?: now()->format('Y-m-d');

        $newBlock = "\n## [{$version}] - {$date}\n";
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

        return self::saveRawContent($finalContent);
    }
}

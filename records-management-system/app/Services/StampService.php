<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

/**
 * Apply PDF stamps (Reference, and historical Controlled/Obsolete/etc. labels).
 *
 * UI: pages/dcs/stamping/index.blade.php
 * Backups: StampBackupService (unstamped originals under storage/app/private/stamp_backups)
 *
 * DEBUG MAP:
 *   Stamp misplaced / wrong page      → auto placement cache + FPDI coordinates
 *   Cannot restamp after replace scan → StampBackupService::invalidate on edit upload
 *   Only Reference allowed going fwd  → STAMPS vs STAMP_LABELS
 */
class StampService
{
    public const MAX_STAMP_PAGES = 100;

    /** @var array<string, array{x: float, y: float}> */
    private array $autoPlacementCache = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** Labels for historical stamp records (UI display only). */
    private const STAMP_LABELS = [
        'controlled'          => 'Controlled',
        'obsolete'            => 'Obsolete',
        'master_copy'         => 'Master Copy',
        'reference'           => 'Reference',
        'certified_true_copy' => 'Certified True Copy',
    ];

    /** Built-in Reference stamp (always available). Custom imported stamps are stored in customStampsDir(). */
    private const STAMPS = [
        'reference' => [
            'label'     => 'Reference',
            'title'     => 'REFERENCE',
            'subtitle'  => '',
            'color'     => [176, 48, 48],
            // Aspect matches reference.png (~2.8:1) including signature overhang.
            'width'     => 36.0,
            'height'    => 13.0,
            'titleSize' => 9,
            'subSize'   => 5,
            'image'     => 'images/stamps/reference.png',
        ],
    ];

    // ──────────────────────────────────────────
    // CUSTOM STAMPS REGISTRY (persistent files + JSON manifest)
    // ──────────────────────────────────────────

    private function customStampsDir(): string
    {
        $dir = storage_path('app/private/stamps/custom');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function customStampsManifestPath(): string
    {
        return $this->customStampsDir() . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCustomStampManifest(): array
    {
        $dir = $this->customStampsDir();
        if (Schema::hasTable('dcs_custom_stamps')) {
            $rows = DB::table('dcs_custom_stamps')->orderBy('id')->get();
            $out = [];
            foreach ($rows as $row) {
                $id = trim((string) ($row->stamp_key ?? ''));
                $filename = basename((string) ($row->filename ?? ''));
                if ($id === '' || $filename === '' || ! is_file($dir . DIRECTORY_SEPARATOR . $filename)) {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'label' => (string) ($row->label ?? 'Custom Stamp'),
                    'filename' => $filename,
                    'width_mm' => (float) ($row->width_mm ?? 36.0),
                    'height_mm' => (float) ($row->height_mm ?? 15.0),
                    'px_width' => (int) ($row->px_width ?? 0),
                    'px_height' => (int) ($row->px_height ?? 0),
                    'created_by' => $row->created_by ? (int) $row->created_by : null,
                    'updated_ts' => $row->updated_at ? strtotime((string) $row->updated_at) : time(),
                ];
            }

            return $out;
        }

        $path = $this->customStampsManifestPath();
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            $filename = basename((string) ($item['filename'] ?? ''));
            if ($id === '' || $filename === '' || ! is_file($dir . DIRECTORY_SEPARATOR . $filename)) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeCustomStampManifest(array $items): void
    {
        if (Schema::hasTable('dcs_custom_stamps')) {
            $now = now();
            $keepKeys = [];
            DB::transaction(function () use ($items, $now, &$keepKeys) {
                foreach ($items as $item) {
                    $key = trim((string) ($item['id'] ?? ''));
                    $filename = basename((string) ($item['filename'] ?? ''));
                    if ($key === '' || $filename === '') {
                        continue;
                    }
                    $keepKeys[] = $key;
                    DB::table('dcs_custom_stamps')->updateOrInsert(
                        ['stamp_key' => mb_substr($key, 0, 64)],
                        [
                            'label' => mb_substr(trim((string) ($item['label'] ?? 'Custom Stamp')), 0, 120),
                            'filename' => mb_substr($filename, 0, 255),
                            'width_mm' => max(10.0, min(160.0, (float) ($item['width_mm'] ?? 36.0))),
                            'height_mm' => max(6.0, min(160.0, (float) ($item['height_mm'] ?? 15.0))),
                            'px_width' => max(0, (int) ($item['px_width'] ?? 0)),
                            'px_height' => max(0, (int) ($item['px_height'] ?? 0)),
                            'created_by' => ! empty($item['created_by']) ? (int) $item['created_by'] : Auth::id(),
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
                if ($keepKeys === []) {
                    DB::table('dcs_custom_stamps')->delete();
                } else {
                    DB::table('dcs_custom_stamps')->whereNotIn('stamp_key', $keepKeys)->delete();
                }
            });

            return;
        }

        $path = $this->customStampsManifestPath();
        $json = json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not save custom stamps registry.');
        }
    }

    /**
     * Returns all available stamps (built-in + custom imported) for the Stamping UI.
     *
     * @return list<array<string, mixed>>
     */
    public function availableStampsCatalog(): array
    {
        $refPath = public_path('images/stamps/reference.png');
        $refVer = @filemtime($refPath) ?: time();
        $items = [[
            'id'        => 'reference',
            'label'     => 'Reference',
            'title'     => 'REFERENCE',
            'color'     => '#b03030',
            'width_mm'  => 36.0,
            'height_mm' => 13.0,
            'aspect'    => round(36.0 / 13.0, 4),
            'image_url' => asset('images/stamps/reference.png') . '?v=' . $refVer,
            'is_custom' => false,
        ]];

        foreach ($this->readCustomStampManifest() as $entry) {
            $id = (string) $entry['id'];
            $w = max(10.0, min(160.0, (float) ($entry['width_mm'] ?? 36.0)));
            $h = max(6.0, min(160.0, (float) ($entry['height_mm'] ?? 15.0)));
            $ver = (int) ($entry['updated_ts'] ?? time());
            $items[] = [
                'id'        => $id,
                'label'     => (string) ($entry['label'] ?? 'Custom Stamp'),
                'title'     => mb_strtoupper((string) ($entry['label'] ?? 'CUSTOM')),
                'color'     => '#1d4ed8',
                'width_mm'  => round($w, 2),
                'height_mm' => round($h, 2),
                'aspect'    => round($w / max(0.1, $h), 4),
                'image_url' => '/dcs/stamp/custom/' . rawurlencode($id) . '/image?v=' . $ver,
                'is_custom' => true,
            ];
        }

        return $items;
    }

    public function listCustomStamps()
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');

        return response()->json([
            'success' => true,
            'stamps'  => $this->availableStampsCatalog(),
        ]);
    }

    public function uploadCustomStamp(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        $rateCheck = RateLimiterService::check('dcs_action');
        if (! $rateCheck['allowed']) {
            return response()->json(['success' => false, 'message' => $rateCheck['message']], 429);
        }

        $validated = $request->validate([
            'label' => 'required|string|max:60',
            'image' => 'required|file|mimes:png,jpg,jpeg,webp|max:4096',
        ]);

        $label = trim((string) $validated['label']);
        if ($label === '') {
            return response()->json(['success' => false, 'message' => 'Enter a stamp name.'], 422);
        }

        $file = $request->file('image');
        $realPath = $file->getRealPath();
        $info = $realPath ? @getimagesize($realPath) : false;
        if (! is_array($info) || empty($info[0]) || empty($info[1])) {
            return response()->json(['success' => false, 'message' => 'Could not read image dimensions. Upload a valid PNG, JPG, or WebP image.'], 422);
        }

        $pxW = (int) $info[0];
        $pxH = (int) $info[1];
        $mime = strtolower((string) ($info['mime'] ?? $file->getMimeType() ?? 'image/png'));

        $id = 'custom_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $dir = $this->customStampsDir();

        // Store as PNG (converting WebP to PNG so FPDI can embed it reliably).
        $isJpeg = in_array($mime, ['image/jpeg', 'image/jpg'], true);
        $ext = $isJpeg ? 'jpg' : 'png';
        $filename = $id . '.' . $ext;
        $destPath = $dir . DIRECTORY_SEPARATOR . $filename;

        if ($mime === 'image/webp') {
            $converted = false;
            if (function_exists('imagecreatefromwebp') && function_exists('imagepng')) {
                $gd = @imagecreatefromwebp($realPath);
                if ($gd !== false) {
                    @imagesavealpha($gd, true);
                    $converted = @imagepng($gd, $destPath);
                    @imagedestroy($gd);
                }
            } elseif (class_exists(\Imagick::class)) {
                try {
                    $im = new \Imagick($realPath);
                    $im->setImageFormat('png');
                    $im->writeImage($destPath);
                    $im->clear();
                    $converted = is_file($destPath);
                } catch (\Throwable) {
                    $converted = false;
                }
            }
            if (! $converted) {
                return response()->json(['success' => false, 'message' => 'WebP conversion is unavailable on this server. Please upload a PNG or JPG image.'], 422);
            }
            $mime = 'image/png';
        } else {
            if (! @copy($realPath, $destPath)) {
                return response()->json(['success' => false, 'message' => 'Could not save stamp image on the server.'], 500);
            }
        }

        // Default physical size in mm preserving exact image aspect ratio.
        $aspect = $pxW / max(1, $pxH);
        $defaultW = 36.0;
        $defaultH = round($defaultW / max(0.15, $aspect), 2);
        if ($defaultH > 45.0) {
            $defaultH = 45.0;
            $defaultW = round($defaultH * $aspect, 2);
        } elseif ($defaultH < 10.0) {
            $defaultH = 10.0;
            $defaultW = round($defaultH * $aspect, 2);
        }
        $defaultW = max(12.0, min(90.0, $defaultW));
        $defaultH = max(8.0, min(90.0, $defaultH));

        $nowTs = time();
        $entry = [
            'id'         => $id,
            'label'      => $label,
            'filename'   => $filename,
            'mime'       => $mime,
            'fpdi_type'  => $isJpeg ? 'JPEG' : 'PNG',
            'width_mm'   => $defaultW,
            'height_mm'  => $defaultH,
            'aspect'     => round($aspect, 4),
            'created_by' => Auth::id(),
            'created_at' => now()->toIso8601String(),
            'updated_ts' => $nowTs,
        ];

        $manifest = $this->readCustomStampManifest();
        $manifest[] = $entry;
        $this->writeCustomStampManifest($manifest);

        \App\Helpers\RegisterPersistHelper::logAdminChange('Added custom stamp: ' . $label);

        return response()->json([
            'success' => true,
            'message' => 'Custom stamp "' . $label . '" imported.',
            'stamp'   => [
                'id'        => $id,
                'label'     => $label,
                'title'     => mb_strtoupper($label),
                'color'     => '#1d4ed8',
                'width_mm'  => $defaultW,
                'height_mm' => $defaultH,
                'aspect'    => round($aspect, 4),
                'image_url' => '/dcs/stamp/custom/' . rawurlencode($id) . '/image?v=' . $nowTs,
                'is_custom' => true,
            ],
            'stamps' => $this->availableStampsCatalog(),
        ]);
    }

    public function deleteCustomStamp(string $id)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        if (! preg_match('/^custom_[a-z0-9_-]{4,40}$/i', $id)) {
            return response()->json(['success' => false, 'message' => 'Invalid custom stamp ID.'], 422);
        }

        $manifest = $this->readCustomStampManifest();
        $removed = null;
        $remaining = [];
        foreach ($manifest as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                $removed = $item;
            } else {
                $remaining[] = $item;
            }
        }

        if ($removed === null) {
            return response()->json(['success' => false, 'message' => 'Custom stamp not found.'], 404);
        }

        $file = $this->customStampsDir() . DIRECTORY_SEPARATOR . basename((string) ($removed['filename'] ?? ''));
        if (is_file($file)) {
            @unlink($file);
        }
        $this->writeCustomStampManifest($remaining);

        \App\Helpers\RegisterPersistHelper::logAdminChange('Deleted custom stamp: ' . ($removed['label'] ?? $id));

        return response()->json([
            'success' => true,
            'message' => 'Custom stamp removed.',
            'stamps'  => $this->availableStampsCatalog(),
        ]);
    }

    public function serveCustomStampImage(string $id)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        if (! preg_match('/^custom_[a-z0-9_-]{4,40}$/i', $id)) {
            abort(404);
        }

        foreach ($this->readCustomStampManifest() as $item) {
            if ((string) ($item['id'] ?? '') !== $id) {
                continue;
            }
            $file = $this->customStampsDir() . DIRECTORY_SEPARATOR . basename((string) ($item['filename'] ?? ''));
            if (! is_file($file) || ! is_readable($file)) {
                abort(404);
            }
            $mime = (string) ($item['mime'] ?? 'image/png');

            return response()->file($file, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        abort(404);
    }

    /**
     * Resolves a stamp definition (built-in or custom) and applies optional resize overrides in mm.
     *
     * @return array<string, mixed>|null
     */
    private function resolveStampConfig(string $stampType, ?float $overrideWidthMm = null, ?float $overrideHeightMm = null): ?array
    {
        $cfg = null;
        if (isset(self::STAMPS[$stampType])) {
            $cfg = self::STAMPS[$stampType];
        } else {
            foreach ($this->readCustomStampManifest() as $item) {
                if ((string) ($item['id'] ?? '') === $stampType) {
                    $file = $this->customStampsDir() . DIRECTORY_SEPARATOR . basename((string) ($item['filename'] ?? ''));
                    $cfg = [
                        'label'               => (string) ($item['label'] ?? 'Custom Stamp'),
                        'title'               => mb_strtoupper((string) ($item['label'] ?? 'CUSTOM')),
                        'subtitle'            => '',
                        'color'               => [29, 78, 216],
                        'width'               => (float) ($item['width_mm'] ?? 36.0),
                        'height'              => (float) ($item['height_mm'] ?? 15.0),
                        'titleSize'           => 9,
                        'subSize'             => 5,
                        'image'               => '',
                        'absolute_image_path' => $file,
                        'fpdi_type'           => (string) ($item['fpdi_type'] ?? 'PNG'),
                    ];
                    break;
                }
            }
        }

        if (! $cfg) {
            return null;
        }

        $baseW = max(1.0, (float) $cfg['width']);
        $baseH = max(1.0, (float) $cfg['height']);
        $aspect = $baseW / $baseH;

        if ($overrideWidthMm !== null && $overrideWidthMm > 0) {
            $w = max(8.0, min(160.0, $overrideWidthMm));
            $h = ($overrideHeightMm !== null && $overrideHeightMm > 0)
                ? max(5.0, min(160.0, $overrideHeightMm))
                : max(5.0, min(160.0, round($w / max(0.1, $aspect), 2)));
            $cfg['width'] = round($w, 2);
            $cfg['height'] = round($h, 2);
        } elseif ($overrideHeightMm !== null && $overrideHeightMm > 0) {
            $h = max(5.0, min(160.0, $overrideHeightMm));
            $w = max(8.0, min(160.0, round($h * $aspect, 2)));
            $cfg['width'] = round($w, 2);
            $cfg['height'] = round($h, 2);
        }

        return $cfg;
    }

    public function stampLabelFor(string $stampType): string
    {
        if (isset(self::STAMPS[$stampType]['label'])) {
            return (string) self::STAMPS[$stampType]['label'];
        }
        if (isset(self::STAMP_LABELS[$stampType])) {
            return (string) self::STAMP_LABELS[$stampType];
        }
        foreach ($this->readCustomStampManifest() as $item) {
            if ((string) ($item['id'] ?? '') === $stampType) {
                return (string) ($item['label'] ?? $stampType);
            }
        }

        return $stampType;
    }

    // ──────────────────────────────────────────
    // SHARED VALIDATION
    // ──────────────────────────────────────────

    /**
     * Validates request input for validate stamp payload.
     */
    private function validateStampPayload(Request $request): array
    {
        $validated = $request->validate([
            'file_key'     => ['required', 'string', 'max:50', 'in:masterlist'],
            'request_id'   => 'required|integer|exists:dcs_document_requests,id',
            'doc_no'       => 'nullable|string|max:100',
            'doc_title'    => 'nullable|string|max:500',
            'rev'          => 'nullable|string|max:20',
            'stamp_type'   => 'required|string|max:50',
            'stamp_width_mm'  => 'nullable|numeric|min:8|max:160',
            'stamp_height_mm' => 'nullable|numeric|min:5|max:160',
            'position'     => 'required|string|in:top-left,top-right,bottom-left,bottom-right,center,auto,custom',
            'x_pct'        => 'nullable|numeric|min:0|max:100',
            'y_pct'        => 'nullable|numeric|min:0|max:100',
            'all_pages'    => 'required|boolean',
            'page_positions' => 'nullable|array|max:' . self::MAX_STAMP_PAGES,
            'page_positions.*.page' => 'required|integer|min:1|max:' . self::MAX_STAMP_PAGES,
            'page_positions.*.x_pct' => 'required|numeric|min:0|max:100',
            'page_positions.*.y_pct' => 'required|numeric|min:0|max:100',
        ]);

        if (! $this->resolveStampConfig((string) $validated['stamp_type'])) {
            abort(422, 'Unsupported or removed stamp type.');
        }

        if ($validated['position'] === 'custom') {
            $hasPagePositions = !empty($validated['page_positions']);
            $hasFallback = isset($validated['x_pct'], $validated['y_pct']);
            if (!$hasPagePositions && !$hasFallback) {
                abort(422, 'Custom stamp placement requires coordinates.');
            }
        }

        $relative = $this->storedScanRelativePath((int) $validated['request_id'], $validated['file_key']);
        if (!$relative) {
            abort(422, 'No scanned file is stored for this document.');
        }
        \App\Helpers\RegisterQueryHelper::assertCanAccessRequest((int) $validated['request_id']);
        $validated['file_path'] = $relative;
        $validated['page_positions'] = $this->normalizePagePositions($validated['page_positions'] ?? null);

        return $validated;
    }

    /**
     * @param  array<int, array{page?:mixed,x_pct?:mixed,y_pct?:mixed}>|null  $rows
     * @return array<int, array{x_pct: float, y_pct: float}>
     */
    private function normalizePagePositions(?array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = (int) ($row['page'] ?? 0);
            if ($page < 1) {
                continue;
            }
            $out[$page] = [
                'x_pct' => max(0.0, min(100.0, (float) ($row['x_pct'] ?? 0))),
                'y_pct' => max(0.0, min(100.0, (float) ($row['y_pct'] ?? 0))),
            ];
        }

        return $out;
    }

    /**
     * Find an empty/low-ink rectangle for the stamp.
     * Hard rule: stay in the vertical middle (mid-right / mid-left).
     * Never prefer the bottom margin just because it is blank.
     */
    private function findEmptyArea(string $pdfPath, int $pageNum, float $pageWmm, float $pageHmm, float $stampWmm, float $stampHmm): array
    {
        // Forced visible placement: middle-right (never bottom).
        $fallbackRight = $this->clampToPage(
            ['x' => $pageWmm - $stampWmm - 15, 'y' => ($pageHmm - $stampHmm) / 2],
            $pageWmm,
            $pageHmm,
            $stampWmm,
            $stampHmm
        );
        $fallbackLeft = $this->clampToPage(
            ['x' => 15, 'y' => ($pageHmm - $stampHmm) / 2],
            $pageWmm,
            $pageHmm,
            $stampWmm,
            $stampHmm
        );

        if (!class_exists(\Imagick::class)) {
            Log::warning('Stamp: Imagick unavailable, using mid-right fallback for auto-placement');
            return $fallbackRight;
        }

        try {
            $dpi = 120;
            $img = new \Imagick();
            $img->setResolution($dpi, $dpi);
            $img->readImage($pdfPath . '[' . ($pageNum - 1) . ']');
            $img->setImageColorspace(\Imagick::COLORSPACE_GRAY);

            $imgW = $img->getImageWidth();
            $imgH = $img->getImageHeight();
            $pxPerMm = $imgW / max(1, $pageWmm);

            $pixels = $img->exportImagePixels(0, 0, $imgW, $imgH, 'I', \Imagick::PIXEL_CHAR);
            $img->clear();
            unset($img);

            // Darker than paper = real content. Soft threshold tolerates scan noise.
            $strongThreshold = 210;

            $strongTable = array_fill(0, $imgH + 1, null);
            $strongTable[0] = array_fill(0, $imgW + 1, 0);

            for ($y = 0; $y < $imgH; $y++) {
                $strongTable[$y + 1] = [0];
                for ($x = 0; $x < $imgW; $x++) {
                    $v = $pixels[$y * $imgW + $x];
                    $hit = ($v < $strongThreshold) ? 1 : 0;
                    $strongTable[$y + 1][$x + 1] = $hit
                        + $strongTable[$y][$x + 1]
                        + $strongTable[$y + 1][$x]
                        - $strongTable[$y][$x];
                }
            }

            $regionSum = function (array $table, int $x, int $y, int $w, int $h) use ($imgW, $imgH): int {
                $x = max(0, $x);
                $y = max(0, $y);
                $w = min($w, $imgW - $x);
                $h = min($h, $imgH - $y);
                if ($w <= 0 || $h <= 0) {
                    return 0;
                }

                return $table[$y + $h][$x + $w] - $table[$y][$x + $w] - $table[$y + $h][$x] + $table[$y][$x];
            };

            $stampWpx = max(1, (int) round($stampWmm * $pxPerMm));
            $stampHpx = max(1, (int) round($stampHmm * $pxPerMm));
            $marginPx = (int) round(4 * $pxPerMm);
            $coarseStep = max(2, (int) round($pxPerMm));
            $fineStep   = max(1, (int) round($pxPerMm * 0.4));
            $totalStampPx = max(1, $stampWpx * $stampHpx);

            // Hard mid-band: stamp center must sit near vertical middle.
            $midYStart = (int) max($marginPx, $imgH * 0.30);
            $midYEnd   = (int) min($imgH - $stampHpx - $marginPx, $imgH * 0.70 - $stampHpx);
            if ($midYEnd < $midYStart) {
                $midYStart = (int) max($marginPx, ($imgH - $stampHpx) / 2 - $stampHpx);
                $midYEnd = (int) min($imgH - $stampHpx - $marginPx, ($imgH - $stampHpx) / 2 + $stampHpx);
            }

            $xRight  = $imgW - $stampWpx - $marginPx;
            $xLeft   = $marginPx;

            // Soft empty: allow light paper noise / faint scan grain (up to 3% dark px).
            $isMostlyEmpty = function (int $x, int $y) use ($regionSum, $strongTable, $stampWpx, $stampHpx, $totalStampPx): bool {
                $strong = $regionSum($strongTable, $x, $y, $stampWpx, $stampHpx);

                return ($strong / $totalStampPx) <= 0.03;
            };

            $inkAt = function (int $x, int $y) use ($regionSum, $strongTable, $stampWpx, $stampHpx): int {
                return $regionSum($strongTable, $x, $y, $stampWpx, $stampHpx);
            };

            $scoreMidSpot = function (int $x, int $y, int $ink) use ($imgW, $imgH, $stampWpx, $stampHpx, $totalStampPx): float {
                $cx = ($x + $stampWpx / 2) / max(1, $imgW);
                $cy = ($y + $stampHpx / 2) / max(1, $imgH);

                // Vertical: exact middle wins.
                $yScore = 1.0 - min(1.0, abs($cy - 0.50) * 2.8);

                // Horizontal: mid-right > mid-left > center.
                $rightScore  = 1.0 - min(1.0, abs($cx - 0.84) * 3.5);
                $leftScore   = 1.0 - min(1.0, abs($cx - 0.16) * 3.5);
                $centerScore = 1.0 - min(1.0, abs($cx - 0.50) * 3.5);
                $xScore = max($rightScore * 1.25, $leftScore * 1.10, $centerScore * 0.70);

                $clearScore = 1.0 - min(1.0, $ink / max(1, $totalStampPx));

                return ($yScore * 4.0) + ($xScore * 2.0) + ($clearScore * 3.0);
            };

            $toMm = function (int $x, int $y) use ($pxPerMm, $pageWmm, $pageHmm, $stampWmm, $stampHmm): array {
                return $this->clampToPage(
                    ['x' => $x / $pxPerMm, 'y' => $y / $pxPerMm],
                    $pageWmm,
                    $pageHmm,
                    $stampWmm,
                    $stampHmm
                );
            };

            // --- 1) Probe fixed mid-right / mid-left slots (fast + visible) ---
            $probeBest = null;
            $probeBestScore = -1.0;
            foreach ([0.40, 0.45, 0.50, 0.55, 0.60] as $yFrac) {
                $py = (int) max($midYStart, min($midYEnd, ($imgH * $yFrac) - ($stampHpx / 2)));
                foreach ([$xRight, $xLeft] as $px) {
                    if ($px < $marginPx || $px + $stampWpx > $imgW - $marginPx) {
                        continue;
                    }
                    if (!$isMostlyEmpty($px, $py)) {
                        continue;
                    }
                    $ink = $inkAt($px, $py);
                    $score = $scoreMidSpot($px, $py, $ink);
                    if ($score > $probeBestScore) {
                        $probeBestScore = $score;
                        $probeBest = ['x' => $px, 'y' => $py, 'ink' => $ink];
                    }
                }
            }

            if ($probeBest !== null) {
                Log::debug('Stamp: mid-band probe hit', [
                    'page' => $pageNum,
                    'x' => round($probeBest['x'] / $pxPerMm, 1),
                    'y' => round($probeBest['y'] / $pxPerMm, 1),
                    'score' => round($probeBestScore, 3),
                ]);

                return $toMm($probeBest['x'], $probeBest['y']);
            }

            // --- 2) Scan only the mid-band for mostly-empty spots ---
            $scanMid = function (int $step) use (
                $midYStart, $midYEnd, $marginPx, $stampWpx, $imgW, $isMostlyEmpty, $inkAt, $scoreMidSpot
            ): ?array {
                $best = null;
                $bestScore = -1.0;
                for ($y = $midYStart; $y <= $midYEnd; $y += $step) {
                    for ($x = $marginPx; $x + $stampWpx <= $imgW - $marginPx; $x += $step) {
                        if (!$isMostlyEmpty($x, $y)) {
                            continue;
                        }
                        $ink = $inkAt($x, $y);
                        $score = $scoreMidSpot($x, $y, $ink);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['x' => $x, 'y' => $y, 'ink' => $ink];
                        }
                    }
                }

                return $best;
            };

            $midEmpty = $scanMid($coarseStep) ?? $scanMid($fineStep);
            if ($midEmpty !== null) {
                Log::debug('Stamp: mid-band empty scan hit', [
                    'page' => $pageNum,
                    'x' => round($midEmpty['x'] / $pxPerMm, 1),
                    'y' => round($midEmpty['y'] / $pxPerMm, 1),
                ]);

                return $toMm($midEmpty['x'], $midEmpty['y']);
            }

            // --- 2b) Full-page empty scan when mid-band is crowded (e.g. dense tables/forms) ---
            $fullYStart = $marginPx;
            $fullYEnd = max($marginPx, $imgH - $stampHpx - $marginPx);
            $fullBest = null;
            $fullBestScore = -1.0;
            for ($y = $fullYStart; $y <= $fullYEnd; $y += $coarseStep) {
                for ($x = $marginPx; $x + $stampWpx <= $imgW - $marginPx; $x += $coarseStep) {
                    if (!$isMostlyEmpty($x, $y)) {
                        continue;
                    }
                    $ink = $inkAt($x, $y);
                    $score = $scoreMidSpot($x, $y, $ink);
                    if ($score > $fullBestScore) {
                        $fullBestScore = $score;
                        $fullBest = ['x' => $x, 'y' => $y, 'ink' => $ink];
                    }
                }
            }
            if ($fullBest !== null) {
                Log::debug('Stamp: full-page empty scan hit', [
                    'page' => $pageNum,
                    'x' => round($fullBest['x'] / $pxPerMm, 1),
                    'y' => round($fullBest['y'] / $pxPerMm, 1),
                ]);

                return $toMm($fullBest['x'], $fullBest['y']);
            }

            // --- 3) Mid-band least-ink (even if not perfectly empty) — still never bottom ---
            $findLeastInkMid = function (int $step) use (
                $midYStart, $midYEnd, $marginPx, $stampWpx, $stampHpx, $imgW, $inkAt, $scoreMidSpot
            ): ?array {
                $best = null;
                $bestScore = -1.0;
                for ($y = $midYStart; $y <= $midYEnd; $y += $step) {
                    for ($x = $marginPx; $x + $stampWpx <= $imgW - $marginPx; $x += $step) {
                        $ink = $inkAt($x, $y);
                        // Skip boxes that are mostly filled with content/images.
                        if ($ink > ($stampWpx * $stampHpx * 0.45)) {
                            continue;
                        }
                        $score = $scoreMidSpot($x, $y, $ink);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['x' => $x, 'y' => $y, 'ink' => $ink];
                        }
                    }
                }

                return $best;
            };

            $least = $findLeastInkMid($coarseStep) ?? $findLeastInkMid(max($coarseStep * 2, $fineStep * 2));
            if ($least !== null) {
                Log::debug('Stamp: mid-band least-ink placement', [
                    'page' => $pageNum,
                    'x' => round($least['x'] / $pxPerMm, 1),
                    'y' => round($least['y'] / $pxPerMm, 1),
                    'ink' => $least['ink'],
                ]);

                return $toMm($least['x'], $least['y']);
            }

            // --- 4) Choose mid-right vs mid-left by ink at exact middle Y ---
            $midY = (int) max($midYStart, min($midYEnd, ($imgH - $stampHpx) / 2));
            $rightInk = $inkAt($xRight, $midY);
            $leftInk = $inkAt($xLeft, $midY);
            $chosen = ($leftInk + ($totalStampPx * 0.02) < $rightInk) ? $fallbackLeft : $fallbackRight;

            Log::debug('Stamp: forced mid-side fallback', [
                'page' => $pageNum,
                'side' => ($chosen === $fallbackLeft) ? 'left' : 'right',
                'left_ink' => $leftInk,
                'right_ink' => $rightInk,
            ]);

            return $chosen;
        } catch (\Throwable $e) {
            Log::warning('Stamp: auto-placement detection failed', ['error' => $e->getMessage(), 'page' => $pageNum]);

            return $fallbackRight;
        }
    }

    /**
     * Parses or normalizes values used by resolve stamp position.
     */
    private function resolveStampPosition(
        string $pdfPath,
        int $pageNum,
        string $position,
        float $pageWmm,
        float $pageHmm,
        float $stampWmm,
        float $stampHmm,
        array $options = []
    ): array {
        $pagePositions = $options['page_positions'] ?? [];
        if (is_array($pagePositions) && isset($pagePositions[$pageNum])) {
            $xPct = max(0.0, min(100.0, (float) ($pagePositions[$pageNum]['x_pct'] ?? 0)));
            $yPct = max(0.0, min(100.0, (float) ($pagePositions[$pageNum]['y_pct'] ?? 0)));

            return $this->clampToPage(
                [
                    'x' => ($xPct / 100.0) * $pageWmm,
                    'y' => ($yPct / 100.0) * $pageHmm,
                ],
                $pageWmm,
                $pageHmm,
                $stampWmm,
                $stampHmm
            );
        }

        if ($position === 'custom') {
            // Mixed mode: some pages were dragged, others were not listed →
            // find an empty area on this page instead of copying page 1 coords.
            if (is_array($pagePositions) && $pagePositions !== []) {
                $position = 'auto';
            } else {
                $xPct = max(0.0, min(100.0, (float) ($options['x_pct'] ?? 0)));
                $yPct = max(0.0, min(100.0, (float) ($options['y_pct'] ?? 0)));

                return $this->clampToPage(
                    [
                        'x' => ($xPct / 100.0) * $pageWmm,
                        'y' => ($yPct / 100.0) * $pageHmm,
                    ],
                    $pageWmm,
                    $pageHmm,
                    $stampWmm,
                    $stampHmm
                );
            }
        }

        if ($position === 'auto') {
            // Scan each page independently — empty space differs page to page.
            $cacheKey = md5($pdfPath . '|mid-v2|p' . $pageNum . '|'
                . round($pageWmm, 1) . 'x' . round($pageHmm, 1) . '|'
                . round($stampWmm, 1) . 'x' . round($stampHmm, 1));

            if (isset($this->autoPlacementCache[$cacheKey])) {
                return $this->autoPlacementCache[$cacheKey];
            }

            $pos = $this->findEmptyArea($pdfPath, $pageNum, $pageWmm, $pageHmm, $stampWmm, $stampHmm);
            $this->autoPlacementCache[$cacheKey] = $pos;

            return $pos;
        }

        return $this->calcPosition($position, $pageWmm, $pageHmm, $stampWmm, $stampHmm);
    }

    /**
     * Loads or finds data for get pdf page size.
     */
    private function getPdfPageSize(string $pdfPath, int $pageNum = 1): array
    {
        $pdfPath = $this->preparePdfForFpdi($pdfPath);
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($pdfPath);
        $pageNum   = max(1, min($pageNum, $pageCount));
        $tplId     = $pdf->importPage($pageNum);
        $size      = $pdf->getTemplateSize($tplId);

        return [
            'page'       => $pageNum,
            'page_count' => $pageCount,
            'width'      => $size['width'],
            'height'     => $size['height'],
        ];
    }

    /**
     * Stops the request if a required condition fails (assert stampable pdf).
     *
     * DEBUG: unexpected 403 / access denied → check this guard.
     */
    private function assertStampablePdf(string $pdfPath): ?\Illuminate\Http\JsonResponse
    {
        try {
            $size = $this->getPdfPageSize($pdfPath, 1);
            if ((int) ($size['page_count'] ?? 0) > self::MAX_STAMP_PAGES) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF exceeds the maximum of ' . self::MAX_STAMP_PAGES . ' pages for stamping.',
                ], 422);
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (
                stripos($msg, 'encrypt') !== false
                || stripos($msg, 'password') !== false
                || stripos($msg, 'secured') !== false
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Encrypted PDFs cannot be stamped. Use an unencrypted PDF.',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not open this PDF for stamping.',
            ], 422);
        }

        return null;
    }

    // ──────────────────────────────────────────
    // BACKUP MANAGEMENT (via StampBackupService)
    // ──────────────────────────────────────────

    /**
     * Parses or normalizes values used by resolve stamp source.
     */
    private function resolveStampSource(int $requestId, string $fileKey, string $fullPath, string $relativePath): string
    {
        return StampBackupService::resolveSource($requestId, $fileKey, $fullPath, $relativePath);
    }

    // ──────────────────────────────────────────
    // PREVIEW — detect empty area and return coordinates
    // ──────────────────────────────────────────

    /**
     * Helper used for preview.
     */
    public function preview(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        $rateCheck = RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['success' => false, 'message' => $rateCheck['message']], 429);
        }
        @ini_set('memory_limit', '512M');
        @set_time_limit(600);
        $this->autoPlacementCache = [];

        $validated = $this->validateStampPayload($request);
        $page      = max(1, (int) $request->input('page', 1));

        $fullPath = $this->resolveOwnedFilePath($validated['request_id'], $validated['file_key']);
        if (!$fullPath) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }

        try {
            $sourcePath = $this->resolveStampSource(
                $validated['request_id'],
                $validated['file_key'],
                $fullPath,
                $validated['file_path']
            );

            if ($blocked = $this->assertStampablePdf($sourcePath)) {
                return $blocked;
            }

            $config = $this->resolveStampConfig(
                (string) $validated['stamp_type'],
                isset($validated['stamp_width_mm']) ? (float) $validated['stamp_width_mm'] : null,
                isset($validated['stamp_height_mm']) ? (float) $validated['stamp_height_mm'] : null
            );
            if (! $config) {
                return response()->json(['success' => false, 'message' => 'Stamp type not found.'], 422);
            }
            $size   = $this->getPdfPageSize($sourcePath, $page);

            $pos = $this->resolveStampPosition(
                $sourcePath,
                $size['page'],
                $validated['position'],
                $size['width'],
                $size['height'],
                $config['width'],
                $config['height'],
                [
                    'x_pct' => $validated['x_pct'] ?? null,
                    'y_pct' => $validated['y_pct'] ?? null,
                ]
            );

            return response()->json([
                'success'          => true,
                'page'             => $size['page'],
                'page_count'       => $size['page_count'],
                'page_width_mm'    => $size['width'],
                'page_height_mm'   => $size['height'],
                'stamp_width_mm'   => $config['width'],
                'stamp_height_mm'  => $config['height'],
                'x_mm'             => round($pos['x'], 2),
                'y_mm'             => round($pos['y'], 2),
                'x_pct'            => round(($pos['x'] / $size['width']) * 100, 2),
                'y_pct'            => round(($pos['y'] / $size['height']) * 100, 2),
                'width_pct'        => round(($config['width'] / $size['width']) * 100, 2),
                'height_pct'       => round(($config['height'] / $size['height']) * 100, 2),
                'auto_detected'    => $validated['position'] === 'auto',
            ]);
        } catch (\Throwable $e) {
            try {
                Log::error('Stamp preview error: ' . $e->getMessage());
            } catch (\Throwable) {
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not detect stamp placement.',
            ], 500);
        } finally {
            $this->cleanupTempFiles();
        }
    }

    // ──────────────────────────────────────────
    // APPLY STAMP
    // ──────────────────────────────────────────

    /**
     * Apply query/scope constraint: apply.
     *
     * DEBUG: wrong rows visible for office/user → check this scope.
     */
    public function apply(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        $rateCheck = RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['success' => false, 'message' => $rateCheck['message']], 429);
        }
        @ini_set('memory_limit', '512M');
        @set_time_limit(600);
        $this->autoPlacementCache = [];

        $validated = $this->validateStampPayload($request);

        $fullPath = $this->resolveOwnedFilePath($validated['request_id'], $validated['file_key']);

        if (!$fullPath) {
            return response()->json([
                'success' => false,
                'message' => 'File not found on the server.',
            ], 404);
        }

        try {
            $sourcePath = $this->resolveStampSource(
                $validated['request_id'],
                $validated['file_key'],
                $fullPath,
                $validated['file_path']
            );

            if ($blocked = $this->assertStampablePdf($sourcePath)) {
                return $blocked;
            }

            Log::debug('Stamp: applying to file', [
                'source'  => $sourcePath,
                'target'  => $fullPath,
                'type'    => $validated['stamp_type'],
                'pos'     => $validated['position'],
            ]);

            // Stamp from the ORIGINAL file
            $outputPath = $this->stampPdf(
                $sourcePath,
                $validated['stamp_type'],
                $validated['position'],
                [
                    'stamp_all_pages' => $validated['all_pages'],
                    'x_pct' => $validated['x_pct'] ?? null,
                    'y_pct' => $validated['y_pct'] ?? null,
                    'page_positions' => $validated['page_positions'] ?? [],
                    'stamp_width_mm' => isset($validated['stamp_width_mm']) ? (float) $validated['stamp_width_mm'] : null,
                    'stamp_height_mm' => isset($validated['stamp_height_mm']) ? (float) $validated['stamp_height_mm'] : null,
                ]
            );

            // Verify output exists and is valid
            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new \RuntimeException('Stamp output file is empty or missing.');
            }

            Log::debug('Stamp: output generated', [
                'output' => $outputPath,
                'size'   => filesize($outputPath),
            ]);

            // Overwrite the stored scan with the stamped version
            $this->persistStampedFile($validated['file_path'], $fullPath, $outputPath);

            clearstatcache(true, $fullPath);

            Log::debug('Stamp: file overwritten', [
                'relative' => $validated['file_path'],
                'newSize'  => filesize($outputPath),
            ]);

            StampBackupService::recordStamped(
                $validated['request_id'],
                $validated['file_key'],
                $outputPath,
                $validated['file_path']
            );

            // Clean up temp file
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            // Record in database (update or create)
            $stampData = [
                'file_path'    => $validated['file_path'],
                'stamp_type'   => $validated['stamp_type'],
                'position'     => $validated['position'],
                'all_pages'    => $validated['all_pages'],
                'stamped_by'   => Auth::id(),
                'stamped_at'   => now(),
                'updated_at'   => now(),
            ];

            $updated = DB::table('dcs_document_stamps')
                ->where('document_request_id', $validated['request_id'])
                ->where('file_key', $validated['file_key'])
                ->update($stampData);

            if ($updated) {
                $action = 'changed';
            } else {
                DB::table('dcs_document_stamps')->insert(array_merge($stampData, [
                    'document_request_id' => $validated['request_id'],
                    'file_key'            => $validated['file_key'],
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]));
                $action = 'applied';
            }

            $stampLabel = $this->stampLabelFor((string) $validated['stamp_type']);

            Log::debug("Stamp {$action}", [
                'doc'  => $validated['doc_no'] ?? 'N/A',
                'file' => $validated['file_path'],
                'type' => $validated['stamp_type'],
                'user' => Auth::id(),
            ]);

            StampBackupService::pruneOrphans();

            \App\Helpers\RegisterPersistHelper::logAdminChange(
                'Applied stamp on request #' . $validated['request_id']
                . (!empty($validated['doc_no']) ? ' — ' . $validated['doc_no'] : '')
                . ' (' . ($validated['stamp_type'] ?? 'stamp')
                . (!empty($validated['file_key']) ? ', ' . $validated['file_key'] : '')
                . ')'
            );

            DcsAuditService::log(
                'stamp.apply',
                'stamping',
                (int) $validated['request_id'],
                $validated['file_path'] ?? null,
                [
                    'stamp_type' => $validated['stamp_type'] ?? null,
                    'file_key' => $validated['file_key'] ?? null,
                    'action' => $action,
                ]
            );

            $docNo = trim((string) ($validated['doc_no'] ?? ''));
            if ($docNo !== '') {
                $stamperName = \App\Helpers\RegisterQueryHelper::currentUserDisplayName();
                $originOfficeCodes = DB::table('dcs_masterlist_source_offices as mso')
                    ->join('dcs_masterlist_registration as ml', 'ml.id', '=', 'mso.masterlist_id')
                    ->join((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as o', 'o.id', '=', 'mso.office_id')
                    ->where('ml.request_id', $validated['request_id'])
                    ->whereNotNull('o.office_code')
                    ->where('o.office_code', '!=', '')
                    ->pluck('o.office_code')
                    ->map(fn ($code) => strtoupper(trim((string) $code)))
                    ->unique()
                    ->values();

                $actorOffice = \App\Helpers\RegisterQueryHelper::currentOfficeCode();
                foreach ($originOfficeCodes as $officeCode) {
                    if ($actorOffice !== null && strcasecmp($officeCode, $actorOffice) === 0) {
                        continue;
                    }
                    DcsNotificationService::notifyDocumentStamped(
                        $officeCode,
                        $stamperName,
                        $docNo,
                        (int) $validated['request_id'],
                        $stampLabel
                    );
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Stamp {$action} — {$stampLabel}",
            ]);

        } catch (\Throwable $e) {
            try {
                Log::error('Stamp apply error', [
                    'file'    => $validated['file_path'] ?? null,
                    'type'    => $validated['stamp_type'] ?? null,
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            } catch (\Throwable) {
            }

            return response()->json([
                'success' => false,
                'message' => 'Stamping failed: ' . $e->getMessage(),
            ], 500);
        } finally {
            $this->cleanupTempFiles();
        }
    }

    // ──────────────────────────────────────────
    // REMOVE STAMP — restores original file
    // ──────────────────────────────────────────

    /**
     * Helper used for remove.
     */
    public function remove(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        $rateCheck = RateLimiterService::check('dcs_action');
        if (!$rateCheck['allowed']) {
            return response()->json(['success' => false, 'message' => $rateCheck['message']], 429);
        }
        $request->validate([
            'request_id' => 'required|integer|exists:dcs_document_requests,id',
            'file_key'   => ['required', 'string', 'max:50', 'in:masterlist'],
        ]);

        $requestId = (int) $request->request_id;
        $fileKey = (string) $request->file_key;
        \App\Helpers\RegisterQueryHelper::assertCanAccessRequest($requestId);

        $stamp = DB::table('dcs_document_stamps')
            ->where('document_request_id', $requestId)
            ->where('file_key', $fileKey)
            ->first();

        if (!$stamp) {
            return response()->json([
                'success' => false,
                'message' => 'No stamp found for this file.',
            ], 404);
        }

        $relative = $this->storedScanRelativePath($requestId, $fileKey);
        $fullPath = $relative ? $this->resolveFilePath($relative) : null;

        if (!$fullPath || !$relative) {
            return response()->json([
                'success' => false,
                'message' => 'File not found on the server.',
            ], 404);
        }

        if (!$this->restoreOriginalFile($requestId, $fileKey, $relative, $fullPath)) {
            return response()->json([
                'success' => false,
                'message' => 'No original backup is available to restore.',
            ], 422);
        }

        StampBackupService::invalidate($requestId, $fileKey);
        StampBackupService::pruneOrphans();

        Log::debug('Stamp removed', [
            'request_id' => $requestId,
            'file_key'   => $fileKey,
            'user'       => Auth::id(),
        ]);

        \App\Helpers\RegisterPersistHelper::logAdminChange(
            'Removed stamp on request #' . $requestId
            . (!empty($fileKey) ? ' — ' . $fileKey : '')
        );

        DcsAuditService::log('stamp.remove', 'stamping', $requestId, $relative, [
            'file_key' => $fileKey,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stamp removed. Original file restored.',
        ]);
    }

    // ──────────────────────────────────────────
    // DOWNLOAD STAMPED COPY (no overwrite)
    // ──────────────────────────────────────────

    /**
     * Helper used for download.
     */
    public function download(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser('stamping');
        @ini_set('memory_limit', '512M');
        @set_time_limit(600);
        $this->autoPlacementCache = [];

        // Download the already-stamped live file as-is (no re-stamp).
        if ($request->boolean('use_live_file')) {
            $requestId = (int) $request->input('request_id');
            $fileKey = (string) $request->input('file_key', 'masterlist');
            $request->validate([
                'request_id' => 'required|integer|exists:dcs_document_requests,id',
                'file_key' => ['required', 'string', 'max:50', 'in:masterlist'],
            ]);
            \App\Helpers\RegisterQueryHelper::assertCanAccessRequest($requestId);
            $fullLive = $this->resolveOwnedFilePath($requestId, $fileKey);
            if (!$fullLive) {
                return response()->json(['success' => false, 'message' => 'File not found.'], 404);
            }
            $filename = $this->referenceScanDownloadFilename(
                (string) $request->input('scan_name', ''),
                $this->storedScanRelativePath($requestId, $fileKey) ?: basename($fullLive)
            );

            return response()->download($fullLive, $filename, ['Content-Type' => 'application/pdf']);
        }

        $validated = $this->validateStampPayload($request);

        $fullPath = $this->resolveOwnedFilePath($validated['request_id'], $validated['file_key']);

        if (!$fullPath) {
            return response()->json([
                'success' => false,
                'message' => 'File not found.',
            ], 404);
        }

        try {
            $sourcePath = $this->resolveStampSource(
                $validated['request_id'],
                $validated['file_key'],
                $fullPath,
                $validated['file_path']
            );

            $outputPath = $this->stampPdf(
                $sourcePath,
                $validated['stamp_type'],
                $validated['position'],
                [
                    'stamp_all_pages' => $validated['all_pages'],
                    'x_pct' => $validated['x_pct'] ?? null,
                    'y_pct' => $validated['y_pct'] ?? null,
                    'page_positions' => $validated['page_positions'] ?? [],
                    'stamp_width_mm' => isset($validated['stamp_width_mm']) ? (float) $validated['stamp_width_mm'] : null,
                    'stamp_height_mm' => isset($validated['stamp_height_mm']) ? (float) $validated['stamp_height_mm'] : null,
                ]
            );

            $filename = $this->referenceScanDownloadFilename(
                (string) $request->input('scan_name', ''),
                $validated['file_path'],
                $this->stampLabelFor((string) $validated['stamp_type'])
            );

            return response()
                ->download($outputPath, $filename, ['Content-Type' => 'application/pdf'])
                ->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            try {
                Log::error('Stamp download error: ' . $e->getMessage());
            } catch (\Throwable) {
            }

            return response()->json([
                'success' => false,
                'message' => 'Download failed: ' . $e->getMessage(),
            ], 500);
        } finally {
            $this->cleanupTempFiles();
        }
    }

    // ──────────────────────────────────────────
    // CHECK STAMP
    // ──────────────────────────────────────────

    /**
     * Helper used for check stamp.
     */
    public function checkStamp(Request $request)
    {
        $request->validate([
            'request_id' => 'required|integer',
            'file_key'   => ['required', 'string', 'in:masterlist'],
        ]);

        $stamp = DB::table('dcs_document_stamps')
            ->where('document_request_id', $request->request_id)
            ->where('file_key', $request->file_key)
            ->first();

        if (!$stamp) {
            return response()->json(['stamped' => false]);
        }

        $stampedBy = 'Unknown';
        if ($stamp->stamped_by) {
            $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
            $details = DB::table($accDetailsTbl)->where('account_id', $stamp->stamped_by)->first();
            $stampedBy = $details
                ? trim($details->first_name . ' ' . $details->last_name)
                : 'Unknown';
        }

        return response()->json([
            'stamped'    => true,
            'stamp_type' => $stamp->stamp_type,
            'label'      => $this->stampLabelFor((string) $stamp->stamp_type),
            'stamped_at' => \Carbon\Carbon::parse($stamp->stamped_at)->format('M d, Y h:i A'),
            'stamped_by' => $stampedBy ?: 'Unknown',
        ]);
    }

    // ──────────────────────────────────────────
    // FILE PATH RESOLUTION
    // ──────────────────────────────────────────

    /**
     * Parses or normalizes values used by resolve file path.
     */
    private function resolveFilePath(string $path): ?string
    {
        $path = ltrim($path, '/');

        $candidates = [
            storage_path('app/public/' . $path),
            storage_path('app/' . $path),
            public_path('storage/' . $path),
        ];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real && file_exists($real) && is_readable($real)) {
                Log::debug('Stamp: resolved file', ['input' => $path, 'resolved' => $real]);
                return $real;
            }
        }

        if (DocumentStorageService::isDcsStoragePath($path)) {
            $localRel = DocumentStorageService::localUploadsPath($path);
            if (Storage::disk('local')->exists($localRel)) {
                $localAbs = Storage::disk('local')->path($localRel);
                if (is_readable($localAbs) && filesize($localAbs) > 0) {
                    if ($this->isValidPdfFile($localAbs)) {
                        Log::debug('Stamp: resolved DCS local cache', ['input' => $path, 'resolved' => $localAbs]);

                        return realpath($localAbs) ?: $localAbs;
                    }

                    Log::warning('Stamp: invalid PDF in local cache, refreshing from storage', ['path' => $path]);
                    Storage::disk('local')->delete($localRel);
                }
            }

            if (DocumentStorageService::dcsScanExists($path)) {
                $content = DocumentStorageService::getDcsScanContent($path);
                if ($content !== null && $content !== '' && str_starts_with($content, '%PDF')) {
                    $temp = tempnam(sys_get_temp_dir(), 'stamp_src_');
                    if ($temp === false) {
                        return null;
                    }
                    @unlink($temp);
                    $tempPdf = $temp . '.pdf';
                    file_put_contents($tempPdf, $content);
                    $this->tempFiles[] = $tempPdf;
                    Log::debug('Stamp: resolved DCS cloud file to temp', ['input' => $path, 'temp' => $tempPdf]);

                    return $tempPdf;
                }

                if ($content !== null && $content !== '') {
                    Log::warning('Stamp: storage returned non-PDF content', ['path' => $path, 'head' => substr($content, 0, 32)]);
                }
            }
        }

        Log::warning('Stamp: file not found', [
            'path'       => $path,
            'candidates' => $candidates,
        ]);

        return null;
    }

    /**
     * Helper used for persist stamped file.
     */
    private function persistStampedFile(string $relativePath, string $resolvedAbsolutePath, string $stampedOutputPath): void
    {
        if (!is_file($stampedOutputPath) || filesize($stampedOutputPath) === 0) {
            throw new \RuntimeException('Stamp output file is empty or missing.');
        }

        if (DocumentStorageService::isLegacyPublicScanPath($relativePath)) {
            $dest = Storage::disk('public')->path(ltrim($relativePath, '/'));
            if (!copy($stampedOutputPath, $dest)) {
                throw new \RuntimeException('Failed to write stamped file to destination.');
            }

            return;
        }

        if (DocumentStorageService::isDcsStoragePath($relativePath)) {
            $content = file_get_contents($stampedOutputPath);
            if ($content === false || $content === '') {
                throw new \RuntimeException('Stamp output could not be read.');
            }
            DocumentStorageService::storeDcsFileAtPath($relativePath, $content);
            $stampCopy = DocumentStorageService::buildDccRelativePath(
                'masterlist',
                basename($relativePath),
                ['kind' => 'stamped']
            );
            DocumentStorageService::storeDcsFileAtPath($stampCopy, $content);

            return;
        }

        if (!copy($stampedOutputPath, $resolvedAbsolutePath)) {
            throw new \RuntimeException('Failed to write stamped file to destination.');
        }
    }

    /**
     * Helper used for restore original file.
     */
    private function restoreOriginalFile(int $requestId, string $fileKey, string $relativePath, string $resolvedAbsolutePath): bool
    {
        $backupPath = StampBackupService::backupPath($requestId, $fileKey);
        if (!is_file($backupPath) || filesize($backupPath) === 0) {
            return false;
        }

        if (DocumentStorageService::isLegacyPublicScanPath($relativePath)) {
            $dest = Storage::disk('public')->path(ltrim($relativePath, '/'));

            return copy($backupPath, $dest);
        }

        if (DocumentStorageService::isDcsStoragePath($relativePath)) {
            $content = file_get_contents($backupPath);
            if ($content === false || $content === '') {
                return false;
            }
            DocumentStorageService::storeDcsFileAtPath($relativePath, $content);

            return true;
        }

        return StampBackupService::restoreTo($requestId, $fileKey, $resolvedAbsolutePath);
    }

    /**
     * Tells whether is valid pdf file — used for access and UI decisions.
     */
    private function isValidPdfFile(string $absolutePath): bool
    {
        if (!is_readable($absolutePath) || filesize($absolutePath) < 5) {
            return false;
        }

        $head = file_get_contents($absolutePath, false, null, 0, 5);

        return $head === '%PDF-';
    }

    /**
     * Saves data for stored scan relative path.
     *
     * DEBUG: save failures / wrong DB rows → step through this write path.
     */
    private function storedScanRelativePath(int $requestId, string $fileKey): ?string
    {
        $normalize = static function ($path): ?string {
            $path = ltrim(str_replace('\\', '/', (string) $path), '/');
            $path = str_replace(['../', '..\\'], '', $path);

            return $path !== '' ? $path : null;
        };

        $value = match ($fileKey) {
            'masterlist' => DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->value('scanned_masterlist'),
            'drf' => DB::table('dcs_document_request_form')->where('request_id', $requestId)->value('scanned_drf'),
            'dcn' => DB::table('dcs_document_change_notice')->where('request_id', $requestId)->value('scanned_dcn'),
            'distribution' => DB::table('dcs_document_distribution')->where('request_id', $requestId)->value('scanned_distribution'),
            default => null,
        };

        if ($value === null && preg_match('/^syllabi_drf_(\d+)$/', $fileKey, $m)) {
            $value = DB::table('dcs_syllabi_drf as sd')
                ->join('dcs_syllabi as s', 's.id', '=', 'sd.syllabi_id')
                ->where('s.request_id', $requestId)
                ->where('sd.id', (int) $m[1])
                ->value('sd.scanned_drf');
        }

        return $normalize($value);
    }

    /**
     * Parses or normalizes values used by resolve owned file path.
     */
    private function resolveOwnedFilePath(int $requestId, string $fileKey): ?string
    {
        $relative = $this->storedScanRelativePath($requestId, $fileKey);
        if (!$relative) {
            return null;
        }

        return $this->resolveFilePath($relative);
    }

    // ──────────────────────────────────────────
    // PDF STAMPING ENGINE
    // ──────────────────────────────────────────

    /**
     * Helper used for stamp pdf.
     */
    private function stampPdf(string $inputPath, string $stampType, string $position, array $options): string
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Source PDF not found: {$inputPath}");
        }

        if (filesize($inputPath) === 0) {
            throw new \RuntimeException("Source PDF is empty: {$inputPath}");
        }

        $inputPath = $this->preparePdfForFpdi($inputPath);

        Log::debug('Stamp: engine starting', [
            'input'  => $inputPath,
            'size'   => filesize($inputPath),
            'type'   => $stampType,
            'pos'    => $position,
            'pages'  => $options['stamp_all_pages'] ? 'all' : 'first',
        ]);

        $this->autoPlacementCache = [];

        $pdf = new Fpdi();

        // CRITICAL: prevent FPDI from silently inserting new blank pages when a
        // Cell() call would cross the default bottom margin. We manage pages
        // manually via importPage()/useTemplate(), so auto page-break must be off —
        // otherwise a stamp placed near the page edge causes its trailing lines
        // (date/subtitle) to spill onto an auto-created extra page.
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $pageCount = $pdf->setSourceFile($inputPath);

        if ($pageCount < 1) {
            throw new \RuntimeException('PDF has no pages.');
        }

        Log::debug('Stamp: loaded PDF', ['pageCount' => $pageCount]);

        $stampAll = $options['stamp_all_pages'] ?? true;

        for ($page = 1; $page <= $pageCount; $page++) {
            $tplId = $pdf->importPage($page);
            $size  = $pdf->getTemplateSize($tplId);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

            if ($stampAll || $page === 1) {
                $this->renderStamp($pdf, $stampType, $position, $size['width'], $size['height'], $options, $inputPath, $page);
            }

            if ($pageCount > 50 && $page % 50 === 0) {
                Log::debug('Stamp: progress', ['page' => $page, 'total' => $pageCount]);
            }
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'stamp_') . '.pdf';
        $pdf->Output($outputPath, 'F');

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('FPDI failed to generate output PDF.');
        }

        Log::debug('Stamp: engine output', [
            'output' => $outputPath,
            'size'   => filesize($outputPath),
        ]);

        return $outputPath;
    }

    /**
     * FPDI's free parser rejects many modern/scanned PDFs (compressed xref / objects).
     * Rewrite to PDF 1.4 via Ghostscript when needed so stamping can proceed.
     */
    private function preparePdfForFpdi(string $inputPath): string
    {
        try {
            $probe = new Fpdi();
            $probe->setSourceFile($inputPath);

            return $inputPath;
        } catch (\Throwable $e) {
            Log::warning('Stamp: FPDI cannot read source PDF — flattening with Ghostscript', [
                'file'  => $inputPath,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->flattenPdfForFpdi($inputPath);
    }

    /**
     * Helper used for flatten pdf for fpdi.
     */
    private function flattenPdfForFpdi(string $inputPath): string
    {
        $gs = $this->ghostscriptBinary();
        if ($gs === null) {
            throw new \RuntimeException(
                'This PDF uses compression FPDI cannot read, and Ghostscript is not installed to convert it.'
            );
        }

        $hash = @md5_file($inputPath) ?: sha1($inputPath . '|' . filesize($inputPath));
        $cacheDir = $this->writableStampCacheDir();
        $outputPath = $cacheDir . DIRECTORY_SEPARATOR . $hash . '.pdf';

        if (is_file($outputPath) && filesize($outputPath) > 0) {
            try {
                $probe = new Fpdi();
                $probe->setSourceFile($outputPath);

                return $outputPath;
            } catch (\Throwable $e) {
                @unlink($outputPath);
            }
        }

        // Write via a temp file first so a partial Ghostscript failure never leaves a bad cache entry.
        $tempOut = tempnam(sys_get_temp_dir(), 'stamp_gs_');
        if ($tempOut === false) {
            throw new \RuntimeException('Could not create a temporary file for PDF conversion.');
        }
        @unlink($tempOut);
        $tempOut .= '.pdf';
        $this->tempFiles[] = $tempOut;

        $cmd = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dDetectDuplicateImages=true -dCompressFonts=true -sOutputFile=%s %s 2>&1',
            escapeshellarg($gs),
            escapeshellarg($tempOut),
            escapeshellarg($inputPath)
        );

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);

        if ($code !== 0 || !is_file($tempOut) || filesize($tempOut) === 0) {
            @unlink($tempOut);
            throw new \RuntimeException(
                'Could not convert PDF for stamping. ' . trim(implode("\n", $out))
            );
        }

        try {
            $probe = new Fpdi();
            $probe->setSourceFile($tempOut);
        } catch (\Throwable $e) {
            @unlink($tempOut);
            throw new \RuntimeException(
                'Converted PDF is still unreadable by the stamp engine: ' . $e->getMessage()
            );
        }

        // Best-effort cache for reuse (preview → apply). Fall back to the temp path if move fails.
        if (@rename($tempOut, $outputPath) || @copy($tempOut, $outputPath)) {
            @unlink($tempOut);
            $this->tempFiles = array_values(array_filter(
                $this->tempFiles,
                static fn ($p) => $p !== $tempOut
            ));
            $finalPath = $outputPath;
        } else {
            $finalPath = $tempOut;
        }

        Log::debug('Stamp: Ghostscript flatten ready for FPDI', [
            'from' => $inputPath,
            'to'   => $finalPath,
            'size' => filesize($finalPath),
        ]);

        return $finalPath;
    }

    /**
     * Helper used for writable stamp cache dir.
     */
    private function writableStampCacheDir(): string
    {
        $candidates = [
            storage_path('app/private/stamp_fpdi_cache'),
            storage_path('app/private/temp/stamp_fpdi_cache'),
            sys_get_temp_dir() . '/rms_stamp_fpdi_cache',
        ];

        foreach ($candidates as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        throw new \RuntimeException('No writable directory available for stamp PDF conversion cache.');
    }

    /**
     * Helper used for ghostscript binary.
     */
    private function ghostscriptBinary(): ?string
    {
        foreach (['gs', '/usr/bin/gs', '/usr/local/bin/gs'] as $candidate) {
            if ($candidate === 'gs') {
                $path = trim((string) shell_exec('command -v gs 2>/dev/null'));
                if ($path !== '' && is_executable($path)) {
                    return $path;
                }
                continue;
            }
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Download name matching masterlist scan convention:
     * REFERENCE (YYYY-MM-DD_DOC_…_RevN).pdf
     */
    private function referenceScanDownloadFilename(string $scanName, string $fallbackPath, string $stampLabel = 'REFERENCE'): string
    {
        $base = trim($scanName);
        if ($base === '') {
            $base = pathinfo($fallbackPath, PATHINFO_FILENAME);
        }
        $base = preg_replace('/\.pdf$/i', '', $base) ?? $base;
        $base = preg_replace('/[\\\\\/:\*\?"<>\|]+/', '-', $base) ?? $base;
        $base = trim($base, " .\t\n\r\0\x0B-");
        if ($base === '') {
            $base = 'document';
        }

        $prefix = mb_strtoupper(trim($stampLabel));
        $prefix = preg_replace('/[\\\\\/:\*\?"<>\|]+/', '-', $prefix) ?? $prefix;
        $prefix = $prefix !== '' ? $prefix : 'REFERENCE';

        // Avoid PREFIX (PREFIX (...))
        if (preg_match('/^' . preg_quote($prefix, '/') . '\s*\(/i', $base)) {
            $name = $base;
        } else {
            $name = $prefix . ' (' . $base . ')';
        }

        return mb_substr($name, 0, 180) . '.pdf';
    }

    /**
     * Helper used for cleanup temp files.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Helper used for render stamp.
     */
    private function renderStamp($pdf, string $type, string $position, float $pageW, float $pageH, array $options, string $pdfPath, int $pageNum): void
    {
        $config = $this->resolveStampConfig(
            $type,
            isset($options['stamp_width_mm']) ? (float) $options['stamp_width_mm'] : null,
            isset($options['stamp_height_mm']) ? (float) $options['stamp_height_mm'] : null
        );
        if (!$config) {
            throw new \RuntimeException('Unsupported stamp type: ' . $type);
        }

        $pos = $this->resolveStampPosition(
            $pdfPath,
            $pageNum,
            $position,
            $pageW,
            $pageH,
            $config['width'],
            $config['height'],
            $options
        );
        $this->drawStandard($pdf, $pos['x'], $pos['y'], $config);
    }

    /**
     * Helper used for calc position.
     */
    private function calcPosition(string $position, float $pageW, float $pageH, float $stampW, float $stampH): array
    {
        $m = 15;

        $pos = match ($position) {
            'top-left'     => ['x' => $m, 'y' => $m],
            'top-right'    => ['x' => $pageW - $stampW - $m, 'y' => $m],
            'bottom-left'  => ['x' => $m, 'y' => $pageH - $stampH - $m],
            'bottom-right' => ['x' => $pageW - $stampW - $m, 'y' => $pageH - $stampH - $m],
            'center'       => ['x' => ($pageW - $stampW) / 2, 'y' => ($pageH - $stampH) / 2],
        };

        return $this->clampToPage($pos, $pageW, $pageH, $stampW, $stampH);
    }

    // NEW: hard safety clamp — guarantees the stamp box (and everything drawn
    // inside it, including trailing text lines) always stays fully within the
    // physical page bounds, regardless of which placement strategy picked the spot.
    /**
     * Helper used for clamp to page.
     */
    private function clampToPage(array $pos, float $pageW, float $pageH, float $stampW, float $stampH): array
    {
        $pos['x'] = max(0, min($pos['x'], $pageW - $stampW));
        $pos['y'] = max(0, min($pos['y'], $pageH - $stampH));
        return $pos;
    }

    /**
     * Helper used for draw standard.
     */
    private function drawStandard($pdf, float $x, float $y, array $cfg): void
    {
        $w = $cfg['width'];
        $h = $cfg['height'];

        // Custom imported stamp image (stored in private storage).
        $absImagePath = (string) ($cfg['absolute_image_path'] ?? '');
        if ($absImagePath !== '' && is_file($absImagePath) && is_readable($absImagePath)) {
            $fpdiType = strtoupper((string) ($cfg['fpdi_type'] ?? 'PNG'));
            if (! in_array($fpdiType, ['PNG', 'JPEG', 'JPG'], true)) {
                $fpdiType = 'PNG';
            }
            $pdf->Image($absImagePath, $x, $y, $w, $h, $fpdiType);
            return;
        }

        // Prefer built-in stamp artwork: red REFERENCE box + blue signature at bottom-right.
        $imageRel = (string) ($cfg['image'] ?? '');
        if ($imageRel !== '') {
            $imagePath = public_path($imageRel);
            if (is_file($imagePath) && is_readable($imagePath)) {
                $pdf->Image($imagePath, $x, $y, $w, $h, 'PNG');
                return;
            }
        }

        // Fallback: red rectangle + REFERENCE text + simple blue signature mark.
        [$r, $g, $b] = $cfg['color'];
        $boxH = max(8.0, $h * 0.72);
        $pdf->SetDrawColor($r, $g, $b);
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($x, $y, $w, $boxH);
        $pdf->SetFont('Helvetica', 'B', $cfg['titleSize']);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x, $y + ($boxH / 2) - 3.5);
        $pdf->Cell($w, 7, $cfg['title'], 0, 0, 'C');

        // Blue signature flourish overlapping the bottom-right corner.
        $pdf->SetDrawColor(30, 79, 216);
        $pdf->SetLineWidth(0.45);
        $sx = $x + ($w * 0.55);
        $sy = $y + $boxH - 1.2;
        $pdf->Line($sx, $sy + 1.5, $sx + 3.2, $sy - 1.8);
        $pdf->Line($sx + 3.2, $sy - 1.8, $sx + 6.0, $sy + 2.2);
        $pdf->Line($sx + 6.0, $sy + 2.2, $sx + 9.5, $sy - 1.0);
        $pdf->Line($sx + 9.5, $sy - 1.0, $sx + 13.0, $sy + 1.8);
        $pdf->Line($sx + 13.0, $sy + 1.8, $sx + 16.5, $sy - 0.6);
        $pdf->Line($sx + 2.0, $sy + 3.0, $sx + 15.5, $sy + 3.6);
    }
}
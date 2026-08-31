<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class StampService
{
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

    /** Only Reference may be applied going forward. */
    private const STAMPS = [
        'reference' => [
            'label'     => 'Reference',
            'title'     => 'REFERENCE',
            'subtitle'  => '',
            'color'     => [176, 48, 48],
            'width'     => 52,
            'height'    => 24,
            'titleSize' => 14,
            'subSize'   => 6,
            'image'     => 'images/stamps/reference.png',
        ],
    ];

    // ──────────────────────────────────────────
    // SHARED VALIDATION
    // ──────────────────────────────────────────

    private function validateStampPayload(Request $request): array
    {
        $validated = $request->validate([
            'file_key'     => ['required', 'string', 'max:50', 'in:masterlist'],
            'request_id'   => 'required|integer|exists:dcs_document_requests,id',
            'doc_no'       => 'nullable|string|max:100',
            'doc_title'    => 'nullable|string|max:500',
            'rev'          => 'nullable|string|max:20',
            'stamp_type'   => 'required|string|in:reference',
            'position'     => 'required|string|in:top-left,top-right,bottom-left,bottom-right,center,auto',
            'all_pages'    => 'required|boolean',
            'certified_by' => 'nullable|string|max:255',
            'designation'  => 'nullable|string|max:255',
        ]);

        $relative = $this->storedScanRelativePath((int) $validated['request_id'], $validated['file_key']);
        if (!$relative) {
            abort(422, 'No scanned file is stored for this document.');
        }
        \App\Helpers\RegisterQueryHelper::assertCanAccessRequest((int) $validated['request_id']);
        $validated['file_path'] = $relative;

        return $validated;
    }

    /**
     * Find a completely empty rectangle on the page for the stamp.
     * Scans the whole page (not only top/bottom). Prefers empty space at
     * mid-left, mid-right, or center when those regions are clear.
     */
    private function findEmptyArea(string $pdfPath, int $pageNum, float $pageWmm, float $pageHmm, float $stampWmm, float $stampHmm): array
    {
        $fallback = $this->clampToPage(
            ['x' => $pageWmm - $stampWmm - 15, 'y' => ($pageHmm - $stampHmm) / 2],
            $pageWmm,
            $pageHmm,
            $stampWmm,
            $stampHmm
        );

        if (!class_exists(\Imagick::class)) {
            Log::warning('Stamp: Imagick unavailable, using fallback for auto-placement');
            return $fallback;
        }

        try {
            $dpi = 120;
            $img = new \Imagick();
            $img->setResolution($dpi, $dpi);
            $img->readImage($pdfPath . '[' . ($pageNum - 1) . ']');
            $img->setImageColorspace(\Imagick::COLORSPACE_GRAY);

            // Do not downscale — thumbnail blurs scans and hides empty margins.
            $imgW = $img->getImageWidth();
            $imgH = $img->getImageHeight();
            $pxPerMm = $imgW / max(1, $pageWmm);

            $pixels = $img->exportImagePixels(0, 0, $imgW, $imgH, 'I', \Imagick::PIXEL_CHAR);
            $img->clear();
            unset($img);

            // Body text, rules, images, watermarks — anything clearly darker than paper.
            $strongThreshold = 215;

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

            $stampWpx = (int) round($stampWmm * $pxPerMm);
            $stampHpx = (int) round($stampHmm * $pxPerMm);
            $marginPx = (int) round(3 * $pxPerMm);
            $coarseStep = max(2, (int) round($pxPerMm));
            $fineStep   = max(1, (int) round($pxPerMm * 0.4));

            $isRectEmpty = function (int $x, int $y) use ($regionSum, $strongTable, $pixels, $imgW, $stampWpx, $stampHpx): bool {
                if ($regionSum($strongTable, $x, $y, $stampWpx, $stampHpx) > 0) {
                    return false;
                }

                $x2 = min($imgW, $x + $stampWpx);
                $y2 = $y + $stampHpx;
                for ($py = $y; $py < $y2; $py++) {
                    for ($px = $x; $px < $x2; $px++) {
                        if ($pixels[$py * $imgW + $px] < 250) {
                            return false;
                        }
                    }
                }

                return true;
            };

            $scanForEmpty = function (int $yStart, int $yEnd, int $step) use (
                $marginPx, $stampWpx, $stampHpx, $imgW, $imgH, $isRectEmpty
            ): array {
                $found = [];
                $yStart = max($marginPx, $yStart);
                $yEnd   = min($imgH - $stampHpx - $marginPx, $yEnd);

                for ($y = $yStart; $y <= $yEnd; $y += $step) {
                    for ($x = $marginPx; $x + $stampWpx <= $imgW - $marginPx; $x += $step) {
                        if ($isRectEmpty($x, $y)) {
                            $found[] = ['x' => $x, 'y' => $y];
                        }
                    }
                }

                return $found;
            };

            $scoreSpot = function (int $x, int $y) use ($imgW, $imgH, $stampWpx, $stampHpx): float {
                $cx = ($x + $stampWpx / 2) / max(1, $imgW);
                $cy = ($y + $stampHpx / 2) / max(1, $imgH);

                // Prefer the vertical middle; penalize hugging the top or bottom.
                $yScore = 1.0 - min(1.0, abs($cy - 0.5) * 1.7);

                // Prefer left, right, or true center columns.
                $xScore = 1.0 - min(abs($cx - 0.18), abs($cx - 0.82), abs($cx - 0.50)) * 2.4;

                return ($yScore * 2.2) + $xScore;
            };

            $pickPreferred = function (array $candidates) use ($scoreSpot, $pxPerMm, $imgH): ?array {
                if ($candidates === []) {
                    return null;
                }

                $best = null;
                $bestRank = -1.0;

                foreach ($candidates as $c) {
                    $rank = $scoreSpot((int) $c['x'], (int) $c['y']);
                    if ($rank > $bestRank) {
                        $bestRank = $rank;
                        $best = $c;
                    }
                }

                if ($best === null) {
                    return null;
                }

                return [
                    'x' => $best['x'] / $pxPerMm,
                    'y' => $best['y'] / $pxPerMm,
                    'y_pct' => round(($best['y'] / $imgH) * 100, 1),
                ];
            };

            $refinePosition = function (int $x, int $y) use (
                $isRectEmpty, $scoreSpot, $fineStep, $marginPx, $imgW, $imgH, $stampWpx, $stampHpx
            ): ?array {
                $best = ['x' => $x, 'y' => $y];
                $bestScore = $scoreSpot($x, $y);

                for ($dy = -$fineStep * 4; $dy <= $fineStep * 4; $dy += $fineStep) {
                    for ($dx = -$fineStep * 4; $dx <= $fineStep * 4; $dx += $fineStep) {
                        $tx = $x + $dx;
                        $ty = $y + $dy;
                        if ($tx < $marginPx || $ty < $marginPx) {
                            continue;
                        }
                        if ($tx + $stampWpx > $imgW - $marginPx || $ty + $stampHpx > $imgH - $marginPx) {
                            continue;
                        }
                        if (!$isRectEmpty($tx, $ty)) {
                            continue;
                        }
                        $score = $scoreSpot($tx, $ty);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['x' => $tx, 'y' => $ty];
                        }
                    }
                }

                return $best;
            };

            $xRight  = $imgW - $stampWpx - $marginPx;
            $xLeft   = $marginPx;
            $xCentre = (int) max($marginPx, ($imgW - $stampWpx) / 2);

            $probeSlots = [];
            foreach ([0.18, 0.32, 0.50, 0.68, 0.82] as $yFrac) {
                $py = (int) max($marginPx, ($imgH * $yFrac) - ($stampHpx / 2));
                $py = min($py, $imgH - $stampHpx - $marginPx);
                $probeSlots[] = ['x' => $xLeft,   'y' => $py];
                $probeSlots[] = ['x' => $xRight,  'y' => $py];
                $probeSlots[] = ['x' => $xCentre, 'y' => $py];
            }

            $probeBest = null;
            $probeBestScore = -1.0;
            foreach ($probeSlots as $slot) {
                if (!$isRectEmpty($slot['x'], $slot['y'])) {
                    continue;
                }
                $score = $scoreSpot($slot['x'], $slot['y']);
                if ($score > $probeBestScore) {
                    $probeBestScore = $score;
                    $probeBest = $slot;
                }
            }

            if ($probeBest !== null && $probeBestScore >= 2.4) {
                Log::debug('Stamp: empty area found via full-page probe', [
                    'page'  => $pageNum,
                    'x'     => round($probeBest['x'] / $pxPerMm, 1),
                    'y'     => round($probeBest['y'] / $pxPerMm, 1),
                    'score' => round($probeBestScore, 3),
                ]);

                return $this->clampToPage(
                    ['x' => $probeBest['x'] / $pxPerMm, 'y' => $probeBest['y'] / $pxPerMm],
                    $pageWmm,
                    $pageHmm,
                    $stampWmm,
                    $stampHmm
                );
            }

            $candidates = $scanForEmpty($marginPx, $imgH - $stampHpx - $marginPx, $coarseStep);

            if ($candidates === []) {
                $candidates = $scanForEmpty($marginPx, $imgH - $stampHpx - $marginPx, $fineStep);
            }

            $best = $pickPreferred($candidates);

            if ($best !== null) {
                $rx = (int) round($best['x'] * $pxPerMm);
                $ry = (int) round($best['y'] * $pxPerMm);
                $refined = $refinePosition($rx, $ry);
                if ($refined !== null) {
                    $best['x'] = $refined['x'] / $pxPerMm;
                    $best['y'] = $refined['y'] / $pxPerMm;
                    $best['y_pct'] = round(($refined['y'] / $imgH) * 100, 1);
                }

                Log::debug('Stamp: empty area found', [
                    'page'  => $pageNum,
                    'x'     => round($best['x'], 1),
                    'y'     => round($best['y'], 1),
                    'y_pct' => $best['y_pct'],
                ]);

                return $this->clampToPage(
                    ['x' => $best['x'], 'y' => $best['y']],
                    $pageWmm,
                    $pageHmm,
                    $stampWmm,
                    $stampHmm
                );
            }

            $totalStampPx = max(1, $stampWpx * $stampHpx);

            $findLeastInk = function (int $step) use (
                $marginPx, $stampWpx, $stampHpx, $imgW, $imgH, $regionSum, $strongTable, $isRectEmpty, $scoreSpot
            ): ?array {
                $best = null;
                $minStrong = PHP_INT_MAX;
                $bestEmpty = null;
                $bestEmptyScore = -1.0;
                $yEnd = $imgH - $stampHpx - $marginPx;

                for ($y = $marginPx; $y <= $yEnd; $y += $step) {
                    for ($x = $marginPx; $x + $stampWpx <= $imgW - $marginPx; $x += $step) {
                        $strong = $regionSum($strongTable, $x, $y, $stampWpx, $stampHpx);

                        if ($strong === 0 && $isRectEmpty($x, $y)) {
                            $score = $scoreSpot($x, $y);
                            if ($score > $bestEmptyScore) {
                                $bestEmptyScore = $score;
                                $bestEmpty = ['x' => $x, 'y' => $y, 'strong' => 0];
                            }
                            continue;
                        }

                        if ($strong < $minStrong) {
                            $minStrong = $strong;
                            $best = ['x' => $x, 'y' => $y, 'strong' => $strong];
                        } elseif ($strong === $minStrong && $best !== null) {
                            if ($scoreSpot($x, $y) > $scoreSpot($best['x'], $best['y'])) {
                                $best = ['x' => $x, 'y' => $y, 'strong' => $strong];
                            }
                        }
                    }
                }

                return $bestEmpty ?? $best;
            };

            $formatResult = function (array $spot, int $page) use ($pxPerMm, $imgH, $pageWmm, $pageHmm, $stampWmm, $stampHmm, $totalStampPx): array {
                Log::debug('Stamp: using least-ink placement', [
                    'page'         => $page,
                    'x'            => round($spot['x'] / $pxPerMm, 1),
                    'y'            => round($spot['y'] / $pxPerMm, 1),
                    'y_pct'        => round(($spot['y'] / $imgH) * 100, 1),
                    'strong_px'    => $spot['strong'],
                    'strong_ratio' => round($spot['strong'] / $totalStampPx, 4),
                ]);

                return $this->clampToPage(
                    ['x' => $spot['x'] / $pxPerMm, 'y' => $spot['y'] / $pxPerMm],
                    $pageWmm,
                    $pageHmm,
                    $stampWmm,
                    $stampHmm
                );
            };
            $least = $findLeastInk($coarseStep);
            if ($least === null) {
                $least = $findLeastInk(max($coarseStep * 2, $fineStep * 2));
            }

            if ($least !== null) {
                if ($least['strong'] === 0 && $isRectEmpty($least['x'], $least['y'])) {
                    return $formatResult($least, $pageNum);
                }

                return $formatResult($least, $pageNum);
            }

            Log::warning('Stamp: could not analyse page for placement', ['page' => $pageNum]);

            return $fallback;

        } catch (\Throwable $e) {
            Log::warning('Stamp: auto-placement detection failed', ['error' => $e->getMessage(), 'page' => $pageNum]);
            return $fallback;
        }
    }

    private function resolveStampPosition(string $pdfPath, int $pageNum, string $position, float $pageWmm, float $pageHmm, float $stampWmm, float $stampHmm): array
    {
        if ($position === 'auto') {
            // Scan each page independently — empty space differs page to page.
            $cacheKey = md5($pdfPath . '|p' . $pageNum . '|'
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

    // ──────────────────────────────────────────
    // BACKUP MANAGEMENT (via StampBackupService)
    // ──────────────────────────────────────────

    private function resolveStampSource(int $requestId, string $fileKey, string $fullPath, string $relativePath): string
    {
        return StampBackupService::resolveSource($requestId, $fileKey, $fullPath, $relativePath);
    }

    // ──────────────────────────────────────────
    // PREVIEW — detect empty area and return coordinates
    // ──────────────────────────────────────────

    public function preview(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser();
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

            $config = self::STAMPS[$validated['stamp_type']];
            $size   = $this->getPdfPageSize($sourcePath, $page);

            $pos = $this->resolveStampPosition(
                $sourcePath,
                $size['page'],
                $validated['position'],
                $size['width'],
                $size['height'],
                $config['width'],
                $config['height']
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
            Log::error('Stamp preview error: ' . $e->getMessage());

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

    public function apply(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser();
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
                    'certified_by'    => $validated['certified_by'] ?? '',
                    'designation'     => $validated['designation'] ?? '',
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
                'certified_by' => $validated['certified_by'] ?? null,
                'designation'  => $validated['designation'] ?? null,
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

            $stampLabel = self::STAMPS[$validated['stamp_type']]['label'] ?? self::STAMP_LABELS[$validated['stamp_type']] ?? $validated['stamp_type'];

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
            Log::error('Stamp apply error', [
                'file'    => $validated['file_path'] ?? null,
                'type'    => $validated['stamp_type'] ?? null,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

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

    public function remove(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser();
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

        return response()->json([
            'success' => true,
            'message' => 'Stamp removed. Original file restored.',
        ]);
    }

    // ──────────────────────────────────────────
    // DOWNLOAD STAMPED COPY (no overwrite)
    // ──────────────────────────────────────────

    public function download(Request $request)
    {
        \App\Helpers\RegisterQueryHelper::assertFullDcsUser();
        @ini_set('memory_limit', '512M');
        @set_time_limit(600);
        $this->autoPlacementCache = [];

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
                    'certified_by'    => $validated['certified_by'] ?? '',
                    'designation'     => $validated['designation'] ?? '',
                ]
            );

            $stampLabel = strtolower(str_replace(' ', '-', self::STAMPS[$validated['stamp_type']]['label']));
            $baseName   = pathinfo($validated['file_path'], PATHINFO_FILENAME);
            $filename   = "stamped-{$stampLabel}-{$baseName}.pdf";

            return response()
                ->download($outputPath, $filename, ['Content-Type' => 'application/pdf'])
                ->deleteFileAfterSend(true);

        } catch (\Throwable $e) {
            Log::error('Stamp download error: ' . $e->getMessage());

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
            'label'      => self::STAMP_LABELS[$stamp->stamp_type] ?? self::STAMPS[$stamp->stamp_type]['label'] ?? $stamp->stamp_type,
            'stamped_at' => \Carbon\Carbon::parse($stamp->stamped_at)->format('M d, Y h:i A'),
            'stamped_by' => $stampedBy ?: 'Unknown',
        ]);
    }

    // ──────────────────────────────────────────
    // FILE PATH RESOLUTION
    // ──────────────────────────────────────────

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

            return;
        }

        if (!copy($stampedOutputPath, $resolvedAbsolutePath)) {
            throw new \RuntimeException('Failed to write stamped file to destination.');
        }
    }

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

    private function isValidPdfFile(string $absolutePath): bool
    {
        if (!is_readable($absolutePath) || filesize($absolutePath) < 5) {
            return false;
        }

        $head = file_get_contents($absolutePath, false, null, 0, 5);

        return $head === '%PDF-';
    }

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
            'retrieval' => DB::table('dcs_document_retrieval')->where('request_id', $requestId)->value('scanned_retrieval'),
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

    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    private function renderStamp($pdf, string $type, string $position, float $pageW, float $pageH, array $options, string $pdfPath, int $pageNum): void
    {
        $config = self::STAMPS[$type] ?? null;
        if (!$config) {
            throw new \RuntimeException('Unsupported stamp type: ' . $type);
        }

        $pos = $this->resolveStampPosition($pdfPath, $pageNum, $position, $pageW, $pageH, $config['width'], $config['height']);
        $this->drawStandard($pdf, $pos['x'], $pos['y'], $config);
    }

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
    private function clampToPage(array $pos, float $pageW, float $pageH, float $stampW, float $stampH): array
    {
        $pos['x'] = max(0, min($pos['x'], $pageW - $stampW));
        $pos['y'] = max(0, min($pos['y'], $pageH - $stampH));
        return $pos;
    }

    private function drawStandard($pdf, float $x, float $y, array $cfg): void
    {
        $w = $cfg['width'];
        $h = $cfg['height'];

        // Prefer clean stamp artwork (REFERENCE box only — no signature).
        $imageRel = (string) ($cfg['image'] ?? '');
        if ($imageRel !== '') {
            $imagePath = public_path($imageRel);
            if (is_file($imagePath) && is_readable($imagePath)) {
                $pdf->Image($imagePath, $x, $y, $w, $h, 'PNG');
                return;
            }
        }

        // Fallback: red rectangle + REFERENCE text only.
        [$r, $g, $b] = $cfg['color'];
        $pdf->SetDrawColor($r, $g, $b);
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($x, $y, $w, $h);
        $pdf->SetFont('Helvetica', 'B', $cfg['titleSize']);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x, $y + ($h / 2) - 3.5);
        $pdf->Cell($w, 7, $cfg['title'], 0, 0, 'C');
    }

    private function drawCertified($pdf, float $x, float $y, array $cfg, array $options): void
    {
        $w = $cfg['width'];
        $h = $cfg['height'];
        [$r, $g, $b] = $cfg['color'];
        $date   = now()->format('M d, Y');
        $certBy = $options['certified_by'] ?? '';
        $desig  = $options['designation'] ?? '';

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $w, $h, 'F');

        $pdf->SetDrawColor($r, $g, $b);
        $pdf->SetLineWidth(0.7);
        $pdf->Rect($x, $y, $w, $h);

        $pdf->SetLineWidth(0.3);
        $pdf->Rect($x + 1.5, $y + 1.5, $w - 3, $h - 3);

        $pdf->SetFont('Helvetica', 'B', $cfg['titleSize']);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x, $y + 3);
        $pdf->Cell($w, 5, $cfg['title'], 0, 0, 'C');

        $pdf->SetLineWidth(0.2);
        $pdf->Line($x + 4, $y + 9, $x + $w - 4, $y + 9);

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($x + 3, $y + 10.5);
        $pdf->Cell(25, 4, 'Certified by:', 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($w - 31, 4, $certBy, 0, 0, 'L');

        $pdf->SetLineWidth(0.15);
        $pdf->SetDrawColor(150, 150, 150);
        $pdf->Line($x + 25, $y + 14.5, $x + $w - 4, $y + 14.5);

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($x + 3, $y + 16);
        $pdf->Cell(25, 4, 'Designation:', 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($w - 31, 4, $desig, 0, 0, 'L');

        $pdf->Line($x + 25, $y + 20, $x + $w - 4, $y + 20);

        $pdf->SetDrawColor($r, $g, $b);
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x, $y + 23);
        $pdf->Cell($w, 4, 'Date: ' . $date, 0, 0, 'C');
    }
}
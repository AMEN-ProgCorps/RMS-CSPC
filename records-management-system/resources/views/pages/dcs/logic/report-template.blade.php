<?php

namespace App\Helpers;

use App\Services\DocumentStorageService;
use App\Services\PdfPageRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportTemplateHelper
{
    private const DCS_TEMPLATE_OFFICE = 'GENERAL';

    public static function list(): array
    {
        return DB::table('dcs_report_templates')
            ->orderByDesc('id')
            ->get(['id', 'name', 'preview_path', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'preview_url' => self::previewUrl($row->preview_path),
            ])
            ->all();
    }

    public static function store(Request $request): JsonResponse
    {
        $request->validate([
            'template' => 'required|file|mimes:pdf|max:10240',
            'name' => 'nullable|string|max:120',
        ]);

        $file = $request->file('template');
        $pdfContent = file_get_contents($file->getRealPath());
        $token = 'DCS-TPL-' . strtoupper(Str::random(8));
        $safeBase = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_') ?: 'template';
        $pdfPath = self::DCS_TEMPLATE_OFFICE . "/DCS/report_templates/{$token}_{$safeBase}.pdf";
        $previewPath = null;

        try {
            $tempPdf = Storage::disk('local')->path('temp/report-templates/' . uniqid('tpl_', true) . '.pdf');
            @mkdir(dirname($tempPdf), 0775, true);
            file_put_contents($tempPdf, $pdfContent);

            $imageName = self::DCS_TEMPLATE_OFFICE . '/DCS/report_templates/previews/' . $token . '.jpg';
            $imageFull = Storage::disk('local')->path('temp/report-templates/' . uniqid('preview_', true) . '.jpg');
            @mkdir(dirname($imageFull), 0775, true);
            PdfPageRenderer::savePage($tempPdf, $imageFull, 1);
            $previewContent = file_get_contents($imageFull);
            $previewPath = DocumentStorageService::storeDcsFileAtPath(
                $imageName,
                $previewContent,
                auth()->user(),
                basename($imageName),
                'image/jpeg'
            );

            DocumentStorageService::storeDcsFileAtPath(
                $pdfPath,
                $pdfContent,
                auth()->user(),
                $file->getClientOriginalName(),
                'application/pdf'
            );
        } catch (\Throwable $e) {
            Log::warning('Report template preview failed: ' . $e->getMessage());
            if ($previewPath) {
                DocumentStorageService::deleteDcsScan($previewPath);
            }
            if (isset($pdfPath)) {
                DocumentStorageService::deleteDcsScan($pdfPath);
            }

            return response()->json([
                'message' => 'Could not render page 1 of the PDF. Use a valid, unencrypted PDF. Details are in the application log.',
            ], 422);
        } finally {
            if (! empty($tempPdf) && is_file($tempPdf)) {
                @unlink($tempPdf);
            }
            if (! empty($imageFull) && is_file($imageFull)) {
                @unlink($imageFull);
            }
        }

        $name = trim((string) $request->input('name')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $id = DB::table('dcs_report_templates')->insertGetId([
            'name' => $name,
            'pdf_path' => $pdfPath,
            'preview_path' => $previewPath,
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'name' => $name,
            'preview_url' => self::previewUrl($previewPath),
        ], 201);
    }

    public static function letterheadDataUrl(int $templateId): ?string
    {
        if ($templateId <= 0) {
            return null;
        }

        $tpl = DB::table('dcs_report_templates')->where('id', $templateId)->first();
        if (! $tpl || ! $tpl->preview_path) {
            return null;
        }

        $content = self::readTemplateFile($tpl->preview_path);
        if ($content === null) {
            return null;
        }

        $mime = DocumentStorageService::dcsFileMimeType($tpl->preview_path);

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    public static function destroy(int $id): JsonResponse
    {
        $tpl = DB::table('dcs_report_templates')->where('id', $id)->first();
        if (! $tpl) {
            abort(404);
        }

        if (! empty($tpl->pdf_path)) {
            self::deleteTemplateFile($tpl->pdf_path);
        }
        if (! empty($tpl->preview_path)) {
            self::deleteTemplateFile($tpl->preview_path);
        }

        DB::table('dcs_report_templates')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    public static function render(Request $request)
    {
        $rawOffices = $request->input('offices', []);
        $copiesInput = $request->input('copies', []);

        $offices = [];
        if (is_array($rawOffices)) {
            foreach (array_values($rawOffices) as $i => $row) {
                if (is_array($row)) {
                    $name = trim((string) ($row['name'] ?? $row['office'] ?? ''));
                    $copies = $row['copies'] ?? '';
                } else {
                    $name = trim((string) $row);
                    $copies = is_array($copiesInput) ? ($copiesInput[$i] ?? '') : '';
                }
                if ($name === '') {
                    continue;
                }
                $copiesStr = is_numeric($copies) ? (string) (int) $copies : trim((string) $copies);
                $offices[] = [
                    'name' => $name,
                    'copies' => $copiesStr,
                ];
            }
        }

        if ($offices === []) {
            $offices = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                ->where('is_active', true)
                ->orderBy('office_name')
                ->pluck('office_name')
                ->map(fn ($name) => ['name' => $name, 'copies' => ''])
                ->all();
        }

        $date = $request->input('date') ?: now('Asia/Manila')->toDateString();
        $documentTitle = trim((string) $request->input('document_title', $request->input('title', '')));
        $effectivityRaw = trim((string) $request->input('effectivity_date', ''));
        $revisionRaw = trim((string) $request->input('revision_no', $request->input('revise_no', '')));

        $footerEffectivity = '';
        if ($effectivityRaw !== '') {
            try {
                $footerEffectivity = \Carbon\Carbon::parse($effectivityRaw)->format('F Y');
            } catch (\Throwable $e) {
                $footerEffectivity = $effectivityRaw;
            }
        }

        $footerRev = preg_match('/^-?\d+$/', $revisionRaw) ? (string) (int) $revisionRaw : $revisionRaw;

        $logoPath = public_path('images/logo.png');
        $logoSrc = self::croppedLogoDataUrl($logoPath);

        return view('pages.dcs.reports.distribution-template', [
            'offices' => $offices,
            'date' => $date,
            'documentTitle' => $documentTitle,
            'logoSrc' => $logoSrc,
            'republic' => 'Republic of the Philippines',
            'institutionName' => 'Camarines Sur Polytechnic Colleges',
            'institutionAddress' => 'Nabua, Camarines Sur',
            'letterNumber' => 'CSPC-F-DCC-05',
            'footerLeft' => 'Effectivity Date:',
            'footerCenter' => 'Rev.',
            'footerEffectivity' => $footerEffectivity,
            'footerRev' => $footerRev,
            'title' => 'DISTRIBUTION AND RETRIEVAL',
            'embed' => $request->boolean('embed'),
            'autoprint' => $request->boolean('autoprint'),
        ]);
    }

    /**
     * logo.png has large transparent padding (~23% each side). Crop to the
     * opaque seal so it fills the 0.64in × 0.66in header box in print.
     */
    private static function croppedLogoDataUrl(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        if (! function_exists('imagecreatefrompng')) {
            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
        }

        $src = @imagecreatefrompng($path);
        if (! $src) {
            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($src, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                // GD alpha: 0 opaque … 127 transparent
                if ($alpha < 120) {
                    if ($x < $minX) {
                        $minX = $x;
                    }
                    if ($y < $minY) {
                        $minY = $y;
                    }
                    if ($x > $maxX) {
                        $maxX = $x;
                    }
                    if ($y > $maxY) {
                        $maxY = $y;
                    }
                }
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            imagedestroy($src);

            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
        }

        // Small inset so the gear rim is not clipped hard
        $pad = (int) max(2, round(($maxX - $minX + 1) * 0.02));
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($w - 1, $maxX + $pad);
        $maxY = min($h - 1, $maxY + $pad);
        $cw = $maxX - $minX + 1;
        $ch = $maxY - $minY + 1;

        $dst = imagecreatetruecolor($cw, $ch);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $cw, $ch, $transparent);
        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, $minX, $minY, $cw, $ch);

        ob_start();
        imagepng($dst);
        $png = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $png !== false && $png !== ''
            ? ('data:image/png;base64,' . base64_encode($png))
            : '';
    }

    private static function previewUrl(?string $path): ?string
    {
        if (! $path || ! DocumentStorageService::dcsScanExists($path)) {
            return null;
        }

        if (DocumentStorageService::isLegacyPublicScanPath($path)) {
            return Storage::disk('public')->url($path);
        }

        return route('dcs.view-document', ['path' => $path]);
    }

    private static function readTemplateFile(string $path): ?string
    {
        if (DocumentStorageService::isLegacyPublicScanPath($path)) {
            return Storage::disk('public')->exists($path)
                ? Storage::disk('public')->get($path)
                : null;
        }

        return DocumentStorageService::getDcsScanContent($path);
    }

    private static function deleteTemplateFile(string $path): void
    {
        if (DocumentStorageService::isLegacyPublicScanPath($path)) {
            Storage::disk('public')->delete($path);

            return;
        }

        DocumentStorageService::deleteDcsScan($path);
    }
}

<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Printed Distribution and Retrieval sheet used from Document Registration. */
class ReportTemplateHelper
{
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
                $footerEffectivity = RegisterQueryHelper::formatSmartDate($effectivityRaw, '', true);
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
}

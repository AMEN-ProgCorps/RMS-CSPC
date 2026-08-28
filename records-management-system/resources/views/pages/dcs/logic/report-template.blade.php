<?php

namespace App\Helpers;

use App\Services\PdfPageRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportTemplateHelper
{
    public static function list(): array
    {
        return DB::table('dcs_report_templates')
            ->orderByDesc('id')
            ->get(['id', 'name', 'preview_path', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'preview_url' => $row->preview_path ? Storage::disk('public')->url($row->preview_path) : null,
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
        $pdfPath = $file->store('report_templates', 'public');
        $fullPdf = Storage::disk('public')->path($pdfPath);
        $previewPath = null;
        $imageFull = null;

        try {
            $imageName = 'report_templates/previews/' . uniqid('tpl_', true) . '.jpg';
            $imageFull = Storage::disk('public')->path($imageName);
            Storage::disk('public')->makeDirectory('report_templates/previews');
            PdfPageRenderer::savePage($fullPdf, $imageFull, 1);
            $previewPath = $imageName;
        } catch (\Throwable $e) {
            Log::warning('Report template preview failed: ' . $e->getMessage());
            Storage::disk('public')->delete($pdfPath);
            if (!empty($imageFull) && is_file($imageFull)) {
                @unlink($imageFull);
            }

            return response()->json([
                'message' => 'Could not render page 1 of the PDF. Use a valid, unencrypted PDF. Details are in the application log.',
            ], 422);
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
            'preview_url' => $previewPath ? Storage::disk('public')->url($previewPath) : null,
        ], 201);
    }

    public static function letterheadDataUrl(int $templateId): ?string
    {
        if ($templateId <= 0) {
            return null;
        }
        $tpl = DB::table('dcs_report_templates')->where('id', $templateId)->first();
        if (!$tpl || !$tpl->preview_path || !Storage::disk('public')->exists($tpl->preview_path)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode(Storage::disk('public')->get($tpl->preview_path));
    }

    public static function destroy(int $id): JsonResponse
    {
        $tpl = DB::table('dcs_report_templates')->where('id', $id)->first();
        if (!$tpl) {
            abort(404);
        }

        if (!empty($tpl->pdf_path)) {
            Storage::disk('public')->delete($tpl->pdf_path);
        }
        if (!empty($tpl->preview_path)) {
            Storage::disk('public')->delete($tpl->preview_path);
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
            $offices = DB::table('office')
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

        $templateId = (int) $request->input('template_id', 0);
        $letterheadUrl = null;
        $templateName = 'Built-in DCS';

        if ($templateId > 0) {
            $tpl = DB::table('dcs_report_templates')->where('id', $templateId)->first();
            if ($tpl) {
                $templateName = $tpl->name;
                $letterheadUrl = self::letterheadDataUrl($templateId);
            }
        }

        $logoPath = public_path('images/logo.png');
        $logoSrc = file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoPath))) : '';

        return view('pages.dcs.reports.distribution-template', [
            'offices' => $offices,
            'date' => $date,
            'documentTitle' => $documentTitle,
            'letterheadUrl' => $letterheadUrl,
            'templateName' => $templateName,
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
}

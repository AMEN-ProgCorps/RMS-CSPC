<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saved report column/filter templates for Generate Report.
 *
 * DEBUG MAP:
 *   Template missing after save       → Schema template tables; list/save methods
 *   Applied template wrong columns    → template payload keys vs ReportHelper columns
 */
class ReportTemplateHelper
{
    /**
     * Helper used for render.
     */
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

        $tpl = DcsPrintTemplateHelper::get('distribution');
        $identity = DcsPrintTemplateHelper::identityVars($tpl);
        // Custom upload keeps as-is; default seal uses the cropped live logo (DRF/DCN unchanged).
        $logoSrc = trim((string) ($tpl->logo_path ?? '')) !== '' && ($identity['logoSrc'] ?? '') !== ''
            ? $identity['logoSrc']
            : DcsPrintTemplateHelper::croppedLogoDataUrl(public_path('images/logo.png'));

        return view('pages.dcs.reports.distribution-template', [
            'offices' => $offices,
            'date' => $date,
            'documentTitle' => $documentTitle,
            'logoSrc' => $logoSrc,
            'republic' => $identity['republic'],
            'institutionName' => $identity['institutionName'],
            'institutionAddress' => $identity['institutionAddress'],
            'letterNumber' => $identity['letterNumber'] !== '' ? $identity['letterNumber'] : 'CSPC-F-DCC-05',
            'footerLeft' => $identity['footerLeft'],
            'footerCenter' => $identity['footerCenter'],
            'footerEffectivity' => $footerEffectivity !== '' ? $footerEffectivity : $identity['footerEffectivity'],
            'footerRev' => $footerRev !== '' ? $footerRev : $identity['footerRev'],
            'title' => $identity['formTitle'] !== '' ? $identity['formTitle'] : 'DISTRIBUTION AND RETRIEVAL',
            'templateColumns' => DcsPrintTemplateHelper::columnsPayload($tpl),
            'tableHeaderStyle' => DcsPrintTemplateHelper::columnsPayload($tpl)['style'],
            'embed' => $request->boolean('embed'),
            'autoprint' => $request->boolean('autoprint'),
        ]);
    }

}

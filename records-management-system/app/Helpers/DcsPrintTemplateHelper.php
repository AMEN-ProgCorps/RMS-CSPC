<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DCS print form templates (identity, layout_json, columns_json).
 *
 * DEBUG MAP:
 *   Wrong form code on print     → get()/forReportSub() key mapping; seed form_code
 *   Missing columns after edit   → applyColumnsJson(); catalog keys vs ReportHelper rows
 *   Blank layout print           → layoutBlocks(); fallback to defaultSeed()
 */
class DcsPrintTemplateHelper
{
    public const TABLE = 'dcs_print_form_templates';

    public const REVISIONS_TABLE = 'dcs_print_form_template_revisions';

    /** @var array<string, object>|null */
    private static ?array $cache = null;

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /**
     * Force re-read from DB / seeds (e.g. after Reset).
     */
    public static function refresh(string $key): object
    {
        self::flushCache();

        return self::get($key);
    }

    public static function templateKeys(): array
    {
        return array_keys(self::defaultSeeds());
    }

    /**
     * Published template for consumers (office intake / report exports).
     * Prefer live_json; else official seed. Editor should use getDraft().
     */
    public static function get(string $key): object
    {
        return self::getLive($key);
    }

    /**
     * Fill blank identity / layout from system defaults when a DB row was saved empty.
     *
     * @param  array<string, mixed>  $seed
     */
    private static function hydrateEmptyIdentityFromSeed(object $row, array $seed): object
    {
        if ($seed === []) {
            return $row;
        }

        $stringCols = [
            'label', 'form_code', 'form_title', 'republic', 'institution_name',
            'institution_address', 'footer_left', 'footer_center',
            'default_effectivity', 'default_rev',
        ];
        foreach ($stringCols as $col) {
            $current = $row->{$col} ?? null;
            if (($current === null || $current === '') && array_key_exists($col, $seed) && $seed[$col] !== null && $seed[$col] !== '') {
                $row->{$col} = $seed[$col];
            }
        }

        // Official DCN form is Rev. 1 — upgrade legacy seed/DB value of 0.
        if ((string) ($row->key ?? '') === 'office_dcn' && (string) ($row->default_rev ?? '') === '0') {
            $row->default_rev = (string) ($seed['default_rev'] ?? '1');
        }

        $layout = $row->layout_json ?? null;
        if (is_string($layout)) {
            $decoded = json_decode($layout, true);
            $layout = $decoded;
        }
        $blocks = is_array($layout) ? ($layout['blocks'] ?? $layout) : null;
        if ((! is_array($blocks) || $blocks === []) && isset($seed['layout_json'])) {
            $row->layout_json = $seed['layout_json'];
        }

        $page = $row->page_json ?? null;
        if (is_string($page)) {
            $page = json_decode($page, true);
        }
        if ((! is_array($page) || $page === []) && isset($seed['page_json'])) {
            $row->page_json = $seed['page_json'];
        }

        return $row;
    }

    public static function forReportSub(?string $sub): object
    {
        $map = [
            'drf' => 'office_drf',
            'dcn' => 'office_dcn',
            'internal_docs' => 'masterlist_internal_docs',
            'internal_forms' => 'masterlist_internal_forms',
            'external_docs' => 'masterlist_external_docs',
            'forms' => 'masterlist_forms',
            'logbooks' => 'masterlist_logbooks',
        ];

        return self::get($map[$sub ?? ''] ?? 'masterlist_internal_docs');
    }

    public static function identityVars(object $tpl): array
    {
        return [
            'republic' => (string) ($tpl->republic ?? 'Republic of the Philippines'),
            'institutionName' => (string) ($tpl->institution_name ?? 'Camarines Sur Polytechnic Colleges'),
            'institutionAddress' => (string) ($tpl->institution_address ?? 'Nabua, Camarines Sur'),
            'letterNumber' => (string) ($tpl->form_code ?? ''),
            'formTitle' => (string) ($tpl->form_title ?? ''),
            'footerLeft' => (string) ($tpl->footer_left ?? 'Effectivity Date:'),
            'footerCenter' => (string) ($tpl->footer_center ?? 'Rev.'),
            'footerEffectivity' => (string) ($tpl->default_effectivity ?? ''),
            'footerRev' => (string) ($tpl->default_rev ?? ''),
            'logoSrc' => self::logoUrl($tpl),
        ];
    }

    public static function logoUrl(object $tpl): string
    {
        $path = trim((string) ($tpl->logo_path ?? ''));
        if ($path !== '') {
            try {
                if (Storage::disk('public')->exists($path)) {
                    return Storage::disk('public')->url($path);
                }
                if (is_file($path)) {
                    return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
                }
            } catch (\Throwable $e) {
                // fall through to default
            }
        }

        $logoPath = public_path('images/logo.png');
        if (! is_file($logoPath)) {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
    }

    /**
     * logo.png has large transparent padding. Crop to the opaque seal so it
     * fills the print header box the same way as the live form.
     */
    public static function croppedLogoDataUrl(string $path): string
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

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * True when layout is the old simplified DCN seed (single From: field), not official dcn-print geometry.
     */
    public static function isLegacySimplifiedDcnLayout(mixed $layout): bool
    {
        if (! is_array($layout)) {
            return true;
        }
        $blocks = $layout['blocks'] ?? $layout;
        if (! is_array($blocks) || $blocks === []) {
            return true;
        }

        $hasRuledChangeFrom = false;
        $hasSimpleFromField = false;
        foreach ($blocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            $bind = (string) ($b['bind'] ?? '');
            $type = (string) ($b['type'] ?? '');
            $label = (string) ($b['label'] ?? '');
            if ($bind === 'change_from' && $type === 'ruled_lines') {
                $hasRuledChangeFrom = true;
            }
            if ($bind === 'change_from' && $type === 'field') {
                $hasSimpleFromField = true;
            }
            if (stripos($label, 'Detailed Description') !== false) {
                $hasRuledChangeFrom = true;
            }
        }

        return $hasSimpleFromField && ! $hasRuledChangeFrom;
    }

    /**
     * Map DCN canvas blocks → dcn-print geometry + field label overrides.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public static function dcnLayoutOverridesFromBlocks(array $blocks): array
    {
        $out = ['labels' => [], 'styles' => []];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $bind = (string) ($block['bind'] ?? '');
            $section = (string) ($block['section'] ?? '');
            $label = trim((string) ($block['label'] ?? ''));
            $x = (float) ($block['x'] ?? 0);
            $y = (float) ($block['y'] ?? 0);
            $w = (float) ($block['w'] ?? 0);
            $h = (float) ($block['h'] ?? 0);
            $style = self::normalizeBlockStyle(is_array($block['style'] ?? null) ? $block['style'] : []);

            // Typography / fill from Layout canvas → official DCN preview + print.
            if ($bind !== '') {
                $out['styles'][$bind] = $style;
            }

            if ($type === 'logo') {
                $out['logo'] = ['top' => $y, 'left' => $x, 'width' => $w, 'height' => $h];
            } elseif ($type === 'line' && $section === 'header') {
                $out['rule'] = [
                    'top' => $y,
                    'color' => trim((string) $style['lineColor']) !== '' ? (string) $style['lineColor'] : '#0071BC',
                ];
            } elseif ($type === 'line' && $section === 'footer') {
                $out['footer_rule'] = [
                    'color' => trim((string) $style['lineColor']) !== '' ? (string) $style['lineColor'] : '#0071BC',
                ];
            } elseif ($bind === 'form_code') {
                $out['form_code'] = ['top' => $y, 'left' => $x];
            } elseif ($bind === 'form_title') {
                // hdr-band is 1.00in; margin-top positions the title below it.
                $out['form_title'] = [
                    'margin_top' => max(0.05, round($y - 1.0, 4)),
                ];
            } elseif (in_array($bind, ['republic', 'institution_name', 'institution_address'], true)) {
                // Drive hdr-text-cell from the topmost branding block position.
                if (! isset($out['header_text'])) {
                    $out['header_text'] = ['top' => $y, 'left' => $x];
                } else {
                    $out['header_text']['top'] = min((float) $out['header_text']['top'], $y);
                    $out['header_text']['left'] = min((float) $out['header_text']['left'], $x);
                }
            }

            if ($bind !== '' && $label !== '') {
                $out['labels'][$bind] = $label;
            } elseif ($type === 'table' && $label !== '') {
                $out['labels']['approvals'] = $label;
            }

            if ($type === 'table') {
                $cols = max(1, min(12, (int) ($block['table_cols'] ?? 4)));
                $rows = max(1, min(12, (int) ($block['table_rows'] ?? 3)));
                $headers = is_array($block['table_headers'] ?? null) ? array_values($block['table_headers']) : [];
                while (count($headers) < $cols) {
                    $headers[] = '';
                }
                $headers = array_slice($headers, 0, $cols);

                // Official DCN Approvals header (Position / Names / Signature / Date).
                $isApprovals = $label === '' || stripos($label, 'approval') !== false || ! array_key_exists('table_cells', $block);
                if ($isApprovals && $headers !== []) {
                    $out['approvals_headers'] = $headers;
                    $out['approvals_borders'] = self::tableBordersVisible($block);
                    $out['approvals_style'] = $style;
                    $out['styles']['approvals'] = $style;
                }

                // Extra Word-like freeform tables (explicit table_cells on non-approvals blocks).
                if (array_key_exists('table_cells', $block) && stripos($label, 'approval') === false) {
                    $out['extra_tables'][] = [
                        'x' => $x,
                        'y' => $y,
                        'w' => max(0.5, $w),
                        'h' => max(0.3, $h),
                        'cols' => $cols,
                        'rows' => $rows,
                        'borders' => self::tableBordersVisible($block),
                        'cells' => self::padTableCells($block['table_cells'] ?? [], $rows, $cols),
                        'headers' => $headers,
                        'style' => $style,
                    ];
                }
            }
        }

        if (! isset($out['extra_tables'])) {
            $out['extra_tables'] = [];
        }

        // Canvas often stores "Detailed Description… — From:" as one label.
        $cf = (string) ($out['labels']['change_from'] ?? '');
        if ($cf !== '' && preg_match('/^(.*?)(?:\s*[—\-–]+\s*)?(From:)\s*$/iu', $cf, $m)) {
            $desc = trim((string) ($m[1] ?? ''));
            $out['labels']['change_desc'] = $desc !== '' ? $desc : 'Detailed Description of Change:';
            if (! str_ends_with($out['labels']['change_desc'], ':')) {
                $out['labels']['change_desc'] .= ':';
            }
            $out['labels']['change_from_only'] = 'From:';
        } elseif ($cf !== '') {
            $out['labels']['change_desc'] = $cf;
            $out['labels']['change_from_only'] = 'From:';
        }

        return $out;
    }

    /**
     * Layout overrides for the published office_dcn live template.
     *
     * @return array<string, mixed>
     */
    public static function dcnLiveLayoutOverrides(): array
    {
        return self::dcnLayoutOverridesFromBlocks(self::layoutBlocks(self::getLive('office_dcn')));
    }

    /**
     * Official DCN canvas blocks matched to dcn-print.blade.php.
     *
     * @return list<array<string, mixed>>
     */
    public static function officialDcnLayoutBlocks(): array
    {
        $seed = self::defaultSeeds()['office_dcn']['layout_json']['blocks'] ?? [];

        return is_array($seed) ? array_values($seed) : [];
    }

    public static function layoutBlocks(object $tpl): array
    {
        $layout = $tpl->layout_json ?? null;
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        if (! is_array($layout)) {
            $layout = [];
        }
        $blocks = $layout['blocks'] ?? $layout;
        if (! is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($blocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            $b['section'] = self::normalizeSection($b['section'] ?? null, (float) ($b['y'] ?? 0));
            $b['style'] = self::normalizeBlockStyle(is_array($b['style'] ?? null) ? $b['style'] : []);
            $normalized[] = $b;
        }

        usort($normalized, function (array $a, array $b): int {
            $order = ['header' => 0, 'body' => 1, 'footer' => 2];
            $sa = $order[$a['section'] ?? 'body'] ?? 1;
            $sb = $order[$b['section'] ?? 'body'] ?? 1;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return ((float) ($a['y'] ?? 0)) <=> ((float) ($b['y'] ?? 0));
        });

        return array_values($normalized);
    }

    public static function normalizeSection(mixed $section, float $y = 0.0): string
    {
        $section = strtolower(trim((string) $section));
        if (in_array($section, ['header', 'body', 'footer'], true)) {
            return $section;
        }
        if ($y < 0.95) {
            return 'header';
        }
        if ($y >= 6.5) {
            return 'footer';
        }

        return 'body';
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    public static function normalizeBlockStyle(array $style): array
    {
        $fontStyle = (string) ($style['fontStyle'] ?? 'normal');
        $align = strtolower(trim((string) ($style['align'] ?? 'left')));
        $verticalAlign = strtolower(trim((string) ($style['verticalAlign'] ?? 'top')));
        $objectFit = (string) ($style['objectFit'] ?? 'contain');

        return [
            'fontFamily' => (string) ($style['fontFamily'] ?? 'Arial'),
            'fontSizePt' => (float) ($style['fontSizePt'] ?? 11),
            'fontWeight' => (int) ($style['fontWeight'] ?? 400),
            'fontStyle' => in_array($fontStyle, ['normal', 'italic'], true) ? $fontStyle : 'normal',
            'underline' => ! empty($style['underline']),
            'align' => in_array($align, ['left', 'center', 'right', 'justify', 'start', 'end'], true) ? $align : 'left',
            'verticalAlign' => in_array($verticalAlign, ['top', 'middle', 'bottom', 'baseline'], true) ? $verticalAlign : 'top',
            'color' => (string) ($style['color'] ?? '#000000'),
            'backgroundColor' => (string) ($style['backgroundColor'] ?? ''),
            'lineColor' => (string) ($style['lineColor'] ?? '#0d2a7a'),
            'objectFit' => in_array($objectFit, ['contain', 'cover', 'fill'], true) ? $objectFit : 'contain',
        ];
    }

    /**
     * Convert a normalized block style into an inline CSS string for official print blades.
     *
     * @param  array<string, mixed>  $style
     * @param  array{typography?: bool, background?: bool, important?: bool, underline?: bool}  $opts
     */
    public static function blockStyleToInlineCss(array $style, array $opts = []): string
    {
        $style = self::normalizeBlockStyle($style);
        $wantTypo = ! array_key_exists('typography', $opts) || (bool) $opts['typography'];
        $wantBg = ! array_key_exists('background', $opts) || (bool) $opts['background'];
        // Official DCN print uses separate write-in rules; label text must not inherit canvas underline.
        $wantUnderline = array_key_exists('underline', $opts)
            ? (bool) $opts['underline']
            : true;
        $bang = ! empty($opts['important']) ? ' !important' : '';
        $parts = [];

        if ($wantTypo) {
            $family = trim((string) $style['fontFamily']) !== '' ? (string) $style['fontFamily'] : 'Arial';
            $color = trim((string) $style['color']) !== '' ? (string) $style['color'] : '#000000';
            $parts[] = 'font-family:'.$family.', Helvetica, sans-serif'.$bang;
            $parts[] = 'font-size:'.(float) $style['fontSizePt'].'pt'.$bang;
            $parts[] = 'font-weight:'.(int) $style['fontWeight'].$bang;
            $parts[] = 'font-style:'.$style['fontStyle'].$bang;
            $parts[] = 'text-align:'.$style['align'].$bang;
            $parts[] = 'color:'.$color.$bang;
            if ($wantUnderline && ! empty($style['underline'])) {
                $parts[] = 'text-decoration:underline'.$bang;
            } elseif (! $wantUnderline) {
                $parts[] = 'text-decoration:none'.$bang;
            }
        }

        if ($wantBg) {
            $bg = trim((string) ($style['backgroundColor'] ?? ''));
            if ($bg !== '') {
                $parts[] = 'background-color:'.$bg.$bang;
            }
        }

        return $parts !== [] ? implode(';', $parts).';' : '';
    }

    /**
     * Pad / truncate a freeform table cell matrix to rows × cols (Word-like tables).
     *
     * @param  mixed  $cells
     * @return list<list<string>>
     */
    public static function padTableCells(mixed $cells, int $rows, int $cols): array
    {
        $rows = max(1, min(20, $rows));
        $cols = max(1, min(20, $cols));
        $src = is_array($cells) ? $cells : [];
        $out = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = $src[$r] ?? $src[(string) $r] ?? [];
            if (! is_array($row)) {
                $row = [];
            }
            $line = [];
            for ($c = 0; $c < $cols; $c++) {
                $line[] = trim((string) ($row[$c] ?? $row[(string) $c] ?? ''));
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Whether a table block should draw cell borders (default true).
     */
    public static function tableBordersVisible(array $block): bool
    {
        if (! array_key_exists('table_borders', $block)) {
            return true;
        }

        return filter_var($block['table_borders'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{group_headers: list<array>, columns: list<array>, style: array<string, mixed>}
     */
    public static function columnsPayload(object $tpl): array
    {
        $key = (string) ($tpl->key ?? '');
        $seed = self::defaultSeeds()[$key] ?? null;
        $seedCols = is_array($seed['columns_json'] ?? null) ? $seed['columns_json'] : [];

        $cols = $tpl->columns_json ?? null;
        if (is_string($cols)) {
            $cols = json_decode($cols, true);
        }
        if (! is_array($cols)) {
            $cols = $seedCols;
        }

        $groups = array_values($cols['group_headers'] ?? []);
        $columns = array_values($cols['columns'] ?? []);
        $style = self::normalizeTableStyle(is_array($cols['style'] ?? null) ? $cols['style'] : []);

        // Live CSPC-F-DCC-05: white DISTRIBUTION/RETRIEVAL headers (upgrade old blue default).
        if ($key === 'distribution') {
            if (strtolower((string) ($style['header_row_bg'] ?? '')) === '#8db3e2') {
                $style = self::normalizeTableStyle(is_array($seedCols['style'] ?? null) ? $seedCols['style'] : [
                    'header_row_bg' => '#ffffff',
                    'header_row_color' => '#000000',
                    'header_font_size_pt' => 11,
                ]);
            }
            if (count($groups) < 2 && is_array($seedCols['group_headers'] ?? null)) {
                $groups = array_values($seedCols['group_headers']);
            }
            if (count($columns) < 6 && is_array($seedCols['columns'] ?? null)) {
                $columns = array_values($seedCols['columns']);
            }
        }

        return [
            'group_headers' => $groups,
            'columns' => $columns,
            'style' => $style,
        ];
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    public static function normalizeTableStyle(array $style): array
    {
        $fontStyle = (string) ($style['header_font_style'] ?? 'normal');

        return [
            'header_row_bg' => (string) ($style['header_row_bg'] ?? '#8db3e2'),
            'header_row_color' => (string) ($style['header_row_color'] ?? '#000000'),
            'header_font_family' => (string) ($style['header_font_family'] ?? 'Arial'),
            'header_font_size_pt' => (float) ($style['header_font_size_pt'] ?? 10),
            'header_font_weight' => (int) ($style['header_font_weight'] ?? 700),
            'header_font_style' => in_array($fontStyle, ['normal', 'italic'], true) ? $fontStyle : 'normal',
        ];
    }

    /**
     * Merge template columns onto ReportHelper column maps.
     *
     * @param  array{columns?: array, group_headers?: array}  $data
     * @return array{columns: array, group_headers: array}
     */
    public static function applyColumnsJson(array $data, object $tpl): array
    {
        $payload = self::columnsPayload($tpl);
        if ($payload['columns'] === []) {
            return [
                'columns' => $data['columns'] ?? [],
                'group_headers' => $data['group_headers'] ?? [],
            ];
        }

        $columns = [];
        $groupHeaders = [];
        foreach ($payload['columns'] as $col) {
            if (! is_array($col) || empty($col['visible'])) {
                continue;
            }
            $key = (string) ($col['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $columns[$key] = (string) ($col['label'] ?? $key);
            $group = $col['group'] ?? null;
            if ($group !== null && $group !== '') {
                $groupHeaders[$key] = (string) $group;
            } else {
                $groupHeaders[$key] = null;
            }
        }

        if ($columns === []) {
            return [
                'columns' => $data['columns'] ?? [],
                'group_headers' => $data['group_headers'] ?? [],
            ];
        }

        return [
            'columns' => $columns,
            'group_headers' => $groupHeaders,
        ];
    }

    /**
     * @return list<array{key: string, label: string, repeatable?: bool}>
     */
    public static function fieldCatalog(string $templateKey): array
    {
        return match ($templateKey) {
            'office_drf' => [
                ['key' => 'republic', 'label' => 'Republic (branding)'],
                ['key' => 'institution_name', 'label' => 'Institution (branding)'],
                ['key' => 'institution_address', 'label' => 'Address (branding)'],
                ['key' => 'form_code', 'label' => 'Form code (branding)'],
                ['key' => 'form_title', 'label' => 'Form title (branding)'],
                ['key' => 'request_no', 'label' => 'Request #'],
                ['key' => 'drf_date', 'label' => 'Date'],
                ['key' => 'originator', 'label' => 'Originator'],
                ['key' => 'doc_title', 'label' => 'Document Title'],
                ['key' => 'previous_document_no', 'label' => 'Recurring from document no.'],
                ['key' => 'doc_type', 'label' => 'Type of document'],
                ['key' => 'description', 'label' => 'Description/reason'],
                ['key' => 'distribute_to', 'label' => 'Distribute document to'],
                ['key' => 'prepared_by_name', 'label' => 'Prepared by — Name', 'repeatable' => true],
                ['key' => 'prepared_by_designation', 'label' => 'Prepared by — Designation', 'repeatable' => true],
                ['key' => 'reviewed_by_name', 'label' => 'Reviewed by — Name', 'repeatable' => true],
                ['key' => 'reviewed_by_designation', 'label' => 'Reviewed by — Designation', 'repeatable' => true],
                ['key' => 'approved_by_name', 'label' => 'Approved by — Name', 'repeatable' => true],
                ['key' => 'approved_by_designation', 'label' => 'Approved by — Designation', 'repeatable' => true],
            ],
            'office_dcn' => [
                ['key' => 'republic', 'label' => 'Republic (branding)'],
                ['key' => 'institution_name', 'label' => 'Institution (branding)'],
                ['key' => 'institution_address', 'label' => 'Address (branding)'],
                ['key' => 'form_code', 'label' => 'Form code (branding)'],
                ['key' => 'form_title', 'label' => 'Form title (branding)'],
                ['key' => 'dcn_no', 'label' => 'DCN #'],
                ['key' => 'document_no', 'label' => 'Document No'],
                ['key' => 'document_title', 'label' => 'Title'],
                ['key' => 'change_from', 'label' => 'From'],
                ['key' => 'change_to', 'label' => 'To'],
                ['key' => 'justification', 'label' => 'Justification of Change'],
                ['key' => 'originator', 'label' => 'Originator'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'reviewed_by', 'label' => 'Reviewed by', 'repeatable' => true],
                ['key' => 'approved_by', 'label' => 'Approved by', 'repeatable' => true],
            ],
            'distribution' => [
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'signature', 'label' => 'Signature'],
                ['key' => 'dist_date', 'label' => 'Distribution Date'],
                ['key' => 'copies', 'label' => 'Copies'],
                ['key' => 'retrieved_by', 'label' => 'Retrieved By'],
                ['key' => 'ret_date', 'label' => 'Retrieval Date'],
                ['key' => 'document_title', 'label' => 'Title of Document'],
            ],
            default => [
                ['key' => 'item_no', 'label' => 'Item No.'],
                ['key' => 'doc_no', 'label' => 'Doc. No.'],
                ['key' => 'rev_no', 'label' => 'Rev No.'],
                ['key' => 'doc_title', 'label' => 'Document Title'],
                ['key' => 'effectivity_date', 'label' => 'Effectivity Date'],
                ['key' => 'originator', 'label' => 'Originator'],
                ['key' => 'no_pages', 'label' => 'No. of pages'],
                ['key' => 'pdf_path', 'label' => 'PDF FILE'],
            ],
        };
    }

    /**
     * Canonical page sizes used by form templates.
     * Short bond = US Letter; A4 = ISO 216 (not the same).
     *
     * @return array<string, array{label: string, width_in: float, height_in: float}>
     */
    public static function pageSizes(): array
    {
        return [
            'short_bond' => [
                'label' => 'Short bond (8.5 × 11 in)',
                'width_in' => 8.5,
                'height_in' => 11.0,
            ],
            'long_bond' => [
                'label' => 'Long bond (8.5 × 13 in)',
                'width_in' => 8.5,
                'height_in' => 13.0,
            ],
            'a4' => [
                'label' => 'A4 (210 × 297 mm / 8.27 × 11.69 in)',
                'width_in' => 8.27,
                'height_in' => 11.69,
            ],
        ];
    }

    /**
     * @return array{size: string, width_in: float, height_in: float, margin_in: float}
     */
    public static function resolvePageSize(string $size, ?float $widthIn = null, ?float $heightIn = null): array
    {
        $sizes = self::pageSizes();
        // Legacy: older templates stored "a4" with Letter dimensions — treat bare a4 with 8.5×11 as short_bond.
        if ($size === 'a4' && $widthIn !== null && $heightIn !== null
            && abs($widthIn - 8.5) < 0.02 && abs($heightIn - 11.0) < 0.02) {
            $size = 'short_bond';
        }
        if (! isset($sizes[$size])) {
            $size = 'short_bond';
        }
        $spec = $sizes[$size];

        return [
            'size' => $size,
            'width_in' => $widthIn !== null && $widthIn > 0 ? $widthIn : $spec['width_in'],
            'height_in' => $heightIn !== null && $heightIn > 0 ? $heightIn : $spec['height_in'],
            'margin_in' => 0,
        ];
    }

    public static function pageSpec(object $tpl): array
    {
        $page = $tpl->page_json ?? null;
        if (is_string($page)) {
            $page = json_decode($page, true);
        }
        if (! is_array($page)) {
            $seed = self::defaultSeeds()[(string) ($tpl->key ?? '')] ?? [];
            $page = is_array($seed['page_json'] ?? null) ? $seed['page_json'] : [];
        }

        $size = (string) ($page['size'] ?? 'short_bond');
        $width = isset($page['width_in']) ? (float) $page['width_in'] : null;
        $height = isset($page['height_in']) ? (float) $page['height_in'] : null;
        $resolved = self::resolvePageSize($size, $width, $height);
        $resolved['margin_in'] = (float) ($page['margin_in'] ?? 0);

        return $resolved;
    }

    public static function resolveBind(array $values, ?string $bind): string
    {
        $bind = trim((string) $bind);
        if ($bind === '') {
            return '';
        }
        $val = $values[$bind] ?? '';
        if (is_array($val)) {
            return implode(', ', array_map(fn ($v) => trim((string) $v), $val));
        }

        return trim((string) $val);
    }

    /**
     * Bind values for office DRF layout printing / intake preview.
     *
     * @param  list<array{office_id?: int, code?: string, name?: string}>  $distributeOffices
     * @param  iterable<int, object|array<string, mixed>>  $sourceOffices
     * @return array<string, string>
     */
    public static function drfLayoutValues(object $drf, array $distributeOffices = [], iterable $sourceOffices = []): array
    {
        $dist = [];
        foreach ($distributeOffices as $office) {
            if (! is_array($office)) {
                continue;
            }
            $code = trim((string) ($office['code'] ?? ''));
            $name = trim((string) ($office['name'] ?? ''));
            $label = $code !== '' ? $code : $name;
            if ($label !== '') {
                $dist[] = $label;
            }
        }

        $originator = trim((string) ($drf->originator_name ?? ''));
        if ($originator === '') {
            $names = [];
            foreach ($sourceOffices as $office) {
                $row = is_object($office) ? $office : (object) $office;
                $name = trim((string) ($row->office_name ?? $row->name ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $originator = implode(', ', $names);
        }

        $docType = trim((string) (
            $drf->doc_type_label
            ?? $drf->type_of_document
            ?? $drf->doc_type_kind
            ?? ''
        ));
        if ($docType !== '' && ! str_contains($docType, ' ')) {
            $docType = Str::title(str_replace(['_', '-'], ' ', $docType));
        }

        return [
            'request_no' => trim((string) ($drf->request_no ?? $drf->drf_no ?? '')),
            'drf_date' => self::formatLayoutDate($drf->drf_date ?? null),
            'originator' => $originator,
            'doc_title' => trim((string) ($drf->doc_title ?? '')),
            'previous_document_no' => trim((string) ($drf->previous_document_no ?? '')),
            'doc_type' => $docType,
            'description' => trim((string) ($drf->description_reason ?? $drf->description ?? $drf->reason ?? '')),
            'distribute_to' => implode(', ', $dist),
            'prepared_by_name' => trim((string) ($drf->prepared_by_name ?? '')),
            'prepared_by_designation' => trim((string) ($drf->prepared_by_designation ?? '')),
            'reviewed_by_name' => trim((string) ($drf->reviewed_by_name ?? '')),
            'reviewed_by_designation' => trim((string) ($drf->reviewed_by_designation ?? '')),
            'approved_by_name' => trim((string) ($drf->approved_by_name ?? '')),
            'approved_by_designation' => trim((string) ($drf->approved_by_designation ?? '')),
        ];
    }

    /**
     * Bind values for office DCN layout printing / intake preview.
     *
     * @param  iterable<int, object|array<string, mixed>>  $sourceOffices
     * @return array<string, string>
     */
    public static function dcnLayoutValues(object $dcn, iterable $sourceOffices = []): array
    {
        $originator = trim((string) ($dcn->originator_name ?? ''));
        if ($originator === '') {
            $names = [];
            foreach ($sourceOffices as $office) {
                $row = is_object($office) ? $office : (object) $office;
                $name = trim((string) ($row->office_name ?? $row->name ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $originator = implode(', ', $names);
        }

        $deptCode = trim((string) ($dcn->preview_department_code ?? $dcn->department_code ?? ''));
        $deptName = trim((string) ($dcn->preview_department ?? $dcn->department ?? ''));
        $deptDate = self::formatLayoutDate($dcn->department_on ?? $dcn->department_date ?? null, 'M d, Y');
        $departmentParts = array_values(array_filter([$deptCode, $deptName, $deptDate], fn ($p) => $p !== ''));
        $department = implode(' / ', $departmentParts);

        $reviewed = trim((string) ($dcn->reviewed_by_name ?? $dcn->reviewed_by ?? ''));
        if ($reviewed === '' && ! empty($dcn->preview_reviewers) && is_array($dcn->preview_reviewers)) {
            $labels = [];
            foreach ($dcn->preview_reviewers as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $label = trim((string) ($row['label'] ?? $row['name'] ?? ''));
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
            $reviewed = implode(', ', $labels);
        }

        return [
            'dcn_no' => trim((string) ($dcn->dcn_no ?? '')),
            'document_no' => trim((string) ($dcn->document_no ?? $dcn->doc_no ?? '')),
            'document_title' => trim((string) ($dcn->document_title ?? $dcn->doc_title ?? '')),
            'change_from' => trim((string) ($dcn->change_from ?? $dcn->from_description ?? '')),
            'change_to' => trim((string) ($dcn->change_to ?? $dcn->to_description ?? '')),
            'justification' => trim((string) ($dcn->brief_purpose ?? $dcn->justification ?? $dcn->purpose ?? '')),
            'originator' => $originator,
            'department' => $department,
            'reviewed_by' => $reviewed,
            'approved_by' => trim((string) ($dcn->approved_by_name ?? $dcn->approved_by ?? '')),
        ];
    }

    private static function formatLayoutDate(mixed $value, string $format = 'F d, Y'): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($value)->format($format);
        } catch (\Throwable $e) {
            return trim((string) $value);
        }
    }

    /**
     * Snapshot current template layout/columns before an overwrite.
     *
     * @param  array<string, mixed>|null  $layoutJson
     * @param  array<string, mixed>|null  $columnsJson
     * @param  array<string, mixed>|null  $pageJson
     */
    /**
     * @return int|null New or existing revision id
     */
    public static function pushRevision(
        string $templateKey,
        ?array $layoutJson,
        ?array $columnsJson = null,
        ?array $pageJson = null,
        string $source = 'save',
        string $kind = 'layout',
        ?string $label = null,
        bool $forceInsert = false
    ): ?int {
        if (! Schema::hasTable(self::REVISIONS_TABLE)) {
            return null;
        }

        $blocks = is_array($layoutJson['blocks'] ?? null) ? $layoutJson['blocks'] : [];
        $layoutPayload = json_encode($layoutJson ?? ['blocks' => []]);
        $columnsPayload = $columnsJson !== null ? json_encode($columnsJson) : null;
        // Skip duplicate snapshot when content is unchanged (e.g. repeated Restore).
        // forceInsert=true always creates a new Saved versions row (Save as new).
        if (! $forceInsert) {
            try {
                $latest = DB::table(self::REVISIONS_TABLE)
                    ->where('template_key', $templateKey)
                    ->orderByDesc('id')
                    ->first(['id', 'layout_json', 'columns_json', 'kind', 'source']);
                if ($latest
                    && (string) ($latest->layout_json ?? '') === (string) $layoutPayload
                    && (string) ($latest->columns_json ?? '') === (string) ($columnsPayload ?? '')
                    && (string) ($latest->kind ?? '') === $kind
                    && (string) ($latest->source ?? '') === $source
                ) {
                    return (int) ($latest->id ?? 0) ?: null;
                }
            } catch (\Throwable $ignored) {
            }
        }
        try {
            return (int) DB::table(self::REVISIONS_TABLE)->insertGetId([
                'template_key' => $templateKey,
                'label' => $label,
                'layout_json' => $layoutPayload,
                'columns_json' => $columnsPayload,
                'page_json' => $pageJson !== null ? json_encode($pageJson) : null,
                'block_count' => count($blocks),
                'source' => $source,
                'kind' => $kind,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Older revisions table without columns_json/kind — retry minimal insert.
            try {
                return (int) DB::table(self::REVISIONS_TABLE)->insertGetId([
                    'template_key' => $templateKey,
                    'label' => $label,
                    'layout_json' => json_encode($layoutJson ?? ['blocks' => []]),
                    'page_json' => $pageJson !== null ? json_encode($pageJson) : null,
                    'block_count' => count($blocks),
                    'source' => $source,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $ignored) {
                // Revisions are best-effort; never block save.
            }
        }

        return null;
    }

    /**
     * If live exists but has no revision_id, attach the matching Saved/Official row
     * (or create one) and stamp live_json. Returns that History id.
     */
    public static function ensureLiveRevisionTracked(string $key): ?int
    {
        $status = self::liveStatus($key);
        if (empty($status['has_live'])) {
            return null;
        }

        $existingLiveRevId = (int) ($status['live_revision_id'] ?? 0);
        if ($existingLiveRevId > 0) {
            $existingRow = Schema::hasTable(self::REVISIONS_TABLE)
                ? DB::table(self::REVISIONS_TABLE)->where('id', $existingLiveRevId)->first([
                    'id', 'label', 'source', 'kind', 'layout_json', 'columns_json',
                ])
                : null;
            $label = trim((string) ($existingRow->label ?? ''));
            $source = (string) ($existingRow->source ?? '');
            $isTracker = $existingRow && (
                strcasecmp($label, 'Current live') === 0
                || strcasecmp($label, 'Live layout') === 0
            );
            // Official (stock) may be the live pointer while live_json holds custom edits — keep it.
            if ($existingRow && $source === 'official') {
                return $existingLiveRevId;
            }
            if ($existingRow && ! $isTracker) {
                // Keep a named/saved stamp only when that row still matches live content.
                $liveProbe = self::getLive($key);
                $isFormProbe = in_array($key, ['office_drf', 'office_dcn'], true);
                $liveFp = self::revisionSnapshotFingerprint(
                    $isFormProbe ? 'layout' : 'columns',
                    $isFormProbe ? ['blocks' => self::layoutBlocks($liveProbe)] : ['blocks' => []],
                    $isFormProbe ? null : self::columnsPayload($liveProbe)
                );
                $rowFp = self::revisionSnapshotFingerprint(
                    (string) ($existingRow->kind ?? ($isFormProbe ? 'layout' : 'columns')),
                    self::decodeJsonField($existingRow->layout_json ?? null),
                    self::decodeJsonField($existingRow->columns_json ?? null)
                );
                if ($liveFp === $rowFp) {
                    return $existingLiveRevId;
                }
                // Stale auto-save pointer — retarget below (prefer Official when present).
            }
        }

        $live = self::getLive($key);
        $isForm = in_array($key, ['office_drf', 'office_dcn'], true);
        $layout = self::layoutBlocks($live);
        $columnsPayload = self::columnsPayload($live);
        $page = self::pageSpec($live);
        $kind = $isForm ? 'layout' : 'columns';
        $liveFingerprint = self::revisionSnapshotFingerprint(
            $kind,
            $isForm ? ['blocks' => $layout] : ['blocks' => []],
            $isForm ? null : [
                'style' => $columnsPayload['style'] ?? self::normalizeTableStyle([]),
                'group_headers' => $columnsPayload['group_headers'] ?? [],
                'columns' => $columnsPayload['columns'] ?? [],
            ]
        );

        // Prefer an existing Official/Saved row with the same snapshot — avoid "Current live" dupes.
        $revId = null;
        if (Schema::hasTable(self::REVISIONS_TABLE)) {
            $candidates = DB::table(self::REVISIONS_TABLE)
                ->where('template_key', $key)
                ->whereIn('source', ['official', 'save'])
                ->where(function ($q) {
                    $q->whereNull('label')->orWhere('label', '<>', 'Current live');
                })
                ->orderByRaw("CASE WHEN source = 'official' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get(['id', 'kind', 'layout_json', 'columns_json']);
            foreach ($candidates as $candidate) {
                $candKind = (string) ($candidate->kind ?? $kind);
                $fingerprint = self::revisionSnapshotFingerprint(
                    $candKind,
                    self::decodeJsonField($candidate->layout_json ?? null),
                    self::decodeJsonField($candidate->columns_json ?? null)
                );
                if ($fingerprint !== $liveFingerprint) {
                    continue;
                }
                $revId = (int) ($candidate->id ?? 0) ?: null;
                break;
            }
        }

        if (! $revId) {
            $layoutForRev = $isForm ? ['blocks' => $layout] : ['blocks' => []];
            $columnsForRev = $isForm ? null : [
                'style' => $columnsPayload['style'] ?? self::normalizeTableStyle([]),
                'group_headers' => $columnsPayload['group_headers'] ?? [],
                'columns' => $columnsPayload['columns'] ?? [],
            ];
            $pageForRev = [
                'size' => $page['size'],
                'width_in' => $page['width_in'],
                'height_in' => $page['height_in'],
                'margin_in' => 0,
            ];

            // Prefer overwriting the newest real save instead of creating "Current live" leftovers.
            $newestSaveId = (int) (DB::table(self::REVISIONS_TABLE)
                ->where('template_key', $key)
                ->where('source', 'save')
                ->where(function ($q) {
                    $q->whereNull('label')->orWhere('label', '<>', 'Current live');
                })
                ->orderByDesc('id')
                ->value('id') ?? 0);
            if ($newestSaveId > 0 && self::updateRevision($newestSaveId, $layoutForRev, $columnsForRev, $pageForRev, $kind)) {
                $revId = $newestSaveId;
            } else {
                $revId = self::pushRevision(
                    $key,
                    $layoutForRev,
                    $columnsForRev,
                    $pageForRev,
                    'save',
                    $kind,
                    'Live layout'
                );
            }
        }

        if (! $revId || ! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'live_json')) {
            return $revId;
        }

        if ($existingLiveRevId === $revId) {
            return $revId;
        }

        $row = DB::table(self::TABLE)->where('key', $key)->first();
        if (! $row) {
            return $revId;
        }
        $livePayload = self::livePayloadFromRow(self::normalizeRow($row)) ?? [];
        $livePayload['revision_id'] = $revId;
        DB::table(self::TABLE)->where('id', $row->id)->update([
            'live_json' => json_encode($livePayload),
            'updated_at' => now(),
        ]);
        self::flushCache();

        return $revId;
    }

    /**
     * Ensure a permanent Official (stock) History row exists for easy Enable live.
     */
    public static function ensureOfficialHistory(string $templateKey): ?int
    {
        if (! Schema::hasTable(self::REVISIONS_TABLE)) {
            return null;
        }
        $seeds = self::defaultSeeds();
        if (! isset($seeds[$templateKey])) {
            return null;
        }

        $existing = DB::table(self::REVISIONS_TABLE)
            ->where('template_key', $templateKey)
            ->where('source', 'official')
            ->orderByDesc('id')
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $seed = $seeds[$templateKey];
        $isForm = in_array($templateKey, ['office_drf', 'office_dcn'], true);
        $layout = is_array($seed['layout_json'] ?? null) ? $seed['layout_json'] : ['blocks' => []];
        $columns = is_array($seed['columns_json'] ?? null) ? $seed['columns_json'] : null;
        $page = is_array($seed['page_json'] ?? null) ? $seed['page_json'] : null;
        $kind = $isForm ? 'layout' : 'columns';
        $label = $isForm
            ? ('Official ' . ($templateKey === 'office_dcn' ? 'DCN' : 'DRF') . ' layout')
            : 'Official columns';

        return self::pushRevision(
            $templateKey,
            $isForm ? $layout : ['blocks' => []],
            $isForm ? null : $columns,
            $page,
            'official',
            $kind,
            $label
        );
    }

    /**
     * Drop leftover "Restored previous layout" rows (feature removed; should not appear in History).
     */
    public static function purgeRestoredPreviousRevisions(?string $templateKey = null): void
    {
        if (! Schema::hasTable(self::REVISIONS_TABLE)) {
            return;
        }
        try {
            $q = DB::table(self::REVISIONS_TABLE)
                ->where('label', 'like', 'Restored previous layout%');
            if ($templateKey !== null && $templateKey !== '') {
                $q->where('template_key', $templateKey);
            }
            $q->delete();
        } catch (\Throwable $ignored) {
            // Best-effort cleanup.
        }
    }

    /**
     * Drop tracker-only leftovers ("Current live" / "Live layout") that are not the active live pointer.
     * Does not delete normal "Saved …" drafts — those are intentional Save as new versions.
     */
    public static function purgeAutodatedSaveLeftovers(?string $templateKey = null): void
    {
        if (! Schema::hasTable(self::REVISIONS_TABLE)) {
            return;
        }

        try {
            $keys = $templateKey !== null && $templateKey !== ''
                ? [$templateKey]
                : DB::table(self::REVISIONS_TABLE)->distinct()->pluck('template_key')->all();

            foreach ($keys as $key) {
                $key = (string) $key;
                if ($key === '') {
                    continue;
                }
                $liveRevisionId = (int) (self::liveStatus($key)['live_revision_id'] ?? 0);
                $rows = DB::table(self::REVISIONS_TABLE)
                    ->where('template_key', $key)
                    ->where('source', 'save')
                    ->get(['id', 'label']);

                $deleteIds = [];
                foreach ($rows as $row) {
                    $id = (int) ($row->id ?? 0);
                    $label = trim((string) ($row->label ?? ''));
                    if ($id < 1 || $id === $liveRevisionId) {
                        continue;
                    }
                    if (strcasecmp($label, 'Current live') === 0 || strcasecmp($label, 'Live layout') === 0) {
                        $deleteIds[] = $id;
                    }
                }

                if ($deleteIds !== []) {
                    DB::table(self::REVISIONS_TABLE)->whereIn('id', $deleteIds)->delete();
                }
            }
        } catch (\Throwable $ignored) {
            // Best-effort cleanup.
        }
    }

    /**
     * Remove duplicate Saved versions created by Enable live (same snapshot as an earlier row).
     * Keeps official rows, the live revision, and the first unique save of each snapshot.
     */
    public static function purgeDuplicateSaveRevisions(?string $templateKey = null): void
    {
        if (! Schema::hasTable(self::REVISIONS_TABLE)) {
            return;
        }

        try {
            $keys = $templateKey !== null && $templateKey !== ''
                ? [$templateKey]
                : DB::table(self::REVISIONS_TABLE)->distinct()->pluck('template_key')->all();

            foreach ($keys as $key) {
                $key = (string) $key;
                if ($key === '') {
                    continue;
                }

                // Retarget live off tracker-only rows onto Official/Saved when possible.
                self::ensureLiveRevisionTracked($key);
                $liveRevisionId = (int) (self::liveStatus($key)['live_revision_id'] ?? 0);
                $rows = DB::table(self::REVISIONS_TABLE)
                    ->where('template_key', $key)
                    ->orderBy('id')
                    ->get(['id', 'source', 'kind', 'label', 'layout_json', 'columns_json']);

                $seen = [];
                $deleteIds = [];
                foreach ($rows as $row) {
                    $id = (int) ($row->id ?? 0);
                    $source = (string) ($row->source ?? 'save');
                    $fingerprint = self::revisionSnapshotFingerprint(
                        (string) ($row->kind ?? ''),
                        self::decodeJsonField($row->layout_json ?? null),
                        self::decodeJsonField($row->columns_json ?? null)
                    );
                    $label = trim((string) ($row->label ?? ''));

                    // Leftover tracker rows from earlier Enable-live experiments.
                    $isTrackerLabel = strcasecmp($label, 'Current live') === 0
                        || strcasecmp($label, 'Live layout') === 0;

                    if ($source === 'official') {
                        $seen[$fingerprint] = ['id' => $id, 'label' => $label];
                        continue;
                    }

                    // Never drop the revision currently stamped as live.
                    if ($id === $liveRevisionId) {
                        $seen[$fingerprint] = ['id' => $id, 'label' => $label];
                        continue;
                    }

                    // Leftover "Current live" trackers that are not the active live pointer.
                    if ($isTrackerLabel) {
                        $deleteIds[] = $id;
                        continue;
                    }

                    // Same content as an earlier row: drop only when labels match (accidental
                    // duplicate). Keep "Save as new" copies that share layout but have a new name.
                    if (isset($seen[$fingerprint])) {
                        $prevLabel = trim((string) ($seen[$fingerprint]['label'] ?? ''));
                        if (strcasecmp($label, $prevLabel) === 0 || $label === '' || $prevLabel === '') {
                            $deleteIds[] = $id;
                            continue;
                        }
                        // Different label — intentional Save as new; keep both.
                        continue;
                    }

                    $seen[$fingerprint] = ['id' => $id, 'label' => $label];
                }

                if ($deleteIds !== []) {
                    DB::table(self::REVISIONS_TABLE)->whereIn('id', $deleteIds)->delete();
                }
            }
        } catch (\Throwable $ignored) {
            // Best-effort cleanup.
        }
    }

    /**
     * Saved + official stock versions (excludes restored-previous noise).
     *
     * @param  'layout'|'columns'|null  $kindFilter  layout = Save layout only; columns = Save columns only
     * @return list<array<string, mixed>>
     */
    public static function listRevisions(string $templateKey, int $limit = 20, ?string $kindFilter = null): array
    {
        if (! Schema::hasTable(self::REVISIONS_TABLE)) {
            return [];
        }

        self::purgeRestoredPreviousRevisions($templateKey);
        self::ensureOfficialHistory($templateKey);
        self::purgeDuplicateSaveRevisions($templateKey);
        self::purgeAutodatedSaveLeftovers($templateKey);

        $query = DB::table(self::REVISIONS_TABLE)
            ->where('template_key', $templateKey)
            ->whereIn('source', ['save', 'official'])
            ->where(function ($q) {
                $q->whereNull('label')
                    ->orWhere('label', 'not like', 'Restored previous layout%');
            });

        if ($kindFilter === 'layout') {
            $query->whereIn('kind', ['layout', 'full']);
        } elseif ($kindFilter === 'columns') {
            $query->whereIn('kind', ['columns', 'full']);
        }

        // Newest saves first; Official (stock) stays at the bottom.
        return $query
            ->orderByRaw("CASE WHEN source = 'official' THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(function ($row) {
                $source = (string) ($row->source ?? 'save');
                $label = trim((string) ($row->label ?? ''));
                if ($label === '') {
                    $label = $source === 'official' ? 'Official (stock)' : 'Saved layout';
                }

                return [
                    'id' => (int) $row->id,
                    'label' => $label,
                    'source' => $source,
                    'kind' => (string) ($row->kind ?? 'layout'),
                    'block_count' => (int) ($row->block_count ?? 0),
                    'created_at' => (string) ($row->created_at ?? ''),
                    'has_columns' => ! empty($row->columns_json),
                    'is_official' => $source === 'official',
                    'is_live' => false,
                ];
            })
            ->all();
    }

    public static function findRevision(int $id): ?object
    {
        if ($id < 1 || ! Schema::hasTable(self::REVISIONS_TABLE)) {
            return null;
        }
        $row = DB::table(self::REVISIONS_TABLE)->where('id', $id)->first();
        if (! $row) {
            return null;
        }
        foreach (['layout_json', 'columns_json', 'page_json'] as $col) {
            if (isset($row->{$col}) && is_string($row->{$col})) {
                $row->{$col} = json_decode($row->{$col}, true);
            }
        }

        return $row;
    }

    /**
     * Overwrite an existing Saved versions row (used when updating the live template in place).
     * Refuses to mutate Official (stock) rows.
     */
    public static function updateRevision(
        int $id,
        ?array $layoutJson,
        ?array $columnsJson = null,
        ?array $pageJson = null,
        ?string $kind = null
    ): bool {
        if ($id < 1 || ! Schema::hasTable(self::REVISIONS_TABLE)) {
            return false;
        }

        $row = DB::table(self::REVISIONS_TABLE)->where('id', $id)->first(['id', 'source']);
        if (! $row || (string) ($row->source ?? '') === 'official') {
            return false;
        }

        $blocks = is_array($layoutJson['blocks'] ?? null) ? $layoutJson['blocks'] : [];
        $payload = [
            'layout_json' => json_encode($layoutJson ?? ['blocks' => []]),
            'block_count' => count($blocks),
            'updated_at' => now(),
        ];
        if ($columnsJson !== null) {
            $payload['columns_json'] = json_encode($columnsJson);
        }
        if ($pageJson !== null) {
            $payload['page_json'] = json_encode($pageJson);
        }
        if ($kind !== null && $kind !== '') {
            $payload['kind'] = $kind;
        }

        try {
            DB::table(self::REVISIONS_TABLE)->where('id', $id)->update($payload);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Seed rows for migration / reset-to-default.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultSeeds(): array
    {
        $identity = [
            'republic' => 'Republic of the Philippines',
            'institution_name' => 'Camarines Sur Polytechnic Colleges',
            'institution_address' => 'Nabua, Camarines Sur',
            'footer_left' => 'Effectivity Date:',
            'footer_center' => 'Rev.',
            'logo_path' => null,
        ];

        $tableStyle = self::normalizeTableStyle([]);
        $mlColumns = [
            'style' => $tableStyle,
            'group_headers' => [],
            'columns' => [
                ['key' => 'item_no', 'label' => 'Item<br>No.', 'width' => '6%', 'visible' => true],
                ['key' => 'doc_no', 'label' => 'Doc. No.', 'width' => '12%', 'visible' => true],
                ['key' => 'rev_no', 'label' => 'Rev<br>No.', 'width' => '6%', 'visible' => true],
                ['key' => 'doc_title', 'label' => 'Document Title', 'width' => '28%', 'visible' => true],
                ['key' => 'effectivity_date', 'label' => 'Effectivity<br>Date', 'width' => '12%', 'visible' => true],
                ['key' => 'originator', 'label' => 'Originator', 'width' => '18%', 'visible' => true],
                ['key' => 'no_pages', 'label' => 'No.<br>of pages', 'width' => '8%', 'visible' => true],
                ['key' => 'pdf_path', 'label' => 'PDF FILE', 'width' => '10%', 'visible' => true],
            ],
        ];

        return [
            'office_drf' => array_merge($identity, [
                'label' => 'Office DRF (Document Request Form)',
                'form_code' => 'CSPC-F-DCC-06',
                'form_title' => 'Document Request Form',
                'default_effectivity' => 'January 2018',
                'default_rev' => '0',
                'page_json' => ['size' => 'long_bond', 'width_in' => 8.5, 'height_in' => 13.0, 'margin_in' => 0],
                'columns_json' => null,
                'layout_json' => self::defaultDrfLayout(),
            ]),
            'office_dcn' => array_merge($identity, [
                'label' => 'Office DCN (Document Change Notice)',
                'form_code' => 'CSPC-F-DCC-01',
                'form_title' => 'Document Change Notice',
                'default_effectivity' => 'January 2018',
                'default_rev' => '1',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'columns_json' => null,
                'layout_json' => self::defaultDcnLayout(),
            ]),
            'distribution' => array_merge($identity, [
                'label' => 'Distribution and Retrieval',
                'form_code' => 'CSPC-F-DCC-05',
                'form_title' => 'DISTRIBUTION AND RETRIEVAL',
                'default_effectivity' => 'August 2019',
                'default_rev' => '2',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'layout_json' => ['blocks' => []],
                'columns_json' => [
                    // Match live CSPC-F-DCC-05: white group headers, black text.
                    'style' => self::normalizeTableStyle([
                        'header_row_bg' => '#ffffff',
                        'header_row_color' => '#000000',
                        'header_font_family' => 'Arial',
                        'header_font_size_pt' => 11,
                        'header_font_weight' => 700,
                        'header_font_style' => 'normal',
                    ]),
                    'group_headers' => [
                        ['label' => 'DISTRIBUTION', 'span' => 4],
                        ['label' => 'RETRIEVAL', 'span' => 2],
                    ],
                    'columns' => [
                        ['key' => 'department', 'label' => 'Department', 'width' => '34%', 'visible' => true, 'group' => 'DISTRIBUTION'],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '16%', 'visible' => true, 'group' => 'DISTRIBUTION'],
                        ['key' => 'dist_date', 'label' => 'Date', 'width' => '12%', 'visible' => true, 'group' => 'DISTRIBUTION'],
                        ['key' => 'copies', 'label' => 'Copies', 'width' => '10%', 'visible' => true, 'group' => 'DISTRIBUTION'],
                        ['key' => 'retrieved_by', 'label' => 'By', 'width' => '14%', 'visible' => true, 'group' => 'RETRIEVAL'],
                        ['key' => 'ret_date', 'label' => 'Date', 'width' => '14%', 'visible' => true, 'group' => 'RETRIEVAL'],
                    ],
                ],
            ]),
            'masterlist_internal_docs' => array_merge($identity, [
                'label' => 'Generate Report — Internal',
                'form_code' => 'CSPC-F-DCC-03',
                'form_title' => 'Document Masterlist (Internal)',
                'default_effectivity' => 'January 2025',
                'default_rev' => '4',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'layout_json' => ['blocks' => []],
                'columns_json' => $mlColumns,
            ]),
            'masterlist_internal_forms' => array_merge($identity, [
                'label' => 'Generate Report — Internal Forms',
                'form_code' => 'CSPC-F-DCC-03',
                'form_title' => 'Document Masterlist (Internal Forms)',
                'default_effectivity' => 'August 2021',
                'default_rev' => '3',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'layout_json' => ['blocks' => []],
                'columns_json' => $mlColumns,
            ]),
            'masterlist_external_docs' => array_merge($identity, [
                'label' => 'Generate Report — External',
                'form_code' => 'CSPC-F-DCC-03',
                'form_title' => 'Document Masterlist (External)',
                'default_effectivity' => 'August 2021',
                'default_rev' => '3',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'layout_json' => ['blocks' => []],
                'columns_json' => $mlColumns,
            ]),
            'masterlist_forms' => array_merge($identity, [
                'label' => 'Generate Report — Forms',
                'form_code' => 'CSPC-F-DCC-03',
                'form_title' => 'Document Masterlist (Forms)',
                'default_effectivity' => 'August 2021',
                'default_rev' => '3',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'layout_json' => ['blocks' => []],
                'columns_json' => $mlColumns,
            ]),
            'masterlist_logbooks' => array_merge($identity, [
                'label' => 'Generate Report — Logbooks',
                'form_code' => 'CSPC-F-DCC-03',
                'form_title' => 'Document Masterlist (Logbooks)',
                'default_effectivity' => 'August 2021',
                'default_rev' => '3',
                'page_json' => ['size' => 'short_bond', 'width_in' => 8.5, 'height_in' => 11.0, 'margin_in' => 0],
                'layout_json' => ['blocks' => []],
                'columns_json' => $mlColumns,
            ]),
        ];
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    private static function defaultDrfLayout(): array
    {
        // Geometry matched to resources/views/pages/dcs/office/drf-print.blade.php
        // Official uses 13.5pt field rows, 10pt check row + 13pt gap, 13pt desc lines, 12.5pt dist rows.
        $pt = static fn (float $pt): float => round($pt / 72, 4);
        $style = self::normalizeBlockStyle([
            'fontFamily' => 'Arial',
            'fontSizePt' => 11,
            'color' => '#000000',
            'lineColor' => '#0070C0',
        ]);

        $rowH = $pt(13.5);       // .row
        $checkH = $pt(10);       // .check-row height
        $checkGap = $pt(13);     // .check-row margin-bottom
        $titleH = $pt(12);       // .form-title
        $titleGap = $pt(11);     // .form-title margin-bottom
        $descLabel = $pt(11);
        $descLine = $pt(13);     // .desc-line
        $descAfter = $pt(14);    // .desc-block margin-bottom
        $distLabel = $pt(11);
        $distLine = $pt(12.5);   // .dist-line
        $distAfter = $pt(27);    // .dist-grid margin-bottom
        $sigRow = 0.21;          // .sig-table th/td height
        $lineH = $pt(2.5);       // header/footer blue rule

        // Header band ends at 0.78in; body padding-top 0.27in → first content at 1.05in
        $y = 0.78 + 0.27; // 1.05
        $titleY = $y;
        $y = $titleY + $titleH + $titleGap;
        $requestY = $y;
        $originatorY = $requestY + $rowH;
        $docTitleY = $originatorY + $rowH;
        $checkY = $docTitleY + $rowH;
        $descY = $checkY + $checkH + $checkGap;
        $descH = $descLabel + (4 * $descLine);
        $distY = $descY + $descH + $descAfter;
        $distH = $distLabel + (6 * $distLine);
        $tableY = $distY + $distH + $distAfter;
        $tableH = 5 * $sigRow; // header + 4 body rows
        $footerLineY = $tableY + $tableH + $pt(10);
        $footerTextY = $footerLineY + $lineH + $pt(4);

        return [
            'blocks' => [
                // Header band (logo + text + blue rule + form code)
                self::block('logo', 'header', 0.46, 0.16, 0.57, 0.59, '', '', $style),
                self::block('text', 'header', 1.13, 0.20, 5.6, $pt(11), 'Republic of the Philippines', 'republic', array_merge($style, ['align' => 'left', 'fontSizePt' => 11, 'fontWeight' => 400])),
                self::block('text', 'header', 1.13, 0.38, 5.6, $pt(11), 'CAMARINES SUR POLYTECHNIC COLLEGES', 'institution_name', array_merge($style, ['align' => 'left', 'fontWeight' => 700, 'fontSizePt' => 11])),
                self::block('text', 'header', 1.13, 0.56, 5.6, $pt(11), 'Nabua, Camarines Sur', 'institution_address', array_merge($style, ['align' => 'left', 'fontSizePt' => 11, 'fontWeight' => 400])),
                self::block('line', 'header', 0.49, 0.76, 6.55, $lineH, 'Line', '', array_merge($style, ['lineColor' => '#0070C0'])),
                self::block('text', 'header', 7.05, 0.70, 1.2, $pt(10), 'CSPC-F-DCC-06', 'form_code', array_merge($style, ['fontWeight' => 700, 'fontSizePt' => 10, 'align' => 'right'])),

                // Body — identical spacing to official DRF print
                self::block('text', 'body', 0.5, $titleY, 7.5, $titleH, 'DOCUMENT REQUEST FORM', 'form_title', array_merge($style, ['align' => 'center', 'fontWeight' => 700, 'fontSizePt' => 12])),
                self::block('field', 'body', 0.5, $requestY, 1.55, $rowH, 'Request # :', 'request_no', array_merge($style, ['underline' => true])),
                self::block('field', 'body', 4.55, $requestY, 2.24, $rowH, 'Date:', 'drf_date', array_merge($style, ['underline' => true])),
                self::block('field', 'body', 0.5, $originatorY, 7.5, $rowH, 'Originator :', 'originator', array_merge($style, ['underline' => true])),
                self::block('field', 'body', 0.5, $docTitleY, 7.5, $rowH, 'Document Title :', 'doc_title', array_merge($style, ['underline' => true])),
                array_merge(
                    self::block('checkboxes', 'body', 0.5, $checkY, 7.5, $checkH, 'Type of document:', 'doc_type', $style),
                    ['options' => ['Internal', 'External']]
                ),
                array_merge(
                    self::block('ruled_lines', 'body', 0.5, $descY, 7.5, $descH, 'Description/reason for request (define in detail):', 'description', $style),
                    ['line_count' => 4]
                ),
                array_merge(
                    self::block('dist_grid', 'body', 0.5, $distY, 7.5, $distH, 'Distribute document to (department/position):', 'distribute_to', $style),
                    ['grid_cols' => 4, 'grid_rows' => 6]
                ),
                array_merge(
                    self::block('table', 'body', 0.45, $tableY, 7.6, $tableH, 'Signature table', '', array_merge($style, ['backgroundColor' => '', 'fontSizePt' => 11, 'align' => 'center'])),
                    [
                        'table_cols' => 4,
                        'table_rows' => 4,
                        'table_headers' => ['', 'Prepared by:', 'Reviewed by:', 'Approved by:'],
                        'table_row_labels' => ['Signature', 'Name', 'Designation', 'Date'],
                        'table_cell_binds' => [
                            '1' => ['1' => 'prepared_by_name', '2' => 'reviewed_by_name', '3' => 'approved_by_name'],
                            '2' => ['1' => 'prepared_by_designation', '2' => 'reviewed_by_designation', '3' => 'approved_by_designation'],
                        ],
                    ]
                ),
                self::block('line', 'footer', 0.4, $footerLineY, 7.7, $lineH, 'Line', '', array_merge($style, ['lineColor' => '#0070C0'])),
                // Equal thirds so Footer center (Rev. 0) sits in the page center.
                self::block('text', 'footer', 0.5, $footerTextY, 2.5, $pt(7), 'Effectivity Date: January 2018', 'footer_effectivity_line', array_merge($style, ['align' => 'left', 'fontSizePt' => 7, 'fontWeight' => 700])),
                self::block('text', 'footer', 3.0, $footerTextY, 2.5, $pt(7), 'Rev. 0', 'footer_rev_line', array_merge($style, ['align' => 'center', 'fontSizePt' => 7, 'fontWeight' => 700])),
                self::block('text', 'footer', 5.5, $footerTextY, 2.5, $pt(7), 'Page: 1 of 1', '', array_merge($style, ['align' => 'right', 'fontSizePt' => 7, 'fontWeight' => 700])),
            ],
        ];
    }

    /**
     * Geometry matched to resources/views/pages/dcs/office/dcn-print.blade.php
     * (header band, blue rule + form code, Detailed Description From/To, approvals).
     *
     * @return array{blocks: list<array<string, mixed>>}
     */
    private static function defaultDcnLayout(): array
    {
        $pt = static fn (float $pt): float => round($pt / 72, 4);
        $style = self::normalizeBlockStyle([
            'fontFamily' => 'Arial',
            'fontSizePt' => 11,
            'color' => '#000000',
            'lineColor' => '#0071BC',
        ]);
        $lineH = $pt(1.5);
        $band = 0.18; // official description band height

        // Header ends ~1.00in; title margin 0.35in → ~1.35in
        $titleY = 1.35;
        $dcnNoY = $titleY + $pt(12) + 0.14;
        $tableY = $dcnNoY + 0.22 + 0.18; // after DCN # row
        $docNoY = $tableY + 0.19;
        $titleFieldY = $docNoY + 0.18 + 0.18;
        $descY = $titleFieldY + 0.40;
        $descH = (3 * $band) + (6 * $band) + $band + (7 * $band) + $band; // label/from/to structure
        $justY = $descY + $descH + 0.06;
        $justH = 5 * $band;
        $sigY = $justY + $justH + 0.08;
        $reviewY = $sigY + 0.36;
        $approvalsY = $reviewY + 0.50;
        $approvalsH = 0.90;
        $footerLineY = min(10.35, $approvalsY + $approvalsH + 0.20);
        $footerTextY = $footerLineY + 0.08;

        return [
            'blocks' => [
                // Header — matches dcn-print hdr-band
                self::block('logo', 'header', 0.51, 0.24, 0.64, 0.66, '', '', $style),
                self::block('text', 'header', 1.22, 0.35, 5.5, $pt(10), 'Republic of the Philippines', 'republic', array_merge($style, ['align' => 'left', 'fontSizePt' => 10, 'fontWeight' => 400])),
                self::block('text', 'header', 1.22, 0.52, 5.5, $pt(10), 'CAMARINES SUR POLYTECHNIC COLLEGES', 'institution_name', array_merge($style, ['align' => 'left', 'fontWeight' => 700, 'fontSizePt' => 10])),
                self::block('text', 'header', 1.22, 0.69, 5.5, $pt(10), 'Nabua, Camarines Sur', 'institution_address', array_merge($style, ['align' => 'left', 'fontSizePt' => 10, 'fontWeight' => 400])),
                self::block('line', 'header', 0.37, 0.95, 6.4, $lineH, 'Line', '', array_merge($style, ['lineColor' => '#0071BC'])),
                self::block('text', 'header', 6.85, 0.88, 1.15, $pt(12), 'CSPC-F-DCC-01', 'form_code', array_merge($style, ['fontWeight' => 700, 'fontSizePt' => 12, 'align' => 'right'])),

                // Body — official DCN structure
                self::block('text', 'body', 0.69, $titleY, 7.1, $pt(12), 'Document Change Notice', 'form_title', array_merge($style, ['align' => 'center', 'fontWeight' => 700, 'fontSizePt' => 12])),
                self::block('field', 'body', 1.42, $dcnNoY, 2.2, 0.22, 'DCN #', 'dcn_no', array_merge($style, ['underline' => true, 'fontSizePt' => 11])),
                self::block('field', 'body', 1.34, $docNoY, 3.2, 0.22, 'Document No:', 'document_no', array_merge($style, ['underline' => true, 'fontSizePt' => 11])),
                self::block('field', 'body', 2.72, $titleFieldY, 4.8, 0.28, 'Title:', 'document_title', array_merge($style, ['underline' => true, 'fontSizePt' => 10])),
                array_merge(
                    self::block('ruled_lines', 'body', 1.34, $descY, 6.39, max(1.6, $descH * 0.55), 'Detailed Description of Change — From:', 'change_from', $style),
                    ['line_count' => 6]
                ),
                array_merge(
                    self::block('ruled_lines', 'body', 1.34, $descY + max(1.6, $descH * 0.55) + 0.08, 6.39, 1.5, 'To:', 'change_to', $style),
                    ['line_count' => 7]
                ),
                array_merge(
                    self::block('ruled_lines', 'body', 1.34, $justY, 6.39, $justH, 'Justification of Change:', 'justification', $style),
                    ['line_count' => 4]
                ),
                self::block('field', 'body', 1.34, $sigY, 3.5, 0.28, 'Originator/ Signature:', 'originator', array_merge($style, ['underline' => true])),
                self::block('field', 'body', 4.95, $sigY, 2.78, 0.28, 'Department/ Date:', 'department', array_merge($style, ['underline' => true])),
                self::block('signature', 'body', 1.34, $reviewY, 6.39, 0.42, 'Reviewed by/ Date:', 'reviewed_by', $style),
                array_merge(
                    self::block('table', 'body', 1.34, $approvalsY, 6.39, $approvalsH, 'Approvals', '', array_merge($style, ['fontSizePt' => 10, 'align' => 'center'])),
                    [
                        'table_cols' => 4,
                        'table_rows' => 3,
                        'table_headers' => ['Position', 'Names', 'Signature', 'Date'],
                        'table_row_labels' => ['', '', ''],
                        'table_cell_binds' => [
                            '0' => ['1' => 'approved_by'],
                        ],
                    ]
                ),

                // Footer
                self::block('line', 'footer', 0.5, $footerLineY, 7.5, $lineH, 'Line', '', array_merge($style, ['lineColor' => '#0071BC'])),
                self::block('text', 'footer', 0.5, $footerTextY, 2.5, $pt(9), 'Effectivity Date: January 2018', 'footer_effectivity_line', array_merge($style, ['align' => 'left', 'fontSizePt' => 9, 'fontWeight' => 700])),
                self::block('text', 'footer', 3.0, $footerTextY, 2.5, $pt(9), 'Rev. 1', 'footer_rev_line', array_merge($style, ['align' => 'center', 'fontSizePt' => 9, 'fontWeight' => 700])),
                self::block('text', 'footer', 5.5, $footerTextY, 2.5, $pt(9), 'Page: 1 of 1', '', array_merge($style, ['align' => 'right', 'fontSizePt' => 9, 'fontWeight' => 700])),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private static function block(
        string $type,
        string $section,
        float $x,
        float $y,
        float $w,
        float $h,
        string $label,
        string $bind,
        array $style
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'section' => self::normalizeSection($section, $y),
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'unit' => 'in',
            'label' => $label,
            'bind' => $bind,
            'style' => self::normalizeBlockStyle($style),
        ];
    }

    private static function normalizeRow(object $row): object
    {
        foreach (['layout_json', 'columns_json', 'page_json', 'live_json', 'live_json_previous'] as $jsonCol) {
            if (isset($row->{$jsonCol}) && is_string($row->{$jsonCol})) {
                $decoded = json_decode($row->{$jsonCol}, true);
                $row->{$jsonCol} = $decoded;
            }
        }

        return $row;
    }

    /**
     * Draft row as stored in the editor (does not apply live_json overlay).
     */
    public static function getDraft(string $key): object
    {
        $defaults = self::defaultSeeds();
        if (! isset($defaults[$key])) {
            $key = 'office_drf';
        }

        self::warmCache();

        if (isset(self::$cache[$key])) {
            return self::hydrateEmptyIdentityFromSeed(
                self::cloneTpl(self::$cache[$key]),
                $defaults[$key] ?? []
            );
        }

        return self::normalizeRow((object) array_merge($defaults[$key], ['key' => $key]));
    }

    /**
     * Published template for office intake / report exports.
     * Prefer live_json snapshot; otherwise official seed.
     */
    public static function getLive(string $key): object
    {
        $defaults = self::defaultSeeds();
        if (! isset($defaults[$key])) {
            $key = 'office_drf';
        }

        self::warmCache();
        $seed = $defaults[$key] ?? [];

        if (isset(self::$cache[$key])) {
            $draft = self::cloneTpl(self::$cache[$key]);
            $livePayload = self::livePayloadFromRow($draft);
            if ($livePayload !== null) {
                return self::hydrateEmptyIdentityFromSeed(
                    self::tplFromLivePayload($key, $livePayload, $draft),
                    $seed
                );
            }

            // Never published — fall back to official seed (not unsaved draft).
            return self::normalizeRow((object) array_merge($seed, ['key' => $key]));
        }

        return self::normalizeRow((object) array_merge($seed, ['key' => $key]));
    }

    /**
     * Copy current draft fields into live_json and stamp live_at.
     * Optionally record which History revision was published.
     * Keeps the previous live_json so Reset can restore the last published template.
     */
    public static function publishLive(string $key, ?int $revisionId = null): bool
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'live_json')) {
            return false;
        }

        $row = DB::table(self::TABLE)->where('key', $key)->first();
        if (! $row) {
            return false;
        }

        $live = [
            'label' => $row->label ?? null,
            'form_code' => $row->form_code ?? null,
            'form_title' => $row->form_title ?? null,
            'republic' => $row->republic ?? null,
            'institution_name' => $row->institution_name ?? null,
            'institution_address' => $row->institution_address ?? null,
            'footer_left' => $row->footer_left ?? null,
            'footer_center' => $row->footer_center ?? null,
            'default_effectivity' => $row->default_effectivity ?? null,
            'default_rev' => $row->default_rev ?? null,
            'logo_path' => $row->logo_path ?? null,
            'layout_json' => self::decodeJsonField($row->layout_json ?? null),
            'columns_json' => self::decodeJsonField($row->columns_json ?? null),
            'page_json' => self::decodeJsonField($row->page_json ?? null),
            'revision_id' => $revisionId && $revisionId > 0 ? $revisionId : null,
        ];

        $update = [
            'live_json' => json_encode($live),
            'live_at' => now(),
            'updated_at' => now(),
        ];

        // Snapshot the currently published live template before replacing it.
        $existingLive = self::livePayloadFromRow(self::normalizeRow($row));
        if ($existingLive !== null && Schema::hasColumn(self::TABLE, 'live_json_previous')) {
            $update['live_json_previous'] = json_encode($existingLive);
            if (Schema::hasColumn(self::TABLE, 'live_previous_at')) {
                $update['live_previous_at'] = now();
            }
        }

        DB::table(self::TABLE)->where('id', $row->id)->update($update);
        self::flushCache();

        return true;
    }

    /**
     * True when a previous published live snapshot exists for Reset.
     */
    public static function hasPreviousLive(string $key): bool
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'live_json_previous')) {
            return false;
        }
        self::warmCache();
        $row = self::$cache[$key] ?? null;
        if (! $row) {
            return false;
        }
        $prev = $row->live_json_previous ?? null;
        if (is_string($prev)) {
            $prev = self::decodeJsonField($prev);
        }

        return is_array($prev) && $prev !== [];
    }

    /**
     * Restore live (+ draft) from live_json_previous and clear the snapshot.
     * Also rewrites the stamped Saved versions row when it is not Official.
     *
     * @return array{ok: bool, revision_id: ?int, message: string}
     */
    public static function restorePreviousLive(string $key): array
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'live_json_previous')) {
            return ['ok' => false, 'revision_id' => null, 'message' => 'Previous live snapshot is not available.'];
        }

        $row = DB::table(self::TABLE)->where('key', $key)->first();
        if (! $row) {
            return ['ok' => false, 'revision_id' => null, 'message' => 'Template not found.'];
        }

        $previous = self::decodeJsonField($row->live_json_previous ?? null);
        if (! is_array($previous) || $previous === []) {
            return ['ok' => false, 'revision_id' => null, 'message' => 'No previous live template to restore. Update live once first.'];
        }

        $revisionId = isset($previous['revision_id']) ? (int) $previous['revision_id'] : null;
        $layout = is_array($previous['layout_json'] ?? null) ? $previous['layout_json'] : ['blocks' => []];
        $columns = is_array($previous['columns_json'] ?? null) ? $previous['columns_json'] : null;
        $page = is_array($previous['page_json'] ?? null) ? $previous['page_json'] : null;

        $draftUpdate = [
            'label' => $previous['label'] ?? $row->label,
            'form_code' => $previous['form_code'] ?? $row->form_code,
            'form_title' => $previous['form_title'] ?? $row->form_title,
            'republic' => $previous['republic'] ?? $row->republic,
            'institution_name' => $previous['institution_name'] ?? $row->institution_name,
            'institution_address' => $previous['institution_address'] ?? $row->institution_address,
            'footer_left' => $previous['footer_left'] ?? $row->footer_left,
            'footer_center' => $previous['footer_center'] ?? $row->footer_center,
            'default_effectivity' => $previous['default_effectivity'] ?? $row->default_effectivity,
            'default_rev' => $previous['default_rev'] ?? $row->default_rev,
            'logo_path' => $previous['logo_path'] ?? $row->logo_path,
            'layout_json' => json_encode($layout),
            'columns_json' => $columns !== null ? json_encode($columns) : $row->columns_json,
            'page_json' => $page !== null ? json_encode($page) : $row->page_json,
            'live_json' => json_encode($previous),
            'live_at' => now(),
            'live_json_previous' => null,
            'updated_at' => now(),
        ];
        if (Schema::hasColumn(self::TABLE, 'live_previous_at')) {
            $draftUpdate['live_previous_at'] = null;
        }

        DB::table(self::TABLE)->where('id', $row->id)->update($draftUpdate);

        // Keep the Saved versions row in sync when it is a mutable save (not Official).
        if ($revisionId && $revisionId > 0 && Schema::hasTable(self::REVISIONS_TABLE)) {
            $rev = DB::table(self::REVISIONS_TABLE)->where('id', $revisionId)->first(['id', 'source']);
            if ($rev && (string) ($rev->source ?? '') !== 'official') {
                $isForm = in_array($key, ['office_drf', 'office_dcn'], true);
                self::updateRevision(
                    $revisionId,
                    $isForm ? $layout : ['blocks' => []],
                    $isForm ? null : $columns,
                    $page,
                    $isForm ? 'layout' : 'columns'
                );
            }
        }

        self::flushCache();

        return [
            'ok' => true,
            'revision_id' => $revisionId && $revisionId > 0 ? $revisionId : null,
            'message' => 'Restored the previous live template (before the last Update live).',
        ];
    }

    /**
     * @return array{has_live: bool, live_at: ?string, draft_differs: bool, live_revision_id: ?int}
     */
    public static function liveStatus(string $key): array
    {
        self::warmCache();
        $row = self::$cache[$key] ?? null;
        if (! $row) {
            return ['has_live' => false, 'live_at' => null, 'draft_differs' => false, 'live_revision_id' => null];
        }

        $livePayload = self::livePayloadFromRow($row);
        $hasLive = $livePayload !== null;
        $liveAt = isset($row->live_at) && $row->live_at !== null && $row->live_at !== ''
            ? (string) $row->live_at
            : null;
        $liveRevisionId = null;
        if ($hasLive && isset($livePayload['revision_id']) && (int) $livePayload['revision_id'] > 0) {
            $liveRevisionId = (int) $livePayload['revision_id'];
        }

        $draftDiffers = false;
        if ($hasLive) {
            $draftSnap = self::draftSnapshotFromRow($row);
            // Ignore revision_id when comparing draft vs live content.
            $liveCompare = $livePayload;
            unset($liveCompare['revision_id']);
            $draftDiffers = self::canonicalJson($draftSnap) !== self::canonicalJson($liveCompare);
        }

        return [
            'has_live' => $hasLive,
            'live_at' => $liveAt,
            'draft_differs' => $draftDiffers,
            'live_revision_id' => $liveRevisionId,
        ];
    }

    private static function warmCache(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            if (Schema::hasTable(self::TABLE)) {
                foreach (DB::table(self::TABLE)->get() as $row) {
                    self::$cache[(string) $row->key] = self::normalizeRow($row);
                }
            }
        } catch (\Throwable $e) {
            // DB unavailable — leave empty cache.
        }
    }

    private static function cloneTpl(object $row): object
    {
        return clone $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function livePayloadFromRow(object $row): ?array
    {
        $live = $row->live_json ?? null;
        if (is_string($live)) {
            $live = json_decode($live, true);
        }
        if (! is_array($live) || $live === []) {
            return null;
        }

        return $live;
    }

    /**
     * @param  array<string, mixed>  $live
     */
    private static function tplFromLivePayload(string $key, array $live, object $draftFallback): object
    {
        $tpl = (object) [
            'key' => $key,
            'id' => $draftFallback->id ?? null,
            'label' => $live['label'] ?? ($draftFallback->label ?? ''),
            'form_code' => $live['form_code'] ?? null,
            'form_title' => $live['form_title'] ?? null,
            'republic' => $live['republic'] ?? null,
            'institution_name' => $live['institution_name'] ?? null,
            'institution_address' => $live['institution_address'] ?? null,
            'footer_left' => $live['footer_left'] ?? null,
            'footer_center' => $live['footer_center'] ?? null,
            'default_effectivity' => $live['default_effectivity'] ?? null,
            'default_rev' => $live['default_rev'] ?? null,
            'logo_path' => $live['logo_path'] ?? null,
            'layout_json' => $live['layout_json'] ?? null,
            'columns_json' => $live['columns_json'] ?? null,
            'page_json' => $live['page_json'] ?? null,
            'live_json' => $live,
            'live_at' => $draftFallback->live_at ?? null,
        ];

        return self::normalizeRow($tpl);
    }

    /**
     * @return array<string, mixed>
     */
    private static function draftSnapshotFromRow(object $row): array
    {
        return [
            'label' => $row->label ?? null,
            'form_code' => $row->form_code ?? null,
            'form_title' => $row->form_title ?? null,
            'republic' => $row->republic ?? null,
            'institution_name' => $row->institution_name ?? null,
            'institution_address' => $row->institution_address ?? null,
            'footer_left' => $row->footer_left ?? null,
            'footer_center' => $row->footer_center ?? null,
            'default_effectivity' => $row->default_effectivity ?? null,
            'default_rev' => $row->default_rev ?? null,
            'logo_path' => $row->logo_path ?? null,
            'layout_json' => is_array($row->layout_json ?? null) ? $row->layout_json : self::decodeJsonField($row->layout_json ?? null),
            'columns_json' => is_array($row->columns_json ?? null) ? $row->columns_json : self::decodeJsonField($row->columns_json ?? null),
            'page_json' => is_array($row->page_json ?? null) ? $row->page_json : self::decodeJsonField($row->page_json ?? null),
        ];
    }

    private static function decodeJsonField(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Fingerprint for Saved versions dedupe — ignores canvas block UUIDs.
     */
    private static function revisionSnapshotFingerprint(string $kind, mixed $layoutJson, mixed $columnsJson): string
    {
        $layout = is_array($layoutJson) ? $layoutJson : [];
        $blocks = is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        $normalizedBlocks = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            unset($block['id']);
            $normalizedBlocks[] = $block;
        }

        return md5(self::canonicalJson([
            'kind' => $kind === 'full' ? 'full' : $kind,
            'blocks' => $normalizedBlocks,
            'columns' => is_array($columnsJson) ? $columnsJson : null,
        ]));
    }
}

{{--
  DCS print layout renderer — absolute-positioned blocks from layout_json.
  Renders Header → Body → Footer. Expects: $tpl, $values, optional $preview
--}}
@php
    use App\Helpers\DcsPrintTemplateHelper;

    $tpl = $tpl ?? DcsPrintTemplateHelper::get('office_drf');
    $values = is_array($values ?? null) ? $values : [];
    $identity = DcsPrintTemplateHelper::identityVars($tpl);
    if (! empty($logoSrcOverride)) {
        $identity['logoSrc'] = (string) $logoSrcOverride;
    }
    $baseIdentityDefaults = [
        'form_code' => $identity['letterNumber'],
        'form_title' => $identity['formTitle'],
    ];
    $values = array_merge($baseIdentityDefaults, $values);
    $batchListRaw = (isset($batchItemsValues) && is_array($batchItemsValues) && count($batchItemsValues) > 1)
        ? array_values($batchItemsValues)
        : [];
    $isBatchRender = count($batchListRaw) > 1;
    $renderItems = $isBatchRender
        ? array_map(fn ($v) => array_merge($baseIdentityDefaults, is_array($v) ? $v : []), $batchListRaw)
        : [$values];
    $renderPairs = $isBatchRender ? array_chunk($renderItems, 2) : [[$values]];
    $page = DcsPrintTemplateHelper::pageSpec($tpl);
    if (! empty($pageOverride) && is_array($pageOverride)) {
        $page = array_merge($page, $pageOverride);
    }
    $blocks = (! empty($blocksOverride) && is_array($blocksOverride))
        ? array_values($blocksOverride)
        : DcsPrintTemplateHelper::layoutBlocks($tpl);
    if (! empty($identityOverride) && is_array($identityOverride)) {
        $identity = array_merge($identity, $identityOverride);
    }
    $widthIn = (float) ($page['width_in'] ?? 0) ?: 8.5;
    $heightIn = (float) ($page['height_in'] ?? 0) ?: 11.0;
    $isPreview = ! empty($preview);
    $tplKey = (string) ($tpl->key ?? '');
    $isDrfTheme = $tplKey === 'office_drf';
    $isDcnTheme = $tplKey === 'office_dcn';
    $isOfficialFormTheme = $isDrfTheme || $isDcnTheme;
    $sections = ['header' => [], 'body' => [], 'footer' => []];
    foreach ($blocks as $block) {
        $sec = DcsPrintTemplateHelper::normalizeSection($block['section'] ?? null, (float) ($block['y'] ?? 0));
        $sections[$sec][] = $block;
    }

    // Overflow distribute-to offices (> grid capacity) → attach continuation pages.
    // Grid (fits in rows) = office codes; attach sheet (overflow) = full office names.
    $gridOfficeRows = [];
    $attachOfficeRows = [];
    $distCapacity = 24;
    foreach ($blocks as $block) {
        if ((string) ($block['type'] ?? '') !== 'dist_grid') {
            continue;
        }
        $distCapacity = max(1, (int) ($block['grid_cols'] ?? 4)) * max(1, (int) ($block['grid_rows'] ?? 6));
        break;
    }
    $rawDistribute = is_array($distributeOffices ?? null) ? $distributeOffices : [];
    if ($rawDistribute !== []) {
        foreach ($rawDistribute as $office) {
            if (! is_array($office)) {
                continue;
            }
            $name = trim((string) ($office['name'] ?? ''));
            $code = trim((string) ($office['code'] ?? ''));
            $gridLabel = $code !== '' ? $code : $name;
            $attachLabel = $name !== '' ? $name : $code;
            if ($gridLabel !== '') {
                $gridOfficeRows[] = $gridLabel;
            }
            if ($attachLabel !== '') {
                $attachOfficeRows[] = $attachLabel;
            }
        }
    }
    if ($gridOfficeRows === []) {
        $gridOfficeRows = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*,\s*/', (string) ($values['distribute_to'] ?? '')) ?: []
        ), fn ($v) => $v !== ''));
    }
    if ($attachOfficeRows === []) {
        $attachOfficeRows = $gridOfficeRows;
    }
    $packedDist = \App\Helpers\OfficeIntakeHelper::packDistributeGridCells($gridOfficeRows, $distCapacity);
    $distributeOverflow = $packedDist['overflow'];
    $packedDistCells = $packedDist['cells'];
    /*
     * Attach list: 11pt @ line-height 1.0. Pack the first sheet full, then spill.
     * Keep PHP row math in sync with .sheet-attach CSS below.
     */
    $attachPadTopIn = 0.50;
    $attachPadBottomIn = 0.28;
    $attachMetaIn = 0.50; // Document Title + dist label + table thead
    $attachRowPt = 11.0;  // strict 1.0: 11pt text @ line-height 1
    $attachUsableIn = max(3.0, $heightIn - $attachPadTopIn - $attachPadBottomIn - $attachMetaIn);
    $attachPerPage = max(20, (int) floor($attachUsableIn / ($attachRowPt / 72)));
    $attachPages = $distributeOverflow ? array_chunk($attachOfficeRows, $attachPerPage) : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $previewTitle = trim((string) ($identity['formTitle'] ?? '')) !== ''
            ? (trim((string) $identity['formTitle']) . ' Preview')
            : 'Document Request Form Preview';
        $docTitle = $isPreview
            ? $previewTitle
            : (trim((string) ($identity['formTitle'] ?? 'Document')) . ' — ' . ($identity['letterNumber'] ?? ''));
    @endphp
    <title>{{ $docTitle }}</title>
    @if($isDrfTheme)
        @include('pages.dcs.partials.drf-form-styles')
    @endif
    @if($isDcnTheme)
        @include('pages.dcs.partials.dcn-form-styles')
    @endif
    <style>
        @page {
            size: {{ $isDrfTheme && $isBatchRender ? '8.5in 13in' : ($widthIn . 'in ' . $heightIn . 'in') }};
            margin: 0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: {{ !empty($embed) ? '#fff' : ($isPreview ? '#e2e8f0' : '#fff') }};
            color: #000;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .print-toolbar {
            display: flex;
            gap: 10px;
            justify-content: center;
            padding: 10px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .print-toolbar button {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .print-toolbar .btn-print { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        @if(!empty($embed))
        html, body {
            overflow: hidden !important;
            @if($isDrfTheme)
            height: 6.5in !important;
            @endif
        }
        @endif
        .sheet {
            position: relative;
            width: {{ $widthIn }}in;
            height: {{ ($isDrfTheme && ($isPreview || !empty($halfSheet))) ? '6.5in' : ($heightIn . 'in') }};
            margin: {{ $isPreview && empty($embed) ? '16px auto' : '0' }};
            background: #fff;
            overflow: hidden;
            box-shadow: {{ $isPreview && empty($embed) ? '0 4px 20px rgba(15,23,42,.12)' : 'none' }};
            {{ $isDrfTheme ? 'font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1;' : '' }}
        }
        .drf-longbond-pair {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 18px;
            margin: 18px auto;
        }
        .drf-batch-item-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .drf-batch-badge {
            width: {{ $widthIn }}in;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-bottom: none;
            border-radius: 10px 10px 0 0;
            font-size: 12px;
            color: #0f172a;
        }
        .drf-batch-badge-num {
            padding: 2px 9px;
            border-radius: 999px;
            background: #e8f0fe;
            color: #0d2a7a;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .drf-batch-badge-title {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .drf-batch-item-wrap .sheet {
            margin: 0 !important;
            border: 1px solid #cbd5e1;
            border-top: 1px solid #e2e8f0;
            border-radius: 0 0 10px 10px;
        }
        @media print {
            .print-toolbar,
            .drf-batch-badge { display: none !important; }
            html, body { background: #fff !important; overflow: visible !important; height: auto !important; margin: 0 !important; padding: 0 !important; }
            .drf-longbond-pair {
                display: flex !important;
                flex-direction: column !important;
                align-items: stretch !important;
                justify-content: flex-start !important;
                gap: 0 !important;
                width: 8.5in !important;
                height: 100vh !important;
                min-height: 100vh !important;
                max-height: 100vh !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                page-break-after: always !important;
                break-after: page !important;
            }
            .drf-longbond-pair:last-of-type {
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .drf-batch-item-wrap {
                display: block !important;
                flex: 0 0 50% !important;
                width: 8.5in !important;
                height: 50% !important;
                min-height: 50% !important;
                max-height: 50% !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                position: relative !important;
                box-sizing: border-box !important;
            }
            .drf-batch-item-wrap:first-child {
                border-bottom: 1px dashed #cbd5e1 !important;
            }
            .drf-batch-item-wrap .sheet,
            .sheet.drf-batch-half {
                position: relative !important;
                width: 8.5in !important;
                height: 100% !important;
                min-height: 100% !important;
                max-height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                overflow: hidden !important;
            }
            .sheet:not(.drf-batch-half) { height: {{ $heightIn }}in !important; margin: 0 !important; box-shadow: none !important; }
        }
        .block {
            position: absolute;
            overflow: hidden;
            line-height: 1.15;
        }
        .block-line-inner {
            width: 100%;
            height: 100%;
            min-height: 1px;
        }
        .block-logo img {
            display: block;
            width: 100%;
            height: 100%;
        }
        .block-field {
            display: flex;
            flex-direction: row;
            align-items: baseline;
            gap: 0.12in;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
        }
        .block-field .lbl {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .block-field .val {
            flex: 1 1 auto;
            min-width: 0.4in;
            min-height: 1em;
        }
        .block-signature {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
        }
        .block-signature .lbl {
            display: block;
            margin-bottom: 2px;
            flex: 0 0 auto;
        }
        .block-signature .val {
            flex: 1 1 auto;
            min-height: 1em;
        }
        .block-field.is-underline .val,
        .block-signature.is-underline .val {
            border-bottom: 1px solid currentColor;
            padding-bottom: 1px;
        }
        .block-text.is-underline {
            text-decoration: underline;
            text-underline-offset: 3px;
            text-decoration-thickness: 1px;
        }
        .block-table {
            border-collapse: collapse;
            border-spacing: 0;
            width: 100%;
            height: 100%;
            table-layout: fixed;
        }
        .block-table th,
        .block-table td {
            border: 1px solid #000;
            padding: 2px 4px;
            font-size: inherit;
        }
        .block-table.no-borders,
        .block-table.no-borders th,
        .block-table.no-borders td,
        .drf-sig.no-borders,
        .drf-sig.no-borders th,
        .drf-sig.no-borders td {
            border: none !important;
            border-color: transparent !important;
        }
        .block-checkboxes {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.18in 0.35in;
            width: 100%;
            height: 100%;
        }
        .block-checkboxes .lbl { flex: 0 0 auto; white-space: nowrap; }
        .block-checkboxes .opt {
            display: inline-flex;
            align-items: center;
            gap: 0.06in;
            white-space: nowrap;
        }
        .block-checkboxes .cb {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 0.16in;
            height: 0.16in;
            border: 1px solid #000;
            font-size: 8pt;
            line-height: 1;
        }
        .block-ruled {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100%;
            gap: 0.02in;
        }
        .block-ruled .lbl { flex: 0 0 auto; margin-bottom: 0.04in; }
        .block-ruled .ruled-line {
            flex: 1 1 auto;
            min-height: 0.18in;
            border-bottom: 1px solid #000;
            position: relative;
        }
        .block-ruled .ruled-line .txt {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 1px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
        }
        .block-dist {
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100%;
        }
        .block-dist .lbl { margin-bottom: 0.06in; }
        .block-dist .grid {
            flex: 1 1 auto;
            display: grid;
            gap: 0.08in 0.16in;
            min-height: 0;
        }
        .block-dist .dist-col {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .block-dist .cell {
            border-bottom: 1px solid #000;
            min-height: 0.2in;
            font-size: 9pt;
            overflow: hidden;
            white-space: nowrap;
        }
        .sheet-attach {
            position: relative;
            width: {{ $widthIn }}in;
            height: {{ $heightIn }}in;
            margin: {{ $isPreview && empty($embed) ? '16px auto' : '0' }};
            background: #fff;
            overflow: hidden;
            box-shadow: {{ $isPreview && empty($embed) ? '0 4px 20px rgba(15,23,42,.12)' : 'none' }};
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1;
            page-break-before: always;
            break-before: page;
        }
        .sheet-attach .body {
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            height: 100%;
            min-height: 100%;
            max-height: 100%;
            padding: {{ $attachPadTopIn }}in 0.5in {{ $attachPadBottomIn }}in;
        }
        .sheet-attach .attach-doc-title {
            display: flex;
            align-items: flex-end;
            gap: 4px;
            margin: 0 0 2pt;
            min-height: 11pt;
            line-height: 1;
            flex: 0 0 auto;
        }
        .sheet-attach .attach-doc-title .lbl { white-space: nowrap; font-weight: 400; line-height: 1; }
        .sheet-attach .attach-doc-title .fline {
            flex: 1 1 auto;
            min-width: 0;
            border-bottom: 1px solid #000;
            padding: 0 1px;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1;
        }
        .sheet-attach .attach-dist-label {
            margin: 1pt 0 2pt;
            font-size: 11pt;
            font-weight: 700;
            line-height: 1;
            color: #000;
            flex: 0 0 auto;
        }
        .sheet-attach .attach-table {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            table-layout: fixed;
            margin: 0;
            flex: 0 0 auto;
            font-size: 11pt;
            line-height: 1;
        }
        .sheet-attach .attach-table th,
        .sheet-attach .attach-table td {
            border: 0.5pt solid #000;
            padding: 0 3px !important;
            margin: 0 !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            height: {{ $attachRowPt }}pt !important;
            min-height: {{ $attachRowPt }}pt !important;
            max-height: {{ $attachRowPt }}pt !important;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sheet-attach .attach-table th {
            text-align: center;
            font-weight: 700;
            height: {{ $attachRowPt }}pt !important;
            min-height: {{ $attachRowPt }}pt !important;
            max-height: {{ $attachRowPt }}pt !important;
            line-height: 1 !important;
            padding: 0 3px !important;
        }
        .sheet-attach .attach-table col.c-no { width: 0.55in; }
        @media print {
            .sheet-attach {
                page-break-before: always !important;
                break-before: page !important;
                box-shadow: none !important;
                margin: 0 !important;
            }
        }
    </style>
</head>
<body>
@php
    $canPrint = ! empty($allowPrint);
    $lockMsg = trim((string) ($printLockMessage ?? ''));
    if ($lockMsg === '') {
        $lockMsg = 'Preview only — this is not a printable copy yet.';
    }
@endphp
@if(! $canPrint)
<style id="ofi-print-block-style">
    @media print {
        body * { display: none !important; }
        body::after {
            display: block !important;
            content: "{{ $lockMsg }}";
            font: 14pt Arial, sans-serif;
            color: #000;
            padding: 1in;
        }
    }
</style>
<script>
window.print = function () {};
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
        e.preventDefault();
        e.stopPropagation();
    }
}, true);
</script>
@endif
@if(empty($embed) && empty($hideToolbar))
<div class="print-toolbar">
    @if($canPrint)
        @if($isBatchRender)
            <span style="align-self:center;font-size:12px;color:#334155;font-weight:600;">Batch · {{ count($renderItems) }} DRFs (prints 2 per Long bond sheet — set Margins → None)</span>
        @endif
        <button type="button" class="btn-print" onclick="ofiPrintBatchClean()">{{ $isBatchRender ? ('Print All (' . count($renderItems) . ' DRFs)') : 'Print' }}</button>
        <button type="button" onclick="window.close()">Close</button>
    @else
        <span style="align-self:center;font-size:12px;color:#64748b;font-weight:600">{{ $isBatchRender ? ('Batch Preview · ' . count($renderItems) . ' DRFs — ' . $lockMsg) : $lockMsg }}</span>
        <button type="button" onclick="window.close()">Close</button>
    @endif
</div>
@endif

@php $globalDrfIdx = 0; @endphp
@foreach($renderPairs as $pair)
@if($isBatchRender)<div class="drf-longbond-pair">@endif
@foreach($pair as $itemValues)
@php
    $globalDrfIdx++;
    $values = $itemValues;
@endphp
@if($isBatchRender)
<div class="drf-batch-item-wrap">
    <div class="drf-batch-badge">
        <span class="drf-batch-badge-num">DRF #{{ $globalDrfIdx }} of {{ count($renderItems) }}</span>
        <span class="drf-batch-badge-title">{{ trim((string) ($values['doc_title'] ?? '')) !== '' ? trim((string) ($values['doc_title'] ?? '')) : 'Untitled Document Request Form' }}</span>
    </div>
@endif
<div class="sheet {{ $isDrfTheme ? 'drf-theme' : '' }} {{ $isDcnTheme ? 'dcn-theme' : '' }} {{ $isBatchRender ? 'drf-batch-half' : '' }}">
    @foreach(['header', 'body', 'footer'] as $sectionName)
        @foreach($sections[$sectionName] as $block)
            @php
                $type = (string) ($block['type'] ?? 'text');
                $x = (float) ($block['x'] ?? 0);
                $y = (float) ($block['y'] ?? 0);
                $w = (float) ($block['w'] ?? 1);
                $h = (float) ($block['h'] ?? 0.3);
                $label = (string) ($block['label'] ?? '');
                $bind = (string) ($block['bind'] ?? '');
                $style = DcsPrintTemplateHelper::normalizeBlockStyle(is_array($block['style'] ?? null) ? $block['style'] : []);
                // Always honor canvas Typography (Size / Color / Weight / Family).
                $fontFamily = trim((string) ($style['fontFamily'] ?? '')) !== '' ? (string) $style['fontFamily'] : 'Arial';
                $fontSize = (float) ($style['fontSizePt'] ?: ($isOfficialFormTheme ? 11 : 11));
                $fontWeight = $style['fontWeight'];
                $fontStyle = $style['fontStyle'];
                $align = $style['align'];
                $verticalAlign = $style['verticalAlign'] ?? 'top';
                $underline = $style['underline'];
                // Official DCN title is always underlined (paper form CSPC-F-DCC-01).
                if ($isDcnTheme && ($bind === 'form_title' || str_contains(mb_strtolower($label), 'document change notice'))) {
                    $underline = true;
                }
                $color = trim((string) ($style['color'] ?? '')) !== '' ? (string) $style['color'] : '#000';
                // Honor canvas Fill for all block types (including tables).
                $bg = trim((string) ($style['backgroundColor'] ?? ''));
                // Honor canvas Line color; fall back to official theme blues.
                $lineColor = trim((string) ($style['lineColor'] ?? ''));
                if ($lineColor === '') {
                    $lineColor = $isDrfTheme ? '#0070C0' : ($isDcnTheme ? '#0071BC' : '#0d2a7a');
                }
                $objectFit = $style['objectFit'];
                $bound = DcsPrintTemplateHelper::resolveBind($values, $bind);
                if ($bind === 'form_code' && $bound === '') {
                    $bound = $identity['letterNumber'];
                }
                if ($bind === 'form_title' && $bound === '') {
                    $bound = $identity['formTitle'];
                }
                if ($bind === 'republic' && $bound === '') {
                    $bound = $identity['republic'];
                }
                if ($bind === 'institution_name' && $bound === '') {
                    $bound = $identity['institutionName'];
                }
                if ($bind === 'institution_address' && $bound === '') {
                    $bound = $identity['institutionAddress'];
                }
                if ($bind === 'footer_effectivity_line') {
                    // Canvas label wins when the editor set an explicit line (keeps preview in sync).
                    if (trim($label) !== '') {
                        $bound = trim($label);
                    } else {
                        $left = trim((string) ($identity['footerLeft'] ?? 'Effectivity Date:'));
                        $eff = trim((string) ($identity['footerEffectivity'] ?? ''));
                        if ($eff === '') {
                            $eff = trim((string) ($values['default_effectivity'] ?? 'January 2018'));
                        }
                        $bound = trim($left . ' ' . $eff);
                    }
                }
                if ($bind === 'footer_rev_line') {
                    if (trim($label) !== '') {
                        $bound = trim($label);
                    } else {
                        $center = trim((string) ($identity['footerCenter'] ?? 'Rev.'));
                        $rev = trim((string) ($identity['footerRev'] ?? ''));
                        if ($rev === '') {
                            $rev = trim((string) ($values['default_rev'] ?? ($isDcnTheme ? '1' : '0')));
                        }
                        $bound = trim($center . ' ' . $rev);
                    }
                }
                // Text blocks: prefer canvas label when bind did not resolve (or for branding binds
                // where the editor label is the intentional override).
                $displayText = $type === 'text'
                    ? ($bound !== '' ? $bound : $label)
                    : $bound;
                if ($type === 'text' && trim($label) !== '' && in_array($bind, [
                    'republic', 'institution_name', 'institution_address', 'form_code', 'form_title',
                    'footer_rev_line', 'footer_effectivity_line',
                ], true)) {
                    $displayText = trim($label);
                }
                $extraClass = '';
                if ($isDrfTheme && $type === 'text') {
                    if ($bind === 'form_code' || str_contains(mb_strtolower($label), 'cspc-f-')) {
                        $extraClass = 'drf-form-code';
                    } elseif (str_contains(mb_strtoupper($label), 'DOCUMENT REQUEST')) {
                        $extraClass = 'drf-title';
                    } elseif ($fontWeight >= 700 && $sectionName === 'header') {
                        $extraClass = 'drf-hdr-text is-name';
                    } elseif ($sectionName === 'header') {
                        $extraClass = 'drf-hdr-text';
                    } elseif ($sectionName === 'footer') {
                        $extraClass = 'drf-footer';
                    }
                }
                if ($isDcnTheme && $type === 'text') {
                    if ($bind === 'form_code' || str_contains(mb_strtolower($label), 'cspc-f-')) {
                        $extraClass = 'dcn-form-code';
                    } elseif ($bind === 'form_title' || str_contains(mb_strtolower($label), 'document change notice')) {
                        $extraClass = 'dcn-title';
                    } elseif ($fontWeight >= 700 && $sectionName === 'header') {
                        $extraClass = 'dcn-hdr-text is-name';
                    } elseif ($sectionName === 'header') {
                        $extraClass = 'dcn-hdr-text';
                    } elseif ($sectionName === 'footer') {
                        $extraClass = 'dcn-footer';
                    }
                }
                $vAlignCss = '';
                if ($type === 'field') {
                    $vAlignCss = 'align-items:flex-end;';
                } elseif ($type !== 'logo' && $type !== 'line' && $type !== 'table') {
                    $vAlignCss = match ($verticalAlign) {
                        'middle' => 'display:flex; flex-direction:column; justify-content:center;',
                        'bottom' => 'display:flex; flex-direction:column; justify-content:flex-end;',
                        default => '',
                    };
                }
                $css = "left: {$x}in; top: {$y}in; width: {$w}in; height: {$h}in;"
                    . " font-family: {$fontFamily}, Helvetica, sans-serif !important;"
                    . " font-size: {$fontSize}pt !important; font-weight: {$fontWeight} !important; font-style: {$fontStyle} !important;"
                    . " text-align: {$align} !important; color: {$color} !important; vertical-align: {$verticalAlign};"
                    . $vAlignCss
                    . ($bg !== '' ? " background-color: {$bg} !important;" : '');
            @endphp
            <div class="block block-{{ $type }} {{ $underline ? 'is-underline' : '' }} {{ $extraClass }} section-{{ $sectionName }}"
                 data-section="{{ $sectionName }}"
                 data-y-in="{{ $y }}"
                 @if($type === 'field' && $bind === 'doc_title') data-doc-title-block="1" @endif
                 style="{{ $css }}">
                @if($type === 'logo')
                    @if($identity['logoSrc'] !== '')
                        <div style="width:100%;height:100%;overflow:hidden;line-height:0;">
                            <img src="{{ $identity['logoSrc'] }}" alt="">
                        </div>
                    @endif
                @elseif($type === 'line')
                    <div class="block-line-inner" style="background: {{ $lineColor !== '' ? $lineColor : '#0070C0' }};"></div>
                @elseif($type === 'spacer')
                    &nbsp;
                @elseif($type === 'field')
                    <div class="{{ $isDrfTheme ? 'drf-field' : ($isDcnTheme ? 'dcn-field' : 'block-field') }} {{ $underline || $isOfficialFormTheme ? 'is-underline' : '' }}">
                        @if($label !== '')
                            <span class="lbl">{{ $label }}</span>
                        @endif
                        @if($isOfficialFormTheme)
                            <span class="fline">
                                <span class="fline-val"@if($bind === 'doc_title') data-doc-title-fit="1" data-raw-title="{{ $displayText }}" data-base-pt="{{ $fontSize }}"@endif>{{ $displayText !== '' ? $displayText : "\u{00A0}" }}</span>
                                <span class="fline-rule"></span>
                            </span>
                        @else
                            <span class="val"@if($bind === 'doc_title') data-fit-one-line="1" data-base-pt="{{ $fontSize }}"@endif>{{ $displayText !== '' ? $displayText : "\u{00A0}" }}</span>
                        @endif
                    </div>
                @elseif($type === 'checkboxes')
                    @php
                        $options = is_array($block['options'] ?? null) ? $block['options'] : ['Internal', 'External'];
                        $selected = mb_strtolower(trim($displayText));
                    @endphp
                    <div class="{{ $isDrfTheme ? 'drf-checks' : 'block-checkboxes' }}">
                        @if($label !== '')
                            <span class="lbl">{{ $label }}</span>
                        @endif
                        @foreach($options as $oi => $opt)
                            @php
                                $optLabel = is_array($opt) ? (string) ($opt['label'] ?? $opt['value'] ?? '') : (string) $opt;
                                $checked = $selected !== '' && (mb_strtolower($optLabel) === $selected || str_contains($selected, mb_strtolower($optLabel)));
                            @endphp
                            <span class="opt {{ $oi === 0 ? 'is-first' : '' }}"><span class="cb">{{ $checked ? '✓' : '' }}</span> {{ $optLabel }}</span>
                        @endforeach
                    </div>
                @elseif($type === 'ruled_lines')
                    @php
                        $lineCount = max(1, min(12, (int) ($block['line_count'] ?? ($isOfficialFormTheme ? 4 : 5))));
                        $chunks = [];
                        if ($displayText !== '') {
                            $words = preg_split('/\s+/', $displayText) ?: [];
                            $line = '';
                            foreach ($words as $word) {
                                $next = $line === '' ? $word : ($line . ' ' . $word);
                                if (mb_strlen($next) > 95 && $line !== '') {
                                    $chunks[] = $line;
                                    $line = $word;
                                } else {
                                    $line = $next;
                                }
                            }
                            if ($line !== '') {
                                $chunks[] = $line;
                            }
                        }
                        while (count($chunks) < $lineCount) {
                            $chunks[] = '';
                        }
                        $chunks = array_slice($chunks, 0, $lineCount);
                    @endphp
                    <div class="{{ $isDrfTheme ? 'drf-ruled' : ($isDcnTheme ? 'dcn-ruled' : 'block-ruled') }}">
                        @if($label !== '')
                            <div class="lbl">{{ $label }}</div>
                        @endif
                        @foreach($chunks as $chunk)
                            <div class="ruled-line"><span class="txt">{{ $chunk }}</span></div>
                        @endforeach
                    </div>
                @elseif($type === 'dist_grid')
                    @php
                        $gCols = max(1, min(6, (int) ($block['grid_cols'] ?? 4)));
                        $gRows = max(1, min(10, (int) ($block['grid_rows'] ?? ($isDrfTheme ? 6 : 5))));
                        $itemDistList = isset($values['_distribute_offices']) && is_array($values['_distribute_offices'])
                            ? $values['_distribute_offices']
                            : null;
                        if ($itemDistList !== null) {
                            $rawCells = [];
                            foreach ($itemDistList as $oRow) {
                                if (! is_array($oRow)) continue;
                                $lbl = trim((string) ($oRow['code'] ?? '')) ?: trim((string) ($oRow['name'] ?? ''));
                                if ($lbl !== '') $rawCells[] = $lbl;
                            }
                        } else {
                            $rawCells = $gridOfficeRows !== []
                                ? $gridOfficeRows
                                : array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $displayText) ?: [])));
                        }
                        $total = $gCols * $gRows;
                        $packedBlock = \App\Helpers\OfficeIntakeHelper::packDistributeGridCells($rawCells, $total);
                        $cells = $packedBlock['cells'];
                        if ($packedBlock['overflow']) {
                            // Column-major indexes: [1]=2nd row col1, [2]=3rd row col1 (official DRF).
                            $overflowCells = array_fill(0, $total, '');
                            if ($gRows >= 2) {
                                $overflowCells[1] = 'Please see attached';
                            }
                            if ($gRows >= 3) {
                                $overflowCells[2] = 'distribution list';
                            }
                            $cells = $overflowCells;
                        }
                    @endphp
                    <div class="{{ $isDrfTheme ? 'drf-dist' : 'block-dist' }}">
                        @if($label !== '')
                            <div class="lbl">{{ $label }}</div>
                        @endif
                        {{-- Column-major fill (same as official drf-print) so row 2/3 are indexes 1/2. --}}
                        <div class="grid" style="grid-template-columns: repeat({{ $gCols }}, minmax(0, 1fr));">
                            @for($col = 0; $col < $gCols; $col++)
                                <div class="dist-col">
                                    @for($row = 0; $row < $gRows; $row++)
                                        <div class="cell">{{ $cells[$col * $gRows + $row] ?? '' }}</div>
                                    @endfor
                                </div>
                            @endfor
                        </div>
                    </div>
                @elseif($type === 'signature')
                    @if($label !== '')
                        <span class="lbl">{{ $label }}</span>
                    @endif
                    <div class="val">{{ $displayText !== '' ? $displayText : "\u{00A0}" }}</div>
                @elseif($type === 'table')
                    @php
                        $tableStyle = DcsPrintTemplateHelper::columnsPayload($tpl)['style'];
                        $hdrBg = $isOfficialFormTheme ? 'transparent' : ($bg !== '' ? $bg : ($tableStyle['header_row_bg'] ?? '#8db3e2'));
                        $hdrFg = $color !== '' ? $color : ($isOfficialFormTheme ? '#000' : ($tableStyle['header_row_color'] ?? '#000'));
                        $cols = max(1, min(12, (int) ($block['table_cols'] ?? 4)));
                        $rows = max(1, min(12, (int) ($block['table_rows'] ?? 4)));
                        $showBorders = DcsPrintTemplateHelper::tableBordersVisible($block);
                        $borderCls = $showBorders ? '' : 'no-borders';
                        $freeCells = array_key_exists('table_cells', $block)
                            ? DcsPrintTemplateHelper::padTableCells($block['table_cells'], $rows, $cols)
                            : null;
                        $headers = is_array($block['table_headers'] ?? null) ? $block['table_headers'] : [];
                        $rowLabels = is_array($block['table_row_labels'] ?? null) ? $block['table_row_labels'] : [];
                        $cellBinds = is_array($block['table_cell_binds'] ?? null) ? $block['table_cell_binds'] : [];
                        $useFreeTable = is_array($freeCells);
                        if (! $useFreeTable) {
                            if ($headers === []) {
                                $headers = match ($cols) {
                                    4 => ['', 'Prepared by:', 'Reviewed by:', 'Approved by:'],
                                    3 => ['', 'Prepared by:', 'Approved by:'],
                                    default => array_pad([''], $cols, ''),
                                };
                            }
                            if ($rowLabels === []) {
                                $defaults = ['Signature', 'Name', 'Designation', 'Date'];
                                $rowLabels = array_slice(array_pad($defaults, $rows, ''), 0, $rows);
                            }
                            while (count($headers) < $cols) {
                                $headers[] = '';
                            }
                            while (count($rowLabels) < $rows) {
                                $rowLabels[] = '';
                            }
                        }
                    @endphp
                    @if($useFreeTable)
                        <table class="{{ $isDrfTheme ? 'drf-sig' : 'block-table' }} {{ $borderCls }}">
                            <tbody>
                                @for($r = 0; $r < $rows; $r++)
                                    <tr>
                                        @for($c = 0; $c < $cols; $c++)
                                            @php
                                                $text = (string) ($freeCells[$r][$c] ?? '');
                                                $bindKey = (string) ($cellBinds[(string) $r][(string) $c] ?? $cellBinds[$r][$c] ?? '');
                                                if ($text === '' && $bindKey !== '') {
                                                    $text = DcsPrintTemplateHelper::resolveBind($values, $bindKey);
                                                }
                                                $cellBorder = $showBorders ? '1px solid #000' : 'none';
                                                $fitFreeCell = $isDrfTheme && $text !== '' && (str_ends_with($bindKey, '_name') || str_ends_with($bindKey, '_designation'));
                                            @endphp
                                            <td style="border: {{ $cellBorder }}; text-align: {{ $align }}; color: {{ $color }};">@if($fitFreeCell)<span data-fit-one-line="1" data-fit-align="{{ $align }}" data-base-pt="{{ $fontSize }}">{{ $text }}</span>@else{{ $text !== '' ? $text : "\u{00A0}" }}@endif</td>
                                        @endfor
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    @else
                    <table class="{{ $isDrfTheme ? 'drf-sig' : 'block-table' }} {{ $borderCls }}">
                        @if($isDrfTheme && $cols === 4)
                            <colgroup>
                                <col style="width:1.01in">
                                <col style="width:1.87in">
                                <col style="width:2.50in">
                                <col style="width:2.19in">
                            </colgroup>
                        @endif
                        <thead>
                            <tr>
                                @for($c = 0; $c < $cols; $c++)
                                    <th style="background: {{ $hdrBg }}; color: {{ $hdrFg }}; text-align: center; {{ $showBorders ? '' : 'border:none;' }}">{{ $headers[$c] ?? '' }}</th>
                                @endfor
                            </tr>
                        </thead>
                        <tbody>
                            @for($r = 0; $r < $rows; $r++)
                                <tr>
                                    @for($c = 0; $c < $cols; $c++)
                                        @if($c === 0)
                                            <td class="row-label" style="{{ $showBorders ? '' : 'border:none;' }}">{{ $rowLabels[$r] ?? '' }}</td>
                                        @else
                                            @php
                                                $bindKey = (string) ($cellBinds[(string) $r][(string) $c] ?? $cellBinds[$r][$c] ?? '');
                                                $cellVal = $bindKey !== '' ? DcsPrintTemplateHelper::resolveBind($values, $bindKey) : '';
                                                $rowLblLower = mb_strtolower(trim((string) ($rowLabels[$r] ?? '')));
                                                $isNameRow = $rowLblLower === 'name';
                                                $isDesigRow = $rowLblLower === 'designation';
                                                $shouldFitSigCell = $isDrfTheme && $cellVal !== '' && ($isNameRow || $isDesigRow || str_ends_with($bindKey, '_name') || str_ends_with($bindKey, '_designation'));
                                            @endphp
                                            <td class="{{ $isNameRow && $cellVal !== '' ? 'name-bold' : '' }}" style="text-align:center; {{ $showBorders ? '' : 'border:none;' }}">@if($shouldFitSigCell)<span data-fit-one-line="1" data-fit-align="center" data-base-pt="{{ $fontSize }}">{{ $cellVal }}</span>@else{{ $cellVal !== '' ? $cellVal : "\u{00A0}" }}@endif</td>
                                        @endif
                                    @endfor
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                    @endif
                @else
                    @if($isDcnTheme && $extraClass === 'dcn-title')
                        <span class="dcn-title-text">{{ $displayText !== '' ? $displayText : 'Document Change Notice' }}</span>
                    @else
                        {{ $displayText }}
                    @endif
                @endif
            </div>
        @endforeach
    @endforeach
</div>
@if($isBatchRender)</div>@endif
@endforeach
@if($isBatchRender)</div>@endif
@endforeach

@foreach($renderItems as $attachItemValues)
@php
    $itemAttachRows = [];
    if (isset($attachItemValues['_distribute_offices']) && is_array($attachItemValues['_distribute_offices'])) {
        $itemGridRows = [];
        foreach ($attachItemValues['_distribute_offices'] as $oRow) {
            if (! is_array($oRow)) continue;
            $gLbl = trim((string) ($oRow['code'] ?? '')) ?: trim((string) ($oRow['name'] ?? ''));
            $aLbl = trim((string) ($oRow['name'] ?? '')) ?: trim((string) ($oRow['code'] ?? ''));
            if ($gLbl !== '') $itemGridRows[] = $gLbl;
            if ($aLbl !== '') $itemAttachRows[] = $aLbl;
        }
        $itemPacked = \App\Helpers\OfficeIntakeHelper::packDistributeGridCells($itemGridRows, $distCapacity);
        $itemAttachPages = $itemPacked['overflow'] ? array_chunk($itemAttachRows, $attachPerPage) : [];
    } else {
        $itemAttachPages = $distributeOverflow ? $attachPages : [];
    }
@endphp
@foreach($itemAttachPages as $pageIndex => $pageOffices)
@php
    $rowOffset = $pageIndex * $attachPerPage;
    $attachDocTitle = trim((string) ($attachItemValues['doc_title'] ?? ''));
@endphp
{{-- Attach list only — no DRF footer / page count (form stays Page: 1 of 1). --}}
<div class="sheet sheet-attach {{ $isDrfTheme ? 'drf-theme' : '' }}">
    <div class="body">
        <div class="attach-doc-title">
            <span class="lbl">Document Title :</span>
            <div class="fline" data-fit-one-line="1" data-base-pt="11">{{ $attachDocTitle }}</div>
        </div>
        <div class="attach-dist-label">Distribute document to (department/position):</div>
        <table class="attach-table">
            <colgroup>
                <col class="c-no">
                <col class="c-name">
            </colgroup>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Name of Offices</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pageOffices as $index => $officeLabel)
                    <tr>
                        <td style="text-align: center;">{{ $rowOffset + $index + 1 }}</td>
                        <td>{{ $officeLabel }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach
@endforeach
<script>
(function () {
    function measureTextWidth(el) {
        try {
            var range = document.createRange();
            range.selectNodeContents(el);
            return range.getBoundingClientRect().width;
        } catch (e) {
            return el.scrollWidth;
        }
    }
    function ofiFitOneLineFields() {
        var minPt = 4.5;
        document.querySelectorAll('[data-fit-one-line="1"]').forEach(function (el) {
            var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text) return;
            var basePt = parseFloat(el.getAttribute('data-base-pt') || '11') || 11;
            var box = el.parentElement || el;
            var rectW = box.getBoundingClientRect().width || el.getBoundingClientRect().width || 0;
            if (rectW < 24) return;
            var padX = 4;
            try {
                var cs = window.getComputedStyle(box);
                padX = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0) + 4;
            } catch (e) {}
            var avail = Math.max(24, rectW - padX);
            var fitAlign = (el.getAttribute('data-fit-align') || '').toLowerCase();

            el.style.removeProperty('transform');
            el.style.removeProperty('transform-origin');
            el.style.removeProperty('display');
            el.style.setProperty('white-space', 'nowrap', 'important');
            el.style.setProperty('overflow', 'hidden', 'important');
            el.style.setProperty('font-size', basePt + 'pt', 'important');

            if (measureTextWidth(el) <= avail) return;

            var lo = minPt;
            var hi = basePt;
            var best = minPt;
            for (var i = 0; i < 24; i++) {
                var mid = (lo + hi) / 2;
                el.style.setProperty('font-size', mid + 'pt', 'important');
                if (measureTextWidth(el) <= avail) {
                    best = mid;
                    lo = mid;
                } else {
                    hi = mid;
                }
            }
            el.style.setProperty('font-size', (Math.floor(best * 100) / 100) + 'pt', 'important');
            var finalW = measureTextWidth(el);
            if (finalW > avail) {
                var scale = Math.max(0.5, avail / finalW);
                el.style.setProperty('display', 'inline-block', 'important');
                el.style.setProperty('overflow', 'visible', 'important');
                el.style.setProperty('transform-origin', fitAlign === 'center' ? 'center center' : 'left bottom', 'important');
                el.style.setProperty('transform', 'scaleX(' + scale.toFixed(4) + ')', 'important');
            }
        });
    }
    function ofiFitDocTitleTwoLine() {
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        if (!ctx) return;
        var minOneLinePt = 8.0;
        var minTwoLinePt = 6.5;

        function packTwo(words, pt, limit) {
            ctx.font = (pt * 96 / 72) + 'px Arial, Helvetica, sans-serif';
            var l1 = '';
            var i = 0;
            for (; i < words.length; i++) {
                var next = l1 === '' ? words[i] : (l1 + ' ' + words[i]);
                if (l1 !== '' && ctx.measureText(next).width > limit) break;
                l1 = next;
            }
            if (l1 === '' && words.length) {
                l1 = words[0];
                i = 1;
            }
            var l2 = words.slice(i).join(' ');
            var w1 = l1 ? ctx.measureText(l1).width : 0;
            var w2 = l2 ? ctx.measureText(l2).width : 0;
            return { ok: w1 <= limit && w2 <= limit, l1: l1, l2: l2 };
        }

        document.querySelectorAll('[data-doc-title-block="1"]').forEach(function (block) {
            var sheet = block.closest('.sheet');
            var valEl = block.querySelector('[data-doc-title-fit="1"]');
            var field = block.querySelector('.drf-field, .dcn-field');
            if (!valEl || !field) return;

            var title = (valEl.getAttribute('data-raw-title') || valEl.textContent || '').replace(/\s+/g, ' ').trim();
            var basePt = parseFloat(valEl.getAttribute('data-base-pt') || '11') || 11;
            var fline = valEl.closest('.fline');
            if (!fline) return;

            // Reset any prior 2-line expansion first
            var prevWrap = field.querySelector('.doc-title-stack');
            if (prevWrap) {
                prevWrap.replaceWith(fline);
            }
            if (!block.dataset.origHeight) block.dataset.origHeight = block.style.height;
            block.style.height = block.dataset.origHeight;
            field.style.alignItems = '';
            if (sheet) {
                sheet.querySelectorAll('.block[data-shifted-by]').forEach(function (b) {
                    if (b.dataset.origTop) b.style.top = b.dataset.origTop;
                    delete b.dataset.shiftedBy;
                });
            }

            if (!title) return;
            var avail = Math.max(40, fline.getBoundingClientRect().width - 6);

            function measureOne(pt) {
                ctx.font = (pt * 96 / 72) + 'px Arial, Helvetica, sans-serif';
                return ctx.measureText(title).width;
            }

            // 1) Try single line from basePt (11pt) down to 8pt.
            if (measureOne(basePt) <= avail) {
                valEl.textContent = title;
                valEl.style.setProperty('font-size', basePt + 'pt', 'important');
                return;
            }
            if (measureOne(minOneLinePt) <= avail) {
                var lo = minOneLinePt, hi = basePt, best = minOneLinePt;
                for (var s = 0; s < 20; s++) {
                    var mid = (lo + hi) / 2;
                    if (measureOne(mid) <= avail) { best = mid; lo = mid; } else { hi = mid; }
                }
                valEl.textContent = title;
                valEl.style.setProperty('font-size', (Math.floor(best * 100) / 100) + 'pt', 'important');
                return;
            }

            // 2) Too long for 1 line at 8pt → wrap cleanly onto 2 underlined lines (9.5pt down to 6.5pt).
            var words = title.split(' ');
            var twoHi = Math.min(9.5, basePt);
            var twoLo = minTwoLinePt;
            var bestPt = minTwoLinePt;
            var bestPack = packTwo(words, minTwoLinePt, avail);
            for (var t = 0; t < 20; t++) {
                var tMid = (twoLo + twoHi) / 2;
                var p = packTwo(words, tMid, avail);
                if (p.ok) {
                    bestPt = tMid;
                    bestPack = p;
                    twoLo = tMid;
                } else {
                    twoHi = tMid;
                }
            }
            var finalPt = Math.floor(bestPt * 100) / 100;

            var stack = document.createElement('span');
            stack.className = 'doc-title-stack';
            stack.style.cssText = 'flex:1 1 auto;min-width:0;display:flex;flex-direction:column;gap:2pt;';

            valEl.textContent = bestPack.l1;
            valEl.style.setProperty('font-size', finalPt + 'pt', 'important');
            fline.style.setProperty('height', '11pt', 'important');

            var fline2 = document.createElement('span');
            fline2.className = 'fline';
            fline2.style.setProperty('height', '11pt', 'important');
            var val2 = document.createElement('span');
            val2.className = 'fline-val';
            val2.textContent = bestPack.l2 || '\u00A0';
            val2.style.setProperty('font-size', finalPt + 'pt', 'important');
            var rule2 = document.createElement('span');
            rule2.className = 'fline-rule';
            fline2.appendChild(val2);
            fline2.appendChild(rule2);

            fline.parentNode.insertBefore(stack, fline);
            stack.appendChild(fline);
            stack.appendChild(fline2);

            field.style.setProperty('align-items', 'flex-start', 'important');
            var extraIn = 0.17;
            var origH = parseFloat(block.dataset.origHeight) || 0.19;
            block.style.height = (origH + extraIn).toFixed(3) + 'in';

            // Shift blocks directly below Document Title (Type of document, Description, Distribute) down cleanly.
            var titleY = parseFloat(block.getAttribute('data-y-in') || '0') || 0;
            if (sheet && titleY > 0) {
                sheet.querySelectorAll('.block.section-body').forEach(function (other) {
                    if (other === block) return;
                    if (other.classList.contains('block-table')) return; // keep signature table fixed
                    var oy = parseFloat(other.getAttribute('data-y-in') || '0') || 0;
                    if (oy > titleY) {
                        if (!other.dataset.origTop) other.dataset.origTop = other.style.top;
                        other.style.top = (oy + extraIn).toFixed(3) + 'in';
                        other.dataset.shiftedBy = String(extraIn);
                    }
                });
            }
        });
    }
    function ofiFitAll() {
        ofiFitOneLineFields();
        ofiFitDocTitleTwoLine();
    }
    document.addEventListener('DOMContentLoaded', ofiFitAll);
    window.addEventListener('load', ofiFitAll);
    window.addEventListener('resize', ofiFitAll);
    window.addEventListener('beforeprint', ofiFitAll);
    ofiFitAll();

    var markPrintedUrl = @json($markPrintedUrl ?? '');
    var printedMarked = false;
    function markPrinted() {
        if (!markPrintedUrl || printedMarked) return;
        printedMarked = true;
        try {
            fetch(markPrintedUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                keepalive: true
            }).catch(function () { printedMarked = false; });
        } catch (e) {
            printedMarked = false;
        }
    }
    window.ofiPrintBatchClean = function () {
        ofiFitAll();
        markPrinted();
        window.print();
    };
})();
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DRF {{ $drf->drf_no }} — CSPC-F-DCC-06</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            color: #000;
            background: #e2e8f0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-toolbar {
            display: flex;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 24px;
            justify-content: center;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .print-toolbar button {
            padding: 8px 20px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .print-toolbar .btn-print { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            background: #fff;
            padding: 6mm 14mm 10mm;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
            font-family: Arial, sans-serif;
            font-size: 11pt;
        }
        .hdr-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 4px;
        }
        .hdr-table, .hdr-table tr, .hdr-table td { border: none !important; background: none !important; }
        .hdr-table td { padding: 0; vertical-align: middle; }
        .hdr-logo {
            height: 68pt;
            width: auto;
            object-fit: contain;
            display: block;
        }
        .hdr-republic,
        .hdr-location,
        .hdr-name {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.25;
        }
        .hdr-name {
            font-weight: 700;
            text-transform: uppercase;
            margin: 1px 0;
        }
        .hdr-line {
            position: relative;
            border-top: 2px solid #0071BC;
            height: 0;
            margin: 4px 0 12px;
        }
        .hdr-line span {
            position: absolute;
            right: 0;
            top: -0.65em;
            background: #fff;
            padding-left: 8px;
            font-family: Arial, sans-serif;
            font-size: 11pt;
            font-weight: 700;
            white-space: nowrap;
        }
        .form-title {
            font-family: Arial, sans-serif;
            text-align: center;
            font-size: 12pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 14px;
        }
        .field-row {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-bottom: 8px;
            line-height: 1.35;
        }
        .field-row.split {
            justify-content: space-between;
            gap: 16px;
        }
        .field-group {
            display: flex;
            align-items: baseline;
            gap: 6px;
            flex: 1;
            min-width: 0;
        }
        .field-group.shrink { flex: 0 0 auto; }
        .lbl { white-space: nowrap; }
        .uline {
            border-bottom: 1px solid #000;
            min-height: 1.2em;
            flex: 1;
            padding: 0 2px 1px;
            word-break: break-word;
        }
        .uline.short { min-width: 120px; max-width: 180px; }
        .check-row {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 8px;
        }
        .check-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .cb {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1.5px solid #000;
            text-align: center;
            line-height: 10px;
            font-size: 10px;
            font-weight: 700;
            vertical-align: middle;
        }
        .desc-block {
            margin-bottom: 10px;
        }
        .desc-block .lbl {
            display: block;
            margin-bottom: 4px;
        }
        .desc-lines {
            height: calc(4 * 1.35em);
            line-height: 1.35em;
            padding: 0 2px 1px;
            white-space: pre-wrap;
            word-break: break-word;
            overflow: hidden;
            background-image: repeating-linear-gradient(
                to bottom,
                transparent 0,
                transparent calc(1.35em - 1px),
                #000 calc(1.35em - 1px),
                #000 1.35em
            );
            background-size: 100% 1.35em;
        }
        .dist-label {
            margin: 10px 0 6px;
        }
        .dist-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0 14px;
            margin-bottom: 12px;
        }
        .dist-col {
            display: flex;
            flex-direction: column;
            gap: 0;
        }
        .dist-line {
            border-bottom: 1px solid #000;
            min-height: 1.35em;
            padding: 1px 2px 2px;
            word-break: break-word;
        }
        .sig-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .sig-table th,
        .sig-table td {
            border: 1px solid #000;
            padding: 5px 6px;
            vertical-align: middle;
            text-align: center;
        }
        .sig-table th {
            font-weight: 700;
            background: #fff;
        }
        .sig-table .row-label {
            font-weight: 700;
            text-align: left;
            width: 14%;
        }
        .sig-table .sig-cell {
            height: 28px;
        }
        .sig-table .name-bold {
            font-weight: 700;
            text-transform: uppercase;
        }
        .footer-rule {
            border-top: 2px solid #0071BC;
            margin: 10px 0 6px;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .footer strong {
            font-size: 10pt;
            font-weight: 700;
        }
        @media print {
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet {
                box-shadow: none;
                margin: 0;
                width: auto;
                min-height: auto;
                padding: 0;
            }
            @page { size: A4 portrait; margin: 6mm 12mm 8mm 12mm; }
        }
    </style>
</head>
<body>
@php
    use App\Helpers\OfficeIntakeHelper;

    $originator = trim((string) ($drf->originator_name ?? ''))
        ?: trim((string) ($drf->prepared_by_name ?? ''));
    $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
    $isInternal = $kind === 'internal';
    $isExternal = $kind === 'external';
    $description = trim((string) ($drf->description_reason ?? ''));
    $distribute = OfficeIntakeHelper::decodeDistributeTo($drf->distribute_to ?? null);
    $distribute = array_pad(array_slice($distribute, 0, 24), 24, '');
    $preparedName = trim((string) ($drf->prepared_by_name ?? ''));
    $drfDate = $drf->drf_date
        ? \Carbon\Carbon::parse($drf->drf_date)->format('F d, Y')
        : '';
@endphp
<div class="print-toolbar">
    <button type="button" class="btn-print" onclick="window.print()">Print</button>
    <button type="button" class="btn-close" onclick="window.close()">Close</button>
</div>
<div class="sheet">
    <table class="hdr-table">
        <tr>
            <td style="width:1%; white-space:nowrap; padding-right:10px;">
                @if(!empty($logoSrc))
                    <img src="{{ $logoSrc }}" alt="" class="hdr-logo">
                @endif
            </td>
            <td>
                <div class="hdr-republic">Republic of the Philippines</div>
                <div class="hdr-name">Camarines Sur Polytechnic Colleges</div>
                <div class="hdr-location">Nabua, Camarines Sur</div>
            </td>
        </tr>
    </table>
    <div class="hdr-line"><span>CSPC-F-DCC-06</span></div>

    <div class="form-title">Document Request Form</div>

    <div class="field-row split">
        <div class="field-group">
            <span class="lbl">Request # :</span>
            <span class="uline">{{ $drf->drf_no }}</span>
        </div>
        <div class="field-group shrink">
            <span class="lbl">Date:</span>
            <span class="uline short">{{ $drfDate }}</span>
        </div>
    </div>

    <div class="field-row">
        <span class="lbl">Originator :</span>
        <span class="uline">{{ $originator }}</span>
    </div>

    <div class="field-row">
        <span class="lbl">Document Title :</span>
        <span class="uline">{{ $drf->doc_title }}</span>
    </div>

    <div class="check-row">
        <span class="lbl">Type of document:</span>
        <span class="check-item">
            <span class="cb">@if($isInternal)✓@endif</span> Internal
        </span>
        <span class="check-item">
            <span class="cb">@if($isExternal)✓@endif</span> External
        </span>
    </div>

    <div class="desc-block">
        <span class="lbl">Description/reason for request (define in detail):</span>
        <div class="desc-lines">{{ $description }}</div>
    </div>

    <div class="dist-label">Distribute document to (department/position):</div>
    <div class="dist-grid">
        @for($col = 0; $col < 4; $col++)
            <div class="dist-col">
                @for($row = 0; $row < 6; $row++)
                    <div class="dist-line">{{ $distribute[$col * 6 + $row] ?? '' }}</div>
                @endfor
            </div>
        @endfor
    </div>

    <table class="sig-table">
        <thead>
            <tr>
                <th class="row-label"></th>
                <th>Prepared by:</th>
                <th>Reviewed by:</th>
                <th>Approved by:</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="row-label">Signature</td>
                <td class="sig-cell"></td>
                <td class="sig-cell"></td>
                <td class="sig-cell"></td>
            </tr>
            <tr>
                <td class="row-label">Name</td>
                <td>{{ $preparedName }}</td>
                <td class="name-bold">NANCY S. PENETRANTE, MBA</td>
                <td class="name-bold">JOCELYN O. JINTALAN, DBA</td>
            </tr>
            <tr>
                <td class="row-label">Designation</td>
                <td></td>
                <td>ISO Vice Chairperson</td>
                <td>ISO Chairperson</td>
            </tr>
            <tr>
                <td class="row-label">Date</td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="footer-rule"></div>
    <div class="footer">
        <span>Effectivity Date: January 2018</span>
        <span>Rev. <strong>0</strong></span>
        <span>Page: <strong>1</strong> of <strong>1</strong></span>
    </div>
</div>
</body>
</html>

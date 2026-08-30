<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DCN {{ $dcn->dcn_no }} — CSPC-F-DCC-01</title>
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
            margin-bottom: 10px;
        }
        .dcn-no-row {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .dcn-no-row .uline {
            flex: 1;
            max-width: 220px;
            border-bottom: 1px solid #000;
            min-height: 1.2em;
            padding: 0 2px 1px;
            font-weight: 400;
        }
        .form-box {
            border: 1.5px solid #000;
        }
        .box-section {
            border-bottom: 1px solid #000;
            padding: 8px 10px;
        }
        .box-section:last-child {
            border-bottom: none;
        }
        .doc-id-row {
            margin-bottom: 6px;
            display: flex;
            align-items: baseline;
            gap: 6px;
        }
        .doc-id-row.title-row {
            padding-left: 72px;
        }
        .lbl { white-space: nowrap; }
        .uline {
            border-bottom: 1px solid #000;
            min-height: 1.2em;
            flex: 1;
            padding: 0 2px 1px;
            word-break: break-word;
        }
        .section-label {
            font-weight: 700;
            margin-bottom: 6px;
        }
        .change-block {
            margin-top: 4px;
        }
        .change-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 8px;
        }
        .change-row:last-child { margin-bottom: 0; }
        .change-row .lbl {
            font-weight: 700;
            min-width: 36px;
            padding-top: 2px;
        }
        .ruled-area {
            flex: 1;
            min-height: calc(4 * 1.35em);
            line-height: 1.35em;
            white-space: pre-wrap;
            word-break: break-word;
            background-image: repeating-linear-gradient(
                to bottom,
                transparent 0,
                transparent calc(1.35em - 1px),
                #000 calc(1.35em - 1px),
                #000 1.35em
            );
            background-size: 100% 1.35em;
        }
        .ruled-area.is-short {
            min-height: calc(3 * 1.35em);
        }
        .sig-row {
            display: flex;
            align-items: baseline;
            gap: 6px;
            margin-bottom: 8px;
        }
        .sig-row:last-child { margin-bottom: 0; }
        .sig-row .uline { flex: 1; }
        .approvals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11pt;
        }
        .approvals-table th,
        .approvals-table td {
            border: 1px solid #000;
            padding: 6px 8px;
            vertical-align: middle;
            text-align: center;
            height: 32px;
        }
        .approvals-table th {
            font-weight: 700;
            background: #fff;
        }
        .approvals-table .approvals-label {
            font-style: italic;
            text-align: left;
            width: 18%;
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
    $docNo = trim((string) ($dcn->document_no ?? ''));
    $docTitle = trim((string) ($dcn->document_title ?? ''));
    if ($docNo === '' || $docTitle === '') {
        $firstRev = ($revisions ?? collect())->first();
        if ($firstRev) {
            $docNo = $docNo ?: trim((string) ($firstRev->document_no ?? ''));
            $docTitle = $docTitle ?: trim((string) ($firstRev->title ?? ''));
        }
    }
    $changeFrom = trim((string) ($dcn->change_from ?? ''));
    $changeTo = trim((string) ($dcn->change_to ?? ''));
    $justification = trim((string) ($dcn->brief_purpose ?? ''));
    $originator = trim((string) ($dcn->originator_name ?? ''));
    $departmentDate = trim((string) ($dcn->department_date ?? ''));
    $reviewedByDate = trim((string) ($dcn->reviewed_by_date ?? ''));
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
    <div class="hdr-line"><span>CSPC-F-DCC-01</span></div>

    <div class="form-title">Document Change Notice</div>

    <div class="dcn-no-row">
        <span>DCN #</span>
        <span class="uline">{{ $dcn->dcn_no }}</span>
    </div>

    <div class="form-box">
        <div class="box-section">
            <div class="doc-id-row">
                <span class="lbl">Document no:</span>
                <span class="uline">{{ $docNo }}</span>
            </div>
            <div class="doc-id-row title-row">
                <span class="lbl">Title:</span>
                <span class="uline">{{ $docTitle }}</span>
            </div>
        </div>

        <div class="box-section">
            <div class="section-label">Detailed Description of Change:</div>
            <div class="change-block">
                <div class="change-row">
                    <span class="lbl">From:</span>
                    <div class="ruled-area">{{ $changeFrom }}</div>
                </div>
                <div class="change-row">
                    <span class="lbl">To:</span>
                    <div class="ruled-area">{{ $changeTo }}</div>
                </div>
            </div>
        </div>

        <div class="box-section">
            <div class="section-label">Justification of Change:</div>
            <div class="ruled-area is-short">{{ $justification }}</div>
        </div>

        <div class="box-section">
            <div class="sig-row">
                <span class="lbl">Originator/ Signature:</span>
                <span class="uline">{{ $originator }}</span>
            </div>
            <div class="sig-row">
                <span class="lbl">Department/ Date:</span>
                <span class="uline">{{ $departmentDate }}</span>
            </div>
            <div class="sig-row">
                <span class="lbl">Reviewed by/ Date:</span>
                <span class="uline">{{ $reviewedByDate }}</span>
            </div>
        </div>

        <div class="box-section" style="padding:0;">
            <table class="approvals-table">
                <thead>
                    <tr>
                        <th class="approvals-label"><em>Approvals:</em></th>
                        <th>Position</th>
                        <th>Names</th>
                        <th>Signature</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @for($i = 0; $i < 4; $i++)
                        <tr>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
    </div>

    <div class="footer-rule"></div>
    <div class="footer">
        <span>Effectivity Date: January 2018</span>
        <span>Rev. <strong>1</strong></span>
        <span>Page: <strong>1</strong> of <strong>1</strong></span>
    </div>
</div>
</body>
</html>

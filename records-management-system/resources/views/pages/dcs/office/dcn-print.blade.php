<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
        /*
         * CSPC-F-DCC-01 Document Change Notice — short bond 8.5 × 11in
         * Header from page edge; form table at left 1.34 / right 0.78 / width 6.39
         */
        @page {
            size: 8.5in 11in;
            margin: 0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { margin: 0; padding: 0; min-height: 100%; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1;
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
            width: 8.5in;
            height: 11in;
            min-height: 11in;
            max-height: 11in;
            margin: 16px auto;
            padding: 0;
            background: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
            line-height: 1;
        }

        /* ── Header (page-edge) ──
         * logo 0.64×0.66 | left 0.51 | top 0.24 | gap to line 0.1
         * line Y = 0.24+0.66+0.1 = 1.00in | line left 0.37 | code right 0.51 | gap 0.08
         */
        .hdr-band {
            position: relative;
            width: 8.5in;
            height: 1.00in;
            margin: 0;
            padding: 0;
            overflow: visible;
        }
        .hdr-logo-cell {
            position: absolute;
            top: 0.24in;
            left: 0.51in;
            width: 0.64in;
            height: 0.66in;
            overflow: hidden;
            line-height: 0;
            z-index: 2;
        }
        /* logo.png ~500×500 with ~272×272 opaque seal */
        .hdr-logo {
            display: block;
            width: calc(0.64in * 500 / 272);
            height: calc(0.66in * 500 / 272);
            margin-left: calc((0.64in - (0.64in * 500 / 272)) / 2);
            margin-top: calc((0.66in - (0.66in * 500 / 272)) / 2);
            object-fit: fill;
            border: 0;
        }
        .hdr-text-cell {
            position: absolute;
            top: 0.35in;
            left: calc(0.51in + 0.64in + 0.09in);
            right: 1.6in;
            height: calc(1.00in - 0.35in - 0.14in); /* ends 0.14in above line */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            line-height: 1;
            z-index: 1;
        }
        .hdr-republic {
            font-family: Verdana, Geneva, sans-serif;
            font-size: 10pt;
            font-weight: 400;
            line-height: 1;
        }
        .hdr-name {
            font-family: 'Arial Rounded MT Bold', 'Arial Rounded MT', 'Arial Rounded', Arial, Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            line-height: 1;
        }
        .hdr-location {
            font-family: Verdana, Geneva, sans-serif;
            font-size: 10pt;
            font-weight: 400;
            line-height: 1;
        }
        .hdr-rule {
            position: absolute;
            top: 1.00in;
            left: 0.37in;
            right: 0.51in;
            display: flex;
            align-items: center;
            gap: 0.08in;
            height: 12pt;
            transform: translateY(-50%);
            z-index: 3;
        }
        .hdr-rule-line {
            flex: 1 1 auto;
            min-width: 0;
            height: 2px;
            background: #0071BC;
            border: none;
        }
        .hdr-rule-code {
            flex: 0 0 auto;
            font-family: 'Arial Narrow', Arial, Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
            background: transparent;
        }

        /* Title: 0.61in below header line */
        .form-title {
            margin: 0.61in 0.69in 0;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1;
            text-transform: none;
        }

        /* DCN #: 0.22 below title; underline 0.71; then 0.35 to table */
        .dcn-no-row {
            display: flex;
            align-items: flex-end;
            margin: 0.22in 0.69in 0.18in 1.43in;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: 400;
            line-height: 1;
            height: 0.22in;
        }
        .dcn-no-row .lbl {
            margin-right: 4px;
            white-space: nowrap;
        }
        .dcn-no-uline {
            display: inline-flex;
            flex-direction: column;
            justify-content: flex-end;
            width: 0.71in;
            height: 0.2in;
        }
        .dcn-no-uline .val {
            font-weight: 400;
            font-size: 11pt;
            line-height: 1;
            overflow: hidden;
            white-space: nowrap;
        }
        .dcn-no-uline .rule {
            border-bottom: 1px solid #000;
            height: 0;
        }

        /* Main form table */
        .dcn-table {
            width: 6.39in;
            margin: 0 0.78in 0 1.34in;
            border: 1px solid #000;
            border-collapse: collapse;
            table-layout: fixed;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1;
        }
        .dcn-table td {
            border: 1px solid #000;
            vertical-align: top;
            padding: 0;
        }

        /* Row 1: 0.19+0.18+0.13+0.20+0.18 = 0.88in */
        .r1 { height: 0.88in; }
        .r1-inner {
            height: 0.88in;
            display: flex;
            flex-direction: column;
        }
        .r1-band { width: 100%; flex-shrink: 0; }
        .r1-b1 { height: 0.19in; }
        .r1-b2 {
            height: 0.18in;
            display: flex;
            align-items: flex-end;
            padding-left: 0.13in;
            padding-right: 0.1in;
            font-size: 11pt;
            line-height: 1;
        }
        .r1-b3 { height: 0.13in; }
        .r1-b4 {
            height: 0.20in;
            display: flex;
            align-items: flex-end;
            padding-left: 1.38in;
            padding-right: 0.1in;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1;
        }
        .r1-b5 { height: 0.18in; }
        .fline {
            display: inline-flex;
            flex-direction: column;
            justify-content: flex-end;
            height: 100%;
            min-width: 0;
        }
        .fline .val {
            line-height: 1;
            overflow: hidden;
            white-space: nowrap;
            padding: 0 2px;
            background: transparent;
        }
        .fline .rule {
            border-bottom: 1px solid #000;
            height: 0;
            width: 100%;
        }
        .fline.docno { width: 1.86in; flex: 0 0 1.86in; }
        .fline.title { width: 3.32in; flex: 0 0 3.32in; }
        .lbl-11 {
            font-size: 11pt;
            line-height: 1;
            white-space: nowrap;
            margin-right: 4px;
        }
        .lbl-10 {
            font-size: 10pt;
            line-height: 1;
            white-space: nowrap;
            margin-right: 4px;
        }

        /* Row 2: 0.18 × 18 = 3.24in (From/To each reduced by 2 × 0.18) */
        .r2 { height: 3.24in; }
        .r2-inner {
            height: 3.24in;
            display: flex;
            flex-direction: column;
        }
        .r2-band {
            height: 0.18in;
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            font-size: 11pt;
            line-height: 1;
        }
        .r2-label { padding-left: 0.09in; font-weight: 400; }
        .r2-from { padding-left: 0.74in; }
        .r2-to { padding-left: 0.82in; }
        .r2-write {
            flex: 1;
            height: 0.18in;
            margin: 0 0.12in 0 0.08in;
            border-bottom: none;
            overflow: hidden;
            font-size: 11pt;
            line-height: 1;
            padding: 0 2px;
        }
        .r2-write.is-first { border-bottom: none; }

        /* Row 3: 0.18 × 5 = 0.90in */
        .r3 { height: 0.90in; }
        .r3-inner {
            height: 0.90in;
            display: flex;
            flex-direction: column;
        }
        .r3-band {
            height: 0.18in;
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            font-size: 11pt;
            line-height: 1;
            padding-left: 0.08in;
            padding-right: 0.12in;
        }
        .r3-write {
            flex: 1;
            height: 0.18in;
            border-bottom: none;
            overflow: hidden;
            padding: 0 2px;
        }

        /* Row 4: 0.18 × 5 = 0.90in */
        .r4 { height: 0.90in; }
        .r4-inner {
            height: 0.90in;
            display: flex;
            flex-direction: column;
        }
        .r4-band {
            height: 0.18in;
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            font-size: 11pt;
            line-height: 1;
            padding-left: 0.08in;
            padding-right: 0.12in;
        }
        .fline.sig { width: 3.16in; flex: 0 0 3.16in; }

        /* Row 5 header: 0.18in — 4 cols = 1.85+2.25+1.00+1.29 = 6.39 */
        .r5-head { height: 0.18in; padding: 0; border-bottom: 1px solid #000; }
        .approvals {
            width: 100%;
            height: 0.18in;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .approvals th {
            border: none;
            border-right: 1px solid #000;
            height: 0.18in;
            max-height: 0.18in;
            padding: 0 2px;
            text-align: center;
            vertical-align: middle;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: 400;
            line-height: 1;
            background: transparent;
        }
        .approvals th:last-child { border-right: none; }
        .approvals col.c1 { width: 1.85in; }
        .approvals col.c2 { width: 2.25in; }
        .approvals col.c3 { width: 1.00in; }
        .approvals col.c4 { width: 1.29in; }
        .approvals .appr-label em {
            font-style: italic;
            font-weight: 400;
        }
        .approvals .appr-label .pos {
            font-style: normal;
            font-weight: 400;
        }

        /* Row 5 body: 0.18 × 9 = 1.62in */
        .r5-body { height: 1.62in; padding: 0; }
        .approvals-body {
            width: 100%;
            height: 1.62in;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .approvals-body td {
            border: none;
            border-right: 1px solid #000;
            height: 1.62in;
            vertical-align: top;
            padding: 0;
        }
        .approvals-body td:last-child { border-right: none; }
        .approvals-body col.c1 { width: 1.85in; }
        .approvals-body col.c2 { width: 2.25in; }
        .approvals-body col.c3 { width: 1.00in; }
        .approvals-body col.c4 { width: 1.29in; }

        /* Footer: line left 0.69 / right 0.72; bottom margin 0.46 */
        .footer-wrap {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0.46in;
        }
        .footer-rule {
            margin: 0 0.72in 0 0.69in;
            border: none;
            border-top: 2px solid #0071BC;
            height: 0;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 4px 0.69in 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            font-weight: 400;
            line-height: 1;
        }

        @media print {
            html, body {
                width: 8.5in !important;
                height: 11in !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .print-toolbar { display: none !important; }
            .sheet {
                box-shadow: none !important;
                margin: 0 !important;
                width: 8.5in !important;
                height: 11in !important;
                min-height: 11in !important;
                max-height: 11in !important;
                overflow: hidden !important;
                page-break-inside: avoid !important;
            }
            .hdr-rule-line,
            .footer-rule {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .fline .rule,
            .dcn-no-uline .rule {
                border-bottom: 1px solid #000 !important;
            }
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
    $departmentDate = \App\Helpers\OfficeIntakeHelper::departmentDateForPrint(
        trim((string) ($dcn->department_date ?? ''))
    );
    $reviewedByDate = trim((string) ($dcn->reviewed_by_date ?? ''));
    $dcnNo = trim((string) ($dcn->dcn_no ?? ''));

    $fromLines = array_pad([''], 6, '');
    if ($changeFrom !== '') {
        $parts = preg_split('/\R/u', $changeFrom) ?: [$changeFrom];
        $fromLines = array_pad(array_slice($parts, 0, 6), 6, '');
        if (count($parts) === 1) {
            $fromLines = array_pad([$changeFrom], 6, '');
        }
    }

    $toLines = array_pad([''], 7, '');
    if ($changeTo !== '') {
        $parts = preg_split('/\R/u', $changeTo) ?: [$changeTo];
        $toLines = array_pad(array_slice($parts, 0, 7), 7, '');
        if (count($parts) === 1) {
            $toLines = array_pad([$changeTo], 7, '');
        }
    }

    $justLines = array_pad([''], 3, '');
    if ($justification !== '') {
        $parts = preg_split('/\R/u', $justification) ?: [$justification];
        $justLines = array_pad(array_slice($parts, 0, 3), 3, '');
        if (count($parts) === 1) {
            $justLines = array_pad([$justification], 3, '');
        }
    }
@endphp
<div class="print-toolbar">
    <button type="button" class="btn-print" onclick="document.title=''; window.print();">Print</button>
    <button type="button" class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="sheet">
    <header class="hdr-band">
        <div class="hdr-logo-cell">
            @if(!empty($logoSrc))
                <img src="{{ $logoSrc }}" alt="" class="hdr-logo">
            @endif
        </div>
        <div class="hdr-text-cell">
            <div class="hdr-republic">Republic of the Philippines</div>
            <div class="hdr-name">Camarines Sur Polytechnic Colleges</div>
            <div class="hdr-location">Nabua, Camarines Sur</div>
        </div>
        <div class="hdr-rule">
            <div class="hdr-rule-line"></div>
            <span class="hdr-rule-code">CSPC-F-DCC-01</span>
        </div>
    </header>

    <div class="form-title">Document Change Notice</div>

    <div class="dcn-no-row">
        <span class="lbl">DCN #</span>
        <div class="dcn-no-uline">
            <div class="val">{{ $dcnNo }}</div>
            <div class="rule"></div>
        </div>
    </div>

    <table class="dcn-table">
        {{-- Row 1: Document No + Title --}}
        <tr class="r1">
            <td>
                <div class="r1-inner">
                    <div class="r1-band r1-b1"></div>
                    <div class="r1-band r1-b2">
                        <span class="lbl-11">Document No:</span>
                        <div class="fline docno">
                            <div class="val">{{ $docNo }}</div>
                            <div class="rule"></div>
                        </div>
                    </div>
                    <div class="r1-band r1-b3"></div>
                    <div class="r1-band r1-b4">
                        <span class="lbl-10">Title:</span>
                        <div class="fline title">
                            <div class="val">{{ $docTitle }}</div>
                            <div class="rule"></div>
                        </div>
                    </div>
                    <div class="r1-band r1-b5"></div>
                </div>
            </td>
        </tr>

        {{-- Row 2: Detailed Description (22 × 0.18) --}}
        <tr class="r2">
            <td>
                <div class="r2-inner">
                    {{-- 18 × 0.18in: From/To each shortened by 2 bands --}}
                    <div class="r2-band"></div>
                    <div class="r2-band r2-label">Detailed Description of Change:</div>
                    <div class="r2-band"></div>
                    <div class="r2-band r2-from">
                        <span>From:</span>
                        <div class="r2-write is-first">{{ $fromLines[0] ?? '' }}</div>
                    </div>
                    @for($i = 1; $i <= 5; $i++)
                        <div class="r2-band">
                            <div class="r2-write" style="margin-left:0.74in;">{{ $fromLines[$i] ?? '' }}</div>
                        </div>
                    @endfor
                    <div class="r2-band"></div>
                    <div class="r2-band r2-to">
                        <span>To:</span>
                        <div class="r2-write is-first">{{ $toLines[0] ?? '' }}</div>
                    </div>
                    @for($i = 1; $i <= 6; $i++)
                        <div class="r2-band">
                            <div class="r2-write" style="margin-left:0.82in;">{{ $toLines[$i] ?? '' }}</div>
                        </div>
                    @endfor
                    <div class="r2-band"></div>
                </div>
            </td>
        </tr>

        {{-- Row 3: Justification (5 × 0.18) --}}
        <tr class="r3">
            <td>
                <div class="r3-inner">
                    <div class="r3-band"></div>
                    <div class="r3-band">Justification of Change:</div>
                    <div class="r3-band"><div class="r3-write">{{ $justLines[0] ?? '' }}</div></div>
                    <div class="r3-band"><div class="r3-write">{{ $justLines[1] ?? '' }}</div></div>
                    <div class="r3-band"><div class="r3-write">{{ $justLines[2] ?? '' }}</div></div>
                </div>
            </td>
        </tr>

        {{-- Row 4: Originator / Department / Reviewed (5 × 0.18) --}}
        <tr class="r4">
            <td>
                <div class="r4-inner">
                    <div class="r4-band"></div>
                    <div class="r4-band">
                        <span class="lbl-11">Originator/ Signature:</span>
                        <div class="fline sig">
                            <div class="val">{{ $originator }}</div>
                            <div class="rule"></div>
                        </div>
                    </div>
                    <div class="r4-band">
                        <span class="lbl-11">Department/ Date:</span>
                        <div class="fline sig">
                            <div class="val">{{ $departmentDate }}</div>
                            <div class="rule"></div>
                        </div>
                    </div>
                    <div class="r4-band">
                        <span class="lbl-11">Reviewed by/ Date:</span>
                        <div class="fline sig">
                            <div class="val">{{ $reviewedByDate }}</div>
                            <div class="rule"></div>
                        </div>
                    </div>
                    <div class="r4-band"></div>
                </div>
            </td>
        </tr>

        {{-- Row 5 header --}}
        <tr>
            <td class="r5-head">
                <table class="approvals">
                    <colgroup>
                        <col class="c1"><col class="c2"><col class="c3"><col class="c4">
                    </colgroup>
                    <tr>
                        <th class="appr-label"><em>Approvals:</em> <span class="pos">Position</span></th>
                        <th>Names</th>
                        <th>Signature</th>
                        <th>Date</th>
                    </tr>
                </table>
            </td>
        </tr>

        {{-- Row 5 body: 11 × 0.18 --}}
        <tr>
            <td class="r5-body">
                <table class="approvals-body">
                    <colgroup>
                        <col class="c1"><col class="c2"><col class="c3"><col class="c4">
                    </colgroup>
                    <tr>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="footer-wrap">
        <div class="footer-rule"></div>
        <div class="footer">
            <span>Effectivity Date: January 2018</span>
            <span>Rev. 1</span>
            <span>Page: 1 of 1</span>
        </div>
    </div>
</div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
        /*
         * CSPC-F-DCC-06 Document Request Form — forced print geometry
         * Long bond 8.5 × 13in | form on upper half
         * Logo: left 0.46in, top 0.23in, size 0.57×0.59, gap to line 0.03in
         * Header/footer rules: 3px
         *
         * @page MUST be top-level (not nested in @media) or Chrome ignores
         * zero margins and still prints date/URL/page headers & footers.
         */
        @page {
            size: 8.5in 13in;
            margin: 0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            min-height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            color: #000 !important;
            background: #e2e8f0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
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
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .print-toolbar .btn-print { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; }

        /* Full long-bond sheet (8.5 × 13); form uses upper half only */
        .sheet {
            width: 8.5in !important;
            height: 13in !important;
            min-height: 13in !important;
            max-height: 13in !important;
            margin: 16px auto;
            padding: 0 !important;
            background: #fff !important;
            position: relative !important;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            color: #000 !important;
            overflow: hidden !important;
        }

        /* ═══════════ HEADER ═══════════
         * logo top 0.23 + height 0.59 + gap 0.03 → line at 0.85in
         */
        .hdr-band {
            position: relative !important;
            width: 8.5in !important;
            height: 0.85in !important;
            min-height: 0.85in !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
        }
        .hdr-logo-cell {
            position: absolute !important;
            top: 0.23in !important;
            left: 0.46in !important;
            width: 0.57in !important;
            height: 0.59in !important;
            min-width: 0.57in !important;
            min-height: 0.59in !important;
            max-width: 0.57in !important;
            max-height: 0.59in !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 0 !important;
            overflow: hidden !important;
            z-index: 2;
        }
        /*
         * logo.png is 500×500 with ~272×272 opaque seal.
         * Size the <img> up and center it so the seal fills the 0.57×0.59 clip box.
         */
        .hdr-logo {
            display: block !important;
            width: calc(0.57in * 500 / 272) !important;
            height: calc(0.59in * 500 / 272) !important;
            min-width: calc(0.57in * 500 / 272) !important;
            min-height: calc(0.59in * 500 / 272) !important;
            max-width: none !important;
            max-height: none !important;
            margin-left: calc((0.57in - (0.57in * 500 / 272)) / 2) !important;
            margin-top: calc((0.59in - (0.59in * 500 / 272)) / 2) !important;
            padding: 0 !important;
            border: 0 !important;
            object-fit: fill !important;
            background: transparent !important;
        }
        .hdr-text-cell {
            position: absolute !important;
            top: 0.23in !important;
            left: calc(0.46in + 0.57in + 0.1in) !important;
            right: 1.5in !important;
            height: 0.59in !important; /* same band as logo; 0.03in clear below to line */
            margin: 0 !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            z-index: 1;
            background: transparent !important;
        }
        .hdr-republic,
        .hdr-location {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .hdr-name {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }
        /* Line at logo-bottom + 0.03in = 0.85in — 3px rule */
        .hdr-rule {
            position: absolute !important;
            top: 0.85in !important;
            left: 0.49in !important;
            right: 0.24in !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.12in !important;
            height: 12pt !important;
            margin: 0 !important;
            padding: 0 !important;
            z-index: 3;
            transform: translateY(-50%);
            background: transparent !important;
        }
        .hdr-rule-line {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            height: 3px !important;
            background: #0071BC !important;
            border: none !important;
            margin: 0 !important;
            padding: 0 !important;
            align-self: center !important;
        }
        .hdr-rule-code {
            flex: 0 0 auto !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 10pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }

        /* ═══════════ BODY (Narrow 0.5in) ═══════════ */
        .body {
            padding: 0.27in 0.5in 0.5in !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            color: #000 !important;
            background: #fff !important;
        }

        .form-title {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            text-align: center !important;
            line-height: 1 !important;
            margin: 0 0 11pt !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }

        /* Write-on lines: VALUE and RULE are siblings — text never covers the stroke */
        .fline {
            display: inline-flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            vertical-align: bottom !important;
            height: 11pt !important;
            background: transparent !important;
        }
        .fline-val {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            min-height: 0 !important;
            padding: 0 1px !important;
            margin: 0 !important;
            background: transparent !important;
            background-color: transparent !important;
            color: #000 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: clip !important;
        }
        .fline-rule {
            display: block !important;
            width: 100% !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            border-bottom: 1px solid #000 !important;
            background: transparent !important;
        }
        .fline.w-req { width: 1.54in !important; flex: 0 0 1.54in !important; }
        .fline.w-date { width: 1.79in !important; flex: 0 0 1.79in !important; }
        .fline.w-fill { flex: 1 1 auto !important; width: auto !important; min-width: 0 !important; }
        .fline.w-fill .fline-val { white-space: normal !important; }

        /* Request / Originator / Document Title — single spacing (1.0) */
        .row {
            display: flex !important;
            align-items: flex-end !important;
            margin: 0 !important;
            padding: 0 !important;
            height: 11pt !important;
            min-height: 11pt !important;
            max-height: 11pt !important;
            background: transparent !important;
            font-size: 11pt !important;
            line-height: 1 !important;
        }
        .lbl {
            flex: 0 0 auto !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            margin: 0 4px 0 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .meta-gap {
            flex: 0 0 2.87in !important;
            width: 2.87in !important;
            height: 1px !important;
        }

        .check-row {
            display: flex !important;
            align-items: center !important;
            height: 11pt !important;
            min-height: 11pt !important;
            max-height: 11pt !important;
            margin: 0 0 9pt !important; /* Arial 9 space before Description */
            padding: 0 !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
        }
        .check-item {
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .check-item.internal { margin-left: 0.24in !important; }
        .check-item.external { margin-left: 0.35in !important; }
        .cb {
            display: inline-block !important;
            width: 0.17in !important;
            height: 0.14in !important;
            border: 1px solid #000 !important;
            text-align: center !important;
            line-height: 0.12in !important;
            font-size: 8pt !important;
            font-weight: 700 !important;
            background: transparent !important;
            color: #000 !important;
            flex-shrink: 0 !important;
        }

        .desc-block {
            margin: 0 0 11pt !important; /* Arial 11 before Distribute */
            padding: 0 !important;
            background: transparent !important;
        }
        .desc-block .lbl {
            display: block !important;
            margin: 0 !important;
            height: 11pt !important;
            line-height: 1 !important;
        }
        .desc-line {
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            height: 11pt !important;
            min-height: 11pt !important;
            max-height: 11pt !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            line-height: 1 !important;
        }
        .desc-line .fline-val {
            white-space: normal !important;
            width: 100% !important;
        }

        .dist-label {
            margin: 0 !important;
            padding: 0 !important;
            height: 11pt !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .dist-grid {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            column-gap: 0.33in !important;
            margin: 0 0 27pt !important; /* Arial 8+8+11 before table */
            padding: 0 !important;
            background: transparent !important;
        }
        .dist-col {
            display: flex !important;
            flex-direction: column !important;
            background: transparent !important;
        }
        /* Distribute rows — single spacing (1.0); column gap stays 0.33in */
        .dist-line {
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            height: 11pt !important;
            min-height: 11pt !important;
            max-height: 11pt !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            line-height: 1 !important;
        }
        .dist-line .fline-val {
            font-size: 11pt !important;
            line-height: 1 !important;
            white-space: nowrap !important;
        }

        /* Signature table */
        .sig-table {
            width: 7.58in !important;
            max-width: 100% !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
        }
        .sig-table col.c1 { width: 1.01in !important; }
        .sig-table col.c2 { width: 1.88in !important; }
        .sig-table col.c3 { width: 2.50in !important; }
        .sig-table col.c4 { width: 2.19in !important; }
        .sig-table th,
        .sig-table td {
            border: 1px solid #000 !important;
            height: 0.2in !important;
            max-height: 0.2in !important;
            padding: 0 3px !important;
            vertical-align: middle !important;
            line-height: 1 !important;
            overflow: hidden !important;
            background: transparent !important;
            background-color: transparent !important;
            color: #000 !important;
        }
        .sig-table .row-label {
            text-align: left !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
        }
        .sig-table .col-c {
            text-align: center !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
        }
        .sig-table thead th {
            font-size: 11pt !important;
            font-weight: 400 !important;
            text-align: center !important;
        }
        .sig-table .name-bold {
            font-size: 10pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            text-align: center !important;
            background: transparent !important;
        }
        .sig-table .desig {
            font-size: 11pt !important;
            font-weight: 400 !important;
            text-align: center !important;
            background: transparent !important;
        }

        .footer-rule {
            border: none !important;
            border-top: 3px solid #0071BC !important;
            margin: 7px 0 0 !important;
            padding: 0 !important;
            height: 0 !important;
            background: transparent !important;
        }
        .footer {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-top: 4px !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 7pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .footer span,
        .footer strong {
            font-size: 7pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }

        @media print {
            html, body {
                width: 8.5in !important;
                height: 13in !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }
            .print-toolbar { display: none !important; }
            .sheet {
                box-shadow: none !important;
                margin: 0 !important;
                width: 8.5in !important;
                height: 13in !important;
                min-height: 13in !important;
                max-height: 13in !important;
                overflow: hidden !important;
                page-break-after: avoid !important;
                break-after: avoid !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .fline-rule,
            .desc-line .fline-rule,
            .dist-line .fline-rule {
                border-bottom: 1px solid #000 !important;
            }
            .hdr-rule-line {
                height: 3px !important;
                background: #0071BC !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .footer-rule {
                border-top: 3px solid #0071BC !important;
            }
        }
    </style>
</head>
<body>
@php
    use App\Helpers\OfficeIntakeHelper;

    $originator = trim((string) ($drf->originator_name ?? ''));
    $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
    $isInternal = $kind === 'internal';
    $isExternal = $kind === 'external';
    $description = trim((string) ($drf->description_reason ?? ''));
    $descLines = $description !== '' ? preg_split('/\R/u', $description) : [];
    // Pack into exactly 4 write-on lines
    $packed = ['', '', '', ''];
    $idx = 0;
    foreach ($descLines as $chunk) {
        $chunk = trim((string) $chunk);
        if ($chunk === '' && $idx === 0 && $packed[0] === '') {
            continue;
        }
        if ($idx < 4) {
            $packed[$idx] = $chunk;
            $idx++;
        } else {
            $packed[3] = trim($packed[3] . ' ' . $chunk);
        }
    }
    $distribute = OfficeIntakeHelper::decodeDistributeTo($drf->distribute_to ?? null);
    $distribute = array_pad(array_slice($distribute, 0, 24), 24, '');
    $preparedName = trim((string) ($drf->prepared_by_name ?? ''));
    $docTitle = trim((string) ($drf->doc_title ?? ''));
    $drfDate = $drf->drf_date
        ? \Carbon\Carbon::parse($drf->drf_date)->format('F d, Y')
        : '';
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
            <span class="hdr-rule-code">CSPC-F-DCC-06</span>
        </div>
    </header>

    <div class="body">
        <div class="form-title">Document Request Form</div>

        <div class="row">
            <span class="lbl">Request # :</span>
            <div class="fline w-req"><div class="fline-val"></div><div class="fline-rule"></div></div>
            <div class="meta-gap" aria-hidden="true"></div>
            <span class="lbl">Date:</span>
            <div class="fline w-date"><div class="fline-val">{{ $drfDate }}</div><div class="fline-rule"></div></div>
        </div>

        <div class="row">
            <span class="lbl">Originator :</span>
            <div class="fline w-fill"><div class="fline-val">{{ $originator }}</div><div class="fline-rule"></div></div>
        </div>

        <div class="row">
            <span class="lbl">Document Title :</span>
            <div class="fline w-fill"><div class="fline-val">{{ $docTitle }}</div><div class="fline-rule"></div></div>
        </div>

        <div class="check-row">
            <span class="lbl">Type of document:</span>
            <span class="check-item internal">
                <span class="cb">@if($isInternal)✓@endif</span> Internal
            </span>
            <span class="check-item external">
                <span class="cb">@if($isExternal)✓@endif</span> External
            </span>
        </div>

        <div class="desc-block">
            <span class="lbl">Description/reason for request (define in detail):</span>
            @foreach($packed as $line)
                <div class="desc-line">
                    <div class="fline-val">{{ $line }}</div>
                    <div class="fline-rule"></div>
                </div>
            @endforeach
        </div>

        <div class="dist-label">Distribute document to (department/position):</div>
        <div class="dist-grid">
            @for($col = 0; $col < 4; $col++)
                <div class="dist-col">
                    @for($row = 0; $row < 6; $row++)
                        <div class="dist-line">
                            <div class="fline-val">{{ $distribute[$col * 6 + $row] ?? '' }}</div>
                            <div class="fline-rule"></div>
                        </div>
                    @endfor
                </div>
            @endfor
        </div>

        <table class="sig-table">
            <colgroup>
                <col class="c1">
                <col class="c2">
                <col class="c3">
                <col class="c4">
            </colgroup>
            <thead>
                <tr>
                    <th class="row-label"></th>
                    <th class="col-c">Prepared by:</th>
                    <th class="col-c">Reviewed by:</th>
                    <th class="col-c">Approved by:</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="row-label">Signature</td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                </tr>
                <tr>
                    <td class="row-label">Name</td>
                    <td class="col-c">{{ $preparedName }}</td>
                    <td class="name-bold">NANCY S. PENETRANTE, MBA</td>
                    <td class="name-bold">JOCELYN O. JINTALAN, DBA</td>
                </tr>
                <tr>
                    <td class="row-label">Designation</td>
                    <td class="col-c"></td>
                    <td class="desig">ISO Vice Chairperson</td>
                    <td class="desig">ISO Chairperson</td>
                </tr>
                <tr>
                    <td class="row-label">Date</td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-rule"></div>
        <div class="footer">
            <span>Effectivity Date: January 2018</span>
            <span>Rev. 0</span>
            <span>Page: 1 of 1</span>
        </div>
    </div>
</div>
</body>
</html>

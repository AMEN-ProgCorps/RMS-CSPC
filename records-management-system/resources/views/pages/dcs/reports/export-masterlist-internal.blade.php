<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ !empty($autoPrint) || !empty($embed) ? '' : 'Document Masterlist' }}</title>
    <style>
        /*
         * CSPC-F-DCC-03 Document Masterlist (Internal) — short bond 8.5 × 11
         * Line spacing 1.0. Print dialog: Margins → None.
         */
        @page {
            size: 8.5in 11in;
            margin: 0 !important;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1;
            color: #000;
            background: #94a3b8;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-toolbar {
            display: none;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 24px;
            justify-content: center;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .print-toolbar.visible { display: flex; }
        .print-toolbar button {
            padding: 8px 20px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
        }
        .print-toolbar .btn-pdf { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-print { background: #fff; color: #0d2a7a; border-color: #0d2a7a; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; }

        .ml-sheet {
            width: 8.5in;
            height: 11in;
            min-height: 11in;
            max-height: 11in;
            margin: 16px auto;
            padding: 0;
            background: #fff;
            position: relative;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.28);
            overflow: hidden;
            page-break-after: always;
            break-after: page;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .ml-sheet:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        /*
         * Forced header geometry (inches). Do not inherit other report CSS.
         * Logo 0.63×0.65 @ top 0.22 left 0.54 | gap to text 0.09
         * Logo bottom → line 0.03 → line Y = 0.90in
         * Text top 0.34 | 10pt × 1.0 | text bottom → line 0.07
         * Line 7.32in, #0070C0, 1.5pt | code 0.11 above, right 0.55
         */
        .ml-hdr {
            position: relative !important;
            width: 8.5in !important;
            height: 0.96in !important;
            min-height: 0.96in !important;
            max-height: 0.96in !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
        }
        .ml-logo-cell {
            position: absolute !important;
            top: 0.22in !important;
            left: 0.54in !important;
            width: 0.63in !important;
            height: 0.65in !important;
            min-width: 0.63in !important;
            min-height: 0.65in !important;
            max-width: 0.63in !important;
            max-height: 0.65in !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
            line-height: 0 !important;
            z-index: 2;
        }
        /* logo.png is 500×500 with ~272×272 seal — scale so the seal fills 0.63×0.65 */
        .ml-logo {
            display: block !important;
            width: calc(0.63in * 500 / 272) !important;
            height: calc(0.65in * 500 / 272) !important;
            min-width: calc(0.63in * 500 / 272) !important;
            min-height: calc(0.65in * 500 / 272) !important;
            max-width: none !important;
            max-height: none !important;
            margin-left: calc((0.63in - (0.63in * 500 / 272)) / 2) !important;
            margin-top: calc((0.65in - (0.65in * 500 / 272)) / 2) !important;
            padding: 0 !important;
            border: 0 !important;
            object-fit: fill !important;
            background: transparent !important;
        }
        .ml-hdr-text {
            position: absolute !important;
            top: 0.34in !important;
            left: calc(0.54in + 0.63in + 0.09in) !important;
            right: 1.7in !important;
            width: auto !important;
            height: calc(0.22in + 0.65in + 0.03in - 0.34in - 0.07in) !important; /* ends 0.07 above the line */
            min-height: calc(0.22in + 0.65in + 0.03in - 0.34in - 0.07in) !important;
            max-height: calc(0.22in + 0.65in + 0.03in - 0.34in - 0.07in) !important;
            margin: 0 !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            font-size: 10pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            letter-spacing: 0 !important;
            z-index: 1;
            background: transparent !important;
            box-sizing: border-box !important;
            color: #000 !important;
        }
        .ml-hdr-text > div {
            display: block !important;
            height: auto !important;
            max-height: none !important;
            margin: 0 !important;
            padding: 0 !important;
            font-size: 10pt !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
        }
        .ml-hdr-republic,
        .ml-hdr-location {
            font-family: Verdana, Geneva, sans-serif !important;
            font-size: 10pt !important;
            font-weight: 400 !important;
        }
        .ml-hdr-name {
            font-family: "Arial Rounded MT Bold", "Arial Rounded MT", "Arial Rounded", Arial, Helvetica, sans-serif !important;
            font-size: 10pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
        }
        .ml-hdr-rule {
            position: absolute !important;
            top: calc(0.22in + 0.65in + 0.03in) !important;
            left: 0.54in !important;
            width: 7.32in !important;
            min-width: 7.32in !important;
            max-width: 7.32in !important;
            right: auto !important;
            height: 12pt !important;
            margin: 0 !important;
            padding: 0 !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.11in !important;
            transform: translateY(-50%) !important;
            z-index: 3;
            box-sizing: border-box !important;
        }
        .ml-hdr-rule-line {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            width: auto !important;
            height: 1.5pt !important;
            min-height: 1.5pt !important;
            max-height: 1.5pt !important;
            background: #0070C0 !important;
            border: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .ml-hdr-code {
            flex: 0 0 auto !important;
            position: static !important;
            top: auto !important;
            right: auto !important;
            left: auto !important;
            height: auto !important;
            display: block !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 9pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            color: #000 !important;
            z-index: 4;
        }

        .ml-body {
            padding: 0.14in 0.56in 0 0.56in;
        }
        .ml-title {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16pt;
            font-weight: 700;
            text-align: center;
            line-height: 1;
            text-transform: uppercase;
        }
        .ml-asof {
            display: block !important;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: 700;
            font-style: italic;
            text-align: center;
            line-height: 1;
            margin-top: 0.06in;
            margin-bottom: 0;
            color: #000;
            visibility: visible !important;
        }
        .ml-filters {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-top: 0.10in;
            margin-bottom: 0.13in;
            margin-left: 0.90in;
            margin-right: 1.59in;
            line-height: 1;
        }
        .ml-filters.has-rule {
            margin-bottom: 0.02in;
        }
        .ml-filters.is-no-asof {
            margin-top: 0.24in;
        }
        .ml-filters.has-box {
            justify-content: space-evenly;
            width: 7.38in;
            margin-left: 0;
            margin-right: 0;
            margin-bottom: 0.11in;
            padding: 0.06in 0;
            border: 0.5pt solid #000;
            box-sizing: border-box;
        }
        .ml-filters.has-box .ml-fi.is-external,
        .ml-filters.has-box .ml-fi.is-forms,
        .ml-filters.has-box .ml-fi.is-logbooks {
            margin-left: 0;
        }
        .ml-fi {
            display: inline-flex;
            align-items: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: 700;
            line-height: 1;
            color: #000;
        }
        .ml-fi + .ml-fi { margin-left: 0; }
        .ml-fi.is-external { margin-left: 0.76in; }
        .ml-fi.is-forms { margin-left: 0.64in; }
        .ml-fi.is-logbooks { margin-left: 0.79in; }
        .ml-cb {
            display: inline-block;
            width: 0.1in;
            height: 0.1in;
            border: 1px solid #000;
            margin-right: 0.05in;
            text-align: center;
            line-height: 0.09in;
            font-size: 8pt;
            font-weight: 700;
            flex: 0 0 0.1in;
        }
        .ml-filter-rule {
            width: 7.38in;
            height: 0;
            border: 0 !important;
            border-top: 0.5pt solid #000 !important;
            margin: 0 0 0.11in 0;
        }

        .ml-table {
            width: 7.36in;
            border-collapse: separate !important;
            border-spacing: 0 !important;
            border: 0.5pt solid #000 !important;
            table-layout: fixed;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1;
        }
        .ml-table col.c1 { width: 0.44in; }
        .ml-table col.c2 { width: 1.38in; }
        .ml-table col.c3 { width: 0.39in; }
        .ml-table col.c4 { width: 2.3in; }
        .ml-table col.c5 { width: 1.13in; }
        .ml-table col.c6 { width: 1.17in; }
        .ml-table col.c7 { width: 0.55in; }
        .ml-thead {
            height: 0.49in;
        }
        .ml-thead th {
            height: 0.49in;
            background: #8DB4E2;
            border: 0 !important;
            border-right: 0.5pt solid #000 !important;
            border-bottom: 0.5pt solid #000 !important;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            vertical-align: middle;
            padding: 0 2px;
        }
        .ml-thead th:last-child { border-right: 0 !important; }
        .ml-thead tr:last-child th { border-bottom: 0 !important; }
        .ml-thead-gap { height: 0.18in; }
        .ml-table td {
            border: 0 !important;
            border-right: 0.5pt solid #000 !important;
            border-bottom: 0.5pt solid #000 !important;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1;
            text-align: center;
            vertical-align: middle;
            padding: 0.05in 0.04in;
        }
        .ml-table td:last-child { border-right: 0 !important; }
        .ml-table tr:last-child td { border-bottom: 0 !important; }
        .ml-table td.is-title { text-align: left; }
        .ml-table td.is-doc-no,
        .ml-table td.is-doc-no b {
            font-weight: 700 !important;
        }
        .ml-empty { text-align: center; font-style: italic; padding: 0.2in; }

        .ml-footer {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0.28in;
        }
        .ml-footer-line {
            width: 7.4in;
            height: 0;
            border: 0;
            border-top: 1.5pt solid #0070C0;
            margin: 0 0.58in 0.04in 0.52in;
        }
        .ml-ft {
            display: flex;
            align-items: baseline;
            flex-wrap: nowrap;
            margin-left: 0.51in;
            margin-right: 1in;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 9pt !important;
            line-height: 1;
            color: #000;
            white-space: nowrap;
        }
        .ml-ft-date,
        .ml-ft-rev,
        .ml-ft-page {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        .ml-ft-date { font-weight: 400; }
        .ml-ft-date strong,
        .ml-ft-rev strong,
        .ml-ft-page b {
            font-family: Arial, Helvetica, sans-serif !important;
            font-weight: 700 !important;
        }
        .ml-ft-rev { margin-left: 1.89in; }
        .ml-ft-page { margin-left: 2.37in; }
        .ml-hdr-code b { font-weight: 700 !important; }

        .ml-sheet.is-internal-forms .ml-hdr {
            height: 0.90in !important;
            min-height: 0.90in !important;
            max-height: 0.90in !important;
        }
        .ml-sheet.is-internal-forms .ml-body {
            padding-top: 0.14in !important;
        }
        .ml-sheet.is-internal-forms .ml-title {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 16pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
        }
        .ml-sheet.is-internal-forms .ml-footer { bottom: 0.32in; }
        .ml-sheet.is-internal-forms .ml-footer-line {
            width: 7.49in;
            margin: 0 0.49in 0.05in 0.52in;
        }
        .ml-sheet.is-internal-forms .ml-ft { margin-right: 0.5in; }
        .ml-sheet.is-internal-forms .ml-ft-rev { margin-left: 1.95in; }
        .ml-sheet.is-internal-forms .ml-ft-page { margin-left: 2.87in; }

        @media print {
            body { background: #fff !important; }
            .print-toolbar { display: none !important; }
            .ml-sheet {
                margin: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    @if(empty($embed))
    <div class="print-toolbar" id="toolbar">
        <button class="btn-pdf" type="button" id="btnPdf">Save as PDF</button>
        <button class="btn-print" type="button" id="btnPrint">Print</button>
        <button class="btn-close" type="button" id="btnClose">Close</button>
    </div>
    @endif

    @php
        $logoPath = public_path('images/logo.png');
        $logoSrc = file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoPath))) : '';
        $asOfLabel = !empty($asOf)
            ? \Carbon\Carbon::parse($asOf)->format('F j, Y')
            : now('Asia/Manila')->format('F j, Y');
        $footerEffectivity = $footerEffectivity ?? 'January 2025';
        $footerRev = $footerRev ?? '4';
        $letterNumber = $letterNumber ?? 'CSPC-F-DCC-03';
        $checkedType = $checkedType ?? 'internal';
        $activeSub = $activeSub ?? '';
        $showAsOf = ! in_array($activeSub, ['internal_forms', 'external_docs', 'forms', 'logbooks'], true);
        $showFilterRule = in_array($activeSub, ['internal_forms', 'external_docs'], true);
        $showFilterBox = in_array($activeSub, ['forms', 'logbooks'], true);
        $allRows = collect($rows ?? [])->values()->all();
        $pageChunks = $allRows === [] ? [[]] : array_chunk($allRows, 18);
        $totalPages = max(1, count($pageChunks));
        $colgroup = '<colgroup><col class="c1"><col class="c2"><col class="c3"><col class="c4"><col class="c5"><col class="c6"><col class="c7"></colgroup>';
        $startNo = 0;
    @endphp

    @foreach($pageChunks as $pageIndex => $pageRows)
        @php
            $pageNo = $pageIndex + 1;
        @endphp
        <div class="ml-sheet {{ $activeSub === 'internal_forms' ? 'is-internal-forms' : '' }}">
            <div class="ml-hdr">
                @if($logoSrc)
                    <span class="ml-logo-cell">
                        <img src="{{ $logoSrc }}" alt="" class="ml-logo">
                    </span>
                @endif
                <div class="ml-hdr-text">
                    <div class="ml-hdr-republic">{{ $republic ?? 'Republic of the Philippines' }}</div>
                    <div class="ml-hdr-name">{{ $institutionName ?? 'Camarines Sur Polytechnic Colleges' }}</div>
                    <div class="ml-hdr-location">{{ $institutionAddress ?? 'Nabua, Camarines Sur' }}</div>
                </div>
                <div class="ml-hdr-rule">
                    <span class="ml-hdr-rule-line"></span>
                    <span class="ml-hdr-code"><b>{{ $letterNumber }}</b></span>
                </div>
            </div>

            <div class="ml-body">
                <div class="ml-title">DOCUMENT MASTERLIST</div>
                @if($showAsOf)
                    <div class="ml-asof">As of {{ $asOfLabel }}</div>
                @endif
                <div class="ml-filters {{ $showAsOf ? '' : 'is-no-asof' }}{{ $showFilterRule ? ' has-rule' : '' }}{{ $showFilterBox ? ' has-box' : '' }}">
                    <span class="ml-fi"><span class="ml-cb">{{ $checkedType === 'internal' ? '/' : '' }}</span>Internal</span>
                    <span class="ml-fi is-external"><span class="ml-cb">{{ $checkedType === 'external' ? '/' : '' }}</span>External</span>
                    <span class="ml-fi is-forms"><span class="ml-cb">{{ $checkedType === 'forms' ? '/' : '' }}</span>Forms</span>
                    <span class="ml-fi is-logbooks"><span class="ml-cb">{{ $checkedType === 'logbooks' ? '/' : '' }}</span>Logbooks</span>
                </div>
                @if($showFilterRule)
                    <div class="ml-filter-rule"></div>
                @endif

                <table class="ml-table ml-thead">
                    {!! $colgroup !!}
                    <thead>
                        <tr>
                            <th>Item<br>No.</th>
                            <th>Doc. No.</th>
                            <th>Rev<br>No.</th>
                            <th>Document Title</th>
                            <th>Effectivity<br>Date</th>
                            <th>Originator</th>
                            <th>No.<br>of pages</th>
                        </tr>
                    </thead>
                </table>
                <div class="ml-thead-gap"></div>
                <table class="ml-table">
                    {!! $colgroup !!}
                    <tbody>
                        @forelse($pageRows as $i => $row)
                            @php
                                $item = is_array($row) ? ($row['item_no'] ?? '') : ($row->item_no ?? '');
                            @endphp
                            <tr>
                                <td>{{ $item !== '' && $item !== null ? $item : '' }}</td>
                                <td class="is-doc-no"><b>{{ is_array($row) ? ($row['doc_no'] ?? '—') : ($row->doc_no ?? '—') }}</b></td>
                                <td>{{ is_array($row) ? ($row['rev_no'] ?? '—') : ($row->rev_no ?? '—') }}</td>
                                <td class="is-title">{{ is_array($row) ? ($row['doc_title'] ?? '—') : ($row->doc_title ?? '—') }}</td>
                                <td>{{ is_array($row) ? ($row['effectivity_date'] ?? '—') : ($row->effectivity_date ?? '—') }}</td>
                                <td>{{ is_array($row) ? ($row['originator'] ?? '—') : ($row->originator ?? '—') }}</td>
                                <td>{{ is_array($row) ? ($row['no_pages'] ?? '—') : ($row->no_pages ?? '—') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="ml-empty">No records found for the selected filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ml-footer">
                <div class="ml-footer-line"></div>
                <div class="ml-ft">
                    <span class="ml-ft-date">Effectivity Date: <strong>{{ $footerEffectivity }}</strong></span>
                    <span class="ml-ft-rev">Rev. <strong>{{ $footerRev }}</strong></span>
                    <span class="ml-ft-page">Page <b>{{ $pageNo }}</b> of <b>{{ $totalPages }}</b></span>
                </div>
            </div>
        </div>
        @php $startNo += count($pageRows); @endphp
    @endforeach

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var t = document.getElementById('toolbar');
            if (t) t.classList.add('visible');
            var btnPdf = document.getElementById('btnPdf');
            if (btnPdf) btnPdf.addEventListener('click', function(e) {
                e.preventDefault();
                var p = new URLSearchParams(window.location.search);
                p.set('format', 'pdf');
                var url = window.location.pathname + '?' + p.toString();
                var ifr = document.createElement('iframe');
                ifr.style.display = 'none';
                ifr.src = url;
                document.body.appendChild(ifr);
                setTimeout(function() { if (ifr.parentNode) ifr.parentNode.removeChild(ifr); }, 5000);
            });
            var btnPrint = document.getElementById('btnPrint');
            if (btnPrint) btnPrint.addEventListener('click', function(e) {
                e.preventDefault();
                window.print();
            });
            var btnClose = document.getElementById('btnClose');
            if (btnClose) btnClose.addEventListener('click', function() { window.close(); });
            if (new URLSearchParams(window.location.search).get('autoPrint') === '1') {
                setTimeout(function() { window.print(); }, 250);
            }
        });
    </script>
</body>
</html>

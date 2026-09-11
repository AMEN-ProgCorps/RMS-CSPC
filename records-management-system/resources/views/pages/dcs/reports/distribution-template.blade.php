<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ empty($embed) ? (($title ?? 'DISTRIBUTION AND RETRIEVAL') . ' — ' . ($letterNumber ?? 'CSPC-F-DCC-05')) : '' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { min-height: 100%; }
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
        .print-toolbar .btn-print { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; }

        /*
         * Short bond 8.5 × 11
         * Logo: 0.64×0.66 | top 0.28in | left 0.5in | right-gap 0.09in | bottom-to-line 0.06in
         * Republic top: 0.34in | Location bottom-to-line: 0.12in
         * Line Y = 0.28 + 0.66 + 0.06 = 1.00in
         * Table left: 0.76in | right: 0.70in | Sub-header: 3 × 11pt (top space + text + bottom space)
         * Footer: left 0.59in | right 0.36in | bottom 0.28in
         */
        .sheet {
            width: 8.5in;
            height: 11in;
            min-height: 11in;
            max-height: 11in;
            margin: 16px auto;
            padding: 0 0 0.28in;
            background: #fff;
            position: relative;
            box-shadow: 0 1px 6px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            page-break-inside: avoid;
            break-inside: avoid;
            page-break-after: always;
            break-after: page;
        }
        .sheet:last-child {
            page-break-after: auto;
            break-after: auto;
        }
        .sheet-body {
            height: calc(11in - 0.28in);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .sheet-main {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }

        /* Fixed band: logo top+height+bottom-gap pins the blue line */
        .hdr-band {
            position: relative !important;
            width: 8.5in !important;
            height: calc(0.28in + 0.66in + 0.06in) !important; /* 1.00in */
            min-height: calc(0.28in + 0.66in + 0.06in) !important;
            max-height: calc(0.28in + 0.66in + 0.06in) !important;
            margin: 0 !important;
            padding: 0 !important;
            box-sizing: border-box !important;
        }
        .hdr-logo-cell {
            position: absolute !important;
            top: 0.28in !important;
            left: 0.5in !important;
            width: 0.64in !important;
            height: 0.66in !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 0 !important;
            z-index: 2;
        }
        .hdr-logo-wrap {
            width: 0.64in !important;
            height: 0.66in !important;
            min-width: 0.64in !important;
            min-height: 0.66in !important;
            max-width: 0.64in !important;
            max-height: 0.66in !important;
            margin: 0 !important;
            overflow: hidden;
            display: block;
            line-height: 0;
        }
        .hdr-logo {
            width: 0.64in !important;
            height: 0.66in !important;
            min-width: 0.64in !important;
            min-height: 0.66in !important;
            max-width: none !important;
            max-height: none !important;
            object-fit: fill !important;
            object-position: center center;
            display: block !important;
            margin: 0 !important;
        }
        /* Republic top 0.34; box ends 0.12 above the blue line */
        .hdr-text-cell {
            position: absolute !important;
            top: 0.34in !important;
            left: calc(0.5in + 0.64in + 0.09in) !important; /* 0.5 + logo + 0.09 gap */
            right: 1.6in !important;
            height: calc(0.28in + 0.66in + 0.06in - 0.34in - 0.12in) !important; /* → line − 0.12 */
            margin: 0 !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            line-height: 1.1 !important;
            z-index: 1;
            box-sizing: border-box !important;
        }
        .hdr-republic {
            font-family: Verdana, Geneva, sans-serif;
            font-size: 10pt;
            font-weight: 400;
            color: #000;
            line-height: 1.1;
            margin: 0 !important;
            padding: 0 !important;
        }
        .hdr-name {
            font-family: 'Arial Rounded MT Bold', 'Arial Rounded MT', 'Arial Rounded', Arial, sans-serif;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #000;
            line-height: 1.1;
            margin: 0 !important;
            padding: 0 !important;
        }
        .hdr-location {
            font-family: Verdana, Geneva, sans-serif;
            font-size: 9pt;
            font-weight: 400;
            color: #000;
            line-height: 1.1;
            margin: 0 !important;
            padding: 0 !important;
        }
        /* Blue line + code: ------------------ CSPC-F-DCC-05 (code centered on the line) */
        .hdr-rule {
            position: absolute !important;
            left: 0.36in !important;
            right: 0.39in !important;
            top: 0.90in !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.11in !important;
            margin: 0 !important;
            padding: 0 !important;
            z-index: 3;
            height: 12pt !important;
        }
        .hdr-rule-line {
            flex: 1 1 auto;
            min-width: 0;
            height: 2px !important;
            background: #0070C0 !important;
            border: none !important;
            margin: 0 !important;
            padding: 0 !important;
            align-self: center !important;
        }
        .hdr-rule-code {
            flex: 0 0 auto;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: 700;
            color: #000;
            line-height: 1 !important;
            white-space: nowrap;
            margin: 0 !important;
            padding: 0 !important;
            background: #fff;
        }

        /* After header line → title: 10pt + 12pt; title → doc title: 12pt */
        .rpt-title {
            text-align: center;
            margin: calc(10pt + 12pt) 0.70in 12pt 0.76in;
            line-height: 1;
        }
        .rpt-title h2 {
            font-family: 'Arial Narrow', Arial, Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: 700;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            line-height: 1;
        }
        /* Doc title → table: 12pt; title text has bottom rule only (no text-decoration underline) */
        .doc-title-row {
            display: flex;
            align-items: baseline;
            gap: 4px;
            margin: 0 0.70in 12pt 0.76in;
            font-family: 'Arial Narrow', Arial, Helvetica, sans-serif;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1;
            color: #000;
        }
        .doc-title-row .label {
            font-weight: 700;
            white-space: nowrap;
        }
        .doc-title-row .value {
            flex: 1;
            min-width: 0;
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid #000;
            min-height: 1em;
            padding: 0 1px 1px;
            line-height: 1;
        }

        /* Page 2+: same gap after header line as page 1 (10pt + 12pt), no form title / table header */
        .cont-spacer {
            height: calc(10pt + 12pt);
            margin: 0 0.70in 0 0.76in;
        }

        .data-table {
            width: calc(8.5in - 0.76in - 0.70in);
            margin-left: 0.76in;
            margin-right: 0.70in;
            border-collapse: collapse;
            border: 1.25px solid #000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            table-layout: fixed;
            background: #fff;
            line-height: 1;
        }
        /* DISTRIBUTION 5.19in + RETRIEVAL 1.87in (scaled into 7.04in content width) */
        .data-table col.col-dept { width: 2.38in; }
        .data-table col.col-sig { width: 1.13in; }
        .data-table col.col-date { width: 1.06in; }
        .data-table col.col-copies { width: 0.63in; }
        .data-table col.col-by { width: 1.13in; }
        .data-table col.col-ret-date { width: 0.74in; }
        .data-table th,
        .data-table td {
            border: 1px solid #000;
            padding: 3px 3px;
            text-align: center;
            vertical-align: middle;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: 400;
            color: #000;
            line-height: 1;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .data-table thead tr.group-header th {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            font-weight: 700;
            background: #fff;
            height: 0.65in;
            padding: 4px;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }
        /* Sub-header = 3 × 11pt: top space + 11pt text + bottom space */
        .data-table thead tr.sub-header {
            height: calc(11pt * 3) !important;
            max-height: calc(11pt * 3) !important;
        }
        .data-table thead tr.sub-header th {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            background: #fff !important;
            height: calc(11pt * 3) !important;
            min-height: calc(11pt * 3) !important;
            max-height: calc(11pt * 3) !important;
            padding: 0 !important;
            margin: 0 !important;
            white-space: nowrap !important;
            text-align: center !important;
            vertical-align: middle !important;
            line-height: calc(11pt * 3) !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
        }
        .data-table thead tr.sub-header th .sub-h {
            display: block !important;
            width: 100% !important;
            height: calc(11pt * 3) !important;
            min-height: calc(11pt * 3) !important;
            max-height: calc(11pt * 3) !important;
            margin: 0 !important;
            padding: 0 !important;
            text-align: center !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: calc(11pt * 3) !important;
            white-space: nowrap !important;
            overflow: hidden !important;
        }
        .data-table tbody td {
            height: 0.28in;
            min-height: 0.28in;
        }
        .data-table tbody td.col-dept {
            text-align: left;
            padding-left: 6px;
            padding-right: 4px;
            vertical-align: middle;
        }

        .sheet-footer {
            flex: 0 0 auto;
            margin-top: auto;
            margin-left: 0.59in;
            margin-right: 0.36in;
            background: #fff;
            padding-top: 8px;
            width: calc(8.5in - 0.59in - 0.36in);
        }
        .sheet-footer-line {
            border-top: 2px solid #0070C0;
            margin: 0;
        }
        .sheet-footer-inner {
            padding: 4px 0 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1;
            color: #000;
        }
        .ft-table { width: 100%; border-collapse: collapse; }
        .ft-table td {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1;
            color: #000;
            vertical-align: middle;
            padding: 0;
        }
        .ft-l { text-align: left; width: 40%; }
        .ft-c { text-align: center; width: 20%; }
        .ft-r { text-align: right; width: 40%; }
        .ft-table strong { font-weight: 700; }

        body.is-embed { background: #fff; }
        body.is-embed .sheet {
            margin: 0 auto 12px;
            box-shadow: none;
        }

        @media print {
            @page {
                size: letter portrait;
                margin: 0;
            }
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet {
                width: 8.5in;
                height: 11in;
                min-height: 11in;
                max-height: 11in;
                margin: 0;
                padding: 0 0 0.28in;
                box-shadow: none;
                overflow: hidden;
                page-break-inside: avoid;
                break-inside: avoid;
                page-break-after: always;
                break-after: page;
            }
            .sheet:last-child {
                page-break-after: auto;
                break-after: auto;
            }
            .hdr-band {
                height: calc(0.28in + 0.66in + 0.06in) !important;
                min-height: calc(0.28in + 0.66in + 0.06in) !important;
                max-height: calc(0.28in + 0.66in + 0.06in) !important;
            }
            .hdr-logo-cell {
                top: 0.28in !important;
                left: 0.5in !important;
                width: 0.64in !important;
                height: 0.66in !important;
            }
            .hdr-logo-wrap,
            .hdr-logo {
                width: 0.64in !important;
                height: 0.66in !important;
                min-width: 0.64in !important;
                min-height: 0.66in !important;
            }
            .hdr-logo {
                max-width: none !important;
                max-height: none !important;
                object-fit: fill !important;
            }
            .hdr-text-cell {
                top: 0.34in !important;
                left: calc(0.5in + 0.64in + 0.09in) !important;
                height: calc(0.28in + 0.66in + 0.06in - 0.34in - 0.12in) !important;
                margin: 0 !important;
            }
            .hdr-rule {
                /* top is controlled only by the main .hdr-rule rule above — change it there */
                margin: 0 !important;
            }
            .data-table thead tr.sub-header {
                height: calc(11pt * 3) !important;
                max-height: calc(11pt * 3) !important;
            }
            .data-table thead tr.sub-header th,
            .data-table thead tr.sub-header th .sub-h {
                height: calc(11pt * 3) !important;
                min-height: calc(11pt * 3) !important;
                max-height: calc(11pt * 3) !important;
                font-size: 11pt !important;
                line-height: calc(11pt * 3) !important;
                padding: 0 !important;
                margin: 0 !important;
                text-align: center !important;
                vertical-align: middle !important;
                overflow: hidden !important;
            }
            .data-table thead tr.sub-header th .sub-h {
                display: block !important;
            }
        }
    </style>
</head>
<body class="{{ !empty($embed) ? 'is-embed' : '' }}">
    @if(empty($embed))
    <div class="print-toolbar" id="toolbar">
        <button class="btn-print" type="button" id="btnPrint">Print</button>
        <button class="btn-close" type="button" onclick="window.close()">Close</button>
    </div>
    @endif

    @php
        $rows = collect($offices ?? [])->values()->map(function ($office) {
            return [
                'name' => is_array($office) ? (string) ($office['name'] ?? '') : (string) $office,
                'copies' => is_array($office) ? ($office['copies'] ?? '') : '',
            ];
        });

        // Page 1: header band + titles + group(0.65) + sub(3×11pt) + footer; ~0.28in/row.
        // Leave a little room for wrapped department names.
        $page1Capacity = 24;
        $contCapacity = 30;

        $pageChunks = [];
        $remaining = $rows->all();
        $pageChunks[] = array_splice($remaining, 0, $page1Capacity);
        while (count($remaining) > 0) {
            $pageChunks[] = array_splice($remaining, 0, $contCapacity);
        }

        // Do not pad empty rows — blank space under a short last page is fine;
        // padding was leaving large empty table areas / forcing early page breaks.

        $totalPages = max(1, count($pageChunks));
        $documentTitle = trim((string) ($documentTitle ?? ''));
        $footerEffectivityFixed = 'August 2019';
        $footerRevFixed = '2';

        $colgroup = '
            <colgroup>
                <col class="col-dept">
                <col class="col-sig">
                <col class="col-date">
                <col class="col-copies">
                <col class="col-by">
                <col class="col-ret-date">
            </colgroup>';
    @endphp

    @foreach($pageChunks as $pageIndex => $pageRows)
        @php $pageNo = $pageIndex + 1; @endphp
        <div class="sheet{{ $pageIndex === 0 ? ' sheet--page1' : '' }}">
            <div class="sheet-body">
                <div class="sheet-main">
                    {{-- Every page keeps the institutional header; only page 1 has form title + table header --}}
                    <div class="{{ $pageIndex === 0 ? 'form-header' : 'cont-header' }}">
                        <div class="hdr-band" style="position:relative;width:8.5in;height:calc(0.28in + 0.66in + 0.06in);margin:0;padding:0">
                            <div class="hdr-logo-cell" style="position:absolute;top:0.28in;left:0.5in;width:0.64in;height:0.66in;margin:0;padding:0">
                                @if(!empty($logoSrc))
                                    <span class="hdr-logo-wrap" style="display:block;width:0.64in;height:0.66in;overflow:hidden;line-height:0">
                                        <img src="{{ $logoSrc }}" alt="" class="hdr-logo" style="display:block;width:0.64in;height:0.66in;object-fit:fill;margin:0">
                                    </span>
                                @endif
                            </div>
                            <div class="hdr-text-cell" style="position:absolute;top:0.34in;left:calc(0.5in + 0.64in + 0.09in);right:1.6in;height:calc(0.28in + 0.66in + 0.06in - 0.34in - 0.12in);margin:0;padding:0;display:flex;flex-direction:column;justify-content:space-between">
                                <div class="hdr-republic" style="margin:0;padding:0">{{ $republic }}</div>
                                <div class="hdr-name" style="margin:0;padding:0">{{ $institutionName }}</div>
                                <div class="hdr-location" style="margin:0;padding:0">{{ $institutionAddress }}</div>
                            </div>
                            <div class="hdr-rule" style="position:absolute;left:0.36in;right:0.39in;display:flex;align-items:center;gap:0.11in;margin:0;padding:0;height:12pt">
                                <div class="hdr-rule-line" aria-hidden="true"></div>
                                <span class="hdr-rule-code">{{ $letterNumber }}</span>
                            </div>
                        </div>

                        @if($pageIndex === 0)
                            <div class="rpt-title"><h2>{{ $title }}</h2></div>
                            <div class="doc-title-row">
                                <span class="label">Title of Document:</span>
                                <span class="value">@if($documentTitle !== ''){{ $documentTitle }}@else&nbsp;@endif</span>
                            </div>
                        @else
                            <div class="cont-spacer" aria-hidden="true"></div>
                        @endif
                    </div>

                    <table class="data-table">
                        {!! $colgroup !!}
                        @if($pageIndex === 0)
                            <thead>
                                <tr class="group-header">
                                    <th colspan="4">DISTRIBUTION</th>
                                    <th colspan="2">RETRIEVAL</th>
                                </tr>
                                <tr class="sub-header" style="height:calc(11pt * 3);max-height:calc(11pt * 3)">
                                    <th class="col-dept" style="height:calc(11pt * 3);max-height:calc(11pt * 3);padding:0;font-size:11pt;line-height:calc(11pt * 3);text-align:center;vertical-align:middle"><div class="sub-h">Department</div></th>
                                    <th style="height:calc(11pt * 3);max-height:calc(11pt * 3);padding:0;font-size:11pt;line-height:calc(11pt * 3);text-align:center;vertical-align:middle"><div class="sub-h">Signature</div></th>
                                    <th style="height:calc(11pt * 3);max-height:calc(11pt * 3);padding:0;font-size:11pt;line-height:calc(11pt * 3);text-align:center;vertical-align:middle"><div class="sub-h">Date</div></th>
                                    <th class="col-copies" style="height:calc(11pt * 3);max-height:calc(11pt * 3);padding:0;font-size:11pt;line-height:calc(11pt * 3);text-align:center;vertical-align:middle"><div class="sub-h">Copies</div></th>
                                    <th style="height:calc(11pt * 3);max-height:calc(11pt * 3);padding:0;font-size:11pt;line-height:calc(11pt * 3);text-align:center;vertical-align:middle"><div class="sub-h">By</div></th>
                                    <th style="height:calc(11pt * 3);max-height:calc(11pt * 3);padding:0;font-size:11pt;line-height:calc(11pt * 3);text-align:center;vertical-align:middle"><div class="sub-h">Date</div></th>
                                </tr>
                            </thead>
                        @endif
                        <tbody>
                            @foreach($pageRows as $office)
                                <tr>
                                    <td class="col-dept">{{ $office['name'] }}</td>
                                    <td></td>
                                    <td></td>
                                    <td>{{ $office['copies'] !== '' && $office['copies'] !== null ? $office['copies'] : '' }}</td>
                                    <td></td>
                                    <td></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="sheet-footer">
                    <div class="sheet-footer-line"></div>
                    <div class="sheet-footer-inner">
                        <table class="ft-table"><tr>
                            <td class="ft-l">Effectivity Date: <strong>{{ $footerEffectivityFixed }}</strong></td>
                            <td class="ft-c">Rev. <strong>{{ $footerRevFixed }}</strong></td>
                            <td class="ft-r">Page {{ $pageNo }} of {{ $totalPages }}</td>
                        </tr></table>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @if(empty($embed))
            document.getElementById('toolbar')?.classList.add('visible');
            document.getElementById('btnPrint')?.addEventListener('click', function () {
                window.print();
            });
            @endif
        });
    </script>
</body>
</html>

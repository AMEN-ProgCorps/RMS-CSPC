<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
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
        .print-container {
            max-width: 794px;
            margin: 0 auto;
            padding: 24px 36px 64px;
            position: relative;
            z-index: 1;
        }
        .hdr-table { width: 100%; border-collapse: collapse; }
        .hdr-table td { padding: 0; vertical-align: middle; }
        .hdr-logo { width: 72px; height: 72px; object-fit: contain; display: block; }
        .hdr-republic,
        .hdr-name,
        .hdr-location {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            line-height: 1.25;
        }
        .hdr-name { font-weight: 700; text-transform: uppercase; margin: 1px 0; }
        .hdr-line {
            position: relative;
            margin: 6px 0 14px;
            border-top: 2px solid #0071BC;
            height: 0;
        }
        .hdr-line span {
            position: absolute;
            right: 0;
            top: -0.65em;
            background: #fff;
            padding-left: 8px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            font-weight: 700;
            color: #000;
            line-height: 1;
            white-space: nowrap;
        }
        .rpt-title {
            text-align: center;
            margin: 0 0 14px;
        }
        .rpt-title h2 {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            font-weight: 700;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .doc-title-row {
            display: flex;
            align-items: baseline;
            gap: 4px;
            margin: 0 0 12px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            color: #000;
        }
        .doc-title-row .label {
            font-weight: 700;
            white-space: nowrap;
        }
        .doc-title-row .value {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 1.15em;
            padding: 0 2px 1px;
            font-weight: 400;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            table-layout: fixed;
            background: #fff;
        }
        .data-table col.col-dept { width: 38%; }
        .data-table col.col-sig { width: 15%; }
        .data-table col.col-date { width: 12%; }
        .data-table col.col-copies { width: 9%; }
        .data-table col.col-by { width: 13%; }
        .data-table col.col-ret-date { width: 13%; }
        .data-table th,
        .data-table td {
            border: 1px solid #000;
            padding: 4px 3px;
            text-align: center;
            vertical-align: middle;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .data-table thead th {
            font-weight: 700;
            background: #fff;
            height: 22px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .data-table thead th.col-copies {
            white-space: nowrap;
            padding-left: 2px;
            padding-right: 2px;
        }
        .data-table tbody td {
            height: 24px;
            font-weight: 700;
        }
        .data-table tbody td.col-dept,
        .data-table thead th.col-dept {
            text-align: left;
            padding-left: 6px;
            padding-right: 4px;
        }
        .data-table tbody td.col-dept {
            font-weight: 700;
            text-align: left;
        }
        .rpt-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            z-index: 2;
        }
        .rpt-footer-line {
            border-top: 2px solid #0071BC;
            margin: 0 36px;
        }
        .rpt-footer-inner {
            padding: 4px 36px 10px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
        }
        .ft-table { width: 100%; border-collapse: collapse; }
        .ft-table td {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            vertical-align: middle;
            padding: 0;
        }
        .ft-l { text-align: left; width: 38%; }
        .ft-c { text-align: center; width: 24%; }
        .ft-r { text-align: right; width: 38%; }
        .ft-table strong { font-weight: 700; }
        body.has-letterhead {
            background: #fff url('{{ $letterheadUrl ?? '' }}') no-repeat center top;
            background-size: 100% 100%;
        }
        body.has-letterhead .print-container {
            padding: 210px 48px 110px;
            max-width: none;
        }
        body.has-letterhead .form-header { display: none; }
        body.has-letterhead .rpt-footer { display: none; }
        @media print {
            .print-toolbar { display: none !important; }
            .print-container { padding: 12px 20px 48px; max-width: none; }
            .rpt-footer { position: fixed; bottom: 0; }
            .rpt-footer-line { margin: 0 20px; }
            .rpt-footer-inner { padding: 4px 20px 8px; }
            @page { size: A4; margin: 12mm 10mm; }
            body.has-letterhead .print-container { padding: 210px 48px 110px; }
        }
    </style>
</head>
<body class="{{ !empty($letterheadUrl) ? 'has-letterhead' : '' }}">
    <div class="print-toolbar" id="toolbar">
        <button class="btn-print" type="button" id="btnPrint">Print</button>
        <button class="btn-close" type="button" onclick="window.close()">Close</button>
    </div>

    @php
        $rows = collect($offices ?? [])->values();
        $minRows = 22;
        while ($rows->count() < $minRows) {
            $rows->push(['name' => '', 'copies' => '']);
        }
        $documentTitle = trim((string) ($documentTitle ?? ''));
    @endphp

    <div class="print-container">
        <div class="form-header">
            <table class="hdr-table">
                <tr>
                    <td style="width:84px; padding-right:10px;">
                        @if(!empty($logoSrc))
                            <img src="{{ $logoSrc }}" alt="" class="hdr-logo">
                        @endif
                    </td>
                    <td>
                        <div class="hdr-republic">{{ $republic }}</div>
                        <div class="hdr-name">{{ $institutionName }}</div>
                        <div class="hdr-location">{{ $institutionAddress }}</div>
                    </td>
                </tr>
            </table>
            <div class="hdr-line"><span>{{ $letterNumber }}</span></div>
            <div class="rpt-title"><h2>{{ $title }}</h2></div>
        </div>

        <div class="doc-title-row">
            <span class="label">Title of Document:</span>
            <span class="value">{{ $documentTitle }}</span>
        </div>

        <table class="data-table">
            <colgroup>
                <col class="col-dept">
                <col class="col-sig">
                <col class="col-date">
                <col class="col-copies">
                <col class="col-by">
                <col class="col-ret-date">
            </colgroup>
            <thead>
                <tr>
                    <th colspan="4">DISTRIBUTION</th>
                    <th colspan="2">RETRIEVAL</th>
                </tr>
                <tr>
                    <th class="col-dept">Department</th>
                    <th>Signature</th>
                    <th>Date</th>
                    <th class="col-copies">Copies</th>
                    <th>By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $office)
                    @php
                        $name = is_array($office) ? ($office['name'] ?? '') : (string) $office;
                        $copies = is_array($office) ? ($office['copies'] ?? '') : '';
                    @endphp
                    <tr>
                        <td class="col-dept">{{ $name }}</td>
                        <td></td>
                        <td></td>
                        <td>{{ $copies !== '' && $copies !== null ? $copies : '' }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if(empty($letterheadUrl))
    <div class="rpt-footer">
        <div class="rpt-footer-line"></div>
        <div class="rpt-footer-inner">
            <table class="ft-table"><tr>
                <td class="ft-l">Effectivity Date: <strong>{{ ($footerEffectivity ?? '') !== '' ? $footerEffectivity : '—' }}</strong></td>
                <td class="ft-c">Rev. <strong>{{ ($footerRev ?? '') !== '' ? $footerRev : '—' }}</strong></td>
                <td class="ft-r">Page <strong>1</strong> of <strong>1</strong></td>
            </tr></table>
        </div>
    </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('toolbar')?.classList.add('visible');
            document.getElementById('btnPrint')?.addEventListener('click', function () {
                var markup = document.documentElement.outerHTML
                    .replace(/<title>[^<]*<\/title>/i, '<title></title>')
                    .replace('print-toolbar visible', 'print-toolbar');
                var w = window.open('', '_blank');
                if (!w) { window.print(); return; }
                w.document.open();
                w.document.write(markup);
                w.document.close();
                w.focus();
                setTimeout(function () { w.print(); }, 400);
            });
        });
    </script>
</body>
</html>

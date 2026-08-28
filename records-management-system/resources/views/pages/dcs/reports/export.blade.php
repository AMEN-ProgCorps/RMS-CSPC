<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ !empty($autoPrint) || !empty($embed) ? '' : ($title ?? 'Report') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #000;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Toolbar ── */
        .print-toolbar { display: none; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 24px; justify-content: center; gap: 10px; position: sticky; top: 0; z-index: 100; }
        .print-toolbar.visible { display: flex; }
        .print-toolbar button { padding: 8px 20px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .print-toolbar .btn-pdf { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-pdf:hover { background: #0b2368; }
        .print-toolbar .btn-print { background: #fff; color: #0d2a7a; border-color: #0d2a7a; }
        .print-toolbar .btn-print:hover { background: #eef2ff; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; border-color: #e2e8f0; }
        .print-toolbar .btn-close:hover { background: #f8fafc; }

        /* ── Container ── */
        .print-container { max-width: 1100px; margin: 0 auto; padding: 24px 36px 60px; }
        body.is-opcr .print-container { max-width: 1400px; }

        /* ── Header (CSPC masterlist letterhead) ── */
        .hdr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .hdr-table, .hdr-table tr, .hdr-table td { border: none !important; background: none !important; }
        .hdr-table td { padding: 0; text-align: left; }
        .hdr-brand-row td { vertical-align: middle; }
        .hdr-logo-cell {
            width: 1%;
            white-space: nowrap;
            padding-right: 8px;
            font-size: 10pt;
            line-height: 1.25;
        }
        .hdr-text-cell {
            font-size: 10pt;
            line-height: 1.25;
        }
        .hdr-logo {
            height: 70pt;
            width: auto;
            object-fit: contain;
            object-position: center center;
            display: block;
        }
        .hdr-republic,
        .hdr-name,
        .hdr-location {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #000;
            line-height: 1.25;
            white-space: nowrap;
        }
        .hdr-name { font-weight: 700; text-transform: uppercase; margin: 1px 0; }
        .hdr-line-row td {
            padding-top: 2px;
            vertical-align: top;
        }
        .hdr-line {
            position: relative;
            margin: 0 0 10px;
            border-top: 2px solid #0071BC;
            height: 0;
        }
        .hdr-line span {
            position: absolute;
            right: 0;
            top: -0.6em;
            background: #fff;
            padding-left: 8px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            font-weight: 700;
            color: #000;
            line-height: 1;
            white-space: nowrap;
        }
        .hdr-line--plain span { display: none; }

        /* ── Title ── */
        .rpt-title { text-align: center; margin-bottom: 12px; }
        .rpt-title h2 {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16pt;
            font-weight: 700;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .rpt-period { text-align: center; margin-bottom: 12px; font-size: 11px; color: #64748b; }
        body.is-opcr .rpt-period { display: none !important; }
        body.is-opcr .rpt-filters { display: none !important; }

        /* ── Checkboxes ── */
        .rpt-filters { text-align: center; margin-bottom: 16px; }
        .rpt-fi {
            display: inline-block;
            margin: 0 10px 6px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: 500;
            color: #000;
            vertical-align: middle;
        }
        .rpt-cb {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1.5px solid #333;
            vertical-align: middle;
            margin-right: 4px;
            background: #fff;
            text-align: center;
            line-height: 12px;
            font-size: 10pt;
            color: #333;
        }

        /* ── Table ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-family: Arial, sans-serif;
            font-size: 10pt;
        }
        .data-table th {
            background: #8DB4E2;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-family: Arial, sans-serif;
            font-size: 10pt;
            font-weight: 700;
            text-transform: none;
            letter-spacing: normal;
            color: #000;
            padding: 6px 4px;
            border: 1px solid #000;
            text-align: center;
            vertical-align: middle;
            line-height: 1.15;
        }
        .data-table thead tr:nth-child(2) th {
            font-size: 10pt;
            font-weight: 700;
            padding: 5px 4px;
        }
        .data-table td {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            padding: 6px 4px;
            border: 1px solid #000;
            vertical-align: middle;
            color: #000;
            text-align: center;
        }
        .data-table tr:nth-child(even) td { background: #fff; }
        .rpt-na { color: #000; }
        .empty-msg { text-align: center; padding: 24px; color: #000; font-style: italic; font-size: 10pt; font-family: Arial, sans-serif; }

        /* ── Footer ── */
        .rpt-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 0;
        }
        .rpt-footer-line { border-top: 2px solid #0d2a7a; margin: 0 36px; }
        .rpt-footer-inner { padding: 4px 36px; }
        .ft-table { width: 100%; border-collapse: collapse; }
        .ft-table, .ft-table tr, .ft-table td { border: none !important; background: none !important; }
        .ft-table td { padding: 0; font-size: 11px; font-family: Arial, Helvetica, sans-serif; vertical-align: top; }
        .ft-l { text-align: left; }
        .ft-c { text-align: center; }
        .ft-r { text-align: right; }

        /* Letterhead as page background (works in print + Dompdf better than fixed img) */
        body.has-letterhead {
            @if(!empty($letterheadUrl))
            background: #fff url('{{ $letterheadUrl }}') no-repeat center top;
            background-size: 100% 100%;
            @else
            background: #fff;
            @endif
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body.has-letterhead .print-container {
            position: relative;
            z-index: 1;
            padding: 210px 36px 110px;
        }
        body.has-letterhead .rpt-footer { display: none; }

        /* ── DOMPDF / PRINT ── */
        @page { margin: 12mm 10mm 18mm 10mm; }

        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 8mm 14mm 8mm;
            }
            .print-toolbar { display: none !important; }
            body { padding: 0; }
            .print-container { padding: 0 0 10px 0; max-width: 100%; }
            body.has-letterhead .print-container { padding: 200px 28px 100px; }
            .data-table,
            .data-table th,
            .data-table td { font-family: Arial, sans-serif; font-size: 10pt; }
            .rpt-footer { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; }
            .rpt-period { display: none !important; }
        }
    </style>
</head>
<body class="{{ trim(implode(' ', array_filter([
    !empty($letterheadUrl) ? 'has-letterhead' : null,
    (($activeCategory ?? '') === 'opcr') ? 'is-opcr' : null,
]))) }}">

    <div class="print-toolbar{{ empty($embed) ? '' : ' rpt-embed-hidden' }}" id="toolbar">
        <button class="btn-pdf" type="button" id="btnPdf"><i class="fa-solid fa-file-pdf"></i> Save as PDF</button>
        <button class="btn-print" type="button" id="btnPrint"><i class="fa-solid fa-print"></i> Print</button>
        <button class="btn-close" type="button" id="btnClose">Close</button>
    </div>

    <div class="print-container">

        {{-- HEADER --}}
        @if(empty($letterheadUrl))
        @php
            $logoPath = public_path('images/logo.png');
            $logoSrc = file_exists($logoPath) ? ('data:image/png;base64,' . base64_encode(file_get_contents($logoPath))) : '';
        @endphp
        <table class="hdr-table" cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr class="hdr-brand-row">
                <td class="hdr-logo-cell" valign="middle">@if($logoSrc)<img src="{{ $logoSrc }}" alt="" class="hdr-logo" height="70">@endif</td>
                <td class="hdr-text-cell" valign="middle">
                    <div class="hdr-republic">{{ $republic ?? 'Republic of the Philippines' }}</div>
                    <div class="hdr-name">{{ $institutionName ?? 'Camarines Sur Polytechnic Colleges' }}</div>
                    <div class="hdr-location">{{ $institutionAddress ?? 'Nabua, Camarines Sur' }}</div>
                </td>
            </tr>
            <tr class="hdr-line-row">
                <td colspan="2">
                    <div class="hdr-line @if(($activeCategory ?? '') === 'opcr') hdr-line--plain @endif">
                        @if(($activeCategory ?? '') !== 'opcr')
                            <span>{{ $letterNumber ?? 'CSPC-F-DCC-03' }}</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
        @endif

        {{-- TITLE --}}
        <div class="rpt-title"><h2>{{ $title ?? 'Document Masterlist' }}</h2></div>

        @if(($activeCategory ?? '') !== 'opcr' && (!empty($dateFrom) || !empty($dateTo) || !empty($asOf)))
            <div class="rpt-period">
                @if(!empty($periodLabel) && ($period ?? '') !== 'custom')
                    <strong>{{ $periodLabel }}</strong> report
                    @if(!empty($asOf))
                        (as of {{ \Carbon\Carbon::parse($asOf)->format('M d, Y') }})
                    @endif
                    @if(!empty($dateFrom) && !empty($dateTo))
                        — {{ \Carbon\Carbon::parse($dateFrom)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($dateTo)->format('M d, Y') }}
                    @endif
                @elseif(!empty($dateFrom) || !empty($dateTo))
                    Period: {{ $dateFrom ? \Carbon\Carbon::parse($dateFrom)->format('M d, Y') : '—' }}
                    – {{ $dateTo ? \Carbon\Carbon::parse($dateTo)->format('M d, Y') : '—' }}
                @elseif(!empty($asOf))
                    As of {{ \Carbon\Carbon::parse($asOf)->format('M d, Y') }}
                @endif
            </div>
        @endif

        {{-- CHECKBOXES — doc type tabs --}}
        @php
            $checklists = [
                'masterlist' => ['internal_docs'=>'Internal','external_docs'=>'External','internal_forms'=>'Internal Forms','forms'=>'Forms','logbooks'=>'Logbooks'],
                'monitoring' => ['internal_docs'=>'Internal','external_docs'=>'External','internal_forms'=>'Internal Forms','forms'=>'Forms','logbooks'=>'Logbooks','drf'=>'DRF','dcn'=>'DCN'],
                'opcr' => ['update_masterlist'=>'Updating of Masterlist','issuance_internal'=>'Issuance of Controlled Internal Documents','issuance_external'=>'Issuance of Controlled External Documents','control_forms'=>'Controlling of Forms','control_logbooks'=>'Controlling of Logbooks','control_internal_forms'=>'Controlling of Internal Forms'],
            ];
            $activeCat = $activeCategory ?? null;
            $activeSub = $activeSub ?? null;
        @endphp

        @if(($activeCategory ?? '') !== 'opcr' && $activeCat && isset($checklists[$activeCat]))
            <div class="rpt-filters">
                @foreach($checklists[$activeCat] as $key => $label)
                    <span class="rpt-fi">
                        <span class="rpt-cb">{!! ($activeSub === $key) ? '/' : '' !!}</span>
                        {{ $label }}
                    </span>
                @endforeach
            </div>
        @endif

        @if(($activeCategory ?? '') !== 'opcr' && !empty($selectedSubTypeNames))
            <div class="rpt-filters" style="margin-top:8px;">
                @foreach($selectedSubTypeNames as $subName)
                    <span class="rpt-fi">
                        <span class="rpt-cb">/</span>
                        {{ $subName }}
                    </span>
                @endforeach
            </div>
        @endif

        {{-- TABLE --}}
        @php
            $visCols = [];
            foreach ($columns as $k => $v) { if ($k !== 'pdf_path') $visCols[$k] = $v; }
            $colN = count($visCols);
            $keys = array_keys($visCols);
            $groups = $groupHeaders ?? ($group_headers ?? []);
            $hasGroups = collect($groups)->contains(fn ($g) => $g !== null && $g !== '');
        @endphp
        <table class="data-table">
            <thead>
                @if($hasGroups)
                    <tr>
                        @php $i = 0; @endphp
                        @while($i < count($keys))
                            @php
                                $key = $keys[$i];
                                $group = $groups[$key] ?? null;
                            @endphp
                            @if($group === null || $group === '')
                                <th rowspan="2">{!! $visCols[$key] !!}</th>
                                @php $i++; @endphp
                            @else
                                @php
                                    $span = 1;
                                    while ($i + $span < count($keys) && ($groups[$keys[$i + $span]] ?? null) === $group) {
                                        $span++;
                                    }
                                @endphp
                                <th colspan="{{ $span }}">{!! $group !!}</th>
                                @php $i += $span; @endphp
                            @endif
                        @endwhile
                    </tr>
                    <tr>
                        @foreach($keys as $key)
                            @if(($groups[$key] ?? null) !== null && ($groups[$key] ?? null) !== '')
                                <th>{!! $visCols[$key] !!}</th>
                            @endif
                        @endforeach
                    </tr>
                @else
                    <tr>@foreach($visCols as $h)<th>{!! $h !!}</th>@endforeach</tr>
                @endif
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        @foreach($keys as $k)
                            @php
                                $v = is_array($row)
                                    ? ($row[$k] ?? null)
                                    : (is_object($row) ? ($row->{$k} ?? null) : null);
                            @endphp
                            @if($v !== null && $v !== '')<td>{{ $v }}</td>@else<td class="rpt-na">&mdash;</td>@endif
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ $colN }}" class="empty-msg">No records found for the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>

    </div>

    {{-- FOOTER --}}
    @if(empty($isPdf) && empty($letterheadUrl))
    <div class="rpt-footer" id="rptFooter">
        <div class="rpt-footer-line"></div>
        <div class="rpt-footer-inner">
            <table class="ft-table"><tr>
                <td class="ft-l" style="width:33%;">{{ $footerLeft ?? 'Effectivity Date:' }}</td>
                <td class="ft-c" style="width:34%;">{{ $footerCenter ?? 'Rev.' }}</td>
                <td class="ft-r" id="pageCounter" style="width:33%;"></td>
            </tr></table>
        </div>
    </div>
    @endif
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var t = document.getElementById('toolbar');
            if (t && !t.classList.contains('rpt-embed-hidden')) t.classList.add('visible');
            var pc = document.getElementById('pageCounter');
            if (pc) {
                var isOpcr = document.body.classList.contains('is-opcr');
                var rows = document.querySelectorAll('.data-table tbody tr').length;
                var empty = document.querySelector('.data-table .empty-msg');
                var total = 1;
                if (isOpcr && !empty && rows > 0) {
                    total = Math.max(1, Math.ceil(rows / 12));
                }
                pc.textContent = 'Page 1 of ' + total;
            }
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
                // Route print through PDF so browser URL/timestamp headers are not injected
                var p = new URLSearchParams(window.location.search);
                p.set('format', 'pdf');
                p.set('inline', '1');
                p.set('autoPrint', '1');
                window.location.href = window.location.pathname + '?' + p.toString();
            });
            var btnClose = document.getElementById('btnClose');
            if (btnClose) btnClose.addEventListener('click', function() { window.close(); });
            if (new URLSearchParams(window.location.search).has('autoPrint') && new URLSearchParams(window.location.search).get('format') !== 'pdf') {
                setTimeout(function() {
                    var p = new URLSearchParams(window.location.search);
                    p.set('format', 'pdf');
                    p.set('inline', '1');
                    p.set('autoPrint', '1');
                    window.location.replace(window.location.pathname + '?' + p.toString());
                }, 50);
            }
        });
    </script>

</body>
</html>

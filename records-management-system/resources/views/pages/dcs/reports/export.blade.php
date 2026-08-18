<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} - CSPC DCS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1e293b;
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

        /* ── Header ── */
        .hdr-table { width: 100%; border-collapse: collapse; }
        .hdr-table, .hdr-table tr, .hdr-table td { border: none !important; background: none !important; }
        .hdr-table td { padding: 0; vertical-align: middle; text-align: left; }
        .hdr-logo { width: 100px; height: 100px; object-fit: contain; }
        .hdr-republic { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .hdr-name { font-size: 11px; font-weight: 700; color: #0d2a7a; text-transform: uppercase; letter-spacing: 0.3px; margin: 2px 0; }
        .hdr-location { font-size: 11px; color: #64748b; }

        .hdr-line { position: relative; margin: 10px 0 16px; border-top: 2px solid #0d2a7a; height: 1px; }
        .hdr-line span { position: absolute; top: -12px; right: 0; background: #fff; padding: 0 0 0 10px; font-size: 11px; font-weight: 700; color: #0d2a7a; }

        /* ── Title ── */
        .rpt-title { text-align: center; margin-bottom: 12px; }
        .rpt-title h2 { font-size: 11px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; }

        /* ── Checkboxes ── */
        .rpt-filters { text-align: center; margin-bottom: 16px; }
        .rpt-fi { display: inline-block; margin: 0 10px; font-size: 11px; font-weight: 500; color: #1e293b; vertical-align: middle; }
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
            font-size: 11px;
            color: #333;
        }

        /* ── Table ── */
        .data-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .data-table th {
            background: #4C94D8;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #1e293b;
            padding: 8px 6px;
            border: 1px solid #000;
            text-align: center;
        }
        .data-table td {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            padding: 7px 6px;
            border: 1px solid #000;
            vertical-align: middle;
            color: #1e293b;
            text-align: center;
        }
        .data-table tr:nth-child(even) td { background: #f8fafc; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .rpt-na { color: #94a3b8; }
        .empty-msg { text-align: center; padding: 24px; color: #94a3b8; font-style: italic; font-size: 11px; }

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
        .letterhead-bg {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: fill;
            z-index: 0;
            pointer-events: none;
        }
        body.has-letterhead .print-container {
            position: relative;
            z-index: 1;
            padding: 210px 36px 110px;
        }
        body.has-letterhead .rpt-footer { display: none; }

        /* ── DOMPDF ── */
        @page { margin: 18mm 15mm 22mm 15mm; }

        /* ── PRINT ── */
        @media print {
            @page { size: A4 portrait; margin: 15mm 15mm 22mm 15mm; }
            .print-toolbar { display: none !important; }
            body { padding: 0; }
            .print-container { padding: 0 0 10px 0; max-width: 100%; }
            .data-table { font-size: 11px; }
            .data-table th { font-size: 11px; padding: 6px 5px; }
            .data-table td { font-size: 11px; padding: 5px; }
            .rpt-footer { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; }
        }
    </style>
</head>
<body class="{{ !empty($letterheadUrl) ? 'has-letterhead' : '' }}">

    @if(!empty($letterheadUrl))
        <img src="{{ $letterheadUrl }}" alt="" class="letterhead-bg">
    @endif

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
        <table class="hdr-table"><tr>
            <td style="width:115px; padding-right:14px;">@if($logoSrc)<img src="{{ $logoSrc }}" alt="" class="hdr-logo">@endif</td>
            <td>
                <div class="hdr-republic">{{ $republic ?? 'Republic of the Philippines' }}</div>
                <div class="hdr-name">{{ $institutionName ?? 'Camarines Sur Polytechnic Colleges' }}</div>
                <div class="hdr-location">{{ $institutionAddress ?? 'Nabua, Camarines Sur' }}</div>
            </td>
        </tr></table>

        <div class="hdr-line"><span>{{ $letterNumber ?? 'CSPC-QA-F001' }}</span></div>
        @endif

        {{-- TITLE --}}
        <div class="rpt-title"><h2>{{ $title ?? 'Document Masterlist' }}</h2></div>

        @if(!empty($dateFrom) || !empty($dateTo) || !empty($asOf))
            <div class="rpt-period" style="text-align:center;margin-bottom:12px;font-size:11px;color:#64748b;">
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

        @if($activeCat && isset($checklists[$activeCat]))
            <div class="rpt-filters">
                @foreach($checklists[$activeCat] as $key => $label)
                    <span class="rpt-fi">
                        <span class="rpt-cb">{!! ($activeSub === $key) ? '/' : '' !!}</span>
                        {{ $label }}
                    </span>
                @endforeach
            </div>
        @endif

        @if(!empty($selectedSubTypeNames))
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
        @endphp
        <table class="data-table">
            <thead><tr>@foreach($visCols as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        @foreach(array_keys($visCols) as $k)
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
            if (pc) pc.textContent = 'Page 1 of 1';
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
            if (btnPrint) btnPrint.addEventListener('click', function() { window.print(); });
            var btnClose = document.getElementById('btnClose');
            if (btnClose) btnClose.addEventListener('click', function() { window.close(); });
            if (new URLSearchParams(window.location.search).has('autoPrint')) {
                setTimeout(function() { window.print(); }, 500);
            }
        });
    </script>

</body>
</html>
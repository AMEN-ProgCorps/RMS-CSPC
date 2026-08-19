<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} - CSPC DCS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1e293b;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-toolbar { display: none; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 24px; justify-content: center; gap: 10px; position: sticky; top: 0; z-index: 100; }
        .print-toolbar.visible { display: flex; }
        .print-toolbar button { padding: 8px 20px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: 600; cursor: pointer; }
        .print-toolbar .btn-print { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; }
        .print-container { max-width: 900px; margin: 0 auto; padding: 24px 36px 70px; position: relative; z-index: 1; }
        .hdr-table { width: 100%; border-collapse: collapse; }
        .hdr-table td { padding: 0; vertical-align: middle; }
        .hdr-logo { width: 100px; height: 100px; object-fit: contain; }
        .hdr-republic { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .hdr-name { font-size: 11px; font-weight: 700; color: #0d2a7a; text-transform: uppercase; letter-spacing: 0.3px; margin: 2px 0; }
        .hdr-location { font-size: 11px; color: #64748b; }
        .hdr-line { position: relative; margin: 10px 0 16px; border-top: 2px solid #0d2a7a; height: 1px; }
        .hdr-line span { position: absolute; top: -12px; right: 0; background: #fff; padding: 0 0 0 10px; font-size: 11px; font-weight: 700; color: #0d2a7a; }
        .rpt-title { text-align: center; margin-bottom: 16px; }
        .rpt-title h2 { font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 11px; background: rgba(255,255,255,0.88); }
        .data-table th {
            background: #4C94D8;
            font-weight: 700;
            text-transform: uppercase;
            color: #1e293b;
            padding: 8px 6px;
            border: 1px solid #000;
            text-align: center;
        }
        .data-table td {
            padding: 8px 6px;
            border: 1px solid #000;
            text-align: center;
        }
        .data-table tr:nth-child(even) td { background: #f8fafc; }
        .rpt-footer { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; }
        .rpt-footer-line { border-top: 2px solid #0d2a7a; margin: 0 36px; }
        .rpt-footer-inner { padding: 4px 36px; }
        .ft-table { width: 100%; }
        .ft-l { text-align: left; }
        .ft-c { text-align: center; }
        .ft-r { text-align: right; }
        body.has-letterhead {
            background: #fff url('{{ $letterheadUrl ?? '' }}') no-repeat center top;
            background-size: 100% 100%;
        }
        body.has-letterhead .print-container {
            padding: 210px 48px 110px;
            max-width: none;
        }
        body.has-letterhead .rpt-title,
        body.has-letterhead .rpt-footer { display: none; }
        @media print {
            .print-toolbar { display: none !important; }
            .print-container { padding: 0 0 24px; }
            .rpt-footer { position: fixed; bottom: 0; }
            @page { size: A4; margin: 0; }
            body.has-letterhead .print-container { padding: 210px 48px 110px; }
        }
    </style>
</head>
<body class="{{ !empty($letterheadUrl) ? 'has-letterhead' : '' }}">
    <div class="print-toolbar" id="toolbar">
        <button class="btn-print" type="button" onclick="window.print()">Print</button>
        <button class="btn-close" type="button" onclick="window.close()">Close</button>
    </div>

    <div class="print-container">
        @if(empty($letterheadUrl))
            <table class="hdr-table"><tr>
                <td style="width:115px; padding-right:14px;">@if($logoSrc)<img src="{{ $logoSrc }}" alt="" class="hdr-logo">@endif</td>
                <td>
                    <div class="hdr-republic">{{ $republic }}</div>
                    <div class="hdr-name">{{ $institutionName }}</div>
                    <div class="hdr-location">{{ $institutionAddress }}</div>
                </td>
            </tr></table>
            <div class="hdr-line"><span>{{ $letterNumber }}</span></div>
            <div class="rpt-title"><h2>{{ $title }}</h2></div>
        @endif

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:90px;">Item No.</th>
                    <th>Office Name</th>
                    <th style="width:160px;">Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($offices as $i => $office)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td style="text-align:left; padding-left:10px;">{{ $office }}</td>
                        <td>{{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">No offices selected.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(empty($letterheadUrl))
    <div class="rpt-footer">
        <div class="rpt-footer-line"></div>
        <div class="rpt-footer-inner">
            <table class="ft-table"><tr>
                <td class="ft-l" style="width:33%;">{{ $footerLeft }}</td>
                <td class="ft-c" style="width:34%;">{{ $footerCenter }}</td>
                <td class="ft-r" style="width:33%;">Page 1 of 1</td>
            </tr></table>
        </div>
    </div>
    @endif
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('toolbar')?.classList.add('visible');
        });
    </script>
</body>
</html>

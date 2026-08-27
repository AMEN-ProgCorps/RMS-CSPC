<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DCN {{ $dcn->dcn_no }} — CSPC-F-DCC-01</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
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
            padding: 16mm 16mm 14mm;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
        }
        .hdr {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }
        .hdr-left { display: flex; align-items: center; gap: 12px; }
        .hdr-logo { width: 72px; height: 72px; object-fit: contain; }
        .hdr-text { line-height: 1.25; }
        .hdr-text .rep { font-size: 11px; }
        .hdr-text .org { font-size: 14px; font-weight: 700; text-transform: uppercase; }
        .hdr-text .loc { font-size: 11px; }
        .hdr-code { font-size: 13px; font-weight: 700; white-space: nowrap; padding-top: 4px; }
        .blue-rule { height: 2.5px; background: #0071BC; margin: 6px 0 12px; }
        .form-title {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .dcn-no { margin-bottom: 10px; font-weight: 600; }
        .dcn-no span { font-weight: 400; border-bottom: 1px solid #000; padding: 0 8px 2px; min-width: 160px; display: inline-block; }
        .meta { margin-bottom: 12px; }
        .meta div { margin-bottom: 6px; }
        .lbl { font-weight: 600; }
        .val { border-bottom: 1px solid #000; display: inline-block; min-width: 40%; padding: 0 4px 2px; }
        table.rev {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 14px;
            font-size: 11px;
        }
        table.rev th, table.rev td {
            border: 1px solid #000;
            padding: 6px 5px;
            text-align: left;
            vertical-align: top;
        }
        table.rev th { font-weight: 700; background: #f8fafc; }
        .block {
            border: 1.5px solid #000;
            padding: 10px;
            margin-bottom: 12px;
            min-height: 48px;
            white-space: pre-wrap;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }
        @media print {
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet { box-shadow: none; margin: 0; width: auto; min-height: auto; padding: 10mm 12mm; }
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>
@php
    $revs = $revisions ?? collect();
    $sources = $sourceOffices ?? collect();
    $sourceText = $sources->pluck('office_name')->filter()->implode(', ');
@endphp
<div class="print-toolbar">
    <button type="button" class="btn-print" onclick="window.print()">Print</button>
    <button type="button" class="btn-close" onclick="window.close()">Close</button>
</div>
<div class="sheet">
    <div class="hdr">
        <div class="hdr-left">
            @if(!empty($logoSrc))
                <img src="{{ $logoSrc }}" alt="" class="hdr-logo">
            @endif
            <div class="hdr-text">
                <div class="rep">Republic of the Philippines</div>
                <div class="org">Camarines Sur Polytechnic Colleges</div>
                <div class="loc">Nabua, Camarines Sur</div>
            </div>
        </div>
        <div class="hdr-code">CSPC-F-DCC-01</div>
    </div>
    <div class="blue-rule"></div>
    <div class="form-title">Document Change Notice</div>
    <div class="dcn-no">DCN # <span>{{ $dcn->dcn_no }}</span></div>

    <div class="meta">
        <div><span class="lbl">DCN Date:</span> <span class="val">{{ $dcn->dcn_date ? \Carbon\Carbon::parse($dcn->dcn_date)->format('F d, Y') : '' }}</span></div>
        <div>
            <span class="lbl">DCN Receipt:</span>
            <span class="val">
                {{ $dcn->dcn_receipt_date ? \Carbon\Carbon::parse($dcn->dcn_receipt_date)->format('F d, Y') : '' }}
                @if($dcn->dcn_receipt_time)
                    {{ \Illuminate\Support\Str::of($dcn->dcn_receipt_time)->substr(0, 5) }}
                @endif
            </span>
        </div>
        <div><span class="lbl">Source Unit:</span> <span class="val" style="min-width:60%;">{{ $sourceText }}</span></div>
    </div>

    <div class="lbl" style="margin-bottom:6px;">Documents for Revision</div>
    <table class="rev">
        <thead>
            <tr>
                <th>Document No.</th>
                <th>Document Title</th>
                <th>Effectivity</th>
                <th>Rev No.</th>
                <th>Brief Purpose</th>
            </tr>
        </thead>
        <tbody>
            @forelse($revs as $rev)
                <tr>
                    <td>{{ $rev->document_no }}</td>
                    <td>{{ $rev->title }}</td>
                    <td>{{ $rev->effectivity_date ? \Carbon\Carbon::parse($rev->effectivity_date)->format('M d, Y') : '' }}</td>
                    <td>{{ $rev->revision_no }}</td>
                    <td>{{ $rev->brief_purpose }}</td>
                </tr>
            @empty
                <tr><td colspan="5">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="lbl" style="margin-bottom:6px;">Justification</div>
    <div class="block">{{ $dcn->brief_purpose }}</div>

    <div class="blue-rule"></div>
    <div class="footer">
        <span>Effectivity Date: January 2018</span>
        <span>Rev. 1</span>
        <span>Page <strong>1</strong> of <strong>1</strong></span>
    </div>
</div>
</body>
</html>

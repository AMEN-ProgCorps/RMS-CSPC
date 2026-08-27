<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DRF {{ $drf->drf_no }} — CSPC-F-DCC-06</title>
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
            padding: 18mm 18mm 16mm;
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
        .blue-rule { height: 3px; background: #0071BC; margin: 6px 0 14px; }
        .form-title {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 18px;
        }
        .row-inline {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 10px;
        }
        .row-inline .grow { flex: 1; }
        .lbl { font-weight: 600; white-space: nowrap; }
        .uline {
            border-bottom: 1px solid #000;
            min-height: 1.15em;
            flex: 1;
            padding: 0 4px 2px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 18px;
            margin-bottom: 14px;
        }
        .meta-grid .uline { display: block; margin-top: 2px; }
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 600;
            margin-top: 24px;
        }
        @media print {
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet { box-shadow: none; margin: 0; width: auto; min-height: auto; padding: 12mm 14mm; }
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>
@php
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
        <div class="hdr-code">CSPC-F-DCC-06</div>
    </div>
    <div class="blue-rule"></div>
    <div class="form-title">Document Request Form</div>

    <div class="meta-grid">
        <div>
            <span class="lbl">DRF No.</span>
            <span class="uline">{{ $drf->drf_no }}</span>
        </div>
        <div>
            <span class="lbl">DRF Date</span>
            <span class="uline">{{ $drf->drf_date ? \Carbon\Carbon::parse($drf->drf_date)->format('F d, Y') : '' }}</span>
        </div>
        <div>
            <span class="lbl">Date Receipt</span>
            <span class="uline">
                {{ $drf->drf_receipt_date ? \Carbon\Carbon::parse($drf->drf_receipt_date)->format('F d, Y') : '' }}
                @if($drf->drf_receipt_time)
                    {{ \Illuminate\Support\Str::of($drf->drf_receipt_time)->substr(0, 5) }}
                @endif
            </span>
        </div>
        <div>
            <span class="lbl">Source Unit</span>
            <span class="uline">{{ $sourceText }}</span>
        </div>
    </div>

    <div class="row-inline">
        <span class="lbl">Document Title :</span>
        <span class="uline">{{ $drf->doc_title }}</span>
    </div>

    @if($drf->scanned_drf)
        <div class="row-inline" style="margin-top:12px;">
            <span class="lbl">Scanned DRF on file:</span>
            <span class="uline">Yes</span>
        </div>
    @endif

    <div class="blue-rule"></div>
    <div class="footer">
        <span>Effectivity Date: January 2018</span>
        <span>Rev.0</span>
        <span>Page: 1 of 1</span>
    </div>
</div>
</body>
</html>

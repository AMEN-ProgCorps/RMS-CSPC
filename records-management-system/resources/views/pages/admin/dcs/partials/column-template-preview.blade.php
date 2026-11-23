{{-- Accurate live preview for report/distribution column templates --}}
@php
    $ths = is_array($tableHeaderStyle ?? null) ? $tableHeaderStyle : [];
    $hdrBg = (string) ($ths['header_row_bg'] ?? '#8db3e2');
    $hdrFg = (string) ($ths['header_row_color'] ?? '#000000');
    $hdrFf = (string) ($ths['header_font_family'] ?? 'Arial');
    $hdrFs = (float) ($ths['header_font_size_pt'] ?? 10);
    $hdrFw = (int) ($ths['header_font_weight'] ?? 700);
    $hdrFi = (string) ($ths['header_font_style'] ?? 'normal');
    $visibleCols = array_values(array_filter(is_array($columns ?? null) ? $columns : [], fn ($c) => is_array($c) && ! empty($c['visible'])));
    $groups = is_array($groupHeaders ?? null) ? $groupHeaders : [];
    $widthIn = (float) ($pageWidth ?? 8.5);
    $heightIn = (float) ($pageHeight ?? 11);
    $logoSrc = (string) ($logoSrc ?? '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Template preview</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: {{ !empty($embed) ? '#fff' : '#e2e8f0' }}; font-family: Arial, Helvetica, sans-serif; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet {
            width: {{ $widthIn }}in;
            min-height: {{ $heightIn }}in;
            margin: {{ !empty($embed) ? '0' : '16px auto' }};
            background: #fff;
            box-shadow: {{ !empty($embed) ? 'none' : '0 4px 20px rgba(15,23,42,.12)' }};
            padding: 0.35in 0.45in 0.4in;
            position: relative;
        }
        .hdr {
            display: grid;
            grid-template-columns: 0.7in 1fr auto;
            gap: 0.12in;
            align-items: center;
            margin-bottom: 0.2in;
        }
        .hdr img { width: 0.64in; height: 0.66in; object-fit: contain; display: block; }
        .hdr-text { text-align: center; line-height: 1.15; }
        .hdr-republic { font-size: 10pt; }
        .hdr-name { font-size: 11pt; font-weight: 700; }
        .hdr-addr { font-size: 10pt; }
        .hdr-code { font-size: 9pt; font-weight: 700; white-space: nowrap; }
        .hdr-rule { height: 2px; background: #0070C0; margin: 0.08in 0 0.16in; }
        .title { text-align: center; font-size: 13pt; font-weight: 700; margin-bottom: 0.18in; }
        table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.data th, table.data td {
            border: 1px solid #000;
            padding: 4px 3px;
            font-size: 9pt;
            vertical-align: middle;
            word-wrap: break-word;
        }
        table.data thead th {
            background: {{ $hdrBg }};
            color: {{ $hdrFg }};
            font-family: {{ $hdrFf }}, Helvetica, sans-serif;
            font-size: {{ $hdrFs }}pt;
            font-weight: {{ $hdrFw }};
            font-style: {{ $hdrFi }};
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .footer {
            display: flex;
            justify-content: space-between;
            margin-top: 0.25in;
            font-size: 9pt;
            border-top: 2px solid #0070C0;
            padding-top: 0.08in;
        }
        .empty { color: #64748b; font-size: 12px; padding: 24px; text-align: center; }
    </style>
</head>
<body>
<div class="sheet">
    <div class="hdr">
        <div>@if($logoSrc !== '')<img src="{{ $logoSrc }}" alt="">@endif</div>
        <div class="hdr-text">
            <div class="hdr-republic">{{ $republic }}</div>
            <div class="hdr-name">{{ $institutionName }}</div>
            <div class="hdr-addr">{{ $institutionAddress }}</div>
        </div>
        <div class="hdr-code">{{ $formCode }}</div>
    </div>
    <div class="hdr-rule"></div>
    <div class="title">{{ $formTitle }}</div>

    @if($visibleCols === [])
        <div class="empty">No visible columns — turn some On in the Columns tab.</div>
    @else
        <table class="data">
            <colgroup>
                @foreach($visibleCols as $c)
                    <col style="width: {{ $c['width'] ?? 'auto' }}">
                @endforeach
            </colgroup>
            <thead>
                @if(count($groups) > 0)
                    <tr>
                        @foreach($groups as $g)
                            <th colspan="{{ max(1, (int) ($g['span'] ?? 1)) }}">{{ $g['label'] ?? '' }}</th>
                        @endforeach
                    </tr>
                @endif
                <tr>
                    @foreach($visibleCols as $c)
                        <th>{!! $c['label'] !!}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach(($sampleRows ?? []) as $row)
                    <tr>
                        @foreach($visibleCols as $c)
                            @php $k = (string) ($c['key'] ?? ''); @endphp
                            <td>{{ $row[$k] ?? '' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        <span>{{ $footerLeft }} {{ $defaultEffectivity }}</span>
        <span>{{ $footerCenter }} {{ $defaultRev }}</span>
        <span>Page 1 of 1</span>
    </div>
</div>
</body>
</html>

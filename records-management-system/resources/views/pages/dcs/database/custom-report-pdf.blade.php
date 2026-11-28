<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body {
            font-family: Arial, Helvetica, DejaVu Sans, sans-serif;
            font-size: 9pt;
            color: #000000;
            margin: 0;
        }
        /* Official CSPC Letterhead (matches Generate Report) */
        .hdr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .hdr-table, .hdr-table tr, .hdr-table td {
            border: none;
            background: none;
            padding: 0;
        }
        .hdr-brand-row td {
            vertical-align: middle;
        }
        .hdr-logo-cell {
            width: 1%;
            white-space: nowrap;
            padding-right: 10px;
        }
        .hdr-logo {
            height: 54pt;
            width: auto;
            display: block;
        }
        .hdr-republic,
        .hdr-name,
        .hdr-location {
            font-family: Arial, Helvetica, DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #000000;
            line-height: 1.25;
            white-space: nowrap;
        }
        .hdr-name {
            font-weight: 700;
            text-transform: uppercase;
            margin: 1px 0;
        }
        .hdr-line-wrap {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0 10px;
        }
        .hdr-line-wrap td {
            border: none;
            padding: 0;
            vertical-align: middle;
        }
        .hdr-blue-rule {
            border-top: 2px solid #0071BC;
            height: 1px;
            font-size: 0;
            line-height: 0;
        }
        .rpt-title {
            text-align: center;
            font-size: 13pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #000000;
            margin: 4px 0 6px;
        }
        .rpt-meta {
            text-align: center;
            font-size: 8pt;
            color: #475569;
            margin-bottom: 8px;
        }
        .rpt-filters {
            font-size: 8pt;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            margin-bottom: 10px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }
        table.data-table thead th {
            background: #8db3e2;
            color: #000000;
            font-size: 8pt;
            font-weight: 700;
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #334155;
            white-space: nowrap;
        }
        table.data-table tbody td {
            padding: 4px 6px;
            border: 1px solid #94a3b8;
            vertical-align: top;
            word-wrap: break-word;
            font-size: 8pt;
            line-height: 1.35;
        }
        table.data-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }
        table.data-table tbody tr.is-child td {
            color: #475569;
        }
        .rpt-footer {
            margin-top: 10px;
            font-size: 7.5pt;
            color: #64748b;
            text-align: right;
        }
    </style>
</head>
<body>
    <table class="hdr-table">
        <tr class="hdr-brand-row">
            @if(!empty($logoSrc))
                <td class="hdr-logo-cell">
                    <img src="{{ $logoSrc }}" class="hdr-logo" alt="CSPC">
                </td>
            @endif
            <td>
                <div class="hdr-republic">Republic of the Philippines</div>
                <div class="hdr-name">CAMARINES SUR POLYTECHNIC COLLEGES</div>
                <div class="hdr-location">Nabua, Camarines Sur</div>
            </td>
        </tr>
    </table>
    <table class="hdr-line-wrap">
        <tr>
            <td><div class="hdr-blue-rule"></div></td>
        </tr>
    </table>

    <div class="rpt-title">{{ $title }}</div>
    <div class="rpt-meta">Generated {{ $generatedAt }} · {{ count($rows) }} {{ count($rows) === 1 ? 'record' : 'records' }}</div>

    @if(!empty($filterSummary))
        <div class="rpt-filters">
            <strong>Active Filters:</strong> {{ $filterSummary }}
        </div>
    @endif

    <table class="data-table">
        <thead>
            <tr>
                @foreach($headers as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                <tr class="{{ !empty($r['is_child']) ? 'is-child' : '' }}">
                    @foreach($r['cells'] as $val)
                        <td>{{ $val !== '' ? $val : '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(1, count($headers)) }}" style="text-align:center; padding:14px; color:#64748b;">
                        No matching documents found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="rpt-footer">
        CSPC Records Management System · Document Control System
    </div>
</body>
</html>

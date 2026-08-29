<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $meta['title'] ?? 'DTS List of Transactions' }} — CSPC RMS</title>
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #0f172a;
            background: #f1f5f9;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .print-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border-bottom: 1px solid #cbd5e1;
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .print-toolbar .title-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .print-toolbar .title-area h2 {
            font-size: 14px;
            font-weight: 700;
            color: #1e40af;
            margin: 0;
        }

        .print-toolbar .actions-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .print-toolbar button {
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .btn-print {
            background: #1e40af;
            color: #ffffff;
            border: 1px solid #1e40af;
        }

        .btn-print:hover {
            background: #1d4ed8;
        }

        .btn-close {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .btn-close:hover {
            background: #f8fafc;
        }

        .sheet {
            width: 96%;
            max-width: 1400px;
            margin: 20px auto;
            background: #ffffff;
            padding: 24px 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
        }

        /* Official Header */
        .hdr {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 14px;
            margin-bottom: 16px;
            gap: 16px;
        }

        .hdr-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .hdr-logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
        }

        .hdr-text {
            line-height: 1.35;
        }

        .hdr-text .rep {
            font-size: 10.5px;
            color: #475569;
            letter-spacing: 0.3px;
        }

        .hdr-text .org {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hdr-text .loc {
            font-size: 11px;
            color: #475569;
        }

        .hdr-text .subsys {
            font-size: 12px;
            font-weight: 700;
            color: #1e40af;
            margin-top: 2px;
        }

        .hdr-right {
            text-align: right;
            line-height: 1.4;
        }

        .hdr-right .report-tag {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .hdr-right .meta-item {
            font-size: 10.5px;
            color: #475569;
        }

        .hdr-right .meta-item strong {
            color: #0f172a;
        }

        /* Filter Summary Bar */
        .report-summary-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 8px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 11px;
            color: #334155;
        }

        /* Data Table */
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 10.5px;
        }

        .report-table th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            padding: 8px 6px;
            border: 1px solid #cbd5e1;
            text-align: left;
            text-transform: uppercase;
            font-size: 9.5px;
            letter-spacing: 0.3px;
        }

        .report-table td {
            padding: 8px 6px;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            vertical-align: top;
            line-height: 1.35;
        }

        .report-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        .report-table .align-center {
            text-align: center;
        }

        .report-table .align-right {
            text-align: right;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid transparent;
        }

        .status-completed { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .status-ongoing { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .status-revision { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .status-cancelled { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .status-drafted { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }

        /* Signatories */
        .signatories-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 36px;
            padding-top: 16px;
            page-break-inside: avoid;
        }

        .signatory-box {
            width: 240px;
        }

        .signatory-box .role-label {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 36px;
        }

        .signatory-box .sig-line {
            border-bottom: 1.5px solid #0f172a;
            margin-bottom: 4px;
        }

        .signatory-box .sig-name {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
        }

        .signatory-box .sig-title {
            font-size: 10.5px;
            color: #475569;
        }

        /* Print Media Styles */
        @media print {
            @page {
                size: landscape;
                margin: 10mm 12mm;
            }

            body {
                background: #ffffff !important;
                font-size: 9.5pt;
                color: #000000;
            }

            .no-print {
                display: none !important;
            }

            .sheet {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .hdr {
                border-bottom: 2px solid #000000 !important;
            }

            .hdr-text .org, .hdr-text .subsys {
                color: #000000 !important;
            }

            .report-table th {
                background-color: #f1f5f9 !important;
                color: #000000 !important;
                border: 1px solid #94a3b8 !important;
            }

            .report-table td {
                border: 1px solid #cbd5e1 !important;
                color: #000000 !important;
            }

            .report-table tr:nth-child(even) td {
                background-color: #fafafa !important;
            }

            .report-table tr {
                page-break-inside: avoid;
            }

            .status-pill {
                border: 1px solid #000000 !important;
                background: transparent !important;
                color: #000000 !important;
            }
        }
    </style>
</head>
<body>

    <div class="print-toolbar no-print">
        <div class="title-area">
            <h2>{{ $meta['title'] ?? 'DTS Report Preview' }}</h2>
        </div>
        <div class="actions-area">
            <button type="button" class="btn-print" onclick="window.print()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Print / Save as PDF
            </button>
            <button type="button" class="btn-close" onclick="window.close()">
                Close
            </button>
        </div>
    </div>

    <div class="sheet">
        <!-- Institutional Header -->
        <div class="hdr">
            <div class="hdr-left">
                <img class="hdr-logo" src="{{ asset('images/cspc.png') }}" alt="CSPC Logo" onerror="this.src='{{ asset('images/logo.png') }}'">
                <div class="hdr-text">
                    <div class="rep">Republic of the Philippines</div>
                    <div class="org">Camarines Sur Polytechnic Colleges</div>
                    <div class="loc">Nabua, Camarines Sur</div>
                    <div class="subsys">Document Tracking System — {{ $meta['title'] ?? 'List of Transactions' }}</div>
                </div>
            </div>
            <div class="hdr-right">
                <div class="report-tag">Official Report</div>
                <div class="meta-item">Office: <strong>{{ $meta['office_name'] ?? 'Records Management System' }}</strong></div>
                <div class="meta-item">Date Generated: <strong>{{ now()->format('F d, Y h:i A') }}</strong></div>
                <div class="meta-item">Total Records: <strong>{{ count($rows) }}</strong></div>
            </div>
        </div>

        <!-- Filter Summary Bar -->
        <div class="report-summary-bar">
            <div>
                <strong>Scope:</strong> {{ $meta['scope_label'] ?? 'All Matching Records' }}
                @if(!empty($meta['date_range_label']))
                    &nbsp;|&nbsp; <strong>Date Range:</strong> {{ $meta['date_range_label'] }}
                @endif
                @if(!empty($meta['status_label']) && $meta['status_label'] !== 'all')
                    &nbsp;|&nbsp; <strong>Status:</strong> {{ ucfirst($meta['status_label']) }}
                @endif
                @if(!empty($meta['priority_label']) && $meta['priority_label'] !== 'all')
                    &nbsp;|&nbsp; <strong>Priority:</strong> {{ ucfirst(str_replace('_', ' ', $meta['priority_label'])) }}
                @endif
            </div>
            <div>
                <strong>Generated by:</strong> {{ auth()->user()?->details?->first_name ? (auth()->user()->details->first_name . ' ' . auth()->user()->details->last_name) : (auth()->user()?->name ?? 'User') }}
            </div>
        </div>

        <!-- Data Table -->
        <table class="report-table">
            <thead>
                <tr>
                    @foreach($selectedColumns as $colKey)
                        <th class="{{ in_array($colKey, ['item_no', 'status', 'elapsed_days', 'released_time', 'received_time']) ? 'align-center' : '' }}">
                            {{ $availableColumns[$colKey]['label'] ?? ucfirst(str_replace('_', ' ', $colKey)) }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        @foreach($selectedColumns as $colKey)
                            @php
                                $val = $row[$colKey] ?? '-';
                                $isCenter = in_array($colKey, ['item_no', 'status', 'elapsed_days', 'released_time', 'received_time', 'released_date', 'received_date']);
                            @endphp
                            <td class="{{ $isCenter ? 'align-center' : '' }}">
                                @if($colKey === 'status')
                                    @php
                                        $st = strtolower(trim((string)$val));
                                    @endphp
                                    <span class="status-pill status-{{ $st }}">{{ $val }}</span>
                                @elseif($colKey === 'control_number')
                                    <strong style="color: #1e40af;">{{ $val }}</strong>
                                @else
                                    {{ $val }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($selectedColumns) }}" style="text-align: center; padding: 24px; color: #94a3b8; font-style: italic;">
                            No transactions found matching the selected criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <!-- Signatories -->
        <div class="signatories-container">
            <div class="signatory-box">
                <div class="role-label">Prepared by:</div>
                <div class="sig-line"></div>
                <div class="sig-name">{{ !empty($meta['prepared_by']) ? $meta['prepared_by'] : (auth()->user()?->details?->first_name ? (auth()->user()->details->first_name . ' ' . auth()->user()->details->last_name) : (auth()->user()?->name ?? 'Office Staff')) }}</div>
                <div class="sig-title">{{ $meta['prepared_by_title'] ?? (auth()->user()?->details?->position ?? 'DTS In-Charge / Records Custodian') }}</div>
            </div>

            <div class="signatory-box">
                <div class="role-label">Noted / Approved by:</div>
                <div class="sig-line"></div>
                <div class="sig-name">{{ !empty($meta['noted_by']) ? $meta['noted_by'] : 'Head of Office / Department Head' }}</div>
                <div class="sig-title">{{ $meta['noted_by_title'] ?? ($meta['office_name'] ?? 'College Department') }}</div>
            </div>
        </div>
    </div>

    <script>
        // If autoPrint parameter is provided
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('autoPrint') === '1') {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
        });
    </script>
</body>
</html>

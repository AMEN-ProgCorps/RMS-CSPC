<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ ($check['year'] ?? '') }} {{ $check['cycle_label'] ?? 'Random Check' }} — {{ $check['office_name'] ?? 'Office' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #111; background: #fff; }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 10px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; }
        .toolbar button, .toolbar a { padding: 8px 16px; border: 1.5px solid #0d2a7a; border-radius: 8px; background: #0d2a7a; color: #fff; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar .ghost { background: #fff; color: #0d2a7a; }
        .sheet { max-width: 900px; margin: 0 auto; padding: 28px 36px 48px; }
        .hdr { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .hdr img { height: 64px; }
        .hdr-name { font-weight: 700; text-transform: uppercase; }
        .line { border-top: 2px solid #0071BC; margin: 8px 0 16px; }
        h1 { text-align: center; font-size: 16pt; margin-bottom: 4px; }
        .sub { text-align: center; color: #334155; margin-bottom: 18px; font-size: 11pt; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta td { padding: 4px 8px 4px 0; vertical-align: top; }
        .meta b { display: inline-block; min-width: 130px; }
        .note { background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px 12px; margin-bottom: 16px; font-size: 10.5pt; line-height: 1.45; }
        table.grid { width: 100%; border-collapse: collapse; font-size: 10pt; }
        table.grid th, table.grid td { border: 1px solid #94a3b8; padding: 6px 8px; vertical-align: top; }
        table.grid th { background: #0d2a7a; color: #fff; font-size: 9pt; text-transform: uppercase; }
        .sign { display: flex; justify-content: space-between; gap: 40px; margin-top: 36px; }
        .sign div { flex: 1; text-align: center; }
        .sign .linebox { border-top: 1px solid #111; margin-top: 48px; padding-top: 6px; font-size: 10pt; }
        @media print {
            .toolbar { display: none !important; }
            .sheet { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print letter</button>
        <a class="ghost" href="{{ url()->previous() }}">Back</a>
    </div>
    <div class="sheet">
        <div class="hdr">
            @if(!empty($logoSrc))
                <img src="{{ $logoSrc }}" alt="CSPC">
            @endif
            <div>
                <div>Republic of the Philippines</div>
                <div class="hdr-name">Camarines Sur Polytechnic Colleges</div>
                <div>Nabua, Camarines Sur</div>
            </div>
        </div>
        <div class="line"></div>
        <h1>Random Checking of Documents</h1>
        <p class="sub">{{ $check['cycle_label'] ?? 'Random Check' }} {{ $check['year'] ?? '' }}</p>

        <table class="meta">
            <tr>
                <td><b>Office</b> {{ $check['office_label'] ?? $check['office_name'] ?? '—' }}</td>
                <td><b>Visit date</b> {{ $check['check_date_label'] ?? '—' }}</td>
            </tr>
            <tr>
                <td><b>Conducted by</b> {{ $check['conducted_by'] ?: '—' }}</td>
                <td><b>Tested by</b> {{ $check['tested_by'] ?: '—' }}</td>
            </tr>
        </table>

        @if(!empty($check['excerpt']))
            <div class="note">
                This letter contains an <strong>excerpt</strong> of the random checking result — only documents with recommended actions.
                Your office may view the entire result in DCS (Office → Random Check). Printing the full list is optional so copies with no findings are not reproduced.
            </div>
        @else
            <div class="note">
                This is the entire random checking result for this office and cycle. Historical visits stay as recorded even if documents are revised later.
            </div>
        @endif

        <table class="grid">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Document No.</th>
                    <th>Rev</th>
                    <th>Effectivity</th>
                    <th>Availability</th>
                    <th>Remarks</th>
                    <th>Recommended actions</th>
                    <th>Compliance</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                @forelse(($check['rows'] ?? []) as $row)
                    <tr>
                        <td>{{ $row['item_no'] ?? '' }}</td>
                        <td>{{ $row['doc_no'] ?: '—' }}</td>
                        <td>{{ $row['rev_no'] ?? 0 }}</td>
                        <td>{{ $row['effectivity_date'] ?: '—' }}</td>
                        <td>{{ strtoupper($row['availability'] ?: '—') }}</td>
                        <td>{{ $row['remarks'] ?: '—' }}</td>
                        <td>{{ $row['recommended_actions'] ?: '—' }}</td>
                        <td>
                            @if(($row['compliance_status'] ?? '') === 'complied') Complied
                            @elseif(($row['compliance_status'] ?? '') === 'not_complied') Not complied
                            @else —
                            @endif
                        </td>
                        <td>{{ $row['notes'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">No documents in this report.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="sign">
            <div>
                <div class="linebox">Conducted by</div>
            </div>
            <div>
                <div class="linebox">Tested by / Office representative</div>
            </div>
        </div>
    </div>
</body>
</html>

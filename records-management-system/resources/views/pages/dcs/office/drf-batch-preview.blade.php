<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch DRF Preview ({{ count($previews) }} {{ count($previews) === 1 ? 'Form' : 'Forms' }})</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', Arial, Helvetica, sans-serif;
            background: #e2e8f0;
            color: #0f172a;
            padding-bottom: 48px;
        }
        .batch-prev-bar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 24px;
            background: #ffffff;
            border-bottom: 1px solid #cbd5e1;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
        }
        .batch-prev-bar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .batch-prev-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 11px;
            border-radius: 999px;
            background: #0d2a7a;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
        }
        .batch-prev-sub {
            font-size: 13px;
            color: #475569;
            font-weight: 500;
        }
        .batch-prev-close {
            padding: 7px 18px;
            border-radius: 8px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .batch-prev-close:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .batch-prev-stack {
            max-width: calc(8.5in + 36px);
            margin: 22px auto 0;
            padding: 0 16px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .batch-prev-card {
            width: 8.5in;
            max-width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .batch-prev-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .batch-prev-card-head-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .batch-prev-print-btn {
            padding: 5px 13px;
            border-radius: 7px;
            border: 1px solid #0d2a7a;
            background: #0d2a7a;
            color: #ffffff;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            flex-shrink: 0;
        }
        .batch-prev-print-btn:hover {
            background: #163b9e;
        }
        .batch-prev-num {
            display: inline-flex;
            align-items: center;
            padding: 2px 9px;
            border-radius: 999px;
            background: #e8f0fe;
            color: #0d2a7a;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            flex-shrink: 0;
        }
        .batch-prev-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .batch-prev-frame {
            display: block;
            width: 8.5in;
            max-width: 100%;
            height: 6.5in;
            border: none;
            background: #ffffff;
            overflow: hidden;
        }
    </style>
</head>
<body>
    @php
        $isViewer = ! empty($viewerMode);
        $canPrintBatch = ! empty($allowPrint) && ! $isViewer;
    @endphp
    @if(! $isViewer)
    <header class="batch-prev-bar">
        <div class="batch-prev-bar-left">
            <span class="batch-prev-pill">Batch · {{ count($previews) }} {{ count($previews) === 1 ? 'DRF' : 'DRFs' }}</span>
            <span class="batch-prev-sub">
                {{ $canPrintBatch ? 'Print each DRF on Long bond (8.5 × 13 in) with Margins → None.' : ($printLockMessage ?? 'Preview only — print unlocks after RFIU confirms each saved DRF.') }}
            </span>
        </div>
        <button type="button" class="batch-prev-close" onclick="window.close()">Close</button>
    </header>
    @endif

    <main class="batch-prev-stack">
        @foreach($previews as $idx => $item)
            <section class="batch-prev-card">
                <div class="batch-prev-card-head">
                    <div class="batch-prev-card-head-left">
                        <span class="batch-prev-num">DRF #{{ $idx + 1 }} of {{ count($previews) }}</span>
                        <span class="batch-prev-title">{{ $item['title'] !== '' ? $item['title'] : 'Untitled Document Request Form' }}</span>
                    </div>
                    @if($canPrintBatch)
                        <button type="button" class="batch-prev-print-btn" onclick="printBatchFrame('batchFrame{{ $idx }}')">Print DRF #{{ $idx + 1 }}</button>
                    @endif
                </div>
                <iframe
                    id="batchFrame{{ $idx }}"
                    class="batch-prev-frame"
                    scrolling="no"
                    title="DRF #{{ $idx + 1 }} preview"
                    srcdoc="{{ $item['html'] }}"
                ></iframe>
            </section>
        @endforeach
    </main>
    @if($canPrintBatch)
    <script>
    function printBatchFrame(id) {
        var frame = document.getElementById(id);
        if (!frame || !frame.contentWindow) return;
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (e) {}
    }
    </script>
    @endif
</body>
</html>

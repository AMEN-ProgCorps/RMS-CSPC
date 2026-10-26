{{--
DCS file: CSPC-F-DCC-06 print. The title uses at most two underlined lines: line 1 is filled with whole words, and both lines shrink together only when line 2 still overflows. Originator stays one 11pt comma-separated line.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
        /*
         * CSPC-F-DCC-06 Document Request Form — forced print geometry
         * Philippine long bond 8.5 × 13in. The form itself uses the top half (6.5in).
         * Logo: left 0.46in, top 0.16in, size 0.57×0.59, gap to line 0.03in
         * Header/footer rules: 3px
         *
         * Print dialog MUST use Margins → None AND Headers/footers off.
         * Browser "Default" margins stack on top of the CSS header inset and look
         * like a huge top gap. Keep @page margin 0; header top stays ≥ ~0.16in so
         * printers do not clip the seal / "Republic…" line (non-printable area).
         */
        @page {
            /* Philippine long bond. The form occupies the top half of this sheet. */
            size: 8.5in 13in;
            margin: 0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            min-height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            color: #000 !important;
            background: #e2e8f0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .print-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 10px 24px;
            justify-content: center;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .print-toolbar-tip {
            flex: 1 1 100%;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            line-height: 1.35;
            margin: 0;
        }
        .print-toolbar-tip strong { color: #0d2a7a; }
        .print-toolbar button {
            padding: 8px 20px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .print-toolbar .btn-print { background: #0d2a7a; color: #fff; border-color: #0d2a7a; }
        .print-toolbar .btn-close { background: #fff; color: #64748b; }

        /* Long bond is 13in tall. The DRF form is the top half; the lower half stays blank. */
        .sheet {
            width: 8.5in !important;
            height: 13in !important;
            min-height: 13in !important;
            max-height: 13in !important;
            margin: 16px auto;
            padding: 0 !important;
            background: #fff !important;
            position: relative !important;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            color: #000 !important;
            overflow: hidden !important;
        }
        /* The DRF form is only the top half of the long bond sheet. */
        .sheet-main {
            height: auto !important;
            min-height: 6.5in !important;
            max-height: none !important;
        }

        /* ═══════════ HEADER ═══════════
         * Top inset 0.16in + logo 0.59 + gap 0.03 → line at 0.78in
         * (Less than the old 0.23in screen gap; enough to avoid printer clip.)
         */
        .hdr-band {
            position: relative !important;
            width: 8.5in !important;
            height: 0.78in !important;
            min-height: 0.78in !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
        }
        .hdr-logo-cell {
            position: absolute !important;
            top: 0.16in !important;
            left: 0.46in !important;
            width: 0.57in !important;
            height: 0.59in !important;
            min-width: 0.57in !important;
            min-height: 0.59in !important;
            max-width: 0.57in !important;
            max-height: 0.59in !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 0 !important;
            overflow: hidden !important;
            z-index: 2;
        }
        /*
         * logo.png is 500×500 with ~272×272 opaque seal.
         * Size the <img> up and center it so the seal fills the 0.57×0.59 clip box.
         */
        .hdr-logo {
            display: block !important;
            width: calc(0.57in * 500 / 272) !important;
            height: calc(0.59in * 500 / 272) !important;
            min-width: calc(0.57in * 500 / 272) !important;
            min-height: calc(0.59in * 500 / 272) !important;
            max-width: none !important;
            max-height: none !important;
            margin-left: calc((0.57in - (0.57in * 500 / 272)) / 2) !important;
            margin-top: calc((0.59in - (0.59in * 500 / 272)) / 2) !important;
            padding: 0 !important;
            border: 0 !important;
            object-fit: fill !important;
            background: transparent !important;
        }
        .hdr-text-cell {
            position: absolute !important;
            top: 0.16in !important;
            left: calc(0.46in + 0.57in + 0.1in) !important;
            right: 1.5in !important;
            height: 0.59in !important; /* same band as logo; 0.03in clear below to line */
            margin: 0 !important;
            padding: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            z-index: 1;
            background: transparent !important;
        }
        .hdr-republic,
        .hdr-location {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .hdr-name {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }
        /* Line at logo-bottom + 0.03in = 0.78in — 3px rule */
        .hdr-rule {
            position: absolute !important;
            top: 0.78in !important;
            left: 0.49in !important;
            right: 0.24in !important;
            display: flex !important;
            align-items: center !important;
            gap: 0.12in !important;
            height: 12pt !important;
            margin: 0 !important;
            padding: 0 !important;
            z-index: 3;
            transform: translateY(-50%);
            background: transparent !important;
        }
        .hdr-rule-line {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            height: 2.5pt !important;
            min-height: 2.5pt !important;
            max-height: 2.5pt !important;
            background: #0070C0 !important;
            border: none !important;
            margin: 0 !important;
            padding: 0 !important;
            align-self: center !important;
        }
        .hdr-rule-code {
            flex: 0 0 auto !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 10pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }

        /* ═══════════ BODY (Narrow 0.5in) ═══════════ */
        .body {
            padding: 0.27in 0.5in 0.5in !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            color: #000 !important;
            background: #fff !important;
        }

        .form-title {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            text-align: center !important;
            line-height: 1 !important;
            margin: 0 0 11pt !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }

        /* Write-on lines: VALUE and RULE are siblings — text never covers the stroke */
        .fline {
            display: inline-flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            vertical-align: bottom !important;
            height: 11pt !important;
            background: transparent !important;
        }
        .fline-val {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            min-height: 0 !important;
            padding: 0 1px !important;
            margin: 0 !important;
            background: transparent !important;
            background-color: transparent !important;
            color: #000 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: clip !important;
        }
        .fline-rule {
            display: block !important;
            width: 100% !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            border-bottom: 1px solid #000 !important;
            background: transparent !important;
        }
        .fline.w-req { width: 1.54in !important; flex: 0 0 1.54in !important; }
        /* Keep Date: label in its original spot; only the underline grows to the right edge */
        .fline.w-date { flex: 1 1 auto !important; width: auto !important; min-width: 1.79in !important; }
        .fline.w-fill { flex: 1 1 auto !important; width: auto !important; min-width: 0 !important; }
        .fline.w-fill .fline-val { white-space: normal !important; }
        .fline.w-fill .fline-val.is-originator {
            white-space: nowrap !important;
            overflow: hidden !important;
            font-size: 11pt !important;
        }

        /* Long titles: line 2 starts under the first title words, beside the label. */
        .doc-title-block {
            display: flex !important;
            align-items: flex-start !important;
            width: 100% !important;
            height: auto !important;
            min-height: 13pt !important;
            max-height: none !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
        }
        .doc-title-block .lbl {
            height: 13pt !important;
            line-height: 13pt !important;
        }
        .doc-title-lines {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            display: flex !important;
            flex-direction: column !important;
        }
        .doc-title-block .fline {
            display: flex !important;
            width: 100% !important;
            height: 13pt !important;
            min-height: 13pt !important;
            max-height: 13pt !important;
        }
        .doc-title-block .fline.w-fill .fline-val {
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: clip !important;
        }

        /* Request / Originator / Document Title — single spacing (1.0) */
        .row {
            display: flex !important;
            align-items: flex-end !important;
            margin: 0 !important;
            padding: 0 !important;
            height: 13pt !important;
            min-height: 13pt !important;
            max-height: 13pt !important;
            background: transparent !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            width: 100% !important;
        }
        .lbl {
            flex: 0 0 auto !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            margin: 0 4px 0 0 !important;
            padding: 0 !important;
            background: transparent !important;
            color: #000 !important;
        }
        /* Fixed gap — same Date: label position as the official form */
        .meta-gap {
            flex: 0 0 2.87in !important;
            width: 2.87in !important;
            height: 1px !important;
        }

        .check-row {
            display: flex !important;
            align-items: center !important;
            height: 10pt !important;
            min-height: 10pt !important;
            max-height: 10pt !important;
            margin: 0 0 12pt !important;
            padding: 0 !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
        }
        .check-item {
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .check-item.internal { margin-left: 0.24in !important; }
        .check-item.external { margin-left: 0.35in !important; }
        .cb {
            display: inline-block !important;
            width: 0.17in !important;
            height: 0.14in !important;
            border: 0.5px solid #000 !important;
            text-align: center !important;
            line-height: 0.12in !important;
            font-size: 8pt !important;
            font-weight: 700 !important;
            background: transparent !important;
            color: #000 !important;
            flex-shrink: 0 !important;
        }

        .desc-block {
            margin: 0 0 13pt !important;
            padding: 0 !important;
            background: transparent !important;
        }
        .desc-block .lbl {
            display: block !important;
            margin: 0 !important;
            height: 11pt !important;
            line-height: 1 !important;
        }
        .desc-line {
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            height: 13pt !important;
            min-height: 13pt !important;
            max-height: 13pt !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            line-height: 1 !important;
        }
        .desc-line .fline-val {
            white-space: normal !important;
            width: 100% !important;
        }

        .dist-label {
            margin: 0 !important;
            padding: 0 !important;
            height: 11pt !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .dist-grid {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            column-gap: 0.33in !important;
            margin: 0 0 27pt !important;
            padding: 0 !important;
            background: transparent !important;
        }
        .dist-col {
            display: flex !important;
            flex-direction: column !important;
            background: transparent !important;
        }
        /* Distribute rows — single spacing (1.0); column gap stays 0.33in */
        .dist-line {
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            height: 12.5pt !important;
            min-height: 12.5pt !important;
            max-height: 12.5pt !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            line-height: 1 !important;
        }
        .dist-line .fline-val {
            font-size: 11pt !important;
            line-height: 1 !important;
            white-space: nowrap !important;
        }

        /* Signature table */
        .sig-table {
            width: 7.57in !important;
            max-width: 100% !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1.0 !important;
            background: transparent !important;
        }
        .sig-table col.c1 { width: 1.01in !important; }
        .sig-table col.c2 { width: 1.87in !important; }
        .sig-table col.c3 { width: 2.50in !important; }
        .sig-table col.c4 { width: 2.19in !important; }
        .sig-table th,
        .sig-table td {
            border: 0.5pt solid #000 !important;
            height: 0.21in !important;
            min-height: 0.21in !important;
            max-height: 0.21in !important;
            padding: 0 3px !important;
            vertical-align: middle !important;
            line-height: 1.0 !important;
            overflow: hidden !important;
            white-space: nowrap !important;
            background: transparent !important;
            background-color: transparent !important;
            color: #000 !important;
        }
        .sig-table .row-label {
            text-align: left !important;
            font-size: 11pt !important;
            font-weight: 400 !important;
        }
        .sig-table .col-c {
            text-align: center !important;
            font-weight: 400 !important;
        }
        .sig-table thead th {
            font-size: 11pt !important;
            font-weight: 400 !important;
            text-align: center !important;
        }
        .sig-table .name-bold {
            font-weight: 700 !important;
            text-transform: uppercase !important;
            text-align: center !important;
            background: transparent !important;
        }
        .sig-table .desig {
            font-weight: 400 !important;
            text-align: center !important;
            background: transparent !important;
        }
        .sig-table .sig-fit-cell {
            text-align: center !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            max-width: 0 !important;
            padding: 0 2px !important;
        }
        .sig-table .sig-fit {
            display: inline-block !important;
            white-space: nowrap !important;
            line-height: 1 !important;
            vertical-align: middle !important;
            max-width: 100% !important;
        }
        .sig-table .sig-fit.desig,
        .sig-table .sig-fit.col-c {
            font-weight: 400 !important;
            text-transform: none !important;
        }

        .footer-rule {
            border: none !important;
            margin: 10px calc(-0.5in + 0.35in) 0 calc(-0.5in + 0.4in) !important;
            padding: 0 !important;
            height: 2.5pt !important;
            min-height: 2.5pt !important;
            max-height: 2.5pt !important;
            background: #0070C0 !important;
        }
        .footer {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-top: 4px !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 7pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }
        .footer span,
        .footer strong {
            font-size: 7pt !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            background: transparent !important;
            color: #000 !important;
        }

        .attach-title {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 12pt !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            text-align: center !important;
            line-height: 1 !important;
            margin: 0 0 11pt !important;
            padding: 0 !important;
            color: #000 !important;
        }
        .attach-table {
            width: 100% !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
        }
        .attach-table th,
        .attach-table td {
            border: 0.5pt solid #000 !important;
            padding: 0 4px !important;
            vertical-align: middle !important;
            text-align: left !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1 !important;
            height: 13pt !important;
            max-height: 13pt !important;
            overflow: hidden !important;
            white-space: nowrap !important;
            text-overflow: ellipsis !important;
        }
        .attach-table th {
            font-weight: 700 !important;
            text-align: center !important;
            height: 14pt !important;
            max-height: 14pt !important;
        }
        .attach-table col.c-no { width: 0.55in !important; }
        .attach-table col.c-name { width: auto !important; }

        /*
         * Attach pages: pack office rows until long-bond body is full,
         * then spill to the next page (footer stays pinned to bottom).
         */
        /*
         * Distribution continuation page (page 2 and after).
         * Edit the FIRST number in padding below to change the top margin.
         * It is 0.75in right now. Example: padding: 1.10in 0.5in 0.12in !important;
         */
        .sheet-attach .body {
            display: flex !important;
            flex-direction: column !important;
            box-sizing: border-box !important;
            height: calc(13in - 0.85in) !important;
            min-height: calc(13in - 0.85in) !important;
            max-height: calc(13in - 0.85in) !important;
            padding: 0.75in 0.5in 0.12in !important;
        }
        .sheet-attach .attach-doc-title {
            margin: 0 0 4pt !important;
            height: auto !important;
            min-height: 13pt !important;
            max-height: none !important;
        }
        .sheet-attach .attach-dist-label {
            margin: 2pt 0 2pt !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            font-weight: 700 !important;
            line-height: 1.15 !important;
            color: #000 !important;
        }
        .sheet-attach .attach-office-name {
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 11pt !important;
            line-height: 1.35 !important;
            color: #000 !important;
        }
        .sheet-attach .attach-dist-note {
            margin: 0 0 6pt !important;
            font-family: Arial, Helvetica, sans-serif !important;
            font-size: 9pt !important;
            font-weight: 400 !important;
            line-height: 1.2 !important;
            color: #000 !important;
        }
        .sheet-attach .attach-table {
            flex: 0 0 auto !important;
            margin: 0 !important;
        }
        .sheet-attach .footer-rule {
            margin-top: auto !important;
            margin-bottom: 0 !important;
        }
        .sheet-attach .footer {
            margin: 0 !important;
            padding: 2pt 0 0 !important;
        }

        .sheet-attach {
            page-break-before: always !important;
            break-before: page !important;
        }

        @media print {
            html, body {
                width: 8.5in !important;
                min-height: 0 !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                display: block !important;
                position: static !important;
            }
            .print-toolbar { display: none !important; }
            .sheet {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 8.5in !important;
                height: 13in !important;
                min-height: 13in !important;
                max-height: 13in !important;
                overflow: hidden !important;
                position: relative !important;
                top: 0 !important;
                left: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .sheet-main {
                height: auto !important;
                min-height: 6.5in !important;
                max-height: none !important;
                page-break-after: auto !important;
                break-after: auto !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .sheet-attach {
                page-break-before: always !important;
                break-before: page !important;
                page-break-after: auto !important;
                break-after: auto !important;
            }
            .hdr-band {
                margin: 0 !important;
                padding: 0 !important;
                height: 0.78in !important;
                min-height: 0.78in !important;
            }
            .hdr-logo-cell,
            .hdr-text-cell {
                top: 0.16in !important;
            }
            .hdr-rule {
                top: 0.78in !important;
            }
            .fline-rule,
            .desc-line .fline-rule,
            .dist-line .fline-rule {
                border-bottom: 1px solid #000 !important;
            }
            .hdr-rule-line {
                height: 2.5pt !important;
                min-height: 2.5pt !important;
                max-height: 2.5pt !important;
                background: #0070C0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .footer-rule {
                border: none !important;
                height: 2.5pt !important;
                min-height: 2.5pt !important;
                max-height: 2.5pt !important;
                background: #0070C0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>
@php
    use App\Helpers\OfficeIntakeHelper;

    $originator = trim((string) ($drf->originator_name ?? ''));
    $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
    $isInternal = $kind === 'internal';
    $isExternal = $kind === 'external';
    $description = trim((string) ($drf->description_reason ?? ''));
    $descLines = $description !== '' ? preg_split('/\R/u', $description) : [];
    // Pack into exactly 4 write-on lines
    $packed = ['', '', '', ''];
    $idx = 0;
    foreach ($descLines as $chunk) {
        $chunk = trim((string) $chunk);
        if ($chunk === '' && $idx === 0 && $packed[0] === '') {
            continue;
        }
        if ($idx < 4) {
            $packed[$idx] = $chunk;
            $idx++;
        } else {
            $packed[3] = trim($packed[3] . ' ' . $chunk);
        }
    }
    $allDistribute = array_values(array_filter(array_map(
        fn (array $office) => trim((string) (($office['code'] ?? '') !== '' ? $office['code'] : ($office['name'] ?? ''))),
        OfficeIntakeHelper::drfDistributeOffices($drf)
    ), fn ($code) => $code !== ''));

    $distributeOffices = OfficeIntakeHelper::drfDistributeOffices($drf);
    $attachOfficeRows = collect($distributeOffices)->map(function (array $office) {
        $name = trim((string) ($office['name'] ?? ''));
        $code = trim((string) ($office['code'] ?? ''));

        return $name !== '' ? $name : $code;
    })->filter(fn ($label) => $label !== '')->values()->all();

    $distributeOverflow = count($allDistribute) > 24;
    /*
     * Long bond attach body ≈ 11.5in after header/title/dist-label/footer.
     * Arial 11pt / line-height 1.0 @ ~13pt rows → ~58 offices before next page
     * (slightly fewer than before to leave room for the distribution heading).
     */
    $attachPerPage = 58;
    $attachPages = $distributeOverflow
        ? array_chunk($attachOfficeRows, $attachPerPage)
        : [];

    if ($distributeOverflow) {
        $distribute = array_fill(0, 24, '');
        $distribute[1] = 'Please see attached';
        $distribute[2] = 'distribution list';
    } else {
        $distribute = array_pad(array_slice($allDistribute, 0, 24), 24, '');
    }

    $preparedName = trim((string) ($drf->prepared_by_name ?? ''));
    $preparedDesig = trim((string) ($drf->prepared_by_designation ?? ''));
    $reviewedName = trim((string) ($drf->reviewed_by_name ?? ''));
    $reviewedDesig = trim((string) ($drf->reviewed_by_designation ?? ''));
    $approvedName = trim((string) ($drf->approved_by_name ?? ''));
    $approvedDesig = trim((string) ($drf->approved_by_designation ?? ''));

    $sigFitPt = static function (string $text, float $cellIn, float $startPt, bool $bold = false): float {
        $text = trim($text);
        if ($text === '') {
            return $startPt;
        }
        $avgEm = $bold ? 0.84 : 0.68;
        $usablePt = max(16.0, ($cellIn * 72) - 8);
        $needed = mb_strlen($text) * $startPt * $avgEm;
        if ($needed <= $usablePt) {
            return $startPt;
        }

        return max(5.0, round($startPt * ($usablePt / $needed), 2));
    };
    $preparedNamePt = $sigFitPt($preparedName, 1.87, 11, true);
    $reviewedNamePt = $sigFitPt($reviewedName, 2.50, 11, true);
    $approvedNamePt = $sigFitPt($approvedName, 2.19, 11, true);
    $preparedDesigPt = $sigFitPt($preparedDesig, 1.87, 11, false);
    $reviewedDesigPt = $sigFitPt($reviewedDesig, 2.50, 11, false);
    $approvedDesigPt = $sigFitPt($approvedDesig, 2.19, 11, false);

    $docTitle = trim((string) ($drf->doc_title ?? ''));
    $drfDate = $drf->drf_date
        ? \Carbon\Carbon::parse($drf->drf_date)->format('F d, Y')
        : '';
@endphp
@php
    $isReviewer = \App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake();
    $viewerMode = ! empty($viewerMode);
    $allowPrint = $allowPrint ?? true;
    $printLockMessage = $printLockMessage ?? 'Save the form before printing.';
    $printReadyId = (int) ($drf->id ?? 0);
    $printReadyUrl = $printReadyId > 0
        ? route('dcs.api.office-intake.print-ready', ['type' => 'drf', 'ids' => $printReadyId], false)
        : '';
@endphp
@if($viewerMode)
<script>
(function () {
    var last = 0;
    function ping() {
        var now = Date.now();
        if (now - last < 4000) return;
        last = now;
        try { parent.postMessage({ type: 'rms-user-activity' }, location.origin); } catch (e) {}
    }
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'click', 'touchstart'].forEach(function (name) {
        document.addEventListener(name, ping, { passive: true });
    });
    ping();
})();
</script>
@endif
@if(! $allowPrint)
<style id="ofi-print-block-style">
    @media print {
        body * { display: none !important; }
        body::after {
            display: block !important;
            content: "{{ $printLockMessage }}";
            font: 14pt Arial, sans-serif;
            color: #000;
            padding: 1in;
        }
    }
</style>
<script>
window.print = function () {};
@if($printReadyUrl !== '')
(function () {
    var url = @json($printReadyUrl);
    var id = @json((string) $printReadyId);
    var timer = setInterval(async function () {
        try {
            var res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!res.ok) return;
            var data = await res.json();
            var ready = (data.ready || []).map(String);
            if (ready.indexOf(id) === -1) return;
            clearInterval(timer);
            location.reload();
        } catch (e) {}
    }, 2000);
})();
@endif
</script>
@endif
<div class="print-toolbar">
    @if($viewerMode)
        <span style="font-size:12px;font-weight:600;color:#64748b;align-self:center;">View only — RFIO review</span>
    @elseif(! $allowPrint)
        <span style="font-size:12px;font-weight:600;color:#64748b;align-self:center;">{{ $printLockMessage }}</span>
        <button type="button" class="btn-close" onclick="window.close()">Close</button>
    @else
        <p class="print-toolbar-tip">
            Important: in the print dialog set <strong>Margins → None.</strong>
            Keep paper <strong>Long bond (8.5 × 13 in)</strong>, and leave Headers and footers off.
        </p>
        @if($isReviewer)
            <a href="{{ route('dcs.requests.index', absolute: false) }}" class="btn-close" style="text-decoration:none;display:inline-flex;align-items:center;">Back to Request</a>
        @endif
        <button type="button" class="btn-print" onclick="ofiPrintClean()">Print</button>
        <button type="button" class="btn-close" onclick="window.close()">Close</button>
    @endif
</div>

<div class="sheet sheet-main">
    <header class="hdr-band">
        <div class="hdr-logo-cell">
            @if(!empty($logoSrc))
                <img src="{{ $logoSrc }}" alt="" class="hdr-logo">
            @endif
        </div>
        <div class="hdr-text-cell">
            <div class="hdr-republic">Republic of the Philippines</div>
            <div class="hdr-name">Camarines Sur Polytechnic Colleges</div>
            <div class="hdr-location">Nabua, Camarines Sur</div>
        </div>
        <div class="hdr-rule">
            <div class="hdr-rule-line"></div>
            <span class="hdr-rule-code">CSPC-F-DCC-06</span>
        </div>
    </header>

    <div class="body">
        <div class="form-title">Document Request Form</div>

        <div class="row">
            <span class="lbl">Request # :</span>
            <div class="fline w-req"><div class="fline-val"></div><div class="fline-rule"></div></div>
            <div class="meta-gap" aria-hidden="true"></div>
            <span class="lbl">Date:</span>
            <div class="fline w-date"><div class="fline-val">{{ $drfDate }}</div><div class="fline-rule"></div></div>
        </div>

        <div class="row">
            <span class="lbl">Originator :</span>
            <div class="fline w-fill"><div class="fline-val is-originator">{{ $originator }}</div><div class="fline-rule"></div></div>
        </div>

        <div class="doc-title-block" data-doc-title="{{ $docTitle }}">
            <span class="lbl">Document Title :</span>
            <div class="doc-title-lines">
                <div class="fline w-fill"><div class="fline-val">{{ $docTitle }}</div><div class="fline-rule"></div></div>
            </div>
        </div>

        <div class="check-row">
            <span class="lbl">Type of document:</span>
            <span class="check-item internal">
                <span class="cb">@if($isInternal)✓@endif</span> Internal
            </span>
            <span class="check-item external">
                <span class="cb">@if($isExternal)✓@endif</span> External
            </span>
        </div>

        <div class="desc-block">
            <span class="lbl">Description/reason for request (define in detail):</span>
            @foreach($packed as $line)
                <div class="desc-line">
                    <div class="fline-val">{{ $line }}</div>
                    <div class="fline-rule"></div>
                </div>
            @endforeach
        </div>

        <div class="dist-label">Distribute document to (department/position):</div>
        <div class="dist-grid">
            @for($col = 0; $col < 4; $col++)
                <div class="dist-col">
                    @for($row = 0; $row < 6; $row++)
                        <div class="dist-line">
                            <div class="fline-val">{{ $distribute[$col * 6 + $row] ?? '' }}</div>
                            <div class="fline-rule"></div>
                        </div>
                    @endfor
                </div>
            @endfor
        </div>

        <table class="sig-table">
            <colgroup>
                <col class="c1">
                <col class="c2">
                <col class="c3">
                <col class="c4">
            </colgroup>
            <thead>
                <tr>
                    <th class="row-label"></th>
                    <th class="col-c">Prepared by:</th>
                    <th class="col-c">Reviewed by:</th>
                    <th class="col-c">Approved by:</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="row-label">Signature</td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                </tr>
                <tr>
                    <td class="row-label">Name</td>
                    <td class="sig-fit-cell"><span class="sig-fit name-bold" data-sig-fit data-fit-width="1.87" data-fit-base="11" data-fit-pt="{{ $preparedNamePt }}" style="font-size: {{ $preparedNamePt }}pt !important;">{{ $preparedName }}</span></td>
                    <td class="sig-fit-cell"><span class="sig-fit name-bold" data-sig-fit data-fit-width="2.50" data-fit-base="11" data-fit-pt="{{ $reviewedNamePt }}" style="font-size: {{ $reviewedNamePt }}pt !important;">{{ $reviewedName }}</span></td>
                    <td class="sig-fit-cell"><span class="sig-fit name-bold" data-sig-fit data-fit-width="2.19" data-fit-base="11" data-fit-pt="{{ $approvedNamePt }}" style="font-size: {{ $approvedNamePt }}pt !important;">{{ $approvedName }}</span></td>
                </tr>
                <tr>
                    <td class="row-label">Designation</td>
                    <td class="sig-fit-cell"><span class="sig-fit col-c" data-sig-fit data-fit-width="1.87" data-fit-base="11" data-fit-pt="{{ $preparedDesigPt }}" style="font-size: {{ $preparedDesigPt }}pt !important;">{{ $preparedDesig }}</span></td>
                    <td class="sig-fit-cell"><span class="sig-fit desig" data-sig-fit data-fit-width="2.50" data-fit-base="11" data-fit-pt="{{ $reviewedDesigPt }}" style="font-size: {{ $reviewedDesigPt }}pt !important;">{{ $reviewedDesig }}</span></td>
                    <td class="sig-fit-cell"><span class="sig-fit desig" data-sig-fit data-fit-width="2.19" data-fit-base="11" data-fit-pt="{{ $approvedDesigPt }}" style="font-size: {{ $approvedDesigPt }}pt !important;">{{ $approvedDesig }}</span></td>
                </tr>
                <tr>
                    <td class="row-label">Date</td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                    <td class="col-c"></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-rule"></div>
        <div class="footer">
            <span>Effectivity Date: January 2018</span>
            <span>Rev. 0</span>
            <span>Page: 1 of 1</span>
        </div>
    </div>
</div>

@if($distributeOverflow)
@foreach($attachPages as $pageIndex => $pageOffices)
@php $rowOffset = $pageIndex * $attachPerPage; @endphp
<div class="sheet sheet-attach">
    <div class="body">
        <div class="doc-title-block attach-doc-title" data-doc-title="{{ $docTitle }}">
            <span class="lbl">Document Title :</span>
            <div class="doc-title-lines">
                <div class="fline w-fill"><div class="fline-val">{{ $docTitle }}</div><div class="fline-rule"></div></div>
            </div>
        </div>
        <div class="attach-dist-label">Distribute document to (department/position):</div>
        <table class="attach-table">
            <colgroup>
                <col class="c-no">
                <col class="c-name">
            </colgroup>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Name of Offices</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pageOffices as $index => $officeLabel)
                    <tr>
                        <td style="text-align: center;">{{ $rowOffset + $index + 1 }}</td>
                        <td>{{ $officeLabel }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach
@endif
<script>
function ofiFitSigText() {
    document.querySelectorAll('[data-sig-fit]').forEach(function (el) {
        var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text) return;
        var widthIn = parseFloat(el.getAttribute('data-fit-width') || '0') || 0;
        var avail = widthIn > 0
            ? (widthIn * 96) - 8
            : Math.max(8, ((el.closest('td') || el.parentElement).clientWidth || 0) - 6);
        var basePt = 11;
        var lo = 4.5;
        var hi = basePt;
        var best = lo;
        el.style.whiteSpace = 'nowrap';
        el.style.maxWidth = 'none';
        el.style.transform = 'none';
        for (var i = 0; i < 22; i++) {
            var mid = (lo + hi) / 2;
            el.style.setProperty('font-size', mid + 'pt', 'important');
            if (el.scrollWidth <= avail) {
                best = mid;
                lo = mid;
            } else {
                hi = mid;
            }
        }
        el.style.setProperty('font-size', best + 'pt', 'important');
        el.style.maxWidth = '100%';
    });
}

function ofiTitleLine(text) {
    var holder = document.createElement('div');
    holder.textContent = text;
    return '<div class="fline w-fill"><div class="fline-val">' + holder.innerHTML + '</div><div class="fline-rule"></div></div>';
}

function ofiPackTwoLines(words, ctx, limit) {
    var line1 = '';
    var index = 0;
    for (; index < words.length; index++) {
        var next = line1 === '' ? words[index] : (line1 + ' ' + words[index]);
        if (line1 !== '' && ctx.measureText(next).width > limit) break;
        line1 = next;
    }
    if (line1 === '' && words.length) {
        line1 = words[0];
        index = 1;
    }
    return [line1, words.slice(index).join(' ')];
}

function ofiFitDocTitles() {
    var canvas = document.createElement('canvas');
    var ctx = canvas.getContext('2d');
    if (!ctx) return;
    document.querySelectorAll('.doc-title-block[data-doc-title]').forEach(function (block) {
        var title = (block.getAttribute('data-doc-title') || '').replace(/\s+/g, ' ').trim();
        var host = block.querySelector('.doc-title-lines');
        if (!host) return;
        var width = host.clientWidth;
        if (width < 40) width = 560;
        var limit = width - 8;
        var words = title === '' ? [] : title.split(' ');
        function packedAt(pt) {
            ctx.font = (pt * 96 / 72) + 'px Arial';
            var packed = ofiPackTwoLines(words, ctx, limit);
            var line1 = packed[0] === '' ? 0 : ctx.measureText(packed[0]).width;
            var line2 = packed[1] === '' ? 0 : ctx.measureText(packed[1]).width;
            return { ok: line1 <= limit && line2 <= limit, lines: packed };
        }
        var size = 11;
        var fit = packedAt(11);
        var lines = fit.lines;
        if (!fit.ok) {
            var lo = 7;
            var hi = 11;
            size = 7;
            lines = packedAt(7).lines;
            for (var step = 0; step < 24; step++) {
                var mid = (lo + hi) / 2;
                var trial = packedAt(mid);
                if (trial.ok) {
                    size = mid;
                    lines = trial.lines;
                    lo = mid;
                } else {
                    hi = mid;
                }
            }
            size = Math.floor(size * 100) / 100;
            if (size < 7) size = 7;
            lines = packedAt(size).lines;
        }
        host.innerHTML = ofiTitleLine(lines[0]) + (lines[1] !== '' ? ofiTitleLine(lines[1]) : '');
        host.querySelectorAll('.fline-val').forEach(function (value) {
            value.style.setProperty('font-size', size + 'pt', 'important');
            value.style.setProperty('white-space', 'nowrap', 'important');
            value.style.setProperty('overflow', 'hidden', 'important');
        });
    });
}

function ofiPrintClean() {
    ofiFitDocTitles();
    ofiFitSigText();
    var prevTitle = document.title;
    var prevPath = window.location.pathname + window.location.search + window.location.hash;
    var restored = false;
    function restore() {
        if (restored) return;
        restored = true;
        document.title = prevTitle;
        try { history.replaceState(null, '', prevPath); } catch (e) {}
        window.removeEventListener('afterprint', restore);
    }
    document.title = '\u00A0';
    try { history.replaceState(null, '', '/'); } catch (e) {}
    window.addEventListener('afterprint', restore);
    window.print();
    setTimeout(restore, 1500);
}

document.addEventListener('DOMContentLoaded', function () {
    ofiFitSigText();
    ofiFitDocTitles();
});
window.addEventListener('resize', function () {
    ofiFitSigText();
    ofiFitDocTitles();
});
window.addEventListener('beforeprint', function () {
    ofiFitDocTitles();
    ofiFitSigText();
});
ofiFitDocTitles();
window.addEventListener('load', ofiFitDocTitles);
</script>
</body>
</html>

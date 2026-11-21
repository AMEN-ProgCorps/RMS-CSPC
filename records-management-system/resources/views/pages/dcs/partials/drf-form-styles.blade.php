{{--
  Shared CSPC-F-DCC-06 Document Request Form visual styles.
  Used by drf-print and the layout renderer (office_drf theme).
--}}
<style>
    .drf-theme {
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 11pt !important;
        line-height: 1 !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .drf-theme .block {
        position: absolute !important;
        font-family: Arial, Helvetica, sans-serif !important;
        color: #000 !important;
        line-height: 1 !important;
        overflow: visible !important;
        /* Never let theme helpers override the block's own height from layout_json */
        max-height: none;
    }
    .drf-theme .block-logo img {
        display: block !important;
        width: calc(0.57in * 500 / 272) !important;
        height: calc(0.59in * 500 / 272) !important;
        max-width: none !important;
        max-height: none !important;
        margin-left: calc((0.57in - (0.57in * 500 / 272)) / 2) !important;
        margin-top: calc((0.59in - (0.59in * 500 / 272)) / 2) !important;
        object-fit: fill !important;
    }
    .drf-theme .block-line-inner {
        height: 2.5pt !important;
        min-height: 2.5pt !important;
        background: #0070C0 !important;
    }
    .drf-theme .drf-title {
        font-size: 12pt !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        text-align: center !important;
        line-height: 1 !important;
    }
    .drf-theme .drf-hdr-text {
        font-size: 11pt !important;
        line-height: 1 !important;
        text-align: left !important;
    }
    .drf-theme .drf-hdr-text.is-name {
        font-weight: 700 !important;
        text-transform: uppercase !important;
    }
    .drf-theme .drf-form-code {
        font-size: 10pt !important;
        font-weight: 700 !important;
        white-space: nowrap !important;
        text-align: right !important;
    }
    .drf-theme .block-field {
        /* Match official .row — 13.5pt single spacing; height comes from layout_json */
        overflow: hidden !important;
    }
    .drf-theme .drf-field {
        display: flex !important;
        align-items: flex-end !important;
        width: 100% !important;
        height: 100% !important;
        max-height: 100% !important;
        font-size: 11pt !important;
        line-height: 1 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .drf-theme .drf-field .lbl {
        flex: 0 0 auto !important;
        margin: 0 4px 0 0 !important;
        white-space: nowrap !important;
        font-size: 11pt !important;
        font-weight: 400 !important;
    }
    .drf-theme .drf-field .fline {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-end !important;
        height: 11pt !important;
    }
    .drf-theme .drf-field .fline-val {
        font-size: 11pt !important;
        line-height: 1 !important;
        padding: 0 1px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        background: transparent !important;
    }
    .drf-theme .drf-field .fline-rule {
        display: block !important;
        width: 100% !important;
        height: 0 !important;
        border-bottom: 1px solid #000 !important;
    }
    /* Inner content only — do NOT target .block-checkboxes (that is the absolute shell). */
    .drf-theme .block-checkboxes {
        overflow: hidden !important;
    }
    .drf-theme .drf-checks {
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        height: 100% !important;
        max-height: 100% !important;
        font-size: 11pt !important;
        line-height: 1 !important;
        gap: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .drf-theme .drf-checks .lbl {
        flex: 0 0 auto !important;
        margin-right: 4px !important;
        white-space: nowrap !important;
    }
    .drf-theme .drf-checks .opt {
        display: inline-flex !important;
        align-items: center !important;
        gap: 4px !important;
        margin-left: 0.35in !important;
        white-space: nowrap !important;
    }
    .drf-theme .drf-checks .opt.is-first { margin-left: 0.20in !important; }
    .drf-theme .drf-checks .cb {
        display: inline-block !important;
        width: 0.17in !important;
        height: 0.14in !important;
        border: 0.5px solid #000 !important;
        text-align: center !important;
        line-height: 0.12in !important;
        font-size: 8pt !important;
        font-weight: 700 !important;
        background: transparent !important;
    }
    .drf-theme .drf-ruled {
        display: flex !important;
        flex-direction: column !important;
        width: 100% !important;
        height: 100% !important;
    }
    .drf-theme .drf-ruled .lbl {
        display: block !important;
        height: 11pt !important;
        line-height: 1 !important;
        margin: 0 0 2px 0 !important;
        font-size: 11pt !important;
    }
    .drf-theme .drf-ruled .ruled-line {
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-end !important;
        height: 13pt !important;
        min-height: 13pt !important;
        max-height: 13pt !important;
        position: relative !important;
        border-bottom: none !important;
    }
    .drf-theme .drf-ruled .ruled-line::after {
        content: '';
        display: block;
        width: 100%;
        border-bottom: 1px solid #000;
    }
    .drf-theme .drf-ruled .ruled-line .txt {
        position: absolute !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 1px !important;
        font-size: 11pt !important;
        white-space: nowrap !important;
        overflow: hidden !important;
    }
    .drf-theme .drf-dist {
        display: flex !important;
        flex-direction: column !important;
        width: 100% !important;
        height: 100% !important;
    }
    .drf-theme .drf-dist .lbl {
        height: 11pt !important;
        margin: 0 0 2px 0 !important;
        font-size: 11pt !important;
    }
    .drf-theme .drf-dist .grid {
        flex: 1 1 auto !important;
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        column-gap: 0.33in !important;
        min-height: 0 !important;
    }
    .drf-theme .drf-dist .cell {
        height: 12.5pt !important;
        min-height: 12.5pt !important;
        max-height: 12.5pt !important;
        border-bottom: 1px solid #000 !important;
        font-size: 11pt !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        display: flex !important;
        align-items: flex-end !important;
        padding: 0 !important;
    }
    .drf-theme .drf-sig {
        width: 100% !important;
        height: 100% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        font-size: 11pt !important;
        line-height: 1 !important;
        background: transparent !important;
    }
    .drf-theme .drf-sig th,
    .drf-theme .drf-sig td {
        border: 0.5pt solid #000 !important;
        height: 0.21in !important;
        min-height: 0.21in !important;
        max-height: 0.21in !important;
        padding: 0 3px !important;
        vertical-align: middle !important;
        background: transparent !important;
        background-color: transparent !important;
        color: #000 !important;
        font-weight: 400 !important;
        overflow: hidden !important;
        white-space: nowrap !important;
    }
    .drf-theme .drf-sig thead th {
        text-align: center !important;
        font-weight: 400 !important;
        font-size: 11pt !important;
    }
    .drf-theme .drf-sig .row-label {
        text-align: left !important;
        font-weight: 400 !important;
    }
    .drf-theme .drf-sig .name-bold {
        font-weight: 700 !important;
        text-transform: uppercase !important;
        text-align: center !important;
    }
    .drf-theme .drf-footer {
        font-size: 7pt !important;
        font-weight: 700 !important;
        line-height: 1 !important;
    }
</style>

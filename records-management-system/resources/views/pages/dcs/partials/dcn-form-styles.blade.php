{{--
  Shared CSPC-F-DCC-01 Document Change Notice visual styles.
  Used by the layout renderer (office_dcn theme) so canvas edits match the official look.
--}}
<style>
    .dcn-theme {
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 11pt !important;
        line-height: 1 !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .dcn-theme .block {
        position: absolute !important;
        /* Typography comes from each block's inline style (canvas Size / Color / Weight). */
        line-height: 1 !important;
        overflow: visible !important;
        max-height: none;
    }
    .dcn-theme .block-logo img {
        display: block !important;
        width: calc(0.64in * 500 / 272) !important;
        height: calc(0.66in * 500 / 272) !important;
        max-width: none !important;
        max-height: none !important;
        margin-left: calc((0.64in - (0.64in * 500 / 272)) / 2) !important;
        margin-top: calc((0.66in - (0.66in * 500 / 272)) / 2) !important;
        object-fit: fill !important;
    }
    .dcn-theme .block-line-inner {
        height: 1.5pt !important;
        min-height: 1.5pt !important;
        background: #0071BC !important;
    }
    /* Document Change Notice — always underlined like the paper form */
    .dcn-theme .dcn-title,
    .dcn-theme .block-text.is-underline.dcn-title {
        line-height: 1.2 !important;
        text-decoration: none !important;
        text-transform: none !important;
        display: flex !important;
        align-items: flex-end !important;
        justify-content: center !important;
        overflow: visible !important;
    }
    .dcn-theme .dcn-title .dcn-title-text {
        display: inline-block !important;
        line-height: 1.15 !important;
        border-bottom: 1px solid #000 !important;
        padding-bottom: 1px !important;
        text-decoration: none !important;
        /* Inherit Size / Color / Weight from the parent block inline style. */
        font-size: inherit !important;
        font-weight: inherit !important;
        font-family: inherit !important;
        color: inherit !important;
        text-align: inherit !important;
    }
    .dcn-theme .dcn-hdr-text {
        line-height: 1 !important;
    }
    .dcn-theme .dcn-hdr-text.is-name {
        text-transform: uppercase !important;
    }
    .dcn-theme .dcn-form-code {
        white-space: nowrap !important;
    }
    .dcn-theme .dcn-field {
        display: flex !important;
        align-items: flex-end !important;
        width: 100% !important;
        height: 100% !important;
        max-height: 100% !important;
        font-size: inherit !important;
        color: inherit !important;
        line-height: 1 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .dcn-theme .dcn-field .lbl {
        flex: 0 0 auto !important;
        margin: 0 4px 0 0 !important;
        white-space: nowrap !important;
        font-size: inherit !important;
        font-weight: inherit !important;
        color: inherit !important;
        text-decoration: none !important;
    }
    .dcn-theme .dcn-field .fline {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-end !important;
        height: 1em !important;
    }
    .dcn-theme .dcn-field .fline-val {
        font-size: inherit !important;
        color: inherit !important;
        line-height: 1 !important;
        padding: 0 1px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        background: transparent !important;
    }
    .dcn-theme .dcn-field .fline-rule {
        display: block !important;
        width: 100% !important;
        height: 0 !important;
        border-bottom: 1px solid #000 !important;
    }
    .dcn-theme .dcn-ruled {
        display: flex !important;
        flex-direction: column !important;
        width: 100% !important;
        height: 100% !important;
    }
    .dcn-theme .dcn-ruled .lbl {
        display: block !important;
        line-height: 1.15 !important;
        margin: 0 0 2px 0 !important;
        font-size: inherit !important;
        color: inherit !important;
    }
    .dcn-theme .dcn-ruled .ruled-line {
        display: flex !important;
        flex-direction: column !important;
        justify-content: flex-end !important;
        height: 13pt !important;
        min-height: 13pt !important;
        max-height: 13pt !important;
        position: relative !important;
        border-bottom: none !important;
    }
    .dcn-theme .dcn-ruled .ruled-line::after {
        content: '';
        display: block;
        width: 100%;
        border-bottom: 1px solid #000;
    }
    .dcn-theme .dcn-ruled .ruled-line .txt {
        position: absolute !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 1px !important;
        font-size: inherit !important;
        color: inherit !important;
        white-space: nowrap !important;
        overflow: hidden !important;
    }
    .dcn-theme .block-signature .lbl {
        font-size: inherit !important;
        color: inherit !important;
    }
    .dcn-theme .block-table {
        border-collapse: collapse !important;
        width: 100% !important;
        height: 100% !important;
        font-size: inherit !important;
        color: inherit !important;
    }
    .dcn-theme .block-table th,
    .dcn-theme .block-table td {
        border: 1px solid #000 !important;
        padding: 2px 4px !important;
        text-align: center !important;
        font-size: inherit !important;
        color: inherit !important;
    }
    .dcn-theme .block-table.no-borders th,
    .dcn-theme .block-table.no-borders td {
        border: none !important;
        border-color: transparent !important;
    }
    .dcn-theme .block-table th {
        background: transparent !important;
        font-weight: 700 !important;
    }
    .dcn-theme .dcn-footer {
        line-height: 1 !important;
    }
</style>

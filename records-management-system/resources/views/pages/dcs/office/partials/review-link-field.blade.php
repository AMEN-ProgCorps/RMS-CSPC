{{--
  DCS PARTIAL: Optional Document Link for Review (DRF & DCN create/edit)
  FILE: resources/views/pages/dcs/office/partials/review-link-field.blade.php
--}}
@php
    $reviewLinkValue = (string) ($value ?? '');
    $reviewLinkId = (string) ($inputId ?? 'reviewDocumentLink');
@endphp
<div class="ofi-review-link-card">
    <div class="ofi-review-link-head">
        <span class="ofi-review-link-icon" aria-hidden="true"><i class="fa-solid fa-link"></i></span>
        <div class="ofi-review-link-meta">
            <div class="ofi-review-link-title-row">
                <label for="{{ $reviewLinkId }}">Document link for review</label>
                <span class="ofi-review-link-opt">Optional</span>
            </div>
            <p class="ofi-review-link-sub">Share a Google Docs / Drive link if you want Records to review and comment on the main document before registration.</p>
        </div>
    </div>
    <div class="ofi-review-link-input-row">
        <span class="ofi-review-link-prefix" aria-hidden="true"><i class="fa-solid fa-globe"></i></span>
        <input
            type="url"
            id="{{ $reviewLinkId }}"
            name="reviewDocumentLink"
            class="ofi-review-link-input"
            value="{{ $reviewLinkValue }}"
            maxlength="2000"
            placeholder="https://docs.google.com/document/d/..."
            autocomplete="off"
        >
        <a
            href="{{ $reviewLinkValue !== '' ? $reviewLinkValue : '#' }}"
            target="_blank"
            rel="noopener noreferrer"
            class="ofi-review-link-open{{ $reviewLinkValue === '' ? ' is-disabled' : '' }}"
            id="{{ $reviewLinkId }}OpenBtn"
            @if($reviewLinkValue === '') aria-disabled="true" tabindex="-1" @endif
        >
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open
        </a>
    </div>
</div>
<style>
.ofi-review-link-card {
    margin: 6px 0 4px;
    padding: 13px 15px;
    border: 1.5px solid #dbe6f7;
    border-radius: 11px;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ofi-review-link-head {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.ofi-review-link-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #e8f0fe;
    color: #0d2a7a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    flex-shrink: 0;
    margin-top: 1px;
}
.ofi-review-link-meta {
    min-width: 0;
    flex: 1 1 auto;
}
.ofi-review-link-title-row {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}
.ofi-review-link-title-row label {
    margin: 0 !important;
    font-size: 0.82rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    text-transform: none !important;
    letter-spacing: 0 !important;
}
.ofi-review-link-opt {
    display: inline-flex;
    align-items: center;
    padding: 1px 8px;
    border-radius: 999px;
    background: #eef2f7;
    color: #475569;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.ofi-review-link-sub {
    margin: 2px 0 0;
    font-size: 0.76rem;
    color: #64748b;
    line-height: 1.35;
}
.ofi-review-link-input-row {
    display: flex;
    align-items: center;
    gap: 0;
    border: 1.5px solid #cbd5e1;
    border-radius: 9px;
    background: #ffffff;
    overflow: hidden;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.ofi-review-link-input-row:focus-within {
    border-color: #0d2a7a;
    box-shadow: 0 0 0 3px rgba(13, 42, 122, 0.10);
}
.ofi-review-link-prefix {
    padding: 0 10px 0 12px;
    color: #64748b;
    font-size: 0.82rem;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}
.ofi-review-link-input {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    border: none !important;
    box-shadow: none !important;
    outline: none !important;
    background: transparent !important;
    padding: 9px 10px 9px 0 !important;
    font-size: 0.84rem !important;
    color: #0f172a !important;
}
.ofi-review-link-open {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 13px;
    margin: 3px;
    border-radius: 7px;
    background: #0d2a7a;
    color: #ffffff !important;
    font-size: 0.76rem;
    font-weight: 600;
    text-decoration: none !important;
    flex-shrink: 0;
    transition: background 0.15s ease, opacity 0.15s ease;
}
.ofi-review-link-open:hover {
    background: #163b9e;
}
.ofi-review-link-open.is-disabled {
    background: #e2e8f0;
    color: #94a3b8 !important;
    pointer-events: none;
}
</style>
<script>
(function () {
    var inp = document.getElementById(@json($reviewLinkId));
    var btn = document.getElementById(@json($reviewLinkId . 'OpenBtn'));
    if (!inp || !btn) return;
    function sync() {
        var val = (inp.value || '').trim();
        var ok = /^https?:\/\/.+/i.test(val);
        btn.href = ok ? val : '#';
        btn.classList.toggle('is-disabled', !ok);
        if (ok) {
            btn.removeAttribute('aria-disabled');
            btn.removeAttribute('tabindex');
        } else {
            btn.setAttribute('aria-disabled', 'true');
            btn.setAttribute('tabindex', '-1');
        }
    }
    inp.addEventListener('input', sync);
    sync();
})();
</script>

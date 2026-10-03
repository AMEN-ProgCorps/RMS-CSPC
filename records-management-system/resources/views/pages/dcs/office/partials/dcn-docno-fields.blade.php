{{-- Shared Document No. search for office DCN create/edit --}}
@php
    $initialDocNo = trim((string) ($initialDocNo ?? ''));
    $initialDocTitle = trim((string) ($initialDocTitle ?? ''));
    $initialReviseNo = $initialReviseNo ?? null;
    $forceConfirmed = (bool) ($forceConfirmed ?? false);
    $hasInitialSelection = $initialDocNo !== '' && (
        $forceConfirmed || old('documentNoConfirmed') === '1'
    );
@endphp
<div class="ofi-dcn-doc-fields">
    <div class="reg-field">
        <label for="dcnDocumentTitle">Document Title <span class="ofi-req">*</span></label>
        <input type="text" id="dcnDocumentTitle" name="documentTitle"
            value="{{ $initialDocTitle }}"
            required maxlength="255"
            placeholder="Filled from the registered document (editable)"
            autocomplete="off">
    </div>
    <div class="reg-field ofi-docno-field">
        <label for="dcnDocumentNo">Document no. <span class="ofi-req">*</span></label>
        <div class="ofi-docno-search-wrap">
            <input type="text" id="dcnDocumentNo" name="documentNo"
                value="{{ $initialDocNo }}"
                required maxlength="150"
                placeholder="Search a registered Internal or External Document No."
                autocomplete="off"
                aria-autocomplete="list"
                aria-controls="dcnDocNoResults"
                aria-expanded="false">
            <div id="dcnDocNoResults" class="reg-reldocs-dropdown ofi-docno-results" style="display:none;" role="listbox"></div>
        </div>
        <input type="hidden" id="dcnDocNoConfirmed" name="documentNoConfirmed" value="{{ $hasInitialSelection ? '1' : '' }}">
        <div id="dcnDocNoChip" class="ofi-docno-selected" data-revise="{{ $initialReviseNo !== null ? $initialReviseNo : '' }}" @if(! $hasInitialSelection) hidden @endif>
            <div class="ofi-docno-selected-head">
                <span class="ofi-docno-selected-kicker">Selected document</span>
                <button type="button" class="ofi-docno-selected-clear" id="dcnDocNoClear">Clear</button>
            </div>
            <div class="ofi-docno-selected-no-row">
                <strong id="dcnDocNoChipNo">{{ $hasInitialSelection ? $initialDocNo : '' }}</strong>
                <span class="ofi-docno-selected-rev" id="dcnDocNoChipRev" @if($initialReviseNo === null) hidden @endif>@if($initialReviseNo !== null)Rev {{ $initialReviseNo }}@endif</span>
            </div>
            <p class="ofi-docno-selected-title" id="dcnDocNoChipTitle">{{ $hasInitialSelection ? $initialDocTitle : '' }}</p>
        </div>
        <p class="ofi-hint">Search and confirm an existing registered <strong>Internal</strong> or <strong>External</strong> Document No. that allows revision. Forms, Internal Forms, and Logbooks are not listed here.</p>
    </div>
</div>

<div id="dcnDocNoUseModal" class="ofi-docno-use-overlay" hidden aria-hidden="true">
    <div class="ofi-docno-use-modal" role="dialog" aria-modal="true" aria-labelledby="dcnDocNoUseTitle">
        <div class="ofi-docno-use-header">
            <div>
                <span class="ofi-docno-use-kicker">Confirm document</span>
                <h3 id="dcnDocNoUseTitle">Use this registered document?</h3>
            </div>
            <button type="button" class="ofi-docno-use-close" id="dcnDocNoUseClose" aria-label="Close">&times;</button>
        </div>
        <div class="ofi-docno-use-body">
            <p class="ofi-docno-use-lead">This Document Change Notice will revise the selected registration.</p>
            <dl class="ofi-docno-use-meta">
                <div>
                    <dt>Document No.</dt>
                    <dd id="dcnDocNoUseNo">—</dd>
                </div>
                <div>
                    <dt>Title</dt>
                    <dd id="dcnDocNoUseTitleText">—</dd>
                </div>
                <div>
                    <dt>Revision</dt>
                    <dd id="dcnDocNoUseRev">—</dd>
                </div>
                <div>
                    <dt>Type</dt>
                    <dd id="dcnDocNoUseType">—</dd>
                </div>
            </dl>
        </div>
        <div class="ofi-docno-use-footer">
            <button type="button" class="reg-btn reg-btn-cancel" id="dcnDocNoUseCancel">Cancel</button>
            <button type="button" class="reg-btn reg-btn-save" id="dcnDocNoUseConfirm">
                <i class="fa-solid fa-check"></i> Use this document
            </button>
        </div>
    </div>
</div>

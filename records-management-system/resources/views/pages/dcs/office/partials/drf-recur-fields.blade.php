{{--
DCS file: DRF recurring finder. Same confidential match as DCN / admin Recur:
document number + current title + originator. Only the latest revision can be recurred.
--}}
@php
    $finderNo = trim((string) old('finderDocumentNo', $initialFinderNo ?? ''));
    $finderTitle = trim((string) old('finderCurrentTitle', $initialFinderTitle ?? ''));
    $finderOriginator = trim((string) old('finderOriginator', $initialFinderOriginator ?? ''));
    $confirmed = old('documentNoConfirmed') === '1';
    $reviseNo = old('finderReviseNo', $initialReviseNo ?? '');
    $docType = old('finderDocType', $initialDocType ?? '');
    $storedNo = trim((string) old('documentNo', $confirmed ? $finderNo : ''));
@endphp
<section class="reg-card ofi-dcn-finder-card ofi-drf-recur-finder">
    <div class="reg-card-header">
        <span>Find the document to recur</span>
    </div>
    <div class="reg-card-body">
        <p class="ofi-hint ofi-dcn-finder-lead">Type the document number, title, and originator printed on the controlled copy, then Find document. Pick the matching document from the list. Only the latest revision can be recurred.</p>
        <div class="ofi-dcn-finder-grid">
            <div class="reg-field">
                <label for="drfFinderDocumentNo">Registered document no. <span class="ofi-req">*</span></label>
                <input type="text" id="drfFinderDocumentNo" name="finderDocumentNo"
                    value="{{ $finderNo }}"
                    required maxlength="150"
                    placeholder="Type the full document number from your copy"
                    autocomplete="off">
            </div>
            <div class="reg-field">
                <label for="drfFinderCurrentTitle">Current document title <span class="ofi-req">*</span></label>
                <input type="text" id="drfFinderCurrentTitle" name="finderCurrentTitle"
                    value="{{ $finderTitle }}"
                    required
                    placeholder="Type the title printed on the current document"
                    autocomplete="off">
            </div>
            <div class="reg-field">
                <label for="drfFinderOriginator">Registered originator <span class="ofi-req">*</span></label>
                <input type="text" id="drfFinderOriginator" name="finderOriginator"
                    value="{{ $finderOriginator }}"
                    required
                    placeholder="Type the originator on the current document"
                    autocomplete="off">
            </div>
        </div>
        <div class="ofi-dcn-finder-actions">
            <button type="button" class="reg-btn reg-btn-save" id="drfDocNoFind">Find document</button>
            <p id="drfDocNoFindStatus" class="ofi-hint" role="status" @if(! $confirmed) hidden @endif>
                @if($confirmed)
                    Document found{{ $docType !== '' ? ' · ' . $docType : '' }}{{ $reviseNo !== '' && $reviseNo !== null ? ' · Rev ' . $reviseNo : '' }}.
                @endif
            </p>
        </div>
        <div id="drfDocNoSuggestions" class="ofi-suggest" hidden></div>
        <div id="drfSelectedDocument" class="ofi-dcn-selected" @if(! $confirmed) hidden @endif>
            <div class="ofi-dcn-selected-head">
                <span>Selected document</span>
                <button type="button" class="ofi-dcn-reselect" id="drfDocNoReselect">Select a different document</button>
            </div>
            <p id="drfSelectedDuplicate" class="ofi-alert err" hidden></p>
            <dl class="ofi-dcn-selected-meta">
                <div>
                    <dt>Document no.</dt>
                    <dd id="drfSelectedNo">{{ $storedNo !== '' ? $storedNo : $finderNo }}</dd>
                </div>
                <div>
                    <dt>Title</dt>
                    <dd id="drfSelectedTitle">{{ $finderTitle }}</dd>
                </div>
                <div>
                    <dt>Originator</dt>
                    <dd id="drfSelectedOriginator">{{ $finderOriginator }}</dd>
                </div>
                <div>
                    <dt>Type</dt>
                    <dd id="drfSelectedType">{{ $docType !== '' ? $docType : '—' }}</dd>
                </div>
                <div>
                    <dt>Revision</dt>
                    <dd id="drfSelectedRev">{{ $reviseNo !== '' && $reviseNo !== null ? 'Rev '.$reviseNo : 'Latest' }}</dd>
                </div>
            </dl>
        </div>
        <input type="hidden" id="drfDocumentNo" name="documentNo" value="{{ $storedNo }}">
        <input type="hidden" id="drfDocNoConfirmed" name="documentNoConfirmed" value="{{ $confirmed ? '1' : '' }}">
        <input type="hidden" id="drfFinderReviseNo" name="finderReviseNo" value="{{ $reviseNo }}">
        <input type="hidden" id="drfFinderDocType" name="finderDocType" value="{{ $docType }}">
        <input type="hidden" id="drfFinderMasterlistId" name="finderMasterlistId" value="{{ old('finderMasterlistId', $initialMasterlistId ?? '') }}">
        <input type="hidden" id="drfFinderRequestId" name="finderRequestId" value="{{ old('finderRequestId', $initialRequestId ?? '') }}">
        <input type="hidden" name="drfMode" value="recurring">
    </div>
</section>

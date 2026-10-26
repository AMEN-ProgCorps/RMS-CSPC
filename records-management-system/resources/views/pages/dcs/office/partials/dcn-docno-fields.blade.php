{{--
DCS file: DCN finder. The office types the current document number, title, and originator. Only the latest revision can be revised.
--}}
{{-- Confidential document finder: exact number + current title + originator. No result list. --}}
@php
    $finderNo = trim((string) old('finderDocumentNo', $initialFinderNo ?? $initialDocNo ?? ''));
    $finderTitle = trim((string) old('finderCurrentTitle', $initialFinderTitle ?? ''));
    $finderOriginator = trim((string) old('finderOriginator', $initialFinderOriginator ?? ''));
    $confirmed = old('documentNoConfirmed') === '1';
    $reviseNo = old('finderReviseNo', $initialReviseNo ?? '');
    $docType = old('finderDocType', $initialDocType ?? '');
    $storedNo = trim((string) old('documentNo', $confirmed ? $finderNo : ($initialDocNo ?? '')));
@endphp
<section class="reg-card ofi-dcn-finder-card">
    <div class="reg-card-header">
        <span>Find the document to revise</span>
    </div>
    <div class="reg-card-body">
        <p class="ofi-hint ofi-dcn-finder-lead">Type the document number, title, and originator printed on the controlled copy, then Find document. Pick the matching document from the list. Only the latest revision can be revised.</p>
        <div class="ofi-dcn-finder-grid">
            <div class="reg-field">
                <label for="dcnFinderDocumentNo">Registered document no. <span class="ofi-req">*</span></label>
                <input type="text" id="dcnFinderDocumentNo" name="finderDocumentNo"
                    value="{{ $finderNo }}"
                    required maxlength="150"
                    placeholder="Type the full document number from your copy"
                    autocomplete="off">
            </div>
            <div class="reg-field">
                <label for="dcnFinderCurrentTitle">Current document title <span class="ofi-req">*</span></label>
                <input type="text" id="dcnFinderCurrentTitle" name="finderCurrentTitle"
                    value="{{ $finderTitle }}"
                    required
                    placeholder="Type the title printed on the current document"
                    autocomplete="off">
            </div>
            <div class="reg-field">
                <label for="dcnFinderOriginator">Registered originator <span class="ofi-req">*</span></label>
                <input type="text" id="dcnFinderOriginator" name="finderOriginator"
                    value="{{ $finderOriginator }}"
                    required
                    placeholder="Type the originator on the current document"
                    autocomplete="off">
            </div>
        </div>
        <div class="ofi-dcn-finder-actions">
            <button type="button" class="reg-btn reg-btn-save" id="dcnDocNoFind">Find document</button>
            <p id="dcnDocNoFindStatus" class="ofi-hint" role="status" @if(! $confirmed) hidden @endif>
                @if($confirmed)
                    Document found{{ $docType !== '' ? ' · ' . $docType : '' }}{{ $reviseNo !== '' && $reviseNo !== null ? ' · Rev ' . $reviseNo : '' }}.
                @endif
            </p>
        </div>
        <div id="dcnDocNoSuggestions" class="ofi-suggest" hidden></div>
        <div id="dcnSelectedDocument" class="ofi-dcn-selected" @if(! $confirmed) hidden @endif>
            <div class="ofi-dcn-selected-head">
                <span>Selected document</span>
                <button type="button" class="ofi-dcn-reselect" id="dcnDocNoReselect">Select a different document</button>
            </div>
            <p id="dcnSelectedDuplicate" class="ofi-alert err" hidden></p>
            <dl class="ofi-dcn-selected-meta">
                <div>
                    <dt>Document no.</dt>
                    <dd id="dcnSelectedNo">{{ $storedNo !== '' ? $storedNo : $finderNo }}</dd>
                </div>
                <div>
                    <dt>Title</dt>
                    <dd id="dcnSelectedTitle">{{ $finderTitle }}</dd>
                </div>
                <div>
                    <dt>Originator</dt>
                    <dd id="dcnSelectedOriginator">{{ $finderOriginator }}</dd>
                </div>
                <div>
                    <dt>Type</dt>
                    <dd id="dcnSelectedType">{{ $docType !== '' ? $docType : '—' }}</dd>
                </div>
                <div>
                    <dt>Revision</dt>
                    <dd id="dcnSelectedRev">{{ $reviseNo !== '' && $reviseNo !== null ? 'Rev '.$reviseNo : 'Latest' }}</dd>
                </div>
            </dl>
        </div>
        <input type="hidden" id="dcnDocumentNo" name="documentNo" value="{{ $storedNo }}">
        <input type="hidden" id="dcnDocNoConfirmed" name="documentNoConfirmed" value="{{ $confirmed ? '1' : '' }}">
        <input type="hidden" id="dcnFinderReviseNo" name="finderReviseNo" value="{{ $reviseNo }}">
        <input type="hidden" id="dcnFinderDocType" name="finderDocType" value="{{ $docType }}">
    </div>
</section>

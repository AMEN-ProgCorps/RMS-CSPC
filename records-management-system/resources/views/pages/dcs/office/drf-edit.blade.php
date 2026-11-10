<?php

/**
 * DCS file: Edit an office DRF while it is still editable. Same title and originator rules as create.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Edit DRF — CSPC DCS')] class extends Component {
    public int $id;

    public function mount($id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $this->id = (int) $id;
        abort_unless(OfficeIntakeHelper::canOfficeEditIntake('drf', $this->id), 403, OfficeIntakeHelper::IMMUTABLE_MESSAGE);
    }

    public function with(): array
    {
        $drf = OfficeIntakeHelper::findOfficeDrf($this->id);
        abort_unless($drf, 404);

        $distributeIds = OfficeIntakeHelper::distributeOfficeIds((int) $drf->id);
        $lockDocType = (int) ($drf->source_office_dcn_id ?? 0) > 0;
        $lockedTypeLabel = '';
        if ($lockDocType) {
            $dcn = OfficeIntakeHelper::findOfficeDcn((int) $drf->source_office_dcn_id);
            $docNo = trim((string) ($dcn?->previous_document_no ?? ''));
            if ($docNo === '') {
                $docNo = trim((string) ($dcn?->document_no ?? ''));
            }
            $registered = OfficeIntakeHelper::latestRegistrationForDocNo($docNo);
            if ($registered) {
                $subName = trim((string) ($registered->sub_type_name ?? ''));
                $typeName = trim((string) ($registered->doc_type_name ?? ''));
                $lockedTypeLabel = $subName !== '' ? $subName : $typeName;
            }
        }

        $source = OfficeIntakeHelper::drfSourceOffices((int) $drf->id)->first();
        $office = OfficeIntakeHelper::currentUserOfficeForDcn();
        if ($source) {
            $office = [
                'id' => (int) ($source->office_id ?? 0),
                'code' => trim((string) ($source->office_code ?? '')),
                'name' => trim((string) ($source->office_name ?? '')),
                'label' => trim((string) (($source->office_code ?? '') !== '' && ($source->office_name ?? '') !== ''
                    ? $source->office_code.' — '.$source->office_name
                    : (($source->office_name ?? '') !== '' ? $source->office_name : ($source->office_code ?? '')))),
            ];
        }

        $docTypeId = (int) ($drf->doc_type_id ?? 0);
        $subTypeId = (int) ($drf->sub_type_id ?? 0);
        $docTypeName = $docTypeId > 0
            ? trim((string) (\Illuminate\Support\Facades\DB::table('dcs_doc_types')->where('id', $docTypeId)->value('doc_type_name') ?? ''))
            : '';
        $subTypeName = $subTypeId > 0
            ? trim((string) (\Illuminate\Support\Facades\DB::table('dcs_doc_types')->where('id', $subTypeId)->value('doc_type_name') ?? ''))
            : '';

        return [
            'drf' => $drf,
            'offices' => RegisterQueryHelper::jsCatalog()['offices'] ?? [],
            'clusters' => RegisterQueryHelper::jsCatalog()['clusters'] ?? [],
            'oldDistributeOfficeIds' => array_values(array_unique(array_filter(array_map(
                'intval',
                (array) old('distributeToOffice', $distributeIds)
            )))),
            'editReason' => trim((string) ($drf->edit_unlock_reason ?? '')),
            'lockDocType' => $lockDocType,
            'lockedTypeLabel' => $lockedTypeLabel,
            'optInTypeRadios' => OfficeIntakeHelper::enabledOfficeDrfTypeRadios(),
            'syllabiCatalog' => OfficeIntakeHelper::officeDrfSyllabiCatalog((int) ($drf->id ?? 0)),
            'isSyllabiLikeEdit' => RegisterQueryHelper::isSyllabiLikeName($subTypeName),
            'docTypeName' => $docTypeName,
            'subTypeName' => $subTypeName,
            'userOfficeId' => $office['id'],
            'userOfficeCode' => $office['code'],
            'userOfficeName' => $office['name'],
            'userOfficeLabel' => $office['label'] !== '' ? $office['label'] : 'Your office',
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.drf.show', $drf->id, absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <p class="ofi-toolbar-hint">Correct the form, then save to resubmit to RFIO. The document will lock again after save.</p>
        </div>

        @if($editReason !== '')
            <div class="ofi-alert err ofi-correction-alert" role="status">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div class="ofi-alert-body">
                    <strong>RFIO asked for corrections</strong>
                    <span>{{ $editReason }}</span>
                </div>
            </div>
        @endif

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.drf.update', $drf->id, absolute: false) }}" id="ofiDrfForm">
            @csrf
            @method('PUT')
            <section class="reg-card" id="section-1">
                <div class="reg-card-header">
                    <span>Document Request Form</span>
                </div>
                <div class="reg-card-body ofi-drf-form">
                    <div class="reg-field">
                        <label>Originator <span class="ofi-req">*</span></label>
                        <input type="text" name="originatorName" value="{{ old('originatorName', $drf->originator_name) }}" required placeholder="Separate multiple originators with a comma">
                    </div>
                    <div class="reg-grid-2-1">
                        @include('pages.dcs.office.partials.dcn-department-field', [
                            'userOfficeId' => $userOfficeId ?? 0,
                            'userOfficeCode' => $userOfficeCode ?? '',
                            'userOfficeName' => $userOfficeName ?? '',
                            'userOfficeLabel' => $userOfficeLabel ?? '',
                            'departmentFieldLabel' => 'Source unit',
                        ])
                        <div class="reg-field">
                            <label>Date <span class="ofi-req">*</span></label>
                            <input type="date" id="drfDate" name="drfDate" value="{{ old('drfDate', $drf->drf_date ? \Carbon\Carbon::parse($drf->drf_date)->format('Y-m-d') : '') }}" required>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label>Document Title <span class="ofi-req">*</span></label>
                        <input type="text" id="drfTitle" name="drfTitle" value="{{ old('drfTitle', $drf->doc_title) }}" required placeholder="Enter document title">
                    </div>
                    <div class="reg-field">
                        <label>Type of document <span class="ofi-req">*</span></label>
                        @php
                            $docTypeKind = old('docTypeKind', $drf->doc_type_kind ?: 'internal');
                            $optInRadios = $optInTypeRadios ?? [];
                            $optInDocTypeId = '';
                            foreach ($optInRadios as $opt) {
                                if (($opt['slug'] ?? '') === $docTypeKind) {
                                    $optInDocTypeId = (string) ($opt['id'] ?? '');
                                    break;
                                }
                            }
                            $savedDocTypeId = (string) old('docTypeId', (string) ($drf->doc_type_id ?? ''));
                            $savedSubTypeId = (string) old('subTypeId', (string) ($drf->sub_type_id ?? ''));
                            $lockedDocTypeDisplay = trim((string) ($docTypeName ?? ''));
                            $lockedSubTypeDisplay = trim((string) ($subTypeName ?? ''));
                            if ($lockedDocTypeDisplay === '' && ! empty($lockedTypeLabel)) {
                                $lockedDocTypeDisplay = trim((string) $lockedTypeLabel);
                            }
                            if ($lockedDocTypeDisplay === '') {
                                $lockedDocTypeDisplay = match ($docTypeKind) {
                                    'external' => 'External',
                                    'internal' => 'Internal',
                                    default => collect($optInRadios)->firstWhere('slug', $docTypeKind)['label'] ?? ucfirst((string) $docTypeKind),
                                };
                            }
                            if ($lockedSubTypeDisplay === '') {
                                $lockedSubTypeDisplay = '—';
                            }
                        @endphp
                        {{-- Edit keeps type/sub-type fixed; values are submitted as hidden fields. --}}
                        <input type="hidden" name="docTypeKind" value="{{ $docTypeKind }}">
                        @if($optInDocTypeId !== '')
                            <input type="hidden" name="docTypeId" value="{{ $optInDocTypeId }}">
                        @elseif($savedDocTypeId !== '')
                            <input type="hidden" name="docTypeId" value="{{ $savedDocTypeId }}">
                        @endif
                        @if($savedSubTypeId !== '')
                            <input type="hidden" name="subTypeId" id="drfSubTypeId" value="{{ $savedSubTypeId }}">
                        @endif
                        <div class="ofi-radio-row">
                            <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === 'internal')> Internal</label>
                            <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === 'external')> External</label>
                            @foreach($optInRadios as $opt)
                                <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === $opt['slug'])> {{ $opt['label'] }}</label>
                            @endforeach
                        </div>
                        <div class="reg-grid-2 ofi-locked-type-grid" aria-label="Document type and sub-type">
                            <div class="reg-field">
                                <label for="ofiLockedDocType">Document type</label>
                                <input
                                    id="ofiLockedDocType"
                                    type="text"
                                    class="ofi-input-locked"
                                    value="{{ $lockedDocTypeDisplay }}"
                                    disabled
                                    readonly
                                >
                            </div>
                            <div class="reg-field">
                                <label for="ofiLockedSubType">Sub-type</label>
                                <input
                                    id="ofiLockedSubType"
                                    type="text"
                                    class="ofi-input-locked"
                                    value="{{ $lockedSubTypeDisplay }}"
                                    disabled
                                    readonly
                                >
                            </div>
                        </div>
                        <p class="ofi-hint">Document type and sub-type cannot be changed while correcting this request.</p>
                        @include('pages.dcs.office.partials.drf-syllabi-context', [
                            'syllabiContextVisible' => (bool) ($isSyllabiLikeEdit ?? false),
                            'subTypeName' => $subTypeName ?? '',
                            'oldCollegeId' => old('collegeId', (string) ($drf->college_id ?? '')),
                            'oldProgramId' => old('programId', (string) ($drf->program_id ?? '')),
                            'oldSemesterId' => old('semesterId', (string) ($drf->semester_id ?? '')),
                            'oldCourseId' => old('courseId', (string) ($drf->course_id ?? '')),
                            'oldSchoolYearId' => old('schoolYearId', (string) ($drf->school_year_id ?? '')),
                        ])
                    </div>
                    <div class="reg-field">
                        <label>Description/reason for request (define in detail) <span class="ofi-req">*</span></label>
                        <textarea id="descriptionReason" name="descriptionReason" rows="4" required maxlength="5000" placeholder="For document control and dissemination">{{ old('descriptionReason', $drf->description_reason) }}</textarea>
                    </div>
                    <div class="reg-field ofi-distribute-field">
                        <label class="ofi-distribute-label">
                            <span>Distribute document to (department/position)</span>
                            <span class="ofi-total-offices" aria-live="polite">
                                total offices: <strong data-ofi-selected-count="distribute">0</strong>
                            </span>
                        </label>
                        <div class="reg-cluster-chips" data-ofi-cluster-widget="distribute" aria-label="Select offices by cluster"></div>
                        @if($lockDocType ?? false)
                            <p class="ofi-hint">These offices are the same as the previous distribution of the document being revised. You can add more offices.</p>
                        @endif
                        <p class="ofi-hint">
                            Leave empty if this controlled document is for your office only — your office is already Source Unit.
                            Add other offices only when they need a distributed copy (Incoming). Selecting your own office does not put the document on your Incoming list.
                            Search by office name or code — selected offices show their <strong>full name</strong> here.
                            If more than 24 offices are selected, the print form shows “Please see attached list of offices” and lists them on following pages.
                            Click the <i class="fa-solid fa-chevron-down ofi-hint-icon"></i> arrow to view or remove selected offices.
                        </p>
                        <div class="reg-reldocs" id="drfDistributeWidget">
                            <div class="reg-reldocs-inputwrap">
                                <input type="text" id="drfDistributeSearch" class="reg-reldocs-input"
                                    placeholder="Type to search offices..." autocomplete="off">
                                <button type="button" class="reg-reldocs-arrow-btn" id="drfDistributeArrowBtn" title="View selected offices">
                                    <i class="fa-solid fa-chevron-down"></i>
                                    <span class="ofi-arrow-count" data-ofi-arrow-count="distribute" hidden>0</span>
                                </button>
                            </div>
                            <div id="drfDistributeResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                            <div id="drfDistributeInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                        </div>
                    </div>

                    <div class="ofi-sig-block">
                        <p class="ofi-sig-heading">Signatories</p>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Prepared by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="preparedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="preparedByName" name="preparedByName"
                                        value="{{ old('preparedByName', data_get($drf, 'prepared_by_name')) }}"
                                        required maxlength="255" placeholder="Prepared by name">
                                </div>
                                <div class="reg-field">
                                    <label for="preparedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="preparedByDesignation" name="preparedByDesignation"
                                        value="{{ old('preparedByDesignation', data_get($drf, 'prepared_by_designation', '')) }}"
                                        required maxlength="255" placeholder="Prepared by designation">
                                </div>
                            </div>
                        </div>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Reviewed by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="reviewedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="reviewedByName" name="reviewedByName"
                                        value="{{ old('reviewedByName', data_get($drf, 'reviewed_by_name', '')) }}"
                                        required maxlength="255" placeholder="Reviewed by name">
                                </div>
                                <div class="reg-field">
                                    <label for="reviewedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="reviewedByDesignation" name="reviewedByDesignation"
                                        value="{{ old('reviewedByDesignation', data_get($drf, 'reviewed_by_designation', '')) }}"
                                        required maxlength="255" placeholder="Reviewed by designation">
                                </div>
                            </div>
                        </div>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Approved by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="approvedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="approvedByName" name="approvedByName"
                                        value="{{ old('approvedByName', data_get($drf, 'approved_by_name', '')) }}"
                                        required maxlength="255" placeholder="Approved by name">
                                </div>
                                <div class="reg-field">
                                    <label for="approvedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="approvedByDesignation" name="approvedByDesignation"
                                        value="{{ old('approvedByDesignation', data_get($drf, 'approved_by_designation', '')) }}"
                                        required maxlength="255" placeholder="Approved by designation">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="ofi-confirm-block">
                <label class="ofi-confirm-check">
                    <input type="checkbox" id="ofiConfirmData" name="confirmDataCorrect" value="1" required>
                    <span>I am confirming all the inputted data are correct.</span>
                </label>
            </div>

            <div class="reg-form-actions ofi-reg-actions">
                <button type="submit" id="ofiDrfPreviewBtn" class="reg-btn ofi-btn-preview" formnovalidate formtarget="_blank" formaction="{{ route('dcs.office.drf.preview', absolute: false) }}">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i> Preview form
                </button>
                <button type="submit" id="ofiDrfSaveBtn" class="reg-btn reg-btn-save" disabled>
                    <i class="fa-solid fa-paper-plane"></i> Save &amp; resubmit to RFIO
                </button>
            </div>
        </form>
    </div>

    <div class="ofi-confirm-dialog-overlay" id="ofiAlertDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="ofiAlertDialogTitle">
        <div class="ofi-confirm-dialog">
            <div class="ofi-confirm-dialog-header">
                <span class="ofi-confirm-dialog-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <h3 id="ofiAlertDialogTitle">Cannot save</h3>
            </div>
            <div class="ofi-confirm-dialog-body" id="ofiAlertDialogMessage"></div>
            <div class="ofi-confirm-dialog-footer">
                <button type="button" class="ofi-confirm-dialog-btn ofi-confirm-dialog-btn-ok" data-ofi-alert-ok>OK</button>
            </div>
        </div>
    </div>
</div>

<script>
window.__ofiOffices = @json($offices);
window.__ofiClusters = @json($clusters);
window.__ofiOldDistribute = @json($oldDistributeOfficeIds);
window.__ofiSyllabiCatalog = @json($syllabiCatalog ?? []);
window.__ofiSourceConfigs = [
    {
        key: 'distribute',
        widgetId: 'drfDistributeWidget',
        inputId: 'drfDistributeSearch',
        arrowId: 'drfDistributeArrowBtn',
        resultsId: 'drfDistributeResults',
        chipsId: 'drfDistributeInlineChips',
        officeFieldName: 'distributeToOffice[]',
        oldIds: window.__ofiOldDistribute,
        seedDefaultOffice: false,
        labelFormat: 'name',
    },
];
</script>
<script src="{{ asset('js/dcs/office-intake.js') }}?v={{ @filemtime(public_path('js/dcs/office-intake.js')) ?: time() }}"></script>
<script>
(function () {
    const form = document.getElementById('ofiDrfForm');
    const confirmBox = document.getElementById('ofiConfirmData');
    const saveBtn = document.getElementById('ofiDrfSaveBtn');
    if (!form || !confirmBox || !saveBtn) return;

    function showOfiAlert(message, title) {
        const overlay = document.getElementById('ofiAlertDialog');
        if (!overlay) {
            window.alert(message);
            return;
        }
        if (overlay.parentElement !== document.body) {
            document.body.appendChild(overlay);
        }
        const titleEl = overlay.querySelector('#ofiAlertDialogTitle');
        const messageEl = overlay.querySelector('#ofiAlertDialogMessage');
        const okBtn = overlay.querySelector('[data-ofi-alert-ok]');
        if (titleEl) titleEl.textContent = title || 'Cannot save';
        if (messageEl) messageEl.textContent = message;
        function close() {
            overlay.classList.remove('is-open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('ofi-modal-open');
            document.removeEventListener('keydown', onKey);
            overlay.removeEventListener('click', onBackdrop);
            if (okBtn) okBtn.removeEventListener('click', onOk);
        }
        function onOk() { close(); }
        function onBackdrop(e) { if (e.target === overlay) close(); }
        function onKey(e) { if (e.key === 'Escape' || e.key === 'Enter') close(); }
        overlay.addEventListener('click', onBackdrop);
        document.addEventListener('keydown', onKey);
        if (okBtn) okBtn.addEventListener('click', onOk);
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ofi-modal-open');
        if (okBtn) okBtn.focus();
    }

    function syncConfirm() {
        saveBtn.disabled = !confirmBox.checked;
        saveBtn.setAttribute('aria-disabled', confirmBox.checked ? 'false' : 'true');
    }

    confirmBox.addEventListener('change', syncConfirm);
    syncConfirm();

    const reason = document.getElementById('descriptionReason');
    if (reason) {
        reason.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || e.shiftKey || reason.value.trim() !== '') return;
            e.preventDefault();
            reason.value = 'For document control and dissemination';
            const end = reason.value.length;
            reason.setSelectionRange(end, end);
            reason.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }
    // Enter in date/text fields would implicit-submit via the first submit button (Preview).
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        const t = e.target;
        if (!t || t.tagName === 'TEXTAREA') return;
        if (t.tagName === 'BUTTON') return;
        if (t.tagName === 'INPUT' && (t.type === 'submit' || t.type === 'button')) return;
        e.preventDefault();
    });

    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.id === 'ofiDrfPreviewBtn') return;
        if (!confirmBox.checked) {
            e.preventDefault();
            showOfiAlert('Please confirm that all the inputted data are correct before saving.');
            return;
        }

        // Empty distribution is allowed = office-only controlled document (Source Unit).
    });
})();
</script>
@include('pages.dcs.office.partials.drf-syllabi-context-script')

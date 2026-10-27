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
            <div class="ofi-alert err">
                <strong>RFIO correction note:</strong> {{ $editReason }}
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
                            'departmentFieldLabel' => 'Department',
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
                        @endphp
                        @if($lockDocType ?? false)
                            <input type="hidden" name="docTypeKind" value="{{ $docTypeKind }}">
                            <div class="ofi-radio-row">
                                <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind !== 'external')> Internal</label>
                                <label class="ofi-radio"><input type="checkbox" disabled @checked($docTypeKind === 'external')> External</label>
                            </div>
                            <p class="ofi-hint">This follows the document being revised and cannot be changed.</p>
                        @else
                            <div class="ofi-radio-row">
                                <label class="ofi-radio"><input type="radio" name="docTypeKind" value="internal" required @checked($docTypeKind === 'internal')> Internal</label>
                                <label class="ofi-radio"><input type="radio" name="docTypeKind" value="external" @checked($docTypeKind === 'external')> External</label>
                            </div>
                        @endif
                    </div>
                    <div class="reg-field">
                        <label>Description/reason for request (define in detail) <span class="ofi-req">*</span></label>
                        <textarea id="descriptionReason" name="descriptionReason" rows="4" required maxlength="5000" placeholder="For document control and dissemination">{{ old('descriptionReason', $drf->description_reason) }}</textarea>
                    </div>
                    <div class="reg-field ofi-distribute-field">
                        <label class="ofi-distribute-label">
                            <span>Distribute document to (department/position) <span class="ofi-req">*</span></span>
                            <span class="ofi-total-offices" aria-live="polite">
                                total offices: <strong data-ofi-selected-count="distribute">0</strong>
                            </span>
                        </label>
                        <div class="reg-cluster-chips" data-ofi-cluster-widget="distribute" aria-label="Select offices by cluster"></div>
                        @if($lockDocType ?? false)
                            <p class="ofi-hint">These offices are the same as the previous distribution of the document being revised. You can add more offices.</p>
                        @endif
                        <p class="ofi-hint">
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
                <button type="submit" id="ofiDrfPreviewBtn" class="reg-btn reg-btn-cancel" formnovalidate formtarget="_blank" formaction="{{ route('dcs.office.drf.preview', absolute: false) }}">
                    Preview form
                </button>
                <button type="submit" id="ofiDrfSaveBtn" class="reg-btn reg-btn-save" disabled>
                    <i class="fa-solid fa-paper-plane"></i> Save &amp; resubmit to RFIO
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.__ofiOffices = @json($offices);
window.__ofiClusters = @json($clusters);
window.__ofiOldDistribute = @json($oldDistributeOfficeIds);
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
    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.id === 'ofiDrfPreviewBtn') return;
        if (!confirmBox.checked) {
            e.preventDefault();
            alert('Please confirm that all the inputted data are correct before saving.');
            return;
        }

        const selected = form.querySelectorAll('input[name="distributeToOffice[]"]');
        if (!selected.length) {
            e.preventDefault();
            alert('Select at least one office to distribute the document to.');
            return;
        }
    });
})();
</script>

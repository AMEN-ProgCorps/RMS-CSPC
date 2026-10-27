<?php

/**
 * DCS file: Create an office DRF. Title and originator have no maxlength. Several originators go in one field, separated by commas.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('New DRF — CSPC DCS')] class extends Component {
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        $fromDcn = (int) request()->query('from_dcn', 0);
        if ($fromDcn > 0) {
            $existingDrfId = OfficeIntakeHelper::findLinkedDrfIdForOfficeDcn($fromDcn);
            if ($existingDrfId !== null) {
                session()->flash(
                    'error',
                    'This Document Change Notice already has a Document Request Form. Opening the existing DRF.'
                );
                $this->redirect(route('dcs.office.drf.show', $existingDrfId, absolute: false));

                return;
            }
        }
    }

    public function with(): array
    {
        $prefill = [
            'drfTitle' => '',
            'originatorName' => '',
            'descriptionReason' => '',
            'from_dcn' => 0,
        ];
        $fromDcn = (int) request()->query('from_dcn', 0);
        if ($fromDcn > 0) {
            $prefill = OfficeIntakeHelper::drfPrefillFromDcn($fromDcn);
        }

        $postedOffices = array_values(array_filter(array_map(
            'intval',
            (array) old('distributeToOffice', [])
        )));
        $seedOffices = $postedOffices !== []
            ? $postedOffices
            : array_values(array_filter(array_map('intval', (array) ($prefill['distributeOfficeIds'] ?? []))));

        $office = OfficeIntakeHelper::currentUserOfficeForDcn();
        $fromDcnId = (int) ($prefill['from_dcn'] ?? 0);
        $isRecurring = $fromDcnId < 1 && (
            strtolower((string) request()->query('mode', '')) === 'recurring'
            || old('drfMode') === 'recurring'
        );

        return [
            'offices' => RegisterQueryHelper::jsCatalog()['offices'] ?? [],
            'clusters' => RegisterQueryHelper::jsCatalog()['clusters'] ?? [],
            'oldDistributeOfficeIds' => $seedOffices,
            'prefill' => $prefill,
            'fromDcnId' => $fromDcnId,
            'isRecurring' => $isRecurring,
            'prevSignatories' => OfficeIntakeHelper::previousDrfSignatories(),
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
            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">
                @if(($fromDcnId ?? 0) > 0)
                    Prefilled from Document Change Notice #{{ $fromDcnId }}. Document type follows the document being revised. Distribution starts with that document’s offices — add any extra offices before saving.
                @elseif($isRecurring ?? false)
                    Recurring Document Request Form. Find the registered document first (same match as DCN / admin Recur). RFIO will register this as Recurring.
                @else
                    Fill in the form and save it. Print stays locked until RFIO confirms the form and notifies you to print and sign. Scanned DRF uploads are handled by RFIO during document registration.
                @endif
            </p>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.drf.store', absolute: false) }}" id="ofiDrfForm">
            @csrf
            @if(($fromDcnId ?? 0) > 0)
                <input type="hidden" name="from_dcn" value="{{ $fromDcnId }}">
            @endif
            @if($isRecurring ?? false)
                @include('pages.dcs.office.partials.drf-recur-fields')
            @endif
            <section class="reg-card" id="section-1">
                <div class="reg-card-header">
                    <span>Document Request Form{{ ($isRecurring ?? false) ? ' · Recurring' : '' }}</span>
                </div>
                <div class="reg-card-body ofi-drf-form">
                    <div class="reg-field">
                        <label for="drfOriginatorName">Originator <span class="ofi-req">*</span></label>
                        <input type="text" id="drfOriginatorName" name="originatorName" value="{{ old('originatorName', $prefill['originatorName'] ?? '') }}" required placeholder="Separate multiple originators with a comma">
                        @if($isRecurring ?? false)
                            <div id="drfSuggestOriginator" class="ofi-suggest" hidden></div>
                        @endif
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
                            <input type="date" id="drfDate" name="drfDate" value="{{ old('drfDate') }}" required>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label for="drfTitle">Document Title <span class="ofi-req">*</span></label>
                        <input type="text" id="drfTitle" name="drfTitle" value="{{ old('drfTitle', $prefill['drfTitle'] ?? '') }}" required placeholder="Enter document title">
                        @if($isRecurring ?? false)
                            <div id="drfSuggestTitle" class="ofi-suggest" hidden></div>
                        @endif
                    </div>
                    <div class="reg-field">
                        <label>Type of document <span class="ofi-req">*</span></label>
                        @php
                            $lockDocType = (bool) ($prefill['lockDocType'] ?? false);
                            $docTypeKind = old('docTypeKind', $prefill['docTypeKind'] ?? 'internal');
                        @endphp
                        @if($lockDocType)
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
                            <p id="drfRecurDocTypeHint" class="ofi-hint" hidden>This follows the registered document and cannot be changed after Find document.</p>
                        @endif
                    </div>
                    <div class="reg-field">
                        <label>Description/reason for request (define in detail) <span class="ofi-req">*</span></label>
                        <textarea id="descriptionReason" name="descriptionReason" rows="4" required maxlength="5000" placeholder="For document control and dissemination">{{ old('descriptionReason') }}</textarea>
                    </div>
                    <div class="reg-field ofi-distribute-field">
                        <label class="ofi-distribute-label">
                            <span>Distribute document to (department/position) <span class="ofi-req">*</span></span>
                            <span class="ofi-total-offices" aria-live="polite">
                                total offices: <strong data-ofi-selected-count="distribute">0</strong>
                            </span>
                        </label>
                        <div class="reg-cluster-chips" data-ofi-cluster-widget="distribute" aria-label="Select offices by cluster"></div>
                        @if(($fromDcnId ?? 0) > 0)
                            <p class="ofi-hint">These offices are the same as the previous distribution of the document being revised. You can add more offices.</p>
                        @elseif($isRecurring ?? false)
                            <p class="ofi-hint">After you find the document, distribution starts with that document’s offices. You can add more offices.</p>
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

                    @php
                        $prevSig = $prevSignatories ?? null;
                    @endphp
                    <div class="ofi-sig-block">
                        <p class="ofi-sig-heading">Signatories</p>
                        @if($prevSig)
                            <div class="ofi-suggest" id="drfPrevSigSuggest">
                                <p class="ofi-suggest-label">Press Tab in an empty signatory field to use your last DRF.</p>
                            </div>
                        @endif
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Prepared by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="preparedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="preparedByName" name="preparedByName"
                                        value="{{ old('preparedByName') }}"
                                        required maxlength="255"
                                        placeholder="{{ ($prevSig['prepared_by_name'] ?? '') !== '' ? $prevSig['prepared_by_name'] : 'Prepared by name' }}"
                                        data-prev-sig="{{ $prevSig['prepared_by_name'] ?? '' }}">
                                </div>
                                <div class="reg-field">
                                    <label for="preparedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="preparedByDesignation" name="preparedByDesignation"
                                        value="{{ old('preparedByDesignation') }}"
                                        required maxlength="255"
                                        placeholder="{{ ($prevSig['prepared_by_designation'] ?? '') !== '' ? $prevSig['prepared_by_designation'] : 'Prepared by designation' }}"
                                        data-prev-sig="{{ $prevSig['prepared_by_designation'] ?? '' }}">
                                </div>
                            </div>
                        </div>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Reviewed by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="reviewedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="reviewedByName" name="reviewedByName"
                                        value="{{ old('reviewedByName') }}"
                                        required maxlength="255"
                                        placeholder="{{ ($prevSig['reviewed_by_name'] ?? '') !== '' ? $prevSig['reviewed_by_name'] : 'Reviewed by name' }}"
                                        data-prev-sig="{{ $prevSig['reviewed_by_name'] ?? '' }}">
                                </div>
                                <div class="reg-field">
                                    <label for="reviewedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="reviewedByDesignation" name="reviewedByDesignation"
                                        value="{{ old('reviewedByDesignation') }}"
                                        required maxlength="255"
                                        placeholder="{{ ($prevSig['reviewed_by_designation'] ?? '') !== '' ? $prevSig['reviewed_by_designation'] : 'Reviewed by designation' }}"
                                        data-prev-sig="{{ $prevSig['reviewed_by_designation'] ?? '' }}">
                                </div>
                            </div>
                        </div>
                        <div class="ofi-sig-group">
                            <p class="ofi-sig-label">Approved by</p>
                            <div class="reg-grid-2">
                                <div class="reg-field">
                                    <label for="approvedByName">Name <span class="ofi-req">*</span></label>
                                    <input type="text" id="approvedByName" name="approvedByName"
                                        value="{{ old('approvedByName') }}"
                                        required maxlength="255"
                                        placeholder="{{ ($prevSig['approved_by_name'] ?? '') !== '' ? $prevSig['approved_by_name'] : 'Approved by name' }}"
                                        data-prev-sig="{{ $prevSig['approved_by_name'] ?? '' }}">
                                </div>
                                <div class="reg-field">
                                    <label for="approvedByDesignation">Designation <span class="ofi-req">*</span></label>
                                    <input type="text" id="approvedByDesignation" name="approvedByDesignation"
                                        value="{{ old('approvedByDesignation') }}"
                                        required maxlength="255"
                                        placeholder="{{ ($prevSig['approved_by_designation'] ?? '') !== '' ? $prevSig['approved_by_designation'] : 'Approved by designation' }}"
                                        data-prev-sig="{{ $prevSig['approved_by_designation'] ?? '' }}">
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
                    <i class="fa-solid fa-lock"></i> Save (cannot edit later)
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

    form.querySelectorAll('[data-prev-sig]').forEach(function (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || e.shiftKey || input.value.trim() !== '') return;
            const prev = (input.getAttribute('data-prev-sig') || '').trim();
            if (!prev) return;
            e.preventDefault();
            input.value = prev;
            const end = input.value.length;
            try { input.setSelectionRange(end, end); } catch (_) {}
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.id === 'ofiDrfPreviewBtn') return;
        if (!confirmBox.checked) {
            e.preventDefault();
            alert('Please confirm that all the inputted data are correct before saving.');
            return;
        }

        const distWidget = window.__sourceWidgets && window.__sourceWidgets.distribute;
        // Re-seed from last finder match if the widget somehow lost its selection.
        const seedCsv = String(form.dataset.ofiSeedDistributeIds || '').trim();
        if (distWidget && (!distWidget.selected || distWidget.selected.length === 0) && seedCsv !== '') {
            const seedIds = seedCsv.split(',').map(Number).filter(function (id) { return id > 0; });
            if (seedIds.length && typeof distWidget.seedQuiet === 'function') {
                distWidget.seedQuiet(seedIds);
            }
        }
        if (distWidget && typeof distWidget.syncHiddenInputs === 'function') {
            distWidget.syncHiddenInputs();
        }
        let selected = form.querySelectorAll('input[name="distributeToOffice[]"]');
        let selectedCount = selected.length
            || (distWidget && distWidget.selected ? distWidget.selected.length : 0);
        if (!selectedCount) {
            // Last resort: rebuild hiddens from backup CSV / seed dataset.
            const backup = form.querySelector('input[name="distributeToOfficeBackup"]');
            const csv = String((backup && backup.value) || seedCsv || '').trim();
            const ids = csv.split(',').map(Number).filter(function (id) { return id > 0; });
            ids.forEach(function (id) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'distributeToOffice[]';
                input.dataset.sourceHidden = 'true';
                input.dataset.ofiKey = 'distribute';
                input.value = String(id);
                form.appendChild(input);
            });
            selected = form.querySelectorAll('input[name="distributeToOffice[]"]');
            selectedCount = selected.length;
        }
        if (!selectedCount) {
            e.preventDefault();
            alert('Select at least one office to distribute the document to.');
            return;
        }

        if (!form.checkValidity()) {
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    });
})();
</script>
@if($isRecurring ?? false)
    @include('pages.dcs.office.partials.drf-recur-script')
@endif

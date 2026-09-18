<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('New DCN — CSPC DCS')] class extends Component {
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
    }

    public function with(): array
    {
        $catalog = RegisterQueryHelper::jsCatalog();
        $offices = $catalog['offices'] ?? [];
        $clusters = $catalog['clusters'] ?? [];

        $officesByCluster = [];
        foreach ($clusters as $cluster) {
            $code = (string) ($cluster['cluster_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $officesByCluster[$code] = [
                'label' => (string) ($cluster['cluster_name'] ?? $code),
                'offices' => [],
            ];
        }

        $unassigned = [];
        foreach ($offices as $office) {
            $code = trim((string) ($office['cluster'] ?? ''));
            if ($code !== '' && isset($officesByCluster[$code])) {
                $officesByCluster[$code]['offices'][] = $office;
            } else {
                $unassigned[] = $office;
            }
        }

        $groupedOffices = array_values(array_filter(
            $officesByCluster,
            fn (array $group) => $group['offices'] !== []
        ));
        if ($unassigned !== []) {
            $groupedOffices[] = [
                'label' => 'Other',
                'offices' => $unassigned,
            ];
        }

        return [
            'groupedOffices' => $groupedOffices,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">Fill in the Document Change Notice (CSPC-F-DCC-01), save, then print and submit the signed copy to RFIO.</p>
        </div>

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.dcn.store', absolute: false) }}" id="ofiDcnForm">
            @csrf
            <section class="reg-card ofi-dcn-card">
                <div class="reg-card-header">
                    <span>Document Change Notice</span>
                    <span class="ofi-form-code-badge">CSPC-F-DCC-01</span>
                </div>
                <div class="reg-card-body ofi-dcn-form">
                    <div class="ofi-dcn-box">
                        <div class="ofi-dcn-box-section">
                            <div class="ofi-dcn-doc-fields">
                                <div class="reg-field">
                                    <label for="dcnDocumentTitle">Document Title <span class="ofi-req">*</span></label>
                                    <input type="text" id="dcnDocumentTitle" name="documentTitle" value="{{ old('documentTitle') }}" required maxlength="255" placeholder="Enter document title" autocomplete="off">
                                </div>
                                <div class="reg-field">
                                    <label for="dcnDocumentNo">Document no. <span class="ofi-req">*</span></label>
                                    <input type="text" id="dcnDocumentNo" name="documentNo" value="{{ old('documentNo') }}" required maxlength="150" placeholder="Enter document no." autocomplete="off">
                                    <p class="ofi-hint">Create a new Document Change Notice — no need to look up an existing registered document.</p>
                                </div>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <label class="ofi-dcn-section-label">Detailed Description of Change:</label>
                            <div class="reg-field">
                                <label for="changeFrom">From <span class="ofi-req">*</span></label>
                                <textarea id="changeFrom" name="changeFrom" rows="4" required maxlength="5000" placeholder="Describe the current state…">{{ old('changeFrom') }}</textarea>
                            </div>
                            <div class="reg-field">
                                <label for="changeTo">To <span class="ofi-req">*</span></label>
                                <textarea id="changeTo" name="changeTo" rows="4" required maxlength="5000" placeholder="Describe the proposed change…">{{ old('changeTo') }}</textarea>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <label class="ofi-dcn-section-label" for="dcnJustification">Justification of Change: <span class="ofi-req">*</span></label>
                            <div class="reg-field">
                                <textarea id="dcnJustification" name="dcnJustification" rows="3" required maxlength="5000" placeholder="Enter justification for this change…">{{ old('dcnJustification') }}</textarea>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <div class="reg-field">
                                <label for="originatorName">Originator/ Signature <span class="ofi-req">*</span></label>
                                <input type="text" id="originatorName" name="originatorName" value="{{ old('originatorName') }}" required maxlength="255" placeholder="Enter originator name">
                            </div>
                            <div class="reg-grid-2-1">
                                <div class="reg-field">
                                    <label for="departmentOfficeId">Department <span class="ofi-req">*</span></label>
                                    <select id="departmentOfficeId" name="departmentOfficeId" required>
                                        <option value="">Select department…</option>
                                        @foreach($groupedOffices as $group)
                                            <optgroup label="{{ $group['label'] }}">
                                                @foreach($group['offices'] as $office)
                                                    @php
                                                        $officeId = (int) ($office['office_id'] ?? 0);
                                                        $code = trim((string) ($office['office_code'] ?? ''));
                                                        $name = trim((string) ($office['office_name'] ?? ''));
                                                        $label = $code !== '' && $name !== ''
                                                            ? $code . ' — ' . $name
                                                            : ($name !== '' ? $name : $code);
                                                    @endphp
                                                    @if($officeId > 0 && $label !== '')
                                                        <option value="{{ $officeId }}" @selected((string) old('departmentOfficeId') === (string) $officeId)>{{ $label }}</option>
                                                    @endif
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="reg-field">
                                    <label for="departmentDate">Date <span class="ofi-req">*</span></label>
                                    <input type="date" id="departmentDate" name="departmentDate" value="{{ old('departmentDate') }}" required>
                                </div>
                            </div>
                            <div class="ofi-reviewers" id="ofiReviewers">
                                <div class="ofi-reviewer-card" data-reviewer="1">
                                    <div class="ofi-reviewer-card-head">
                                        <span class="ofi-reviewer-badge">Reviewer 1</span>
                                    </div>
                                    <div class="reg-grid-2-1">
                                        <div class="reg-field">
                                            <label for="reviewedByName">Name <span class="ofi-req">*</span></label>
                                            <input type="text" id="reviewedByName" name="reviewedByName"
                                                value="{{ old('reviewedByName') }}"
                                                required maxlength="255" placeholder="Reviewer name" autocomplete="name">
                                        </div>
                                        <div class="reg-field">
                                            <label for="reviewedByOn">Date <span class="ofi-req">*</span></label>
                                            <input type="date" id="reviewedByOn" name="reviewedByOn"
                                                value="{{ old('reviewedByOn') }}" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="ofi-reviewer-card ofi-reviewer-2" id="ofiReviewer2" @if(!old('reviewedByName2') && !old('reviewedByOn2')) hidden @endif>
                                    <div class="ofi-reviewer-card-head">
                                        <span class="ofi-reviewer-badge">Reviewer 2</span>
                                        <button type="button" class="ofi-btn-sm ofi-btn-ghost" id="ofiRemoveReviewer" title="Remove second reviewer">
                                            <i class="fa-solid fa-xmark"></i> Remove
                                        </button>
                                    </div>
                                    <div class="reg-grid-2-1">
                                        <div class="reg-field">
                                            <label for="reviewedByName2">Name</label>
                                            <input type="text" id="reviewedByName2" name="reviewedByName2"
                                                value="{{ old('reviewedByName2') }}"
                                                maxlength="255" placeholder="Second reviewer name" autocomplete="name">
                                        </div>
                                        <div class="reg-field">
                                            <label for="reviewedByOn2">Date</label>
                                            <input type="date" id="reviewedByOn2" name="reviewedByOn2"
                                                value="{{ old('reviewedByOn2') }}">
                                        </div>
                                    </div>
                                </div>

                                <button type="button" class="ofi-add-reviewer" id="ofiAddReviewer" @if(old('reviewedByName2') || old('reviewedByOn2')) hidden @endif>
                                    <i class="fa-solid fa-plus"></i> Add another reviewer
                                </button>
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
                <button type="submit" id="ofiDcnSaveBtn" class="reg-btn reg-btn-save" disabled>
                    <i class="fa-solid fa-lock"></i> Save (cannot edit later)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('ofiDcnForm');
    const confirmBox = document.getElementById('ofiConfirmData');
    const saveBtn = document.getElementById('ofiDcnSaveBtn');
    if (!form || !confirmBox || !saveBtn) return;

    function syncConfirm() {
        saveBtn.disabled = !confirmBox.checked;
        saveBtn.setAttribute('aria-disabled', confirmBox.checked ? 'false' : 'true');
    }

    confirmBox.addEventListener('change', syncConfirm);
    syncConfirm();

    const reviewer2 = document.getElementById('ofiReviewer2');
    const addReviewerBtn = document.getElementById('ofiAddReviewer');
    const removeReviewerBtn = document.getElementById('ofiRemoveReviewer');
    const name2 = document.getElementById('reviewedByName2');
    const on2 = document.getElementById('reviewedByOn2');

    function showSecondReviewer() {
        if (!reviewer2 || !addReviewerBtn) return;
        reviewer2.hidden = false;
        addReviewerBtn.hidden = true;
        if (name2) {
            name2.required = true;
            name2.focus();
        }
        if (on2) on2.required = true;
    }

    function hideSecondReviewer() {
        if (!reviewer2 || !addReviewerBtn) return;
        reviewer2.hidden = true;
        addReviewerBtn.hidden = false;
        if (name2) {
            name2.required = false;
            name2.value = '';
        }
        if (on2) {
            on2.required = false;
            on2.value = '';
        }
    }

    if (addReviewerBtn) addReviewerBtn.addEventListener('click', showSecondReviewer);
    if (removeReviewerBtn) removeReviewerBtn.addEventListener('click', hideSecondReviewer);
    if (reviewer2 && !reviewer2.hidden) {
        if (name2) name2.required = true;
        if (on2) on2.required = true;
    }

    form.addEventListener('submit', function (e) {
        if (!confirmBox.checked) {
            e.preventDefault();
            alert('Please confirm that all the inputted data are correct before saving.');
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

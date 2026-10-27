<?php

/**
 * DCS file: Create an office DCN. The new title and the originator have no maxlength. Several originators are comma-separated in one field.
 */

use App\Helpers\OfficeIntakeHelper;
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
        $office = OfficeIntakeHelper::currentUserOfficeForDcn();

        $prevSignatories = OfficeIntakeHelper::previousDcnSignatories();
        $prevSignatories['source'] = 'previous';

        return [
            'userOfficeId' => $office['id'],
            'userOfficeCode' => $office['code'],
            'userOfficeName' => $office['name'],
            'userOfficeLabel' => $office['label'] !== '' ? $office['label'] : 'Your office',
            'prevDcnSignatories' => $prevSignatories,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">Fill in the Document Change Notice (CSPC-F-DCC-01) and save it. Print stays locked until RFIO confirms the form and notifies you to print and sign.</p>
        </div>

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.dcn.store', absolute: false) }}" id="ofiDcnForm">
            @csrf
            @include('pages.dcs.office.partials.dcn-docno-fields', [
                'initialDocNo' => old('documentNo', ''),
                'initialDocTitle' => old('documentTitle', ''),
                'initialReviseNo' => null,
            ])
            <section class="reg-card ofi-dcn-card">
                <div class="reg-card-header">
                    <span>Document Change Notice</span>
                    <span class="ofi-form-code-badge">CSPC-F-DCC-01</span>
                </div>
                <div class="reg-card-body ofi-dcn-form">
                    <div class="ofi-dcn-box">
                        <div class="ofi-dcn-box-section">
                            <div class="reg-field">
                                <label for="dcnDocumentTitle">New document title <span class="ofi-req">*</span></label>
                                <input type="text" id="dcnDocumentTitle" name="documentTitle"
                                    value="{{ old('documentTitle') }}"
                                    required
                                    placeholder="New document title"
                                    autocomplete="off">
                                <div id="dcnSuggestTitle" class="ofi-suggest" hidden></div>
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
                                <label for="originatorName">Originator <span class="ofi-req">*</span></label>
                                <input type="text" id="originatorName" name="originatorName" value="{{ old('originatorName') }}" required placeholder="Separate multiple originators with a comma">
                                <div id="dcnSuggestOriginator" class="ofi-suggest" hidden></div>
                            </div>
                            <div class="reg-grid-2-1">
                                @include('pages.dcs.office.partials.dcn-department-field', [
                                    'userOfficeId' => $userOfficeId ?? 0,
                                    'userOfficeCode' => $userOfficeCode ?? '',
                                    'userOfficeName' => $userOfficeName ?? '',
                                    'userOfficeLabel' => $userOfficeLabel ?? '',
                                ])
                                <div class="reg-field">
                                    <label for="departmentDate">Date <span class="ofi-req">*</span></label>
                                    <input type="date" id="departmentDate" name="departmentDate" value="{{ old('departmentDate') }}" required>
                                </div>
                            </div>
                            @php
                                $prevDcn = $prevDcnSignatories ?? ['reviewers' => [], 'approvals' => []];
                                $oldNames = old('reviewedByName');
                                $oldDates = old('reviewedByOn');
                                $reviewerRows = [];
                                if (is_array($oldNames) || is_array($oldDates)) {
                                    $oldNames = is_array($oldNames) ? $oldNames : [];
                                    $oldDates = is_array($oldDates) ? $oldDates : [];
                                    $count = max(count($oldNames), count($oldDates), 1);
                                    for ($i = 0; $i < $count; $i++) {
                                        $reviewerRows[] = [
                                            'name' => $oldNames[$i] ?? '',
                                            'date' => $oldDates[$i] ?? '',
                                        ];
                                    }
                                } else {
                                    // Legacy single fields from older form posts
                                    $n1 = old('reviewedByName');
                                    $d1 = old('reviewedByOn');
                                    $n2 = old('reviewedByName2');
                                    $d2 = old('reviewedByOn2');
                                    if ($n1 !== null || $d1 !== null || $n2 !== null || $d2 !== null) {
                                        $reviewerRows[] = ['name' => (string) ($n1 ?? ''), 'date' => (string) ($d1 ?? '')];
                                        if (trim((string) ($n2 ?? '')) !== '' || trim((string) ($d2 ?? '')) !== '') {
                                            $reviewerRows[] = ['name' => (string) ($n2 ?? ''), 'date' => (string) ($d2 ?? '')];
                                        }
                                    } else {
                                        $reviewerRows = [['name' => '', 'date' => '']];
                                    }
                                }
                            @endphp
                            @if(! empty($prevDcn['reviewers']))
                                <div class="ofi-suggest" id="dcnPrevReviewersSuggest">
                                    <p class="ofi-suggest-label">Press Tab in an empty Reviewer name to use your last DCN reviewers.</p>
                                </div>
                            @endif
                            @include('pages.dcs.office.partials.dcn-reviewers-fields', [
                                'reviewerRows' => $reviewerRows,
                                'prevReviewerPlaceholder' => $prevDcn['reviewers'][0]['name'] ?? '',
                            ])
                            @php
                                $oldPositions = old('approvalPosition');
                                $oldApprNames = old('approvalName');
                                $oldDates = old('approvalDate');
                                $approvalRows = [];
                                if (is_array($oldPositions) || is_array($oldApprNames) || is_array($oldDates)) {
                                    $oldPositions = is_array($oldPositions) ? $oldPositions : [];
                                    $oldApprNames = is_array($oldApprNames) ? $oldApprNames : [];
                                    $oldDates = is_array($oldDates) ? $oldDates : [];
                                    $count = max(count($oldPositions), count($oldApprNames), count($oldDates), 1);
                                    for ($i = 0; $i < $count; $i++) {
                                        $approvalRows[] = [
                                            'position' => $oldPositions[$i] ?? '',
                                            'name' => $oldApprNames[$i] ?? '',
                                            'date' => $oldDates[$i] ?? '',
                                        ];
                                    }
                                } else {
                                    $approvalRows = [['position' => '', 'name' => '', 'date' => '']];
                                }
                            @endphp
                            @if(! empty($prevDcn['approvals']))
                                <div class="ofi-suggest" id="dcnPrevApprovalsSuggest">
                                    <p class="ofi-suggest-label">Press Tab in an empty Position to use your last DCN approvals.</p>
                                </div>
                            @endif
                            @include('pages.dcs.office.partials.dcn-approvals-fields', [
                                'approvalRows' => $approvalRows,
                                'prevApprovalPositionPlaceholder' => $prevDcn['approvals'][0]['position'] ?? '',
                                'prevApprovalNamePlaceholder' => $prevDcn['approvals'][0]['name'] ?? '',
                            ])
                        </div>
                    </div>
                </div>
            </section>

            <div class="ofi-confirm-block">
                <label class="ofi-confirm-check">
                    <input type="checkbox" id="ofiConfirmData" name="confirmDataCorrect" value="1" required>
                    <span>I am confirming all the inputted data are correct.</span>
                </label>
                <label class="ofi-confirm-check ofi-also-drf-check">
                    <input type="checkbox" id="ofiAlsoCreateDrf" name="alsoCreateDrf" value="1" @checked(old('alsoCreateDrf'))>
                    <span>Also create a Document Request Form (DRF) for this change after save</span>
                </label>
            </div>

            <div class="reg-form-actions ofi-reg-actions">
                <button type="submit" id="ofiDcnPreviewBtn" class="reg-btn reg-btn-cancel" formnovalidate formtarget="_blank" formaction="{{ route('dcs.office.dcn.preview', absolute: false) }}">
                    Preview form
                </button>
                <button type="submit" id="ofiDcnSaveBtn" class="reg-btn reg-btn-save" disabled>
                    <i class="fa-solid fa-lock"></i> Save (cannot edit later)
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.__ofiPrevDcnSignatories = @json($prevDcnSignatories ?? ['reviewers' => [], 'approvals' => []]);
(function () {
    const form = document.getElementById('ofiDcnForm');
    const confirmBox = document.getElementById('ofiConfirmData');
    const saveBtn = document.getElementById('ofiDcnSaveBtn');
    const confirmed = document.getElementById('dcnDocNoConfirmed');
    if (!form || !confirmBox || !saveBtn) return;

    function syncConfirm() {
        saveBtn.disabled = !confirmBox.checked;
        saveBtn.setAttribute('aria-disabled', confirmBox.checked ? 'false' : 'true');
    }

    confirmBox.addEventListener('change', syncConfirm);
    syncConfirm();

    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.id === 'ofiDcnPreviewBtn') return;
        if (!confirmBox.checked) {
            e.preventDefault();
            alert('Please confirm that all the inputted data are correct before saving.');
            return;
        }
        if (confirmed && confirmed.value !== '1') {
            e.preventDefault();
            alert('Find the registered document before saving.');
            const docNo = document.getElementById('finderDocumentNo');
            if (docNo) docNo.focus();
            return;
        }
        if (form.dataset.openRequestMessage) {
            e.preventDefault();
            return;
        }
        if (!form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
            return;
        }
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    });
})();
</script>
@include('pages.dcs.office.partials.dcn-docno-script')
@include('pages.dcs.office.partials.dcn-reviewers-script')
@include('pages.dcs.office.partials.dcn-approvals-script')
<script>
(function () {
    const prev = window.__ofiPrevDcnSignatories || { reviewers: [], approvals: [] };

    function fillReviewers(rows) {
        const list = document.getElementById('ofiReviewersList');
        const addBtn = document.getElementById('ofiAddReviewer');
        const tpl = document.getElementById('ofiReviewerRowTpl');
        if (!list || !tpl || !Array.isArray(rows) || !rows.length) return;
        list.innerHTML = '';
        rows.forEach(function (row) {
            const node = tpl.content.cloneNode(true);
            const input = node.querySelector('input[name="reviewedByName[]"]');
            if (input) input.value = (row && row.name) ? String(row.name) : '';
            list.appendChild(node);
        });
        const items = Array.from(list.querySelectorAll('[data-reviewer-row]'));
        items.forEach(function (row, i) {
            const badge = row.querySelector('[data-reviewer-index]');
            if (badge) badge.textContent = String(i + 1);
            const remove = row.querySelector('[data-reviewer-remove]');
            if (remove) remove.hidden = i === 0 || items.length < 2;
            const nameInput = row.querySelector('input[name="reviewedByName[]"]');
            if (nameInput) nameInput.required = true;
        });
        if (addBtn) addBtn.hidden = items.length >= 9;
    }

    function fillApprovals(rows) {
        const list = document.getElementById('ofiApprovalsList');
        const addBtn = document.getElementById('ofiAddApproval');
        const tpl = document.getElementById('ofiApprovalRowTpl');
        if (!list || !tpl || !Array.isArray(rows) || !rows.length) return;
        list.innerHTML = '';
        rows.forEach(function (row) {
            const node = tpl.content.cloneNode(true);
            const pos = node.querySelector('input[name="approvalPosition[]"]');
            const name = node.querySelector('input[name="approvalName[]"]');
            if (pos) pos.value = (row && row.position) ? String(row.position) : '';
            if (name) name.value = (row && row.name) ? String(row.name) : '';
            list.appendChild(node);
        });
        const items = Array.from(list.querySelectorAll('[data-approval-row]'));
        items.forEach(function (row) {
            const remove = row.querySelector('[data-approval-remove]');
            if (remove) remove.hidden = items.length < 2;
        });
        if (addBtn) addBtn.hidden = items.length >= 9;
    }

    document.getElementById('ofiReviewersList')?.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) return;
        const input = e.target;
        if (!input || !input.matches || !input.matches('input[name="reviewedByName[]"]')) return;
        if (input.value.trim() !== '' || !prev.reviewers.length) return;
        e.preventDefault();
        fillReviewers(prev.reviewers);
    });

    document.getElementById('ofiApprovalsList')?.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) return;
        const input = e.target;
        if (!input || !input.matches || !input.matches('input[name="approvalPosition[]"]')) return;
        if (input.value.trim() !== '' || !prev.approvals.length) return;
        e.preventDefault();
        fillApprovals(prev.approvals);
    });
})();
</script>

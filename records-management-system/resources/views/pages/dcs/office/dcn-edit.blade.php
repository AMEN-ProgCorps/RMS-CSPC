<?php

use App\Helpers\OfficeIntakeHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Edit DCN — CSPC DCS')] class extends Component {
    public int $id;

    public function mount($id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $this->id = (int) $id;
        abort_unless(OfficeIntakeHelper::canOfficeEditIntake('dcn', $this->id), 403, OfficeIntakeHelper::IMMUTABLE_MESSAGE);
    }

    public function with(): array
    {
        $dcn = OfficeIntakeHelper::findOfficeDcn($this->id);
        abort_unless($dcn, 404);

        $dept = OfficeIntakeHelper::parseDepartmentDate($dcn->department_date ?? null);
        $office = OfficeIntakeHelper::currentUserOfficeForDcn();

        return [
            'dcn' => $dcn,
            'userOfficeId' => $office['id'],
            'userOfficeCode' => $office['code'],
            'userOfficeName' => $office['name'],
            'userOfficeLabel' => $office['label'] !== '' ? $office['label'] : 'Your office',
            'departmentDate' => old('departmentDate', $dept['date_iso'] ?? ''),
            'editReason' => trim((string) ($dcn->edit_unlock_reason ?? '')),
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.dcn.show', $dcn->id, absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
            <p class="ofi-toolbar-hint">Correct the Document Change Notice, then save to resubmit to RFIO. The document will lock again after save.</p>
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

        <form method="POST" action="{{ route('dcs.office.dcn.update', $dcn->id, absolute: false) }}" id="ofiDcnForm">
            @csrf
            @method('PUT')
            <section class="reg-card ofi-dcn-card">
                <div class="reg-card-header">
                    <span>Document Change Notice</span>
                    <span class="ofi-form-code-badge">CSPC-F-DCC-01</span>
                </div>
                <div class="reg-card-body ofi-dcn-form">
                    <div class="ofi-dcn-box">
                        <div class="ofi-dcn-box-section">
                            @include('pages.dcs.office.partials.dcn-docno-fields', [
                                'initialDocNo' => old('documentNo', $dcn->document_no),
                                'initialDocTitle' => old('documentTitle', $dcn->document_title),
                                'initialReviseNo' => null,
                                'forceConfirmed' => trim((string) old('documentNo', $dcn->document_no)) !== '',
                            ])
                        </div>

                        <div class="ofi-dcn-box-section">
                            <label class="ofi-dcn-section-label">Detailed Description of Change:</label>
                            <div class="reg-field">
                                <label for="changeFrom">From <span class="ofi-req">*</span></label>
                                <textarea id="changeFrom" name="changeFrom" rows="4" required maxlength="5000" placeholder="Describe the current state…">{{ old('changeFrom', $dcn->change_from) }}</textarea>
                            </div>
                            <div class="reg-field">
                                <label for="changeTo">To <span class="ofi-req">*</span></label>
                                <textarea id="changeTo" name="changeTo" rows="4" required maxlength="5000" placeholder="Describe the proposed change…">{{ old('changeTo', $dcn->change_to) }}</textarea>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <label class="ofi-dcn-section-label" for="dcnJustification">Justification of Change: <span class="ofi-req">*</span></label>
                            <div class="reg-field">
                                <textarea id="dcnJustification" name="dcnJustification" rows="3" required maxlength="5000" placeholder="Enter justification for this change…">{{ old('dcnJustification', $dcn->brief_purpose) }}</textarea>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <div class="reg-field">
                                <label for="originatorName">Originator/ Signature <span class="ofi-req">*</span></label>
                                <input type="text" id="originatorName" name="originatorName" value="{{ old('originatorName', $dcn->originator_name) }}" required maxlength="255" placeholder="Enter originator name">
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
                                    <input type="date" id="departmentDate" name="departmentDate" value="{{ $departmentDate }}" required>
                                </div>
                            </div>
                            @php
                                $oldNames = old('reviewedByName');
                                $oldDates = old('reviewedByOn');
                                if (is_array($oldNames) || is_array($oldDates)) {
                                    $oldNames = is_array($oldNames) ? $oldNames : [];
                                    $oldDates = is_array($oldDates) ? $oldDates : [];
                                    $reviewerRows = [];
                                    $count = max(count($oldNames), count($oldDates), 1);
                                    for ($i = 0; $i < $count; $i++) {
                                        $reviewerRows[] = [
                                            'name' => $oldNames[$i] ?? '',
                                            'date' => $oldDates[$i] ?? '',
                                        ];
                                    }
                                } else {
                                    $loaded = \App\Helpers\OfficeIntakeHelper::loadDcnReviewers((int) $dcn->id, $dcn);
                                    if ($loaded === []) {
                                        $reviewerRows = [['name' => '', 'date' => '']];
                                    } else {
                                        $reviewerRows = array_map(function ($row) {
                                            return [
                                                'name' => $row['name'] ?? '',
                                                'date' => $row['date'] ?? '',
                                            ];
                                        }, $loaded);
                                    }
                                }
                            @endphp
                            @include('pages.dcs.office.partials.dcn-reviewers-fields', ['reviewerRows' => $reviewerRows])
                            @php
                                $oldPositions = old('approvalPosition');
                                $oldNames = old('approvalName');
                                $oldDates = old('approvalDate');
                                if (is_array($oldPositions) || is_array($oldNames) || is_array($oldDates)) {
                                    $oldPositions = is_array($oldPositions) ? $oldPositions : [];
                                    $oldNames = is_array($oldNames) ? $oldNames : [];
                                    $oldDates = is_array($oldDates) ? $oldDates : [];
                                    $approvalRows = [];
                                    $count = max(count($oldPositions), count($oldNames), count($oldDates), 1);
                                    for ($i = 0; $i < $count; $i++) {
                                        $approvalRows[] = [
                                            'position' => $oldPositions[$i] ?? '',
                                            'name' => $oldNames[$i] ?? '',
                                            'date' => $oldDates[$i] ?? '',
                                        ];
                                    }
                                } else {
                                    $approvalRows = \App\Helpers\OfficeIntakeHelper::loadDcnApprovals((int) $dcn->id);
                                    if ($approvalRows === []) {
                                        $approvalRows = [['position' => '', 'name' => '', 'date' => '']];
                                    } else {
                                        $approvalRows = array_map(function ($row) {
                                            return [
                                                'position' => $row['position'] ?? '',
                                                'name' => $row['name'] ?? '',
                                                'date' => $row['date'] ?? '',
                                            ];
                                        }, $approvalRows);
                                    }
                                }
                            @endphp
                            @include('pages.dcs.office.partials.dcn-approvals-fields', ['approvalRows' => $approvalRows])
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
                    <i class="fa-solid fa-paper-plane"></i> Save &amp; resubmit to RFIO
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

    form.addEventListener('submit', function (e) {
        if (!confirmBox.checked) {
            e.preventDefault();
            alert('Please confirm that all the inputted data are correct before saving.');
        }
    });
})();
</script>
@include('pages.dcs.office.partials.dcn-docno-script')
@include('pages.dcs.office.partials.dcn-reviewers-script')
@include('pages.dcs.office.partials.dcn-approvals-script')

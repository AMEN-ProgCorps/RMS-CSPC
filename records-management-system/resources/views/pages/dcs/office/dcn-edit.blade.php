<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
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
        $selectedOfficeId = null;
        if (($dept['department_code'] ?? '') !== '' || ($dept['department'] ?? '') !== '') {
            $needle = trim((string) (($dept['department_code'] ?? '') !== '' ? $dept['department_code'] : $dept['department']));
            $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $selectedOfficeId = \Illuminate\Support\Facades\DB::table($officeTbl)
                ->where(function ($q) use ($needle) {
                    $q->where('office_code', $needle)->orWhere('office_name', $needle);
                })
                ->value('id');
        }

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
            'dcn' => $dcn,
            'groupedOffices' => $groupedOffices,
            'selectedOfficeId' => old('departmentOfficeId', $selectedOfficeId),
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
                            <div class="ofi-dcn-doc-fields">
                                <div class="reg-field">
                                    <label for="dcnDocumentTitle">Document Title <span class="ofi-req">*</span></label>
                                    <input type="text" id="dcnDocumentTitle" name="documentTitle" value="{{ old('documentTitle', $dcn->document_title) }}" required maxlength="255" placeholder="Enter document title" autocomplete="off">
                                </div>
                                <div class="reg-field">
                                    <label for="dcnDocumentNo">Document no. <span class="ofi-req">*</span></label>
                                    <input type="text" id="dcnDocumentNo" name="documentNo" value="{{ old('documentNo', $dcn->document_no) }}" required maxlength="150" placeholder="Enter document no." autocomplete="off">
                                </div>
                            </div>
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
                                                        <option value="{{ $officeId }}" @selected((string) $selectedOfficeId === (string) $officeId)>{{ $label }}</option>
                                                    @endif
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="reg-field">
                                    <label for="departmentDate">Date <span class="ofi-req">*</span></label>
                                    <input type="date" id="departmentDate" name="departmentDate" value="{{ $departmentDate }}" required>
                                </div>
                            </div>
                            <div class="reg-field">
                                <label for="reviewedByDate">Reviewed by/ Date <span class="ofi-req">*</span></label>
                                <input type="text" id="reviewedByDate" name="reviewedByDate" value="{{ old('reviewedByDate', $dcn->reviewed_by_date) }}" required maxlength="255" placeholder="Enter reviewer name and/or date">
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

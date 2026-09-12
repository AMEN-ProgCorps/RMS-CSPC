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
                                    <label for="dcnDocumentTitle">Document Title</label>
                                    <input type="text" id="dcnDocumentTitle" name="documentTitle" value="{{ old('documentTitle') }}" required maxlength="255" placeholder="Enter document title" autocomplete="off">
                                </div>
                                <div class="reg-field">
                                    <label for="dcnDocumentNo">Document no. (optional)</label>
                                    <input type="text" id="dcnDocumentNo" name="documentNo" value="{{ old('documentNo') }}" maxlength="150" placeholder="Enter document no. if known" autocomplete="off">
                                    <p class="ofi-hint">Create a new Document Change Notice — no need to look up an existing registered document.</p>
                                </div>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <label class="ofi-dcn-section-label">Detailed Description of Change:</label>
                            <div class="reg-field">
                                <label for="changeFrom">From</label>
                                <textarea id="changeFrom" name="changeFrom" rows="4" maxlength="5000" placeholder="Describe the current state…">{{ old('changeFrom') }}</textarea>
                            </div>
                            <div class="reg-field">
                                <label for="changeTo">To</label>
                                <textarea id="changeTo" name="changeTo" rows="4" maxlength="5000" placeholder="Describe the proposed change…">{{ old('changeTo') }}</textarea>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <label class="ofi-dcn-section-label" for="dcnJustification">Justification of Change:</label>
                            <div class="reg-field">
                                <textarea id="dcnJustification" name="dcnJustification" rows="3" required maxlength="5000" placeholder="Enter justification for this change…">{{ old('dcnJustification') }}</textarea>
                            </div>
                        </div>

                        <div class="ofi-dcn-box-section">
                            <div class="reg-field">
                                <label for="originatorName">Originator/ Signature</label>
                                <input type="text" id="originatorName" name="originatorName" value="{{ old('originatorName') }}" maxlength="255" placeholder="Leave blank for manual entry when printing">
                            </div>
                            <div class="reg-grid-2-1">
                                <div class="reg-field">
                                    <label for="departmentOfficeId">Department</label>
                                    <select id="departmentOfficeId" name="departmentOfficeId">
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
                                    <label for="departmentDate">Date</label>
                                    <input type="date" id="departmentDate" name="departmentDate" value="{{ old('departmentDate') }}">
                                </div>
                            </div>
                            <div class="reg-field">
                                <label for="reviewedByDate">Reviewed by/ Date</label>
                                <input type="text" id="reviewedByDate" name="reviewedByDate" value="{{ old('reviewedByDate') }}" maxlength="255" placeholder="Leave blank for manual entry when printing">
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="reg-form-actions ofi-reg-actions">
                <button type="submit" class="reg-btn reg-btn-save">
                    <i class="fa-solid fa-lock"></i> Save (cannot edit later)
                </button>
            </div>
        </form>
    </div>
</div>

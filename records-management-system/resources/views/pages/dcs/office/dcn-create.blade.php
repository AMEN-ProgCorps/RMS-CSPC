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
        return [
            'defaultOfficeId' => RegisterQueryHelper::currentOfficeId(),
            'defaultOfficeName' => RegisterQueryHelper::currentOfficeName(),
            'userDisplayName' => RegisterQueryHelper::currentUserDisplayName(),
            'offices' => RegisterQueryHelper::jsCatalog()['offices'] ?? [],
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner ofi-inner-wide">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">Search and select the document you are revising. Only documents where you are the originator (<strong>{{ $userDisplayName }}</strong>) are listed. After saving, it cannot be edited.</p>
        </div>

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.dcn.store', absolute: false) }}" enctype="multipart/form-data" id="ofiDcnForm">
            @csrf
            <section class="reg-card" id="section-2">
                <div class="reg-card-header">
                    <span>Document Change Notice</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-field reg-revision-table">
                        <label>Documents for Revision</label>
                        <div class="reg-table-wrap">
                            <table class="reg-table">
                                <thead>
                                    <tr>
                                        <th>Document No.</th>
                                        <th>Document Title</th>
                                        <th>Effectivity Date</th>
                                        <th>Revision No.</th>
                                        <th>Scanned Copy</th>
                                        <th>Brief Purpose</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="revisionTableBody">
                                    <tr>
                                        <td>
                                            <input type="text" name="documentNo[]" placeholder="Search or enter document no." autocomplete="off">
                                            <input type="hidden" name="revisionScannedPath[]" value="">
                                            <input type="hidden" name="revisionMasterlistId[]" value="">
                                            <input type="hidden" name="revisionLinked[]" value="0">
                                        </td>
                                        <td><input type="text" name="documentTitle[]" placeholder="Search or enter document title" autocomplete="off"></td>
                                        <td><input type="date" name="effectiveDate[]" readonly class="reg-revrow-locked" tabindex="-1"></td>
                                        <td><input type="number" name="revisionNo[]" placeholder="—" readonly class="reg-revrow-locked" tabindex="-1"></td>
                                        <td class="reg-rev-scan-cell" style="text-align:center;color:#94a3b8;">—</td>
                                        <td class="reg-rev-purpose-cell">
                                            <input type="hidden" name="revisionPurpose[]" value="">
                                            <span class="reg-rev-purpose-text">—</span>
                                        </td>
                                        <td>
                                            <button type="button" class="reg-row-del" onclick="ofiRemoveRevisionRow(this)">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <button type="button" id="btnAddRevisionRow" onclick="ofiAddRevisionRow()">
                            <i class="fa-solid fa-plus"></i> Add Row
                        </button>
                    </div>

                    <div class="reg-field">
                        <label>Justification</label>
                        <input type="text" id="dcnJustification" name="dcnJustification" value="{{ old('dcnJustification') }}" required maxlength="5000" placeholder="Enter justification for this change notice...">
                    </div>

                    <div class="reg-grid-3">
                        <div class="reg-field">
                            <label>DCN No.</label>
                            <input type="text" id="dcnNumber" name="dcnNumber" value="{{ old('dcnNumber') }}" required maxlength="100" placeholder="Enter DCN No.">
                        </div>
                        <div class="reg-field">
                            <label>DCN Date</label>
                            <input type="date" id="noticeDate" name="noticeDate" value="{{ old('noticeDate', now()->toDateString()) }}">
                        </div>
                        <div class="reg-field">
                            <label>DCN Receipt</label>
                            <div class="reg-dual">
                                <input type="date" id="receiptDate" name="receiptDate" value="{{ old('receiptDate') }}">
                                <input type="time" id="receiptTime" name="receiptTime" value="{{ old('receiptTime') }}">
                            </div>
                        </div>
                    </div>

                    <div class="reg-grid-2-1">
                        <div class="reg-field">
                            <label>Upload Scanned DCN</label>
                            <label class="reg-upload">
                                <input type="file" id="dcnFile" name="dcnFile" accept=".pdf,application/pdf">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>Choose scanned PDF</span>
                            </label>
                        </div>
                        <div class="reg-field">
                            <label>Source Unit</label>
                            <div class="reg-reldocs" id="dcnSourceUnitWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="dcnSourceUnitSearch" class="reg-reldocs-input"
                                        placeholder="Type to search offices..." autocomplete="off">
                                    <button type="button" class="reg-reldocs-arrow-btn" id="dcnSourceArrowBtn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="dcnSourceResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="dcnSourceInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
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

<script>
window.__ofiOffices = @json($offices);
window.__ofiDefaultOffice = @json([
    'office_id' => $defaultOfficeId,
    'office_name' => $defaultOfficeName,
]);
window.__ofiOldSource = @json(array_values(array_filter(array_map('intval', (array) old('dcnSourceUnit', $defaultOfficeId ? [$defaultOfficeId] : [])))));
window.__ofiOriginatorSelf = true;
window.__ofiSourceConfigs = [
    {
        key: 'dcn',
        widgetId: 'dcnSourceUnitWidget',
        inputId: 'dcnSourceUnitSearch',
        arrowId: 'dcnSourceArrowBtn',
        resultsId: 'dcnSourceResults',
        chipsId: 'dcnSourceInlineChips',
        officeFieldName: 'dcnSourceUnit[]',
    }
];
</script>
<script src="{{ asset('js/dcs/office-intake.js') }}"></script>

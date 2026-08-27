<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('New DRF — CSPC DCS')] class extends Component {
    public function mount(): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
    }

    public function with(): array
    {
        return [
            'defaultOfficeId' => RegisterQueryHelper::currentOfficeId(),
            'defaultOfficeName' => RegisterQueryHelper::currentOfficeName(),
            'offices' => RegisterQueryHelper::jsCatalog()['offices'] ?? [],
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">After saving, this form cannot be edited — only viewed and printed.</p>
        </div>

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.drf.store', absolute: false) }}" enctype="multipart/form-data" id="ofiDrfForm">
            @csrf
            <section class="reg-card" id="section-1">
                <div class="reg-card-header">
                    <span>Document Request Form</span>
                </div>
                <div class="reg-card-body">
                    <div class="reg-grid-3">
                        <div class="reg-field">
                            <label>DRF No.</label>
                            <input type="text" id="drfNo" name="drfNo" value="{{ old('drfNo') }}" required maxlength="100" placeholder="Enter DRF No.">
                        </div>
                        <div class="reg-field">
                            <label>DRF Date</label>
                            <input type="date" id="drfDate" name="drfDate" value="{{ old('drfDate', now()->toDateString()) }}" required>
                        </div>
                        <div class="reg-field">
                            <label>Date Receipt</label>
                            <div class="reg-dual">
                                <input type="date" id="drfReceiptDate" name="drfReceiptDate" value="{{ old('drfReceiptDate') }}">
                                <input type="time" id="drfTime" name="drfTime" value="{{ old('drfTime') }}">
                            </div>
                        </div>
                    </div>
                    <div class="reg-grid-2-1">
                        <div class="reg-field">
                            <label>Document Title</label>
                            <input type="text" id="drfTitle" name="drfTitle" value="{{ old('drfTitle') }}" required maxlength="255" placeholder="Enter document title">
                        </div>
                        <div class="reg-field">
                            <label>Source Unit</label>
                            <div class="reg-reldocs" id="drfSourceUnitWidget">
                                <div class="reg-reldocs-inputwrap">
                                    <input type="text" id="drfSourceUnitSearch" class="reg-reldocs-input"
                                        placeholder="Type to search offices..." autocomplete="off">
                                    <button type="button" class="reg-reldocs-arrow-btn" id="drfSourceArrowBtn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                                <div id="drfSourceResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                                <div id="drfSourceInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label>Upload Scanned DRF</label>
                        <label class="reg-upload">
                            <input type="file" id="drfFile" name="drfFile" accept=".pdf,application/pdf">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <span>Choose scanned PDF</span>
                        </label>
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
window.__ofiOldSource = @json(array_values(array_filter(array_map('intval', (array) old('drfSourceUnit', $defaultOfficeId ? [$defaultOfficeId] : [])))));
window.__ofiSourceConfigs = [
    {
        key: 'drf',
        widgetId: 'drfSourceUnitWidget',
        inputId: 'drfSourceUnitSearch',
        arrowId: 'drfSourceArrowBtn',
        resultsId: 'drfSourceResults',
        chipsId: 'drfSourceInlineChips',
        officeFieldName: 'drfSourceUnit[]',
    }
];
</script>
<script src="{{ asset('js/dcs/office-intake.js') }}"></script>

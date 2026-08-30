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
            'defaultOriginator' => RegisterQueryHelper::currentUserDisplayName(),
            'offices' => RegisterQueryHelper::jsCatalog()['offices'] ?? [],
            'oldDistributeOfficeIds' => array_values(array_filter(array_map(
                'intval',
                (array) old('distributeToOffice', [])
            ))),
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.drf.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">Fill in the form, save, then print and submit the signed copy to RFIO. Scanned DRF uploads are handled by RFIO during document registration.</p>
        </div>

        @if($errors->any())
            <div class="ofi-alert err">
                <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dcs.office.drf.store', absolute: false) }}" id="ofiDrfForm">
            @csrf
            <section class="reg-card" id="section-1">
                <div class="reg-card-header">
                    <span>Document Request Form</span>
                </div>
                <div class="reg-card-body ofi-drf-form">
                    <div class="reg-grid-2">
                        <div class="reg-field">
                            <label>Request #</label>
                            <input type="text" id="drfNo" name="drfNo" value="{{ old('drfNo') }}" required maxlength="100" placeholder="Enter request number">
                        </div>
                        <div class="reg-field">
                            <label>Date</label>
                            <input type="date" id="drfDate" name="drfDate" value="{{ old('drfDate', now()->toDateString()) }}" required>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label>Originator</label>
                        <input type="text" name="originatorName" value="{{ old('originatorName', $defaultOriginator) }}" maxlength="255" placeholder="Name of originator">
                    </div>
                    <div class="reg-field">
                        <label>Document Title</label>
                        <input type="text" id="drfTitle" name="drfTitle" value="{{ old('drfTitle') }}" required maxlength="255" placeholder="Enter document title">
                    </div>
                    <div class="reg-field">
                        <label>Type of document</label>
                        <div class="ofi-radio-row">
                            <label class="ofi-radio"><input type="radio" name="docTypeKind" value="internal" @checked(old('docTypeKind', 'internal') === 'internal')> Internal</label>
                            <label class="ofi-radio"><input type="radio" name="docTypeKind" value="external" @checked(old('docTypeKind') === 'external')> External</label>
                        </div>
                    </div>
                    <div class="reg-field">
                        <label>Description/reason for request (define in detail)</label>
                        <textarea name="descriptionReason" rows="4" maxlength="5000" placeholder="Define in detail…">{{ old('descriptionReason') }}</textarea>
                    </div>
                    <div class="reg-field">
                        <label>Distribute document to (department/position)</label>
                        <p class="ofi-hint">
                            Search by office name or code — office <strong>code</strong> prints on the form.
                            Click the <i class="fa-solid fa-chevron-down ofi-hint-icon"></i> arrow to view or remove selected offices.
                        </p>
                        <div class="reg-reldocs" id="drfDistributeWidget">
                            <div class="reg-reldocs-inputwrap">
                                <input type="text" id="drfDistributeSearch" class="reg-reldocs-input"
                                    placeholder="Type to search offices..." autocomplete="off">
                                <button type="button" class="reg-reldocs-arrow-btn" id="drfDistributeArrowBtn" title="View selected offices">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>
                            <div id="drfDistributeResults" class="reg-reldocs-dropdown" style="display:none;"></div>
                            <div id="drfDistributeInlineChips" class="reg-reldocs-dropdown reg-reldocs-selected-panel" style="display:none;"></div>
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
        labelFormat: 'code',
    },
];
</script>
<script src="{{ asset('js/dcs/office-intake.js') }}"></script>

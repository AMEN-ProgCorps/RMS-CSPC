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
        $officeName = RegisterQueryHelper::currentOfficeName();
        $defaultDepartmentDate = $officeName
            ? $officeName . ' / ' . now()->format('M d, Y')
            : now()->format('M d, Y');

        return [
            'userDisplayName' => RegisterQueryHelper::currentUserDisplayName(),
            'defaultDepartmentDate' => $defaultDepartmentDate,
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-toolbar">
            <a href="{{ route('dcs.office.dcn.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> Back to list
            </a>
            <p class="ofi-toolbar-hint">Fill in the official Document Change Notice (CSPC-F-DCC-01), save, then print and submit the signed copy to RFIO.</p>
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
                    <div class="reg-field">
                        <label for="dcnNumber">DCN #</label>
                        <input type="text" id="dcnNumber" name="dcnNumber" value="{{ old('dcnNumber') }}" required maxlength="100" placeholder="Enter DCN number">
                    </div>

                    <div class="ofi-dcn-box">
                        <div class="ofi-dcn-box-section">
                            <div class="ofi-dcn-doc-fields">
                                <div class="reg-field">
                                    <label for="dcnDocumentNo">Document no.</label>
                                    <input type="text" id="dcnDocumentNo" name="documentNo" value="{{ old('documentNo') }}" required maxlength="150" placeholder="Search or enter document no." autocomplete="off">
                                    <input type="hidden" id="dcnMasterlistId" name="revisionMasterlistId" value="{{ old('revisionMasterlistId') }}">
                                    <input type="hidden" id="dcnDocumentLinked" name="revisionLinked" value="{{ old('revisionLinked', '0') }}">
                                    <p class="ofi-hint">Search and select the document you are revising. Only documents where you are the originator (<strong>{{ $userDisplayName }}</strong>) are listed.</p>
                                </div>
                                <div class="reg-field">
                                    <label for="dcnDocumentTitle">Title</label>
                                    <input type="text" id="dcnDocumentTitle" name="documentTitle" value="{{ old('documentTitle') }}" maxlength="255" placeholder="Document title" readonly class="reg-revrow-locked" tabindex="-1">
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
                                <input type="text" id="originatorName" name="originatorName" value="{{ old('originatorName', $userDisplayName) }}" maxlength="255" placeholder="Name of originator">
                            </div>
                            <div class="reg-field">
                                <label for="departmentDate">Department/ Date</label>
                                <input type="text" id="departmentDate" name="departmentDate" value="{{ old('departmentDate', $defaultDepartmentDate) }}" maxlength="255" placeholder="Department / date">
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

<script>
window.__ofiOriginatorSelf = true;
</script>
<script src="{{ asset('js/dcs/office-intake.js') }}"></script>

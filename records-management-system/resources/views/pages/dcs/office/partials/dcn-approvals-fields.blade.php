@php
    $approvalRows = $approvalRows ?? [];
    if ($approvalRows === []) {
        $approvalRows = [['position' => '', 'name' => '', 'date' => '']];
    }
@endphp
<div class="ofi-approvals" id="ofiApprovals" data-max="9">
    <div class="ofi-approvals-head">
        <label class="ofi-dcn-section-label">Approvals</label>
        <p class="ofi-approvals-hint">Add approving positions and names. Signature is left blank for wet-ink on the printed form.</p>
    </div>
    <div class="ofi-approvals-list" id="ofiApprovalsList">
        @foreach($approvalRows as $i => $appr)
            <div class="ofi-approval-card" data-approval-row>
                <div class="ofi-approval-card-head">
                    <span class="ofi-reviewer-badge">Approval <span data-approval-index>{{ $i + 1 }}</span></span>
                    <button type="button" class="ofi-btn-sm ofi-btn-ghost" data-approval-remove title="Remove approval" @if(count($approvalRows) < 2) hidden @endif>
                        <i class="fa-solid fa-xmark"></i> Remove
                    </button>
                </div>
                <div class="reg-grid-3">
                    <div class="reg-field">
                        <label>Position</label>
                        <input type="text" name="approvalPosition[]" value="{{ $appr['position'] ?? '' }}" maxlength="255" placeholder="e.g. College Dean" autocomplete="organization-title">
                    </div>
                    <div class="reg-field">
                        <label>Name</label>
                        <input type="text" name="approvalName[]" value="{{ $appr['name'] ?? '' }}" maxlength="255" placeholder="Approver name" autocomplete="name">
                    </div>
                    <div class="reg-field">
                        <label>Date</label>
                        <input type="date" name="approvalDate[]" value="{{ $appr['date'] ?? '' }}">
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <button type="button" class="ofi-add-reviewer" id="ofiAddApproval">
        <i class="fa-solid fa-plus"></i> Add approval position
    </button>
</div>

<template id="ofiApprovalRowTpl">
    <div class="ofi-approval-card" data-approval-row>
        <div class="ofi-approval-card-head">
            <span class="ofi-reviewer-badge">Approval <span data-approval-index>1</span></span>
            <button type="button" class="ofi-btn-sm ofi-btn-ghost" data-approval-remove title="Remove approval">
                <i class="fa-solid fa-xmark"></i> Remove
            </button>
        </div>
        <div class="reg-grid-3">
            <div class="reg-field">
                <label>Position</label>
                <input type="text" name="approvalPosition[]" value="" maxlength="255" placeholder="e.g. College Dean" autocomplete="organization-title">
            </div>
            <div class="reg-field">
                <label>Name</label>
                <input type="text" name="approvalName[]" value="" maxlength="255" placeholder="Approver name" autocomplete="name">
            </div>
            <div class="reg-field">
                <label>Date</label>
                <input type="date" name="approvalDate[]" value="">
            </div>
        </div>
    </div>
</template>

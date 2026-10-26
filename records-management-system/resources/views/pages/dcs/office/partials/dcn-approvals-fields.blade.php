{{--
DCS file: Repeatable approver name and position rows on the office DCN.
--}}
@php
    $approvalRows = $approvalRows ?? [];
    if ($approvalRows === []) {
        $approvalRows = [['position' => '', 'name' => '', 'date' => '']];
    }
    $prevApprovalPositionPlaceholder = trim((string) ($prevApprovalPositionPlaceholder ?? ''));
    $prevApprovalNamePlaceholder = trim((string) ($prevApprovalNamePlaceholder ?? ''));
@endphp
<div class="ofi-approvals" id="ofiApprovals" data-max="9">
    <div class="ofi-approvals-head">
        <label class="ofi-dcn-section-label">Approvals</label>
        <p class="ofi-approvals-hint">Position and name only. Signature and date stay blank for wet-ink on the printed form.</p>
        <div id="dcnSuggestApprovals" class="ofi-suggest" hidden></div>
    </div>
    <div class="ofi-approvals-table-wrap">
        <table class="ofi-approvals-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Name</th>
                    <th class="ofi-approval-action-col"></th>
                </tr>
            </thead>
            <tbody id="ofiApprovalsList">
                @foreach($approvalRows as $i => $appr)
                    <tr data-approval-row>
                        <td>
                            <input type="text" name="approvalPosition[]" value="{{ $appr['position'] ?? '' }}" maxlength="255"
                                placeholder="{{ ($i === 0 && $prevApprovalPositionPlaceholder !== '') ? $prevApprovalPositionPlaceholder : 'e.g. College Dean' }}"
                                autocomplete="organization-title">
                        </td>
                        <td>
                            <input type="text" name="approvalName[]" value="{{ $appr['name'] ?? '' }}" maxlength="255"
                                placeholder="{{ ($i === 0 && $prevApprovalNamePlaceholder !== '') ? $prevApprovalNamePlaceholder : 'Approver name' }}"
                                autocomplete="name">
                        </td>
                        <td>
                            <button type="button" class="ofi-approval-remove" data-approval-remove title="Remove" @if(count($approvalRows) < 2) hidden @endif>Remove</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <button type="button" class="ofi-add-reviewer" id="ofiAddApproval">
        <i class="fa-solid fa-plus"></i> Add approval
    </button>
</div>

<template id="ofiApprovalRowTpl">
    <tr data-approval-row>
        <td>
            <input type="text" name="approvalPosition[]" value="" maxlength="255" placeholder="e.g. College Dean" autocomplete="organization-title">
        </td>
        <td>
            <input type="text" name="approvalName[]" value="" maxlength="255" placeholder="Approver name" autocomplete="name">
        </td>
        <td>
            <button type="button" class="ofi-approval-remove" data-approval-remove title="Remove">Remove</button>
        </td>
    </tr>
</template>

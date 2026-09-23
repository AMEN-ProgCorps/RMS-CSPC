@php
    $reviewerRows = $reviewerRows ?? [];
    if ($reviewerRows === []) {
        $reviewerRows = [['name' => '', 'date' => '']];
    }
@endphp
<div class="ofi-reviewers" id="ofiReviewers" data-max="9">
    <div class="ofi-reviewers-list" id="ofiReviewersList">
        @foreach($reviewerRows as $i => $rev)
            <div class="ofi-reviewer-card" data-reviewer-row>
                <div class="ofi-reviewer-card-head">
                    <span class="ofi-reviewer-badge">Reviewer <span data-reviewer-index>{{ $i + 1 }}</span></span>
                    <button type="button" class="ofi-btn-sm ofi-btn-ghost" data-reviewer-remove title="Remove reviewer" @if($i === 0 || count($reviewerRows) < 2) hidden @endif>
                        <i class="fa-solid fa-xmark"></i> Remove
                    </button>
                </div>
                <div class="reg-field">
                    <label>Name @if($i === 0)<span class="ofi-req">*</span>@endif</label>
                    <input type="text" name="reviewedByName[]" value="{{ $rev['name'] ?? '' }}"
                        @if($i === 0) required @endif
                        maxlength="255"
                        placeholder="Reviewer name"
                        autocomplete="name">
                </div>
            </div>
        @endforeach
    </div>
    <button type="button" class="ofi-add-reviewer" id="ofiAddReviewer" @if(count($reviewerRows) >= 9) hidden @endif>
        <i class="fa-solid fa-plus"></i> Add another reviewer
    </button>
</div>

<template id="ofiReviewerRowTpl">
    <div class="ofi-reviewer-card" data-reviewer-row>
        <div class="ofi-reviewer-card-head">
            <span class="ofi-reviewer-badge">Reviewer <span data-reviewer-index>1</span></span>
            <button type="button" class="ofi-btn-sm ofi-btn-ghost" data-reviewer-remove title="Remove reviewer">
                <i class="fa-solid fa-xmark"></i> Remove
            </button>
        </div>
        <div class="reg-field">
            <label>Name</label>
            <input type="text" name="reviewedByName[]" value="" maxlength="255" placeholder="Reviewer name" autocomplete="name">
        </div>
    </div>
</template>

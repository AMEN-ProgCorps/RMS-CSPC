@php
    $departmentParts = \App\Helpers\OfficeIntakeHelper::parseDepartmentDate($dcn->department_date ?? null);
@endphp
<div class="ofi-review">
    <div class="ofi-review-hero">
        <div class="ofi-review-hero-main">
            <span class="ofi-review-badge">Office submission</span>
            <h3 class="ofi-review-heading">Document Change Notice</h3>
            <p class="ofi-review-lead">
                {{ $meta['office'] }} submitted a Document Change Notice for RFIO review.
            </p>
        </div>
        <dl class="ofi-review-meta-grid">
            <div>
                <dt>Submitting office</dt>
                <dd>{{ $meta['office'] }}</dd>
            </div>
            <div>
                <dt>Submitted by</dt>
                <dd>{{ $meta['submitter'] }}</dd>
            </div>
            <div>
                <dt>Submitted on</dt>
                <dd>{{ $meta['submittedAt'] }}</dd>
            </div>
        </dl>
    </div>

    <div class="ofi-review-note">
        <i class="fa-solid fa-circle-info"></i>
        <span>This is a read-only RFIO review view of a submission from another office.</span>
    </div>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Document Change Notice</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field is-wide">
                <dt>Document Title</dt>
                <dd>{{ $docTitle ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Document no. (optional)</dt>
                <dd>{{ $docNo ?: '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Detailed Description of Change</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field is-wide">
                <dt>From</dt>
                <dd class="is-multiline">{{ $dcn->change_from ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field is-wide">
                <dt>To</dt>
                <dd class="is-multiline">{{ $dcn->change_to ?: '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Justification of Change</h4>
        <div class="ofi-review-text-block">{{ $dcn->brief_purpose ?: '—' }}</div>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Signatures &amp; dates</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field">
                <dt>Originator/ Signature</dt>
                <dd>{{ $dcn->originator_name ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Department</dt>
                <dd>{{ $departmentParts['department_label'] ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Date</dt>
                <dd>{{ $departmentParts['date_label'] ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Reviewed by / Date</dt>
                <dd class="ofi-show-reviewed">{{ $dcn->reviewed_by_date ?: '—' }}</dd>
            </div>
            @if(trim((string) ($dcn->reviewed_by_date_2 ?? '')) !== '')
            <div class="ofi-review-field">
                <dt>Reviewed by / Date (2nd)</dt>
                <dd class="ofi-show-reviewed">{{ $dcn->reviewed_by_date_2 }}</dd>
            </div>
            @endif
        </dl>
    </section>
</div>

<div class="ofi-review">
    <div class="ofi-review-hero">
        <div class="ofi-review-hero-main">
            <span class="ofi-review-badge">Office submission</span>
            <h3 class="ofi-review-heading">Document Change Notice</h3>
            <p class="ofi-review-lead">
                {{ $meta['office'] }} submitted DCN <strong>{{ $dcn->dcn_no ?: '—' }}</strong>
                for RFIO review.
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
        <h4 class="ofi-review-section-title">Document details</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field">
                <dt>DCN number</dt>
                <dd>{{ $dcn->dcn_no ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Document number</dt>
                <dd>{{ $docNo ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field is-wide">
                <dt>Document title</dt>
                <dd>{{ $docTitle ?: '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Change description</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field is-wide">
                <dt>From</dt>
                <dd class="is-multiline">{{ $dcn->change_from ?? '—' }}</dd>
            </div>
            <div class="ofi-review-field is-wide">
                <dt>To</dt>
                <dd class="is-multiline">{{ $dcn->change_to ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Justification</h4>
        <div class="ofi-review-text-block">{{ $dcn->brief_purpose ?: '—' }}</div>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Signatures &amp; dates</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field">
                <dt>Originator</dt>
                <dd>{{ $dcn->originator_name ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Department / date</dt>
                <dd>{{ $dcn->department_date ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Reviewed by / date</dt>
                <dd>{{ $dcn->reviewed_by_date ?: '—' }}</dd>
            </div>
        </dl>
    </section>
</div>

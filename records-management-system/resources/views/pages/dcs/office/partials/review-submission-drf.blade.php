@php
    $originator = trim((string) ($drf->originator_name ?? ''));
    $kind = strtolower(trim((string) ($drf->doc_type_kind ?? '')));
    $docTypeLabel = match ($kind) {
        'internal' => 'Internal',
        'external' => 'External',
        default => 'Not specified',
    };
    $drfDate = $drf->drf_date
        ? \Carbon\Carbon::parse($drf->drf_date)->format('M d, Y')
        : '—';
@endphp

<div class="ofi-review">
    <div class="ofi-review-hero">
        <div class="ofi-review-hero-main">
            <span class="ofi-review-badge">Office submission</span>
            <h3 class="ofi-review-heading">Document Request Form</h3>
            <p class="ofi-review-lead">
                {{ $meta['office'] }} submitted a Document Request Form for RFIO review.
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
        <h4 class="ofi-review-section-title">Request details</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field">
                <dt>Request date</dt>
                <dd>{{ $drfDate }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Originator</dt>
                <dd>{{ $originator ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Document type</dt>
                <dd><span class="ofi-review-pill">{{ $docTypeLabel }}</span></dd>
            </div>
            <div class="ofi-review-field is-wide">
                <dt>Document title</dt>
                <dd>{{ $drf->doc_title ?: '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Description / reason</h4>
        <div class="ofi-review-text-block">{{ $drf->description_reason ?: '—' }}</div>
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">
            Distribute document to (department/position)
            <span class="ofi-total-offices" style="margin-left:8px;font-weight:600;text-transform:none;letter-spacing:0;">
                total offices: <strong>{{ count($distributeOffices ?? []) }}</strong>
            </span>
        </h4>
        <p class="ofi-review-hint" style="margin:0 0 10px;font-size:0.82rem;color:#64748b;line-height:1.4;">
            All offices listed here are recipients for <strong>distribution</strong> of this document.
        </p>
        @if(!empty($distributeOffices))
            <div class="ofi-review-chips">
                @foreach($distributeOffices as $office)
                    <span class="ofi-review-chip" title="{{ $office['name'] ?: $office['code'] }}">
                        @if($office['code'] !== '')
                            <span class="ofi-review-chip-code">{{ $office['code'] }}</span>
                        @endif
                        @if($office['name'] !== '')
                            <span class="ofi-review-chip-name">{{ $office['name'] }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @else
            <div class="ofi-review-empty">No distribution offices listed.</div>
        @endif
    </section>

    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Signatories</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field">
                <dt>Prepared by — Name</dt>
                <dd>{{ trim((string) data_get($drf, 'prepared_by_name', '')) ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Prepared by — Designation</dt>
                <dd>{{ trim((string) data_get($drf, 'prepared_by_designation', '')) ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Reviewed by — Name</dt>
                <dd>{{ trim((string) data_get($drf, 'reviewed_by_name', '')) ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Reviewed by — Designation</dt>
                <dd>{{ trim((string) data_get($drf, 'reviewed_by_designation', '')) ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Approved by — Name</dt>
                <dd>{{ trim((string) data_get($drf, 'approved_by_name', '')) ?: '—' }}</dd>
            </div>
            <div class="ofi-review-field">
                <dt>Approved by — Designation</dt>
                <dd>{{ trim((string) data_get($drf, 'approved_by_designation', '')) ?: '—' }}</dd>
            </div>
        </dl>
    </section>
</div>

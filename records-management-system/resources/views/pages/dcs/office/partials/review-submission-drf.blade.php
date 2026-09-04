@php
    $originator = trim((string) ($drf->originator_name ?? ''))
        ?: trim((string) ($drf->prepared_by_name ?? ''));
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
                {{ $meta['office'] }} submitted DRF <strong>{{ $drf->drf_no ?: '—' }}</strong>
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
        <h4 class="ofi-review-section-title">Request details</h4>
        <dl class="ofi-review-fields">
            <div class="ofi-review-field">
                <dt>Request number</dt>
                <dd>{{ $drf->drf_no ?: '—' }}</dd>
            </div>
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
        <h4 class="ofi-review-section-title">Distribution</h4>
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
</div>

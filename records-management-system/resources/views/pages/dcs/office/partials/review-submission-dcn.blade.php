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
            @php
                $reviewerRows = \App\Helpers\OfficeIntakeHelper::loadDcnReviewers((int) $dcn->id, $dcn);
            @endphp
            @forelse($reviewerRows as $i => $rev)
            <div class="ofi-review-field">
                <dt>Reviewed by / Date{{ count($reviewerRows) > 1 ? ' ('.($i + 1).')' : '' }}</dt>
                <dd class="ofi-show-reviewed">{{ $rev['label'] !== '' ? $rev['label'] : '—' }}</dd>
            </div>
            @empty
            <div class="ofi-review-field">
                <dt>Reviewed by / Date</dt>
                <dd class="ofi-show-reviewed">{{ $dcn->reviewed_by_date ?: '—' }}</dd>
            </div>
            @endforelse
        </dl>
    </section>

    @php
        $approvalRows = \App\Helpers\OfficeIntakeHelper::loadDcnApprovals((int) $dcn->id);
    @endphp
    @if($approvalRows !== [])
    <section class="ofi-review-section">
        <h4 class="ofi-review-section-title">Approvals</h4>
        <div class="ofi-approvals-table-wrap">
            <table class="ofi-approvals-table">
                <thead>
                    <tr>
                        <th>Position</th>
                        <th>Name</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($approvalRows as $appr)
                        <tr>
                            <td>{{ $appr['position'] !== '' ? $appr['position'] : '—' }}</td>
                            <td>{{ $appr['name'] !== '' ? $appr['name'] : '—' }}</td>
                            <td>
                                @if(!empty($appr['date']))
                                    {{ \Carbon\Carbon::parse($appr['date'])->format('M d, Y') }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif
</div>

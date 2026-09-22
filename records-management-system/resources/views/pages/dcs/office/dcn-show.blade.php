<?php

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('View DCN — CSPC DCS')] class extends Component {
    public int $id;

    public function mount($id): void
    {
        OfficeIntakeHelper::assertCanAccessIntake();
        $this->id = (int) $id;

        if (RegisterQueryHelper::canBrowseAllOfficeIntake()) {
            $this->redirect(route('dcs.requests.show', ['type' => 'dcn', 'id' => $this->id], absolute: false));

            return;
        }

        $dcn = OfficeIntakeHelper::findOfficeDcn($this->id);
        abort_unless($dcn, 404);
        OfficeIntakeHelper::assertOwnsDcn($dcn);
    }

    public function with(): array
    {
        $dcn = OfficeIntakeHelper::findOfficeDcn($this->id);
        abort_unless($dcn, 404);

        $revisions = OfficeIntakeHelper::dcnRevisions($this->id);
        $firstRev = $revisions->first();
        $departmentParts = OfficeIntakeHelper::parseDepartmentDate($dcn->department_date ?? null);

        return [
            'dcn' => $dcn,
            'docNo' => trim((string) ($dcn->document_no ?? '')) ?: trim((string) ($firstRev->document_no ?? '')),
            'docTitle' => trim((string) ($dcn->document_title ?? '')) ?: trim((string) ($firstRev->title ?? '')),
            'departmentLabel' => $departmentParts['department_label'],
            'departmentDateLabel' => $departmentParts['date_label'],
            'immutableMessage' => OfficeIntakeHelper::IMMUTABLE_MESSAGE,
            'isIntakeReviewer' => RegisterQueryHelper::canBrowseAllOfficeIntake(),
            'canEdit' => OfficeIntakeHelper::canOfficeEditIntake('dcn', $this->id),
            'editReason' => trim((string) ($dcn->edit_unlock_reason ?? '')),
            'isRegistered' => OfficeIntakeHelper::isIntakeRegistered('dcn', $this->id),
            'linkedDrfId' => OfficeIntakeHelper::findLinkedDrfIdForOfficeDcn($this->id),
            'canCreateDrf' => ! OfficeIntakeHelper::officeDcnHasLinkedDrf($this->id),
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-show-toolbar">
            <a href="{{ ($isIntakeReviewer ?? false) ? route('dcs', absolute: false) : route('dcs.office.dcn.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> {{ ($isIntakeReviewer ?? false) ? 'Back to DCS' : 'Back to list' }}
            </a>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                @if($canEdit ?? false)
                    <a href="{{ route('dcs.office.dcn.edit', $dcn->id, absolute: false) }}" class="reg-btn reg-btn-save">
                        <i class="fa-solid fa-pen"></i> Edit form
                    </a>
                @endif
                @unless($isIntakeReviewer ?? false)
                    @if($canCreateDrf ?? true)
                        <a href="{{ route('dcs.office.drf.create', ['from_dcn' => $dcn->id], absolute: false) }}" class="reg-btn reg-btn-save">
                            <i class="fa-solid fa-file-circle-plus"></i> Create DRF for this change
                        </a>
                    @elseif(! empty($linkedDrfId))
                        <a href="{{ route('dcs.office.drf.show', $linkedDrfId, absolute: false) }}" class="reg-btn reg-btn-save">
                            <i class="fa-solid fa-file-lines"></i> View linked DRF
                        </a>
                    @endif
                @endunless
                <a href="{{ route('dcs.office.dcn.print', $dcn->id, absolute: false) }}" target="_blank" class="reg-btn reg-btn-save">
                    <i class="fa-solid fa-print"></i> Print form
                </a>
            </div>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        @if(($canEdit ?? false) && ($editReason ?? '') !== '')
            <div class="ofi-alert err">
                <strong>RFIO asked for corrections:</strong> {{ $editReason }}
            </div>
        @endif

        <div class="ofi-lock-banner">
            @if($isRegistered ?? false)
                <i class="fa-solid fa-circle-check"></i>
                <span>This document has been registered / controlled by RFIO.</span>
            @elseif($canEdit ?? false)
                <i class="fa-solid fa-unlock"></i>
                <span>RFIO enabled editing so you can correct and resubmit this form.</span>
            @else
                <i class="fa-solid fa-lock"></i>
                <span>{{ $immutableMessage }}</span>
            @endif
        </div>

        <section class="reg-card ofi-show-card ofi-dcn-card">
            <div class="reg-card-header">
                <span>Document Change Notice</span>
                <span class="ofi-form-code-badge">CSPC-F-DCC-01</span>
            </div>
            <div class="reg-card-body ofi-dcn-form ofi-show-form">
                <div class="ofi-dcn-box ofi-dcn-box-readonly">
                    <div class="ofi-dcn-box-section">
                        <div class="ofi-dcn-doc-fields">
                            <div class="reg-field">
                                <label>Document Title</label>
                                <div class="ofi-show-value">{{ $docTitle ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Document no. (optional)</label>
                                <div class="ofi-show-value">{{ $docNo ?: '—' }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="ofi-dcn-box-section">
                        <label class="ofi-dcn-section-label">Detailed Description of Change:</label>
                        <div class="reg-field">
                            <label>From</label>
                            <div class="ofi-show-value is-multiline">{{ $dcn->change_from ?: '—' }}</div>
                        </div>
                        <div class="reg-field">
                            <label>To</label>
                            <div class="ofi-show-value is-multiline">{{ $dcn->change_to ?: '—' }}</div>
                        </div>
                    </div>

                    <div class="ofi-dcn-box-section">
                        <label class="ofi-dcn-section-label">Justification of Change:</label>
                        <div class="reg-field">
                            <div class="ofi-show-value is-multiline">{{ $dcn->brief_purpose ?: '—' }}</div>
                        </div>
                    </div>

                    <div class="ofi-dcn-box-section">
                        <div class="reg-field">
                            <label>Originator/ Signature</label>
                            <div class="ofi-show-value">{{ $dcn->originator_name ?: '—' }}</div>
                        </div>
                        <div class="reg-grid-2-1">
                            <div class="reg-field">
                                <label>Department</label>
                                <div class="ofi-show-value">{{ $departmentLabel ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Date</label>
                                <div class="ofi-show-value">{{ $departmentDateLabel ?: '—' }}</div>
                            </div>
                        </div>
                        @php
                            $reviewerRows = \App\Helpers\OfficeIntakeHelper::loadDcnReviewers((int) $dcn->id, $dcn);
                        @endphp
                        @foreach($reviewerRows as $i => $rev)
                        <div class="reg-field">
                            <label>Reviewed by / Date{{ count($reviewerRows) > 1 ? ' ('.($i + 1).')' : '' }}</label>
                            <div class="ofi-show-value ofi-show-reviewed">{{ $rev['label'] !== '' ? $rev['label'] : '—' }}</div>
                        </div>
                        @endforeach
                        @if($reviewerRows === [])
                        <div class="reg-field">
                            <label>Reviewed by / Date</label>
                            <div class="ofi-show-value ofi-show-reviewed">{{ $dcn->reviewed_by_date ?: '—' }}</div>
                        </div>
                        @endif
                        @php
                            $approvalRows = \App\Helpers\OfficeIntakeHelper::loadDcnApprovals((int) $dcn->id);
                        @endphp
                        @if($approvalRows !== [])
                        <div class="ofi-approvals ofi-approvals-readonly">
                            <label class="ofi-dcn-section-label">Approvals</label>
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
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

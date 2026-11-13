<?php

/**
 * DCS file: One office DCN. Print unlocks on this page when RFIO sets rfio_print_notified_at.
 */

use App\Helpers\OfficeIntakeHelper;
use App\Helpers\RegisterQueryHelper;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
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
        $departmentParts = OfficeIntakeHelper::parseDepartmentDate($dcn);

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
            'canCreateDrf' => OfficeIntakeHelper::officeDcnAllowsLaterDrf($this->id)
                && ! OfficeIntakeHelper::officeDcnHasLinkedDrf($this->id),
            'canPrint' => OfficeIntakeHelper::officeMayPrintIntake($dcn),
            'printHint' => 'Print stays locked until RFIO confirms this form and notifies you to print and sign.',
        ];
    }
}; ?>

<div class="ofi-page" @if(!($canPrint ?? false)) wire:poll.1s @endif>
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
                            <i class="fa-solid fa-file-circle-plus"></i> Create DRF
                        </a>
                    @elseif(! empty($linkedDrfId))
                        <a href="{{ route('dcs.office.drf.show', $linkedDrfId, absolute: false) }}" class="reg-btn reg-btn-save">
                            <i class="fa-solid fa-file-lines"></i> View linked DRF
                        </a>
                    @endif
                @endunless
                @if($canPrint ?? false)
                    <a href="{{ route('dcs.office.dcn.print', $dcn->id, absolute: false) }}" target="_blank" class="reg-btn reg-btn-save">
                        <i class="fa-solid fa-print"></i> Print form
                    </a>
                @else
                    <span id="ofiPrintControl" class="reg-btn reg-btn-save ofi-print-locked" title="{{ $printHint }}"
                        data-locked="1"
                        data-intake-id="{{ $dcn->id }}"
                        data-status-url="{{ route('dcs.api.office-intake.print-ready', ['type' => 'dcn', 'ids' => $dcn->id], false) }}"
                        data-print-url="{{ route('dcs.office.dcn.print', $dcn->id, false) }}">
                        <i class="fa-solid fa-print"></i> Print form
                    </span>
                @endif
            </div>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        @if(($canEdit ?? false) && ($editReason ?? '') !== '')
            <div class="ofi-alert err ofi-correction-alert" role="status">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <div class="ofi-alert-body">
                    <strong>RFIO asked for corrections</strong>
                    <span>{{ $editReason }}</span>
                </div>
            </div>
        @endif

        @unless($canPrint ?? false)
            <div class="ofi-alert ofi-print-hint-alert" id="ofiPrintHint" role="status">
                <i class="fa-solid fa-print" aria-hidden="true"></i>
                <div class="ofi-alert-body">
                    <strong>Print is locked</strong>
                    <span>{{ $printHint }}</span>
                </div>
            </div>
        @endunless

        <div class="ofi-lock-banner {{ ($isRegistered ?? false) ? 'is-registered' : (($canEdit ?? false) ? 'is-edit' : '') }}" role="status">
            @if($isRegistered ?? false)
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div class="ofi-lock-banner-body">
                    <strong>Registered by RFIO</strong>
                    <span>This document has been registered / controlled by RFIO.</span>
                </div>
            @elseif($canEdit ?? false)
                <i class="fa-solid fa-unlock" aria-hidden="true"></i>
                <div class="ofi-lock-banner-body">
                    <strong>Editing enabled</strong>
                    <span>RFIO enabled editing so you can correct and resubmit this form.</span>
                </div>
            @else
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                <div class="ofi-lock-banner-body">
                    <strong>View only</strong>
                    <span>{{ $immutableMessage }}</span>
                </div>
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
                            <label>Originator</label>
                            <div class="ofi-show-value"><x-dcs.originator-lines :name="$dcn->originator_name ?? ''" /></div>
                        </div>
                        <div class="reg-grid-2-1">
                            <div class="reg-field">
                                <label>Source unit</label>
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
                        @forelse($reviewerRows as $i => $rev)
                        <div class="reg-field">
                            <label>Reviewed by{{ count($reviewerRows) > 1 ? ' ('.($i + 1).')' : '' }}</label>
                            <div class="ofi-show-value ofi-show-reviewed">{{ $rev['label'] !== '' ? $rev['label'] : '—' }}</div>
                        </div>
                        @empty
                        <div class="reg-field">
                            <label>Reviewed by</label>
                            <div class="ofi-show-value ofi-show-reviewed">—</div>
                        </div>
                        @endforelse
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
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($approvalRows as $appr)
                                            <tr>
                                                <td>{{ $appr['position'] !== '' ? $appr['position'] : '—' }}</td>
                                                <td>{{ $appr['name'] !== '' ? $appr['name'] : '—' }}</td>
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
@include('pages.dcs.office.partials.print-unlock-watch')

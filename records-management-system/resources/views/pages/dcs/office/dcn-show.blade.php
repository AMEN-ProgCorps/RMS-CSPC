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
            $this->redirect('/dcs?intake=dcn&id=' . $this->id, navigate: false);

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

        return [
            'dcn' => $dcn,
            'docNo' => trim((string) ($dcn->document_no ?? '')) ?: trim((string) ($firstRev->document_no ?? '')),
            'docTitle' => trim((string) ($dcn->document_title ?? '')) ?: trim((string) ($firstRev->title ?? '')),
            'immutableMessage' => OfficeIntakeHelper::IMMUTABLE_MESSAGE,
            'isIntakeReviewer' => RegisterQueryHelper::canBrowseAllOfficeIntake(),
        ];
    }
}; ?>

<div class="ofi-page">
    <div class="ofi-inner">
        <div class="ofi-show-toolbar">
            <a href="{{ ($isIntakeReviewer ?? false) ? route('dcs', absolute: false) : route('dcs.office.dcn.index', absolute: false) }}" class="reg-btn reg-btn-cancel">
                <i class="fa-solid fa-arrow-left"></i> {{ ($isIntakeReviewer ?? false) ? 'Back to DCS' : 'Back to list' }}
            </a>
            <a href="{{ route('dcs.office.dcn.print', $dcn->id, absolute: false) }}" target="_blank" class="reg-btn reg-btn-save">
                <i class="fa-solid fa-print"></i> Print form
            </a>
        </div>

        @if(session('success'))
            <div class="ofi-alert ok">{{ session('success') }}</div>
        @endif

        <div class="ofi-lock-banner">
            <i class="fa-solid fa-lock"></i>
            <span>{{ $immutableMessage }}</span>
        </div>

        <section class="reg-card ofi-show-card ofi-dcn-card">
            <div class="reg-card-header">
                <span>Document Change Notice</span>
                <span class="ofi-form-code-badge">CSPC-F-DCC-01</span>
            </div>
            <div class="reg-card-body ofi-dcn-form ofi-show-form">
                <div class="reg-field">
                    <label>DCN #</label>
                    <div class="ofi-show-value">{{ $dcn->dcn_no ?: '—' }}</div>
                </div>

                <div class="ofi-dcn-box ofi-dcn-box-readonly">
                    <div class="ofi-dcn-box-section">
                        <div class="ofi-dcn-doc-fields">
                            <div class="reg-field">
                                <label>Document no.</label>
                                <div class="ofi-show-value">{{ $docNo ?: '—' }}</div>
                            </div>
                            <div class="reg-field">
                                <label>Title</label>
                                <div class="ofi-show-value">{{ $docTitle ?: '—' }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="ofi-dcn-box-section">
                        <label class="ofi-dcn-section-label">Detailed Description of Change:</label>
                        <div class="reg-field">
                            <label>From</label>
                            <div class="ofi-show-value is-multiline">{{ $dcn->change_from ?? '—' }}</div>
                        </div>
                        <div class="reg-field">
                            <label>To</label>
                            <div class="ofi-show-value is-multiline">{{ $dcn->change_to ?? '—' }}</div>
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
                        <div class="reg-field">
                            <label>Department/ Date</label>
                            <div class="ofi-show-value">{{ $dcn->department_date ?: '—' }}</div>
                        </div>
                        <div class="reg-field">
                            <label>Reviewed by/ Date</label>
                            <div class="ofi-show-value">{{ $dcn->reviewed_by_date ?: '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

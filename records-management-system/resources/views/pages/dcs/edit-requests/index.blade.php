<?php

use App\Helpers\RegisterQueryHelper;
use App\Helpers\RegisterUpdateHelper;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Edit Requests — CSPC DCS')] class extends Component {
    #[Url]
    public string $search = '';

    public int $page = 1;

    public ?int $approveId = null;
    public string $approveTitle = '';
    public string $approveDocNo = '';
    public string $approveRequester = '';
    public string $approveReason = '';

    public ?int $denyId = null;
    public string $denyTitle = '';
    public string $denyDocNo = '';
    public string $denyNote = '';
    public string $denyError = '';

    public function mount(): void
    {
        abort_unless(RegisterQueryHelper::isDocumentControlHead(), 403);
    }

    public function with(): array
    {
        return [
            'list' => RegisterUpdateHelper::editRequestList($this->search, $this->page),
        ];
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
        $this->closeApprove();
        $this->closeDeny();
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function confirmApprove(int $id, string $title, string $docNo, string $requester, string $reason): void
    {
        $this->closeDeny();
        $this->approveId = $id;
        $this->approveTitle = $title;
        $this->approveDocNo = $docNo;
        $this->approveRequester = $requester;
        $this->approveReason = $reason;
    }

    public function closeApprove(): void
    {
        $this->approveId = null;
        $this->approveTitle = '';
        $this->approveDocNo = '';
        $this->approveRequester = '';
        $this->approveReason = '';
    }

    public function approve(): void
    {
        if (! $this->approveId) {
            return;
        }

        $response = RegisterUpdateHelper::approveEditRequest($this->approveId);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }

    public function confirmDeny(int $id, string $title, string $docNo): void
    {
        $this->closeApprove();
        $this->denyId = $id;
        $this->denyTitle = $title;
        $this->denyDocNo = $docNo;
        $this->denyNote = '';
        $this->denyError = '';
    }

    public function closeDeny(): void
    {
        $this->denyId = null;
        $this->denyTitle = '';
        $this->denyDocNo = '';
        $this->denyNote = '';
        $this->denyError = '';
    }

    public function deny(): void
    {
        if (! $this->denyId) {
            return;
        }

        $note = trim(preg_replace('/\s+/u', ' ', $this->denyNote) ?? '');
        if ($note === '' || mb_strlen($note) < 5) {
            $this->denyError = 'Please enter a denial note (at least 5 characters).';

            return;
        }
        if (mb_strlen($note) > 1000) {
            $this->denyError = 'Denial note must be 1000 characters or fewer.';

            return;
        }

        $response = RegisterUpdateHelper::denyEditRequest($this->denyId, $note);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }
}; ?>

<div class="rb-container main-content">
    <div class="rb-header">
        <div>
            <div class="upd-breadcrumb">Document Control System / <span>Edit Requests</span></div>
            <div class="rb-title-wrap">
                <div class="rb-title-icon"><i class="fa-solid fa-lock-open"></i></div>
                <div>
                    <div class="rb-title">Edit Requests</div>
                    <p class="rb-subtitle">
                        Review Document Controller requests to unlock editing on published documents.
                    </p>
                </div>
            </div>
        </div>
        <div class="rb-header-actions">
            <span class="rb-count-badge">
                <i class="fa-solid fa-inbox"></i>
                {{ $list['total'] }} pending request{{ $list['total'] === 1 ? '' : 's' }}
            </span>
            <a href="{{ route('dcs.recycle-bin', absolute: false) }}" class="rb-back-link">
                <i class="fa-solid fa-trash-can"></i> Recycle Bin
            </a>
            <a href="{{ route('dcs.register.update', absolute: false) }}" class="rb-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Update
            </a>
        </div>
    </div>

    <div class="rb-callout">
        <div class="rb-callout-icon"><i class="fa-solid fa-circle-info"></i></div>
        <div class="rb-callout-text">
            <strong>HEAD Admin of DCS</strong>
            <p>
                Approve to unlock editing for the requester. Deny with a short note if the change should not proceed.
                After the document is saved, edit access locks again — Controllers may request edit again as often as needed.
            </p>
        </div>
    </div>

    <div class="rb-search-bar">
        <div class="rb-search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="rb-search-input" wire:model.live.debounce.400ms="search"
                placeholder="Search by title, document no, requester, or reason..."
                autocomplete="off">
        </div>
    </div>

    <div class="rb-table-card">
        <div class="upd-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
            <table class="rb-table">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Document No.</th>
                        <th>Rev</th>
                        <th>Requested by</th>
                        <th>Reason</th>
                        <th>Requested</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($list['rows'] as $row)
                        <tr>
                            <td class="upd-doc-title">{{ $row['title'] }}</td>
                            <td class="upd-doc-no">{{ $row['doc_no'] }}</td>
                            <td><span class="upd-rev-badge">{{ $row['rev_no'] }}</span></td>
                            <td>{{ $row['requester'] }}</td>
                            <td>
                                <span class="rb-delete-reason" title="{{ $row['reason'] }}">{{ $row['reason'] }}</span>
                            </td>
                            <td>{{ $row['requested_at'] }}</td>
                            <td>
                                <div class="upd-actions">
                                    <button type="button" class="upd-btn-icon" title="Approve edit"
                                        wire:click="confirmApprove({{ $row['request_id'] }}, @js($row['title']), @js($row['doc_no']), @js($row['requester']), @js($row['reason']))"
                                        wire:loading.attr="disabled">
                                        <i class="fa-solid fa-check" style="color:#15803d;"></i>
                                    </button>
                                    <button type="button" class="upd-btn-icon danger" title="Deny request"
                                        wire:click="confirmDeny({{ $row['request_id'] }}, @js($row['title']), @js($row['doc_no']))">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="upd-empty" @if(count($list['rows']) > 0) style="display:none" @endif>
            <i class="fa-solid fa-inbox"></i>
            <p>No pending edit requests</p>
        </div>
        <div class="upd-pagination">
            <div class="upd-pagination-info">
                Page {{ $list['current_page'] }} of {{ $list['last_page'] }} ({{ $list['total'] }} total)
            </div>
            <div class="upd-pagination-links">
                @if($list['current_page'] > 1)
                    <button type="button" class="upd-pg" wire:click="goToPage({{ $list['current_page'] - 1 }})">Prev</button>
                @endif
                @if($list['current_page'] < $list['last_page'])
                    <button type="button" class="upd-pg" wire:click="goToPage({{ $list['current_page'] + 1 }})">Next</button>
                @endif
            </div>
        </div>
    </div>

    @if($approveId)
    @teleport('body')
    <div class="upd-modal-overlay" style="display:flex;">
        <div class="upd-modal upd-modal-wide">
            <div class="upd-modal-icon"><i class="fa-solid fa-lock-open" style="color:#15803d;"></i></div>
            <h3>Approve Edit Access?</h3>
            <p>
                Unlock editing for <strong>{{ $approveTitle }}</strong>
                @if($approveDocNo !== '' && $approveDocNo !== 'N/A')
                    ({{ $approveDocNo }})
                @endif
                so <strong>{{ $approveRequester }}</strong> can update it.
            </p>
            @if($approveReason !== '')
                <div class="upd-modal-field">
                    <label>Requester reason</label>
                    <p style="margin:0;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#334155;font-size:0.9rem;">
                        {{ $approveReason }}
                    </p>
                </div>
            @endif
            <p style="margin-top:0.75rem;color:#64748b;font-size:0.9rem;">
                After they save the document, edit access locks again. They may request edit again anytime.
            </p>
            <div class="upd-modal-actions">
                <button type="button" class="upd-modal-btn upd-modal-cancel" wire:click="closeApprove">Cancel</button>
                <button type="button" class="upd-modal-btn upd-modal-confirm" wire:click="approve" wire:loading.attr="disabled"
                    style="background:#15803d;border-color:#15803d;">
                    <i class="fa-solid fa-check"></i> Approve Edit
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif

    @if($denyId)
    @teleport('body')
    <div class="upd-modal-overlay" style="display:flex;">
        <div class="upd-modal upd-modal-wide">
            <div class="upd-modal-icon upd-modal-icon-recycle"><i class="fa-solid fa-ban"></i></div>
            <h3>Deny Edit Request?</h3>
            <p>
                Deny unlocking <strong>{{ $denyTitle }}</strong>
                @if($denyDocNo !== '' && $denyDocNo !== 'N/A')
                    ({{ $denyDocNo }})
                @endif
                and notify the requester.
            </p>
            <div class="upd-modal-field">
                <label for="editDenyNote">Denial note <span>*</span></label>
                <textarea id="editDenyNote" class="upd-modal-textarea" rows="3"
                    wire:model="denyNote"
                    placeholder="Explain why this edit should not proceed..."
                    maxlength="1000"></textarea>
                @if($denyError !== '')
                    <div class="upd-modal-error">{{ $denyError }}</div>
                @endif
            </div>
            <div class="upd-modal-actions">
                <button type="button" class="upd-modal-btn upd-modal-cancel" wire:click="closeDeny">Cancel</button>
                <button type="button" class="upd-modal-btn upd-modal-confirm" wire:click="deny" wire:loading.attr="disabled">
                    <i class="fa-solid fa-ban"></i> Deny Request
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif
</div>

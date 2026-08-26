<?php

use App\Helpers\RegisterQueryHelper;
use App\Helpers\RegisterUpdateHelper;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Recycle Bin — CSPC DCS')] class extends Component {
    #[Url]
    public string $search = '';

    public int $page = 1;

    public ?int $restoreId = null;
    public string $restoreTitle = '';
    public string $restoreDocNo = '';

    public function with(): array
    {
        return [
            'list' => RegisterQueryHelper::recycleBinList($this->search, $this->page),
        ];
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function confirmRestore(int $id, string $title, string $docNo): void
    {
        $this->restoreId = $id;
        $this->restoreTitle = $title;
        $this->restoreDocNo = $docNo;
    }

    public function closeRestore(): void
    {
        $this->restoreId = null;
        $this->restoreTitle = '';
        $this->restoreDocNo = '';
    }

    public function restore(): void
    {
        if (!$this->restoreId) {
            return;
        }

        $response = RegisterUpdateHelper::restore($this->restoreId);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }
}; ?>

<div class="rb-container main-content">
    <div class="rb-header">
        <div>
            <div class="upd-breadcrumb">Document Control System / <span>Recycle Bin</span></div>
            <div class="rb-title-wrap">
                <div class="rb-title-icon"><i class="fa-solid fa-trash-can"></i></div>
                <div>
                    <div class="rb-title">Recycle Bin</div>
                    <p class="rb-subtitle">Deleted documents are kept here until restored.</p>
                </div>
            </div>
        </div>
        <div class="rb-header-actions">
            <span class="rb-count-badge">
                <i class="fa-solid fa-trash-can"></i>
                {{ $list['total'] }} deleted document{{ $list['total'] === 1 ? '' : 's' }}
            </span>
            <a href="{{ route('dcs.register.update', absolute: false) }}" class="rb-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Update
            </a>
        </div>
    </div>

    <div class="rb-callout">
        <div class="rb-callout-icon"><i class="fa-solid fa-circle-info"></i></div>
        <div class="rb-callout-text">
            <strong>Soft delete only</strong>
            <p>
                Documents deleted from the Update page are moved here and stay in the system.
                Use <strong>Restore</strong> to return a document to the active Update list.
                There is no permanent delete.
            </p>
        </div>
    </div>

    <div class="rb-search-bar">
        <div class="rb-search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="rb-search-input" wire:model.live.debounce.400ms="search"
                placeholder="Search deleted documents by title or document no..." autocomplete="off">
        </div>
    </div>

    <div class="rb-table-card">
        <div class="upd-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
            <table class="rb-table">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Document No.</th>
                        <th>Type</th>
                        <th>Rev</th>
                        <th>Deleted</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($list['rows'] as $doc)
                        <tr>
                            <td data-label="Document">
                                <div class="rb-doc-title" title="{{ $doc['title'] }}">{{ $doc['title'] }}</div>
                            </td>
                            <td data-label="Document No.">
                                <span class="rb-doc-no">{{ $doc['doc_no'] }}</span>
                            </td>
                            <td data-label="Type">
                                <span class="rb-type-badge">{{ $doc['doc_type'] }}</span>
                            </td>
                            <td data-label="Rev">
                                <span class="rb-rev-badge">Rev {{ $doc['rev_no'] }}</span>
                            </td>
                            <td data-label="Deleted">
                                <span class="rb-deleted-at">{{ $doc['deleted_at'] }}</span>
                                @if(!empty($doc['deleted_by']))
                                    <span class="rb-deleted-by">by {{ $doc['deleted_by'] }}</span>
                                @endif
                            </td>
                            <td data-label="Actions">
                                <div class="rb-actions">
                                    <button type="button" class="rb-btn rb-btn-restore" title="Restore document"
                                        wire:click="confirmRestore({{ $doc['request_id'] }}, @js($doc['title']), @js($doc['doc_no']))">
                                        <i class="fa-solid fa-rotate-left"></i> Restore
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="rb-empty" @if(count($list['rows']) > 0) style="display:none" @endif>
            <div class="rb-empty-icon"><i class="fa-solid fa-trash-can"></i></div>
            <h3>Recycle Bin is empty</h3>
            <p>Deleted documents will appear here. You can restore them anytime.</p>
        </div>

        <div class="rb-pagination">
            <div class="rb-pagination-info">
                Page {{ $list['current_page'] }} of {{ $list['last_page'] }} ({{ $list['total'] }} total)
            </div>
            <div class="rb-pagination-links">
                @if($list['current_page'] > 1)
                    <button type="button" class="rb-pg" wire:click="goToPage({{ $list['current_page'] - 1 }})">Prev</button>
                @endif
                @if($list['current_page'] < $list['last_page'])
                    <button type="button" class="rb-pg" wire:click="goToPage({{ $list['current_page'] + 1 }})">Next</button>
                @endif
            </div>
        </div>
    </div>

    @if($restoreId)
    @teleport('body')
    <div class="rb-modal-overlay">
        <div class="rb-modal">
            <div class="rb-modal-icon is-restore"><i class="fa-solid fa-rotate-left"></i></div>
            <h3>Restore Document?</h3>
            <p>
                Restore <strong>{{ $restoreTitle }}</strong>
                @if($restoreDocNo && $restoreDocNo !== 'N/A')
                    (<span>{{ $restoreDocNo }}</span>)
                @endif
                back to the active Update Documents list?
            </p>
            <div class="rb-modal-actions">
                <button type="button" class="rb-modal-btn rb-modal-cancel" wire:click="closeRestore">Cancel</button>
                <button type="button" class="rb-modal-btn rb-modal-restore" wire:click="restore" wire:loading.attr="disabled">
                    <i class="fa-solid fa-rotate-left"></i> Restore
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif
</div>

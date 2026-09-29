<?php

use App\Helpers\RegisterQueryHelper;
use App\Helpers\RegisterUpdateHelper;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('CSPC - Document Control System')] class extends Component {
    #[Url]
    public string $search = '';

    #[Url]
    public string $docTypeId = 'all';

    public int $page = 1;

    public ?int $deleteId = null;
    public string $deleteTitle = '';
    public int $deleteRev = 0;

    public function with(): array
    {
        return [
            'docTypes' => RegisterQueryHelper::parentDocTypes(),
            'list' => RegisterQueryHelper::draftList($this->search, $this->docTypeId, $this->page),
        ];
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedDocTypeId(): void
    {
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->docTypeId = 'all';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function confirmDelete(int $id, string $title, int $rev): void
    {
        $this->deleteId = $id;
        $this->deleteTitle = $title;
        $this->deleteRev = $rev;
    }

    public function closeDelete(): void
    {
        $this->deleteId = null;
        $this->deleteTitle = '';
        $this->deleteRev = 0;
    }

    public function destroy(): void
    {
        if (!$this->deleteId) {
            return;
        }

        $response = RegisterUpdateHelper::destroyDraftPermanently($this->deleteId);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }
}; ?>

<div class="upd-container main-content">
    <div class="upd-header">
        <div>
            <div class="upd-breadcrumb">Document Control System / Document Registration / <span>Drafts</span></div>
            <div class="upd-title">Draft Documents</div>
        </div>
        <div class="upd-header-stats">
            <span class="upd-count">{{ $list['total'] }} Draft{{ $list['total'] === 1 ? '' : 's' }}</span>
        </div>
    </div>

    <div class="upd-search-bar">
        <div class="upd-search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="upd-search-input" wire:model.live.debounce.400ms="search"
                placeholder="Search drafts by title, document no, DRF no..." autocomplete="off">
        </div>
        <select class="upd-filter-select" wire:model.live="docTypeId">
            <option value="all">All Document Types</option>
            @foreach($docTypes as $type)
                <option value="{{ $type->id }}">{{ $type->doc_type_name }}</option>
            @endforeach
        </select>
        <button type="button" class="upd-btn-search" wire:click="clearFilters" title="Reset filters">
            <i class="fa-solid fa-xmark"></i> Clear
        </button>
    </div>

    <div class="upd-table-card" style="position:relative;" wire:loading.class="is-loading">
        <div class="dcs-loading-overlay" wire:loading.flex>
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4>Loading drafts…</h4>
            <p>Fetching records and preparing the list.</p>
        </div>
        @if(count($list['rows']) > 0)
        <div class="upd-table-scroll">
            <table class="upd-table">
                <thead>
                    <tr>
                        <th style="width:56px;">#</th>
                        <th>Doc Type</th>
                        <th>Title</th>
                        <th>Document No.</th>
                        <th>Rev</th>
                        <th>Status</th>
                        <th style="width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($list['rows'] as $i => $doc)
                        @php
                            $itemNo = (($list['current_page'] - 1) * $list['per_page']) + $i + 1;
                        @endphp
                        <tr class="upd-parent-row">
                            <td><span class="upd-id">{{ $itemNo }}</span></td>
                            <td>{{ $doc['doc_type'] }}</td>
                            <td class="upd-doc-title">{{ $doc['title'] }}</td>
                            <td class="upd-doc-no">{{ $doc['doc_no'] }}</td>
                            <td><span class="upd-rev-badge">{{ $doc['rev_no'] }}</span></td>
                            <td><span class="upd-status-badge is-draft">Draft</span></td>
                            <td>
                                <div class="upd-actions">
                                    <a href="{{ $doc['edit_url'] }}" class="upd-btn-icon" title="Continue editing"><i class="fa-solid fa-pen"></i></a>
                                    @if(!empty($doc['can_delete']))
                                        <button type="button" class="upd-btn-icon danger" title="Delete draft"
                                            wire:click="confirmDelete({{ $doc['request_id'] }}, @js($doc['title']), {{ $doc['rev_no'] }})">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(($list['last_page'] ?? 1) > 1)
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
        @endif
        @else
        <div class="upd-empty">
            <i class="fa-regular fa-floppy-disk" aria-hidden="true"></i>
            <p>No drafts yet</p>
            <span>Use <strong>Save Draft</strong> while registering to continue later here.</span>
        </div>
        @endif
    </div>

    @if($deleteId)
    @teleport('body')
    <div id="deleteModal" class="upd-modal-overlay" style="display:flex;">
        <div class="upd-modal upd-modal-wide">
            <div class="upd-modal-icon upd-modal-icon-recycle"><i class="fa-solid fa-trash-can"></i></div>
            <h3>Delete Draft Permanently?</h3>
            <p>
                This will permanently delete the draft
                <strong>{{ $deleteTitle }}</strong>@if($deleteRev !== null) (Rev {{ $deleteRev }})@endif.
                The registered document is not a draft and will not be deleted.
            </p>
            <div class="upd-modal-actions">
                <button type="button" class="upd-modal-btn upd-modal-cancel" wire:click="closeDelete">Cancel</button>
                <button type="button" class="upd-modal-btn upd-modal-confirm" wire:click="destroy" wire:loading.attr="disabled">
                    <i class="fa-solid fa-trash-can"></i> Delete permanently
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif
</div>

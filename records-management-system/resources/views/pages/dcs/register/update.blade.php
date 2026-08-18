<?php

use App\Helpers\RegisterQueryHelper;
use App\Helpers\RegisterUpdateHelper;
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
            'list' => RegisterQueryHelper::updateList($this->search, $this->docTypeId, $this->page),
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

    public function destroy()
    {
        if (!$this->deleteId) {
            return;
        }

        return RegisterUpdateHelper::destroy($this->deleteId);
    }
}; ?>

<div class="upd-container main-content">
    <div class="upd-header">
        <div>
            <div class="upd-breadcrumb">Document Control System / Document Registration / <span>Update</span></div>
            <div class="upd-title">Update Documents</div>
        </div>
        <div class="upd-header-stats">
            <span class="upd-count">{{ $list['total'] }} Document{{ $list['total'] === 1 ? '' : 's' }}</span>
        </div>
    </div>

    <div class="upd-search-bar">
        <div class="upd-search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="upd-search-input" wire:model.live.debounce.400ms="search"
                placeholder="Search by title, document no, DRF no, DCN no..." autocomplete="off">
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

    <div class="upd-table-card">
        <div class="upd-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
            <table class="upd-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Doc Type</th>
                        <th>Title</th>
                        <th>Document No.</th>
                        <th>Rev</th>
                        <th>Checklists</th>
                        <th style="width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($list['rows'] as $i => $doc)
                        <tr>
                            <td>{{ (($list['current_page'] - 1) * $list['per_page']) + $i + 1 }}</td>
                            <td>{{ $doc['doc_type'] }}</td>
                            <td>{{ $doc['title'] }}</td>
                            <td>{{ $doc['doc_no'] }}</td>
                            <td>{{ $doc['rev_no'] }}</td>
                            <td>
                                @foreach($doc['checklists'] as $cl)
                                    <span class="upd-checklist-tag">{{ $cl }}</span>
                                @endforeach
                            </td>
                            <td>
                                <div class="upd-actions">
                                    <a href="{{ $doc['edit_url'] }}" class="upd-btn-icon" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    @if($doc['history_url'])
                                        <a href="{{ $doc['history_url'] }}" class="upd-btn-icon" title="History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                                    @endif
                                    <button type="button" class="upd-btn-icon danger" title="Delete"
                                        wire:click="confirmDelete({{ $doc['request_id'] }}, @js($doc['title']), {{ $doc['rev_no'] }})">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="upd-empty" @if(count($list['rows']) > 0) style="display:none" @endif>
            <i class="fa-solid fa-folder-open"></i>
            <p>No documents found</p>
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

    @if($deleteId)
    <div id="deleteModal" class="upd-modal-overlay" style="display:flex;">
        <div class="upd-modal">
            <div class="upd-modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <h3>Delete Document?</h3>
            <p>This will permanently remove "<strong>{{ $deleteTitle }}</strong>" (Rev {{ $deleteRev }}). This action cannot be undone.</p>
            <div class="upd-modal-actions">
                <button type="button" class="upd-modal-btn upd-modal-cancel" wire:click="closeDelete">Cancel</button>
                <button type="button" class="upd-modal-btn upd-modal-confirm" wire:click="destroy" wire:loading.attr="disabled">
                    <i class="fa-solid fa-trash-can"></i> Delete
                </button>
            </div>
        </div>
    </div>
    @endif
</div>

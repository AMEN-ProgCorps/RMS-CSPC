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
    public string $deleteReason = '';
    public string $deleteError = '';

    public ?int $editRequestId = null;
    public string $editRequestTitle = '';
    public int $editRequestRev = 0;
    public string $editRequestReason = '';
    public string $editRequestError = '';
    public string $editRequestMode = 'request';

    public function with(): array
    {
        return [
            'docTypes' => RegisterQueryHelper::parentDocTypes(),
            'list' => RegisterQueryHelper::updateList($this->search, $this->docTypeId, $this->page),
            'canReviewRecycleBin' => RegisterQueryHelper::isDocumentControlHead(),
            'isHeadAdmin' => RegisterQueryHelper::isDocumentControlHead(),
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
        $this->closeEditRequest();
        $this->deleteId = $id;
        $this->deleteTitle = $title;
        $this->deleteRev = $rev;
        $this->deleteReason = '';
        $this->deleteError = '';
    }

    public function closeDelete(): void
    {
        $this->deleteId = null;
        $this->deleteTitle = '';
        $this->deleteRev = 0;
        $this->deleteReason = '';
        $this->deleteError = '';
    }

    public function destroy(): void
    {
        if (!$this->deleteId) {
            return;
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $this->deleteReason) ?? '');
        if ($reason === '' || mb_strlen($reason) < 5) {
            $this->deleteError = 'Please enter a delete reason (at least 5 characters).';

            return;
        }
        if (mb_strlen($reason) > 1000) {
            $this->deleteError = 'Delete reason must be 1000 characters or fewer.';

            return;
        }

        $response = RegisterUpdateHelper::destroy($this->deleteId, $reason);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }

    public function confirmEditRequest(int $id, string $title, int $rev, string $mode = 'request'): void
    {
        $this->closeDelete();
        $this->editRequestId = $id;
        $this->editRequestTitle = $title;
        $this->editRequestRev = $rev;
        $this->editRequestReason = '';
        $this->editRequestError = '';
        $this->editRequestMode = $mode === 'request_again' ? 'request_again' : 'request';
    }

    public function closeEditRequest(): void
    {
        $this->editRequestId = null;
        $this->editRequestTitle = '';
        $this->editRequestRev = 0;
        $this->editRequestReason = '';
        $this->editRequestError = '';
        $this->editRequestMode = 'request';
    }

    public function submitEditRequest(): void
    {
        if (!$this->editRequestId) {
            return;
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $this->editRequestReason) ?? '');
        if ($reason === '' || mb_strlen($reason) < 5) {
            $this->editRequestError = 'Please enter a reason (at least 5 characters).';

            return;
        }
        if (mb_strlen($reason) > 1000) {
            $this->editRequestError = 'Reason must be 1000 characters or fewer.';

            return;
        }

        $response = RegisterUpdateHelper::requestEdit($this->editRequestId, $reason);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
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

    <div class="upd-table-card" x-data="{ expanded: {} }" style="position:relative;" wire:loading.class="is-loading">
        <div class="dcs-loading-overlay" wire:loading.flex>
            <div class="dcs-loading-spinner" aria-hidden="true"></div>
            <h4>Loading documents…</h4>
            <p>Fetching records and preparing the list.</p>
        </div>
        <div class="upd-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
            <table class="upd-table">
                <thead>
                    <tr>
                        <th style="width:56px;">#</th>
                        <th>Doc Type</th>
                        <th>Title</th>
                        <th>Document No.</th>
                        <th>Rev</th>
                        <th>Status</th>
                        <th style="width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($list['rows'] as $i => $group)
                        @php
                            $doc = $group['parent'];
                            $itemNo = (($list['current_page'] - 1) * $list['per_page']) + $i + 1;
                            $revKey = 'g-' . $doc['request_id'];
                            $children = $group['children'] ?? [];
                            $editAction = $doc['edit_action'] ?? 'edit';
                        @endphp
                        <tr class="upd-parent-row">
                            <td>
                                @if(!empty($children))
                                    <button type="button" class="upd-expand-btn"
                                        :class="{ open: expanded['{{ $revKey }}'] }"
                                        @click="expanded['{{ $revKey }}'] = !expanded['{{ $revKey }}']"
                                        :aria-expanded="!!expanded['{{ $revKey }}']"
                                        title="{{ !empty($group['allows_revision']) || !array_key_exists('allows_revision', $group) ? 'Show obsolete revisions' : 'Show more registrations' }}">
                                        <i class="fa-solid" :class="expanded['{{ $revKey }}'] ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                                    </button>
                                @endif
                                <span class="upd-id">{{ $itemNo }}</span>
                            </td>
                            <td>{{ $doc['doc_type'] }}</td>
                            <td class="upd-doc-title">{{ $doc['title'] }}</td>
                            <td class="upd-doc-no">{{ $doc['doc_no'] }}</td>
                            <td><span class="upd-rev-badge">{{ $doc['rev_no'] }}</span></td>
                            <td>
                                @php
                                    $parentStatus = strtolower((string) ($doc['revision_status'] ?? 'latest'));
                                    $parentIsLatest = $parentStatus !== 'obsolete' && $parentStatus !== 'draft';
                                @endphp
                                <span class="upd-status-badge {{ $parentIsLatest ? 'is-latest' : 'is-obsolete' }}">{{ $parentIsLatest ? 'Latest' : 'Obsolete' }}</span>
                                @if(!empty($children))
                                    <span class="upd-rev-count">+{{ count($children) }} {{ (!array_key_exists('allows_revision', $group) || !empty($group['allows_revision'])) ? 'older' : 'more' }}</span>
                                @endif
                                @if(($doc['edit_request_status'] ?? null) === 'pending')
                                    <span class="upd-status-badge" style="margin-left:6px;background:#fef3c7;color:#92400e;">Edit pending</span>
                                @elseif(($doc['edit_request_status'] ?? null) === 'denied')
                                    <span class="upd-status-badge" style="margin-left:6px;background:#fee2e2;color:#991b1b;" title="{{ $doc['edit_review_note'] ?? '' }}">Edit denied</span>
                                @endif
                            </td>
                            <td>
                                <div class="upd-actions">
                                    @if($editAction === 'edit')
                                        <a href="{{ $doc['edit_url'] }}" class="upd-btn-icon" title="Edit"><i class="fa-solid fa-pen"></i></a>
                                    @elseif($editAction === 'pending')
                                        <a href="{{ $doc['edit_url'] }}" class="upd-btn-icon" title="View / Generate distribution (edit pending)"><i class="fa-solid fa-eye"></i></a>
                                        <span class="upd-btn-icon" style="opacity:0.55;cursor:default;" title="Waiting for HEAD Admin approval"><i class="fa-solid fa-hourglass-half"></i></span>
                                    @elseif(in_array($editAction, ['request', 'request_again'], true))
                                        <a href="{{ $doc['edit_url'] }}" class="upd-btn-icon" title="View / Generate distribution"><i class="fa-solid fa-eye"></i></a>
                                        <button type="button" class="upd-btn-icon" title="{{ $editAction === 'request_again' ? 'Request edit again' : 'Request edit' }}"
                                            wire:click="confirmEditRequest({{ $doc['request_id'] }}, @js($doc['title']), {{ $doc['rev_no'] }}, @js($editAction))">
                                            <i class="fa-solid fa-lock"></i>
                                        </button>
                                    @endif
                                    @if($doc['history_url'])
                                        <a href="{{ $doc['history_url'] }}" class="upd-btn-icon" title="History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                                    @endif
                                    @if(!empty($doc['can_delete']))
                                        <button type="button" class="upd-btn-icon danger" title="Delete"
                                            wire:click="confirmDelete({{ $doc['request_id'] }}, @js($doc['title']), {{ $doc['rev_no'] }})">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @foreach($children as $ci => $child)
                            @php
                                $childStatus = strtolower((string) ($child['revision_status'] ?? 'latest'));
                                $childIsLatest = $childStatus !== 'obsolete' && $childStatus !== 'draft';
                                $childEditAction = $child['edit_action'] ?? 'edit';
                            @endphp
                            <tr class="upd-child-row" x-show="expanded['{{ $revKey }}']" x-cloak>
                                <td class="upd-child-ind"></td>
                                <td>{{ $child['doc_type'] }}</td>
                                <td class="upd-doc-title">{{ $child['title'] }}</td>
                                <td class="upd-doc-no">{{ $child['doc_no'] }}</td>
                                <td><span class="upd-rev-badge">{{ $child['rev_no'] }}</span></td>
                                <td><span class="upd-status-badge {{ $childIsLatest ? 'is-latest' : 'is-obsolete' }}">{{ $childIsLatest ? 'Latest' : 'Obsolete' }}</span></td>
                                <td>
                                    <div class="upd-actions">
                                        @if($childEditAction === 'edit')
                                            <a href="{{ $child['edit_url'] }}" class="upd-btn-icon" title="{{ $childIsLatest ? 'Edit' : 'Edit obsolete revision' }}"><i class="fa-solid fa-pen"></i></a>
                                        @elseif($childEditAction === 'pending')
                                            <a href="{{ $child['edit_url'] }}" class="upd-btn-icon" title="View / Generate distribution (edit pending)"><i class="fa-solid fa-eye"></i></a>
                                            <span class="upd-btn-icon" style="opacity:0.55;cursor:default;" title="Waiting for HEAD Admin approval"><i class="fa-solid fa-hourglass-half"></i></span>
                                        @elseif(in_array($childEditAction, ['request', 'request_again'], true))
                                            <a href="{{ $child['edit_url'] }}" class="upd-btn-icon" title="View / Generate distribution"><i class="fa-solid fa-eye"></i></a>
                                            <button type="button" class="upd-btn-icon" title="Request edit"
                                                wire:click="confirmEditRequest({{ $child['request_id'] }}, @js($child['title']), {{ $child['rev_no'] }}, @js($childEditAction))">
                                                <i class="fa-solid fa-lock"></i>
                                            </button>
                                        @endif
                                        @if($child['history_url'])
                                            <a href="{{ $child['history_url'] }}" class="upd-btn-icon" title="History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
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
    @teleport('body')
    <div id="deleteModal" class="upd-modal-overlay" style="display:flex;">
        <div class="upd-modal upd-modal-wide">
            <div class="upd-modal-icon upd-modal-icon-recycle"><i class="fa-solid fa-trash-can"></i></div>
            <h3>Delete Document?</h3>
            <p>
                This will move <strong>{{ $deleteTitle }}</strong> (Rev {{ $deleteRev }}) to the Recycle Bin
                for HEAD Admin of DCS review. It is not permanently deleted.
                @if($canReviewRecycleBin)
                    You can review it in the
                    <a href="{{ route('dcs.recycle-bin', absolute: false) }}" class="upd-modal-link">Recycle Bin</a>.
                @else
                    Only the HEAD Admin of DCS can review it in the Recycle Bin or permanently delete it.
                @endif
            </p>
            <div class="upd-modal-field">
                <label for="updDeleteReason">Delete reason <span>*</span></label>
                <textarea id="updDeleteReason" class="upd-modal-textarea" rows="3"
                    wire:model="deleteReason"
                    placeholder="Explain why this document is being deleted..."
                    maxlength="1000"></textarea>
                @if($deleteError !== '')
                    <div class="upd-modal-error">{{ $deleteError }}</div>
                @endif
            </div>
            <div class="upd-modal-actions">
                <button type="button" class="upd-modal-btn upd-modal-cancel" wire:click="closeDelete">Cancel</button>
                <button type="button" class="upd-modal-btn upd-modal-confirm" wire:click="destroy" wire:loading.attr="disabled">
                    <i class="fa-solid fa-trash-can"></i> Move to Recycle Bin
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif

    @if($editRequestId)
    @teleport('body')
    <div id="editRequestModal" class="upd-modal-overlay" style="display:flex;">
        <div class="upd-modal upd-modal-wide">
            <div class="upd-modal-icon"><i class="fa-solid fa-lock"></i></div>
            <h3>{{ $editRequestMode === 'request_again' ? 'Request Edit Again?' : 'Request Edit?' }}</h3>
            <p>
                Ask the HEAD Admin of DCS to unlock editing for
                <strong>{{ $editRequestTitle }}</strong> (Rev {{ $editRequestRev }}).
                Include why the document needs to be changed.
            </p>
            <div class="upd-modal-field">
                <label for="updEditRequestReason">Reason for edit <span>*</span></label>
                <textarea id="updEditRequestReason" class="upd-modal-textarea" rows="3"
                    wire:model="editRequestReason"
                    placeholder="Explain why this document needs to be edited..."
                    maxlength="1000"></textarea>
                @if($editRequestError !== '')
                    <div class="upd-modal-error">{{ $editRequestError }}</div>
                @endif
            </div>
            <div class="upd-modal-actions">
                <button type="button" class="upd-modal-btn upd-modal-cancel" wire:click="closeEditRequest">Cancel</button>
                <button type="button" class="upd-modal-btn upd-modal-confirm" wire:click="submitEditRequest" wire:loading.attr="disabled">
                    <i class="fa-solid fa-paper-plane"></i> Send Request
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif
</div>

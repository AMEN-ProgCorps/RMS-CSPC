<?php

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use App\Helpers\RegisterUpdateHelper;
use App\Helpers\SettingsRecycleHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.dcs')] #[Title('Recycle Bin — CSPC DCS')] class extends Component {
    #[Url]
    public string $search = '';

    #[Url]
    public string $tab = 'documents';

    public int $page = 1;

    public ?int $restoreId = null;
    public string $restoreKind = '';
    public string $restoreTitle = '';
    public string $restoreDocNo = '';

    public ?int $deleteId = null;
    public string $deleteKind = '';
    public string $deleteTitle = '';
    public string $deleteDocNo = '';
    public string $deleteConfirmCode = '';
    public string $deleteError = '';

    public function with(): array
    {
        $this->normalizeTab();

        $docs = RegisterQueryHelper::recycleBinList(
            $this->tab === 'documents' ? $this->search : '',
            $this->tab === 'documents' ? $this->page : 1
        );
        $settings = SettingsRecycleHelper::recycleBinList(
            $this->tab === 'settings' ? $this->search : '',
            $this->tab === 'settings' ? $this->page : 1
        );

        $list = $this->tab === 'settings' ? $settings : $docs;

        return [
            'list' => $list,
            'documentsTotal' => $docs['total'],
            'settingsTotal' => $settings['total'],
            'deleteCodeConfigured' => $this->configuredDeleteCode() !== '',
            'isSettingsTab' => $this->tab === 'settings',
            'canPermanentlyDelete' => RegisterQueryHelper::canPermanentlyDeleteDcsDocuments(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
        $this->page = 1;
        $this->search = '';
        $this->closeRestore();
        $this->closeDelete();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->updatedTab();
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function confirmRestore(int $id, string $title, string $docNo, string $kind = ''): void
    {
        $this->closeDelete();
        $this->restoreId = $id;
        $this->restoreKind = $kind;
        $this->restoreTitle = $title;
        $this->restoreDocNo = $docNo;
    }

    public function closeRestore(): void
    {
        $this->restoreId = null;
        $this->restoreKind = '';
        $this->restoreTitle = '';
        $this->restoreDocNo = '';
    }

    public function restore(): void
    {
        RegisterQueryHelper::assertFullDcsUser('recycle_bin');
        if (! $this->restoreId) {
            return;
        }

        if ($this->restoreKind !== '') {
            $result = SettingsRecycleHelper::restore($this->restoreKind, $this->restoreId);
            $title = $this->restoreTitle;
            $this->closeRestore();

            if (! ($result['ok'] ?? false)) {
                $this->dispatch('dcs-toast', message: $result['message'] ?? 'Restore failed.', type: 'error');

                return;
            }

            RegisterPersistHelper::logAdminChange('Restored settings item from Recycle Bin: ' . $title);
            $this->dispatch(
                'dcs-toast',
                message: 'Settings item restored from Recycle Bin successfully.',
                type: 'success'
            );

            return;
        }

        $response = RegisterUpdateHelper::restore($this->restoreId);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }

    public function confirmDelete(int $id, string $title, string $docNo, string $kind = ''): void
    {
        $this->closeRestore();
        $this->deleteId = $id;
        $this->deleteKind = $kind;
        $this->deleteTitle = $title;
        $this->deleteDocNo = $docNo;
        $this->deleteConfirmCode = '';
        $this->deleteError = '';
    }

    public function closeDelete(): void
    {
        $this->deleteId = null;
        $this->deleteKind = '';
        $this->deleteTitle = '';
        $this->deleteDocNo = '';
        $this->deleteConfirmCode = '';
        $this->deleteError = '';
    }

    public function permanentDelete(): void
    {
        RegisterQueryHelper::assertFullDcsUser('recycle_bin');
        abort_unless(
            RegisterQueryHelper::canPermanentlyDeleteDcsDocuments(),
            403,
            'Only the HEAD Admin of DCS can permanently delete documents.'
        );
        if (! $this->deleteId) {
            return;
        }

        $this->deleteError = '';

        $rateCheck = \App\Services\RateLimiterService::check('dcs_action');
        if (! $rateCheck['allowed']) {
            $this->deleteError = $rateCheck['message'];

            return;
        }

        $expectedCode = $this->configuredDeleteCode();

        if ($expectedCode === '') {
            $this->deleteError = 'Permanent delete is not configured. Set the code in Admin → System Settings (or DCS_RECYCLE_DELETE_CODE in the server environment).';
            RegisterPersistHelper::logAdminChange(
                'Blocked permanent delete of #' . $this->deleteId . ' — delete code not configured'
            );

            return;
        }

        $provided = trim($this->deleteConfirmCode);
        if ($provided === '' || ! hash_equals($expectedCode, $provided)) {
            $this->deleteError = 'Incorrect secret code. Permanent delete was blocked.';
            RegisterPersistHelper::logAdminChange(
                'Blocked permanent delete of #' . $this->deleteId . ' — invalid delete code'
            );

            return;
        }

        $id = $this->deleteId;
        $kind = $this->deleteKind;
        $title = $this->deleteTitle;
        $docNo = $this->deleteDocNo;
        $this->closeDelete();

        \App\Services\DcsAuditService::log(
            'recycle.permanent_delete',
            'recycle_bin',
            $kind === '' ? $id : null,
            null,
            ['id' => $id, 'kind' => $kind, 'title' => $title, 'doc_no' => $docNo]
        );

        if ($kind !== '') {
            SettingsRecycleHelper::permanentDestroy($kind, $id);
            RegisterPersistHelper::logAdminChange(
                'Permanently deleted settings item #' . $id
                . ($title !== '' ? ': ' . $title : '')
                . ' (secret code verified)'
            );
            $this->dispatch(
                'dcs-toast',
                message: 'Settings item permanently deleted from Recycle Bin.',
                type: 'success'
            );

            return;
        }

        RegisterPersistHelper::logAdminChange(
            'Permanently deleted document #' . $id
            . ($docNo !== '' && $docNo !== 'N/A' ? ' — ' . $docNo : '')
            . ($title !== '' ? ': ' . $title : '')
            . ' (secret code verified)'
        );

        $response = RegisterUpdateHelper::permanentDestroy($id);
        if ($response instanceof RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: true);
        }
    }

    private function normalizeTab(): void
    {
        if (! in_array($this->tab, ['documents', 'settings'], true)) {
            $this->tab = 'documents';
        }
    }

    private function configuredDeleteCode(): string
    {
        try {
            $table = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings')
                ? 'sys_system_settings'
                : 'system_settings';

            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                $fromDb = DB::table($table)->where('key', 'dcs_recycle_delete_code')->value('value');
                if (is_string($fromDb) && trim($fromDb) !== '') {
                    return trim($fromDb);
                }
            }
        } catch (\Throwable) {
        }

        return trim((string) env('DCS_RECYCLE_DELETE_CODE', ''));
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
                    <p class="rb-subtitle">
                        Deleted documents and settings items are kept for {{ $list['retention_years'] ?? 1 }} year, then permanently removed.
                    </p>
                </div>
            </div>
        </div>
        <div class="rb-header-actions">
            <span class="rb-count-badge">
                <i class="fa-solid fa-trash-can"></i>
                {{ $documentsTotal + $settingsTotal }} deleted item{{ ($documentsTotal + $settingsTotal) === 1 ? '' : 's' }}
            </span>
            <a href="{{ route('dcs.register.update', absolute: false) }}" class="rb-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Update
            </a>
        </div>
    </div>

    <div class="rb-tabs" role="tablist">
        <button type="button" class="rb-tab {{ $tab === 'documents' ? 'is-active' : '' }}"
            wire:click="setTab('documents')" role="tab" aria-selected="{{ $tab === 'documents' ? 'true' : 'false' }}">
            <i class="fa-solid fa-file-lines"></i>
            Documents
            <span class="rb-tab-count">{{ $documentsTotal }}</span>
        </button>
        <button type="button" class="rb-tab {{ $tab === 'settings' ? 'is-active' : '' }}"
            wire:click="setTab('settings')" role="tab" aria-selected="{{ $tab === 'settings' ? 'true' : 'false' }}">
            <i class="fa-solid fa-sliders"></i>
            Settings
            <span class="rb-tab-count">{{ $settingsTotal }}</span>
        </button>
    </div>

    <div class="rb-callout">
        <div class="rb-callout-icon"><i class="fa-solid fa-circle-info"></i></div>
        <div class="rb-callout-text">
            <strong>{{ $list['retention_years'] ?? 1 }}-year retention</strong>
            <p>
                @if($isSettingsTab)
                    Items deleted from DCS Settings stay here for
                    <strong>{{ $list['retention_years'] ?? 1 }} year</strong>.
                    Use <strong>Restore</strong> to return them to Settings.
                    @if($canPermanentlyDelete)
                        The HEAD Admin of DCS may <strong>Delete forever</strong> with the operations secret code.
                    @endif
                    After expiry, they are permanently removed.
                @else
                    Documents soft-deleted by DCS admins stay here for
                    <strong>{{ $list['retention_years'] ?? 1 }} year</strong>
                    with their delete reason for HEAD Admin of DCS review.
                    Use <strong>Restore</strong> before expiry.
                    @if($canPermanentlyDelete)
                        Only the HEAD Admin of DCS may <strong>Delete forever</strong> with the operations secret code.
                    @endif
                    After expiry, they are permanently deleted with their files.
                @endif
            </p>
        </div>
    </div>

    <div class="rb-search-bar">
        <div class="rb-search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="rb-search-input" wire:model.live.debounce.400ms="search"
                placeholder="{{ $isSettingsTab ? 'Search deleted settings by name or type...' : 'Search deleted documents by title or document no...' }}"
                autocomplete="off">
        </div>
    </div>

    <div class="rb-table-card">
        <div class="upd-table-scroll" @if(count($list['rows']) === 0) style="display:none" @endif>
            <table class="rb-table">
                <thead>
                    <tr>
                        <th>{{ $isSettingsTab ? 'Item' : 'Document' }}</th>
                        @unless($isSettingsTab)
                            <th>Document No.</th>
                        @endunless
                        <th>Type</th>
                        @unless($isSettingsTab)
                            <th>Rev</th>
                        @endunless
                        <th>Deleted</th>
                        @unless($isSettingsTab)
                            <th>Reason</th>
                        @endunless
                        <th>Expires</th>
                        <th style="width:260px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($list['rows'] as $doc)
                        @php
                            $rowKind = $doc['kind'] ?? '';
                            $rowId = $doc['request_id'] ?? $doc['id'];
                        @endphp
                        <tr>
                            <td data-label="{{ $isSettingsTab ? 'Item' : 'Document' }}">
                                <div class="rb-doc-title" title="{{ $doc['title'] }}">{{ $doc['title'] }}</div>
                            </td>
                            @unless($isSettingsTab)
                                <td data-label="Document No.">
                                    <span class="rb-doc-no">{{ $doc['doc_no'] }}</span>
                                </td>
                            @endunless
                            <td data-label="Type">
                                <span class="rb-type-badge">{{ $doc['doc_type'] }}</span>
                            </td>
                            @unless($isSettingsTab)
                                <td data-label="Rev">
                                    <span class="rb-rev-badge">Rev {{ $doc['rev_no'] }}</span>
                                </td>
                            @endunless
                            <td data-label="Deleted">
                                <span class="rb-deleted-at">{{ $doc['deleted_at'] }}</span>
                                @if(!empty($doc['deleted_by']))
                                    <span class="rb-deleted-by">by {{ $doc['deleted_by'] }}</span>
                                @endif
                            </td>
                            @unless($isSettingsTab)
                                <td data-label="Reason">
                                    @if(!empty($doc['deleted_reason']))
                                        <span class="rb-delete-reason" title="{{ $doc['deleted_reason'] }}">{{ $doc['deleted_reason'] }}</span>
                                    @else
                                        <span class="rb-delete-reason is-empty">No reason recorded</span>
                                    @endif
                                </td>
                            @endunless
                            <td data-label="Expires">
                                <span class="rb-expires-at">{{ $doc['expires_at'] }}</span>
                                @if(isset($doc['days_left']))
                                    @if($doc['days_left'] <= 30)
                                        <span class="rb-expires-soon">{{ max(0, $doc['days_left']) }} day{{ $doc['days_left'] === 1 ? '' : 's' }} left</span>
                                    @else
                                        <span class="rb-expires-left">{{ $doc['days_left'] }} days left</span>
                                    @endif
                                @endif
                            </td>
                            <td data-label="Actions">
                                <div class="rb-actions">
                                    <button type="button" class="rb-btn rb-btn-restore" title="Restore"
                                        wire:click="confirmRestore({{ $rowId }}, @js($doc['title']), @js($doc['doc_no']), @js($rowKind))">
                                        <i class="fa-solid fa-rotate-left"></i> Restore
                                    </button>
                                    @if($canPermanentlyDelete)
                                        <button type="button" class="rb-btn rb-btn-delete" title="Permanently delete"
                                            wire:click="confirmDelete({{ $rowId }}, @js($doc['title']), @js($doc['doc_no']), @js($rowKind))">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </button>
                                    @endif
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
            <p>
                @if($isSettingsTab)
                    Deleted settings items will appear here for {{ $list['retention_years'] ?? 1 }} year and can be restored before they expire.
                @else
                    Deleted documents will appear here for {{ $list['retention_years'] ?? 1 }} year and can be restored before they expire.
                @endif
            </p>
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
            <h3>{{ $restoreKind !== '' ? 'Restore Settings Item?' : 'Restore Document?' }}</h3>
            <p>
                Restore <strong>{{ $restoreTitle }}</strong>
                @if($restoreKind === '' && $restoreDocNo && $restoreDocNo !== 'N/A')
                    (<span>{{ $restoreDocNo }}</span>)
                @endif
                {{ $restoreKind !== '' ? 'back to DCS Settings?' : 'back to the active Update Documents list?' }}
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

    @if($deleteId)
    @teleport('body')
    <div class="rb-modal-overlay">
        <div class="rb-modal rb-modal-wide">
            <div class="rb-modal-icon is-danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <h3>Permanently Delete?</h3>
            <p>
                This will permanently remove <strong>{{ $deleteTitle }}</strong>
                @if($deleteKind === '' && $deleteDocNo && $deleteDocNo !== 'N/A')
                    (<span>{{ $deleteDocNo }}</span>)
                @endif
                {{ $deleteKind !== '' ? 'from Settings.' : 'and its scanned files.' }} This cannot be undone.
            </p>

            <div class="rb-confirm-fields">
                <label class="rb-confirm-label" for="rbDeleteCode">Secret code</label>
                <input id="rbDeleteCode" type="password" class="rb-confirm-input"
                    wire:model="deleteConfirmCode" autocomplete="off"
                    placeholder="Enter permanent-delete secret code"
                    wire:keydown.enter="permanentDelete">
                <p class="rb-confirm-hint">
                    @if($deleteCodeConfigured)
                        Enter the DCS permanent-delete code from Admin → System Settings.
                    @else
                        Permanent delete is disabled until the code is set in Admin → System Settings
                        (or <code>DCS_RECYCLE_DELETE_CODE</code> on the server).
                    @endif
                </p>
            </div>

            @if($deleteError !== '')
                <div class="rb-confirm-error">{{ $deleteError }}</div>
            @endif

            <div class="rb-modal-actions">
                <button type="button" class="rb-modal-btn rb-modal-cancel" wire:click="closeDelete">Cancel</button>
                <button type="button" class="rb-modal-btn rb-modal-danger"
                    wire:click="permanentDelete" wire:loading.attr="disabled"
                    @disabled(! $deleteCodeConfigured)>
                    <span wire:loading.remove wire:target="permanentDelete"><i class="fa-solid fa-trash"></i> Delete forever</span>
                    <span wire:loading wire:target="permanentDelete">Deleting…</span>
                </button>
            </div>
        </div>
    </div>
    @endteleport
    @endif
</div>

<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('DTS - External Transactions History')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    #[On('dts-transaction-updated')]
    #[On('refresh-transactions')]
    public function refreshTransactionsList(): void
    {
        $this->resetPage();
    }

    public int $perPage = 10;
    public string $searchQuery = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sortOrder = 'desc'; // 'desc' or 'asc'
    public string $layoutMode = 'table'; // table or box

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_external) {
            abort(403, 'Unauthorized access to External transactions history.');
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function updatingSearchQuery()
    {
        $this->resetPage();
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    public function updatingDateFrom()
    {
        $this->resetPage();
    }

    public function updatingDateTo()
    {
        $this->resetPage();
    }

    public function updatingSortOrder()
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->searchQuery = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortOrder = 'desc';
        $this->resetPage();
    }

    public function getTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.trans_type', 'external')
            ->where('dtd.is_active', 1)
            ->where('dtd.originated_from', '!=', $userOfficeCode)
            ->whereExists(function($q) use ($userOfficeCode) {
                $q->select(DB::raw(1))
                  ->from((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . ' as log')
                  ->whereColumn('log.transaction_id', 'dt.transaction_id')
                  ->where('log.office_code', $userOfficeCode)
                  ->where(function($sub) {
                      $sub->whereIn('log.type', ['received', 'forwarded'])
                          ->orWhereNotNull('log.date_in')
                          ->orWhereNotNull('log.date_out');
                  });

                if (!empty($this->dateFrom) || !empty($this->dateTo)) {
                    $from = !empty($this->dateFrom) ? \Carbon\Carbon::parse($this->dateFrom)->startOfDay() : null;
                    $to = !empty($this->dateTo) ? \Carbon\Carbon::parse($this->dateTo)->endOfDay() : null;

                    if ($from && $to && $from->gt($to)) {
                        $temp = $from;
                        $from = $to->copy()->startOfDay();
                        $to = $temp->copy()->endOfDay();
                    }

                    $fromStr = $from ? $from->toDateTimeString() : null;
                    $toStr = $to ? $to->toDateTimeString() : null;

                    $q->where(function($dq) use ($fromStr, $toStr) {
                        $dq->where(function($sub) use ($fromStr, $toStr) {
                            if ($fromStr && $toStr) {
                                $sub->whereBetween('log.date_in', [$fromStr, $toStr])
                                    ->orWhereBetween('log.date_out', [$fromStr, $toStr]);
                            } elseif ($fromStr) {
                                $sub->where('log.date_in', '>=', $fromStr)
                                    ->orWhere('log.date_out', '>=', $fromStr);
                            } elseif ($toStr) {
                                $sub->where('log.date_in', '<=', $toStr)
                                    ->orWhere('log.date_out', '<=', $toStr);
                            }
                        })->orWhere(function($sub) use ($fromStr, $toStr) {
                            if ($fromStr && $toStr) {
                                $sub->whereBetween('dtd.date_created', [$fromStr, $toStr]);
                            } elseif ($fromStr) {
                                $sub->where('dtd.date_created', '>=', $fromStr);
                            } elseif ($toStr) {
                                $sub->where('dtd.date_created', '<=', $toStr);
                            }
                        });
                    });
                }
            });

        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $decoded = base64_decode($searchVal, true);
            if ($decoded !== false && preg_match('/^[A-Z0-9-]+$/i', $decoded)) {
                $searchVal = $decoded;
            }
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dt.qr_code', 'like', '%' . $searchVal . '%');
            });
        }

        $sortDirection = in_array(strtolower($this->sortOrder), ['asc', 'desc']) ? strtolower($this->sortOrder) : 'desc';

        return $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dt.trans_type',
            'dtd.control_number',
            'req.requestor_name',
            'req.requestor_position as requestor_label',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'dtd.originated_from',
            DB::raw("COALESCE(NULLIF(flow.referenced_flow, ''), flow.flow_name) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name',
            DB::raw("(SELECT MAX(COALESCE(log.date_in, log.date_out)) FROM " . (\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . " as log WHERE log.transaction_id = dt.transaction_id AND log.office_code = " . DB::getPdo()->quote($userOfficeCode) . ") as office_activity_date")
        )
        ->orderBy('dtd.date_created', $sortDirection)
        ->paginate($this->perPage);
    }
};
?>

@push('styles')
    @vite(['resources/css/dts/list_transaction.css', 'resources/css/dts/receive.css'])
@endpush

<div class="rms-container" wire:poll.10s.keep-alive>
    <div class="rms-header">
        <h2>External Transactions History</h2>
    </div>

    <div class="rms-toolbar" style="margin-bottom: 20px;">
        <div class="rms-toolbar-bottom" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div class="rms-entries" style="display: flex; align-items: center; gap: 8px;">
                Show 
                <select class="rms-select" wire:model.live="perPage">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select> 
                Entries
            </div>

            <div class="rms-filters" style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 0.8rem; font-weight: 600; color: #64748b;">From:</span>
                    <input type="date" class="rms-select" wire:model.live="dateFrom" style="padding-right: 8px; background-image: none; font-size: 0.82rem; height: 34px;" title="Filter From Date">
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 0.8rem; font-weight: 600; color: #64748b;">To:</span>
                    <input type="date" class="rms-select" wire:model.live="dateTo" style="padding-right: 8px; background-image: none; font-size: 0.82rem; height: 34px;" title="Filter To Date">
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <select class="rms-select" wire:model.live="sortOrder" style="font-size: 0.82rem; height: 34px;">
                        <option value="desc">Date (Newest First)</option>
                        <option value="asc">Date (Oldest First)</option>
                    </select>
                </div>
                @if(!empty($dateFrom) || !empty($dateTo) || !empty($searchQuery) || $sortOrder !== 'desc')
                    <button type="button" wire:click="resetFilters" class="rms-select" style="background: #f8fafc; border-color: #cbd5e1; color: #475569; padding-right: 12px; display: inline-flex; align-items: center; gap: 4px; height: 34px; font-size: 0.82rem; font-weight: 600;" title="Reset all filters">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        Reset
                    </button>
                @endif
            </div>

            <div class="rms-actions" style="display: flex; align-items: center; gap: 12px;">
                <button type="button" wire:click="toggleLayout" class="rms-select" style="background: white; padding-right: 12px; display: inline-flex; align-items: center; gap: 6px; height: 34px;">
                    @if ($layoutMode === 'table')
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Grid
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Table
                    @endif
                </button>
                <div class="rms-search-wrapper">
                    <input type="text" class="rms-search-input" placeholder="Search control no., subject..." wire:model.live="searchQuery" style="width: 220px; height: 34px;">
                </div>
            </div>
        </div>
    </div>

    @if(!empty($dateFrom) || !empty($dateTo))
        <div style="margin-bottom: 16px; font-size: 0.82rem; color: #0369a1; background: #f0f9ff; border: 1px solid #bae6fd; padding: 6px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 8px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <span>
                Showing transactions between <strong>{{ !empty($dateFrom) ? \Carbon\Carbon::parse($dateFrom)->format('M d, Y') : 'Earliest' }}</strong> and <strong>{{ !empty($dateTo) ? \Carbon\Carbon::parse($dateTo)->format('M d, Y') : 'Latest' }}</strong>
            </span>
            <button type="button" wire:click="$set('dateFrom', ''); $set('dateTo', '');" style="background: none; border: none; color: #0369a1; cursor: pointer; font-weight: 700; font-size: 14px; padding: 0 4px; line-height: 1;" title="Clear date filter">×</button>
        </div>
    @endif

    @if ($layoutMode === 'table')
        <div class="rms-table-responsive">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th>CONTROL NO.</th>
                        <th>DOCUMENT TYPE</th>
                        <th>SUBJECT</th>
                        <th>ORIGINATED FROM</th>
                        <th>CURRENT LOCATION</th>
                        <th>DATE</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->transactions as $t)
                        <tr>
                            <td style="font-weight: 600; color: #1e40af;">{{ $t->control_number }}</td>
                            <td>{{ $t->doc_type_name ?: ucfirst($t->trans_type) }}</td>
                            <td style="max-width: 280px;">{{ Str::limit($t->subject, 50) }}</td>
                            <td>{{ $t->originated_office_name ?: $t->originated_from }}</td>
                            <td style="color: #0369a1; font-weight: 600;">🏢 {{ $t->current_office_name ?: $t->current_office }}</td>
                            <td style="white-space: nowrap;">
                                <div style="font-weight: 600; color: #1e293b;">
                                    {{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y') }}
                                </div>
                                @if(!empty($t->office_activity_date))
                                    <div style="font-size: 0.75rem; color: #0284c7; margin-top: 2px;" title="Date processed in your office">
                                        <span style="font-weight: 600;">Office:</span> {{ \Carbon\Carbon::parse($t->office_activity_date)->format('M d, Y') }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="rms-no-data" colspan="6">No external transaction history records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; margin-top: 16px;">
            @forelse ($this->transactions as $t)
                <div style="background: white; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.04);">
                    <div style="margin-bottom: 10px;">
                        <span style="font-weight: 700; color: #1e40af; font-size: 0.95rem;">{{ $t->control_number }}</span>
                    </div>
                    <h4 style="font-size: 0.9rem; font-weight: 600; color: #1e293b; margin: 0 0 8px 0;">{{ Str::limit($t->subject, 55) }}</h4>
                    <div style="font-size: 0.8rem; color: #64748b; line-height: 1.5;">
                        <div><strong>Originated:</strong> {{ $t->originated_office_name ?: $t->originated_from }}</div>
                        <div><strong>Current Location:</strong> {{ $t->current_office_name ?: $t->current_office }}</div>
                        <div><strong>Date Created:</strong> {{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y') }}</div>
                        @if(!empty($t->office_activity_date))
                            <div><strong>Office Activity:</strong> {{ \Carbon\Carbon::parse($t->office_activity_date)->format('M d, Y') }}</div>
                        @endif
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; background: white; padding: 32px; text-align: center; color: #64748b; border-radius: 12px; border: 1px solid #cbd5e1;">
                    No external transaction history records found.
                </div>
            @endforelse
        </div>
    @endif

    <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; background: white; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
        <span style="font-size: 13px; color: #64748b;">Total Records: <strong>{{ $this->transactions->total() }}</strong></span>
        <div style="display: flex; gap: 8px; align-items: center;">
            @if ($this->transactions->onFirstPage())
                <button type="button" style="padding: 6px 14px; font-size: 12px; border-radius: 6px; border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: not-allowed;" disabled>← Previous</button>
            @else
                <button type="button" wire:click="previousPage" style="padding: 6px 14px; font-size: 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: white; color: #1e40af; font-weight: 600; cursor: pointer;">← Previous</button>
            @endif
            <span style="font-size: 13px; color: #475569;">Page <strong>{{ $this->transactions->currentPage() }}</strong> of <strong>{{ $this->transactions->lastPage() }}</strong></span>
            @if ($this->transactions->hasMorePages())
                <button type="button" wire:click="nextPage" style="padding: 6px 14px; font-size: 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: white; color: #1e40af; font-weight: 600; cursor: pointer;">Next →</button>
            @else
                <button type="button" style="padding: 6px 14px; font-size: 12px; border-radius: 6px; border: 1px solid #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: not-allowed;" disabled>Next →</button>
            @endif
        </div>
    </div>
</div>

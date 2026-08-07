<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('DTS - Internal Transactions History')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    public int $perPage = 10;
    public string $searchQuery = '';
    public string $layoutMode = 'table'; // table or box

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_internal) {
            abort(403, 'Unauthorized access to Internal transactions history.');
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

    public function getTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.trans_type', 'internal')
            ->where('dtd.is_active', 1)
            ->where('dtd.originated_from', '!=', $userOfficeCode)
            ->whereExists(function($q) use ($userOfficeCode) {
                $q->select(DB::raw(1))
                  ->from('sub_document_tracking_system_logs as log')
                  ->whereColumn('log.transaction_id', 'dt.transaction_id')
                  ->where('log.office_code', $userOfficeCode)
                  ->where(function($sub) {
                      $sub->whereIn('log.type', ['received', 'forwarded'])
                          ->orWhereNotNull('log.date_in')
                          ->orWhereNotNull('log.date_out');
                  });
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

        return $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dt.trans_type',
            'dtd.control_number',
            'dtd.requestor_name',
            'dtd.requestor_label',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'dtd.originated_from',
            DB::raw("COALESCE(NULLIF(flow.referenced_flow, ''), flow.flow_name) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->paginate($this->perPage);
    }
};
?>

@push('styles')
    @vite(['resources/css/dts/list_transaction.css', 'resources/css/dts/receive.css'])
@endpush

<div class="rms-container">
    <div class="rms-header">
        <h2>Internal Transactions History</h2>
    </div>

    <div class="rms-toolbar" style="margin-bottom: 20px;">
        <div class="rms-toolbar-bottom">
            <div class="rms-entries">
                Show 
                <select class="rms-select" wire:model.live="perPage">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select> 
                Entries
            </div>
            <div class="rms-actions" style="display: flex; align-items: center; gap: 12px;">
                <button type="button" wire:click="toggleLayout" class="rms-select" style="background: white; padding-right: 12px; display: inline-flex; align-items: center; gap: 6px;">
                    @if ($layoutMode === 'table')
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Grid
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Table
                    @endif
                </button>
                <div class="rms-search-wrapper">
                    <input type="text" class="rms-search-input" placeholder="Search control no., subject..." wire:model.live="searchQuery" style="width: 240px;">
                </div>
            </div>
        </div>
    </div>

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
                        <th>DATE CREATED</th>
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
                            <td>{{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="rms-no-data" colspan="6">No internal transaction history records found.</td>
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
                        <div><strong>Date:</strong> {{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y') }}</div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; background: white; padding: 32px; text-align: center; color: #64748b; border-radius: 12px; border: 1px solid #cbd5e1;">
                    No internal transaction history records found.
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

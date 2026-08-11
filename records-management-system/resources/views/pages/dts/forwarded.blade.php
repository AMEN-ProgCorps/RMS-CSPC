<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Forwarded Transactions - Document Tracking System')] class extends Component {
    use WithPagination;

    #[On('dts-transaction-updated')]
    #[On('refresh-transactions')]
    public function refreshTransactionsList(): void
    {
        $this->resetPage();
    }

    public string $searchQuery = '';
    public int $perPage = 10;
    public string $layoutMode = 'table';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_access_dts) {
            abort(403, 'Unauthorized access to DTS.');
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function updatingSearchQuery(): void
    {
        $this->resetPage();
    }

    public function getForwardedTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        // Transactions where user office performed forward log and destination office hasn't received yet
        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->where('dtd.is_active', 1)
            ->where('dt.current_office', '!=', $userOfficeCode)
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $searchVal . '%')
                  ->orWhere('dt.qr_code', 'like', '%' . $searchVal . '%');
            });
        }

        $items = $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dt.trans_type',
            'dtd.control_number',
            'req.requestor_name',
            'dtd.subject',
            'dtd.date_created',
            'dtd.originated_from',
            DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->get();

        // Include only transactions where user office sent it and destination office hasn't received yet
        $forwardedPending = $items->filter(function($t) use ($userOfficeCode) {
            // Check if user's office logged a date_out / forwarded step for this transaction
            $userLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $userOfficeCode)
                ->whereNotNull('date_out')
                ->first();

            if (!$userLog) {
                return false;
            }

            // Check if destination office has received it
            $destLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $t->current_office)
                ->orderBy('id', 'desc')
                ->first();

            $destReceived = $destLog && ($destLog->type === 'received' || (!empty($destLog->date_in) && $destLog->type !== 'forwarded'));

            // Display in Forwarded Transactions ONLY until target destination receives it
            return !$destReceived;
        });

        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $sliced = $forwardedPending->slice(($page - 1) * $this->perPage, $this->perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $sliced,
            $forwardedPending->count(),
            $this->perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );
    }
};
?>

<div wire:poll.5s.keep-alive>
    <link rel="stylesheet" href="{{ asset('css/dts/internal.css') }}">
    <style>
        .dts-badge-forwarded {
            background-color: #e0e7ff;
            color: #4338ca;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>

    {{-- Header Banner --}}
    <div style="background: #ffffff; padding: 20px 24px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <h1 style="font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>📤 Step 3: Forwarded Transactions</span>
        </h1>
        <p style="color: #64748b; font-size: 0.875rem; margin: 4px 0 0 0;">
            Transactions forwarded by your office to another destination office, currently awaiting receipt at the destination.
        </p>
    </div>

    {{-- Search & Controls --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div style="position: relative; flex: 1; max-width: 400px;">
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="Search forwarded control no., subject..." style="width: 100%; padding: 10px 14px 10px 38px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </div>
        <button wire:click="toggleLayout" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; color: #475569; font-weight: 500;">
            {{ $layoutMode === 'table' ? '📦 Grid View' : '📋 Table View' }}
        </button>
    </div>

    @php $forwardedList = $this->forwardedTransactions; @endphp

    @if($forwardedList->count() === 0)
        <div style="background: #ffffff; border-radius: 12px; padding: 48px; text-align: center; color: #64748b; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <div style="font-size: 2.5rem; margin-bottom: 12px;">📤</div>
            <h3 style="font-size: 1.1rem; font-weight: 600; color: #334155; margin-bottom: 4px;">No Pending Destination Receipts</h3>
            <p style="font-size: 0.875rem; margin: 0;">All transactions forwarded by your office have been received by their target offices.</p>
        </div>
    @else
        @if($layoutMode === 'table')
            <div style="background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">
                            <th style="padding: 14px 16px;">Control No.</th>
                            <th style="padding: 14px 16px;">Document Type</th>
                            <th style="padding: 14px 16px;">Subject</th>
                            <th style="padding: 14px 16px;">Forwarded Destination</th>
                            <th style="padding: 14px 16px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($forwardedList as $t)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 14px 16px; font-weight: 700; color: #0f172a;">
                                    {{ $t->control_number }}
                                </td>
                                <td style="padding: 14px 16px; color: #334155;">
                                    <span style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                        {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}
                                    </span>
                                </td>
                                <td style="padding: 14px 16px; color: #1e293b; max-width: 280px;">
                                    <div style="font-weight: 600;">{{ Str::limit($t->subject ?: 'No subject', 45) }}</div>
                                </td>
                                <td style="padding: 14px 16px; font-weight: 600; color: #2563eb;">
                                    🏢 {{ $t->current_office_name ?: $t->current_office }}
                                </td>
                                <td style="padding: 14px 16px;">
                                    <span class="dts-badge-forwarded">
                                        ⏳ Awaiting Destination Receipt
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                @foreach($forwardedList as $t)
                    <div style="background: #ffffff; border-radius: 12px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <span style="font-weight: 700; font-size: 1rem; color: #0f172a;">{{ $t->control_number }}</span>
                            <span class="dts-badge-forwarded">⏳ Awaiting Receipt</span>
                        </div>
                        <h4 style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin: 0 0 6px 0;">
                            {{ Str::limit($t->subject ?: 'No subject', 50) }}
                        </h4>
                        <div style="font-size: 0.8rem; color: #64748b;">
                            <div><strong>Forwarded To:</strong> <span style="color: #2563eb; font-weight: 600;">{{ $t->current_office_name ?: $t->current_office }}</span></div>
                            <div><strong>Type:</strong> {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div style="margin-top: 16px;">
            {{ $forwardedList->links() }}
        </div>
    @endif
</div>

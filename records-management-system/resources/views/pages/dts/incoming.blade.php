<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Incoming Transactions - Document Tracking System')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    #[On('dts-transaction-updated')]
    #[On('refresh-transactions')]
    public function refreshTransactionsList(): void
    {
        $this->resetPage();
    }

    public string $activeTab = 'all';
    public string $searchQuery = '';
    public int $perPage = 10;
    public string $layoutMode = 'table';
    public string $successMessage = '';
    public string $errorMessage = '';

    // Modal state properties
    public string $selectedTransactionId = '';
    public $selectedTransaction = null;
    public bool $showViewFileModal = false;
    public string $attachedDocName = '';

    public function mount(): void
    {
        // Check user permission
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

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * One-Click Instant Receive action for Incoming Transactions
     */
    public function receiveIncoming(string $transactionId): void
    {
        $this->clearMessages();
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'User office code could not be resolved.';
            return;
        }

        $trans = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->where('dt.transaction_id', $transactionId)
            ->first();

        if (!$trans) {
            $this->errorMessage = 'Transaction not found.';
            return;
        }

        $isFreeFlow = ($trans->transaction_flow === 'FLOW-FREE-FLOW' || str_starts_with($trans->transaction_flow, 'FLOW-FREE-FLOW'));
        $hasOfficeLog = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
            ->where('transaction_id', $trans->transaction_id)
            ->where('office_code', $userOfficeCode)
            ->exists();

        if (!$isFreeFlow && !$hasOfficeLog && $trans->current_office !== $userOfficeCode) {
            $this->errorMessage = 'Unauthorized: Document is currently assigned to another office.';
            return;
        }

        try {
            DB::transaction(function () use ($trans, $userOfficeCode) {
                $currentLog = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                    ->where('transaction_id', $trans->transaction_id)
                    ->where('office_code', $userOfficeCode)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($currentLog) {
                    DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                        ->where('id', $currentLog->id)
                        ->update([
                            'type' => 'received',
                            'date_in' => now(),
                            'performed_by' => auth()->id(),
                        ]);
                } else {
                    DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $trans->transaction_id,
                        'office_code' => $userOfficeCode,
                        'type' => 'received',
                        'date_in' => now(),
                        'date_out' => null,
                        'notes' => 'Received via Incoming Transactions',
                        'performed_by' => auth()->id(),
                    ]);
                }

                $flow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $trans->transaction_flow)
                    ->first();

                if ($flow) {
                    DB::table('dts_sequence_list')
                        ->where('control_id', $flow->id)
                        ->where('sequence_ranking', $trans->sequence)
                        ->update([
                            'date_in' => now(),
                            'account_received' => auth()->id(),
                            'scanned_id' => true,
                        ]);
                }

                // Notify origin office that this recipient office received the transaction
                if (!empty($trans->originated_from) && $trans->originated_from !== $userOfficeCode) {
                    \App\Services\DtsNotificationService::notifyHubOfficeReceived(
                        $trans->originated_from,
                        $userOfficeCode,
                        $trans->control_number,
                        $trans->transaction_id
                    );
                }

                $this->successMessage = "Transaction '{$trans->control_number}' received successfully! Moved to Received Transactions.";
            });
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to receive transaction: ' . $e->getMessage();
        }
    }

    public function viewDetails(string $transactionId): void
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $t = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.transaction_id', $transactionId)
            ->select(
                'dt.transaction_id',
                'dt.status',
                'dt.sequence',
                'dt.qr_code',
                'dt.current_office',
                'dt.trans_type',
                'dt.doc_dir',
                'dtd.control_number',
                'req.requestor_name',
                'req.requestor_position as requestor_label',
                'dtd.subject',
                'dtd.classification',
                'dtd.action_needed',
                'dtd.date_created',
                'dtd.originated_from',
                'dtd.transaction_flow',
                DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
                'originated_office.office_name as originated_office_name',
                'current_office.office_name as current_office_name',
                'doc.document_name'
            )
            ->first();

        if ($t) {
            $this->selectedTransactionId = $t->transaction_id;
            $this->selectedTransaction = (array) $t;
        }
    }

    public function getIncomingTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dtd.is_active', 1)
            ->whereNotIn('dt.status', ['completed', 'cancelled'])
            ->where(function($q) use ($userOfficeCode) {
                $q->where('dt.current_office', $userOfficeCode)
                  ->orWhereExists(function($sub) use ($userOfficeCode) {
                      $sub->select(DB::raw(1))
                          ->from(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                          ->whereColumn('sub_document_tracking_system_logs.transaction_id', 'dt.transaction_id')
                          ->where('sub_document_tracking_system_logs.office_code', $userOfficeCode)
                          ->whereNull('sub_document_tracking_system_logs.date_in');
                  });
            });

        // Search Filter
        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $searchVal . '%')
                  ->orWhere('req.requestor_name', 'like', '%' . $searchVal . '%')
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
            'req.requestor_position as requestor_label',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'dtd.originated_from',
            DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->get();

        // Filter out transactions that have ALREADY been received at user office
        $incomingOnly = $items->filter(function($t) use ($userOfficeCode) {
            $currentLog = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $userOfficeCode)
                ->orderBy('id', 'desc')
                ->first();

            $isReceived = $currentLog && ($currentLog->type === 'received' || (!empty($currentLog->date_in) && $currentLog->type !== 'forwarded'));
            return !$isReceived; // Return TRUE only for transactions NOT received yet
        });

        // Paginate manually
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $sliced = $incomingOnly->slice(($page - 1) * $this->perPage, $this->perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $sliced,
            $incomingOnly->count(),
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
        .dts-badge-incoming {
            background-color: #fef3c7;
            color: #d97706;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-receive-action {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.825rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.25);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-receive-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.35);
        }
        .alert-success-banner {
            background-color: #ecfdf5;
            border-left: 4px solid #10b981;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .alert-error-banner {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>

    {{-- Header Banner --}}
    <div style="background: #ffffff; padding: 20px 24px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
                <span>📥 Step 1: Incoming Transactions</span>
            </h1>
            <p style="color: #64748b; font-size: 0.875rem; margin: 4px 0 0 0;">
                Transactions routed to your office pending formal receipt. Click <strong>"Receive Document"</strong> to confirm arrival and move to Received Transactions.
            </p>
        </div>
        <div>
            <a href="javascript:void(0)" onclick="if(window.openScannerModal) window.openScannerModal();" class="btn-receive-action" style="text-decoration: none; background: #3b82f6;">
                📷 Open Scanner
            </a>
        </div>
    </div>

    {{-- Alerts --}}
    @if(!empty($successMessage))
        <div class="alert-success-banner">
            <div>✓ {{ $successMessage }}</div>
            <button wire:click="clearMessages" style="background:none; border:none; color:#065f46; cursor:pointer; font-weight:bold;">✕</button>
        </div>
    @endif
    @if(!empty($errorMessage))
        <div class="alert-error-banner">
            <div>⚠ {{ $errorMessage }}</div>
            <button wire:click="clearMessages" style="background:none; border:none; color:#991b1b; cursor:pointer; font-weight:bold;">✕</button>
        </div>
    @endif

    {{-- Search & Controls --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px;">
        <div style="position: relative; flex: 1; max-width: 400px;">
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="Search incoming control no., subject, or QR code..." style="width: 100%; padding: 10px 14px 10px 38px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </div>
        <div style="display: flex; gap: 8px;">
            <button wire:click="toggleLayout" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; color: #475569; font-weight: 500;">
                {{ $layoutMode === 'table' ? '📦 Grid View' : '📋 Table View' }}
            </button>
        </div>
    </div>

    {{-- Transactions Display --}}
    @php $incomingList = $this->incomingTransactions; @endphp

    @if($incomingList->count() === 0)
        <div style="background: #ffffff; border-radius: 12px; padding: 48px; text-align: center; color: #64748b; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <div style="font-size: 2.5rem; margin-bottom: 12px;">📭</div>
            <h3 style="font-size: 1.1rem; font-weight: 600; color: #334155; margin-bottom: 4px;">No Pending Incoming Transactions</h3>
            <p style="font-size: 0.875rem; margin: 0;">All transactions routed to your office have been received.</p>
        </div>
    @else
        @if($layoutMode === 'table')
            <div style="background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">
                            <th style="padding: 14px 16px;">Control No.</th>
                            <th style="padding: 14px 16px;">Document Type</th>
                            <th style="padding: 14px 16px;">Subject / Particulars</th>
                            <th style="padding: 14px 16px;">Originated From</th>
                            <th style="padding: 14px 16px;">Status</th>
                            <th style="padding: 14px 16px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($incomingList as $t)
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease;">
                                <td style="padding: 14px 16px; font-weight: 700; color: #0f172a;">
                                    {{ $t->control_number }}
                                </td>
                                <td style="padding: 14px 16px; color: #334155;">
                                    <span style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                        {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}
                                    </span>
                                </td>
                                <td style="padding: 14px 16px; color: #1e293b; max-width: 280px;">
                                    <div style="font-weight: 600;">{{ Str::limit($t->subject ?: 'No subject', 40) }}</div>
                                    <div style="font-size: 0.75rem; color: #64748b;">Requestor: {{ $t->requestor_name ?: 'N/A' }}</div>
                                </td>
                                <td style="padding: 14px 16px; color: #475569;">
                                    {{ $t->originated_office_name ?: $t->originated_from }}
                                </td>
                                <td style="padding: 14px 16px;">
                                    <span class="dts-badge-incoming">
                                        ⏳ Pending Receipt
                                    </span>
                                </td>
                                <td style="padding: 14px 16px; text-align: right; white-space: nowrap;">
                                    <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="btn-receive-action">
                                        📷 Scan
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                @foreach($incomingList as $t)
                    <div style="background: #ffffff; border-radius: 12px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                <span style="font-weight: 700; font-size: 1rem; color: #0f172a;">{{ $t->control_number }}</span>
                                <span class="dts-badge-incoming">⏳ Pending Receipt</span>
                            </div>
                            <h4 style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin: 0 0 6px 0;">
                                {{ Str::limit($t->subject ?: 'No subject', 50) }}
                            </h4>
                            <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 12px;">
                                <div><strong>Originated:</strong> {{ $t->originated_office_name ?: $t->originated_from }}</div>
                                <div><strong>Type:</strong> {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}</div>
                            </div>
                        </div>
                        <div style="padding-top: 12px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
                            <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="btn-receive-action" style="width: 100%; justify-content: center;">
                                📷 Scan
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div style="margin-top: 16px;">
            {{ $incomingList->links() }}
        </div>
    @endif
</div>

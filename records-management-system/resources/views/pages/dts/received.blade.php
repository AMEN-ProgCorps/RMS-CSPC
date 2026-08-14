<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

new #[Layout('layouts.dts')] #[Title('Received Transactions - Document Tracking System')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    #[On('dts-transaction-updated')]
    #[On('refresh-transactions')]
    public function refreshTransactionsList(): void
    {
        $this->resetPage();
    }

    public string $searchQuery = '';
    public int $perPage = 10;
    public string $layoutMode = 'table';
    public string $successMessage = '';
    public string $errorMessage = '';

    // Forward Modal State
    public bool $showForwardModal = false;
    public string $forwardTransId = '';
    public array $forwardTransData = [];
    public string $actionNeeded = '';
    public string $notes = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_access_dts) {
            abort(403, 'Unauthorized access to DTS.');
        }

        $this->actionNeeded = DB::table('dts_action_options')->orderBy('option_name', 'asc')->value('option_name') ?: 'For Approval';
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

    public function openForwardModal(string $transactionId): void
    {
        $this->clearMessages();
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $t = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->where('dt.transaction_id', $transactionId)
            ->select(
                'dt.transaction_id',
                'dt.status',
                'dt.sequence',
                'dt.current_office',
                'dtd.control_number',
                'dtd.subject',
                'dtd.originated_from',
                'dtd.transaction_flow',
                'originated_office.office_name as originated_office_name'
            )
            ->first();

        $hasOfficeLog = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $t->transaction_id)
            ->where('office_code', $userOfficeCode)
            ->exists();

        if (!$t || ($t->current_office !== $userOfficeCode && $t->current_office !== '[HUB]' && !$hasOfficeLog)) {
            $this->errorMessage = 'Unauthorized: This transaction is not currently at your office.';
            return;
        }

        // Determine next office
        $nextOfficeName = 'N/A';
        $nextOfficeCode = null;
        $flow = DB::table('dts_transaction_flow')->where('flow_code', $t->transaction_flow)->first();
        $nextSequence = null;

        if ($flow) {
            $nextSequence = DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->where('sequence_ranking', $t->sequence + 1)
                ->first();

            if ($nextSequence) {
                $nextCode = $nextSequence->office_code;
                if ($nextCode === 'ORIGIN') {
                    $nextCode = $t->originated_from;
                } elseif ($nextCode === '[HUB]') {
                    $cfRecord = DB::table('dts_copy_filled_transaction')->where('control_num', $t->control_number)->first();
                    $hubOffices = $cfRecord ? DB::table('dts_copy_filled_to_office')->where('control_id', $cfRecord->assign_offices_id)->pluck('office_code')->toArray() : [];
                    $nextOfficeCode = '[HUB]';
                    $nextOfficeName = 'Office Hub [Multi-Receiving] (' . implode(', ', $hubOffices) . ')';
                }

                if ($nextCode !== '[HUB]') {
                    $nextOfficeCode = $nextCode;
                    $nextOfficeName = DB::table('office')->where('office_code', $nextCode)->value('office_name') ?: $nextCode;
                }
            } else {
                $nextOfficeName = 'End of Flow (Complete)';
            }
        }

        $tData = (array) $t;
        $tData['next_office_name'] = $nextOfficeName;
        $tData['next_office_code'] = $nextOfficeCode;
        $tData['is_last_step'] = empty($nextSequence);

        $this->forwardTransId = $transactionId;
        $this->forwardTransData = $tData;
        $this->showForwardModal = true;
    }

    public function closeForwardModal(): void
    {
        $this->showForwardModal = false;
        $this->forwardTransId = '';
        $this->forwardTransData = [];
    }

    public function confirmForward(): void
    {
        $this->clearMessages();
        if (empty($this->forwardTransId) || empty($this->forwardTransData)) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $transId = $this->forwardTransId;
                $tData = $this->forwardTransData;

                $flow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $tData['transaction_flow'])
                    ->first();

                if (!$flow) {
                    throw new \Exception('Transaction flow configuration missing.');
                }

                // Close current office log
                DB::table('sub_document_tracking_system_logs')
                    ->where('transaction_id', $transId)
                    ->where('office_code', $userOfficeCode)
                    ->orderBy('id', 'desc')
                    ->limit(1)
                    ->update([
                        'type' => 'forwarded',
                        'date_out' => now(),
                        'notes' => $this->notes ?: 'Forwarded to next office',
                        'performed_by' => auth()->id(),
                    ]);

                // Update dts_sequence_list
                if ($flow) {
                    DB::table('dts_sequence_list')
                        ->where('control_id', $flow->id)
                        ->where('sequence_ranking', $tData['sequence'])
                        ->update([
                            'date_out' => now(),
                            'account_forwarded' => auth()->id(),
                            'action_needed' => $this->actionNeeded ?: 'Forwarded',
                            'note' => $this->notes,
                        ]);
                }

                if ($tData['is_last_step']) {
                    // Mark Completed
                    DB::table('dts_transactions')
                        ->where('transaction_id', $transId)
                        ->update(['status' => 'completed']);

                    DB::table('dts_transaction_details')
                        ->where('id', $transId)
                        ->update(['status' => 'completed']);

                    $this->successMessage = "Transaction '{$tData['control_number']}' has reached final step and is COMPLETED!";
                } else {
                    $nextCode = $tData['next_office_code'];
                    $nextSeq = $tData['sequence'] + 1;

                    if ($nextCode === '[HUB]') {
                        $cfRecord = DB::table('dts_copy_filled_transaction')->where('control_num', $tData['control_number'])->first();
                        $hubOffices = $cfRecord ? DB::table('dts_copy_filled_to_office')->where('control_id', $cfRecord->assign_offices_id)->pluck('office_code')->toArray() : [];

                        if (count($hubOffices) > 0) {
                            // Office 1 uses the original transaction
                            $primaryHubOffice = $hubOffices[0];
                            DB::table('dts_transactions')
                                ->where('transaction_id', $transId)
                                ->update([
                                    'current_office' => $primaryHubOffice,
                                    'sequence' => $nextSeq,
                                ]);

                            DB::table('dts_transaction_details')
                                ->where('id', $transId)
                                ->update([
                                    'current_office_hold' => $primaryHubOffice,
                                    'action_needed' => $this->actionNeeded,
                                ]);

                            DB::table('sub_document_tracking_system_logs')->insert([
                                'transaction_id' => $transId,
                                'office_code' => $primaryHubOffice,
                                'type' => 'forwarded',
                                'date_in' => null,
                                'date_out' => null,
                                'notes' => 'Dispatched via Office Hub [HUB] (Primary)',
                                'performed_by' => auth()->id(),
                            ]);
                            \App\Services\DtsNotificationService::notifyWaitingToBeReceived($primaryHubOffice, $tData['control_number'], $transId);

                            // Parse control number parts for child suffixes
                            $cParts = explode('-', $tData['control_number']);
                            $typePrefix = $cParts[0] ?? 'DOC';
                            $seqBase = end($cParts);

                            // Copy prior logs to replicate complete routing history for children
                            $priorLogs = DB::table('sub_document_tracking_system_logs')
                                ->where('transaction_id', $transId)
                                ->whereNotNull('date_in')
                                ->orderBy('id', 'asc')
                                ->get();

                            // Offices 2..N spawn child transactions with hyphenated suffixes (-1, -2, ...)
                            for ($i = 1; $i < count($hubOffices); $i++) {
                                $childOffice = $hubOffices[$i];
                                $childSeq = $seqBase . '-' . $i;
                                $childControlNumber = $tData['control_number'] . '-' . $i;
                                $childQrCode = \App\Services\DtsQrCodeService::generate($typePrefix, $childSeq);
                                $childTransId = 'TRANS-' . strtoupper(Str::random(10));

                                // Insert child into dts_transactions
                                DB::table('dts_transactions')->insert([
                                    'transaction_id' => $childTransId,
                                    'enable_notif' => 1,
                                    'trans_type' => $tData['trans_type'] ?? 'memorandom',
                                    'doc_dir' => $tData['doc_dir'] ?? null,
                                    'qr_code' => $childQrCode,
                                    'current_office' => $childOffice,
                                    'status' => 'ongoing',
                                    'sequence' => $nextSeq,
                                ]);

                                // Insert child into dts_transaction_details
                                DB::table('dts_transaction_details')->insert([
                                    'id' => $childTransId,
                                    'type' => $tData['type'] ?? 'memorandom',
                                    'created_by' => $tData['created_by'] ?? auth()->id(),
                                    'originated_from' => $tData['originated_from'] ?? $userOfficeCode,
                                    'requestor_id' => $tData['requestor_id'] ?? null,
                                    'source_office' => $tData['source_office'] ?? null,
                                    'subject' => $tData['subject'] ?? '',
                                    'classification' => $tData['classification'] ?? null,
                                    'action_needed' => $this->actionNeeded,
                                    'current_office_hold' => $childOffice,
                                    'status' => 'ongoing',
                                    'document_password' => $tData['document_password'] ?? null,
                                    'email_access' => $tData['email_access'] ?? null,
                                    'transaction_flow' => $tData['transaction_flow'],
                                    'is_active' => 1,
                                    'date_created' => $tData['date_created'] ?? now(),
                                    'control_number' => $childControlNumber,
                                    'copy_filled_id' => $tData['copy_filled_id'] ?? null,
                                ]);

                                // Replicate prior logs for this child transaction
                                foreach ($priorLogs as $pLog) {
                                    DB::table('sub_document_tracking_system_logs')->insert([
                                        'transaction_id' => $childTransId,
                                        'office_code' => $pLog->office_code,
                                        'type' => $pLog->type,
                                        'date_in' => $pLog->date_in,
                                        'date_out' => $pLog->date_out,
                                        'notes' => $pLog->notes,
                                        'performed_by' => $pLog->performed_by,
                                    ]);
                                }

                                // Pending log for child recipient office
                                DB::table('sub_document_tracking_system_logs')->insert([
                                    'transaction_id' => $childTransId,
                                    'office_code' => $childOffice,
                                    'type' => 'forwarded',
                                    'date_in' => null,
                                    'date_out' => null,
                                    'notes' => 'Dispatched via Office Hub [HUB] (Child Branch #' . $i . ')',
                                    'performed_by' => auth()->id(),
                                ]);

                                \App\Services\DtsNotificationService::notifyWaitingToBeReceived($childOffice, $childControlNumber, $childTransId);
                            }
                        }

                        $this->successMessage = "Transaction '{$tData['control_number']}' broadcast to Office Hub recipients successfully!";
                    } else {
                        // Advance transaction to next single office
                        DB::table('dts_transactions')
                            ->where('transaction_id', $transId)
                            ->update([
                                'current_office' => $nextCode,
                                'sequence' => $nextSeq,
                            ]);

                        DB::table('dts_transaction_details')
                            ->where('id', $transId)
                            ->update([
                                'current_office_hold' => $nextCode,
                                'action_needed' => $this->actionNeeded,
                            ]);

                        // Insert log for next office
                        $fwdNote = ($tData['current_office'] === '[HUB]') 
                            ? 'Returned from Office Hub (' . $userOfficeCode . ')'
                            : 'Forwarded from ' . $userOfficeCode;

                        DB::table('sub_document_tracking_system_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $nextCode,
                            'type' => 'forwarded',
                            'date_in' => null,
                            'date_out' => null,
                            'notes' => $fwdNote,
                            'performed_by' => auth()->id(),
                        ]);

                        \App\Services\DtsNotificationService::notifyWaitingToBeReceived($nextCode, $tData['control_number'], $transId);

                        $userFirstName = auth()->user()?->details?->first_name ?: (auth()->user()?->username ?: 'User');
                        if (!empty($tData['originated_from']) && $tData['originated_from'] !== $userOfficeCode) {
                            \App\Services\DtsNotificationService::notifyHubOfficeForwarded(
                                $tData['originated_from'],
                                $userOfficeCode,
                                $tData['control_number'],
                                $transId,
                                $userFirstName
                            );
                        }

                        $this->successMessage = "Transaction '{$tData['control_number']}' successfully forwarded to {$tData['next_office_name']}! Moved to Forwarded Transactions.";
                    }
                }
            });

            $this->closeForwardModal();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to forward transaction: ' . $e->getMessage();
        }
    }

    public function getReceivedTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->where('dtd.is_active', 1)
            ->whereNotIn('dt.status', ['cancelled'])
            ->where(function($q) use ($userOfficeCode) {
                $q->where('dt.current_office', $userOfficeCode)
                  ->orWhereExists(function($sub) use ($userOfficeCode) {
                      $sub->select(DB::raw(1))
                          ->from('sub_document_tracking_system_logs')
                          ->whereColumn('sub_document_tracking_system_logs.transaction_id', 'dt.transaction_id')
                          ->where('sub_document_tracking_system_logs.office_code', $userOfficeCode)
                          ->whereNotNull('sub_document_tracking_system_logs.date_in')
                          ->where('sub_document_tracking_system_logs.type', 'received');
                  });
            });

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
            'dtd.action_needed',
            'dtd.date_created',
            'dtd.originated_from',
            DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
            'originated_office.office_name as originated_office_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->get();

        // Include ONLY transactions that HAVE been received by user office
        $receivedOnly = $items->filter(function($t) use ($userOfficeCode) {
            $currentLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $userOfficeCode)
                ->orderBy('id', 'desc')
                ->first();

            return $currentLog && ($currentLog->type === 'received' || (!empty($currentLog->date_in) && $currentLog->type !== 'forwarded'));
        });

        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $sliced = $receivedOnly->slice(($page - 1) * $this->perPage, $this->perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $sliced,
            $receivedOnly->count(),
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
        .dts-badge-received {
            background-color: #dcfce7;
            color: #15803d;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-forward-action {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.825rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.25);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-forward-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.35);
        }
    </style>

    {{-- Header Banner --}}
    <div style="background: #ffffff; padding: 20px 24px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <h1 style="font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>📥 Step 2: Received Transactions</span>
        </h1>
        <p style="color: #64748b; font-size: 0.875rem; margin: 4px 0 0 0;">
            Transactions received and currently held at your office. Click <strong>"Forward Transaction"</strong> when ready to route to the next office.
        </p>
    </div>

    {{-- Alerts --}}
    @if(!empty($successMessage))
        <div style="background:#ecfdf5; border-left:4px solid #10b981; color:#065f46; padding:12px 16px; border-radius:6px; margin-bottom:16px; display:flex; justify-between; align-items:center;">
            <div>✓ {{ $successMessage }}</div>
            <button wire:click="clearMessages" style="background:none; border:none; color:#065f46; cursor:pointer; font-weight:bold;">✕</button>
        </div>
    @endif
    @if(!empty($errorMessage))
        <div style="background:#fef2f2; border-left:4px solid #ef4444; color:#991b1b; padding:12px 16px; border-radius:6px; margin-bottom:16px; display:flex; justify-between; align-items:center;">
            <div>⚠ {{ $errorMessage }}</div>
            <button wire:click="clearMessages" style="background:none; border:none; color:#991b1b; cursor:pointer; font-weight:bold;">✕</button>
        </div>
    @endif

    {{-- Search & Controls --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div style="position: relative; flex: 1; max-width: 400px;">
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="Search received control no., subject..." style="width: 100%; padding: 10px 14px 10px 38px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </div>
        <button wire:click="toggleLayout" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; color: #475569; font-weight: 500;">
            {{ $layoutMode === 'table' ? '📦 Grid View' : '📋 Table View' }}
        </button>
    </div>

    @php $receivedList = $this->receivedTransactions; @endphp

    @if($receivedList->count() === 0)
        <div style="background: #ffffff; border-radius: 12px; padding: 48px; text-align: center; color: #64748b; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <div style="font-size: 2.5rem; margin-bottom: 12px;">✅</div>
            <h3 style="font-size: 1.1rem; font-weight: 600; color: #334155; margin-bottom: 4px;">No Transactions Held in Office</h3>
            <p style="font-size: 0.875rem; margin: 0;">There are no received transactions waiting for forwarding at your office.</p>
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
                            <th style="padding: 14px 16px;">Originated From</th>
                            <th style="padding: 14px 16px;">Status</th>
                            <th style="padding: 14px 16px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($receivedList as $t)
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
                                    <div style="font-weight: 600;">{{ Str::limit($t->subject ?: 'No subject', 40) }}</div>
                                </td>
                                <td style="padding: 14px 16px; color: #475569;">
                                    {{ $t->originated_office_name ?: $t->originated_from }}
                                </td>
                                <td style="padding: 14px 16px;">
                                    <span class="dts-badge-received">
                                        ✓ Held in Office
                                    </span>
                                </td>
                                <td style="padding: 14px 16px; text-align: right; white-space: nowrap;">
                                    <div style="display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
                                        <button wire:click="openForwardModal('{{ $t->transaction_id }}')" class="btn-forward-action">
                                            👁 View
                                        </button>
                                        <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="btn-forward-action" style="background: #0284c7;">
                                            📷 Scan
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                @foreach($receivedList as $t)
                    <div style="background: #ffffff; border-radius: 12px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                <span style="font-weight: 700; font-size: 1rem; color: #0f172a;">{{ $t->control_number }}</span>
                                <span class="dts-badge-received">✓ Held in Office</span>
                            </div>
                            <h4 style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin: 0 0 6px 0;">
                                {{ Str::limit($t->subject ?: 'No subject', 50) }}
                            </h4>
                            <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 12px;">
                                <div><strong>Originated:</strong> {{ $t->originated_office_name ?: $t->originated_from }}</div>
                                <div><strong>Type:</strong> {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}</div>
                            </div>
                        </div>
                        <div style="padding-top: 12px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 8px;">
                            <button wire:click="openForwardModal('{{ $t->transaction_id }}')" class="btn-forward-action" style="flex: 1; justify-content: center;">
                                👁 View
                            </button>
                            <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="btn-forward-action" style="flex: 1; justify-content: center; background: #0284c7;">
                                📷 Scan
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div style="margin-top: 16px;">
            {{ $receivedList->links() }}
        </div>
    @endif

    {{-- Forward Modal --}}
    @if($showForwardModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; z-index: 9999;">
            <div style="background: #ffffff; border-radius: 12px; width: 100%; max-width: 500px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
                <h3 style="margin: 0 0 16px 0; font-size: 1.2rem; font-weight: 700; color: #1e293b;">
                    📤 Forward Transaction {{ $forwardTransData['control_number'] ?? '' }}
                </h3>

                <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.875rem;">
                    <div><strong>Subject:</strong> {{ $forwardTransData['subject'] ?? 'N/A' }}</div>
                    <div style="margin-top: 4px;"><strong>Next Destination:</strong> <span style="color: #2563eb; font-weight: 700;">{{ $forwardTransData['next_office_name'] ?? 'N/A' }}</span></div>
                </div>

                @if(!($forwardTransData['is_last_step'] ?? false))
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 4px;">Action Needed:</label>
                        <select wire:model="actionNeeded" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.875rem;">
                            @foreach(DB::table('dts_action_options')->orderBy('option_name', 'asc')->pluck('option_name') as $opt)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 4px;">Notes / Remarks (Optional):</label>
                    <textarea wire:model="notes" rows="3" placeholder="Add any special instructions or remarks..." style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 0.875rem; font-family: inherit;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button wire:click="closeForwardModal" style="background: #f1f5f9; color: #475569; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button wire:click="confirmForward" class="btn-forward-action">
                        {{ ($forwardTransData['is_last_step'] ?? false) ? '✓ Complete Transaction' : '📤 Confirm Forward' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

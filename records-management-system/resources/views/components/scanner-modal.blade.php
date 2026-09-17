<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

new class extends Component {
    public bool $isOpen = false;
    public string $scannedCode = '';
    public ?array $activeTransaction = null;
    public string $actionNeeded = '';
    public string $notes = '';
    public string $successMessage = '';
    public string $errorMessage = '';
    public array $recentScans = [];
    public array $actionOptions = [];
    public string $resubmitTarget = 'start';

    public function mount(): void
    {
        $this->actionOptions = DB::table('dts_action_options')
            ->orderBy('option_name', 'asc')
            ->pluck('option_name')
            ->toArray();

        if (empty($this->actionOptions)) {
            $this->actionOptions = ['For Approval', 'For Review', 'For Signature', 'For Release', 'For Filing', 'For Action'];
        }

        $this->actionNeeded = $this->actionOptions[0] ?? 'For Approval';
        $this->recentScans = session()->get('dts_recent_scans', []);
    }

    #[On('open-scanner-modal')]
    public function openModal(string $code = ''): void
    {
        $this->isOpen = true;
        $this->clearMessages();
        $this->activeTransaction = null;
        $this->scannedCode = '';

        $this->dispatch('init-camera-scanner');
    }

    #[On('close-scanner-modal')]
    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->clearMessages();
        $this->activeTransaction = null;
        $this->scannedCode = '';
        $this->dispatch('stop-camera-scanner');
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function loadTransaction(): void
    {
        $this->clearMessages();
        $this->activeTransaction = null;

        $rawCode = trim($this->scannedCode);
        if (empty($rawCode)) {
            return;
        }

        // Decode base64 if valid base64
        $code = $rawCode;
        $decoded = base64_decode($rawCode, true);
        if ($decoded !== false && ctype_print($decoded)) {
            $code = trim($decoded);
        }

        // Log scan
        try {
            $logPath = storage_path('logs/dts_scans.log');
            $scanId = 'SCAN-' . strtoupper(Str::random(12));
            $username = auth()->user()?->username ?: 'Unknown';
            $officeName = auth()->user()?->details?->office?->office_name ?: 'Unknown Office';

            $logLine = json_encode([
                'scan_id' => $scanId,
                'scanned_data' => $rawCode,
                'decoded_data' => $code,
                'user' => $username,
                'office' => $officeName,
                'timestamp' => now()->toIso8601String(),
            ]) . PHP_EOL;

            File::append($logPath, $logLine);
        } catch (\Exception $e) {
            // Silently ignore log write failures
        }

        $qrExists = DB::table('dts_qr_code')->where('code_id', $code)->exists();
        if (!$qrExists) {
            $this->errorMessage = 'Invalid QR Code: Only valid, registered QR codes can be processed by the scanner.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        $transaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office_tb', 'current_office_tb.office_code', '=', 'dt.current_office')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.qr_code', $code)
            ->select(
                'dt.transaction_id',
                'dt.trans_type as type',
                'dt.status',
                'dt.sequence',
                'dt.qr_code',
                'dt.current_office',
                'dt.revision_requested_by_office',
                'dt.revision_requested_by_sequence',
                'current_office_tb.office_name as current_office_name',
                'dtd.transaction_flow',
                'dtd.control_number',
                'req.requestor_name',
                'req.requestor_position as requestor_label',
                'dtd.subject',
                'dtd.classification',
                'dtd.originated_from',
                'originated_office.office_name as originated_office_name',
                'doc.document_name'
            )
            ->first();

        if (!$transaction) {
            $this->errorMessage = 'Inactive QR Code: Code registered in system but not yet linked to any transaction.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        $rawStatus = strtolower($transaction->status);
        if ($rawStatus === 'completed') {
            $this->errorMessage = 'That QR code is already finished its transaction.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        if ($rawStatus === 'cancelled') {
            $this->errorMessage = 'That QR code transaction has been cancelled.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'User office code could not be resolved.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        if ($transaction->current_office !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        $lastLog = DB::table('dts_transaction_logs')
            ->where('transaction_id', $transaction->transaction_id)
            ->where('office_code', $userOfficeCode)
            ->orderBy('id', 'desc')
            ->first();
        $isReceivedAtUserOffice = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));

        // Get next office in sequence if flow exists
        $nextOfficeName = 'End of Flow';
        $nextOfficeCode = null;
        if (!empty($transaction->transaction_flow)) {
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $transaction->transaction_flow)->first();
            if ($flow) {
                $nextSeq = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $transaction->sequence + 1)
                    ->first();
                if ($nextSeq) {
                    $nextOfficeCode = $nextSeq->office_code;
                    $nextOfficeName = DB::table('sys_office')->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                }
            }
        }

        $this->activeTransaction = [
            'id' => $transaction->transaction_id,
            'control_number' => $transaction->control_number,
            'qr_code' => $transaction->qr_code,
            'type' => ucfirst($transaction->type),
            'subject' => $transaction->subject ?: 'No subject specified',
            'requestor_name' => $transaction->requestor_name ?: 'N/A',
            'originated_office' => $transaction->originated_office_name ?: $transaction->originated_from,
            'originated_office_code' => $transaction->originated_from,
            'current_office' => $transaction->current_office_name ?: $transaction->current_office,
            'current_office_code' => $transaction->current_office,
            'next_office' => $nextOfficeName,
            'next_office_code' => $nextOfficeCode,
            'status' => ucfirst($transaction->status),
            'is_completed' => false,
            'sequence' => $transaction->sequence,
            'document_name' => $transaction->document_name,
            'is_received_here' => $isReceivedAtUserOffice,
            'revision_requested_by_office' => $transaction->revision_requested_by_office ?? null,
            'revision_requested_by_sequence' => $transaction->revision_requested_by_sequence ?? null,
            'transaction_flow' => $transaction->transaction_flow,
        ];

        // Push scan to session recent history
        array_unshift($this->recentScans, [
            'control_number' => $transaction->control_number,
            'subject' => Str::limit($transaction->subject ?: 'No subject', 35),
            'scanned_at' => now()->format('h:i:s A'),
            'status' => $transaction->status,
        ]);
        $this->recentScans = array_slice($this->recentScans, 0, 5);
        session()->put('dts_recent_scans', $this->recentScans);

        $this->dispatch('scanner-code-success');
    }

    public function processScanAction(): void
    {
        $this->clearMessages();

        if (!$this->activeTransaction) {
            $this->errorMessage = 'No transaction selected.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'User office code could not be resolved.';
            return;
        }

        if ($this->activeTransaction['current_office_code'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $transId = $this->activeTransaction['id'];
                $isReceived = $this->activeTransaction['is_received_here'];

                // Case 1: Receive incoming transaction
                if (!$isReceived) {
                    $currentLog = DB::table('dts_transaction_logs')
                        ->where('transaction_id', $transId)
                        ->where('office_code', $userOfficeCode)
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($currentLog) {
                        DB::table('dts_transaction_logs')
                            ->where('id', $currentLog->id)
                            ->update([
                                'type' => 'received',
                                'date_in' => now(),
                                'performed_by' => auth()->id(),
                            ]);
                    } else {
                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $userOfficeCode,
                            'type' => 'received',
                            'date_in' => now(),
                            'notes' => 'Received via Global Scanner',
                            'performed_by' => auth()->id(),
                        ]);
                    }

                    if (!empty($this->activeTransaction['transaction_flow'])) {
                        $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                        if ($flow) {
                            DB::table('dts_sequence_list')
                                ->where('control_id', $flow->id)
                                ->where('sequence_ranking', $this->activeTransaction['sequence'])
                                ->update([
                                    'date_in' => now(),
                                    'account_received' => auth()->id(),
                                    'scanned_id' => true,
                                ]);
                        }
                    }

                    $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
                    $userFirstName = auth()->user()?->details?->first_name 
                        ?: DB::table($accDetailsTbl)->where('account_id', auth()->id())->value('first_name')
                        ?: auth()->user()?->username 
                        ?: 'User';

                    $controlNumber = $this->activeTransaction['control_number'] ?? '';

                    if ($userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyReceived($userOfficeCode, $userFirstName, $controlNumber, $transId);
                    }

                    $originatedFrom = $this->activeTransaction['originated_office_code'] ?? null;
                    if ($originatedFrom && $originatedFrom !== $userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyHubOfficeReceived($originatedFrom, $userOfficeCode, $controlNumber, $transId);
                    }

                    $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' received successfully at {$this->activeTransaction['current_office']}!";
                } 
                // Case 2: Forward received transaction
                else {
                    $isRevisionAction = in_array($this->actionNeeded, ['For Revision', 'Returned for Revision']);

                    if ($isRevisionAction) {
                        $transDb = DB::table('dts_transaction_details')->where('id', $transId)->first();
                        $originatedFrom = $transDb?->originated_from ?? 'ORIGIN';

                        // Update date_out for user office
                        DB::table('dts_transaction_logs')
                            ->where('transaction_id', $transId)
                            ->where('office_code', $userOfficeCode)
                            ->whereNull('date_out')
                            ->update([
                                'date_out' => now(),
                                'notes' => $this->notes ?: 'Returned for Revision via Scanner',
                                'type' => 'returned',
                                'performed_by' => auth()->id(),
                            ]);

                        $updateTransData = [
                            'current_office' => $originatedFrom,
                            'sequence' => 1,
                            'status' => 'revision',
                        ];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_requested_by_sequence')) {
                            $updateTransData['revision_requested_by_sequence'] = $this->activeTransaction['sequence'];
                            $updateTransData['revision_requested_by_office'] = $userOfficeCode;
                        }
                        if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_count')) {
                            $updateTransData['revision_count'] = DB::raw('COALESCE(revision_count, 0) + 1');
                        }

                        DB::table('dts_transactions')
                            ->where('transaction_id', $transId)
                            ->update($updateTransData);

                        DB::table('dts_transaction_details')
                            ->where('id', $transId)
                            ->update([
                                'action_needed' => 'For Revision',
                            ]);

                        if (!empty($transDb->transaction_flow)) {
                            $flow = DB::table('dts_transaction_flow')->where('flow_code', $transDb->transaction_flow)->first();
                            if ($flow) {
                                // Close out the forwarding office's step
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $flow->id)
                                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                                    ->update([
                                        'date_out' => now(),
                                        'account_forwarded' => auth()->id(),
                                        'action_needed' => 'For Revision',
                                        'note' => $this->notes ?: 'Returned for Revision',
                                    ]);

                                // Update ORIGIN step to receive the revision
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $flow->id)
                                    ->where('sequence_ranking', 1)
                                    ->update([
                                        'date_in' => now(),
                                        'date_out' => null,
                                        'account_received' => auth()->id(),
                                        'account_forwarded' => null,
                                        'action_needed' => 'Returned for Revision',
                                        'note' => $this->notes,
                                        'total_time_completed' => null,
                                    ]);
                            }
                        }

                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $originatedFrom,
                            'type' => 'returned',
                            'date_in' => now(),
                            'date_out' => null,
                            'notes' => 'Returned for revision from ' . $userOfficeCode . ': ' . ($this->notes ?: 'Please revise document'),
                            'performed_by' => auth()->id(),
                        ]);

                        $controlNumber = $this->activeTransaction['control_number'] ?? '';
                        \App\Services\DtsNotificationService::createNotification(
                            $originatedFrom,
                            "Transaction {$controlNumber} has been returned for revision by {$userOfficeCode}.",
                            '/dts?open=' . urlencode($transId)
                        );

                        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                        $originatedOfficeName = DB::table($officeTbl)->where('office_code', $originatedFrom)->value('office_name') ?: $originatedFrom;
                        $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' returned for revision to {$originatedOfficeName}!";
                    } else {
                        // Check if transaction is in revision status (fresh from revision at Originator)
                        $isRevisionResubmit = strtolower($this->activeTransaction['status']) === 'revision';
                        $targetSeqNum = $this->activeTransaction['sequence'] + 1;
                        $nextOfficeCode = $this->activeTransaction['next_office_code'];

                        if ($isRevisionResubmit) {
                            $reqSeq = $this->activeTransaction['revision_requested_by_sequence'] ?? null;
                            if ($this->resubmitTarget === 'requestor' && $reqSeq && $reqSeq > 1) {
                                $targetSeqNum = (int)$reqSeq;
                            } else {
                                $targetSeqNum = 2; // Option A: Restart at sequence 2 (e.g. VP)
                            }

                            if (!empty($this->activeTransaction['transaction_flow'])) {
                                $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                                if ($flow) {
                                    $nextSeq = DB::table('dts_sequence_list')
                                        ->where('control_id', $flow->id)
                                        ->where('sequence_ranking', $targetSeqNum)
                                        ->first();
                                    if ($nextSeq) {
                                        $nextOfficeCode = $nextSeq->office_code;
                                        if ($nextOfficeCode === 'ORIGIN') {
                                            $nextOfficeCode = $this->activeTransaction['originated_office_code'];
                                        }
                                    }
                                }
                            }
                            if (!$nextOfficeCode) {
                                $nextOfficeCode = $this->activeTransaction['revision_requested_by_office'] ?? $this->activeTransaction['next_office_code'];
                            }
                        }

                        // Conditional sequence list wipe at resubmit time
                        if ($isRevisionResubmit && !empty($this->activeTransaction['transaction_flow'])) {
                            $revFlow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                            if ($revFlow) {
                                $reqSeq = $this->activeTransaction['revision_requested_by_sequence'] ?? null;
                                if ($reqSeq) {
                                    if ($this->resubmitTarget === 'start') {
                                        // Option A: Wipe steps from 2 up to requestor sequence
                                        DB::table('dts_sequence_list')
                                            ->where('control_id', $revFlow->id)
                                            ->where('sequence_ranking', '>', 1)
                                            ->where('sequence_ranking', '<=', $reqSeq)
                                            ->update([
                                                'date_in' => null, 'date_out' => null,
                                                'action_needed' => null, 'note' => null,
                                                'total_time_completed' => null,
                                            ]);
                                    } else {
                                        // Option B: Only reset the requestor's step
                                        DB::table('dts_sequence_list')
                                            ->where('control_id', $revFlow->id)
                                            ->where('sequence_ranking', $reqSeq)
                                            ->update([
                                                'date_in' => null, 'date_out' => null,
                                                'action_needed' => null, 'note' => null,
                                                'total_time_completed' => null,
                                            ]);
                                    }
                                }
                                // Close ORIGIN's date_out on resubmit
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $revFlow->id)
                                    ->where('sequence_ranking', 1)
                                    ->update([
                                        'date_out' => now(),
                                        'action_needed' => 'Resubmitted (' . ($this->resubmitTarget === 'start' ? 'Restart' : 'Fast-Track') . ')',
                                    ]);
                            }
                        }

                        // Update date_out for user office
                        $logNote = $isRevisionResubmit 
                            ? ($this->resubmitTarget === 'requestor' ? 'Resubmitted directly to ' : 'Resubmitted to ') . $nextOfficeCode
                            : ($this->notes ?: 'Forwarded via Global Scanner');

                        DB::table('dts_transaction_logs')
                            ->where('transaction_id', $transId)
                            ->where('office_code', $userOfficeCode)
                            ->whereNull('date_out')
                            ->update([
                                'date_out' => now(),
                                'notes' => $logNote,
                                'type' => 'forwarded',
                                'performed_by' => auth()->id(),
                            ]);

                        if (!empty($this->activeTransaction['transaction_flow'])) {
                            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                            if ($flow) {
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $flow->id)
                                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                                    ->update([
                                        'date_out' => now(),
                                        'account_forwarded' => auth()->id(),
                                        'action_needed' => $this->actionNeeded,
                                        'note' => $this->notes ?: null,
                                    ]);
                            }
                        }

                        // If there is a next office in flow
                        if ($nextOfficeCode) {
                            $updateTransData = [
                                'current_office' => $nextOfficeCode,
                                'sequence' => $targetSeqNum,
                                'status' => 'ongoing',
                            ];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_resubmit_type')) {
                                $updateTransData['revision_resubmit_type'] = $this->resubmitTarget;
                            }
                            // KEEP revision_requested_by_* — needed for timeline coloring

                            DB::table('dts_transactions')
                                ->where('transaction_id', $transId)
                                ->update($updateTransData);

                            DB::table('dts_transaction_details')
                                ->where('id', $transId)
                                ->update([
                                    'action_needed' => $this->actionNeeded,
                                ]);

                            DB::table('dts_transaction_logs')->insert([
                                'transaction_id' => $transId,
                                'office_code' => $nextOfficeCode,
                                'type' => 'forwarded',
                                'date_in' => null,
                                'notes' => $logNote,
                                'performed_by' => auth()->id(),
                            ]);

                            $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                            $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

                            $destOfficeName = DB::table($officeTbl)->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                            $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' forwarded successfully to {$destOfficeName}!";

                            $userFirstName = auth()->user()?->details?->first_name 
                                ?: DB::table($accDetailsTbl)->where('account_id', auth()->id())->value('first_name')
                                ?: auth()->user()?->username 
                                ?: 'User';

                            $controlNumber = $this->activeTransaction['control_number'] ?? '';

                            if ($userOfficeCode) {
                                \App\Services\DtsNotificationService::notifyForwarded($userOfficeCode, $userFirstName, $controlNumber, $transId);
                            }

                            if (!empty($nextOfficeCode)) {
                                \App\Services\DtsNotificationService::notifyWaitingToBeReceived($nextOfficeCode, $controlNumber, $transId);
                            }

                            $originatedFrom = $this->activeTransaction['originated_office_code'] ?? null;
                            if ($originatedFrom && $originatedFrom !== $userOfficeCode) {
                                \App\Services\DtsNotificationService::notifyHubOfficeForwarded($originatedFrom, $userOfficeCode, $controlNumber, $transId, $userFirstName);
                            }
                        } else {
                            // End of flow -> Complete
                            DB::table('dts_transactions')
                                ->where('transaction_id', $transId)
                                ->update([
                                    'status' => 'completed',
                                ]);

                            $controlNumber = $this->activeTransaction['control_number'] ?? '';
                            if ($userOfficeCode) {
                                \App\Services\DtsNotificationService::notifyCompleted($userOfficeCode, $controlNumber, $transId);
                            }

                            $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' marked as COMPLETED!";
                        }
                    }
                }
            });

            if ($isReceived) {
                // Forwarded or returned for revision - reset active transaction
                $this->activeTransaction = null;
                $this->scannedCode = '';
            } else {
                // Received - refresh active transaction details
                $this->loadTransaction();
            }
            $this->notes = '';
            $this->dispatch('dts-transaction-updated');
        } catch (\Throwable $e) {
            $this->errorMessage = 'Action failed: ' . $e->getMessage();
        }
    }
};
?>

<div>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    @if($isOpen)
        <div class="global-scanner-backdrop">
            <div class="global-scanner-modal">
                
                <!-- Modal Header -->
                <div class="global-scanner-header">
                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                        <div class="global-scanner-icon-box">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                                <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                                <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                                <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                                <line x1="7" y1="12" x2="17" y2="12"></line>
                            </svg>
                        </div>
                        <div style="min-width: 0; flex: 1;">
                            <h3 class="global-scanner-title">DTS Barcode & QR Scanner</h3>
                            <p class="global-scanner-subtitle">Scan QR code with webcam, barcode gun, or control number</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeModal" class="global-scanner-close-btn" aria-label="Close scanner">
                        &times;
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="global-scanner-body">
                    
                    <!-- Alert Banners -->
                    @if($successMessage)
                        <div class="scanner-alert alert-success">
                            <span style="display: flex; align-items: center; gap: 6px; min-width: 0; word-break: break-word;">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                <span>{{ $successMessage }}</span>
                            </span>
                            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #166534; cursor: pointer; font-weight: bold; font-size: 16px; line-height: 1; padding: 2px;">&times;</button>
                        </div>
                    @endif

                    @if($errorMessage)
                        <div class="scanner-alert alert-danger">
                            <span style="display: flex; align-items: center; gap: 6px; min-width: 0; word-break: break-word;">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                <span>{{ $errorMessage }}</span>
                            </span>
                            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #991b1b; cursor: pointer; font-weight: bold; font-size: 16px; line-height: 1; padding: 2px;">&times;</button>
                        </div>
                    @endif

                    <!-- Scanner Custom Styles & Mobile Optimization -->
                    <style>
                        .global-scanner-backdrop {
                            position: fixed;
                            inset: 0;
                            background: rgba(15, 23, 42, 0.75);
                            backdrop-filter: blur(6px);
                            z-index: 99999;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            padding: 16px;
                            box-sizing: border-box;
                        }
                        .global-scanner-modal {
                            background: #ffffff;
                            border-radius: 16px;
                            max-width: 640px;
                            width: 100%;
                            max-height: 90vh;
                            max-height: 90dvh;
                            overflow-y: auto;
                            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
                            border: 1px solid #e2e8f0;
                            animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                            display: flex;
                            flex-direction: column;
                            box-sizing: border-box;
                        }
                        .global-scanner-header {
                            padding: 16px 22px;
                            border-bottom: 1px solid #f1f5f9;
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            background: #f8fafc;
                            border-top-left-radius: 16px;
                            border-top-right-radius: 16px;
                            gap: 12px;
                        }
                        .global-scanner-icon-box {
                            width: 36px;
                            height: 36px;
                            min-width: 36px;
                            border-radius: 10px;
                            background: #eff6ff;
                            color: #2563eb;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }
                        .global-scanner-title {
                            font-size: 16.5px;
                            font-weight: 700;
                            color: #0f172a;
                            margin: 0;
                            line-height: 1.25;
                        }
                        .global-scanner-subtitle {
                            font-size: 12px;
                            color: #64748b;
                            margin: 2px 0 0 0;
                            line-height: 1.35;
                            white-space: nowrap;
                            overflow: hidden;
                            text-overflow: ellipsis;
                        }
                        .global-scanner-close-btn {
                            background: transparent;
                            border: none;
                            font-size: 22px;
                            color: #94a3b8;
                            cursor: pointer;
                            border-radius: 50%;
                            width: 32px;
                            height: 32px;
                            min-width: 32px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            transition: all 0.15s;
                        }
                        .global-scanner-close-btn:hover {
                            background: #e2e8f0;
                            color: #0f172a;
                        }
                        .global-scanner-body {
                            padding: 18px 22px;
                            box-sizing: border-box;
                        }
                        .scanner-alert {
                            padding: 11px 14px;
                            border-radius: 10px;
                            font-size: 13px;
                            font-weight: 600;
                            margin-bottom: 16px;
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 10px;
                        }
                        .scanner-alert.alert-success {
                            background: #f0fdf4;
                            border: 1px solid #bbf7d0;
                            color: #166534;
                        }
                        .scanner-alert.alert-danger {
                            background: #fef2f2;
                            border: 1px solid #fecaca;
                            color: #991b1b;
                        }
                        .dts-scanner-viewport-container {
                            background: #0f172a;
                            border-radius: 14px;
                            overflow: hidden;
                            position: relative;
                            min-height: 280px;
                            width: 100%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            box-shadow: inset 0 0 24px rgba(0, 0, 0, 0.6);
                        }
                        #modal-qr-preview {
                            width: 100% !important;
                            border: none !important;
                        }
                        #modal-qr-preview video {
                            width: 100% !important;
                            height: 100% !important;
                            object-fit: cover !important;
                            border-radius: 12px;
                            max-height: 340px;
                        }
                        #modal-qr-preview__scan_region {
                            border: 3px dashed #3b82f6 !important;
                            border-radius: 16px !important;
                            box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.6), 0 0 25px rgba(59, 130, 246, 0.5) !important;
                            transition: all 0.2s ease;
                        }
                        #modal-qr-preview__scan_region img {
                            display: none !important;
                        }
                        #modal-qr-preview__dashboard {
                            display: none !important;
                        }
                        .scanner-badge {
                            display: inline-flex;
                            align-items: center;
                            gap: 5px;
                            padding: 5px 12px;
                            border-radius: 20px;
                            font-size: 12px;
                            font-weight: 700;
                            line-height: 1.2;
                        }
                        .scanner-badge.badge-completed {
                            background: #f1f5f9;
                            color: #475569;
                            border: 1px solid #cbd5e1;
                        }
                        .scanner-badge.badge-received {
                            background: #f0fdf4;
                            color: #166534;
                            border: 1px solid #bbf7d0;
                        }
                        .scanner-badge.badge-incoming {
                            background: #eff6ff;
                            color: #1e40af;
                            border: 1px solid #bfdbfe;
                        }
                        .scanner-input-row {
                            display: flex;
                            gap: 8px;
                            width: 100%;
                            box-sizing: border-box;
                        }
                        .scanner-details-grid {
                            display: grid;
                            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                            gap: 8px 12px;
                            font-size: 12.5px;
                            color: #334155;
                            margin-bottom: 14px;
                            word-break: break-word;
                        }
                        .scanner-action-btn {
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            gap: 6px;
                            padding: 10px 22px;
                            color: #ffffff;
                            border: none;
                            border-radius: 8px;
                            font-weight: 700;
                            font-size: 13.5px;
                            cursor: pointer;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
                            transition: transform 0.1s ease, box-shadow 0.15s ease;
                        }
                        .scanner-action-btn:active {
                            transform: scale(0.98);
                        }

                        /* ── Mobile Shrink & Responsiveness ── */
                        @media (max-width: 640px) {
                            .global-scanner-backdrop {
                                padding: 8px !important;
                            }
                            .global-scanner-modal {
                                border-radius: 12px !important;
                                max-height: 94dvh !important;
                            }
                            .global-scanner-header {
                                padding: 12px 14px !important;
                            }
                            .global-scanner-icon-box {
                                width: 32px !important;
                                height: 32px !important;
                                min-width: 32px !important;
                            }
                            .global-scanner-title {
                                font-size: 15px !important;
                            }
                            .global-scanner-subtitle {
                                font-size: 11px !important;
                            }
                            .global-scanner-body {
                                padding: 12px 14px !important;
                            }
                            .dts-scanner-viewport-container {
                                min-height: 220px !important;
                                max-height: 250px !important;
                            }
                            #modal-qr-preview video {
                                max-height: 250px !important;
                            }
                            .scanner-input-row {
                                flex-direction: row !important;
                            }
                            #global-scanner-code-input {
                                font-size: 13px !important;
                                padding: 9px 12px !important;
                            }
                            .scanner-search-btn {
                                padding: 9px 14px !important;
                                font-size: 12.5px !important;
                            }
                            .scanner-action-form-row {
                                flex-direction: column !important;
                                gap: 8px !important;
                            }
                            .scanner-action-form-row > div {
                                width: 100% !important;
                                min-width: 100% !important;
                            }
                            .scanner-action-btn {
                                width: 100% !important;
                                padding: 11px 16px !important;
                            }
                            .scanner-details-grid {
                                grid-template-columns: 1fr 1fr !important;
                                gap: 6px 8px !important;
                                font-size: 11.5px !important;
                            }
                        }

                        @media (max-width: 420px) {
                            .scanner-details-grid {
                                grid-template-columns: 1fr !important;
                            }
                            .scanner-badge {
                                font-size: 11px !important;
                                padding: 4px 8px !important;
                            }
                            .scanner-recent-item {
                                flex-direction: column !important;
                                align-items: flex-start !important;
                                gap: 3px !important;
                            }
                        }
                    </style>

                    <!-- Camera & Manual Input Grid -->
                    <div style="display: grid; grid-template-columns: 1fr; gap: 14px; margin-bottom: 18px;">
                        
                        <!-- Webcam Viewport -->
                        <div wire:ignore class="dts-scanner-viewport-container">
                            <div id="modal-qr-preview"></div>
                            <div id="camera-loading-placeholder" style="position: absolute; color: #94a3b8; font-size: 13px; font-weight: 500; display: flex; flex-direction: column; align-items: center; gap: 8px; z-index: 5;">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.85;">
                                    <path d="M23 7l-7 5 7 5V7z"></path>
                                    <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                                </svg>
                                <span>Initializing Camera Feed...</span>
                            </div>
                        </div>

                        <!-- Manual Code Input Field -->
                        <div class="scanner-input-row">
                            <input 
                                type="text" 
                                id="global-scanner-code-input" 
                                wire:model.live.debounce.300ms="scannedCode" 
                                wire:keydown.enter="loadTransaction" 
                                placeholder="Scan barcode gun or enter Control / QR Code..." 
                                style="flex: 1; min-width: 0; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 13.5px; outline: none; transition: border-color 0.15s; box-sizing: border-box;" 
                                onfocus="this.style.borderColor='#2563eb';" 
                                onblur="this.style.borderColor='#cbd5e1';"
                            />
                            <button 
                                type="button" 
                                wire:click="loadTransaction" 
                                class="scanner-search-btn"
                                style="padding: 10px 18px; background: #2563eb; color: #ffffff; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: background 0.15s; white-space: nowrap; flex-shrink: 0;"
                                onmouseover="this.style.background='#1d4ed8';" 
                                onmouseout="this.style.background='#2563eb';"
                            >
                                Search
                            </button>
                        </div>
                    </div>

                    <!-- Active Transaction Result Panel -->
                    @if($activeTransaction)
                        <div style="background: #f8fafc; border: 1.5px solid {{ $activeTransaction['is_completed'] ? '#cbd5e1' : ($activeTransaction['is_received_here'] ? '#bbf7d0' : '#bfdbfe') }}; border-radius: 12px; padding: 16px; margin-bottom: 18px; animation: modalPop 0.15s ease; box-sizing: border-box;">
                            
                            <!-- Header Status Line -->
                            <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Control Number</span>
                                    <h4 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 2px 0 0 0; word-break: break-all;">{{ $activeTransaction['control_number'] }}</h4>
                                </div>

                                <div>
                                    @if($activeTransaction['is_completed'])
                                        <span class="scanner-badge badge-completed">
                                            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            Completed
                                        </span>
                                    @elseif($activeTransaction['is_received_here'])
                                        <span class="scanner-badge badge-received">
                                            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                            Received at Office (Ready to Forward)
                                        </span>
                                    @else
                                        <span class="scanner-badge badge-incoming">
                                            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                                            Incoming (Ready to Receive)
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <!-- Completed Transaction Alert -->
                            @if($activeTransaction['is_completed'])
                                <div style="background: #fff7ed; border: 1px solid #fed7aa; color: #c2410c; padding: 10px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                    <span>This transaction has already been completed. No further action needed.</span>
                                </div>
                            @endif

                            <!-- Detail Specs Grid -->
                            <div class="scanner-details-grid">
                                <div><strong style="color: #64748b;">Subject:</strong> {{ $activeTransaction['subject'] }}</div>
                                <div><strong style="color: #64748b;">Requestor:</strong> {{ $activeTransaction['requestor_name'] }}</div>
                                <div><strong style="color: #64748b;">Originator:</strong> {{ $activeTransaction['originated_office'] }}</div>
                                <div><strong style="color: #64748b;">Current Office:</strong> {{ $activeTransaction['current_office'] }}</div>
                                <div><strong style="color: #64748b;">Next Office:</strong> {{ $activeTransaction['next_office'] }}</div>
                            </div>

                            <!-- Action Form Controls (If not completed) -->
                            @if(!$activeTransaction['is_completed'])
                                <div style="border-top: 1px solid #e2e8f0; padding-top: 14px; display: flex; flex-direction: column; gap: 10px;">
                                    @if($activeTransaction['is_received_here'])
                                        @if(strtolower($activeTransaction['status']) === 'revision')
                                            <div style="margin: 4px 0 6px 0; padding: 12px 14px; background: #eff6ff; border: 1.5px solid #3b82f6; border-radius: 10px; font-size: 12px;">
                                                <div style="font-weight: 700; color: #1e40af; margin-bottom: 4px; display: flex; align-items: center; gap: 5px;">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                                    <span>Resubmission Route for Revised Document</span>
                                                </div>
                                                <div style="color: #334155; margin-bottom: 8px;">
                                                    This document was returned for revision{{ !empty($activeTransaction['revision_requested_by_office']) ? ' by ' . $activeTransaction['revision_requested_by_office'] : '' }}. Select target:
                                                </div>
                                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                                    <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: #ffffff; border: 1px solid #cbd5e1;">
                                                        <input type="radio" wire:model="resubmitTarget" value="start" style="margin-top: 2px; accent-color: #2563eb;">
                                                        <div>
                                                            <strong style="color: #0f172a;">Option A: Restart Approval Chain</strong>
                                                            <div style="font-size: 11px; color: #64748b;">Resubmit sequentially to the next office in the approval chain.</div>
                                                        </div>
                                                    </label>
                                                    @if(!empty($activeTransaction['revision_requested_by_sequence']) && $activeTransaction['revision_requested_by_sequence'] > 1)
                                                        <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: #ffffff; border: 1px solid #cbd5e1;">
                                                            <input type="radio" wire:model="resubmitTarget" value="requestor" style="margin-top: 2px; accent-color: #2563eb;">
                                                            <div>
                                                                <strong style="color: #2563eb;">Option B: Fast-Track to Revision Requestor</strong>
                                                                <div style="font-size: 11px; color: #64748b;">Bypass prior steps and send directly back to {{ $activeTransaction['revision_requested_by_office'] ?? 'Revision Requestor' }}.</div>
                                                            </div>
                                                        </label>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        <div class="scanner-action-form-row" style="display: flex; gap: 10px; flex-wrap: wrap;">
                                            <div style="flex: 1; min-width: 160px;">
                                                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px; text-transform: uppercase;">Action Needed For Forwarding</label>
                                                <select wire:model="actionNeeded" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #ffffff; box-sizing: border-box;">
                                                    @foreach($actionOptions as $opt)
                                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div style="flex: 2; min-width: 180px;">
                                                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px; text-transform: uppercase;">Remarks / Notes (Optional)</label>
                                                <input type="text" wire:model="notes" placeholder="Enter forwarding remarks..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box;">
                                            </div>
                                        </div>
                                    @endif

                                    <div style="display: flex; justify-content: flex-end; margin-top: 6px;">
                                        <button 
                                            type="button" 
                                            wire:click="processScanAction" 
                                            class="scanner-action-btn"
                                            style="background: {{ $activeTransaction['is_received_here'] ? '#16a34a' : '#2563eb' }};"
                                        >
                                            @if($activeTransaction['is_received_here'])
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                                                <span>Forward Transaction</span>
                                            @else
                                                <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 10.586V7z" clip-rule="evenodd"/></svg>
                                                <span>Receive Transaction</span>
                                            @endif
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Recent Session Scans -->
                    @if(!empty($recentScans))
                        <div style="border-top: 1px solid #f1f5f9; padding-top: 14px;">
                            <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em;">Recent Session Scans</span>
                            <div style="display: flex; flex-direction: column; gap: 6px; margin-top: 8px;">
                                @foreach($recentScans as $rs)
                                    <div class="scanner-recent-item" style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; background: #f8fafc; padding: 7px 12px; border-radius: 6px; border: 1px solid #e2e8f0; gap: 8px; box-sizing: border-box;">
                                        <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                            <span style="font-weight: 700; color: #2563eb; font-family: monospace; flex-shrink: 0;">{{ $rs['control_number'] }}</span>
                                            <span style="color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $rs['subject'] }}</span>
                                        </div>
                                        <span style="color: #94a3b8; font-weight: 500; font-size: 11px; flex-shrink: 0;">{{ $rs['scanned_at'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <script>
            document.addEventListener('livewire:initialized', () => {
                let html5QrCode = null;

                async function stopScanner() {
                    if (html5QrCode) {
                        try {
                            if (html5QrCode.isScanning) {
                                await html5QrCode.stop();
                            }
                            html5QrCode.clear();
                        } catch(e) {}
                        html5QrCode = null;
                    }
                }

                Livewire.on('init-camera-scanner', async () => {
                    const placeholder = document.getElementById('camera-loading-placeholder');
                    const codeInput = document.getElementById('global-scanner-code-input');
                    if (codeInput) {
                        codeInput.focus();
                        codeInput.select();
                    }

                    if (!document.getElementById('modal-qr-preview')) return;

                    // If camera is already actively scanning, keep it running smoothly
                    if (html5QrCode && html5QrCode.isScanning) {
                        if (placeholder) placeholder.style.display = 'none';
                        return;
                    }

                    await stopScanner();

                    try {
                        html5QrCode = new Html5Qrcode("modal-qr-preview");

                        const calculateQrboxSize = function(viewfinderWidth, viewfinderHeight) {
                            // Enforce a perfect 1:1 SQUARE scan box
                            const minDimension = Math.min(viewfinderWidth, viewfinderHeight);
                            // Fill 78% of minimum dimension to maximize scan area on mobile & desktop
                            let boxSize = Math.floor(minDimension * 0.78);
                            
                            // Enforce a generous minimum size (240px for mobile phones)
                            if (boxSize < 240 && minDimension >= 240) {
                                boxSize = 240;
                            } else if (boxSize < 180) {
                                boxSize = Math.max(180, minDimension - 20);
                            }

                            return {
                                width: boxSize,
                                height: boxSize
                            };
                        };

                        await html5QrCode.start(
                            { facingMode: "environment" },
                            { 
                                fps: 20, 
                                qrbox: calculateQrboxSize
                            },
                            (decodedText) => {
                                if (codeInput) {
                                    codeInput.value = decodedText;
                                }
                                @this.set('scannedCode', decodedText);
                                @this.loadTransaction();
                            },
                            () => {}
                        );
                        if (placeholder) placeholder.style.display = 'none';
                    } catch (err) {
                        if (placeholder) {
                            placeholder.innerHTML = '<svg style="width: 24px; height: 24px; margin-bottom: 8px; stroke: #f59e0b;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><div>Camera Access Disabled / Unavailable</div>';
                        }
                    }
                });

                Livewire.on('stop-camera-scanner', async () => {
                    await stopScanner();
                });

                Livewire.on('scanner-code-invalid', () => {
                    const input = document.getElementById('global-scanner-code-input');
                    if (input) {
                        input.style.borderColor = '#ef4444';
                        input.focus();
                        input.select();
                        setTimeout(() => { input.style.borderColor = '#cbd5e1'; }, 2000);
                    }
                });
            });

            // Global JS Helper
            window.openScannerModal = function(code = '') {
                Livewire.dispatch('open-scanner-modal', { code: code });
            };
        </script>
</div>

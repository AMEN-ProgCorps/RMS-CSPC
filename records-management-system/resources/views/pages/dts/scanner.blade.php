<?php
/**
 * Document Tracking System - Advanced Scanner Console
 * 
 * High-performance full-page scanner console supporting live camera scanning,
 * hardware barcode gun input, batch continuous intake, real-time workflow actions
 * (Receive, Forward, Return for Revision, Complete), and live session audit logs.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Advanced Scanner Console - DTS')] class extends Component {
    /** @var string The scanned barcode, QR code or manual control number */
    public string $scannedCode = '';

    /** @var array|null Loaded active transaction record */
    public ?array $activeTransaction = null;

    /** @var string Action needed when forwarding */
    public string $actionNeeded = 'For Action';

    /** @var string Forwarding notes or revision reason */
    public string $notes = '';

    /** @var string Resubmit routing target: 'start' or 'requestor' */
    public string $resubmitTarget = 'requestor';

    /** @var bool Continuous/Batch scanning mode */
    public bool $continuousMode = false;

    /** @var bool Auto action (Receive if incoming, Forward if received) immediately on scan */
    public bool $autoAction = false;

    /** @var array Action needed options from DB */
    public array $actionOptions = [];

    /** @var array Session scan history log */
    public array $sessionScans = [];

    /** @var string Status alert messages */
    public string $successMessage = '';
    public string $errorMessage = '';
    public string $infoMessage = '';

    /** @var bool Available codes modal open state */
    public bool $showAvailableModal = false;

    /** @var string Available codes search query */
    public string $availableSearch = '';

    /** @var string Available codes filter: 'all', 'incoming', 'received' */
    public string $availableFilter = 'all';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !($perms->can_access_dts ?? true)) {
            abort(403, 'Unauthorized access to DTS Scanner.');
        }

        $this->actionOptions = DB::table('dts_action_options')
            ->orderBy('option_name', 'asc')
            ->pluck('option_name')
            ->toArray();

        if (empty($this->actionOptions)) {
            $this->actionOptions = ['For Action', 'For Approval', 'For Review', 'For Signature', 'For Appropriate Action', 'For Information'];
        }

        $this->actionNeeded = $this->actionOptions[0] ?? 'For Action';
        $this->sessionScans = session()->get('dts_scanner_session_logs', []);
    }

    public function clearAlerts(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
        $this->infoMessage = '';
    }

    public function toggleContinuousMode(): void
    {
        $this->continuousMode = !$this->continuousMode;
    }

    public function clearSessionHistory(): void
    {
        $this->sessionScans = [];
        session()->forget('dts_scanner_session_logs');
        $this->infoMessage = 'Session scan logs cleared.';
    }

    public function resetConsole(): void
    {
        $this->clearAlerts();
        $this->activeTransaction = null;
        $this->scannedCode = '';
        $this->notes = '';
        $this->dispatch('focus-scanner-input');
    }

    public function openAvailableCodesModal(): void
    {
        $this->availableSearch = '';
        $this->availableFilter = 'all';
        $this->showAvailableModal = true;
    }

    public function closeAvailableCodesModal(): void
    {
        $this->showAvailableModal = false;
    }

    public function selectAndScan(string $code): void
    {
        $this->showAvailableModal = false;
        $this->scannedCode = $code;
        $this->loadTransaction();
    }

    /**
     * Get list of pending transactions available for action at user's office.
     */
    public function getAvailableTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode && !auth()->user()?->permissions?->is_sadm) {
            return collect();
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office_tb', 'current_office_tb.office_code', '=', 'dt.current_office')
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        if (!auth()->user()?->permissions?->is_sadm) {
            $query->where('dt.current_office', $userOfficeCode);
        }

        if (!empty(trim($this->availableSearch))) {
            $s = '%' . trim($this->availableSearch) . '%';
            $query->where(function($q) use ($s) {
                $q->where('dtd.control_number', 'like', $s)
                  ->orWhere('dt.qr_code', 'like', $s)
                  ->orWhere('dtd.subject', 'like', $s)
                  ->orWhere('req.requestor_name', 'like', $s)
                  ->orWhere('originated_office.office_name', 'like', $s);
            });
        }

        $transactions = $query->select(
            'dt.transaction_id',
            'dt.trans_type as type',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'current_office_tb.office_name as current_office_name',
            'dtd.control_number',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.originated_from',
            'originated_office.office_name as originated_office_name',
            'req.requestor_name',
            'dtd.date_created as tx_created_at'
        )->orderBy('dtd.date_created', 'desc')->limit(60)->get();

        return $transactions->map(function($t) use ($userOfficeCode) {
            $checkOffice = $userOfficeCode ?: $t->current_office;
            $lastLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $checkOffice)
                ->orderBy('id', 'desc')
                ->first();

            $isReceived = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));
            $t->is_received = $isReceived;
            $t->action_state = $isReceived ? 'received' : 'incoming';
            return $t;
        })->filter(function($t) {
            if ($this->availableFilter === 'incoming') {
                return !$t->is_received;
            } elseif ($this->availableFilter === 'received') {
                return $t->is_received;
            }
            return true;
        });
    }

    /**
     * Get summary count of available transactions for action.
     */
    public function getAvailableCountsProperty(): array
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode && !auth()->user()?->permissions?->is_sadm) {
            return ['all' => 0, 'incoming' => 0, 'received' => 0];
        }

        $query = DB::table('dts_transactions as dt')
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        if (!auth()->user()?->permissions?->is_sadm) {
            $query->where('dt.current_office', $userOfficeCode);
        }

        $allRows = $query->select('dt.transaction_id', 'dt.current_office')->get();
        $incomingCount = 0;
        $receivedCount = 0;

        foreach ($allRows as $row) {
            $checkOffice = $userOfficeCode ?: $row->current_office;
            $lastLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $row->transaction_id)
                ->where('office_code', $checkOffice)
                ->orderBy('id', 'desc')
                ->first();

            $isReceived = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));
            if ($isReceived) {
                $receivedCount++;
            } else {
                $incomingCount++;
            }
        }

        return [
            'all' => count($allRows),
            'incoming' => $incomingCount,
            'received' => $receivedCount,
        ];
    }

    /**
     * Search and load transaction details by scanned QR code or Control Number.
     */
    public function loadTransaction(): void
    {
        $this->clearAlerts();
        $this->activeTransaction = null;

        $rawCode = trim($this->scannedCode);
        if (empty($rawCode)) {
            return;
        }

        // Base64 decode if applicable
        $code = $rawCode;
        $decoded = base64_decode($rawCode, true);
        if ($decoded !== false && ctype_print($decoded)) {
            $code = trim($decoded);
        }

        // File log
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
                'mode' => 'Advanced Console',
                'timestamp' => now()->toIso8601String(),
            ]) . PHP_EOL;

            File::append($logPath, $logLine);
        } catch (\Exception $e) {
            // Silently ignore log write failures
        }

        $qrExists = DB::table('dts_qr_code')->where('code_id', $code)->exists();
        if (!$qrExists) {
            $this->errorMessage = 'Invalid QR Code: Only valid, registered QR codes can be processed by the scanner.';
            $this->dispatch('scanner-audio-error');
            $this->logSessionScan($rawCode, 'Invalid QR Code', 'error');
            return;
        }

        $transaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office_tb', 'current_office_tb.office_code', '=', 'dt.current_office')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
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
                'dtd.date_created as tx_created_at',
                'current_office_tb.office_name as current_office_name',
                'dtd.transaction_flow',
                'dtd.control_number',
                'dtd.action_needed as current_action_needed',
                'req.requestor_name',
                'req.requestor_position as requestor_label',
                'dtd.subject',
                'dtd.classification',
                'dtd.originated_from',
                'originated_office.office_name as originated_office_name',
                'doc.document_name',
                'doc.document_path'
            )
            ->first();

        if (!$transaction) {
            $this->errorMessage = 'Inactive QR Code: Code registered in system but not yet linked to any transaction.';
            $this->dispatch('scanner-audio-error');
            $this->logSessionScan($rawCode, 'Inactive QR Code', 'warning');
            return;
        }

        $rawStatus = strtolower($transaction->status);
        if ($rawStatus === 'completed') {
            $this->errorMessage = 'That QR code is already finished its transaction.';
            $this->dispatch('scanner-audio-error');
            $this->logSessionScan($code, 'Finished Transaction', 'warning');
            return;
        }

        if ($rawStatus === 'cancelled') {
            $this->errorMessage = 'That QR code transaction has been cancelled.';
            $this->dispatch('scanner-audio-error');
            $this->logSessionScan($code, 'Cancelled Transaction', 'warning');
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'Could not resolve your user office profile.';
            $this->dispatch('scanner-audio-error');
            return;
        }

        // Office Scope Check: Document must currently be assigned to user's office
        if ($transaction->current_office !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            $this->dispatch('scanner-audio-error');
            $this->logSessionScan($code, 'Out of Office Scope', 'error');
            return;
        }

        $lastLog = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $transaction->transaction_id)
            ->where('office_code', $userOfficeCode)
            ->orderBy('id', 'desc')
            ->first();
        $isReceivedAtUserOffice = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));

        // Get next office in sequence
        $nextOfficeName = 'End of Flow (Complete)';
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
                    if ($nextOfficeCode === 'ORIGIN') {
                        $nextOfficeCode = $transaction->originated_from;
                    }
                    $nextOfficeName = DB::table('office')->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                }
            }
        }

        $this->activeTransaction = [
            'id' => $transaction->transaction_id,
            'control_number' => $transaction->control_number,
            'qr_code' => $transaction->qr_code,
            'type' => ucfirst($transaction->type),
            'subject' => $transaction->subject ?: 'No subject specified',
            'classification' => $transaction->classification ?: 'Standard',
            'requestor_name' => $transaction->requestor_name ?: 'N/A',
            'requestor_label' => $transaction->requestor_label ?: '',
            'originated_office' => $transaction->originated_office_name ?: $transaction->originated_from,
            'originated_office_code' => $transaction->originated_from,
            'current_office' => $transaction->current_office_name ?: $transaction->current_office,
            'current_office_code' => $transaction->current_office,
            'next_office' => $nextOfficeName,
            'next_office_code' => $nextOfficeCode,
            'status' => ucfirst($transaction->status),
            'raw_status' => $rawStatus,
            'is_completed' => false,
            'is_cancelled' => false,
            'sequence' => $transaction->sequence,
            'document_name' => $transaction->document_name,
            'document_path' => $transaction->document_path,
            'is_received_here' => $isReceivedAtUserOffice,
            'revision_requested_by_office' => $transaction->revision_requested_by_office ?? null,
            'revision_requested_by_sequence' => $transaction->revision_requested_by_sequence ?? null,
            'transaction_flow' => $transaction->transaction_flow,
            'created_at' => $transaction->tx_created_at ? date('M d, Y h:i A', strtotime($transaction->tx_created_at)) : 'N/A',
        ];

        $this->dispatch('scanner-audio-success');
        $this->logSessionScan($transaction->qr_code, $transaction->subject ?: 'Document Inspected', 'success');

        // Auto-Action if enabled and eligible (Auto-Receive incoming OR Auto-Forward already received)
        if ($this->autoAction && $transaction->current_office === $userOfficeCode) {
            if (!$isReceivedAtUserOffice) {
                $this->executeReceive();
            } else {
                $this->executeForward();
            }
        }
    }

    public function selectScannedCode(string $code): void
    {
        $this->selectAndScan($code);
    }

    /**
     * Execute Receive action on active document.
     */
    public function executeReceive(): void
    {
        $this->clearAlerts();

        if (!$this->activeTransaction) {
            $this->errorMessage = 'No document selected.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'Could not resolve your user office profile.';
            return;
        }

        if ($this->activeTransaction['current_office_code'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $transId = $this->activeTransaction['id'];

                $currentLog = DB::table('sub_document_tracking_system_logs')
                    ->where('transaction_id', $transId)
                    ->where('office_code', $userOfficeCode)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($currentLog) {
                    DB::table('sub_document_tracking_system_logs')
                        ->where('id', $currentLog->id)
                        ->update([
                            'type' => 'received',
                            'date_in' => now(),
                            'notes' => $this->notes ? 'Received: ' . $this->notes : 'Received via Advanced Scanner',
                            'performed_by' => auth()->id(),
                        ]);
                } else {
                    DB::table('sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $transId,
                        'office_code' => $userOfficeCode,
                        'type' => 'received',
                        'date_in' => now(),
                        'notes' => $this->notes ? 'Received: ' . $this->notes : 'Received via Advanced Scanner',
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
            });

            $this->successMessage = "Document '{$this->activeTransaction['control_number']}' successfully RECEIVED at {$this->activeTransaction['current_office']}!";
            $this->dispatch('scanner-audio-action');
            $this->logSessionScan($this->activeTransaction['qr_code'], 'Received', 'action');

            if ($this->continuousMode) {
                $this->resetConsole();
            } else {
                $this->loadTransaction(); // Refresh active transaction details to show received state
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to receive document: ' . $e->getMessage();
        }
    }

    /**
     * Execute Forward or Dispatch action to next office in sequence.
     */
    public function executeForward(): void
    {
        $this->clearAlerts();

        if (!$this->activeTransaction) {
            $this->errorMessage = 'No document selected.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'Could not resolve your user office.';
            return;
        }

        if ($this->activeTransaction['current_office_code'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $transId = $this->activeTransaction['id'];
                $isRevisionResubmit = $this->activeTransaction['raw_status'] === 'revision';
                $targetSeqNum = $this->activeTransaction['sequence'] + 1;
                $nextOfficeCode = $this->activeTransaction['next_office_code'];

                if ($isRevisionResubmit) {
                    $reqSeq = $this->activeTransaction['revision_requested_by_sequence'] ?? null;
                    if ($this->resubmitTarget === 'requestor' && $reqSeq && $reqSeq > 1) {
                        $targetSeqNum = (int)$reqSeq;
                    } else {
                        $targetSeqNum = 2;
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

                // Sequence list update for revision
                if ($isRevisionResubmit && !empty($this->activeTransaction['transaction_flow'])) {
                    $revFlow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                    if ($revFlow) {
                        $reqSeq = $this->activeTransaction['revision_requested_by_sequence'] ?? null;
                        if ($reqSeq) {
                            if ($this->resubmitTarget === 'start') {
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
                        DB::table('dts_sequence_list')
                            ->where('control_id', $revFlow->id)
                            ->where('sequence_ranking', 1)
                            ->update([
                                'date_out' => now(),
                                'action_needed' => 'Resubmitted (' . ($this->resubmitTarget === 'start' ? 'Restart' : 'Fast-Track') . ')',
                            ]);
                    }
                }

                // Update current user office log
                $logNote = $isRevisionResubmit 
                    ? ($this->resubmitTarget === 'requestor' ? 'Resubmitted directly to ' : 'Resubmitted to ') . $nextOfficeCode
                    : ($this->notes ?: 'Forwarded via Advanced Scanner');

                DB::table('sub_document_tracking_system_logs')
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

                if ($nextOfficeCode) {
                    $updateTransData = [
                        'current_office' => $nextOfficeCode,
                        'sequence' => $targetSeqNum,
                        'status' => 'ongoing',
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_resubmit_type')) {
                        $updateTransData['revision_resubmit_type'] = $this->resubmitTarget;
                    }

                    DB::table('dts_transactions')
                        ->where('transaction_id', $transId)
                        ->update($updateTransData);

                    DB::table('dts_transaction_details')
                        ->where('id', $transId)
                        ->update([
                            'action_needed' => $this->actionNeeded,
                        ]);

                    DB::table('sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $transId,
                        'office_code' => $nextOfficeCode,
                        'type' => 'forwarded',
                        'date_in' => null,
                        'notes' => $logNote,
                        'performed_by' => auth()->id(),
                    ]);

                    $destOfficeName = DB::table('office')->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                    $this->successMessage = "Document '{$this->activeTransaction['control_number']}' forwarded successfully to {$destOfficeName}!";
                } else {
                    DB::table('dts_transactions')
                        ->where('transaction_id', $transId)
                        ->update(['status' => 'completed']);

                    $this->successMessage = "Document '{$this->activeTransaction['control_number']}' marked as COMPLETED!";
                }
            });

            $this->dispatch('scanner-audio-action');
            $this->logSessionScan($this->activeTransaction['qr_code'], 'Forwarded', 'action');

            // Reset console to ensure document cannot be received/forwarded again from same scan
            $msg = $this->successMessage;
            $this->resetConsole();
            $this->successMessage = $msg;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to forward document: ' . $e->getMessage();
        }
    }

    /**
     * Execute Return for Revision back to document originator.
     */
    public function executeReturnRevision(): void
    {
        $this->clearAlerts();

        if (!$this->activeTransaction) {
            $this->errorMessage = 'No document selected.';
            return;
        }

        if (empty(trim($this->notes))) {
            $this->errorMessage = 'Please provide a reason / note for returning this document for revision.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if ($this->activeTransaction['current_office_code'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $transId = $this->activeTransaction['id'];
                $transDb = DB::table('dts_transaction_details')->where('id', $transId)->first();
                $originatedFrom = $transDb?->originated_from ?? 'ORIGIN';

                DB::table('sub_document_tracking_system_logs')
                    ->where('transaction_id', $transId)
                    ->where('office_code', $userOfficeCode)
                    ->whereNull('date_out')
                    ->update([
                        'date_out' => now(),
                        'notes' => $this->notes,
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
                    ->update(['action_needed' => 'For Revision']);

                if (!empty($transDb->transaction_flow)) {
                    $flow = DB::table('dts_transaction_flow')->where('flow_code', $transDb->transaction_flow)->first();
                    if ($flow) {
                        DB::table('dts_sequence_list')
                            ->where('control_id', $flow->id)
                            ->where('sequence_ranking', $this->activeTransaction['sequence'])
                            ->update([
                                'date_out' => now(),
                                'account_forwarded' => auth()->id(),
                                'action_needed' => 'For Revision',
                                'note' => $this->notes,
                            ]);

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

                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $transId,
                    'office_code' => $originatedFrom,
                    'type' => 'returned',
                    'date_in' => now(),
                    'date_out' => null,
                    'notes' => 'Returned for revision: ' . $this->notes,
                    'performed_by' => auth()->id(),
                ]);
            });

            $originatedOfficeName = DB::table('office')->where('office_code', $this->activeTransaction['originated_office_code'])->value('office_name') ?: $this->activeTransaction['originated_office_code'];
            $this->successMessage = "Document '{$this->activeTransaction['control_number']}' returned for revision to {$originatedOfficeName}!";
            $this->dispatch('scanner-audio-action');
            $this->logSessionScan($this->activeTransaction['qr_code'], 'Returned for Revision', 'warning');

            // Reset console to ensure document cannot be received/forwarded again from same scan
            $msg = $this->successMessage;
            $this->resetConsole();
            $this->successMessage = $msg;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to return document: ' . $e->getMessage();
        }
    }

    /**
     * Internal helper to record session history.
     */
    private function logSessionScan(string $code, string $detail, string $type): void
    {
        array_unshift($this->sessionScans, [
            'code' => $code,
            'detail' => Str::limit($detail, 40),
            'time' => now()->format('h:i:s A'),
            'type' => $type, // 'success', 'error', 'warning', 'action'
        ]);

        $this->sessionScans = array_slice($this->sessionScans, 0, 15);
        session()->put('dts_scanner_session_logs', $this->sessionScans);
    }
}; ?>

<div class="advanced-scanner-wrapper" style="padding: 24px; max-width: 1400px; margin: 0 auto; font-family: Outfit, 'Segoe UI', sans-serif;">

    {{-- Header Banner & Mode Switches --}}
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); display: flex; align-items: center; justify-content: center; color: #ffffff; box-shadow: 0 4px 10px rgba(2, 132, 199, 0.3);">
                <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M3 8V5a2 2 0 0 1 2-2h3v2H5v3H3zm13-5h3a2 2 0 0 1 2 2v3h-2V5h-3V3zM3 16v3a2 2 0 0 0 2 2h3v-2H5v-3H3zm18 0v3a2 2 0 0 1-2 2h-3v-2h3v-3h2zM7 7h3.5v3.5H7V7zm6.5 0h3.5v3.5h-3.5V7zM7 13.5h3.5v3.5H7v-3.5zm6.5 0h3.5v3.5h-3.5v-3.5z" fill="currentColor"/>
                </svg>
            </div>
            <div>
                <h1 style="font-size: 20px; font-weight: 700; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    DTS Advanced Scanner Console
                    <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; font-weight: 700; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.05em;">Live Hardware & Camera</span>
                </h1>
                <p style="font-size: 13px; color: #64748b; margin: 3px 0 0 0;">
                    High-speed document receiving, routing inspection, and barcode scanning workstation.
                </p>
            </div>
        </div>

        {{-- Console Toolbars & Controls --}}
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            {{-- Continuous Mode Toggle --}}
            <button type="button" wire:click="toggleContinuousMode" 
                    style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: 1px solid {{ $continuousMode ? '#38bdf8' : '#e2e8f0' }}; background: {{ $continuousMode ? '#f0f9ff' : '#f8fafc' }}; color: {{ $continuousMode ? '#0284c7' : '#475569' }};">
                <span style="width: 8px; height: 8px; border-radius: 50%; background: {{ $continuousMode ? '#0284c7' : '#94a3b8' }};"></span>
                <span>Batch Mode: <strong>{{ $continuousMode ? 'ON' : 'OFF' }}</strong></span>
            </button>

            {{-- Auto Action Toggle --}}
            <label title="Automatically receives incoming documents, or forwards already received documents upon scanning" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 10px; cursor: pointer;">
                <input type="checkbox" wire:model.live="autoAction" style="cursor: pointer; accent-color: #0284c7; width: 15px; height: 15px;">
                <span>Auto-Action on Scan</span>
            </label>

            {{-- Reset / Clear Button --}}
            <button type="button" wire:click="resetConsole" 
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #e2e8f0; background: #ffffff; color: #475569;">
                <i class="fa-solid fa-arrows-rotate"></i> Reset
            </button>
        </div>
    </div>

    {{-- Dynamic Alerts --}}
    @if($successMessage)
        <div style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-size: 14px; font-weight: 600; box-shadow: 0 2px 4px rgba(22, 101, 52, 0.05);">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="font-size: 18px; color: #16a34a;"></i>
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="clearAlerts" style="background: transparent; border: none; color: #166534; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if($errorMessage)
        <div style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-size: 14px; font-weight: 600; box-shadow: 0 2px 4px rgba(153, 27, 27, 0.05);">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px; color: #dc2626;"></i>
                <span>{{ $errorMessage }}</span>
            </div>
            <button type="button" wire:click="clearAlerts" style="background: transparent; border: none; color: #991b1b; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if($infoMessage)
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 500;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-info" style="color: #64748b;"></i>
                <span>{{ $infoMessage }}</span>
            </div>
            <button type="button" wire:click="clearAlerts" style="background: transparent; border: none; color: #64748b; cursor: pointer;">&times;</button>
        </div>
    @endif

    {{-- Main Two-Column Scanner Grid --}}
    <div style="display: grid; grid-template-columns: 1fr 1.15fr; gap: 24px; align-items: start;">

        {{-- Left Column: Scanner Hardware & Live Camera Viewport --}}
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
            
            {{-- Direct Text / Barcode Gun Input --}}
            <div style="margin-bottom: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <label for="scanner-main-input" style="font-size: 13px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 4px; margin: 0;">
                        <i class="fa-solid fa-barcode" style="color: #0284c7;"></i> Barcode Gun / Manual Input
                    </label>
                    <button type="button" wire:click="openAvailableCodesModal" 
                            style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #0284c7; background: #e0f2fe; border: 1px solid #bae6fd; padding: 4px 10px; border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Available Codes</span>
                        @if($this->availableCounts['all'] > 0)
                            <span style="background: #0284c7; color: #ffffff; font-size: 10px; font-weight: 800; padding: 1px 6px; border-radius: 9999px;">
                                {{ $this->availableCounts['all'] }}
                            </span>
                        @endif
                    </button>
                </div>
                <div style="position: relative;">
                    <input type="text" 
                           id="scanner-main-input" 
                           wire:model.live.debounce.250ms="scannedCode" 
                           wire:keydown.enter="loadTransaction"
                           placeholder="Scan barcode gun or enter Control / QR Code..." 
                           autofocus
                           style="width: 100%; height: 46px; border: 2px solid #0284c7; border-radius: 12px; padding: 0 44px 0 16px; font-size: 15px; font-weight: 600; color: #0f172a; outline: none; background: #f0f9ff; transition: all 0.2s;" />
                    @if($scannedCode)
                        <button type="button" wire:click="$set('scannedCode', '')" 
                                style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: #64748b; cursor: pointer; font-size: 16px;">
                            &times;
                        </button>
                    @endif
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                    <span style="font-size: 11px; color: #64748b;">Press <kbd style="background: #e2e8f0; padding: 1px 4px; border-radius: 4px; font-family: monospace;">Enter</kbd> or trigger barcode gun</span>
                    <button type="button" wire:click="loadTransaction" style="font-size: 12px; font-weight: 700; color: #0284c7; background: transparent; border: none; cursor: pointer;">
                        Lookup Code &rarr;
                    </button>
                </div>
            </div>

            <hr style="border: none; border-top: 1px dashed #e2e8f0; margin: 20px 0;">

            {{-- Live Webcam / Mobile Camera Viewport --}}
            <div wire:ignore id="camera-scanner-section-wrapper">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <label style="font-size: 13px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-camera" style="color: #0284c7;"></i> Live Camera Scanner
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" id="toggle-camera-btn" onclick="toggleCameraScanner()" 
                                style="font-size: 12px; font-weight: 600; padding: 5px 10px; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; cursor: pointer;">
                            <i class="fa-solid fa-power-off" style="margin-right: 4px;"></i> <span id="camera-btn-text">Start Camera</span>
                        </button>
                    </div>
                </div>

                {{-- Camera Selection Dropdown --}}
                <div id="camera-select-container" style="display: none; margin-bottom: 10px;">
                    <select id="camera-select" onchange="switchCamera(this.value)" 
                            style="width: 100%; height: 36px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; font-size: 12px; font-weight: 500; color: #334155; background: #ffffff;">
                        <option value="">Detecting video sources...</option>
                    </select>
                </div>

                {{-- Viewport Container --}}
                <div class="scanner-viewport-box" style="position: relative; width: 100%; height: 320px; background: #0f172a; border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    <div id="console-qr-reader" style="width: 100%; height: 100%;"></div>

                    {{-- Laser Scan Overlay Animation --}}
                    <div id="scanner-laser-line" style="display: none; position: absolute; left: 10%; right: 10%; height: 3px; background: #38bdf8; box-shadow: 0 0 12px #38bdf8, 0 0 24px #38bdf8; z-index: 10; animation: laserScan 2s infinite ease-in-out;"></div>

                    {{-- Camera Off Placeholder --}}
                    <div id="camera-off-placeholder" style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8; padding: 20px; text-align: center; background: #0f172a; z-index: 20;">
                        <div style="width: 60px; height: 60px; border-radius: 50%; background: #1e293b; display: flex; align-items: center; justify-content: center; margin-bottom: 12px;">
                            <i class="fa-solid fa-camera" style="font-size: 24px; color: #64748b;"></i>
                        </div>
                        <p style="font-size: 13px; font-weight: 600; color: #e2e8f0; margin: 0;">Camera is inactive</p>
                        <p style="font-size: 11px; color: #64748b; margin: 4px 0 14px 0;">Click 'Start Camera' to scan physical QR codes with your webcam.</p>
                        <button type="button" onclick="toggleCameraScanner()" style="padding: 7px 16px; border-radius: 8px; background: #0284c7; color: #ffffff; border: none; font-size: 12px; font-weight: 600; cursor: pointer;">
                            Turn On Camera
                        </button>
                    </div>
                </div>
            </div>

            {{-- Keyboard Shortcut Helper Tips --}}
            <div style="margin-top: 18px; padding: 12px 14px; background: #f8fafc; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 12px; color: #64748b;">
                <div style="font-weight: 700; color: #334155; margin-bottom: 4px;">⚡ Fast Workflow Tips:</div>
                <ul style="margin: 0; padding-left: 18px; line-height: 1.5;">
                    <li>Handheld USB/Bluetooth barcode guns auto-focus and trigger instantly.</li>
                    <li>Toggle <strong>Auto-Action on Scan</strong> to auto-receive incoming documents or auto-forward already received documents in rapid succession.</li>
                </ul>
            </div>
        </div>

        {{-- Right Column: Live Document Intelligence & Action Console --}}
        <div>
            @if($activeTransaction)
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                    
                    {{-- Document Header Badges --}}
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                <span style="font-size: 11px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 6px;">
                                    {{ $activeTransaction['type'] }}
                                </span>
                                <span style="font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 6px;">
                                    {{ $activeTransaction['classification'] }}
                                </span>
                                @if($activeTransaction['raw_status'] === 'completed')
                                    <span style="font-size: 11px; font-weight: 700; background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 6px;">
                                        Completed
                                    </span>
                                @elseif($activeTransaction['raw_status'] === 'revision')
                                    <span style="font-size: 11px; font-weight: 700; background: #fee2e2; color: #b91c1c; padding: 3px 8px; border-radius: 6px;">
                                        Needs Revision
                                    </span>
                                @else
                                    <span style="font-size: 11px; font-weight: 700; background: #e0e7ff; color: #4338ca; padding: 3px 8px; border-radius: 6px;">
                                        In Progress (Step {{ $activeTransaction['sequence'] }})
                                    </span>
                                @endif
                            </div>
                            <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; font-family: monospace; letter-spacing: 0.02em;">
                                {{ $activeTransaction['control_number'] }}
                            </h2>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 11px; color: #94a3b8; display: block;">QR Code ID</span>
                            <span style="font-size: 12px; font-weight: 600; color: #475569; font-family: monospace;">{{ $activeTransaction['qr_code'] ?: 'N/A' }}</span>
                        </div>
                    </div>

                    {{-- Document Subject & Meta --}}
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 20px;">
                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; margin-bottom: 4px;">Subject / Purpose</div>
                        <div style="font-size: 14px; font-weight: 600; color: #1e293b; line-height: 1.4;">
                            {{ $activeTransaction['subject'] }}
                        </div>
                    </div>

                    {{-- Routing & Station Breakdown Grid --}}
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b;">Origin Office</div>
                            <div style="font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 2px;">{{ $activeTransaction['originated_office'] }}</div>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">By: {{ $activeTransaction['requestor_name'] }}</div>
                        </div>
                        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b;">Current Station</div>
                            <div style="font-size: 13px; font-weight: 700; color: #0284c7; margin-top: 2px;">{{ $activeTransaction['current_office'] }}</div>
                            <div style="font-size: 11px; color: {{ $activeTransaction['is_received_here'] ? '#16a34a' : '#ea580c' }}; font-weight: 600; margin-top: 2px;">
                                <i class="fa-solid {{ $activeTransaction['is_received_here'] ? 'fa-check' : 'fa-clock' }}"></i>
                                {{ $activeTransaction['is_received_here'] ? 'Received at Your Station' : 'Pending Physical Intake' }}
                            </div>
                        </div>
                    </div>

                    {{-- Next Step in Flow --}}
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 14px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #166534;">Next Station in Flow</span>
                            <div style="font-size: 13px; font-weight: 700; color: #14532d; margin-top: 2px;">
                                {{ $activeTransaction['next_office'] }}
                            </div>
                        </div>
                        @if($activeTransaction['document_name'])
                            <span style="font-size: 11px; font-weight: 600; color: #166534; background: #ffffff; padding: 4px 8px; border-radius: 6px; border: 1px solid #bbf7d0;">
                                <i class="fa-solid fa-paperclip"></i> Attached Document
                            </span>
                        @endif
                    </div>

                    {{-- Action Control Deck --}}
                    <div style="border-top: 1px solid #e2e8f0; padding-top: 20px;">
                        <h3 style="font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 14px 0; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-bolt" style="color: #0284c7;"></i> Action Execution
                        </h3>

                        {{-- Case A: Document is NOT received yet at user's office --}}
                        @if(!$activeTransaction['is_received_here'] && !$activeTransaction['is_completed'] && !$activeTransaction['is_cancelled'])
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">
                                    Receipt Notes / Remarks (Optional)
                                </label>
                                <input type="text" wire:model="notes" placeholder="e.g. Received from bearer / messenger..." 
                                       style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 12px; font-size: 13px; outline: none; margin-bottom: 12px;" />

                                <button type="button" wire:click="executeReceive" 
                                        style="width: 100%; height: 44px; background: #0284c7; color: #ffffff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);">
                                    <i class="fa-solid fa-inbox"></i> RECEIVE DOCUMENT AT MY OFFICE
                                </button>
                            </div>

                        {{-- Case B: Document IS received -> Ready to Forward / Return / Complete --}}
                        @elseif($activeTransaction['is_received_here'] && !$activeTransaction['is_completed'] && !$activeTransaction['is_cancelled'])
                            
                            {{-- If Resubmitting from Revision at Origin --}}
                            @if($activeTransaction['raw_status'] === 'revision')
                                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px; margin-bottom: 14px;">
                                    <div style="font-size: 12px; font-weight: 700; color: #991b1b; margin-bottom: 6px;">Revision Resubmit Routing:</div>
                                    <div style="display: flex; gap: 16px;">
                                        <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #7f1d1d; cursor: pointer;">
                                            <input type="radio" wire:model="resubmitTarget" value="requestor" style="accent-color: #dc2626;">
                                            Fast-Track back to requesting office
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #7f1d1d; cursor: pointer;">
                                            <input type="radio" wire:model="resubmitTarget" value="start" style="accent-color: #dc2626;">
                                            Restart approval flow
                                        </label>
                                    </div>
                                </div>
                            @endif

                            <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">
                                        Action Needed
                                    </label>
                                    <select wire:model="actionNeeded" style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; font-size: 13px; font-weight: 600; color: #1e293b; background: #ffffff;">
                                        @foreach($actionOptions as $opt)
                                            <option value="{{ $opt }}">{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">
                                        Forward / Dispatch Notes
                                    </label>
                                    <input type="text" wire:model="notes" placeholder="Remarks / endorsement notes..." 
                                           style="width: 100%; height: 38px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 12px; font-size: 13px; outline: none;" />
                                </div>
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="button" wire:click="executeForward" 
                                        style="flex: 2; height: 44px; background: #16a34a; color: #ffffff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25);">
                                    <i class="fa-solid fa-paper-plane"></i> FORWARD TO {{ strtoupper($activeTransaction['next_office']) }}
                                </button>
                                <button type="button" wire:click="executeReturnRevision" 
                                        style="flex: 1; height: 44px; background: #dc2626; color: #ffffff; border: none; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                    <i class="fa-solid fa-rotate-left"></i> Return Revision
                                </button>
                            </div>

                        {{-- Case C: Already completed or cancelled --}}
                        @else
                            <div style="padding: 16px; background: #f8fafc; border-radius: 10px; text-align: center; color: #64748b; font-size: 13px; font-weight: 500;">
                                <i class="fa-solid fa-lock" style="margin-right: 6px;"></i> Document is in a terminal state ({{ $activeTransaction['status'] }}). No further workflow actions can be executed.
                            </div>
                        @endif
                    </div>
                </div>

            {{-- Empty Waiting State --}}
            @else
                <div style="background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 16px; padding: 60px 30px; text-align: center; color: #64748b;">
                    <div style="width: 68px; height: 68px; border-radius: 50%; background: #f0f9ff; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; color: #0284c7;">
                        <i class="fa-solid fa-qrcode" style="font-size: 32px;"></i>
                    </div>
                    <h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 6px 0;">Awaiting Scanned Input</h3>
                    <p style="font-size: 13px; color: #64748b; max-width: 380px; margin: 0 auto 18px auto; line-height: 1.5;">
                        Scan any document QR code via camera, trigger your handheld barcode scanner, or type a control number into the input box on the left.
                    </p>
                    <div style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 6px 14px; border-radius: 9999px;">
                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #0284c7; animation: pulse 1.5s infinite;"></span>
                        Ready to process
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Bottom Section: Live Session Scan Audit Log --}}
    <div style="margin-top: 30px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px 24px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-clock-rotate-left" style="color: #0284c7; font-size: 16px;"></i>
                <h3 style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 0;">Current Session Scan History</h3>
                <span style="font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 9999px;">{{ count($sessionScans) }} Scans</span>
            </div>
            @if(!empty($sessionScans))
                <button type="button" wire:click="clearSessionHistory" style="font-size: 12px; font-weight: 600; color: #ef4444; background: transparent; border: none; cursor: pointer;">
                    <i class="fa-solid fa-trash-can" style="margin-right: 4px;"></i> Clear Log
                </button>
            @endif
        </div>

        @if(!empty($sessionScans))
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="border-bottom: 1px solid #e2e8f0; text-align: left; color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase;">
                            <th style="padding: 10px 14px;">Time</th>
                            <th style="padding: 10px 14px;">Code / Control No.</th>
                            <th style="padding: 10px 14px;">Result / Action</th>
                            <th style="padding: 10px 14px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sessionScans as $idx => $scan)
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 12px 14px; color: #94a3b8; font-weight: 500; font-family: monospace;">{{ $scan['time'] }}</td>
                                <td style="padding: 12px 14px; font-weight: 700; color: #0f172a; font-family: monospace;">{{ $scan['code'] }}</td>
                                <td style="padding: 12px 14px;">
                                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; 
                                        @if($scan['type'] === 'success') background: #e0f2fe; color: #0369a1;
                                        @elseif($scan['type'] === 'action') background: #dcfce7; color: #15803d;
                                        @elseif($scan['type'] === 'warning') background: #fef3c7; color: #b45309;
                                        @else background: #fee2e2; color: #b91c1c; @endif">
                                        {{ $scan['detail'] }}
                                    </span>
                                </td>
                                <td style="padding: 12px 14px; text-align: right;">
                                    <button type="button" wire:click="selectScannedCode('{{ $scan['code'] }}')" 
                                            style="font-size: 12px; font-weight: 600; color: #0284c7; background: #f0f9ff; border: 1px solid #bae6fd; padding: 4px 10px; border-radius: 6px; cursor: pointer;">
                                        Inspect
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="padding: 24px; text-align: center; color: #94a3b8; font-size: 13px;">
                No scans recorded in this active session yet.
            </div>
        @endif
    </div>

    {{-- Scanner Custom Styles for 1:1 Square Scan Box & Animations --}}
    <style>
        .scanner-viewport-box {
            background: #0f172a;
            border-radius: 14px;
            overflow: hidden;
            position: relative;
            min-height: 320px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 24px rgba(0, 0, 0, 0.6);
        }
        #console-qr-reader {
            width: 100% !important;
            border: none !important;
        }
        #console-qr-reader video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border-radius: 12px;
            max-height: 360px;
        }
        #console-qr-reader__scan_region {
            border: 3.5px dashed #0284c7 !important;
            border-radius: 20px !important;
            box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.65), 0 0 25px rgba(2, 132, 199, 0.5) !important;
            transition: all 0.2s ease;
        }
        #console-qr-reader__scan_region img {
            display: none !important;
        }
        #console-qr-reader__dashboard {
            display: none !important;
        }
        @keyframes laserScan {
            0% { top: 12%; opacity: 0.8; }
            50% { top: 88%; opacity: 1; }
            100% { top: 12%; opacity: 0.8; }
        }
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes modalZoom {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>

    {{-- Available Codes / Actionable Transactions Modal --}}
    @if($showAvailableModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 16px; animation: modalFadeIn 0.15s ease-out;">
            <div style="background: #ffffff; border-radius: 16px; max-width: 860px; width: 100%; max-height: 88vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e2e8f0; overflow: hidden; animation: modalZoom 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
                
                {{-- Modal Header --}}
                <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: #f8fafc;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-list-check"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0;">
                                Available Documents for Action
                            </h3>
                            <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
                                Documents currently assigned to your station pending receipt or dispatch.
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeAvailableCodesModal" 
                            style="width: 32px; height: 32px; border-radius: 8px; border: 1px solid #e2e8f0; background: #ffffff; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                        &times;
                    </button>
                </div>

                {{-- Modal Filters & Search Bar --}}
                <div style="padding: 16px 24px; border-bottom: 1px solid #f1f5f9; background: #ffffff; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;">
                    
                    {{-- Tab Switcher --}}
                    <div style="display: flex; gap: 6px; background: #f1f5f9; padding: 4px; border-radius: 10px;">
                        <button type="button" wire:click="$set('availableFilter', 'all')"
                                style="padding: 6px 12px; border-radius: 7px; border: none; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s; {{ $availableFilter === 'all' ? 'background: #ffffff; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);' : 'background: transparent; color: #64748b;' }}">
                            All ({{ $this->availableCounts['all'] }})
                        </button>
                        <button type="button" wire:click="$set('availableFilter', 'incoming')"
                                style="padding: 6px 12px; border-radius: 7px; border: none; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s; {{ $availableFilter === 'incoming' ? 'background: #ffffff; color: #0284c7; box-shadow: 0 1px 3px rgba(0,0,0,0.1);' : 'background: transparent; color: #64748b;' }}">
                            📥 Incoming / To Receive ({{ $this->availableCounts['incoming'] }})
                        </button>
                        <button type="button" wire:click="$set('availableFilter', 'received')"
                                style="padding: 6px 12px; border-radius: 7px; border: none; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s; {{ $availableFilter === 'received' ? 'background: #ffffff; color: #16a34a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);' : 'background: transparent; color: #64748b;' }}">
                            📤 In Custody / To Forward ({{ $this->availableCounts['received'] }})
                        </button>
                    </div>

                    {{-- Search Input --}}
                    <div style="position: relative; flex: 1; min-width: 220px; max-width: 320px;">
                        <input type="text" wire:model.live.debounce.250ms="availableSearch" placeholder="Search control no, subject, origin..." 
                               style="width: 100%; height: 36px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 12px 0 32px; font-size: 12px; outline: none;" />
                        <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 12px; color: #94a3b8;"></i>
                    </div>
                </div>

                {{-- Modal Body: Scrollable Document List --}}
                <div style="padding: 16px 24px; overflow-y: auto; flex: 1; max-height: calc(88vh - 180px);">
                    @if($this->availableTransactions->isNotEmpty())
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            @foreach($this->availableTransactions as $item)
                                <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 16px; transition: all 0.15s; background: #ffffff;" 
                                     onmouseover="this.style.borderColor='#0284c7'; this.style.background='#f8fafc';" 
                                     onmouseout="this.style.borderColor='#e2e8f0'; this.style.background='#ffffff';">
                                    
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                                            <span style="font-family: monospace; font-size: 14px; font-weight: 800; color: #0f172a;">
                                                {{ $item->control_number }}
                                            </span>
                                            @if(!$item->is_received)
                                                <span style="font-size: 10px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 7px; border-radius: 5px; text-transform: uppercase;">
                                                    📥 Pending Receipt
                                                </span>
                                            @else
                                                <span style="font-size: 10px; font-weight: 700; background: #dcfce7; color: #15803d; padding: 2px 7px; border-radius: 5px; text-transform: uppercase;">
                                                    📤 In Custody / Ready to Forward
                                                </span>
                                            @endif
                                            <span style="font-size: 10px; font-weight: 600; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 5px;">
                                                {{ ucfirst($item->type) }}
                                            </span>
                                        </div>

                                        <div style="font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            {{ $item->subject ?: 'No subject specified' }}
                                        </div>

                                        <div style="font-size: 11px; color: #64748b; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                            <span><strong>Origin:</strong> {{ $item->originated_office_name ?: $item->originated_from }}</span>
                                            @if($item->action_needed)
                                                <span><strong>Action Needed:</strong> <span style="color: #0284c7; font-weight: 600;">{{ $item->action_needed }}</span></span>
                                            @endif
                                            @if($item->qr_code)
                                                <span style="font-family: monospace; color: #94a3b8;">QR: {{ $item->qr_code }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    <div>
                                        <button type="button" wire:click="selectAndScan('{{ $item->qr_code }}')" 
                                                style="padding: 8px 16px; border-radius: 8px; background: #0284c7; color: #ffffff; border: none; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(2, 132, 199, 0.2); white-space: nowrap;">
                                            <i class="fa-solid fa-barcode"></i> Select & Scan
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div style="padding: 40px 20px; text-align: center; color: #94a3b8;">
                            <div style="font-size: 32px; margin-bottom: 8px; color: #cbd5e1;">
                                <i class="fa-solid fa-folder-open"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 600; color: #475569;">No matching documents found</div>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">There are no active transactions pending action for this filter.</div>
                        </div>
                    @endif
                </div>

                {{-- Modal Footer --}}
                <div style="padding: 12px 24px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 12px; color: #64748b;">Click <strong>Select & Scan</strong> to load the document directly into the scanner workstation.</span>
                    <button type="button" wire:click="closeAvailableCodesModal" 
                            style="padding: 6px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #ffffff; color: #475569; font-size: 12px; font-weight: 600; cursor: pointer;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
{{-- Include HTML5 QR Code Scanner Library if not present --}}
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<script>
(function() {
    let html5QrCode = null;
    let isScanning = false;
    let availableCameras = [];
    let activeCameraId = null;
    let audioCtx = null;
    let lastScannedText = '';
    let lastScanTime = 0;

    // Release all active tracks across the page
    function releaseAllMediaTracks() {
        try {
            const videos = document.querySelectorAll('video');
            videos.forEach(v => {
                if (v.srcObject && typeof v.srcObject.getTracks === 'function') {
                    v.srcObject.getTracks().forEach(track => track.stop());
                    v.srcObject = null;
                }
            });
        } catch (e) {}
    }

    // Web Audio Sound Feedback Synthesizer
    function playBeep(freq, type, duration) {
        try {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type || 'sine';
            osc.frequency.value = freq || 800;
            gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            // Audio context not allowed or unsupported
        }
    }

    window.addEventListener('scanner-audio-success', () => {
        playBeep(880, 'sine', 0.12);
        setTimeout(() => playBeep(1320, 'sine', 0.18), 120);
    });

    window.addEventListener('scanner-audio-action', () => {
        playBeep(587.33, 'triangle', 0.1);
        setTimeout(() => playBeep(880, 'triangle', 0.1), 100);
        setTimeout(() => playBeep(1174.66, 'triangle', 0.2), 200);
    });

    window.addEventListener('scanner-audio-error', () => {
        playBeep(250, 'sawtooth', 0.25);
    });

    window.addEventListener('focus-scanner-input', () => {
        const input = document.getElementById('scanner-main-input');
        if (input) input.focus();
    });

    async function stopScanner() {
        if (html5QrCode) {
            try {
                if (html5QrCode.isScanning) {
                    await html5QrCode.stop();
                }
                html5QrCode.clear();
            } catch (e) {
                console.warn('Scanner stop cleanup:', e);
            }
            html5QrCode = null;
        }
        releaseAllMediaTracks();
        isScanning = false;

        const placeholder = document.getElementById('camera-off-placeholder');
        const laser = document.getElementById('scanner-laser-line');
        const btnText = document.getElementById('camera-btn-text');
        const cameraSelectContainer = document.getElementById('camera-select-container');

        if (placeholder) placeholder.style.display = 'flex';
        if (laser) laser.style.display = 'none';
        if (btnText) btnText.textContent = 'Start Camera';
        if (cameraSelectContainer) cameraSelectContainer.style.display = 'none';
    }

    // Camera Management
    window.toggleCameraScanner = async function() {
        if (isScanning) {
            await stopScanner();
            return;
        }

        try {
            if (typeof Html5Qrcode === 'undefined') {
                alert('Scanner library is loading, please try again in a moment.');
                return;
            }

            await stopScanner();

            const container = document.getElementById('console-qr-reader');
            if (!container) return;

            html5QrCode = new Html5Qrcode('console-qr-reader');

            let devices = [];
            try {
                devices = await Html5Qrcode.getCameras();
            } catch (permErr) {
                console.warn('getCameras error, will attempt facingMode fallback:', permErr);
            }

            const placeholder = document.getElementById('camera-off-placeholder');
            const laser = document.getElementById('scanner-laser-line');
            const btnText = document.getElementById('camera-btn-text');
            const cameraSelectContainer = document.getElementById('camera-select-container');
            const selectEl = document.getElementById('camera-select');

            if (devices && devices.length > 0) {
                availableCameras = devices;
                activeCameraId = devices[0].id;

                if (selectEl) {
                    selectEl.innerHTML = devices.map(d => `<option value="${d.id}">${d.label || 'Camera ' + d.id}</option>`).join('');
                    if (cameraSelectContainer) cameraSelectContainer.style.display = devices.length > 1 ? 'block' : 'none';
                }

                await startScanning({ deviceId: { exact: activeCameraId } });
            } else {
                await startScanning({ facingMode: "environment" });
            }

            isScanning = true;
            if (placeholder) placeholder.style.display = 'none';
            if (laser) laser.style.display = 'block';
            if (btnText) btnText.textContent = 'Stop Camera';
        } catch (err) {
            console.error('Camera init error:', err);
            await stopScanner();
            alert('Unable to access camera. Please check camera permissions in your browser.');
        }
    };

    async function startScanning(cameraConfig) {
        const calculateQrboxSize = function(viewfinderWidth, viewfinderHeight) {
            const minDimension = Math.min(viewfinderWidth, viewfinderHeight);
            let boxSize = Math.floor(minDimension * 0.72);
            if (boxSize < 220 && minDimension >= 220) {
                boxSize = 220;
            } else if (boxSize < 160) {
                boxSize = Math.max(160, minDimension - 20);
            }
            return {
                width: boxSize,
                height: boxSize
            };
        };

        const config = {
            fps: 20,
            qrbox: calculateQrboxSize,
            aspectRatio: 1.0,
        };

        await html5QrCode.start(
            cameraConfig,
            config,
            (decodedText) => {
                if (!decodedText) return;
                const now = Date.now();
                if (decodedText === lastScannedText && (now - lastScanTime) < 1500) {
                    return;
                }
                lastScannedText = decodedText;
                lastScanTime = now;

                const input = document.getElementById('scanner-main-input');
                if (input) {
                    input.value = decodedText;
                }
                @this.set('scannedCode', decodedText);
                @this.loadTransaction();
            },
            () => {} // suppress frame decode errors
        );
    }

    window.switchCamera = async function(cameraId) {
        if (!isScanning || !html5QrCode) return;
        try {
            await html5QrCode.stop();
            await startScanning({ deviceId: { exact: cameraId } });
            activeCameraId = cameraId;
        } catch (e) {
            console.error('Failed to switch camera:', e);
        }
    };

    window.addEventListener('beforeunload', () => {
        releaseAllMediaTracks();
    });
})();
</script>
@endpush


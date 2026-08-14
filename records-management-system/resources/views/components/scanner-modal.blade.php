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
        $cnExists = DB::table('dts_transaction_details')->where('control_number', $code)->exists();

        if (!$qrExists && !$cnExists) {
            $this->errorMessage = 'Invalid Code: Scanned input is neither a registered QR Code nor a valid Control Number.';
            $this->dispatch('scanner-code-invalid');
            return;
        }

        if ($qrExists) {
            $hasTransaction = DB::table('dts_transactions')->where('qr_code', $code)->exists();
            if (!$hasTransaction) {
                $this->errorMessage = 'Inactive QR Code: Code registered in system but not yet linked to any transaction.';
                $this->dispatch('scanner-code-invalid');
                return;
            }
        }

        $transaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office_tb', 'current_office_tb.office_code', '=', 'dt.current_office')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where(function($q) use ($code) {
                $q->where('dt.qr_code', $code)
                  ->orWhere('dtd.control_number', $code);
            })
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
            $this->errorMessage = 'No transaction found matching code: ' . $code;
            $this->dispatch('scanner-code-invalid');
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $isReceivedAtUserOffice = false;
        if ($userOfficeCode) {
            $lastLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $transaction->transaction_id)
                ->where('office_code', $userOfficeCode)
                ->orderBy('id', 'desc')
                ->first();
            $isReceivedAtUserOffice = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));
        }

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
                    $nextOfficeName = DB::table('office')->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                }
            }
        }

        $isCompleted = strtolower($transaction->status) === 'completed';

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
            'is_completed' => $isCompleted,
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

        if ($this->activeTransaction['is_completed']) {
            $this->errorMessage = 'This transaction is already completed. No further action can be performed.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'User office code could not be resolved.';
            return;
        }

        if ($this->activeTransaction['current_office_code'] !== $userOfficeCode && !auth()->user()?->permissions?->is_sadm) {
            $this->errorMessage = 'Unauthorized: Document is currently assigned to ' . $this->activeTransaction['current_office'] . '.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $transId = $this->activeTransaction['id'];
                $isReceived = $this->activeTransaction['is_received_here'];

                // Case 1: Receive incoming transaction
                if (!$isReceived) {
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
                                'performed_by' => auth()->id(),
                            ]);
                    } else {
                        DB::table('sub_document_tracking_system_logs')->insert([
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

                    $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' received successfully at {$this->activeTransaction['current_office']}!";
                } 
                // Case 2: Forward received transaction
                else {
                    $isRevisionAction = in_array($this->actionNeeded, ['For Revision', 'Returned for Revision']);

                    if ($isRevisionAction) {
                        $transDb = DB::table('dts_transaction_details')->where('id', $transId)->first();
                        $originatedFrom = $transDb?->originated_from ?? 'ORIGIN';

                        // Update date_out for user office
                        DB::table('sub_document_tracking_system_logs')
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

                        DB::table('sub_document_tracking_system_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $originatedFrom,
                            'type' => 'returned',
                            'date_in' => now(),
                            'date_out' => null,
                            'notes' => 'Returned for revision from ' . $userOfficeCode . ': ' . ($this->notes ?: 'Please revise document'),
                            'performed_by' => auth()->id(),
                        ]);

                        $originatedOfficeName = DB::table('office')->where('office_code', $originatedFrom)->value('office_name') ?: $originatedFrom;
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

                            DB::table('sub_document_tracking_system_logs')->insert([
                                'transaction_id' => $transId,
                                'office_code' => $nextOfficeCode,
                                'type' => 'forwarded',
                                'date_in' => null,
                                'notes' => $logNote,
                                'performed_by' => auth()->id(),
                            ]);

                            $destOfficeName = DB::table('office')->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                            $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' forwarded successfully to {$destOfficeName}!";
                        } else {
                            // End of flow -> Complete
                            DB::table('dts_transactions')
                                ->where('transaction_id', $transId)
                                ->update([
                                    'status' => 'completed',
                                ]);

                            $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' marked as COMPLETED!";
                        }
                    }
                }
            });

            // Reload active transaction details
            $this->loadTransaction();
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
        <div class="global-scanner-backdrop" style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 16px;">
            <div class="global-scanner-modal" style="background: #ffffff; border-radius: 16px; max-width: 640px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
                
                <!-- Modal Header -->
                <div style="padding: 18px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-top-left-radius: 16px; border-top-right-radius: 16px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 36px; height: 36px; border-radius: 10px; background: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700;">
                            📷
                        </div>
                        <div>
                            <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin: 0;">DTS Barcode & QR Scanner</h3>
                            <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">Scan QR code with webcam, barcode gun, or control number</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeModal" style="background: transparent; border: none; font-size: 24px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; transition: all 0.15s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#0f172a';" onmouseout="this.style.background='transparent'; this.style.color='#94a3b8';">
                        &times;
                    </button>
                </div>

                <!-- Modal Body -->
                <div style="padding: 20px 24px;">
                    
                    <!-- Alert Banners -->
                    @if($successMessage)
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                            <span>✓ {{ $successMessage }}</span>
                            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #166534; cursor: pointer; font-weight: bold;">&times;</button>
                        </div>
                    @endif

                    @if($errorMessage)
                        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
                            <span>⚠ {{ $errorMessage }}</span>
                            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #991b1b; cursor: pointer; font-weight: bold;">&times;</button>
                        </div>
                    @endif

                    <!-- Scanner Custom Styles for 1:1 Square Scan Box -->
                    <style>
                        .dts-scanner-viewport-container {
                            background: #0f172a;
                            border-radius: 14px;
                            overflow: hidden;
                            position: relative;
                            min-height: 300px;
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
                            max-height: 360px;
                        }
                        #modal-qr-preview__scan_region {
                            border: 3.5px dashed #3b82f6 !important;
                            border-radius: 20px !important;
                            box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.6), 0 0 25px rgba(59, 130, 246, 0.5) !important;
                            transition: all 0.2s ease;
                        }
                        #modal-qr-preview__scan_region img {
                            display: none !important;
                        }
                        #modal-qr-preview__dashboard {
                            display: none !important;
                        }
                        @media (max-width: 640px) {
                            .dts-scanner-viewport-container {
                                min-height: 340px !important;
                            }
                            #modal-qr-preview video {
                                max-height: 380px !important;
                            }
                        }
                    </style>

                    <!-- Camera & Manual Input Grid -->
                    <div style="display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 20px;">
                        
                        <!-- Webcam Viewport -->
                        <div wire:ignore class="dts-scanner-viewport-container">
                            <div id="modal-qr-preview"></div>
                            <div id="camera-loading-placeholder" style="position: absolute; color: #94a3b8; font-size: 13px; font-weight: 500; display: flex; flex-direction: column; align-items: center; gap: 8px; z-index: 5;">
                                <span style="font-size: 28px;">🎥</span>
                                <span>Initializing Camera Feed...</span>
                            </div>
                        </div>

                        <!-- Manual Code Input Field -->
                        <div style="display: flex; gap: 8px;">
                            <input 
                                type="text" 
                                id="global-scanner-code-input" 
                                wire:model.live.debounce.300ms="scannedCode" 
                                wire:keydown.enter="loadTransaction" 
                                placeholder="Scan barcode gun or enter Control / QR Code..." 
                                style="flex: 1; padding: 11px 16px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 13.5px; outline: none; transition: border-color 0.15s;" 
                                onfocus="this.style.borderColor='#2563eb';" 
                                onblur="this.style.borderColor='#cbd5e1';"
                            />
                            <button 
                                type="button" 
                                wire:click="loadTransaction" 
                                style="padding: 11px 20px; background: #2563eb; color: #ffffff; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: background 0.15s;"
                                onmouseover="this.style.background='#1d4ed8';" 
                                onmouseout="this.style.background='#2563eb';"
                            >
                                Search
                            </button>
                        </div>
                    </div>

                    <!-- Active Transaction Result Panel -->
                    @if($activeTransaction)
                        <div style="background: #f8fafc; border: 1.5px solid {{ $activeTransaction['is_completed'] ? '#cbd5e1' : ($activeTransaction['is_received_here'] ? '#bbf7d0' : '#bfdbfe') }}; border-radius: 12px; padding: 18px; margin-bottom: 20px; animation: modalPop 0.15s ease;">
                            
                            <!-- Header Status Line -->
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Control Number</span>
                                    <h4 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 2px 0 0 0;">{{ $activeTransaction['control_number'] }}</h4>
                                </div>

                                <div>
                                    @if($activeTransaction['is_completed'])
                                        <span style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                            ✅ Completed
                                        </span>
                                    @elseif($activeTransaction['is_received_here'])
                                        <span style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                            📥 Received at Office (Ready to Forward)
                                        </span>
                                    @else
                                        <span style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                                            ⏳ Incoming (Ready to Receive)
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <!-- Completed Transaction Alert -->
                            @if($activeTransaction['is_completed'])
                                <div style="background: #fff7ed; border: 1px solid #fed7aa; color: #c2410c; padding: 10px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; margin-bottom: 14px;">
                                    ℹ️ This transaction has already been completed. No further action needed.
                                </div>
                            @endif

                            <!-- Detail Specs Grid -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; font-size: 12.5px; color: #334155; margin-bottom: 16px;">
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
                                                <div style="font-weight: 700; color: #1e40af; margin-bottom: 4px;">
                                                    ↺ Resubmission Route for Revised Document
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

                                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                            <div style="flex: 1; min-width: 180px;">
                                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">ACTION NEEDED FOR FORWARDING</label>
                                                <select wire:model="actionNeeded" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #ffffff;">
                                                    @foreach($actionOptions as $opt)
                                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div style="flex: 2; min-width: 220px;">
                                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">REMARKS / NOTES (OPTIONAL)</label>
                                                <input type="text" wire:model="notes" placeholder="Enter forwarding remarks..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px;">
                                            </div>
                                        </div>
                                    @endif

                                    <div style="display: flex; justify-content: flex-end; margin-top: 6px;">
                                        <button 
                                            type="button" 
                                            wire:click="processScanAction" 
                                            style="padding: 10px 24px; background: {{ $activeTransaction['is_received_here'] ? '#16a34a' : '#2563eb' }}; color: #ffffff; border: none; border-radius: 8px; font-weight: 700; font-size: 13.5px; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,0.1);"
                                        >
                                            {{ $activeTransaction['is_received_here'] ? '📤 Forward Transaction' : '📥 Receive Transaction' }}
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
                                    <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; background: #f8fafc; padding: 6px 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 700; color: #2563eb;">{{ $rs['control_number'] }}</span>
                                            <span style="color: #64748b;">{{ $rs['subject'] }}</span>
                                        </div>
                                        <span style="color: #94a3b8; font-weight: 500;">{{ $rs['scanned_at'] }}</span>
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
                        if (placeholder) placeholder.innerHTML = '⚠️ Camera Access Disabled / Unavailable';
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

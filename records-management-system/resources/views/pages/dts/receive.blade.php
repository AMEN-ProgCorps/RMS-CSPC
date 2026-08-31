<?php
/**
 * Document Tracking System - Receive Transactions Component
 * 
 * Provides live webcam QR scanning, barcode gun input, and manual control number lookup
 * to receive and forward transactions seamlessly.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Receive Transactions')] class extends Component {
    /** @var string The scanned barcode or manual input code */
    public string $scannedCode = '';

    /** @var array|null Loaded transaction record details */
    public ?array $activeTransaction = null;

    /** @var string Target forward action option */
    public string $actionNeeded = '';

    /** @var string Custom notes for the forwarding step */
    public string $notes = '';

    /** @var string UI status alerts */
    public string $successMessage = '';
    public string $errorMessage = '';

    /** @var array Recent scans logged in session */
    public array $recentScans = [];

    /**
     * Component mount lifecycle hook.
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_user_received) {
            abort(403, 'Unauthorized. You do not have permission to receive or forward transactions.');
        }

        $this->actionNeeded = DB::table('dts_action_options')->orderBy('option_name', 'asc')->value('option_name') ?: 'For Approval';
        $this->recentScans = session()->get('dts_recent_scans', []);
    }

    /**
     * Clear active message alerts.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Search and retrieve transaction records matching the scanned input code.
     */
    public function loadTransaction(): void
    {
        $this->clearMessages();
        $this->activeTransaction = null;

        $rawCode = trim($this->scannedCode);
        if (empty($rawCode)) {
            return;
        }

        // Decode base64 if it is a valid base64 string
        $code = $rawCode;
        $decoded = base64_decode($rawCode, true);
        if ($decoded !== false && ctype_print($decoded)) {
            $code = trim($decoded);
        }

        // Write scan data log to storage/logs/dts_scans.log
        try {
            $logPath = storage_path('logs/dts_scans.log');
            $scanId = 'SCAN-' . strtoupper(Str::random(12));
            $username = auth()->user()?->username ?: 'Unknown';
            $officeName = auth()->user()?->details?->office?->office_name ?: 'Unknown Office';
            $time = now()->toIso8601String();

            $logLine = json_encode([
                'scan_id' => $scanId,
                'scanned_data' => $rawCode,
                'decoded_data' => $code,
                'user' => $username,
                'office' => $officeName,
                'timestamp' => $time,
            ]) . PHP_EOL;

            File::append($logPath, $logLine);
        } catch (\Exception $e) {
            // Silently ignore log write failures to not block UI
        }

        $qrExists = DB::table('dts_qr_code')->where('code_id', $code)->exists();
        if (!$qrExists) {
            $this->errorMessage = 'Invalid QR Code: Only valid, registered QR codes can be processed by the scanner.';
            return;
        }

        // Match raw QR code sequence ID
        $transaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.qr_code', $code)
            ->select(
                'dt.transaction_id',
                'dt.trans_type as type',
                'dt.status',
                'dt.sequence',
                'dt.qr_code',
                'dt.current_office',
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
            $this->errorMessage = 'Inactive QR Code: This QR Code is registered in the system but has not been associated with any transaction yet.';
            return;
        }

        $rawStatus = strtolower($transaction->status);
        if ($rawStatus === 'completed') {
            $this->errorMessage = 'That QR code is already finished its transaction.';
            return;
        }

        if ($rawStatus === 'cancelled') {
            $this->errorMessage = 'That QR code transaction has been cancelled.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'Could not resolve your user office profile.';
            return;
        }

        if ($transaction->current_office !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        $isFreeFlow = ($transaction->transaction_flow === 'FLOW-FREE-FLOW' || str_starts_with($transaction->transaction_flow, 'FLOW-FREE-FLOW'));

        // Check if already received at current office
        $currentLog = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
            ->where('transaction_id', $transaction->transaction_id)
            ->where('office_code', $userOfficeCode)
            ->orderBy('id', 'desc')
            ->first();

        $transaction->is_received = $currentLog && ($currentLog->type === 'received' || (!empty($currentLog->date_in) && $currentLog->type !== 'forwarded'));

        // Pre-resolve name of the next destination office in sequence
        $nextOfficeName = 'N/A';
        $nextSequence = null;
        if ($isFreeFlow) {
            $nextOfficeName = 'Free Flow (Broadcast)';
        } else {
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $transaction->transaction_flow)->first();
            if ($flow) {
                $nextSequence = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $transaction->sequence + 1)
                    ->first();
                if ($nextSequence) {
                    $nextCode = $nextSequence->office_code;
                    if ($nextCode === 'ORIGIN') {
                        $nextCode = $transaction->originated_from;
                    } elseif ($nextCode === '[H]') {
                        $originOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $transaction->originated_from)->first();
                        if ($originOffice && $originOffice->cluster) {
                            $cluster = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')->where('cluster_code', $originOffice->cluster)->first();
                            if ($cluster && $cluster->cluster_head) {
                                $nextCode = $cluster->cluster_head;
                            }
                        }
                    }
                    $nextOfficeName = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $nextCode)->value('office_name') ?: $nextCode;
                } else {
                    $nextOfficeName = 'End of Flow (Complete)';
                }
            }
        }

        $transaction->is_last_step = $isFreeFlow || empty($nextSequence);
        $transaction->next_office_name = $nextOfficeName;
        $this->activeTransaction = (array) $transaction;
    }

    /**
     * Mark the active transaction as RECEIVED (resets diff_in_minutes timer).
     */
    public function receiveTransaction(): void
    {
        $this->clearMessages();
        if (!$this->activeTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if ($this->activeTransaction['current_office'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $log = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                    ->where('transaction_id', $this->activeTransaction['transaction_id'])
                    ->where('office_code', $userOfficeCode)
                    ->orderBy('id', 'desc')
                    ->first();

                if ($log) {
                    DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                        ->where('id', $log->id)
                        ->update([
                            'type' => 'received',
                            'date_in' => now(),
                            'performed_by' => auth()->id(),
                        ]);
                } else {
                    DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $this->activeTransaction['transaction_id'],
                        'office_code' => $userOfficeCode,
                        'type' => 'received',
                        'date_in' => now(),
                        'date_out' => null,
                        'notes' => 'Received via Scanner/Input',
                        'performed_by' => auth()->id(),
                    ]);
                }

                $flow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $this->activeTransaction['transaction_flow'])
                    ->first();

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

                $userFirstName = auth()->user()?->details?->first_name 
                    ?: DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details')->where('account_id', auth()->id())->value('first_name')
                    ?: auth()->user()?->username 
                    ?: 'User';

                $controlNumber = $this->activeTransaction['control_number'] ?? '';
                $transId = $this->activeTransaction['transaction_id'] ?? '';

                if ($userOfficeCode) {
                    \App\Services\DtsNotificationService::notifyReceived($userOfficeCode, $userFirstName, $controlNumber, $transId);
                }

                $originatedFrom = $this->activeTransaction['originated_from'] ?? null;
                if ($originatedFrom && $originatedFrom !== $userOfficeCode) {
                    \App\Services\DtsNotificationService::notifyHubOfficeReceived($originatedFrom, $userOfficeCode, $controlNumber, $transId);
                }

                $this->successMessage = "Transaction {$this->activeTransaction['control_number']} has been successfully RECEIVED at your office. Timer reset to 0.";
                $this->activeTransaction['is_received'] = true;
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to receive transaction: ' . $e->getMessage();
        }
    }

    /**
     * Run routing forward transition for the loaded transaction.
     */
    public function proceedTransaction(): void
    {
        $this->clearMessages();
        if (!$this->activeTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if ($this->activeTransaction['current_office'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        try {
            DB::transaction(function () use ($userOfficeCode) {
                $flow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $this->activeTransaction['transaction_flow'])
                    ->first();

                if (!$flow) {
                    throw new \Exception('Transaction flow not found.');
                }

                // If not yet marked as received, set date_in now
                $currentStep = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                    ->first();

                $dateInTime = ($currentStep && $currentStep->date_in) ? Carbon::parse($currentStep->date_in) : now();

                // Compute time duration
                $dateOut = now();
                $diff = $dateInTime->diff($dateOut);
                $parts = [];
                if ($diff->d > 0) {
                    $parts[] = $diff->d . ' ' . Str::plural('day', $diff->d);
                }
                if ($diff->h > 0) {
                    $parts[] = $diff->h . ' ' . Str::plural('hour', $diff->h);
                }
                if ($diff->i > 0) {
                    $parts[] = $diff->i . ' ' . Str::plural('minute', $diff->i);
                }
                if (empty($parts)) {
                    $parts[] = 'less than a minute';
                }
                $duration = implode(' ', $parts);

                // Update sequence step details - AND flag scanned_id as true
                DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                    ->update([
                        'date_in' => $currentStep->date_in ?? now(),
                        'account_received' => $currentStep->account_received ?? auth()->id(),
                        'date_out' => now(),
                        'account_forwarded' => auth()->id(),
                        'action_needed' => $this->actionNeeded,
                        'note' => $this->notes,
                        'total_time_completed' => $duration,
                        'scanned_id' => true,
                    ]);

                // Update sub_document_tracking_system_logs completion of current step
                DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                    ->where('transaction_id', $this->activeTransaction['transaction_id'])
                    ->where('office_code', $userOfficeCode)
                    ->whereNull('date_out')
                    ->update([
                        'date_out' => now(),
                        'type' => 'received',
                        'notes' => $this->notes,
                        'performed_by' => auth()->id(),
                    ]);

                // Check for next office step in sequence
                $nextSequence = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->activeTransaction['sequence'] + 1)
                    ->first();

                if ($nextSequence) {
                    // Resolve actual destination office code if special symbol
                    $destOfficeCode = $nextSequence->office_code;
                    if ($destOfficeCode === 'ORIGIN') {
                        $destOfficeCode = $this->activeTransaction['originated_from'];
                    } elseif ($destOfficeCode === '[H]') {
                        $originOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $this->activeTransaction['originated_from'])->first();
                        if ($originOffice && $originOffice->cluster) {
                            $cluster = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')->where('cluster_code', $originOffice->cluster)->first();
                            if ($cluster && $cluster->cluster_head) {
                                $destOfficeCode = $cluster->cluster_head;
                            }
                        }
                    }

                    // Forward to next office
                    DB::table('dts_transactions')
                        ->where('transaction_id', $this->activeTransaction['transaction_id'])
                        ->update([
                            'current_office' => $destOfficeCode,
                            'sequence' => $this->activeTransaction['sequence'] + 1,
                            'status' => 'ongoing',
                        ]);

                    // Create log for next office (date_in remains null until received at next office)
                    DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $this->activeTransaction['transaction_id'],
                        'office_code' => $destOfficeCode,
                        'type' => 'forwarded',
                        'date_in' => null,
                        'date_out' => null,
                        'notes' => 'Forwarded from ' . (auth()->user()?->details?->office?->office_name ?: $userOfficeCode),
                        'performed_by' => auth()->id(),
                    ]);

                    $userFirstName = auth()->user()?->details?->first_name 
                        ?: DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details')->where('account_id', auth()->id())->value('first_name')
                        ?: auth()->user()?->username 
                        ?: 'User';

                    $controlNumber = $this->activeTransaction['control_number'] ?? '';
                    $transId = $this->activeTransaction['transaction_id'] ?? '';

                    if ($userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyForwarded($userOfficeCode, $userFirstName, $controlNumber, $transId);
                    }

                    if (!empty($destOfficeCode)) {
                        \App\Services\DtsNotificationService::notifyWaitingToBeReceived($destOfficeCode, $controlNumber, $transId);
                    }

                    $this->successMessage = "Transaction {$this->activeTransaction['control_number']} successfully forwarded to {$this->activeTransaction['next_office_name']}.";
                } else {
                    // Final step sequence completion (keep in Current Transactions for document upload)
                    $this->successMessage = "Transaction {$this->activeTransaction['control_number']} final routing step recorded. Please go to Current Transactions to upload circulated document and complete the transaction.";
                }

                // Record recent scan item in session
                $scanRecord = [
                    'control_number' => $this->activeTransaction['control_number'],
                    'subject' => $this->activeTransaction['subject'],
                    'status' => $nextSequence ? 'Forwarded' : 'Completed',
                    'timestamp' => now()->format('h:i:s A'),
                ];

                array_unshift($this->recentScans, $scanRecord);
                $this->recentScans = array_slice($this->recentScans, 0, 10);
                session()->put('dts_recent_scans', $this->recentScans);

                // Reset state
                $this->scannedCode = '';
                $this->activeTransaction = null;
                $this->notes = '';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to process transaction: ' . $e->getMessage();
        }
    }
}; ?>

@push('styles')
    <style>
        @keyframes scanLaser {
            0% { top: 0; opacity: 0.8; }
            50% { top: calc(100% - 2px); opacity: 1; }
            100% { top: 0; opacity: 0.8; }
        }
        @media (max-width: 767px) {
            body {
                top: 0 !important;
                left: 0 !important;
                position: fixed !important;
                z-index: 99999 !important;
                background: #111827 !important;
                overflow-y: auto !important;
            }
            .scanner-page-wrapper {
                padding: 12px !important;
                min-height: 100vh !important;
            }
        }
    </style>
@endpush

<div class="scanner-page-wrapper" style="padding: 24px; min-height: calc(100vh - 120px);">
    <!-- Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="{{ route('dts') }}" style="display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; background: #003699; color: white; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: bold; cursor: pointer; transition: all 0.2s;" title="Back to Current Transactions">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <h1 style="font-size: 20px; font-weight: 700; color: #1e293b; margin: 0; font-family: Outfit, sans-serif;">Receive Transactions Console</h1>
        </div>
        <span style="font-size: 12px; font-weight: 600; padding: 6px 12px; background: #eff6ff; color: #1e40af; border-radius: 20px; border: 1px solid #bfdbfe;">
            <i class="fa-solid fa-qrcode" style="margin-right: 4px;"></i> QR Code Subsystem
        </span>
    </div>

    <!-- Main Content Layout Grid -->
    <div style="display: flex; flex-direction: column; gap: 20px; font-family: Roboto, sans-serif;">
        <!-- Alert Messages -->
        @if ($successMessage)
            <div style="background: #f0fdf4; border: 1.5px solid #22c55e; color: #15803d; padding: 14px 18px; border-radius: 10px; font-weight: 500; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="font-size: 16px; color: #16a34a;"></i>
                {{ $successMessage }}
            </div>
        @endif

        @if ($errorMessage)
            <div style="background: #fef2f2; border: 1.5px solid #ef4444; color: #b91c1c; padding: 14px 18px; border-radius: 10px; font-weight: 500; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px; color: #dc2626;"></i>
                {{ $errorMessage }}
            </div>
        @endif

        <!-- Scanner responsive side-by-side panel -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
            <!-- Viewfinder / Camera Card -->
            <div style="background: #111827; border: 1px solid #374151; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2); min-height: 400px;">
                <!-- Camera Header -->
                <div style="padding: 12px 16px; background: #1f2937; border-bottom: 1px solid #374151; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <span style="font-size: 12.5px; font-weight: 600; color: #9ca3af; display: flex; align-items: center; gap: 6px;">
                        <span style="display: inline-block; width: 8px; height: 8px; background: #ef4444; border-radius: 50%;" id="camera-status-dot"></span>
                        Camera Stream
                    </span>
                    <select id="camera-select" style="background: #111827; color: #e5e7eb; border: 1px solid #4b5563; border-radius: 6px; padding: 4px 8px; font-size: 11.5px; outline: none; max-width: 180px;">
                        <option value="">Detecting cameras...</option>
                    </select>
                </div>

                <!-- Viewfinder Area -->
                <div style="position: relative; background: #000; flex: 1; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                    <!-- Corners overlays -->
                    <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 10; pointer-events: none; padding: 40px; display: flex; align-items: center; justify-content: center;">
                        <div id="viewfinder-box" style="width: 200px; height: 200px; border: 2px dashed rgba(255,255,255,0.2); position: relative; border-radius: 12px;">
                            <div style="position: absolute; top: -2px; left: -2px; width: 24px; height: 24px; border-top: 4px solid #3b82f6; border-left: 4px solid #3b82f6; border-top-left-radius: 8px;"></div>
                            <div style="position: absolute; top: -2px; right: -2px; width: 24px; height: 24px; border-top: 4px solid #3b82f6; border-right: 4px solid #3b82f6; border-top-right-radius: 8px;"></div>
                            <div style="position: absolute; bottom: -2px; left: -2px; width: 24px; height: 24px; border-bottom: 4px solid #3b82f6; border-left: 4px solid #3b82f6; border-bottom-left-radius: 8px;"></div>
                            <div style="position: absolute; bottom: -2px; right: -2px; width: 24px; height: 24px; border-bottom: 4px solid #3b82f6; border-right: 4px solid #3b82f6; border-bottom-right-radius: 8px;"></div>
                            <div id="scanning-laser" style="position: absolute; top: 0; left: 0; width: 100%; height: 2px; background: linear-gradient(to right, transparent, #3b82f6, transparent); box-shadow: 0 0 8px #3b82f6; animation: scanLaser 2s linear infinite; display: none;"></div>
                        </div>
                    </div>

                    <!-- Video Feed Element -->
                    <div id="scanner-preview" style="width: 100%; height: 100%;"></div>

                    <!-- Offline Fallback -->
                    <div id="camera-fallback" style="position: absolute; text-align: center; color: #4b5563; z-index: 5;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="margin: 0 auto 12px; color: #374151;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        <span style="font-size: 13px; font-weight: 500;">Camera scan feed not active</span>
                    </div>
                </div>

                <!-- Control Button -->
                <div style="padding: 16px; background: #1f2937; border-top: 1px solid #374151;">
                    <button type="button" id="toggle-camera-btn" style="width: 100%; padding: 12px; background: #374151; color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 13.5px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fa-solid fa-camera"></i> Start Camera Scan
                    </button>
                </div>
            </div>

            <!-- Details Form / Input Panel -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Barcode Gun Entry Card -->
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #1e293b; font-family: Outfit, sans-serif;">Barcode Gun / Text Scanner Input</h3>
                    <form wire:submit.prevent="loadTransaction" style="display: flex; gap: 8px;">
                        <div style="position: relative; flex: 1;">
                           <input 
                               type="text" 
                               id="scanner-text-input"
                               wire:model.defer="scannedCode"
                               placeholder="Scan or type QR code ID / Control #" 
                               style="width: 100%; background: #f8fafc; color: #1e293b; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px 12px 10px 36px; font-size: 13px; outline: none; transition: all 0.2s;"
                               onfocus="this.style.borderColor='#003699'; this.style.backgroundColor='#fff';"
                               onblur="this.style.borderColor='#cbd5e1'; this.style.backgroundColor='#f8fafc';"
                           />
                           <i class="fa-solid fa-barcode" style="position: absolute; left: 12px; top: 13px; color: #94a3b8; font-size: 14px;"></i>
                        </div>
                        <button type="submit" style="background: #003699; color: white; border: none; border-radius: 8px; padding: 0 20px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.2s;">
                            Load
                        </button>
                    </form>
                </div>

                <!-- Loaded transaction display form -->
                @if ($activeTransaction)
                    <div style="background: #fff; border: 1.5px solid {{ $activeTransaction['is_received'] ? '#059669' : '#f59e0b' }}; border-radius: 12px; padding: 24px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); display: flex; flex-direction: column; gap: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: {{ $activeTransaction['is_received'] ? '#059669' : '#d97706' }}; letter-spacing: 0.05em;">
                                    {{ $activeTransaction['is_received'] ? 'Status: Received (In Process)' : 'Status: Pending Receive' }}
                                </span>
                                <span style="font-size: 11px; font-weight: 600; padding: 2px 8px; background: #eff6ff; color: #2563eb; border-radius: 12px; text-transform: capitalize;">{{ $activeTransaction['type'] }}</span>
                            </div>
                            <h2 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b; line-height: 1.4;">{{ $activeTransaction['subject'] }}</h2>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 12.5px; color: #475569; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; padding: 12px 0;">
                            <div>
                                <strong style="color: #64748b;">Control Number</strong>
                                <div style="color: #1d4ed8; font-weight: 600; margin-top: 2px;">{{ $activeTransaction['control_number'] }}</div>
                            </div>
                            <div>
                                <strong style="color: #64748b;">Originated Office</strong>
                                <div style="color: #334155; margin-top: 2px;">{{ $activeTransaction['originated_office_name'] }}</div>
                            </div>
                            <div>
                                <strong style="color: #64748b;">Requestor Name</strong>
                                <div style="color: #334155; margin-top: 2px;">{{ $activeTransaction['requestor_name'] }}</div>
                            </div>
                            <div>
                                <strong style="color: #64748b;">Next Receiving Office</strong>
                                <div style="color: #059669; font-weight: 600; margin-top: 2px;">{{ $activeTransaction['next_office_name'] }}</div>
                            </div>
                        </div>

                        <!-- Routing Action Buttons -->
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            @if (!$activeTransaction['is_received'])
                                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 10px 14px; color: #92400e; font-size: 12px;">
                                    <i class="fa-solid fa-circle-info" style="margin-right: 6px;"></i> Click <strong>Receive Transaction</strong> to confirm arrival and start the timer.
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="button" wire:click="receiveTransaction" style="flex: 1; padding: 12px; background: #16a34a; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 13.5px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(22,163,74,0.25);">
                                        <i class="fa-solid fa-download"></i> Receive Transaction
                                    </button>
                                    <button type="button" wire:click="proceedTransaction" style="flex: 1; padding: 12px; background: #2563eb; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 13.5px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(37,99,235,0.25);">
                                        <i class="fa-solid fa-paper-plane"></i> Receive & Forward
                                    </button>
                                </div>
                            @else
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">Action Needed:</label>
                                    <select wire:model="actionNeeded" style="width: 100%; background: #f8fafc; color: #1e293b; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px; font-size: 13px; outline: none; cursor: pointer;">
                                        @foreach(DB::table('dts_action_options')->orderBy('option_name', 'asc')->pluck('option_name') as $opt)
                                            <option value="{{ $opt }}">{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">Notes (Optional):</label>
                                    <input type="text" wire:model="notes" placeholder="Type notes for the next stop..." style="width: 100%; background: #f8fafc; color: #1e293b; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 10px; font-size: 13px; outline: none;" />
                                </div>

                                @if (!empty($activeTransaction['is_last_step']))
                                    <button type="button" wire:click="proceedTransaction" style="width: 100%; padding: 12px; background: #16a34a; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 8px; box-shadow: 0 4px 6px -1px rgba(22,163,74,0.25);">
                                        <i class="fa-solid fa-circle-check"></i> Complete Sequence (Finish Final Step)
                                    </button>
                                @else
                                    <button type="button" wire:click="proceedTransaction" style="width: 100%; padding: 12px; background: #059669; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 8px; box-shadow: 0 4px 6px -1px rgba(5,150,105,0.25);">
                                        <i class="fa-solid fa-paper-plane"></i> Forward Transaction
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                @else
                    <!-- Empty State Loaded Panel -->
                    <div style="background: #fff; border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 40px; text-align: center; color: #94a3b8; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                        <i class="fa-solid fa-qrcode" style="font-size: 40px; margin-bottom: 12px; color: #cbd5e1;"></i>
                        <p style="font-size: 13px; margin: 0; font-family: Outfit, sans-serif; font-weight: 500;">No active scanned details</p>
                        <p style="font-size: 12px; margin: 4px 0 0 0; color: #94a3b8;">Use webcam feed or barcode gun to load document context</p>
                    </div>
                @endif

                <!-- Recent Scans Card -->
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <h3 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #1e293b; font-family: Outfit, sans-serif;">Recent Scans (Current Session)</h3>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        @forelse ($recentScans as $scan)
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12.5px;">
                                <div style="min-width: 0; flex: 1;">
                                    <span style="color: #0f172a; font-weight: 600; display: block;">{{ $scan['control_number'] }}</span>
                                    <span style="color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; margin-top: 2px;">{{ $scan['subject'] }}</span>
                                </div>
                                <div style="text-align: right; flex-shrink: 0;">
                                    <span style="font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 12px; background: {{ $scan['status'] === 'Completed' ? '#d1fae5' : '#dbeafe' }}; color: {{ $scan['status'] === 'Completed' ? '#065f46' : '#1e40af' }}; display: inline-block;">{{ $scan['status'] }}</span>
                                    <span style="display: block; font-size: 9.5px; color: #94a3b8; margin-top: 4px;">{{ $scan['timestamp'] }}</span>
                                </div>
                            </div>
                        @empty
                            <div style="text-align: center; padding: 16px; color: #94a3b8; font-size: 12px; font-style: italic;">
                                No transactions processed during this session.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const previewId = "scanner-preview";
            const selectElement = document.getElementById("camera-select");
            const toggleButton = document.getElementById("toggle-camera-btn");
            const fallbackElement = document.getElementById("camera-fallback");
            const laserElement = document.getElementById("scanning-laser");
            const statusDot = document.getElementById("camera-status-dot");
            const textInput = document.getElementById("scanner-text-input");

            let html5QrCode = null;
            let cameraActive = false;

            // Focus text input
            if (textInput) {
                textInput.focus();
            }

            // Retrieve local camera inputs
            Html5Qrcode.getCameras().then(devices => {
                if (devices && devices.length) {
                    selectElement.innerHTML = "";
                    devices.forEach(device => {
                        const option = document.createElement("option");
                        option.value = device.id;
                        option.text = device.label || `Camera ${selectElement.options.length + 1}`;
                        selectElement.appendChild(option);
                    });
                } else {
                    selectElement.innerHTML = "<option value=''>No camera found</option>";
                }
            }).catch(err => {
                selectElement.innerHTML = "<option value=''>Access denied</option>";
                console.error("Camera detection error:", err);
            });

            // Toggle camera scanning state
            toggleButton.addEventListener('click', () => {
                if (cameraActive) {
                    stopScanning();
                } else {
                    const cameraId = selectElement.value;
                    if (!cameraId) {
                        alert("No active camera detected.");
                        return;
                    }
                    startScanning(cameraId);
                }
            });

            function startScanning(cameraId) {
                if (!html5QrCode) {
                    html5QrCode = new Html5Qrcode(previewId);
                }

                fallbackElement.style.display = "none";
                laserElement.style.display = "block";
                statusDot.style.background = "#22c55e"; // Active Green
                toggleButton.innerHTML = '<i class="fa-solid fa-stop"></i> Stop Camera Scan';
                toggleButton.style.background = "#dc2626";
                cameraActive = true;

                html5QrCode.start(
                    cameraId,
                    {
                        fps: 10,
                        qrbox: { width: 200, height: 200 }
                    },
                    (decodedText, decodedResult) => {
                        playBeep();
                        
                        @this.set('scannedCode', decodedText);
                        @this.call('loadTransaction');

                        stopScanning();
                    },
                    (errorMessage) => {
                        // safe to ignore scanner logs
                    }
                ).catch(err => {
                    console.error("Error starting scanner:", err);
                    stopScanning();
                });
            }

            function stopScanning() {
                if (html5QrCode && cameraActive) {
                    html5QrCode.stop().then(() => {
                        fallbackElement.style.display = "block";
                        laserElement.style.display = "none";
                        statusDot.style.background = "#ef4444"; // Inactive Red
                        toggleButton.innerHTML = '<i class="fa-solid fa-camera"></i> Start Camera Scan';
                        toggleButton.style.background = "#374151";
                        cameraActive = false;
                    }).catch(err => {
                        console.error("Error stopping scanner:", err);
                    });
                }
            }

            function playBeep() {
                try {
                    const context = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = context.createOscillator();
                    osc.type = "sine";
                    osc.frequency.setValueAtTime(800, context.currentTime);
                    osc.connect(context.destination);
                    osc.start();
                    osc.stop(context.currentTime + 0.12);
                } catch(e) {
                    // AudioContext blocked
                }
            }

            // Cleanup scan process on navigation
            window.addEventListener('beforeunload', () => {
                if (html5QrCode && cameraActive) {
                    html5QrCode.stop();
                }
            });
        });
    </script>
@endpush

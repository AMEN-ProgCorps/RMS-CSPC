<?php
/**
 * Document Tracking System - QR Code Scanner Volt Component
 * 
 * Provides webcam/camera live stream QR scanning using html5-qrcode
 * and a manual input field fallback, with backend forwarding capability.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - QR Code Scanner')] class extends Component {
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

        // Check if the QR code exists in the dts_qr_code directory
        // or matches a valid transaction control number.
        $qrExists = DB::table('dts_qr_code')->where('code_id', $code)->exists();
        $cnExists = DB::table('dts_transaction_details')->where('control_number', $code)->exists();

        if (!$qrExists && !$cnExists) {
            $this->errorMessage = 'Invalid Code: The scanned code is neither a registered QR Code nor a valid Control Number.';
            return;
        }

        // If it is a registered QR code, check if it's assigned to a transaction
        if ($qrExists) {
            $hasTransaction = DB::table('dts_transactions')->where('qr_code', $code)->exists();
            if (!$hasTransaction) {
                $this->errorMessage = 'Inactive QR Code: This QR Code is registered in the system but has not been associated with any transaction yet.';
                return;
            }
        }

        // Match either raw QR code sequence ID or control number
        $transaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
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
                'dtd.transaction_flow',
                'dtd.control_number',
                'dtd.requestor_name',
                'dtd.subject',
                'dtd.classification',
                'dtd.originated_from',
                'originated_office.office_name as originated_office_name',
                'doc.document_name'
            )
            ->first();

        if (!$transaction) {
            $this->errorMessage = 'No transaction found matching the scanned code: ' . $code;
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($transaction->current_office !== $userOfficeCode) {
            $currentOfficeName = DB::table('office')->where('office_code', $transaction->current_office)->value('office_name') ?: $transaction->current_office;
            $this->errorMessage = "Warning: This transaction is currently at '{$currentOfficeName}'. Your office cannot receive or forward it at this stage.";
        }

        // Pre-resolve name of the next destination office in sequence
        $nextOfficeName = 'N/A';
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
                    $originOffice = DB::table('office')->where('office_code', $transaction->originated_from)->first();
                    if ($originOffice && $originOffice->cluster) {
                        $cluster = DB::table('cluster')->where('cluster_code', $originOffice->cluster)->first();
                        if ($cluster && $cluster->cluster_head) {
                            $nextCode = $cluster->cluster_head;
                        }
                    }
                }
                $nextOfficeName = DB::table('office')->where('office_code', $nextCode)->value('office_name') ?: $nextCode;
            } else {
                $nextOfficeName = 'End of Flow (Complete)';
            }
        }

        $transaction->next_office_name = $nextOfficeName;
        $this->activeTransaction = (array) $transaction;
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

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($this->activeTransaction['current_office'] !== $userOfficeCode) {
            $this->errorMessage = 'Unauthorized: This transaction is not currently at your office.';
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

                // Compute time duration
                $duration = null;
                $currentStep = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                    ->first();

                if ($currentStep && $currentStep->date_in) {
                    $dateIn = Carbon::parse($currentStep->date_in);
                    $dateOut = now();
                    $diff = $dateIn->diff($dateOut);
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
                }

                // Update sequence step details - AND flag scanned_id as true
                DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                    ->update([
                        'date_out' => now(),
                        'action_needed' => $this->actionNeeded,
                        'note' => $this->notes,
                        'total_time_completed' => $duration,
                        'scanned_id' => true,
                    ]);

                // Update intermediate logs
                $activeLog = DB::table('sub_document_tracking_system_logs')
                    ->where('transaction_id', $this->activeTransaction['transaction_id'])
                    ->where('office_code', $userOfficeCode)
                    ->whereNull('date_out')
                    ->first();

                if ($activeLog) {
                    DB::table('sub_document_tracking_system_logs')
                        ->where('id', $activeLog->id)
                        ->update([
                            'type' => 'received',
                            'date_out' => now(),
                            'notes' => $this->notes,
                            'performed_by' => auth()->id(),
                        ]);
                }

                // Resolve next step rank in sequence
                $nextSequence = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->activeTransaction['sequence'] + 1)
                    ->first();

                if ($nextSequence) {
                    $nextCode = $nextSequence->office_code;
                    if ($nextCode === 'ORIGIN') {
                        $nextCode = $this->activeTransaction['originated_from'];
                    } elseif ($nextCode === '[H]') {
                        $originOffice = DB::table('office')->where('office_code', $this->activeTransaction['originated_from'])->first();
                        if ($originOffice && $originOffice->cluster) {
                            $cluster = DB::table('cluster')->where('cluster_code', $originOffice->cluster)->first();
                            if ($cluster && $cluster->cluster_head) {
                                $nextCode = $cluster->cluster_head;
                            }
                        }
                    }

                    // Shift transaction to next office
                    DB::table('dts_transactions')
                        ->where('transaction_id', $this->activeTransaction['transaction_id'])
                        ->update([
                            'current_office' => $nextCode,
                            'sequence' => $nextSequence->sequence_ranking,
                            'status' => 'ongoing',
                        ]);

                    // Init next sequence details
                    DB::table('dts_sequence_list')
                        ->where('control_id', $flow->id)
                        ->where('sequence_ranking', $this->activeTransaction['sequence'] + 1)
                        ->update([
                            'date_in' => now(),
                            'date_out' => null,
                            'action_needed' => null,
                            'note' => null,
                            'total_time_completed' => null,
                        ]);

                    // Create pending log for next stop
                    DB::table('sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $this->activeTransaction['transaction_id'],
                        'office_code' => $nextCode,
                        'type' => 'received',
                        'date_in' => now(),
                        'date_out' => null,
                        'notes' => '',
                        'performed_by' => null,
                    ]);

                    $nextOfficeName = DB::table('office')->where('office_code', $nextCode)->value('office_name') ?: $nextCode;
                    $this->successMessage = "Successfully forwarded transaction {$this->activeTransaction['control_number']} to {$nextOfficeName}!";
                } else {
                    // Flow completed
                    DB::table('dts_transactions')
                        ->where('transaction_id', $this->activeTransaction['transaction_id'])
                        ->update([
                            'status' => 'completed',
                        ]);

                    DB::table('sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $this->activeTransaction['transaction_id'],
                        'office_code' => $userOfficeCode,
                        'type' => 'completed',
                        'date_in' => now(),
                        'date_out' => now(),
                        'notes' => 'Completed transaction flow via scanner.',
                        'performed_by' => auth()->id(),
                    ]);

                    $this->successMessage = "Successfully completed transaction {$this->activeTransaction['control_number']}! Flow finished.";
                }

                // Add to recent scans session list
                $scanEntry = [
                    'control_number' => $this->activeTransaction['control_number'],
                    'subject' => $this->activeTransaction['subject'],
                    'status' => $nextSequence ? 'Forwarded' : 'Completed',
                    'timestamp' => now()->format('H:i:s'),
                ];
                array_unshift($this->recentScans, $scanEntry);
                $this->recentScans = array_slice($this->recentScans, 0, 5);
                session()->put('dts_recent_scans', $this->recentScans);
            });

            $this->activeTransaction = null;
            $this->scannedCode = '';
            $this->notes = '';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to proceed transaction: ' . $e->getMessage();
        }
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        @keyframes scanLaser {
            0% { top: 0; }
            50% { top: 100%; }
            100% { top: 0; }
        }
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.5; }
            100% { transform: scale(1); opacity: 1; }
        }
        #scanner-preview video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }
        @media (max-width: 768px) {
            header { display: none !important; }
            .navigation { display: none !important; }
            html, body { overflow: hidden !important; height: 100vh !important; }
            #article-container {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                height: 100vh !important;
                max-height: 100vh !important;
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
    <!-- Back Button Header -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; padding-bottom: 12px; border-bottom: 1px solid #374151;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="{{ route('dts.receive') }}" style="display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; background: #374151; color: white; border-radius: 8px; text-decoration: none; font-size: 16px; font-weight: bold; cursor: pointer; transition: all 0.2s;" title="Back to Receive Page">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <h1 style="font-size: 20px; font-weight: 700; color: #1e293b; margin: 0; font-family: Outfit, sans-serif;">DTS QR Code Scanner Console</h1>
        </div>
        <span style="font-size: 12px; font-weight: 600; padding: 4px 10px; background: #003699; color: #fff; border-radius: 20px;">Scanner Subsystem</span>
    </div>

    <!-- Main Content Layout Grid -->
    <div style="display: flex; flex-direction: column; gap: 20px; font-family: Roboto, sans-serif;">
        <!-- Alert Messages -->
        @if ($successMessage)
            <div style="background: #065f46; border: 1.5px solid #047857; color: #a7f3d0; padding: 14px 18px; border-radius: 10px; font-weight: 500; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="font-size: 16px;"></i>
                {{ $successMessage }}
            </div>
        @endif

        @if ($errorMessage)
            <div style="background: #7f1d1d; border: 1.5px solid #b91c1c; color: #fca5a5; padding: 14px 18px; border-radius: 10px; font-weight: 500; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px;"></i>
                {{ $errorMessage }}
            </div>
        @endif

        <!-- Scanner responsive side-by-side or stack panel -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
            <!-- Viewfinder / Camera Card -->
            <div style="background: #111827; border: 1px solid #374151; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); min-height: 400px;">
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
                    <div style="background: #fff; border: 1.5px solid #3b82f6; border-radius: 12px; padding: 24px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); display: flex; flex-direction: column; gap: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #2563eb; letter-spacing: 0.05em;">Scanned Document Details</span>
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

                        <!-- Forward routing actions -->
                        <div style="display: flex; flex-direction: column; gap: 12px;">
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

                            <button type="button" wire:click="proceedTransaction" style="width: 100%; padding: 12px; background: #059669; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 8px; box-shadow: 0 4px 6px -1px rgba(5,150,105,0.25);">
                                <i class="fa-solid fa-circle-check"></i> Receive & Forward Transaction
                            </button>
                        </div>
                    </div>
                @else
                    <!-- Empty State Loaded Panel -->
                    <div style="background: #fff; border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 40px; text-align: center; color: #94a3b8; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                        <i class="fa-solid fa-qrcode" style="font-size: 40px; margin-bottom: 12px; color: #cbd5e1;"></i>
                        <p style="font-size: 13px; margin: 0; font-family: Outfit, sans-serif; font-weight: 500;">No active scanned details</p>
                        <p style="font-size: 12px; margin: 4px 0 0 0; color: #94a3b8;">Use webcam feed or scan gun to load document context</p>
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

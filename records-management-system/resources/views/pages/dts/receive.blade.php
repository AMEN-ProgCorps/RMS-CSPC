<?php
/**
 * Document Tracking System - Receive Transactions Volt Component
 * 
 * Provides base card listing of transactions requiring forwarding and
 * an interactive modal displaying path logs and action options.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Receive Transactions')] class extends Component {
    /** @var string Active selected transaction ID */
    public string $selectedTransactionId = '';

    /** @var object|null Loaded details of the selected transaction */
    public $selectedTransaction = null;

    /** @var string Bound values for transaction details */
    public string $controlNumber = '';
    public string $fileCode = '';
    public string $particulars = '';
    public string $classification = '';
    public string $actionNeeded = '';

    /** @var string Bound values for active step processing */
    public string $activeAction = 'forwarded';
    public string $activeNotes = '';

    /** @var string Search query for filtering grid list */
    public string $searchQuery = '';

    /** @var bool Flags for individual field editing toggles */
    public bool $editingControl = false;
    public bool $editingFileCode = false;
    public bool $editingParticulars = false;

    /** @var bool Toggle state for full configured path sequence */
    public bool $showFullConfiguredPath = false;

    /**
     * Component mount hook.
     */
    public function mount()
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_user_received) {
            abort(403, 'Unauthorized. You do not have permission to receive transactions.');
        }

        $id = request()->query('id');
        if ($id) {
            $this->openTransaction($id);
        }
    }

    /**
     * Fetch list of transactions waiting at the user's assigned office.
     */
    public function getTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if (!$userOfficeCode) {
            return collect();
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('dts_document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.current_office', $userOfficeCode)
            ->whereIn('dt.status', ['ongoing', 'revision']);

        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $decoded = base64_decode($searchVal, true);
            if ($decoded !== false && preg_match('/^[A-Z0-9-]+$/i', $decoded)) {
                $searchVal = $decoded;
            }
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.requestor_name', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dt.qr_code', 'like', '%' . $searchVal . '%');
            });
        }

        return $query->select(
            'dt.transaction_id',
            'dt.trans_type as type',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dtd.control_number',
            'dtd.requestor_name',
            'dtd.requestor_label',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'originated_office.office_name as originated_office_name',
            'doc.document_name'
        )
        ->get()
        ->map(function ($t) {
            // Find elapsed days from the log where it entered this office
            $latestLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', auth()->user()?->details?->office?->office_code)
                ->orderBy('id', 'desc')
                ->first();

            $dateReceived = $latestLog ? $latestLog->date_in : $t->date_created;
            $t->date_received = $dateReceived;
            $t->elapsed_days = $dateReceived ? max(0, now()->diffInDays(Carbon::parse($dateReceived))) : 0;

            // Previous office (from office)
            $prevLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
                ->where('log.transaction_id', $t->transaction_id)
                ->whereNotNull('log.date_out')
                ->orderBy('log.id', 'desc')
                ->first();
            $t->from_office = $prevLog ? $prevLog->office_name : 'Originated';

            // Find next office in sequence
            $flow = DB::table('dts_transaction_details')
                ->where('id', $t->transaction_id)
                ->first();
            
            if ($flow && $flow->transaction_flow) {
                $flowRow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $flow->transaction_flow)
                    ->first();
                
                if ($flowRow) {
                    $nextSequence = DB::table('dts_sequence_list')
                        ->where('control_id', $flowRow->id)
                        ->where('sequence_ranking', $t->sequence + 1)
                        ->first();
                    
                    if ($nextSequence) {
                        $nextOffice = DB::table('office')
                            ->where('office_code', $nextSequence->office_code)
                            ->first();
                        
                        $t->next_office_name = $nextOffice ? $nextOffice->office_name : 'N/A';
                    } else {
                        $t->next_office_name = 'Completed (End of Flow)';
                    }
                } else {
                    $t->next_office_name = 'N/A';
                }
            } else {
                $t->next_office_name = 'N/A';
            }

            return $t;
        });
    }

    /**
     * Load path steps for the active selected transaction.
     */
    public function getTransactionPathProperty()
    {
        if (!$this->selectedTransactionId) {
            return collect();
        }

        return DB::table('sub_document_tracking_system_logs as log')
            ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
            ->leftJoin('sub_document_tracking_system_logs_types as lt', 'lt.type_id', '=', 'log.type')
            ->where('log.transaction_id', $this->selectedTransactionId)
            ->select('log.*', 'office.office_name', 'lt.description')
            ->orderBy('log.id', 'asc')
            ->get()
            ->map(function ($step) {
                $step->is_active_step = ($step->office_code === auth()->user()?->details?->office?->office_code && is_null($step->date_out));
                return $step;
            });
    }

    public function toggleFullConfiguredPath(): void
    {
        $this->showFullConfiguredPath = !$this->showFullConfiguredPath;
    }

    public function getFullFlowPathProperty()
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return collect();
        }
        $flowCode = $this->selectedTransaction->transaction_flow;
        $flow = DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
        if (!$flow) {
            return collect();
        }

        $originOfficeCode = $this->selectedTransaction->originated_from;
        $originOffice = DB::table('office')->where('office_code', $originOfficeCode)->first();
        $originOfficeName = $originOffice ? $originOffice->office_name : 'Originated Office';
        
        $clusterHeadCode = $originOfficeCode;
        $clusterHeadName = $originOfficeName;
        if ($originOffice && $originOffice->cluster) {
            $cluster = DB::table('cluster')->where('cluster_code', $originOffice->cluster)->first();
            if ($cluster && $cluster->cluster_head) {
                $clusterHeadCode = $cluster->cluster_head;
                $headOffice = DB::table('office')->where('office_code', $cluster->cluster_head)->first();
                if ($headOffice) {
                    $clusterHeadName = $headOffice->office_name;
                }
            }
        }

        return DB::table('dts_sequence_list as seq')
            ->join('office', 'office.office_code', '=', 'seq.office_code')
            ->where('seq.control_id', $flow->id)
            ->select('seq.sequence_ranking', 'office.office_name', 'seq.office_code', 'seq.date_in', 'seq.date_out', 'seq.action_needed', 'seq.note', 'seq.total_time_completed')
            ->orderBy('seq.sequence_ranking', 'asc')
            ->get()
            ->map(function ($step) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName) {
                if ($step->office_code === 'ORIGIN') {
                    $step->office_code = $originOfficeCode;
                    $step->office_name = $originOfficeName;
                } elseif ($step->office_code === '[H]') {
                    $step->office_code = $clusterHeadCode;
                    $step->office_name = $clusterHeadName;
                }

                $step->is_active_step = (
                    $step->office_code === auth()->user()?->details?->office?->office_code
                    && $step->office_code === $this->selectedTransaction->current_office
                    && $step->sequence_ranking === $this->selectedTransaction->sequence
                    && in_array($this->selectedTransaction->status, ['ongoing', 'revision'])
                    && !is_null($step->date_in)
                );
                $step->description = $step->note ?: 'Pending office flow step.';
                $step->type = $step->action_needed ?: 'pending';
                $step->notes = $step->note ?: '';
                return $step;
            });
    }

    public function getVisiblePathProperty()
    {
        return $this->fullFlowPath;
    }

    /**
     * Select a transaction and initialize bounds.
     */
    public function openTransaction(string $id): void
    {
        $this->selectedTransactionId = $id;
        $this->loadSelectedTransaction();
    }

    /**
     * Close the modal popup.
     */
    public function closeTransaction(): void
    {
        $this->selectedTransactionId = '';
        $this->selectedTransaction = null;
        $this->editingControl = false;
        $this->editingFileCode = false;
        $this->editingParticulars = false;
        $this->showFullConfiguredPath = false;
    }

    /**
     * Load transaction attributes and ensure logs sequence matches.
     */
    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->select('dt.*', 'dtd.*')
            ->first();
        
        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number ?? '';
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?? '';
            $this->particulars = $this->selectedTransaction->subject ?? '';
            $this->classification = $this->selectedTransaction->classification ?? '';
            $this->actionNeeded = $this->selectedTransaction->action_needed ?? '';
            
            // Ensure proper logs sequence exists
            $logsCount = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $this->selectedTransactionId)
                ->count();
            
            if ($logsCount === 0) {
                // Seed baseline Created step
                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $this->selectedTransactionId,
                    'office_code' => $this->selectedTransaction->originated_from,
                    'type' => 'created',
                    'date_in' => $this->selectedTransaction->date_created,
                    'date_out' => $this->selectedTransaction->date_created,
                    'notes' => 'Created transaction',
                    'performed_by' => $this->selectedTransaction->created_by,
                ]);

                // Create a current pending receipt step if the current office differs
                if ($this->selectedTransaction->current_office !== $this->selectedTransaction->originated_from) {
                    DB::table('sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $this->selectedTransactionId,
                        'office_code' => $this->selectedTransaction->current_office,
                        'type' => 'received',
                        'date_in' => $this->selectedTransaction->date_created,
                        'date_out' => null,
                        'notes' => '',
                        'performed_by' => null,
                    ]);
                }
            } else {
                // Add active log entry for the current office if none has been registered
                $activeLogExists = DB::table('sub_document_tracking_system_logs')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->where('office_code', $this->selectedTransaction->current_office)
                    ->whereNull('date_out')
                    ->exists();
                
                if (!$activeLogExists) {
                    DB::table('sub_document_tracking_system_logs')->insert([
                        'transaction_id' => $this->selectedTransactionId,
                        'office_code' => $this->selectedTransaction->current_office,
                        'type' => 'received',
                        'date_in' => now(),
                        'date_out' => null,
                        'notes' => '',
                        'performed_by' => null,
                    ]);
                }
            }

            // Retrieve active logs for user's office
            $activeLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $this->selectedTransactionId)
                ->where('office_code', auth()->user()?->details?->office?->office_code)
                ->whereNull('date_out')
                ->first();
            
            if ($activeLog) {
                $this->activeAction = DB::table('dts_action_options')->orderBy('option_name', 'asc')->value('option_name') ?: 'For Approval';
                $this->activeNotes = $activeLog->notes ?? '';
            } else {
                $this->activeAction = DB::table('dts_action_options')->orderBy('option_name', 'asc')->value('option_name') ?: 'For Approval';
                $this->activeNotes = '';
            }
        }
    }

    /**
     * Process forwarding or revision on selecting the COMPLETED action.
     */
    public function completeTransaction(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if (!$userOfficeCode) {
            return;
        }

        $flow = DB::table('dts_transaction_flow')
            ->where('flow_code', $this->selectedTransaction->transaction_flow)
            ->first();

        if (!$flow) {
            return;
        }

        // Calculate time duration
        $duration = null;
        $currentStep = DB::table('dts_sequence_list')
            ->where('control_id', $flow->id)
            ->where('sequence_ranking', $this->selectedTransaction->sequence)
            ->first();
        if ($currentStep && $currentStep->date_in) {
            $dateIn = \Carbon\Carbon::parse($currentStep->date_in);
            $dateOut = now();
            $diff = $dateIn->diff($dateOut);
            $parts = [];
            if ($diff->d > 0) {
                $parts[] = $diff->d . ' ' . \Illuminate\Support\Str::plural('day', $diff->d);
            }
            if ($diff->h > 0) {
                $parts[] = $diff->h . ' ' . \Illuminate\Support\Str::plural('hour', $diff->h);
            }
            if ($diff->i > 0) {
                $parts[] = $diff->i . ' ' . \Illuminate\Support\Str::plural('minute', $diff->i);
            }
            if (empty($parts)) {
                $parts[] = 'less than a minute';
            }
            $duration = implode(' ', $parts);
        }

        // Update current step in sequence list
        DB::table('dts_sequence_list')
            ->where('control_id', $flow->id)
            ->where('sequence_ranking', $this->selectedTransaction->sequence)
            ->update([
                'date_out' => now(),
                'action_needed' => $this->activeAction,
                'note' => $this->activeNotes,
                'total_time_completed' => $duration,
            ]);

        if ($this->activeAction === 'For Revision') {
            // Returned for Revision
            DB::table('dts_transactions')
                ->where('transaction_id', $this->selectedTransactionId)
                ->update([
                    'current_office' => $this->selectedTransaction->originated_from,
                    'sequence' => 1,
                    'status' => 'revision',
                ]);

            // Reset subsequent steps and set first step as active again
            DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->where('sequence_ranking', '>', 1)
                ->update([
                    'date_in' => null,
                    'date_out' => null,
                    'action_needed' => null,
                    'note' => null,
                    'total_time_completed' => null,
                ]);
            DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->where('sequence_ranking', 1)
                ->update([
                    'date_in' => now(),
                    'date_out' => null,
                    'action_needed' => 'Returned for Revision',
                    'note' => $this->activeNotes,
                    'total_time_completed' => null,
                ]);

            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $this->selectedTransactionId,
                'office_code' => $this->selectedTransaction->originated_from,
                'type' => 'returned',
                'date_in' => now(),
                'date_out' => null,
                'notes' => 'Returned for revision: ' . $this->activeNotes,
                'performed_by' => auth()->id(),
            ]);

        } else {
            // Forwarded/Completed logic
            $nextSequence = DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->where('sequence_ranking', $this->selectedTransaction->sequence + 1)
                ->first();

            // Also update the sub_document_tracking_system_logs completion of current step
            $activeLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $this->selectedTransactionId)
                ->where('office_code', $userOfficeCode)
                ->whereNull('date_out')
                ->first();
            if ($activeLog) {
                DB::table('sub_document_tracking_system_logs')
                    ->where('id', $activeLog->id)
                    ->update([
                        'type' => 'received',
                        'date_out' => now(),
                        'notes' => $this->activeNotes,
                        'performed_by' => auth()->id(),
                    ]);
            }

            if ($nextSequence) {
                // Route to next office
                DB::table('dts_transactions')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->update([
                        'current_office' => $nextSequence->office_code,
                        'sequence' => $nextSequence->sequence_ranking,
                        'status' => 'ongoing',
                    ]);

                // Update next step in sequence list
                DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->selectedTransaction->sequence + 1)
                    ->update([
                        'date_in' => now(),
                        'date_out' => null,
                        'action_needed' => null,
                        'note' => null,
                        'total_time_completed' => null,
                    ]);

                // Create next pending log
                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $this->selectedTransactionId,
                    'office_code' => $nextSequence->office_code,
                    'type' => 'received',
                    'date_in' => now(),
                    'date_out' => null,
                    'notes' => '',
                    'performed_by' => null,
                ]);
            } else {
                // End of Flow reached - mark flow completed
                DB::table('dts_transactions')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->update([
                        'status' => 'completed',
                    ]);

                // Log completion
                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $this->selectedTransactionId,
                    'office_code' => $userOfficeCode,
                    'type' => 'completed',
                    'date_in' => now(),
                    'date_out' => now(),
                    'notes' => 'Completed transaction flow.',
                    'performed_by' => auth()->id(),
                ]);
            }
        }

        if (auth()->user()?->permissions?->is_sadm) {
            DB::table('dts_transaction_details')
                ->where('id', $this->selectedTransactionId)
                ->update([
                    'control_number' => $this->controlNumber,
                    'copy_filled_id' => $this->fileCode ?: null,
                    'subject' => $this->particulars,
                    'classification' => $this->classification ?: null,
                    'action_needed' => $this->actionNeeded ?: null,
                ]);
        }

        $this->closeTransaction();
    }

    /**
     * Completely remove transaction (Creator and non-active/revised/amended/draft states only).
     */
    public function deleteTransaction(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $isCreator = ($this->selectedTransaction->created_by === auth()->id());
        $canDeleteState = in_array($this->selectedTransaction->status, ['revision', 'drafted', 'completed', 'cancelled']) 
                       || !is_null($this->selectedTransaction->append_transaction);

        if (!$isCreator || !$canDeleteState) {
            return;
        }

        if ($this->selectedTransactionId) {
            DB::table('dts_transactions')->where('transaction_id', $this->selectedTransactionId)->delete();
            $this->closeTransaction();
        }
    }

    /**
     * Switch details attributes into edit modes.
     */
    public function startEdit(string $field): void
    {
        if (!auth()->user()?->permissions?->is_sadm) {
            return;
        }
        $this->editingControl = $field === 'control';
        $this->editingFileCode = $field === 'file_code';
        $this->editingParticulars = $field === 'particulars';
    }

    /**
     * Commit individual field modification states.
     */
    public function saveField(string $field): void
    {
        match ($field) {
            'control' => $this->editingControl = false,
            'file_code' => $this->editingFileCode = false,
            'particulars' => $this->editingParticulars = false,
            default => null,
        };
    }
};
?>

@push('styles')
    @vite('resources/css/dts/receive.css')
@endpush

<div class="receive-container" wire:poll.15s>
    <!-- Header Section -->
    <div class="receive-header">
        <h1 class="receive-main-title">Receive Transactions</h1>
        <div class="dts-search-wrap" style="max-width: 320px; margin-bottom: 0;">
            <input
                type="text"
                wire:model.live="searchQuery"
                placeholder="Search control number, requestor..."
                class="dts-search-input"
                style="margin-left: 0; padding-left: 40px;"
            />
            <svg class="dts-search-icon" style="left: 12px;" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 25 25" fill="none">
                <path d="M17.1088 17.1091L21.1345 21.1347M3.01904 11.0706C3.01904 13.2059 3.8673 15.2538 5.37722 16.7637C6.88713 18.2736 8.93501 19.1219 11.0703 19.1219C13.2057 19.1219 15.2536 18.2736 16.7635 16.7637C18.2734 15.2538 19.1217 13.2059 19.1217 11.0706C19.1217 8.93525 18.2734 6.88737 16.7635 5.37746C15.2536 3.86755 13.2057 3.01929 11.0703 3.01929C8.93501 3.01929 6.88713 3.86755 5.37722 5.37746C3.8673 6.88737 3.01904 8.93525 3.01904 11.0706Z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
    </div>

    <!-- Cards Grid -->
    <div class="receive-grid">
        @forelse($this->transactions as $t)
            <div style="background: white; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                <!-- Top Right Info & Icon -->
                <div style="position: absolute; top: 16px; right: 16px; display: flex; align-items: center; gap: 8px; font-size: 11px; color: #6b7280;">
                    <span>{{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->diffForHumans(null, true) . ' ago' : 'N/A' }}</span>
                    @if ($t->status === 'completed')
                        <span style="display: inline-block; width: 14px; height: 14px; background-color: #10b981; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Completed">
                            <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">✔</span>
                        </span>
                    @elseif ($t->classification === 'highly_technical')
                        <span style="display: inline-block; width: 14px; height: 14px; background-color: #ef4444; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Highly Technical">
                            <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                        </span>
                    @else
                        <span style="display: inline-block; width: 14px; height: 14px; background-color: #f59e0b; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Pending Action">
                            <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                        </span>
                    @endif
                </div>

                <!-- Card Body contents -->
                <div style="font-size: 13px; color: #4b5563; line-height: 1.6; margin-top: 12px; font-family: Roboto, sans-serif;">
                    <div style="margin-bottom: 6px; word-break: break-word; overflow-wrap: break-word; white-space: normal;"><strong>Subject:</strong> {{ $t->subject }}</div>
                    <div style="margin-bottom: 6px;"><strong>Unit/College:</strong> {{ $t->originated_office_name }}</div>
                    <div style="margin-bottom: 6px;"><strong>Name of Requestor:</strong> {{ $t->requestor_name }} @if(!empty($t->requestor_label)) <span style="font-size: 12px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</span> @endif</div>
                    <div style="margin-bottom: 6px;"><strong>Control Number:</strong> <span style="font-weight: 600; color: #1e40af;">{{ $t->control_number }}</span></div>
                    <div style="margin-bottom: 14px;"><strong>Type of Document:</strong> {{ $t->document_name ?? ucfirst($t->type) }}</div>

                    <div style="margin-bottom: 6px;"><strong>Receive From:</strong> <span style="color: #ef4444; font-weight: 500;">{{ $t->from_office }}</span></div>
                    <div style="margin-bottom: 14px;"><strong>Receive Date:</strong> {{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</div>

                    <div style="margin-bottom: 14px;"><strong>Next Receiving Office:</strong> {{ $t->next_office_name }}</div>

                    <div style="margin-bottom: 6px;"><strong>Action Needed:</strong> <span style="color: #16a34a; font-weight: 600;">{{ $t->action_needed ?? 'For action' }}</span></div>
                    <div style="margin-bottom: 6px;"><strong>Elapsed Day:</strong> <span style="color: #ef4444; font-style: italic;">{{ $t->elapsed_days }} day(s) </span></div>
                </div>

                <!-- Card Footer receive view action -->
                <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                    <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="receive-view-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        VIEW TRANSACTION
                    </button>
                </div>
            </div>
        @empty
            <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; font-family: Roboto, sans-serif; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); grid-column: 1 / -1;">
                <svg xmlns="http://www.w3.org/2000/svg" style="width: 48px; height: 48px; color: #9ca3af; margin: 0 auto 16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span style="display: block; font-size: 16px; font-weight: 600; color: #4b5563; margin-bottom: 8px;">No Pending Transactions</span>
                <span style="font-size: 13px; color: #9ca3af;">There are no transactions currently at your office that require receiving or forwarding.</span>
            </div>
        @endforelse
    </div>

    <!-- Details Overlay Modal -->
    @if($selectedTransactionId && $selectedTransaction)
        <div class="modal-backdrop" wire:click="closeTransaction">
            <div class="modal-content" wire:click.stop>
                <button type="button" class="modal-close-btn" wire:click="closeTransaction">&times;</button>
                
                <form class="receive-card" method="post" action="#" onsubmit="return false;" style="box-shadow: none;">
                    <h1 class="receive-title">Transaction Details</h1>
                    
                    <div class="receive-fields">
                        <!-- Control Number field -->
                        <div class="receive-field-row">
                            <span class="receive-field-label">Control #:</span>
                            @if ($editingControl)
                                <div style="display: flex; gap: 8px; width: 100%;">
                                    <input type="text" class="receive-field-input" wire:model="controlNumber">
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="saveField('control')">Save</button>
                                    </div>
                                </div>
                            @else
                                <input type="text" class="receive-field-input" value="{{ $controlNumber }}" readonly>
                                @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="startEdit('control')">Update</button>
                                        <span>|</span>
                                        <button type="button" wire:click="startEdit('control')">Edit</button>
                                    </div>
                                @endif
                            @endif
                        </div>

                        <!-- File Code / Copy Furnished field -->
                        <div class="receive-field-row">
                            <span class="receive-field-label">File Code:</span>
                            @if ($editingFileCode)
                                <div style="display: flex; gap: 8px; width: 100%;">
                                    <input type="text" class="receive-field-input" wire:model="fileCode">
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="saveField('file_code')">Save</button>
                                    </div>
                                </div>
                            @else
                                <input type="text" class="receive-field-input" value="{{ $fileCode ?: 'N/A' }}" readonly>
                                @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="startEdit('file_code')">Update</button>
                                        <span>|</span>
                                        <button type="button" wire:click="startEdit('file_code')">Edit</button>
                                    </div>
                                @endif
                            @endif
                        </div>

                        <!-- Particulars / Subject field -->
                        <div class="receive-field-row receive-field-row--particulars">
                            <span class="receive-field-label">Particulars:</span>
                            @if ($editingParticulars)
                                <div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">
                                    <textarea class="receive-field-input" wire:model="particulars" style="min-height: 72px; resize: vertical;"></textarea>
                                    <div class="receive-field-actions" style="justify-content: flex-end;">
                                        <button type="button" wire:click="saveField('particulars')">Save</button>
                                    </div>
                                </div>
                            @else
                                @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                    <div class="receive-particulars-display" wire:click="startEdit('particulars')" style="cursor: pointer; width: 100%;">
                                        {{ $particulars ?: 'Click to add particulars...' }}
                                    </div>
                                @else
                                    <div class="receive-particulars-display" style="width: 100%;">
                                        {{ $particulars ?: 'No particulars provided.' }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>

                    <hr class="receive-divider">

                    <!-- Transaction Path Section -->
                    <h2 class="receive-title" style="font-size: 16px; margin-top: 10px;">Transaction Path</h2>

                    <div class="receive-table-wrap">
                        <table class="receive-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Office</th>
                                    <th>Date In</th>
                                    <th>Date Out</th>
                                    <th>Total Time</th>
                                    <th>Action Need</th>
                                    <th>Notes</th>
                                    <th>Info</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->visiblePath as $index => $step)
                                    <tr>
                                        <td>{{ $step->sequence_ranking ?? ($index + 1) }}</td>
                                        <td class="office-cell">{{ $step->office_name }}</td>
                                        <td>{{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('Y-m-d') : 'N/A' }}</td>
                                        <td>{{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('Y-m-d') : ($step->date_in ? 'Pending' : 'N/A') }}</td>
                                        <td>{{ $step->total_time_completed ?: '-' }}</td>
                                        <td>
                                            @if ($step->is_active_step)
                                                <select wire:model="activeAction" class="receive-row-action-select" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12.5px; font-weight: 500;">
                                                    @foreach(DB::table('dts_action_options')->orderBy('option_name', 'asc')->pluck('option_name') as $opt)
                                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                {{ $step->action_needed ?: ($step->date_in ? 'Ongoing' : 'Pending') }}
                                            @endif
                                        </td>
                                        <td>
                                            @if ($step->is_active_step)
                                                <input type="text" wire:model="activeNotes" class="active-notes-input" placeholder="Type notes here...">
                                            @else
                                                {{ $step->note ?: '-' }}
                                            @endif
                                        </td>
                                        <td>
                                            <button type="button" class="receive-info-btn" title="{{ $step->note ?? ($step->date_in ? 'Active flow step.' : 'Pending office flow step.') }}">
                                                <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px; stroke: currentColor;">
                                                    <circle cx="12" cy="12" r="10" stroke-width="1.5" fill="none"/>
                                                    <circle cx="12" cy="12" r="2" fill="currentColor"/>
                                                    <circle cx="17" cy="12" r="2" fill="currentColor"/>
                                                    <circle cx="7" cy="12" r="2" fill="currentColor"/>
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" style="padding: 24px; color: #888; font-style: italic;">No transaction paths listed.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Popup Action Buttons -->
                    <div class="receive-actions">
                        <!-- VIEW LISTED PATH / VIEW LOGS Toggle -->
                        <button type="button" class="receive-action-btn" wire:click="toggleFullConfiguredPath">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            {{ $showFullConfiguredPath ? 'VIEW LOGS' : 'VIEW LISTED PATH' }}
                        </button>

                        <!-- COMPLETED -->
                        @if ($selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            <button type="button" class="receive-action-btn" wire:click="completeTransaction">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                COMPLETED
                            </button>
                        @endif

                        <!-- EDIT (Save Metadata changes manually without completing) -->
                        @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            <button type="button" class="receive-action-btn" wire:click="completeTransaction">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 3l4 4-7 7H10v-4l7-7z"/>
                                    <path d="M4 20h16"/>
                                </svg>
                                EDIT
                            </button>
                        @endif

                        <!-- DELETE (Creator and non-active/revised/amended/draft states only) -->
                        @php
                            $isCreator = ($selectedTransaction->created_by === auth()->id());
                            $canDeleteState = in_array($selectedTransaction->status, ['revision', 'drafted', 'completed', 'cancelled']) 
                                           || !is_null($selectedTransaction->append_transaction);
                        @endphp
                        @if ($isCreator && $canDeleteState)
                            <button type="button" class="receive-action-btn receive-action-btn--danger" wire:click="deleteTransaction">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    <line x1="10" y1="11" x2="10" y2="17"/>
                                    <line x1="14" y1="11" x2="14" y2="17"/>
                                </svg>
                                DELETE
                            </button>
                        @endif

                        <!-- + ADD CF (Dummy link to edit File Code) -->
                        @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            <button type="button" class="receive-action-btn" wire:click="startEdit('file_code')">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 5v14M5 12h14"/>
                                </svg>
                                + ADD CF
                            </button>
                        @endif

                        <!-- VIEW QRCODE (Modal control number info) -->
                        <button type="button" class="receive-action-btn" onclick="openQrViewModal('{{ $selectedTransaction->qr_code ?? '' }}')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                            VIEW QRCODE
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- View QR Code Modal -->
    <div id="dts-qr-view-modal" class="modal-backdrop" style="display: none; z-index: 10000; align-items: center; justify-content: center;" onclick="closeQrViewModal()">
        <div class="modal-content" style="max-width: 320px; padding: 24px; text-align: center; position: relative; border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 16px;" onclick="event.stopPropagation()">
            <button type="button" class="modal-close-btn" style="position: absolute; top: 12px; right: 16px; font-size: 20px; border: none; background: transparent; cursor: pointer; color: #94a3b8;" onclick="closeQrViewModal()">&times;</button>
            <h3 style="margin: 0; font-family: Roboto, sans-serif; font-size: 16px; font-weight: 700; color: #043899; text-transform: uppercase;">QR Code Scan</h3>
            
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1.5px solid #cbd5e1; display: flex; align-items: center; justify-content: center; width: 182px; height: 182px; box-sizing: border-box; margin: 0 auto;">
                <img id="dts-qr-image" src="" alt="QR Code" style="width: 150px; height: 150px; display: none;">
                <div id="dts-qr-loading" style="font-family: Roboto, sans-serif; font-size: 13px; color: #64748b;">Generating...</div>
            </div>

            <div style="font-family: monospace; font-weight: 700; font-size: 14px; color: #1e293b; word-break: break-all; background: #f1f5f9; padding: 6px 12px; border-radius: 6px; border: 1px solid #e2e8f0; width: 100%; box-sizing: border-box;" id="dts-qr-code-text"></div>
        </div>
    </div>

    <script>
        function openQrViewModal(qrCode) {
            const modal = document.getElementById('dts-qr-view-modal');
            const img = document.getElementById('dts-qr-image');
            const loading = document.getElementById('dts-qr-loading');
            const text = document.getElementById('dts-qr-code-text');

            if (!modal || !img || !loading || !text) return;

            text.innerText = qrCode;
            img.style.display = 'none';
            loading.style.display = 'block';
            modal.style.display = 'flex';

            // Base64 encode the QR Code string
            const qrData = btoa(qrCode);
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(qrData);

            img.src = qrUrl;
            img.onload = function() {
                loading.style.display = 'none';
                img.style.display = 'block';
            };
        }

        function closeQrViewModal() {
            const modal = document.getElementById('dts-qr-view-modal');
            if (modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</div>
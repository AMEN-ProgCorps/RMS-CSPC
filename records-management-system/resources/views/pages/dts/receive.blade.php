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
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Receive Transactions')] class extends Component {
    use WithFileUploads;

    public bool $groupByOffice = false;

    public function toggleGroupByOffice(): void
    {
        $this->groupByOffice = !$this->groupByOffice;
    }
    /** @var string Active selected transaction ID */
    public string $selectedTransactionId = '';

    /** @var object|null Loaded details of the selected transaction */
    public $selectedTransaction = null;

    /** @var string Bound values for transaction details */
    public string $controlNumber = '';
    public string $transactionFlow = '';
    public string $fileCode = '';
    public string $particulars = '';
    public string $classification = '';
    public string $actionNeeded = '';

    /** @var string Bound values for active step processing */
    public string $activeAction = 'forwarded';
    public string $activeNotes = '';

    /** @var string Search query for filtering grid list */
    public string $searchQuery = '';

    /** @var bool Flag for edit mode toggle */
    public bool $editingAll = false;

    /** @var bool Toggle state for full configured path sequence */
    public bool $showFullConfiguredPath = false;
    public bool $showMoreDetails = false;
    public bool $showPassword = false;

    // Reaffirmation & Upload Modal properties
    public bool $showCompletionConfirmModal = false;
    public bool $showUploadModal = false;
    public $uploadedFile = null;
    public string $uploadFileCode = '';
    public string $uploadErrorMessage = '';

    // Show More Details edit properties
    public string $requestorName = '';
    public string $requestorPosition = '';
    public string $emailAccess = '';
    public string $docPassword = '';
    public array $flowOffices = [];
    public string $selectedFlowOfficeToAdd = '';

    // Copy Furnished state properties
    public bool $showCopyFurnished = false;
    public array $cfSelectedOffices = [];
    public string $selectedCfOfficeToAdd = '';

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
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
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
            'dt.current_office',
            'dtd.control_number',
            'dtd.requestor_name',
            'dtd.requestor_label',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
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
            // Check if transaction has been forwarded from originating office
            $firstLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->orderBy('id', 'asc')
                ->first();

            $hasBeenForwarded = ($t->sequence > 1) || ($firstLog && !empty($firstLog->date_out));

            if ($hasBeenForwarded) {
                $startDate = Carbon::parse($firstLog->date_out ?? $firstLog->date_in ?? $t->date_created);
                $t->elapsed_days = (int) abs(now()->diffInDays($startDate)) + 1;
                $t->diff_in_minutes = (int) abs(now()->diffInMinutes($startDate));
            } else {
                $t->elapsed_days = 0;
                $t->diff_in_minutes = 0;
            }

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
        })
        ->sortByDesc('diff_in_minutes')
        ->values();
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

        if ($this->editingAll) {
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

            return collect($this->flowOffices)->map(function ($stepData, $index) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName) {
                $officeCode = $stepData['office_code'];
                $resolvedCode = $officeCode;
                $resolvedName = $officeCode;
                if ($officeCode === 'ORIGIN') {
                    $resolvedCode = $originOfficeCode;
                    $resolvedName = $originOfficeName;
                } elseif ($officeCode === '[H]') {
                    $resolvedCode = $clusterHeadCode;
                    $resolvedName = $clusterHeadName;
                } else {
                    $office = DB::table('office')->where('office_code', $officeCode)->first();
                    if ($office) {
                        $resolvedName = $office->office_name;
                    }
                }

                $step = new \stdClass();
                $step->sequence_ranking = $index + 1;
                $step->office_code = $resolvedCode;
                $step->office_name = $resolvedName;
                $step->date_in = ($index === 0) ? now() : null;
                $step->date_out = null;
                $step->action_needed = ($index === 0) ? 'Created' : null;
                $step->note = ($index === 0) ? 'Created transaction' : null;
                $step->total_time_completed = null;
                $step->is_active_step = false;
                $step->description = $step->note;
                $step->type = $step->action_needed;
                $step->notes = $step->note;
                $step->is_locked = $stepData['is_locked'];
                return $step;
            });
        }

        $flowCode = ($this->editingAll && $this->transactionFlow) ? $this->transactionFlow : $this->selectedTransaction->transaction_flow;
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
        if ($this->showFullConfiguredPath) {
            return $this->fullFlowPath;
        }

        return $this->fullFlowPath->filter(function ($step) {
            return !is_null($step->date_in);
        });
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
        if ($this->editingAll) {
            $this->editingAll = false;
            $this->loadSelectedTransaction();
            return;
        }

        $this->selectedTransactionId = '';
        $this->selectedTransaction = null;
        $this->showFullConfiguredPath = false;
        $this->showMoreDetails = false;
        $this->showPassword = false;
        $this->showCopyFurnished = false;
        $this->cfSelectedOffices = [];
        $this->selectedCfOfficeToAdd = '';
        $this->requestorName = '';
        $this->requestorPosition = '';
        $this->emailAccess = '';
        $this->docPassword = '';
        $this->transactionFlow = '';
        $this->showCompletionConfirmModal = false;
        $this->showUploadModal = false;
        $this->uploadedFile = null;
        $this->uploadErrorMessage = '';
    }

    public function toggleMoreDetails(): void
    {
        $this->showMoreDetails = !$this->showMoreDetails;
    }

    public function updatedTransactionFlow($value): void
    {
        $currentSequenceNum = $this->selectedTransaction->sequence ?? 1;
        // Keep the locked steps
        $lockedSteps = array_filter($this->flowOffices, fn($step) => $step['is_locked']);
        $lockedSteps = array_values($lockedSteps);

        // Fetch the new flow's sequence
        $flow = DB::table('dts_transaction_flow')->where('flow_code', $value)->first();
        $newSteps = [];
        if ($flow) {
            $newSteps = DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->orderBy('sequence_ranking', 'asc')
                ->get()
                ->map(function ($seq) {
                    return [
                        'office_code' => $seq->office_code,
                        'is_locked' => false,
                    ];
                })
                ->toArray();
        }

        // Merge them
        $this->flowOffices = array_merge($lockedSteps, $newSteps);
    }

    public function addFlowOffice(): void
    {
        if ($this->selectedFlowOfficeToAdd) {
            $this->flowOffices[] = [
                'office_code' => $this->selectedFlowOfficeToAdd,
                'is_locked' => false,
            ];
            $this->selectedFlowOfficeToAdd = '';
        }
    }

    public function removeFlowOffice(int $index): void
    {
        if (isset($this->flowOffices[$index]) && !$this->flowOffices[$index]['is_locked']) {
            unset($this->flowOffices[$index]);
            $this->flowOffices = array_values($this->flowOffices);
        }
    }

    public function moveFlowOfficeUp(int $index): void
    {
        if ($index > 0 && !$this->flowOffices[$index]['is_locked'] && !$this->flowOffices[$index - 1]['is_locked']) {
            $temp = $this->flowOffices[$index];
            $this->flowOffices[$index] = $this->flowOffices[$index - 1];
            $this->flowOffices[$index - 1] = $temp;
        }
    }

    public function moveFlowOfficeDown(int $index): void
    {
        if ($index < count($this->flowOffices) - 1 && !$this->flowOffices[$index]['is_locked'] && !$this->flowOffices[$index + 1]['is_locked']) {
            $temp = $this->flowOffices[$index];
            $this->flowOffices[$index] = $this->flowOffices[$index + 1];
            $this->flowOffices[$index + 1] = $temp;
        }
    }

    /**
     * Load transaction attributes and ensure logs sequence matches.
     */
    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_email_access as dea', 'dea.id', '=', 'dtd.email_access')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->select('dt.*', 'dtd.*', 'dea.email as access_email')
            ->first();
        
        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number ?? '';
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?? '';
            $this->particulars = $this->selectedTransaction->subject ?? '';
            $this->classification = $this->selectedTransaction->classification ?? '';
            $this->actionNeeded = $this->selectedTransaction->action_needed ?? '';

            $this->requestorName = $this->selectedTransaction->requestor_name ?: '';
            $this->requestorPosition = $this->selectedTransaction->requestor_label ?: '';
            $this->emailAccess = $this->selectedTransaction->access_email ?: '';
            $this->docPassword = $this->selectedTransaction->document_password ?: '';
            $this->transactionFlow = $this->selectedTransaction->transaction_flow ?: '';
            
            // Load Transaction Path offices for editing
            $this->flowOffices = [];
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->transactionFlow)->first();
            if ($flow) {
                $currentSequenceNum = $this->selectedTransaction->sequence ?? 1;
                $this->flowOffices = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->orderBy('sequence_ranking', 'asc')
                    ->get()
                    ->map(function ($seq) use ($currentSequenceNum) {
                        return [
                            'office_code' => $seq->office_code,
                            'is_locked' => ($seq->sequence_ranking <= $currentSequenceNum) || !is_null($seq->date_in),
                        ];
                    })
                    ->toArray();
            }
            
            // Load Copy Furnished offices
            $this->cfSelectedOffices = [];
            if ($this->selectedTransaction->copy_filled_id) {
                $cfRecord = DB::table('dts_copy_filled_transaction')->where('id', $this->selectedTransaction->copy_filled_id)->first();
                if ($cfRecord) {
                    $this->cfSelectedOffices = DB::table('dts_copy_filled_to_office')
                        ->where('control_id', $cfRecord->assign_offices_id)
                        ->pluck('office_code')
                        ->toArray();
                }
            }
            
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

    public function isLastStep(): bool
    {
        if (!$this->selectedTransaction) {
            return false;
        }
        $flow = DB::table('dts_transaction_flow')
            ->where('flow_code', $this->selectedTransaction->transaction_flow)
            ->first();
        if (!$flow) {
            return false;
        }
        $nextSequence = DB::table('dts_sequence_list')
            ->where('control_id', $flow->id)
            ->where('sequence_ranking', $this->selectedTransaction->sequence + 1)
            ->first();
        return !$nextSequence;
    }

    public function triggerCompletionConfirm(): void
    {
        $this->showCompletionConfirmModal = true;
    }

    public function cancelCompletionConfirm(): void
    {
        $this->showCompletionConfirmModal = false;
    }

    public function confirmAndCompleteTransaction(): void
    {
        $this->showCompletionConfirmModal = false;
        $this->completeTransaction();
    }

    public function triggerUploadFileModal(): void
    {
        $this->uploadFileCode = 'FC-' . strtoupper(Str::random(6));
        $this->uploadErrorMessage = '';
        $this->uploadedFile = null;
        $this->showUploadModal = true;
    }

    public function cancelUploadModal(): void
    {
        $this->showUploadModal = false;
        $this->uploadedFile = null;
        $this->uploadErrorMessage = '';
    }

    public function handleUploadFile(): void
    {
        $this->uploadErrorMessage = '';

        $requiredUpload = DB::table('system_settings')->where('key', 'dts_required_upload_file')->value('value') === 'true';
        if ($requiredUpload && !$this->uploadedFile) {
            $this->uploadErrorMessage = 'Please select a PDF file to upload.';
            return;
        }

        if (!$this->uploadedFile) {
            $this->showUploadModal = false;
            return;
        }

        $extension = strtolower($this->uploadedFile->getClientOriginalExtension());
        if ($extension !== 'pdf') {
            $this->uploadErrorMessage = 'Only PDF files (.pdf) are allowed.';
            return;
        }

        try {
            $docId = 'DOC-' . strtoupper(Str::random(8));
            $originalName = $this->uploadedFile->getClientOriginalName() ?: 'attached_document.pdf';

            $uploadResult = \App\Services\DocumentStorageService::storeUpload(
                $this->uploadedFile,
                'DTS',
                auth()->user(),
                $docId,
                $originalName
            );
            $docPath = $uploadResult['document_path'];

            $assignOfficesId = (DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
            $codeNum = trim($this->uploadFileCode) ?: ('FC-' . strtoupper(Str::random(6)));

            $copyFilledId = DB::table('dts_copy_filled_transaction')->insertGetId([
                'control_num' => $codeNum,
                'total_office' => 0,
                'is_modified' => false,
                'assign_offices_id' => $assignOfficesId,
                'data_created' => now(),
                'date_modified' => now(),
            ]);

            DB::table('dts_transactions')
                ->where('transaction_id', $this->selectedTransactionId)
                ->update(['doc_dir' => $docPath]);

            DB::table('dts_transaction_details')
                ->where('id', $this->selectedTransactionId)
                ->update(['copy_filled_id' => $copyFilledId]);

            $this->loadSelectedTransaction();
            $this->showUploadModal = false;
            $this->uploadedFile = null;
        } catch (\Exception $e) {
            $this->uploadErrorMessage = 'Upload failed: ' . $e->getMessage();
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

        $perms = auth()->user()?->permissions;
        if ($perms && ($perms->is_sadm || $perms->can_dts_modify_transaction)) {
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
     * Toggle all fields into edit mode.
     */
    public function toggleEditAll(): void
    {
        $perms = auth()->user()?->permissions;
        if (!($perms?->is_sadm || $perms?->can_dts_modify_transaction)) {
            return;
        }
        $this->editingAll = !$this->editingAll;
    }

    public function addCfOffice(): void
    {
        if (empty($this->selectedCfOfficeToAdd)) {
            return;
        }
        if (!in_array($this->selectedCfOfficeToAdd, $this->cfSelectedOffices)) {
            $this->cfSelectedOffices[] = $this->selectedCfOfficeToAdd;
        }
        $this->selectedCfOfficeToAdd = '';
    }

    public function removeCfOffice(string $officeCode): void
    {
        $this->cfSelectedOffices = array_values(array_diff($this->cfSelectedOffices, [$officeCode]));
    }

    public function saveMetadataOnly(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && ($perms->is_sadm || $perms->can_dts_modify_transaction)) {
            // Find/create email access ID if provided
            $emailAccessId = null;
            if ($this->emailAccess) {
                $existingEmail = DB::table('dts_email_access')->where('email', $this->emailAccess)->first();
                if (!$existingEmail) {
                    $emailAccessId = DB::table('dts_email_access')->insertGetId([
                        'email' => $this->emailAccess,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $emailAccessId = $existingEmail->id;
                }
            }

            // Sync copy furnished records
            $copyFilledId = $this->selectedTransaction->copy_filled_id;
            if (count($this->cfSelectedOffices) > 0) {
                if ($copyFilledId) {
                    $cfRecord = DB::table('dts_copy_filled_transaction')->where('id', $copyFilledId)->first();
                    if ($cfRecord) {
                        // update transaction
                        DB::table('dts_copy_filled_transaction')
                            ->where('id', $copyFilledId)
                            ->update([
                                'total_office' => count($this->cfSelectedOffices),
                                'date_modified' => now(),
                            ]);
                        // delete and re-insert offices
                        DB::table('dts_copy_filled_to_office')
                            ->where('control_id', $cfRecord->assign_offices_id)
                            ->delete();
                        foreach ($this->cfSelectedOffices as $cfOffice) {
                            DB::table('dts_copy_filled_to_office')->insert([
                                'control_id' => $cfRecord->assign_offices_id,
                                'office_code' => $cfOffice,
                            ]);
                        }
                    }
                } else {
                    // insert new
                    $assignOfficesId = (DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                    $copyFilledId = DB::table('dts_copy_filled_transaction')->insertGetId([
                        'control_num' => $this->controlNumber,
                        'total_office' => count($this->cfSelectedOffices),
                        'assign_offices_id' => $assignOfficesId,
                        'data_created' => now(),
                        'date_modified' => now(),
                    ]);
                    foreach ($this->cfSelectedOffices as $cfOffice) {
                        DB::table('dts_copy_filled_to_office')->insert([
                            'control_id' => $assignOfficesId,
                            'office_code' => $cfOffice,
                        ]);
                    }
                }
            } else {
                // if empty but had cf record, remove it
                if ($copyFilledId) {
                    $cfRecord = DB::table('dts_copy_filled_transaction')->where('id', $copyFilledId)->first();
                    if ($cfRecord) {
                        DB::table('dts_copy_filled_to_office')->where('control_id', $cfRecord->assign_offices_id)->delete();
                        DB::table('dts_copy_filled_transaction')->where('id', $copyFilledId)->delete();
                    }
                    $copyFilledId = null;
                }
            }

            DB::table('dts_transaction_details')
                ->where('id', $this->selectedTransactionId)
                ->update([
                    'control_number' => $this->controlNumber,
                    'copy_filled_id' => $copyFilledId ?: null,
                    'subject' => $this->particulars,
                    'classification' => $this->classification ?: null,
                    'action_needed' => $this->actionNeeded ?: null,
                    'requestor_name' => $this->requestorName ?: null,
                    'requestor_label' => $this->requestorPosition ?: null,
                    'email_access' => $emailAccessId,
                    'document_password' => $this->docPassword ?: null,
                    'transaction_flow' => $this->transactionFlow,
                ]);

            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->transactionFlow)->first();
            if ($flow) {
                $originalSequence = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->orderBy('sequence_ranking', 'asc')
                    ->get()
                    ->keyBy('sequence_ranking')
                    ->toArray();

                if (str_starts_with($flow->flow_code, 'FLOW-CUSTOM-')) {
                    DB::table('dts_sequence_list')->where('control_id', $flow->id)->delete();
                    foreach ($this->flowOffices as $rank => $officeData) {
                        $rank1 = $rank + 1;
                        $officeCode = $officeData['office_code'];
                        
                        if ($officeData['is_locked'] && isset($originalSequence[$rank1])) {
                            $orig = $originalSequence[$rank1];
                            DB::table('dts_sequence_list')->insert([
                                'control_id' => $flow->id,
                                'sequence_ranking' => $rank1,
                                'office_code' => $officeCode,
                                'date_in' => $orig->date_in,
                                'date_out' => $orig->date_out,
                                'action_needed' => $orig->action_needed,
                                'note' => $orig->note,
                                'total_time_completed' => $orig->total_time_completed,
                            ]);
                        } else {
                            DB::table('dts_sequence_list')->insert([
                                'control_id' => $flow->id,
                                'sequence_ranking' => $rank1,
                                'office_code' => $officeCode,
                                'date_in' => null,
                                'date_out' => null,
                                'action_needed' => null,
                                'note' => null,
                                'total_time_completed' => null,
                            ]);
                        }
                    }
                } else {
                    $predefinedOffices = DB::table('dts_sequence_list')
                        ->where('control_id', $flow->id)
                        ->orderBy('sequence_ranking', 'asc')
                        ->pluck('office_code')
                        ->toArray();
                    
                    $currentOfficeCodes = array_map(fn($o) => $o['office_code'], $this->flowOffices);
                    
                    if ($predefinedOffices !== $currentOfficeCodes) {
                        $newFlowCode = 'FLOW-CUSTOM-' . strtoupper(Str::random(10));
                        $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
                        $newFlowId = $maxId + 1;
                        
                        DB::table('dts_transaction_flow')->insert([
                            'flow_code' => $newFlowCode,
                            'flow_name' => 'Flow for ' . $this->controlNumber . ' (' . $newFlowCode . ')',
                            'id' => $newFlowId,
                            'is_active' => 1,
                            'added_by' => auth()->id() ?? 1,
                            'date_added' => now(),
                            'flow_use' => 'none',
                            'flow_for' => 'system',
                            'referenced_flow' => $flow ? ($flow->referenced_flow ?? $flow->flow_name) : null,
                        ]);

                        foreach ($this->flowOffices as $rank => $officeData) {
                            $rank1 = $rank + 1;
                            $officeCode = $officeData['office_code'];
                            
                            if ($officeData['is_locked'] && isset($originalSequence[$rank1])) {
                                $orig = $originalSequence[$rank1];
                                DB::table('dts_sequence_list')->insert([
                                    'control_id' => $newFlowId,
                                    'sequence_ranking' => $rank1,
                                    'office_code' => $officeCode,
                                    'date_in' => $orig->date_in,
                                    'date_out' => $orig->date_out,
                                    'action_needed' => $orig->action_needed,
                                    'note' => $orig->note,
                                    'total_time_completed' => $orig->total_time_completed,
                                ]);
                            } else {
                                DB::table('dts_sequence_list')->insert([
                                    'control_id' => $newFlowId,
                                    'sequence_ranking' => $rank1,
                                    'office_code' => $officeCode,
                                    'date_in' => null,
                                    'date_out' => null,
                                    'action_needed' => null,
                                    'note' => null,
                                    'total_time_completed' => null,
                                ]);
                            }
                        }
                        $this->transactionFlow = $newFlowCode;
                        
                        DB::table('dts_transaction_details')
                            ->where('id', $this->selectedTransactionId)
                            ->update(['transaction_flow' => $newFlowCode]);
                    }
                }
            }
            $this->loadSelectedTransaction();
        }
        $this->editingAll = false;
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    @vite('resources/css/dts/receive.css')
@endpush

<div class="receive-container" wire:poll.15s>
    @php
        $perms = auth()->user()?->permissions;
        $isSadm = $perms?->is_sadm ?? false;
        $canProcess = $isSadm || ($perms?->can_dts_user_received ?? false);
        $canModifyTrans = $isSadm || ($perms?->can_dts_modify_transaction ?? false);
    @endphp
    <!-- Header Section -->
    <div class="receive-header">
        <h1 class="receive-main-title">Receive Transactions</h1>
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="{{ route('dts.scanner') }}" style="display: inline-flex; gap: 8px; align-items: center; justify-content: center; width: auto; height: 38px; background: #003699; padding: 0 14px; color: white; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; font-size: 13px; font-weight: 500;" title="Open QR Scanner">
                Scanner
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
            </a>

            <button type="button" wire:click="toggleGroupByOffice" class="dts-nav-btn" style="border: 1.5px solid {{ $groupByOffice ? '#003699' : '#cbd5e1' }}; background: {{ $groupByOffice ? '#003699' : '#ffffff' }}; color: {{ $groupByOffice ? '#ffffff' : '#334155' }}; font-weight: 600; white-space: nowrap; height: 38px; box-sizing: border-box; display: inline-flex; align-items: center; padding: 0 16px; border-radius: 8px; font-size: 13px; cursor: pointer; outline: none; transition: all 0.2s ease; font-family: 'Inter', sans-serif;">
                <i class="fa-solid fa-folder-tree" style="margin-right: 6px;"></i>
                {{ $groupByOffice ? 'Grouped by Office' : 'Group by Office' }}
            </button>
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
    </div>

    <!-- Cards Grid -->
    <div class="receive-grid">
        @if ($groupByOffice)
            @php
                $groupedTransactions = collect($this->transactions)->groupBy('originated_office_name');
            @endphp
            @forelse ($groupedTransactions as $officeName => $items)
                <div style="grid-column: 1 / -1; margin: 16px 0 8px; display: flex; align-items: center; justify-content: center; gap: 14px; font-weight: 700; color: #475569; letter-spacing: 0.05em; font-size: 12px; font-family: 'Inter', sans-serif;">
                    <div style="flex: 1; height: 1px; background: #cbd5e1;"></div>
                    <div style="text-transform: uppercase; display: flex; align-items: center; gap: 8px; background: #f1f5f9; padding: 6px 16px; border-radius: 99px; border: 1px solid #cbd5e1;">
                        <i class="fa-solid fa-building" style="color: #003699;"></i>
                        <span>{{ $officeName ?: 'Unassigned Office' }}</span>
                    </div>
                    <div style="flex: 1; height: 1px; background: #cbd5e1;"></div>
                </div>
                @php
                    $sortedItems = $items->sortBy(function($t) {
                        if ($t->diff_in_minutes > 60) return 1;
                        if ($t->diff_in_minutes >= 10) return 2;
                        return 3;
                    });
                @endphp
                @foreach ($sortedItems as $t)
                    <div style="background: white; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                        <!-- Top Right Info & Icon -->
                        <div style="position: absolute; top: 16px; right: 16px; display: flex; align-items: center; gap: 8px; font-size: 11px; color: #6b7280;">
                            <span>{{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->diffForHumans(null, true) . ' ago' : 'N/A' }}</span>
                            @if ($t->diff_in_minutes < 10)
                                <span style="display: inline-block; width: 14px; height: 14px; background-color: #10b981; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="New (Less than 10 mins)">
                                    <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                                </span>
                            @elseif ($t->diff_in_minutes <= 60)
                                <span style="display: inline-block; width: 14px; height: 14px; background-color: #f59e0b; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Pending (Over 10 mins)">
                                    <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                                </span>
                            @else
                                <span style="display: inline-block; width: 14px; height: 14px; background-color: #ef4444; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Urgent (Over an hour)">
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

                            <div style="margin-bottom: 14px;"><strong>Current Office:</strong> {{ $t->current_office_name }}</div>

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
                @endforeach
            @empty
                <div style="background: white; border-radius: 12px; padding: 48px; text-align: center; font-family: Roboto, sans-serif; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); grid-column: 1 / -1;">
                    <svg xmlns="http://www.w3.org/2000/svg" style="width: 48px; height: 48px; color: #9ca3af; margin: 0 auto 16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span style="display: block; font-size: 16px; font-weight: 600; color: #4b5563; margin-bottom: 8px;">No Pending Transactions</span>
                    <span style="font-size: 13px; color: #9ca3af;">There are no transactions currently at your office that require receiving or forwarding.</span>
                </div>
            @endforelse
        @else
            @forelse($this->transactions as $t)
                <div style="background: white; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                    <!-- Top Right Info & Icon -->
                    <div style="position: absolute; top: 16px; right: 16px; display: flex; align-items: center; gap: 8px; font-size: 11px; color: #6b7280;">
                        <span>{{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->diffForHumans(null, true) . ' ago' : 'N/A' }}</span>
                        @if ($t->diff_in_minutes < 10)
                            <span style="display: inline-block; width: 14px; height: 14px; background-color: #10b981; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="New (Less than 10 mins)">
                                <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                            </span>
                        @elseif ($t->diff_in_minutes <= 60)
                            <span style="display: inline-block; width: 14px; height: 14px; background-color: #f59e0b; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Pending (Over 10 mins)">
                                <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                            </span>
                        @else
                            <span style="display: inline-block; width: 14px; height: 14px; background-color: #ef4444; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Urgent (Over an hour)">
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

                        <div style="margin-bottom: 14px;"><strong>Current Office:</strong> {{ $t->current_office_name }}</div>

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
        @endif
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
                            @if ($editingAll)
                                <input type="text" class="receive-field-input" wire:model="controlNumber">
                            @else
                                <input type="text" class="receive-field-input" value="{{ $controlNumber }}" readonly>
                            @endif
                        </div>

                        <!-- File Code / Copy Furnished field -->
                        @if ($selectedTransaction && !empty($selectedTransaction->doc_dir) && !empty($fileCode) && $fileCode !== 'N/A')
                            <div class="receive-field-row">
                                <span class="receive-field-label">File Code:</span>
                                @if ($editingAll)
                                    <input type="text" class="receive-field-input" wire:model="fileCode">
                                @else
                                    <input type="text" class="receive-field-input" value="{{ $fileCode }}" readonly>
                                @endif
                            </div>
                        @endif

                        <!-- Transaction Flow field -->
                        <div class="receive-field-row" style="align-items: center;">
                            <span class="receive-field-label">Flow:</span>
                            @if ($editingAll)
                                @php
                                    $availableFlows = DB::table('dts_transaction_flow')
                                        ->where('is_active', true)
                                        ->orWhere('flow_code', $transactionFlow)
                                        ->orderBy('flow_name', 'asc')
                                        ->get();
                                @endphp
                                <select class="receive-field-input" wire:model.live="transactionFlow" style="height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid #cbd5e1; outline: none; background: #fff;">
                                    @foreach ($availableFlows as $f)
                                        <option value="{{ $f->flow_code }}">{{ $f->flow_name }}</option>
                                    @endforeach
                                </select>
                            @else
                                @php
                                    $flowName = DB::table('dts_transaction_flow')->where('flow_code', $transactionFlow)->value('flow_name') ?? $transactionFlow;
                                @endphp
                                <input type="text" class="receive-field-input" value="{{ $flowName }}" readonly style="background-color: #f8fafc; color: #64748b;">
                            @endif
                        </div>

                        <!-- Particulars / Subject field -->
                        <div class="receive-field-row receive-field-row--particulars">
                            <span class="receive-field-label">Particulars:</span>
                            @if ($editingAll)
                                <textarea class="receive-field-input" wire:model="particulars" style="min-height: 72px; resize: vertical;"></textarea>
                            @else
                                <div class="receive-particulars-display" style="width: 100%;">
                                    {{ $particulars ?: 'No particulars provided.' }}
                                </div>
                            @endif
                        </div>

                        <!-- Show More Details Button -->
                        <div style="margin: 14px 0 6px; display: flex; justify-content: flex-start; padding: 0 4px;">
                            <button type="button" wire:click="toggleMoreDetails" style="background: none; border: none; color: #2563eb; font-weight: 600; font-size: 13px; cursor: pointer; padding: 0; outline: none; font-family: 'Inter', sans-serif;">
                                {{ $showMoreDetails ? '▲ Hide Details' : '▼ Show More Details' }}
                            </button>
                        </div>

                        @if ($showMoreDetails)
                            <div style="border-top: 1.5px dashed #e2e8f0; padding-top: 12px; margin-top: 6px;">
                                <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 12px; align-items: center;">
                                    <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Requestor Name:</span>
                                    @if ($editingAll)
                                        <input type="text" class="receive-field-input" wire:model="requestorName">
                                    @else
                                        <input type="text" class="receive-field-input" value="{{ $requestorName ?: 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b;">
                                    @endif
                                </div>
                                <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 12px; align-items: center;">
                                    <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Requestor Position:</span>
                                    @if ($editingAll)
                                        <input type="text" class="receive-field-input" wire:model="requestorPosition">
                                    @else
                                        <input type="text" class="receive-field-input" value="{{ $requestorPosition ?: 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b;">
                                    @endif
                                </div>
                                @if ($selectedTransaction && !empty($selectedTransaction->doc_dir))
                                    <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 12px; align-items: center;">
                                        <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Copy Furnished (CF) ID:</span>
                                        <input type="text" class="receive-field-input" value="{{ $fileCode ?: 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b;">
                                    </div>
                                @endif
                                <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 12px; align-items: center;">
                                    <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Email Access:</span>
                                    @if ($editingAll)
                                        <input type="email" class="receive-field-input" wire:model="emailAccess">
                                    @else
                                        <input type="text" class="receive-field-input" value="{{ $emailAccess ?: 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b;">
                                    @endif
                                </div>
                                <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 4px; align-items: center;">
                                    <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Document Password:</span>
                                    <div style="display: flex; gap: 8px; width: 100%; align-items: center;">
                                        @if ($editingAll)
                                            <input type="text" class="receive-field-input" wire:model="docPassword" style="flex: 1;">
                                        @else
                                            <input type="{{ $showPassword ? 'text' : 'password' }}" class="receive-field-input" value="{{ $docPassword ?: 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b; flex: 1;">
                                            <button type="button" wire:click="$toggle('showPassword')" style="background: #e2e8f0; border: 1px solid #cbd5e1; color: #475569; border-radius: 6px; padding: 8px 12px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; outline: none; transition: all 0.2s ease; height: 38px;">
                                                <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                                {{ $showPassword ? 'Hide' : 'Show' }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <hr class="receive-divider">

                    @if ($showCopyFurnished)
                        <!-- Copy Furnished Section -->
                        <h2 class="receive-title" style="font-size: 16px; margin-top: 10px;">Copy Furnished Offices</h2>
                        
                        @if ($editingAll)
                            <div style="display: flex; gap: 8px; margin-bottom: 12px; align-items: center; max-width: 500px;">
                                <select class="receive-field-input" style="flex: 1; height: 38px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 10px;" wire:model="selectedCfOfficeToAdd">
                                    <option value="">-- Select Office to Add --</option>
                                    @foreach(DB::table('office')->where('is_active', true)->whereNotIn('office_code', ['ORIGIN', '[H]'])->orderBy('office_name', 'asc')->get() as $off)
                                        <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                                    @endforeach
                                </select>
                                <button type="button" class="receive-action-btn" wire:click="addCfOffice" style="padding: 8px 16px; height: 38px; white-space: nowrap; background-color: #2563eb; color: #fff;">
                                    + Add Office
                                </button>
                            </div>
                        @endif

                        <div class="receive-table-wrap">
                            <table class="receive-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>Office Code</th>
                                        <th>Office Name</th>
                                        @if ($editingAll)
                                            <th style="width: 100px;">Actions</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $cfOfficeDetails = collect($cfSelectedOffices)->map(function($code) {
                                            $off = DB::table('office')->where('office_code', $code)->first();
                                            return (object)[
                                                'code' => $code,
                                                'name' => $off ? $off->office_name : 'Unknown Office'
                                            ];
                                        });
                                    @endphp
                                    @forelse ($cfOfficeDetails as $index => $cf)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td style="font-weight: 600; color: #3b82f6;">{{ $cf->code }}</td>
                                            <td class="office-cell">{{ $cf->name }}</td>
                                            @if ($editingAll)
                                                <td>
                                                    <button type="button" class="receive-action-btn receive-action-btn--danger" wire:click="removeCfOffice('{{ $cf->code }}')" style="padding: 4px 8px; font-size: 11px;">
                                                        Remove
                                                    </button>
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $editingAll ? 4 : 3 }}" style="padding: 24px; color: #888; font-style: italic; text-align: center;">No copy furnished offices added to this transaction.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @else
                        <!-- Transaction Path Section -->
                        <h2 class="receive-title" style="font-size: 16px; margin-top: 10px;">Transaction Path</h2>

                        @if ($editingAll)
                            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; max-width: 500px;">
                                @php
                                    $allSystemOffices = DB::table('office')
                                        ->where('is_active', true)
                                        ->whereNotIn('office_code', ['ORIGIN', '[H]'])
                                        ->orderBy('office_name', 'asc')
                                        ->get();
                                @endphp
                                <select class="receive-field-input" wire:model="selectedFlowOfficeToAdd" style="flex: 1; height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid #cbd5e1; outline: none; background: #fff;">
                                    <option value="">Select Office to Add...</option>
                                    <option value="ORIGIN">ORIGIN (Creator Office)</option>
                                    <option value="[H]"> [H] (Cluster Head Office)</option>
                                    @foreach ($allSystemOffices as $o)
                                        <option value="{{ $o->office_code }}">{{ $o->office_name }} ({{ $o->office_code }})</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="addFlowOffice" style="background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;">Add Office</button>
                            </div>
                        @endif

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
                                        @if ($editingAll)
                                            <th>Actions</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($this->visiblePath as $index => $step)
                                        <tr>
                                            <td>{{ $index + 1 }}</td>
                                            <td class="office-cell">
                                                <strong>{{ $step->office_code }}</strong> - {{ $step->office_name }}
                                            </td>
                                            <td>{{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('Y-m-d') : 'N/A' }}</td>
                                            <td>{{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('Y-m-d') : ($step->date_in ? 'Pending' : 'N/A') }}</td>
                                            <td>{{ $step->total_time_completed ?: '-' }}</td>
                                            <td>
                                                @if ($step->is_active_step && $canProcess)
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
                                                @if ($step->is_active_step && $canProcess)
                                                    <input type="text" wire:model="activeNotes" class="active-notes-input" placeholder="Type notes here...">
                                                @else
                                                    {{ $step->note ?: '-' }}
                                                @endif
                                            </td>
                                            @if ($editingAll)
                                                <td style="text-align: center; white-space: nowrap;">
                                                    @if (!$step->is_locked)
                                                        <div style="display: inline-flex; gap: 4px;">
                                                            <button type="button" wire:click="moveFlowOfficeUp({{ $index }})" {{ $index === 0 || $flowOffices[$index - 1]['is_locked'] ? 'disabled' : '' }} style="border: none; background: #f1f5f9; color: {{ $index === 0 || $flowOffices[$index - 1]['is_locked'] ? '#cbd5e1' : '#475569' }}; padding: 6px 10px; border-radius: 4px; font-size: 11px; cursor: {{ $index === 0 || $flowOffices[$index - 1]['is_locked'] ? 'not-allowed' : 'pointer' }}; font-weight: bold;">
                                                                <i class="fa-solid fa-arrow-up"></i>
                                                            </button>
                                                            <button type="button" wire:click="moveFlowOfficeDown({{ $index }})" {{ $index === count($flowOffices) - 1 ? 'disabled' : '' }} style="border: none; background: #f1f5f9; color: {{ $index === count($flowOffices) - 1 ? '#cbd5e1' : '#475569' }}; padding: 6px 10px; border-radius: 4px; font-size: 11px; cursor: {{ $index === count($flowOffices) - 1 ? 'not-allowed' : 'pointer' }}; font-weight: bold;">
                                                                <i class="fa-solid fa-arrow-down"></i>
                                                            </button>
                                                            <button type="button" wire:click="removeFlowOffice({{ $index }})" style="border: none; background: #fee2e2; color: #dc2626; padding: 6px 10px; border-radius: 4px; font-size: 11px; cursor: pointer; font-weight: bold;">
                                                                <i class="fa-solid fa-trash-can"></i>
                                                            </button>
                                                        </div>
                                                    @else
                                                        <span style="font-size: 11px; color: #94a3b8; font-style: italic;"><i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Locked</span>
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" style="padding: 24px; color: #888; font-style: italic;">No transaction paths listed.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <!-- Popup Action Buttons -->
                    <div class="receive-actions">
                        <!-- VIEW LISTED PATH / VIEW LOGS / VIEW COPY FURNISHED Toggle Buttons -->
                        @if (!$showCopyFurnished)
                            <button type="button" class="receive-action-btn" wire:click="toggleFullConfiguredPath">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                {{ $showFullConfiguredPath ? 'VIEW LOGS' : 'VIEW LISTED PATH' }}
                            </button>
                            <button type="button" class="receive-action-btn" wire:click="$set('showCopyFurnished', true)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="9" y1="9" x2="15" y2="9"/>
                                    <line x1="9" y1="13" x2="15" y2="13"/>
                                    <line x1="9" y1="17" x2="15" y2="17"/>
                                </svg>
                                VIEW COPY FURNISHED
                            </button>
                        @else
                            <button type="button" class="receive-action-btn" wire:click="$set('showCopyFurnished', false)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                VIEW LISTED PATH
                            </button>
                        @endif

                        <!-- COMPLETED / REAFFIRM / UPLOAD -->
                        @php
                            $allowManualComplete = \DB::table('system_settings')->where('key', 'dts_allow_manual_completion_button')->value('value') === 'true';
                        @endphp
                        @if ($selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            @if ($this->isLastStep())
                                @if ($allowManualComplete)
                                    <button type="button" class="receive-action-btn" wire:click="triggerCompletionConfirm" style="background-color: #16a34a;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        Complete Transaction
                                    </button>
                                @endif
                                <button type="button" class="receive-action-btn" wire:click="triggerUploadFileModal" style="background-color: #0284c7;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    Upload File
                                </button>
                            @else
                                @if ($allowManualComplete)
                                    <button type="button" class="receive-action-btn" wire:click="completeTransaction">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        COMPLETED
                                    </button>
                                @endif
                            @endif
                        @endif

                        <!-- EDIT / SAVE toggle -->
                        @if ($canModifyTrans)
                            @if ($editingAll)
                                <button type="button" class="receive-action-btn" wire:click="saveMetadataOnly" style="background-color: #16a34a;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                    SAVE
                                </button>
                            @else
                                <button type="button" class="receive-action-btn" wire:click="toggleEditAll">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M17 3l4 4-7 7H10v-4l7-7z"/>
                                        <path d="M4 20h16"/>
                                    </svg>
                                    EDIT
                                </button>
                            @endif
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

    <!-- Complete Transaction Confirmation Popout Modal -->
    @if ($showCompletionConfirmModal)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 16px;">
            <div style="background: #ffffff; width: 100%; max-width: 440px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); overflow: hidden; font-family: 'Inter', sans-serif; animation: fadeIn 0.2s ease-out;">
                
                <!-- Modal Header -->
                <div style="padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">Complete Transaction</h3>
                            <span style="font-size: 12px; color: #64748b;">Final step verification</span>
                        </div>
                    </div>
                    <button type="button" wire:click="cancelCompletionConfirm" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='transparent'">&times;</button>
                </div>

                <!-- Modal Body -->
                <div style="padding: 24px;">
                    <p style="margin: 0 0 12px 0; font-size: 14px; color: #334155; line-height: 1.5;">
                        Are you sure you want to mark this transaction as <strong style="color: #15803d;">Completed</strong>?
                    </p>
                    @if ($selectedTransaction)
                        <div style="background: #f1f5f9; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #475569;">
                            <div style="margin-bottom: 4px;"><strong>Control No:</strong> <span style="font-family: monospace; font-weight: 700; color: #0f172a;">{{ $selectedTransaction->control_number }}</span></div>
                            <div><strong>Subject:</strong> {{ $selectedTransaction->subject }}</div>
                        </div>
                    @endif
                    <p style="margin: 12px 0 0 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                        Once completed, the document tracking flow for this transaction will be closed.
                    </p>
                </div>

                <!-- Modal Footer -->
                <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 12px;">
                    <button type="button" wire:click="cancelCompletionConfirm" style="padding: 9px 18px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #334155; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmAndCompleteTransaction" style="padding: 9px 20px; border-radius: 8px; border: none; background: #16a34a; color: #ffffff; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25); transition: background 0.15s;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">
                        <i class="fa-solid fa-check"></i> Yes, Complete
                    </button>
                </div>

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
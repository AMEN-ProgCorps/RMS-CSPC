<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('DTS - Application Letters')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    public bool $showCompletionConfirmModal = false;
    public bool $showUploadModal = false;
    public $uploadedFile = null;
    public string $uploadFileCode = '';
    public string $uploadErrorMessage = '';

    public string $selectedPriority = 'all';
    public string $selectedStatus = 'all';
    public int $perPage = 10;
    public string $searchQuery = '';
    public string $layoutMode = 'table'; // table or box

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_application) {
            abort(403, 'Unauthorized access to Application Letter transactions list.');
        }
    }

    // Modal state properties
    public string $selectedTransactionId = '';
    public $selectedTransaction = null;
    public string $controlNumber = '';
    public string $fileCode = '';
    public string $particulars = '';
    public string $classification = '';
    public string $actionNeeded = '';
    public string $activeAction = 'forwarded';
    public string $activeNotes = '';
    public bool $editingControl = false;
    public bool $editingFileCode = false;
    public bool $editingParticulars = false;
    public bool $showFullConfiguredPath = false;

    // Selection state
    public array $selectedIds = [];
    public bool $selectAll = false;

    public function updatedSelectAll($value): void
    {
        if ($value) {
            $this->selectedIds = collect($this->transactions->items())
                ->pluck('transaction_id')
                ->map(fn($id) => (string)$id)
                ->toArray();
        } else {
            $this->selectedIds = [];
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function updatingSearchQuery()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatingSelectedPriority()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatingSelectedStatus()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function updatingPerPage()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    public function getTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.trans_type', 'others');

        $canViewAll = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_dts_view_all_list;
        if (!$canViewAll) {
            $query->where('dtd.originated_from', $userOfficeCode);
        } // 'others' trans_type maps to Application Letters

        if ($this->selectedPriority !== 'all') {
            $query->where('dtd.classification', $this->selectedPriority);
        }

        if ($this->selectedStatus !== 'all') {
            $query->where('dt.status', $this->selectedStatus);
        }

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

        $list = $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dtd.control_number',
            'dtd.requestor_name',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            DB::raw("COALESCE(NULLIF(flow.referenced_flow, ''), flow.flow_name) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->paginate($this->perPage);

        // Map remarks, received by, received date/time and destination from sequence flow
        $list->getCollection()->transform(function ($t) {
            $latestLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('account_details as ad', 'ad.account_id', '=', 'log.performed_by')
                ->where('log.transaction_id', $t->transaction_id)
                ->orderBy('log.id', 'desc')
                ->select('log.notes', 'log.date_in', 'ad.first_name', 'ad.last_name')
                ->first();
            $t->remarks = $latestLog ? $latestLog->notes : '-';
            $t->received_by = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';

            // Date/Time Received is when it arrived at current location
            $currentLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $t->current_office)
                ->orderBy('id', 'desc')
                ->first();
            $t->date_time_received = $currentLog ? $currentLog->date_in : $t->date_created;

            // Previous office (from office)
            $prevLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
                ->where('log.transaction_id', $t->transaction_id)
                ->whereNotNull('log.date_out')
                ->orderBy('log.id', 'desc')
                ->first();
            $t->from_office = $prevLog ? $prevLog->office_name : 'Originated';

            // Destination is the next sequence ranking office
            $flow = DB::table('dts_transaction_details')
                ->where('id', $t->transaction_id)
                ->first();
            $t->destination = 'N/A';
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
                        $t->destination = $nextOffice ? $nextOffice->office_name : 'N/A';
                    } else {
                        $t->destination = 'Completed';
                    }
                }
            }
            return $t;
        });

        return $list;
    }

    // Modal methods
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
        if ($this->showFullConfiguredPath) {
            return $this->fullFlowPath;
        }

        return $this->fullFlowPath->filter(function ($step) {
            return !is_null($step->date_in);
        });
    }

    public function openTransaction(string $id): void
    {
        $this->selectedTransactionId = $id;
        $this->loadSelectedTransaction();
    }

    public function closeTransaction(): void
    {
        $this->selectedTransactionId = '';
        $this->selectedTransaction = null;
        $this->editingControl = false;
        $this->editingFileCode = false;
        $this->editingParticulars = false;
        $this->showFullConfiguredPath = false;
    }

    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->first();

        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number;
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?: '';
            $this->particulars = $this->selectedTransaction->subject ?: '';
            $this->classification = $this->selectedTransaction->classification ?: '';
            $this->actionNeeded = $this->selectedTransaction->action_needed ?: '';
            $this->activeAction = DB::table('dts_action_options')->orderBy('option_name', 'asc')->value('option_name') ?: 'For Approval';
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

    public function reopenTransaction(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_modify_transaction && !$perms->can_dts_user_received) {
            return;
        }

        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;

        DB::transaction(function () use ($userOfficeCode) {
            DB::table('dts_transactions')
                ->where('transaction_id', $this->selectedTransactionId)
                ->update([
                    'status' => 'ongoing',
                ]);

            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $this->selectedTransactionId,
                'office_code' => $userOfficeCode ?: $this->selectedTransaction->current_office,
                'type' => 'received',
                'date_in' => now(),
                'date_out' => null,
                'notes' => 'Restored/Re-opened to Current Transactions for review.',
                'performed_by' => auth()->id(),
            ]);
        });

        $this->loadSelectedTransaction();
    }

    public function completeTransaction(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($this->selectedTransaction->current_office !== $userOfficeCode) {
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
            DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $this->selectedTransactionId)
                ->where('office_code', $userOfficeCode)
                ->whereNull('date_out')
                ->update([
                    'date_out' => now(),
                    'type' => 'received',
                    'notes' => $this->activeNotes,
                    'performed_by' => auth()->id(),
                ]);

            if ($nextSequence) {
                // Resolve destination office code if special symbol (ORIGIN or [H])
                $destOfficeCode = $nextSequence->office_code;
                $originatedFrom = $this->selectedTransaction->originated_from;
                if ($destOfficeCode === 'ORIGIN') {
                    $destOfficeCode = $originatedFrom;
                } elseif ($destOfficeCode === '[H]') {
                    $originOffice = DB::table('office')->where('office_code', $originatedFrom)->first();
                    if ($originOffice && $originOffice->cluster) {
                        $cluster = DB::table('cluster')->where('cluster_code', $originatedFrom)->first();
                        if ($cluster && $cluster->cluster_head) {
                            $destOfficeCode = $cluster->cluster_head;
                        }
                    }
                }

                // Route to next office
                DB::table('dts_transactions')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->update([
                        'current_office' => $destOfficeCode,
                        'sequence' => $this->selectedTransaction->sequence + 1,
                        'status' => 'ongoing',
                    ]);

                // Update next step in sequence list (date_in remains null until received at next office)
                DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->selectedTransaction->sequence + 1)
                    ->update([
                        'date_in' => null,
                        'date_out' => null,
                        'action_needed' => null,
                        'note' => null,
                        'total_time_completed' => null,
                    ]);

                // Create next pending log (date_in remains null until received at next office)
                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $this->selectedTransactionId,
                    'office_code' => $destOfficeCode,
                    'type' => 'forwarded',
                    'date_in' => null,
                    'date_out' => null,
                    'notes' => 'Forwarded from ' . auth()->user()?->details?->office?->office_name,
                    'performed_by' => auth()->id(),
                ]);
            } else {
                // Completion of flow
                DB::table('dts_transactions')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->update([
                        'status' => 'completed',
                    ]);

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

    public function startEdit(string $field): void
    {
        if (!auth()->user()?->permissions?->is_sadm) {
            return;
        }
        $this->editingControl = $field === 'control';
        $this->editingFileCode = $field === 'file_code';
        $this->editingParticulars = $field === 'particulars';
    }

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
    @vite(['resources/css/dts/list_transaction.css', 'resources/css/dts/receive.css'])
@endpush

<div class="rms-container">
    <div class="rms-header">
        <h2>Application Letters</h2>
    </div>

    <div class="rms-toolbar">
        <div class="rms-toolbar-top">
            <div class="rms-filters">
                <select class="rms-select" wire:model.live="selectedPriority">
                    <option value="all">All Priority</option>
                    <option value="simple">Simple</option>
                    <option value="complex">Complex</option>
                    <option value="highly_technical">Highly Technical</option>
                </select>
                <select class="rms-select" wire:model.live="selectedStatus">
                    <option value="all">All Status</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                    <option value="revision">Revision</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="drafted">Drafted</option>
                </select>
            </div>
            <div style="display: flex; gap: 16px; align-items: center;">
                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; cursor: pointer; user-select: none; color: #4b5563; font-weight: 500;">
                    <input type="checkbox" wire:model.live="selectAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af;">
                    Select All
                </label>
                <button type="button" wire:click="toggleLayout" class="rms-select" style="background: white; padding-right: 12px; display: inline-flex; align-items: center; gap: 6px;">
                    @if ($layoutMode === 'table')
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Grid
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Table
                    @endif
                </button>
                <button type="button" class="rms-btn-print" onclick="window.print()">
                    <svg class="btn-icon" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 9h6v2a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-2zm7-5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg> Print
                </button>
            </div>
        </div>
        <div class="rms-toolbar-bottom">
            <div class="rms-entries">
                Show 
                <select class="rms-select" wire:model.live="perPage">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select> 
                Entries
            </div>
            <div class="rms-actions">
                <div class="rms-search-wrapper">
                    <input type="text" class="rms-search-input" placeholder="Search..." wire:model.live="searchQuery">
                </div>
            </div>
        </div>
    </div>

    @if ($layoutMode === 'table')
        <div class="rms-table-responsive">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" wire:model.live="selectAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af;">
                        </th>
                        <th style="width: 60px;">Item No.</th>
                        <th>Control Number</th>
                        <th>Barcode</th>
                        <th>Name of Applicant</th>
                        <th>Position</th>
                        <th>Unit/College</th>
                        <th>Date Created</th>
                        <th>Current Location</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Destination</th>
                        <th>Date/Time Received</th>
                        <th>Received by</th>
                        <th style="width: 60px;">View</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->transactions as $index => $t)
                        @php
                            $isChecked = in_array((string)$t->transaction_id, $selectedIds);
                        @endphp
                        <tr style="background-color: {{ $isChecked ? '#f0f6ff' : '' }};">
                            <td style="text-align: center;">
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $t->transaction_id }}" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af;">
                            </td>
                            <td style="text-align: center;">{{ $this->transactions->firstItem() + $index }}</td>
                            <td>{{ $t->control_number }}</td>
                            <td>{{ $t->qr_code }}</td>
                            <td>{{ $t->requestor_name ?? 'N/A' }}</td>
                            <td>{{ $t->classification ?? 'N/A' }}</td>
                            <td>{{ $t->originated_office_name }}</td>
                            <td>{{ \Carbon\Carbon::parse($t->date_created)->format('Y-m-d H:i') }}</td>
                            <td>{{ $t->current_office_name }}</td>
                            <td style="text-align: center;">
                                <span class="status-badge status-{{ $t->status }}">
                                    {{ $t->status }}
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <span class="priority-badge priority-{{ $t->classification }}">
                                    {{ $t->classification ?: 'normal' }}
                                </span>
                            </td>
                            <td>{{ $t->destination }}</td>
                            <td>{{ $t->date_time_received ? \Carbon\Carbon::parse($t->date_time_received)->format('Y-m-d H:i') : '-' }}</td>
                            <td>{{ $t->received_by }}</td>
                            <td style="text-align: center;">
                                <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="15" class="rms-no-data">No transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <!-- Box Layout (Simplified Checkable Cards Grid) -->
        <div class="dts-card-grid-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; margin-bottom: 20px;">
            @forelse ($this->transactions as $index => $t)
                @php
                    $isChecked = in_array((string)$t->transaction_id, $selectedIds);
                @endphp
                <div class="dts-box-card" style="background: {{ $isChecked ? '#f0f6ff' : 'white' }}; border: 1.5px solid {{ $isChecked ? '#1e40af' : '#ced4da' }}; border-radius: 8px; padding: 16px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; gap: 12px; align-items: flex-start; transition: all 0.2s ease;">
                    
                    <!-- Left side Checkbox -->
                    <div style="display: flex; align-items: center; justify-content: center; height: 20px;">
                        <input type="checkbox" wire:model.live="selectedIds" value="{{ $t->transaction_id }}" style="width: 18px; height: 18px; cursor: pointer; accent-color: #1e40af;">
                    </div>

                    <!-- Right side Info Contents -->
                    <div style="flex-grow: 1; font-family: Roboto, sans-serif; font-size: 13px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; gap: 8px;">
                            <span style="font-weight: 600; color: #1e40af; font-size: 13px;">{{ $t->control_number }}</span>
                            <span class="status-badge status-{{ $t->status }}" style="font-size: 9px; padding: 2px 6px;">{{ $t->status }}</span>
                        </div>

                        <div style="font-weight: 500; color: #1f2937; margin-bottom: 4px; line-height: 1.4; word-break: break-word;">{{ $t->subject }}</div>
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 2px;">Applicant: {{ $t->requestor_name ?? 'N/A' }} ({{ $t->classification ?? 'N/A' }})</div>
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px;">{{ $t->document_name ?? 'Application Letter' }}</div>

                        <div style="display: flex; justify-content: space-between; font-size: 10px; color: #9ca3af; border-top: 1px solid #f3f4f6; padding-top: 8px; margin-top: 8px; align-items: center;">
                            <span>Source: {{ $t->originated_office_name }}</span>
                            <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" style="background: transparent; border: none; color: #2563eb; font-size: 11px; font-weight: 600; cursor: pointer; padding: 0;">View Details</button>
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; background: white; border-radius: 12px; padding: 40px; text-align: center; color: #9CA3AF; font-style: italic; border: 1.5px solid #ced4da;">
                    No records found.
                </div>
            @endforelse
        </div>
    @endif

    <!-- AJAX Pagination Control -->
    <div class="rms-pagination-wrap" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; font-size: 0.85rem; color: #6c757d;">
        <div>
            Showing {{ $this->transactions->firstItem() ?? 0 }} to {{ $this->transactions->lastItem() ?? 0 }} of {{ $this->transactions->total() }} entries
        </div>
        <div class="rms-pagination-buttons" style="display: flex; gap: 8px;">
            @if ($this->transactions->onFirstPage())
                <button type="button" class="rms-select" style="cursor: not-allowed; opacity: 0.5;" disabled>Previous</button>
            @else
                <button type="button" class="rms-select" wire:click="previousPage">Previous</button>
            @endif

            @if ($this->transactions->hasMorePages())
                <button type="button" class="rms-select" wire:click="nextPage">Next</button>
            @else
                <button type="button" class="rms-select" style="cursor: not-allowed; opacity: 0.5;" disabled>Next</button>
            @endif
        </div>
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
                        @if ($selectedTransaction && !empty($selectedTransaction->doc_dir) && !empty($fileCode) && $fileCode !== 'N/A')
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
                                    <input type="text" class="receive-field-input" value="{{ $fileCode }}" readonly>
                                    @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                        <div class="receive-field-actions">
                                            <button type="button" wire:click="startEdit('file_code')">Update</button>
                                            <span>|</span>
                                            <button type="button" wire:click="startEdit('file_code')">Edit</button>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif

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
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->visiblePath as $index => $step)
                                    <tr>
                                        <td>{{ $step->sequence_ranking ?? ($index + 1) }}</td>
                                        <td class="office-cell">{{ $step->office_name }}</td>
                                        <td>{{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('Y-m-d h:i A') : 'N/A' }}</td>
                                        <td>{{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('Y-m-d h:i A') : ($step->date_in ? 'Pending' : 'N/A') }}</td>
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
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" style="padding: 24px; color: #888; font-style: italic;">No transaction paths listed.</td>
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

                        @if ($selectedTransaction->status === 'completed')
                            <button type="button" class="receive-action-btn" wire:click="reopenTransaction" style="background-color: #ea580c;" title="Restore this transaction back to Current Transactions">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="1 4 1 10 7 10"/>
                                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                                </svg>
                                RESTORE TO ACTIVE
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

                        <!-- BARCODE (Alert control number info) -->
                        <button type="button" class="receive-action-btn" onclick="alert('Barcode scan ID: ' + '{{ $selectedTransaction->qr_code ?? '' }}')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 5h2v14H3zM7 5h2v14H7zM11 5h2v14h-2zM15 5h2v14h-2zM19 5h2v14h-2z"/>
                            </svg>
                            BARCODE
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

    <!-- Upload File Modal -->
    @if ($showUploadModal)
        <div style="position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 16px;">
            <div style="background: #ffffff; width: 100%; max-width: 500px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); overflow: hidden; font-family: 'Inter', sans-serif;">
                
                <!-- Header -->
                <div style="padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-file-arrow-up"></i>
                        </div>
                        <div>
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">Upload Circulated Document</h3>
                            <span style="font-size: 12px; color: #64748b;">Attach PDF file for this transaction</span>
                        </div>
                    </div>
                    <button type="button" wire:click="cancelUploadModal" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='transparent'">&times;</button>
                </div>

                <!-- Body -->
                <form wire:submit.prevent="handleUploadFile">
                    <div style="padding: 24px;">
                        @if (!empty($uploadErrorMessage))
                            <div style="background: #fef2f2; border: 1.5px solid #ef4444; border-radius: 8px; padding: 10px 14px; color: #dc2626; font-size: 13px; margin-bottom: 16px;">
                                {{ $uploadErrorMessage }}
                            </div>
                        @endif

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">File Code (Optional / Auto-generated):</label>
                            <input type="text" wire:model="uploadFileCode" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box;">
                        </div>

                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">Select Document PDF (*.pdf):</label>
                            <input type="file" wire:model="uploadedFile" accept=".pdf" style="width: 100%; padding: 8px 12px; border: 1.5px dashed #0284c7; border-radius: 8px; font-size: 13px; background: #f0f9ff; cursor: pointer; box-sizing: border-box;">
                            <div wire:loading wire:target="uploadedFile" style="margin-top: 6px; font-size: 12px; color: #0284c7; font-weight: 500;">
                                Loading file...
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 12px;">
                        <button type="button" wire:click="cancelUploadModal" style="padding: 9px 18px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" style="padding: 9px 20px; border-radius: 8px; border: none; background: #0284c7; color: #ffffff; font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 4px 10px rgba(2, 132, 199, 0.25);">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Upload Document
                        </button>
                    </div>
                </form>

            </div>
        </div>
    @endif
</div>
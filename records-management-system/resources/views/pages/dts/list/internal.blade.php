<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('DTS - Internal Transactions')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    public bool $showCompletionConfirmModal = false;
    public bool $showUploadModal = false;
    public bool $showViewFileModal = false;
    public bool $showPdfPreviewModal = false;
    public bool $editingAll = false;
    public bool $showFullConfiguredPath = false;
    public bool $showMoreDetails = false;
    public bool $showPassword = false;
    public string $pathViewMode = 'timeline'; // timeline or table

    // Show More Details edit properties
    public string $requestorName = '';
    public string $requestorPosition = '';
    public string $emailAccess = '';
    public string $docPassword = '';
    public string $transactionFlow = '';
    public array $flowOffices = [];
    public string $selectedFlowOfficeToAdd = '';

    // Copy Furnished state properties
    public bool $showCopyFurnished = false;
    public array $cfSelectedOffices = [];
    public string $selectedCfOfficeToAdd = '';

    public $uploadedFile = null;
    public string $uploadFileName = '';
    public string $uploadErrorMessage = '';
    public string $attachedDocName = '';

    public string $selectedPriority = 'all';
    public string $selectedStatus = 'all';
    public int $perPage = 10;
    public string $searchQuery = '';
    public string $layoutMode = 'table'; // table or box

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_internal) {
            abort(403, 'Unauthorized access to Internal transactions list.');
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
            ->where('dt.trans_type', 'internal');

        $canViewAll = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_dts_view_all_list;
        if (!$canViewAll) {
            $query->where('dtd.originated_from', $userOfficeCode);
        }

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
            'dtd.subject',
            'dtd.classification',
            'dtd.date_created',
            DB::raw("COALESCE(NULLIF(flow.referenced_flow, ''), flow.flow_name) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->paginate($this->perPage);

        // Map remarks, received by, previous office and next office from latest logs
        $list->getCollection()->transform(function ($t) {
            $latestLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('account_details as ad', 'ad.account_id', '=', 'log.performed_by')
                ->where('log.transaction_id', $t->transaction_id)
                ->orderBy('log.id', 'desc')
                ->select('log.notes', 'log.date_in', 'ad.first_name', 'ad.last_name')
                ->first();
            $t->remarks = $latestLog ? $latestLog->notes : '-';
            $t->received_by = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';

            // Previous office (from office)
            $prevLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
                ->where('log.transaction_id', $t->transaction_id)
                ->whereNotNull('log.date_out')
                ->orderBy('log.id', 'desc')
                ->first();
            $t->from_office = $prevLog ? $prevLog->office_name : 'Originated';

            // Next office
            $flow = DB::table('dts_transaction_details')
                ->where('id', $t->transaction_id)
                ->first();
            $t->next_office_name = 'N/A';
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
                        $t->next_office_name = 'Completed';
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

        // Fetch actual execution logs for this transaction
        $actualLogs = DB::table('sub_document_tracking_system_logs as log')
            ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
            ->where('log.transaction_id', $this->selectedTransactionId)
            ->select('log.*', 'office.office_name')
            ->orderBy('log.id', 'asc')
            ->get();

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

        $flowSteps = collect();

        if ($this->editingAll && !empty($this->flowOffices)) {
            $flowSteps = collect($this->flowOffices)->map(function ($stepData, $index) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName) {
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
                $step->is_locked = $stepData['is_locked'] ?? false;
                return $step;
            });
        } else {
            $flowCode = ($this->editingAll && $this->transactionFlow) ? $this->transactionFlow : $this->selectedTransaction->transaction_flow;
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
            if ($flow) {
                $flowSteps = DB::table('dts_sequence_list as seq')
                    ->leftJoin('office', 'office.office_code', '=', 'seq.office_code')
                    ->where('seq.control_id', $flow->id)
                    ->select('seq.sequence_ranking', 'seq.office_code', 'office.office_name')
                    ->orderBy('seq.sequence_ranking', 'asc')
                    ->get()
                    ->map(function ($step) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName) {
                        if ($step->office_code === 'ORIGIN') {
                            $step->office_code = $originOfficeCode;
                            $step->office_name = $originOfficeName;
                        } elseif ($step->office_code === '[H]') {
                            $step->office_code = $clusterHeadCode;
                            $step->office_name = $clusterHeadName;
                        } elseif (empty($step->office_name)) {
                            $off = DB::table('office')->where('office_code', $step->office_code)->first();
                            $step->office_name = $off ? $off->office_name : $step->office_code;
                        }
                        return $step;
                    });
            }
        }

        if ($flowSteps->isEmpty()) {
            return $actualLogs->values()->map(function ($log, $idx) {
                $step = new \stdClass();
                $step->sequence_ranking = $idx + 1;
                $step->office_code = $log->office_code;
                $step->office_name = $log->office_name ?? $log->office_code;
                $step->date_in = $log->date_in;
                $step->date_out = $log->date_out;
                $step->action_needed = $log->notes ?? $log->type;
                $step->note = $log->notes ?? '-';
                $step->total_time_completed = null;
                $step->is_active_step = ($log->office_code === $this->selectedTransaction->current_office && is_null($log->date_out));
                $step->is_locked = false;
                return $step;
            });
        }

        return $flowSteps->values()->map(function ($step, $index) use ($actualLogs) {
            $seqRank = $step->sequence_ranking ?? ($index + 1);
            $step->total_time_completed = null;
            $log = $actualLogs->get($index);

            if ($log && $log->office_code === $step->office_code) {
                $step->date_in = $log->date_in;
                $step->date_out = $log->date_out;
                $step->action_needed = $log->notes ?? $log->type;
                $step->note = $log->notes ?? '-';
            } else {
                $step->date_in = null;
                $step->date_out = null;
                $step->action_needed = null;
                $step->note = null;
            }

            $step->is_active_step = (
                $step->office_code === $this->selectedTransaction->current_office
                && $seqRank == $this->selectedTransaction->sequence
                && in_array($this->selectedTransaction->status, ['ongoing', 'revision'])
                && is_null($step->date_out)
            );

            if (!empty($step->date_in) && empty($step->total_time_completed) && ($step->date_out || $this->selectedTransaction->status === 'completed')) {
                $dateIn = \Carbon\Carbon::parse($step->date_in);
                $dateOut = $step->date_out ? \Carbon\Carbon::parse($step->date_out) : now();
                $diff = $dateIn->diff($dateOut);
                $parts = [];
                if ($diff->d > 0) $parts[] = $diff->d . ' ' . \Illuminate\Support\Str::plural('day', $diff->d);
                if ($diff->h > 0) $parts[] = $diff->h . ' ' . \Illuminate\Support\Str::plural('hour', $diff->h);
                if ($diff->i > 0) $parts[] = $diff->i . ' ' . \Illuminate\Support\Str::plural('minute', $diff->i);
                if (empty($parts)) $parts[] = 'less than a minute';
                $step->total_time_completed = implode(' ', $parts);
            }

            if ($this->selectedTransaction->status === 'completed' && $seqRank == $this->selectedTransaction->sequence) {
                $step->action_needed = $step->action_needed ?: 'Finished';
            }

            $step->description = $step->note ?: 'Pending office flow step.';
            $step->type = $step->action_needed ?: ($step->date_in ? 'Received' : 'Pending');
            $step->notes = $step->note ?: '';
            $step->is_locked = $step->is_locked ?? false;
            return $step;
        });
    }

    public function getVisiblePathProperty()
    {
        return $this->fullFlowPath;
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
        $this->showFullConfiguredPath = false;
        $this->showMoreDetails = false;
        $this->showPassword = false;
        $this->showCopyFurnished = false;
        $this->cfSelectedOffices = [];
        $this->selectedCfOfficeToAdd = '';
        $this->editingAll = false;
        $this->requestorName = '';
        $this->requestorPosition = '';
        $this->emailAccess = '';
        $this->docPassword = '';
        $this->transactionFlow = '';
    }

    public function toggleMoreDetails(): void
    {
        $this->showMoreDetails = !$this->showMoreDetails;
    }

    public function toggleEditAll(): void
    {
        $perms = auth()->user()?->permissions;
        $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false));
        if (!$canListModify) {
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

    public function saveMetadataOnly(): void
    {
        $perms = auth()->user()?->permissions;
        $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false));
        if ($canListModify) {
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

            $copyFilledId = $this->selectedTransaction->copy_filled_id;
            if (count($this->cfSelectedOffices) > 0) {
                if ($copyFilledId) {
                    $cfRecord = DB::table('dts_copy_filled_transaction')->where('id', $copyFilledId)->first();
                    if ($cfRecord) {
                        DB::table('dts_copy_filled_transaction')
                            ->where('id', $copyFilledId)
                            ->update([
                                'total_office' => count($this->cfSelectedOffices),
                                'date_modified' => now(),
                            ]);
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
            $this->editingAll = false;
            $this->loadSelectedTransaction();
        }
    }

    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('dts_email_access as dea', 'dea.id', '=', 'dtd.email_access')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->select('dt.*', 'dtd.*', 'dea.email as access_email', 'originated_office.office_name as originated_office_name')
            ->first();

        $this->attachedDocName = '';
        if ($this->selectedTransaction && !empty($this->selectedTransaction->doc_dir)) {
            $docData = DB::table('document_data')->where('document_path', $this->selectedTransaction->doc_dir)->first();
            $this->attachedDocName = $docData->document_name ?? basename($this->selectedTransaction->doc_dir);
        }

        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number;
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?: '';
            $this->particulars = $this->selectedTransaction->subject ?: '';
            $this->classification = $this->selectedTransaction->classification ?: '';
            $this->actionNeeded = $this->selectedTransaction->action_needed ?: '';
            $this->activeAction = DB::table('dts_action_options')->orderBy('option_name', 'asc')->value('option_name') ?: 'For Approval';

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

            // Auto-repair completed transaction steps if missing Finished action or elapsed time
            if ($this->selectedTransaction && $this->selectedTransaction->status === 'completed') {
                $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->selectedTransaction->transaction_flow)->first();
                if ($flow) {
                    $lastSeq = DB::table('dts_sequence_list')
                        ->where('control_id', $flow->id)
                        ->where('sequence_ranking', $this->selectedTransaction->sequence)
                        ->first();
                    if ($lastSeq && ($lastSeq->action_needed !== 'Finished' || empty($lastSeq->total_time_completed))) {
                        $duration = $lastSeq->total_time_completed;
                        if (!$duration && $lastSeq->date_in) {
                            $dateIn = \Carbon\Carbon::parse($lastSeq->date_in);
                            $dateOut = $lastSeq->date_out ? \Carbon\Carbon::parse($lastSeq->date_out) : now();
                            $diff = $dateIn->diff($dateOut);
                            $parts = [];
                            if ($diff->d > 0) $parts[] = $diff->d . ' ' . \Illuminate\Support\Str::plural('day', $diff->d);
                            if ($diff->h > 0) $parts[] = $diff->h . ' ' . \Illuminate\Support\Str::plural('hour', $diff->h);
                            if ($diff->i > 0) $parts[] = $diff->i . ' ' . \Illuminate\Support\Str::plural('minute', $diff->i);
                            if (empty($parts)) $parts[] = 'less than a minute';
                            $duration = implode(' ', $parts);
                        }
                        DB::table('dts_sequence_list')
                            ->where('control_id', $flow->id)
                            ->where('sequence_ranking', $this->selectedTransaction->sequence)
                            ->update([
                                'action_needed' => 'Finished',
                                'date_out' => $lastSeq->date_out ?: now(),
                                'total_time_completed' => $duration ?: 'less than a minute',
                            ]);
                    }
                }
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
        $this->uploadFileName = '';
        $this->uploadErrorMessage = '';
        $this->uploadedFile = null;
        $this->showUploadModal = true;
    }

    public function openViewFileModal(): void
    {
        $this->showViewFileModal = true;
    }

    public function closeViewFileModal(): void
    {
        $this->showViewFileModal = false;
    }

    public function openPdfPreviewModal(): void
    {
        $this->showPdfPreviewModal = true;
    }

    public function closePdfPreviewModal(): void
    {
        $this->showPdfPreviewModal = false;
    }

    public function removeUploadedFile(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) return;

        $oldDocDir = $this->selectedTransaction->doc_dir ?? null;

        DB::table('dts_transactions')
            ->where('transaction_id', $this->selectedTransactionId)
            ->update(['doc_dir' => null]);

        if ($oldDocDir) {
            \App\Services\DocumentStorageService::deleteDocument($oldDocDir);
        }

        $this->loadSelectedTransaction();
        $this->showViewFileModal = false;
    }

    public function changeUploadedFile(): void
    {
        $this->showViewFileModal = false;
        $this->triggerUploadFileModal();
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
            $codeNum = trim($this->uploadFileName) ?: $originalName;

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
            $effectiveOffice = ($this->selectedTransaction->current_office === 'ORIGIN') 
                ? $this->selectedTransaction->originated_from 
                : $this->selectedTransaction->current_office;

            DB::table('dts_transactions')
                ->where('transaction_id', $this->selectedTransactionId)
                ->update([
                    'status' => 'ongoing',
                    'current_office' => $effectiveOffice,
                ]);

            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $this->selectedTransactionId,
                'office_code' => $userOfficeCode ?: $effectiveOffice,
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
                        $cluster = DB::table('cluster')->where('cluster_code', $originOffice->cluster)->first();
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

                DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->selectedTransaction->sequence)
                    ->update([
                        'action_needed' => 'Finished',
                        'date_out' => now(),
                        'total_time_completed' => $duration ?: 'less than a minute',
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
        <h2>Internal Transactions</h2>
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
                        <th>QR Code</th>
                        <th>Source</th>
                        <th>Subject</th>
                        <th>Type of Document</th>
                        <th>Date Created</th>
                        <th>Current Location</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Remarks</th>
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
                            <td>{{ $t->originated_office_name }}</td>
                            <td>{{ $t->subject }}</td>
                            <td>{{ (!empty($t->doc_type_name) && !str_starts_with($t->doc_type_name, 'Flow for ')) ? $t->doc_type_name : ucfirst($t->classification ?: 'Internal') }}</td>
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
                            <td>{{ $t->remarks }}</td>
                            <td>{{ $t->received_by }}</td>
                            <td style="text-align: center;">
                                <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="rms-no-data">No transactions found.</td></tr>
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
                        <div style="font-size: 11px; color: #6b7280; margin-bottom: 8px;">{{ $t->document_name ?? ucfirst($t->classification ?: 'internal') }}</div>

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
                            @if ($editingAll)
                                <input type="text" class="receive-field-input" wire:model="controlNumber">
                            @else
                                <input type="text" class="receive-field-input" value="{{ $controlNumber }}" readonly>
                            @endif
                        </div>

                        <!-- Originator field -->
                        <div class="receive-field-row">
                            <span class="receive-field-label">Originator:</span>
                            <input type="text" class="receive-field-input" value="{{ $selectedTransaction->originated_office_name ?? 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b;">
                        </div>

                        <!-- Document Type field (formerly Flow) -->
                        <div class="receive-field-row" style="align-items: center;">
                            <span class="receive-field-label">Document Type:</span>
                            @if ($editingAll)
                                @php
                                    $availableFlows = DB::table('dts_transaction_flow')
                                        ->where('is_active', true)
                                        ->where('flow_name', 'not like', 'Flow for %')
                                        ->orWhere('flow_code', $transactionFlow)
                                        ->orderBy('flow_name', 'asc')
                                        ->get();
                                @endphp
                                <select class="receive-field-input" wire:model.live="transactionFlow" style="height: 38px; padding: 0 10px; border-radius: 6px; border: 1px solid #cbd5e1; outline: none; background: #fff;">
                                    @foreach ($availableFlows as $f)
                                        <option value="{{ $f->flow_code }}">{{ $f->referenced_flow ?: $f->flow_name }}</option>
                                    @endforeach
                                </select>
                            @else
                                @php
                                    $flowRow = DB::table('dts_transaction_flow')->where('flow_code', $transactionFlow)->first();
                                    $flowName = \App\Services\DocumentStorageService::resolveFlowName($transactionFlow, $flowRow?->referenced_flow, $flowRow?->flow_name, $selectedTransaction->trans_type ?? 'Internal');
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
                                <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 12px; align-items: center;">
                                    <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">File Code:</span>
                                    @if ($editingAll)
                                        <input type="text" class="receive-field-input" wire:model="fileCode">
                                    @else
                                        <input type="text" class="receive-field-input" value="{{ $fileCode ?: 'N/A' }}" readonly style="background-color: #f8fafc; color: #64748b;">
                                    @endif
                                </div>
                                @if ($editingAll || (!empty(trim($emailAccess ?? '')) && $emailAccess !== 'N/A'))
                                    <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 12px; align-items: center;">
                                        <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Email Access:</span>
                                        @if ($editingAll)
                                            <input type="email" class="receive-field-input" wire:model="emailAccess">
                                        @else
                                            <input type="text" class="receive-field-input" value="{{ $emailAccess }}" readonly style="background-color: #f8fafc; color: #64748b;">
                                        @endif
                                    </div>
                                @endif
                                @if ($editingAll || (!empty(trim($docPassword ?? '')) && $docPassword !== 'N/A'))
                                    <div class="receive-field-row" style="grid-template-columns: 180px 1fr; margin-bottom: 4px; align-items: center;">
                                        <span class="receive-field-label" style="font-weight: 600; color: #475569; white-space: nowrap;">Document Password:</span>
                                        <div style="display: flex; gap: 8px; width: 100%; align-items: center;">
                                            @if ($editingAll)
                                                <input type="text" class="receive-field-input" wire:model="docPassword" style="flex: 1;">
                                            @else
                                                <input type="{{ $showPassword ? 'text' : 'password' }}" class="receive-field-input" value="{{ $docPassword }}" readonly style="background-color: #f8fafc; color: #64748b; flex: 1;">
                                                <button type="button" wire:click="$toggle('showPassword')" style="background: #e2e8f0; border: 1px solid #cbd5e1; color: #475569; border-radius: 6px; padding: 8px 12px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; outline: none; transition: all 0.2s ease; height: 38px;">
                                                    <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                                    {{ $showPassword ? 'Hide' : 'Show' }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endif
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
                        <!-- Transaction Path Section Header & View Toggle -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px; margin-bottom: 8px;">
                            <h2 class="receive-title" style="font-size: 16px; margin: 0;">Transaction Path</h2>
                            <div style="display: flex; gap: 4px; background: #f1f5f9; padding: 3px; border-radius: 8px; border: 1px solid #cbd5e1;">
                                <button type="button" wire:click="$set('pathViewMode', 'timeline')" style="padding: 5px 12px; font-size: 12px; font-weight: 600; border-radius: 6px; border: none; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.15s; {{ $pathViewMode === 'timeline' ? 'background: #2563eb; color: #ffffff; box-shadow: 0 1px 3px rgba(37,99,235,0.3);' : 'background: transparent; color: #64748b;' }}">
                                    <i class="fa-solid fa-chart-line"></i> Timeline View
                                </button>
                                <button type="button" wire:click="$set('pathViewMode', 'table')" style="padding: 5px 12px; font-size: 12px; font-weight: 600; border-radius: 6px; border: none; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.15s; {{ $pathViewMode === 'table' ? 'background: #2563eb; color: #ffffff; box-shadow: 0 1px 3px rgba(37,99,235,0.3);' : 'background: transparent; color: #64748b;' }}">
                                    <i class="fa-solid fa-table"></i> Table View
                                </button>
                            </div>
                        </div>

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

                        @if ($pathViewMode === 'timeline')
                            <style>
                                .dts-timeline-node-wrapper {
                                    position: relative;
                                    display: flex;
                                    flex-direction: column;
                                    align-items: center;
                                }
                                .dts-timeline-node-wrapper .dts-node-tooltip {
                                    position: absolute;
                                    top: 100%;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    margin-top: 36px;
                                    width: 250px;
                                    background: #0f172a;
                                    color: #f8fafc;
                                    padding: 12px 14px;
                                    border-radius: 10px;
                                    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
                                    border: 1px solid #334155;
                                    opacity: 0;
                                    visibility: hidden;
                                    pointer-events: none;
                                    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                                    z-index: 100;
                                    font-family: 'Inter', sans-serif;
                                    text-align: left;
                                }
                                .dts-timeline-node-wrapper .dts-node-tooltip::after {
                                    content: '';
                                    position: absolute;
                                    bottom: 100%;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    border-width: 6px;
                                    border-style: solid;
                                    border-color: transparent transparent #0f172a transparent;
                                }
                                .dts-timeline-node-wrapper:hover .dts-node-tooltip {
                                    opacity: 1;
                                    visibility: visible;
                                    margin-top: 32px;
                                }
                                .dts-timeline-node-dot {
                                    transition: all 0.2s ease;
                                }
                                .dts-timeline-node-wrapper:hover .dts-timeline-node-dot {
                                    transform: scale(1.25) !important;
                                    box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.4) !important;
                                }
                            </style>

                            <!-- Horizontal Progress Line Graph with Hover Tooltips -->
                            <div style="width: 100%; overflow: visible; padding: 54px 16px 24px 16px; box-sizing: border-box; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-top: 4px; margin-bottom: 16px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; min-width: max-content; padding: 0 30px; position: relative;">
                                    @forelse ($this->visiblePath as $index => $step)
                                        @php
                                            $isReceived = !is_null($step->date_in) || $selectedTransaction->status === 'completed';
                                            $isForwarded = !is_null($step->date_out) || $selectedTransaction->status === 'completed';

                                            $dotColor = $isReceived ? '#10b981' : '#dc2626';
                                            $lineColor = $isForwarded ? '#10b981' : '#dc2626';

                                            $isCurrentOffice = $step->is_active_step && !is_null($step->date_in) && is_null($step->date_out) && $selectedTransaction->status !== 'completed';
                                            $isInTransitToThisOffice = $step->is_active_step && is_null($step->date_in) && $selectedTransaction->status !== 'completed';
                                        @endphp

                                        <!-- Node Wrapper (Dot + Current Indicator + Tooltip) -->
                                        <div class="dts-timeline-node-wrapper">
                                            
                                            <!-- Current Office or In-Transit Indicator Above Dot -->
                                            @if ($isCurrentOffice)
                                                <div style="position: absolute; bottom: 42px; display: flex; flex-direction: column; align-items: center; white-space: nowrap; z-index: 5;">
                                                    <span style="font-size: 11.5px; font-weight: 800; color: #10b981; text-transform: uppercase; letter-spacing: 0.5px; text-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                        &lt;Transaction Holder&gt;
                                                    </span>
                                                    <span style="font-size: 14px; font-weight: 900; color: #10b981; margin-top: -2px;">v</span>
                                                </div>
                                            @elseif ($isInTransitToThisOffice)
                                                <div style="position: absolute; bottom: 42px; display: flex; flex-direction: column; align-items: center; white-space: nowrap; z-index: 5;">
                                                    <span style="font-size: 11px; font-weight: 800; color: #f59e0b; text-transform: uppercase; letter-spacing: 0.5px; text-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                        &lt;In Transit to {{ $step->office_code }}&gt;
                                                    </span>
                                                    <span style="font-size: 14px; font-weight: 900; color: #f59e0b; margin-top: -2px;">v</span>
                                                </div>
                                            @endif

                                            <!-- Node Dot Circle -->
                                            <div class="dts-timeline-node-dot" style="width: 32px; height: 32px; border-radius: 50%; background: {{ $dotColor }}; color: #ffffff; font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 2; cursor: pointer; {{ $isCurrentOffice ? 'box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.35); transform: scale(1.15);' : ($isInTransitToThisOffice ? 'box-shadow: 0 0 0 6px rgba(245, 158, 11, 0.35);' : '') }}">
                                                @if ($isReceived)
                                                    <i class="fa-solid fa-check" style="font-size: 13px;"></i>
                                                @else
                                                    {{ $index + 1 }}
                                                @endif
                                            </div>

                                            <!-- Office Code Below Dot -->
                                            <span style="margin-top: 8px; font-size: 11.5px; font-weight: 700; color: {{ $isReceived ? '#10b981' : '#dc2626' }}; font-family: 'Inter', sans-serif;">
                                                {{ $step->office_code }}
                                            </span>

                                            <!-- Hover Tooltip Floating Card -->
                                            <div class="dts-node-tooltip">
                                                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; padding-bottom: 6px; margin-bottom: 6px;">
                                                    <span style="font-weight: 700; font-size: 12px; color: #38bdf8;">Step {{ $index + 1 }}: {{ $step->office_code }}</span>
                                                    @if ($isReceived && $isForwarded)
                                                        <span style="background: #166534; color: #4ade80; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 12px;">✓ Forwarded</span>
                                                    @elseif ($isReceived && !$isForwarded)
                                                        <span style="background: #1e3a8a; color: #60a5fa; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 12px;">📍 Received / Held</span>
                                                    @else
                                                        <span style="background: #450a0a; color: #fca5a5; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 12px;">⏳ Pending</span>
                                                    @endif
                                                </div>
                                                
                                                <div style="font-size: 11px; font-weight: 600; color: #f1f5f9; margin-bottom: 6px; line-height: 1.3;">
                                                    {{ $step->office_name }}
                                                </div>

                                                <div style="font-size: 10.5px; color: #94a3b8; display: flex; flex-direction: column; gap: 3px;">
                                                    <div><strong style="color: #cbd5e1;">Date In:</strong> {{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('M d, Y h:i A') : 'N/A' }}</div>
                                                    <div><strong style="color: #cbd5e1;">Date Out:</strong> {{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('M d, Y h:i A') : ($step->date_in ? 'Pending' : 'N/A') }}</div>
                                                    @if (!empty($step->total_time_completed) && $step->total_time_completed !== '-')
                                                        <div><strong style="color: #cbd5e1;">Total Time:</strong> <span style="color: #38bdf8; font-weight: 700;">{{ $step->total_time_completed }}</span></div>
                                                    @endif
                                                    <div><strong style="color: #cbd5e1;">Action:</strong> {{ ($selectedTransaction->status === 'completed' || !is_null($step->date_out)) ? ($step->action_needed ?: 'Finished') : ($step->action_needed ?: ($step->date_in ? 'Ongoing' : 'Pending')) }}</div>
                                                    @if (!empty($step->note) && $step->note !== '-')
                                                        <div style="font-style: italic; color: #e2e8f0; margin-top: 2px;">"{{ $step->note }}"</div>
                                                    @endif
                                                </div>
                                            </div>

                                        </div>

                                        <!-- Progress Bar Line Segment -->
                                        @if (!$loop->last)
                                            <div style="flex: 1; height: 8px; background: {{ $lineColor }}; min-width: 60px; margin: 0 -4px; border-radius: 4px; z-index: 1; transition: all 0.3s ease;"></div>
                                        @endif

                                    @empty
                                        <div style="padding: 24px; color: #888; font-style: italic; width: 100%; text-align: center;">No transaction paths listed.</div>
                                    @endforelse
                                </div>
                            </div>
                        @else
                            <!-- Table View -->
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
                                                <td>{{ $step->sequence_ranking ?? ($index + 1) }}</td>
                                                <td class="office-cell">{{ $step->office_name }}</td>
                                                <td>{{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('Y-m-d h:i A') : 'N/A' }}</td>
                                                <td>{{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('Y-m-d h:i A') : ($step->date_in ? 'Pending' : 'N/A') }}</td>
                                                <td>{{ $step->total_time_completed ?: '-' }}</td>
                                                <td>
                                                    @if ($step->is_active_step && is_null($step->date_out) && $selectedTransaction->status !== 'completed')
                                                        <select wire:model="activeAction" class="receive-row-action-select" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12.5px; font-weight: 500;">
                                                            @foreach(DB::table('dts_action_options')->orderBy('option_name', 'asc')->pluck('option_name') as $opt)
                                                                <option value="{{ $opt }}">{{ $opt }}</option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        {{ ($selectedTransaction->status === 'completed' || !is_null($step->date_out)) ? ($step->action_needed ?: 'Finished') : ($step->action_needed ?: ($step->date_in ? 'Ongoing' : 'Pending')) }}
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($step->is_active_step && is_null($step->date_out) && $selectedTransaction->status !== 'completed')
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
                    @endif

                    <!-- Popup Action Buttons -->
                    <div class="receive-actions">
                        <!-- VIEW LISTED PATH / VIEW COPY FURNISHED Toggle Button -->
                        @if (!$showCopyFurnished)
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
                            $effectiveCurrentOffice = ($selectedTransaction->current_office === 'ORIGIN') ? $selectedTransaction->originated_from : $selectedTransaction->current_office;
                            $isUserOffice = ($effectiveCurrentOffice === auth()->user()?->details?->office?->office_code);
                        @endphp
                        @if ($isUserOffice && $selectedTransaction->status !== 'completed')
                            @if ($this->isLastStep())
                                @if ($allowManualComplete)
                                    <button type="button" class="receive-action-btn" wire:click="triggerCompletionConfirm" style="background-color: #16a34a;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        Complete Transaction
                                    </button>
                                @endif
                            @else
                                @if ($allowManualComplete)
                                    <button type="button" class="receive-action-btn" wire:click="completeTransaction" style="background-color: #2563eb;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                            <polyline points="12 5 19 12 12 19"/>
                                        </svg>
                                        FORWARD TRANSACTION
                                    </button>
                                @endif
                            @endif
                        @endif

                        @if ($selectedTransaction->status === 'completed' || $this->isLastStep() || $showUploadModal)
                            @if (!empty($selectedTransaction->doc_dir))
                                <button type="button" class="receive-action-btn" wire:click="openPdfPreviewModal" style="background-color: #0891b2;" title="Directly preview attached document">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    VIEW FILE
                                </button>
                            @else
                                <button type="button" class="receive-action-btn" wire:click="triggerUploadFileModal" style="background-color: #0284c7;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="17 8 12 3 7 8"/>
                                        <line x1="12" y1="3" x2="12" y2="15"/>
                                    </svg>
                                    UPLOAD FILE
                                </button>
                            @endif
                        @endif

                        @if ($selectedTransaction->status === 'completed' || $selectedTransaction->current_office === 'ORIGIN')
                            <button type="button" class="receive-action-btn" wire:click="reopenTransaction" style="background-color: #ea580c;" title="Restore this transaction back to Current Transactions">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="1 4 1 10 7 10"/>
                                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                                </svg>
                                RESTORE TO ACTIVE
                            </button>
                        @endif

                        <!-- EDIT / SAVE toggle -->
                        @php
                            $perms = auth()->user()?->permissions;
                            $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false));
                            $canEditMetadata = ($selectedTransaction->status !== 'completed') || $canListModify;
                        @endphp
                        @if ($canEditMetadata)
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
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">File Name (Optional):</label>
                            <input type="text" wire:model="uploadFileName" placeholder="Auto-detected from file if left blank" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; box-sizing: border-box;">
                        </div>

                        <div x-data="{ uploading: false, progress: 0 }"
                             x-on:livewire-upload-start="uploading = true; progress = 0"
                             x-on:livewire-upload-finish="uploading = false; progress = 100"
                             x-on:livewire-upload-cancel="uploading = false; progress = 0"
                             x-on:livewire-upload-error="uploading = false; progress = 0"
                             x-on:livewire-upload-progress="progress = $event.detail.progress">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px;">Select Document PDF (*.pdf):</label>
                            <input type="file" wire:model="uploadedFile" accept=".pdf" style="width: 100%; padding: 8px 12px; border: 1.5px dashed #0284c7; border-radius: 8px; font-size: 13px; background: #f0f9ff; cursor: pointer; box-sizing: border-box;">
                            
                            <!-- Upload Progress Bar -->
                            <div x-show="uploading" x-cloak style="margin-top: 10px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <span style="font-size: 12px; font-weight: 600; color: #0284c7;">Uploading...</span>
                                    <span style="font-size: 12px; font-weight: 700; color: #0284c7;" x-text="progress + '%'"></span>
                                </div>
                                <div style="width: 100%; height: 8px; background: #e0f2fe; border-radius: 99px; overflow: hidden;">
                                    <div style="height: 100%; background: linear-gradient(90deg, #0284c7, #06b6d4); border-radius: 99px; transition: width 0.3s ease;" x-bind:style="'width: ' + progress + '%'"></div>
                                </div>
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

    <!-- View File Modal -->
    @if ($showViewFileModal && $selectedTransaction && !empty($selectedTransaction->doc_dir))
        <div style="position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 16px;">
            <div style="background: #ffffff; width: 100%; max-width: 460px; border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); overflow: hidden; font-family: 'Inter', sans-serif;">
                
                <!-- Header -->
                <div style="padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: #f0fdf4; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                            <i class="fa-solid fa-file-pdf"></i>
                        </div>
                        <div>
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">Attached Document</h3>
                            <span style="font-size: 12px; color: #64748b;">Manage the uploaded circulated document</span>
                        </div>
                    </div>
                    <button type="button" wire:click="closeViewFileModal" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='transparent'">&times;</button>
                </div>

                <!-- Body -->
                <div style="padding: 24px;">
                    <!-- File Info Card -->
                    <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 14px;">
                        <div style="width: 44px; height: 44px; border-radius: 10px; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                            <i class="fa-solid fa-file-pdf"></i>
                        </div>
                        <div style="min-width: 0; flex: 1;">
                            <div style="font-size: 14px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $attachedDocName ?: 'Document' }}</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">PDF Document</div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <!-- View Document -->
                        <button type="button" wire:click="openPdfPreviewModal" style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #ffffff; color: #334155; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s; width: 100%; text-align: left;" onmouseover="this.style.background='#f0f9ff'; this.style.borderColor='#0284c7'" onmouseout="this.style.background='#ffffff'; this.style.borderColor='#e2e8f0'">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0;">
                                <i class="fa-solid fa-eye"></i>
                            </div>
                            <div>
                                <div style="font-weight: 700; color: #0f172a;">View Document</div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 1px;">Open PDF popout previewer directly in browser</div>
                            </div>
                        </button>

                        @php
                            $perms = auth()->user()?->permissions;
                            $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false));
                            $canModifyFile = ($selectedTransaction->status !== 'completed') || $canListModify;
                        @endphp

                        @if ($canModifyFile)
                            <!-- Change File -->
                            <button type="button" wire:click="changeUploadedFile" style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #ffffff; color: #334155; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s; width: 100%; text-align: left;" onmouseover="this.style.background='#fffbeb'; this.style.borderColor='#f59e0b'" onmouseout="this.style.background='#ffffff'; this.style.borderColor='#e2e8f0'">
                                <div style="width: 36px; height: 36px; border-radius: 8px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0;">
                                    <i class="fa-solid fa-file-pen"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #0f172a;">Change File</div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 1px;">Replace with a different PDF document</div>
                                </div>
                            </button>

                            <!-- Remove File -->
                            <button type="button" wire:click="removeUploadedFile" onclick="return confirm('Are you sure you want to remove this uploaded document?')" style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #ffffff; color: #334155; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s; width: 100%; text-align: left;" onmouseover="this.style.background='#fef2f2'; this.style.borderColor='#ef4444'" onmouseout="this.style.background='#ffffff'; this.style.borderColor='#e2e8f0'">
                                <div style="width: 36px; height: 36px; border-radius: 8px; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0;">
                                    <i class="fa-solid fa-trash-can"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: #0f172a;">Remove File</div>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 1px;">Detach and delete the uploaded document</div>
                                </div>
                            </button>
                        @endif
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding: 14px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end;">
                    <button type="button" wire:click="closeViewFileModal" style="padding: 9px 18px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- PDF Preview Popout Modal -->
    @if ($showPdfPreviewModal && $selectedTransaction && !empty($selectedTransaction->doc_dir))
        <div style="position: fixed; inset: 0; z-index: 999999; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; padding: 16px;">
            <div style="background: #ffffff; width: 95%; max-width: 1000px; height: 90vh; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; display: flex; flex-direction: column; font-family: 'Inter', sans-serif;">
                
                <!-- Header -->
                <div style="padding: 16px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                        <div style="width: 38px; height: 38px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                            <i class="fa-solid fa-file-pdf"></i>
                        </div>
                        <div style="min-width: 0;">
                            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $attachedDocName ?: 'Document Preview' }}</h3>
                            <span style="font-size: 11px; color: #64748b;">PDF Document Popout Viewer</span>
                        </div>
                    </div>
                    
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <a href="{{ route('dts.view-document', ['path' => $selectedTransaction->doc_dir]) }}" target="_blank" style="padding: 7px 14px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #0284c7; font-size: 12px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: all 0.15s;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background='#ffffff'">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in New Tab
                        </a>
                        <button type="button" wire:click="closePdfPreviewModal" style="background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='transparent'">&times;</button>
                    </div>
                </div>

                <!-- Body (PDF Viewer IFrame) -->
                <div style="flex: 1; background: #525659; width: 100%; height: 100%; position: relative;">
                    <iframe src="{{ route('dts.view-document', ['path' => $selectedTransaction->doc_dir]) }}" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>

            </div>
        </div>
    @endif

    <!-- View QR Code Modal -->
    <div id="dts-qr-view-modal" class="modal-backdrop" style="display: none; z-index: 1000000; align-items: center; justify-content: center;" onclick="closeQrViewModal()">
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
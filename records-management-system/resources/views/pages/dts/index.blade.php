<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    #[On('dts-transaction-updated')]
    #[On('refresh-transactions')]
    public function refreshTransactionsList(): void
    {
        $this->resetPage();
    }

    public string $activeTab = 'all';
    public string $searchQuery = '';
    public int $perPage = 10;
    public string $layoutMode = 'table'; // table or box
    public string $pathViewMode = 'timeline'; // timeline or table
    public bool $groupByOffice = false;

    public function toggleGroupByOffice(): void
    {
        $this->groupByOffice = !$this->groupByOffice;
    }

    // Modal state properties
    public string $selectedTransactionId = '';
    public $selectedTransaction = null;
    public string $controlNumber = '';
    public string $transactionFlow = '';
    public string $fileCode = '';
    public string $particulars = '';
    public string $classification = '';
    public string $actionNeeded = '';
    public string $activeAction = 'forwarded';
    public string $activeNotes = '';
    public bool $editingAll = false;
    public bool $showFullConfiguredPath = false;
    public bool $showMoreDetails = false;
    public bool $showPassword = false;

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

    // Reaffirmation & Upload Modal properties
    public bool $showCompletionConfirmModal = false;
    public bool $showUploadModal = false;
    public bool $showViewFileModal = false;
    public bool $showPdfPreviewModal = false;
    public $uploadedFile = null;
    public string $uploadFileName = '';
    public string $uploadErrorMessage = '';
    public string $attachedDocName = '';

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function setTab(string $tab): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm) {
            if ($tab === 'internal' && !$perms->can_dts_use_internal) return;
            if ($tab === 'external' && !$perms->can_dts_use_external) return;
            if ($tab === 'applications' && !$perms->can_dts_use_application) return;
            if ($tab === 'issuances' && !$perms->can_dts_use_issuance) return;
        }

        $this->activeTab = $tab;
        $this->searchQuery = '';
        $this->resetPage();
    }

    public function updatingSearchQuery(): void
    {
        $this->resetPage();
    }

    public function getTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());
        if (!$userOfficeCode) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        // Auto-heal any legacy records where current_office was saved as 'ORIGIN'
        try {
            DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where('dt.current_office', 'ORIGIN')
                ->update(['dt.current_office' => DB::raw('dtd.originated_from')]);
        } catch (\Throwable $e) {}

        $routeName = request()->route()?->getName();

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('dts_source_office as src', 'src.s_office_code', '=', 'dtd.source_office')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dtd.is_active', 1)
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        if ($routeName === 'dts.my-transactions') {
            $query->where(function($q) use ($userOfficeCode) {
                $q->where('dtd.originated_from', $userOfficeCode)
                  ->orWhere('dtd.created_by', auth()->id());
            });
        } elseif ($routeName === 'dts.forwarded') {
            $query->where('dt.current_office', '!=', $userOfficeCode)
                  ->whereExists(function($q) use ($userOfficeCode) {
                      $q->select(DB::raw(1))
                        ->from('sub_document_tracking_system_logs as log')
                        ->whereColumn('log.transaction_id', 'dt.transaction_id')
                        ->where('log.office_code', $userOfficeCode)
                        ->whereNotNull('log.date_out');
                  });
        } else {
            $query->where(function($q) use ($userOfficeCode) {
                $q->where('dt.current_office', $userOfficeCode)
                  ->orWhere(function($sub) use ($userOfficeCode) {
                      $sub->where('dt.current_office', 'ORIGIN')
                          ->where('dtd.originated_from', $userOfficeCode);
                  });
            });
        }

        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm) {
            $allowedTypes = [];
            if ($perms->can_dts_use_internal) {
                $allowedTypes[] = 'internal';
            }
            if ($perms->can_dts_use_external) {
                $allowedTypes[] = 'external';
            }
            if ($perms->can_dts_use_application) {
                $allowedTypes[] = 'others';
            }
            if ($perms->can_dts_use_issuance) {
                $allowedTypes[] = 'memorandom';
            }

            if (empty($allowedTypes)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('dt.trans_type', $allowedTypes);
            }
        }

        // Tab filters
        if ($this->activeTab === 'internal') {
            $query->where('dt.trans_type', 'internal');
        } elseif ($this->activeTab === 'external') {
            $query->where('dt.trans_type', 'external');
        } elseif ($this->activeTab === 'applications') {
            $query->where('dt.trans_type', 'others');
        } elseif ($this->activeTab === 'issuances') {
            $query->where('dt.trans_type', 'memorandom');
        }

        // Search filter
        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $decoded = base64_decode($searchVal, true);
            if ($decoded !== false && preg_match('/^[A-Z0-9-]+$/i', $decoded)) {
                $searchVal = $decoded;
            }
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('req.requestor_name', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('src.s_office_name', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dt.qr_code', 'like', '%' . $searchVal . '%');
            });
        }

        $list = $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dt.trans_type',
            'dtd.control_number',
            'dtd.requestor_id',
            'dtd.source_office',
            'req.requestor_name',
            'req.requestor_position as requestor_label',
            'src.s_office_name as source_office_name',
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

        // Map elapsed days, next office, previous office, and received date/time
        $list->getCollection()->transform(function ($t) {
            // Find active/current log step
            $currentLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $t->current_office)
                ->orderBy('id', 'desc')
                ->first();

            $isReceived = $currentLog && ($currentLog->type === 'received' || (!empty($currentLog->date_in) && $currentLog->type !== 'forwarded'));

            if ($isReceived && !empty($currentLog->date_in)) {
                $dateReceived = $currentLog->date_in;
                $t->diff_in_minutes = (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($dateReceived)));
                $t->is_received = true;
            } else {
                $dateReceived = $currentLog?->created_at ?? $t->date_created;
                $t->diff_in_minutes = 0; // Reset timer to 0 until received at current office!
                $t->is_received = false;
            }
            $t->date_received = $dateReceived;

            // Check if transaction has been forwarded from originating office
            $firstLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->orderBy('id', 'asc')
                ->first();

            $hasBeenForwarded = ($t->sequence > 1) || ($firstLog && !empty($firstLog->date_out));

            if ($hasBeenForwarded) {
                $startDate = \Carbon\Carbon::parse($firstLog->date_out ?? $firstLog->date_in ?? $t->date_created);
                $t->elapsed_days = (int) abs(now()->diffInDays($startDate)) + 1;
            } else {
                $t->elapsed_days = 0;
            }

            // Calculate actual elapsed minutes since arrival/creation at current office
            $t->diff_in_minutes = (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($dateReceived)));

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

        if (in_array($routeName, ['dts', 'dts.incoming', 'dts.received', 'dts.forwarded'])) {
            $filteredCollection = $list->getCollection()->filter(function ($t) use ($routeName, $userOfficeCode) {
                if ($routeName === 'dts.received') {
                    return $t->is_received === true;
                }
                if ($routeName === 'dts.forwarded') {
                    $destLog = DB::table('sub_document_tracking_system_logs')
                        ->where('transaction_id', $t->transaction_id)
                        ->where('office_code', $t->current_office)
                        ->orderBy('id', 'desc')
                        ->first();
                    $destReceived = $destLog && ($destLog->type === 'received' || (!empty($destLog->date_in) && $destLog->type !== 'forwarded'));
                    return !$destReceived;
                }
                // Default & dts.incoming: incoming only (not received at user office)
                return $t->is_received === false;
            })->values();

            $list->setCollection($filteredCollection);
        }

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
            ->leftJoin('office', 'office.office_code', '=', 'seq.office_code')
            ->where('seq.control_id', $flow->id)
            ->select('seq.*', 'office.office_name')
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

                $step->is_active_step = (
                    $step->office_code === auth()->user()?->details?->office?->office_code
                    && $step->office_code === $this->selectedTransaction->current_office
                    && $step->sequence_ranking == $this->selectedTransaction->sequence
                    && in_array($this->selectedTransaction->status, ['ongoing', 'revision'])
                    && !is_null($step->date_in)
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

                if ($this->selectedTransaction->status === 'completed' && $step->sequence_ranking == $this->selectedTransaction->sequence) {
                    $step->action_needed = $step->action_needed ?: 'Finished';
                }

                $step->description = $step->note ?: 'Pending office flow step.';
                $step->type = $step->action_needed ?: ($step->date_in ? 'Received' : 'Pending');
                $step->notes = $step->note ?: '';
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

    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('dts_source_office as src', 'src.s_office_code', '=', 'dtd.source_office')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('dts_email_access as dea', 'dea.id', '=', 'dtd.email_access')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->select('dt.*', 'dtd.*', 'req.requestor_name', 'req.requestor_position as requestor_label', 'src.s_office_name as source_office_name', 'dea.email as access_email', 'originated_office.office_name as originated_office_name')
            ->first();

        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number;
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?: '';
            // Fetch the attached document name
            $this->attachedDocName = '';
            if (!empty($this->selectedTransaction->doc_dir)) {
                $docData = DB::table('document_data')->where('document_path', $this->selectedTransaction->doc_dir)->first();
                $this->attachedDocName = $docData->document_name ?? basename($this->selectedTransaction->doc_dir);
            }
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

        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;

        DB::transaction(function () use ($userOfficeCode) {
            DB::table('dts_transactions')
                ->where('transaction_id', $this->selectedTransactionId)
                ->update([
                    'status' => 'completed',
                ]);

            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->selectedTransaction->transaction_flow)->first();
            if ($flow) {
                $lastSeq = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $this->selectedTransaction->sequence)
                    ->first();
                if ($lastSeq) {
                    $duration = $lastSeq->total_time_completed;
                    if (!$duration && $lastSeq->date_in) {
                        $dateIn = \Carbon\Carbon::parse($lastSeq->date_in);
                        $dateOut = now();
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

            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $this->selectedTransactionId,
                'office_code' => $userOfficeCode,
                'type' => 'completed',
                'date_in' => now(),
                'date_out' => now(),
                'notes' => 'Completed transaction flow.',
                'performed_by' => auth()->id(),
            ]);
        });

        $this->closeTransaction();
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
                'total_office' => count($this->cfSelectedOffices),
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

    public function isTransactionReceived(): bool
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return false;
        }

        $log = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $this->selectedTransactionId)
            ->where('office_code', $this->selectedTransaction->current_office)
            ->orderBy('id', 'desc')
            ->first();

        return $log && ($log->type === 'received' || (!empty($log->date_in) && $log->type !== 'forwarded'));
    }

    public function receiveTransaction(?string $transId = null): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_user_received) {
            return;
        }

        $targetId = $transId ?: $this->selectedTransactionId;
        if (!$targetId) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        $trans = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->where('dt.transaction_id', $targetId)
            ->select('dt.*', 'dtd.transaction_flow', 'dtd.originated_from')
            ->first();

        if (!$trans || $trans->current_office !== $userOfficeCode) {
            return;
        }

        DB::transaction(function () use ($targetId, $userOfficeCode, $trans) {
            $log = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $targetId)
                ->where('office_code', $userOfficeCode)
                ->orderBy('id', 'desc')
                ->first();

            if ($log) {
                DB::table('sub_document_tracking_system_logs')
                    ->where('id', $log->id)
                    ->update([
                        'type' => 'received',
                        'date_in' => now(),
                        'performed_by' => auth()->id(),
                    ]);
            } else {
                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $targetId,
                    'office_code' => $userOfficeCode,
                    'type' => 'received',
                    'date_in' => now(),
                    'date_out' => null,
                    'notes' => 'Received at office',
                    'performed_by' => auth()->id(),
                ]);
            }

            $flow = DB::table('dts_transaction_flow')->where('flow_code', $trans->transaction_flow)->first();
            if ($flow) {
                DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $trans->sequence)
                    ->update([
                        'date_in' => now(),
                        'scanned_id' => true,
                    ]);
            }
        });

        if ($this->selectedTransactionId === $targetId) {
            $this->loadSelectedTransaction();
        }
    }

    public function completeTransaction(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_user_received) {
            return;
        }

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
                // Flow sequence complete - keep transaction in Current Transactions for file upload
                // User will explicitly finalize via Complete Transaction double-verification modal.
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

            $reqId = null;
            if (!empty($this->requestorName)) {
                $reqOffice = $this->selectedTransaction->source_office ?? ($this->selectedTransaction->originated_from ?? 'ORIGIN');
                $existingReq = DB::table('dts_requestor_history')
                    ->where('requestor_name', trim($this->requestorName))
                    ->where('office', $reqOffice)
                    ->first();
                if ($existingReq) {
                    $reqId = $existingReq->id;
                    if (!empty($this->requestorPosition)) {
                        DB::table('dts_requestor_history')
                            ->where('id', $existingReq->id)
                            ->update(['requestor_position' => trim($this->requestorPosition)]);
                    }
                } else {
                    $reqId = DB::table('dts_requestor_history')->insertGetId([
                        'requestor_name' => trim($this->requestorName),
                        'requestor_position' => trim($this->requestorPosition ?? ''),
                        'office' => $reqOffice,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
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
                    'requestor_id' => $reqId,
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
    @vite(['resources/css/dts/internal.css', 'resources/css/dts/receive.css', 'resources/css/dts/list_transaction.css'])
@endpush

<div class="dts-page min-h-screen" wire:poll.5s.keep-alive>
    @php
        $perms = auth()->user()?->permissions;
        $isSadm = $perms?->is_sadm ?? false;
        $canListModify = $isSadm || ($perms?->can_dts_list_modify_transaction ?? false) || ($perms?->can_dts_modify_transaction ?? false);
        $canModifyTrans = $canListModify || (isset($this->selectedTransaction) && $this->selectedTransaction->status !== 'completed' && ($perms?->can_dts_modify_transaction ?? false));
        $canProcess = $isSadm || ($perms?->can_dts_user_received ?? false);
    @endphp
    <div class="dts-topbar">
        <div class="dts-nav-group">

            <button wire:click="setTab('all')"
                class="dts-nav-btn dts-nav-btn--back {{ $activeTab === 'all' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive dts-nav-btn--pill' }}">
                <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 19 19" fill="none">
                    <path d="M6.98828 10.8447C7.31464 10.8447 7.57578 10.9523 7.81152 11.1885C8.04812 11.4256 8.15571 11.6876 8.15625 12.0137V17.083C8.15625 17.4094 8.04873 17.6705 7.8125 17.9062C7.57563 18.1426 7.31408 18.25 6.98828 18.25H1.91895C1.59147 18.25 1.33023 18.1422 1.09473 17.9062C0.887473 17.6985 0.77902 17.4723 0.754883 17.2012L0.75 17.082V12.0127C0.750012 11.6863 0.857514 11.4252 1.09375 11.1895C1.30126 10.9824 1.52792 10.8747 1.7998 10.8506L1.91895 10.8447H6.98828ZM17.082 10.8438C17.4084 10.8438 17.6695 10.9513 17.9053 11.1875C18.1124 11.3951 18.221 11.6216 18.2451 11.8936L18.25 12.0127V17.082C18.25 17.4084 18.1425 17.6695 17.9062 17.9053C17.6691 18.1419 17.4072 18.2495 17.0811 18.25H12.0127C11.6852 18.25 11.424 18.1422 11.1885 17.9062C10.9814 17.6987 10.8728 17.4722 10.8486 17.2002L10.8438 17.0811V12.0117C10.8438 11.6853 10.9513 11.4242 11.1875 11.1885C11.3951 10.9814 11.6216 10.8728 11.8936 10.8486L12.0127 10.8438H17.082ZM6.98828 0.75C7.31465 0.75 7.57577 0.857501 7.81152 1.09375C8.01861 1.3013 8.12722 1.52784 8.15137 1.7998L8.15625 1.91895V6.98828C8.15625 7.31465 8.04875 7.57577 7.8125 7.81152C7.57537 8.04812 7.31347 8.15576 6.9873 8.15625H1.91895C1.59147 8.15624 1.33023 8.0485 1.09473 7.8125C0.887637 7.60495 0.779032 7.37841 0.754883 7.10645L0.75 6.9873V1.91797C0.75 1.5916 0.857501 1.33048 1.09375 1.09473C1.3013 0.887637 1.52784 0.779032 1.7998 0.754883L1.91895 0.75H6.98828ZM17.082 0.75C17.4084 0.75 17.6695 0.857501 17.9053 1.09375C18.1124 1.3013 18.221 1.52784 18.2451 1.7998L18.25 1.91895V6.98828C18.25 7.31465 18.1425 7.57577 17.9062 7.81152C17.6691 8.04812 17.4072 8.15576 17.0811 8.15625H12.0127C11.6852 8.15624 11.424 8.0485 11.1885 7.8125C10.9814 7.60495 10.8728 7.37841 10.8486 7.10645L10.8438 6.9873V1.91797C10.8438 1.5916 10.9513 1.33048 11.1875 1.09473C11.3951 0.887637 11.6216 0.779032 11.8936 0.754883L12.0127 0.75H17.082Z" stroke="currentColor" stroke-width="1.5"/>
                </svg>
                All Records
            </button>

            @if ($isSadm || ($perms?->can_dts_use_internal ?? false))
                <button wire:click="setTab('internal')"
                    class="dts-nav-btn {{ $activeTab === 'internal' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                    <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 26 26" fill="none">
                        <path d="M22 22H3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M17.25 22V6.8C17.25 5.0083 17.25 4.1134 16.6933 3.5567C16.1366 3 15.2417 3 13.45 3H11.55C9.75825 3 8.86335 3 8.30665 3.5567C7.74995 4.1134 7.74995 5.0083 7.74995 6.8V22M21.05 22V12.025C21.05 10.6902 21.05 10.0233 20.7298 9.54455C20.5911 9.337 20.413 9.15881 20.2054 9.02015C19.7266 8.7 19.0588 8.7 17.725 8.7M3.94995 22V12.025C3.94995 10.6902 3.94995 10.0233 4.2701 9.54455C4.40876 9.337 4.58695 9.15881 4.7945 9.02015C5.2733 8.7 5.94115 8.7 7.27495 8.7" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M12.5001 22.0001V19.1501M10.6001 5.8501H14.4001M10.6001 8.7001H14.4001M10.6001 11.5501H14.4001M10.6001 14.4001H14.4001" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Internal
                </button>
            @endif

            @if ($isSadm || ($perms?->can_dts_use_external ?? false))
                <button wire:click="setTab('external')"
                    class="dts-nav-btn {{ $activeTab === 'external' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                    <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="17" viewBox="0 0 17 21" fill="none">
                        <path d="M2.89286 19.75C2.32454 19.75 1.77949 19.5276 1.37763 19.1317C0.975765 18.7358 0.75 18.1988 0.75 17.6389V0.75H10.3929L15.75 6.02778V17.6389C15.75 18.1988 15.5242 18.7358 15.1224 19.1317C14.7205 19.5276 14.1755 19.75 13.6071 19.75H2.89286Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M9.32153 0.75V7.08333H15.7501" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                        <path d="M5.03589 11.3055H11.4645M5.03589 15.5278H11.4645" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    External
                </button>
            @endif

            @if ($isSadm || ($perms?->can_dts_use_application ?? false))
                <button wire:click="setTab('applications')"
                    class="dts-nav-btn dts-nav-btn--pill {{ $activeTab === 'applications' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                    <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="15" viewBox="0 0 21 19" fill="none">
                        <path d="M8.25 7.125C8.25 6.91504 8.32902 6.71367 8.46967 6.56521C8.61032 6.41674 8.80109 6.33333 9 6.33333H15C15.1989 6.33333 15.3897 6.41674 15.5303 6.56521C15.671 6.71367 15.75 6.91504 15.75 7.125C15.75 7.33496 15.671 7.53633 15.5303 7.68479C15.3897 7.83326 15.1989 7.91667 15 7.91667H9C8.80109 7.91667 8.61032 7.83326 8.46967 7.68479C8.32902 7.53633 8.25 7.33496 8.25 7.125ZM9 11.0833H15C15.1989 11.0833 15.3897 10.9999 15.5303 10.8515C15.671 10.703 15.75 10.5016 15.75 10.2917C15.75 10.0817 15.671 9.88034 15.5303 9.73187C15.3897 9.58341 15.1989 9.5 15 9.5H9C8.80109 9.5 8.61032 9.58341 8.46967 9.73187C8.32902 9.88034 8.25 10.0817 8.25 10.2917C8.25 10.5016 8.32902 10.703 8.46967 10.8515C8.61032 10.9999 8.80109 11.0833 9 11.0833ZM21 15.8333C21 16.6732 20.6839 17.4786 20.1213 18.0725C19.5587 18.6664 18.7957 19 18 19H7.5C6.70435 19 5.94129 18.6664 5.37868 18.0725C4.81607 17.4786 4.5 16.6732 4.5 15.8333V3.16667C4.5 2.74674 4.34197 2.34401 4.06066 2.04708C3.77936 1.75015 3.39783 1.58333 3 1.58333C2.60218 1.58333 2.22064 1.75015 1.93934 2.04708C1.65804 2.34401 1.5 2.74674 1.5 3.16667C1.5 3.73469 1.95281 4.11865 1.9575 4.1226C2.08163 4.22343 2.17273 4.36275 2.21804 4.52101C2.26334 4.67927 2.26057 4.84853 2.21011 5.00505C2.15965 5.16156 2.06404 5.29747 1.93668 5.39371C1.80933 5.48995 1.65663 5.54169 1.5 5.54167C1.33782 5.54189 1.18006 5.48592 1.05094 5.38234C0.942187 5.29823 0 4.51349 0 3.16667C0 2.32681 0.316071 1.52136 0.87868 0.927495C1.44129 0.33363 2.20435 0 3 0H15.75C16.5457 0 17.3087 0.33363 17.8713 0.927495C18.4339 1.52136 18.75 2.32681 18.75 3.16667V13.4583H19.5C19.6623 13.4583 19.8202 13.5139 19.95 13.6167C20.0625 13.7018 21 14.4865 21 15.8333ZM8.27438 14.0006C8.32562 13.841 8.42342 13.7025 8.55376 13.6051C8.6841 13.5077 8.84031 13.4563 9 13.4583H17.25V3.16667C17.25 2.74674 17.092 2.34401 16.8107 2.04708C16.5294 1.75015 16.1478 1.58333 15.75 1.58333H5.59594C5.86124 2.06396 6.00069 2.61041 6 3.16667V15.8333C6 16.2533 6.15804 16.656 6.43934 16.9529C6.72065 17.2499 7.10218 17.4167 7.5 17.4167C7.89783 17.4167 8.27936 17.2499 8.56066 16.9529C8.84197 16.656 9 16.2533 9 15.8333C9 15.2653 8.54719 14.8814 8.5425 14.8774C8.41469 14.7809 8.31963 14.6436 8.27136 14.4857C8.22308 14.3279 8.22414 14.1578 8.27438 14.0006ZM19.5 15.8333C19.4906 15.54 19.3834 15.2596 19.1972 15.0417H10.3847C10.46 15.298 10.4982 15.5649 10.4981 15.8333C10.4989 16.3893 10.3602 16.9357 10.0959 17.4167H18C18.3978 17.4167 18.7794 17.2499 19.0607 16.9529C19.342 16.656 19.5 16.2533 19.5 15.8333Z" fill="currentColor"/>
                    </svg>
                    Application
                </button>
            @endif

            @if ($isSadm || ($perms?->can_dts_use_issuance ?? false))
                <button wire:click="setTab('issuances')"
                    class="dts-nav-btn dts-nav-btn--pill {{ $activeTab === 'issuances' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                    <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="17" viewBox="0 0 21 22" fill="none">
                        <path d="M6.25 3.35718H3.41667C3.04094 3.35718 2.68061 3.50016 2.41493 3.75468C2.14926 4.00919 2 4.35438 2 4.71432V19.6429C2 20.0028 2.14926 20.348 2.41493 20.6025C2.68061 20.8571 3.04094 21 3.41667 21H17.5833C17.9591 21 18.3194 20.8571 18.5851 20.6025C18.8507 20.348 19 20.0028 19 19.6429V4.71432C19 4.35438 18.8507 4.00919 18.5851 3.75468C18.3194 3.50016 17.9591 3.35718 17.5833 3.35718H14.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M9.08325 8.78571H16.1666M9.08325 12.8571H16.1666M9.08325 16.9286H16.1666M4.83325 8.78571H6.24992M4.83325 12.8571H6.24992M4.83325 16.9286H6.24992M7.66659 2H13.3333C13.709 2 14.0693 2.14298 14.335 2.3975C14.6007 2.65201 14.7499 2.99721 14.7499 3.35714C14.7499 3.71708 14.6007 4.06227 14.335 4.31679C14.0693 4.5713 13.709 4.71429 13.3333 4.71429H7.66659C7.29086 4.71429 6.93053 4.5713 6.66485 4.31679C6.39917 4.06227 6.24992 3.71708 6.24992 3.35714C6.24992 2.99721 6.39917 2.65201 6.66485 2.3975C6.93053 2.14298 7.29086 2 7.66659 2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Issuances
                </button>
            @endif
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="button" wire:click="toggleGroupByOffice" class="dts-nav-btn" style="border: 1.5px solid {{ $groupByOffice ? '#003699' : 'var(--border-gray)' }}; background: {{ $groupByOffice ? '#003699' : 'white' }}; color: {{ $groupByOffice ? '#ffffff' : 'inherit' }}; font-weight: 600; white-space: nowrap; height: 42px; box-sizing: border-box; display: inline-flex; align-items: center; padding: 0 16px; border-radius: 8px; font-size: 13px; cursor: pointer; outline: none; transition: all 0.2s ease; font-family: 'Inter', sans-serif;">
                <i class="fa-solid fa-folder-tree" style="margin-right: 6px;"></i>
                {{ $groupByOffice ? 'Grouped by Office' : 'Group by Office' }}
            </button>

            <button type="button" wire:click="toggleLayout" class="dts-nav-btn" style="border: 1.5px solid var(--border-gray); background: white; white-space: nowrap; height: 42px; box-sizing: border-box;">
                @if ($layoutMode === 'table')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Grid View
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Table View
                @endif
            </button>

            <div class="dts-search-wrap">
                <input
                    type="text"
                    wire:model.live="searchQuery"
                    placeholder="Search control number, subject..."
                    class="dts-search-input"
                    style="margin-left: 0; padding-left: 40px; height: 42px;"
                />
                <svg class="dts-search-icon" style="left: 12px;" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 25 25" fill="none">
                    <path d="M17.1088 17.1091L21.1345 21.1347M3.01904 11.0706C3.01904 13.2059 3.8673 15.2538 5.37722 16.7637C6.88713 18.2736 8.93501 19.1219 11.0703 19.1219C13.2057 19.1219 15.2536 18.2736 16.7635 16.7637C18.2734 15.2538 19.1217 13.2059 19.1217 11.0706C19.1217 8.93525 18.2734 6.88737 16.7635 5.37746C15.2536 3.86755 13.2057 3.01929 11.0703 3.01929C8.93501 3.01929 6.88713 3.86755 5.37722 5.37746C3.8673 6.88737 3.01904 8.93525 3.01904 11.0706Z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
    </div>

    @if ($layoutMode === 'table')
        <div class="rms-table-responsive max-w-full mx-auto" style="background: white;">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th>SUBJECT</th>
                        <th>REQUESTOR</th>
                        <th>CONTROL NO.</th>
                        <th>DOC TYPE</th>
                        <th>ORIGINATOR</th>
                        <th>RECEIVED</th>
                        <th>NEXT OFFICE</th>
                        <th>ACTION NEEDED</th>
                        <th>ELAPSED DAY</th>
                        <th>STATUS</th>
                        <th style="width: 100px; text-align: center;">Action</th>
                    </tr>
                </thead>

                <tbody>
                    @if ($groupByOffice)
                        @php
                            $groupedTransactions = collect($this->transactions->items())->groupBy('current_office_name');
                        @endphp
                        @forelse ($groupedTransactions as $officeName => $items)
                            <tr class="office-divider-row">
                                <td colspan="11" style="padding: 14px 12px; background: #f8fafc; font-weight: 700; color: #475569; letter-spacing: 0.05em; font-size: 12px; font-family: 'Inter', sans-serif;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 14px;">
                                        <div style="flex: 1; height: 1px; background: #cbd5e1;"></div>
                                        <div style="text-transform: uppercase; display: flex; align-items: center; gap: 8px;">
                                            <i class="fa-solid fa-building" style="color: #003699;"></i>
                                            <span>{{ $officeName ?: 'Unassigned Office' }}</span>
                                        </div>
                                        <div style="flex: 1; height: 1px; background: #cbd5e1;"></div>
                                    </div>
                                </td>
                            </tr>
                            @php
                                $sortedItems = $items->sortBy(function($t) {
                                    if ($t->diff_in_minutes > 60) return 1;
                                    if ($t->diff_in_minutes >= 10) return 2;
                                    return 3;
                                });
                            @endphp
                            @foreach ($sortedItems as $t)
                                <tr>
                                    <td style="max-width: 300px; white-space: normal; word-break: break-word;">{{ $t->subject }}</td>
                                     <td style="white-space: nowrap;">
                                         {{ $t->requestor_name }}
                                         @if(!empty($t->requestor_label))
                                             <div style="font-size: 11px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</div>
                                         @endif
                                     </td>
                                    <td style="font-weight: 600; color: #1e40af; text-align: center;">{{ $t->control_number }}</td>
                                    <td>{{ (!empty($t->doc_type_name) && !str_starts_with($t->doc_type_name, 'Flow for ')) ? $t->doc_type_name : ucfirst($t->trans_type) }}</td>
                                    <td>{{ $t->originated_office_name ?? 'N/A' }}</td>
                                    <td>{{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</td>
                                    <td>{{ $t->next_office_name }}</td>
                                    <td style="color: #16a34a; font-weight: 500;">{{ $t->action_needed ?? 'For action' }}</td>
                                    <td style="color: #dc2626; font-weight: 600; white-space: nowrap; text-align: center;">{{ $t->elapsed_days }} day(s)</td>
                                    <td style="text-align: center;">
                                        <span class="status-badge status-{{ $t->status }}">
                                            {{ $t->status }}
                                        </span>
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        @if(request()->routeIs('dts.incoming'))
                                            <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                                <i class="fa-solid fa-qrcode"></i> Scan
                                            </button>
                                        @elseif(request()->routeIs('dts.received'))
                                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                                <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                                <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                                    <i class="fa-solid fa-qrcode"></i> Scan
                                                </button>
                                            </div>
                                        @else
                                            <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td class="rms-no-data" colspan="11">No records found.</td>
                            </tr>
                        @endforelse
                    @else
                        @forelse ($this->transactions as $t)
                            <tr>
                                <td style="max-width: 300px; white-space: normal; word-break: break-word;">{{ $t->subject }}</td>
                                 <td style="white-space: nowrap;">
                                     {{ $t->requestor_name }}
                                     @if(!empty($t->requestor_label))
                                         <div style="font-size: 11px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</div>
                                     @endif
                                 </td>
                                <td style="font-weight: 600; color: #1e40af; text-align: center;">{{ $t->control_number }}</td>
                                <td>{{ (!empty($t->doc_type_name) && !str_starts_with($t->doc_type_name, 'Flow for ')) ? $t->doc_type_name : ucfirst($t->trans_type) }}</td>
                                <td>{{ $t->originated_office_name ?? 'N/A' }}</td>
                                <td>{{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</td>
                                <td>{{ $t->next_office_name }}</td>
                                <td style="color: #16a34a; font-weight: 500;">{{ $t->action_needed ?? 'For action' }}</td>
                                <td style="color: #dc2626; font-weight: 600; white-space: nowrap; text-align: center;">{{ $t->elapsed_days }} day(s)</td>
                                <td style="text-align: center;">
                                    <span class="status-badge status-{{ $t->status }}">
                                        {{ $t->status }}
                                    </span>
                                </td>
                                <td style="text-align: center; white-space: nowrap;">
                                    @if(request()->routeIs('dts.incoming'))
                                        <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                            <i class="fa-solid fa-qrcode"></i> Scan
                                        </button>
                                    @elseif(request()->routeIs('dts.received'))
                                        <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                            <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                            <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                                <i class="fa-solid fa-qrcode"></i> Scan
                                            </button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="rms-no-data" colspan="11">No records found.</td>
                            </tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
        </div>
    @else
        <!-- Box Layout (Card Grid) -->
        <div class="dts-card-grid-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 20px; margin-bottom: 20px;">
            @if ($groupByOffice)
                @php
                    $groupedTransactions = collect($this->transactions->items())->groupBy('current_office_name');
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
                        <div class="dts-box-card" style="background: white; border: 1.5px solid var(--border-gray); border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                        @php
                            $minutes = $t->diff_in_minutes ?? 0;
                            if ($minutes <= 10) {
                                $indicatorColor = '#10b981'; // Green: 0s -> 10m
                                $indicatorTitle = 'New (0 - 10 mins)';
                            } elseif ($minutes <= 60) {
                                $indicatorColor = '#f59e0b'; // Yellow: >10m -> 1h
                                $indicatorTitle = 'Pending (10 mins - 1 hr)';
                            } else {
                                $indicatorColor = '#ef4444'; // Red: >1h
                                $indicatorTitle = 'Urgent (More than 1 hr)';
                            }
                        @endphp
                        <!-- Top Header: Indicator + Elapsed Time on Left, QR Code on Far Right -->
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #6b7280; font-weight: 500;">
                                <span style="display: inline-flex; width: 18px; height: 18px; background-color: {{ $indicatorColor }}; transform: rotate(45deg); align-items: center; justify-content: center; border-radius: 3px; flex-shrink: 0;" title="{{ $indicatorTitle }}">
                                    <span style="transform: rotate(-45deg); color: white; font-size: 10px; font-weight: bold;">!</span>
                                </span>
                                <span>{{ \Carbon\Carbon::parse($t->date_received)->diffForHumans(null, true) }} ago</span>
                            </div>

                            @if(!empty($t->qr_code))
                                <div style="font-size: 11px; font-weight: 700; color: #1e40af; background: #eff6ff; padding: 3px 8px; border-radius: 6px; border: 1px solid #bfdbfe; font-family: monospace;" title="QR Code">
                                    <i class="fa-solid fa-qrcode" style="margin-right: 4px;"></i>{{ $t->qr_code }}
                                </div>
                            @endif
                        </div>

                            <!-- Card Body contents -->
                            <div style="font-size: 13px; color: #4b5563; line-height: 1.6; margin-top: 12px; font-family: Roboto, sans-serif;">
                                <div style="margin-bottom: 6px; word-break: break-word; overflow-wrap: break-word; white-space: normal;"><strong>Subject:</strong> {{ $t->subject }}</div>
                                <div style="margin-bottom: 6px;"><strong>Name of Requestor:</strong> {{ $t->requestor_name }} @if(!empty($t->requestor_label)) <span style="font-size: 12px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</span> @endif</div>
                                <div style="margin-bottom: 6px;"><strong>Control Number:</strong> <span style="font-weight: 600; color: #1e40af;">{{ $t->control_number }}</span></div>
                                <div style="margin-bottom: 14px;"><strong>Type of Document:</strong> {{ (!empty($t->doc_type_name) && !str_starts_with($t->doc_type_name, 'Flow for ')) ? $t->doc_type_name : ucfirst($t->trans_type) }}</div>

                                <div style="margin-bottom: 6px;"><strong>Originator:</strong> <span style="color: #ef4444; font-weight: 500;">{{ $t->originated_office_name ?? 'N/A' }}</span></div>
                                <div style="margin-bottom: 14px;"><strong>Receive Date:</strong> {{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</div>

                                <div style="margin-bottom: 14px;"><strong>Current Office:</strong> {{ $t->current_office_name }}</div>

                                <div style="margin-bottom: 6px;"><strong>Action Needed:</strong> <span style="color: #16a34a; font-weight: 600;">{{ $t->action_needed ?? 'For action' }}</span></div>
                                <div style="margin-bottom: 6px;"><strong>Elapsed Day:</strong> <span style="color: #ef4444; font-style: italic;">{{ $t->elapsed_days }} day(s)</span></div>
                            </div>

                            <!-- Card Footer action -->
                            <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 16px;">
                                @if(request()->routeIs('dts.incoming'))
                                    <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                        <i class="fa-solid fa-qrcode"></i> Scan
                                    </button>
                                @elseif(request()->routeIs('dts.received'))
                                    <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                    <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                        <i class="fa-solid fa-qrcode"></i> Scan
                                    </button>
                                @else
                                    <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @empty
                    <div style="grid-column: 1 / -1; background: white; border-radius: 12px; padding: 40px; text-align: center; color: #9CA3AF; font-style: italic; border: 1.5px solid var(--border-gray);">
                        No records found.
                    </div>
                @endforelse
            @else
                @forelse ($this->transactions as $t)
                    <div class="dts-box-card" style="background: white; border: 1.5px solid var(--border-gray); border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                        @php
                            $minutes = $t->diff_in_minutes ?? 0;
                            if ($minutes <= 10) {
                                $indicatorColor = '#10b981'; // Green: 0s -> 10m
                                $indicatorTitle = 'New (0 - 10 mins)';
                            } elseif ($minutes <= 60) {
                                $indicatorColor = '#f59e0b'; // Yellow: >10m -> 1h
                                $indicatorTitle = 'Pending (10 mins - 1 hr)';
                            } else {
                                $indicatorColor = '#ef4444'; // Red: >1h
                                $indicatorTitle = 'Urgent (More than 1 hr)';
                            }
                        @endphp
                        <!-- Top Header: Indicator + Elapsed Time on Left, QR Code on Far Right -->
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: #6b7280; font-weight: 500;">
                                <span style="display: inline-flex; width: 18px; height: 18px; background-color: {{ $indicatorColor }}; transform: rotate(45deg); align-items: center; justify-content: center; border-radius: 3px; flex-shrink: 0;" title="{{ $indicatorTitle }}">
                                    <span style="transform: rotate(-45deg); color: white; font-size: 10px; font-weight: bold;">!</span>
                                </span>
                                <span>{{ \Carbon\Carbon::parse($t->date_received)->diffForHumans(null, true) }} ago</span>
                            </div>

                            @if(!empty($t->qr_code))
                                <div style="font-size: 11px; font-weight: 700; color: #1e40af; background: #eff6ff; padding: 3px 8px; border-radius: 6px; border: 1px solid #bfdbfe; font-family: monospace;" title="QR Code">
                                    <i class="fa-solid fa-qrcode" style="margin-right: 4px;"></i>{{ $t->qr_code }}
                                </div>
                            @endif
                        </div>

                        <!-- Card Body contents -->
                        <div style="font-size: 13px; color: #4b5563; line-height: 1.6; margin-top: 12px; font-family: Roboto, sans-serif;">
                            <div style="margin-bottom: 6px; word-break: break-word; overflow-wrap: break-word; white-space: normal;"><strong>Subject:</strong> {{ $t->subject }}</div>
                            <div style="margin-bottom: 6px;"><strong>Name of Requestor:</strong> {{ $t->requestor_name }} @if(!empty($t->requestor_label)) <span style="font-size: 12px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</span> @endif</div>
                            <div style="margin-bottom: 6px;"><strong>Control Number:</strong> <span style="font-weight: 600; color: #1e40af;">{{ $t->control_number }}</span></div>
                            <div style="margin-bottom: 14px;"><strong>Type of Document:</strong> {{ (!empty($t->doc_type_name) && !str_starts_with($t->doc_type_name, 'Flow for ')) ? $t->doc_type_name : ucfirst($t->trans_type) }}</div>

                            <div style="margin-bottom: 6px;"><strong>Originator:</strong> <span style="color: #ef4444; font-weight: 500;">{{ $t->originated_office_name ?? 'N/A' }}</span></div>
                            <div style="margin-bottom: 14px;"><strong>Receive Date:</strong> {{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</div>

                            <div style="margin-bottom: 14px;"><strong>Current Office:</strong> {{ $t->current_office_name }}</div>

                            <div style="margin-bottom: 6px;"><strong>Action Needed:</strong> <span style="color: #16a34a; font-weight: 600;">{{ $t->action_needed ?? 'For action' }}</span></div>
                            <div style="margin-bottom: 6px;"><strong>Elapsed Day:</strong> <span style="color: #ef4444; font-style: italic;">{{ $t->elapsed_days }} day(s)</span></div>
                        </div>

                        <!-- Card Footer action -->
                        <div style="display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 16px;">
                            @if(request()->routeIs('dts.incoming'))
                                <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                    <i class="fa-solid fa-qrcode"></i> Scan
                                </button>
                            @elseif(request()->routeIs('dts.received'))
                                <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                                <button type="button" onclick="if(window.openScannerModal) window.openScannerModal('{{ $t->control_number }}');" class="rms-select" style="text-decoration: none; display: inline-flex; align-items: center; gap: 4px; border: none; background: transparent; cursor: pointer; color: #0284c7; font-weight: 600;">
                                    <i class="fa-solid fa-qrcode"></i> Scan
                                </button>
                            @else
                                <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div style="grid-column: 1 / -1; background: white; border-radius: 12px; padding: 40px; text-align: center; color: #9CA3AF; font-style: italic; border: 1.5px solid var(--border-gray);">
                        No records found.
                    </div>
                @endforelse
            @endif
        </div>
    @endif

    <div class="dts-footer">
        <span class="dts-footer-total">Total Records: <strong>{{ $this->transactions->total() }}</strong></span>
        <div class="dts-pagination">
            @if ($this->transactions->onFirstPage())
                <button type="button" class="dts-page-btn" style="cursor: not-allowed; opacity: 0.5;" disabled>← Previous</button>
            @else
                <button type="button" class="dts-page-btn" wire:click="previousPage">← Previous</button>
            @endif
            <span class="dts-page-indicator">Page <strong>{{ $this->transactions->currentPage() }}</strong> of <strong>{{ $this->transactions->lastPage() }}</strong></span>
            @if ($this->transactions->hasMorePages())
                <button type="button" class="dts-page-btn" wire:click="nextPage">Next →</button>
            @else
                <button type="button" class="dts-page-btn" style="cursor: not-allowed; opacity: 0.5;" disabled>Next →</button>
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
                                        @php
                                            $fDisplay = $f->referenced_flow ?: $f->flow_name;
                                            if (!empty($fDisplay) && preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $fDisplay, $matches)) {
                                                $rName = DB::table('dts_transaction_flow')->where('id', $matches[1])->value('flow_name');
                                                if (!empty($rName)) {
                                                    $fDisplay = $rName;
                                                }
                                            }
                                        @endphp
                                        <option value="{{ $f->flow_code }}">{{ $fDisplay }}</option>
                                    @endforeach
                                </select>
                            @else
                                @php
                                    $flowRow = DB::table('dts_transaction_flow')->where('flow_code', $transactionFlow)->first();
                                    $flowName = $flowRow?->referenced_flow ?: ($flowRow?->flow_name ?: $transactionFlow);
                                    if (!empty($flowName) && preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $flowName, $matches)) {
                                        $rName = DB::table('dts_transaction_flow')->where('id', $matches[1])->value('flow_name');
                                        if (!empty($rName)) {
                                            $flowName = $rName;
                                        }
                                    }
                                    if (!empty($flowName) && str_starts_with($flowName, 'Flow for ')) {
                                        $flowName = ucfirst($selectedTransaction->trans_type ?? 'Internal');
                                    }
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
                                    <i class="fa-solid fa-table-cells"></i> Table View
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
                                    bottom: 68px;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    width: 250px;
                                    background: #0f172a;
                                    color: #f8fafc;
                                    padding: 12px 14px;
                                    border-radius: 10px;
                                    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
                                    border: 1px solid #334155;
                                    opacity: 0;
                                    visibility: hidden;
                                    pointer-events: none;
                                    transition: opacity 0.15s ease, visibility 0.15s ease;
                                    z-index: 9999;
                                    font-family: 'Inter', sans-serif;
                                    text-align: left;
                                }
                                .dts-timeline-node-wrapper .dts-node-tooltip::after {
                                    content: '';
                                    position: absolute;
                                    top: 100%;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    border-width: 6px;
                                    border-style: solid;
                                    border-color: #0f172a transparent transparent transparent;
                                }
                                .dts-timeline-node-wrapper:first-child .dts-node-tooltip {
                                    left: -10px;
                                    transform: none;
                                }
                                .dts-timeline-node-wrapper:first-child .dts-node-tooltip::after {
                                    left: 26px;
                                    transform: none;
                                }
                                .dts-timeline-node-wrapper:last-child .dts-node-tooltip {
                                    left: auto;
                                    right: -10px;
                                    transform: none;
                                }
                                .dts-timeline-node-wrapper:last-child .dts-node-tooltip::after {
                                    left: auto;
                                    right: 26px;
                                    transform: none;
                                }
                                .dts-timeline-node-wrapper:hover .dts-node-tooltip {
                                    opacity: 1;
                                    visibility: visible;
                                }
                                .dts-timeline-node-dot {
                                    transition: all 0.2s ease;
                                }
                                .dts-timeline-node-wrapper:hover .dts-timeline-node-dot {
                                    transform: scale(1.25) !important;
                                    box-shadow: 0 0 0 6px rgba(16, 185, 129, 0.4) !important;
                                }
                            </style>

                            <!-- Horizontal Progress Line Graph (Transparent Side-Fit Box with Hover Tooltips) -->
                            <div style="width: 100%; overflow: visible; padding: 165px 60px 20px 60px; box-sizing: border-box; background: transparent; border: none; margin-top: 4px; margin-bottom: 12px; position: relative;">
                                <div style="display: flex; align-items: center; justify-content: space-between; min-width: max-content; padding: 0; position: relative;">
                                    @forelse ($this->visiblePath as $index => $step)
                                        @php
                                            $isReceived = !is_null($step->date_in) || $selectedTransaction->status === 'completed';
                                            $isForwarded = !is_null($step->date_out) || $selectedTransaction->status === 'completed';

                                            $dotColor = $isReceived ? '#10b981' : '#dc2626';
                                            $lineColor = $isForwarded ? '#10b981' : '#dc2626';

                                            $isCurrentOffice = $step->is_active_step && !is_null($step->date_in) && is_null($step->date_out) && $selectedTransaction->status !== 'completed';
                                            $isInTransitToThisOffice = $step->is_active_step && is_null($step->date_in) && $selectedTransaction->status !== 'completed';
                                        @endphp

                                        <!-- Node Wrapper -->
                                        <div class="dts-timeline-node-wrapper">
                                            
                                            <!-- Current Office or In-Transit Indicator Above Dot (No Brackets) -->
                                            @if ($isCurrentOffice)
                                                <div style="position: absolute; bottom: 42px; display: flex; flex-direction: column; align-items: center; white-space: nowrap; z-index: 5;">
                                                    <span style="font-size: 11.5px; font-weight: 800; color: #10b981; text-transform: uppercase; letter-spacing: 0.5px; text-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                        Transaction Holder
                                                    </span>
                                                    <span style="font-size: 14px; font-weight: 900; color: #10b981; margin-top: -2px;">v</span>
                                                </div>
                                            @elseif ($isInTransitToThisOffice)
                                                <div style="position: absolute; bottom: 42px; display: flex; flex-direction: column; align-items: center; white-space: nowrap; z-index: 5;">
                                                    <span style="font-size: 11px; font-weight: 800; color: #f59e0b; text-transform: uppercase; letter-spacing: 0.5px; text-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                        In Transit to {{ $step->office_code }}
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

                                            <!-- Hover Tooltip Card (Appears on Hover) -->
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
                                                <td>{{ $index + 1 }}</td>
                                                <td class="office-cell">
                                                    <strong>{{ $step->office_code }}</strong> - {{ $step->office_name }}
                                                </td>
                                                <td>{{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('Y-m-d h:i A') : 'N/A' }}</td>
                                                <td>{{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('Y-m-d h:i A') : ($step->date_in ? 'Pending' : 'N/A') }}</td>
                                                <td>{{ ($step->total_time_completed ?? null) ?: '-' }}</td>
                                                <td>
                                                    @if ($step->is_active_step && $canProcess && is_null($step->date_out) && $selectedTransaction->status !== 'completed')
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
                                                    @if ($step->is_active_step && $canProcess && is_null($step->date_out) && $selectedTransaction->status !== 'completed')
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

                        <!-- RECEIVE / FORWARD / COMPLETE / UPLOAD -->
                        @php
                            $allowManualComplete = \DB::table('system_settings')->where('key', 'dts_allow_manual_completion_button')->value('value') === 'true';
                            $isReceivedState = $this->isTransactionReceived();
                        @endphp
                        @if ($selectedTransaction->current_office === auth()->user()?->details?->office?->office_code && $canProcess && $selectedTransaction->status !== 'completed')
                            @if (!$isReceivedState)
                                <button type="button" class="receive-action-btn" wire:click="receiveTransaction" style="background-color: #16a34a;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="7 10 12 15 17 10"/>
                                        <line x1="12" y1="15" x2="12" y2="3"/>
                                    </svg>
                                    RECEIVE TRANSACTION
                                </button>
                            @else
                                @if ($this->isLastStep())
                                    <button type="button" class="receive-action-btn" wire:click="triggerCompletionConfirm" style="background-color: #16a34a;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                        Complete Transaction
                                    </button>
                                @else
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
                <div style="padding: 24px; font-family: Roboto, sans-serif;">
                    <p style="margin: 0 0 14px 0; font-size: 14px; font-weight: 600; color: #1e293b; line-height: 1.5;">
                        Are you sure you want to complete this transaction?
                    </p>

                    @php
                        $hasUploadedFile = !empty($selectedTransaction->doc_dir) || !empty($selectedTransaction->copy_filled_id);
                    @endphp

                    @if (!$hasUploadedFile)
                        <div style="background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: 10px; padding: 12px 14px; color: #b45309; font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px; color: #d97706; flex-shrink: 0;"></i>
                            <span>This document still has no upload file bind to it.</span>
                        </div>
                    @else
                        <div style="background: #f0fdf4; border: 1.5px solid #22c55e; border-radius: 10px; padding: 12px 14px; color: #15803d; font-size: 13px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-circle-check" style="font-size: 16px; color: #16a34a; flex-shrink: 0;"></i>
                            <span>Circulated document file is attached and bound.</span>
                        </div>
                    @endif

                    @if ($selectedTransaction)
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; font-size: 12.5px; color: #475569;">
                            <div style="margin-bottom: 4px;"><strong>Control No:</strong> <span style="font-family: monospace; font-weight: 700; color: #1e40af;">{{ $selectedTransaction->control_number }}</span></div>
                            <div><strong>Subject:</strong> {{ $selectedTransaction->subject }}</div>
                        </div>
                    @endif
                </div>

                <!-- Modal Footer -->
                <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 12px;">
                    <button type="button" wire:click="cancelCompletionConfirm" style="padding: 9px 18px; border-radius: 8px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #475569; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmAndCompleteTransaction" style="padding: 9px 20px; border-radius: 8px; border: none; background: #16a34a; color: #ffffff; font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25); transition: background 0.15s;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">
                        <i class="fa-solid fa-check"></i> Yes
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

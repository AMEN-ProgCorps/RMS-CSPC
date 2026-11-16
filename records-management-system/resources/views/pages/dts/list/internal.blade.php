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
    public string $selectedOffice = 'all';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sortOrder = 'desc';
    public string $statusOrder = 'ongoing_first'; // ongoing_first, completed_first, none
    public int $perPage = 10;
    public string $searchQuery = '';
    public string $layoutMode = 'table'; // table or box
    public string $pathViewMode = 'timeline'; // timeline or table

    /**
     * Cans view all.
     * @return bool
     */
    public function canViewAll(): bool
    {
        $perms = auth()->user()?->permissions;
        return (bool)($perms?->is_sadm ?? false) || (bool)($perms?->can_dts_view_all_list ?? false);
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        $canViewAll = $this->canViewAll();
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officesList = $canViewAll
            ? DB::table($officeTbl)
                ->where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
                ->orderBy('office_name', 'asc')
                ->get()
            : collect();

        return [
            'canViewAll'  => $canViewAll,
            'officesList' => $officesList,
        ];
    }

    /**
     * Initializes component state, authorization gates, and default configurations.
     * @return void
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_internal) {
            abort(403, 'Unauthorized access to Internal transactions list.');
        }
    }

    // Export Modal Properties
    public bool $showExportModal = false;
    public string $exportFormat = 'pdf';
    public array $exportColumns = [];
    public int $exportFlowCount = 0;
    public string $exportPreparedBy = '';
    public string $exportNotedBy = '';

    /**
     * Displays the export modal.
     * @return void
     */
    public function openExportModal(): void
    {
        $this->exportFlowCount = 0;
        $available = \App\Helpers\DtsExportHelper::getAvailableColumns('internal', 0);
        $this->exportColumns = array_keys(array_filter($available, fn($c) => $c['default']));
        $user = auth()->user();
        $this->exportPreparedBy = $user?->details?->first_name ? ($user->details->first_name . ' ' . $user->details->last_name) : ($user?->name ?? '');
        $this->exportNotedBy = '';
        $this->showExportModal = true;
    }

    /**
     * Closes the export modal.
     * @return void
     */
    public function closeExportModal(): void
    {
        $this->showExportModal = false;
    }

    /**
     * Creates a new export flow record.
     * @return void
     */
    public function addExportFlow(): void
    {
        $this->exportFlowCount++;
        $newIndex = $this->exportFlowCount;
        $newCols = array_keys(\App\Helpers\DtsExportHelper::getFlowColumnDefinitions($newIndex));
        $this->exportColumns = array_values(array_unique(array_merge($this->exportColumns, $newCols)));
    }

    /**
     * Deletes or deactivates the specified export flow.
     *
     * @param int $flowIndex
     *
     * @return void
     */
    public function removeExportFlow(int $flowIndex): void
    {
        if ($flowIndex < 1 || $flowIndex > $this->exportFlowCount) {
            return;
        }

        $updated = [];
        foreach ($this->exportColumns as $col) {
            if (preg_match('/^flow(\d+)_(.+)$/', $col, $matches)) {
                $idx = (int)$matches[1];
                $field = $matches[2];
                if ($idx < $flowIndex) {
                    $updated[] = $col;
                } elseif ($idx > $flowIndex) {
                    $newIdx = $idx - 1;
                    $updated[] = "flow{$newIdx}_{$field}";
                }
            } else {
                $updated[] = $col;
            }
        }

        $this->exportColumns = $updated;
        $this->exportFlowCount = max(0, $this->exportFlowCount - 1);
    }

    /**
     * Toggles the flow step columns setting or state.
     *
     * @param int $flowIndex
     *
     * @return void
     */
    public function toggleFlowStepColumns(int $flowIndex): void
    {
        $fKeys = array_keys(\App\Helpers\DtsExportHelper::getFlowColumnDefinitions($flowIndex));
        $hasAll = count(array_intersect($fKeys, $this->exportColumns)) === count($fKeys);
        if ($hasAll) {
            $this->exportColumns = array_values(array_diff($this->exportColumns, $fKeys));
        } else {
            $this->exportColumns = array_values(array_unique(array_merge($this->exportColumns, $fKeys)));
        }
    }

    /**
     * Selects all export columns and populates the details panel.
     * @return void
     */
    public function selectAllExportColumns(): void
    {
        $available = \App\Helpers\DtsExportHelper::getAvailableColumns('internal', $this->exportFlowCount);
        $this->exportColumns = array_keys($available);
    }

    /**
     * Resets default export columns and clears active selections.
     * @return void
     */
    public function resetDefaultExportColumns(): void
    {
        $this->exportFlowCount = 0;
        $available = \App\Helpers\DtsExportHelper::getAvailableColumns('internal', 0);
        $this->exportColumns = array_keys(array_filter($available, fn($c) => $c['default']));
    }

    /**
     * Deselects all export columns.
     * @return void
     */
    public function deselectAllExportColumns(): void
    {
        $this->exportColumns = [];
    }

    /**
     * Executes export.
     */
    public function executeExport()
    {
        if (empty($this->selectedIds)) {
            return;
        }

        $available = \App\Helpers\DtsExportHelper::resolveAllColumns('internal', $this->exportColumns);
        $colsToUse = !empty($this->exportColumns) ? $this->exportColumns : array_keys(array_filter($available, fn($c) => $c['default']));

        $filters = [
            'sort_order'   => $this->sortOrder,
            'status_order' => $this->statusOrder,
            'office'       => $this->selectedOffice,
        ];

        if ($this->exportFormat === 'excel') {
            $rows = \App\Helpers\DtsExportHelper::fetchExportRecords('internal', $filters, $this->selectedIds);
            $filename = 'dts-internal-transactions-' . now()->format('Y-m-d') . '.xls';
            $meta = [
                'title'       => \App\Helpers\DtsExportHelper::getCategoryTitle('internal'),
                'office_name' => auth()->user()?->details?->office?->office_name ?? 'Records Management System',
                'prepared_by' => $this->exportPreparedBy,
                'noted_by'    => $this->exportNotedBy,
            ];
            $this->showExportModal = false;
            return \App\Helpers\DtsExportHelper::exportExcel($filename, $colsToUse, $rows, $available, $meta);
        }

        // PDF / Print format
        $params = [
            'category'    => 'internal',
            'ids'         => implode(',', $this->selectedIds),
            'cols'        => implode(',', $colsToUse),
            'sort_order'   => $this->sortOrder,
            'status_order' => $this->statusOrder,
            'office'       => $this->selectedOffice,
            'prepared_by' => $this->exportPreparedBy,
            'noted_by'    => $this->exportNotedBy,
        ];

        $printUrl = route('dts.list.print', $params);
        $this->showExportModal = false;
        $this->js("window.open('{$printUrl}', '_blank')");
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
    public string $forwardToOfficeCode = '';
    public string $activeNotes = '';

    /**
     * Retrieves and populates action options property data.
     * @return array
     */
    public function getActionOptionsProperty(): array
    {
        $canForwardOtherOffice = \App\Services\DtsRoutingService::canForwardToOtherOffice();
        $query = DB::table('dts_action_options')->orderBy('option_name', 'asc');
        if (!$canForwardOtherOffice) {
            $query->where('option_name', '!=', 'To be Forwarded to');
        }
        return $query->pluck('option_name')->toArray();
    }

    // Forward HUB Selection Modal State
    public bool $showForwardHubModal = false;
    public string $forwardHubSearch = '';
    public array $forwardHubOffices = [];
    public array $forwardHubTempSelected = [];

    /**
     * Displays the forward hub modal.
     * @return void
     */
    public function openForwardHubModal(): void
    {
        $this->forwardHubTempSelected = $this->forwardHubOffices;
        $this->forwardHubSearch = '';
        $this->showForwardHubModal = true;
    }

    /**
     * Closes the forward hub modal.
     * @return void
     */
    public function closeForwardHubModal(): void
    {
        $this->showForwardHubModal = false;
        $this->forwardHubSearch = '';
    }

    /**
     * Toggles the forward hub office setting or state.
     *
     * @param string $officeCode
     *
     * @return void
     */
    public function toggleForwardHubOffice(string $officeCode): void
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if ($officeCode === $userOfficeCode || in_array($officeCode, ['ORIGIN', '[H]', '[HUB]'])) {
            return;
        }

        if (in_array('ALL', $this->forwardHubTempSelected)) {
            $allActive = collect($this->availableTargetOffices)
                ->pluck('office_code')
                ->filter(fn($c) => $c !== $userOfficeCode && !in_array($c, ['ORIGIN', '[H]', '[HUB]', 'ALL']))
                ->toArray();
            $this->forwardHubTempSelected = array_values(array_diff($allActive, [$officeCode]));
            return;
        }

        if (in_array($officeCode, $this->forwardHubTempSelected)) {
            $this->forwardHubTempSelected = array_values(array_diff($this->forwardHubTempSelected, [$officeCode]));
        } else {
            $this->forwardHubTempSelected[] = $officeCode;
        }
    }

    /**
     * Selects all forward hub offices and populates the details panel.
     * @return void
     */
    public function selectAllForwardHubOffices(): void
    {
        $this->forwardHubTempSelected = ['ALL'];
    }

    /**
     * Resets forward hub offices and clears active selections.
     * @return void
     */
    public function clearForwardHubOffices(): void
    {
        $this->forwardHubTempSelected = [];
    }

    /**
     * Filters and queries forward hub modal based on criteria.
     * @return void
     */
    public function applyForwardHubModal(): void
    {
        $this->forwardHubOffices = $this->forwardHubTempSelected;
        $this->showForwardHubModal = false;
        $this->forwardHubSearch = '';
    }

    /**
     * Deletes or deactivates the specified forward hub office.
     *
     * @param int $index
     *
     * @return void
     */
    public function removeForwardHubOffice(int $index): void
    {
        if (isset($this->forwardHubOffices[$index])) {
            array_splice($this->forwardHubOffices, $index, 1);
        }
    }

    /**
     * Retrieves and populates available target offices property data.
     */
    public function getAvailableTargetOfficesProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());
        return \App\Services\DtsRoutingService::getAvailableTargetOffices($userOfficeCode);
    }

    public bool $editingControl = false;
    public bool $editingFileCode = false;
    public bool $editingParticulars = false;

    // Selection state
    public array $selectedIds = [];
    public bool $selectAll = false;

    /**
     * Lifecycle hook executed after updating the select all property.
     *
     * @param mixed $value
     *
     * @return void
     */
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

    /**
     * Toggles the layout setting or state.
     * @return void
     */
    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    /**
     * Lifecycle hook executed before updating the search query property.
     */
    public function updatingSearchQuery()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the selected priority property.
     */
    public function updatingSelectedPriority()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the selected status property.
     */
    public function updatingSelectedStatus()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the selected office property.
     */
    public function updatingSelectedOffice()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the per page property.
     */
    public function updatingPerPage()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the date from property.
     */
    public function updatingDateFrom()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the date to property.
     */
    public function updatingDateTo()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the sort order property.
     */
    public function updatingSortOrder()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Lifecycle hook executed before updating the status order property.
     */
    public function updatingStatusOrder()
    {
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Resets filters and clears active selections.
     * @return void
     */
    public function resetFilters(): void
    {
        $this->searchQuery = '';
        $this->selectedPriority = 'all';
        $this->selectedStatus = 'all';
        $this->selectedOffice = 'all';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortOrder = 'desc';
        $this->statusOrder = 'ongoing_first';
        $this->resetPage();
        $this->selectedIds = [];
        $this->selectAll = false;
    }

    /**
     * Retrieves and populates transactions property data.
     */
    public function getTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.trans_type', 'internal');

        $canViewAll = $this->canViewAll();
        if (!$canViewAll) {
            $this->selectedOffice = 'all';
            $query->where('dtd.originated_from', $userOfficeCode);
        } elseif ($this->selectedOffice !== 'all' && !empty($this->selectedOffice)) {
            $query->where('dtd.originated_from', $this->selectedOffice);
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

        if (!empty($this->dateFrom) || !empty($this->dateTo)) {
            $from = !empty($this->dateFrom) ? \Carbon\Carbon::parse($this->dateFrom)->startOfDay() : null;
            $to = !empty($this->dateTo) ? \Carbon\Carbon::parse($this->dateTo)->endOfDay() : null;

            if ($from && $to && $from->gt($to)) {
                $temp = $from;
                $from = $to->copy()->startOfDay();
                $to = $temp->copy()->endOfDay();
            }

            if ($from && $to) {
                $query->whereBetween('dtd.date_created', [$from->toDateTimeString(), $to->toDateTimeString()]);
            } elseif ($from) {
                $query->where('dtd.date_created', '>=', $from->toDateTimeString());
            } elseif ($to) {
                $query->where('dtd.date_created', '<=', $to->toDateTimeString());
            }
        }

        if ($this->statusOrder === 'ongoing_first') {
            $query->orderByRaw("CASE WHEN LOWER(TRIM(dt.status)) = 'ongoing' THEN 0 ELSE 1 END ASC");
        } elseif ($this->statusOrder === 'completed_first') {
            $query->orderByRaw("CASE WHEN LOWER(TRIM(dt.status)) = 'completed' THEN 0 ELSE 1 END ASC");
        }

        $sortDirection = in_array(strtolower($this->sortOrder), ['asc', 'desc']) ? strtolower($this->sortOrder) : 'desc';

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
            DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', $sortDirection)
        ->paginate($this->perPage);

        // Map Step 1 (Origin) and Step 2 (Current) Received/Released logs and Elapsed Days
        $list->getCollection()->transform(function ($t) {
            $logs = DB::table((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . ' as log')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as ad', 'ad.account_id', '=', 'log.performed_by')
                ->where('log.transaction_id', $t->transaction_id)
                ->orderBy('log.id', 'asc')
                ->select('log.*', 'ad.first_name', 'ad.last_name')
                ->get();

            $firstLog = $logs->first();
            $secondLog = $logs->count() > 1 ? $logs->get(1) : null;
            $latestLog = $logs->last();

            $t->remarks = $latestLog ? $latestLog->notes : '-';
            $t->received_by = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';

            // Step 1 Received & Released
            $t->step1_received = $firstLog && $firstLog->date_in ? \Carbon\Carbon::parse($firstLog->date_in)->format('M d, Y h:i A') : ($t->date_created ? \Carbon\Carbon::parse($t->date_created)->format('M d, Y h:i A') : '-');
            $t->step1_released = $firstLog && $firstLog->date_out ? \Carbon\Carbon::parse($firstLog->date_out)->format('M d, Y h:i A') : ($logs->count() > 1 ? \Carbon\Carbon::parse($firstLog?->date_in ?? $t->date_created)->format('M d, Y h:i A') : '-');

            // Step 2 Received & Released
            $t->step2_received = $secondLog && $secondLog->date_in ? \Carbon\Carbon::parse($secondLog->date_in)->format('M d, Y h:i A') : '-';
            $t->step2_released = $secondLog && $secondLog->date_out ? \Carbon\Carbon::parse($secondLog->date_out)->format('M d, Y h:i A') : '-';

            // Elapsed Days
            $createdDateRaw = $firstLog?->date_in ?? $t->date_created;
            $t->elapsed_days = $createdDateRaw ? \App\Services\DtsWorkingHoursService::calculateWorkingDays($createdDateRaw) : 0;

            // Previous office (from office)
            $prevLog = DB::table((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . ' as log')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'office.office_code', '=', 'log.office_code')
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
                        $nextOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
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

        return DB::table((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . ' as log')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'office.office_code', '=', 'log.office_code')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_log_types') ? 'dts_transaction_log_types' : 'sub_document_tracking_system_logs_types') . ' as lt', 'lt.type_id', '=', 'log.type')
            ->where('log.transaction_id', $this->selectedTransactionId)
            ->select('log.*', 'office.office_name', 'lt.description')
            ->orderBy('log.id', 'asc')
            ->get()
            ->map(function ($step) {
                $step->is_active_step = ($step->office_code === auth()->user()?->details?->office?->office_code && is_null($step->date_out));
                return $step;
            });
    }
    /**
     * Toggles the full configured path setting or state.
     * @return void
     */
    public function toggleFullConfiguredPath(): void
    {
        $this->showFullConfiguredPath = !$this->showFullConfiguredPath;
    }

    /**
     * Retrieves and populates full flow path property data.
     */
    public function getFullFlowPathProperty()
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return collect();
        }

        if ($this->editingAll) {
            $originOfficeCode = $this->selectedTransaction->originated_from;
            $originOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $originOfficeCode)->first();
            $originOfficeName = $originOffice ? $originOffice->office_name : 'Originated Office';
            
            $clusterHeadCode = $originOfficeCode;
            $clusterHeadName = $originOfficeName;
            if ($originOffice && $originOffice->cluster) {
                $cluster = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')->where('cluster_code', $originOffice->cluster)->first();
                if ($cluster && $cluster->cluster_head) {
                    $clusterHeadCode = $cluster->cluster_head;
                    $headOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $cluster->cluster_head)->first();
                    if ($headOffice) {
                        $clusterHeadName = $headOffice->office_name;
                    }
                }
            }

            return collect($this->flowOffices)->map(function ($stepData, $index) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName) {
                $officeCode = is_array($stepData) ? ($stepData['office_code'] ?? '') : ($stepData->office_code ?? '');
                $resolvedCode = $officeCode;
                $resolvedName = $officeCode;
                if ($officeCode === 'ORIGIN') {
                    $resolvedCode = $originOfficeCode;
                    $resolvedName = $originOfficeName;
                } elseif ($officeCode === '[H]') {
                    $resolvedCode = $clusterHeadCode;
                    $resolvedName = $clusterHeadName;
                } else {
                    $office = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $officeCode)->first();
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
                $step->is_locked = is_array($stepData) ? ($stepData['is_locked'] ?? false) : ($stepData->is_locked ?? false);
                $step->receiver_name = '—';
                $step->forwarder_name = '—';
                $step->log_type = null;
                $step->is_historical = false;
                return $step;
            });
        }

        $flowCode = $this->selectedTransaction->transaction_flow;
        $flow = DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
        if (!$flow) {
            return collect();
        }

        $originOfficeCode = $this->selectedTransaction->originated_from;
        $originOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $originOfficeCode)->first();
        $originOfficeName = $originOffice ? $originOffice->office_name : 'Originated Office';
        
        $clusterHeadCode = $originOfficeCode;
        $clusterHeadName = $originOfficeName;
        if ($originOffice && $originOffice->cluster) {
            $cluster = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')->where('cluster_code', $originOffice->cluster)->first();
            if ($cluster && $cluster->cluster_head) {
                $clusterHeadCode = $cluster->cluster_head;
                $headOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $cluster->cluster_head)->first();
                if ($headOffice) {
                    $clusterHeadName = $headOffice->office_name;
                }
            }
        }

        return DB::table('dts_sequence_list as seq')
            ->join((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'office.office_code', '=', 'seq.office_code')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as r_ad', 'r_ad.account_id', '=', 'seq.account_received')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account') . ' as r_acc', 'r_acc.id', '=', 'seq.account_received')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as f_ad', 'f_ad.account_id', '=', 'seq.account_forwarded')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account') . ' as f_acc', 'f_acc.id', '=', 'seq.account_forwarded')
            ->where('seq.control_id', $flow->id)
            ->select(
                'seq.sequence_ranking', 
                'office.office_name', 
                'seq.office_code', 
                'seq.date_in', 
                'seq.date_out', 
                'seq.action_needed', 
                'seq.note', 
                'seq.total_time_completed',
                DB::raw("TRIM(CONCAT(COALESCE(r_ad.first_name, ''), ' ', COALESCE(r_ad.last_name, ''))) as receiver_name"),
                'r_acc.username as receiver_username',
                DB::raw("TRIM(CONCAT(COALESCE(f_ad.first_name, ''), ' ', COALESCE(f_ad.last_name, ''))) as forwarder_name"),
                'f_acc.username as forwarder_username'
            )
            ->orderBy('seq.sequence_ranking', 'asc')
            ->get()
            ->map(function ($step) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName) {
                if ($step->office_code === 'ORIGIN') {
                    $step->office_code = $originOfficeCode;
                    $step->office_name = $originOfficeName;
                } elseif ($step->office_code === '[H]') {
                    $step->office_code = $clusterHeadCode;
                    $step->office_name = $clusterHeadName;
                } elseif ($step->office_code === '[HUB]' || !empty($step->is_hub)) {
                    $step->office_code = '[HUB]';
                    $step->office_name = 'Office Hub [Multi-Receiving]';
                    $step->is_hub = true;

                    // Calculate hub counts for modal view
                    $cBranches = DB::table('dts_transactions as dt')
                        ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                        ->where('dtd.control_number', 'like', $this->selectedTransaction->control_number . '-%')
                        ->where('dtd.is_active', 1)
                        ->whereNotIn('dt.status', ['cancelled'])
                        ->select('dt.transaction_id', 'dt.status', 'dt.sequence', 'dt.current_office')
                        ->get();

                    $hubTotal = $cBranches->count();
                    if ($hubTotal === 0) {
                        $cfRecord = DB::table('dts_copy_filled_transaction')->where('control_num', $this->selectedTransaction->control_number)->first();
                        $hubOffices = $cfRecord ? DB::table('dts_copy_filled_to_office')->where('control_id', $cfRecord->assign_offices_id)->pluck('office_code')->toArray() : [];
                        $hubTotal = count($hubOffices);
                    }

                    $hubDone = 0;
                    if ($cBranches->isNotEmpty()) {
                        $cIds = $cBranches->pluck('transaction_id')->toArray();
                        $fwdLogs = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                            ->whereIn('transaction_id', $cIds)
                            ->whereNotNull('date_out')
                            ->pluck('transaction_id')
                            ->toArray();

                        foreach ($cBranches as $cb) {
                            if (strtolower($cb->status) === 'completed' || in_array($cb->transaction_id, $fwdLogs) || ($cb->sequence ?? 0) > 2) {
                                $hubDone++;
                            }
                        }
                    }

                    $step->hub_forwarded_count = $hubDone;
                    $step->hub_total = $hubTotal;
                    if ($hubTotal > 0 && $hubDone >= $hubTotal) {
                        $step->date_out = now();
                    }
                }

                $step->receiver_name = !empty(trim($step->receiver_name ?? '')) ? $step->receiver_name : ($step->receiver_username ?: '—');
                $step->forwarder_name = !empty(trim($step->forwarder_name ?? '')) ? $step->forwarder_name : ($step->forwarder_username ?: '—');

                $step->is_active_step = (
                    $step->office_code === auth()->user()?->details?->office?->office_code
                    && $step->office_code === $this->selectedTransaction->current_office
                    && $step->sequence_ranking === $this->selectedTransaction->sequence
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
                $step->type = $step->action_needed ?: 'pending';
                $step->notes = $step->note ?: '';
                $step->is_locked = ($step->sequence_ranking <= ($this->selectedTransaction->sequence ?? 1)) || !is_null($step->date_in);
                return $step;
            });
    }

    /**
     * Retrieves and populates visible path property data.
     */
    public function getVisiblePathProperty()
    {
        return $this->fullFlowPath;
    }

    /**
     * Displays the transaction dialog or panel.
     *
     * @param string $id
     *
     * @return void
     */
    public function openTransaction(string $id): void
    {
        $this->selectedTransactionId = $id;
        $this->loadSelectedTransaction();
    }

    /**
     * Closes or dismisses the transaction.
     * @return void
     */
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
        $this->forwardToOfficeCode = '';
        $this->forwardHubOffices = [];
        $this->forwardHubTempSelected = [];
        $this->showForwardHubModal = false;
    }

    /**
     * Toggles the more details setting or state.
     * @return void
     */
    public function toggleMoreDetails(): void
    {
        $this->showMoreDetails = !$this->showMoreDetails;
    }

    /**
     * Toggles the edit all setting or state.
     * @return void
     */
    public function toggleEditAll(): void
    {
        $perms = auth()->user()?->permissions;
        $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false) || ($perms->can_dts_modify_control_no ?? false));
        $isCreator = isset($this->selectedTransaction) && ($this->selectedTransaction->created_by === auth()->id());
        $isAtOrigin = isset($this->selectedTransaction) && ($this->selectedTransaction->current_office === $this->selectedTransaction->originated_from);
        $isInitialOrRevisionState = isset($this->selectedTransaction) && ($this->selectedTransaction->sequence == 1 || $this->selectedTransaction->status === 'revision');

        $canModify = isset($this->selectedTransaction) 
            && ($canListModify || ($this->selectedTransaction->status !== 'completed' && $isCreator && $isAtOrigin && $isInitialOrRevisionState));

        if (!$canModify) {
            return;
        }
        $this->editingAll = !$this->editingAll;
    }

    /**
     * Creates a new cf office record.
     * @return void
     */
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

    /**
     * Deletes or deactivates the specified cf office.
     *
     * @param string $officeCode
     *
     * @return void
     */
    public function removeCfOffice(string $officeCode): void
    {
        $this->cfSelectedOffices = array_values(array_diff($this->cfSelectedOffices, [$officeCode]));
    }

    /**
     * Creates a new flow office record.
     * @return void
     */
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

    /**
     * Deletes or deactivates the specified flow office.
     *
     * @param int $index
     *
     * @return void
     */
    public function removeFlowOffice(int $index): void
    {
        if (isset($this->flowOffices[$index]) && !$this->flowOffices[$index]['is_locked']) {
            unset($this->flowOffices[$index]);
            $this->flowOffices = array_values($this->flowOffices);
        }
    }

    /**
     * Reorders flow office up items.
     *
     * @param int $index
     *
     * @return void
     */
    public function moveFlowOfficeUp(int $index): void
    {
        if ($index > 0 && !$this->flowOffices[$index]['is_locked'] && !$this->flowOffices[$index - 1]['is_locked']) {
            $temp = $this->flowOffices[$index];
            $this->flowOffices[$index] = $this->flowOffices[$index - 1];
            $this->flowOffices[$index - 1] = $temp;
        }
    }

    /**
     * Reorders flow office down items.
     *
     * @param int $index
     *
     * @return void
     */
    public function moveFlowOfficeDown(int $index): void
    {
        if ($index < count($this->flowOffices) - 1 && !$this->flowOffices[$index]['is_locked'] && !$this->flowOffices[$index + 1]['is_locked']) {
            $temp = $this->flowOffices[$index];
            $this->flowOffices[$index] = $this->flowOffices[$index + 1];
            $this->flowOffices[$index + 1] = $temp;
        }
    }

    /**
     * Persists metadata only changes to the database.
     * @return void
     */
    public function saveMetadataOnly(): void
    {
        $perms = auth()->user()?->permissions;
        $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false) || ($perms->can_dts_modify_control_no ?? false));
        $isCreator = isset($this->selectedTransaction) && ($this->selectedTransaction->created_by === auth()->id());
        $isAtOrigin = isset($this->selectedTransaction) && ($this->selectedTransaction->current_office === $this->selectedTransaction->originated_from);
        $isInitialOrRevisionState = isset($this->selectedTransaction) && ($this->selectedTransaction->sequence == 1 || $this->selectedTransaction->status === 'revision');

        $canModify = isset($this->selectedTransaction) 
            && ($canListModify || ($this->selectedTransaction->status !== 'completed' && $isCreator && $isAtOrigin && $isInitialOrRevisionState));

        if ($canModify) {
            try {
                DB::transaction(function () use ($canListModify) {
                    $emailAccessId = null;
                    if (!empty(trim($this->emailAccess ?? ''))) {
                        $trimmedEmail = trim($this->emailAccess);
                        $existingEmail = DB::table('dts_email_access')->where('email', $trimmedEmail)->first();
                        if (!$existingEmail) {
                            $emailAccessId = DB::table('dts_email_access')->insertGetId([
                                'email' => $trimmedEmail,
                                'is_active' => true,
                                'date_created' => now(),
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
                                        'control_num' => $this->controlNumber,
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
                            // Safely unlink copy_filled_id first to prevent cascade deletion
                            DB::table('dts_transaction_details')
                                ->where('id', $this->selectedTransactionId)
                                ->update(['copy_filled_id' => null]);

                            if ($cfRecord) {
                                DB::table('dts_copy_filled_to_office')->where('control_id', $cfRecord->assign_offices_id)->delete();
                                DB::table('dts_copy_filled_transaction')->where('id', $copyFilledId)->delete();
                            }
                            $copyFilledId = null;
                        }
                    }

                    // Upsert / resolve requestor in dts_requestor_history
                    $requestorId = null;
                    if (!empty(trim($this->requestorName ?? ''))) {
                        $tDetail = DB::table('dts_transaction_details')->where('id', $this->selectedTransactionId)->first();
                        $originOffice = $tDetail?->originated_from ?? (auth()->user()?->details?->office?->office_code ?? 'RFIO');
                        $existingReq = DB::table('dts_requestor_history')
                            ->where('requestor_name', trim($this->requestorName))
                            ->where('office', $originOffice)
                            ->first();

                        if ($existingReq) {
                            $requestorId = $existingReq->id;
                            if (!empty($this->requestorPosition)) {
                                DB::table('dts_requestor_history')
                                    ->where('id', $existingReq->id)
                                    ->update(['requestor_position' => trim($this->requestorPosition)]);
                            }
                        } else {
                            $requestorId = DB::table('dts_requestor_history')->insertGetId([
                                'requestor_name' => trim($this->requestorName),
                                'requestor_position' => trim($this->requestorPosition ?? ''),
                                'office' => $originOffice,
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
                            'requestor_id' => $requestorId,
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
                            ->keyBy('sequence_ranking');

                        if (str_starts_with($flow->flow_code, 'FLOW-CUSTOM-')) {
                            DB::table('dts_sequence_list')->where('control_id', $flow->id)->delete();
                            foreach ($this->flowOffices as $rank => $officeData) {
                                $rank1 = $rank + 1;
                                $officeCode = is_array($officeData) ? $officeData['office_code'] : $officeData->office_code;
                                $isLocked = is_array($officeData) ? ($officeData['is_locked'] ?? false) : ($officeData->is_locked ?? false);
                                
                                $orig = $originalSequence->get($rank1);
                                if ($isLocked && $orig) {
                                    $origDateIn = is_array($orig) ? ($orig['date_in'] ?? null) : ($orig->date_in ?? null);
                                    $origDateOut = is_array($orig) ? ($orig['date_out'] ?? null) : ($orig->date_out ?? null);
                                    $origActionNeeded = is_array($orig) ? ($orig['action_needed'] ?? null) : ($orig->action_needed ?? null);
                                    $origNote = is_array($orig) ? ($orig['note'] ?? null) : ($orig->note ?? null);
                                    $origTotalTime = is_array($orig) ? ($orig['total_time_completed'] ?? null) : ($orig->total_time_completed ?? null);

                                    DB::table('dts_sequence_list')->insert([
                                        'control_id' => $flow->id,
                                        'sequence_ranking' => $rank1,
                                        'office_code' => $officeCode,
                                        'date_in' => $origDateIn,
                                        'date_out' => $origDateOut,
                                        'action_needed' => $origActionNeeded,
                                        'note' => $origNote,
                                        'total_time_completed' => $origTotalTime,
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
                            
                            $currentOfficeCodes = array_map(fn($o) => is_array($o) ? $o['office_code'] : $o->office_code, $this->flowOffices);
                            
                            if ($predefinedOffices !== $currentOfficeCodes) {
                                $newFlowCode = 'FLOW-CUSTOM-' . strtoupper(Str::random(10));
                                $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
                                $newFlowId = $maxId + 1;
                                
                                DB::table('dts_transaction_flow')->insert([
                                    'flow_code' => $newFlowCode,
                                    'flow_name' => 'Flow for ' . $this->controlNumber . ' (' . $newFlowCode . ')',
                                    'id' => $newFlowId,
                                    'is_active' => true,
                                    'added_by' => auth()->id() ?? 1,
                                    'date_added' => now(),
                                    'flow_use' => 'none',
                                    'flow_for' => 'system',
                                    'referenced_flow' => $flow ? ($flow->referenced_flow ?? $flow->flow_name) : null,
                                ]);

                                foreach ($this->flowOffices as $rank => $officeData) {
                                    $rank1 = $rank + 1;
                                    $officeCode = is_array($officeData) ? $officeData['office_code'] : $officeData->office_code;
                                    $isLocked = is_array($officeData) ? ($officeData['is_locked'] ?? false) : ($officeData->is_locked ?? false);
                                    
                                    $orig = $originalSequence->get($rank1);
                                    if ($isLocked && $orig) {
                                        $origDateIn = is_array($orig) ? ($orig['date_in'] ?? null) : ($orig->date_in ?? null);
                                        $origDateOut = is_array($orig) ? ($orig['date_out'] ?? null) : ($orig->date_out ?? null);
                                        $origActionNeeded = is_array($orig) ? ($orig['action_needed'] ?? null) : ($orig->action_needed ?? null);
                                        $origNote = is_array($orig) ? ($orig['note'] ?? null) : ($orig->note ?? null);
                                        $origTotalTime = is_array($orig) ? ($orig['total_time_completed'] ?? null) : ($orig->total_time_completed ?? null);

                                        DB::table('dts_sequence_list')->insert([
                                            'control_id' => $newFlowId,
                                            'sequence_ranking' => $rank1,
                                            'office_code' => $officeCode,
                                            'date_in' => $origDateIn,
                                            'date_out' => $origDateOut,
                                            'action_needed' => $origActionNeeded,
                                            'note' => $origNote,
                                            'total_time_completed' => $origTotalTime,
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
                });

                $this->loadSelectedTransaction();
                $this->editingAll = false;
                $this->dispatch('dts-transaction-updated');
                session()->flash('success', 'Transaction details updated successfully.');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Error saving transaction metadata: ' . $e->getMessage(), [
                    'exception' => $e,
                    'transaction_id' => $this->selectedTransactionId,
                ]);
                session()->flash('error', 'Failed to save changes: ' . $e->getMessage());
            }
        }
        $this->editingAll = false;
    }

    /**
     * Retrieves and populates selected transaction data.
     * @return void
     */
    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('dts_email_access as dea', 'dea.id', '=', 'dtd.email_access')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as creator_ad', 'creator_ad.account_id', '=', 'dtd.created_by')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account') . ' as creator_acc', 'creator_acc.id', '=', 'dtd.created_by')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->select(
                'dt.*',
                'dtd.*',
                'req.requestor_name',
                'req.requestor_position as requestor_label',
                'dea.email as access_email',
                'originated_office.office_name as originated_office_name',
                DB::raw("TRIM(CONCAT(COALESCE(creator_ad.first_name, ''), ' ', COALESCE(creator_ad.last_name, ''))) as creator_name"),
                'creator_acc.username as creator_username'
            )
            ->first();

        $this->attachedDocName = '';
        if ($this->selectedTransaction && !empty($this->selectedTransaction->doc_dir)) {
            $docData = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data')->where('document_path', $this->selectedTransaction->doc_dir)->first();
            $this->attachedDocName = $docData->document_name ?? basename($this->selectedTransaction->doc_dir);
        }

        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number;
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?: '';
            $this->particulars = $this->selectedTransaction->subject ?: '';
            $this->classification = $this->selectedTransaction->classification ?: '';
            $this->actionNeeded = $this->selectedTransaction->action_needed ?: '';
            $this->activeAction = $this->actionOptions[0] ?? 'For Approval';
            $this->forwardToOfficeCode = '';

            $this->requestorName = $this->selectedTransaction->requestor_name ?? '';
            $this->requestorPosition = $this->selectedTransaction->requestor_label ?? '';
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

    /**
     * Is last step.
     * @return bool
     */
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

    /**
     * Triggers completion confirm.
     * @return void
     */
    public function triggerCompletionConfirm(): void
    {
        $this->showCompletionConfirmModal = true;
    }

    /**
     * Resets completion confirm and clears active selections.
     * @return void
     */
    public function cancelCompletionConfirm(): void
    {
        $this->showCompletionConfirmModal = false;
    }

    /**
     * Submits and processes and complete transaction.
     * @return void
     */
    public function confirmAndCompleteTransaction(): void
    {
        $this->showCompletionConfirmModal = false;
        $this->completeTransaction();
    }

    /**
     * Triggers upload file modal.
     * @return void
     */
    public function triggerUploadFileModal(): void
    {
        $this->uploadFileName = '';
        $this->uploadErrorMessage = '';
        $this->uploadedFile = null;
        $this->showUploadModal = true;
    }

    /**
     * Displays the view file modal.
     * @return void
     */
    public function openViewFileModal(): void
    {
        $this->showViewFileModal = true;
    }

    /**
     * Closes the view file modal.
     * @return void
     */
    public function closeViewFileModal(): void
    {
        $this->showViewFileModal = false;
    }

    /**
     * Displays the pdf preview modal.
     * @return void
     */
    public function openPdfPreviewModal(): void
    {
        $this->showPdfPreviewModal = true;
    }

    /**
     * Closes the pdf preview modal.
     * @return void
     */
    public function closePdfPreviewModal(): void
    {
        $this->showPdfPreviewModal = false;
    }

    /**
     * Deletes or deactivates the specified uploaded file.
     * @return void
     */
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

    /**
     * Sets the active uploaded file.
     * @return void
     */
    public function changeUploadedFile(): void
    {
        $this->showViewFileModal = false;
        $this->triggerUploadFileModal();
    }

    /**
     * Resets upload modal and clears active selections.
     * @return void
     */
    public function cancelUploadModal(): void
    {
        $this->showUploadModal = false;
        $this->uploadedFile = null;
        $this->uploadErrorMessage = '';
    }

    /**
     * Handles upload file event or action.
     * @return void
     */
    public function handleUploadFile(): void
    {
        $this->uploadErrorMessage = '';

        $requiredUpload = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings')->where('key', 'dts_required_upload_file')->value('value') === 'true';
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

    /**
     * Reopens transaction.
     * @return void
     */
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

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
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

    /**
     * Completes transaction.
     * @return void
     */
    public function completeTransaction(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($this->selectedTransaction->current_office !== $userOfficeCode) {
            return;
        }

        if ($this->activeAction === 'To be Forwarded to') {
            if (empty($this->forwardToOfficeCode)) {
                session()->flash('error', 'Please select a destination office to forward to.');
                return;
            }

            if ($this->forwardToOfficeCode === '[HUB]' && empty($this->forwardHubOffices)) {
                session()->flash('error', 'Please select at least one recipient office for Office Hub broadcast.');
                return;
            }

            try {
                $result = \App\Services\DtsRoutingService::forwardToOtherOffice(
                    $this->selectedTransactionId,
                    $this->forwardToOfficeCode,
                    $this->activeNotes,
                    auth()->id(),
                    $this->forwardHubOffices
                );
                session()->flash('success', "Transaction '{$result['control_number']}' has been forwarded to {$result['target_office_name']}!");
                $this->closeTransaction();
            } catch (\Throwable $e) {
                session()->flash('error', 'Failed to forward transaction: ' . $e->getMessage());
            }
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

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
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
            DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
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
                    $originOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $originatedFrom)->first();
                    if ($originOffice && $originOffice->cluster) {
                        $cluster = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')->where('cluster_code', $originOffice->cluster)->first();
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
                DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
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
                
                DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')->insert([
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
        if ($perms && (($perms->is_sadm ?? false) || ($perms->can_dts_modify_transaction ?? false) || ($perms->can_dts_modify_control_no ?? false) || ($perms->can_dts_list_modify_transaction ?? false))) {
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

        $completedTxId = $this->selectedTransactionId;
        $controlNo = $this->selectedTransaction->control_number ?? $this->controlNumber ?? '';
        $isCompletedNow = DB::table('dts_transactions')
            ->where('transaction_id', $completedTxId)
            ->value('status') === 'completed';

        $this->closeTransaction();

        if ($isCompletedNow) {
            $this->dispatch('open-dts-rdp-handoff', transactionId: $completedTxId, controlNumber: $controlNo);
        }
    }

    /**
     * Deletes or deactivates the specified transaction.
     * @return void
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
     * Starts edit.
     *
     * @param string $field
     *
     * @return void
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
     * Persists field changes to the database.
     *
     * @param string $field
     *
     * @return void
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
    @vite(['resources/css/dts/list_transaction.css', 'resources/css/dts/receive.css'])
    <style>
        .dts-timeline-node-wrapper {
            position: relative;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            margin: 0;
        }
        .dts-timeline-node-wrapper .dts-node-tooltip {
            position: absolute;
            bottom: 34px;
            left: 50%;
            transform: translateX(-50%);
            width: 210px;
            background: #0f172a;
            color: #f8fafc;
            padding: 8px 10px;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            border: 1px solid #334155;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.15s ease, visibility 0.15s ease;
            z-index: 9999;
            text-align: left;
            font-size: 11px;
            line-height: 1.4;
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
            right: auto;
            transform: none;
        }
        .dts-timeline-node-wrapper:first-child .dts-node-tooltip::after {
            left: 26px;
            right: auto;
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
        .dts-timeline-node-wrapper:only-child .dts-node-tooltip,
        .dts-timeline-node-wrapper:first-child:last-child .dts-node-tooltip {
            left: 50%;
            right: auto;
            transform: translateX(-50%);
        }
        .dts-timeline-node-wrapper:only-child .dts-node-tooltip::after,
        .dts-timeline-node-wrapper:first-child:last-child .dts-node-tooltip::after {
            left: 50%;
            right: auto;
            transform: translateX(-50%);
        }
        .dts-timeline-node-wrapper:hover .dts-node-tooltip {
            opacity: 1 !important;
            visibility: visible !important;
        }
        .dts-timeline-node-dot {
            transition: all 0.2s ease;
        }
        .dts-timeline-node-wrapper:hover .dts-timeline-node-dot {
            transform: scale(1.2) !important;
        }

        /* ═══════════════════════════════════════════════════════════════
           DTS ISSUANCE OVERHAULED MODAL (Unique Registry Dossier)
           ═══════════════════════════════════════════════════════════════ */
        .dts-im-backdrop {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            animation: dtsImFadeIn 0.2s ease-out;
        }
        @keyframes dtsImFadeIn {
            from { opacity: 0; transform: scale(0.98); }
            to { opacity: 1; transform: scale(1); }
        }
        .dts-im-card {
            background: #ffffff;
            width: 100%;
            max-width: 1060px;
            max-height: 94vh;
            border-radius: 18px;
            box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            border: 1px solid #e2e8f0;
        }
        .dts-im-header {
            background: linear-gradient(135deg, #091b3e 0%, #173878 50%, #1e40af 100%);
            color: #ffffff;
            padding: 20px 26px 18px;
            flex-shrink: 0;
            position: relative;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }
        .dts-im-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .dts-im-header-identity {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .dts-im-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #e0f2fe;
        }
        .dts-im-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11.5px;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .dts-im-close-btn {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 20px;
            color: #ffffff;
            cursor: pointer;
            border-radius: 50%;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            line-height: 1;
        }
        .dts-im-close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
        .dts-im-header-main {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .dts-im-control-wrap {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .dts-im-control-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.9px;
            text-transform: uppercase;
            color: #93c5fd;
        }
        .dts-im-control-val {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
            font-family: 'JetBrains Mono', monospace, monospace;
            letter-spacing: 0.5px;
        }
        .dts-im-copy-btn {
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #ffffff;
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .dts-im-copy-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .dts-im-meta-cluster {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .dts-im-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(15, 23, 42, 0.35);
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .dts-im-meta-item i {
            color: #93c5fd;
            font-size: 13px;
        }
        .dts-im-meta-k {
            display: block;
            font-size: 9.5px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #cbd5e1;
        }
        .dts-im-meta-v {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #ffffff;
            white-space: nowrap;
        }
        .dts-im-body {
            padding: 22px 26px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
            background: #f8fafc;
        }
        .dts-im-card-panel {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 18px 20px;
        }
        .dts-im-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            gap: 10px;
        }
        .dts-im-panel-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .dts-im-subject-box {
            border-left: 4px solid #2563eb;
            background: #f8fafc;
            border-radius: 0 8px 8px 0;
            padding: 12px 16px;
            font-size: 13.5px;
            line-height: 1.6;
            color: #1e293b;
            font-weight: 500;
            margin-bottom: 16px;
        }
        .dts-im-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
        }
        .dts-im-tile {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .dts-im-tile-k {
            font-size: 10.5px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dts-im-tile-v {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            word-break: break-word;
        }
        .dts-im-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        .dts-im-tab-btn {
            border: none;
            background: transparent;
            padding: 6px 14px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .dts-im-tab-btn.active {
            background: #ffffff;
            color: #1e40af;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .dts-im-table-wrap {
            width: 100%;
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .dts-im-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
            text-align: left;
        }
        .dts-im-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            padding: 10px 14px;
            border-bottom: 1.5px solid #cbd5e1;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            white-space: nowrap;
        }
        .dts-im-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #334155;
            background: #ffffff;
        }
        .dts-im-table tbody tr:hover td {
            background: #f8fafc;
        }
        .dts-im-table tbody tr:last-child td {
            border-bottom: none;
        }
        .dts-im-step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0284c7;
            font-weight: 700;
            font-size: 11.5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .dts-im-office-badge {
            font-weight: 700;
            color: #1e40af;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11.5px;
            margin-right: 6px;
            display: inline-block;
        }
        .dts-im-user-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            color: #475569;
            margin-top: 3px;
        }
        .dts-im-duration-pill {
            background: #f1f5f9;
            color: #475569;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .dts-im-footer {
            padding: 14px 26px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .dts-im-footer-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .dts-im-btn {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            font-family: inherit;
            line-height: 1;
        }
        .dts-im-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .dts-im-btn-primary {
            background: #2563eb;
            color: #ffffff;
            border-color: #1d4ed8;
        }
        .dts-im-btn-primary:hover {
            background: #1d4ed8;
        }
        .dts-im-btn-success {
            background: #16a34a;
            color: #ffffff;
            border-color: #15803d;
        }
        .dts-im-btn-success:hover {
            background: #15803d;
        }
        .dts-im-btn-danger {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }
        .dts-im-btn-danger:hover {
            background: #fecaca;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .dts-im-card {
            background: #0f172a;
            border-color: #1e293b;
            color: #f8fafc;
        }
        [data-theme="dark"] .dts-im-body {
            background: #090d16;
        }
        [data-theme="dark"] .dts-im-card-panel {
            background: #131c2e;
            border-color: #1e293b;
        }
        [data-theme="dark"] .dts-im-panel-title {
            color: #f8fafc;
        }
        [data-theme="dark"] .dts-im-subject-box {
            background: #1e293b;
            color: #f1f5f9;
        }
        [data-theme="dark"] .dts-im-tile {
            background: #1a2438;
            border-color: #334155;
        }
        [data-theme="dark"] .dts-im-tile-v {
            color: #f8fafc;
        }
        [data-theme="dark"] .dts-im-tabs {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .dts-im-tab-btn.active {
            background: #0f172a;
            color: #60a5fa;
        }
        [data-theme="dark"] .dts-im-table th {
            background: #1e293b;
            color: #cbd5e1;
            border-color: #334155;
        }
        [data-theme="dark"] .dts-im-table td {
            background: #131c2e;
            color: #e2e8f0;
            border-color: #1e293b;
        }
        [data-theme="dark"] .dts-im-table tbody tr:hover td {
            background: #1a2438;
        }
        [data-theme="dark"] .dts-im-footer {
            background: #0f172a;
            border-color: #1e293b;
        }
        [data-theme="dark"] .dts-im-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .dts-im-btn:hover {
            background: #334155;
        }
    </style>
@endpush

<div class="rms-container">
    <div class="rms-header">
        <h2>Internal Transactions</h2>
    </div>

    <div class="rms-toolbar">
        <div class="rms-toolbar-top">
            <div class="rms-filters">
                @if($canViewAll)
                <select class="rms-select" wire:model.live="selectedOffice" title="Filter by Office" style="max-width: 260px; text-overflow: ellipsis;">
                    <option value="all">All Offices</option>
                    @foreach($officesList as $off)
                        <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                    @endforeach
                </select>
                @endif
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
                <label class="rms-select-all-label">
                    <input type="checkbox" wire:model.live="selectAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af;">
                    Select All
                </label>
                <button type="button" wire:click="toggleLayout" class="rms-select" style="background-image: none; padding-right: 12px; display: inline-flex; align-items: center; gap: 6px;">
                    @if ($layoutMode === 'table')
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Grid
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Table
                    @endif
                </button>
                <button type="button" class="rms-btn-export" wire:click="openExportModal">
                    <svg class="btn-icon" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg> Export
                </button>
            </div>
        </div>
        <div class="rms-toolbar-bottom" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div class="rms-entries" style="display: flex; align-items: center; gap: 8px;">
                Show 
                <select class="rms-select" wire:model.live="perPage">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select> 
                Entries
            </div>
            <div class="rms-filters" style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="rms-filter-label">From:</span>
                    <input type="date" class="rms-select" wire:model.live="dateFrom" style="padding-right: 8px; background-image: none; font-size: 0.82rem; height: 34px;" title="Filter Created From Date">
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="rms-filter-label">To:</span>
                    <input type="date" class="rms-select" wire:model.live="dateTo" style="padding-right: 8px; background-image: none; font-size: 0.82rem; height: 34px;" title="Filter Created To Date">
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <select class="rms-select" wire:model.live="sortOrder" style="font-size: 0.82rem; height: 34px;">
                        <option value="desc">Date (Newest First)</option>
                        <option value="asc">Date (Oldest First)</option>
                    </select>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="rms-filter-label">Modifier:</span>
                    <select class="rms-select" wire:model.live="statusOrder" style="font-size: 0.82rem; height: 34px;" title="Filter Modifier">
                        <option value="ongoing_first">Ongoing First</option>
                        <option value="completed_first">Completed First</option>
                        <option value="none">None</option>
                    </select>
                </div>
                @if(!empty($dateFrom) || !empty($dateTo) || !empty($searchQuery) || $selectedPriority !== 'all' || $selectedStatus !== 'all' || ($canViewAll && $selectedOffice !== 'all') || $sortOrder !== 'desc' || $statusOrder !== 'ongoing_first')
                    <button type="button" wire:click="resetFilters" class="rms-select rms-btn-reset-filters" style="background-image: none; padding-right: 12px; display: inline-flex; align-items: center; gap: 4px; height: 34px; font-size: 0.82rem; font-weight: 600;" title="Reset all filters">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        Reset
                    </button>
                @endif
            </div>
            <div class="rms-actions">
                <div class="rms-search-wrapper">
                    <input type="text" class="rms-search-input" placeholder="Search..." wire:model.live="searchQuery" style="width: 220px; height: 34px;">
                </div>
            </div>
        </div>
    </div>

    @if(!empty($dateFrom) || !empty($dateTo))
        <div class="rms-date-range-badge">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <span>
                Showing transactions created between <strong>{{ !empty($dateFrom) ? \Carbon\Carbon::parse($dateFrom)->format('M d, Y') : 'Earliest' }}</strong> and <strong>{{ !empty($dateTo) ? \Carbon\Carbon::parse($dateTo)->format('M d, Y') : 'Latest' }}</strong>
            </span>
            <button type="button" wire:click="$set('dateFrom', ''); $set('dateTo', '');" style="background: none; border: none; cursor: pointer; font-weight: 700; font-size: 14px; padding: 0 4px; line-height: 1;" title="Clear date filter">×</button>
        </div>
    @endif

    @if ($layoutMode === 'table')
        <div class="rms-table-responsive">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" wire:model.live="selectAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af;">
                        </th>
                        <th style="width: 60px;">Item No.</th>
                        <th>Control No.</th>
                        <th>QR Code</th>
                        <th>Created</th>
                        <th>Originator</th>
                        <th>Subject</th>
                        <th>Received</th>
                        <th>Released</th>
                        <th>Received</th>
                        <th>Released</th>
                        <th>Elapsed Day</th>
                        <th>Status</th>
                        <th style="width: 60px;">View</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->transactions as $index => $t)
                        @php
                            $isChecked = in_array((string)$t->transaction_id, $selectedIds);
                            $canSeeCtrlNo = (auth()->user()?->permissions?->is_sadm ?? false) || (auth()->user()?->permissions?->can_dts_modify_control_no ?? false) || (auth()->user()?->permissions?->can_dts_modify_transaction ?? false) || (auth()->user()?->permissions?->can_dts_list_modify_transaction ?? false) || (strtolower($t->status ?? '') === 'completed');
                        @endphp
                        <tr style="background-color: {{ $isChecked ? '#f0f6ff' : '' }};">
                            <td style="text-align: center;">
                                <input type="checkbox" wire:model.live="selectedIds" value="{{ $t->transaction_id }}" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e40af;">
                            </td>
                            <td style="text-align: center;">{{ $this->transactions->firstItem() + $index }}</td>
                            <td style="font-weight: 600; color: {{ $canSeeCtrlNo ? '#1e40af' : '#94a3b8' }};">
                                {{ $canSeeCtrlNo ? $t->control_number : '—' }}
                            </td>
                            <td>{{ $t->qr_code }}</td>
                            <td>{{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y h:i A') }}</td>
                            <td>{{ $t->originated_office_name ?: $t->originated_from }}</td>
                            <td style="max-width: 250px; word-break: break-word;">
                                @if(mb_strlen($t->subject ?? '') > 100)
                                    {{ mb_substr($t->subject, 0, 100) }}...
                                    <button type="button" wire:click.stop="openTransaction('{{ $t->transaction_id }}')" class="dts-show-more-link" style="background: none; border: none; padding: 0; margin-left: 2px; color: #2563eb; font-size: inherit; font-weight: 600; cursor: pointer; text-decoration: underline; font-family: inherit; display: inline; vertical-align: baseline;">see more</button>
                                @else
                                    {{ $t->subject }}
                                @endif
                            </td>
                            <td style="text-align: center;">{{ $t->step1_received }}</td>
                            <td style="text-align: center;">{{ $t->step1_released }}</td>
                            <td style="text-align: center;">{{ $t->step2_received }}</td>
                            <td style="text-align: center;">{{ $t->step2_released }}</td>
                            <td style="font-weight: 600; white-space: nowrap; text-align: center;">
                                {{ strtolower($t->status ?? '') === 'completed' ? '' : ($t->elapsed_days . ' day(s)') }}
                            </td>
                            <td style="text-align: center;">
                                <span class="status-badge status-{{ $t->status }}">
                                    {{ $t->status }}
                                </span>
                            </td>
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
        <div class="dts-card-grid-container">
            @forelse ($this->transactions as $index => $t)
                @php
                    $isChecked = in_array((string)$t->transaction_id, $selectedIds);
                    $canSeeCtrlNo = (auth()->user()?->permissions?->is_sadm ?? false) || (auth()->user()?->permissions?->can_dts_modify_control_no ?? false) || (auth()->user()?->permissions?->can_dts_modify_transaction ?? false) || (auth()->user()?->permissions?->can_dts_list_modify_transaction ?? false) || (strtolower($t->status ?? '') === 'completed');
                @endphp
                <div class="dts-box-card {{ $isChecked ? 'selected' : '' }}">
                    
                    <!-- Left side Checkbox -->
                    <div style="display: flex; align-items: center; justify-content: center; height: 20px;">
                        <input type="checkbox" wire:model.live="selectedIds" value="{{ $t->transaction_id }}" style="width: 18px; height: 18px; cursor: pointer; accent-color: #1e40af;">
                    </div>

                    <!-- Right side Info Contents -->
                    <div class="dts-box-card-content">
                        <div class="dts-box-card-header">
                            <span class="dts-box-control-no" style="{{ $canSeeCtrlNo ? '' : 'color: #94a3b8;' }}">{{ $canSeeCtrlNo ? $t->control_number : '—' }}</span>
                            <span class="status-badge status-{{ $t->status }}" style="font-size: 9px; padding: 2px 6px;">{{ $t->status }}</span>
                        </div>

                        <div class="dts-box-subject">
                            @if(mb_strlen($t->subject ?? '') > 100)
                                {{ mb_substr($t->subject, 0, 100) }}...
                                <button type="button" wire:click.stop="openTransaction('{{ $t->transaction_id }}')" class="dts-show-more-link" style="background: none; border: none; padding: 0; margin-left: 2px; color: #2563eb; font-size: inherit; font-weight: 600; cursor: pointer; text-decoration: underline; font-family: inherit; display: inline; vertical-align: baseline;">see more</button>
                            @else
                                {{ $t->subject }}
                            @endif
                        </div>
                        <div class="dts-box-docname">{{ $t->document_name ?? ucfirst($t->classification ?: 'internal') }}</div>

                        <div class="dts-box-footer">
                            <span>Source: {{ $t->originated_office_name }}</span>
                            <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="dts-box-btn-details">View Details</button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="dts-box-empty">
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

    <!-- Details Overlay Modal (Executive Dossier) -->
    @if($selectedTransactionId && $selectedTransaction)
        <div class="dts-im-backdrop" wire:click="closeTransaction">
            <div class="dts-im-card" wire:click.stop>
                
                <!-- ══ Header Banner ══ -->
                <div class="dts-im-header">
                    <div class="dts-im-header-top">
                        <div class="dts-im-header-identity">
                            <div class="dts-im-badge">
                                <i class="fa-solid fa-building-user"></i>
                                <span>INTERNAL TRANSACTION DOSSIER</span>
                            </div>
                            @php
                                $status = strtolower($selectedTransaction->status ?? 'ongoing');
                                $statusConfig = match($status) {
                                    'completed' => ['bg' => '#dcfce7', 'text' => '#15803d', 'border' => '#86efac', 'icon' => 'fa-circle-check', 'label' => 'Completed'],
                                    'revision'  => ['bg' => '#fef3c7', 'text' => '#b45309', 'border' => '#fcd34d', 'icon' => 'fa-triangle-exclamation', 'label' => 'In Revision'],
                                    'cancelled' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'border' => '#fca5a5', 'icon' => 'fa-ban', 'label' => 'Cancelled'],
                                    'drafted'   => ['bg' => '#f1f5f9', 'text' => '#475569', 'border' => '#cbd5e1', 'icon' => 'fa-pen-ruler', 'label' => 'Draft'],
                                    default     => ['bg' => '#e0f2fe', 'text' => '#0369a1', 'border' => '#7dd3fc', 'icon' => 'fa-arrows-rotate fa-spin', 'label' => 'Ongoing']
                                };
                            @endphp
                            <div class="dts-im-status-pill" style="background: {{ $statusConfig['bg'] }}; color: {{ $statusConfig['text'] }}; border: 1px solid {{ $statusConfig['border'] }};">
                                <i class="fa-solid {{ $statusConfig['icon'] }}"></i>
                                <span>{{ $statusConfig['label'] }}</span>
                            </div>
                        </div>
                        <button type="button" class="dts-im-close-btn" wire:click="closeTransaction" title="Close modal">&times;</button>
                    </div>

                    <div class="dts-im-header-main">
                        <div class="dts-im-control-wrap">
                            <span class="dts-im-control-label">Control Number</span>
                            @if ($editingAll && ((auth()->user()?->permissions?->is_sadm ?? false) || (auth()->user()?->permissions?->can_dts_modify_control_no ?? false) || (auth()->user()?->permissions?->can_dts_modify_transaction ?? false) || (auth()->user()?->permissions?->can_dts_list_modify_transaction ?? false)))
                                <input type="text" class="receive-field-input" wire:model="controlNumber" style="font-size: 16px; font-weight: 700; max-width: 300px; color: #0f172a;">
                            @else
                                <div class="dts-im-control-val">
                                    <span>{{ $controlNumber ?: ($selectedTransaction->control_number ?? 'N/A') }}</span>
                                    <button type="button" class="dts-im-copy-btn" onclick="navigator.clipboard.writeText('{{ $controlNumber }}'); const el = this; el.innerHTML = '<i class=\'fa-solid fa-check\'></i> Copied'; setTimeout(() => { el.innerHTML = '<i class=\'fa-regular fa-copy\'></i> Copy'; }, 1500);" title="Copy Control Number">
                                        <i class="fa-regular fa-copy"></i> Copy
                                    </button>
                                </div>
                            @endif
                        </div>

                        <div class="dts-im-meta-cluster">
                            <div class="dts-im-meta-item" title="Date Created">
                                <i class="fa-regular fa-calendar-check"></i>
                                <div>
                                    <span class="dts-im-meta-k">Created</span>
                                    <span class="dts-im-meta-v">{{ !empty($selectedTransaction->date_created) ? \Carbon\Carbon::parse($selectedTransaction->date_created)->format('M d, Y · h:i A') : 'N/A' }}</span>
                                </div>
                            </div>
                            <div class="dts-im-meta-item" title="Originating Office">
                                <i class="fa-solid fa-landmark"></i>
                                <div>
                                    <span class="dts-im-meta-k">Origin Office</span>
                                    <span class="dts-im-meta-v">{{ $selectedTransaction->originated_office_name ?? ($selectedTransaction->originated_from ?? 'N/A') }}</span>
                                </div>
                            </div>
                            <div class="dts-im-meta-item" title="Originating Office">
                                <i class="fa-solid fa-landmark"></i>
                                <div>
                                    <span class="dts-im-meta-k">Origin Office</span>
                                    <span class="dts-im-meta-v">{{ $selectedTransaction->originated_office_name ?? ($selectedTransaction->originated_from ?? 'N/A') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══ Scrollable Body ══ -->
                <div class="dts-im-body">
                    
                    <!-- Panel 1: Document Details & Subject -->
                    <div class="dts-im-card-panel">
                        <div class="dts-im-panel-header">
                            <div class="dts-im-panel-title">
                                <i class="fa-solid fa-align-left" style="color: #2563eb;"></i>
                                <span>Subject &amp; Core Information</span>
                            </div>
                            @if (!empty($selectedTransaction->classification))
                                <span style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 12px; text-transform: uppercase;">
                                    {{ $selectedTransaction->classification }}
                                </span>
                            @endif
                        </div>

                        <!-- Subject / Particulars Box -->
                        @if ($editingAll)
                            <div style="margin-bottom: 14px;">
                                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px;">Subject / Particulars:</label>
                                <textarea class="receive-field-input" wire:model="particulars" style="min-height: 75px; max-height: 180px; width: 100%; border-radius: 8px; padding: 10px 12px; font-size: 13px; line-height: 1.5; resize: vertical;"></textarea>
                            </div>
                        @else
                            <div class="dts-im-subject-box">
                                <div style="font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                                    <i class="fa-regular fa-comment-dots" style="margin-right: 4px; color: #3b82f6;"></i> Subject / Particulars
                                </div>
                                <div>{{ $particulars ?: 'No subject or particulars provided.' }}</div>
                            </div>
                        @endif

                        <!-- Key Attributes Grid -->
                        <div class="dts-im-grid">
                            <!-- Flow Template -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-diagram-project" style="color: #3b82f6;"></i> Flow Template</span>
                                @if ($editingAll)
                                    @php
                                        $availableFlows = DB::table('dts_transaction_flow')
                                            ->where('is_active', true)
                                            ->where('flow_name', 'not like', 'Flow for %')
                                            ->orWhere('flow_code', $transactionFlow)
                                            ->orderBy('flow_name', 'asc')
                                            ->get();
                                    @endphp
                                    <select class="receive-field-input" wire:model.live="transactionFlow" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                        @foreach ($availableFlows as $f)
                                            @php
                                                $fDisplay = null;
                                                if (!empty($f->referenced_flow)) {
                                                    if (preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\\d+)$/', $f->referenced_flow, $matches)) {
                                                        $rName = DB::table('dts_transaction_flow')->where('id', $matches[1])->value('flow_name');
                                                        if (!empty($rName) && !str_starts_with($rName, 'Flow for ') && !str_starts_with($rName, 'REF-')) {
                                                            $fDisplay = $rName;
                                                        }
                                                    } elseif (!str_starts_with($f->referenced_flow, 'REF-') && !str_starts_with($f->referenced_flow, 'FLOW-') && !str_starts_with($f->referenced_flow, 'Flow for ')) {
                                                        $fDisplay = $f->referenced_flow;
                                                    }
                                                }
                                                if (empty($fDisplay) && !empty($f->flow_name)) {
                                                    if (!str_starts_with($f->flow_name, 'Flow for ') && !str_starts_with($f->flow_name, 'REF-') && !str_starts_with($f->flow_name, 'FLOW-')) {
                                                        $fDisplay = $f->flow_name;
                                                    }
                                                }
                                                if (empty($fDisplay) || str_starts_with($fDisplay, 'REF-') || str_starts_with($fDisplay, 'FLOW-') || str_starts_with($fDisplay, 'Flow for ')) {
                                                    $fDisplay = !empty($selectedTransaction->classification)
                                                        ? ucfirst($selectedTransaction->classification)
                                                        : ucfirst($selectedTransaction->trans_type ?? 'Transaction');
                                                }
                                            @endphp
                                            <option value="{{ $f->flow_code }}">{{ $fDisplay }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    @php
                                        $flowRow = DB::table('dts_transaction_flow')->where('flow_code', $transactionFlow)->first();
                                        $flowName = null;
                                        if ($flowRow && !empty($flowRow->referenced_flow)) {
                                            if (preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\\d+)$/', $flowRow->referenced_flow, $matches)) {
                                                $rName = DB::table('dts_transaction_flow')->where('id', $matches[1])->value('flow_name');
                                                if (!empty($rName) && !str_starts_with($rName, 'Flow for ') && !str_starts_with($rName, 'REF-')) {
                                                    $flowName = $rName;
                                                }
                                            } elseif (!str_starts_with($flowRow->referenced_flow, 'REF-') && !str_starts_with($flowRow->referenced_flow, 'FLOW-') && !str_starts_with($flowRow->referenced_flow, 'Flow for ')) {
                                                $flowName = $flowRow->referenced_flow;
                                            }
                                        }
                                        if (empty($flowName) && $flowRow && !empty($flowRow->flow_name)) {
                                            if (!str_starts_with($flowRow->flow_name, 'Flow for ') && !str_starts_with($flowRow->flow_name, 'REF-') && !str_starts_with($flowRow->flow_name, 'FLOW-')) {
                                                $flowName = $flowRow->flow_name;
                                            }
                                        }
                                        if (empty($flowName)) {
                                            $attName = $this->attachedDocName ?? ($attachedDocName ?? '');
                                            if (!empty($attName)) {
                                                $flowName = $attName;
                                            }
                                        }
                                        if (empty($flowName) || str_starts_with($flowName, 'REF-') || str_starts_with($flowName, 'FLOW-') || str_starts_with($flowName, 'Flow for ')) {
                                            $flowName = !empty($selectedTransaction->classification)
                                                ? ucfirst($selectedTransaction->classification)
                                                : ucfirst($selectedTransaction->trans_type ?? 'Transaction');
                                        }
                                    @endphp
                                    <span class="dts-im-tile-v">{{ $flowName }}</span>
                                @endif
                            </div>

                            <!-- Current Station -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-location-dot" style="color: #ef4444;"></i> Current Location</span>
                                <span class="dts-im-tile-v">
                                    <span class="dts-im-office-badge">{{ $selectedTransaction->current_office ?: 'N/A' }}</span>
                                    <span style="font-size: 11px; color: #64748b;">(Step {{ $selectedTransaction->sequence ?? 1 }})</span>
                                </span>
                            </div>

                            <!-- Classification -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-tags" style="color: #8b5cf6;"></i> Classification</span>
                                @if ($editingAll)
                                    @php
                                        $availableClassifications = DB::table('dts_classifications')
                                            ->where('is_active', true)
                                            ->orderBy('classification_name', 'asc')
                                            ->get();
                                    @endphp
                                    <select class="receive-field-input" wire:model="classification" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                        <option value="">Select Classification...</option>
                                        @foreach ($availableClassifications as $c)
                                            <option value="{{ $c->classification_name }}">{{ $c->classification_name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="dts-im-tile-v">{{ $classification ?: ($selectedTransaction->classification ?? 'N/A') }}</span>
                                @endif
                            </div>

                            <!-- Action Needed -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-clipboard-check" style="color: #10b981;"></i> Action Needed</span>
                                @if ($editingAll)
                                    @php
                                        $availableActionNeeded = DB::table('dts_action_options')
                                            ->orderBy('option_name', 'asc')
                                            ->get();
                                    @endphp
                                    <select class="receive-field-input" wire:model="actionNeeded" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                        <option value="">Select Action Needed...</option>
                                        @foreach ($availableActionNeeded as $an)
                                            <option value="{{ $an->option_name }}">{{ $an->option_name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="dts-im-tile-v">{{ $actionNeeded ?: ($selectedTransaction->action_needed ?? 'N/A') }}</span>
                                @endif
                            </div>

                            <!-- Requestor Name -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-regular fa-user" style="color: #6366f1;"></i> Requestor Name</span>
                                @if ($editingAll)
                                    <input type="text" class="receive-field-input" wire:model="requestorName" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                @else
                                    <span class="dts-im-tile-v">{{ $requestorName ?: ($selectedTransaction->requestor_name ?? 'N/A') }}</span>
                                @endif
                            </div>

                            <!-- Requestor Position -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-id-badge" style="color: #6366f1;"></i> Requestor Position</span>
                                @if ($editingAll)
                                    <input type="text" class="receive-field-input" wire:model="requestorPosition" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                @else
                                    <span class="dts-im-tile-v">{{ $requestorPosition ?: ($selectedTransaction->requestor_label ?? 'N/A') }}</span>
                                @endif
                            </div>

                            <!-- Email Access -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-regular fa-envelope" style="color: #0284c7;"></i> Email Access</span>
                                @if ($editingAll)
                                    <input type="email" class="receive-field-input" wire:model="emailAccess" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                @else
                                    <span class="dts-im-tile-v">{{ $emailAccess ?: ($selectedTransaction->access_email ?? 'N/A') }}</span>
                                @endif
                            </div>

                            <!-- Document Password -->
                            <div class="dts-im-tile">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-key" style="color: #f59e0b;"></i> Document Password</span>
                                @if ($editingAll)
                                    <input type="text" class="receive-field-input" wire:model="docPassword" style="height: 36px; padding: 0 8px; border-radius: 6px; font-size: 12px;">
                                @else
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                        <span class="dts-im-tile-v font-mono" style="font-family: monospace;">
                                            {{ $showPassword ? ($docPassword ?: ($selectedTransaction->document_password ?? 'None')) : (!empty($docPassword) || !empty($selectedTransaction->document_password) ? '••••••••' : 'None') }}
                                        </span>
                                        @if (!empty($docPassword) || !empty($selectedTransaction->document_password))
                                            <button type="button" wire:click="$toggle('showPassword')" style="border: none; background: none; color: #64748b; cursor: pointer; font-size: 12px; padding: 0;" title="Toggle Password">
                                                <i class="fa-solid {{ $showPassword ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>


                            <!-- Attached Document -->
                            <div class="dts-im-tile" style="grid-column: span 2;">
                                <span class="dts-im-tile-k"><i class="fa-solid fa-paperclip" style="color: #0891b2;"></i> Attached Document</span>
                                @if (!empty($selectedTransaction->doc_dir))
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 2px;">
                                        <div style="display: flex; align-items: center; gap: 6px; min-width: 0;">
                                            <i class="fa-solid fa-file-pdf" style="color: #dc2626; font-size: 16px;"></i>
                                            <span style="font-size: 12px; font-weight: 600; color: #0284c7; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                {{ $attachedDocName ?: basename($selectedTransaction->doc_dir) }}
                                            </span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                                            <button type="button" class="dts-im-btn" wire:click="openPdfPreviewModal" style="padding: 4px 10px; font-size: 11.5px; color: #0284c7; border-color: #bae6fd; background: #f0f9ff;">
                                                <i class="fa-solid fa-file-pdf"></i> Preview
                                            </button>
                                            <button type="button" class="dts-im-btn" wire:click="openViewFileModal" style="padding: 4px 10px; font-size: 11.5px;">
                                                <i class="fa-solid fa-gear"></i> Manage
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 2px;">
                                        <span style="font-size: 12px; color: #94a3b8; font-style: italic;">No document currently attached</span>
                                        <button type="button" class="dts-im-btn" wire:click="triggerUploadFileModal" style="padding: 4px 10px; font-size: 11.5px; color: #16a34a; border-color: #bbf7d0; background: #f0fdf4;">
                                            <i class="fa-solid fa-cloud-arrow-up"></i> Upload Document
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Panel 2: Routing Flow & Steps -->
                    <div class="dts-im-card-panel">
                        <div class="dts-im-panel-header">
                            <div class="dts-im-panel-title">
                                <i class="fa-solid fa-route" style="color: #2563eb;"></i>
                                <span>Routing Stations &amp; Audit Trail</span>
                            </div>
                            <!-- View Mode Tabs -->
                            <div class="dts-im-tabs">
                                <button type="button" class="dts-im-tab-btn {{ !$showCopyFurnished && $pathViewMode === 'table' ? 'active' : '' }}" wire:click="$set('pathViewMode', 'table'); $set('showCopyFurnished', false);">
                                    <i class="fa-solid fa-table-list"></i> Table View
                                </button>
                                <button type="button" class="dts-im-tab-btn {{ !$showCopyFurnished && $pathViewMode === 'timeline' ? 'active' : '' }}" wire:click="$set('pathViewMode', 'timeline'); $set('showCopyFurnished', false);">
                                    <i class="fa-solid fa-timeline"></i> Timeline View
                                </button>
                                <button type="button" class="dts-im-tab-btn {{ $showCopyFurnished ? 'active' : '' }}" wire:click="$set('showCopyFurnished', true)">
                                    <i class="fa-solid fa-copy"></i> Copy Furnished ({{ count($cfSelectedOffices) }})
                                </button>
                            </div>
                        </div>

                        <!-- Edit Flow Add-Office Toolbar -->
                        @if ($editingAll && !$showCopyFurnished)
                            <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px; background: #eff6ff; padding: 10px 14px; border-radius: 8px; border: 1px solid #bfdbfe;">
                                <i class="fa-solid fa-plus-circle" style="color: #2563eb;"></i>
                                <span style="font-size: 12px; font-weight: 700; color: #1e40af; white-space: nowrap;">Add Station to Path:</span>
                                @php
                                    $allSystemOffices = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
                                        ->where('is_active', true)
                                        ->whereNotIn('office_code', ['ORIGIN', '[H]'])
                                        ->orderBy('office_name', 'asc')
                                        ->get();
                                @endphp
                                <select class="receive-field-input" wire:model="selectedFlowOfficeToAdd" style="flex: 1; height: 34px; padding: 0 10px; border-radius: 6px; font-size: 12px; background: #ffffff;">
                                    <option value="">Select Office to Add...</option>
                                    <option value="ORIGIN">ORIGIN (Creator Office)</option>
                                    <option value="[H]"> [H] (Cluster Head Office)</option>
                                    @foreach ($allSystemOffices as $o)
                                        <option value="{{ $o->office_code }}">{{ $o->office_name }} ({{ $o->office_code }})</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="addFlowOffice" class="dts-im-btn dts-im-btn-primary" style="height: 34px; font-size: 12px; padding: 0 14px;">
                                    Add Office
                                </button>
                            </div>
                        @endif

                        <!-- View 1: Copy Furnished -->
                        @if ($showCopyFurnished)
                            @if ($editingAll)
                                <div style="display: flex; gap: 8px; margin-bottom: 12px; align-items: center; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                    <select class="receive-field-input" style="flex: 1; height: 34px; border-radius: 6px; padding: 0 10px; font-size: 12px;" wire:model="selectedCfOfficeToAdd">
                                        <option value="">-- Select Office to Add to Copy Furnished --</option>
                                        @foreach(DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('is_active', true)->whereNotIn('office_code', ['ORIGIN', '[H]'])->orderBy('office_name', 'asc')->get() as $off)
                                            <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="dts-im-btn dts-im-btn-primary" wire:click="addCfOffice" style="height: 34px; font-size: 12px;">
                                        + Add Office
                                    </button>
                                </div>
                            @endif

                            <div class="dts-im-table-wrap">
                                <table class="dts-im-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px; text-align: center;">#</th>
                                            <th style="width: 140px;">Office Code</th>
                                            <th>Office Name</th>
                                            @if ($editingAll)
                                                <th style="width: 100px; text-align: center;">Actions</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @php
                                            $cfOfficeDetails = collect($cfSelectedOffices)->map(function($code) {
                                                $off = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $code)->first();
                                                return (object)[
                                                    'code' => $code,
                                                    'name' => $off ? $off->office_name : 'Unknown Office'
                                                ];
                                            });
                                        @endphp
                                        @forelse ($cfOfficeDetails as $index => $cf)
                                            <tr>
                                                <td style="text-align: center;">
                                                    <span class="dts-im-step-num">{{ $index + 1 }}</span>
                                                </td>
                                                <td>
                                                    <span class="dts-im-office-badge">{{ $cf->code }}</span>
                                                </td>
                                                <td style="font-weight: 600; color: #1e293b;">{{ $cf->name }}</td>
                                                @if ($editingAll)
                                                    <td style="text-align: center;">
                                                        <button type="button" class="dts-im-btn dts-im-btn-danger" wire:click="removeCfOffice('{{ $cf->code }}')" style="padding: 4px 10px; font-size: 11px;">
                                                            <i class="fa-solid fa-trash-can"></i> Remove
                                                        </button>
                                                    </td>
                                                @endif
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="{{ $editingAll ? 4 : 3 }}" style="padding: 24px; color: #94a3b8; font-style: italic; text-align: center;">
                                                    No copy furnished offices attached to this record.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                        <!-- View 2: Timeline View -->
                        @elseif ($pathViewMode === 'timeline')
                            <div style="width: 100%; overflow-x: auto; padding: 140px 40px 20px 40px; box-sizing: border-box; background: #ffffff; border-radius: 10px; border: 1px solid #e2e8f0; position: relative;">
                                <div style="display: flex; align-items: center; justify-content: {{ count($this->visiblePath) <= 1 ? 'center' : 'space-between' }}; width: 100%; min-width: max-content; padding: 0; position: relative;">
                                    @forelse ($this->visiblePath as $index => $step)
                                        @php
                                            $isReceived = !is_null($step->date_in) || $selectedTransaction->status === 'completed';
                                            $isForwarded = !is_null($step->date_out) || $selectedTransaction->status === 'completed';
                                            $isCompleted = ($isReceived && $isForwarded) || strtolower($selectedTransaction->status ?? '') === 'completed';

                                            $isCurrentOffice = $step->is_active_step && !is_null($step->date_in) && is_null($step->date_out) && $selectedTransaction->status !== 'completed';
                                            $isInTransitToThisOffice = $step->is_active_step && is_null($step->date_in) && $selectedTransaction->status !== 'completed';
                                            $isHubStep = ($step->office_code === '[HUB]' || !empty($step->is_hub));
                                            $isOnHubArea = $isHubStep && ($isCurrentOffice || $step->sequence_ranking == $selectedTransaction->sequence || $selectedTransaction->current_office === '[HUB]' || (!is_null($step->date_in) && is_null($step->date_out)));

                                            if ($isCompleted) {
                                                $dotBg = '#10b981';
                                                $dotBorder = '#10b981';
                                                $lineColor = '#10b981';
                                                $iconHtml = '<i class="fa-solid fa-check" style="font-size: 13px; color: #fff;"></i>';
                                            } elseif ($isOnHubArea) {
                                                $dotBg = '#3b82f6';
                                                $dotBorder = '#60a5fa';
                                                $lineColor = '#3b82f6';
                                                $iconHtml = '<i class="fa-solid fa-arrows-split-up-and-left" style="font-size: 13px; color: #fff;"></i>';
                                            } elseif ($isCurrentOffice) {
                                                $dotBg = '#3b82f6';
                                                $dotBorder = '#2563eb';
                                                $lineColor = '#e2e8f0';
                                                $iconHtml = '<div style="width: 10px; height: 10px; border-radius: 50%; background: #fff;"></div>';
                                            } elseif ($isInTransitToThisOffice) {
                                                $dotBg = '#f59e0b';
                                                $dotBorder = '#d97706';
                                                $lineColor = '#e2e8f0';
                                                $iconHtml = '<i class="fa-solid fa-truck-fast" style="font-size: 11px; color: #fff;"></i>';
                                            } else {
                                                $dotBg = '#cbd5e1';
                                                $dotBorder = '#94a3b8';
                                                $lineColor = '#e2e8f0';
                                                $iconHtml = '';
                                            }
                                        @endphp

                                        <div class="dts-timeline-node-wrapper">
                                            <!-- Dot -->
                                            <div class="dts-timeline-node-dot" style="width: 32px; height: 32px; border-radius: 50%; background: {{ $dotBg }}; border: 3px solid {{ $dotBorder }}; display: flex; align-items: center; justify-content: center; z-index: 2; box-shadow: 0 2px 6px rgba(0,0,0,0.15); cursor: pointer; position: relative;">
                                                {!! $iconHtml !!}
                                                @if ($isCurrentOffice || $isOnHubArea)
                                                    <div style="position: absolute; inset: -5px; border-radius: 50%; border: 2px solid #3b82f6; animation: ping 1.5s cubic-bezier(0, 0, 0.2, 1) infinite; opacity: 0.75; pointer-events: none;"></div>
                                                @endif
                                            </div>

                                            <!-- Label -->
                                            <div style="margin-top: 8px; text-align: center; max-width: 90px;">
                                                <div style="font-size: 11.5px; font-weight: 700; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    {{ $step->office_code }}
                                                </div>
                                                <div style="font-size: 10px; color: #64748b; font-weight: 500;">
                                                    Step {{ $step->sequence_ranking ?? ($index + 1) }}
                                                </div>
                                            </div>

                                            <!-- Tooltip -->
                                            <div class="dts-node-tooltip">
                                                <div style="font-weight: 700; color: #93c5fd; margin-bottom: 2px; font-size: 11.5px;">
                                                    {{ $step->office_code }} - {{ $step->office_name }}
                                                </div>
                                                <div style="color: #cbd5e1; margin-bottom: 4px; font-size: 10.5px;">
                                                    Action: <span style="color: #fff; font-weight: 600;">{{ $step->action_needed ?: 'None' }}</span>
                                                </div>
                                                <div style="border-top: 1px solid rgba(255,255,255,0.15); padding-top: 4px; margin-top: 4px; font-size: 10px; color: #94a3b8;">
                                                    <div>In: {{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('M d, g:i A') : 'Pending' }}</div>
                                                    <div>Out: {{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('M d, g:i A') : 'Pending' }}</div>
                                                    @if(!empty($step->receiver_name) && $step->receiver_name !== '—')
                                                        <div style="margin-top: 2px; color: #86efac;">Recv: {{ $step->receiver_name }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                        @if (!$loop->last)
                                            <div style="flex: 1; height: 3px; background: {{ $lineColor }}; min-width: 40px; margin: 0 4px; align-self: flex-start; margin-top: 15px; z-index: 1;"></div>
                                        @endif
                                    @empty
                                        <div style="color: #94a3b8; font-style: italic; font-size: 13px;">No timeline stations available.</div>
                                    @endforelse
                                </div>
                            </div>

                        <!-- View 3: Table View -->
                        @else
                            <div class="dts-im-table-wrap">
                                <table class="dts-im-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 45px; text-align: center;">Seq</th>
                                            <th style="width: 200px;">Station / Office</th>
                                            <th style="width: 140px;">Date In / Receiver</th>
                                            <th style="width: 140px;">Date Out / Forwarder</th>
                                            <th style="width: 90px;">Duration</th>
                                            <th style="width: 150px;">Action Taken</th>
                                            <th>Notes / Remarks</th>
                                            @if ($editingAll)
                                                <th style="width: 110px; text-align: center;">Actions</th>
                                            @endif
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($this->fullFlowPath as $index => $step)
                                            @php
                                                $isCurrentStep = $step->is_active_step && !is_null($step->date_in) && is_null($step->date_out);
                                                $isPassedStep = !is_null($step->date_out);
                                            @endphp
                                            <tr style="{{ $isCurrentStep ? 'background: #f0fdf4;' : '' }}">
                                                <td style="text-align: center;">
                                                    <span class="dts-im-step-num" style="{{ $isCurrentStep ? 'background: #22c55e; color: #ffffff;' : '' }}">
                                                        {{ $step->sequence_ranking ?? ($index + 1) }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                                        <span class="dts-im-office-badge">{{ $step->office_code }}</span>
                                                        <span style="font-weight: 600; color: #1e293b;">{{ $step->office_name }}</span>
                                                    </div>
                                                    @if ($isCurrentStep)
                                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #dcfce7; color: #166534; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; margin-top: 4px;">
                                                            <i class="fa-solid fa-arrows-spin fa-spin"></i> Active Station
                                                        </span>
                                                    @endif
                                                </td>
                                                <td style="white-space: nowrap;">
                                                    <div style="font-weight: 600; color: #1e293b; font-size: 12px;">
                                                        {{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('M d, Y · h:i A') : 'N/A' }}
                                                    </div>
                                                    @if (!empty($step->receiver_name) && $step->receiver_name !== '—')
                                                        <div class="dts-im-user-badge">
                                                            <i class="fa-solid fa-user-check" style="color: #10b981; font-size: 11px;"></i>
                                                            <span>{{ $step->receiver_name }}</span>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td style="white-space: nowrap;">
                                                    <div style="font-weight: 600; color: #1e293b; font-size: 12px;">
                                                        {{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('M d, Y · h:i A') : ($step->date_in ? 'Pending' : 'N/A') }}
                                                    </div>
                                                    @if (!empty($step->forwarder_name) && $step->forwarder_name !== '—')
                                                        <div class="dts-im-user-badge">
                                                            <i class="fa-solid fa-paper-plane" style="color: #3b82f6; font-size: 11px;"></i>
                                                            <span>{{ $step->forwarder_name }}</span>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if (!empty($step->total_time_completed) && $step->total_time_completed !== '-')
                                                        <span class="dts-im-duration-pill">
                                                            <i class="fa-regular fa-clock" style="color: #0284c7;"></i>
                                                            {{ $step->total_time_completed }}
                                                        </span>
                                                    @else
                                                        <span style="color: #94a3b8;">-</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($step->is_active_step && is_null($step->date_out) && $selectedTransaction->status !== 'completed')
                                                        <select wire:model.live="activeAction" style="padding: 4px 8px; border: 1.5px solid #3b82f6; border-radius: 6px; font-size: 12px; font-weight: 600; background: #eff6ff; color: #1e40af;">
                                                            @foreach($this->actionOptions as $opt)
                                                                <option value="{{ $opt }}">{{ $opt }}</option>
                                                            @endforeach
                                                        </select>
                                                        @if ($activeAction === 'To be Forwarded to')
                                                            <div style="margin-top: 6px;">
                                                                <select wire:model.live="forwardToOfficeCode" style="width: 100%; padding: 4px 8px; border: 1.5px solid #3b82f6; border-radius: 6px; font-size: 11px; font-weight: 600; background: #ffffff; color: #1e40af;">
                                                                    <option value="">-- Target Office --</option>
                                                                    @foreach($this->availableTargetOffices as $availOff)
                                                                        <option value="{{ $availOff->office_code }}">{{ $availOff->office_code }} - {{ $availOff->office_name }}</option>
                                                                    @endforeach
                                                                </select>

                                                                @if($forwardToOfficeCode === '[HUB]')
                                                                    <div style="margin-top: 6px; padding: 6px; background: #f8fafc; border: 1.5px dashed #3b82f6; border-radius: 6px; text-align: left;">
                                                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                                                            <button type="button" 
                                                                                    wire:click="openForwardHubModal" 
                                                                                    style="background: #2563eb; color: #ffffff; border: none; border-radius: 5px; padding: 3px 8px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                                                                <i class="fa-solid fa-plus-circle"></i> Office Hub [ {{ in_array('ALL', $forwardHubOffices) ? 'ALL' : count($forwardHubOffices) }} selected ]
                                                                            </button>
                                                                        </div>
                                                                        @if(empty($forwardHubOffices))
                                                                            <div style="font-size: 11px; color: #dc2626; font-style: italic;">
                                                                                ⚠️ Select Hub offices
                                                                            </div>
                                                                        @else
                                                                            <div style="display: flex; flex-wrap: wrap; gap: 4px; max-height: 80px; overflow-y: auto;">
                                                                                @if(in_array('ALL', $forwardHubOffices))
                                                                                    <span style="display: inline-flex; align-items: center; gap: 4px; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; font-size: 10.5px; font-weight: 700;">
                                                                                        ALL OFFICES
                                                                                        <button type="button" wire:click="clearForwardHubOffices" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 11px; line-height: 1; padding: 0;">&times;</button>
                                                                                    </span>
                                                                                @else
                                                                                    @foreach($forwardHubOffices as $idx => $fCode)
                                                                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; font-size: 10.5px; font-weight: 600;">
                                                                                            <strong>{{ $fCode }}</strong>
                                                                                            <button type="button" wire:click="removeForwardHubOffice({{ $idx }})" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 11px; line-height: 1; padding: 0;">&times;</button>
                                                                                        </span>
                                                                                    @endforeach
                                                                                @endif
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    @else
                                                        @if ($selectedTransaction->status === 'completed' || !is_null($step->date_out))
                                                            <span style="background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                                                <i class="fa-solid fa-check"></i> {{ $step->action_needed ?: 'Finished' }}
                                                            </span>
                                                        @else
                                                            <span style="background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center;">
                                                                {{ $step->action_needed ?: ($step->date_in ? 'Ongoing' : 'Pending') }}
                                                            </span>
                                                        @endif
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($step->is_active_step && is_null($step->date_out) && $selectedTransaction->status !== 'completed')
                                                        <input type="text" wire:model="activeNotes" class="receive-field-input" placeholder="Type notes here..." style="font-size: 12px; height: 30px; padding: 0 8px;">
                                                    @else
                                                        <span style="font-size: 12px; color: {{ $step->note ? '#334155' : '#94a3b8' }};">
                                                            {{ $step->note ?: '-' }}
                                                        </span>
                                                    @endif
                                                </td>
                                                @if ($editingAll)
                                                    <td style="text-align: center; white-space: nowrap;">
                                                        @if (!($step->is_locked ?? false))
                                                            <div style="display: inline-flex; gap: 4px;">
                                                                <button type="button" wire:click="moveFlowOfficeUp({{ $index }})" {{ $index === 0 || ($flowOffices[$index - 1]['is_locked'] ?? false) ? 'disabled' : '' }} style="border: none; background: #f1f5f9; color: {{ $index === 0 || ($flowOffices[$index - 1]['is_locked'] ?? false) ? '#cbd5e1' : '#475569' }}; padding: 5px 8px; border-radius: 4px; font-size: 11px; cursor: {{ $index === 0 || ($flowOffices[$index - 1]['is_locked'] ?? false) ? 'not-allowed' : 'pointer' }}; font-weight: bold;" title="Move Up">
                                                                    <i class="fa-solid fa-arrow-up"></i>
                                                                </button>
                                                                <button type="button" wire:click="moveFlowOfficeDown({{ $index }})" {{ $index === count($flowOffices) - 1 || ($flowOffices[$index + 1]['is_locked'] ?? false) ? 'disabled' : '' }} style="border: none; background: #f1f5f9; color: {{ $index === count($flowOffices) - 1 || ($flowOffices[$index + 1]['is_locked'] ?? false) ? '#cbd5e1' : '#475569' }}; padding: 5px 8px; border-radius: 4px; font-size: 11px; cursor: {{ $index === count($flowOffices) - 1 || ($flowOffices[$index + 1]['is_locked'] ?? false) ? 'not-allowed' : 'pointer' }}; font-weight: bold;" title="Move Down">
                                                                    <i class="fa-solid fa-arrow-down"></i>
                                                                </button>
                                                                <button type="button" wire:click="removeFlowOffice({{ $index }})" style="border: none; background: #fee2e2; color: #dc2626; padding: 5px 8px; border-radius: 4px; font-size: 11px; cursor: pointer; font-weight: bold;" title="Remove Office">
                                                                    <i class="fa-solid fa-trash-can"></i>
                                                                </button>
                                                            </div>
                                                        @else
                                                            <span style="font-size: 11px; color: #94a3b8; font-style: italic;"><i class="fa-solid fa-lock" style="margin-right: 3px;"></i> Locked</span>
                                                        @endif
                                                    </td>
                                                @endif
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="{{ $editingAll ? 8 : 7 }}" style="padding: 24px; color: #94a3b8; font-style: italic; text-align: center;">No transaction routing paths listed.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                </div>

                <!-- ══ Docked Action Toolbar Footer ══ -->
                <div class="dts-im-footer">
                    <!-- Left Utility Group -->
                    <div class="dts-im-footer-group">
                        <button type="button" class="dts-im-btn" onclick="openQrViewModal('{{ $selectedTransaction->qr_code ?? '' }}')">
                            <i class="fa-solid fa-qrcode" style="color: #0284c7;"></i>
                            <span>View QR</span>
                        </button>

                        @if (!empty($selectedTransaction->doc_dir))
                            <button type="button" class="dts-im-btn" wire:click="openPdfPreviewModal">
                                <i class="fa-solid fa-file-pdf" style="color: #dc2626;"></i>
                                <span>Preview Document</span>
                            </button>
                        @endif
                    </div>

                    <!-- Right Controls Group -->
                    <div class="dts-im-footer-group">
                        <!-- Operational Forward / Complete -->
                        @php
                            $allowManualComplete = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings')->where('key', 'dts_allow_manual_completion_button')->value('value') === 'true';
                            $effectiveCurrentOffice = ($selectedTransaction->current_office === 'ORIGIN') ? $selectedTransaction->originated_from : $selectedTransaction->current_office;
                            $isUserOffice = ($effectiveCurrentOffice === auth()->user()?->details?->office?->office_code);
                        @endphp
                        @if ($isUserOffice && $selectedTransaction->status !== 'completed')
                            @if ($this->isLastStep() && $activeAction !== 'To be Forwarded to')
                                @if ($allowManualComplete)
                                    <button type="button" class="dts-im-btn dts-im-btn-success" wire:click="triggerCompletionConfirm">
                                        <i class="fa-solid fa-check"></i>
                                        <span>Complete Transaction</span>
                                    </button>
                                @endif
                            @else
                                <button type="button" class="dts-im-btn dts-im-btn-primary" wire:click="completeTransaction">
                                    <i class="fa-solid fa-paper-plane"></i>
                                    <span>{{ $activeAction === 'To be Forwarded to' ? 'Forward to Office' : 'Forward Transaction' }}</span>
                                </button>
                            @endif
                        @endif

                        <!-- Restore / Reopen to Active -->
                        @if ($selectedTransaction->status === 'completed' || $selectedTransaction->current_office === 'ORIGIN')
                            <button type="button" class="dts-im-btn" wire:click="reopenTransaction" style="color: #ea580c; border-color: #fdba74;" title="Restore this record back to Active">
                                <i class="fa-solid fa-arrow-rotate-left"></i>
                                <span>Restore to Active</span>
                            </button>
                        @endif

                        <!-- Edit / Save Toggle -->
                        @php
                            $perms = auth()->user()?->permissions;
                            $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false) || ($perms->can_dts_modify_control_no ?? false));
                            $canEditMetadata = ($selectedTransaction->status !== 'completed') || $canListModify;
                        @endphp
                        @if ($canEditMetadata)
                            @if ($editingAll)
                                <button type="button" class="dts-im-btn dts-im-btn-success" wire:click="saveMetadataOnly">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                    <span>Save Changes</span>
                                </button>
                                <button type="button" class="dts-im-btn" wire:click="toggleEditAll">
                                    <span>Cancel</span>
                                </button>
                            @else
                                <button type="button" class="dts-im-btn" wire:click="toggleEditAll">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                    <span>Edit Info</span>
                                </button>
                            @endif
                        @endif

                        <!-- Close Modal Button -->
                        <button type="button" class="dts-im-btn" wire:click="closeTransaction">
                            Close
                        </button>
                    </div>
                </div>

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
                            $canListModify = $perms && ($perms->is_sadm || ($perms->can_dts_list_modify_transaction ?? false) || ($perms->can_dts_modify_transaction ?? false) || ($perms->can_dts_modify_control_no ?? false));
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
    <div id="dts-qr-view-modal" class="modal-backdrop" wire:ignore wire:key="dts-qr-view-modal-internal" style="display: none; z-index: 1000000; align-items: center; justify-content: center;" onclick="closeQrViewModal()">
        <div class="modal-content" style="max-width: 320px; padding: 24px; text-align: center; position: relative; border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 14px;" onclick="event.stopPropagation()">
            <button type="button" class="modal-close-btn" style="position: absolute; top: 12px; right: 16px; font-size: 20px; border: none; background: transparent; cursor: pointer; color: #94a3b8;" onclick="closeQrViewModal()">&times;</button>
            <h3 style="margin: 0; font-family: Roboto, sans-serif; font-size: 16px; font-weight: 700; color: #043899; text-transform: uppercase;">QR Code Scan</h3>
            
            <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1.5px solid #cbd5e1; display: flex; align-items: center; justify-content: center; width: 182px; height: 182px; box-sizing: border-box; margin: 0 auto;">
                <img id="dts-qr-image" src="" alt="QR Code" style="width: 150px; height: 150px; display: none;">
                <div id="dts-qr-loading" style="font-family: Roboto, sans-serif; font-size: 13px; color: #64748b;">Generating...</div>
            </div>

            <div style="font-family: monospace; font-weight: 700; font-size: 14px; color: #1e293b; word-break: break-all; background: #f1f5f9; padding: 6px 12px; border-radius: 6px; border: 1px solid #e2e8f0; width: 100%; box-sizing: border-box;" id="dts-qr-code-text"></div>

            <button type="button" onclick="printQrCodeFromModal()" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; background: #0284c7; color: #ffffff; border: none; border-radius: 8px; padding: 10px 16px; font-family: Roboto, sans-serif; font-size: 13px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25); transition: background-color 0.15s ease;">
                <i class="fa-solid fa-print"></i> Print QR Code
            </button>
        </div>
    </div>

    <!-- Dynamic QR Code Print Modal -->
    @include('components.dts.qr-print-modal')

    <!-- Export Modal -->
    <x-dts.export-modal
        :show="$showExportModal"
        category="internal"
        :available-columns="\App\Helpers\DtsExportHelper::getAvailableColumns('internal', $exportFlowCount)"
        :selected-columns="$exportColumns"
        :format="$exportFormat"
        :selected-count="count($selectedIds)"
        :flow-count="$exportFlowCount"
        :prepared-by="$exportPreparedBy"
        :noted-by="$exportNotedBy"
    />

    <!-- Office Hub Collective Modal for Forwarding -->
    <x-dts.hub-collective-modal 
        :show="$showForwardHubModal" 
        :search="$forwardHubSearch" 
        :selected="$forwardHubTempSelected" 
        :offices="$this->availableTargetOffices"
        wireClose="closeForwardHubModal"
        wireToggle="toggleForwardHubOffice"
        wireSelectAll="selectAllForwardHubOffices"
        wireClear="clearForwardHubOffices"
        wireApply="applyForwardHubModal"
        wireSearch="forwardHubSearch"
    />
</div>
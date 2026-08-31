<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.admin')] #[Title('Admin Console - Record Series')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    public string $search = '';
    public string $statusFilter = '';

    // Active Tab state: 'unregistered' or rdp_record_series_type.id (as string)
    public string $activeTab = 'unregistered';

    // Add Record Series Type Modal State
    public bool $showAddTypeModal = false;
    public string $newTypeName = '';
    public string $newTypeShortCode = '';

    // File Import State
    public bool $showImportModal = false;
    public $importFile = null;

    // Add Form Fields (Matching records-and-disposition-schedule)
    public ?string $newItemNumber = '';
    public string $series_title = '';
    public array $subsections = [];
    public string $bracketInput = '';
    public ?string $newOfficeCode = '';
    public bool $showBracketDropdown = false;
    public bool $showParentDropdown = false;
    public string $newActivePeriod = '';
    public string $newStoragePeriod = '';
    public string $newTotalPeriod = '';
    public string $newRemarks = '';
    public bool $newIsPermanent = false;
    public bool $showAddForm = false;

    // Edit State
    public ?int $editingId = null;
    public ?string $editItemNumber = '';
    public string $editSeriesTitle = '';
    public string $editBracketInput = '';
    public ?string $editOfficeCode = '';
    public bool $showEditBracketDropdown = false;
    public ?int $editSeriesType = null;
    public string $editActivePeriod = '';
    public string $editStoragePeriod = '';
    public string $editTotalPeriod = '';
    public string $editRemarks = '';
    public bool $editIsActive = false;
    public bool $editIsPermanent = false;

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_rdp_admin)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function selectTab(string $tabKey): void
    {
        $this->activeTab = $tabKey;
        $this->resetPage();
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function toggleAddForm(): void
    {
        $this->showAddForm = !$this->showAddForm;
        if ($this->showAddForm) {
            $this->resetAddForm();
        }
    }

    public function addSubsection(): void
    {
        $this->subsections[] = '';
    }

    public function removeSubsection(int $index): void
    {
        if (isset($this->subsections[$index])) {
            unset($this->subsections[$index]);
            $this->subsections = array_values($this->subsections);
        }
    }

    public function selectBracketSuggestion(string $bracketName): void
    {
        $this->bracketInput = mb_strtoupper($bracketName);
        $this->showBracketDropdown = false;
    }

    public function selectEditBracketSuggestion(string $bracketName): void
    {
        $this->editBracketInput = mb_strtoupper($bracketName);
        $this->showEditBracketDropdown = false;
    }

    public function updatedSeriesTitle(): void
    {
        $this->showParentDropdown = true;
    }

    public function updatedBracketInput(): void
    {
        $this->showBracketDropdown = true;
    }

    public function selectParentSuggestion(string $title): void
    {
        $this->series_title = mb_strtoupper($title);
        $this->showParentDropdown = false;
    }

    public function openAddTypeModal(): void
    {
        $this->newTypeName = '';
        $this->newTypeShortCode = '';
        $this->showAddTypeModal = true;
    }

    public function closeAddTypeModal(): void
    {
        $this->showAddTypeModal = false;
        $this->newTypeName = '';
        $this->newTypeShortCode = '';
    }

    public function openImportModal(): void
    {
        $this->importFile = null;
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->importFile = null;
        $this->showImportModal = false;
    }

    public function saveRecordType(): void
    {
        $this->clearMessages();

        $typeName = trim($this->newTypeName);
        $shortCode = mb_strtoupper(trim($this->newTypeShortCode));

        if (empty($typeName)) {
            $this->errorMessage = 'Record Series Type Name is required.';
            return;
        }

        if (empty($shortCode)) {
            $shortCode = mb_strtoupper(substr(str_replace(' ', '', $typeName), 0, 10));
        }

        try {
            $existing = DB::table('rdp_record_series_type')
                ->where('type_name', 'ilike', $typeName)
                ->orWhere('shorted_type', 'ilike', $shortCode)
                ->first();

            if ($existing) {
                $this->errorMessage = "Record Series Type \"{$typeName}\" ({$shortCode}) already exists.";
                return;
            }

            $newTypeId = DB::table('rdp_record_series_type')->insertGetId([
                'type_name'    => $typeName,
                'shorted_type' => $shortCode,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                'changes'      => "Registered new Record Series Type: \"{$typeName}\" ({$shortCode})",
                'admin_id'     => auth()->id(),
                'what_system'  => 2,
                'when_changes' => now(),
            ]);

            $this->successMessage = "Record Series Type \"{$typeName}\" added successfully!";
            $this->closeAddTypeModal();
            $this->activeTab = (string)$newTypeId;
            $this->resetPage();

        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to add Record Series Type: ' . $e->getMessage();
        }
    }

    public function resolveBracketId(string $inputName): ?int
    {
        $inputName = mb_strtoupper(trim($inputName));
        if (empty($inputName)) {
            return null;
        }

        $existing = DB::table('rdp_record_series_brackets')
            ->where('bracket_name', 'ilike', $inputName)
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return DB::table('rdp_record_series_brackets')->insertGetId([
            'bracket_name' => $inputName,
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function resolveOfficeCode(?string $input, bool $onlyExisting = false): ?string
    {
        $input = trim(rtrim($input ?? '', ';'));
        if (empty($input)) {
            return null;
        }

        // 1. Check exact or ILIKE match on office_code or office_name in office table
        $existing = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
            ->where('is_active', true)
            ->where(function($q) use ($input) {
                $q->where('office_code', 'ilike', $input)
                  ->orWhere('office_name', 'ilike', $input);
            })
            ->first();

        if ($existing) {
            return $existing->office_code;
        }

        return $onlyExisting ? null : mb_strtoupper($input);
    }

    public function computeTotalPeriod(?string $active, ?string $storage, bool $isPermanent): string
    {
        if ($isPermanent) {
            return 'Permanent';
        }

        $active = trim($active ?? '');
        $storage = trim($storage ?? '');

        if (empty($active) && empty($storage)) {
            return '';
        }

        if (empty($active)) {
            return mb_strtoupper($storage);
        }

        if (empty($storage)) {
            return mb_strtoupper($active);
        }

        $parseTime = function(string $str) {
            $years = 0;
            $months = 0;
            if (preg_match('/(\d+)\s*(?:year|yr|y)s?/i', $str, $m)) {
                $years = (int)$m[1];
            }
            if (preg_match('/(\d+)\s*(?:month|mo|m)s?/i', $str, $m)) {
                $months = (int)$m[1];
            }
            return [$years, $months];
        };

        [$aYears, $aMonths] = $parseTime($active);
        [$sYears, $sMonths] = $parseTime($storage);

        if (($aYears > 0 || $aMonths > 0) && ($sYears > 0 || $sMonths > 0)) {
            $totalMonths = ($aYears * 12 + $aMonths) + ($sYears * 12 + $sMonths);
            $tYears = intdiv($totalMonths, 12);
            $remMonths = $totalMonths % 12;

            $parts = [];
            if ($tYears > 0) {
                $parts[] = $tYears . ' ' . ($tYears === 1 ? 'Year' : 'Years');
            }
            if ($remMonths > 0) {
                $parts[] = $remMonths . ' ' . ($remMonths === 1 ? 'Month' : 'Months');
            }
            return mb_strtoupper(implode(' ', $parts));
        }

        return mb_strtoupper($active . ' + ' . $storage);
    }

    private function resolveEffectiveRetention(array $allSeriesMap, object $record): object
    {
        $current = $record;
        $visited = [];

        while ($current) {
            $hasActive = !empty(trim($current->active_period ?? ''));
            $hasStorage = !empty(trim($current->storage_period ?? ''));
            $hasTotal = !empty(trim($current->total_period ?? ''));
            $isPerm = (bool)($current->is_retention_period_permanent ?? false);

            if ($isPerm || $hasActive || $hasStorage || $hasTotal) {
                return (object)[
                    'active_period'                  => $current->active_period,
                    'storage_period'                 => $current->storage_period,
                    'total_period'                   => $current->total_period,
                    'is_retention_period_permanent' => $isPerm,
                    'inherited'                      => $current->id !== $record->id,
                ];
            }

            if (in_array($current->id, $visited, true)) {
                break;
            }
            $visited[] = $current->id;

            $pId = $current->parent_id ?? null;
            $current = ($pId && isset($allSeriesMap[$pId])) ? $allSeriesMap[$pId] : null;
        }

        return (object)[
            'active_period'                  => null,
            'storage_period'                 => null,
            'total_period'                   => null,
            'is_retention_period_permanent' => false,
            'inherited'                      => false,
        ];
    }

    private function buildGroupedTreeHierarchy(array $records): array
    {
        $byBracketAndOffice = [];
        foreach ($records as $r) {
            $bKey = $r->bracket_id ? ('b_' . $r->bracket_id) : 'no_bracket';
            $oKey = $r->recorded_at_office ? ('o_' . $r->recorded_at_office) : 'no_office';
            $byBracketAndOffice[$bKey][$oKey][] = $r;
        }

        $finalOrdered = [];

        foreach ($byBracketAndOffice as $bKey => $officeGroups) {
            foreach ($officeGroups as $oKey => $items) {
                $itemIdsInGroup = array_column($items, 'id');
                $byParent = [];
                foreach ($items as $item) {
                    $pId = $item->parent_id ?? 0;
                    if ($pId > 0 && !in_array($pId, $itemIdsInGroup, true)) {
                        $pId = 0;
                    }
                    $byParent[$pId][] = $item;
                }

                $groupOrdered = [];
                $flatten = function ($parentId, $depth) use (&$flatten, &$groupOrdered, $byParent) {
                    if (!isset($byParent[$parentId])) {
                        return;
                    }
                    foreach ($byParent[$parentId] as $item) {
                        $item->depth = $depth;
                        $groupOrdered[] = $item;
                        $flatten($item->id, $depth + 1);
                    }
                };

                $flatten(0, 0);

                $addedIds = array_column($groupOrdered, 'id');
                foreach ($items as $item) {
                    if (!in_array($item->id, $addedIds, true)) {
                        $item->depth = 0;
                        $groupOrdered[] = $item;
                    }
                }

                foreach ($groupOrdered as $go) {
                    $finalOrdered[] = $go;
                }
            }
        }

        return $finalOrdered;
    }

    public function resetAddForm(): void
    {
        $this->newItemNumber = '';
        $this->series_title = '';
        $this->subsections = [];
        $this->bracketInput = '';
        $this->newOfficeCode = '';
        $this->showBracketDropdown = false;
        $this->showParentDropdown = false;
        $this->newActivePeriod = '';
        $this->newStoragePeriod = '';
        $this->newTotalPeriod = '';
        $this->newRemarks = '';
        $this->newIsPermanent = false;
    }

    public function addSeries(): void
    {
        $this->clearMessages();

        $parentTitle = mb_strtoupper(trim($this->series_title));
        if (empty($parentTitle)) {
            $this->errorMessage = 'Series title is required.';
            return;
        }

        $allTitles = [$parentTitle];
        foreach ($this->subsections as $sub) {
            $trimmed = mb_strtoupper(trim($sub));
            if (!empty($trimmed)) {
                $allTitles[] = $trimmed;
            }
        }

        $bracketId = $this->resolveBracketId($this->bracketInput);
        $officeCode = $this->resolveOfficeCode($this->newOfficeCode);
        $seriesTypeId = is_numeric($this->activeTab) ? (int) $this->activeTab : null;
        $itemNum = trim($this->newItemNumber ?? '');
        $itemNumberVal = ($itemNum !== '' && is_numeric($itemNum)) ? (int) $itemNum : null;

        $retentionId = null;
        $computedTotal = $this->computeTotalPeriod($this->newActivePeriod, $this->newStoragePeriod, $this->newIsPermanent);
        $activePeriod = $this->newIsPermanent ? 'Permanent' : (trim($this->newActivePeriod) ?: null);
        $storagePeriod = $this->newIsPermanent ? 'Permanent' : (trim($this->newStoragePeriod) ?: null);
        $totalPeriod = $computedTotal ?: null;

        if ($this->newIsPermanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
            $retentionId = DB::table('rdp_retention_period')->insertGetId([
                'active_period'  => $activePeriod,
                'storage_period' => $storagePeriod,
                'total_period'   => $totalPeriod,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        try {
            DB::beginTransaction();

            $currentParentId = null;

            foreach ($allTitles as $idx => $t) {
                $isLeaf = ($idx === count($allTitles) - 1);

                $existing = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $t)
                    ->where('parent_id', $currentParentId)
                    ->first();

                $seriesData = [
                    'item_number'                  => ($idx === 0) ? $itemNumberVal : ($existing->item_number ?? null),
                    'series_title'                 => $t,
                    'parent_id'                    => $currentParentId,
                    'bracket_id'                   => $bracketId,
                    'recorded_at_office'           => $officeCode,
                    'series_type'                  => $seriesTypeId,
                    'retention_period'             => $isLeaf ? $retentionId : ($existing->retention_period ?? null),
                    'is_retention_period_permanent' => $isLeaf ? $this->newIsPermanent : ($existing->is_retention_period_permanent ?? false),
                    'is_verified'                  => true,
                    'is_active'                    => true,
                    'remarks'                      => $isLeaf ? (trim($this->newRemarks) ?: null) : ($existing->remarks ?? null),
                    'updated_at'                   => now(),
                ];

                if ($existing) {
                    DB::table('rdp_record_series')->where('id', $existing->id)->update($seriesData);
                    $currentParentId = $existing->id;
                } else {
                    $seriesData['created_at'] = now();
                    $currentParentId = DB::table('rdp_record_series')->insertGetId($seriesData);
                }
            }

            DB::commit();

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                'changes'      => "Added Record Series: \"{$parentTitle}\"",
                'admin_id'     => auth()->id(),
                'what_system'  => 2,
                'when_changes' => now(),
            ]);

            $this->successMessage = "Record Series \"{$parentTitle}\" hierarchy created successfully.";
            $this->resetAddForm();
            $this->showAddForm = false;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to create record series: ' . $e->getMessage();
        }
    }

    public function startEdit(int $seriesId): void
    {
        $this->clearMessages();

        $series = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series_brackets', 'rdp_record_series.bracket_id', '=', 'rdp_record_series_brackets.id')
            ->select('rdp_record_series.*', 'rdp_retention_period.active_period', 'rdp_retention_period.storage_period', 'rdp_retention_period.total_period', 'rdp_record_series_brackets.bracket_name')
            ->where('rdp_record_series.id', $seriesId)
            ->first();

        if (!$series) return;

        $this->editingId = $series->id;
        $this->editItemNumber = $series->item_number !== null ? (string)$series->item_number : '';
        $this->editSeriesTitle = $series->series_title;
        $this->editBracketInput = $series->bracket_name ?? '';
        $this->editOfficeCode = $series->recorded_at_office ?? '';
        $this->showEditBracketDropdown = false;
        $this->editSeriesType = $series->series_type;
        $this->editIsPermanent = (bool) $series->is_retention_period_permanent;
        $this->editActivePeriod = $this->editIsPermanent ? '' : ($series->active_period ?? '');
        $this->editStoragePeriod = $this->editIsPermanent ? '' : ($series->storage_period ?? '');
        $this->editTotalPeriod = $series->total_period ?? '';
        $this->editRemarks = $series->remarks ?? '';
        $this->editIsActive = (bool) $series->is_active;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function updateSeries(): void
    {
        $this->clearMessages();

        if (!$this->editingId) return;

        $title = mb_strtoupper(trim($this->editSeriesTitle));
        if (empty($title)) {
            $this->errorMessage = 'Series title is required.';
            return;
        }

        $existingSeries = DB::table('rdp_record_series')->where('id', $this->editingId)->first();
        if (!$existingSeries) return;

        $retentionId = $existingSeries->retention_period;
        $computedTotal = $this->computeTotalPeriod($this->editActivePeriod, $this->editStoragePeriod, $this->editIsPermanent);
        $activePeriod = $this->editIsPermanent ? 'Permanent' : (trim($this->editActivePeriod) ?: null);
        $storagePeriod = $this->editIsPermanent ? 'Permanent' : (trim($this->editStoragePeriod) ?: null);
        $totalPeriod = $computedTotal ?: null;

        if ($this->editIsPermanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
            $retentionData = [
                'active_period'  => $activePeriod,
                'storage_period' => $storagePeriod,
                'total_period'   => $totalPeriod,
                'updated_at'     => now(),
            ];

            if ($retentionId) {
                DB::table('rdp_retention_period')->where('id', $retentionId)->update($retentionData);
            } else {
                $retentionData['created_at'] = now();
                $retentionId = DB::table('rdp_retention_period')->insertGetId($retentionData);
            }
        }

        $bracketId = $this->resolveBracketId($this->editBracketInput);
        $officeCode = $this->resolveOfficeCode($this->editOfficeCode);
        $itemNum = trim($this->editItemNumber ?? '');
        $itemNumberVal = ($itemNum !== '' && is_numeric($itemNum)) ? (int) $itemNum : null;

        DB::table('rdp_record_series')->where('id', $this->editingId)->update([
            'item_number'                  => $itemNumberVal,
            'series_title'                 => $title,
            'bracket_id'                   => $bracketId,
            'recorded_at_office'           => $officeCode,
            'retention_period'             => $retentionId,
            'is_retention_period_permanent' => $this->editIsPermanent,
            'is_active'                    => $this->editIsActive,
            'remarks'                      => trim($this->editRemarks) ?: null,
            'updated_at'                   => now(),
        ]);

        // Log admin action
        DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'changes'      => "Updated Record Series: \"{$title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$title}\" has been updated successfully.";
        $this->editingId = null;
    }

    public function importRecordSeries(): void
    {
        $this->clearMessages();

        $this->validate([
            'importFile' => 'required|file|extensions:txt,text|max:2048',
        ], [
            'importFile.required'   => 'Please select a text file to upload.',
            'importFile.extensions' => 'The file must be a plain text file (.txt).',
            'importFile.max'        => 'The file size must be less than 2MB.',
        ]);

        try {
            $content = $this->importFile->get();
            if ($content === false || $content === null) {
                $content = @file_get_contents($this->importFile->getRealPath());
            }

            if (!$content) {
                throw new \Exception('Could not read the uploaded text file content.');
            }

            $rawLines = preg_split('/\r\n|\r|\n/', $content);
            $seriesTypeId = is_numeric($this->activeTab) ? (int)$this->activeTab : null;

            $stack = []; // subsection parent tracking: depth => series_id
            $blockStack = []; // array of ['type' => 'bracket'|'office', 'val' => mixed]
            $importedCount = 0;

            $getCurrentBracketId = function() use (&$blockStack) {
                for ($i = count($blockStack) - 1; $i >= 0; $i--) {
                    if ($blockStack[$i]['type'] === 'bracket') {
                        return $blockStack[$i]['val'];
                    }
                }
                return null;
            };

            $getCurrentOfficeCode = function() use (&$blockStack) {
                for ($i = count($blockStack) - 1; $i >= 0; $i--) {
                    if ($blockStack[$i]['type'] === 'office') {
                        return $blockStack[$i]['val'];
                    }
                }
                return null;
            };

            $pendingItemNo = null;
            $pendingTitle = null;
            $pendingDepth = 0;
            $pendingActive = null;
            $pendingStorage = null;
            $pendingIsPermanent = false;
            $pendingRemarks = null;
            $hasPendingSeries = false;

            $flushPending = function () use (
                &$pendingItemNo, &$pendingTitle, &$pendingDepth,
                &$pendingActive, &$pendingStorage, &$pendingIsPermanent, &$pendingRemarks,
                &$hasPendingSeries, &$stack, &$getCurrentBracketId, &$getCurrentOfficeCode, &$seriesTypeId, &$importedCount
            ) {
                if (!$hasPendingSeries || empty($pendingTitle)) {
                    $hasPendingSeries = false;
                    return;
                }

                $retentionId = null;
                $computedTotal = $this->computeTotalPeriod($pendingActive, $pendingStorage, $pendingIsPermanent);
                $activePeriod = $pendingIsPermanent ? 'Permanent' : (trim($pendingActive ?? '') ?: null);
                $storagePeriod = $pendingIsPermanent ? 'Permanent' : (trim($pendingStorage ?? '') ?: null);
                $totalPeriod = $computedTotal ?: null;

                if ($pendingIsPermanent || !empty($activePeriod) || !empty($storagePeriod) || !empty($totalPeriod)) {
                    $retentionId = DB::table('rdp_retention_period')->insertGetId([
                        'active_period'  => $activePeriod,
                        'storage_period' => $storagePeriod,
                        'total_period'   => $totalPeriod,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                $parentId = null;
                if ($pendingDepth > 0 && isset($stack[$pendingDepth - 1])) {
                    $parentId = $stack[$pendingDepth - 1];
                }

                $bId = $getCurrentBracketId();
                $oCode = $getCurrentOfficeCode();

                // Check for existing duplicate series to prevent duplicates
                $existingQuery = DB::table('rdp_record_series')
                    ->where('series_title', 'ilike', $pendingTitle);

                if ($parentId === null) {
                    $existingQuery->whereNull('parent_id');
                } else {
                    $existingQuery->where('parent_id', $parentId);
                }

                if ($bId === null) {
                    $existingQuery->whereNull('bracket_id');
                } else {
                    $existingQuery->where('bracket_id', $bId);
                }

                if ($oCode === null) {
                    $existingQuery->whereNull('recorded_at_office');
                } else {
                    $existingQuery->where('recorded_at_office', $oCode);
                }

                if ($seriesTypeId === null) {
                    $existingQuery->whereNull('series_type');
                } else {
                    $existingQuery->where('series_type', $seriesTypeId);
                }

                $existing = $existingQuery->first();

                if ($existing) {
                    $updateData = ['updated_at' => now()];
                    if ($pendingItemNo !== null) {
                        $updateData['item_number'] = $pendingItemNo;
                    }
                    if ($retentionId !== null) {
                        $updateData['retention_period'] = $retentionId;
                    }
                    if ($pendingIsPermanent) {
                        $updateData['is_retention_period_permanent'] = true;
                    }
                    if (!empty($pendingRemarks)) {
                        $updateData['remarks'] = trim($pendingRemarks);
                    }

                    DB::table('rdp_record_series')->where('id', $existing->id)->update($updateData);
                    $seriesId = $existing->id;
                } else {
                    $seriesId = DB::table('rdp_record_series')->insertGetId([
                        'item_number'                  => $pendingItemNo,
                        'series_title'                 => $pendingTitle,
                        'parent_id'                    => $parentId,
                        'bracket_id'                   => $bId,
                        'recorded_at_office'           => $oCode,
                        'series_type'                  => $seriesTypeId,
                        'retention_period'             => $retentionId,
                        'is_retention_period_permanent' => $pendingIsPermanent,
                        'is_verified'                  => true,
                        'is_active'                    => true,
                        'remarks'                      => trim($pendingRemarks ?? '') ?: null,
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ]);
                    $importedCount++;
                }

                $stack[$pendingDepth] = $seriesId;
                foreach (array_keys($stack) as $d) {
                    if ($d > $pendingDepth) {
                        unset($stack[$d]);
                    }
                }

                $pendingItemNo = null;
                $pendingTitle = null;
                $pendingDepth = 0;
                $pendingActive = null;
                $pendingStorage = null;
                $pendingIsPermanent = false;
                $pendingRemarks = null;
                $hasPendingSeries = false;
            };

            foreach ($rawLines as $rawLine) {
                $line = trim($rawLine);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                // Check for Bracket block opening: [ <bracket name> ] { or [ <bracket name> ]{
                if (preg_match('/^\[\s*(.+?)\s*\]\s*\{$/', $line, $matches)) {
                    $flushPending();
                    $bracketName = trim($matches[1]);
                    $bracketId = $this->resolveBracketId($bracketName);
                    $blockStack[] = ['type' => 'bracket', 'val' => $bracketId];
                    $stack = [];
                    continue;
                }

                // Check for Office Section block opening: ( <section name> ) { or ( <section name> ){
                if (preg_match('/^\(\s*(.+?)\s*\)\s*\{$/', $line, $matches)) {
                    $flushPending();
                    $sectionName = trim($matches[1]);
                    $officeCode = $this->resolveOfficeCode($sectionName);
                    $blockStack[] = ['type' => 'office', 'val' => $officeCode];
                    $stack = [];
                    continue;
                }

                // Check for Block closing: }; or }
                if ($line === '};' || $line === '}') {
                    $flushPending();
                    array_pop($blockStack);
                    $stack = [];
                    continue;
                }

                // Check for Root series line starting with =: e.g. =001; or =ACTION PLAN;
                if (str_starts_with($line, '=')) {
                    $flushPending();
                    $trimmed = ltrim($line, '=');
                    $parts = explode(';', $trimmed);
                    $firstVal = trim($parts[0]);

                    if (is_numeric($firstVal)) {
                        $pendingItemNo = (int)$firstVal;
                        $pendingTitle = count($parts) > 1 ? trim($parts[1]) : null;
                    } else {
                        $pendingItemNo = null;
                        $pendingTitle = $firstVal;
                    }

                    $pendingDepth = 0;
                    $hasPendingSeries = true;
                    continue;
                }

                // Check for Subsection line starting with -: e.g. - Title; or -- Title;
                if (str_starts_with($line, '-')) {
                    $flushPending();
                    preg_match('/^(-+)\s*(.+)$/', $line, $hyphenMatches);
                    $hyphens = $hyphenMatches[1] ?? '-';
                    $rest = trim($hyphenMatches[2] ?? '');
                    $rest = rtrim($rest, ';');

                    $pendingDepth = strlen($hyphens);
                    $pendingTitle = $rest;
                    $pendingItemNo = null;
                    $hasPendingSeries = true;
                    continue;
                }

                // Retention line: [A=..., S=...], [P], Permanent;, P;, etc.
                $lineLower = strtolower(rtrim($line, ';'));
                $isPermLine = in_array($lineLower, ['p', 'permanent', '[p]', '[permanent]'], true);

                if ($hasPendingSeries && (str_starts_with($line, '[') || $isPermLine)) {
                    $cleanLine = rtrim($line, ';');
                    if ($isPermLine || stristr($cleanLine, 'permanent') || strcasecmp($cleanLine, 'P') === 0) {
                        $pendingIsPermanent = true;
                    } else {
                        if (preg_match('/A\s*=\s*[\'"]?([^\'",\]]+)[\'"]?/i', $cleanLine, $m)) {
                            $pendingActive = trim($m[1]);
                        }
                        if (preg_match('/S\s*=\s*[\'"]?([^\'",\]]+)[\'"]?/i', $cleanLine, $m)) {
                            $pendingStorage = trim($m[1]);
                        }
                        if (empty($pendingActive) && empty($pendingStorage) && preg_match('/\[\s*[\'"]?([^\'",\]]+)[\'"]?\s*(?:,\s*[\'"]?([^\'",\]]+)[\'"]?)?\s*\]/', $cleanLine, $m)) {
                            $pendingActive = trim($m[1] ?? '');
                            $pendingStorage = trim($m[2] ?? '');
                        }
                    }
                    continue;
                }

                // Remarks line starting with $
                if ($hasPendingSeries && str_starts_with($line, '$')) {
                    $remText = ltrim($line, '$');
                    $remText = trim(rtrim(trim($remText), ';'));
                    if (!empty($remText)) {
                        $pendingRemarks = $pendingRemarks ? ($pendingRemarks . ' ' . $remText) : $remText;
                    }
                    continue;
                }

                // If title was missing on =, fill title first
                if ($hasPendingSeries && empty($pendingTitle)) {
                    $pendingTitle = trim(rtrim(trim($line), ';'));
                    continue;
                }

                // Append any trailing remarks text
                if ($hasPendingSeries) {
                    $remText = trim(rtrim(trim($line), ';'));
                    if (!empty($remText)) {
                        $pendingRemarks = $pendingRemarks ? ($pendingRemarks . ' ' . $remText) : $remText;
                    }
                }
            }

            $flushPending();

            DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                'changes'      => "Imported {$importedCount} Record Series entries from text file",
                'admin_id'     => auth()->id(),
                'what_system'  => 2,
                'when_changes' => now(),
            ]);

            $this->successMessage = "Successfully imported {$importedCount} record series entries from text file!";
            $this->closeImportModal();
            $this->resetPage();

        } catch (\Exception $e) {
            $this->errorMessage = 'Import failed: ' . $e->getMessage();
        }
    }

    public function toggleActive(int $seriesId): void
    {
        $this->clearMessages();

        $series = DB::table('rdp_record_series')->where('id', $seriesId)->first();
        if (!$series) return;

        $newStatus = !$series->is_active;
        DB::table('rdp_record_series')->where('id', $seriesId)->update([
            'is_active'  => $newStatus,
            'updated_at' => now(),
        ]);

        $statusText = $newStatus ? 'Activated' : 'Deactivated';

        DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'changes'      => "{$statusText} Record Series: \"{$series->series_title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$series->series_title}\" has been {$statusText}.";
    }

    public function deleteSeries(int $seriesId): void
    {
        $this->clearMessages();

        $series = DB::table('rdp_record_series')->where('id', $seriesId)->first();
        if (!$series) return;

        $usageCount = DB::table('rdp_record')->where('record_series_id', $seriesId)->count();
        if ($usageCount > 0) {
            $this->errorMessage = "Cannot delete \"{$series->series_title}\": it is referenced by {$usageCount} record(s). Deactivate it instead.";
            return;
        }

        $childCount = DB::table('rdp_record_series')->where('parent_id', $seriesId)->count();
        if ($childCount > 0) {
            $this->errorMessage = "Cannot delete \"{$series->series_title}\": it has {$childCount} child series. Remove them first.";
            return;
        }

        DB::table('rdp_record_series')->where('id', $seriesId)->delete();

        if ($series->retention_period) {
            $otherUsage = DB::table('rdp_record_series')
                ->where('retention_period', $series->retention_period)
                ->exists();
            if (!$otherUsage) {
                DB::table('rdp_retention_period')->where('id', $series->retention_period)->delete();
            }
        }

        DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
            'changes'      => "Deleted Record Series: \"{$series->series_title}\"",
            'admin_id'     => auth()->id(),
            'what_system'  => 2,
            'when_changes' => now(),
        ]);

        $this->successMessage = "Record Series \"{$series->series_title}\" has been deleted.";
    }

    public function with(): array
    {
        $seriesTypes = DB::table('rdp_record_series_type')
            ->where('is_active', true)
            ->orderBy('id', 'asc')
            ->get();

        $offices = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')
            ->where('is_active', true)
            ->orderBy('office_name', 'asc')
            ->get();

        $bracketSuggestions = DB::table('rdp_record_series_brackets')
            ->select('bracket_name')
            ->where('is_active', true)
            ->when(!empty(trim($this->bracketInput)), fn($q) => $q->where('bracket_name', 'ilike', '%' . trim($this->bracketInput) . '%'))
            ->distinct()
            ->orderBy('bracket_name', 'asc')
            ->limit(8)
            ->get();

        $editBracketSuggestions = DB::table('rdp_record_series_brackets')
            ->select('bracket_name')
            ->where('is_active', true)
            ->when(!empty(trim($this->editBracketInput)), fn($q) => $q->where('bracket_name', 'ilike', '%' . trim($this->editBracketInput) . '%'))
            ->distinct()
            ->orderBy('bracket_name', 'asc')
            ->limit(8)
            ->get();

        $termAdmin = strtolower(trim($this->series_title));

        $parentSuggestionsQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_record_series_type', 'rdp_record_series.series_type', '=', 'rdp_record_series_type.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->select([
                'rdp_record_series.id',
                'rdp_record_series.series_title',
                'rdp_record_series.recorded_at_office',
                'rdp_record_series_type.shorted_type',
                'office.office_name as recorded_office_name',
            ])
            ->whereNull('rdp_record_series.parent_id');

        if (!empty($termAdmin)) {
            $searchTerm = '%' . $termAdmin . '%';
            $allMatching = $parentSuggestionsQuery->where('rdp_record_series.series_title', 'ilike', $searchTerm)
                ->limit(50)
                ->get();

            $sorted = $allMatching->sort(function ($a, $b) use ($termAdmin) {
                $titleA = strtolower($a->series_title ?? '');
                $titleB = strtolower($b->series_title ?? '');

                $getPriority = function ($title) use ($termAdmin) {
                    if ($title === $termAdmin) return 1;
                    if (str_starts_with($title, $termAdmin)) return 2;
                    if (str_contains($title, ' ' . $termAdmin)) return 3;
                    return 4;
                };

                $pA = $getPriority($titleA);
                $pB = $getPriority($titleB);

                if ($pA !== $pB) {
                    return $pA <=> $pB;
                }

                return strcmp($titleA, $titleB);
            })->values();

            $parentSuggestions = $sorted->slice(0, 8);
        } else {
            $parentSuggestions = $parentSuggestionsQuery->orderBy('rdp_record_series.series_title', 'asc')->limit(8)->get();
        }

        $baseQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
            ->leftJoin('rdp_record_series_brackets', 'rdp_record_series.bracket_id', '=', 'rdp_record_series_brackets.id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
            ->where('rdp_record_series.is_verified', true)
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'parent.series_title as parent_title',
                'rdp_record_series_brackets.bracket_name',
                'office.office_name as recorded_office_name',
            ]);

        $seriesTypeId = is_numeric($this->activeTab) ? (int)$this->activeTab : null;

        if ($this->activeTab === 'unregistered') {
            $baseQuery->whereNull('rdp_record_series.series_type');
        } elseif ($seriesTypeId !== null) {
            $baseQuery->where('rdp_record_series.series_type', $seriesTypeId);
        }

        if (!empty(trim($this->search))) {
            $searchTerm = '%' . trim($this->search) . '%';

            $matchedQuery = DB::table('rdp_record_series')
                ->leftJoin('rdp_record_series_brackets', 'rdp_record_series.bracket_id', '=', 'rdp_record_series_brackets.id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'rdp_record_series.recorded_at_office', '=', 'office.office_code')
                ->where('rdp_record_series.is_verified', true);

            if ($this->activeTab === 'unregistered') {
                $matchedQuery->whereNull('rdp_record_series.series_type');
            } elseif ($seriesTypeId !== null) {
                $matchedQuery->where('rdp_record_series.series_type', $seriesTypeId);
            }

            $matchedIds = $matchedQuery->where(function ($q) use ($searchTerm) {
                $q->where('rdp_record_series.series_title', 'ilike', $searchTerm)
                  ->orWhere('rdp_record_series.remarks', 'ilike', $searchTerm)
                  ->orWhere('rdp_record_series_brackets.bracket_name', 'ilike', $searchTerm)
                  ->orWhere('office.office_name', 'ilike', $searchTerm)
                  ->orWhere('rdp_record_series.recorded_at_office', 'ilike', $searchTerm)
                  ->orWhere(DB::raw("CAST(rdp_record_series.item_number AS TEXT)"), 'ilike', $searchTerm);
            })->pluck('rdp_record_series.id')->toArray();

            $allTypeSeries = DB::table('rdp_record_series')
                ->where('is_verified', true)
                ->when($this->activeTab === 'unregistered', fn($q) => $q->whereNull('series_type'))
                ->when($seriesTypeId !== null, fn($q) => $q->where('series_type', $seriesTypeId))
                ->get();

            $includedIds = [];
            foreach ($matchedIds as $mId) {
                $includedIds[$mId] = true;

                $collectChildren = function($pId) use (&$collectChildren, &$includedIds, $allTypeSeries) {
                    foreach ($allTypeSeries as $s) {
                        if ($s->parent_id == $pId) {
                            $includedIds[$s->id] = true;
                            $collectChildren($s->id);
                        }
                    }
                };
                $collectChildren($mId);

                $currId = $mId;
                while ($currId) {
                    $item = $allTypeSeries->firstWhere('id', $currId);
                    if ($item && $item->parent_id) {
                        $includedIds[$item->parent_id] = true;
                        $currId = $item->parent_id;
                    } else {
                        break;
                    }
                }
            }

            $baseQuery->whereIn('rdp_record_series.id', array_keys($includedIds));
        }

        if ($this->statusFilter !== '') {
            $baseQuery->where('rdp_record_series.is_active', $this->statusFilter === '1');
        }

        $allFetched = $baseQuery->orderByRaw('
            rdp_record_series_brackets.bracket_name ASC NULLS LAST,
            office.office_name ASC NULLS LAST,
            rdp_record_series.item_number ASC NULLS LAST,
            rdp_record_series.series_title ASC
        ')->get();

        $allFetchedMap = [];
        foreach ($allFetched as $item) {
            $allFetchedMap[$item->id] = $item;
        }

        $treeOrdered = $this->buildGroupedTreeHierarchy($allFetched->all());

        foreach ($treeOrdered as $item) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $item);
            $item->effective_active = $eff->active_period;
            $item->effective_storage = $eff->storage_period;
            $item->effective_total = $eff->total_period;
            $item->effective_is_permanent = $eff->is_retention_period_permanent;
            $item->is_inherited = $eff->inherited;
        }

        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage() ?: 1;
        $perPage = 25;
        $paginatedItems = array_slice($treeOrdered, ($page - 1) * $perPage, $perPage);
        $paginatedRecords = new \Illuminate\Pagination\LengthAwarePaginator(
            $paginatedItems,
            count($treeOrdered),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
        );

        return [
            'records'                 => $paginatedRecords,
            'bracketSuggestions'     => $bracketSuggestions,
            'editBracketSuggestions' => $editBracketSuggestions,
            'parentSuggestions'       => $parentSuggestions,
            'seriesTypes'             => $seriesTypes,
            'offices'                 => $offices,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/record-series.css'])
@endpush

<div class="ars-container">
    <!-- Alert Notifications -->
    @if($successMessage)
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-weight: 700; font-size: 13.5px; display: flex; align-items: center; justify-content: space-between;">
            <span>✅ {{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer; font-weight: 800; font-size: 16px;">✕</button>
        </div>
    @endif
    @if($errorMessage)
        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-weight: 700; font-size: 13.5px; display: flex; align-items: center; justify-content: space-between;">
            <span>❌ {{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #dc2626; cursor: pointer; font-weight: 800; font-size: 16px;">✕</button>
        </div>
    @endif

    <!-- Dynamic Record Series Type Tabs Header -->
    <div class="tabs-header">
        <!-- Unregistered Tab -->
        <button type="button" class="tab-btn {{ $activeTab === 'unregistered' ? 'active' : '' }}" wire:click="selectTab('unregistered')">
            Unregistered
        </button>

        <!-- Dynamic Record Series Types -->
        @foreach($seriesTypes as $sType)
            <button type="button" class="tab-btn {{ $activeTab === (string)$sType->id ? 'active' : '' }}" wire:click="selectTab('{{ $sType->id }}')">
                {{ $sType->type_name }}
                @if(!empty($sType->shorted_type))
                    <span style="font-size: 11px; font-weight: 800; opacity: 0.75; margin-left: 2px;">({{ $sType->shorted_type }})</span>
                @endif
            </button>
        @endforeach

        <!-- Add Type Plus Button Tab -->
        <button type="button" class="tab-btn-add" wire:click="openAddTypeModal" title="Register New Record Series Type">
            +
        </button>
    </div>

    <!-- Add Record Series Popout Modal -->
    @if($showAddForm)
        <div class="ars-modal-overlay">
            <div class="ars-modal-card" style="width: 650px;">
                <div class="ars-modal-header">
                    <h3>➕ Add New Record Series</h3>
                    <button type="button" wire:click="toggleAddForm" class="ars-modal-close">&times;</button>
                </div>
                <div class="ars-modal-body">
                    <!-- Bracket Autocomplete Input -->
                    <div class="ars-form-row" style="position: relative;" wire:click.outside="$set('showBracketDropdown', false)">
                        <span class="ars-label">Bracket:</span>
                        <div style="flex: 1; position: relative;">
                            <input type="text"
                                   class="ars-input"
                                   wire:model.live="bracketInput"
                                   wire:focus="$set('showBracketDropdown', true)"
                                   placeholder="Type bracket name (e.g. OFFICE OF THE PRESIDENT)..."
                                   style="width: 100%; font-weight: 700;">

                            @if($showBracketDropdown && count($bracketSuggestions) > 0)
                                <ul class="ars-suggestions-list">
                                    @foreach($bracketSuggestions as $b)
                                        <li class="ars-suggestion-item" wire:click="selectBracketSuggestion('{{ addslashes($b->bracket_name) }}')">
                                            {{ $b->bracket_name }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    <!-- Recorded Office Selection -->
                    <div class="ars-form-row">
                        <span class="ars-label">Recorded Office:</span>
                        <select class="ars-input" wire:model="newOfficeCode" style="font-weight: 600;">
                            <option value="">No Office Section (Global / Unassigned)</option>
                            @foreach($offices as $off)
                                <option value="{{ $off->office_code }}">{{ $off->office_code }} — {{ $off->office_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Item Number -->
                    <div class="ars-form-row">
                        <span class="ars-label">Item Number:</span>
                        <input type="number" class="ars-input" wire:model="newItemNumber" placeholder="E.G. 1, 2, 100" style="max-width: 160px; font-weight: 700;">
                    </div>

                    <!-- Record Series Title (with Autocomplete Dropdown matching records-and-disposition-schedule) -->
                    <div class="ars-form-row" style="position: relative;" wire:click.outside="$set('showParentDropdown', false)">
                        <span class="ars-label">Series Title *:</span>
                        <div style="flex: 1; position: relative;">
                            <input type="text"
                                   class="ars-input"
                                   wire:model.live.debounce.150ms="series_title"
                                   wire:focus="$set('showParentDropdown', true)"
                                   placeholder="E.G. GATE PASSES, RECEIPTS, FINANCIAL STATEMENTS"
                                   style="width: 100%; font-weight: 700;">

                            @if($showParentDropdown && count($parentSuggestions) > 0)
                                <ul class="ars-suggestions-list">
                                    @foreach($parentSuggestions as $ps)
                                        <li class="ars-suggestion-item" wire:click="selectParentSuggestion('{{ addslashes($ps->series_title) }}')" style="display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                @if(!empty($ps->shorted_type))
                                                    <span style="font-size: 10px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; letter-spacing: 0.5px; text-transform: uppercase;">{{ $ps->shorted_type }}</span>
                                                @endif
                                                <span>{{ $ps->series_title }}</span>
                                            </div>
                                            @if(!empty($ps->recorded_at_office))
                                                <span style="font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; border: 1px solid #cbd5e1; padding: 1px 5px; border-radius: 4px; text-transform: uppercase;">
                                                    {{ $ps->recorded_office_name ?? $ps->recorded_at_office }}
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    <!-- Dynamic Subsections (Matching records-and-disposition-schedule) -->
                    @foreach($subsections as $index => $subVal)
                        <div class="ars-form-row" style="padding-left: {{ ($index + 1) * 20 }}px;">
                            <span class="ars-label" style="font-size: 12px; color: var(--ars-blue-800);">
                                └─ Sub {{ $index + 1 }}:
                            </span>
                            <div style="flex: 1; display: flex; gap: 8px;">
                                <input type="text"
                                       class="ars-input"
                                       wire:model="subsections.{{ $index }}"
                                       placeholder="E.G. VISITOR LOGS, DELEGATE BADGES..."
                                       style="flex: 1; font-weight: 600;">

                                <button type="button" wire:click="removeSubsection({{ $index }})" class="ars-btn ars-btn-danger" style="padding: 6px 12px;" title="Remove Subsection">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    @endforeach

                    <!-- Add Subsection Button -->
                    <div style="margin: 6px 0 16px 180px;">
                        <button type="button" wire:click="addSubsection" class="ars-btn ars-btn-secondary" style="font-size: 12px; padding: 6px 14px; border-style: dashed; border-color: var(--ars-blue-500); color: var(--ars-blue-600);">
                            + Add Subsection (Child)
                        </button>
                    </div>

                    <!-- Permanent Retention Option -->
                    <div class="ars-form-row">
                        <span class="ars-label">Permanent Record:</span>
                        <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 700; color: var(--ars-blue-800); cursor: pointer;">
                                <input type="checkbox" wire:model.live="newIsPermanent" style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--ars-blue-600);">
                                Permanent Record Series
                            </label>
                            <span style="font-size: 12px; color: var(--ars-slate-500);">(Disables period inputs and sets retention to Permanent)</span>
                        </div>
                    </div>

                    <!-- Active Period -->
                    <div class="ars-form-row" style="{{ $newIsPermanent ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
                        <span class="ars-label">Active Period:</span>
                        <input type="text" class="ars-input" wire:model.live.debounce.200ms="newActivePeriod" placeholder="E.G. 6 MONTHS, 1 YEAR" {{ $newIsPermanent ? 'disabled' : '' }}>
                    </div>

                    <!-- Storage Period -->
                    <div class="ars-form-row" style="{{ $newIsPermanent ? 'opacity: 0.4; pointer-events: none; user-select: none; transition: all 0.2s;' : 'transition: all 0.2s;' }}">
                        <span class="ars-label">Storage Period:</span>
                        <input type="text" class="ars-input" wire:model.live.debounce.200ms="newStoragePeriod" placeholder="E.G. 1 YEAR, 4 YEARS" {{ $newIsPermanent ? 'disabled' : '' }}>
                    </div>

                    <!-- Total Period -->
                    <div class="ars-form-row" style="{{ $newIsPermanent ? 'opacity: 0.4; pointer-events: none; user-select: none;' : '' }}">
                        <span class="ars-label">Total Period:</span>
                        <div style="flex: 1; padding: 10px 14px; background: var(--ars-slate-50); border: 1px solid var(--ars-slate-300); border-radius: 8px; font-weight: 700; font-size: 13.5px; color: var(--ars-blue-800);">
                            {{ $this->computeTotalPeriod($newActivePeriod, $newStoragePeriod, $newIsPermanent) ?: '— (Auto-calculated from Active & Storage)' }}
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="ars-form-row" style="align-items: flex-start;">
                        <span class="ars-label" style="margin-top: 10px;">Remarks:</span>
                        <input type="text" class="ars-input" wire:model="newRemarks" placeholder="E.G. DISPOSE AFTER RETENTION, AUDIT REQUIRED">
                    </div>
                </div>
                <div class="ars-modal-footer">
                    <button type="button" wire:click="toggleAddForm" class="ars-btn ars-btn-secondary">Cancel</button>
                    <button type="button" wire:click="addSeries" class="ars-btn ars-btn-primary">Save Record Series</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Data Table Card with Alpine.js Collapsible State -->
    <div class="ars-table-card" x-data="{ 
        collapsedBrackets: {}, 
        collapsedOffices: {}, 
        search: @entangle('search').live,
        toggleBracket(id) { 
            this.collapsedBrackets[id] = !this.collapsedBrackets[id]; 
        }, 
        toggleOffice(id) { 
            this.collapsedOffices[id] = !this.collapsedOffices[id]; 
        },
        isBracketCollapsed(bId) { 
            return !this.search && !!this.collapsedBrackets[bId]; 
        },
        isRowCollapsed(bId, oId) { 
            return !this.search && (!!this.collapsedBrackets[bId] || !!this.collapsedOffices[oId]); 
        }
    }">
        <div class="ars-filter-bar">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; flex: 1; align-items: center;">
                <input type="text" class="ars-input" wire:model.live.debounce.300ms="search" placeholder="Search title, remarks, bracket, office..." style="max-width: 320px;">

                <select class="ars-input" wire:model.live="statusFilter" style="max-width: 160px;">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>

                @if($search || $statusFilter !== '')
                    <button type="button" wire:click="clearFilters" class="ars-btn ars-btn-secondary">
                        Reset Filters
                    </button>
                @endif
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="button" wire:click="openImportModal" class="ars-btn ars-btn-import">
                    Import File
                </button>
                <button type="button" wire:click="toggleAddForm" class="ars-btn {{ $showAddForm ? 'ars-btn-danger' : 'ars-btn-primary' }}">
                    @if($showAddForm)
                        <span>✕</span> Cancel
                    @else
                        <span>+</span> Add Record Series
                    @endif
                </button>
            </div>
        </div>

        <div style="overflow-x: auto; width: 100%;">
            <table class="ars-table" style="min-width: 1050px; border: 1.5px solid #cbd5e1;">
                <thead>
                    <tr style="background: #f1f5f9;">
                        <th rowspan="2" style="width: 80px; text-align: center; border: 1px solid #cbd5e1;">ITEM NO.</th>
                        <th rowspan="2" style="text-align: center; width: 34%; border: 1px solid #cbd5e1;">RECORD SERIES TITLE & DESCRIPTION</th>
                        <th colspan="3" style="text-align: center; border: 1px solid #cbd5e1; width: 225px;">RETENTION PERIOD</th>
                        <th rowspan="2" style="text-align: center; width: 26%; border: 1px solid #cbd5e1;">REMARKS</th>
                        <th rowspan="2" style="text-align: center; width: 200px; border: 1px solid #cbd5e1;">ACTIONS</th>
                    </tr>
                    <tr style="background: #f1f5f9;">
                        <th style="text-align: center; width: 75px; border: 1px solid #cbd5e1;">ACTIVE</th>
                        <th style="text-align: center; width: 75px; border: 1px solid #cbd5e1;">STORAGE</th>
                        <th style="text-align: center; width: 75px; border: 1px solid #cbd5e1;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $index => $record)
                        @php
                            $prevRecord = $index > 0 ? $records[$index - 1] : null;
                            $bracketChanged = !$prevRecord || ($prevRecord->bracket_id !== $record->bracket_id);
                            $officeChanged = $bracketChanged || ($prevRecord->recorded_at_office !== $record->recorded_at_office);
                            $bKey = 'b_' . ($record->bracket_id ?? 'none');
                            $oKey = 'o_' . ($record->bracket_id ?? 'none') . '_' . ($record->recorded_at_office ?? 'none');
                        @endphp

                        @if($bracketChanged && !empty($record->bracket_name))
                            <!-- Bracket Section Header Banner (Collapsible) -->
                            <tr x-on:click="toggleBracket('{{ $bKey }}')" style="background: #cbd5e1; font-weight: 800; font-size: 13.5px; text-align: center; color: #0f172a; letter-spacing: 0.8px; cursor: pointer; user-select: none;">
                                <td colspan="7" style="padding: 10px 16px; border: 1.5px solid #94a3b8; text-transform: uppercase;">
                                    <span x-text="isBracketCollapsed('{{ $bKey }}') ? '►' : '▼'" style="font-size: 11px; margin-right: 6px; color: #475569;"></span>
                                    {{ $record->bracket_name }}
                                </td>
                            </tr>
                        @endif

                        @if($officeChanged && !empty($record->recorded_at_office))
                            <!-- Office / Section Header Banner (Collapsible) -->
                            <tr x-show="!isBracketCollapsed('{{ $bKey }}')" x-on:click="toggleOffice('{{ $oKey }}')" style="background: #e2e8f0; font-weight: 700; font-size: 12.5px; color: #1e293b; letter-spacing: 0.5px; cursor: pointer; user-select: none;">
                                <td colspan="7" style="padding: 7px 20px; border: 1px solid #cbd5e1; text-align: left; padding-left: 45px; text-transform: uppercase;">
                                    <span x-text="collapsedOffices['{{ $oKey }}'] ? '►' : '▼'" style="font-size: 10px; margin-right: 6px; color: #64748b;"></span>
                                    {{ $record->recorded_office_name ?? $record->recorded_at_office }}
                                </td>
                            </tr>
                        @endif

                        @if($editingId === $record->id)
                            <!-- Inline Edit Row -->
                            <tr x-show="!isRowCollapsed('{{ $bKey }}', '{{ $oKey }}')" style="background: #fffbeb;">
                                <td style="text-align: center; border: 1px solid #cbd5e1;">
                                    <input type="number" class="ars-input" wire:model="editItemNumber" placeholder="No." style="width: 70px; text-align: center; font-weight: 700;">
                                </td>
                                <td style="text-align: center; position: relative; border: 1px solid #cbd5e1;">
                                    <input type="text" class="ars-input" wire:model="editSeriesTitle" style="font-weight: 700; margin-bottom: 4px; text-align: center;">

                                    <!-- Edit Bracket Autocomplete -->
                                    <div style="position: relative; margin-bottom: 4px;" wire:click.outside="$set('showEditBracketDropdown', false)">
                                        <input type="text"
                                               class="ars-input"
                                               wire:model.live="editBracketInput"
                                               wire:focus="$set('showEditBracketDropdown', true)"
                                               placeholder="Type bracket name..."
                                               style="font-size: 11.5px; text-align: center;">

                                        @if($showEditBracketDropdown && count($editBracketSuggestions) > 0)
                                            <ul class="ars-suggestions-list">
                                                @foreach($editBracketSuggestions as $b)
                                                    <li class="ars-suggestion-item" wire:click="selectEditBracketSuggestion('{{ addslashes($b->bracket_name) }}')">
                                                        {{ $b->bracket_name }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>

                                    <!-- Edit Office Select -->
                                    <div>
                                        <select class="ars-input" wire:model="editOfficeCode" style="font-size: 11.5px; text-align: center;">
                                            <option value="">No Office</option>
                                            @foreach($offices as $off)
                                                <option value="{{ $off->office_code }}">{{ $off->office_code }} ({{ $off->office_name }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </td>
                                <td style="border: 1px solid #cbd5e1;">
                                    <label style="display: flex; align-items: center; justify-content: center; gap: 4px; font-size: 11px; font-weight: 700; color: var(--ars-blue-800); margin-bottom: 4px;">
                                        <input type="checkbox" wire:model.live="editIsPermanent" style="width: 14px; height: 14px; accent-color: var(--ars-blue-600);"> Permanent
                                    </label>
                                    <input type="text" class="ars-input" wire:model.live.debounce.200ms="editActivePeriod" placeholder="Active" {{ $editIsPermanent ? 'disabled style=opacity:0.4;' : '' }} style="text-align: center;">
                                </td>
                                <td style="border: 1px solid #cbd5e1;">
                                    <input type="text" class="ars-input" wire:model.live.debounce.200ms="editStoragePeriod" placeholder="Storage" {{ $editIsPermanent ? 'disabled style=opacity:0.4;' : '' }} style="text-align: center;">
                                </td>
                                <td style="font-weight: 700; color: var(--ars-blue-800); text-align: center; border: 1px solid #cbd5e1;">
                                    {{ $this->computeTotalPeriod($editActivePeriod, $editStoragePeriod, $editIsPermanent) ?: '—' }}
                                </td>
                                <td style="text-align: center; border: 1px solid #cbd5e1;">
                                    <input type="text" class="ars-input" wire:model="editRemarks" placeholder="Enter remarks..." style="width: 100%; text-align: center;">
                                </td>
                                <td style="text-align: center; white-space: nowrap; width: 200px; border: 1px solid #cbd5e1;">
                                    <div style="margin-bottom: 6px;">
                                        <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; cursor: pointer;">
                                            <input type="checkbox" wire:model="editIsActive" style="width: 14px; height: 14px;"> Active
                                        </label>
                                    </div>
                                    <button type="button" wire:click="updateSeries" class="ars-btn ars-btn-primary" style="padding: 5px 10px; font-size: 12px; margin-right: 2px;">Save</button>
                                    <button type="button" wire:click="cancelEdit" class="ars-btn ars-btn-secondary" style="padding: 5px 10px; font-size: 12px;">Cancel</button>
                                </td>
                            </tr>
                        @else
                            <!-- Display Row -->
                            <tr x-show="!isRowCollapsed('{{ $bKey }}', '{{ $oKey }}')">
                                <td style="text-align: center; font-weight: 800; color: var(--ars-blue-800); border: 1px solid #cbd5e1;">
                                    @if(($record->depth ?? 0) === 0)
                                        {{ $record->item_number ?? '—' }}
                                    @else
                                        {{ $record->item_number ?? '' }}
                                    @endif
                                </td>
                                <td style="text-align: left; padding-left: {{ (($record->depth ?? 0) * 28) + 16 }}px; border: 1px solid #cbd5e1;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                        <div>
                                            @if(($record->depth ?? 0) > 0)
                                                <span style="color: var(--ars-blue-600); font-weight: 800; font-family: monospace; margin-right: 6px;">└─</span>
                                            @endif
                                            <span style="{{ ($record->depth ?? 0) === 0 ? 'font-weight: 800; font-size: 13.5px; color: #0f172a;' : (($record->depth ?? 0) === 1 ? 'font-weight: 700; font-size: 13px; color: #1e293b;' : 'font-weight: 600; font-size: 12.5px; color: #334155;') }}">
                                                {{ $record->series_title ?? '—' }}
                                            </span>
                                        </div>
                                        <div>
                                            @if($record->is_active)
                                                <span class="ars-badge ars-badge-active" style="font-size: 10px; padding: 2px 6px;">Active</span>
                                            @else
                                                <span class="ars-badge ars-badge-inactive" style="font-size: 10px; padding: 2px 6px;">Inactive</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                @php
                                    $isPermSeries = (bool)($record->effective_is_permanent) || 
                                                    (strtolower(trim($record->effective_total ?? '')) === 'permanent') ||
                                                    (strtolower(trim($record->effective_active ?? '')) === 'permanent' && strtolower(trim($record->effective_storage ?? '')) === 'permanent');
                                @endphp

                                @if($isPermSeries)
                                    <td colspan="3" style="text-align: center; font-weight: 800; letter-spacing: 3px; color: #1e3a8a; background: #eff6ff; font-size: 12.5px; border: 1px solid #cbd5e1;">
                                        P E R M A N E N T
                                        @if(!empty($record->is_inherited))
                                            <span style="font-size: 10px; font-weight: 600; opacity: 0.75; letter-spacing: normal; margin-left: 4px;">(Inherited)</span>
                                        @endif
                                    </td>
                                @else
                                    <td style="color: var(--ars-slate-800); font-size: 13px; text-align: center; border: 1px solid #cbd5e1;">
                                        {{ $record->effective_active ?? '' }}
                                    </td>
                                    <td style="color: var(--ars-slate-800); font-size: 13px; text-align: center; border: 1px solid #cbd5e1;">
                                        {{ $record->effective_storage ?? '' }}
                                    </td>
                                    <td style="color: var(--ars-slate-800); font-size: 13px; text-align: center; border: 1px solid #cbd5e1;">
                                        {{ $record->effective_total ?? '' }}
                                        @if(!empty($record->is_inherited) && (!empty($record->effective_active) || !empty($record->effective_storage)))
                                            <span style="font-size: 10px; font-weight: 600; color: var(--ars-slate-500); margin-left: 4px;">(Inherited)</span>
                                        @endif
                                    </td>
                                @endif

                                <td style="color: var(--ars-slate-700); font-size: 13px; text-align: left; padding-left: 12px; word-break: break-word; border: 1px solid #cbd5e1;">
                                    {{ $record->remarks ?? '—' }}
                                </td>
                                <td style="text-align: center; white-space: nowrap; width: 200px; border: 1px solid #cbd5e1;">
                                    <button type="button" wire:click="startEdit({{ $record->id }})" class="ars-btn ars-btn-secondary" style="padding: 5px 10px; font-size: 12px; margin-right: 2px;">Edit</button>
                                    <button type="button" wire:click="toggleActive({{ $record->id }})" class="ars-btn ars-btn-secondary" style="padding: 5px 10px; font-size: 12px; margin-right: 2px; color: {{ $record->is_active ? '#ea580c' : '#16a34a' }};">
                                        {{ $record->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    <button type="button" wire:click="deleteSeries({{ $record->id }})" wire:confirm="Are you sure you want to delete '{{ $record->series_title }}'?" class="ars-btn ars-btn-danger" style="padding: 5px 10px; font-size: 12px;">Delete</button>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" style="padding: 32px; text-align: center; color: var(--ars-slate-500);">
                                No record series found in this category matching criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Custom Styled Pagination Bar -->
        @if($records->hasPages())
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: var(--ars-white); border: 1px solid var(--ars-slate-200); border-top: none; border-radius: 0 0 12px 12px; margin-top: 0; flex-wrap: wrap; gap: 12px;">
                <div style="font-size: 13px; font-weight: 600; color: var(--ars-slate-600);">
                    Showing <span style="font-weight: 800; color: var(--ars-slate-900);">{{ $records->firstItem() ?? 0 }}</span> to <span style="font-weight: 800; color: var(--ars-slate-900);">{{ $records->lastItem() ?? 0 }}</span> of <span style="font-weight: 800; color: var(--ars-slate-900);">{{ $records->total() }}</span> entries
                </div>
                <div style="display: flex; gap: 4px; align-items: center;">
                    @if($records->onFirstPage())
                        <button disabled class="ars-btn ars-btn-secondary" style="opacity: 0.4; cursor: not-allowed; padding: 5px 12px; font-size: 12px;">« Previous</button>
                    @else
                        <button wire:click="previousPage" class="ars-btn ars-btn-secondary" style="padding: 5px 12px; font-size: 12px;">« Previous</button>
                    @endif

                    @foreach($records->getUrlRange(1, $records->lastPage()) as $pageNumber => $url)
                        <button wire:click="gotoPage({{ $pageNumber }})" class="ars-btn {{ $pageNumber === $records->currentPage() ? 'ars-btn-primary' : 'ars-btn-secondary' }}" style="padding: 5px 10px; font-size: 12px; min-width: 32px;">
                            {{ $pageNumber }}
                        </button>
                    @endforeach

                    @if($records->hasMorePages())
                        <button wire:click="nextPage" class="ars-btn ars-btn-secondary" style="padding: 5px 12px; font-size: 12px;">Next »</button>
                    @else
                        <button disabled class="ars-btn ars-btn-secondary" style="opacity: 0.4; cursor: not-allowed; padding: 5px 12px; font-size: 12px;">Next »</button>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <!-- Register New Record Series Type Modal (+) -->
    @if($showAddTypeModal)
        <div class="ars-modal-overlay">
            <div class="ars-modal-card">
                <div class="ars-modal-header">
                    <h3>➕ Register New Record Series Type</h3>
                    <button type="button" wire:click="closeAddTypeModal" class="ars-modal-close">&times;</button>
                </div>
                <div class="ars-modal-body">
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: var(--ars-slate-700); margin-bottom: 6px;">
                            Record Series Type Name *:
                        </label>
                        <input type="text" class="ars-input" wire:model="newTypeName" placeholder="e.g. General Records Disposition Schedule" style="width: 100%;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; color: var(--ars-slate-700); margin-bottom: 6px;">
                            Short Code / Abbreviation:
                        </label>
                        <input type="text" class="ars-input" wire:model="newTypeShortCode" placeholder="e.g. GRDS (Auto-generated if blank)" style="width: 100%;">
                    </div>
                </div>
                <div class="ars-modal-footer">
                    <button type="button" wire:click="closeAddTypeModal" class="ars-btn ars-btn-secondary">Cancel</button>
                    <button type="button" wire:click="saveRecordType" class="ars-btn ars-btn-primary">Save Record Type</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Import Record Series File Modal -->
    @if($showImportModal)
        <div class="ars-modal-overlay">
            <div class="ars-modal-card" style="width: 650px;">
                <div class="ars-modal-header">
                    <h3>📄 Import Record Series File</h3>
                    <button type="button" wire:click="closeImportModal" class="ars-modal-close">&times;</button>
                </div>
                <div class="ars-modal-body">
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: var(--ars-slate-700); margin-bottom: 6px;">
                            Select Text File (.txt) *:
                        </label>
                        <input type="file" wire:model="importFile" accept=".txt" class="ars-input" style="width: 100%;">
                        @error('importFile')
                            <span style="color: #dc2626; font-size: 12px; font-weight: 700; margin-top: 4px; display: block;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; font-size: 12px; color: #334155; line-height: 1.5;">
                        <strong style="color: #0f172a; display: block; margin-bottom: 6px;">Supported Block Syntax Cheat Sheet:</strong>
                        <pre style="margin: 0; font-family: monospace; font-size: 11px; background: #0f172a; color: #38bdf8; padding: 10px 12px; border-radius: 6px; overflow-x: auto;">
[ MAIN BRACKET ] {
= 001;
GATE PASSES;
[A='1 Year', S='2 Years'];

( OFFICE OF THE PRESIDENT ) {
 = 002;
 EXECUTIVE DIRECTIVES;
 [A='2 Years', S='3 Years'];

 - Special Orders;
 [A='1 Year'];
};

= 003;
RECEIPTS;
};
                        </pre>
                        <ul style="margin: 8px 0 0 16px; padding: 0;">
                            <li><code>[ BRACKET NAME ] { ... };</code> - Optional Bracket container block.</li>
                            <li><code>( OFFICE SECTION ) { ... };</code> - Optional Office section block (can be used with or without Brackets).</li>
                            <li><code>= ItemNo; Title;</code> - Root record series.</li>
                            <li><code>- Title;</code> / <code>-- Title;</code> - Nested subsections.</li>
                            <li><code>[A='...', S='...']</code> or <code>P;</code> / <code>Permanent;</code> - Retention periods.</li>
                            <li><code>$ Remarks</code> - Series remarks description.</li>
                        </ul>
                    </div>
                </div>
                <div class="ars-modal-footer">
                    <button type="button" wire:click="closeImportModal" class="ars-btn ars-btn-secondary">Cancel</button>
                    <button type="button" wire:click="importRecordSeries" class="ars-btn ars-btn-primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>Import File</span>
                        <span wire:loading>Importing...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

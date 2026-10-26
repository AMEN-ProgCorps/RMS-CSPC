<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Generalized Records')] class extends Component {
    public string $search = '';
    public string $officeFilter = '';
    public string $retentionFilter = 'all'; // 'all', 'permanent', 'temporary'
    public string $statusFilter = 'all';    // 'all', 'active', 'revised', 'disposed', 'pending_rds', 'expired'
    public string $mediumFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    // Generate Form Modal Properties
    public bool $showGenerateModal = false;
    public string $selectedFormType = 'nap1'; // 'nap1', 'nap2', 'nap3'
    public string $formClusterName = '';
    public string $formOffice = '';
    public ?string $generateSuccessMessage = null;
    public ?string $generateErrorMessage = null;
    public ?int $lastGeneratedClusterId = null;

    // View / Edit Record Details & Action Date Modal Properties
    public bool $showRecordModal = false;
    public ?int $selectedRecordId = null;
    public ?object $selectedRecord = null;
    public ?string $editActionDate = '';
    public ?string $recordModalSuccess = null;
    public ?string $recordModalError = null;

    /**
     * Check if user has role clearance to access the Generalized Records report.
     */
    private function hasReportAccessClearance(?object $user, ?object $perms): bool
    {
        if (!$user || !$perms) return false;

        return (bool)($perms->is_sadm ?? false)
            || (bool)($perms->is_admin ?? false)
            || (int)($user->account_role ?? 0) === 1
            || (bool)($perms->can_access_rdp_admin ?? false)
            || (bool)($perms->can_rdp_generate_reports ?? true)
            || (bool)($perms->can_access_rdp ?? true);
    }

    /**
     * Check if user has role clearance to view across all offices (or select other offices).
     */
    private function hasAllOfficesClearance(?object $user, ?object $perms): bool
    {
        if (!$user || !$perms) return false;

        return (bool)($perms->is_sadm ?? false)
            || (bool)($perms->is_admin ?? false)
            || (int)($user->account_role ?? 0) === 1
            || (bool)($perms->can_access_rdp_admin ?? false)
            || (bool)($perms->rdp_view_all_files ?? false);
    }

    public function mount(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;

        // Access clearance check via role clearance
        if (!$this->hasReportAccessClearance($user, $perms)) {
            $this->redirectRoute('rdp');
            return;
        }

        $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;
        if (!$this->hasAllOfficesClearance($user, $perms) && $userOffice) {
            $this->officeFilter = $userOffice;
        }
    }

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->retentionFilter = 'all';
        $this->statusFilter = 'all';
        $this->mediumFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';

        $user = Auth::user();
        $perms = $user?->permissions;
        $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;
        if (!$this->hasAllOfficesClearance($user, $perms) && $userOffice) {
            $this->officeFilter = $userOffice;
        } else {
            $this->officeFilter = '';
        }
    }

    private function applySearchFilter($query, array $columns): void
    {
        $tokens = preg_split('/\s+/', trim($this->search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $like = '%' . addcslashes($token, '%_\\') . '%';

            $query->where(function ($q) use ($columns, $like) {
                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    if (stripos($column, 'CAST(') === 0) {
                        $q->{$method}(DB::raw($column), 'ilike', $like);
                    } else {
                        $q->{$method}($column, 'ilike', $like);
                    }
                }
            });
        }
    }

    private function resolveEffectiveRetention(array $allSeriesMap, object $record): object
    {
        $current = $record;
        $visited = [];

        while ($current) {
            $hasActive = !empty(trim((string)($current->active_period ?? '')));
            $hasStorage = !empty(trim((string)($current->storage_period ?? '')));
            $hasTotal = !empty(trim((string)($current->total_period ?? '')));
            $isPerm = (bool)($current->is_retention_period_permanent ?? false);

            if ($isPerm || $hasActive || $hasStorage || $hasTotal) {
                return (object)[
                    'active_period'                 => $current->active_period,
                    'storage_period'                => $current->storage_period,
                    'total_period'                  => $current->total_period,
                    'is_retention_period_permanent' => $isPerm,
                    'inherited'                     => $current->id !== $record->id,
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
            'active_period'                 => null,
            'storage_period'                => null,
            'total_period'                  => null,
            'is_retention_period_permanent' => false,
            'inherited'                     => false,
        ];
    }

    private function compileVolume(array $volumes): string
    {
        $vols = array_filter(array_map('trim', $volumes));
        if (empty($vols)) return '';

        $unitTotals = [];
        $unparsed = [];

        foreach ($vols as $v) {
            if (preg_match('/^([\d\.]+)\s*(.*)$/', $v, $matches)) {
                $num = (float)$matches[1];
                $unit = strtolower(trim($matches[2]));
                if ($unit === '') $unit = 'units';
                $unitTotals[$unit] = ($unitTotals[$unit] ?? 0) + $num;
            } else {
                $unparsed[] = $v;
            }
        }

        $parts = [];
        foreach ($unitTotals as $unit => $total) {
            $formattedNum = (fmod($total, 1) === 0.0) ? (int)$total : number_format($total, 2);
            $parts[] = $formattedNum . ' ' . $unit;
        }
        foreach (array_unique($unparsed) as $u) {
            $parts[] = $u;
        }

        return implode(', ', $parts);
    }

    public function exportCsv(): StreamedResponse
    {
        $data = $this->with();
        $hierarchyTree = $data['hierarchyTree'] ?? [];
        $filename = 'generalized_records_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($hierarchyTree) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Item No.',
                'Record Series / Title',
                'Classification Level',
                'Subject Description',
                'Period Covered',
                'Volume',
                'Medium',
                'Restrictions',
                'Location',
                'Active Period',
                'Storage Period',
                'Total Retention',
                'Disposition Provision',
                'Action Date',
                'Lifecycle Status',
                'Recorded Office',
            ]);

            foreach ($hierarchyTree as $root) {
                fputcsv($handle, [
                    $root->item_number ?: '—',
                    $root->series_title,
                    'Root Series',
                    $root->remarks ?: '—',
                    $root->compiled_period ?: '—',
                    $root->compiled_volume ?: '—',
                    $root->compiled_medium ?: '—',
                    $root->compiled_restriction ?: '—',
                    $root->compiled_location ?: '—',
                    $root->effective_active ?: '—',
                    $root->effective_storage ?: '—',
                    $root->effective_total ?: '—',
                    $root->remarks ?: '—',
                    '—',
                    $root->lifecycle_status,
                    $root->recorded_at_office ?: '—',
                ]);

                if (!empty($root->direct_records)) {
                    foreach ($root->direct_records as $rec) {
                        fputcsv($handle, [
                            $root->item_number ?: '—',
                            $root->series_title,
                            'Record Item',
                            $rec->description,
                            $rec->date_covered ?: '—',
                            $rec->volume ?: '—',
                            $rec->medium ?: '—',
                            $rec->restriction ?: '—',
                            $rec->location ?: '—',
                            $root->effective_active ?: '—',
                            $root->effective_storage ?: '—',
                            $root->effective_total ?: '—',
                            $root->remarks ?: '—',
                            $rec->formatted_action_date ?: '—',
                            $rec->status,
                            $root->recorded_at_office ?: '—',
                        ]);
                    }
                }

                if (!empty($root->children)) {
                    foreach ($root->children as $sub) {
                        fputcsv($handle, [
                            $sub->item_number ?: '—',
                            $sub->series_title,
                            'Sub-Series',
                            $sub->remarks ?: '—',
                            $sub->compiled_period ?: '—',
                            $sub->compiled_volume ?: '—',
                            $sub->compiled_medium ?: '—',
                            $sub->compiled_restriction ?: '—',
                            $sub->compiled_location ?: '—',
                            $sub->effective_active ?: '—',
                            $sub->effective_storage ?: '—',
                            $sub->effective_total ?: '—',
                            $sub->remarks ?: ($root->remarks ?: '—'),
                            '—',
                            $sub->lifecycle_status,
                            $sub->recorded_at_office ?: '—',
                        ]);

                        if (!empty($sub->records)) {
                            foreach ($sub->records as $sRec) {
                                fputcsv($handle, [
                                    $sub->item_number ?: '—',
                                    $sub->series_title,
                                    'Sub-Series Item',
                                    $sRec->description,
                                    $sRec->date_covered ?: '—',
                                    $sRec->volume ?: '—',
                                    $sRec->medium ?: '—',
                                    $sRec->restriction ?: '—',
                                    $sRec->location ?: '—',
                                    $sub->effective_active ?: '—',
                                    $sub->effective_storage ?: '—',
                                    $sub->effective_total ?: '—',
                                    $sub->remarks ?: ($root->remarks ?: '—'),
                                    $sRec->formatted_action_date ?: '—',
                                    $sRec->status,
                                    $sub->recorded_at_office ?: '—',
                                ]);
                            }
                        }
                    }
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function openGenerateFormModal(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        if (!$this->hasReportAccessClearance($user, $perms)) {
            $this->generateErrorMessage = 'You do not have clearance to generate RDP forms.';
            return;
        }

        $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;
        $this->formOffice = !empty($this->officeFilter) ? $this->officeFilter : ($userOffice ?? '');
        $this->selectedFormType = 'nap1';
        $this->updateDefaultFormClusterName();
        $this->generateSuccessMessage = null;
        $this->generateErrorMessage = null;
        $this->lastGeneratedClusterId = null;
        $this->showGenerateModal = true;
    }

    public function closeGenerateFormModal(): void
    {
        $this->showGenerateModal = false;
        $this->generateSuccessMessage = null;
        $this->generateErrorMessage = null;
    }

    public function openRecordModal(int $recordId): void
    {
        $rec = DB::table('rdp_record')
            ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_remarks_logic', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
            ->where('rdp_record.id', $recordId)
            ->select([
                'rdp_record.*',
                'rdp_period_covered.date_covered',
                'rdp_period_covered.date_covered_end',
                'rdp_period_covered.volume as period_volume',
                'rdp_record_series.series_title',
                'rdp_record_series.item_number as series_item_number',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
                'rdp_retention_period.is_permanent as retention_is_permanent',
                'rdp_remarks_logic.remarks_value as disposition_provision',
            ])
            ->first();

        if ($rec) {
            $this->selectedRecordId = $recordId;
            $this->selectedRecord = $rec;
            $this->editActionDate = !empty($rec->action_date)
                ? (string)$rec->action_date
                : (!empty($rec->transferred_to_nap3_at) ? Carbon::parse($rec->transferred_to_nap3_at)->format('Y-m-d') : '');
            $this->recordModalSuccess = null;
            $this->recordModalError = null;
            $this->showRecordModal = true;
        }
    }

    public function closeRecordModal(): void
    {
        $this->showRecordModal = false;
        $this->selectedRecordId = null;
        $this->selectedRecord = null;
        $this->editActionDate = '';
        $this->recordModalSuccess = null;
        $this->recordModalError = null;
    }

    public function saveRecordActionDate(): void
    {
        if (!$this->selectedRecordId) return;

        try {
            $val = trim((string)$this->editActionDate);
            $parsedDate = !empty($val) ? Carbon::parse($val)->format('Y-m-d') : null;

            DB::table('rdp_record')
                ->where('id', $this->selectedRecordId)
                ->update([
                    'action_date' => $parsedDate,
                    'updated_at'  => Carbon::now(),
                ]);

            $this->recordModalSuccess = 'Action date updated successfully!';
            if ($this->selectedRecord) {
                $this->selectedRecord->action_date = $parsedDate;
            }
        } catch (\Throwable $e) {
            $this->recordModalError = 'Failed to update action date: ' . $e->getMessage();
        }
    }

    public function updatedSelectedFormType(): void
    {
        $this->updateDefaultFormClusterName();
    }

    public function updatedFormOffice(): void
    {
        $this->updateDefaultFormClusterName();
    }

    private function updateDefaultFormClusterName(): void
    {
        $off = !empty($this->formOffice) ? $this->formOffice : 'CAMPUS';
        $date = Carbon::now()->format('Y-m-d');
        if ($this->selectedFormType === 'nap1') {
            $this->formClusterName = "Inventory Form — {$off} ({$date})";
        } elseif ($this->selectedFormType === 'nap2') {
            $this->formClusterName = "RDS Schedule Form — {$off} ({$date})";
        } else {
            $this->formClusterName = "Disposal Authority Form — {$off} ({$date})";
        }
    }

    public function submitGenerateForm(): void
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        if (!$this->hasReportAccessClearance($user, $perms)) {
            $this->generateErrorMessage = 'You do not have clearance to generate RDP forms.';
            return;
        }

        $targetOffice = trim($this->formOffice);
        $userOffice = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;
        if (empty($targetOffice) && !$this->hasAllOfficesClearance($user, $perms)) {
            $targetOffice = $userOffice ?: '';
        }

        $data = $this->with();
        $hierarchyTree = $data['hierarchyTree'] ?? [];

        $mainPendingTbl = Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';

        try {
            DB::beginTransaction();

            if ($this->selectedFormType === 'nap1') {
                // Collect active (non-disposed) records from current hierarchy tree
                $recordIds = [];
                foreach ($hierarchyTree as $root) {
                    if (!empty($root->direct_records)) {
                        foreach ($root->direct_records as $rec) {
                            if (($rec->status ?? '') !== 'disposed') {
                                if (empty($targetOffice) || ($rec->office ?? '') === $targetOffice) {
                                    $recordIds[] = (int)$rec->id;
                                }
                            }
                        }
                    }
                    if (!empty($root->children)) {
                        foreach ($root->children as $child) {
                            if (!empty($child->records)) {
                                foreach ($child->records as $sRec) {
                                    if (($sRec->status ?? '') !== 'disposed') {
                                        if (empty($targetOffice) || ($sRec->office ?? '') === $targetOffice) {
                                            $recordIds[] = (int)$sRec->id;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $recordIds = array_values(array_unique($recordIds));

                if (empty($recordIds) && !empty($targetOffice)) {
                    $recordIds = DB::table('rdp_record')
                        ->where('is_draft', false)
                        ->where('is_active', true)
                        ->where('transferred_to_nap3', false)
                        ->where('office_own', $targetOffice)
                        ->pluck('id')
                        ->map(fn($v) => (int)$v)
                        ->all();
                }

                if (empty($recordIds)) {
                    $this->generateErrorMessage = 'No active records available to package into NAP Form 1 for the selected office.';
                    DB::rollBack();
                    return;
                }

                $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                    'status'     => 'UNUSED',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $clusterTitle = trim($this->formClusterName) ?: ("Inventory Form — " . ($targetOffice ?: 'CAMPUS') . " (" . now()->format('Y-m-d') . ")");

                DB::table('rdp_pending_record')->insert([
                    'cluster_id'       => $mainPendingId,
                    'cluster_name'     => $clusterTitle,
                    'status_id'        => 1, // Pending Verification
                    'office'           => $targetOffice ?: null,
                    'created_by'       => $user?->id,
                    'is_for_nap_one'   => true,
                    'is_for_nap_three' => false,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                foreach ($recordIds as $rId) {
                    DB::table('rdp_grouped_record')->insert([
                        'group_head' => $mainPendingId,
                        'record_id'  => (int)$rId,
                        'is_active'  => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::commit();
                $this->lastGeneratedClusterId = $mainPendingId;
                $this->generateSuccessMessage = "NAP Form 1 created with " . count($recordIds) . " records and saved to Pending successfully!";

            } elseif ($this->selectedFormType === 'nap2') {
                // Collect series from current hierarchy tree
                $seriesIds = [];
                foreach ($hierarchyTree as $root) {
                    if (empty($targetOffice) || ($root->recorded_at_office ?? '') === $targetOffice) {
                        $seriesIds[] = (int)$root->id;
                    }
                    if (!empty($root->children)) {
                        foreach ($root->children as $child) {
                            if (empty($targetOffice) || ($child->recorded_at_office ?? '') === $targetOffice) {
                                $seriesIds[] = (int)$child->id;
                            }
                        }
                    }
                }
                $seriesIds = array_values(array_unique($seriesIds));

                if (empty($seriesIds) && !empty($targetOffice)) {
                    $seriesIds = DB::table('rdp_record_series')
                        ->where('is_active', true)
                        ->where('recorded_at_office', $targetOffice)
                        ->pluck('id')
                        ->map(fn($v) => (int)$v)
                        ->all();
                }

                if (empty($seriesIds)) {
                    $this->generateErrorMessage = 'No record series available to package into NAP Form 2 for the selected office.';
                    DB::rollBack();
                    return;
                }

                $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                    'status'     => 'UNUSED',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $clusterTitle = trim($this->formClusterName) ?: ("RDS Schedule Form — " . ($targetOffice ?: 'CAMPUS') . " (" . now()->format('Y-m-d') . ")");

                DB::table('rdp_pending_record_series')->insert([
                    'cluster_id'   => $mainPendingId,
                    'cluster_name' => $clusterTitle,
                    'status_id'    => 1, // Pending Verification
                    'office'       => $targetOffice ?: null,
                    'created_by'   => $user?->id,
                    'is_active'    => true,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                foreach ($seriesIds as $sId) {
                    DB::table('rdp_grouped_record_series')->insert([
                        'group_head'       => $mainPendingId,
                        'record_series_id' => (int)$sId,
                        'is_active'        => true,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }

                DB::commit();
                $this->lastGeneratedClusterId = $mainPendingId;
                $this->generateSuccessMessage = "NAP Form 2 created with " . count($seriesIds) . " record series and saved to Pending successfully!";

            } else {
                // Collect disposed/expired records from current hierarchy tree
                $recordIds = [];
                foreach ($hierarchyTree as $root) {
                    if (!empty($root->direct_records)) {
                        foreach ($root->direct_records as $rec) {
                            if (($rec->status ?? '') === 'disposed') {
                                if (empty($targetOffice) || ($rec->office ?? '') === $targetOffice) {
                                    $recordIds[] = (int)$rec->id;
                                }
                            }
                        }
                    }
                    if (!empty($root->children)) {
                        foreach ($root->children as $child) {
                            if (!empty($child->records)) {
                                foreach ($child->records as $sRec) {
                                    if (($sRec->status ?? '') === 'disposed') {
                                        if (empty($targetOffice) || ($sRec->office ?? '') === $targetOffice) {
                                            $recordIds[] = (int)$sRec->id;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $recordIds = array_values(array_unique($recordIds));

                if (empty($recordIds) && !empty($targetOffice)) {
                    $recordIds = DB::table('rdp_record')
                        ->where('is_draft', false)
                        ->where('is_active', true)
                        ->where('transferred_to_nap3', true)
                        ->where('office_own', $targetOffice)
                        ->pluck('id')
                        ->map(fn($v) => (int)$v)
                        ->all();
                }

                if (empty($recordIds)) {
                    $this->generateErrorMessage = 'No disposed or expired records available to package into NAP Form 3 for the selected office.';
                    DB::rollBack();
                    return;
                }

                $mainPendingId = DB::table($mainPendingTbl)->insertGetId([
                    'status'     => 'UNUSED',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $clusterTitle = trim($this->formClusterName) ?: ("Disposal Authority Form — " . ($targetOffice ?: 'CAMPUS') . " (" . now()->format('Y-m-d') . ")");

                DB::table('rdp_pending_record')->insert([
                    'cluster_id'       => $mainPendingId,
                    'cluster_name'     => $clusterTitle,
                    'status_id'        => 1, // Pending Verification
                    'office'           => $targetOffice ?: null,
                    'created_by'       => $user?->id,
                    'is_for_nap_one'   => false,
                    'is_for_nap_three' => true,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                foreach ($recordIds as $rId) {
                    DB::table('rdp_grouped_record')->insert([
                        'group_head' => $mainPendingId,
                        'record_id'  => (int)$rId,
                        'is_active'  => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::commit();
                $this->lastGeneratedClusterId = $mainPendingId;
                $this->generateSuccessMessage = "NAP Form 3 created with " . count($recordIds) . " expired records and saved to Pending successfully!";
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->generateErrorMessage = 'Failed to generate form: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $user = Auth::user();
        $perms = $user?->permissions;
        $hasAllOfficesClearance = $this->hasAllOfficesClearance($user, $perms);
        $userOfficeCode = $user?->details?->office_code ?? $user?->details?->office?->office_code ?? null;

        // Fetch office list for filter panel
        $officesList = DB::table(Schema::hasTable('sys_office') ? 'sys_office' : 'office')
            ->where('is_active', true)
            ->orderBy('office_name')
            ->get();

        // Fetch utility types and mediums map
        $mediumTable = Schema::hasTable('rdp_recorded_value') ? 'rdp_recorded_value' : (Schema::hasTable('rdp_records_medium') ? 'rdp_records_medium' : (Schema::hasTable('rdp_medium') ? 'rdp_medium' : null));
        $mediumsMap = $mediumTable ? DB::table($mediumTable)->pluck('medium_name', 'id')->all() : [];
        $mediumsList = $mediumTable ? DB::table($mediumTable)->orderBy('medium_name')->get() : collect();

        // 1. Base Record Series Query
        $seriesQuery = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->leftJoin('rdp_remarks_logic', 'rdp_record_series.remarks', '=', 'rdp_remarks_logic.id')
            ->where('rdp_record_series.is_active', true)
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
            ])
            ->addSelect('rdp_remarks_logic.remarks_value as remarks');

        if (!$hasAllOfficesClearance) {
            $seriesQuery->where('rdp_record_series.recorded_at_office', $userOfficeCode ?: '___NONE___');
        } elseif (!empty($this->officeFilter)) {
            $seriesQuery->where('rdp_record_series.recorded_at_office', $this->officeFilter);
        }

        $allRawSeries = $seriesQuery
            ->orderByRaw("item_number ASC NULLS LAST, series_title ASC")
            ->get();

        $allSeriesMap = [];
        foreach ($allRawSeries as $s) {
            $allSeriesMap[$s->id] = $s;
        }

        // 2. Fetch Records with Period Covered
        $recordQuery = DB::table('rdp_record')
            ->leftJoin('rdp_period_covered', 'rdp_record.id', '=', 'rdp_period_covered.period_owner')
            ->where('rdp_record.is_active', true)
            ->select([
                'rdp_record.*',
                'rdp_period_covered.date_covered',
                'rdp_period_covered.date_covered_end',
                'rdp_period_covered.volume as period_volume',
                'rdp_period_covered.id as period_id',
            ]);

        $seriesIds = $allRawSeries->pluck('id')->all();
        if (empty($seriesIds)) {
            $allRawRecords = collect();
        } else {
            $recordQuery->whereIn('rdp_record.record_series_id', $seriesIds);

        if (!empty($this->mediumFilter)) {
            $recordQuery->where('rdp_record.records_medium', $this->mediumFilter);
        }

        if (!empty($this->dateFrom)) {
            $recordQuery->where(function($q) {
                $q->where('rdp_period_covered.date_covered', '>=', $this->dateFrom)
                  ->orWhere('rdp_period_covered.date_covered_end', '>=', $this->dateFrom);
            });
        }
        if (!empty($this->dateTo)) {
            $recordQuery->where(function($q) {
                $q->where('rdp_period_covered.date_covered', '<=', $this->dateTo)
                  ->orWhere('rdp_period_covered.date_covered_end', '<=', $this->dateTo);
            });
        }

        if (!empty($this->search)) {
            $this->applySearchFilter($recordQuery, [
                'rdp_record.description',
                'rdp_record.records_location',
                'rdp_period_covered.date_covered',
                'rdp_period_covered.date_covered_end',
            ]);
        }

            $allRawRecords = $recordQuery->get();
        }

        // Fetch revision and disposal markers
        $revisedRecordIds = [];
        if (Schema::hasTable('rdp_revised_records')) {
            $revisedRecordIds = DB::table('rdp_revised_records')->pluck('record_id', 'record_id')->all();
        }

        // Count totals for Status Pills
        $statusCounts = [
            'all'         => 0,
            'active'      => 0,
            'revised'     => 0,
            'disposed'    => 0,
            'pending_rds' => 0,
            'expired'     => 0,
        ];

        // Group records by series_id
        $recordsBySeries = [];
        foreach ($allRawRecords as $r) {
            $sId = (int)$r->record_series_id;
            $parentSeries = $allSeriesMap[$sId] ?? null;
            $eff = $parentSeries ? $this->resolveEffectiveRetention($allSeriesMap, $parentSeries) : (object)[
                'active_period' => null,
                'storage_period' => null,
                'total_period' => null,
                'is_retention_period_permanent' => false,
            ];

            // Evaluate lifecycle status
            $isRevised = isset($revisedRecordIds[$r->id]);
            if (!$isRevised) {
                $dLow = mb_strtolower((string)$r->description);
                if (str_contains($dLow, '[revised]') || str_contains($dLow, '(revised)') || preg_match('/\b(revised|superseded)\b/i', $dLow)) {
                    $isRevised = true;
                }
            }

            $isExpired = \App\Services\RdpRetentionService::isPeriodExpired(
                $r->date_covered,
                $r->date_covered_end,
                $eff->total_period,
                $eff->active_period,
                $eff->storage_period,
                $eff->is_retention_period_permanent
            );
            $isTransferred = (bool)($r->transferred_to_nap3 ?? false);

            if ($isRevised) {
                $status = 'revised';
            } elseif ($isTransferred) {
                $status = 'disposed';
            } elseif ($isExpired) {
                $status = 'expired';
            } elseif ($parentSeries && (!(bool)($parentSeries->is_verified ?? false))) {
                $status = 'pending_rds';
            } else {
                $status = 'active';
            }

            $r->status = $status;
            $r->medium = !empty($r->records_medium) ? ($mediumsMap[$r->records_medium] ?? (string)$r->records_medium) : '—';
            $r->location = $r->records_location ?: '—';
            $r->volume = $r->period_volume ?: ($r->volume ?: '—');

            $actionDateFormatted = '—';
            if (!empty($r->action_date)) {
                try {
                    $actionDateFormatted = Carbon::parse($r->action_date)->format('M d, Y');
                } catch (\Throwable $e) {
                    $actionDateFormatted = (string)$r->action_date;
                }
            } elseif (!empty($r->transferred_to_nap3_at)) {
                try {
                    $actionDateFormatted = Carbon::parse($r->transferred_to_nap3_at)->format('M d, Y');
                } catch (\Throwable $e) {
                    $actionDateFormatted = (string)$r->transferred_to_nap3_at;
                }
            }
            $r->formatted_action_date = $actionDateFormatted;
            $r->disposition_provision = !empty($parentSeries?->remarks) ? $parentSeries->remarks : '—';

            // Apply Status Filter if specific status is selected
            if ($this->statusFilter !== 'all' && $this->statusFilter !== $status) {
                continue;
            }

            // Apply Retention Filter (Permanent vs Temporary)
            if ($this->retentionFilter === 'permanent' && !($eff->is_retention_period_permanent || strtolower(trim((string)$eff->total_period)) === 'permanent')) {
                continue;
            } elseif ($this->retentionFilter === 'temporary' && ($eff->is_retention_period_permanent || strtolower(trim((string)$eff->total_period)) === 'permanent')) {
                continue;
            }

            $recordsBySeries[$sId][] = $r;

            // Increment status pill counts
            $statusCounts['all']++;
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        // 3. Assemble Hierarchical Tree
        $rootSeries = [];
        $childSeriesByParent = [];

        foreach ($allRawSeries as $s) {
            $eff = $this->resolveEffectiveRetention($allSeriesMap, $s);
            $s->effective_active = $eff->active_period;
            $s->effective_storage = $eff->storage_period;
            $s->effective_total = $eff->total_period;
            $s->is_retention_period_permanent = $eff->is_retention_period_permanent;

            $sRecords = $recordsBySeries[$s->id] ?? [];
            $s->direct_records = $sRecords;
            $s->records = $sRecords;
            $s->total_records_count = count($sRecords);

            // Inherit lifecycle badge for series level
            if (!(bool)($s->is_verified ?? false)) {
                $s->lifecycle_status = 'pending_rds';
            } else {
                $s->lifecycle_status = 'active';
            }

            // Summarized metadata
            $pCovered = [];
            $pVols = [];
            $pMeds = [];
            $pLocs = [];
            $pRestr = [];

            foreach ($sRecords as $rec) {
                if (!empty($rec->date_covered)) $pCovered[] = $rec->date_covered;
                if (!empty($rec->volume) && $rec->volume !== '—') $pVols[] = $rec->volume;
                if (!empty($rec->medium) && $rec->medium !== '—') $pMeds[] = $rec->medium;
                if (!empty($rec->location) && $rec->location !== '—') $pLocs[] = $rec->location;
                if (!empty($rec->restriction) && $rec->restriction !== '—') $pRestr[] = $rec->restriction;
            }

            $s->compiled_period = !empty($pCovered) ? (min($pCovered) . (max($pCovered) !== min($pCovered) ? ' - ' . max($pCovered) : '')) : '—';
            $s->compiled_volume = $this->compileVolume($pVols) ?: '—';
            $s->compiled_medium = !empty($pMeds) ? implode(', ', array_unique($pMeds)) : '—';
            $s->compiled_location = !empty($pLocs) ? implode(', ', array_unique($pLocs)) : '—';
            $s->compiled_restriction = !empty($pRestr) ? implode(', ', array_unique($pRestr)) : '—';

            if (empty($s->parent_id)) {
                $rootSeries[] = $s;
            } else {
                $childSeriesByParent[(int)$s->parent_id][] = $s;
            }
        }

        $hierarchyTree = [];
        foreach ($rootSeries as $root) {
            $children = $childSeriesByParent[$root->id] ?? [];
            $root->children = $children;
            $root->has_children = !empty($children);

            // Compute total child records across root and sub-series
            $childRecCount = $root->total_records_count;
            foreach ($children as $c) {
                $childRecCount += $c->total_records_count;
            }
            $root->aggregate_records_count = $childRecCount;

            // If search/filter active, hide empty series branches
            $hasContent = $childRecCount > 0 || (empty($this->search) && $this->statusFilter === 'all' && $this->retentionFilter === 'all');
            if ($hasContent) {
                $hierarchyTree[] = $root;
            }
        }

        return [
            'hierarchyTree'          => $hierarchyTree,
            'statusCounts'           => $statusCounts,
            'officesList'            => $officesList,
            'mediumsList'            => $mediumsList,
            'hasAllOfficesClearance' => $hasAllOfficesClearance,
            'isSadm'                 => $hasAllOfficesClearance,
            'userOfficeCode'         => $userOfficeCode,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css'])
    <style>
        .ml-container {
            padding: 24px;
            min-height: 100vh;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* Controls Toolbar */
        .ml-controls-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        /* Top Status Pills Bar */
        .ml-pills-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .ml-pill-btn {
            height: 34px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 0 12px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease-in-out;
        }

        .ml-pill-btn:hover {
            border-color: #94a3b8;
            color: #0f172a;
            background: #f8fafc;
        }

        .ml-pill-btn.is-active {
            background: #0f172a;
            border-color: #0f172a;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .ml-pill-count {
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
        }

        .ml-pill-btn.is-active .ml-pill-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* Toolbar Actions on Right */
        .ml-actions-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .ml-search-box {
            position: relative;
            width: 260px;
        }

        .ml-search-box svg {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            pointer-events: none;
        }

        .ml-search-box input {
            width: 100%;
            height: 34px;
            padding: 0 12px 0 32px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12.5px;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.15s ease;
        }

        .ml-search-box input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .ml-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 34px;
            padding: 0 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .ml-action-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
            color: #0f172a;
        }

        .ml-action-btn.ml-export-btn {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #0f172a;
            font-weight: 700;
        }

        .ml-action-btn.ml-export-btn:hover {
            border-color: #2563eb;
            color: #2563eb;
            background: #eff6ff;
        }

        .ml-action-btn.ml-generate-btn {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
            font-weight: 700;
        }

        .ml-action-btn.ml-generate-btn:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            color: #ffffff;
        }

        /* Generate Form Modal */
        .ml-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .ml-modal-dialog {
            background: #ffffff;
            width: 100%;
            max-width: 580px;
            border-radius: 14px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.2);
            padding: 24px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-sizing: border-box;
            animation: mlModalFadeIn 0.18s ease-out;
        }

        @keyframes mlModalFadeIn {
            from { opacity: 0; transform: scale(0.97) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .ml-form-type-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .ml-form-type-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .ml-form-type-card:hover {
            border-color: #93c5fd;
            background: #f0f7ff;
        }

        .ml-form-type-card.active {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 0 0 1px #2563eb;
        }

        [data-theme="dark"] .ml-modal-dialog {
            background: #0f172a;
            border-color: #1e293b;
            color: #f1f5f9;
        }

        [data-theme="dark"] .ml-form-type-card {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .ml-form-type-card.active {
            background: #1e3a5f;
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
        }

        .ml-filter-indicator {
            position: absolute;
            top: -3px;
            right: -3px;
            width: 8px;
            height: 8px;
            background: #2563eb;
            border-radius: 50%;
        }

        /* Slide-Out Filter Drawer (Elevated and Clean) */
        .ml-drawer-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(3px);
            z-index: 1000000;
            transition: opacity 0.2s ease;
        }

        .ml-drawer-panel {
            position: fixed;
            top: 0;
            right: 0;
            width: 380px;
            max-width: 92vw;
            height: 100vh;
            background: #ffffff;
            z-index: 1000001;
            box-shadow: -6px 0 25px rgba(0, 0, 0, 0.15);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .ml-drawer-head {
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ml-drawer-body {
            padding: 22px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 18px;
            flex-grow: 1;
        }

        .ml-filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .ml-filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ml-filter-group input,
        .ml-filter-group select {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.15s ease;
            box-sizing: border-box;
        }

        .ml-filter-group input:focus,
        .ml-filter-group select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .ml-drawer-foot {
            padding: 16px 22px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ml-drawer-btn-reset {
            height: 38px;
            padding: 0 16px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .ml-drawer-btn-reset:hover {
            border-color: #94a3b8;
            background: #f1f5f9;
            color: #0f172a;
        }

        .ml-drawer-btn-apply {
            flex: 1;
            height: 38px;
            padding: 0 18px;
            border: none;
            background: #2563eb;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .ml-drawer-btn-apply:hover {
            background: #1d4ed8;
        }

        /* Table Styling with Sticky Anchors and Full Grid Matrix Borders */
        .ml-table-wrap {
            position: relative;
            max-height: 72vh;
            overflow: auto;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .ml-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
            color: #0f172a;
        }

        .ml-table thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f8fafc;
            color: #475569;
            padding: 10px 12px;
            font-weight: 700;
            font-size: 11px;
            letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 2px solid #cbd5e1;
            border-right: 1px solid #e2e8f0;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .ml-table th.col-sticky-1,
        .ml-table td.col-sticky-1 {
            position: sticky;
            left: 0;
            z-index: 12;
            width: 75px;
            min-width: 75px;
            max-width: 75px;
            text-align: center;
            border-right: 1px solid #e2e8f0;
        }

        .ml-table th.col-sticky-2,
        .ml-table td.col-sticky-2 {
            position: sticky;
            left: 75px;
            z-index: 12;
            min-width: 290px;
            border-right: 1px solid #e2e8f0;
        }

        .ml-table thead th.col-sticky-1,
        .ml-table thead th.col-sticky-2 {
            z-index: 15;
            background: #f8fafc;
        }

        /* Grid lines across every cell */
        .ml-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            vertical-align: middle;
            line-height: 1.4;
        }

        .ml-table td:last-child {
            border-right: none;
        }

        /* Row Footprints */
        .root-series-row td {
            background-color: #f8fafc;
            font-weight: 700;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
        }

        .sub-series-row td {
            background-color: #ffffff;
            font-weight: 600;
            border-bottom: 1px solid #e2e8f0;
        }

        .record-item-row td {
            background-color: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }

        .record-item-row:hover td {
            background-color: #f8fafc;
        }

        /* Muted Footprint for Disposed & Soft Tint for Revised */
        .record-item-row.is-disposed td {
            background-color: #f8fafc !important;
            color: #94a3b8 !important;
        }

        .record-item-row.is-revised td {
            background-color: #fffbeb !important;
        }

        .table-section-divider-row td {
            background: #e2e8f0 !important;
            color: #1e293b !important;
            font-weight: 800;
            text-align: left;
            padding: 8px 16px;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-top: 2px solid #cbd5e1;
            border-bottom: 2px solid #cbd5e1;
            border-right: none !important;
        }

        /* Modern Chevron Toggle Button */
        .ml-chevron-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: transparent;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: #64748b;
            padding: 0;
            transition: all 0.15s ease;
        }

        .ml-chevron-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* Subtle dash styling */
        .ml-dash {
            color: #cbd5e1;
            font-weight: 400;
        }

        [data-theme="dark"] .ml-table thead th,
        [data-theme="dark"] .ml-table thead th.col-sticky-1,
        [data-theme="dark"] .ml-table thead th.col-sticky-2 {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] .ml-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] .root-series-row td {
            background-color: #1e293b !important;
        }
        [data-theme="dark"] .sub-series-row td,
        [data-theme="dark"] .record-item-row td {
            background-color: #0f172a !important;
        }
        [data-theme="dark"] .table-section-divider-row td {
            background: #1e293b !important;
            color: #60a5fa !important;
            border-color: #334155 !important;
        }

        /* Badges */
        .ml-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ml-badge-active { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .ml-badge-revised { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .ml-badge-disposed { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .ml-badge-pending { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .ml-badge-expired { background: #fef2f2; color: #991b1b; border: 1px solid #f87171; }

        /* Clamped Text */
        .ml-text-clamp {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.35;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .ml-table-wrap { background: #131c2e !important; border-color: #1e293b !important; }
        [data-theme="dark"] .ml-table { color: #cbd5e1 !important; }
        [data-theme="dark"] .ml-table thead th { background: #0b1120 !important; color: #f8fafc !important; border-color: #1e293b !important; }
        [data-theme="dark"] .root-series-row { background: #1a253c !important; }
        [data-theme="dark"] .sub-series-row { background: #131c2e !important; }
        [data-theme="dark"] .record-item-row { background: #0f172a !important; }
        [data-theme="dark"] .record-item-row:hover { background: #1e293b !important; }
        [data-theme="dark"] .ml-drawer-panel { background: #131c2e !important; color: #f8fafc !important; }
        [data-theme="dark"] .ml-drawer-head, [data-theme="dark"] .ml-drawer-foot { background: #0f172a !important; border-color: #1e293b !important; }
    </style>
@endpush

<div class="ml-container" 
     @keydown.escape.window="drawerOpen = false"
     x-data="{
        drawerOpen: false,
        collapsedRoots: {},
        allRootsCollapsed: false,
        collapsedSubs: {},
        allSubsCollapsed: false,

        init() {
            this.$watch('drawerOpen', (open) => {
                const widget = document.getElementById('chatify-global-widget');
                if (widget) {
                    widget.style.transition = 'transform 0.28s cubic-bezier(0.16, 1, 0.3, 1)';
                    widget.style.transform = open ? 'translateX(-390px)' : 'translateX(0)';
                }
            });
        },
        destroy() {
            const widget = document.getElementById('chatify-global-widget');
            if (widget) {
                widget.style.transform = 'translateX(0)';
            }
        },

        toggleRoot(key) {
            this.collapsedRoots[key] = !this.isRootCollapsed(key);
        },
        isRootCollapsed(key) {
            if ($wire.search && $wire.search.trim().length > 0) return false;
            if (this.collapsedRoots[key] !== undefined) return this.collapsedRoots[key];
            return this.allRootsCollapsed;
        },
        toggleSub(key) {
            this.collapsedSubs[key] = !this.isSubCollapsed(key);
        },
        isSubCollapsed(key) {
            if ($wire.search && $wire.search.trim().length > 0) return false;
            if (this.collapsedSubs[key] !== undefined) return this.collapsedSubs[key];
            return this.allSubsCollapsed;
        },
        collapseAll() {
            this.allRootsCollapsed = true;
            this.allSubsCollapsed = true;
            this.collapsedRoots = {};
            this.collapsedSubs = {};
        },
        expandAll() {
            this.allRootsCollapsed = false;
            this.allSubsCollapsed = false;
            this.collapsedRoots = {};
            this.collapsedSubs = {};
        }
     }">

    <!-- Page Header (Polished DCS & NAP Fusion) -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 14px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; letter-spacing: -0.02em; margin: 0;">Generalized Records</h1>
                <span style="background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; font-size: 11px; padding: 2px 9px; border-radius: 9999px; font-weight: 800; letter-spacing: 0.4px;">
                    GENERAL MATRIX
                </span>
            </div>
            <p style="font-size: 13px; color: #64748b; margin: 0;">Consolidated inventory matrix generalizing NAP Forms 1 to 3 with complete historical tracking of active, revised, and disposed records.</p>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 12px; font-weight: 700; color: #1d4ed8; background: #eff6ff; border: 1px solid #bfdbfe; padding: 6px 14px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>{{ $statusCounts['all'] ?? 0 }} Records Tracked</span>
            </span>
        </div>
    </div>

    <!-- Main Card -->
    <div class="nap-card" style="padding: 18px 20px;">
        <!-- Unified Controls Toolbar (Pills on Left, Search + Actions on Right) -->
        <div class="ml-controls-bar">
            <!-- Status Pills (Left) -->
            <div class="ml-pills-wrap">
                <button type="button" 
                        class="ml-pill-btn {{ $statusFilter === 'all' ? 'is-active' : '' }}" 
                        wire:click="setStatusFilter('all')">
                    <span>ALL</span>
                    <span class="ml-pill-count">{{ $statusCounts['all'] }}</span>
                </button>
                <button type="button" 
                        class="ml-pill-btn {{ $statusFilter === 'active' ? 'is-active' : '' }}" 
                        wire:click="setStatusFilter('active')">
                    <span style="color: #16a34a; font-weight: 900;">●</span>
                    <span>ACTIVE</span>
                    <span class="ml-pill-count">{{ $statusCounts['active'] }}</span>
                </button>
                <button type="button" 
                        class="ml-pill-btn {{ $statusFilter === 'revised' ? 'is-active' : '' }}" 
                        wire:click="setStatusFilter('revised')">
                    <span style="color: #d97706; font-weight: 900;">●</span>
                    <span>REVISED</span>
                    <span class="ml-pill-count">{{ $statusCounts['revised'] }}</span>
                </button>
                <button type="button" 
                        class="ml-pill-btn {{ $statusFilter === 'disposed' ? 'is-active' : '' }}" 
                        wire:click="setStatusFilter('disposed')">
                    <span style="color: #dc2626; font-weight: 900;">●</span>
                    <span>DISPOSED</span>
                    <span class="ml-pill-count">{{ $statusCounts['disposed'] }}</span>
                </button>
                <button type="button" 
                        class="ml-pill-btn {{ $statusFilter === 'pending_rds' ? 'is-active' : '' }}" 
                        wire:click="setStatusFilter('pending_rds')">
                    <span style="color: #2563eb; font-weight: 900;">●</span>
                    <span>PENDING RDS</span>
                    <span class="ml-pill-count">{{ $statusCounts['pending_rds'] }}</span>
                </button>
                <button type="button" 
                        class="ml-pill-btn {{ $statusFilter === 'expired' ? 'is-active' : '' }}" 
                        wire:click="setStatusFilter('expired')">
                    <span style="color: #991b1b; font-weight: 900;">●</span>
                    <span>EXPIRED</span>
                    <span class="ml-pill-count">{{ $statusCounts['expired'] }}</span>
                </button>
            </div>

            <!-- Search & Actions (Right) -->
            <div class="ml-actions-wrap">
                <div class="ml-search-box">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <input type="text" 
                           wire:model.live.debounce.350ms="search" 
                           placeholder="Search series, description, remarks...">
                </div>

                <!-- Expand / Collapse All Toggle -->
                <button type="button" 
                        @click="allRootsCollapsed ? expandAll() : collapseAll()" 
                        class="ml-action-btn" 
                        title="Expand or collapse all series groups">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" x-show="!allRootsCollapsed"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" x-show="allRootsCollapsed" x-cloak><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                    <span x-text="allRootsCollapsed ? 'Expand All' : 'Collapse All'"></span>
                </button>

                <!-- Filter Drawer Trigger Button -->
                <button type="button" 
                        @click="drawerOpen = true" 
                        class="ml-action-btn" 
                        style="position: relative;"
                        title="Open filter panel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span>Filters</span>
                    @if($retentionFilter !== 'all' || !empty($mediumFilter) || !empty($dateFrom) || !empty($dateTo) || ($hasAllOfficesClearance && !empty($officeFilter)))
                        <span class="ml-filter-indicator" title="Active filters applied"></span>
                    @endif
                </button>

                <!-- Generate Form Button -->
                <button type="button" 
                        wire:click="openGenerateFormModal" 
                        class="ml-action-btn ml-generate-btn"
                        title="Generate official NAP Form (Form 1, 2, or 3) and save to Pending">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                    <span>Generate Form</span>
                </button>

                @if(!empty($search) || $statusFilter !== 'all' || $retentionFilter !== 'all' || !empty($mediumFilter) || !empty($dateFrom) || !empty($dateTo) || ($hasAllOfficesClearance && !empty($officeFilter)))
                    <button type="button" 
                            wire:click="clearFilters" 
                            class="ml-action-btn" 
                            style="color: #dc2626; border-color: #fca5a5;"
                            title="Reset all filters">
                        Reset
                    </button>
                @endif
            </div>
        </div>

        <!-- Table Container with Sticky Anchors and Full Grid Lines -->
        <div class="ml-table-wrap">
            <table class="ml-table">
                <thead>
                    <tr>
                        <th class="col-sticky-1">ITEM NO.</th>
                        <th class="col-sticky-2">RECORD SERIES TITLE & DESCRIPTION</th>
                        <th style="min-width: 125px; text-align: center;">PERIOD COVERED</th>
                        <th style="min-width: 90px; text-align: center;">VOLUME</th>
                        <th style="min-width: 90px; text-align: center;">MEDIUM</th>
                        <th style="min-width: 110px; text-align: center;">RESTRICTIONS</th>
                        <th style="min-width: 130px; text-align: center;">LOCATION</th>
                        <th style="min-width: 75px; text-align: center;">ACTIVE</th>
                        <th style="min-width: 75px; text-align: center;">STORAGE</th>
                        <th style="min-width: 80px; text-align: center;">TOTAL</th>
                        <th style="min-width: 150px; text-align: center;">DISPOSITION PROVISION</th>
                        <th style="min-width: 110px; text-align: center;">ACTION DATE</th>
                        <th style="min-width: 100px; text-align: center;">STATUS</th>
                        <th style="min-width: 100px; text-align: center;">OFFICE</th>
                        <th style="min-width: 75px; text-align: center;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($hierarchyTree as $root)
                        @php
                            $hasExpandable = ($root->aggregate_records_count > 0) || !empty($root->children) || !empty($root->direct_records);
                        @endphp
                        <!-- ROOT SERIES ROW -->
                        <tr class="root-series-row" @if($hasExpandable) style="cursor: pointer;" @click="toggleRoot('root-{{ $root->id }}')" @endif>
                            <td class="col-sticky-1" style="font-weight: 800; text-align: center;">
                                <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                    @if($hasExpandable)
                                        <button type="button" class="ml-chevron-btn" title="Toggle Root Series">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                 :style="isRootCollapsed('root-{{ $root->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </button>
                                    @else
                                        <span style="width: 20px; display: inline-block;"></span>
                                    @endif
                                    <span style="font-size: 13px; font-weight: 800; color: #1e293b;">{{ $root->item_number ?: '—' }}</span>
                                </div>
                            </td>
                            <td class="col-sticky-2">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-weight: 800; color: #0f172a; font-size: 13px;">{{ strtoupper($root->series_title) }}</span>
                                    @if(($root->aggregate_records_count ?? 0) > 0)
                                        <span style="font-size: 10.5px; background: #e2e8f0; color: #334155; padding: 2px 7px; border-radius: 12px; font-weight: 700;">
                                            {{ $root->aggregate_records_count }} {{ $root->aggregate_records_count === 1 ? 'item' : 'items' }}
                                        </span>
                                    @endif
                                </div>
                                @if(!empty($root->remarks))
                                    <div class="ml-text-clamp" style="font-size: 11px; color: #64748b; margin-top: 2px;" title="{{ $root->remarks }}">
                                        {{ $root->remarks }}
                                    </div>
                                @endif
                            </td>
                            <td style="text-align: center;">{!! $root->compiled_period === '—' ? '<span class="ml-dash">—</span>' : e($root->compiled_period) !!}</td>
                            <td style="text-align: center;">{!! $root->compiled_volume === '—' ? '<span class="ml-dash">—</span>' : e($root->compiled_volume) !!}</td>
                            <td style="text-align: center;">{!! $root->compiled_medium === '—' ? '<span class="ml-dash">—</span>' : e($root->compiled_medium) !!}</td>
                            <td style="text-align: center;">{!! $root->compiled_restriction === '—' ? '<span class="ml-dash">—</span>' : e($root->compiled_restriction) !!}</td>
                            <td style="text-align: center;">
                                <div class="ml-text-clamp" title="{{ $root->compiled_location }}">{!! $root->compiled_location === '—' ? '<span class="ml-dash">—</span>' : e($root->compiled_location) !!}</div>
                            </td>
                            <td style="text-align: center;">{!! empty($root->effective_active) ? '<span class="ml-dash">—</span>' : e($root->effective_active) !!}</td>
                            <td style="text-align: center;">{!! empty($root->effective_storage) ? '<span class="ml-dash">—</span>' : e($root->effective_storage) !!}</td>
                            <td style="text-align: center; font-weight: 800;">
                                {{ $root->is_retention_period_permanent ? 'PERMANENT' : ($root->effective_total ?: '—') }}
                            </td>
                            <td style="text-align: center; font-size: 11.5px; color: #475569;">
                                @if(!empty($root->remarks))
                                    <div class="ml-text-clamp" style="max-width: 220px; margin: 0 auto;" title="{{ $root->remarks }}">{{ $root->remarks }}</div>
                                @else
                                    <span class="ml-dash">—</span>
                                @endif
                            </td>
                            <td style="text-align: center; font-size: 11.5px; color: #64748b;">
                                <span class="ml-dash">—</span>
                            </td>
                            <td style="text-align: center;">
                                @if($root->lifecycle_status === 'pending_rds')
                                    <span class="ml-badge ml-badge-pending">Pending RDS</span>
                                @else
                                    <span class="ml-badge ml-badge-active">Active</span>
                                @endif
                            </td>
                            <td style="text-align: center; font-weight: 700;">{{ $root->recorded_at_office ?: 'ALL' }}</td>
                            <td style="text-align: center;">
                                <span class="ml-dash">—</span>
                            </td>
                        </tr>

                        <!-- DIRECT RECORDS (Under Root) -->
                        @if(!empty($root->direct_records))
                            @foreach($root->direct_records as $rec)
                                <tr class="record-item-row {{ $rec->status === 'disposed' ? 'is-disposed' : ($rec->status === 'revised' ? 'is-revised' : '') }}" 
                                    x-show="!isRootCollapsed('root-{{ $root->id }}')">
                                    <td class="col-sticky-1" style="text-align: center;">
                                        <span style="width: 20px; display: inline-block;"></span>
                                    </td>
                                    <td class="col-sticky-2" style="padding-left: 28px;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="color: #94a3b8; font-weight: 600; font-size: 11px;">└</span>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 12px;">{{ $rec->description }}</div>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">{!! empty($rec->date_covered) ? '<span class="ml-dash">—</span>' : e($rec->date_covered) !!}</td>
                                    <td style="text-align: center;">{!! empty($rec->volume) ? '<span class="ml-dash">—</span>' : e($rec->volume) !!}</td>
                                    <td style="text-align: center;">{!! empty($rec->medium) ? '<span class="ml-dash">—</span>' : e($rec->medium) !!}</td>
                                    <td style="text-align: center;">{!! empty($rec->restriction) ? '<span class="ml-dash">—</span>' : e($rec->restriction) !!}</td>
                                    <td style="text-align: center;">
                                        <div class="ml-text-clamp" title="{{ $rec->location }}">{!! empty($rec->location) ? '<span class="ml-dash">—</span>' : e($rec->location) !!}</div>
                                    </td>
                                    <td style="text-align: center;">{!! empty($root->effective_active) ? '<span class="ml-dash">—</span>' : e($root->effective_active) !!}</td>
                                    <td style="text-align: center;">{!! empty($root->effective_storage) ? '<span class="ml-dash">—</span>' : e($root->effective_storage) !!}</td>
                                    <td style="text-align: center;">{{ $root->is_retention_period_permanent ? 'PERMANENT' : ($root->effective_total ?: '—') }}</td>
                                    <td style="text-align: center; font-size: 11.5px; color: #475569;">
                                        @php
                                            $dispProv = $rec->disposition_provision ?? $root->remarks;
                                        @endphp
                                        @if(!empty($dispProv))
                                            <div class="ml-text-clamp" style="max-width: 220px; margin: 0 auto;" title="{{ $dispProv }}">{{ $dispProv }}</div>
                                        @else
                                            <span class="ml-dash">—</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center; font-size: 11.5px; font-weight: 600; color: #334155;">
                                        {!! empty($rec->formatted_action_date) || $rec->formatted_action_date === '—' ? '<span class="ml-dash">—</span>' : e($rec->formatted_action_date) !!}
                                    </td>
                                    <td style="text-align: center;">
                                        @if($rec->status === 'revised')
                                            <span class="ml-badge ml-badge-revised">Revised</span>
                                        @elseif($rec->status === 'disposed')
                                            <span class="ml-badge ml-badge-disposed">Disposed</span>
                                        @elseif($rec->status === 'expired')
                                            <span class="ml-badge ml-badge-expired">Expired</span>
                                        @elseif($rec->status === 'pending_rds')
                                            <span class="ml-badge ml-badge-pending">Pending RDS</span>
                                        @else
                                            <span class="ml-badge ml-badge-active">Active</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center; font-size: 11px;">{{ $root->recorded_at_office ?: 'ALL' }}</td>
                                    <td style="text-align: center;">
                                        <button type="button" 
                                                wire:click="openRecordModal({{ $rec->id }})" 
                                                class="ml-action-btn" 
                                                style="padding: 3px 8px; font-size: 11px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 700; color: #1e293b; cursor: pointer;" 
                                                title="View & Edit Action Date">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        @endif

                        <!-- SUB-SERIES ROWS -->
                        @if(!empty($root->children))
                            @foreach($root->children as $sub)
                                @php
                                    $subHasExpandable = !empty($sub->records) && count($sub->records) > 0;
                                @endphp
                                <tr class="sub-series-row" 
                                    x-show="!isRootCollapsed('root-{{ $root->id }}')" 
                                    @if($subHasExpandable) style="cursor: pointer;" @click="toggleSub('sub-{{ $sub->id }}')" @endif>
                                    <td class="col-sticky-1" style="text-align: center; font-weight: 700;">
                                        <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                            @if($subHasExpandable)
                                                <button type="button" class="ml-chevron-btn" title="Toggle Sub-Series">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                         :style="isSubCollapsed('sub-{{ $sub->id }}') ? 'transform: rotate(-90deg);' : 'transform: rotate(0deg);'">
                                                        <polyline points="6 9 12 15 18 9"></polyline>
                                                    </svg>
                                                </button>
                                            @else
                                                <span style="width: 20px; display: inline-block;"></span>
                                            @endif
                                            @if(!empty($sub->item_number))
                                                <span style="font-size: 11.5px; font-weight: 700; color: #475569;">{{ $sub->item_number }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="col-sticky-2" style="padding-left: 20px;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="color: #64748b; font-weight: 700; font-size: 12px;">└</span>
                                            <span style="font-weight: 700; color: #1e293b;">{{ $sub->series_title }}</span>
                                            @if(($sub->total_records_count ?? 0) > 0)
                                                <span style="font-size: 10px; background: #f1f5f9; color: #475569; padding: 1px 6px; border-radius: 10px;">
                                                    {{ $sub->total_records_count }}
                                                </span>
                                            @endif
                                        </div>
                                        @if(!empty($sub->remarks))
                                            <div class="ml-text-clamp" style="font-size: 11px; color: #64748b; margin-top: 1px; padding-left: 14px;" title="{{ $sub->remarks }}">
                                                {{ $sub->remarks }}
                                            </div>
                                        @endif
                                    </td>
                                    <td style="text-align: center;">{!! $sub->compiled_period === '—' ? '<span class="ml-dash">—</span>' : e($sub->compiled_period) !!}</td>
                                    <td style="text-align: center;">{!! $sub->compiled_volume === '—' ? '<span class="ml-dash">—</span>' : e($sub->compiled_volume) !!}</td>
                                    <td style="text-align: center;">{!! $sub->compiled_medium === '—' ? '<span class="ml-dash">—</span>' : e($sub->compiled_medium) !!}</td>
                                    <td style="text-align: center;">{!! $sub->compiled_restriction === '—' ? '<span class="ml-dash">—</span>' : e($sub->compiled_restriction) !!}</td>
                                    <td style="text-align: center;">
                                        <div class="ml-text-clamp" title="{{ $sub->compiled_location }}">{!! $sub->compiled_location === '—' ? '<span class="ml-dash">—</span>' : e($sub->compiled_location) !!}</div>
                                    </td>
                                    <td style="text-align: center;">{!! empty($sub->effective_active) ? '<span class="ml-dash">—</span>' : e($sub->effective_active) !!}</td>
                                    <td style="text-align: center;">{!! empty($sub->effective_storage) ? '<span class="ml-dash">—</span>' : e($sub->effective_storage) !!}</td>
                                    <td style="text-align: center; font-weight: 700;">
                                        {{ $sub->is_retention_period_permanent ? 'PERMANENT' : ($sub->effective_total ?: '—') }}
                                    </td>
                                    <td style="text-align: center; font-size: 11.5px; color: #475569;">
                                        @php
                                            $subRemarks = $sub->remarks ?? $root->remarks;
                                        @endphp
                                        @if(!empty($subRemarks))
                                            <div class="ml-text-clamp" style="max-width: 220px; margin: 0 auto;" title="{{ $subRemarks }}">{{ $subRemarks }}</div>
                                        @else
                                            <span class="ml-dash">—</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center; font-size: 11.5px; color: #64748b;">
                                        <span class="ml-dash">—</span>
                                    </td>
                                    <td style="text-align: center;">
                                        @if($sub->lifecycle_status === 'pending_rds')
                                            <span class="ml-badge ml-badge-pending">Pending RDS</span>
                                        @else
                                            <span class="ml-badge ml-badge-active">Active</span>
                                        @endif
                                    </td>
                                    <td style="text-align: center; font-size: 11px;">{{ $sub->recorded_at_office ?: 'ALL' }}</td>
                                    <td style="text-align: center;">
                                        <span class="ml-dash">—</span>
                                    </td>
                                </tr>

                                <!-- RECORDS UNDER SUB-SERIES -->
                                @if(!empty($sub->records))
                                    @foreach($sub->records as $sRec)
                                        <tr class="record-item-row {{ $sRec->status === 'disposed' ? 'is-disposed' : ($sRec->status === 'revised' ? 'is-revised' : '') }}" 
                                            x-show="!isRootCollapsed('root-{{ $root->id }}') && !isSubCollapsed('sub-{{ $sub->id }}')">
                                            <td class="col-sticky-1" style="text-align: center;">
                                                <span style="width: 20px; display: inline-block;"></span>
                                            </td>
                                            <td class="col-sticky-2" style="padding-left: 38px;">
                                                <div style="display: flex; align-items: center; gap: 6px;">
                                                    <span style="color: #94a3b8; font-weight: 600; font-size: 11px;">└</span>
                                                    <div style="font-weight: 500; color: #334155; font-size: 12px;">{{ $sRec->description }}</div>
                                                </div>
                                            </td>
                                            <td style="text-align: center;">{!! empty($sRec->date_covered) ? '<span class="ml-dash">—</span>' : e($sRec->date_covered) !!}</td>
                                            <td style="text-align: center;">{!! empty($sRec->volume) ? '<span class="ml-dash">—</span>' : e($sRec->volume) !!}</td>
                                            <td style="text-align: center;">{!! empty($sRec->medium) ? '<span class="ml-dash">—</span>' : e($sRec->medium) !!}</td>
                                            <td style="text-align: center;">{!! empty($sRec->restriction) ? '<span class="ml-dash">—</span>' : e($sRec->restriction) !!}</td>
                                            <td style="text-align: center;">
                                                <div class="ml-text-clamp" title="{{ $sRec->location }}">{!! empty($sRec->location) ? '<span class="ml-dash">—</span>' : e($sRec->location) !!}</div>
                                            </td>
                                            <td style="text-align: center;">{!! empty($sub->effective_active) ? '<span class="ml-dash">—</span>' : e($sub->effective_active) !!}</td>
                                            <td style="text-align: center;">{!! empty($sub->effective_storage) ? '<span class="ml-dash">—</span>' : e($sub->effective_storage) !!}</td>
                                            <td style="text-align: center;">{{ $sub->is_retention_period_permanent ? 'PERMANENT' : ($sub->effective_total ?: '—') }}</td>
                                            <td style="text-align: center; font-size: 11.5px; color: #475569;">
                                                @php
                                                    $sDispProv = $sRec->disposition_provision ?? ($sub->remarks ?? $root->remarks);
                                                @endphp
                                                @if(!empty($sDispProv))
                                                    <div class="ml-text-clamp" style="max-width: 220px; margin: 0 auto;" title="{{ $sDispProv }}">{{ $sDispProv }}</div>
                                                @else
                                                    <span class="ml-dash">—</span>
                                                @endif
                                            </td>
                                            <td style="text-align: center; font-size: 11.5px; font-weight: 600; color: #334155;">
                                                {!! empty($sRec->formatted_action_date) || $sRec->formatted_action_date === '—' ? '<span class="ml-dash">—</span>' : e($sRec->formatted_action_date) !!}
                                            </td>
                                            <td style="text-align: center;">
                                                @if($sRec->status === 'revised')
                                                    <span class="ml-badge ml-badge-revised">Revised</span>
                                                @elseif($sRec->status === 'disposed')
                                                    <span class="ml-badge ml-badge-disposed">Disposed</span>
                                                @elseif($sRec->status === 'expired')
                                                    <span class="ml-badge ml-badge-expired">Expired</span>
                                                @elseif($sRec->status === 'pending_rds')
                                                    <span class="ml-badge ml-badge-pending">Pending RDS</span>
                                                @else
                                                    <span class="ml-badge ml-badge-active">Active</span>
                                                @endif
                                            </td>
                                            <td style="text-align: center; font-size: 11px;">{{ $sub->recorded_at_office ?: 'ALL' }}</td>
                                            <td style="text-align: center;">
                                                <button type="button" 
                                                        wire:click="openRecordModal({{ $sRec->id }})" 
                                                        class="ml-action-btn" 
                                                        style="padding: 3px 8px; font-size: 11px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 700; color: #1e293b; cursor: pointer;" 
                                                        title="View & Edit Action Date">
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="15" style="padding: 48px; text-align: center; color: #64748b;">
                                <div style="font-size: 14px; font-weight: 700; color: #334155; margin-bottom: 4px;">No records match your criteria.</div>
                                <div style="font-size: 12px;">Try adjusting your search query, status pills, or drawer filters.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Slide-Out Filter Drawer (Offcanvas Concept from DCS Database) -->
    <div class="ml-drawer-overlay" x-show="drawerOpen" x-cloak @click="drawerOpen = false"></div>
    <aside class="ml-drawer-panel" x-show="drawerOpen" x-cloak :style="drawerOpen ? 'transform: translateX(0);' : 'transform: translateX(100%);'">
        <div class="ml-drawer-head">
            <div style="font-size: 15px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                <span>Filter Matrix</span>
            </div>
            <button type="button" @click="drawerOpen = false" class="ml-chevron-btn" style="width: 28px; height: 28px; font-size: 20px; color: #64748b;" title="Close drawer">&times;</button>
        </div>

        <div class="ml-drawer-body">
            @if($hasAllOfficesClearance)
                <div class="ml-filter-group">
                    <label>Recorded Office</label>
                    <select wire:model.live="officeFilter">
                        <option value="">All Offices</option>
                        @foreach($officesList as $off)
                            <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                        @endforeach
                    </select>
                </div>
            @else
                <div class="ml-filter-group">
                    <label>Recorded Office</label>
                    <div style="padding: 9px 12px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13px; font-weight: 700; color: #1e293b;">
                        Office: {{ $userOfficeCode ?? 'N/A' }}
                    </div>
                </div>
            @endif

            <div class="ml-filter-group">
                <label>Retention Classification</label>
                <select wire:model.live="retentionFilter">
                    <option value="all">All Retention Types</option>
                    <option value="permanent">Permanent Retention Only</option>
                    <option value="temporary">Temporary Retention Only</option>
                </select>
            </div>

            <div class="ml-filter-group">
                <label>Records Medium</label>
                <select wire:model.live="mediumFilter">
                    <option value="">All Mediums</option>
                    @foreach($mediumsList as $med)
                        <option value="{{ $med->id }}">{{ $med->medium_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ml-filter-group">
                <label>Date Covered From</label>
                <input type="text" wire:model.live.debounce.300ms="dateFrom" placeholder="e.g. 2020 or 2020-01-01">
            </div>

            <div class="ml-filter-group">
                <label>Date Covered To</label>
                <input type="text" wire:model.live.debounce.300ms="dateTo" placeholder="e.g. 2024 or 2024-12-31">
            </div>
        </div>

        <div class="ml-drawer-foot">
            <button type="button" wire:click="clearFilters" @click="drawerOpen = false" class="ml-drawer-btn-reset">Reset</button>
            <button type="button" @click="drawerOpen = false" class="ml-drawer-btn-apply">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Apply Filters</span>
            </button>
        </div>
    </aside>

    <!-- GENERATE FORM MODAL -->
    @if($showGenerateModal)
        <div class="ml-modal-overlay" wire:click.self="closeGenerateFormModal">
            <div class="ml-modal-dialog">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 800; color: inherit;">Generate Report Form</h3>
                        <p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">Select an official NAP Form type to package into Pending.</p>
                    </div>
                    <button type="button" wire:click="closeGenerateFormModal" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center; padding: 4px; border-radius: 6px;" title="Close Modal">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                @if($generateSuccessMessage)
                    <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 14px 16px; font-size: 13px; color: #166534; font-weight: 600; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                            <span>{{ $generateSuccessMessage }}</span>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 4px;">
                            <a href="{{ route('rdp.pending.list') }}" class="ml-action-btn ml-generate-btn" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 6px 14px; height: 32px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
                                <span>Go to Pending Forms</span>
                            </a>
                            <button type="button" wire:click="closeGenerateFormModal" class="ml-action-btn" style="font-size: 12px; padding: 6px 14px; height: 32px;">Close</button>
                        </div>
                    </div>
                @else
                    @if($generateErrorMessage)
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; font-size: 12.5px; color: #991b1b; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            <span>{{ $generateErrorMessage }}</span>
                        </div>
                    @endif

                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 8px;">Select Form Type</label>
                            <div class="ml-form-type-grid">
                                <!-- NAP Form 1 -->
                                <label class="ml-form-type-card {{ $selectedFormType === 'nap1' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="selectedFormType" value="nap1" style="margin-top: 3px;">
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: inherit;">NAP Form 1 — Records Inventory and Appraisal</div>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">Packages active records from Generalized Records for appraisal, verification, and scheduling.</div>
                                    </div>
                                </label>

                                <!-- NAP Form 2 -->
                                <label class="ml-form-type-card {{ $selectedFormType === 'nap2' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="selectedFormType" value="nap2" style="margin-top: 3px;">
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: inherit;">NAP Form 2 — Records Disposition Schedule (RDS)</div>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">Packages record series with retention rules into an RDS submission schedule.</div>
                                    </div>
                                </label>

                                <!-- NAP Form 3 -->
                                <label class="ml-form-type-card {{ $selectedFormType === 'nap3' ? 'active' : '' }}">
                                    <input type="radio" wire:model.live="selectedFormType" value="nap3" style="margin-top: 3px;">
                                    <div>
                                        <div style="font-size: 13px; font-weight: 700; color: inherit;">NAP Form 3 — Request for Authority to Dispose of Records</div>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">Packages expired and disposed records into a formal disposal authority request.</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        @if($hasAllOfficesClearance)
                            <div>
                                <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Target Office</label>
                                <select wire:model.live="formOffice" class="ml-search-input" style="width: 100%; padding: 8px 12px; font-size: 13px;">
                                    <option value="">All Filtered Offices / Campus-wide</option>
                                    @foreach($officesList as $off)
                                        <option value="{{ $off->office_code }}">{{ $off->office_name }} ({{ $off->office_code }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <div style="font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 6px;">
                                <span style="font-weight: 700;">Office:</span>
                                <span>{{ $userOfficeCode ?? 'N/A' }}</span>
                            </div>
                        @endif

                        <div>
                            <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 4px;">Form / Cluster Title</label>
                            <input type="text" wire:model="formClusterName" class="ml-search-input" style="width: 100%; padding: 8px 12px; font-size: 13px;" placeholder="Enter submission form title...">
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                            <button type="button" wire:click="closeGenerateFormModal" class="ml-action-btn" style="padding: 8px 16px;">Cancel</button>
                            <button type="button" wire:click="submitGenerateForm" class="ml-action-btn ml-generate-btn" style="padding: 8px 18px; display: inline-flex; align-items: center; gap: 6px;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                <span>Save to Pending</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Record Details & Action Date Modal -->
    @if($showRecordModal && $selectedRecord)
        <div class="ml-modal-overlay" wire:click.self="closeRecordModal">
            <div class="ml-modal-dialog" style="max-width: 540px;">
                <div style="display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <div style="font-size: 16px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: #2563eb;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <span>Record Details & Action Date</span>
                        </div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                            Manage lifecycle actions and update the official disposition action date.
                        </div>
                    </div>
                    <button type="button" wire:click="closeRecordModal" class="ml-chevron-btn" style="width: 28px; height: 28px; font-size: 20px; color: #64748b;" title="Close">&times;</button>
                </div>

                @if(!empty($recordModalSuccess))
                    <div style="padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; font-size: 12.5px; color: #065f46; display: flex; align-items: center; gap: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>{{ $recordModalSuccess }}</span>
                    </div>
                @endif

                @if(!empty($recordModalError))
                    <div style="padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; font-size: 12.5px; color: #991b1b; display: flex; align-items: center; gap: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <span>{{ $recordModalError }}</span>
                    </div>
                @endif

                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <!-- Record Info Summary -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12px;">
                        <div>
                            <span style="color: #64748b; font-weight: 600; display: block; font-size: 11px;">ITEM & SERIES TITLE</span>
                            <span style="font-weight: 700; color: #0f172a;">
                                {{ $selectedRecord->item_number ? $selectedRecord->item_number . ' - ' : '' }}{{ $selectedRecord->series_title ?? 'N/A' }}
                            </span>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600; display: block; font-size: 11px;">CURRENT STATUS</span>
                            <span style="display: inline-block; margin-top: 2px;">
                                @if($selectedRecord->status === 'revised')
                                    <span class="ml-badge ml-badge-revised">Revised</span>
                                @elseif($selectedRecord->status === 'disposed')
                                    <span class="ml-badge ml-badge-disposed">Disposed</span>
                                @elseif($selectedRecord->status === 'expired')
                                    <span class="ml-badge ml-badge-expired">Expired</span>
                                @elseif($selectedRecord->status === 'pending_rds')
                                    <span class="ml-badge ml-badge-pending">Pending RDS</span>
                                @else
                                    <span class="ml-badge ml-badge-active">Active</span>
                                @endif
                            </span>
                        </div>
                        <div style="grid-column: span 2;">
                            <span style="color: #64748b; font-weight: 600; display: block; font-size: 11px;">RECORD DESCRIPTION</span>
                            <span style="font-weight: 600; color: #1e293b;">{{ $selectedRecord->description ?? '—' }}</span>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600; display: block; font-size: 11px;">PERIOD COVERED</span>
                            <span style="font-weight: 600; color: #1e293b;">{{ $selectedRecord->date_covered ?? '—' }}</span>
                        </div>
                        <div>
                            <span style="color: #64748b; font-weight: 600; display: block; font-size: 11px;">VOLUME / MEDIUM</span>
                            <span style="font-weight: 600; color: #1e293b;">
                                {{ $selectedRecord->volume ?? '—' }} / {{ $selectedRecord->medium_name ?? '—' }}
                            </span>
                        </div>
                        <div style="grid-column: span 2;">
                            <span style="color: #64748b; font-weight: 600; display: block; font-size: 11px;">DISPOSITION PROVISION (REMARKS)</span>
                            <span style="font-weight: 500; color: #334155;">{{ $selectedRecord->disposition_provision ?: '—' }}</span>
                        </div>
                    </div>

                    <!-- Action Date Input -->
                    <div>
                        <label style="font-size: 12.5px; font-weight: 700; color: #334155; display: block; margin-bottom: 6px;">
                            Action Date
                        </label>
                        <input type="date" 
                               wire:model="editActionDate" 
                               class="ml-search-input" 
                               style="width: 100%; padding: 8px 12px; font-size: 13px; font-weight: 600; color: #1e293b;">
                        <span style="font-size: 11px; color: #64748b; margin-top: 4px; display: block;">
                            Official date of disposition or lifecycle update. Leave blank to clear.
                        </span>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 8px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                        <button type="button" wire:click="closeRecordModal" class="ml-action-btn" style="padding: 8px 16px;">
                            Close
                        </button>
                        <button type="button" wire:click="saveRecordActionDate" class="ml-action-btn ml-generate-btn" style="padding: 8px 18px; display: inline-flex; align-items: center; gap: 6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                            <span>Save Action Date</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

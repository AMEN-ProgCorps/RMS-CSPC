<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Pending List')] class extends Component {
    public string $search = '';
    public string $activeTab = 'all'; // 'all', 'nap1', 'nap2', 'nap3'
    public string $statusFilter = '';
    public string $layoutMode = 'table'; // 'table' or 'box'

    // Detail Modal Properties
    public bool $showDetailModal = false;
    public ?object $selectedCluster = null;
    public array $clusterItems = [];

    // Print Modal & Fill-up Properties
    public bool $showPrintModal = false;
    public bool $includeDescriptionOnPrint = false;
    public ?object $printCluster = null;
    public array $printItems = [];
    public string $agencyName = 'Camarines Sur Polytechnic Colleges';
    public string $departmentDivision = 'Office of the President';
    public string $sectionUnit = 'Records Management Unit';
    public string $telephoneNumber = '(054) 288-1534 loc. 113';
    public string $emailAddress = 'records@cspc.edu.ph';
    public string $agencyAddress = 'San Miguel, Nabua, Camarines Sur';
    public string $personInCharge = 'Gennica Aprille S. Penetrante';
    public string $datePrepared = '';

    // Signature Block Fields
    public string $preparedBy = 'Gennica Aprille S. Penetrante';
    public string $preparedPosition = 'Administrative Officer V / Records Officer';
    public string $assistedBy = '';
    public string $assistedPosition = 'NAP Records Management Analyst';
    public string $recommendingBy = '';
    public string $recommendingPosition = 'Vice President for Administration';
    public string $approvedBy = '';
    public string $approvedPosition = 'Chief of Division / Department Head';
    public string $committeeChairmanName = '';
    public string $committeeChairmanTitle = 'Chairman, Records Management Committee';
    public string $executiveDirectorName = '';
    public string $executiveDirectorTitle = 'Executive Director, National Archives of the Philippines';

    // Print Config & Hierarchy
    public string $rdpPrintFontFamily = 'Arial, sans-serif';
    public string $rdpPrintFontSize = '8.5pt';
    public array $printHierarchy = [];
    public string $effectivePrintLocation = '';
    public string $effectivePrintVolume = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }

        $details = Auth::user()?->details;
        if ($details) {
            $fullName = trim(($details->first_name ?? '') . ' ' . ($details->last_name ?? ''));
            $this->preparedBy = $fullName ?: (Auth::user()->username ?? 'Records Officer');
        }

        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $fontFam = DB::table($sysTable)->where('key', 'rdp_print_font_family')->value('value');
        if ($fontFam) $this->rdpPrintFontFamily = $fontFam;
        $fontSize = DB::table($sysTable)->where('key', 'rdp_print_font_size')->value('value');
        if ($fontSize) $this->rdpPrintFontSize = $fontSize;
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->search = '';
        $this->statusFilter = '';
    }

    public function openDetailModal(int $clusterId, string $formType): void
    {
        $cluster = null;
        $items = [];

        if ($formType === 'nap2' || $formType === 'NAP Form 2') {
            $cluster = DB::table('rdp_pending_record_series')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_pending_record_series.office', '=', 'office.office_code')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details', 'rdp_pending_record_series.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record_series.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code")
                ])
                ->first();

            if ($cluster) {
                $items = DB::table('rdp_grouped_record_series')
                    ->join('rdp_record_series', 'rdp_grouped_record_series.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                    ->where('rdp_grouped_record_series.group_head', $clusterId)
                    ->select([
                        'rdp_record_series.*',
                        'rdp_retention_period.active_period',
                        'rdp_retention_period.storage_period',
                        'rdp_retention_period.total_period',
                        'parent.series_title as parent_title'
                    ])
                    ->get()
                    ->toArray();
            }
        } else {
            $cluster = DB::table('rdp_pending_record')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as office', 'rdp_pending_record.office', '=', 'office.office_code')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details') . ' as account_details', 'rdp_pending_record.created_by', '=', 'account_details.account_id')
                ->where('rdp_pending_record.cluster_id', $clusterId)
                ->select([
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    'office.office_name',
                    DB::raw("CONCAT(account_details.first_name, ' ', account_details.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code")
                ])
                ->first();

            if ($cluster) {
                $items = DB::table('rdp_grouped_record')
                    ->join('rdp_record', 'rdp_grouped_record.record_id', '=', 'rdp_record.id')
                    ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
                    ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                    ->leftJoin('rdp_recorded_value', 'rdp_record.records_medium', '=', 'rdp_recorded_value.id')
                    ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                    ->where('rdp_grouped_record.group_head', $clusterId)
                    ->select([
                        'rdp_record.*',
                        'rdp_record_series.series_title',
                        'rdp_record_series.item_number',
                        'rdp_record_series.remarks',
                        'rdp_retention_period.active_period',
                        'rdp_retention_period.storage_period',
                        'rdp_retention_period.total_period',
                        'rdp_recorded_value.medium_name',
                        'parent.series_title as parent_title'
                    ])
                    ->get()
                    ->toArray();

                $recIds = array_column($items, 'id');
                $periods = empty($recIds) ? collect() : DB::table('rdp_period_covered')
                    ->whereIn('period_owner', $recIds)
                    ->orderBy('id', 'asc')
                    ->get()
                    ->groupBy('period_owner');

                $holders = array_filter(array_column($items, 'utility_value'));
                $utilities = empty($holders) ? collect() : DB::table('rdp_utility_manager')
                    ->join('rdp_utility_medium', 'rdp_utility_manager.utility_medium', '=', 'rdp_utility_medium.id')
                    ->whereIn('rdp_utility_manager.record_holder', $holders)
                    ->where('rdp_utility_manager.is_active', true)
                    ->select('rdp_utility_manager.record_holder', 'rdp_utility_medium.utility_name')
                    ->get()
                    ->groupBy('record_holder');

                $dupHolders = array_filter(array_column($items, 'duplication_id'));
                $duplications = empty($dupHolders) ? collect() : DB::table('rdp_duplication_section')
                    ->whereIn('dup_id_manager', $dupHolders)
                    ->select('dup_id_manager', 'office_code')
                    ->get()
                    ->groupBy('dup_id_manager');

                $uMap = ['Administrative' => 'Adm', 'Archival' => 'Arc', 'Fiscal' => 'F', 'Legal' => 'L'];
                foreach ($items as $it) {
                    if (isset($periods[$it->id])) {
                        $pList = [];
                        foreach ($periods[$it->id] as $pRow) {
                            if (!empty($pRow->date_covered)) {
                                $pList[] = $pRow->date_covered;
                            }
                        }
                        $it->period_covered = !empty($pList) ? implode(', ', array_unique($pList)) : '—';
                    } else {
                        $it->period_covered = '—';
                    }

                    if (!empty($it->utility_value) && isset($utilities[$it->utility_value])) {
                        $uNames = $utilities[$it->utility_value]->pluck('utility_name');
                        $abbrs = $uNames->map(fn($n) => $uMap[$n] ?? $n)->unique()->values()->all();
                        $it->utility_name_display = !empty($abbrs) ? implode(', ', $abbrs) : ($it->time_value === 'P' ? 'Arc' : 'Adm');
                    } else {
                        $it->utility_name_display = ($it->time_value === 'P' ? 'Arc' : 'Adm');
                    }

                    if (!empty($it->duplication_id) && isset($duplications[$it->duplication_id])) {
                        $it->duplication = implode(', ', $duplications[$it->duplication_id]->pluck('office_code')->unique()->values()->all());
                    } else {
                        $it->duplication = '—';
                    }
                }
            }
        }

        if ($cluster) {
            $this->selectedCluster = $cluster;
            $this->clusterItems = $items;
            $this->showDetailModal = true;
        }
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedCluster = null;
        $this->clusterItems = [];
    }

    public function openPrintModal(int $clusterId, string $formType): void
    {
        $this->openDetailModal($clusterId, $formType);
        $this->printCluster = $this->selectedCluster;
        $this->printItems = $this->clusterItems;

        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $this->includeDescriptionOnPrint = (\Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'rdp_include_description_on_print')->value('value') === 'true');
        $this->rdpPrintFontFamily = \Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'rdp_print_font_family')->value('value') ?: 'Arial, sans-serif';
        $this->rdpPrintFontSize = \Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'rdp_print_font_size')->value('value') ?: '8.5pt';

        if ($this->printCluster) {
            if (!empty($this->printCluster->office_name)) {
                $this->agencyName = $this->printCluster->office_name;
            }
            if (!empty($this->printCluster->submitter_name)) {
                $this->personInCharge = $this->printCluster->submitter_name;
                $this->preparedBy = $this->printCluster->submitter_name;
            }
            $this->datePrepared = \Carbon\Carbon::parse($this->printCluster->created_at ?? now())->format('m/d/Y');
        }

        $formCode = strtolower($this->printCluster->form_code ?? '');
        $formLabel = strtolower($this->printCluster->form_label ?? '');
        $isNap2 = $formCode === 'nap2' || str_contains($formLabel, 'form 2');
        $isNap3 = $formCode === 'nap3' || str_contains($formLabel, 'form 3');

        if ($isNap2) {
            $this->datePrepared = \Carbon\Carbon::parse($this->printCluster->created_at ?? now())->format('F d, Y');
            $this->printItems = $this->buildNap2Tree($this->clusterItems);
        } else {
            $treeData = $this->buildNapRecordTree($clusterId, $isNap3);
            $this->printHierarchy = $treeData['tree'];
            $this->effectivePrintLocation = $treeData['location'];
            $this->effectivePrintVolume = $treeData['volume'];
            if ($isNap3) {
                $this->datePrepared = \Carbon\Carbon::parse($this->printCluster->created_at ?? now())->format('F d, Y');
            }
        }

        $this->showPrintModal = true;
    }

    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printCluster = null;
        $this->printItems = [];
        $this->printHierarchy = [];
        $this->effectivePrintLocation = '';
        $this->effectivePrintVolume = '';
    }

    private function resolveEffectiveRetention(array $allSeriesMap, object $record): object
    {
        $current = $record;
        $visited = [];

        while ($current) {
            $hasActive = !empty(trim($current->active_period ?? ''));
            $hasStorage = !empty(trim($current->storage_period ?? ''));
            $hasTotal = !empty(trim($current->total_period ?? ''));
            $isPerm = (bool)($current->is_retention_period_permanent ?? false)
                || (strtolower(trim($current->total_period ?? '')) === 'permanent');

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

    private function buildTreeHierarchy(array $records): array
    {
        $allParentIds = [];
        foreach ($records as $r) {
            if (!empty($r->parent_id)) {
                $allParentIds[] = (int)$r->parent_id;
            }
        }

        $recordsById = [];
        foreach ($records as $r) {
            $recordsById[(int)$r->id] = $r;
        }

        $missingParentIds = array_diff(array_unique($allParentIds), array_keys($recordsById));
        if (!empty($missingParentIds)) {
            $missingParents = DB::table('rdp_record_series')
                ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                ->leftJoin('rdp_record_series as parent', 'rdp_record_series.parent_id', '=', 'parent.id')
                ->select([
                    'rdp_record_series.*',
                    'rdp_retention_period.active_period',
                    'rdp_retention_period.storage_period',
                    'rdp_retention_period.total_period',
                    'parent.series_title as parent_title',
                ])
                ->whereIn('rdp_record_series.id', $missingParentIds)
                ->get();

            foreach ($missingParents as $mp) {
                $mp->is_parent_context = true;
                $recordsById[(int)$mp->id] = $mp;
            }
        }

        $allRecords = array_values($recordsById);
        $byParent = [];
        foreach ($allRecords as $r) {
            $pId = (int)($r->parent_id ?? 0);
            $byParent[$pId][] = $r;
        }

        $ordered = [];
        $flatten = function ($parentId, $depth, $rootId) use (&$flatten, &$ordered, $byParent) {
            if (!isset($byParent[$parentId])) {
                return;
            }
            foreach ($byParent[$parentId] as $item) {
                $item->depth = $depth;
                $currentRootId = ($depth === 0) ? (int)$item->id : (int)$rootId;
                $item->root_id = $currentRootId;
                $item->has_children = isset($byParent[$item->id]) && count($byParent[$item->id]) > 0;
                $ordered[] = $item;
                $flatten((int)$item->id, $depth + 1, $currentRootId);
            }
        };

        $flatten(0, 0, null);

        $addedIds = array_column($ordered, 'id');
        foreach ($allRecords as $r) {
            if (!in_array($r->id, $addedIds, true)) {
                $r->depth = 0;
                $r->root_id = (int)$r->id;
                $r->has_children = isset($byParent[$r->id]) && count($byParent[$r->id]) > 0;
                $ordered[] = $r;
            }
        }

        return $ordered;
    }

    private function buildNap2Tree(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $allFetchedMap = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period'
            ])
            ->get()
            ->keyBy('id')
            ->all();

        $treeOrdered = $this->buildTreeHierarchy($items);

        foreach ($treeOrdered as $item) {
            $eff = $this->resolveEffectiveRetention($allFetchedMap, $item);
            $item->effective_active = $eff->active_period;
            $item->effective_storage = $eff->storage_period;
            $item->effective_total = $eff->total_period;
            $item->effective_is_permanent = $eff->is_retention_period_permanent;
            $item->is_inherited = $eff->inherited;
            $item->is_root_parent = empty($item->parent_id);

            $isRegistered = (bool)($item->is_verified ?? false);
            if ($isRegistered && !empty($item->item_number)) {
                $item->display_item_no = (string)$item->item_number;
            } else {
                $item->display_item_no = '';
            }
        }

        return $treeOrdered;
    }

    private function compilePeriodCovered(array $dates): string
    {
        $years = [];
        $rawList = [];
        foreach ($dates as $d) {
            $d = trim((string)$d);
            if (empty($d) || $d === '—') continue;
            if (preg_match('/(19\d\d|20\d\d)/', $d, $m)) {
                $years[] = (int)$m[1];
            } else {
                $rawList[] = $d;
            }
        }
        $years = array_values(array_unique($years));
        rsort($years);

        if (empty($years)) {
            return !empty($rawList) ? implode(', ', array_unique($rawList)) : '—';
        }

        $groups = [];
        $currentGroup = [];
        foreach ($years as $y) {
            if (empty($currentGroup)) {
                $currentGroup[] = $y;
            } else {
                $last = end($currentGroup);
                if ($last - 1 === $y) {
                    $currentGroup[] = $y;
                } else {
                    $groups[] = $currentGroup;
                    $currentGroup = [$y];
                }
            }
        }
        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        $formattedGroups = [];
        foreach ($groups as $grp) {
            if (count($grp) >= 2) {
                $formattedGroups[] = $grp[0] . '-' . end($grp);
            } else {
                $formattedGroups[] = (string)$grp[0];
            }
        }

        $res = implode(', ', $formattedGroups);
        if (!empty($rawList)) {
            $res .= ', ' . implode(', ', array_unique($rawList));
        }
        return $res;
    }

    private function compileVolume(array $volumes): string
    {
        $totals = [];
        $unmatched = [];

        foreach ($volumes as $v) {
            $v = trim((string)$v);
            if (empty($v) || $v === '—') continue;

            $parts = preg_split('/[,+&]|\band\b/i', $v);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) continue;

                if (preg_match('/^(\d+(?:\.\d+)?)\s*([a-zA-Z\s\.]+)/', $part, $m)) {
                    $amount = (float)$m[1];
                    $unit = strtolower(trim($m[2]));
                    if (str_starts_with($unit, 'paper') || str_starts_with($unit, 'sheet') || str_starts_with($unit, 'page')) {
                        $normUnit = 'papers';
                    } elseif (str_starts_with($unit, 'folder')) {
                        $normUnit = 'folders';
                    } elseif (str_starts_with($unit, 'box')) {
                        $normUnit = 'boxes';
                    } elseif (str_starts_with($unit, 'bundle')) {
                        $normUnit = 'bundles';
                    } elseif (str_starts_with($unit, 'cu') || str_contains($unit, 'meter') || str_contains($unit, 'm.')) {
                        $normUnit = 'cu. m.';
                    } else {
                        $normUnit = $unit;
                    }
                    $totals[$normUnit] = ($totals[$normUnit] ?? 0) + $amount;
                } else {
                    $unmatched[] = $part;
                }
            }
        }

        $compiledParts = [];
        $preferredOrder = ['folders', 'boxes', 'papers', 'bundles', 'cu. m.'];
        foreach ($preferredOrder as $u) {
            if (isset($totals[$u])) {
                $cnt = $totals[$u];
                if ($u === 'folders') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'folder' : 'folders');
                } elseif ($u === 'papers') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'paper' : 'papers');
                } elseif ($u === 'boxes') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'box' : 'boxes');
                } elseif ($u === 'bundles') {
                    $compiledParts[] = $cnt . ' ' . ($cnt == 1 ? 'bundle' : 'bundles');
                } else {
                    $compiledParts[] = $cnt . ' ' . $u;
                }
                unset($totals[$u]);
            }
        }
        foreach ($totals as $u => $cnt) {
            $compiledParts[] = $cnt . ' ' . $u;
        }
        foreach (array_unique($unmatched) as $um) {
            $compiledParts[] = $um;
        }

        return !empty($compiledParts) ? implode(', ', $compiledParts) : '—';
    }

    private function compileLocation(array $locations): string
    {
        $locs = [];
        foreach ($locations as $l) {
            $l = trim((string)$l);
            if (!empty($l) && $l !== '—') {
                $locs[] = $l;
            }
        }
        $unique = array_values(array_unique($locs));
        if (empty($unique)) return '—';

        $hasCabinet = true;
        $cabSub = [];
        foreach ($unique as $item) {
            if (preg_match('/^Cabinet\s+(.+)$/i', $item, $m)) {
                $cabSub[] = $m[1];
            } else {
                $hasCabinet = false;
            }
        }

        if ($hasCabinet && count($cabSub) > 1) {
            return 'Cabinet ' . implode(', ', $cabSub);
        }

        return implode(', ', $unique);
    }

    private function compileTimeValue(array $times): string
    {
        $valid = [];
        foreach ($times as $t) {
            $t = strtoupper(trim((string)$t));
            if (!empty($t) && $t !== '—') {
                $valid[] = $t;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : 'T';
    }

    private function compileUtility(array $utilityNames): string
    {
        $map = [
            'Administrative' => 'A',
            'Archival'       => 'ARC',
            'Fiscal'         => 'F',
            'Legal'          => 'L',
        ];
        $abbrs = [];
        foreach ($utilityNames as $n) {
            $abbrs[] = $map[$n] ?? strtoupper(substr($n, 0, 3));
        }
        $unique = array_values(array_unique($abbrs));
        return !empty($unique) ? implode(', ', $unique) : 'A';
    }

    private function formatItemUtility(array $utilityNames): string
    {
        $map = [
            'Administrative' => 'A - Administrative',
            'Archival'       => 'ARC - ARCHIVAL',
            'Fiscal'         => 'F - FISCAL',
            'Legal'          => 'L - LEGAL',
        ];
        $parts = [];
        foreach ($utilityNames as $n) {
            $parts[] = $map[$n] ?? $n;
        }
        $unique = array_values(array_unique($parts));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileMedium(array $mediums): string
    {
        $valid = [];
        foreach ($mediums as $m) {
            $m = trim((string)$m);
            if (!empty($m) && $m !== '—') {
                $valid[] = $m;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileRestriction(array $restrictions): string
    {
        $valid = [];
        foreach ($restrictions as $r) {
            $r = trim((string)$r);
            if (!empty($r) && $r !== '—') {
                $valid[] = $r;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileFrequency(array $freqs): string
    {
        $valid = [];
        foreach ($freqs as $f) {
            $f = trim((string)$f);
            if (!empty($f) && $f !== '—') {
                $valid[] = $f;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function compileDuplication(array $dups): string
    {
        $valid = [];
        foreach ($dups as $d) {
            $d = trim((string)$d);
            if (!empty($d) && $d !== '—') {
                $valid[] = $d;
            }
        }
        $unique = array_values(array_unique($valid));
        return !empty($unique) ? implode(', ', $unique) : '—';
    }

    private function buildNapRecordTree(int $clusterId, bool $isNap3): array
    {
        $records = DB::table('rdp_grouped_record')
            ->join('rdp_record', 'rdp_grouped_record.record_id', '=', 'rdp_record.id')
            ->where('rdp_grouped_record.group_head', $clusterId)
            ->select('rdp_record.*')
            ->orderBy('rdp_record.id', 'asc')
            ->get();

        if ($records->isEmpty()) {
            return ['tree' => [], 'location' => '', 'volume' => ''];
        }

        $recordIds = $records->pluck('id')->all();

        $periods = DB::table('rdp_period_covered')
            ->whereIn('period_owner', $recordIds)
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('period_owner');

        $utilities = DB::table('rdp_utility_manager')
            ->join('rdp_utility_medium', 'rdp_utility_manager.utility_medium', '=', 'rdp_utility_medium.id')
            ->whereIn('rdp_utility_manager.record_holder', $recordIds)
            ->where('rdp_utility_manager.is_active', true)
            ->select('rdp_utility_manager.record_holder', 'rdp_utility_medium.utility_name')
            ->get()
            ->groupBy('record_holder');

        $dupHolders = array_filter($records->pluck('duplication_id')->all());
        $duplications = empty($dupHolders) ? collect() : DB::table('rdp_duplication_section')
            ->whereIn('dup_id_manager', $dupHolders)
            ->select('dup_id_manager', 'office_code')
            ->get()
            ->groupBy('dup_id_manager');

        $mediumsMap = DB::table('rdp_recorded_value')->pluck('medium_name', 'id')->all();

        $recordsBySeries = $records->groupBy('record_series_id');
        $directSeriesIds = array_keys($recordsBySeries->all());

        $directSeries = DB::table('rdp_record_series')
            ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
            ->whereIn('rdp_record_series.id', $directSeriesIds)
            ->select([
                'rdp_record_series.*',
                'rdp_retention_period.active_period',
                'rdp_retention_period.storage_period',
                'rdp_retention_period.total_period',
            ])
            ->get();

        $neededParentIds = array_unique(array_filter($directSeries->pluck('parent_id')->all()));
        $parentSeries = collect();
        if (!empty($neededParentIds)) {
            $parentSeries = DB::table('rdp_record_series')
                ->leftJoin('rdp_retention_period', 'rdp_record_series.retention_period', '=', 'rdp_retention_period.id')
                ->whereIn('rdp_record_series.id', $neededParentIds)
                ->select([
                    'rdp_record_series.*',
                    'rdp_retention_period.active_period',
                    'rdp_retention_period.storage_period',
                    'rdp_retention_period.total_period',
                ])
                ->get();
        }

        $allRoots = $parentSeries->concat($directSeries->whereNull('parent_id'))->unique('id');
        $subSeriesByParent = $directSeries->whereNotNull('parent_id')->groupBy('parent_id');

        $sortedRoots = $allRoots->sortBy(function($root) use ($subSeriesByParent, $recordsBySeries) {
            $minId = PHP_INT_MAX;
            if (isset($recordsBySeries[$root->id])) {
                $minId = min($minId, $recordsBySeries[$root->id]->min('id'));
            }
            if (isset($subSeriesByParent[$root->id])) {
                foreach ($subSeriesByParent[$root->id] as $sub) {
                    if (isset($recordsBySeries[$sub->id])) {
                        $minId = min($minId, $recordsBySeries[$sub->id]->min('id'));
                    }
                }
            }
            return $minId;
        })->values();

        $tree = [];
        $allLocs = [];
        $allVols = [];

        foreach ($sortedRoots as $root) {
            $rootNode = (object)[
                'id'             => $root->id,
                'item_number'    => ((bool)($root->is_verified ?? false) && !empty($root->item_number)) ? $root->item_number : '',
                'series_title'   => $root->series_title,
                'remarks'        => $root->remarks ?? '',
                'sub_series'     => [],
                'direct_records' => [],
                'has_children'   => false,
            ];

            $subs = $subSeriesByParent[$root->id] ?? collect();

            if ($subs->isNotEmpty()) {
                $rootNode->has_children = true;
                $sortedSubs = $subs->sortBy(function($sub) use ($recordsBySeries) {
                    return isset($recordsBySeries[$sub->id]) ? $recordsBySeries[$sub->id]->min('id') : PHP_INT_MAX;
                })->values();

                foreach ($sortedSubs as $sub) {
                    $subRecs = $recordsBySeries[$sub->id] ?? collect();
                    if ($subRecs->isEmpty()) continue;

                    $compiledDates = [];
                    $compiledVols = [];
                    $compiledMediums = [];
                    $compiledRestrictions = [];
                    $compiledLocs = [];
                    $compiledFreqs = [];
                    $compiledDups = [];
                    $compiledTimes = [];
                    $compiledUtils = [];
                    $childItems = [];

                    foreach ($subRecs as $rec) {
                        $pRow = $periods[$rec->id]->first() ?? null;
                        $rawDate = $pRow->date_covered ?? '';
                        $uRows = ($utilities[$rec->id] ?? collect())->pluck('utility_name')->all();

                        $recMedium = '—';
                        if (!empty($rec->records_medium)) {
                            $recMedium = $mediumsMap[$rec->records_medium] ?? (string)$rec->records_medium;
                        }

                        $recRestriction = !empty($rec->restriction) ? $rec->restriction : '—';
                        $recFreq = !empty($rec->frequence_use) ? $rec->frequence_use : '—';

                        if (!empty($rec->duplication_id) && isset($duplications[$rec->duplication_id])) {
                            $dupCodes = $duplications[$rec->duplication_id]->pluck('office_code')->unique()->values()->all();
                            $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '—';
                        } else {
                            $recDup = '—';
                        }

                        $compiledDates[] = $rawDate;
                        $compiledVols[] = $rec->volume;
                        $compiledMediums[] = $recMedium;
                        $compiledRestrictions[] = $recRestriction;
                        $compiledLocs[] = $rec->records_location;
                        $compiledFreqs[] = $recFreq;
                        $compiledDups[] = $recDup;
                        $compiledTimes[] = $rec->time_value;
                        foreach ($uRows as $un) $compiledUtils[] = $un;

                        $allLocs[] = $rec->records_location;
                        $allVols[] = $rec->volume;

                        $childItems[] = (object)[
                            'id'            => $rec->id,
                            'description'   => $rec->description,
                            'date_covered'  => $rawDate ?: '—',
                            'volume'        => $rec->volume ?: '—',
                            'medium'        => $recMedium,
                            'restriction'   => $recRestriction,
                            'location'      => $rec->records_location ?: '—',
                            'frequence_use' => $recFreq,
                            'duplication'   => $recDup,
                            'time_value'    => $rec->time_value ?: 'T',
                            'utility'       => $this->formatItemUtility($uRows),
                        ];
                    }

                    $isPerm = strtolower(trim($sub->total_period ?? '')) === 'permanent' || strtolower(trim($sub->active_period ?? '')) === 'permanent';

                    $subNode = (object)[
                        'id'                   => $sub->id,
                        'series_title'         => $sub->series_title,
                        'compiled_period'      => $this->compilePeriodCovered($compiledDates),
                        'compiled_volume'      => $this->compileVolume($compiledVols),
                        'compiled_medium'      => $this->compileMedium($compiledMediums),
                        'compiled_restriction' => $this->compileRestriction($compiledRestrictions),
                        'compiled_location'    => $this->compileLocation($compiledLocs),
                        'compiled_freq'        => $this->compileFrequency($compiledFreqs),
                        'compiled_duplication' => $this->compileDuplication($compiledDups),
                        'compiled_time'        => $this->compileTimeValue($compiledTimes),
                        'compiled_util'        => $this->compileUtility($compiledUtils),
                        'active_period'        => $isPerm ? 'PERMANENT' : ($sub->active_period ?: '—'),
                        'storage_period'       => $isPerm ? '' : ($sub->storage_period ?: ''),
                        'total_period'         => $isPerm ? 'PERMANENT' : ($sub->total_period ?: '—'),
                        'is_permanent'         => $isPerm,
                        'remarks'              => $sub->remarks ?? '',
                        'records'              => $childItems,
                        'record_ids'           => array_column($childItems, 'id'),
                    ];

                    $rootNode->sub_series[] = $subNode;
                }
            } else {
                $rootRecs = $recordsBySeries[$root->id] ?? collect();
                if ($rootRecs->isEmpty()) continue;

                $compiledDates = [];
                $compiledVols = [];
                $compiledMediums = [];
                $compiledRestrictions = [];
                $compiledLocs = [];
                $compiledFreqs = [];
                $compiledDups = [];
                $compiledTimes = [];
                $compiledUtils = [];
                $childItems = [];

                foreach ($rootRecs as $rec) {
                    $pRow = $periods[$rec->id]->first() ?? null;
                    $rawDate = $pRow->date_covered ?? '';
                    $uRows = ($utilities[$rec->id] ?? collect())->pluck('utility_name')->all();

                    $recMedium = '—';
                    if (!empty($rec->records_medium)) {
                        $recMedium = $mediumsMap[$rec->records_medium] ?? (string)$rec->records_medium;
                    }

                    $recRestriction = !empty($rec->restriction) ? $rec->restriction : '—';
                    $recFreq = !empty($rec->frequence_use) ? $rec->frequence_use : '—';

                    if (!empty($rec->duplication_id) && isset($duplications[$rec->duplication_id])) {
                        $dupCodes = $duplications[$rec->duplication_id]->pluck('office_code')->unique()->values()->all();
                        $recDup = !empty($dupCodes) ? implode(', ', $dupCodes) : '—';
                    } else {
                        $recDup = '—';
                    }

                    $compiledDates[] = $rawDate;
                    $compiledVols[] = $rec->volume;
                    $compiledMediums[] = $recMedium;
                    $compiledRestrictions[] = $recRestriction;
                    $compiledLocs[] = $rec->records_location;
                    $compiledFreqs[] = $recFreq;
                    $compiledDups[] = $recDup;
                    $compiledTimes[] = $rec->time_value;
                    foreach ($uRows as $un) $compiledUtils[] = $un;

                    $allLocs[] = $rec->records_location;
                    $allVols[] = $rec->volume;

                    $childItems[] = (object)[
                        'id'            => $rec->id,
                        'description'   => $rec->description,
                        'date_covered'  => $rawDate ?: '—',
                        'volume'        => $rec->volume ?: '—',
                        'medium'        => $recMedium,
                        'restriction'   => $recRestriction,
                        'location'      => $rec->records_location ?: '—',
                        'frequence_use' => $recFreq,
                        'duplication'   => $recDup,
                        'time_value'    => $rec->time_value ?: 'T',
                        'utility'       => $this->formatItemUtility($uRows),
                    ];
                }

                $isPerm = strtolower(trim($root->total_period ?? '')) === 'permanent' || strtolower(trim($root->active_period ?? '')) === 'permanent';

                $rootNode->compiled_period      = $this->compilePeriodCovered($compiledDates);
                $rootNode->compiled_volume      = $this->compileVolume($compiledVols);
                $rootNode->compiled_medium      = $this->compileMedium($compiledMediums);
                $rootNode->compiled_restriction = $this->compileRestriction($compiledRestrictions);
                $rootNode->compiled_location    = $this->compileLocation($compiledLocs);
                $rootNode->compiled_freq        = $this->compileFrequency($compiledFreqs);
                $rootNode->compiled_duplication = $this->compileDuplication($compiledDups);
                $rootNode->compiled_time        = $this->compileTimeValue($compiledTimes);
                $rootNode->compiled_util        = $this->compileUtility($compiledUtils);
                $rootNode->active_period        = $isPerm ? 'PERMANENT' : ($root->active_period ?: '—');
                $rootNode->storage_period       = $isPerm ? '' : ($root->storage_period ?: '');
                $rootNode->total_period         = $isPerm ? 'PERMANENT' : ($root->total_period ?: '—');
                $rootNode->is_permanent         = $isPerm;
                $rootNode->remarks              = $root->remarks ?? '';
                $rootNode->direct_records       = $childItems;
                $rootNode->record_ids           = array_column($childItems, 'id');
            }

            if ($rootNode->has_children && empty($rootNode->sub_series)) {
                continue;
            }
            if (!$rootNode->has_children && empty($rootNode->direct_records)) {
                continue;
            }

            $tree[] = $rootNode;
        }

        return [
            'tree'     => $tree,
            'location' => $this->compileLocation($allLocs),
            'volume'   => $this->compileVolume($allVols),
        ];
    }

    public function cleanVal($val): string
    {
        if ($val === null) return '';
        $str = trim((string)$val);
        if ($str === '—' || $str === '-' || $str === 'N/A' || $str === 'None' || $str === 'null') {
            return '';
        }
        return $str;
    }

    public function with(): array
    {
        $statuses = DB::table('rdp_pending_status')->where('is_active', true)->get();
        $clustersCollection = collect();

        $user = Auth::user();
        $perms = $user?->permissions;
        $canViewAll = (bool)($perms?->is_sadm ?? false)
            || (bool)($perms?->is_rdp_view_all_pending_list ?? false)
            || (bool)($perms?->can_access_rdp_admin ?? false)
            || (bool)($perms?->rdp_view_all_files ?? false);
        $userOffice = $user?->details?->office_code ?? null;

        $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        // 1. Fetch NAP Form 2 Series Clusters if tab is 'all' or 'nap2'
        if ($this->activeTab === 'all' || $this->activeTab === 'nap2') {
            $qSeries = DB::table($mainPendingTbl)
                ->join('rdp_pending_record_series', "{$mainPendingTbl}.id", '=', 'rdp_pending_record_series.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record_series.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl, 'rdp_pending_record_series.office', '=', "{$officeTbl}.office_code")
                ->leftJoin($accDetailsTbl, 'rdp_pending_record_series.created_by', '=', "{$accDetailsTbl}.account_id")
                ->select([
                    "{$mainPendingTbl}.id as main_id",
                    'rdp_pending_record_series.cluster_id',
                    'rdp_pending_record_series.cluster_name',
                    'rdp_pending_record_series.status_id',
                    'rdp_pending_record_series.office',
                    'rdp_pending_record_series.created_at',
                    'rdp_pending_status.status_name',
                    "{$officeTbl}.office_name",
                    DB::raw("CONCAT({$accDetailsTbl}.first_name, ' ', {$accDetailsTbl}.last_name) as submitter_name"),
                    DB::raw("'NAP Form 2' as form_label"),
                    DB::raw("'nap2' as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record_series WHERE group_head = rdp_pending_record_series.cluster_id) as total_items")
                ]);

            if (!$canViewAll && $userOffice) {
                $qSeries->where('rdp_pending_record_series.office', $userOffice);
            }

            if (!empty($this->statusFilter)) {
                $qSeries->where('rdp_pending_record_series.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qSeries->where(function($q) use ($term, $officeTbl) {
                    $q->where('rdp_pending_record_series.cluster_name', 'ILIKE', $term)
                      ->orWhere("{$officeTbl}.office_name", 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qSeries->get());
        }

        // 2. Fetch Pending Record Clusters (NAP Form 1 or 3) if tab is 'all', 'nap1', or 'nap3'
        if ($this->activeTab === 'all' || $this->activeTab === 'nap1' || $this->activeTab === 'nap3') {
            $qRec = DB::table($mainPendingTbl)
                ->join('rdp_pending_record', "{$mainPendingTbl}.id", '=', 'rdp_pending_record.cluster_id')
                ->leftJoin('rdp_pending_status', 'rdp_pending_record.status_id', '=', 'rdp_pending_status.id')
                ->leftJoin($officeTbl, 'rdp_pending_record.office', '=', "{$officeTbl}.office_code")
                ->leftJoin($accDetailsTbl, 'rdp_pending_record.created_by', '=', "{$accDetailsTbl}.account_id")
                ->select([
                    "{$mainPendingTbl}.id as main_id",
                    'rdp_pending_record.cluster_id',
                    'rdp_pending_record.cluster_name',
                    'rdp_pending_record.status_id',
                    'rdp_pending_record.office',
                    'rdp_pending_record.is_for_nap_one',
                    'rdp_pending_record.is_for_nap_three',
                    'rdp_pending_record.created_at',
                    'rdp_pending_status.status_name',
                    "{$officeTbl}.office_name",
                    DB::raw("CONCAT({$accDetailsTbl}.first_name, ' ', {$accDetailsTbl}.last_name) as submitter_name"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'NAP Form 3' ELSE 'NAP Form 1' END as form_label"),
                    DB::raw("CASE WHEN rdp_pending_record.is_for_nap_three = true THEN 'nap3' ELSE 'nap1' END as form_code"),
                    DB::raw("(SELECT COUNT(*) FROM rdp_grouped_record WHERE group_head = rdp_pending_record.cluster_id) as total_items")
                ]);

            if (!$canViewAll && $userOffice) {
                $qRec->where(function($sub) use ($userOffice) {
                    $sub->where('rdp_pending_record.office', $userOffice)
                        ->orWhereExists(function($dupQ) use ($userOffice) {
                            $dupQ->select(DB::raw(1))
                                ->from('rdp_grouped_record')
                                ->join('rdp_record', 'rdp_grouped_record.record_id', '=', 'rdp_record.id')
                                ->join('rdp_duplication_section', 'rdp_duplication_section.dup_id_manager', '=', 'rdp_record.duplication_id')
                                ->whereColumn('rdp_grouped_record.group_head', 'rdp_pending_record.cluster_id')
                                ->where('rdp_duplication_section.office_code', $userOffice);
                        });
                });
            }

            if ($this->activeTab === 'nap1') {
                $qRec->where('rdp_pending_record.is_for_nap_one', true);
            } elseif ($this->activeTab === 'nap3') {
                $qRec->where('rdp_pending_record.is_for_nap_three', true);
            }

            if (!empty($this->statusFilter)) {
                $qRec->where('rdp_pending_record.status_id', $this->statusFilter);
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $qRec->where(function($q) use ($term, $officeTbl) {
                    $q->where('rdp_pending_record.cluster_name', 'ILIKE', $term)
                      ->orWhere("{$officeTbl}.office_name", 'ILIKE', $term);
                });
            }

            $clustersCollection = $clustersCollection->concat($qRec->get());
        }

        // Sort by main_id descending
        $sortedClusters = $clustersCollection->sortByDesc('main_id')->values();

        return [
            'clusters' => $sortedClusters,
            'statuses' => $statuses,
        ];
    }
}; ?>

<div class="pending-list-page">
    <style>
        .pending-list-page {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .header-title p {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }
        .controls-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* DTS-Styled Tabs Bar */
        .dts-tab-bar {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
            border: 1px solid #e2e8f0;
        }
        .dts-tab-btn {
            border: none;
            background: transparent;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dts-tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            box-shadow: 0 2px 4px rgba(37,99,235,0.1);
        }

        .layout-toggle-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }
        .layout-toggle-btn:hover { background: #f8fafc; }

        .search-input, .select-filter {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .search-input { width: 240px; }

        /* Table & Card Grid Views */
        .pending-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .pending-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .pending-table th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 16px;
            border-right: 1px solid #334155;
        }
        .pending-table td {
            padding: 14px 16px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-null, .status-1 { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; } /* Pending */
        .status-2 { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* Approved */
        .status-3 { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; } /* Rejected */
        .status-4 { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; } /* Returned */

        .form-type-pill {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 6px;
        }

        .btn-view {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-view:hover { background: #dbeafe; }

        .btn-print {
            background: #1e293b;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print:hover { background: #0f172a; }

        /* Box / Card Grid Layout */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        .cluster-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .cluster-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -2px rgba(0,0,0,0.08);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 4px 0 0 0;
        }
        .card-meta {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        /* Modal Layout */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 12px;
            width: 800px;
            max-width: 94vw;
            max-height: 88vh;
            overflow-y: auto;
            padding: 26px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        }

        /* ==========================================================================
           Pending List - Dark Mode Overrides
           ========================================================================== */
        [data-theme="dark"] .header-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] .header-title h1 {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .header-title p {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dts-tab-bar {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .dts-tab-btn {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .dts-tab-btn.active {
            background: #131c2e !important;
            color: #60a5fa !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4) !important;
        }

        [data-theme="dark"] .layout-toggle-btn {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .search-input,
        [data-theme="dark"] .select-filter {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .pending-table-wrapper {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .pending-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-right-color: #1e293b !important;
            border-bottom: 2px solid #1e293b !important;
        }

        [data-theme="dark"] .pending-table td {
            color: #cbd5e1 !important;
            border-bottom-color: #1e293b !important;
            border-right-color: #1e293b !important;
        }

        [data-theme="dark"] .pending-table tr:hover td {
            background-color: #1a253c !important;
        }

        [data-theme="dark"] .cluster-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] .card-title {
            color: #f8fafc !important;
        }

        [data-theme="dark"] .card-meta {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .card-footer {
            border-top-color: #1e293b !important;
        }

        [data-theme="dark"] .btn-view {
            background: #0f172a !important;
            color: #60a5fa !important;
            border-color: #3b82f6 !important;
        }

        [data-theme="dark"] .btn-view:hover {
            background: #1e293b !important;
        }

        [data-theme="dark"] .modal-card {
            background: #131c2e !important;
            border: 1px solid #1e293b !important;
            color: #cbd5e1 !important;
        }

        /* Print Modal & Media Query Defaults */
        @media print {
            @page {
                size: portrait;
                margin: 10px;
            }

            :root {
                zoom: 1 !important;
            }

            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                position: static !important;
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }

            /* Hide web application layout components (header, sidebar, chatify, etc.) */
            header,
            nav,
            .navigation,
            #navigation,
            .chatify-floating-widget,
            .header-card,
            .controls-group,
            .pending-table-wrapper,
            .card-grid,
            .no-print,
            .modal-card > *:not(.printable-report-area) {
                display: none !important;
            }

            section,
            .article-container,
            #article-container,
            .pending-list-page {
                display: block !important;
                position: static !important;
                float: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
            }

            .modal-overlay {
                position: static !important;
                display: block !important;
                float: none !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
            }

            .modal-card {
                position: static !important;
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                border: none !important;
                box-shadow: none !important;
            }

            .printable-report-area {
                display: block !important;
                visibility: visible !important;
                position: static !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 10px !important;
                box-sizing: border-box !important;
                border: none !important;
                background: #ffffff !important;
                box-shadow: none !important;
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }

            .printable-report-area * {
                visibility: visible !important;
            }

            .printable-report-area table {
                font-size: 12px !important;
                width: 100% !important;
            }

            .printable-report-area thead {
                display: table-header-group !important;
            }

            .printable-report-area tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            .nap-form-2-print,
            .nap-form-2-print *,
            .nap-form-3-print,
            .nap-form-3-print * {
                font-family: Arial, sans-serif !important;
                font-size: 12px !important;
            }

            .nap-form-2-print table,
            .nap-form-3-print table {
                font-size: 12px !important;
            }

            .nap-form-2-print th, .nap-form-2-print td,
            .nap-form-3-print th, .nap-form-3-print td {
                font-size: 12px !important;
                padding: 6px 8px !important;
            }

            .print-signatures-block {
                page-break-before: always !important;
                break-before: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                display: block !important;
            }
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Pending Records & Series List</h1>
            <p>Track office submissions awaiting evaluation and approval for the Records Disposition Program</p>
        </div>
        <div class="controls-group">
            <div class="dts-tab-bar">
                <button wire:click="setTab('all')" class="dts-tab-btn {{ $activeTab === 'all' ? 'active' : '' }}">ALL</button>
                <button wire:click="setTab('nap1')" class="dts-tab-btn {{ $activeTab === 'nap1' ? 'active' : '' }}">NAP Form 1</button>
                <button wire:click="setTab('nap2')" class="dts-tab-btn {{ $activeTab === 'nap2' ? 'active' : '' }}">NAP Form 2</button>
                <button wire:click="setTab('nap3')" class="dts-tab-btn {{ $activeTab === 'nap3' ? 'active' : '' }}">NAP Form 3</button>
            </div>

            <button wire:click="toggleLayout" class="layout-toggle-btn">
                @if($layoutMode === 'table')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Box View
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Table View
                @endif
            </button>

            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search cluster or office...">
        </div>
    </div>

    @if($layoutMode === 'table')
        <div class="pending-table-wrapper">
            <table class="pending-table">
                <thead>
                    <tr>
                        <th style="width: 90px;">Main ID</th>
                        <th>Cluster Name</th>
                        <th>Submitting Office</th>
                        <th>Submitted By</th>
                        <th style="width: 110px; text-align: center;">Total Items</th>
                        <th style="width: 140px;">Date Submitted</th>
                        <th style="width: 160px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clusters as $c)
                        <tr>
                            <td><strong>#{{ $c->main_id }}</strong></td>
                            <td>
                                <span class="form-type-pill">{{ $c->form_label }}</span>
                                <strong>{{ $c->cluster_name }}</strong>
                            </td>
                            <td>{{ $c->office_name ?? $c->office ?? 'N/A' }}</td>
                            <td>{{ $c->submitter_name ?: 'System User' }}</td>
                            <td style="text-align: center;"><strong>{{ $c->total_items }}</strong></td>
                            <td>{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y g:i A') }}</td>
                            <td style="text-align: center;">
                                <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-view">View</button>
                                <button wire:click="openPrintModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-print">Print</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                                No pending clusters found matching your query.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="card-grid">
            @forelse($clusters as $c)
                <div class="cluster-card">
                    <div>
                        <div class="card-header">
                            <div>
                                <span class="form-type-pill">{{ $c->form_label }}</span>
                                <span style="font-size: 12px; font-weight: 700; color: #64748b;">#{{ $c->main_id }}</span>
                            </div>
                        </div>
                        <h3 class="card-title">{{ $c->cluster_name }}</h3>
                        <div class="card-meta">
                            <div><strong>Office:</strong> {{ $c->office_name ?? $c->office ?? 'N/A' }}</div>
                            <div><strong>Submitted by:</strong> {{ $c->submitter_name ?: 'System User' }}</div>
                            <div><strong>Items:</strong> {{ $c->total_items }} records</div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span style="font-size: 12px; color: #94a3b8;">{{ \Carbon\Carbon::parse($c->created_at)->format('M d, Y') }}</span>
                        <div>
                            <button wire:click="openDetailModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-view">View</button>
                            <button wire:click="openPrintModal({{ $c->cluster_id }}, '{{ $c->form_code }}')" class="btn-print">Print</button>
                        </div>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; text-align: center; padding: 48px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; color: #64748b;">
                    No pending clusters found matching your query.
                </div>
            @endforelse
        </div>
    @endif

    {{-- Detail Modal --}}
    @if($showDetailModal && $selectedCluster)
        <div class="modal-overlay">
            <div class="modal-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px;">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin: 0;">
                            [{{ $selectedCluster->form_label }}] {{ $selectedCluster->cluster_name }}
                        </h3>
                        <span style="font-size: 13px; color: #64748b;">Submitted by {{ $selectedCluster->office_name ?? $selectedCluster->office }}</span>
                    </div>
                    <button wire:click="closeDetailModal" style="background: none; border: none; font-size: 20px; color: #64748b; cursor: pointer;">&times;</button>
                </div>
                <div style="margin-bottom: 16px;">
                    <span class="status-badge status-{{ $selectedCluster->status_id ?? 'null' }}">
                        Status: {{ $selectedCluster->status_name ?? 'Draft Group' }}
                    </span>
                </div>
                <table class="pending-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Item / Title</th>
                            <th>Details</th>
                            <th>Retention / Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clusterItems as $item)
                            <tr>
                                <td>
                                    <strong>{{ $item->series_title ?? $item->doc_name ?? 'Untitled Item' }}</strong>
                                    @if(!empty($item->parent_title))
                                        <div style="font-size: 11px; color: #64748b;">Sub-series of: {{ $item->parent_title }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($item->item_number))
                                        <div>Item No: <strong>{{ sprintf('%03d', $item->item_number) }}</strong></div>
                                    @endif
                                    @if(isset($item->volume))
                                        <div>Volume: {{ $item->volume }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if(isset($item->total_period))
                                        <div>Retention: <strong>{{ $item->total_period }}</strong></div>
                                    @endif
                                    <div style="color: #64748b;">{{ $item->remarks ?? $item->description ?? '—' }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Printable Modal --}}
    @if($showPrintModal && $printCluster)
        @php
            $formCode = strtolower($printCluster->form_code ?? '');
            $formLabel = strtolower($printCluster->form_label ?? '');
            $isNap1 = $formCode === 'nap1' || str_contains($formLabel, 'form 1');
            $isNap2 = $formCode === 'nap2' || str_contains($formLabel, 'form 2');
            $isNap3 = $formCode === 'nap3' || str_contains($formLabel, 'form 3');
            $cleanVal = function($val) {
                if ($val === null) return '';
                $str = trim((string)$val);
                if ($str === '—' || $str === '-' || $str === 'N/A' || $str === 'None' || $str === 'null') {
                    return '';
                }
                return $str;
            };
        @endphp
        <style>
            :root {
                --rdp-print-font: {{ $rdpPrintFontFamily ?: 'Arial, sans-serif' }};
                --rdp-print-size: {{ $rdpPrintFontSize ?: '8.5pt' }};
            }
            .print-sheet {
                width: 100%;
                background: #ffffff;
                box-shadow: 0 10px 25px rgba(0,0,0,0.15);
                padding: 24px 28px;
                box-sizing: border-box;
                color: #000000;
                font-family: var(--rdp-print-font), Arial, Helvetica, sans-serif !important;
                font-size: var(--rdp-print-size) !important;
                margin-bottom: 24px;
            }
            .print-sheet table, .print-sheet th, .print-sheet td, .print-sheet div, .print-sheet span, .print-sheet strong {
                font-family: var(--rdp-print-font), Arial, Helvetica, sans-serif !important;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
                border: 2px solid #000000;
                font-size: var(--rdp-print-size) !important;
                margin-top: 0;
                background: #ffffff;
            }
            .print-table th {
                border: 1px solid #000000;
                padding: 4px 2px;
                background: #ffffff;
                text-align: center;
                font-weight: bold;
                font-size: var(--rdp-print-size) !important;
                vertical-align: middle;
                color: #000000;
            }
            .print-table td {
                border-left: 1px solid #000000;
                border-right: 1px solid #000000;
                border-top: none;
                border-bottom: none;
                padding: 3px 4px;
                vertical-align: top;
                font-size: var(--rdp-print-size) !important;
                color: #000000;
                background: #ffffff;
            }
            @media print {
                body { background: #ffffff !important; margin: 0 !important; padding: 0 !important; }
                header, #navigation, .no-print, .modal-overlay > :not(.modal-card), footer { display: none !important; }
                .modal-overlay { position: static !important; background: none !important; padding: 0 !important; display: block !important; }
                .modal-card { background: none !important; max-width: 100% !important; max-height: none !important; padding: 0 !important; box-shadow: none !important; overflow: visible !important; width: 100% !important; border: none !important; }
                .print-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; margin-bottom: 0 !important; page-break-after: always; break-after: page; }
                .print-sheet:last-child { page-break-after: auto; break-after: auto; }
                @page {
                    size: {{ $isNap1 ? 'legal landscape' : 'legal portrait' }};
                    margin: {{ $isNap1 ? '0.4in' : '0.5in' }};
                }
            }
        </style>
        <div class="modal-overlay" style="overflow-y: auto;">
            <div class="modal-card" style="width: {{ $isNap1 ? '1280px' : '980px' }}; max-width: 96%; max-height: 94vh; overflow-y: auto; padding: 24px; background: #94a3b8;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;" class="no-print">
                    <div>
                        <h3 style="font-size: 18px; font-weight: 800; color: #ffffff; margin: 0;">
                            Print Preview — {{ $printCluster->cluster_name }}
                        </h3>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #f1f5f9;">
                            Form: <strong>{{ $printCluster->form_label }}</strong> &bull; Print Font: <strong>{{ $rdpPrintFontFamily }}</strong> (<strong>{{ $rdpPrintFontSize }}</strong>) &bull; Configure details and signatures before printing.
                        </p>
                    </div>
                    <div>
                        <button onclick="window.print()" class="btn-print" style="padding: 8px 18px; margin-right: 8px; font-weight: 700; background: #16a34a; color: #fff; border: none; border-radius: 6px; cursor: pointer;">🖨️ Print Now</button>
                        <button wire:click="closePrintModal" class="btn-view" style="padding: 8px 16px; background: #ffffff; color: #0f172a; font-weight: 700; border: none; border-radius: 6px; cursor: pointer;">✕ Close</button>
                    </div>
                </div>

                @if($isNap1)
                    <!-- FILL-UP FORM PANEL (Fields 1 to 8 + Signatures) - NO-PRINT -->
                    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                        <h4 style="margin: 0 0 14px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            📋 Top Header Configuration (Fields 1 – 8)
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 12px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">1. NAME OF OFFICE</label>
                                <input type="text" wire:model.live="agencyName" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">2. DEPARTMENT / DIVISION</label>
                                <input type="text" wire:model.live="departmentDivision" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">3. SECTION / UNIT</label>
                                <input type="text" wire:model.live="sectionUnit" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">4. TELEPHONE NO.</label>
                                <input type="text" wire:model.live="telephoneNumber" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">5. EMAIL ADDRESS</label>
                                <input type="text" wire:model.live="emailAddress" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">6. ADDRESS</label>
                                <input type="text" wire:model.live="agencyAddress" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">7. PERSON-IN-CHARGE OF FILES</label>
                                <input type="text" wire:model.live="personInCharge" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">8. DATE PREPARED</label>
                                <input type="text" wire:model.live="datePrepared" class="form-control" placeholder="e.g. 08/03/2026" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                        </div>

                        <h4 style="margin: 14px 0 12px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            ✍️ Official Signatures Block
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">PREPARED BY (Name & Position)</label>
                                <input type="text" wire:model.live="preparedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="preparedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; background: #ffffff;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">ASSISTED BY (NAP Analyst)</label>
                                <input type="text" wire:model.live="assistedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="assistedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; background: #ffffff;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">APPROVED BY (Chief/Department Head)</label>
                                <input type="text" wire:model.live="approvedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="approvedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px; background: #ffffff;">
                            </div>
                        </div>
                    </div>
                @elseif($isNap2)
                    <!-- FILL-UP FORM PANEL FOR NAP FORM 2 - NO-PRINT -->
                    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                        <h4 style="margin: 0 0 14px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            📋 RDS Header & Details (Boxes 1 – 4)
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">1. AGENCY NAME</label>
                                <input type="text" wire:model.live="agencyName" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">2. ADDRESS</label>
                                <input type="text" wire:model.live="agencyAddress" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">4. DATE PREPARED</label>
                                <input type="text" wire:model.live="datePrepared" class="form-control" placeholder="e.g. August 03, 2026" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                        </div>

                        <h4 style="margin: 14px 0 12px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            ✍️ Signatures & Approvals (Page 2)
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">9. PREPARED BY</label>
                                <input type="text" wire:model.live="preparedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="preparedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">11. RECOMMENDING APPROVAL</label>
                                <input type="text" wire:model.live="recommendingBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="recommendingPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">10. ASSISTED BY</label>
                                <input type="text" wire:model.live="assistedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="assistedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">12. APPROVED</label>
                                <input type="text" wire:model.live="approvedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="approvedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                            </div>
                        </div>
                    </div>
                @else
                    <!-- FILL-UP FORM PANEL FOR NAP FORM 3 - NO-PRINT -->
                    <div class="no-print" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; margin-bottom: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
                        <h4 style="margin: 0 0 14px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            📋 Request Header Information
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">AGENCY NAME</label>
                                <input type="text" wire:model.live="agencyName" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">ADDRESS</label>
                                <input type="text" wire:model.live="agencyAddress" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">DATE</label>
                                <input type="text" wire:model.live="datePrepared" class="form-control" placeholder="e.g. August 03, 2026" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">TELEPHONE NUMBER</label>
                                <input type="text" wire:model.live="telephoneNumber" class="form-control" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                            </div>
                        </div>

                        <h4 style="margin: 14px 0 12px 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px;">
                            ✍️ Signatures & Certifications
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">PREPARED BY (Name & Position)</label>
                                <input type="text" wire:model.live="preparedBy" class="form-control" placeholder="Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="preparedPosition" class="form-control" placeholder="Position" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                            </div>
                            <div>
                                <label style="font-size: 11.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">CERTIFIED AND APPROVED BY (Agency Head)</label>
                                <input type="text" wire:model.live="approvedBy" class="form-control" placeholder="Agency Head Name" style="width: 100%; padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; margin-bottom: 4px;">
                                <input type="text" wire:model.live="approvedPosition" class="form-control" placeholder="Position / Designation" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11px;">
                            </div>
                        </div>
                    </div>
                @endif

                {{-- DOCUMENT PRINT SHEETS --}}
                @if($isNap1)
                    @php
                        $flattenedItems = [];
                        foreach ($printHierarchy as $root) {
                            if (!$root->has_children) {
                                $flattenedItems[] = [
                                    'type' => 'root_standalone',
                                    'root' => $root,
                                ];
                                if ($includeDescriptionOnPrint) {
                                    foreach ($root->direct_records as $rec) {
                                        $flattenedItems[] = [
                                            'type'   => 'record',
                                            'rec'    => $rec,
                                            'indent' => 20,
                                        ];
                                    }
                                }
                            } else {
                                $flattenedItems[] = [
                                    'type' => 'root_header',
                                    'root' => $root,
                                ];
                                foreach ($root->sub_series as $sub) {
                                    $flattenedItems[] = [
                                        'type'   => 'sub_series',
                                        'sub'    => $sub,
                                        'root'   => $root,
                                        'indent' => 16,
                                    ];
                                    if ($includeDescriptionOnPrint) {
                                        foreach ($sub->records as $rec) {
                                            $flattenedItems[] = [
                                                'type'   => 'record',
                                                'rec'    => $rec,
                                                'indent' => 26,
                                            ];
                                        }
                                    }
                                }
                            }
                        }

                        $totalItems = count($flattenedItems);
                        $pages = [];
                        $maxRowsFinalPage = 10;
                        $maxRowsOtherPages = 15;

                        if ($totalItems === 0) {
                            $pages = [ [] ];
                        } elseif ($totalItems <= $maxRowsFinalPage) {
                            $pages = [ $flattenedItems ];
                        } else {
                            $remaining = $flattenedItems;
                            while (!empty($remaining)) {
                                if (count($remaining) <= $maxRowsFinalPage) {
                                    $pages[] = $remaining;
                                    break;
                                }
                                $chunkSize = min($maxRowsOtherPages, max(1, count($remaining) - 1));
                                $chunk = array_splice($remaining, 0, $chunkSize);
                                $pages[] = $chunk;
                            }
                        }
                        $totalPages = count($pages);
                    @endphp

                    @foreach($pages as $pageIndex => $pageItems)
                        @php
                            $isLastPage = ($pageIndex + 1) === $totalPages;
                            $cellBorder = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;";
                            $computedFiller = $isLastPage 
                                ? max(60, 360 - (count($pageItems) * 22)) 
                                : max(60, 460 - (count($pageItems) * 22));
                        @endphp
                        <div class="print-sheet">
                            <!-- Top Form Identifier -->
                            <div style="font-size: 8px; font-weight: normal; margin-bottom: 3px; line-height: 1.25;">
                                NAP Records Inventory and Appraisal Form<br>2024
                            </div>

                            <!-- TOP HEADER GRID BOX (Fields 1 to 8) -->
                            <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-bottom: none; font-size: 8px; text-align: left; table-layout: fixed;">
                                <tr>
                                    <td rowspan="3" style="width: 27.5%; border: 1px solid #000; text-align: center; vertical-align: middle; padding: 4px;">
                                        <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                                            <div style="font-weight: bold; font-size: 10px; text-align: center;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                            <div style="font-style: italic; font-size: 8.5px; margin: 2px 0 8px 0; text-align: center;">Pambansang Sinupan ng Pilipinas</div>
                                            <div style="font-weight: bold; font-size: 10px; text-align: center;">RECORDS INVENTORY AND APPRAISAL</div>
                                        </div>
                                    </td>
                                    <td rowspan="2" style="width: 27%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>1. NAME OF OFFICE:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($agencyName) }}</div>
                                    </td>
                                    <td style="width: 17.5%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>2. DEPARTMENT/DIVISION:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($departmentDivision) }}</div>
                                    </td>
                                    <td style="width: 28%; border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>4. TELEPHONE NO.:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($telephoneNumber) }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>3. SECTION/UNIT:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($sectionUnit) }}</div>
                                    </td>
                                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>5. EMAIL ADDRESS.:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($emailAddress) }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>6. ADDRESS:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($agencyAddress) }}</div>
                                    </td>
                                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>7. PERSON-IN-CHARGE OF FILES:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($personInCharge) }}</div>
                                    </td>
                                    <td style="border: 1px solid #000; padding: 4px 6px; vertical-align: top;">
                                        <strong>8. DATE PREPARED:</strong>
                                        <div style="font-size: 9px; font-weight: bold; margin-top: 2px;">{{ $cleanVal($datePrepared) }}</div>
                                    </td>
                                </tr>
                            </table>

                            <!-- MAIN DATA TABLE (Columns 9 to 20) -->
                            <table class="print-table" style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 8px; text-align: center; table-layout: fixed;">
                                <thead>
                                    <tr style="font-weight: bold;">
                                        <th rowspan="2" style="border: 1px solid #000; width: 17%; padding: 4px 2px; text-align: center;">9. RECORDS SERIES TITLE AND DESCRIPTION</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 8%; padding: 4px 2px; text-align: center;">10. PERIOD COVERED / INCLUSIVE DATES</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 5%; padding: 4px 2px; text-align: center;">11. VOLUME</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">12. RECORDS MEDIUM</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">13. RESTRICTION/S</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 7.5%; padding: 4px 2px; text-align: center;">14. LOCATION OF RECORDS</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">15. FREQUENCY OF USE</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 5.5%; padding: 4px 2px; text-align: center;">16. DUPLICATION</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 5%; padding: 4px 2px; text-align: center;">17. TIME VALUE (T/P)</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 6.5%; padding: 4px 2px; text-align: center;">18. UTILITY VALUE Adm/F/L/Arc</th>
                                        <th colspan="3" style="border: 1px solid #000; width: 11%; padding: 4px 2px; text-align: center;">19. RETENTION PERIOD</th>
                                        <th rowspan="2" style="border: 1px solid #000; width: 15%; padding: 4px 2px; text-align: center;">20. DISPOSITION PROVISION</th>
                                    </tr>
                                    <tr style="font-weight: bold;">
                                        <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px; text-align: center;">Active</th>
                                        <th style="border: 1px solid #000; width: 3.6%; padding: 3px 2px; text-align: center;">Storage</th>
                                        <th style="border: 1px solid #000; width: 3.8%; padding: 3px 2px; text-align: center;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pageItems as $item)
                                        @if($item['type'] === 'root_standalone')
                                            @php $root = $item['root']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px; font-weight: bold; font-size: 8.5px;">
                                                    {{ strtoupper($cleanVal($root->series_title)) }}
                                                </td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_period) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_volume) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_medium) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_restriction) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_location) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_freq) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->compiled_duplication) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">{{ $cleanVal($root->compiled_time) }}</td>
                                                <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">{{ $cleanVal($root->compiled_util) }}</td>
                                                @if($root->is_permanent)
                                                    <td colspan="3" style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">PERMANENT</td>
                                                @else
                                                    <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->active_period) }}</td>
                                                    <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center;">{{ $cleanVal($root->storage_period) }}</td>
                                                    <td style="{{ $cellBorder }} padding: 3px 2px; text-align: center; font-weight: bold;">{{ $cleanVal($root->total_period) }}</td>
                                                @endif
                                                <td style="{{ $cellBorder }} padding: 3px 4px; text-align: left;">{{ $cleanVal($root->remarks) }}</td>
                                            </tr>
                                        @elseif($item['type'] === 'root_header')
                                            @php $root = $item['root']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px 2px 6px; font-weight: bold; font-size: 8.5px;">
                                                    {{ strtoupper($cleanVal($root->series_title)) }}
                                                </td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            </tr>
                                        @elseif($item['type'] === 'sub_series')
                                            @php 
                                                $sub = $item['sub']; 
                                                $root = $item['root']; 
                                            @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: left; padding: 2px 6px 3px {{ $item['indent'] ?? 16 }}px; font-weight: normal; font-size: 8.5px;">
                                                    {{ $cleanVal($sub->series_title) }}
                                                </td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_period) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_volume) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_medium) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_restriction) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_location) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_freq) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->compiled_duplication) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">{{ $cleanVal($sub->compiled_time) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">{{ $cleanVal($sub->compiled_util) }}</td>
                                                @if($sub->is_permanent)
                                                    <td colspan="3" style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">PERMANENT</td>
                                                @else
                                                    <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->active_period) }}</td>
                                                    <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($sub->storage_period) }}</td>
                                                    <td style="{{ $cellBorder }} padding: 2px; text-align: center; font-weight: bold;">{{ $cleanVal($sub->total_period) }}</td>
                                                @endif
                                                <td style="{{ $cellBorder }} padding: 2px 4px; text-align: left;">{{ $cleanVal($sub->remarks ?: $root->remarks) }}</td>
                                            </tr>
                                        @elseif($item['type'] === 'record')
                                            @php $rec = $item['rec']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: left; padding: 2px 6px 2px {{ $item['indent'] ?? 20 }}px; font-size: 8px;">
                                                    {{ $cleanVal($rec->description) }}
                                                </td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->date_covered) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->volume) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->medium) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->restriction) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->location) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->frequence_use) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->duplication) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->time_value) }}</td>
                                                <td style="{{ $cellBorder }} padding: 2px; text-align: center;">{{ $cleanVal($rec->utility) }}</td>
                                                <td colspan="3" style="{{ $cellBorder }} padding: 2px;"></td>
                                                <td style="{{ $cellBorder }} padding: 2px;"></td>
                                            </tr>
                                        @endif
                                    @endforeach

                                    <!-- Tall vertical column lines extending to bottom table border -->
                                    <tr style="height: {{ $computedFiller }}px;">
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- LEGEND SECTION (Visible on every page) -->
                            <div style="font-size: 8px; margin-top: 6px; line-height: 1.35;">
                                <div style="font-weight: bold;">LEGEND:</div>
                                <div style="display: flex; gap: 30px; margin-top: 1px;">
                                    <div style="display: flex; gap: 15px;">
                                        <span style="font-weight: bold; width: 90px;">TIME VALUE:</span>
                                        <span><strong>T</strong> - Temporary &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <strong>P</strong> - Permanent</span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 30px; margin-top: 1px;">
                                    <div style="display: flex; gap: 15px;">
                                        <span style="font-weight: bold; width: 90px;">UTILITY VALUE:</span>
                                        <span><strong>Adm</strong> - Administrative &nbsp;&nbsp;&nbsp;&nbsp; <strong>F</strong> - Fiscal &nbsp;&nbsp;&nbsp;&nbsp; <strong>L</strong> - Legal &nbsp;&nbsp;&nbsp;&nbsp; <strong>Arc</strong> - Archival</span>
                                    </div>
                                </div>
                            </div>

                            <!-- SIGNATURE BLOCK (Visible on the last page) -->
                            @if($isLastPage)
                                <div style="display: flex; justify-content: space-between; font-size: 8.5px; margin-top: 20px;">
                                    <div style="width: 30%; text-align: center;">
                                        <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">PREPARED BY:</div>
                                        <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 13px;">
                                            {{ $cleanVal($preparedBy) }}
                                        </div>
                                        <div style="font-size: 8px; margin-top: 3px;">
                                            {{ $cleanVal($preparedPosition) ?: 'Name and Position' }}
                                        </div>
                                    </div>
                                    <div style="width: 30%; text-align: center;">
                                        <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">ASSISTED BY:</div>
                                        <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 13px;">
                                            {{ $cleanVal($assistedBy) }}
                                        </div>
                                        <div style="font-size: 8px; margin-top: 3px;">
                                            {{ $cleanVal($assistedPosition) ?: 'NAP Records Management Analyst' }}
                                        </div>
                                    </div>
                                    <div style="width: 30%; text-align: center;">
                                        <div style="font-weight: bold; text-align: left; margin-bottom: 25px;">APPROVED BY:</div>
                                        <div style="border-bottom: 1.5px solid #000; width: 90%; margin: 0 auto; font-weight: bold; font-size: 9px; min-height: 13px;">
                                            {{ $cleanVal($approvedBy) }}
                                        </div>
                                        <div style="font-size: 8px; margin-top: 3px;">
                                            {{ $cleanVal($approvedPosition) ?: 'Chief of the Division/Department' }}
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- BOTTOM PAGE NUMBER -->
                            <div style="text-align: right; font-size: 8px; margin-top: 10px;">
                                Page {{ $pageIndex + 1 }} of {{ $totalPages }} {{ $totalPages === 1 ? 'Page' : 'Pages' }}
                            </div>
                        </div>
                    @endforeach
                @elseif($isNap2)
                    @php
                        $totalPrintItems = count($printItems);
                        $rowsPerPage = 16;
                        if ($totalPrintItems === 0) {
                            $dataPages = [ [] ];
                        } else {
                            $dataPages = array_chunk($printItems, $rowsPerPage);
                        }
                        $totalPages = count($dataPages) + 1; // Dedicated final Signatures & NAP Approval Sheet
                    @endphp

                    <!-- DATA PAGES (Page 1 .. N) -->
                    @foreach($dataPages as $pageIndex => $pageItems)
                        @php
                            $pageNumber = $pageIndex + 1;
                            $fillerHeight = empty($pageItems) ? 650 : max(40, 650 - (count($pageItems) * 26));
                        @endphp
                        <div class="print-sheet">
                            <!-- Top Form Identifier -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px;">
                                <div style="font-size: 10px; font-weight: normal; line-height: 1.25;">
                                    NAP Form 2<br>2008
                                </div>
                            </div>

                            <!-- Header Box (Outer Border) -->
                            <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; margin-bottom: 0;">
                                <tr>
                                    <td style="width: 50%; border-right: 2px solid #000; padding: 10px 12px; text-align: center; vertical-align: middle;">
                                        <div style="font-size: 11px; font-weight: bold; letter-spacing: 0.3px;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                        <div style="font-size: 9.5px; font-style: italic; margin-top: 2px; margin-bottom: 8px;">Pambansang Sinupan ng Pilipinas</div>
                                        <div style="font-size: 12px; font-weight: bold; letter-spacing: 0.5px;">RECORDS DISPOSITION SCHEDULE</div>
                                    </td>
                                    <td style="width: 50%; padding: 0; vertical-align: top;">
                                        <div style="padding: 7px 10px; border-bottom: 1px solid #000; font-size: 9px; line-height: 1.4;">
                                            <strong>1. AGENCY NAME:</strong>
                                            <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">{{ $agencyName }}</div>
                                        </div>
                                        <div style="padding: 7px 10px; font-size: 9px; line-height: 1.4;">
                                            <strong>2. ADDRESS:</strong>
                                            <div style="font-size: 10px; font-weight: bold; margin-top: 2px;">{{ $agencyAddress }}</div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width: 50%; border-right: 2px solid #000; border-top: 2px solid #000; padding: 6px 10px; font-size: 9px;">
                                        <strong>3. SCHEDULE NO.:</strong> <span style="font-weight: bold; font-size: 10px; margin-left: 4px;">{{ $printCluster->cluster_id ?? '' }}</span>
                                    </td>
                                    <td style="width: 50%; border-top: 2px solid #000; padding: 6px 10px; font-size: 9px;">
                                        <strong>4. DATE PREPARED:</strong> <span style="font-weight: bold; font-size: 10px; margin-left: 4px;">{{ $datePrepared }}</span>
                                    </td>
                                </tr>
                            </table>

                            <!-- Data Table (Boxes 5 - 8) -->
                            <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 9px; table-layout: fixed;">
                                <thead>
                                    <tr style="background: #ffffff;">
                                        <th rowspan="2" style="width: 11%; border: 1px solid #000; border-top: 2px solid #000; padding: 6px 4px; text-align: center; font-weight: bold; vertical-align: middle;">
                                            5. ITEM NO.:
                                        </th>
                                        <th rowspan="2" style="width: 47%; border: 1px solid #000; border-top: 2px solid #000; padding: 6px 6px; text-align: center; font-weight: bold; vertical-align: middle;">
                                            6. RECORD SERIES TITLE AND DESCRIPTION
                                        </th>
                                        <th colspan="3" style="width: 24%; border: 1px solid #000; border-top: 2px solid #000; padding: 4px; text-align: center; font-weight: bold;">
                                            7. RETENTION PERIOD
                                        </th>
                                        <th rowspan="2" style="width: 18%; border: 1px solid #000; border-top: 2px solid #000; padding: 6px 6px; text-align: center; font-weight: bold; vertical-align: middle;">
                                            8. REMARKS
                                        </th>
                                    </tr>
                                    <tr style="background: #ffffff;">
                                        <th style="width: 8%; border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold;">Active</th>
                                        <th style="width: 8%; border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold;">Storage</th>
                                        <th style="width: 8%; border: 1px solid #000; padding: 4px; text-align: center; font-weight: bold;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pageItems as $item)
                                        @php
                                            $isPermSeries = (bool)($item->effective_is_permanent) || 
                                                            (strtolower(trim($item->effective_total ?? '')) === 'permanent') ||
                                                            (strtolower(trim($item->effective_active ?? '')) === 'permanent' && strtolower(trim($item->effective_storage ?? '')) === 'permanent');
                                        @endphp
                                        <tr>
                                            <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top; font-weight: bold;">
                                                {{ $item->display_item_no }}
                                            </td>
                                            <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 6px; vertical-align: top; padding-left: {{ (($item->depth ?? 0) * 14) + 6 }}px;">
                                                <span style="{{ ($item->depth ?? 0) === 0 ? 'font-weight: bold;' : 'font-weight: 500;' }}">
                                                    {{ $item->series_title }}
                                                </span>
                                            </td>
                                            @if($isPermSeries)
                                                <td colspan="3" style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top; font-weight: bold;">
                                                    PERMANENT
                                                </td>
                                            @else
                                                <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top;">
                                                    {{ $item->effective_active }}
                                                </td>
                                                <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top;">
                                                    {{ $item->effective_storage }}
                                                </td>
                                                <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 4px; text-align: center; vertical-align: top; font-weight: bold;">
                                                    {{ $item->effective_total }}
                                                </td>
                                            @endif
                                            <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; padding: 5px 6px; vertical-align: top; font-size: 8.5px;">
                                                {{ $item->remarks }}
                                            </td>
                                        </tr>
                                    @endforeach

                                    <!-- Filler row to extend column borders to bottom -->
                                    <tr>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none; height: {{ $fillerHeight }}px;"></td>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                        <td style="border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;"></td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Bottom Statutory Notice -->
                            <div style="border-top: 2px solid #000; padding-top: 6px; margin-top: 0; font-size: 8px; line-height: 1.35; text-align: justify;">
                                <strong>IMPORTANT:</strong> Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."
                            </div>

                            <!-- Bottom Page Number -->
                            <div style="text-align: right; font-size: 9px; margin-top: 8px;">
                                Page {{ $pageNumber }} of {{ $totalPages }} Pages
                            </div>
                        </div>
                    @endforeach

                    <!-- SIGNATURES & NAP APPROVAL PAGE (FINAL PAGE) -->
                    <div class="print-sheet">
                        <!-- Top Form Identifier -->
                        <div style="font-size: 10px; font-weight: normal; line-height: 1.25; margin-bottom: 8px;">
                            NAP Form 2<br>2008
                        </div>

                        <!-- Signatures Table (Box 9, 11, 10, 12) -->
                        <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 9px;">
                            <tr>
                                <!-- 9. Prepared by -->
                                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">9. Prepared by:</div>
                                    <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $preparedBy }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $preparedPosition }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                    </div>
                                </td>

                                <!-- 11. Recommending Approval -->
                                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">11. Recommending Approval:</div>
                                    <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $recommendingBy }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $recommendingPosition }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <!-- 10. Assisted by -->
                                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">10. Assisted by:</div>
                                    <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $assistedBy }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $assistedPosition }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                    </div>
                                </td>

                                <!-- 12. Approved -->
                                <td style="width: 50%; border: 1px solid #000; padding: 12px 16px; vertical-align: top; height: 160px; box-sizing: border-box;">
                                    <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 25px;">12. Approved</div>
                                    <div style="display: flex; flex-direction: column; align-items: center; width: 85%; margin: 0 auto;">
                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $approvedBy }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px; margin-bottom: 15px;">Name</div>

                                        <div style="border-bottom: 1px solid #000; width: 100%; text-align: center; font-weight: bold; font-size: 10px; min-height: 16px; padding-bottom: 2px;">
                                            {{ $approvedPosition }}
                                        </div>
                                        <div style="font-size: 8.5px; color: #000; margin-top: 2px;">Position</div>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <!-- NAP Accomplishment Section -->
                        <div style="border: 2px solid #000; margin-top: 14px; font-size: 9.5px; flex-grow: 1; display: flex; flex-direction: column;">
                            <div style="border-bottom: 2px solid #000; padding: 6px; text-align: center; font-weight: bold; font-size: 10px; letter-spacing: 0.5px; text-transform: uppercase;">
                                TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES
                            </div>
                            <div style="padding: 16px 20px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
                                <div>
                                    <div style="margin-bottom: 14px; font-size: 9.5px;">This Records Disposition Schedule</div>
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding-left: 20px;">
                                        <span style="display: inline-block; width: 14px; height: 14px; border: 1.5px solid #000;"></span>
                                        <span>is being returned for improvement / correction</span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px; padding-left: 20px;">
                                        <span style="display: inline-block; width: 14px; height: 14px; border: 1.5px solid #000;"></span>
                                        <span>is being recommended for approval</span>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 50px; padding: 0 20px 20px 20px;">
                                    <!-- Chairman block -->
                                    <div style="text-align: center; width: 42%;">
                                        <div style="border-bottom: 1px solid #000; width: 100%; min-height: 20px; margin-bottom: 4px; font-weight: bold; font-size: 10px;">
                                            {{ $committeeChairmanName }}
                                        </div>
                                        <div style="font-weight: bold; font-size: 9.5px;">Chairman</div>
                                        <div style="font-size: 8.5px; margin-top: 1px;">Records Management Evaluation Committee</div>
                                        <div style="margin-top: 16px; text-align: left; font-size: 9px;">
                                            Date: <span style="display: inline-block; border-bottom: 1px solid #000; width: 130px;"></span>
                                        </div>
                                    </div>

                                    <!-- Executive Director block -->
                                    <div style="text-align: center; width: 42%;">
                                        <div style="font-weight: bold; font-size: 9.5px; margin-bottom: 6px;">APPROVED:</div>
                                        <div style="border-bottom: 1px solid #000; width: 100%; min-height: 20px; margin-bottom: 4px; font-weight: bold; font-size: 10px;">
                                            {{ $executiveDirectorName }}
                                        </div>
                                        <div style="font-weight: bold; font-size: 9.5px;">Executive Director</div>
                                        <div style="margin-top: 16px; text-align: left; font-size: 9px;">
                                            Date: <span style="display: inline-block; border-bottom: 1px solid #000; width: 130px;"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bottom Page Number -->
                        <div style="text-align: right; font-size: 9px; margin-top: 8px;">
                            Page {{ $totalPages }} of {{ $totalPages }} Pages
                        </div>
                    </div>
                @else
                    @php
                        $flattenedItems = [];
                        foreach ($printHierarchy as $root) {
                            if (!$root->has_children) {
                                $flattenedItems[] = [
                                    'type' => 'root_standalone',
                                    'root' => $root,
                                ];
                                if ($includeDescriptionOnPrint) {
                                    foreach ($root->direct_records as $rec) {
                                        $flattenedItems[] = [
                                            'type'   => 'record',
                                            'rec'    => $rec,
                                            'indent' => 20,
                                        ];
                                    }
                                }
                            } else {
                                $flattenedItems[] = [
                                    'type' => 'root_header',
                                    'root' => $root,
                                ];
                                foreach ($root->sub_series as $sub) {
                                    $flattenedItems[] = [
                                        'type'   => 'sub_series',
                                        'sub'    => $sub,
                                        'root'   => $root,
                                        'indent' => 14,
                                    ];
                                    if ($includeDescriptionOnPrint) {
                                        foreach ($sub->records as $rec) {
                                            $flattenedItems[] = [
                                                'type'   => 'record',
                                                'rec'    => $rec,
                                                'indent' => 20,
                                            ];
                                        }
                                    }
                                }
                            }
                        }

                        $totalItems = count($flattenedItems);
                        $pages = [];
                        $maxRowsFinalPage = 10;
                        $maxRowsOtherPages = 15;

                        if ($totalItems === 0) {
                            $pages = [ [] ];
                        } elseif ($totalItems <= $maxRowsFinalPage) {
                            $pages = [ $flattenedItems ];
                        } else {
                            $remaining = $flattenedItems;
                            while (!empty($remaining)) {
                                if (count($remaining) <= $maxRowsFinalPage) {
                                    $pages[] = $remaining;
                                    break;
                                }
                                $chunkSize = min($maxRowsOtherPages, max(1, count($remaining) - 1));
                                $chunk = array_splice($remaining, 0, $chunkSize);
                                $pages[] = $chunk;
                            }
                        }
                        $totalPages = count($pages);
                    @endphp

                    @foreach($pages as $pageIndex => $pageItems)
                        @php
                            $isLastPage = ($pageIndex + 1) === $totalPages;
                            $cellBorder = "border-left: 1px solid #000; border-right: 1px solid #000; border-top: none; border-bottom: none;";
                            $computedFiller = $isLastPage 
                                ? max(60, 480 - (count($pageItems) * 22)) 
                                : max(60, 680 - (count($pageItems) * 22));
                        @endphp
                        <div class="print-sheet">
                            <!-- Top Form ID Line -->
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; font-size: 8.5px;">
                                <div>
                                    <div style="font-weight: bold;">NAP Form No. 3</div>
                                    <div style="font-style: italic;">Revised 2012</div>
                                </div>
                                <div style="font-style: italic; font-size: 8.5px;">
                                    Accomplish in 3 copies
                                </div>
                            </div>

                            <!-- Header Box -->
                            <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 9px; table-layout: fixed;">
                                <tr>
                                    <td rowspan="2" style="width: 50%; border: 1px solid #000; text-align: center; padding: 6px; vertical-align: middle;">
                                        <div style="border: 1.5px solid #000; padding: 8px 6px; margin: 2px;">
                                            <div style="font-weight: bold; font-size: 10px; text-transform: uppercase;">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                                            <div style="font-size: 8.5px; font-style: italic; margin: 2px 0 6px 0;">Pambansang Sinupan ng Pilipinas</div>
                                            <div style="font-weight: 800; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.5px;">
                                                REQUEST FOR AUTHORITY TO DISPOSE<br>OF RECORDS
                                            </div>
                                        </div>
                                    </td>
                                    <td style="width: 50%; border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                                        <div><strong>AGENCY NAME:</strong> <span style="text-transform: uppercase;">{{ $cleanVal($agencyName) }}</span></div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #000; padding: 6px 8px; vertical-align: top;">
                                        <div><strong>ADDRESS:</strong> {{ $cleanVal($agencyAddress) }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #000; padding: 6px 8px;">
                                        <strong>DATE:</strong> {{ $cleanVal($datePrepared) }}
                                    </td>
                                    <td style="border: 1px solid #000; padding: 6px 8px;">
                                        <strong>TELEPHONE NUMBER:</strong> {{ $cleanVal($telephoneNumber) }}
                                    </td>
                                </tr>
                            </table>

                            <!-- Official Table (Revised 2012) -->
                            <table class="print-table" style="width: 100%; border-collapse: collapse; border: 2px solid #000; font-size: 9px; margin-top: 0; border-top: none; table-layout: fixed;">
                                <thead>
                                    <tr>
                                        <th style="width: 12%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">GRDS/ RDS ITEM NO.</th>
                                        <th style="width: 48%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">RECORD SERIES TITLE AND DESCRIPTION</th>
                                        <th style="width: 20%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">PERIOD COVERED</th>
                                        <th style="width: 20%; border: 1px solid #000; padding: 6px 5px; text-align: center; font-weight: bold;">RETENTION PERIOD AND PROVISION/S COMPLIED (If Any)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($pageItems as $item)
                                        @if($item['type'] === 'root_standalone')
                                            @php $root = $item['root']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($root->item_number) }}</td>
                                                <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px; font-weight: bold;">{{ strtoupper($cleanVal($root->series_title)) }}</td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($root->compiled_period) }}</td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal(trim($root->total_period . ($root->remarks ? ' / ' . $root->remarks : ''))) }}</td>
                                            </tr>
                                        @elseif($item['type'] === 'root_header')
                                            @php $root = $item['root']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($root->item_number) }}</td>
                                                <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px; font-weight: bold;">{{ strtoupper($cleanVal($root->series_title)) }}</td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;"></td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;"></td>
                                            </tr>
                                        @elseif($item['type'] === 'sub_series')
                                            @php $sub = $item['sub']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;"></td>
                                                <td style="{{ $cellBorder }} text-align: left; padding: 4px 6px 4px {{ $item['indent'] ?? 14 }}px; font-weight: normal;">
                                                    └ {{ $cleanVal($sub->series_title) }}
                                                </td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal($sub->compiled_period) }}</td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 4px 6px;">{{ $cleanVal(trim($sub->total_period . (($sub->remarks ?: $root->remarks) ? ' / ' . ($sub->remarks ?: $root->remarks) : ''))) }}</td>
                                            </tr>
                                        @elseif($item['type'] === 'record')
                                            @php $rec = $item['rec']; @endphp
                                            <tr style="vertical-align: top;">
                                                <td style="{{ $cellBorder }} text-align: center; padding: 3px 6px;"></td>
                                                <td style="{{ $cellBorder }} text-align: left; padding: 3px 6px 3px {{ $item['indent'] ?? 20 }}px; font-size: 8.5px;">
                                                    {{ $cleanVal($rec->description) }}
                                                </td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 3px 6px;">{{ $cleanVal($rec->date_covered) }}</td>
                                                <td style="{{ $cellBorder }} text-align: center; padding: 3px 6px;"></td>
                                            </tr>
                                        @endif
                                    @endforeach

                                    <!-- Tall vertical column lines extending to bottom table border -->
                                    <tr style="height: {{ $computedFiller }}px;">
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                        <td style="{{ $cellBorder }}">&nbsp;</td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Official Footer Blocks (Revised 2012) -->
                            @if($isLastPage)
                                <table style="width: 100%; border-collapse: collapse; border: 2px solid #000; border-top: none; font-size: 8.5px; page-break-inside: avoid;">
                                    <tr>
                                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                            <strong>LOCATION OF RECORDS:</strong> {{ $cleanVal($effectivePrintLocation) }}
                                        </td>
                                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                            <strong>VOLUME IN CUBIC METER:</strong> {{ $cleanVal($effectivePrintVolume) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                            <strong>PREPARED BY:</strong> {{ $cleanVal($preparedBy) }}
                                        </td>
                                        <td style="width: 50%; border: 1px solid #000; padding: 8px;">
                                            <strong>POSITION:</strong> {{ $cleanVal($preparedPosition) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="border: 1px solid #000; padding: 14px 10px; vertical-align: top;">
                                            <strong>CERTIFIED AND APPROVED BY:</strong>
                                            <div style="font-size: 8px; margin-top: 6px; text-align: center; line-height: 1.4;">
                                                This is to certify that the above mentioned records are no longer needed and<br>not involved nor connected in any administrative or judicial cases.
                                            </div>
                                            <div style="margin-top: 36px; text-align: center; border-bottom: 1px solid #000; width: 45%; margin-left: auto; margin-right: 40px; font-weight: bold; font-size: 9px; min-height: 13px;">
                                                {{ $cleanVal($approvedBy) }}
                                            </div>
                                            <div style="text-align: center; font-size: 8px; margin-top: 3px; width: 45%; margin-left: auto; margin-right: 40px; line-height: 1.3;">
                                                Name and Signature of Agency Head<br>or Duly Authorized Representative
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <!-- BOTTOM PAGE NUMBER -->
                            <div style="text-align: right; font-size: 8px; margin-top: 10px;">
                                Page {{ $pageIndex + 1 }} of {{ $totalPages }} {{ $totalPages === 1 ? 'Page' : 'Pages' }}
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    @endif
</div>

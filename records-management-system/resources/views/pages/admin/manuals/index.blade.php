<?php

use App\Services\ManualService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Page Manuals')] class extends Component {
    public string $search = '';
    public string $subsystemFilter = '';
    public string $typeFilter = '';
    public string $statusFilter = '';

    public ?string $selectedTargetKey = null;
    public string $viewMode = 'preview'; // 'preview' | 'editor'

    // Editor & Assignment state
    public string $editorContent = '';
    public string $selectedManualFile = '';
    public ?string $assignedRelativePath = null;
    public ?array $currentParsedManual = null;
    public bool $isManualLoaded = false;

    // Create Modal state
    public bool $showCreateModal = false;
    public string $newFileName = '';
    public string $newFileSubsystem = 'dts';
    public string $newFileTitle = '';
    public string $newFilePurpose = '';

    // Messages
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (! $perms || (! $perms->is_sadm && ! $perms->can_access_settings)) {
            $this->redirect(route('portal'));
            return;
        }

        $targets = ManualService::getAllSystemTargets();
        if (!empty($targets)) {
            $this->selectTarget($targets[0]['key'], loadFile: false);
        }
    }

    public function selectTarget(string $key, bool $loadFile = false): void
    {
        $this->selectedTargetKey = $key;
        $this->clearMessages();
        $this->isManualLoaded = false;
        $this->currentParsedManual = null;
        $this->editorContent = '';

        // Check mapping or convention match without heavy parsing
        $mappings = ManualService::getManualMapping();
        $isExplicitlyDisabled = isset($mappings[$key]) && in_array($mappings[$key], ['disabled', 'none', '']);
        $assignedFile = null;

        if (!$isExplicitlyDisabled) {
            if (isset($mappings[$key]) && !empty($mappings[$key])) {
                $assignedFile = $mappings[$key];
            } else {
                $index = ManualService::getFileIndex();
                $keyLower = strtolower($key);
                $found = $index['by_modal_base'][$keyLower]
                    ?? ($index['by_base'][$keyLower]
                    ?? ($index['by_path'][$keyLower . '.md']
                    ?? ($index['by_name'][$keyLower . '.md'] ?? null)));
                if ($found) {
                    $baseDir = resource_path('manuals');
                    $assignedFile = str_replace('\\', '/', str_replace([$baseDir . DIRECTORY_SEPARATOR, $baseDir . '/'], '', $found));
                }
            }
        }

        $this->assignedRelativePath = $assignedFile;
        $this->selectedManualFile = $assignedFile ?? '';

        if ($loadFile && !empty($assignedFile)) {
            $this->loadTargetManual();
        }
    }

    public function loadTargetManual(): void
    {
        if (!$this->selectedTargetKey) return;

        $manual = ManualService::getManualByName($this->selectedTargetKey);
        $this->currentParsedManual = $manual;
        $this->isManualLoaded = true;

        if ($manual && !empty($manual['relative_path'])) {
            $this->assignedRelativePath = $manual['relative_path'];
            $this->selectedManualFile = $manual['relative_path'];
            $this->editorContent = $manual['raw_content'] ?? (ManualService::getManualFileRaw($manual['relative_path']) ?? '');
        }
    }

    public function setViewMode(string $mode): void
    {
        if (in_array($mode, ['preview', 'editor'], true)) {
            $this->viewMode = $mode;
            if ($mode === 'editor' && !$this->isManualLoaded && !empty($this->assignedRelativePath)) {
                $this->loadTargetManual();
            }
        }
    }

    public function assignSelectedManual(): void
    {
        if (!$this->selectedTargetKey) return;

        if (empty($this->selectedManualFile)) {
            $this->errorMessage = 'Please select a manual file to assign.';
            return;
        }

        $ok = ManualService::assignManual($this->selectedTargetKey, $this->selectedManualFile);
        if ($ok) {
            $this->successMessage = "Manual '{$this->selectedManualFile}' successfully assigned to {$this->selectedTargetKey}.";
            $this->selectTarget($this->selectedTargetKey);
        } else {
            $this->errorMessage = "Could not assign manual file.";
        }
    }

    public function unassignTarget(): void
    {
        if (!$this->selectedTargetKey) return;

        $ok = ManualService::unassignManual($this->selectedTargetKey);
        if ($ok) {
            $this->successMessage = "Manual unassigned from {$this->selectedTargetKey}.";
            $this->selectTarget($this->selectedTargetKey);
        } else {
            $this->errorMessage = "Could not unassign manual.";
        }
    }

    public function resetToDefaultTarget(): void
    {
        if (!$this->selectedTargetKey) return;

        $ok = ManualService::resetToDefaultManual($this->selectedTargetKey);
        if ($ok) {
            $this->successMessage = "Reset {$this->selectedTargetKey} to default auto-discovery.";
            $this->selectTarget($this->selectedTargetKey);
        } else {
            $this->errorMessage = "Could not reset target.";
        }
    }

    public function saveEditorContent(): void
    {
        if (!$this->selectedTargetKey || empty($this->assignedRelativePath)) {
            $this->errorMessage = 'No manual file is currently assigned to edit.';
            return;
        }

        $ok = ManualService::saveManualFileContent($this->assignedRelativePath, $this->editorContent);
        if ($ok) {
            $this->successMessage = "Saved updates to {$this->assignedRelativePath} successfully.";
            $this->selectTarget($this->selectedTargetKey);
        } else {
            $this->errorMessage = "Could not save manual content to disk.";
        }
    }

    public function openCreateModal(): void
    {
        $this->newFileName = '';
        $this->newFileTitle = '';
        $this->newFilePurpose = '';

        if ($this->selectedTargetKey) {
            $targets = ManualService::getAllSystemTargets();
            $target = collect($targets)->firstWhere('key', $this->selectedTargetKey);
            if ($target) {
                $this->newFileSubsystem = strtolower($target['subsystem']);
                $this->newFileName = strtolower(str_replace('.', '-', $target['key']));
                $this->newFileTitle = $target['name'];
                $this->newFilePurpose = $target['description'];
            }
        }

        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
    }

    public function generateManualFromTemplate(): void
    {
        if (!$this->selectedTargetKey) return;

        $targets = ManualService::getAllSystemTargets();
        $target = collect($targets)->firstWhere('key', $this->selectedTargetKey);
        $title = $target['name'] ?? 'Page Manual';
        $purpose = $target['description'] ?? 'Guide and instructions for this section.';
        $subsystem = strtolower($target['subsystem'] ?? 'dts');
        $fileName = strtolower(str_replace('.', '-', $this->selectedTargetKey)) . '.md';

        $template = "# {$title}\n\n";
        $template .= "> Purpose: {$purpose}\n\n";
        $template .= "## Step 1: Getting Started\n";
        $template .= "- **Review Guidelines**: Read through the required parameters before proceeding.\n";
        $template .= "- **Verify Data**: Ensure all mandatory fields and attachments are verified.\n\n";
        $template .= "## Step 2: Processing & Actions\n";
        $template .= "- **Select Action**: Choose the corresponding action from the available controls.\n";
        $template .= "- **Confirm Submit**: Review entered details and click submit to proceed.\n\n";
        $template .= "> [!TIP] Use keyboard shortcuts for rapid navigation.\n\n";
        $template .= "> [!NOTE] All transitions are recorded in the central audit history.\n";

        $createdRel = ManualService::createManualFile($subsystem, $fileName, $template);
        if ($createdRel) {
            ManualService::assignManual($this->selectedTargetKey, $createdRel);
            $this->successMessage = "Generated manual template '{$createdRel}' and assigned to {$this->selectedTargetKey}.";
            $this->selectTarget($this->selectedTargetKey);
            $this->viewMode = 'editor';
        } else {
            $this->errorMessage = "Could not create manual file.";
        }
    }

    public function submitCreateManual(): void
    {
        $this->validate([
            'newFileName' => 'required|string|min:2|max:100',
            'newFileTitle' => 'required|string|min:3|max:150',
            'newFileSubsystem' => 'required|string|in:dts,rdp,dcs,admin,modal,common',
        ]);

        $fileName = trim($this->newFileName);
        if (!str_ends_with(strtolower($fileName), '.md')) {
            $fileName .= '.md';
        }

        $title = trim($this->newFileTitle);
        $purpose = trim($this->newFilePurpose ?: 'Operational guide and walkthrough.');

        $content = "# {$title}\n\n";
        $content .= "> Purpose: {$purpose}\n\n";
        $content .= "## Step 1: Initial Walkthrough\n";
        $content .= "- **Overview**: Guide users on primary functions and components on this page.\n";
        $content .= "- **Requirements**: Specify mandatory requirements or permissions.\n\n";
        $content .= "## Step 2: Step-by-Step Instructions\n";
        $content .= "- **Fill Required Fields**: Complete required input data.\n";
        $content .= "- **Submit**: Finalize process and await confirmation.\n\n";
        $content .= "> [!NOTE] This manual can be modified at any time in the Admin Console.\n";

        $createdRel = ManualService::createManualFile($this->newFileSubsystem, $fileName, $content);
        if ($createdRel) {
            if ($this->selectedTargetKey) {
                ManualService::assignManual($this->selectedTargetKey, $createdRel);
            }
            $this->successMessage = "Created manual file '{$createdRel}'.";
            $this->showCreateModal = false;
            if ($this->selectedTargetKey) {
                $this->selectTarget($this->selectedTargetKey);
            }
        } else {
            $this->errorMessage = "Could not create manual file on disk.";
        }
    }

    public function deleteAssignedFile(): void
    {
        if (empty($this->assignedRelativePath)) {
            $this->errorMessage = 'No manual file selected to delete.';
            return;
        }

        $fileToDelete = $this->assignedRelativePath;
        $ok = ManualService::deleteManualFile($fileToDelete);
        if ($ok) {
            $this->successMessage = "Deleted file '{$fileToDelete}' and cleared mappings.";
            if ($this->selectedTargetKey) {
                $this->selectTarget($this->selectedTargetKey);
            }
        } else {
            $this->errorMessage = "Could not delete manual file.";
        }
    }

    public function insertMarkdownSnippet(string $type): void
    {
        $snippet = match ($type) {
            'step' => "\n\n## Step: Title Here\n- **Field Name**: Enter clear instructions here.\n",
            'item' => "\n- **Field Label**: Description of what to enter or select.",
            'note' => "\n> [!NOTE] Important operational note or guideline.\n",
            'tip' => "\n> [!TIP] Helpful performance tip or shortcut recommendation.\n",
            'warning' => "\n> [!WARNING] Cautionary advice to prevent data loss or errors.\n",
            default => '',
        };

        $this->editorContent .= $snippet;
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function refreshCatalog(): void
    {
        ManualService::clearFileIndexCache();
        $count = ManualService::syncDatabaseCatalog();
        $this->successMessage = "Catalog refreshed and synchronized successfully ({$count} targets).";
    }

    public function with(): array
    {
        $allFiles = ManualService::getAllManualFiles();

        $enrichedTargets = [];
        $assignedCount = 0;
        $missingCount = 0;

        // Read directly from sys_page_manuals table for lightning-fast sub-millisecond retrieval
        if (\Illuminate\Support\Facades\Schema::hasTable('sys_page_manuals')) {
            $dbRecords = \Illuminate\Support\Facades\DB::table('sys_page_manuals')->get();
            if ($dbRecords->isEmpty()) {
                ManualService::syncDatabaseCatalog();
                $dbRecords = \Illuminate\Support\Facades\DB::table('sys_page_manuals')->get();
            }

            foreach ($dbRecords as $r) {
                $hasManual = !empty($r->relative_path);
                if ($hasManual) {
                    $assignedCount++;
                } else {
                    $missingCount++;
                }

                $enrichedTargets[] = [
                    'key' => $r->target_key,
                    'name' => $r->target_name,
                    'type' => $r->type,
                    'subsystem' => $r->subsystem,
                    'description' => $r->purpose ?? ('Overview and instructions for ' . $r->target_name),
                    'has_manual' => $hasManual,
                    'assigned_file' => $r->relative_path,
                    'step_count' => $hasManual ? max(1, (int) round(($r->line_count ?: 10) / 4)) : 0,
                    'line_count' => $r->line_count ?? 0,
                    'is_custom_mapped' => (bool) $r->is_custom_mapped,
                ];
            }
        }

        // Fallback if table not yet migrated or empty
        if (empty($enrichedTargets)) {
            $allTargets = ManualService::getAllSystemTargets();
            $mappings = ManualService::getManualMapping();
            $fileLookup = [];
            foreach ($allFiles as $f) {
                $fileLookup[strtolower($f['relative_path'])] = $f;
                $fileLookup[strtolower($f['name'])] = $f;
            }

            $assignedCount = 0;
            $missingCount = 0;

            foreach ($allTargets as $t) {
                $key = $t['key'];
                $isExplicitlyDisabled = isset($mappings[$key]) && in_array($mappings[$key], ['disabled', 'none', '']);
                $assignedFile = null;

                if (!$isExplicitlyDisabled && isset($mappings[$key])) {
                    $assignedFile = $mappings[$key];
                }

                $hasManual = !empty($assignedFile);
                if ($hasManual) $assignedCount++; else $missingCount++;

                $t['has_manual'] = $hasManual;
                $t['assigned_file'] = $assignedFile;
                $t['step_count'] = $hasManual ? 3 : 0;
                $t['line_count'] = $hasManual ? 15 : 0;
                $t['is_custom_mapped'] = isset($mappings[$key]);
                $enrichedTargets[] = $t;
            }
        }

        // Apply filters
        $filteredTargets = $enrichedTargets;

        if ($this->search !== '') {
            $q = strtolower(trim($this->search));
            $filteredTargets = array_filter($filteredTargets, function ($item) use ($q) {
                return str_contains(strtolower($item['name']), $q)
                    || str_contains(strtolower($item['key']), $q)
                    || str_contains(strtolower($item['description']), $q)
                    || str_contains(strtolower($item['assigned_file'] ?? ''), $q);
            });
        }

        if ($this->subsystemFilter !== '') {
            $filteredTargets = array_filter($filteredTargets, fn($item) => $item['subsystem'] === $this->subsystemFilter);
        }

        if ($this->typeFilter !== '') {
            $filteredTargets = array_filter($filteredTargets, fn($item) => $item['type'] === $this->typeFilter);
        }

        if ($this->statusFilter === 'assigned') {
            $filteredTargets = array_filter($filteredTargets, fn($item) => $item['has_manual']);
        } elseif ($this->statusFilter === 'missing') {
            $filteredTargets = array_filter($filteredTargets, fn($item) => !$item['has_manual']);
        }

        // Active selected target data
        $activeTarget = collect($enrichedTargets)->firstWhere('key', $this->selectedTargetKey);

        return [
            'targets' => array_values($filteredTargets),
            'allFiles' => $allFiles,
            'activeTarget' => $activeTarget,
            'totalTargets' => count($enrichedTargets),
            'assignedCount' => $assignedCount,
            'missingCount' => $missingCount,
            'totalFiles' => count($allFiles),
        ];
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .pmo-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px 24px;
            box-sizing: border-box;
            user-select: none;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            color: #0f172a;
        }

        /* Top Header Bar */
        .pmo-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .pmo-top-title h1 {
            margin: 0 0 4px 0;
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .pmo-top-title p {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
        }
        .pmo-top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Stat KPI Cards */
        .pmo-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .pmo-stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .pmo-stat-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }
        .pmo-stat-val {
            font-size: 1.65rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
            margin-bottom: 4px;
        }
        .pmo-stat-lbl {
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pmo-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        /* Filter Controls */
        .pmo-filter-bar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .pmo-search-box {
            position: relative;
            flex: 1 1 260px;
            min-width: 200px;
        }
        .pmo-search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .pmo-search-input {
            width: 100%;
            padding: 9px 12px 9px 38px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.85rem;
            background: #f8fafc;
            color: #0f172a;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.15s, background 0.15s;
        }
        .pmo-search-input:focus {
            border-color: #2563eb;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .pmo-pills {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .pmo-pill {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .pmo-pill:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .pmo-pill.active {
            background: #0f172a;
            border-color: #0f172a;
            color: #ffffff;
        }

        /* Two-Column Studio Layout */
        .pmo-studio {
            display: grid;
            grid-template-columns: 460px minmax(0, 1fr);
            gap: 20px;
            height: calc(100vh - 290px);
            min-height: 580px;
        }

        /* Left Column: Catalogue */
        .pmo-catalogue-pane {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }
        .pmo-catalogue-header {
            padding: 12px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
        }
        .pmo-catalogue-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .pmo-target-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            background: #ffffff;
            cursor: pointer;
            transition: border-color 0.15s, transform 0.15s, box-shadow 0.15s;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .pmo-target-card:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
            transform: translateX(2px);
        }
        .pmo-target-card.is-selected {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.12);
        }
        .pmo-target-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .pmo-target-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pmo-badge {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .pmo-badge.page { background: #e0f2fe; color: #0284c7; }
        .pmo-badge.modal { background: #f3e8ff; color: #7e22ce; }
        .pmo-badge.sub-dts { background: #fef3c7; color: #b45309; }
        .pmo-badge.sub-rdp { background: #dcfce7; color: #15803d; }
        .pmo-badge.sub-dcs { background: #fee2e2; color: #b91c1c; }
        .pmo-badge.sub-admin { background: #e2e8f0; color: #334155; }
        .pmo-target-desc {
            font-size: 0.76rem;
            color: #64748b;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .pmo-target-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.72rem;
            margin-top: 2px;
            padding-top: 6px;
            border-top: 1px dashed #e2e8f0;
        }
        .pmo-status-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        .pmo-status-tag.assigned { color: #059669; }
        .pmo-status-tag.missing { color: #d97706; }

        /* Right Column: Inspector & Studio */
        .pmo-inspector-pane {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }
        .pmo-inspector-header {
            padding: 16px 20px;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            flex-shrink: 0;
        }
        .pmo-inspector-title h2 {
            margin: 0 0 2px 0;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: #ffffff;
        }
        .pmo-inspector-title code {
            font-size: 0.78rem;
            background: rgba(255, 255, 255, 0.12);
            color: #93c5fd;
            padding: 2px 7px;
            border-radius: 6px;
        }
        .pmo-tab-pills {
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            padding: 3px;
            border-radius: 8px;
            gap: 2px;
        }
        .pmo-tab-btn {
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pmo-tab-btn:hover { color: #ffffff; }
        .pmo-tab-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
        }

        /* Assignment & Control Strip */
        .pmo-control-strip {
            padding: 12px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            flex-shrink: 0;
        }
        .pmo-assign-select {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1 1 300px;
        }
        .pmo-select-input {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.82rem;
            color: #0f172a;
            background: #ffffff;
            outline: none;
        }
        .pmo-select-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .pmo-strip-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Inspector Body (Scrollable) */
        .pmo-inspector-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
        }

        /* Rendered Guide Preview (Identical to floating widget) */
        .pmo-preview-wrap {
            max-width: 680px;
            margin: 0 auto;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .pmo-purpose-callout {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #1e40af;
            line-height: 1.45;
            display: flex;
            gap: 10px;
        }
        .pmo-section-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            background: #ffffff;
        }
        .pmo-section-header {
            background: #f8fafc;
            padding: 11px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.88rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .pmo-section-badge {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            background: #e0f2fe;
            color: #0284c7;
            font-size: 0.75rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pmo-section-items {
            padding: 14px 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .pmo-item-row {
            display: flex;
            gap: 8px;
            font-size: 0.82rem;
            line-height: 1.45;
            color: #334155;
        }
        .pmo-item-dot { color: #3b82f6; font-weight: bold; }
        .pmo-item-label { font-weight: 700; color: #0f172a; margin-right: 4px; }
        .pmo-callout {
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 0.82rem;
            line-height: 1.45;
        }
        .pmo-callout.note { background: #f1f5f9; border-left: 4px solid #64748b; color: #334155; }
        .pmo-callout.tip { background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; }
        .pmo-callout.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }

        /* Code Editor Studio */
        .pmo-editor-wrap {
            display: flex;
            flex-direction: column;
            flex: 1;
            height: 100%;
            gap: 10px;
        }
        .pmo-editor-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            flex-wrap: wrap;
        }
        .pmo-tool-btn {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 0.76rem;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .pmo-tool-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
        .pmo-textarea {
            width: 100%;
            flex: 1;
            min-height: 380px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.88rem;
            line-height: 1.55;
            color: #0f172a;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 16px;
            box-sizing: border-box;
            outline: none;
            resize: none;
        }
        .pmo-textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        /* Buttons */
        .pmo-btn {
            padding: 8px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }
        .pmo-btn.primary {
            background: #2563eb;
            color: #ffffff;
        }
        .pmo-btn.primary:hover { background: #1d4ed8; }
        .pmo-btn.secondary {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #334155;
        }
        .pmo-btn.secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        .pmo-btn.danger {
            background: #ffffff;
            border-color: #fca5a5;
            color: #dc2626;
        }
        .pmo-btn.danger:hover { background: #fef2f2; border-color: #ef4444; }

        /* Modal Overlay */
        .pmo-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .pmo-modal-card {
            background: #ffffff;
            border-radius: 16px;
            width: 520px;
            max-width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: pmoPop 0.2s ease-out;
        }
        @keyframes pmoPop {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .pmo-modal-header {
            padding: 16px 20px;
            background: #0f172a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .pmo-modal-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .pmo-modal-footer {
            padding: 14px 20px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }
        .pmo-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .pmo-field label {
            font-size: 0.78rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .pmo-field input, .pmo-field select, .pmo-field textarea {
            padding: 9px 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #0f172a;
            outline: none;
        }
        .pmo-field input:focus, .pmo-field select:focus, .pmo-field textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .pmo-banner {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            border: 1px solid transparent;
        }
        .pmo-banner-success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }
        .pmo-banner-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .pmo-banner-close {
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 1rem;
            color: inherit;
        }

        /* Responsive */
        @media (max-width: 1100px) {
            .pmo-studio {
                grid-template-columns: 1fr;
                height: auto;
            }
            .pmo-catalogue-pane { height: 420px; }
        }

        /* Dark Mode */
        [data-theme="dark"] .pmo-container {
            color: #e2e8f0;
        }
        [data-theme="dark"] .pmo-top-title h1 {
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-top-title p {
            color: #94a3b8;
        }

        [data-theme="dark"] .pmo-stat-card,
        [data-theme="dark"] .pmo-filter-bar,
        [data-theme="dark"] .pmo-catalogue-pane,
        [data-theme="dark"] .pmo-inspector-pane,
        [data-theme="dark"] .pmo-section-card,
        [data-theme="dark"] .pmo-modal-card {
            background: #131c2e;
            border-color: #1e293b;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            color: #e2e8f0;
        }
        [data-theme="dark"] .pmo-stat-val {
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-stat-lbl {
            color: #94a3b8;
        }
        [data-theme="dark"] .pmo-stat-icon {
            background: #0f172a !important;
            border: 1px solid #334155;
        }

        [data-theme="dark"] .pmo-search-input {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-search-input:focus {
            background: #0f172a;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        [data-theme="dark"] .pmo-search-input::placeholder {
            color: #94a3b8;
        }
        [data-theme="dark"] .pmo-search-box i {
            color: #64748b;
        }

        [data-theme="dark"] .pmo-pill {
            background: #0f172a;
            border-color: #334155;
            color: #cbd5e1;
        }
        [data-theme="dark"] .pmo-pill:hover {
            background: #1e293b;
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-pill.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }

        [data-theme="dark"] .pmo-catalogue-header,
        [data-theme="dark"] .pmo-control-strip,
        [data-theme="dark"] .pmo-section-header,
        [data-theme="dark"] .pmo-editor-toolbar,
        [data-theme="dark"] .pmo-modal-footer {
            background: #0f172a;
            border-color: #1e293b;
            color: #cbd5e1;
        }
        [data-theme="dark"] .pmo-target-card {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .pmo-target-card:hover {
            background: #1e293b;
            border-color: #475569;
        }
        [data-theme="dark"] .pmo-target-card.is-selected {
            background: rgba(37, 99, 235, 0.18);
            border-color: #3b82f6;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }
        [data-theme="dark"] .pmo-target-title {
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-target-desc {
            color: #94a3b8;
        }
        [data-theme="dark"] .pmo-target-foot {
            border-top-color: #334155;
        }
        [data-theme="dark"] .pmo-target-foot code {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .pmo-status-tag.assigned {
            color: #34d399;
        }
        [data-theme="dark"] .pmo-status-tag.missing {
            color: #fbbf24;
        }
        [data-theme="dark"] .pmo-badge.page { background: #0c4a6e; color: #7dd3fc; }
        [data-theme="dark"] .pmo-badge.modal { background: #4c1d95; color: #d8b4fe; }
        [data-theme="dark"] .pmo-badge.sub-dts { background: #78350f; color: #fcd34d; }
        [data-theme="dark"] .pmo-badge.sub-rdp { background: #14532d; color: #86efac; }
        [data-theme="dark"] .pmo-badge.sub-dcs { background: #7f1d1d; color: #fca5a5; }
        [data-theme="dark"] .pmo-badge.sub-admin { background: #334155; color: #e2e8f0; }

        [data-theme="dark"] .pmo-inspector-body {
            background: #131c2e;
        }
        [data-theme="dark"] .pmo-tab-btn.active {
            background: #1e293b;
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-select-input {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .pmo-assign-select > span {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .pmo-purpose-callout {
            background: rgba(37, 99, 235, 0.15);
            border-left-color: #3b82f6;
            color: #bfdbfe;
        }
        [data-theme="dark"] .pmo-section-badge {
            background: #0c4a6e;
            color: #7dd3fc;
        }
        [data-theme="dark"] .pmo-item-row {
            color: #cbd5e1;
        }
        [data-theme="dark"] .pmo-item-label {
            color: #f8fafc;
        }
        [data-theme="dark"] .pmo-callout.note {
            background: #1e293b;
            border-left-color: #64748b;
            color: #cbd5e1;
        }
        [data-theme="dark"] .pmo-callout.tip {
            background: rgba(34, 197, 94, 0.12);
            border-left-color: #22c55e;
            color: #86efac;
        }
        [data-theme="dark"] .pmo-callout.warning {
            background: rgba(245, 158, 11, 0.12);
            border-left-color: #f59e0b;
            color: #fcd34d;
        }

        [data-theme="dark"] .pmo-tool-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .pmo-tool-btn:hover {
            background: #334155;
            border-color: #475569;
            color: #ffffff;
        }
        [data-theme="dark"] .pmo-textarea {
            background: #0b1220 !important;
            color: #e2e8f0 !important;
            border-color: #334155 !important;
            caret-color: #93c5fd;
        }
        [data-theme="dark"] .pmo-textarea::placeholder {
            color: #94a3b8 !important;
            opacity: 1;
        }
        [data-theme="dark"] .pmo-textarea:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        [data-theme="dark"] .pmo-btn.secondary {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .pmo-btn.secondary:hover {
            background: #334155;
            border-color: #475569;
            color: #ffffff;
        }
        [data-theme="dark"] .pmo-btn.danger {
            background: rgba(127, 29, 29, 0.35);
            border-color: #7f1d1d;
            color: #fca5a5;
        }
        [data-theme="dark"] .pmo-btn.danger:hover {
            background: rgba(127, 29, 29, 0.55);
            border-color: #ef4444;
            color: #fecaca;
        }

        [data-theme="dark"] .pmo-field label {
            color: #94a3b8;
        }
        [data-theme="dark"] .pmo-field input,
        [data-theme="dark"] .pmo-field select,
        [data-theme="dark"] .pmo-field textarea {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .pmo-modal-body {
            background: #131c2e;
            color: #e2e8f0;
        }
        [data-theme="dark"] .pmo-banner-success {
            background: rgba(6, 95, 70, 0.25);
            border-color: #065f46;
            color: #6ee7b7;
        }
        [data-theme="dark"] .pmo-banner-error {
            background: rgba(127, 29, 29, 0.3);
            border-color: #7f1d1d;
            color: #fca5a5;
        }
    </style>
@endpush

<div class="pmo-container">
    <!-- Top Bar -->
    <div class="pmo-top-bar">
        <div class="pmo-top-title">
            <h1>
                <i class="fa-solid fa-book-open" style="color: #0284c7;"></i>
                Page Manuals & Guide Manager
            </h1>
            <p>Assign, monitor, edit, and audit interactive page manuals and modal walkthroughs across all RMS subsystems.</p>
        </div>
        <div class="pmo-top-actions">
            <button type="button" class="pmo-btn secondary" wire:click="refreshCatalog">
                <i class="fa-solid fa-arrows-rotate"></i> Refresh Catalog
            </button>
            <button type="button" class="pmo-btn primary" wire:click="openCreateModal">
                <i class="fa-solid fa-plus"></i> Create Manual File
            </button>
        </div>
    </div>

    <!-- Feedback Banners -->
    @if($successMessage)
        <div class="pmo-banner pmo-banner-success">
            <span><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage }}</span>
            <button type="button" class="pmo-banner-close" wire:click="clearMessages">&times;</button>
        </div>
    @endif
    @if($errorMessage)
        <div class="pmo-banner pmo-banner-error">
            <span><i class="fa-solid fa-circle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage }}</span>
            <button type="button" class="pmo-banner-close" wire:click="clearMessages">&times;</button>
        </div>
    @endif

    <!-- Metrics Cards -->
    <div class="pmo-stats-grid">
        <div class="pmo-stat-card">
            <div>
                <div class="pmo-stat-val">{{ $totalTargets }}</div>
                <div class="pmo-stat-lbl">System Targets</div>
            </div>
            <div class="pmo-stat-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="fa-solid fa-layer-group"></i>
            </div>
        </div>
        <div class="pmo-stat-card">
            <div>
                <div class="pmo-stat-val" style="color: #059669;">{{ $assignedCount }}</div>
                <div class="pmo-stat-lbl">Assigned Guides</div>
            </div>
            <div class="pmo-stat-icon" style="background: #ecfdf5; color: #059669;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="pmo-stat-card">
            <div>
                <div class="pmo-stat-val" style="color: #d97706;">{{ $missingCount }}</div>
                <div class="pmo-stat-lbl">Missing Guides</div>
            </div>
            <div class="pmo-stat-icon" style="background: #fffbeb; color: #d97706;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
        <div class="pmo-stat-card">
            <div>
                <div class="pmo-stat-val" style="color: #0284c7;">{{ $totalFiles }}</div>
                <div class="pmo-stat-lbl">Manual Files (.md)</div>
            </div>
            <div class="pmo-stat-icon" style="background: #e0f2fe; color: #0284c7;">
                <i class="fa-regular fa-file-lines"></i>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="pmo-filter-bar">
        <div class="pmo-search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="text"
                class="pmo-search-input"
                placeholder="Search page name, route key, or assigned file..."
                wire:model.live.debounce.250ms="search"
            >
        </div>

        <div class="pmo-pills">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-right: 2px;">Subsystem:</span>
            <button type="button" class="pmo-pill {{ $subsystemFilter === '' ? 'active' : '' }}" wire:click="$set('subsystemFilter', '')">All</button>
            <button type="button" class="pmo-pill {{ $subsystemFilter === 'DTS' ? 'active' : '' }}" wire:click="$set('subsystemFilter', 'DTS')">DTS</button>
            <button type="button" class="pmo-pill {{ $subsystemFilter === 'RDP' ? 'active' : '' }}" wire:click="$set('subsystemFilter', 'RDP')">RDP</button>
            <button type="button" class="pmo-pill {{ $subsystemFilter === 'DCS' ? 'active' : '' }}" wire:click="$set('subsystemFilter', 'DCS')">DCS</button>
            <button type="button" class="pmo-pill {{ $subsystemFilter === 'ADMIN' ? 'active' : '' }}" wire:click="$set('subsystemFilter', 'ADMIN')">Admin</button>
            <button type="button" class="pmo-pill {{ $subsystemFilter === 'COMMON' ? 'active' : '' }}" wire:click="$set('subsystemFilter', 'COMMON')">Common</button>
        </div>

        <div class="pmo-pills">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-right: 2px;">Type:</span>
            <button type="button" class="pmo-pill {{ $typeFilter === '' ? 'active' : '' }}" wire:click="$set('typeFilter', '')">All</button>
            <button type="button" class="pmo-pill {{ $typeFilter === 'page' ? 'active' : '' }}" wire:click="$set('typeFilter', 'page')">Pages</button>
            <button type="button" class="pmo-pill {{ $typeFilter === 'modal' ? 'active' : '' }}" wire:click="$set('typeFilter', 'modal')">Modals</button>
        </div>

        <div class="pmo-pills">
            <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-right: 2px;">Status:</span>
            <button type="button" class="pmo-pill {{ $statusFilter === '' ? 'active' : '' }}" wire:click="$set('statusFilter', '')">All</button>
            <button type="button" class="pmo-pill {{ $statusFilter === 'assigned' ? 'active' : '' }}" wire:click="$set('statusFilter', 'assigned')">Assigned</button>
            <button type="button" class="pmo-pill {{ $statusFilter === 'missing' ? 'active' : '' }}" wire:click="$set('statusFilter', 'missing')">Missing</button>
        </div>
    </div>

    <!-- Studio Workspace -->
    <div class="pmo-studio">
        <!-- Left: Target Catalogue -->
        <div class="pmo-catalogue-pane">
            <div class="pmo-catalogue-header">
                <span>Targets Directory ({{ count($targets) }})</span>
                <span>Select to Inspect</span>
            </div>

            <div class="pmo-catalogue-list" wire:target="selectTarget" wire:loading.class="opacity-75">
                @forelse($targets as $target)
                    <div
                        wire:key="target-{{ $target['key'] }}"
                        class="pmo-target-card {{ $selectedTargetKey === $target['key'] ? 'is-selected' : '' }}"
                        wire:click="selectTarget('{{ $target['key'] }}')"
                    >
                        <div class="pmo-target-head">
                            <span class="pmo-target-title">{{ $target['name'] }}</span>
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <span class="pmo-badge {{ $target['type'] === 'modal' ? 'modal' : 'page' }}">
                                    {{ $target['type'] }}
                                </span>
                                <span class="pmo-badge sub-{{ strtolower($target['subsystem']) }}">
                                    {{ $target['subsystem'] }}
                                </span>
                            </div>
                        </div>

                        <div class="pmo-target-desc">{{ $target['description'] }}</div>

                        <div class="pmo-target-foot">
                            <code style="font-size: 0.72rem; color: #64748b;">{{ $target['key'] }}</code>

                            @if($target['has_manual'])
                                <span class="pmo-status-tag assigned" title="Assigned: {{ $target['assigned_file'] }}">
                                    <i class="fa-solid fa-check-circle"></i>
                                    {{ $target['step_count'] }} Steps
                                    @if($target['is_custom_mapped'])
                                        <i class="fa-solid fa-link" style="font-size: 0.65rem;" title="Custom Mapped"></i>
                                    @endif
                                </span>
                            @else
                                <span class="pmo-status-tag missing">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    Missing Guide
                                </span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div style="padding: 40px 20px; text-align: center; color: #94a3b8; font-size: 0.85rem;">
                        <i class="fa-solid fa-search" style="font-size: 2rem; margin-bottom: 8px; opacity: 0.4;"></i>
                        <p>No matching targets found for active filters.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Right: Manual Inspector & Studio -->
        <div class="pmo-inspector-pane">
            @if($activeTarget)
                <!-- Header -->
                <div class="pmo-inspector-header">
                    <div class="pmo-inspector-title">
                        <h2>{{ $activeTarget['name'] }}</h2>
                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px;">
                            <code>Target Key: {{ $activeTarget['key'] }}</code>
                            <span class="pmo-badge {{ $activeTarget['type'] === 'modal' ? 'modal' : 'page' }}">{{ $activeTarget['type'] }}</span>
                            <span class="pmo-badge sub-{{ strtolower($activeTarget['subsystem']) }}">{{ $activeTarget['subsystem'] }}</span>
                        </div>
                    </div>

                    <div class="pmo-tab-pills">
                        <button
                            type="button"
                            class="pmo-tab-btn {{ $viewMode === 'preview' ? 'active' : '' }}"
                            wire:click="setViewMode('preview')"
                        >
                            <i class="fa-solid fa-eye"></i> Rendered Preview
                        </button>
                        <button
                            type="button"
                            class="pmo-tab-btn {{ $viewMode === 'editor' ? 'active' : '' }}"
                            wire:click="setViewMode('editor')"
                        >
                            <i class="fa-solid fa-code"></i> Markdown Editor
                        </button>
                    </div>
                </div>

                <!-- Assignment Control Strip -->
                <div class="pmo-control-strip">
                    <div class="pmo-assign-select">
                        <span style="font-size: 0.78rem; font-weight: 700; color: #475569; white-space: nowrap;">
                            Assigned File:
                        </span>
                        <select class="pmo-select-input" wire:model="selectedManualFile">
                            <option value="">-- No File (Unassigned) --</option>
                            @foreach($allFiles as $file)
                                <option value="{{ $file['relative_path'] }}">
                                    [{{ $file['subsystem'] }}] {{ $file['relative_path'] }} ({{ $file['line_count'] }} steps)
                                </option>
                            @endforeach
                        </select>
                        <button
                            type="button"
                            class="pmo-btn primary"
                            wire:click="assignSelectedManual"
                            title="Assign selected file to this target"
                        >
                            <i class="fa-solid fa-link"></i> Link
                        </button>
                        @if($activeTarget['has_manual'] && !$isManualLoaded)
                            <button
                                type="button"
                                class="pmo-btn"
                                style="background: #0284c7; color: #ffffff; white-space: nowrap;"
                                wire:click="loadTargetManual"
                                title="Load and read this specific MD file"
                            >
                                <i class="fa-solid fa-file-arrow-down"></i> Load MD File
                            </button>
                        @endif
                    </div>

                    <div class="pmo-strip-actions">
                        @if($activeTarget['has_manual'])
                            <button
                                type="button"
                                class="pmo-btn danger"
                                wire:click="unassignTarget"
                                title="Unassign manual from this page"
                            >
                                <i class="fa-solid fa-link-slash"></i> Unassign
                            </button>
                            @if($activeTarget['is_custom_mapped'])
                                <button
                                    type="button"
                                    class="pmo-btn secondary"
                                    wire:click="resetToDefaultTarget"
                                    title="Reset to convention auto-discovery"
                                >
                                    <i class="fa-solid fa-rotate-left"></i> Reset
                                </button>
                            @endif
                        @else
                            <button
                                type="button"
                                class="pmo-btn primary"
                                wire:click="generateManualFromTemplate"
                                title="Generate standard template boilerplate"
                            >
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Template
                            </button>
                        @endif
                    </div>
                </div>

                <!-- Body -->
                <div class="pmo-inspector-body">
                    @if($viewMode === 'preview')
                        <!-- Live Guide Preview -->
                        @if($currentParsedManual)
                            <div class="pmo-preview-wrap">
                                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                                    <div>
                                        <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a;">{{ $currentParsedManual['title'] }}</h3>
                                        <span style="font-size: 0.75rem; color: #64748b;">
                                            File: <code>{{ $currentParsedManual['relative_path'] }}</code> &bull; {{ $currentParsedManual['line_count'] }} steps &bull; {{ round(($currentParsedManual['file_size'] ?? 0)/1024, 1) }} KB
                                        </span>
                                    </div>
                                    <button type="button" class="pmo-btn secondary" wire:click="setViewMode('editor')">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Markdown
                                    </button>
                                </div>

                                @if(!empty($currentParsedManual['purpose']))
                                    <div class="pmo-purpose-callout">
                                        <i class="fa-solid fa-circle-info" style="font-size: 1.1rem; flex-shrink: 0; margin-top: 2px;"></i>
                                        <span>{{ $currentParsedManual['purpose'] }}</span>
                                    </div>
                                @endif

                                @foreach($currentParsedManual['sections'] as $sIndex => $sec)
                                    <div class="pmo-section-card">
                                        <div class="pmo-section-header">
                                            <span class="pmo-section-badge">{{ $sIndex + 1 }}</span>
                                            <span>{{ $sec['title'] }}</span>
                                        </div>
                                        <div class="pmo-section-items">
                                            @foreach($sec['items'] as $item)
                                                <div class="pmo-item-row">
                                                    <span class="pmo-item-dot">&bull;</span>
                                                    <div>
                                                        @if(!empty($item['label']))
                                                             <strong class="pmo-item-label">{{ $item['label'] }}:</strong>
                                                        @endif
                                                        <span>{{ $item['text'] }}</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach

                                @if(!empty($currentParsedManual['callouts']))
                                    <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 6px;">
                                        @foreach($currentParsedManual['callouts'] as $callout)
                                            <div class="pmo-callout {{ $callout['type'] }}">
                                                <div style="font-weight: 700; margin-bottom: 2px; display: flex; align-items: center; gap: 6px;">
                                                    @if($callout['type'] === 'warning')
                                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                                    @elseif($callout['type'] === 'tip')
                                                        <i class="fa-solid fa-lightbulb"></i>
                                                    @else
                                                        <i class="fa-solid fa-info-circle"></i>
                                                    @endif
                                                    <span>{{ $callout['title'] }}</span>
                                                </div>
                                                <p style="margin: 0;">{{ $callout['text'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @elseif($activeTarget['has_manual'] && !$isManualLoaded)
                            <!-- On-Demand Load Prompt -->
                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; text-align: center; color: #64748b; padding: 60px 20px;">
                                <div style="width: 64px; height: 64px; border-radius: 999px; background: #f0f9ff; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 14px;">
                                    <i class="fa-regular fa-file-lines"></i>
                                </div>
                                <h3 style="margin: 0 0 6px 0; font-size: 1.15rem; color: #0f172a; font-weight: 700;">Manual File Assigned: <code>{{ $assignedRelativePath }}</code></h3>
                                <p style="max-width: 460px; font-size: 0.85rem; line-height: 1.5; margin: 0 0 20px 0; color: #64748b;">
                                    To keep the directory fast and save memory, content is loaded on demand. Click below to load and inspect this markdown file.
                                </p>
                                <button type="button" class="pmo-btn" style="background: #0284c7; color: #ffffff; padding: 10px 20px; font-size: 0.9rem;" wire:click="loadTargetManual">
                                    <i class="fa-solid fa-file-arrow-down"></i> Load MD File
                                </button>
                            </div>
                        @else
                            <!-- Empty State -->
                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; text-align: center; color: #64748b; padding: 60px 20px;">
                                <div style="width: 64px; height: 64px; border-radius: 999px; background: #fffbeb; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 14px;">
                                    <i class="fa-solid fa-file-circle-question"></i>
                                </div>
                                <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; color: #0f172a; font-weight: 700;">No Manual Assigned to this Target</h3>
                                <p style="max-width: 440px; font-size: 0.85rem; line-height: 1.45; margin: 0 0 20px 0;">
                                    When users visit <code>{{ $activeTarget['key'] }}</code>, the floating Page Guide FAB button will be hidden because no manual markdown file is linked yet.
                                </p>
                                <div style="display: flex; gap: 10px;">
                                    <button type="button" class="pmo-btn primary" wire:click="generateManualFromTemplate">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Template Boilerplate
                                    </button>
                                </div>
                            </div>
                        @endif
                    @else
                        <!-- Markdown Code Editor -->
                        @if($assignedRelativePath)
                            <div class="pmo-editor-wrap">
                                <!-- Formatting Toolbar -->
                                <div class="pmo-editor-toolbar">
                                    <button type="button" class="pmo-tool-btn" wire:click="insertMarkdownSnippet('step')">
                                        <i class="fa-solid fa-list-ol"></i> Add Step Section
                                    </button>
                                    <button type="button" class="pmo-tool-btn" wire:click="insertMarkdownSnippet('item')">
                                        <i class="fa-solid fa-list-ul"></i> Add Bullet Item
                                    </button>
                                    <button type="button" class="pmo-tool-btn" wire:click="insertMarkdownSnippet('note')">
                                        <i class="fa-solid fa-circle-info"></i> Note
                                    </button>
                                    <button type="button" class="pmo-tool-btn" wire:click="insertMarkdownSnippet('tip')">
                                        <i class="fa-solid fa-lightbulb"></i> Tip
                                    </button>
                                    <button type="button" class="pmo-tool-btn" wire:click="insertMarkdownSnippet('warning')">
                                        <i class="fa-solid fa-triangle-exclamation"></i> Warning
                                    </button>

                                    <div style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 0.72rem; color: #64748b;">
                                            Editing: <code>{{ $assignedRelativePath }}</code>
                                        </span>
                                        <button type="button" class="pmo-btn primary" wire:click="saveEditorContent" wire:loading.attr="disabled">
                                            <span wire:loading.remove wire:target="saveEditorContent"><i class="fa-solid fa-floppy-disk"></i> Save Changes</span>
                                            <span wire:loading wire:target="saveEditorContent">Saving…</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Textarea -->
                                <textarea
                                    class="pmo-textarea"
                                    wire:model="editorContent"
                                    placeholder="Enter manual Markdown content here..."
                                    spellcheck="false"
                                ></textarea>

                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: #64748b;">
                                    <span>Supports GFM Markdown, Purpose Callout (<code>> Purpose: ...</code>), Step Sections (<code>## Step 1</code>), and Alert Callouts (<code>[!NOTE]</code>, <code>[!TIP]</code>, <code>[!WARNING]</code>).</span>
                                    <button type="button" class="pmo-btn danger" onclick="confirm('Permanently delete this manual file from disk?') || event.stopImmediatePropagation()" wire:click="deleteAssignedFile">
                                        <i class="fa-solid fa-trash"></i> Delete File
                                    </button>
                                </div>
                            </div>
                        @else
                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; text-align: center; color: #64748b; padding: 60px 20px;">
                                <i class="fa-regular fa-file-code" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 12px;"></i>
                                <h3 style="margin: 0 0 6px 0; font-size: 1.1rem; color: #0f172a; font-weight: 700;">No Manual File Linked</h3>
                                <p style="max-width: 440px; font-size: 0.85rem; line-height: 1.45; margin: 0 0 16px 0;">
                                    Assign an existing manual file above or click Generate Template to start editing.
                                </p>
                                <button type="button" class="pmo-btn primary" wire:click="generateManualFromTemplate">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Template Boilerplate
                                </button>
                            </div>
                        @endif
                    @endif
                </div>
            @else
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; color: #94a3b8;">
                    <i class="fa-solid fa-arrow-left" style="font-size: 2rem; margin-bottom: 10px;"></i>
                    <p style="font-size: 0.95rem;">Select a page or modal target from the left catalogue to inspect or assign.</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Create Manual Modal -->
    @if($showCreateModal)
        <div class="pmo-modal-overlay" wire:click.self="closeCreateModal">
            <div class="pmo-modal-card">
                <div class="pmo-modal-header">
                    <span style="font-weight: 700; font-size: 1rem;"><i class="fa-solid fa-plus-circle" style="margin-right: 6px;"></i> Create New Manual File</span>
                    <button type="button" wire:click="closeCreateModal" style="background: transparent; border: none; color: #94a3b8; font-size: 1.25rem; cursor: pointer;">&times;</button>
                </div>

                <form wire:submit.prevent="submitCreateManual">
                    <div class="pmo-modal-body">
                        <div class="pmo-field">
                            <label>Subsystem Directory</label>
                            <select wire:model="newFileSubsystem" required>
                                <option value="dts">DTS (resources/manuals/dts/)</option>
                                <option value="rdp">RDP (resources/manuals/rdp/)</option>
                                <option value="dcs">DCS (resources/manuals/dcs/)</option>
                                <option value="admin">Admin (resources/manuals/admin/)</option>
                                <option value="modal">Modal (resources/manuals/modal/)</option>
                                <option value="common">Root / Common (resources/manuals/)</option>
                            </select>
                        </div>

                        <div class="pmo-field">
                            <label>Filename (.md)</label>
                            <input type="text" wire:model="newFileName" required placeholder="e.g. dts.scanner.md or transaction.details.modal.md">
                            @error('newFileName') <span style="color: #dc2626; font-size: 0.75rem;">{{ $message }}</span> @enderror
                        </div>

                        <div class="pmo-field">
                            <label>Document Title</label>
                            <input type="text" wire:model="newFileTitle" required placeholder="e.g. Scanner Terminal & Barcode Processing">
                            @error('newFileTitle') <span style="color: #dc2626; font-size: 0.75rem;">{{ $message }}</span> @enderror
                        </div>

                        <div class="pmo-field">
                            <label>Purpose Summary</label>
                            <textarea wire:model="newFilePurpose" rows="3" placeholder="Brief summary of what this guide explains to users..."></textarea>
                        </div>

                        @if($selectedTargetKey)
                            <div style="background: #eff6ff; padding: 10px 12px; border-radius: 8px; font-size: 0.78rem; color: #1e40af;">
                                <i class="fa-solid fa-info-circle"></i> This new file will automatically be assigned to <strong>{{ $selectedTargetKey }}</strong> upon creation.
                            </div>
                        @endif
                    </div>

                    <div class="pmo-modal-footer">
                        <button type="button" class="pmo-btn secondary" wire:click="closeCreateModal">Cancel</button>
                        <button type="submit" class="pmo-btn primary">Create & Assign File</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

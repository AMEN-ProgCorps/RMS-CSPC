<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - Backup & Recovery Manager')] class extends Component {
    use WithFileUploads;

    public string $successMessage = '';
    public string $errorMessage = '';

    // Backup & Recovery System State
    public array $backupsList = [];
    public string $backupStatus = 'unknown';
    public string $backupTestResult = '';
    public $uploadedBackupFile = null;
    public string $selectedTargetBackup = '';
    public bool $showRevertConfirmModal = false;
    public bool $isBackupProcessing = false;
    public string $backupConfirmInput = '';

    // Live Reverting State (Preload Modal Style)
    public bool $isReverting = false;
    public int $revertProgress = 0;
    public string $revertCurrentTable = '';
    public int $revertCurrentIndex = 0;
    public array $revertTablesList = [];
    public array $revertLogs = [];
    public string $revertTargetFilename = '';

    // Backup Selection & Modal State
    public bool $showCreateBackupModal = false;
    public string $customBackupLabel = '';
    public string $backupMode = 'full'; // 'full' or 'selective'
    public array $selectedBackupCategories = [
        'users',
        'offices',
        'roles',
        'flows',
        'dts',
        'rdp',
        'dcs',
        'chat',
        'logs'
    ];

    // Pagination & Search Filter State
    public int $currentPage = 1;
    public int $perPage = 5;
    public string $search = '';
    public string $filterType = 'all'; // 'all', 'full', 'selective'
    public string $filterSource = 'all'; // 'all', 'google', 'local'

    public function mount(): void
    {
        $this->checkBackups();
    }

    public function updatedSearch(): void
    {
        $this->currentPage = 1;
    }

    public function updatedFilterType(): void
    {
        $this->currentPage = 1;
    }

    public function updatedFilterSource(): void
    {
        $this->currentPage = 1;
    }

    public function updatedPerPage(): void
    {
        $this->currentPage = 1;
    }

    public function setPage(int $page): void
    {
        $totalPages = $this->getTotalPages();
        $this->currentPage = max(1, min($page, $totalPages));
    }

    public function previousPage(): void
    {
        if ($this->currentPage > 1) {
            $this->currentPage--;
        }
    }

    public function nextPage(): void
    {
        if ($this->currentPage < $this->getTotalPages()) {
            $this->currentPage++;
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->filterType = 'all';
        $this->filterSource = 'all';
        $this->currentPage = 1;
    }

    public function getFilteredBackups(): array
    {
        $list = $this->backupsList;

        if (!empty(trim($this->search))) {
            $term = strtolower(trim($this->search));
            $list = array_filter($list, function ($item) use ($term) {
                return str_contains(strtolower($item['filename']), $term)
                    || str_contains(strtolower($item['custom_label'] ?? ''), $term)
                    || str_contains(strtolower($item['date_formatted']), $term)
                    || str_contains(strtolower($item['type']), $term)
                    || str_contains(strtolower($item['source']), $term)
                    || str_contains(strtolower($item['size_formatted']), $term);
            });
        }

        if ($this->filterType === 'full') {
            $list = array_filter($list, fn($item) => ($item['type'] ?? '') === 'Full Snapshot');
        } elseif ($this->filterType === 'selective') {
            $list = array_filter($list, fn($item) => ($item['type'] ?? '') === 'Selective');
        }

        if ($this->filterSource === 'google') {
            $list = array_filter($list, fn($item) => str_contains($item['source'] ?? '', 'Google Drive'));
        } elseif ($this->filterSource === 'local') {
            $list = array_filter($list, fn($item) => str_contains($item['source'] ?? '', 'Local Only'));
        }

        return array_values($list);
    }

    public function getTotalFilteredCount(): int
    {
        return count($this->getFilteredBackups());
    }

    public function getTotalPages(): int
    {
        $count = $this->getTotalFilteredCount();
        return max(1, (int) ceil($count / $this->perPage));
    }

    public function getPaginatedBackups(): array
    {
        $filtered = $this->getFilteredBackups();
        $totalPages = $this->getTotalPages();
        if ($this->currentPage > $totalPages) {
            $this->currentPage = $totalPages;
        }

        $offset = ($this->currentPage - 1) * $this->perPage;
        return array_slice($filtered, $offset, $this->perPage);
    }

    public function getBackupCategoryDefinitions(): array
    {
        return [
            'users' => [
                'name' => 'Users & Accounts',
                'description' => 'User login accounts, profiles, security logs, tracking devices & security keys',
                'icon' => 'fa-solid fa-users',
                'color' => '#2563eb',
                'tables' => ['account', 'account_details', 'security_logs', 'security_status', 'tracking_devices_log'],
            ],
            'offices' => [
                'name' => 'Offices & Organizational Structure',
                'description' => 'Campus offices, units, clusters, cluster heads & external source offices',
                'icon' => 'fa-solid fa-building-columns',
                'color' => '#0891b2',
                'tables' => ['office', 'cluster', 'cluster_head', 'dts_source_office'],
            ],
            'roles' => [
                'name' => 'Roles, Clearances & System Settings',
                'description' => 'Role clearances, subsystem versions, global system config & personal settings',
                'icon' => 'fa-solid fa-sliders',
                'color' => '#7c3aed',
                'tables' => ['condition_key', 'condition_details', 'condition_defaults', 'subsystems', 'subsystem_versions_log', 'system_settings', 'personal_settings'],
            ],
            'flows' => [
                'name' => 'Transaction Flows',
                'description' => 'DTS routing flows, sequence rankings, action options & email access configs',
                'icon' => 'fa-solid fa-route',
                'color' => '#d97706',
                'tables' => ['dts_transaction_flow', 'dts_sequence_list', 'dts_action_options', 'dts_email_access'],
            ],
            'dts' => [
                'name' => 'Document Tracking System (DTS)',
                'description' => 'Transactions, document trans, copy-furnished targets, QR codes, revisions & logs',
                'icon' => 'fa-solid fa-file-contract',
                'color' => '#059669',
                'tables' => ['dts_transactions', 'dts_transaction_details', 'dts_document_trans', 'document_data', 'dts_copy_filled_transaction', 'dts_copy_filled_to_office', 'dts_qr_code', 'dts_transaction_version', 'dts_requestor_history', 'sub_document_tracking_system_logs', 'sub_document_tracking_system_logs_types'],
            ],
            'rdp' => [
                'name' => 'Records Disposition Package (RDP)',
                'description' => 'Archival records, document records, retention periods, series brackets & folder configs',
                'icon' => 'fa-solid fa-box-archive',
                'color' => '#475569',
                'tables' => ['rdp_record', 'rdp_record_series', 'rdp_record_series_brackets', 'rdp_record_series_type', 'rdp_recorded_value', 'rdp_frequence_use', 'rdp_restriction_type', 'rdp_utility_medium', 'rdp_time_value', 'rdp_volume_value', 'rdp_volume_conversion', 'rdp_document_record', 'rdp_duplication_section', 'rdp_grouped_record', 'rdp_grouped_record_series', 'rdp_pending_record', 'rdp_pending_record_series', 'rdp_pending_status', 'rdp_period_covered', 'rdp_retention_period', 'rdp_utility_manager', 'folder_data', 'main_pending_id'],
            ],
            'dcs' => [
                'name' => 'Document Control System (DCS)',
                'description' => 'Catalogs, registered documents, stamps, syllabi, reports, calendar & office junctions',
                'icon' => 'fa-solid fa-file-circle-check',
                'color' => '#1d4ed8',
                'tables' => [
                    'dcs_doc_types', 'dcs_version_type', 'dcs_originators', 'dcs_colleges', 'dcs_programs',
                    'dcs_semesters', 'dcs_school_years', 'dcs_faculties', 'dcs_program_courses', 'dcs_program_course_faculties',
                    'dcs_checklist_types', 'dcs_checklist_version', 'dcs_approval_body',
                    'dcs_document_requests', 'dcs_approval_records', 'dcs_document_change_notice', 'dcs_doc_revision',
                    'dcs_document_request_form', 'dcs_document_distribution', 'dcs_document_retrieval',
                    'dcs_masterlist_registration', 'dcs_syllabi', 'dcs_syllabi_drf', 'dcs_syllabi_monitoring_status',
                    'dcs_document_stamps',
                    'dcs_opcr_ratings', 'dcs_calendar_categories', 'dcs_calendar_events', 'dcs_report_templates',
                    'dcs_drf_offices', 'dcs_distribution_offices', 'dcs_retrieval_offices', 'dcs_dcn_offices',
                    'dcs_masterlist_source_offices', 'dcs_masterlist_related_docs',
                ],
            ],
            'chat' => [
                'name' => 'Chatify & Messaging System',
                'description' => 'Conversations, direct & group messages, reactions, read markers, chat backups & legal agreements',
                'icon' => 'fa-solid fa-comments',
                'color' => '#e11d48',
                'tables' => ['chat_conversations', 'chat_messages', 'chat_reactions', 'chat_read_markers', 'chatify_chat_backup', 'chat_notifications', 'chatify_legal_agreements'],
            ],
            'logs' => [
                'name' => 'Audit Trails & System Logs',
                'description' => 'Admin action history, security login logs, chat audit logs, notifications & system tracking logs',
                'icon' => 'fa-solid fa-clipboard-list',
                'color' => '#4b5563',
                'tables' => ['admin_logs', 'security_logs', 'chatify_audit_logs', 'notifications', 'notification_div', 'notif_content', 'sub_document_tracking_system_logs'],
            ],
        ];
    }

    public function openCreateBackupModal(): void
    {
        $this->showCreateBackupModal = true;
        $this->customBackupLabel = '';
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function selectAllCategories(): void
    {
        $this->selectedBackupCategories = array_keys($this->getBackupCategoryDefinitions());
    }

    public function deselectAllCategories(): void
    {
        $this->selectedBackupCategories = [];
    }

    public function ensureBackupDirectoriesExist(): void
    {
        // 1. Check & create local storage/app/backups folder
        try {
            if (!\Illuminate\Support\Facades\Storage::disk('local')->exists('backups')) {
                \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory('backups');
            }
        } catch (\Throwable $e) {
            logger()->warning("Failed creating local backups folder: " . $e->getMessage());
        }

        // 2. Check & create Google Drive backup/ folder
        try {
            if (!\Illuminate\Support\Facades\Storage::disk('google')->exists('backup')) {
                \Illuminate\Support\Facades\Storage::disk('google')->makeDirectory('backup');
            }
        } catch (\Throwable $e) {
            logger()->warning("Failed creating Google Drive backup folder: " . $e->getMessage());
        }
    }

    public function checkBackups(): void
    {
        $this->isBackupProcessing = true;
        $this->backupTestResult = '';
        $this->backupsList = [];

        try {
            $this->ensureBackupDirectoriesExist();

            $foundBackups = [];

            // 1. Scan Local storage/app/backups folder first (instant disk I/O)
            try {
                $localFiles = \Illuminate\Support\Facades\Storage::disk('local')->files('backups');
                foreach ($localFiles as $file) {
                    $filename = basename($file);
                    if (str_ends_with($filename, '.json') || str_starts_with($filename, 'rms_backup_') || str_contains($filename, 'rms_backup_')) {
                        $size = \Illuminate\Support\Facades\Storage::disk('local')->size($file);
                        $lastModified = \Illuminate\Support\Facades\Storage::disk('local')->lastModified($file);
                        $isCustom = str_contains($filename, 'custom');

                        $customLabel = null;
                        if (preg_match('/^\[(.*?)\](.*)$/', $filename, $matches)) {
                            $customLabel = $matches[1];
                        }

                        $foundBackups[$filename] = [
                            'filename' => $filename,
                            'custom_label' => $customLabel,
                            'path' => $file,
                            'source' => 'Local Only',
                            'source_icon' => 'fa-solid fa-hard-drive',
                            'source_color' => '#64748b',
                            'type' => $isCustom ? 'Selective' : 'Full Snapshot',
                            'type_badge' => $isCustom ? 'background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5;' : 'background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7;',
                            'type_icon' => $isCustom ? 'fa-solid fa-filter' : 'fa-solid fa-database',
                            'size_formatted' => $this->formatBytes($size),
                            'size' => $size,
                            'last_modified' => $lastModified,
                            'date_formatted' => date('M d, Y h:i A', $lastModified),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                logger()->warning("Check local backups warning: " . $e->getMessage());
            }

            // 2. Scan Google Drive backup/ folder via listContents (single API call with metadata)
            try {
                $googleItems = \Illuminate\Support\Facades\Storage::disk('google')->listContents('backup');
                foreach ($googleItems as $item) {
                    if (!$item->isFile()) continue;
                    $file = $item->path();
                    $filename = basename($file);
                    if (str_ends_with($filename, '.json') || str_starts_with($filename, 'rms_backup_') || str_contains($filename, 'rms_backup_')) {
                        if (isset($foundBackups[$filename])) {
                            $foundBackups[$filename]['source'] = 'Google Drive & Local';
                            $foundBackups[$filename]['source_icon'] = 'fa-solid fa-cloud-arrow-down';
                            $foundBackups[$filename]['source_color'] = '#059669';
                        } else {
                            $size = $item->fileSize() ?? 0;
                            $lastModified = $item->lastModified() ?? time();
                            $isCustom = str_contains($filename, 'custom');

                            $customLabel = null;
                            if (preg_match('/^\[(.*?)\](.*)$/', $filename, $matches)) {
                                $customLabel = $matches[1];
                            }

                            $foundBackups[$filename] = [
                                'filename' => $filename,
                                'custom_label' => $customLabel,
                                'path' => $file,
                                'source' => 'Google Drive',
                                'source_icon' => 'fa-brands fa-google-drive',
                                'source_color' => '#2563eb',
                                'type' => $isCustom ? 'Selective' : 'Full Snapshot',
                                'type_badge' => $isCustom ? 'background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5;' : 'background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7;',
                                'type_icon' => $isCustom ? 'fa-solid fa-filter' : 'fa-solid fa-database',
                                'size_formatted' => $this->formatBytes($size),
                                'size' => $size,
                                'last_modified' => $lastModified,
                                'date_formatted' => date('M d, Y h:i A', $lastModified),
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                logger()->warning("Check Google Drive backups warning: " . $e->getMessage());
            }

            // Sort by last modified descending (newest first)
            usort($foundBackups, fn($a, $b) => $b['last_modified'] <=> $a['last_modified']);

            $this->backupsList = array_values($foundBackups);
            $this->backupStatus = 'success';
            $count = count($this->backupsList);
            $this->backupTestResult = "✅ Checked backup storage. Found {$count} backup bundle(s) available.";

        } catch (\Throwable $e) {
            $this->backupStatus = 'error';
            $this->backupTestResult = "❌ Error checking backup storage: " . $e->getMessage();
        }

        $this->isBackupProcessing = false;
    }

    public function createBackup(): void
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $this->isBackupProcessing = true;
        $this->successMessage = '';
        $this->errorMessage = '';

        try {
            $this->ensureBackupDirectoriesExist();

            $categoriesDef = $this->getBackupCategoryDefinitions();
            $tablesToBackup = [];
            $categoriesIncluded = [];

            if ($this->backupMode === 'selective') {
                if (empty($this->selectedBackupCategories)) {
                    $this->errorMessage = 'Please select at least one category to back up.';
                    $this->isBackupProcessing = false;
                    return;
                }

                $allDbTables = $this->getAllDatabaseTables();
                foreach ($this->selectedBackupCategories as $catKey) {
                    if (isset($categoriesDef[$catKey])) {
                        $categoriesIncluded[] = $categoriesDef[$catKey]['name'];
                        foreach ($categoriesDef[$catKey]['tables'] as $tbl) {
                            if (in_array($tbl, $allDbTables)) {
                                $tablesToBackup[] = $tbl;
                            }
                        }
                    }
                }
                $tablesToBackup = array_unique(array_values($tablesToBackup));
            } else {
                $tablesToBackup = $this->getAllDatabaseTables();
                $categoriesIncluded = array_map(fn($c) => $c['name'], array_values($categoriesDef));
            }

            if (empty($tablesToBackup)) {
                $this->errorMessage = 'No database tables found matching the selected backup criteria.';
                $this->isBackupProcessing = false;
                return;
            }

            $label = trim($this->customBackupLabel);
            $cleanLabel = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $label);
            $cleanLabel = trim($cleanLabel);

            $backupData = [
                'app_name' => config('app.name', 'RMS CSPC'),
                'version' => '1.0',
                'custom_label' => !empty($cleanLabel) ? $cleanLabel : null,
                'backup_mode' => $this->backupMode,
                'categories_included' => $categoriesIncluded,
                'created_at' => now()->toIso8601String(),
                'created_by' => auth()->user()?->email ?: 'Admin',
                'tables_count' => count($tablesToBackup),
                'total_records' => 0,
                'tables' => [],
            ];

            $totalRecords = 0;
            $isDtsIncluded = ($this->backupMode === 'full') || in_array('dts', $this->selectedBackupCategories);

            foreach ($tablesToBackup as $table) {
                try {
                    $query = \DB::table($table);

                    // If backing up transaction flows without DTS document transactions, filter out active transaction instance flows (REF- / Flow for ...)
                    if ($table === 'dts_transaction_flow' && !$isDtsIncluded) {
                        $query->where(function ($q) {
                            $q->whereNull('flow_code')
                              ->orWhere('flow_code', 'not like', 'REF-%');
                        })->where(function ($q) {
                            if (\Schema::hasColumn('dts_transaction_flow', 'referenced_flow')) {
                                $q->whereNull('referenced_flow')
                                  ->orWhere('referenced_flow', 'not like', 'REF-%');
                            }
                        })->where(function ($q) {
                            $q->whereNull('flow_name')
                              ->orWhere('flow_name', 'not like', 'Flow for %');
                        });
                    } elseif ($table === 'dts_sequence_list' && !$isDtsIncluded) {
                        $masterFlowIds = \DB::table('dts_transaction_flow')
                            ->where(function ($q) {
                                $q->whereNull('flow_code')
                                  ->orWhere('flow_code', 'not like', 'REF-%');
                            })->where(function ($q) {
                                if (\Schema::hasColumn('dts_transaction_flow', 'referenced_flow')) {
                                    $q->whereNull('referenced_flow')
                                      ->orWhere('referenced_flow', 'not like', 'REF-%');
                                }
                            })->where(function ($q) {
                                $q->whereNull('flow_name')
                                  ->orWhere('flow_name', 'not like', 'Flow for %');
                            })
                            ->pluck('id');

                        $query->whereIn('control_id', $masterFlowIds);
                    }

                    $rows = $query->get()->map(function ($item) {
                        return (array) $item;
                    })->toArray();

                    $backupData['tables'][$table] = $rows;
                    $totalRecords += count($rows);
                } catch (\Throwable $e) {
                    logger()->warning("Failed backing up table {$table}: " . $e->getMessage());
                }
            }

            $backupData['total_records'] = $totalRecords;

            $jsonContent = json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $modePrefix = ($this->backupMode === 'selective') ? 'rms_backup_custom_' : 'rms_backup_full_';
            $baseFilename = $modePrefix . date('Y-m-d_His') . '.json';
            $filename = !empty($cleanLabel) ? "[{$cleanLabel}]{$baseFilename}" : $baseFilename;

            // 1. Save to local storage
            \Illuminate\Support\Facades\Storage::disk('local')->put("backups/{$filename}", $jsonContent);

            // 2. Save to Google Drive backup/ folder
            $driveSaved = false;
            try {
                $driveSaved = \Illuminate\Support\Facades\Storage::disk('google')->put("backup/{$filename}", $jsonContent);
            } catch (\Throwable $e) {
                logger()->error("Failed uploading backup to Google Drive: " . $e->getMessage());
            }

            $typeLabel = ($this->backupMode === 'selective') ? "Selective Backup (" . count($categoriesIncluded) . " categories)" : "Full System Backup";

            \DB::table('admin_logs')->insert([
                'changes' => "Created {$typeLabel} [{$filename}] ({$totalRecords} records across " . count($tablesToBackup) . " tables). Saved to " . ($driveSaved ? "Google Drive & Local" : "Local Storage"),
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->showCreateBackupModal = false;
            $this->customBackupLabel = '';
            $this->successMessage = "🎉 {$typeLabel} [{$filename}] created successfully! (" . count($tablesToBackup) . " tables, {$totalRecords} total records backupped).";

            $this->checkBackups();

        } catch (\Throwable $e) {
            $this->errorMessage = "❌ Failed to create backup: " . $e->getMessage();
        }

        $this->isBackupProcessing = false;
    }

    public function downloadBackup(string $filename)
    {
        $filename = basename($filename);
        $localPath = "backups/{$filename}";
        $googlePath = "backup/{$filename}";

        $content = null;

        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($localPath)) {
            $content = \Illuminate\Support\Facades\Storage::disk('local')->get($localPath);
        } elseif (\Illuminate\Support\Facades\Storage::disk('google')->exists($googlePath)) {
            $content = \Illuminate\Support\Facades\Storage::disk('google')->get($googlePath);
            try {
                \Illuminate\Support\Facades\Storage::disk('local')->put($localPath, $content);
            } catch (\Throwable) {}
        }

        if (!$content) {
            $this->errorMessage = "Requested backup file [{$filename}] could not be found for download.";
            return;
        }

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function importBackup(): void
    {
        $this->isBackupProcessing = true;
        $this->successMessage = '';
        $this->errorMessage = '';

        try {
            if (!$this->uploadedBackupFile) {
                $this->errorMessage = 'Please select a valid backup JSON file to import.';
                $this->isBackupProcessing = false;
                return;
            }

            $rawJson = file_get_contents($this->uploadedBackupFile->getRealPath());
            $decoded = json_decode($rawJson, true);

            if (!$decoded || !is_array($decoded) || !isset($decoded['tables'])) {
                $this->errorMessage = 'Invalid backup file format. The file must be a valid RMS JSON backup payload.';
                $this->isBackupProcessing = false;
                return;
            }

            $origName = $this->uploadedBackupFile->getClientOriginalName();
            $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $origName);
            if (!str_ends_with($cleanName, '.json')) {
                $cleanName = 'rms_imported_' . date('Y-m-d_His') . '.json';
            }

            // Save to Local
            \Illuminate\Support\Facades\Storage::disk('local')->put("backups/{$cleanName}", $rawJson);

            // Save to Google Drive
            $driveSaved = false;
            try {
                $driveSaved = \Illuminate\Support\Facades\Storage::disk('google')->put("backup/{$cleanName}", $rawJson);
            } catch (\Throwable) {}

            \DB::table('admin_logs')->insert([
                'changes' => "Imported external backup file [{$cleanName}] to storage inventory (" . count($decoded['tables']) . " tables payload)",
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->uploadedBackupFile = null;
            $this->successMessage = "🎉 Backup file [{$cleanName}] imported & registered successfully!";
            $this->checkBackups();

        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to import backup file: ' . $e->getMessage();
        }

        $this->isBackupProcessing = false;
    }

    public function confirmRevertModal(string $filename): void
    {
        $this->selectedTargetBackup = basename($filename);
        $this->backupConfirmInput = '';
        $this->showRevertConfirmModal = true;
    }

    public function cancelRevertModal(): void
    {
        $this->showRevertConfirmModal = false;
        $this->selectedTargetBackup = '';
        $this->backupConfirmInput = '';
    }

    public function getTablePriorityMap(): array
    {
        return [
            // Priority Tier 1: Lookups & Independent Parent Tables
            'condition_key' => 1,
            'condition_details' => 2,
            'condition_defaults' => 3,
            'subsystems' => 4,
            'subsystem_versions_log' => 5,
            'system_settings' => 6,
            'office' => 8,
            'cluster' => 9,
            'security_status' => 10,
            'sub_document_tracking_system_logs_types' => 11,
            'document_data' => 12,
            'dts_qr_code' => 13,
            'notif_content' => 14,
            'rdp_record_series_type' => 15,
            'rdp_recorded_value' => 16,
            'rdp_frequence_use' => 17,
            'rdp_restriction_type' => 18,
            'rdp_utility_medium' => 19,
            'rdp_time_value' => 20,
            'rdp_volume_value' => 21,
            'rdp_volume_conversion' => 22,
            'rdp_pending_status' => 23,
            'rdp_record_series' => 24,
            'rdp_record_series_brackets' => 25,
            'folder_data' => 26,
            'main_pending_id' => 27,

            // Priority Tier 2: Accounts & User Relations (Depends on condition_key, office, cluster)
            'account' => 30,
            'account_details' => 31,
            'personal_settings' => 32,
            'cluster_head' => 33,
            'dts_source_office' => 34,
            'security_logs' => 35,
            'tracking_devices_log' => 36,

            // Priority Tier 3: Workflow, Options & Transaction Settings (Depends on account, office)
            'dts_transaction_flow' => 40,
            'dts_sequence_list' => 41,
            'dts_action_options' => 42,
            'dts_email_access' => 43,

            // Priority Tier 4: Transactions, Documents, Chat & Messages
            'dts_transactions' => 50,
            'dts_transaction_details' => 51,
            'dts_document_trans' => 52,
            'dts_copy_filled_transaction' => 53,
            'dts_copy_filled_to_office' => 54,
            'dts_transaction_version' => 55,
            'dts_requestor_history' => 56,
            'sub_document_tracking_system_logs' => 57,
            'rdp_record' => 60,
            'rdp_document_record' => 61,
            'rdp_grouped_record' => 62,
            'rdp_grouped_record_series' => 63,
            'rdp_pending_record' => 64,
            'rdp_pending_record_series' => 65,
            'rdp_period_covered' => 66,
            'rdp_retention_period' => 67,
            'rdp_utility_manager' => 68,
            'rdp_duplication_section' => 69,
            'chat_conversations' => 70,
            'chat_messages' => 71,
            'chat_reactions' => 72,
            'chat_read_markers' => 73,
            'chatify_chat_backup' => 74,
            'chat_notifications' => 75,
            'chatify_legal_agreements' => 76,

            // Priority Tier 5: Audit & Notification Logs (Depends on account, subsystems, etc.)
            'notifications' => 80,
            'notification_div' => 81,
            'chatify_audit_logs' => 82,
            'admin_logs' => 83,

            // Priority Tier 6: DCS catalog, then documents, then junctions (Depends on office)
            'dcs_doc_types' => 90,
            'dcs_version_type' => 91,
            'dcs_originators' => 92,
            'dcs_colleges' => 93,
            'dcs_programs' => 94,
            'dcs_semesters' => 95,
            'dcs_school_years' => 96,
            'dcs_faculties' => 97,
            'dcs_program_courses' => 98,
            'dcs_program_course_faculties' => 99,
            'dcs_checklist_types' => 100,
            'dcs_checklist_version' => 101,
            'dcs_approval_body' => 102,
            'dcs_document_requests' => 110,
            'dcs_approval_records' => 111,
            'dcs_document_change_notice' => 112,
            'dcs_doc_revision' => 113,
            'dcs_document_request_form' => 114,
            'dcs_document_distribution' => 115,
            'dcs_document_retrieval' => 116,
            'dcs_masterlist_registration' => 117,
            'dcs_syllabi' => 118,
            'dcs_syllabi_drf' => 119,
            'dcs_document_stamps' => 120,
            'dcs_opcr_ratings' => 121,
            'dcs_calendar_categories' => 122,
            'dcs_calendar_events' => 123,
            'dcs_report_templates' => 124,
            'dcs_drf_offices' => 130,
            'dcs_distribution_offices' => 131,
            'dcs_retrieval_offices' => 132,
            'dcs_dcn_offices' => 133,
            'dcs_masterlist_source_offices' => 134,
            'dcs_masterlist_related_docs' => 135,
            'dcs_syllabi_monitoring_status' => 136,
        ];
    }

    public function startRevertProcess(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';

        if (trim(strtoupper($this->backupConfirmInput)) !== 'REVERT') {
            $this->errorMessage = 'Authorization failed: You must type REVERT to confirm the system database restoration.';
            return;
        }

        $filename = basename($this->selectedTargetBackup);
        $localPath = "backups/{$filename}";
        $googlePath = "backup/{$filename}";

        $jsonContent = null;
        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($localPath)) {
            $jsonContent = \Illuminate\Support\Facades\Storage::disk('local')->get($localPath);
        } elseif (\Illuminate\Support\Facades\Storage::disk('google')->exists($googlePath)) {
            $jsonContent = \Illuminate\Support\Facades\Storage::disk('google')->get($googlePath);
            try {
                \Illuminate\Support\Facades\Storage::disk('local')->put($localPath, $jsonContent);
            } catch (\Throwable) {}
        }

        if (!$jsonContent) {
            $this->errorMessage = "Target backup file [{$filename}] could not be retrieved from storage.";
            $this->showRevertConfirmModal = false;
            return;
        }

        $payload = json_decode($jsonContent, true);
        if (!$payload || !isset($payload['tables']) || !is_array($payload['tables'])) {
            $this->errorMessage = "Failed to parse target backup file [{$filename}]. Invalid or corrupted JSON content.";
            $this->showRevertConfirmModal = false;
            return;
        }

        $priorityMap = $this->getTablePriorityMap();
        $tablesData = $payload['tables'];

        // Insertion order: forward priority order (parents first)
        uksort($tablesData, function ($a, $b) use ($priorityMap) {
            $pA = $priorityMap[$a] ?? 50;
            $pB = $priorityMap[$b] ?? 50;
            return $pA <=> $pB;
        });

        $this->revertTablesList = array_keys($tablesData);
        $this->revertTargetFilename = $filename;
        $this->showRevertConfirmModal = false;
        $this->isReverting = true;
        $this->revertProgress = 0;
        $this->revertCurrentIndex = 0;
        $this->revertCurrentTable = 'Initializing...';
        $this->revertLogs = [
            "- Initializing database restoration sequence from [{$filename}]...",
            "- Payload validated: " . count($tablesData) . " tables ready for restoration."
        ];

        // Store payload in cache for step execution
        \Illuminate\Support\Facades\Cache::put('rms_revert_payload_' . auth()->id(), $payload, now()->addMinutes(15));

        $this->executeRevertStep(0);
    }

    public function executeRevertStep(int $step): void
    {
        if (!$this->isReverting) {
            return;
        }

        @set_time_limit(120);

        $payload = \Illuminate\Support\Facades\Cache::get('rms_revert_payload_' . auth()->id());
        if (!$payload || !isset($payload['tables'])) {
            $this->isReverting = false;
            $this->errorMessage = 'Restoration session expired or payload missing.';
            return;
        }

        $driver = \DB::getDriverName();
        $totalTables = count($this->revertTablesList);

        // Step 0: Disable constraints & clear existing database
        if ($step === 0) {
            $this->revertLogs[] = "- Disabling foreign key constraints & preparing {$driver} database...";
            try {
                if ($driver === 'pgsql') {
                    try {
                        \DB::statement("SET session_replication_role = 'replica';");
                    } catch (\Throwable) {
                        \DB::statement('SET CONSTRAINTS ALL DEFERRED;');
                    }
                } elseif ($driver === 'sqlite') {
                    \DB::statement('PRAGMA foreign_keys = OFF;');
                } elseif ($driver === 'mysql') {
                    \DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
                }
                $this->revertLogs[] = "- Foreign key constraints suspended.";
            } catch (\Throwable $e) {
                $this->revertLogs[] = "- Notice while configuring constraints: " . $e->getMessage();
            }

            // Deletion order: reverse priority (children first)
            $priorityMap = $this->getTablePriorityMap();
            $deletionOrder = $this->revertTablesList;
            usort($deletionOrder, function ($a, $b) use ($priorityMap) {
                $pA = $priorityMap[$a] ?? 50;
                $pB = $priorityMap[$b] ?? 50;
                return $pB <=> $pA;
            });

            $this->revertLogs[] = "- Clearing existing table data in reverse dependency order...";
            foreach ($deletionOrder as $tbl) {
                if (in_array($tbl, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])) continue;
                if (\Schema::hasTable($tbl)) {
                    try {
                        \DB::table($tbl)->delete();
                    } catch (\Throwable) {}
                }
            }
            $this->revertLogs[] = "- Existing records cleared.";

            $this->revertProgress = 5;
            $this->revertCurrentIndex = 0;
            $this->revertCurrentTable = $this->revertTablesList[0] ?? 'Ready';
            $this->js('$wire.executeRevertStep(1)');
            return;
        }

        // Steps 1 to N: Process table by table
        $tableIndex = $step - 1;
        if ($tableIndex < $totalTables) {
            $tableName = $this->revertTablesList[$tableIndex];
            $rows = $payload['tables'][$tableName] ?? [];
            $this->revertCurrentTable = $tableName;
            $this->revertCurrentIndex = $tableIndex + 1;

            if (\Schema::hasTable($tableName) && !in_array($tableName, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])) {
                $count = count($rows);
                $this->revertLogs[] = "- Restoring table [{$tableName}] ({$count} records)...";

                if (!empty($rows)) {
                    $dbColumns = array_flip(\Schema::getColumnListing($tableName));
                    $chunks = array_chunk($rows, 200);
                    foreach ($chunks as $chunk) {
                        $filteredChunk = [];
                        foreach ($chunk as $row) {
                            $filteredRow = array_intersect_key((array) $row, $dbColumns);
                            if (!empty($filteredRow)) {
                                $filteredChunk[] = $filteredRow;
                            }
                        }
                        if (!empty($filteredChunk)) {
                            \DB::table($tableName)->insert($filteredChunk);
                        }
                    }
                }
                $this->revertLogs[] = "- Table [{$tableName}] restored successfully.";
            } else {
                $this->revertLogs[] = "- Skipped [{$tableName}] (table does not exist in schema).";
            }

            $this->revertProgress = (int) round(5 + (($this->revertCurrentIndex / $totalTables) * 90));
            $nextStep = $step + 1;
            $this->js('$wire.executeRevertStep(' . $nextStep . ')');
            return;
        }

        // Final Step: Complete restoration, sequences & re-enable constraints
        $this->revertLogs[] = "- Synchronizing database sequences & role references...";

        // Check condition_key fallback
        if (\Schema::hasTable('account') && \Schema::hasTable('condition_key')) {
            $missingRoles = \DB::table('account')
                ->whereNotNull('account_role')
                ->whereNotIn('account_role', \DB::table('condition_key')->pluck('id'))
                ->pluck('account_role')
                ->unique();

            if ($missingRoles->isNotEmpty()) {
                $firstModifierKey = \DB::table('condition_details')->value('key_id') ?: 1;
                foreach ($missingRoles as $roleId) {
                    try {
                        \DB::table('condition_key')->insert([
                            'id' => $roleId,
                            'key_name' => "Role #{$roleId}",
                            'modifier_key' => $firstModifierKey,
                            'date_created' => now(),
                            'date_updated' => now(),
                        ]);
                    } catch (\Throwable) {}
                }
            }
        }

        // Resync PostgreSQL sequences if applicable
        if ($driver === 'pgsql') {
            $seqTables = \DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
            foreach ($seqTables as $st) {
                $tbl = $st->table_name;
                try {
                    $columns = \Schema::getColumnListing($tbl);
                    foreach ($columns as $col) {
                        $seq = \DB::selectOne("SELECT pg_get_serial_sequence(?, ?) AS seq", ["\"{$tbl}\"", $col]);
                        $seqName = $seq->seq ?? null;
                        if ($seqName) {
                            $maxId = \DB::table($tbl)->max($col) ?: 0;
                            $seqVal = max(1, (int) $maxId);
                            $isCalled = $maxId > 0;
                            \DB::statement("SELECT setval(?, ?, ?)", [$seqName, $seqVal, $isCalled]);
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        // Log action
        try {
            $adminId = auth()->id();
            if (\Schema::hasTable('account') && (!$adminId || !\DB::table('account')->where('id', $adminId)->exists())) {
                $adminId = \DB::table('account')->value('id');
            }
            $whatSystem = 3;
            if (\Schema::hasTable('subsystems') && !\DB::table('subsystems')->where('subsystem_id', $whatSystem)->exists()) {
                $whatSystem = \DB::table('subsystems')->value('subsystem_id');
            }
            if ($adminId && $whatSystem && \Schema::hasTable('admin_logs')) {
                \DB::table('admin_logs')->insert([
                    'changes' => "EMERGENCY REVERT PERFORMED: System database restored to target backup [{$this->revertTargetFilename}]",
                    'admin_id' => $adminId,
                    'what_system' => $whatSystem,
                    'when_changes' => now(),
                ]);
            }
        } catch (\Throwable) {}

        // Re-enable constraints
        if ($driver === 'pgsql') {
            try {
                \DB::statement("SET session_replication_role = 'origin';");
            } catch (\Throwable) {}
        } elseif ($driver === 'sqlite') {
            \DB::statement('PRAGMA foreign_keys = ON;');
        } elseif ($driver === 'mysql') {
            \DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }

        \Illuminate\Support\Facades\Cache::forget('rms_revert_payload_' . auth()->id());
        $this->revertProgress = 100;
        $this->revertLogs[] = "- System database successfully restored from [{$this->revertTargetFilename}] (100%)!";
        $this->successMessage = "System successfully reverted to target backup [{$this->revertTargetFilename}]! All records have been restored.";
        $this->isReverting = false;
        $this->selectedTargetBackup = '';
        $this->backupConfirmInput = '';
        $this->checkBackups();
    }

    public function cancelRevert(): void
    {
        $this->isReverting = false;
        \Illuminate\Support\Facades\Cache::forget('rms_revert_payload_' . auth()->id());
        $this->errorMessage = 'Database revert operation was cancelled by administrator.';
    }

    public function deleteBackup(string $filename): void
    {
        $filename = basename($filename);
        $localPath = "backups/{$filename}";
        $googlePath = "backup/{$filename}";

        try {
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists($localPath)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($localPath);
            }

            if (\Illuminate\Support\Facades\Storage::disk('google')->exists($googlePath)) {
                \Illuminate\Support\Facades\Storage::disk('google')->delete($googlePath);
            }

            \DB::table('admin_logs')->insert([
                'changes' => "Deleted backup file [{$filename}] from Google Drive & Local storage",
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->successMessage = "Backup file [{$filename}] deleted successfully.";
            $this->checkBackups();

        } catch (\Throwable $e) {
            $this->errorMessage = "Failed to delete backup file: " . $e->getMessage();
        }
    }

    protected function getAllDatabaseTables(): array
    {
        $driver = \DB::getDriverName();
        $tables = [];

        if ($driver === 'pgsql') {
            $results = \DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
            foreach ($results as $row) {
                $tables[] = $row->table_name;
            }
        } elseif ($driver === 'sqlite') {
            $results = \DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($results as $row) {
                $tables[] = $row->name;
            }
        } else {
            $results = \DB::select('SHOW TABLES');
            foreach ($results as $row) {
                $arr = (array) $row;
                $tables[] = reset($arr);
            }
        }

        return array_values(array_filter($tables, fn($t) => !in_array($t, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])));
    }

    protected function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}; ?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* Dark mode container & card backgrounds */
        [data-theme="dark"] div[style*="background: #ffffff"],
        [data-theme="dark"] div[style*="background:#ffffff"],
        [data-theme="dark"] div[style*="background: white"],
        [data-theme="dark"] div[style*="background:white"] {
            background-color: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        /* Dark mode secondary backgrounds & filter bars */
        [data-theme="dark"] div[style*="background: #f8fafc"],
        [data-theme="dark"] div[style*="background:#f8fafc"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
        }

        /* Dark mode typography colors */
        [data-theme="dark"] h1[style*="color: #0f172a"],
        [data-theme="dark"] h2[style*="color: #0f172a"],
        [data-theme="dark"] h3[style*="color: #0f172a"],
        [data-theme="dark"] h4[style*="color: #0f172a"],
        [data-theme="dark"] div[style*="color: #0f172a"],
        [data-theme="dark"] span[style*="color: #0f172a"],
        [data-theme="dark"] strong[style*="color: #0f172a"],
        [data-theme="dark"] b[style*="color: #0f172a"],
        [data-theme="dark"] td[style*="color: #0f172a"],
        [data-theme="dark"] p[style*="color: #0f172a"] {
            color: #f8fafc !important;
        }

        [data-theme="dark"] div[style*="color: #334155"],
        [data-theme="dark"] span[style*="color: #334155"],
        [data-theme="dark"] td[style*="color: #334155"],
        [data-theme="dark"] label[style*="color: #334155"] {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] p[style*="color: #64748b"],
        [data-theme="dark"] span[style*="color: #64748b"],
        [data-theme="dark"] div[style*="color: #64748b"],
        [data-theme="dark"] td[style*="color: #64748b"] {
            color: #94a3b8 !important;
        }

        /* Dark mode inputs & selects */
        [data-theme="dark"] input[type="text"],
        [data-theme="dark"] select {
            background-color: #0b1120 !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] input[type="text"]::placeholder {
            color: #64748b !important;
        }

        /* Dark mode table & cells */
        [data-theme="dark"] table {
            background-color: #131c2e !important;
        }
        [data-theme="dark"] table thead tr {
            background-color: #0b1120 !important;
            color: #94a3b8 !important;
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] table thead th {
            color: #94a3b8 !important;
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] table tbody tr {
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] table tbody td {
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] table tbody tr:hover td,
        [data-theme="dark"] table tbody tr:hover {
            background-color: #1e293b !important;
        }

        /* Badges and pills */
        [data-theme="dark"] span[style*="background: #f8fafc"],
        [data-theme="dark"] span[style*="background:#f8fafc"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] span[style*="background: #fff7ed"],
        [data-theme="dark"] span[style*="background:#fff7ed"] {
            background-color: rgba(234, 88, 12, 0.15) !important;
            color: #fb923c !important;
            border-color: rgba(234, 88, 12, 0.3) !important;
        }
        [data-theme="dark"] span[style*="background: #f0fdf4"],
        [data-theme="dark"] span[style*="background:#f0fdf4"] {
            background-color: rgba(34, 197, 94, 0.15) !important;
            color: #4ade80 !important;
            border-color: rgba(34, 197, 94, 0.3) !important;
        }

        /* Pagination buttons */
        [data-theme="dark"] button[style*="background: #ffffff"],
        [data-theme="dark"] button[style*="background:#ffffff"] {
            background-color: #1e293b !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] button[style*="background: #f8fafc"],
        [data-theme="dark"] button[style*="background:#f8fafc"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
            color: #64748b !important;
        }

        /* Selective category labels in modal */
        [data-theme="dark"] label[style*="background: #ffffff"],
        [data-theme="dark"] label[style*="background:#ffffff"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
        }

        /* Dividers & Borders */
        [data-theme="dark"] div[style*="border-bottom: 1px solid #f1f5f9"],
        [data-theme="dark"] div[style*="border-top: 1px solid #f1f5f9"],
        [data-theme="dark"] div[style*="border-bottom: 1px solid #e2e8f0"] {
            border-color: #1e293b !important;
        }

        [data-theme="dark"] code {
            background: #0b1120 !important;
            color: #60a5fa !important;
        }
    </style>
@endpush

<div class="space-y-6">
    <!-- Header Banner -->
    <div style="background: #ffffff; padding: 24px 28px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #ecfdf5; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 4px 8px rgba(16, 185, 129, 0.15);">
                <i class="fa-solid fa-box-archive"></i>
            </div>
            <div>
                <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Backup & Recovery Management</h1>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">Create full system snapshots or selective data backups saved to Google Drive and local storage</p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" wire:click="checkBackups" wire:loading.attr="disabled" style="background: #0f172a; color: white; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-rotate" wire:loading.remove wire:target="checkBackups"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="checkBackups"></i>
                <span>Refresh Inventory</span>
            </button>
            <button type="button" wire:click="openCreateBackupModal" wire:loading.attr="disabled" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);">
                <i class="fa-solid fa-cloud-arrow-up" wire:loading.remove wire:target="openCreateBackupModal"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="openCreateBackupModal"></i>
                <span>Create Backup</span>
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    @if ($successMessage)
        <div style="padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="font-size: 16px;"></i>
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="$set('successMessage', '')" style="background: none; border: none; color: #166534; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if ($errorMessage)
        <div style="padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px;"></i>
                <span>{{ $errorMessage }}</span>
            </div>
            <button type="button" wire:click="$set('errorMessage', '')" style="background: none; border: none; color: #991b1b; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    <!-- Main Card -->
    <div style="background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 16px;">
            <div>
                <h2 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">Backup Storage Inventory</h2>
                <span style="font-size: 12px; color: #64748b;">All database backups stored in Google Drive (<code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">backup/</code>) and local app storage</span>
            </div>

            <!-- Import Backup Upload -->
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="file" wire:model="uploadedBackupFile" id="importedBackupFileInputPage" accept=".json" style="display: none;">
                <label for="importedBackupFileInputPage" style="background: #0284c7; color: white; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; margin: 0; box-shadow: 0 2px 6px rgba(2, 132, 199, 0.2);">
                    <i class="fa-solid fa-file-import"></i>
                    <span>{{ $uploadedBackupFile ? $uploadedBackupFile->getClientOriginalName() : 'Import Backup (.json)' }}</span>
                </label>

                @if ($uploadedBackupFile)
                    <button type="button" wire:click="importBackup" wire:loading.attr="disabled" style="background: #16a34a; color: white; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-upload" wire:loading.remove wire:target="importBackup"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="importBackup"></i>
                        <span>Upload & Register</span>
                    </button>
                @endif
            </div>
        </div>

        @if ($backupTestResult)
            <div style="padding: 12px 16px; border-radius: 10px; font-size: 12px; font-weight: 600; margin-bottom: 20px; background: {{ str_contains($backupTestResult, '✅') ? '#f0fdf4' : '#fef2f2' }}; color: {{ str_contains($backupTestResult, '✅') ? '#166534' : '#991b1b' }}; border: 1px solid {{ str_contains($backupTestResult, '✅') ? '#bbf7d0' : '#fecaca' }};">
                {{ $backupTestResult }}
            </div>
        @endif

        <!-- Filter & Search Controls Bar -->
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; background: #f8fafc; padding: 12px 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
            <!-- Left: Search Box -->
            <div style="position: relative; flex: 1; min-width: 240px; max-width: 380px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px;"></i>
                <input 
                    type="text" 
                    wire:model.live.debounce.250ms="search" 
                    placeholder="Search backups by name, date, type..." 
                    style="width: 100%; padding: 8px 32px 8px 34px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; background: #ffffff; color: #0f172a; outline: none; transition: border-color 0.15s;"
                >
                @if(!empty($search))
                    <button type="button" wire:click="$set('search', '')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 14px;">
                        &times;
                    </button>
                @endif
            </div>

            <!-- Middle: Filters -->
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <!-- Snapshot Type Filter -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Type:</span>
                    <select wire:model.live="filterType" style="padding: 7px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; background: #ffffff; color: #0f172a; cursor: pointer;">
                        <option value="all">All Snapshot Types</option>
                        <option value="full">Full Snapshots</option>
                        <option value="selective">Selective Backups</option>
                    </select>
                </div>

                <!-- Storage Location Filter -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Location:</span>
                    <select wire:model.live="filterSource" style="padding: 7px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; background: #ffffff; color: #0f172a; cursor: pointer;">
                        <option value="all">All Locations</option>
                        <option value="google">Google Drive (Synced)</option>
                        <option value="local">Local Only</option>
                    </select>
                </div>

                <!-- Per Page Selector -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase;">Show:</span>
                    <select wire:model.live="perPage" style="padding: 7px 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 12px; background: #ffffff; color: #0f172a; cursor: pointer; font-weight: 700;">
                        <option value="5">5 per page</option>
                        <option value="10">10 per page</option>
                        <option value="20">20 per page</option>
                        <option value="50">50 per page</option>
                    </select>
                </div>

                @if(!empty($search) || $filterType !== 'all' || $filterSource !== 'all')
                    <button type="button" wire:click="resetFilters" style="background: #e2e8f0; color: #475569; border: none; padding: 7px 12px; border-radius: 8px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fa-solid fa-xmark"></i> Reset
                    </button>
                @endif
            </div>
        </div>

        @php
            $paginatedList = $this->getPaginatedBackups();
            $totalFiltered = $this->getTotalFilteredCount();
            $totalPages = $this->getTotalPages();
            $totalAll = count($backupsList);
        @endphp

        <!-- Backups Inventory Table -->
        <div style="overflow-x: auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 13px;">
                <thead>
                    <tr style="background: #f8fafc; color: #475569; font-weight: 700; text-transform: uppercase; font-size: 11px; border-bottom: 1px solid #e2e8f0;">
                        <th style="padding: 12px 16px;">Backup File Name</th>
                        <th style="padding: 12px 16px;">Snapshot Type</th>
                        <th style="padding: 12px 16px;">Storage Location</th>
                        <th style="padding: 12px 16px;">Date Created</th>
                        <th style="padding: 12px 16px;">File Size</th>
                        <th style="padding: 12px 16px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($paginatedList as $backup)
                        <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;">
                            <td style="padding: 14px 16px;">
                                @if(!empty($backup['custom_label']))
                                    <div style="font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 3px; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-tag" style="color: #2563eb; font-size: 12px;"></i>
                                        <span>{{ $backup['custom_label'] }}</span>
                                    </div>
                                    <div style="font-size: 11px; color: #64748b; font-family: monospace;">
                                        {{ $backup['filename'] }}
                                    </div>
                                @else
                                    <div style="font-weight: 700; color: #0f172a; font-family: monospace; font-size: 13px;">
                                        <i class="fa-solid fa-file-code" style="color: #64748b; margin-right: 6px;"></i>
                                        {{ $backup['filename'] }}
                                    </div>
                                @endif
                            </td>
                            <td style="padding: 14px 16px;">
                                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px; {{ $backup['type_badge'] ?? '' }}">
                                    <i class="{{ $backup['type_icon'] ?? 'fa-solid fa-database' }}"></i>
                                    {{ $backup['type'] ?? 'Full Snapshot' }}
                                </span>
                            </td>
                            <td style="padding: 14px 16px;">
                                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: {{ $backup['source_color'] }}; background: #f8fafc; padding: 4px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <i class="{{ $backup['source_icon'] }}"></i>
                                    {{ $backup['source'] }}
                                </span>
                            </td>
                            <td style="padding: 14px 16px; color: #334155;">
                                {{ $backup['date_formatted'] }}
                            </td>
                            <td style="padding: 14px 16px; color: #64748b; font-family: monospace;">
                                {{ $backup['size_formatted'] }}
                            </td>
                            <td style="padding: 14px 16px; text-align: right;">
                                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                    <button type="button" wire:click="downloadBackup('{{ $backup['filename'] }}')" style="background: #2563eb; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;" title="Download to local machine">
                                        <i class="fa-solid fa-download"></i> Download
                                    </button>

                                    <button type="button" wire:click="confirmRevertModal('{{ $backup['filename'] }}')" style="background: #d97706; color: white; border: none; padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;" title="Revert system database to this target backup">
                                        <i class="fa-solid fa-rotate-left"></i> Revert
                                    </button>

                                    <button type="button" wire:click="deleteBackup('{{ $backup['filename'] }}')" wire:confirm="Are you sure you want to delete this backup file?" style="background: #ef4444; color: white; border: none; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;" title="Delete backup file">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="padding: 36px; text-align: center; color: #64748b;">
                                <i class="fa-solid fa-box-open" style="font-size: 32px; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                                @if(!empty($search) || $filterType !== 'all' || $filterSource !== 'all')
                                    <span style="font-size: 14px; font-weight: 600;">No backups match your current search or filter criteria.</span>
                                    <div style="margin-top: 12px;">
                                        <button type="button" wire:click="resetFilters" style="background: #2563eb; color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-rotate-left"></i> Clear Filters
                                        </button>
                                    </div>
                                @else
                                    <span style="font-size: 14px; font-weight: 600;">No backup files found on Google Drive backup/ folder or local storage.</span>
                                    <div style="margin-top: 12px;">
                                        <button type="button" wire:click="openCreateBackupModal" style="background: #10b981; color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-plus"></i> Create First Backup Now
                                        </button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls Bar -->
        @if ($totalFiltered > 0)
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #f1f5f9;">
                <!-- Result Count Details -->
                <div style="font-size: 12px; color: #64748b;">
                    Showing <strong style="color: #0f172a; font-weight: 800;">{{ ($currentPage - 1) * $perPage + 1 }}</strong>
                    to <strong style="color: #0f172a; font-weight: 800;">{{ min($currentPage * $perPage, $totalFiltered) }}</strong>
                    of <strong style="color: #0f172a; font-weight: 800;">{{ $totalFiltered }}</strong> backup bundle(s)
                    @if($totalFiltered !== $totalAll)
                        <span style="background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; font-size: 11px; margin-left: 4px; font-weight: 600;">filtered from {{ $totalAll }} total</span>
                    @endif
                </div>

                <!-- Page Navigation Buttons -->
                @if ($totalPages > 1)
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <!-- First Page -->
                        <button 
                            type="button" 
                            wire:click="setPage(1)" 
                            @if($currentPage === 1) disabled @endif 
                            style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; background: {{ $currentPage === 1 ? '#f8fafc' : '#ffffff' }}; color: {{ $currentPage === 1 ? '#94a3b8' : '#334155' }}; cursor: {{ $currentPage === 1 ? 'not-allowed' : 'pointer' }}; font-size: 12px; font-weight: 700;"
                            title="First Page"
                        >
                            <i class="fa-solid fa-angles-left"></i>
                        </button>

                        <!-- Prev Page -->
                        <button 
                            type="button" 
                            wire:click="previousPage" 
                            @if($currentPage === 1) disabled @endif 
                            style="padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: {{ $currentPage === 1 ? '#f8fafc' : '#ffffff' }}; color: {{ $currentPage === 1 ? '#94a3b8' : '#334155' }}; cursor: {{ $currentPage === 1 ? 'not-allowed' : 'pointer' }}; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"
                        >
                            <i class="fa-solid fa-chevron-left"></i> Prev
                        </button>

                        <!-- Numbered Page Pills -->
                        @php
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);
                        @endphp

                        @if ($startPage > 1)
                            <button type="button" wire:click="setPage(1)" style="padding: 6px 11px; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; cursor: pointer; font-size: 12px; font-weight: 700;">1</button>
                            @if ($startPage > 2)
                                <span style="color: #94a3b8; padding: 0 4px;">...</span>
                            @endif
                        @endif

                        @for ($page = $startPage; $page <= $endPage; $page++)
                            <button 
                                type="button" 
                                wire:click="setPage({{ $page }})" 
                                style="padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 800; cursor: pointer; transition: all 0.15s ease; border: 1px solid {{ $currentPage === $page ? '#2563eb' : '#cbd5e1' }}; background: {{ $currentPage === $page ? '#2563eb' : '#ffffff' }}; color: {{ $currentPage === $page ? '#ffffff' : '#334155' }}; box-shadow: {{ $currentPage === $page ? '0 2px 6px rgba(37, 99, 235, 0.3)' : 'none' }};"
                            >
                                {{ $page }}
                            </button>
                        @endfor

                        @if ($endPage < $totalPages)
                            @if ($endPage < $totalPages - 1)
                                <span style="color: #94a3b8; padding: 0 4px;">...</span>
                            @endif
                            <button type="button" wire:click="setPage({{ $totalPages }})" style="padding: 6px 11px; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; cursor: pointer; font-size: 12px; font-weight: 700;">{{ $totalPages }}</button>
                        @endif

                        <!-- Next Page -->
                        <button 
                            type="button" 
                            wire:click="nextPage" 
                            @if($currentPage === $totalPages) disabled @endif 
                            style="padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; background: {{ $currentPage === $totalPages ? '#f8fafc' : '#ffffff' }}; color: {{ $currentPage === $totalPages ? '#94a3b8' : '#334155' }}; cursor: {{ $currentPage === $totalPages ? 'not-allowed' : 'pointer' }}; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"
                        >
                            Next <i class="fa-solid fa-chevron-right"></i>
                        </button>

                        <!-- Last Page -->
                        <button 
                            type="button" 
                            wire:click="setPage({{ $totalPages }})" 
                            @if($currentPage === $totalPages) disabled @endif 
                            style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; background: {{ $currentPage === $totalPages ? '#f8fafc' : '#ffffff' }}; color: {{ $currentPage === $totalPages ? '#94a3b8' : '#334155' }}; cursor: {{ $currentPage === $totalPages ? 'not-allowed' : 'pointer' }}; font-size: 12px; font-weight: 700;"
                            title="Last Page"
                        >
                            <i class="fa-solid fa-angles-right"></i>
                        </button>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Configure & Generate System Backup Modal -->
    @if ($showCreateBackupModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; color: #0f172a; padding: 20px;">
        <div style="background: #ffffff; padding: 28px 32px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45); width: 100%; max-width: 720px; border: 1px solid #cbd5e1; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
            
            <!-- Modal Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: #ecfdf5; color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 18px; font-weight: 800; margin: 0; color: #0f172a;">Configure System Backup</h3>
                        <span style="font-size: 12px; color: #64748b;">Choose between full system backup or custom selective data backup</span>
                    </div>
                </div>
                <button type="button" wire:click="$set('showCreateBackupModal', false)" style="background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer; padding: 4px; line-height: 1;" onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#94a3b8'">&times;</button>
            </div>

            <!-- Scrollable Body -->
            <div style="overflow-y: auto; flex: 1; padding-right: 4px;">
                <!-- Backup Mode Selector Cards -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-bottom: 20px;">
                    <!-- Mode 1: Full System -->
                    <div wire:click="$set('backupMode', 'full')" style="padding: 16px; border-radius: 12px; border: 2px solid {{ $backupMode === 'full' ? '#10b981' : '#e2e8f0' }}; background: {{ $backupMode === 'full' ? '#f0fdf4' : '#ffffff' }}; cursor: pointer; transition: all 0.15s ease; position: relative;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                            <i class="fa-solid fa-database" style="font-size: 18px; color: {{ $backupMode === 'full' ? '#10b981' : '#64748b' }};"></i>
                            <span style="font-size: 14px; font-weight: 800; color: #0f172a;">Full System Snapshot</span>
                            @if($backupMode === 'full')
                                <span style="margin-left: auto; background: #10b981; color: white; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 800;">SELECTED</span>
                            @endif
                        </div>
                        <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                            Backs up all database tables in a single complete snapshot. Recommended for full protection.
                        </p>
                    </div>

                    <!-- Mode 2: Selective Backup -->
                    <div wire:click="$set('backupMode', 'selective')" style="padding: 16px; border-radius: 12px; border: 2px solid {{ $backupMode === 'selective' ? '#d97706' : '#e2e8f0' }}; background: {{ $backupMode === 'selective' ? '#fffbeb' : '#ffffff' }}; cursor: pointer; transition: all 0.15s ease; position: relative;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                            <i class="fa-solid fa-filter" style="font-size: 18px; color: {{ $backupMode === 'selective' ? '#d97706' : '#64748b' }};"></i>
                            <span style="font-size: 14px; font-weight: 800; color: #0f172a;">Selective Custom Backup</span>
                            @if($backupMode === 'selective')
                                <span style="margin-left: auto; background: #d97706; color: white; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 800;">SELECTED</span>
                            @endif
                        </div>
                        <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                            Choose specific data categories to back up (Users, Offices, Roles, Flows, DTS, Chatify, Logs).
                        </p>
                    </div>
                </div>

                <!-- Custom Backup Label / Name Input -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        <i class="fa-solid fa-tag" style="color: #2563eb; margin-right: 6px;"></i>
                        Custom Backup Name / Label <span style="font-size: 11px; font-weight: 500; color: #64748b;">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        wire:model.live.debounce.250ms="customBackupLabel" 
                        placeholder="e.g. BACKUP for testing, Before 2026 Migration, Pre-update Snapshot" 
                        style="width: 100%; padding: 9px 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 13px; background: #ffffff; color: #0f172a; outline: none; box-sizing: border-box;"
                    >
                    <div style="font-size: 11px; color: #64748b; margin-top: 5px;">
                        @if(trim($customBackupLabel))
                            File name preview: <code style="font-family: monospace; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 2px 6px; border-radius: 4px;">[{{ preg_replace('/[\/\\\:\*\?"<>\|]/', '', trim($customBackupLabel)) }}]{{ $backupMode === 'selective' ? 'rms_backup_custom_' : 'rms_backup_full_' }}{{ date('Y-m-d_His') }}.json</code>
                        @else
                            File name preview: <code style="font-family: monospace; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px;">{{ $backupMode === 'selective' ? 'rms_backup_custom_' : 'rms_backup_full_' }}{{ date('Y-m-d_His') }}.json</code>
                        @endif
                    </div>
                </div>

                <!-- Category Checkboxes (Only when Selective mode is active) -->
                @if ($backupMode === 'selective')
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <span style="font-size: 13px; font-weight: 700; color: #334155;">Select Categories to Include:</span>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" wire:click="selectAllCategories" style="background: #e2e8f0; color: #334155; border: none; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;">
                                    Select All
                                </button>
                                <button type="button" wire:click="deselectAllCategories" style="background: #e2e8f0; color: #64748b; border: none; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;">
                                    Deselect All
                                </button>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 10px;">
                            @foreach ($this->getBackupCategoryDefinitions() as $catKey => $cat)
                                @php
                                    $isChecked = in_array($catKey, $selectedBackupCategories);
                                @endphp
                                <label style="display: flex; align-items: flex-start; gap: 12px; padding: 12px; border-radius: 8px; background: #ffffff; border: 1.5px solid {{ $isChecked ? $cat['color'] : '#e2e8f0' }}; cursor: pointer; transition: all 0.15s ease;">
                                    <input type="checkbox" wire:model.live="selectedBackupCategories" value="{{ $catKey }}" style="margin-top: 3px; accent-color: {{ $cat['color'] }}; width: 16px; height: 16px; cursor: pointer;">
                                    <div style="flex: 1;">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                            <i class="{{ $cat['icon'] }}" style="color: {{ $cat['color'] }}; font-size: 13px;"></i>
                                            <strong style="font-size: 13px; color: #0f172a;">{{ $cat['name'] }}</strong>
                                        </div>
                                        <p style="margin: 0 0 4px 0; font-size: 11px; color: #64748b; line-height: 1.3;">
                                            {{ $cat['description'] }}
                                        </p>
                                        <span style="font-size: 10px; font-family: monospace; color: #94a3b8;">
                                            Tables: {{ implode(', ', $cat['tables']) }}
                                        </span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- Modal Footer -->
            <div style="display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid #f1f5f9; padding-top: 14px; margin-top: 12px;">
                <button type="button" wire:click="$set('showCreateBackupModal', false)" style="background: #e2e8f0; color: #475569; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;">
                    Cancel
                </button>
                <button type="button" wire:click="createBackup" wire:loading.attr="disabled" style="background: #10b981; color: #ffffff; border: none; padding: 10px 22px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                    <i class="fa-solid fa-cloud-arrow-up" wire:loading.remove wire:target="createBackup"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="createBackup"></i>
                    <span>Start Backup Generation</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    <!-- Revert Confirmation Modal -->
    @if ($showRevertConfirmModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; color: white; padding: 20px;">
        <div style="background: #ffffff; color: #0f172a; padding: 28px 32px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45); width: 100%; max-width: 520px; border: 2px solid #f59e0b;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px; color: #d97706;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size: 28px;"></i>
                <h3 style="font-size: 19px; font-weight: 800; margin: 0; color: #0f172a;">Confirm Reverting System Database</h3>
            </div>

            <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 14px; font-size: 12px; color: #92400e; margin-bottom: 16px; line-height: 1.5;">
                <strong>⚠️ CAUTION & EMERGENCY WARNING:</strong> You are about to restore the system database to target backup <code style="background: #fef3c7; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; color: #78350f;">{{ $selectedTargetBackup }}</code>.
                All current database records will be replaced with data from this target backup file.
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                    Type <span style="color: #ef4444; font-family: monospace;">REVERT</span> below to authorize:
                </label>
                <input type="text" wire:model="backupConfirmInput" placeholder="Type REVERT" style="width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: monospace; font-weight: 700; color: #0f172a;">
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" wire:click="cancelRevertModal" style="background: #e2e8f0; color: #475569; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;">
                    Cancel
                </button>
                <button type="button" wire:click="startRevertProcess" style="background: #d97706; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);">
                    <i class="fa-solid fa-rotate-left"></i>
                    <span>Execute Target Revert</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    <!-- Live Step-by-Step Terminal Progress Modal for Database Reverting (Preload-style) -->
    @if ($isReverting)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; color: white; padding: 20px;">
        <div style="background: #ffffff; color: #0f172a; padding: 28px 32px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45); width: 100%; max-width: 600px; border: 1px solid #cbd5e1; display: flex; flex-direction: column;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-rotate-left fa-spin" style="font-size: 22px; color: #d97706;"></i>
                    <h4 style="font-size: 18px; font-weight: 800; margin: 0; color: #0f172a;">Reverting System Database</h4>
                </div>
                <span style="font-size: 14px; font-weight: 800; color: #d97706; background: #fffbeb; padding: 4px 12px; border-radius: 9999px; border: 1px solid #fde68a;">
                    {{ $revertProgress }}%
                </span>
            </div>

            <!-- Animated Progress Bar -->
            <div style="width: 100%; background: #e2e8f0; height: 12px; border-radius: 9999px; overflow: hidden; margin-bottom: 14px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                <div style="width: {{ $revertProgress }}%; background: linear-gradient(90deg, #d97706 0%, #f59e0b 100%); height: 100%; transition: width 0.3s ease-in-out; border-radius: 9999px;"></div>
            </div>

            <div style="font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 10px; display: flex; justify-content: space-between;">
                <span>Target Table: <strong style="color: #0f172a; font-family: monospace;">{{ $revertCurrentTable ?: 'Initializing...' }}</strong></span>
                <span>({{ $revertCurrentIndex }} / {{ count($revertTablesList) }} Tables)</span>
            </div>

            <!-- Step-by-Step Live Terminal Console Output -->
            <div x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })" x-effect="$nextTick(() => { $el.scrollTop = $el.scrollHeight })" style="background: #0f172a; color: #f59e0b; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; padding: 14px; border-radius: 10px; height: 190px; overflow-y: auto; text-align: left; line-height: 1.6; border: 1px solid #1e293b; margin-bottom: 18px;">
                @foreach ($revertLogs as $log)
                    <div style="margin-bottom: 4px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 2px;">
                        @if (str_contains($log, 'success') || str_contains($log, '100%') || str_contains($log, 'restored successfully') || str_contains($log, 'records cleared') || str_contains($log, 'suspended'))
                            <span style="color: #4ade80; font-weight: 600;">{{ $log }}</span>
                        @elseif (str_contains($log, 'Initializing') || str_contains($log, 'Payload'))
                            <span style="color: #38bdf8; font-weight: 700;">{{ $log }}</span>
                        @elseif (str_contains($log, 'Restoring table'))
                            <span style="color: #fde047;">{{ $log }}</span>
                        @elseif (str_contains($log, 'Clearing') || str_contains($log, 'Skipped'))
                            <span style="color: #fb7185;">{{ $log }}</span>
                        @elseif (str_contains($log, 'Synchronizing') || str_contains($log, 'Disabling'))
                            <span style="color: #a78bfa;">{{ $log }}</span>
                        @elseif (str_contains($log, 'Notice') || str_contains($log, 'Error') || str_contains($log, 'failed'))
                            <span style="color: #ef4444; font-weight: 700;">{{ $log }}</span>
                        @else
                            <span style="color: #94a3b8;">{{ $log }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="cancelRevert" onclick="window.stop(); window.location.reload();" style="width: 100%; background: #ef4444; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);">
                <i class="fa-solid fa-circle-stop"></i> Emergency Cancel Process
            </button>
        </div>
    </div>
    @endif
</div>

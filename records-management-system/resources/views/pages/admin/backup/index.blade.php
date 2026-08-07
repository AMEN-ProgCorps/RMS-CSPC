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

    // Backup Selection & Modal State
    public bool $showCreateBackupModal = false;
    public string $backupMode = 'full'; // 'full' or 'selective'
    public array $selectedBackupCategories = [
        'users',
        'offices',
        'roles',
        'flows',
        'dts',
        'rdp',
        'chat',
        'logs'
    ];

    public function mount(): void
    {
        $this->checkBackups();
    }

    public function getBackupCategoryDefinitions(): array
    {
        return [
            'users' => [
                'name' => 'Users & Accounts',
                'description' => 'User login accounts, profiles, permissions & security keys',
                'icon' => 'fa-solid fa-users',
                'color' => '#2563eb',
                'tables' => ['account', 'account_details', 'permissions', 'account_security_keys', 'password_reset_tokens', 'sessions'],
            ],
            'offices' => [
                'name' => 'Offices & Organizational Structure',
                'description' => 'Campus offices, units & cluster head assignments',
                'icon' => 'fa-solid fa-building-columns',
                'color' => '#0891b2',
                'tables' => ['office', 'cluster_head'],
            ],
            'roles' => [
                'name' => 'Roles, Clearances & System Settings',
                'description' => 'Role clearance keys, subsystem flags & system configuration',
                'icon' => 'fa-solid fa-sliders',
                'color' => '#7c3aed',
                'tables' => ['condition_key', 'condition_details', 'subsystems', 'subsystems_versions', 'system_settings', 'personal_settings'],
            ],
            'flows' => [
                'name' => 'Transaction Flows',
                'description' => 'DTS routing paths, custom flow sequences & action options',
                'icon' => 'fa-solid fa-route',
                'color' => '#d97706',
                'tables' => ['dts_transaction_flow', 'dts_action_options'],
            ],
            'dts' => [
                'name' => 'Document Tracking System (DTS)',
                'description' => 'Document transactions, tracking logs & distribution slips',
                'icon' => 'fa-solid fa-file-contract',
                'color' => '#059669',
                'tables' => ['dts_transaction_details', 'dts_transaction_logs', 'dts_documents', 'dts_document_logs', 'dts_document_attachments', 'dts_routing_slips'],
            ],
            'rdp' => [
                'name' => 'Records Disposition Package (RDP)',
                'description' => 'Archival records, series classifications & folder configs',
                'icon' => 'fa-solid fa-box-archive',
                'color' => '#475569',
                'tables' => ['rdp_documents', 'rdp_record_series', 'folder_drives_config'],
            ],
            'chat' => [
                'name' => 'Chatify & Messaging System',
                'description' => 'Direct messages, group chats, attachments & chat backups',
                'icon' => 'fa-solid fa-comments',
                'color' => '#e11d48',
                'tables' => ['chatify_messages', 'chatify_favorites', 'chatify_chat_backup', 'chat_conversations', 'chat_notifications', 'chatify_legal_agreements'],
            ],
            'logs' => [
                'name' => 'Audit Trails & System Logs',
                'description' => 'Admin action history, portal logs & notification logs',
                'icon' => 'fa-solid fa-clipboard-list',
                'color' => '#4b5563',
                'tables' => ['admin_logs', 'additional_portal_logs', 'chatify_audit_logs', 'notification_div', 'notification_content'],
            ],
        ];
    }

    public function openCreateBackupModal(): void
    {
        $this->showCreateBackupModal = true;
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

            // 1. Scan Google Drive backup/ folder
            try {
                $googleFiles = \Illuminate\Support\Facades\Storage::disk('google')->files('backup');
                foreach ($googleFiles as $file) {
                    $filename = basename($file);
                    if (str_ends_with($filename, '.json') || str_starts_with($filename, 'rms_backup_')) {
                        $size = 0;
                        try {
                            $size = \Illuminate\Support\Facades\Storage::disk('google')->size($file);
                        } catch (\Throwable) {}

                        $lastModified = time();
                        try {
                            $lastModified = \Illuminate\Support\Facades\Storage::disk('google')->lastModified($file);
                        } catch (\Throwable) {}

                        $isCustom = str_contains($filename, 'custom');
                        $foundBackups[$filename] = [
                            'filename' => $filename,
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
            } catch (\Throwable $e) {
                logger()->warning("Check Google Drive backups warning: " . $e->getMessage());
            }

            // 2. Scan Local storage/app/backups folder
            try {
                $localFiles = \Illuminate\Support\Facades\Storage::disk('local')->files('backups');
                foreach ($localFiles as $file) {
                    $filename = basename($file);
                    if (str_ends_with($filename, '.json') || str_starts_with($filename, 'rms_backup_')) {
                        $size = \Illuminate\Support\Facades\Storage::disk('local')->size($file);
                        $lastModified = \Illuminate\Support\Facades\Storage::disk('local')->lastModified($file);
                        $isCustom = str_contains($filename, 'custom');

                        if (isset($foundBackups[$filename])) {
                            $foundBackups[$filename]['source'] = 'Google Drive & Local';
                            $foundBackups[$filename]['source_icon'] = 'fa-solid fa-cloud-arrow-down';
                            $foundBackups[$filename]['source_color'] = '#059669';
                        } else {
                            $foundBackups[$filename] = [
                                'filename' => $filename,
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
                }
            } catch (\Throwable $e) {
                logger()->warning("Check local backups warning: " . $e->getMessage());
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

            $backupData = [
                'app_name' => config('app.name', 'RMS CSPC'),
                'version' => '1.0',
                'backup_mode' => $this->backupMode,
                'categories_included' => $categoriesIncluded,
                'created_at' => now()->toIso8601String(),
                'created_by' => auth()->user()?->email ?: 'Admin',
                'tables_count' => count($tablesToBackup),
                'total_records' => 0,
                'tables' => [],
            ];

            $totalRecords = 0;
            foreach ($tablesToBackup as $table) {
                try {
                    $rows = \DB::table($table)->get()->map(function ($item) {
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
            $filename = $modePrefix . date('Y-m-d_His') . '.json';

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

    public function revertToTargetBackup(): void
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

        $this->isBackupProcessing = true;

        try {
            $tablesData = $payload['tables'];
            $driver = \DB::getDriverName();

            \DB::transaction(function () use ($tablesData, $driver, $filename) {
                if ($driver === 'pgsql') {
                    \DB::statement('SET CONSTRAINTS ALL DEFERRED;');
                } elseif ($driver === 'sqlite') {
                    \DB::statement('PRAGMA foreign_keys = OFF;');
                } elseif ($driver === 'mysql') {
                    \DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
                }

                foreach ($tablesData as $tableName => $rows) {
                    if (in_array($tableName, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])) {
                        continue;
                    }

                    if (!\Schema::hasTable($tableName)) {
                        continue;
                    }

                    \DB::table($tableName)->truncate();

                    if (!empty($rows)) {
                        $chunks = array_chunk($rows, 200);
                        foreach ($chunks as $chunk) {
                            \DB::table($tableName)->insert($chunk);
                        }
                    }
                }

                if ($driver === 'sqlite') {
                    \DB::statement('PRAGMA foreign_keys = ON;');
                } elseif ($driver === 'mysql') {
                    \DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
                }

                \DB::table('admin_logs')->insert([
                    'changes' => "EMERGENCY REVERT PERFORMED: System database restored to target backup [{$filename}]",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->showRevertConfirmModal = false;
            $this->selectedTargetBackup = '';
            $this->backupConfirmInput = '';
            $this->successMessage = "🎉 System successfully reverted to target backup [{$filename}]! Database records have been restored.";

        } catch (\Throwable $e) {
            $this->errorMessage = "❌ Revert process failed: " . $e->getMessage();
        }

        $this->isBackupProcessing = false;
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
                    @forelse ($backupsList as $backup)
                        <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 14px 16px; font-weight: 700; color: #0f172a; font-family: monospace;">
                                <i class="fa-solid fa-file-code" style="color: #64748b; margin-right: 8px;"></i>
                                {{ $backup['filename'] }}
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
                                <span style="font-size: 14px; font-weight: 600;">No backup files found on Google Drive backup/ folder or local storage.</span>
                                <div style="margin-top: 12px;">
                                    <button type="button" wire:click="openCreateBackupModal" style="background: #10b981; color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-plus"></i> Create First Backup Now
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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
                <button type="button" wire:click="revertToTargetBackup" wire:loading.attr="disabled" style="background: #d97706; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);">
                    <i class="fa-solid fa-rotate-left" wire:loading.remove wire:target="revertToTargetBackup"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="revertToTargetBackup"></i>
                    <span>Execute Target Revert</span>
                </button>
            </div>
        </div>
    </div>
    @endif
</div>

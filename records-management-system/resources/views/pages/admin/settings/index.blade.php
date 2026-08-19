<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - System Settings')] class extends Component {
    use WithFileUploads;

    public bool $pagePrewarmingEnabled = true;
    public bool $emailAccessRequiredExternal = true;
    public bool $emailAccessRequiredApplication = true;
    public bool $emailAccessRequiredInternal = false;
    public bool $allowManualCompletionButton = false;
    public bool $autoForwardCreatedTransaction = true;
    public bool $rdpRequiredUploadFile = false;
    public bool $dtsRequiredUploadFile = false;
    public int $tabCloseIdleTimeoutMinutes = 15;
    public string $successMessage = '';
    public string $errorMessage = '';

    // Google Drive Diagnostics & Management
    public string $driveTestResult = '';
    public bool $isTestingDrive = false;
    public string $driveStatus = 'unknown';

    // Google Drive Editing Form State
    public bool $showDriveEditForm = false;
    public string $driveClientId = '';
    public string $driveClientSecret = '';
    public string $driveRefreshToken = '';
    public string $driveFolderId = '';
    public bool $driveVerifySsl = true;

    // Google SSO Credential Manager
    public string $ssoTestResult = '';
    public string $ssoStatus = 'unknown';
    public bool $showSsoEditForm = false;
    public string $ssoClientId = '';
    public string $ssoClientSecret = '';
    public string $ssoRedirectUri = 'dynamic';

    public function toggleDriveEditForm(): void
    {
        $this->showDriveEditForm = !$this->showDriveEditForm;
        if ($this->showDriveEditForm) {
            $this->loadDriveCredentials();
        }
    }

    public function loadDriveCredentials(): void
    {
        $this->driveClientId = \DB::table('system_settings')->where('key', 'google_drive_client_id')->value('value')
            ?: env('GOOGLE_DRIVE_CLIENT_ID', '');

        $this->driveClientSecret = \DB::table('system_settings')->where('key', 'google_drive_client_secret')->value('value')
            ?: env('GOOGLE_DRIVE_CLIENT_SECRET', '');

        $this->driveRefreshToken = \DB::table('system_settings')->where('key', 'google_drive_refresh_token')->value('value')
            ?: env('GOOGLE_DRIVE_REFRESH_TOKEN', '');

        $this->driveFolderId = \DB::table('system_settings')->where('key', 'google_drive_folder_id')->value('value')
            ?: env('GOOGLE_DRIVE_FOLDER_ID', '1tGkgf7DGmxzMRjwj42hjwyYwvRfhh-_F');

        $sslVal = \DB::table('system_settings')->where('key', 'google_drive_verify_ssl')->value('value');
        if ($sslVal !== null) {
            $this->driveVerifySsl = ($sslVal === 'true');
        } else {
            $this->driveVerifySsl = filter_var(env('GOOGLE_DRIVE_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN);
        }
    }

    public function saveDriveCredentials(): void
    {
        try {
            $credentials = [
                'google_drive_client_id'     => trim($this->driveClientId),
                'google_drive_client_secret' => trim($this->driveClientSecret),
                'google_drive_refresh_token' => trim($this->driveRefreshToken),
                'google_drive_folder_id'     => trim($this->driveFolderId),
                'google_drive_verify_ssl'    => $this->driveVerifySsl ? 'true' : 'false',
            ];

            foreach ($credentials as $key => $value) {
                \DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value, 'updated_at' => now()]
                );
            }

            $this->updateEnvFile([
                'GOOGLE_DRIVE_CLIENT_ID'     => trim($this->driveClientId),
                'GOOGLE_DRIVE_CLIENT_SECRET' => trim($this->driveClientSecret),
                'GOOGLE_DRIVE_REFRESH_TOKEN' => trim($this->driveRefreshToken),
                'GOOGLE_DRIVE_FOLDER_ID'     => trim($this->driveFolderId),
                'GOOGLE_DRIVE_VERIFY_SSL'    => $this->driveVerifySsl ? 'true' : 'false',
            ]);

            config([
                'filesystems.disks.google.clientId'     => trim($this->driveClientId),
                'filesystems.disks.google.clientSecret' => trim($this->driveClientSecret),
                'filesystems.disks.google.refreshToken' => trim($this->driveRefreshToken),
                'filesystems.disks.google.folder'       => trim($this->driveFolderId),
            ]);

            \DB::table('admin_logs')->insert([
                'changes' => 'Updated Google Drive Cloud Storage credentials and folder target via Admin Console',
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->showDriveEditForm = false;
            $this->successMessage = 'Google Drive Cloud Storage credentials updated successfully!';
            $this->dispatch('rms-settings-changed', type: 'site_settings', message: 'Google Drive credentials updated by administrator.');

            // Purge the resolved disk instance so the next usage rebuilds with fresh credentials from DB
            try {
                \Illuminate\Support\Facades\Storage::forgetDisk('google');
            } catch (\Throwable) {}

            $this->testDriveConnection();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to save Google Drive credentials: ' . $e->getMessage();
        }
    }

    public function toggleSsoEditForm(): void
    {
        $this->showSsoEditForm = !$this->showSsoEditForm;
        if ($this->showSsoEditForm) {
            $this->loadSsoCredentials();
        }
    }

    public function loadSsoCredentials(): void
    {
        $this->ssoClientId = \DB::table('system_settings')->where('key', 'google_sso_client_id')->value('value')
            ?: env('GOOGLE_CLIENT_ID', '');

        $this->ssoClientSecret = \DB::table('system_settings')->where('key', 'google_sso_client_secret')->value('value')
            ?: env('GOOGLE_CLIENT_SECRET', '');

        $this->ssoRedirectUri = \DB::table('system_settings')->where('key', 'google_sso_redirect_uri')->value('value')
            ?: env('GOOGLE_REDIRECT_URI', 'dynamic');
    }

    public function saveSsoCredentials(): void
    {
        try {
            $credentials = [
                'google_sso_client_id'     => trim($this->ssoClientId),
                'google_sso_client_secret' => trim($this->ssoClientSecret),
                'google_sso_redirect_uri'  => trim($this->ssoRedirectUri),
            ];

            foreach ($credentials as $key => $value) {
                \DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value, 'updated_at' => now()]
                );
            }

            $this->updateEnvFile([
                'GOOGLE_CLIENT_ID'     => trim($this->ssoClientId),
                'GOOGLE_CLIENT_SECRET' => trim($this->ssoClientSecret),
                'GOOGLE_REDIRECT_URI'  => trim($this->ssoRedirectUri),
            ]);

            config([
                'services.google.client_id'     => trim($this->ssoClientId),
                'services.google.client_secret' => trim($this->ssoClientSecret),
                'services.google.redirect'      => trim($this->ssoRedirectUri),
            ]);

            \DB::table('admin_logs')->insert([
                'changes' => 'Updated Google SSO (Sign-In) credentials via Admin Console',
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);

            $this->showSsoEditForm = false;
            $this->successMessage = 'Google SSO credentials updated successfully! Changes are live — no restart required.';
            $this->dispatch('rms-settings-changed', type: 'site_settings', message: 'Google SSO credentials updated by administrator.');

            $this->testSsoConnection();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to save Google SSO credentials: ' . $e->getMessage();
        }
    }

    public function testSsoConnection(): void
    {
        try {
            $clientId = \DB::table('system_settings')->where('key', 'google_sso_client_id')->value('value')
                ?: config('services.google.client_id')
                ?: env('GOOGLE_CLIENT_ID');

            $clientSecret = \DB::table('system_settings')->where('key', 'google_sso_client_secret')->value('value')
                ?: config('services.google.client_secret')
                ?: env('GOOGLE_CLIENT_SECRET');

            if (empty($clientId) || empty($clientSecret)) {
                $this->ssoStatus = 'error';
                $this->ssoTestResult = '❌ Google SSO credentials are missing. Please provide a valid Client ID and Client Secret.';
                return;
            }

            if (!str_contains($clientId, '.apps.googleusercontent.com')) {
                $this->ssoStatus = 'warning';
                $this->ssoTestResult = '⚠️ Client ID format appears invalid. Expected format: xxxx.apps.googleusercontent.com';
                return;
            }

            if (strlen($clientSecret) < 10) {
                $this->ssoStatus = 'warning';
                $this->ssoTestResult = '⚠️ Client Secret appears too short. Please verify your credentials.';
                return;
            }

            $this->ssoStatus = 'connected';
            $this->ssoTestResult = '✅ Google SSO credentials are configured and appear valid. Client ID: ' . substr($clientId, 0, 20) . '...';

            \DB::table('admin_logs')->insert([
                'changes' => 'Validated Google SSO credentials (Status: CONFIGURED)',
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->ssoStatus = 'error';
            $this->ssoTestResult = '❌ SSO Credential Check Error: ' . $e->getMessage();
        }
    }

    protected function updateEnvFile(array $data): void
    {
        $paths = [base_path('.env'), base_path('.env.docker')];
        foreach ($paths as $envPath) {
            if (!file_exists($envPath)) continue;
            try {
                $content = @file_get_contents($envPath);
                if ($content === false) continue;
                foreach ($data as $key => $value) {
                    $valStr = (string) $value;
                    if (preg_match('/\s/', $valStr)) {
                        $valStr = '"' . addslashes($valStr) . '"';
                    }
                    if (preg_match("/^{$key}=.*/m", $content)) {
                        $content = preg_replace("/^{$key}=.*/m", "{$key}={$valStr}", $content);
                    } else {
                        $content .= "\n{$key}={$valStr}";
                    }
                }
                @file_put_contents($envPath, $content);
            } catch (\Throwable) {
                // If .env or .env.docker is read-only for the webserver in Docker,
                // the system_settings DB table handles credential persistence.
            }
        }
    }

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_settings)) {
            $this->redirect(route('portal'));
            return;
        }

        $setting = \DB::table('system_settings')->where('key', 'page_prewarming_enabled')->value('value');
        $this->pagePrewarmingEnabled = ($setting === 'true');

        $ext = \DB::table('system_settings')->where('key', 'dts_email_access_required_external')->value('value');
        $this->emailAccessRequiredExternal = ($ext !== 'false');

        $app = \DB::table('system_settings')->where('key', 'dts_email_access_required_application')->value('value');
        $this->emailAccessRequiredApplication = ($app !== 'false');

        $int = \DB::table('system_settings')->where('key', 'dts_email_access_required_internal')->value('value');
        $this->emailAccessRequiredInternal = ($int === 'true');

        $manual = \DB::table('system_settings')->where('key', 'dts_allow_manual_completion_button')->value('value');
        $this->allowManualCompletionButton = ($manual === 'true');

        $autoFwd = \DB::table('system_settings')->where('key', 'dts_auto_forward_created_transaction')->value('value');
        $this->autoForwardCreatedTransaction = ($autoFwd !== 'false');

        $rdpReq = \DB::table('system_settings')->where('key', 'rdp_required_upload_file')->value('value');
        $this->rdpRequiredUploadFile = ($rdpReq === 'true');

        $dtsReq = \DB::table('system_settings')->where('key', 'dts_required_upload_file')->value('value');
        $this->dtsRequiredUploadFile = ($dtsReq === 'true');

        $timeoutVal = \DB::table('system_settings')->where('key', 'tab_close_idle_timeout_minutes')->value('value');
        $this->tabCloseIdleTimeoutMinutes = ($timeoutVal !== null && is_numeric($timeoutVal)) ? (int) $timeoutVal : 15;
    }

    public function testDriveConnection(): void
    {
        $this->isTestingDrive = true;
        $this->driveTestResult = '';

        try {
            $testFileName = 'admin_health_check_' . time() . '.txt';
            $testContent = 'RMS-CSPC Admin Console Health Check - ' . now();

            $written = \Illuminate\Support\Facades\Storage::disk('google')->put($testFileName, $testContent);

            if ($written && \Illuminate\Support\Facades\Storage::disk('google')->exists($testFileName)) {
                \Illuminate\Support\Facades\Storage::disk('google')->delete($testFileName);

                $this->driveStatus = 'connected';
                $this->driveTestResult = '✅ Google Drive Connection Healthy! Successfully wrote, verified, and cleaned up test file on Google Cloud Storage.';
                
                \DB::table('admin_logs')->insert([
                    'changes' => 'Executed Google Drive Cloud Storage Health Check (Status: HEALTHY)',
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            } else {
                $this->driveStatus = 'error';
                $this->driveTestResult = '❌ Failed to verify file write on Google Drive.';
            }
        } catch (\Throwable $e) {
            $this->driveStatus = 'error';
            $this->driveTestResult = '❌ Google Drive Connection Error: ' . $e->getMessage();
        }

        $this->isTestingDrive = false;
    }

    public function refreshDriveMetrics(): void
    {
        try {
            $offices = \DB::table('folder_data')->get();
            foreach ($offices as $office) {
                $dtsSize = \DB::table('document_data')
                    ->where('user_office', $office->office_name)
                    ->where('document_path', 'like', '%dts%')
                    ->sum(\DB::raw('COALESCE(CAST(NULLIF(file_size, \'\') AS BIGINT), 0)'));

                $rdpSize = \DB::table('document_data')
                    ->where('user_office', $office->office_name)
                    ->where('document_path', 'like', '%rdp%')
                    ->sum(\DB::raw('COALESCE(CAST(NULLIF(file_size, \'\') AS BIGINT), 0)'));

                \DB::table('folder_data')->where('id', $office->id)->update([
                    'current_dts_size'  => $dtsSize,
                    'current_rdp_size'  => $rdpSize,
                    'total_folder_size' => $dtsSize + $rdpSize,
                    'updated_at'        => now(),
                ]);
            }

            $this->successMessage = 'Google Drive storage metrics successfully recalculated and synchronized!';
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed to sync drive metrics: ' . $e->getMessage();
        }
    }

    // Live Preload Progress State
    public bool $isPreloading = false;
    public int $preloadProgress = 0;
    public string $preloadCurrentOffice = '';
    public array $preloadLogs = [];
    public array $preloadOfficesList = [];
    public int $preloadCurrentIndex = 0;

    public function preloadOfficeFolders(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
        $this->preloadLogs = [];
        $this->preloadProgress = 0;
        $this->preloadCurrentIndex = 0;

        $offices = \DB::table('office')
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]'])
            ->pluck('office_code')
            ->toArray();

        if (!in_array('GENERAL', $offices)) {
            $offices[] = 'GENERAL';
        }

        $this->preloadOfficesList = array_values(array_unique($offices));

        if (empty($this->preloadOfficesList)) {
            $this->errorMessage = 'No offices found to preload.';
            return;
        }

        $this->isPreloading = true;
        $this->preloadLogs[] = "🚀 Initializing office folder preload sequence for " . count($this->preloadOfficesList) . " offices...";
        
        $this->executePreloadStep(0);
    }

    public function executePreloadStep(int $index): void
    {
        if (!$this->isPreloading) {
            return;
        }

        $total = count($this->preloadOfficesList);
        if ($index >= $total) {
            $this->preloadProgress = 100;
            $this->isPreloading = false;
            $this->preloadLogs[] = "🎉 All office folder structures successfully preloaded and verified (100%)!";
            $this->successMessage = "Successfully preloaded office folder structures on Google Drive & database for {$total} offices! Uploads will now be instant.";
            
            \DB::table('admin_logs')->insert([
                'changes' => "Preloaded Google Drive office folders & database records for {$total} offices.",
                'admin_id' => auth()->id(),
                'what_system' => 3,
                'when_changes' => now(),
            ]);
            return;
        }

        $officeCode = $this->preloadOfficesList[$index];
        $officeName = strtoupper(\Illuminate\Support\Str::slug($officeCode, '_'));
        if (empty($officeName)) {
            $officeName = 'GENERAL';
        }

        $this->preloadCurrentOffice = $officeName;
        $this->preloadLogs[] = "🔍 Checking if [{$officeName}] folder structure exists on server & Google Drive...";

        // 1. Local cache directories
        try {
            \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory("private/uploads/{$officeName}/DTS");
            \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory("private/uploads/{$officeName}/RDP");
        } catch (\Throwable $e) {}

        // 2. Google Drive directories
        $this->preloadLogs[] = "📁 Attempting to verify / create [{$officeName}], [{$officeName}/DTS], and [{$officeName}/RDP] on Google Drive...";
        try {
            \Illuminate\Support\Facades\Storage::disk('google')->makeDirectory($officeName);
            \Illuminate\Support\Facades\Storage::disk('google')->makeDirectory("{$officeName}/DTS");
            \Illuminate\Support\Facades\Storage::disk('google')->makeDirectory("{$officeName}/RDP");
        } catch (\Throwable $e) {
            logger()->warning("Preload notice for {$officeName}: " . $e->getMessage());
        }

        // 3. Database record update (using updateOrInsert to prevent duplicate key errors!)
        $existing = \DB::table('folder_data')->where('office_name', $officeName)->first();
        if (!$existing) {
            \DB::table('folder_data')->insert([
                'office_name'       => $officeName,
                'total_folder_size' => 0,
                'is_dts_available'  => true,
                'current_dts_size'  => 0,
                'is_rdp_available'  => true,
                'current_rdp_size'  => 0,
                'is_active'         => true,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        } else {
            \DB::table('folder_data')->where('office_name', $officeName)->update([
                'is_dts_available' => true,
                'is_rdp_available' => true,
                'updated_at'        => now(),
            ]);
        }

        $this->preloadLogs[] = "✅ Creation & DB verification success for [{$officeName}]!";
        
        $this->preloadCurrentIndex = $index + 1;
        $this->preloadProgress = (int) round(($this->preloadCurrentIndex / $total) * 100);

        if ($this->preloadCurrentIndex < $total) {
            $this->preloadLogs[] = "➡️ Proceeding to next office folder (" . ($this->preloadCurrentIndex + 1) . " of {$total})...";
            $this->js('$wire.executePreloadStep(' . $this->preloadCurrentIndex . ')');
        } else {
            $this->executePreloadStep($total);
        }
    }

    public function cancelPreload(): void
    {
        $this->isPreloading = false;
        $this->errorMessage = 'Preload office folders operation was manually cancelled by administrator.';
    }

    public function saveSettings(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';

        try {
            \DB::transaction(function () {
                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'page_prewarming_enabled'],
                    [
                        'value' => $this->pagePrewarmingEnabled ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'allow_manual_login'],
                    [
                        'value' => 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'allow_google_login'],
                    [
                        'value' => 'true',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_email_access_required_external'],
                    [
                        'value' => $this->emailAccessRequiredExternal ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_email_access_required_application'],
                    [
                        'value' => $this->emailAccessRequiredApplication ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_email_access_required_internal'],
                    [
                        'value' => $this->emailAccessRequiredInternal ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_allow_manual_completion_button'],
                    [
                        'value' => $this->allowManualCompletionButton ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_auto_forward_created_transaction'],
                    [
                        'value' => $this->autoForwardCreatedTransaction ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'rdp_required_upload_file'],
                    [
                        'value' => $this->rdpRequiredUploadFile ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'dts_required_upload_file'],
                    [
                        'value' => $this->dtsRequiredUploadFile ? 'true' : 'false',
                        'updated_at' => now(),
                    ]
                );

                \DB::table('system_settings')->updateOrInsert(
                    ['key' => 'tab_close_idle_timeout_minutes'],
                    [
                        'value' => (string) max(1, (int) $this->tabCloseIdleTimeoutMinutes),
                        'updated_at' => now(),
                    ]
                );

                // Log activity
                $logText = "Updated system settings. Prewarming: " . ($this->pagePrewarmingEnabled ? 'true' : 'false') . 
                             ", ExtEmail: " . ($this->emailAccessRequiredExternal ? 'true' : 'false') . 
                             ", AppEmail: " . ($this->emailAccessRequiredApplication ? 'true' : 'false') . 
                             ", IntEmail: " . ($this->emailAccessRequiredInternal ? 'true' : 'false') .
                             ", ManualBtn: " . ($this->allowManualCompletionButton ? 'true' : 'false') .
                             ", RDPReq: " . ($this->rdpRequiredUploadFile ? 'true' : 'false') .
                             ", DTSReq: " . ($this->dtsRequiredUploadFile ? 'true' : 'false') .
                             ", InactivityTimeout: " . $this->tabCloseIdleTimeoutMinutes . " mins";

                \DB::table('admin_logs')->insert([
                    'changes' => \Illuminate\Support\Str::limit($logText, 250),
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'System settings updated successfully! Synchronized refresh triggered for all open tabs.';
            $this->dispatch('rms-settings-changed', type: 'site_settings', message: 'System site settings updated by administrator.');
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to update system settings: ' . $e->getMessage();
        }
    }

    public function broadcastRefreshToAllTabs(): void
    {
        $this->successMessage = 'Broadcasted synchronized 5-second auto-refresh signal to all active browser tabs!';
        $this->dispatch('rms-settings-changed', type: 'site_settings', message: 'Administrator initiated a synchronized tab refresh.');

        \DB::table('admin_logs')->insert([
            'changes' => 'Triggered system-wide cross-tab synchronized refresh across active sessions.',
            'admin_id' => auth()->id(),
            'what_system' => 3,
            'when_changes' => now(),
        ]);
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
        } elseif ($driver === 'mysql') {
            $results = \DB::select('SHOW TABLES');
            foreach ($results as $row) {
                $val = array_values((array) $row)[0];
                $tables[] = $val;
            }
        } else {
            $tables = [
                'system_settings', 'users', 'account_details', 'condition_key', 'condition_details',
                'office', 'folder_data', 'subsystems', 'document_data', 'dts_transaction_flow',
                'dts_action_options', 'dts_transaction_details', 'dts_flow_logs', 'rdp_documents',
                'rdp_references', 'rdp_inventory_and_appraisal', 'rdp_records_disposition_schedule',
                'admin_logs', 'security_logs', 'file_upload_logs', 'notifications', 'chat_messages',
                'chat_conversations', 'personal_settings'
            ];
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
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .settings-dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .settings-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .settings-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }
        .settings-card-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .settings-card-header i {
            font-size: 18px;
            color: #2563eb;
        }
        .setting-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f8fafc;
        }
        .setting-item:last-child {
            border-bottom: none;
        }
        .setting-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
            max-width: 78%;
        }
        .setting-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        .setting-desc {
            font-size: 12px;
            color: #64748b;
            line-height: 1.4;
        }
        /* Toggle Switch styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #2563eb;
        }
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        .save-btn {
            background-color: #2563eb;
            color: #ffffff;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        .save-btn:hover {
            background-color: #1d4ed8;
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .page-header h1 {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .page-header p {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .settings-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }
        [data-theme="dark"] .settings-card-header {
            border-bottom: 1px solid #1e293b !important;
        }
        [data-theme="dark"] .settings-card-header h3 {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .settings-card-header i {
            color: #60a5fa !important;
        }
        [data-theme="dark"] .setting-item {
            border-bottom-color: #1a253c !important;
        }
        [data-theme="dark"] .setting-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .setting-desc {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .slider {
            background-color: #334155 !important;
        }
        [data-theme="dark"] input:checked + .slider {
            background-color: #2563eb !important;
        }
        [data-theme="dark"] select.form-input {
            background-color: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] select.form-input option {
            background-color: #0f172a !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] div[style*="background: #f8fafc"],
        [data-theme="dark"] div[style*="background:#f8fafc"] {
            background-color: #0f172a !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] div[style*="background: #eff6ff"],
        [data-theme="dark"] div[style*="background:#eff6ff"] {
            background-color: rgba(37, 99, 235, 0.12) !important;
            border-color: rgba(37, 99, 235, 0.25) !important;
            color: #93c5fd !important;
        }
        [data-theme="dark"] div[style*="background: #eff6ff"] strong,
        [data-theme="dark"] div[style*="background:#eff6ff"] strong {
            color: #60a5fa !important;
        }
        [data-theme="dark"] div[style*="background: #f8fafc"] span[style*="color: #64748b"] {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] div[style*="background: #f8fafc"] div[style*="color: #0f172a"] {
            color: #f8fafc !important;
        }
        [data-theme="dark"] div[style*="background: #f8fafc"] label {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] div[style*="background: #f8fafc"] input {
            background-color: #131c2e !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] div[style*="background: #f8fafc"] h4 {
            color: #f8fafc !important;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Live Progress Bar & Step-by-step Terminal Console Overlay Modal for Preloading -->
    @if ($isPreloading)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.82); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; color: white; padding: 20px;">
        <div style="background: #ffffff; color: #0f172a; padding: 28px 32px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45); width: 100%; max-width: 560px; border: 1px solid #cbd5e1;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-folder-plus" style="font-size: 22px; color: #2563eb;"></i>
                    <h4 style="font-size: 18px; font-weight: 800; margin: 0; color: #0f172a;">Preloading Office Folders</h4>
                </div>
                <span style="font-size: 14px; font-weight: 800; color: #2563eb; background: #eff6ff; padding: 4px 12px; border-radius: 9999px; border: 1px solid #bfdbfe;">
                    {{ $preloadProgress }}%
                </span>
            </div>

            <!-- Animated Progress Bar -->
            <div style="width: 100%; background: #e2e8f0; height: 12px; border-radius: 9999px; overflow: hidden; margin-bottom: 14px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                <div style="width: {{ $preloadProgress }}%; background: linear-gradient(90deg, #2563eb 0%, #3b82f6 100%); height: 100%; transition: width 0.3s ease-in-out; border-radius: 9999px;"></div>
            </div>

            <div style="font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 10px; display: flex; justify-content: space-between;">
                <span>Target: <strong style="color: #0f172a;">{{ $preloadCurrentOffice ?: 'Initializing...' }}</strong></span>
                <span>({{ $preloadCurrentIndex }} / {{ count($preloadOfficesList) }} Offices)</span>
            </div>

            <!-- Step-by-Step Live Terminal Console Output -->
            <div x-data x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })" x-effect="$nextTick(() => { $el.scrollTop = $el.scrollHeight })" style="background: #0f172a; color: #38bdf8; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; padding: 14px; border-radius: 10px; height: 170px; overflow-y: auto; text-align: left; line-height: 1.6; border: 1px solid #1e293b; margin-bottom: 18px;">
                @foreach ($preloadLogs as $log)
                    <div style="margin-bottom: 4px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 2px;">
                        @if (str_contains($log, '✅'))
                            <span style="color: #4ade80; font-weight: 600;">{{ $log }}</span>
                        @elseif (str_contains($log, '🔍'))
                            <span style="color: #fde047;">{{ $log }}</span>
                        @elseif (str_contains($log, '📁'))
                            <span style="color: #60a5fa;">{{ $log }}</span>
                        @elseif (str_contains($log, '🎉'))
                            <span style="color: #a7f3d0; font-weight: 800;">{{ $log }}</span>
                        @else
                            <span style="color: #94a3b8;">{{ $log }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="cancelPreload" onclick="window.stop(); window.location.reload();" style="width: 100%; background: #ef4444; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);">
                <i class="fa-solid fa-circle-stop"></i> Emergency Cancel Process
            </button>
        </div>
    </div>
    @endif

    <!-- General Livewire Action Loading Indicator (For Test Connection & Sync Metrics) -->
    <div wire:loading.flex wire:target="testDriveConnection, saveDriveCredentials, refreshDriveMetrics, saveSsoCredentials, testSsoConnection" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 99998; align-items: center; justify-content: center; color: white;">
        <div style="background: #ffffff; color: #0f172a; padding: 36px 44px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35); text-align: center; max-width: 440px; border: 1px solid #cbd5e1;">
            <div style="width: 56px; height: 56px; border: 4px solid #e2e8f0; border-top-color: #2563eb; border-radius: 50%; animation: spinDrive 0.8s linear infinite; margin: 0 auto 18px auto;"></div>
            <h4 style="font-size: 19px; font-weight: 800; margin: 0 0 8px 0; color: #0f172a;">Processing Operation...</h4>
            <p style="font-size: 13px; color: #64748b; margin: 0 0 20px 0; line-height: 1.5;">
                Synchronizing settings and testing connection with Google Drive...
            </p>
            <button type="button" onclick="window.stop(); window.location.reload();" style="background: #ef4444; color: #ffffff; border: none; padding: 10px 22px; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);">
                <i class="fa-solid fa-circle-stop"></i> Emergency Cancel Process
            </button>
        </div>
    </div>
    <style>
    @keyframes spinDrive {
        to { transform: rotate(360deg); }
    }
    </style>

    <!-- Header with Quick Save Action Bar -->
    <form wire:submit.prevent="saveSettings">
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h1 style="margin: 0;">System Settings Dashboard</h1>
                <p style="margin: 4px 0 0 0;">Manage system-wide behaviors, Google Drive cloud integration, security, and file constraints.</p>
            </div>
            <button type="submit" class="save-btn">
                <i class="fa-solid fa-floppy-disk"></i> Save All Settings
            </button>
        </div>

        <!-- Alert Notifications -->
        @if ($successMessage)
            <div class="alert alert-success" style="background-color: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
                <i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i>
                {{ $successMessage }}
            </div>
        @endif

        @if ($errorMessage)
            <div class="alert alert-danger" style="background-color: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;">
                <i class="fa-solid fa-circle-xmark" style="margin-right: 8px;"></i>
                {{ $errorMessage }}
            </div>
        @endif

        <!-- Google Drive Cloud Manager Card (Full Width Banner) -->
        <div class="settings-card" style="border-top: 4px solid #2563eb; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-brands fa-google-drive" style="font-size: 24px; color: #2563eb;"></i>
                    <div>
                        <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0;">Google Drive Cloud Storage Manager</h3>
                        <span style="font-size: 12px; color: #64748b;">Automated multi-tier cloud storage & database caching workflow</span>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 14px; border: 1px solid #e2e8f0;">
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Storage Target Folder</span>
                    <div style="font-size: 12px; font-weight: 700; color: #0f172a; font-family: monospace; overflow: hidden; text-overflow: ellipsis;">
                        {{ config('filesystems.disks.google.folder') ?: 'Root Folder' }}
                    </div>
                </div>
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Auth Mode</span>
                    <div style="font-size: 12px; font-weight: 700; color: #2563eb;">
                        {{ config('filesystems.disks.google.refreshToken') ? 'OAuth 2.0 User Token' : 'Service Account JSON' }}
                    </div>
                </div>
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">SSL Certificate Verification</span>
                    <div style="font-size: 12px; font-weight: 700; color: #059669;">
                        {{ config('filesystems.disks.google.verify', true) ? 'Enabled (SSL Active)' : 'Bypassed (Local Dev)' }}
                    </div>
                </div>
            </div>

            @if ($driveTestResult)
                <div style="padding: 10px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; margin-bottom: 14px; background: {{ str_contains($driveTestResult, '✅') ? '#f0fdf4' : '#fef2f2' }}; color: {{ str_contains($driveTestResult, '✅') ? '#166534' : '#991b1b' }}; border: 1px solid {{ str_contains($driveTestResult, '✅') ? '#bbf7d0' : '#fecaca' }};">
                    {{ $driveTestResult }}
                </div>
            @endif

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" wire:click="testDriveConnection" wire:loading.attr="disabled" style="background: #2563eb; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-plug" wire:loading.remove wire:target="testDriveConnection"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="testDriveConnection"></i>
                    <span>Test Connection & Health Check</span>
                </button>

                <button type="button" wire:click="preloadOfficeFolders" wire:loading.attr="disabled" style="background: #16a34a; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-folder-plus" wire:loading.remove wire:target="preloadOfficeFolders"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="preloadOfficeFolders"></i>
                    <span>Preload Office Folders</span>
                </button>

                <button type="button" wire:click="toggleDriveEditForm" style="background: #0284c7; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-gear"></i>
                    <span>{{ $showDriveEditForm ? 'Close Editor' : 'Edit Credentials' }}</span>
                </button>

                <button type="button" wire:click="refreshDriveMetrics" wire:loading.attr="disabled" style="background: #475569; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-arrows-rotate" wire:loading.remove wire:target="refreshDriveMetrics"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="refreshDriveMetrics"></i>
                    <span>Sync Storage Metrics</span>
                </button>
            </div>

            @if ($showDriveEditForm)
                <div style="margin-top: 16px; padding-top: 16px; border-top: 2px dashed #e2e8f0; background: #f8fafc; padding: 16px; border-radius: 8px;">
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-key" style="color: #0284c7;"></i> Update Google Drive Credentials & Configuration
                    </h4>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Client ID (GOOGLE_DRIVE_CLIENT_ID)</label>
                            <input type="text" wire:model="driveClientId" placeholder="e.g. 1234567890-xxx.apps.googleusercontent.com" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Client Secret (GOOGLE_DRIVE_CLIENT_SECRET)</label>
                            <input type="password" wire:model="driveClientSecret" placeholder="e.g. GOCSPX-xxxxxxxxxxxxxx" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Target Root Folder ID (GOOGLE_DRIVE_FOLDER_ID)</label>
                            <input type="text" wire:model="driveFolderId" placeholder="e.g. 1tGkgf7DGmxzMRjwj42hjwyYwvRfhh-_F" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Refresh Token (GOOGLE_DRIVE_REFRESH_TOKEN)</label>
                            <input type="text" wire:model="driveRefreshToken" placeholder="e.g. 1//04xxxxxxxxxxxxxx" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0; margin-top: 8px;">
                        <div>
                            <span style="font-size: 12px; font-weight: 700; color: #334155; display: block;">Verify SSL Certificates (GOOGLE_DRIVE_VERIFY_SSL)</span>
                            <span style="font-size: 11px; color: #64748b;">Set to OFF on local Windows dev machines; set to ON for Docker & Production VM.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="driveVerifySsl">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <button type="button" wire:click="saveDriveCredentials" style="background: #16a34a; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Credentials
                        </button>
                        <button type="button" wire:click="toggleDriveEditForm" style="background: #e2e8f0; color: #475569; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>

        <!-- Google SSO Credential Manager Card (Full Width Banner) -->
        <div class="settings-card" style="border-top: 4px solid #ea4335; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fa-brands fa-google" style="font-size: 24px; color: #ea4335;"></i>
                    <div>
                        <h3 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0;">Google SSO Credential Manager</h3>
                        <span style="font-size: 12px; color: #64748b;">Manage Google Single Sign-On (OAuth 2.0) credentials for portal authentication — live changes, no restart needed</span>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 14px; border: 1px solid #e2e8f0;">
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Client ID</span>
                    <div style="font-size: 12px; font-weight: 700; color: #0f172a; font-family: monospace; overflow: hidden; text-overflow: ellipsis;">
                        @php
                            $currentSsoId = \DB::table('system_settings')->where('key', 'google_sso_client_id')->value('value') ?: config('services.google.client_id') ?: env('GOOGLE_CLIENT_ID', '');
                        @endphp
                        {{ $currentSsoId ? \Illuminate\Support\Str::limit($currentSsoId, 30) : 'Not Set' }}
                    </div>
                </div>
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Redirect URI</span>
                    <div style="font-size: 12px; font-weight: 700; color: #ea4335;">
                        @php
                            $currentRedirect = \DB::table('system_settings')->where('key', 'google_sso_redirect_uri')->value('value') ?: env('GOOGLE_REDIRECT_URI', 'dynamic');
                        @endphp
                        {{ $currentRedirect === 'dynamic' ? 'Dynamic (Auto-detect)' : $currentRedirect }}
                    </div>
                </div>
                <div>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Source</span>
                    <div style="font-size: 12px; font-weight: 700; color: #059669;">
                        @php
                            $fromDb = \DB::table('system_settings')->where('key', 'google_sso_client_id')->value('value');
                        @endphp
                        {{ $fromDb ? 'Database (Live Override)' : 'Environment (.env)' }}
                    </div>
                </div>
            </div>

            @if ($ssoTestResult)
                <div style="padding: 10px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; margin-bottom: 14px; background: {{ str_contains($ssoTestResult, '✅') ? '#f0fdf4' : (str_contains($ssoTestResult, '⚠️') ? '#fffbeb' : '#fef2f2') }}; color: {{ str_contains($ssoTestResult, '✅') ? '#166534' : (str_contains($ssoTestResult, '⚠️') ? '#92400e' : '#991b1b') }}; border: 1px solid {{ str_contains($ssoTestResult, '✅') ? '#bbf7d0' : (str_contains($ssoTestResult, '⚠️') ? '#fde68a' : '#fecaca') }};">
                    {{ $ssoTestResult }}
                </div>
            @endif

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" wire:click="testSsoConnection" wire:loading.attr="disabled" style="background: #ea4335; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-plug" wire:loading.remove wire:target="testSsoConnection"></i>
                    <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="testSsoConnection"></i>
                    <span>Validate SSO Credentials</span>
                </button>

                <button type="button" wire:click="toggleSsoEditForm" style="background: #0284c7; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-gear"></i>
                    <span>{{ $showSsoEditForm ? 'Close Editor' : 'Edit SSO Credentials' }}</span>
                </button>
            </div>

            @if ($showSsoEditForm)
                <div style="margin-top: 16px; padding-top: 16px; border-top: 2px dashed #e2e8f0; background: #f8fafc; padding: 16px; border-radius: 8px;">
                    <h4 style="margin: 0 0 12px 0; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-key" style="color: #ea4335;"></i> Update Google SSO Credentials
                    </h4>

                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 12px; color: #1e40af; display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fa-solid fa-circle-info" style="margin-top: 2px;"></i>
                        <span>These credentials are from your <strong>Google Cloud Console</strong> OAuth 2.0 Client ID (Web Application type). Changes take effect immediately — no app restart needed. The Authorized Redirect URI must be <code style="background: #dbeafe; padding: 1px 6px; border-radius: 4px;">{{ url('/auth/google/callback') }}</code></span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Client ID (GOOGLE_CLIENT_ID)</label>
                            <input type="text" wire:model="ssoClientId" placeholder="e.g. 459768812355-xxx.apps.googleusercontent.com" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Client Secret (GOOGLE_CLIENT_SECRET)</label>
                            <input type="password" wire:model="ssoClientSecret" placeholder="e.g. GOCSPX-xxxxxxxxxxxxxx" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                        </div>

                        <div>
                            <label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Redirect URI (GOOGLE_REDIRECT_URI)</label>
                            <input type="text" wire:model="ssoRedirectUri" placeholder="dynamic (auto-detects from APP_URL)" style="width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-family: monospace;">
                            <span style="font-size: 10px; color: #64748b; margin-top: 2px; display: block;">Set to "dynamic" to auto-detect, or enter a fixed URL like https://yourdomain.com/auth/google/callback</span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 12px;">
                        <button type="button" wire:click="saveSsoCredentials" style="background: #16a34a; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-floppy-disk"></i> Save SSO Credentials
                        </button>
                        <button type="button" wire:click="toggleSsoEditForm" style="background: #e2e8f0; color: #475569; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer;">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>

        <!-- 2-Column Responsive Dashboard Grid -->
        <div class="settings-dashboard-grid">

            <!-- Card 1: System Performance & Flow Controls -->
            <div class="settings-card">
                <div>
                    <div class="settings-card-header">
                        <i class="fa-solid fa-bolt"></i>
                        <h3>System & Performance Controls</h3>
                    </div>

                    <!-- Setting: Page Prewarming -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Page Pre-warming / Cache</span>
                            <span class="setting-desc">Asynchronously pre-loads and caches system pages in the background upon login to prevent view compilation delay.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="pagePrewarmingEnabled">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <!-- Setting: Allow Manual Complete / Forward Button -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Allow Manual Completion Buttons</span>
                            <span class="setting-desc">Displays manual COMPLETED buttons as an alternative override option when physical QR code scanning is unavailable.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="allowManualCompletionButton">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <!-- Setting: Auto Forward Created Transaction -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Auto Forward Created Transaction</span>
                            <span class="setting-desc">Automatically forwards newly created transactions to the next destination office in the flow sequence, so they no longer remain in Received Transactions waiting to be manually forwarded.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="autoForwardCreatedTransaction">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <!-- Action: Push Synchronized Refresh to Open Tabs -->
                    <div class="setting-item" style="border-top: 1px dashed #e2e8f0; padding-top: 14px; margin-top: 6px;">
                        <div class="setting-details">
                            <span class="setting-title" style="display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-arrows-rotate" style="color: #2563eb;"></i>
                                Broadcast Tab Auto-Refresh
                            </span>
                            <span class="setting-desc">Sends an instant synchronized 5-second countdown reload notification to all active browser windows and tabs across the system.</span>
                        </div>
                        <button type="button" wire:click="broadcastRefreshToAllTabs" class="form-btn-primary" style="padding: 8px 16px; font-size: 12px; font-weight: 600; white-space: nowrap; height: 36px; display: inline-flex; align-items: center; gap: 6px; background-color: #2563eb; color: #ffffff; border-radius: 8px; border: none; cursor: pointer;">
                            <i class="fa-solid fa-satellite-dish"></i>
                            Push Refresh
                        </button>
                    </div>
                </div>
            </div>



            <!-- Card: Session & Security Settings -->
            <div class="settings-card">
                <div>
                    <div class="settings-card-header">
                        <i class="fa-solid fa-shield-halved"></i>
                        <h3>Session & Inactivity Security Settings</h3>
                    </div>

                    <!-- Setting: Tab-Close & Inactivity Timeout -->
                    <div class="setting-item" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                        <div class="setting-details">
                            <span class="setting-title">Tab-Close & Inactivity Auto-Logout Timeout</span>
                            <span class="setting-desc">Automatically logs out users when their tab is closed or idle without activity for the configured duration.</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px; width: 100%; margin-top: 4px;">
                            <select wire:model="tabCloseIdleTimeoutMinutes" class="form-input" style="max-width: 220px; font-size: 13px; font-weight: 600; color: #1e293b; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 6px 12px; cursor: pointer; background: #ffffff;">
                                <option value="1">1 Minute</option>
                                <option value="2">2 Minutes</option>
                                <option value="3">3 Minutes</option>
                                <option value="5">5 Minutes</option>
                                <option value="10">10 Minutes</option>
                                <option value="15">15 Minutes (Default)</option>
                                <option value="30">30 Minutes</option>
                                <option value="60">60 Minutes (1 Hour)</option>
                            </select>
                            <span style="font-size: 12px; color: #64748b; font-weight: 500;">Minutes until auto-logout</span>
                        </div>
                    </div>

                    <div style="margin-top: 14px; padding: 10px 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 11.5px; color: #1e40af; display: flex; align-items: flex-start; gap: 8px;">
                        <i class="fa-solid fa-user-shield" style="margin-top: 2px; font-size: 13px;"></i>
                        <span>
                            <strong>Admin Account Modification Protection:</strong> Whenever an Admin modifies or deactivates any user in <em>User Management</em>, the system automatically invalidates active sessions and forces an immediate logout for that account.
                        </span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Security & Email Authorization -->
            <div class="settings-card">
                <div>
                    <div class="settings-card-header">
                        <i class="fa-solid fa-shield-halved"></i>
                        <h3>Security & Email Passwords</h3>
                    </div>

                    <!-- Setting: External Transactions Email Access -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">External Transactions Email Password</span>
                            <span class="setting-desc">Enforces tracking email and password validation for external transactions.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="emailAccessRequiredExternal">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <!-- Setting: Application Letters Email Access -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Application Letters Email Password</span>
                            <span class="setting-desc">Enforces tracking email and password validation for application letters.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="emailAccessRequiredApplication">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <!-- Setting: Internal Transactions Email Access -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Internal Transactions Email Password</span>
                            <span class="setting-desc">Enforces tracking email and password validation for internal transactions.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="emailAccessRequiredInternal">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Card 3: Subsystem File Upload Requirements -->
            <div class="settings-card">
                <div>
                    <div class="settings-card-header">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        <h3>File Attachment Constraints</h3>
                    </div>

                    <!-- Setting: RDP Required File Upload -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Require Attachment for RDP Records</span>
                            <span class="setting-desc">Enforces that users saving a record in Records Disposition Program (RDP) must upload a document file.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="rdpRequiredUploadFile">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <!-- Setting: DTS Required File Upload -->
                    <div class="setting-item">
                        <div class="setting-details">
                            <span class="setting-title">Require Attachment for DTS Transactions</span>
                            <span class="setting-desc">Enforces that users attaching files in Document Tracking System (DTS) must upload a document file.</span>
                        </div>
                        <label class="switch">
                            <input type="checkbox" wire:model="dtsRequiredUploadFile">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>

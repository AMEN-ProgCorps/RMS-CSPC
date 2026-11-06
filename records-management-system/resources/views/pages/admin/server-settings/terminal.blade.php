<?php

use App\Services\BackupService;
use App\Services\ServerManagementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Server Terminal')] class extends Component {
    public string $command = '';
    public string $target = 'app'; // 'app', 'node', 'db', 'host'
    public array $history = [];
    public array $commandList = [];
    public bool $isBusy = false;
    public string $serverLabel = '';
    public string $hostname = '';
    public bool $isDocker = false;

    // Safety Backup Soft Protection
    public bool $hasSafetyBackup = false;
    public ?array $latestBackup = null;
    public bool $isCreatingBackup = false;
    public string $safetyBackupMessage = '';

    public function boot(): void
    {
        $this->__alias = 'pages.admin.server-settings.terminal';
    }

    public function mount(ServerManagementService $service): void
    {
        $perms = auth()->user()?->permissions;
        if (!($perms?->is_sadm ?? false)) {
            abort(403, 'Unauthorized. Terminal console access is strictly restricted to Super Administrators.');
        }

        $metrics = $service->getHardwareMetrics();
        $this->serverLabel = ServerManagementService::getServerLabel();
        $this->hostname = $metrics['host']['hostname'] ?? gethostname();
        $this->isDocker = file_exists('/.dockerenv');

        $this->latestBackup = BackupService::getLatestBackup();
        $this->hasSafetyBackup = session()->get('terminal_safety_backup_done', false);

        // Initial welcome log
        $this->history[] = [
            'command' => 'system --status',
            'target' => 'system',
            'output' => "=== RMS-CSPC Web Operations Terminal Initialized ===\nNode: {$this->serverLabel} ({$this->hostname})\nAuthenticated as: " . (auth()->user()?->name ?? 'Super Admin') . " [Role: Super Admin]\nAvailable targets: App (PHP/Artisan), Node (Vite/npm), DB (PostgreSQL), Host (Server Shell).\nSoft protection: " . ($this->hasSafetyBackup ? "Safety Backup Verified [Active]" : "Backup Required before executing commands."),
            'success' => true,
            'exit_code' => 0,
            'time' => now()->format('H:i:s'),
        ];
    }

    public function generateSafetyBackup(BackupService $backupService): void
    {
        $this->isCreatingBackup = true;
        $this->isBusy = true;

        $res = $backupService->createFullBackup('Pre_Terminal_Safety');

        if ($res['success']) {
            session()->put('terminal_safety_backup_done', true);
            $this->hasSafetyBackup = true;
            $this->latestBackup = BackupService::getLatestBackup();
            $this->safetyBackupMessage = $res['message'];

            $this->history[] = [
                'command' => 'system --safety-backup',
                'target' => 'system',
                'output' => "[Safety Guard Passed] Mandatory pre-terminal backup created:\nFile: {$res['filename']}\nPreserved: {$res['total_records']} records across {$res['tables_count']} tables.\nTimestamp: {$res['created_at']}\nTerminal console access is now unlocked.",
                'success' => true,
                'exit_code' => 0,
                'time' => now()->format('H:i:s'),
            ];
        } else {
            $this->safetyBackupMessage = "Safety backup failed: " . ($res['message'] ?? 'Unknown error');
        }

        $this->isCreatingBackup = false;
        $this->isBusy = false;
        $this->dispatch('terminal-output-updated');
    }

    public function executeCommand(ServerManagementService $service, ?string $manualCmd = null, ?string $manualTarget = null): void
    {
        $cmdToRun = trim($manualCmd ?? $this->command);
        $targetToUse = $manualTarget ?? $this->target;

        if ($cmdToRun === '') {
            return;
        }

        if (!$this->hasSafetyBackup) {
            $this->history[] = [
                'command' => $cmdToRun,
                'target' => $targetToUse,
                'output' => "Command Blocked: Mandatory pre-terminal safety backup must be generated before executing terminal commands.",
                'success' => false,
                'exit_code' => 126,
                'time' => now()->format('H:i:s'),
            ];
            $this->command = '';
            $this->isBusy = false;
            $this->dispatch('terminal-output-updated');
            return;
        }

        $this->isBusy = true;

        // Track in history list for arrow key navigation
        if (!in_array($cmdToRun, $this->commandList, true)) {
            $this->commandList[] = $cmdToRun;
        }

        $result = $service->runTerminalCommand($cmdToRun, $targetToUse);

        $this->history[] = [
            'command' => $cmdToRun,
            'target' => $targetToUse,
            'output' => $result['output'] ?? '',
            'success' => $result['success'] ?? false,
            'exit_code' => $result['exit_code'] ?? 0,
            'time' => $result['timestamp'] ?? now()->format('H:i:s'),
        ];

        $this->command = '';
        $this->isBusy = false;

        $this->dispatch('terminal-output-updated');
    }

    public function runQuick(string $cmd, string $target, ServerManagementService $service): void
    {
        if (!$this->hasSafetyBackup) {
            return;
        }
        $this->target = $target;
        $this->executeCommand($service, $cmd, $target);
    }

    public function clearConsole(): void
    {
        $this->history = [
            [
                'command' => 'clear',
                'target' => 'system',
                'output' => "Terminal console buffer cleared.\nActive Node: {$this->serverLabel} | Target: {$this->target}",
                'success' => true,
                'exit_code' => 0,
                'time' => now()->format('H:i:s'),
            ]
        ];
        $this->dispatch('terminal-output-updated');
    }

    public function switchTarget(string $newTarget): void
    {
        if (in_array($newTarget, ['app', 'node', 'db', 'host'], true)) {
            $this->target = $newTarget;
        }
    }
};
?>

@push('styles')
    <style>
        [data-theme="dark"] div[style*="background: #ffffff"],
        [data-theme="dark"] div[style*="background:#ffffff"] {
            background-color: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }

        [data-theme="dark"] div[style*="background: #f8fafc"],
        [data-theme="dark"] div[style*="background:#f8fafc"] {
            background-color: #0b1120 !important;
            border-color: #1e293b !important;
        }

        [data-theme="dark"] div[style*="background: #fffbeb"] {
            background-color: #1c1917 !important;
            border-color: #78350f !important;
        }

        [data-theme="dark"] div[style*="background: #ecfdf5"] {
            background-color: #062419 !important;
            border-color: #065f46 !important;
        }

        [data-theme="dark"] h1[style*="color: #0f172a"],
        [data-theme="dark"] h2[style*="color: #0f172a"],
        [data-theme="dark"] h3[style*="color: #0f172a"],
        [data-theme="dark"] h4[style*="color: #0f172a"] {
            color: #f8fafc !important;
        }

        [data-theme="dark"] p[style*="color: #64748b"] {
            color: #94a3b8 !important;
        }

        /* Interactive Web Terminal Box */
        .terminal-viewport {
            background: #070b14;
            color: #e2e8f0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 13px;
            line-height: 1.55;
            border-radius: 12px;
            border: 1px solid #1e293b;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.45);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .terminal-header {
            background: #0f172a;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #1e293b;
        }

        .terminal-dots {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .terminal-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
        }

        .dot-red { background: #ef4444; }
        .dot-yellow { background: #f59e0b; }
        .dot-green { background: #10b981; }

        .terminal-body {
            height: 520px;
            overflow-y: auto;
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            scroll-behavior: smooth;
        }

        .terminal-body::-webkit-scrollbar {
            width: 8px;
        }

        .terminal-body::-webkit-scrollbar-track {
            background: #070b14;
        }

        .terminal-body::-webkit-scrollbar-thumb {
            background: #1e293b;
            border-radius: 4px;
        }

        .terminal-body::-webkit-scrollbar-thumb:hover {
            background: #334155;
        }

        .terminal-log-output {
            white-space: pre-wrap;
            word-break: break-all;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid #1e293b;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            color: #cbd5e1;
            margin-top: 6px;
        }

        .terminal-prompt-user {
            color: #38bdf8;
            font-weight: 700;
        }

        .terminal-prompt-path {
            color: #a78bfa;
            font-weight: 700;
        }

        .terminal-input-bar {
            background: #0c1220;
            border-top: 1px solid #1e293b;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .terminal-input-field {
            background: transparent;
            border: none;
            outline: none;
            color: #f8fafc;
            font-family: inherit;
            font-size: 13.5px;
            width: 100%;
        }

        .terminal-input-field::placeholder {
            color: #475569;
        }

        .quick-action-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }

        .quick-action-pill:hover {
            border-color: #2563eb;
            color: #2563eb;
            background: #eff6ff;
            transform: translateY(-1px);
        }

        .quick-action-pill.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        [data-theme="dark"] .quick-action-pill {
            background: #131c2e !important;
            border-color: #1e293b !important;
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .quick-action-pill:hover {
            border-color: #38bdf8 !important;
            color: #38bdf8 !important;
            background: #0f172a !important;
        }
    </style>
@endpush

<div class="space-y-6" x-data="{
    historyIdx: -1,
    cmdList: @entangle('commandList'),
    scrollBottom() {
        const el = document.getElementById('terminal-viewport-body');
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    },
    navigateHistory(direction) {
        if (!this.cmdList || this.cmdList.length === 0) return;
        if (direction === 'up') {
            if (this.historyIdx === -1) {
                this.historyIdx = this.cmdList.length - 1;
            } else if (this.historyIdx > 0) {
                this.historyIdx--;
            }
        } else if (direction === 'down') {
            if (this.historyIdx < this.cmdList.length - 1 && this.historyIdx !== -1) {
                this.historyIdx++;
            } else {
                this.historyIdx = -1;
                $wire.set('command', '');
                return;
            }
        }
        if (this.historyIdx >= 0 && this.historyIdx < this.cmdList.length) {
            $wire.set('command', this.cmdList[this.historyIdx]);
        }
    }
}" x-init="
    scrollBottom();
    $wire.on('terminal-output-updated', () => {
        setTimeout(scrollBottom, 50);
        const inp = document.getElementById('term-cmd-input');
        if (inp && !inp.disabled) inp.focus();
    });
">

    <!-- Header Banner -->
    <div style="background: #ffffff; padding: 24px 28px; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #0f172a; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 22px; box-shadow: 0 4px 10px rgba(15, 23, 42, 0.25);">
                <i class="fa-solid fa-terminal"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <h1 style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.02em;">Server Operations Terminal</h1>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;">
                        <i class="fa-solid fa-shield-halved" style="margin-right: 4px;"></i> Super Admin Only
                    </span>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: {{ $hasSafetyBackup ? '#ecfdf5' : '#fffbeb' }}; color: {{ $hasSafetyBackup ? '#059669' : '#b45309' }}; border: 1px solid {{ $hasSafetyBackup ? '#a7f3d0' : '#fcd34d' }};">
                        <i class="fa-solid {{ $hasSafetyBackup ? 'fa-circle-check' : 'fa-triangle-exclamation' }}" style="margin-right: 4px;"></i>
                        {{ $hasSafetyBackup ? 'Backup Verified' : 'Backup Required' }}
                    </span>
                    <span style="font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;">
                        Node: {{ $serverLabel }}
                    </span>
                </div>
                <p style="font-size: 13px; color: #64748b; margin: 4px 0 0 0;">
                    Interactive command console &bull; Target: <strong style="color: #2563eb; text-transform: uppercase;">{{ $target }}</strong> &bull; Host: {{ $hostname }} &bull; Direct SSH Alternative
                </p>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" wire:click="clearConsole" style="background: #ffffff; color: #64748b; border: 1px solid #cbd5e1; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s ease;">
                <i class="fa-solid fa-trash-can"></i>
                <span>Clear Buffer</span>
            </button>
            <button type="button" @click="scrollBottom" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-arrow-down"></i>
                <span>Scroll Bottom</span>
            </button>
        </div>
    </div>

    <!-- Mandatory Safety Backup Soft-Protection Gate -->
    @if(!$hasSafetyBackup)
        <div style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 14px; padding: 22px 26px; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.1); display: flex; flex-direction: column; gap: 14px;">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                <div style="display: flex; gap: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; box-shadow: 0 2px 6px rgba(217, 119, 6, 0.2);">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <h3 style="font-size: 16.5px; font-weight: 800; color: #92400e; margin: 0;">Mandatory Safety Notice: System Backup Required</h3>
                            <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; text-transform: uppercase; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;">
                                Access Restricted
                            </span>
                        </div>
                        <p style="font-size: 13.5px; color: #78350f; margin: 6px 0 0 0; line-height: 1.5; max-width: 760px;">
                            Low-level terminal commands can directly modify database schemas, application assets, and system configurations. As a <strong>soft protection requirement</strong>, an automated database backup must be created before accessing this terminal. This ensures that whatever happens, your system state can be completely restored.
                        </p>
                        @if($latestBackup)
                            <div style="margin-top: 8px; font-size: 12px; color: #b45309; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                                <span>Latest snapshot in storage: <strong>{{ $latestBackup['filename'] }}</strong> ({{ $latestBackup['size_human'] }}, {{ $latestBackup['age_minutes'] }} mins ago)</span>
                            </div>
                        @endif
                    </div>
                </div>

                <div>
                    <button 
                        type="button" 
                        wire:click="generateSafetyBackup" 
                        wire:loading.attr="disabled" 
                        wire:target="generateSafetyBackup"
                        style="background: #d97706; color: white; border: none; padding: 12px 22px; border-radius: 10px; font-weight: 800; font-size: 13.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35); transition: all 0.15s ease;"
                    >
                        <i class="fa-solid fa-cloud-arrow-up" wire:loading.remove wire:target="generateSafetyBackup"></i>
                        <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="generateSafetyBackup"></i>
                        <span wire:loading.remove wire:target="generateSafetyBackup">Generate Safety Backup & Unlock Terminal</span>
                        <span wire:loading wire:target="generateSafetyBackup">Generating Backup... Please Wait</span>
                    </button>
                </div>
            </div>
        </div>
    @else
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 10px; padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="color: #059669; font-size: 18px;"></i>
                <div>
                    <span style="font-size: 13px; font-weight: 700; color: #065f46;">Pre-Terminal Safety Protection: ACTIVE</span>
                    <span style="font-size: 12px; color: #047857; margin-left: 8px;">A verified database snapshot is active for this session.</span>
                </div>
            </div>
            <button 
                type="button" 
                wire:click="generateSafetyBackup" 
                wire:loading.attr="disabled" 
                wire:target="generateSafetyBackup"
                style="background: #ffffff; color: #059669; border: 1px solid #a7f3d0; padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 11.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
            >
                <i class="fa-solid fa-arrows-rotate" wire:loading.remove wire:target="generateSafetyBackup"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="generateSafetyBackup"></i>
                <span>Create Fresh Snapshot</span>
            </button>
        </div>
    @endif

    <!-- Target Environment Selector -->
    <div style="background: #ffffff; padding: 16px 20px; border-radius: 14px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 14px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 13px; font-weight: 700; color: #0f172a;">Execution Target Environment:</span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <button type="button" wire:click="switchTarget('app')" style="padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid {{ $target === 'app' ? '#2563eb' : '#e2e8f0' }}; background: {{ $target === 'app' ? '#2563eb' : 'transparent' }}; color: {{ $target === 'app' ? '#ffffff' : '#475569' }}; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-brands fa-laravel"></i>
                    <span>App Container (rms_app)</span>
                </button>

                <button type="button" wire:click="switchTarget('node')" style="padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid {{ $target === 'node' ? '#16a34a' : '#e2e8f0' }}; background: {{ $target === 'node' ? '#16a34a' : 'transparent' }}; color: {{ $target === 'node' ? '#ffffff' : '#475569' }}; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-brands fa-node-js"></i>
                    <span>Node Container (rms_node)</span>
                </button>

                <button type="button" wire:click="switchTarget('db')" style="padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid {{ $target === 'db' ? '#0891b2' : '#e2e8f0' }}; background: {{ $target === 'db' ? '#0891b2' : 'transparent' }}; color: {{ $target === 'db' ? '#ffffff' : '#475569' }}; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-database"></i>
                    <span>Database Container (rms_db)</span>
                </button>

                <button type="button" wire:click="switchTarget('host')" style="padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1px solid {{ $target === 'host' ? '#475569' : '#e2e8f0' }}; background: {{ $target === 'host' ? '#475569' : 'transparent' }}; color: {{ $target === 'host' ? '#ffffff' : '#475569' }}; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-server"></i>
                    <span>Host Shell (Docker & Git)</span>
                </button>
            </div>
        </div>

        <!-- Quick Command Presets -->
        <div style="border-top: 1px solid #f1f5f9; padding-top: 12px; display: flex; flex-direction: column; gap: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">
                    Quick Action Presets (Click to run):
                </span>
                @if(!$hasSafetyBackup)
                    <span style="font-size: 11px; color: #d97706; font-weight: 600;">
                        <i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Presets locked until backup generated
                    </span>
                @endif
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                @if($target === 'app')
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('php artisan migrate --force', 'app')">
                        <i class="fa-solid fa-database" style="color: #2563eb;"></i> php artisan migrate --force
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('php artisan optimize:clear', 'app')">
                        <i class="fa-solid fa-broom" style="color: #d97706;"></i> php artisan optimize:clear
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('php artisan route:clear && php artisan config:clear', 'app')">
                        <i class="fa-solid fa-arrows-rotate" style="color: #059669;"></i> clear routes & config
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('php artisan queue:restart', 'app')">
                        <i class="fa-solid fa-bolt" style="color: #7c3aed;"></i> queue:restart
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('composer dump-autoload', 'app')">
                        <i class="fa-solid fa-box" style="color: #4b5563;"></i> composer dump-autoload
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('php artisan about', 'app')">
                        <i class="fa-solid fa-circle-info" style="color: #0284c7;"></i> php artisan about
                    </span>
                @elseif($target === 'node')
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('npm run build', 'node')">
                        <i class="fa-solid fa-hammer" style="color: #16a34a;"></i> npm run build
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('npm list --depth=0', 'node')">
                        <i class="fa-solid fa-list-check" style="color: #2563eb;"></i> npm list --depth=0
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('node -v && npm -v', 'node')">
                        <i class="fa-brands fa-node-js" style="color: #16a34a;"></i> check node & npm version
                    </span>
                @elseif($target === 'db')
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('pg_isready -U adminrms -d rms', 'db')">
                        <i class="fa-solid fa-heart-pulse" style="color: #059669;"></i> pg_isready
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('psql -U adminrms -d rms -c &quot;SELECT version();&quot;', 'db')">
                        <i class="fa-solid fa-database" style="color: #0891b2;"></i> check postgres version
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('psql -U adminrms -d rms -c &quot;\\dt&quot;', 'db')">
                        <i class="fa-solid fa-table-list" style="color: #7c3aed;"></i> list tables (\dt)
                    </span>
                @else
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('git status', 'host')">
                        <i class="fa-brands fa-git-alt" style="color: #ea580c;"></i> git status
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('git log -n 5 --oneline', 'host')">
                        <i class="fa-solid fa-code-commit" style="color: #2563eb;"></i> git log -n 5
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('docker compose ps', 'host')">
                        <i class="fa-brands fa-docker" style="color: #0284c7;"></i> docker compose ps
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('uptime', 'host')">
                        <i class="fa-solid fa-clock" style="color: #059669;"></i> uptime
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('df -h', 'host')">
                        <i class="fa-solid fa-hard-drive" style="color: #4b5563;"></i> disk usage (df -h)
                    </span>
                    <span class="quick-action-pill {{ !$hasSafetyBackup ? 'disabled' : '' }}" wire:click="runQuick('free -m', 'host')">
                        <i class="fa-solid fa-memory" style="color: #9333ea;"></i> memory info (free -m)
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Terminal Window Viewport -->
    <div class="terminal-viewport">
        <!-- Terminal Title Bar -->
        <div class="terminal-header">
            <div class="terminal-dots">
                <span class="terminal-dot dot-red"></span>
                <span class="terminal-dot dot-yellow"></span>
                <span class="terminal-dot dot-green"></span>
                <span style="margin-left: 8px; font-size: 11.5px; font-weight: 600; color: #94a3b8; font-family: inherit;">
                    rms@{{ strtolower($serverLabel) }}: [{{ $target }}] @if($target === 'app') /var/www/html @elseif($target === 'node') /app @elseif($target === 'db') /var/lib/postgresql @else host/repo @endif (bash)
                </span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 10px; font-weight: 700; color: #38bdf8; background: rgba(56, 189, 248, 0.1); border: 1px solid rgba(56, 189, 248, 0.25); padding: 2px 7px; border-radius: 4px; text-transform: uppercase;">
                    Target: {{ $target }}
                </span>
                <span style="font-size: 10px; font-weight: 700; color: {{ $hasSafetyBackup ? '#10b981' : '#f59e0b' }}; background: {{ $hasSafetyBackup ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)' }}; border: 1px solid {{ $hasSafetyBackup ? 'rgba(16, 185, 129, 0.25)' : 'rgba(245, 158, 11, 0.25)' }}; padding: 2px 7px; border-radius: 4px;">
                    {{ $hasSafetyBackup ? 'UNLOCKED' : 'LOCKED' }}
                </span>
            </div>
        </div>

        <!-- Terminal Output Stream -->
        <div class="terminal-body" id="terminal-viewport-body">
            @foreach($history as $item)
                <div>
                    <!-- Command Prompt Line -->
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span class="terminal-prompt-user">rms@{{ strtolower($serverLabel) }}</span>
                            <span style="color: #64748b;">:</span>
                            <span class="terminal-prompt-path">[{{ $item['target'] }}]</span>
                            <span style="color: #38bdf8; font-weight: 700;">$</span>
                            <span style="color: #f8fafc; font-weight: 600;">{{ $item['command'] }}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            @if(isset($item['exit_code']))
                                <span style="font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; {{ $item['exit_code'] === 0 ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);' }}">
                                    exit {{ $item['exit_code'] }}
                                </span>
                            @endif
                            <span style="font-size: 10.5px; color: #475569;">{{ $item['time'] ?? '' }}</span>
                        </div>
                    </div>

                    <!-- Output Content -->
                    @if(!empty($item['output']))
                        <div class="terminal-log-output">{{ $item['output'] }}</div>
                    @endif
                </div>
            @endforeach

            <!-- Busy Execution Spinner Indicator -->
            <div wire:loading wire:target="executeCommand, runQuick, generateSafetyBackup" style="display: flex; align-items: center; gap: 10px; color: #38bdf8; font-weight: 600; font-size: 12.5px; padding: 6px 0;">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>Processing operation... please wait</span>
            </div>
        </div>

        <!-- Terminal Input Prompt -->
        <form wire:submit="executeCommand" class="terminal-input-bar">
            <span class="terminal-prompt-user" style="flex-shrink: 0;">rms@{{ strtolower($serverLabel) }}</span>
            <span style="color: #64748b; flex-shrink: 0;">:</span>
            <span class="terminal-prompt-path" style="flex-shrink: 0;">[{{ $target }}]</span>
            <span style="color: #38bdf8; font-weight: 700; flex-shrink: 0;">$</span>
            
            <input 
                type="text" 
                id="term-cmd-input"
                wire:model="command" 
                @if(!$hasSafetyBackup) disabled placeholder="Terminal Locked: Click 'Generate Safety Backup' above to unlock console..." @else placeholder="Type command here (e.g. php artisan migrate, git status, npm run build)..." @endif
                wire:loading.attr="disabled"
                wire:target="executeCommand, runQuick, generateSafetyBackup"
                @keydown.up.prevent="navigateHistory('up')"
                @keydown.down.prevent="navigateHistory('down')"
                autocomplete="off"
                class="terminal-input-field" 
            />

            <button 
                type="submit" 
                @if(!$hasSafetyBackup) disabled style="background: #475569; opacity: 0.6; color: #ffffff; border: none; padding: 6px 14px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: not-allowed; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;" @else style="background: #2563eb; color: #ffffff; border: none; padding: 6px 14px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; transition: background 0.15s ease;" @endif
                wire:loading.attr="disabled"
                wire:target="executeCommand, runQuick, generateSafetyBackup"
            >
                <i class="fa-solid fa-play" wire:loading.remove wire:target="executeCommand, runQuick, generateSafetyBackup"></i>
                <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="executeCommand, runQuick, generateSafetyBackup"></i>
                <span>Execute</span>
            </button>
        </form>
    </div>

    <!-- Security & Terminal Usage Instructions Card -->
    <div style="background: #ffffff; padding: 18px 22px; border-radius: 14px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 8px; color: #0f172a; font-weight: 700; font-size: 13.5px;">
            <i class="fa-solid fa-circle-question" style="color: #2563eb;"></i>
            <span>Terminal Usage Guide & Operational Notes</span>
        </div>
        <div style="font-size: 12.5px; color: #64748b; line-height: 1.6; display: flex; flex-direction: column; gap: 6px;">
            <div>&bull; <strong>Soft Protection Requirement:</strong> A complete database snapshot is created before terminal access is granted to ensure absolute data safety.</div>
            <div>&bull; <strong>Up / Down Arrow Keys:</strong> Cycle through previously submitted commands in this session.</div>
            <div>&bull; <strong>Target Selection:</strong> Select the corresponding target container (e.g. <code>App</code> for Artisan & Composer, <code>Node</code> for npm/Vite, <code>DB</code> for PostgreSQL, or <code>Host</code> for server system & git).</div>
            <div>&bull; <strong>Non-Interactive Commands Only:</strong> Interactive screen programs like <code>nano</code>, <code>vim</code>, <code>htop</code>, or indefinite streams like <code>tail -f</code> are blocked to prevent process hanging. Use batch parameters (e.g., <code>top -b -n 1</code> or <code>cat</code>).</div>
            <div>&bull; <strong>Process Timeout:</strong> Long operations will automatically terminate after 60 seconds to preserve web worker responsiveness.</div>
        </div>
    </div>
</div>

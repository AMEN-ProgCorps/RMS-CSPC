<?php

use App\Services\NasStorageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - NAS & Object Storage')] class extends Component {
    use WithFileUploads;

    public string $currentPrefix = '';
    public string $search = '';

    // Folder creation modal
    public bool $showNewFolderModal = false;
    public string $newFolderName = '';

    // Upload state
    public bool $showUploadModal = false;
    public $uploadedFile = null;

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
    }

    public function navigateTo(string $prefix): void
    {
        $this->currentPrefix = trim($prefix, '/');
        $this->clearMessages();
    }

    public function navigateUp(): void
    {
        if (empty($this->currentPrefix)) return;
        $parts = explode('/', $this->currentPrefix);
        array_pop($parts);
        $this->currentPrefix = implode('/', $parts);
        $this->clearMessages();
    }

    public function testConnection(): void
    {
        $ok = NasStorageService::testWrite();
        if ($ok) {
            $this->successMessage = 'NAS Read & Write Probe Test Passed successfully!';
        } else {
            $this->errorMessage = 'NAS Probe Test Failed. Verify storage endpoint, bucket, or network.';
        }
    }

    public function openNewFolderModal(): void
    {
        $this->newFolderName = '';
        $this->showNewFolderModal = true;
    }

    public function createFolder(): void
    {
        $this->validate([
            'newFolderName' => 'required|string|min:1|max:80|regex:/^[a-zA-Z0-9_\-\.]+$/',
        ]);

        $folderPath = ($this->currentPrefix ? ($this->currentPrefix . '/') : '') . trim($this->newFolderName);
        $ok = NasStorageService::createFolder($folderPath);

        if ($ok) {
            $this->successMessage = "Created directory '{$this->newFolderName}'.";
            $this->showNewFolderModal = false;
        } else {
            $this->errorMessage = "Could not create directory on NAS.";
        }
    }

    public function openUploadModal(): void
    {
        $this->uploadedFile = null;
        $this->showUploadModal = true;
    }

    public function submitUpload(): void
    {
        $this->validate([
            'uploadedFile' => 'required|file|max:51200', // 50MB
        ]);

        $filename = $this->uploadedFile->getClientOriginalName();
        $targetPath = ($this->currentPrefix ? ($this->currentPrefix . '/') : '') . $filename;

        $content = file_get_contents($this->uploadedFile->getRealPath());
        $ok = NasStorageService::uploadFile($targetPath, $content);

        if ($ok) {
            $this->successMessage = "Uploaded '{$filename}' to NAS successfully.";
            $this->showUploadModal = false;
            $this->uploadedFile = null;
        } else {
            $this->errorMessage = "Failed to upload '{$filename}' to NAS.";
        }
    }

    public function deleteItem(string $path, string $type): void
    {
        $isDir = ($type === 'directory');
        $ok = NasStorageService::delete($path, $isDir);

        if ($ok) {
            $this->successMessage = "Deleted '{$path}'.";
        } else {
            $this->errorMessage = "Failed to delete item from NAS.";
        }
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function with(): array
    {
        $status = NasStorageService::getStatus();
        $items = NasStorageService::listObjects($this->currentPrefix);

        if ($this->search !== '') {
            $q = strtolower(trim($this->search));
            $items = array_filter($items, fn($it) => str_contains(strtolower($it['name']), $q));
        }

        // Breadcrumb parts
        $breadcrumbs = [];
        if (!empty($this->currentPrefix)) {
            $accum = '';
            foreach (explode('/', $this->currentPrefix) as $p) {
                $accum .= ($accum ? '/' : '') . $p;
                $breadcrumbs[] = [
                    'label' => $p,
                    'path' => $accum,
                ];
            }
        }

        return [
            'status' => $status,
            'items' => array_values($items),
            'breadcrumbs' => $breadcrumbs,
        ];
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .nas-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px 24px;
            box-sizing: border-box;
            user-select: none;
            font-family: Inter, system-ui, -apple-system, sans-serif;
            color: #0f172a;
        }

        /* Top Header */
        .nas-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .nas-top-title h1 {
            font-size: 1.45rem;
            font-weight: 800;
            margin: 0;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .nas-top-title p {
            margin: 4px 0 0 0;
            font-size: 0.85rem;
            color: #64748b;
        }
        .nas-top-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .nas-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
        }
        .nas-btn.primary { background: #0284c7; color: #ffffff; }
        .nas-btn.primary:hover { background: #0369a1; }
        .nas-btn.secondary { background: #ffffff; border: 1.5px solid #cbd5e1; color: #334155; }
        .nas-btn.secondary:hover { background: #f8fafc; border-color: #94a3b8; }
        .nas-btn.success { background: #059669; color: #ffffff; }
        .nas-btn.success:hover { background: #047857; }
        .nas-btn.danger { background: #ef4444; color: #ffffff; }
        .nas-btn.danger:hover { background: #dc2626; }

        /* KPI Cards */
        .nas-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .nas-stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .nas-stat-val {
            font-size: 1.45rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .nas-stat-lbl {
            font-size: 0.78rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .nas-stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        /* Explorer Card */
        .nas-explorer-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 520px;
        }
        .nas-toolbar {
            padding: 12px 18px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .nas-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
        }
        .nas-crumb {
            cursor: pointer;
            color: #0284c7;
            text-decoration: none;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .nas-crumb:hover { background: #e0f2fe; }

        /* Search input */
        .nas-search-input {
            padding: 7px 12px 7px 32px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.82rem;
            background: #ffffff;
            outline: none;
            width: 240px;
        }
        .nas-search-input:focus { border-color: #0284c7; }

        /* Files Table */
        .nas-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .nas-table th {
            text-align: left;
            padding: 11px 18px;
            background: #f1f5f9;
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #e2e8f0;
        }
        .nas-table td {
            padding: 12px 18px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
        }
        .nas-table tr:hover td {
            background: #f8fafc;
        }
        .nas-item-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            cursor: pointer;
        }
        .nas-item-name:hover {
            color: #0284c7;
        }
        .nas-icon-folder { color: #f59e0b; font-size: 1.15rem; }
        .nas-icon-file { color: #64748b; font-size: 1.15rem; }
        .nas-icon-pdf { color: #ef4444; font-size: 1.15rem; }
        .nas-icon-img { color: #10b981; font-size: 1.15rem; }
        .nas-icon-zip { color: #8b5cf6; font-size: 1.15rem; }

        /* Modal */
        .nas-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000200;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .nas-modal {
            background: #ffffff;
            border-radius: 14px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .nas-modal-head {
            padding: 16px 20px;
            background: #0f172a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nas-modal-head h3 { margin: 0; font-size: 1.05rem; font-weight: 700; }
        .nas-modal-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
        .nas-modal-foot { padding: 12px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px; }
    </style>
@endpush

<div class="nas-container">
    <!-- Top Bar -->
    <div class="nas-top-bar">
        <div class="nas-top-title">
            <h1>
                <i class="fa-solid fa-server" style="color: #0284c7;"></i>
                NAS & S3 Object Storage
            </h1>
            <p>Manage, inspect, and monitor high-speed Network Attached Storage (Pigsty Silo / MinIO S3 cluster).</p>
        </div>
        <div class="nas-top-actions">
            <button type="button" class="nas-btn secondary" wire:click="testConnection">
                <i class="fa-solid fa-bolt"></i> Probe Connection
            </button>
            <a href="{{ $status['console_url'] }}" target="_blank" class="nas-btn secondary">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Silo Web Console
            </a>
            <button type="button" class="nas-btn secondary" wire:click="openNewFolderModal">
                <i class="fa-solid fa-folder-plus"></i> New Folder
            </button>
            <button type="button" class="nas-btn primary" wire:click="openUploadModal">
                <i class="fa-solid fa-upload"></i> Upload to NAS
            </button>
        </div>
    </div>

    <!-- Feedback Banners -->
    @if($successMessage)
        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; font-size: 0.85rem;">
            <span><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" style="border: none; background: transparent; cursor: pointer; color: #065f46; font-size: 1rem;">&times;</button>
        </div>
    @endif
    @if($errorMessage)
        <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; font-size: 0.85rem;">
            <span><i class="fa-solid fa-circle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" style="border: none; background: transparent; cursor: pointer; color: #991b1b; font-size: 1rem;">&times;</button>
        </div>
    @endif

    <!-- KPI Metric Cards -->
    <div class="nas-stats-grid">
        <div class="nas-stat-card">
            <div>
                <div class="nas-stat-val" style="color: {{ $status['is_connected'] ? '#059669' : '#dc2626' }};">
                    {{ $status['is_connected'] ? 'ONLINE' : 'OFFLINE' }}
                </div>
                <div class="nas-stat-lbl">NAS Cluster Status</div>
            </div>
            <div class="nas-stat-icon" style="background: {{ $status['is_connected'] ? '#ecfdf5' : '#fef2f2' }}; color: {{ $status['is_connected'] ? '#059669' : '#dc2626' }};">
                <i class="fa-solid fa-network-wired"></i>
            </div>
        </div>

        <div class="nas-stat-card">
            <div>
                <div class="nas-stat-val">{{ $status['total_files'] }}</div>
                <div class="nas-stat-lbl">Stored Objects</div>
            </div>
            <div class="nas-stat-icon" style="background: #f0f9ff; color: #0284c7;">
                <i class="fa-solid fa-file-shield"></i>
            </div>
        </div>

        <div class="nas-stat-card">
            <div>
                <div class="nas-stat-val">{{ $status['total_size_human'] }}</div>
                <div class="nas-stat-lbl">Storage Consumed</div>
            </div>
            <div class="nas-stat-icon" style="background: #eff6ff; color: #3b82f6;">
                <i class="fa-solid fa-hard-drive"></i>
            </div>
        </div>

        <div class="nas-stat-card">
            <div>
                <div class="nas-stat-val">{{ $status['latency_ms'] !== null ? $status['latency_ms'] . ' ms' : '--' }}</div>
                <div class="nas-stat-lbl">API Latency</div>
            </div>
            <div class="nas-stat-icon" style="background: #fefce8; color: #ca8a04;">
                <i class="fa-solid fa-gauge-high"></i>
            </div>
        </div>
    </div>

    <!-- Explorer Card -->
    <div class="nas-explorer-card">
        <!-- Toolbar & Breadcrumb -->
        <div class="nas-toolbar">
            <div class="nas-breadcrumbs">
                <span class="nas-crumb" wire:click="navigateTo('')">
                    <i class="fa-solid fa-database" style="margin-right: 4px;"></i> {{ $status['bucket'] }}
                </span>
                @foreach($breadcrumbs as $b)
                    <span style="color: #cbd5e1;">/</span>
                    <span class="nas-crumb" wire:click="navigateTo('{{ $b['path'] }}')">{{ $b['label'] }}</span>
                @endforeach
            </div>

            <div style="display: flex; align-items: center; gap: 10px;">
                @if(!empty($currentPrefix))
                    <button type="button" class="nas-btn secondary" style="padding: 6px 12px;" wire:click="navigateUp">
                        <i class="fa-solid fa-arrow-turn-up"></i> Up Level
                    </button>
                @endif
                <div style="position: relative;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.8rem;"></i>
                    <input type="text" class="nas-search-input" placeholder="Filter objects..." wire:model.live="search">
                </div>
            </div>
        </div>

        <!-- Objects Table -->
        <div style="overflow-x: auto; flex: 1;">
            <table class="nas-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">Name</th>
                        <th style="width: 15%;">Size</th>
                        <th style="width: 25%;">Last Modified</th>
                        <th style="width: 10%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr>
                            <td>
                                @if($it['type'] === 'directory')
                                    <div class="nas-item-name" wire:click="navigateTo('{{ $it['path'] }}')">
                                        <i class="fa-solid fa-folder nas-icon-folder"></i>
                                        <span>{{ $it['name'] }}</span>
                                    </div>
                                @else
                                    <div class="nas-item-name" style="cursor: default;">
                                        @if($it['extension'] === 'pdf')
                                            <i class="fa-solid fa-file-pdf nas-icon-pdf"></i>
                                        @elseif(in_array($it['extension'], ['jpg','jpeg','png','webp','gif'], true))
                                            <i class="fa-solid fa-file-image nas-icon-img"></i>
                                        @elseif(in_array($it['extension'], ['zip','tar','gz','sql'], true))
                                            <i class="fa-solid fa-file-zipper nas-icon-zip"></i>
                                        @else
                                            <i class="fa-solid fa-file nas-icon-file"></i>
                                        @endif
                                        <span>{{ $it['name'] }}</span>
                                    </div>
                                @endif
                            </td>
                            <td>{{ $it['size_human'] }}</td>
                            <td>
                                @if($it['last_modified'])
                                    <span style="font-size: 0.8rem; color: #64748b;">
                                        {{ date('M d, Y h:i A', $it['last_modified']) }}
                                    </span>
                                @else
                                    <span style="color: #94a3b8;">--</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <button
                                    type="button"
                                    class="nas-btn danger"
                                    style="padding: 4px 8px; font-size: 0.75rem;"
                                    onclick="confirm('Permanently delete {{ $it['name'] }} from NAS storage?') || event.stopImmediatePropagation()"
                                    wire:click="deleteItem('{{ $it['path'] }}', '{{ $it['type'] }}')"
                                    title="Delete from NAS"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 48px 20px; color: #94a3b8;">
                                <i class="fa-solid fa-box-open" style="font-size: 2.2rem; margin-bottom: 8px; opacity: 0.4;"></i>
                                <p style="margin: 0; font-size: 0.9rem;">No objects found in this directory.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- New Folder Modal -->
    @if($showNewFolderModal)
        <div class="nas-modal-overlay">
            <div class="nas-modal">
                <div class="nas-modal-head">
                    <h3><i class="fa-solid fa-folder-plus" style="margin-right: 8px; color: #38bdf8;"></i> Create NAS Folder</h3>
                    <button type="button" wire:click="$set('showNewFolderModal', false)" style="border: none; background: transparent; color: #94a3b8; font-size: 1.2rem; cursor: pointer;">&times;</button>
                </div>
                <div class="nas-modal-body">
                    <p style="font-size: 0.85rem; color: #64748b; margin: 0;">
                        Target Directory: <code>{{ $currentPrefix ?: '/' }}</code>
                    </p>
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 0.78rem; font-weight: 700; color: #334155; text-transform: uppercase;">Folder Name</label>
                        <input type="text" style="padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 0.85rem;" placeholder="e.g. backup-archives" wire:model="newFolderName">
                        @error('newFolderName') <span style="color: #ef4444; font-size: 0.78rem;">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="nas-modal-foot">
                    <button type="button" class="nas-btn secondary" wire:click="$set('showNewFolderModal', false)">Cancel</button>
                    <button type="button" class="nas-btn primary" wire:click="createFolder">Create Folder</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Upload Modal -->
    @if($showUploadModal)
        <div class="nas-modal-overlay">
            <div class="nas-modal">
                <div class="nas-modal-head">
                    <h3><i class="fa-solid fa-cloud-arrow-up" style="margin-right: 8px; color: #38bdf8;"></i> Upload to NAS</h3>
                    <button type="button" wire:click="$set('showUploadModal', false)" style="border: none; background: transparent; color: #94a3b8; font-size: 1.2rem; cursor: pointer;">&times;</button>
                </div>
                <div class="nas-modal-body">
                    <p style="font-size: 0.85rem; color: #64748b; margin: 0;">
                        Target Folder: <code>{{ $currentPrefix ?: '/' }}</code>
                    </p>
                    <div style="border: 2px dashed #cbd5e1; padding: 24px; border-radius: 10px; text-align: center; background: #f8fafc;">
                        <input type="file" wire:model="uploadedFile" style="font-size: 0.85rem;">
                        <p style="margin: 8px 0 0 0; font-size: 0.75rem; color: #94a3b8;">Max file size: 50MB</p>
                    </div>
                    @error('uploadedFile') <span style="color: #ef4444; font-size: 0.78rem;">{{ $message }}</span> @enderror
                </div>
                <div class="nas-modal-foot">
                    <button type="button" class="nas-btn secondary" wire:click="$set('showUploadModal', false)">Cancel</button>
                    <button type="button" class="nas-btn primary" wire:click="submitUpload" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitUpload">Upload File</span>
                        <span wire:loading wire:target="submitUpload">Uploading…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

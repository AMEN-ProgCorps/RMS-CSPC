<?php

use App\Services\NasStorageService;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Admin Console - NAS Datastore File Browser')] class extends Component {
    use WithFileUploads;

    public string $currentPrefix = '';
    public ?string $selectedPath = null;
    public ?string $selectedType = null; // 'file' or 'directory'
    public string $search = '';
    public string $dirSearch = '';

    // Folder creation modal
    public bool $showNewFolderModal = false;
    public string $newFolderName = '';

    // Upload state
    public bool $showUploadModal = false;
    public $uploadedFile = null;

    // Feedback
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
        $this->selectedPath = null;
        $this->selectedType = null;
        $this->search = '';
        $this->clearMessages();
    }

    public function navigateUp(): void
    {
        if (empty($this->currentPrefix)) {
            return;
        }
        $parts = explode('/', $this->currentPrefix);
        array_pop($parts);
        $this->currentPrefix = implode('/', $parts);
        $this->selectedPath = null;
        $this->selectedType = null;
        $this->clearMessages();
    }

    public function selectItem(string $path, string $type): void
    {
        $this->selectedPath = $path;
        $this->selectedType = $type;
        $this->clearMessages();
    }

    public function clearSelection(): void
    {
        $this->selectedPath = null;
        $this->selectedType = null;
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
            $this->selectedPath = $targetPath;
            $this->selectedType = 'file';
        } else {
            $this->errorMessage = "Failed to upload '{$filename}' to NAS.";
        }
    }

    public function downloadItem(string $path)
    {
        try {
            $disk = Storage::disk('s3');
            if (! $disk->exists($path)) {
                $this->errorMessage = "File does not exist on NAS storage.";
                return null;
            }

            return $disk->download($path, basename($path));
        } catch (\Throwable $e) {
            $this->errorMessage = "Download error: " . $e->getMessage();
            return null;
        }
    }

    public function deleteItem(string $path, string $type): void
    {
        $isDir = ($type === 'directory');
        $ok = NasStorageService::delete($path, $isDir);

        if ($ok) {
            $this->successMessage = "Deleted '{$path}'.";
            if ($this->selectedPath === $path) {
                $this->selectedPath = null;
                $this->selectedType = null;
            }
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
        $allDirs = NasStorageService::getAllDirectories();

        // Filter directory column
        if ($this->dirSearch !== '') {
            $qDir = strtolower(trim($this->dirSearch));
            $allDirs = array_filter($allDirs, fn($d) => str_contains(strtolower($d), $qDir));
        }

        // Filter files/objects in current directory
        if ($this->search !== '') {
            $q = strtolower(trim($this->search));
            $items = array_filter($items, fn($it) => str_contains(strtolower($it['name']), $q));
        }

        // Breadcrumbs
        $breadcrumbs = [];
        if (! empty($this->currentPrefix)) {
            $accum = '';
            foreach (explode('/', $this->currentPrefix) as $p) {
                $accum .= ($accum ? '/' : '') . $p;
                $breadcrumbs[] = [
                    'label' => $p,
                    'path' => $accum,
                ];
            }
        }

        // Selected object details
        $selectedObject = null;
        if ($this->selectedPath) {
            $selectedObject = NasStorageService::getObjectDetails($this->selectedPath, $this->selectedType ?? 'file');
        }

        return [
            'status' => $status,
            'items' => array_values($items),
            'directories' => array_values($allDirs),
            'breadcrumbs' => $breadcrumbs,
            'selectedObject' => $selectedObject,
        ];
    }
};
?>

@push('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            user-select: none;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #0f172a;
        }

        /* 3-Column Miller Browser Layout inspired by ESXi Datastore File Browser */
        .miller-browser {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 290px 1fr 380px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            min-height: 520px;
        }

        .miller-column {
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-height: 0;
            position: relative;
        }
        .miller-column:nth-child(even) {
            background: #fafbfc;
        }
        .miller-column:last-child {
            border-right: none;
        }

        .m-col-header {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .m-col-header span {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .m-col-search {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            color: #0f172a;
        }
        .m-col-search input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-col-list {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 4px 0;
        }

        .m-item {
            padding: 9px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12.5px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
            gap: 8px;
        }
        .m-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .m-item.active {
            background: #0284c7;
            color: #ffffff;
            border-left-color: #0369a1;
            font-weight: 600;
        }
        .m-item.active .m-item-icon,
        .m-item.active .m-item-arrow,
        .m-item.active .m-item-sub {
            color: #ffffff !important;
            opacity: 0.9;
        }
        .m-item.active .m-item-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .m-item-left {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
            flex: 1;
        }
        .m-item-avatar {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }
        .m-item-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        .m-item-title {
            font-weight: 600;
            font-size: 12.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .m-item-sub {
            font-size: 10.5px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .m-item-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .m-item-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
        }
        .m-item-arrow {
            font-size: 11px;
            color: #94a3b8;
        }

        /* Inspector Panel (Column 3) */
        .m-inspector {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow-y: auto;
            flex: 1 1 auto;
        }
        .m-inspector-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .m-inspector-avatar {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .m-inspector-title {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
            margin: 0;
            word-break: break-all;
        }
        .m-inspector-sub {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .m-field-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .m-field-label {
            font-size: 10.5px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .m-field-input {
            width: 100%;
            padding: 6px 9px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            color: #0f172a;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            font-family: inherit;
        }
        .m-field-input[readonly] {
            background: #f8fafc;
            color: #334155;
            font-family: monospace;
            font-size: 11px;
        }

        .m-metric-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .m-metric-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
        }
        .m-metric-value {
            font-size: 12px;
            font-weight: 800;
            color: #0f172a;
        }

        /* Action Buttons */
        .m-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            text-decoration: none;
        }
        .m-btn-primary { background: #0284c7; color: #ffffff; }
        .m-btn-primary:hover { background: #0369a1; }
        .m-btn-secondary { background: #ffffff; color: #475569; border: 1px solid #cbd5e1; }
        .m-btn-secondary:hover { background: #f1f5f9; color: #0f172a; }
        .m-btn-danger { background: #ef4444; color: #ffffff; }
        .m-btn-danger:hover { background: #dc2626; }
        .m-btn-success { background: #059669; color: #ffffff; }
        .m-btn-success:hover { background: #047857; }

        /* Bottom Datastore Path Bar */
        .miller-path-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 8px 14px;
            font-size: 11.5px;
            color: #475569;
            overflow-x: auto;
            white-space: nowrap;
        }
        .miller-path-tag {
            font-weight: 700;
            color: #0284c7;
            cursor: pointer;
        }
        .miller-path-tag:hover {
            text-decoration: underline;
        }

        /* Empty State */
        .m-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 36px 18px;
            text-align: center;
            color: #94a3b8;
            height: 100%;
            box-sizing: border-box;
        }
        .m-empty-icon {
            font-size: 32px;
            margin-bottom: 10px;
            color: #cbd5e1;
        }

        /* Preview box */
        .m-preview-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            max-height: 200px;
            overflow: hidden;
        }
        .m-preview-img {
            max-width: 100%;
            max-height: 180px;
            object-fit: contain;
            border-radius: 6px;
        }
        .m-preview-text {
            font-family: monospace;
            font-size: 11px;
            background: #0f172a;
            color: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
            max-height: 140px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        /* Modals */
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
            border-radius: 12px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        .nas-modal-head {
            padding: 14px 18px;
            background: #0f172a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nas-modal-head h3 { margin: 0; font-size: 1rem; font-weight: 700; }
        .nas-modal-body { padding: 18px; display: flex; flex-direction: column; gap: 12px; }
        .nas-modal-foot { padding: 12px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px; }

        /* Dark Mode Overrides */
        [data-theme="dark"] .miller-browser,
        [data-theme="dark"] .miller-column {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .miller-column:nth-child(even) {
            background: #182234;
        }
        [data-theme="dark"] .m-col-header,
        [data-theme="dark"] .miller-path-bar {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-col-search {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .m-col-search input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-item {
            color: #cbd5e1;
        }
        [data-theme="dark"] .m-item:hover {
            background: #334155;
            color: #f8fafc;
        }
        [data-theme="dark"] .m-item.active {
            background: #0284c7;
            color: #ffffff;
        }
        [data-theme="dark"] .m-field-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-field-input[readonly] {
            background: #0f172a;
            color: #94a3b8;
        }
        [data-theme="dark"] .m-metric-card {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .m-metric-label {
            color: #94a3b8;
        }
        [data-theme="dark"] .m-metric-value {
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-btn-secondary {
            background: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }
        [data-theme="dark"] .m-btn-secondary:hover {
            background: #334155;
            color: #ffffff;
        }
        [data-theme="dark"] .nas-modal {
            background: #1e293b;
            color: #f1f5f9;
        }
        [data-theme="dark"] .nas-modal-foot {
            background: #0f172a;
            border-color: #334155;
        }
    </style>
@endpush

<div class="miller-container">
    {{-- Top Action Toolbar --}}
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <h1 style="font-size: 19px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-server" style="color: #0284c7;"></i> NAS Datastore File Browser
                </h1>
                <span style="font-size: 11px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 9999px; border: 1px solid #bae6fd;">
                    VMware ESXi Miller Columns
                </span>
                <span style="font-size: 11px; font-weight: 700; background: {{ $status['is_connected'] ? '#ecfdf5' : '#fef2f2' }}; color: {{ $status['is_connected'] ? '#059669' : '#dc2626' }}; padding: 3px 8px; border-radius: 9999px; border: 1px solid {{ $status['is_connected'] ? '#a7f3d0' : '#fecaca' }};">
                    <i class="fa-solid fa-circle" style="font-size: 7px; margin-right: 4px;"></i>
                    {{ $status['is_connected'] ? 'ONLINE (' . ($status['latency_ms'] ?? 0) . ' ms)' : 'OFFLINE' }}
                </span>
            </div>
            <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
                High-speed Pigsty Silo / S3 datastore: browse bucket <code>[{{ $status['bucket'] }}]</code>, inspect object metadata, upload files, and audit storage lifecycle.
            </p>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            <button type="button" class="m-btn m-btn-secondary" wire:click="testConnection" title="Probe S3 Read & Write Health">
                <i class="fa-solid fa-bolt"></i> Probe Connection
            </button>
            <a href="{{ $status['console_url'] }}" target="_blank" class="m-btn m-btn-secondary" title="Open Pigsty Silo Web Console">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Silo Console
            </a>
            <button type="button" class="m-btn m-btn-secondary" wire:click="openNewFolderModal">
                <i class="fa-solid fa-folder-plus"></i> New Folder
            </button>
            <button type="button" class="m-btn m-btn-primary" wire:click="openUploadModal">
                <i class="fa-solid fa-upload"></i> Upload Object
            </button>
        </div>
    </div>

    {{-- Feedback Messages --}}
    @if($successMessage)
        <div style="margin-bottom: 12px; padding: 8px 12px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-size: 12.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> {{ $successMessage }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer; font-size: 15px;">&times;</button>
        </div>
    @endif
    @if($errorMessage)
        <div style="margin-bottom: 12px; padding: 8px 12px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 12.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 6px;"></i> {{ $errorMessage }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 15px;">&times;</button>
        </div>
    @endif

    {{-- 3-Column ESXi Datastore Miller Browser --}}
    <div class="miller-browser">
        {{-- COLUMN 1: DATASTORE ROOT & DIRECTORIES --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span><i class="fa-solid fa-database" style="color: #0284c7;"></i> 1. Datastore Folders</span>
                <span class="m-item-badge">{{ count($directories) + 1 }}</span>
            </div>

            <div class="m-col-search">
                <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                <input type="text" placeholder="Filter folders..." wire:model.live="dirSearch">
            </div>

            <div class="m-col-list">
                {{-- Root Directory --}}
                <div class="m-item {{ empty($currentPrefix) ? 'active' : '' }}" wire:click="navigateTo('')">
                    <div class="m-item-left">
                        <div class="m-item-avatar" style="background: #e0f2fe; color: #0284c7;">
                            <i class="fa-solid fa-hard-drive"></i>
                        </div>
                        <div class="m-item-text">
                            <span class="m-item-title">[{{ $status['bucket'] }}] /</span>
                            <span class="m-item-sub">Root Directory</span>
                        </div>
                    </div>
                    <div class="m-item-right">
                        <span class="m-item-badge">Root</span>
                        <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                    </div>
                </div>

                {{-- Discovered Directory Hierarchy --}}
                @foreach($directories as $dir)
                    @php
                        $isActiveDir = ($currentPrefix === $dir) || str_starts_with($currentPrefix, $dir . '/');
                        $isCurrentExact = ($currentPrefix === $dir);
                    @endphp
                    <div class="m-item {{ $isCurrentExact ? 'active' : '' }}" wire:click="navigateTo('{{ $dir }}')">
                        <div class="m-item-left">
                            <div class="m-item-avatar" style="background: #fef3c7; color: #d97706;">
                                <i class="fa-solid fa-folder"></i>
                            </div>
                            <div class="m-item-text">
                                <span class="m-item-title">{{ basename($dir) }}</span>
                                <span class="m-item-sub">{{ $dir }}</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Column 1 Quick Action Footer --}}
            <div style="padding: 8px 10px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 11px; color: #64748b; font-weight: 600;">S3 Node: s3-storage</span>
                <button type="button" class="m-btn m-btn-secondary" style="padding: 4px 8px; font-size: 11px;" wire:click="openNewFolderModal">
                    <i class="fa-solid fa-plus"></i> Folder
                </button>
            </div>
        </div>

        {{-- COLUMN 2: DIRECTORY CONTENTS & OBJECTS --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span>
                    <i class="fa-solid fa-folder-open" style="color: #f59e0b;"></i>
                    2. Contents: <code>/{{ $currentPrefix }}</code>
                </span>
                <span class="m-item-badge">{{ count($items) }}</span>
            </div>

            <div class="m-col-search">
                <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                <input type="text" placeholder="Filter objects in current directory..." wire:model.live="search">
                @if(!empty($currentPrefix))
                    <button type="button" class="m-btn m-btn-secondary" style="padding: 4px 8px; font-size: 11px; white-space: nowrap;" wire:click="navigateUp" title="Up one level">
                        <i class="fa-solid fa-arrow-turn-up"></i> Up
                    </button>
                @endif
            </div>

            <div class="m-col-list">
                @forelse($items as $it)
                    @php
                        $isSelected = ($selectedPath === $it['path']);
                    @endphp
                    <div
                        class="m-item {{ $isSelected ? 'active' : '' }}"
                        wire:click="selectItem('{{ $it['path'] }}', '{{ $it['type'] }}')"
                        @if($it['type'] === 'directory')
                            ondblclick="@this.navigateTo('{{ $it['path'] }}')"
                        @endif
                    >
                        <div class="m-item-left">
                            {{-- Icon by Type --}}
                            <div class="m-item-avatar"
                                @if($it['type'] === 'directory')
                                    style="background: #fef3c7; color: #d97706;"
                                @elseif($it['extension'] === 'pdf')
                                    style="background: #fee2e2; color: #dc2626;"
                                @elseif(in_array($it['extension'], ['jpg','jpeg','png','webp','gif','svg'], true))
                                    style="background: #dcfce7; color: #16a34a;"
                                @elseif(in_array($it['extension'], ['zip','tar','gz','rar','7z'], true))
                                    style="background: #f3e8ff; color: #9333ea;"
                                @elseif(in_array($it['extension'], ['sql','json','xml','txt','md','csv'], true))
                                    style="background: #e0f2fe; color: #0284c7;"
                                @else
                                    style="background: #f1f5f9; color: #64748b;"
                                @endif
                            >
                                @if($it['type'] === 'directory')
                                    <i class="fa-solid fa-folder"></i>
                                @elseif($it['extension'] === 'pdf')
                                    <i class="fa-solid fa-file-pdf"></i>
                                @elseif(in_array($it['extension'], ['jpg','jpeg','png','webp','gif','svg'], true))
                                    <i class="fa-solid fa-file-image"></i>
                                @elseif(in_array($it['extension'], ['zip','tar','gz','rar','7z'], true))
                                    <i class="fa-solid fa-file-zipper"></i>
                                @elseif(in_array($it['extension'], ['sql','json','xml','txt','md','csv'], true))
                                    <i class="fa-solid fa-file-lines"></i>
                                @else
                                    <i class="fa-solid fa-file"></i>
                                @endif
                            </div>

                            <div class="m-item-text">
                                <span class="m-item-title">{{ $it['name'] }}</span>
                                <span class="m-item-sub">
                                    @if($it['type'] === 'directory')
                                        Virtual Directory
                                    @else
                                        {{ $it['size_human'] }} &bull; {{ $it['last_modified'] ? date('M d, Y', $it['last_modified']) : '--' }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div class="m-item-right">
                            @if($it['type'] === 'directory')
                                <button
                                    type="button"
                                    class="m-btn m-btn-secondary"
                                    style="padding: 2px 7px; font-size: 10px;"
                                    wire:click.stop="navigateTo('{{ $it['path'] }}')"
                                    title="Open Folder"
                                >
                                    Open
                                </button>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            @else
                                <span class="m-item-badge">{{ strtoupper($it['extension'] ?: 'BIN') }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="m-empty-state">
                        <i class="fa-solid fa-box-open m-empty-icon"></i>
                        <p style="margin: 0; font-size: 13px; font-weight: 700; color: #475569;">No Objects Found</p>
                        <p style="margin: 4px 0 12px 0; font-size: 11.5px; color: #94a3b8;">
                            @if(!empty($search))
                                No objects matching "{{ $search }}" in this directory.
                            @else
                                This directory is currently empty.
                            @endif
                        </p>
                        <button type="button" class="m-btn m-btn-primary" style="font-size: 11px; padding: 5px 10px;" wire:click="openUploadModal">
                            <i class="fa-solid fa-upload"></i> Upload Object Here
                        </button>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- COLUMN 3: OBJECT INSPECTOR & METADATA PANEL --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span><i class="fa-solid fa-circle-info" style="color: #64748b;"></i> 3. Object Inspector</span>
                @if($selectedObject)
                    <span class="m-item-badge" style="background: #0284c7; color: #ffffff;">ACTIVE</span>
                @endif
            </div>

            @if($selectedObject)
                <div class="m-inspector">
                    {{-- Header --}}
                    <div class="m-inspector-header">
                        <div class="m-inspector-avatar"
                            @if($selectedObject['type'] === 'directory')
                                style="background: #d97706;"
                            @elseif(($selectedObject['extension'] ?? '') === 'pdf')
                                style="background: #dc2626;"
                            @elseif(in_array($selectedObject['extension'] ?? '', ['jpg','jpeg','png','webp','gif','svg'], true))
                                style="background: #16a34a;"
                            @elseif(in_array($selectedObject['extension'] ?? '', ['zip','tar','gz'], true))
                                style="background: #9333ea;"
                            @else
                                style="background: #0284c7;"
                            @endif
                        >
                            @if($selectedObject['type'] === 'directory')
                                <i class="fa-solid fa-folder-open"></i>
                            @elseif(($selectedObject['extension'] ?? '') === 'pdf')
                                <i class="fa-solid fa-file-pdf"></i>
                            @elseif(in_array($selectedObject['extension'] ?? '', ['jpg','jpeg','png','webp','gif','svg'], true))
                                <i class="fa-solid fa-file-image"></i>
                            @elseif(in_array($selectedObject['extension'] ?? '', ['zip','tar','gz'], true))
                                <i class="fa-solid fa-file-zipper"></i>
                            @else
                                <i class="fa-solid fa-file-lines"></i>
                            @endif
                        </div>
                        <div style="min-width: 0; flex: 1;">
                            <h3 class="m-inspector-title">{{ $selectedObject['name'] }}</h3>
                            <div class="m-inspector-sub">
                                {{ $selectedObject['type'] === 'directory' ? 'Virtual Directory' : ($selectedObject['mime_type'] ?? 'Binary') }}
                            </div>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        @if($selectedObject['type'] === 'directory')
                            <button type="button" class="m-btn m-btn-primary" style="flex: 1;" wire:click="navigateTo('{{ $selectedObject['path'] }}')">
                                <i class="fa-solid fa-folder-open"></i> Open
                            </button>
                            <button
                                type="button"
                                class="m-btn m-btn-danger"
                                onclick="confirm('Permanently delete directory \'{{ $selectedObject['name'] }}\' and all contents inside?') || event.stopImmediatePropagation()"
                                wire:click="deleteItem('{{ $selectedObject['path'] }}', 'directory')"
                            >
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        @else
                            <button type="button" class="m-btn m-btn-primary" style="flex: 1;" wire:click="downloadItem('{{ $selectedObject['path'] }}')">
                                <i class="fa-solid fa-download"></i> Download
                            </button>
                            <button
                                type="button"
                                class="m-btn m-btn-danger"
                                onclick="confirm('Permanently delete object \'{{ $selectedObject['name'] }}\' from NAS storage?') || event.stopImmediatePropagation()"
                                wire:click="deleteItem('{{ $selectedObject['path'] }}', 'file')"
                                title="Delete Object"
                            >
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        @endif
                    </div>

                    {{-- Inline Preview (if supported) --}}
                    @if(isset($selectedObject['preview']) && $selectedObject['preview'])
                        <div class="m-field-group">
                            <span class="m-field-label">Object Preview</span>
                            <div class="m-preview-card">
                                @if($selectedObject['preview']['type'] === 'image')
                                    <img src="{{ $selectedObject['preview']['data'] }}" alt="Preview" class="m-preview-img">
                                @elseif($selectedObject['preview']['type'] === 'text')
                                    <pre class="m-preview-text">{{ $selectedObject['preview']['content'] }}</pre>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Metadata Cards --}}
                    <div class="m-metric-card">
                        <span class="m-metric-label">Object Type</span>
                        <span class="m-metric-value">{{ strtoupper($selectedObject['type']) }}</span>
                    </div>

                    <div class="m-metric-card">
                        <span class="m-metric-label">Size</span>
                        <span class="m-metric-value">{{ $selectedObject['size_human'] }}</span>
                    </div>

                    @if($selectedObject['type'] === 'directory')
                        <div class="m-metric-card">
                            <span class="m-metric-label">Items Inside</span>
                            <span class="m-metric-value">{{ $selectedObject['item_count'] }}</span>
                        </div>
                    @else
                        <div class="m-metric-card">
                            <span class="m-metric-label">Last Modified</span>
                            <span class="m-metric-value">{{ $selectedObject['last_modified_human'] }}</span>
                        </div>
                    @endif

                    <div class="m-field-group">
                        <span class="m-field-label">S3 Object Key</span>
                        <input type="text" class="m-field-input" readonly value="{{ $selectedObject['path'] }}">
                    </div>

                    <div class="m-field-group">
                        <span class="m-field-label">Canonical S3 URI</span>
                        <input type="text" class="m-field-input" readonly value="{{ $selectedObject['s3_uri'] }}">
                    </div>

                    <div class="m-field-group">
                        <span class="m-field-label">Storage Class</span>
                        <input type="text" class="m-field-input" readonly value="STANDARD (Pigsty Silo NVMe)">
                    </div>
                </div>
            @else
                {{-- No Object Selected: Datastore Health Summary --}}
                <div class="m-inspector">
                    <div class="m-inspector-header">
                        <div class="m-inspector-avatar" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                            <i class="fa-solid fa-server"></i>
                        </div>
                        <div>
                            <h3 class="m-inspector-title">Storage Datastore</h3>
                            <div class="m-inspector-sub">Pigsty Silo S3 Cluster</div>
                        </div>
                    </div>

                    <div class="m-metric-card">
                        <span class="m-metric-label">Datastore Bucket</span>
                        <span class="m-metric-value"><code>{{ $status['bucket'] }}</code></span>
                    </div>

                    <div class="m-metric-card">
                        <span class="m-metric-label">Cluster Status</span>
                        <span class="m-metric-value" style="color: {{ $status['is_connected'] ? '#059669' : '#dc2626' }};">
                            {{ $status['is_connected'] ? 'HEALTHY / ONLINE' : 'OFFLINE' }}
                        </span>
                    </div>

                    <div class="m-metric-card">
                        <span class="m-metric-label">Total Stored Objects</span>
                        <span class="m-metric-value">{{ $status['total_files'] }}</span>
                    </div>

                    <div class="m-metric-card">
                        <span class="m-metric-label">Storage Capacity Consumed</span>
                        <span class="m-metric-value">{{ $status['total_size_human'] }}</span>
                    </div>

                    <div class="m-metric-card">
                        <span class="m-metric-label">Internal API Latency</span>
                        <span class="m-metric-value">{{ $status['latency_ms'] !== null ? $status['latency_ms'] . ' ms' : '--' }}</span>
                    </div>

                    <div class="m-field-group">
                        <span class="m-field-label">S3 Internal API Endpoint</span>
                        <input type="text" class="m-field-input" readonly value="{{ $status['endpoint'] }}">
                    </div>

                    <div style="padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; font-size: 11.5px; line-height: 1.4;">
                        <i class="fa-solid fa-shield-halved" style="margin-right: 6px;"></i>
                        <strong>Encrypted Datastore:</strong> Files uploaded here are stored in dedicated Silo NVMe object storage with S3 protocol compliance.
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Bottom Datastore Path Bar --}}
    <div class="miller-path-bar">
        <div style="display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-database" style="color: #0284c7; font-size: 11px;"></i>
            <span class="miller-path-tag" wire:click="navigateTo('')">[{{ $status['bucket'] }}]</span>
            <span style="color: #cbd5e1;">/</span>

            @foreach($breadcrumbs as $b)
                <span class="miller-path-tag" wire:click="navigateTo('{{ $b['path'] }}')">{{ $b['label'] }}</span>
                <span style="color: #cbd5e1;">/</span>
            @endforeach

            @if($selectedPath)
                <span style="font-weight: 700; color: #0f172a;">{{ basename($selectedPath) }}</span>
            @endif
        </div>

        <div style="display: flex; align-items: center; gap: 14px; font-size: 11px; color: #64748b;">
            <span><strong>{{ count($items) }}</strong> objects in current folder</span>
            @if($selectedObject)
                <span>Selected: <strong>{{ $selectedObject['name'] }}</strong> ({{ $selectedObject['size_human'] }})</span>
            @endif
            <span>Node: <strong>{{ $status['endpoint'] }}</strong></span>
        </div>
    </div>

    {{-- New Folder Modal --}}
    @if($showNewFolderModal)
        <div class="nas-modal-overlay">
            <div class="nas-modal">
                <div class="nas-modal-head">
                    <h3><i class="fa-solid fa-folder-plus" style="margin-right: 8px; color: #38bdf8;"></i> Create Datastore Folder</h3>
                    <button type="button" wire:click="$set('showNewFolderModal', false)" style="border: none; background: transparent; color: #94a3b8; font-size: 1.2rem; cursor: pointer;">&times;</button>
                </div>
                <div class="nas-modal-body">
                    <p style="font-size: 0.82rem; color: #64748b; margin: 0;">
                        Target Directory: <code>{{ $currentPrefix ? ('/' . $currentPrefix) : '/ (Root)' }}</code>
                    </p>
                    <div class="m-field-group">
                        <label class="m-field-label">Folder Name</label>
                        <input type="text" class="m-field-input" placeholder="e.g. backup-archives" wire:model="newFolderName">
                        @error('newFolderName') <span style="color: #ef4444; font-size: 0.75rem;">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="nas-modal-foot">
                    <button type="button" class="m-btn m-btn-secondary" wire:click="$set('showNewFolderModal', false)">Cancel</button>
                    <button type="button" class="m-btn m-btn-primary" wire:click="createFolder">Create Folder</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Upload Modal --}}
    @if($showUploadModal)
        <div class="nas-modal-overlay">
            <div class="nas-modal">
                <div class="nas-modal-head">
                    <h3><i class="fa-solid fa-cloud-arrow-up" style="margin-right: 8px; color: #38bdf8;"></i> Upload Object to Datastore</h3>
                    <button type="button" wire:click="$set('showUploadModal', false)" style="border: none; background: transparent; color: #94a3b8; font-size: 1.2rem; cursor: pointer;">&times;</button>
                </div>
                <div class="nas-modal-body">
                    <p style="font-size: 0.82rem; color: #64748b; margin: 0;">
                        Target Folder: <code>{{ $currentPrefix ? ('/' . $currentPrefix) : '/ (Root)' }}</code>
                    </p>
                    <div style="border: 2px dashed #cbd5e1; padding: 22px; border-radius: 8px; text-align: center; background: #f8fafc;">
                        <input type="file" wire:model="uploadedFile" style="font-size: 0.82rem;">
                        <p style="margin: 8px 0 0 0; font-size: 0.72rem; color: #94a3b8;">Max single file upload: 50MB</p>
                    </div>
                    @error('uploadedFile') <span style="color: #ef4444; font-size: 0.75rem;">{{ $message }}</span> @enderror
                </div>
                <div class="nas-modal-foot">
                    <button type="button" class="m-btn m-btn-secondary" wire:click="$set('showUploadModal', false)">Cancel</button>
                    <button type="button" class="m-btn m-btn-primary" wire:click="submitUpload" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="submitUpload">Upload Object</span>
                        <span wire:loading wire:target="submitUpload">Uploading…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

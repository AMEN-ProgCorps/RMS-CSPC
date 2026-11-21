<?php
/**
 * Admin Console - Subsystem Patch Notes & Release Manager Volt Component
 * 
 * Provides an editor and release publisher for system-wide patch notes and update logs.
 */

use App\Services\PatchNoteService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Subsystem Patch Notes')] class extends Component {
    public string $viewMode = 'releases'; // 'releases' | 'editor'
    public string $markdownContent = '';
    public string $search = '';

    // Quick New Release Modal
    public bool $showNewReleaseModal = false;
    public string $newVersion = '';
    public string $newDate = '';
    public string $newSummary = '';
    public string $newAdded = '';
    public string $newFixed = '';
    public string $newChanged = '';

    // Status Messages
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_subsystems)) {
            $this->redirect(route('portal'));
            return;
        }

        $this->markdownContent = PatchNoteService::getRawContent();
        $this->newDate = now()->format('Y-m-d');
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = $mode;
        $this->clearMessages();
        if ($mode === 'editor') {
            $this->markdownContent = PatchNoteService::getRawContent();
        }
    }

    public function saveMarkdown(): void
    {
        $this->clearMessages();
        try {
            $ok = PatchNoteService::saveRawContent($this->markdownContent);
            if ($ok) {
                $this->successMessage = 'Patch notes markdown successfully saved!';
                
                // Audit log
                $adminLogsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                \DB::table($adminLogsTbl)->insert([
                    'changes' => 'Updated system patch notes markdown file',
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            } else {
                $this->errorMessage = 'Failed to write patch notes file to disk.';
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Error saving patch notes: ' . $e->getMessage();
        }
    }

    public function openNewReleaseModal(): void
    {
        $this->clearMessages();
        $latest = PatchNoteService::getLatestRelease();
        $this->newVersion = $latest ? $this->incrementMinorVersion($latest['clean_version'] ?? '2.4.2') : '2.4.3';
        $this->newDate = now()->format('Y-m-d');
        $this->newSummary = '';
        $this->newAdded = '';
        $this->newFixed = '';
        $this->newChanged = '';
        $this->showNewReleaseModal = true;
    }

    public function closeNewReleaseModal(): void
    {
        $this->showNewReleaseModal = false;
    }

    protected function incrementMinorVersion(string $ver): string
    {
        $parts = explode('.', $ver);
        if (count($parts) >= 3) {
            $parts[2] = (int)$parts[2] + 1;
            return implode('.', $parts);
        }
        return $ver . '.1';
    }

    public function publishRelease(): void
    {
        $this->clearMessages();
        $this->validate([
            'newVersion' => ['required', 'string', 'regex:/^v?\d+\.\d+\.\d+(?:-[a-zA-Z0-9\.]+)?$/'],
            'newDate' => ['required', 'date_format:Y-m-d'],
        ], [
            'newVersion.regex' => 'Version must follow semantic versioning (e.g. 2.4.3 or v2.4.3).',
        ]);

        $itemsByCategory = [];

        $parseLines = function(string $raw): array {
            $lines = explode("\n", str_replace("\r", "", $raw));
            $clean = [];
            foreach ($lines as $l) {
                $t = trim($l);
                if ($t === '') continue;
                $clean[] = ltrim($t, '-*• ');
            }
            return $clean;
        };

        $addedItems = $parseLines($this->newAdded);
        if (!empty($addedItems)) $itemsByCategory['Added'] = $addedItems;

        $fixedItems = $parseLines($this->newFixed);
        if (!empty($fixedItems)) $itemsByCategory['Fixed'] = $fixedItems;

        $changedItems = $parseLines($this->newChanged);
        if (!empty($changedItems)) $itemsByCategory['Changed'] = $changedItems;

        if (empty($itemsByCategory) && empty($this->newSummary)) {
            $this->errorMessage = 'Please enter at least one change item or a release summary.';
            return;
        }

        try {
            $ok = PatchNoteService::createRelease(
                $this->newVersion,
                $this->newDate,
                $itemsByCategory,
                $this->newSummary
            );

            if ($ok) {
                $this->markdownContent = PatchNoteService::getRawContent();
                $this->showNewReleaseModal = false;
                $this->successMessage = "Release {$this->newVersion} successfully published to patch notes!";

                // Audit log
                $adminLogsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
                \DB::table($adminLogsTbl)->insert([
                    'changes' => "Published patch notes release {$this->newVersion} ({$this->newDate})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now(),
                ]);
            } else {
                $this->errorMessage = 'Could not write new release to disk.';
            }
        } catch (\Throwable $e) {
            $this->errorMessage = 'Failed publishing release: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $releases = PatchNoteService::getReleases();

        if ($this->search !== '') {
            $q = strtolower(trim($this->search));
            $releases = array_filter($releases, function($r) use ($q) {
                if (str_contains(strtolower($r['version']), $q)) return true;
                if (str_contains(strtolower($r['date']), $q)) return true;
                if (!empty($r['summary']) && str_contains(strtolower($r['summary']), $q)) return true;
                foreach ($r['categories'] as $cat) {
                    if (str_contains(strtolower($cat['name']), $q)) return true;
                    foreach ($cat['items'] as $item) {
                        if (str_contains(strtolower($item['label']), $q) || str_contains(strtolower($item['text']), $q)) {
                            return true;
                        }
                    }
                }
                return false;
            });
        }

        return [
            'releases' => $releases,
            'latest' => PatchNoteService::getLatestRelease(),
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/subsystems.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .pne-container {
            padding: 24px 32px;
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .pne-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .pne-title-area h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 4px 0;
        }

        .pne-title-area p {
            font-size: 0.88rem;
            color: #64748b;
            margin: 0;
        }

        .pne-view-tabs {
            display: inline-flex;
            background: #e2e8f0;
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
        }

        .pne-tab-btn {
            border: none;
            background: transparent;
            padding: 8px 16px;
            border-radius: 7px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s ease;
        }

        .pne-tab-btn.active {
            background: #ffffff;
            color: #003699;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }

        .pne-actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .pne-search-box {
            position: relative;
            min-width: 280px;
            max-width: 400px;
            flex: 1;
        }

        .pne-search-box input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #0f172a;
            outline: none;
            background: #ffffff;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }

        .pne-search-box input:focus {
            border-color: #003699;
            box-shadow: 0 0 0 3px rgba(0, 54, 153, 0.08);
        }

        .pne-search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.85rem;
        }

        /* Release Cards Grid */
        .pne-releases-grid {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .pne-release-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            overflow: hidden;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .pne-release-header {
            background: #f8fafc;
            padding: 14px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pne-ver-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pne-ver-tag {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            font-family: monospace;
        }

        .pne-latest-badge {
            background: #10b981;
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 99px;
            letter-spacing: 0.05em;
        }

        .pne-date-tag {
            font-size: 0.82rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pne-release-body {
            padding: 18px 22px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .pne-release-summary {
            font-size: 0.88rem;
            color: #334155;
            line-height: 1.5;
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
            padding: 10px 14px;
            border-radius: 6px;
        }

        .pne-categories-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .pne-cat-block {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .pne-cat-header {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pne-cat-chip {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 9px;
            border-radius: 6px;
            letter-spacing: 0.04em;
        }

        .pne-cat-chip.added { background: #dcfce7; color: #15803d; }
        .pne-cat-chip.fixed { background: #fef3c7; color: #b45309; }
        .pne-cat-chip.changed { background: #e0f2fe; color: #0369a1; }
        .pne-cat-chip.security { background: #f3e8ff; color: #7e22ce; }
        .pne-cat-chip.deprecated { background: #fee2e2; color: #b91c1c; }
        .pne-cat-chip.removed { background: #f1f5f9; color: #475569; }
        .pne-cat-chip.info { background: #f1f5f9; color: #334155; }

        .pne-items-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .pne-item-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 0.85rem;
            color: #1e293b;
            line-height: 1.45;
        }

        .pne-item-dot {
            color: #94a3b8;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .pne-item-label {
            font-weight: 700;
            color: #0f172a;
        }

        /* Editor Section */
        .pne-editor-card {
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 580px;
        }

        .pne-editor-toolbar {
            background: #f8fafc;
            padding: 10px 18px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.82rem;
            color: #64748b;
        }

        .pne-editor-textarea {
            width: 100%;
            height: 520px;
            border: none;
            outline: none;
            padding: 18px 22px;
            font-family: 'JetBrains Mono', 'Fira Code', Menlo, monospace;
            font-size: 0.88rem;
            color: #0f172a;
            line-height: 1.6;
            resize: vertical;
            background: #ffffff;
            box-sizing: border-box;
        }

        .pne-editor-footer {
            background: #f8fafc;
            padding: 12px 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        /* Modal Overlay */
        .pne-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .pne-modal-card {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 620px;
            box-shadow: 0 25px 60px -15px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: pneModalScale 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes pneModalScale {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .pne-modal-header {
            padding: 16px 22px;
            background: #0f172a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .pne-modal-header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pne-modal-body {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-height: calc(85vh - 120px);
            overflow-y: auto;
        }

        .pne-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .pne-field label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .pne-field input, .pne-field textarea {
            padding: 9px 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.88rem;
            color: #0f172a;
            outline: none;
            background: #ffffff;
            box-sizing: border-box;
            font-family: inherit;
        }

        .pne-field input:focus, .pne-field textarea:focus {
            border-color: #003699;
            box-shadow: 0 0 0 3px rgba(0, 54, 153, 0.08);
        }

        .pne-field small {
            font-size: 0.75rem;
            color: #64748b;
        }

        .pne-modal-footer {
            padding: 14px 22px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
    </style>
@endpush

<div class="pne-container" x-data="{ searchQuery: @entangle('search') }">
    <!-- Header -->
    <div class="pne-header">
        <div class="pne-title-area">
            <h1>
                <i class="fa-solid fa-code-commit" style="color: #003699;"></i>
                Subsystem Patch Notes & Release Manager
            </h1>
            <p>Publish, edit, and organize system changelog notes, version updates, and bug fixes for the user console.</p>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <div class="pne-view-tabs">
                <button
                    type="button"
                    class="pne-tab-btn {{ $viewMode === 'releases' ? 'active' : '' }}"
                    wire:click="setViewMode('releases')"
                >
                    <i class="fa-solid fa-list-check"></i>
                    Releases View
                </button>
                <button
                    type="button"
                    class="pne-tab-btn {{ $viewMode === 'editor' ? 'active' : '' }}"
                    wire:click="setViewMode('editor')"
                >
                    <i class="fa-solid fa-pen-to-square"></i>
                    Markdown Editor
                </button>
            </div>

            <button
                type="button"
                class="btn-primary"
                wire:click="openNewReleaseModal"
                style="display: inline-flex; align-items: center; gap: 8px;"
            >
                <i class="fa-solid fa-plus"></i>
                Publish Release
            </button>
        </div>
    </div>

    <!-- Alerts -->
    @if($successMessage)
        <div class="alert alert-success" style="background: #ecfdf5; border: 1px solid #10b981; color: #065f46; padding: 12px 18px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="clearMessages" style="background: transparent; border: none; cursor: pointer; color: #065f46;">&times;</button>
        </div>
    @endif

    @if($errorMessage)
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 18px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>{{ $errorMessage }}</span>
            </div>
            <button type="button" wire:click="clearMessages" style="background: transparent; border: none; cursor: pointer; color: #991b1b;">&times;</button>
        </div>
    @endif

    @if($viewMode === 'releases')
        <!-- Search & Info Bar -->
        <div class="pne-actions-bar">
            <div class="pne-search-box">
                <i class="fa-solid fa-magnifying-glass pne-search-icon"></i>
                <input
                    type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search versions, features, or bug fixes..."
                >
            </div>

            <div style="font-size: 0.85rem; color: #64748b;">
                <span>Total Versions: <strong>{{ count($releases) }}</strong></span>
                @if($latest)
                    <span style="margin-left: 12px;">Active Release: <strong style="color: #10b981;">{{ $latest['version'] }}</strong></span>
                @endif
            </div>
        </div>

        <!-- Release Cards -->
        <div class="pne-releases-grid">
            @forelse($releases as $rel)
                <div class="pne-release-card">
                    <div class="pne-release-header">
                        <div class="pne-ver-group">
                            <span class="pne-ver-tag">{{ $rel['version'] }}</span>
                            @if($rel['is_latest'])
                                <span class="pne-latest-badge">Latest</span>
                            @endif
                        </div>
                        <div class="pne-date-tag">
                            <i class="fa-regular fa-calendar"></i>
                            <span>{{ $rel['date'] ?: 'Unreleased' }}</span>
                        </div>
                    </div>

                    <div class="pne-release-body">
                        @if(!empty($rel['summary']))
                            <div class="pne-release-summary">
                                {{ $rel['summary'] }}
                            </div>
                        @endif

                        <div class="pne-categories-list">
                            @foreach($rel['categories'] as $cat)
                                <div class="pne-cat-block">
                                    <div class="pne-cat-header">
                                        <span class="pne-cat-chip {{ $cat['type'] }}">
                                            @if($cat['type'] === 'added') <i class="fa-solid fa-plus"></i>
                                            @elseif($cat['type'] === 'fixed') <i class="fa-solid fa-wrench"></i>
                                            @elseif($cat['type'] === 'changed') <i class="fa-solid fa-arrows-rotate"></i>
                                            @elseif($cat['type'] === 'security') <i class="fa-solid fa-shield"></i>
                                            @endif
                                            {{ $cat['name'] }}
                                        </span>
                                    </div>
                                    <ul class="pne-items-list">
                                        @foreach($cat['items'] as $item)
                                            <li class="pne-item-row">
                                                <span class="pne-item-dot">&bull;</span>
                                                <div>
                                                    @if(!empty($item['label']))
                                                        <strong class="pne-item-label">{{ $item['label'] }}:</strong>
                                                    @endif
                                                    <span>{{ $item['text'] }}</span>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <div style="background: #ffffff; border-radius: 12px; border: 1.5px dashed #cbd5e1; padding: 48px; text-align: center; color: #64748b;">
                    <i class="fa-solid fa-code-commit" style="font-size: 2rem; color: #94a3b8; margin-bottom: 12px;"></i>
                    <p style="margin: 0; font-weight: 600;">No release notes match your search.</p>
                </div>
            @endforelse
        </div>
    @else
        <!-- Full Markdown Editor Mode -->
        <div class="pne-editor-card">
            <div class="pne-editor-toolbar">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fa-brands fa-markdown" style="font-size: 1.2rem; color: #003699;"></i>
                    <span>Editing <code>resources/patch-notes/README.md</code></span>
                </div>
                <div>
                    <span>Supports Keep-a-Changelog GFM format (<code>## [vX.Y.Z] - YYYY-MM-DD</code>)</span>
                </div>
            </div>

            <textarea
                class="pne-editor-textarea"
                wire:model="markdownContent"
                placeholder="Write release notes in Markdown..."
                spellcheck="false"
            ></textarea>

            <div class="pne-editor-footer">
                <span style="font-size: 0.8rem; color: #64748b;">
                    Changes saved here directly update the patch notes markdown and the live update logs widget.
                </span>
                <button
                    type="button"
                    class="btn-primary"
                    wire:click="saveMarkdown"
                    style="display: inline-flex; align-items: center; gap: 8px;"
                >
                    <i class="fa-solid fa-floppy-disk"></i>
                    Save Patch Notes
                </button>
            </div>
        </div>
    @endif

    <!-- Quick New Release Modal -->
    @if($showNewReleaseModal)
        <div class="pne-modal-overlay" wire:click.self="closeNewReleaseModal">
            <div class="pne-modal-card">
                <div class="pne-modal-header">
                    <h3>
                        <i class="fa-solid fa-rocket" style="color: #38bdf8;"></i>
                        Publish New Release
                    </h3>
                    <button type="button" wire:click="closeNewReleaseModal" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; font-size: 1.2rem;">&times;</button>
                </div>

                <div class="pne-modal-body">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="pne-field">
                            <label>Version Number *</label>
                            <input type="text" wire:model="newVersion" placeholder="e.g. 2.4.3 or v2.4.3">
                            <small>Semantic versioning format</small>
                        </div>
                        <div class="pne-field">
                            <label>Release Date *</label>
                            <input type="date" wire:model="newDate">
                        </div>
                    </div>

                    <div class="pne-field">
                        <label>Release Summary (Optional)</label>
                        <input type="text" wire:model="newSummary" placeholder="Brief summary of this release milestone...">
                    </div>

                    <div class="pne-field">
                        <label style="color: #15803d;"><i class="fa-solid fa-plus"></i> Added (One item per line)</label>
                        <textarea rows="3" wire:model="newAdded" placeholder="**Feature Name**: Description of the new feature&#10;**Another Feature**: Details..."></textarea>
                    </div>

                    <div class="pne-field">
                        <label style="color: #b45309;"><i class="fa-solid fa-wrench"></i> Fixed (One item per line)</label>
                        <textarea rows="3" wire:model="newFixed" placeholder="**Bug Fix**: Description of the resolved bug..."></textarea>
                    </div>

                    <div class="pne-field">
                        <label style="color: #0369a1;"><i class="fa-solid fa-arrows-rotate"></i> Changed (One item per line)</label>
                        <textarea rows="3" wire:model="newChanged" placeholder="**Improvement**: Description of changes or updates..."></textarea>
                    </div>
                </div>

                <div class="pne-modal-footer">
                    <button type="button" class="btn-secondary" wire:click="closeNewReleaseModal">Cancel</button>
                    <button type="button" class="btn-primary" wire:click="publishRelease">
                        <i class="fa-solid fa-check"></i>
                        Publish to Patch Notes
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Received Documents - Document Tracking System')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all'; // 'all', 'pending', 'appraised', 'dismissed'
    public string $layoutMode = 'table'; // 'table' or 'box'
    public int $perPage = 15;

    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    // View Details Modal
    public bool $showDetailModal = false;
    public ?object $selectedDoc = null;

    // Import / Pull from DTS Modal
    public bool $showImportModal = false;
    public string $importSearch = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function openDetailModal(int $id): void
    {
        $this->clearMessages();
        $this->selectedDoc = DB::table('rdp_received_documents')
            ->where('id', $id)
            ->where('source_subsystem', 'DTS')
            ->first();

        if ($this->selectedDoc) {
            $this->showDetailModal = true;
        }
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedDoc = null;
    }

    public function dismissDocument(int $id): void
    {
        $this->clearMessages();
        DB::table('rdp_received_documents')
            ->where('id', $id)
            ->where('source_subsystem', 'DTS')
            ->update([
                'status' => 'dismissed',
                'updated_at' => now(),
            ]);

        $this->successMessage = 'Document marked as dismissed.';
        if ($this->showDetailModal && $this->selectedDoc && $this->selectedDoc->id === $id) {
            $this->selectedDoc->status = 'dismissed';
        }
    }

    public function restoreDocument(int $id): void
    {
        $this->clearMessages();
        DB::table('rdp_received_documents')
            ->where('id', $id)
            ->where('source_subsystem', 'DTS')
            ->update([
                'status' => 'pending',
                'updated_at' => now(),
            ]);

        $this->successMessage = 'Document restored to pending appraisal.';
        if ($this->showDetailModal && $this->selectedDoc && $this->selectedDoc->id === $id) {
            $this->selectedDoc->status = 'pending';
        }
    }

    public function openImportModal(): void
    {
        $this->clearMessages();
        $this->importSearch = '';
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
        $this->importSearch = '';
    }

    public function importDtsTransaction(string $controlNumber): void
    {
        $this->clearMessages();
        $controlNumber = trim($controlNumber);

        if (empty($controlNumber)) {
            $this->errorMessage = 'Please provide a valid DTS Control Number.';
            return;
        }

        // Check if already in received documents
        $existing = DB::table('rdp_received_documents')
            ->where('source_subsystem', 'DTS')
            ->where('document_code', $controlNumber)
            ->first();

        if ($existing) {
            $this->errorMessage = "DTS Transaction '{$controlNumber}' has already been received into RDP.";
            return;
        }

        // Find transaction in dts_transaction_details
        $dtd = DB::table('dts_transaction_details')
            ->where('control_number', $controlNumber)
            ->first();

        if (!$dtd) {
            $this->errorMessage = "DTS Transaction '{$controlNumber}' was not found in the Tracking System.";
            return;
        }

        // Find attached document if exists
        $sysDoc = DB::table('sys_document_data')
            ->where('document_id', $dtd->control_number)
            ->orWhere('document_id', 'like', 'DTS%' . $dtd->control_number . '%')
            ->orWhere('document_name', 'like', '%' . $dtd->control_number . '%')
            ->first();

        $user = Auth::user();

        DB::table('rdp_received_documents')->insert([
            'source_subsystem'    => 'DTS',
            'document_code'       => $dtd->control_number,
            'document_title'      => $dtd->subject ?? 'Untitled Document',
            'description'         => 'Imported from DTS Transaction #' . $dtd->control_number,
            'origin_office'       => $dtd->originated_from ?? null,
            'target_office'       => null,
            'date_received'       => $dtd->created_at ? Carbon::parse($dtd->created_at)->toDateString() : now()->toDateString(),
            'file_path'           => $sysDoc?->document_path ?? null,
            'file_name'           => $sysDoc?->document_name ?? null,
            'document_id_handler' => $sysDoc?->document_id ?? null,
            'status'              => 'pending',
            'sent_by_user'        => $user?->id,
            'metadata'            => json_encode([
                'flow_code'    => $dtd->transaction_flow ?? null,
                'originator'   => $dtd->originated_from ?? null,
                'raw_subject'  => $dtd->subject ?? null,
                'imported_by'  => $user?->username ?? 'System',
            ]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->successMessage = "Successfully imported DTS Transaction '{$controlNumber}' into Received Documents!";
        $this->closeImportModal();
    }

    public function with(): array
    {
        $query = DB::table('rdp_received_documents')
            ->where('source_subsystem', 'DTS');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if (!empty($this->search)) {
            $s = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('document_code', 'ilike', $s)
                  ->orWhere('document_title', 'ilike', $s)
                  ->orWhere('description', 'ilike', $s)
                  ->orWhere('origin_office', 'ilike', $s);
            });
        }

        $documents = $query->orderBy('created_at', 'desc')->paginate($this->perPage);

        // Counters
        $totalAll = DB::table('rdp_received_documents')->where('source_subsystem', 'DTS')->count();
        $totalPending = DB::table('rdp_received_documents')->where('source_subsystem', 'DTS')->where('status', 'pending')->count();
        $totalAppraised = DB::table('rdp_received_documents')->where('source_subsystem', 'DTS')->where('status', 'appraised')->count();
        $totalDismissed = DB::table('rdp_received_documents')->where('source_subsystem', 'DTS')->where('status', 'dismissed')->count();

        // Sample / recent DTS transactions for quick import modal
        $availableDts = collect();
        if ($this->showImportModal) {
            $iq = DB::table('dts_transaction_details')
                ->select('control_number', 'subject', 'originated_from', 'created_at');

            if (!empty($this->importSearch)) {
                $is = '%' . trim($this->importSearch) . '%';
                $iq->where(function($q) use ($is) {
                    $q->where('control_number', 'ilike', $is)
                      ->orWhere('subject', 'ilike', $is)
                      ->orWhere('originated_from', 'ilike', $is);
                });
            }

            $availableDts = $iq->orderBy('created_at', 'desc')->limit(12)->get();
        }

        return [
            'documents'      => $documents,
            'totalAll'       => $totalAll,
            'totalPending'   => $totalPending,
            'totalAppraised' => $totalAppraised,
            'totalDismissed' => $totalDismissed,
            'availableDts'   => $availableDts,
        ];
    }
};
?>

<div class="received-docs-container">
    <style>
        .received-docs-container {
            padding: 24px;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1e293b;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-icon {
            width: 46px;
            height: 46px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbeafe;
        }

        .header-title-box h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .subsystem-badge {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            background: #dbeafe;
            color: #1d4ed8;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }

        .header-title-box p {
            font-size: 13px;
            color: #64748b;
            margin: 3px 0 0 0;
        }

        .action-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-tabs {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            gap: 4px;
            border: 1px solid #e2e8f0;
        }

        .filter-tab-btn {
            border: none;
            background: transparent;
            padding: 7px 14px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .badge-counter {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 10px;
            background: #e2e8f0;
            color: #475569;
        }

        .filter-tab-btn.active .badge-counter {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding: 8px 14px 8px 34px;
            font-size: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            width: 220px;
            outline: none;
            background: #ffffff;
        }

        .search-box input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
        }

        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }

        .btn-toggle-view {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-toggle-view:hover {
            background: #f8fafc;
        }

        .btn-import {
            padding: 8px 14px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background 0.2s;
        }

        .btn-import:hover {
            background: #1d4ed8;
        }

        /* Flash Messages */
        .alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Table Card */
        .table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .data-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .data-table tr:hover td {
            background: #f8fafc;
        }

        .code-pill {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            font-size: 12px;
            color: #1e40af;
            background: #eff6ff;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #dbeafe;
            display: inline-block;
        }

        .doc-title-cell {
            font-weight: 600;
            color: #0f172a;
            max-width: 320px;
        }

        .doc-desc-sub {
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
            margin-top: 3px;
            line-height: 1.3;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-appraised {
            background: #dcfce7;
            color: #166534;
        }

        .status-dismissed {
            background: #f1f5f9;
            color: #64748b;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-pending .status-dot { background: #d97706; }
        .status-appraised .status-dot { background: #16a34a; }
        .status-dismissed .status-dot { background: #94a3b8; }

        .action-btns {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-appraise {
            background: #0284c7;
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.2s;
        }

        .btn-appraise:hover {
            background: #0369a1;
        }

        .btn-view-doc {
            background: #f1f5f9;
            color: #334155;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-view-doc:hover {
            background: #e2e8f0;
        }

        /* Grid View */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .doc-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .doc-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .card-desc {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        .card-meta {
            font-size: 12px;
            color: #475569;
            background: #f8fafc;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .card-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        /* Empty State */
        .empty-state {
            padding: 48px 24px;
            text-align: center;
            color: #64748b;
        }

        .empty-state svg {
            margin-bottom: 12px;
            color: #94a3b8;
        }

        /* Modal Styles */
        .custom-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(2px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }

        .custom-modal-box {
            background: #ffffff;
            border-radius: 14px;
            max-width: 620px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: modalFadeIn 0.2s ease-out;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
        }

        .btn-close-modal {
            background: transparent;
            border: none;
            color: #64748b;
            font-size: 18px;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
        }

        .btn-close-modal:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .modal-body {
            padding: 22px;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            margin-bottom: 12px;
            font-size: 13px;
        }

        .detail-label {
            font-weight: 600;
            color: #64748b;
        }

        .detail-value {
            color: #0f172a;
        }

        .modal-footer {
            padding: 14px 22px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            border-radius: 0 0 14px 14px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
    </style>

    <!-- Header -->
    <div class="header-section">
        <div class="header-title-box">
            <div class="header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
            </div>
            <div>
                <h1>
                    Received Documents
                    <span class="subsystem-badge">Document Tracking System (DTS)</span>
                </h1>
                <p>Incoming documents and transactions routed from Document Tracking System for appraisal and archiving.</p>
            </div>
        </div>

        <div class="action-bar">
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <button type="button" wire:click="$set('statusFilter', 'all')" class="filter-tab-btn {{ $statusFilter === 'all' ? 'active' : '' }}">
                    All <span class="badge-counter">{{ $totalAll }}</span>
                </button>
                <button type="button" wire:click="$set('statusFilter', 'pending')" class="filter-tab-btn {{ $statusFilter === 'pending' ? 'active' : '' }}">
                    Pending <span class="badge-counter">{{ $totalPending }}</span>
                </button>
                <button type="button" wire:click="$set('statusFilter', 'appraised')" class="filter-tab-btn {{ $statusFilter === 'appraised' ? 'active' : '' }}">
                    Appraised <span class="badge-counter">{{ $totalAppraised }}</span>
                </button>
                <button type="button" wire:click="$set('statusFilter', 'dismissed')" class="filter-tab-btn {{ $statusFilter === 'dismissed' ? 'active' : '' }}">
                    Dismissed <span class="badge-counter">{{ $totalDismissed }}</span>
                </button>
            </div>

            <!-- Search -->
            <div class="search-box">
                <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search control no, title...">
            </div>

            <!-- Layout Toggle -->
            <button type="button" wire:click="toggleLayout" class="btn-toggle-view" title="Toggle Layout View">
                @if($layoutMode === 'table')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Grid
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Table
                @endif
            </button>

            <!-- Import / Pull from DTS Button -->
            <button type="button" wire:click="openImportModal" class="btn-import">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Import from DTS
            </button>
        </div>
    </div>

    <!-- Alerts -->
    @if($successMessage)
        <div class="alert-success">
            <span>{{ $successMessage }}</span>
            <button type="button" wire:click="clearMessages" style="background:none;border:none;cursor:pointer;color:#065f46;">&times;</button>
        </div>
    @endif

    @if($errorMessage)
        <div class="alert-error">
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="clearMessages" style="background:none;border:none;cursor:pointer;color:#991b1b;">&times;</button>
        </div>
    @endif

    <!-- Content: Table or Grid -->
    @if($documents->isEmpty())
        <div class="table-card empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            <h3 style="font-size:16px;font-weight:600;color:#334155;margin:0 0 6px 0;">No documents found</h3>
            <p style="font-size:13px;margin:0 0 16px 0;">No incoming documents match the current filter or search criteria.</p>
            <button type="button" wire:click="openImportModal" class="btn-import" style="margin:0 auto;display:inline-flex;">
                Pull from Document Tracking System
            </button>
        </div>
    @elseif($layoutMode === 'table')
        <div class="table-card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Control Number</th>
                        <th>Document Title & Details</th>
                        <th>Origin Office</th>
                        <th>Date Received</th>
                        <th>Status</th>
                        <th>File Attachment</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $doc)
                        <tr>
                            <td>
                                <span class="code-pill">{{ $doc->document_code }}</span>
                            </td>
                            <td>
                                <div class="doc-title-cell">{{ $doc->document_title }}</div>
                                @if(!empty($doc->description))
                                    <div class="doc-desc-sub">{{ Str::limit($doc->description, 60) }}</div>
                                @endif
                            </td>
                            <td>
                                <span style="font-weight: 500; color: #334155;">{{ $doc->origin_office ?: 'N/A' }}</span>
                            </td>
                            <td>
                                <span style="color: #64748b;">{{ $doc->date_received ? Carbon::parse($doc->date_received)->format('M d, Y') : '—' }}</span>
                            </td>
                            <td>
                                @if($doc->status === 'pending')
                                    <span class="status-badge status-pending">
                                        <span class="status-dot"></span> Pending Appraisal
                                    </span>
                                @elseif($doc->status === 'appraised')
                                    <span class="status-badge status-appraised">
                                        <span class="status-dot"></span> Appraised
                                    </span>
                                @else
                                    <span class="status-badge status-dismissed">
                                        <span class="status-dot"></span> Dismissed
                                    </span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($doc->file_name) || !empty($doc->file_path))
                                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#2563eb;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                                        {{ Str::limit($doc->file_name ?: 'Attachment', 20) }}
                                    </span>
                                @else
                                    <span style="color: #94a3b8; font-size: 12px;">None</span>
                                @endif
                            </td>
                            <td style="text-align: right;">
                                <div class="action-btns" style="justify-content: flex-end;">
                                    @if($doc->status !== 'appraised')
                                        <a href="{{ route('rdp.add-records.inventory-and-appraisal', [
                                            'prefill_intake_id' => $doc->id,
                                            'prefill_source'    => 'DTS',
                                            'prefill_code'      => $doc->document_code,
                                            'prefill_title'     => $doc->document_title,
                                            'prefill_desc'      => $doc->description,
                                            'prefill_date'      => $doc->date_received,
                                            'prefill_doc_id'    => $doc->document_id_handler,
                                        ]) }}" class="btn-appraise" title="Pre-fill and appraise into RDP">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                            Appraise
                                        </a>
                                    @endif
                                    <button type="button" wire:click="openDetailModal({{ $doc->id }})" class="btn-view-doc">
                                        View
                                    </button>
                                    @if($doc->status === 'pending')
                                        <button type="button" wire:click="dismissDocument({{ $doc->id }})" class="btn-view-doc" title="Mark as dismissed">
                                            Dismiss
                                        </button>
                                    @elseif($doc->status === 'dismissed')
                                        <button type="button" wire:click="restoreDocument({{ $doc->id }})" class="btn-view-doc" title="Restore to pending">
                                            Restore
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <!-- Grid Mode -->
        <div class="cards-grid">
            @foreach($documents as $doc)
                <div class="doc-card">
                    <div>
                        <div class="card-top">
                            <span class="code-pill">{{ $doc->document_code }}</span>
                            @if($doc->status === 'pending')
                                <span class="status-badge status-pending">
                                    <span class="status-dot"></span> Pending
                                </span>
                            @elseif($doc->status === 'appraised')
                                <span class="status-badge status-appraised">
                                    <span class="status-dot"></span> Appraised
                                </span>
                            @else
                                <span class="status-badge status-dismissed">
                                    <span class="status-dot"></span> Dismissed
                                </span>
                            @endif
                        </div>
                        <div class="card-title">{{ $doc->document_title }}</div>
                        <div class="card-desc">{{ Str::limit($doc->description ?: 'No additional description provided.', 100) }}</div>

                        <div class="card-meta">
                            <div><strong>Origin:</strong> {{ $doc->origin_office ?: 'Unspecified' }}</div>
                            <div><strong>Received:</strong> {{ $doc->date_received ? Carbon::parse($doc->date_received)->format('M d, Y') : '—' }}</div>
                            @if(!empty($doc->file_name))
                                <div style="color: #2563eb;"><strong>File:</strong> {{ Str::limit($doc->file_name, 25) }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="card-actions">
                        <button type="button" wire:click="openDetailModal({{ $doc->id }})" class="btn-view-doc">
                            Details
                        </button>
                        @if($doc->status !== 'appraised')
                            <a href="{{ route('rdp.add-records.inventory-and-appraisal', [
                                'prefill_intake_id' => $doc->id,
                                'prefill_source'    => 'DTS',
                                'prefill_code'      => $doc->document_code,
                                'prefill_title'     => $doc->document_title,
                                'prefill_desc'      => $doc->description,
                                'prefill_date'      => $doc->date_received,
                                'prefill_doc_id'    => $doc->document_id_handler,
                            ]) }}" class="btn-appraise">
                                Appraise into RDP &rarr;
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div style="margin-top: 18px;">
        {{ $documents->links() }}
    </div>

    <!-- View Details Modal -->
    @if($showDetailModal && $selectedDoc)
        <div class="custom-modal-backdrop" wire:click.self="closeDetailModal">
            <div class="custom-modal-box">
                <div class="modal-header">
                    <h3>Document Details (DTS)</h3>
                    <button type="button" wire:click="closeDetailModal" class="btn-close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="detail-row">
                        <div class="detail-label">Control Number:</div>
                        <div class="detail-value"><span class="code-pill">{{ $selectedDoc->document_code }}</span></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Title / Subject:</div>
                        <div class="detail-value" style="font-weight: 600;">{{ $selectedDoc->document_title }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Description:</div>
                        <div class="detail-value">{{ $selectedDoc->description ?: 'None' }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Origin Office:</div>
                        <div class="detail-value">{{ $selectedDoc->origin_office ?: 'N/A' }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Date Received:</div>
                        <div class="detail-value">{{ $selectedDoc->date_received ? Carbon::parse($selectedDoc->date_received)->format('F d, Y') : 'N/A' }}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Intake Status:</div>
                        <div class="detail-value">
                            @if($selectedDoc->status === 'pending')
                                <span class="status-badge status-pending">Pending Appraisal</span>
                            @elseif($selectedDoc->status === 'appraised')
                                <span class="status-badge status-appraised">Appraised into RDP</span>
                            @else
                                <span class="status-badge status-dismissed">Dismissed</span>
                            @endif
                        </div>
                    </div>
                    @if($selectedDoc->file_name)
                        <div class="detail-row">
                            <div class="detail-label">Attachment:</div>
                            <div class="detail-value" style="color: #2563eb;">
                                {{ $selectedDoc->file_name }}
                            </div>
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    @if($selectedDoc->status !== 'appraised')
                        <a href="{{ route('rdp.add-records.inventory-and-appraisal', [
                            'prefill_intake_id' => $selectedDoc->id,
                            'prefill_source'    => 'DTS',
                            'prefill_code'      => $selectedDoc->document_code,
                            'prefill_title'     => $selectedDoc->document_title,
                            'prefill_desc'      => $selectedDoc->description,
                            'prefill_date'      => $selectedDoc->date_received,
                            'prefill_doc_id'    => $selectedDoc->document_id_handler,
                        ]) }}" class="btn-appraise" style="padding: 8px 16px;">
                            Appraise / Add to RDP
                        </a>
                    @endif
                    <button type="button" wire:click="closeDetailModal" class="btn-view-doc" style="padding: 8px 16px;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Import from DTS Modal -->
    @if($showImportModal)
        <div class="custom-modal-backdrop" wire:click.self="closeImportModal">
            <div class="custom-modal-box" style="max-width: 680px;">
                <div class="modal-header">
                    <div>
                        <h3 style="margin:0;">Import Document from DTS</h3>
                        <p style="font-size:12px;color:#64748b;margin:3px 0 0 0;">Select or search active tracking transactions to receive into RDP.</p>
                    </div>
                    <button type="button" wire:click="closeImportModal" class="btn-close-modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="margin-bottom: 16px;">
                        <input type="text" wire:model.live.debounce.300ms="importSearch" 
                               placeholder="Search control number or subject in DTS..." 
                               style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none;">
                    </div>

                    @if($availableDts->isEmpty())
                        <div style="padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                            No matching DTS transactions found to import.
                        </div>
                    @else
                        <div style="max-height: 340px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                            @foreach($availableDts as $dtsItem)
                                <div style="padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; background: #fafafa;">
                                    <div>
                                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                            <span class="code-pill">{{ $dtsItem->control_number }}</span>
                                            <span style="font-size: 11px; color: #64748b;">{{ $dtsItem->originated_from ?: 'No origin' }}</span>
                                        </div>
                                        <div style="font-size: 13px; font-weight: 600; color: #0f172a;">{{ $dtsItem->subject ?: 'No subject' }}</div>
                                    </div>
                                    <button type="button" wire:click="importDtsTransaction('{{ $dtsItem->control_number }}')" class="btn-appraise" style="padding: 6px 12px; cursor: pointer;">
                                        Receive
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeImportModal" class="btn-view-doc" style="padding: 8px 16px;">
                        Done
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

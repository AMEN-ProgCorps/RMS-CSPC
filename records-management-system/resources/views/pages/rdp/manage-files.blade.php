<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - Manage Files')] class extends Component {
    public string $search = '';
    public string $selectedOffice = '';
    public string $sortBy = 'date_added';
    public string $sortDirection = 'desc';

    public function with(): array
    {
        $offices = DB::table('office')
            ->select('office_code', 'office_name')
            ->where('is_active', true)
            ->orderBy('office_name', 'asc')
            ->get();

        $query = DB::table('document_data')
            ->leftJoin('office', 'document_data.user_office', '=', 'office.office_code')
            ->leftJoin('account_details', 'document_data.uploaded_by', '=', 'account_details.account_id')
            ->select(
                'document_data.*',
                'office.office_name',
                'account_details.first_name',
                'account_details.last_name'
            );

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('document_data.document_id', 'like', '%' . $this->search . '%')
                  ->orWhere('document_data.document_name', 'like', '%' . $this->search . '%')
                  ->orWhere('document_data.document_path', 'like', '%' . $this->search . '%');
            });
        }

        if (!empty($this->selectedOffice)) {
            $query->where('document_data.user_office', $this->selectedOffice);
        }

        if ($this->sortBy === 'office') {
            $query->orderBy('office.office_name', $this->sortDirection);
        } else {
            $query->orderBy('document_data.' . $this->sortBy, $this->sortDirection);
        }

        $files = $query->paginate(15);

        return [
            'files' => $files,
            'offices' => $offices,
        ];
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }
};
?>

<div class="rms-container">
    <style>
        .files-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        .files-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        .files-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .files-search {
            flex: 1;
            min-width: 260px;
            position: relative;
        }
        .files-search input {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .files-search input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .files-search svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            fill: #94a3b8;
        }
        .filter-select {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background-color: #fff;
            outline: none;
            cursor: pointer;
        }
        .files-table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .files-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .files-table th {
            background: #f8fafc;
            padding: 14px 16px;
            font-weight: 600;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            user-select: none;
        }
        .files-table th:hover {
            background: #f1f5f9;
        }
        .files-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .files-table tr:last-child td {
            border-bottom: none;
        }
        .files-table tr:hover td {
            background-color: #f8fafc;
        }
        .office-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            background-color: #eff6ff;
            color: #1e40af;
            border: 1px solid #dbeafe;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            background: #eff6ff;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-action:hover {
            background: #dbeafe;
        }
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #64748b;
        }
        .sort-icon {
            font-size: 11px;
            margin-left: 4px;
            color: #94a3b8;
        }
    </style>

    <div class="files-header">
        <h2>Manage Files</h2>
    </div>

    <div class="files-toolbar">
        <div class="files-search">
            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by Document ID or Name...">
        </div>

        <select class="filter-select" wire:model.live="selectedOffice">
            <option value="">All Offices</option>
            @foreach($offices as $office)
                <option value="{{ $office->office_code }}">{{ $office->office_name }} ({{ $office->office_code }})</option>
            @endforeach
        </select>
    </div>

    <div class="files-table-card">
        <table class="files-table">
            <thead>
                <tr>
                    <th wire:click="sortByColumn('document_id')">
                        Document ID
                        <span class="sort-icon">{{ $sortBy === 'document_id' ? ($sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                    </th>
                    <th wire:click="sortByColumn('document_name')">
                        Document Name
                        <span class="sort-icon">{{ $sortBy === 'document_name' ? ($sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                    </th>
                    <th wire:click="sortByColumn('office')">
                        Office Location
                        <span class="sort-icon">{{ $sortBy === 'office' ? ($sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                    </th>
                    <th>Uploaded By</th>
                    <th wire:click="sortByColumn('date_added')">
                        Date Added
                        <span class="sort-icon">{{ $sortBy === 'date_added' ? ($sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $file)
                    <tr>
                        <td style="font-weight: 600; font-family: monospace;">{{ $file->document_id }}</td>
                        <td>{{ $file->document_name }}</td>
                        <td>
                            @if($file->office_name)
                                <span class="office-badge">{{ $file->office_name }}</span>
                            @else
                                <span style="color: #94a3b8; font-style: italic;">Unassigned</span>
                            @endif
                        </td>
                        <td>
                            @if($file->first_name || $file->last_name)
                                {{ $file->first_name }} {{ $file->last_name }}
                            @else
                                <span style="color: #94a3b8; font-style: italic;">System</span>
                            @endif
                        </td>
                        <td>{{ \Carbon\Carbon::parse($file->date_added)->format('M d, Y h:i A') }}</td>
                        <td>
                            <button type="button" class="btn-action">
                                View Details
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                No document files found matching the criteria.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 16px;">
        {{ $files->links() }}
    </div>
</div>

<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - List Internal Transactions')] class extends Component {
    use WithPagination;

    public string $selectedPriority = 'all';
    public string $selectedStatus = 'all';
    public int $perPage = 10;
    public string $searchQuery = '';

    public function updatingSearchQuery()
    {
        $this->resetPage();
    }

    public function updatingSelectedPriority()
    {
        $this->resetPage();
    }

    public function updatingSelectedStatus()
    {
        $this->resetPage();
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    public function getTransactionsProperty()
    {
        $userId = auth()->id();
        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dtd.created_by', $userId)
            ->where('dt.trans_type', 'internal');

        if ($this->selectedPriority !== 'all') {
            $query->where('dtd.classification', $this->selectedPriority);
        }

        if ($this->selectedStatus !== 'all') {
            $query->where('dt.status', $this->selectedStatus);
        }

        if (!empty($this->searchQuery)) {
            $query->where(function($q) {
                $q->where('dtd.control_number', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dt.qr_code', 'like', '%' . $this->searchQuery . '%');
            });
        }

        $list = $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dtd.control_number',
            'dtd.subject',
            'dtd.classification',
            'dtd.date_created',
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->paginate($this->perPage);

        // Map remarks and received by from latest logs
        $list->getCollection()->transform(function ($t) {
            $latestLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('account_details as ad', 'ad.account_id', '=', 'log.performed_by')
                ->where('log.transaction_id', $t->transaction_id)
                ->orderBy('log.id', 'desc')
                ->select('log.notes', 'ad.first_name', 'ad.last_name')
                ->first();
            $t->remarks = $latestLog ? $latestLog->notes : '-';
            $t->received_by = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';
            return $t;
        });

        return $list;
    }
};
?>
@push('styles')
    @vite('resources/css/dts/list_transaction.css')
@endpush

<div class="rms-container">
    <div class="rms-header">
        <h2>Internal Transactions</h2>
    </div>

    <div class="rms-toolbar">
        <div class="rms-toolbar-top">
            <div class="rms-filters">
                <select class="rms-select" wire:model.live="selectedPriority">
                    <option value="all">All Priority</option>
                    <option value="simple">Simple</option>
                    <option value="complex">Complex</option>
                    <option value="highly_technical">Highly Technical</option>
                </select>
                <select class="rms-select" wire:model.live="selectedStatus">
                    <option value="all">All Status</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                    <option value="revision">Revision</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="drafted">Drafted</option>
                </select>
            </div>
            <button type="button" class="rms-btn-print" onclick="window.print()">
                <svg class="btn-icon" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 9h6v2a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-2zm7-5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg> Print
            </button>
        </div>
        <div class="rms-toolbar-bottom">
            <div class="rms-entries">
                Show 
                <select class="rms-select" wire:model.live="perPage">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select> 
                Entries
            </div>
            <div class="rms-actions">
                <div class="rms-search-wrapper">
                    <input type="text" class="rms-search-input" placeholder="Search..." wire:model.live="searchQuery">
                </div>
            </div>
        </div>
    </div>

    <div class="rms-table-responsive">
        <table class="rms-table">
            <thead>
                <tr>
                    <th style="width: 60px;">Item No.</th>
                    <th>Control Number</th>
                    <th>Barcode</th>
                    <th>Source</th>
                    <th>Subject</th>
                    <th>Type of Document</th>
                    <th>Date Created</th>
                    <th>Current Location</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Remarks</th>
                    <th>Received by</th>
                    <th style="width: 60px;">View</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->transactions as $index => $t)
                    <tr>
                        <td style="text-align: center;">{{ $this->transactions->firstItem() + $index }}</td>
                        <td>{{ $t->control_number }}</td>
                        <td>{{ $t->qr_code }}</td>
                        <td>{{ $t->originated_office_name }}</td>
                        <td>{{ $t->subject }}</td>
                        <td>{{ $t->document_name ?? ucfirst($t->classification ?: 'internal') }}</td>
                        <td>{{ \Carbon\Carbon::parse($t->date_created)->format('Y-m-d H:i') }}</td>
                        <td>{{ $t->current_office_name }}</td>
                        <td style="text-align: center;">
                            <span class="status-badge status-{{ $t->status }}">
                                {{ $t->status }}
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <span class="priority-badge priority-{{ $t->classification }}">
                                {{ $t->classification ?: 'normal' }}
                            </span>
                        </td>
                        <td>{{ $t->remarks }}</td>
                        <td>{{ $t->received_by }}</td>
                        <td style="text-align: center;">
                            <a href="{{ route('dts.receive', ['id' => $t->transaction_id]) }}" class="rms-select" style="text-decoration: none; display: inline-block;">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="rms-no-data">No transactions found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- AJAX Pagination Control -->
    <div class="rms-pagination-wrap" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; font-size: 0.85rem; color: #6c757d;">
        <div>
            Showing {{ $this->transactions->firstItem() ?? 0 }} to {{ $this->transactions->lastItem() ?? 0 }} of {{ $this->transactions->total() }} entries
        </div>
        <div class="rms-pagination-buttons" style="display: flex; gap: 8px;">
            @if ($this->transactions->onFirstPage())
                <button type="button" class="rms-select" style="cursor: not-allowed; opacity: 0.5;" disabled>Previous</button>
            @else
                <button type="button" class="rms-select" wire:click="previousPage">Previous</button>
            @endif

            @if ($this->transactions->hasMorePages())
                <button type="button" class="rms-select" wire:click="nextPage">Next</button>
            @else
                <button type="button" class="rms-select" style="cursor: not-allowed; opacity: 0.5;" disabled>Next</button>
            @endif
        </div>
    </div>
</div>
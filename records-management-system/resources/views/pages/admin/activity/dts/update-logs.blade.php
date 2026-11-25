<?php
/**
 * Admin Console - DTS Sequence & Station Update Logs Volt Component
 *
 * Audits station-level sequence state updates, timestamps, durations,
 * and routing anomalies across Document Tracking System transactions.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - DTS Update Logs')] class extends Component {
    use WithPagination;

    /** @var string Real-time search query */
    public string $search = '';

    /** @var string Selected transaction type filter */
    public string $typeFilter = '';

    /** @var string Selected sequence step state filter */
    public string $stateFilter = '';

    /**
     * Initializes component state, authorization gates, and default configurations.
     * @return void
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_dts_admin && !$perms->can_dts_modify_docflow)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /**
     * Reset pagination page on search update.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination page on transaction type filter update.
     */
    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Reset pagination page on step state filter update.
     */
    public function updatingStateFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Clear all filters.
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = '';
        $this->stateFilter = '';
        $this->resetPage();
    }

    /**
     * Fetch sequence & station update logs and pass them to the Blade template.
     */
    public function with(): array
    {
        $accountTbl = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $hasPendingCol = Schema::hasColumn('dts_transactions', 'pending_working_hours_dispatch');
        $hasTotalTimeRecvCol = Schema::hasColumn('dts_sequence_list', 'total_time_received');

        $selects = [
            'seq.control_id',
            'seq.sequence_ranking',
            'seq.office_code as raw_office_code',
            'seq.date_in',
            'seq.date_out',
            'seq.action_needed',
            'seq.note',
            'seq.total_time_completed',
            'dt.transaction_id',
            'dt.trans_type',
            'dt.status as tx_status',
            'dt.sequence as tx_sequence',
            'dt.current_office as tx_current_office',
            'dtd.control_number',
            'dtd.originated_from',
            'dtd.date_created',
            'st_off.office_name as station_office_name',
            'orig_off.office_name as origin_office_name',
            'r_acc.username as receiver_username',
            DB::raw("TRIM(CONCAT(COALESCE(r_ad.first_name, ''), ' ', COALESCE(r_ad.last_name, ''))) as receiver_name"),
            'f_acc.username as forwarder_username',
            DB::raw("TRIM(CONCAT(COALESCE(f_ad.first_name, ''), ' ', COALESCE(f_ad.last_name, ''))) as forwarder_name"),
            $hasPendingCol
                ? 'dt.pending_working_hours_dispatch'
                : DB::raw('false as pending_working_hours_dispatch'),
            $hasTotalTimeRecvCol
                ? 'seq.total_time_received'
                : DB::raw('NULL as total_time_received'),
        ];

        $query = DB::table('dts_sequence_list as seq')
            ->join('dts_transaction_flow as flow', 'flow.id', '=', 'seq.control_id')
            ->join('dts_transaction_details as dtd', 'dtd.transaction_flow', '=', 'flow.flow_code')
            ->join('dts_transactions as dt', 'dt.transaction_id', '=', 'dtd.id')
            ->leftJoin("{$officeTbl} as st_off", 'st_off.office_code', '=', 'seq.office_code')
            ->leftJoin("{$officeTbl} as orig_off", 'orig_off.office_code', '=', 'dtd.originated_from')
            ->leftJoin("{$accountTbl} as r_acc", 'r_acc.id', '=', 'seq.account_received')
            ->leftJoin("{$accDetailsTbl} as r_ad", 'r_ad.account_id', '=', 'r_acc.id')
            ->leftJoin("{$accountTbl} as f_acc", 'f_acc.id', '=', 'seq.account_forwarded')
            ->leftJoin("{$accDetailsTbl} as f_ad", 'f_ad.account_id', '=', 'f_acc.id')
            ->select($selects);

        if ($this->stateFilter === 'completed') {
            $query->whereNotNull('seq.date_in')->whereNotNull('seq.date_out');
        } elseif ($this->stateFilter === 'active') {
            $query->whereNotNull('seq.date_in')->whereNull('seq.date_out');
        } elseif ($this->stateFilter === 'in_transit') {
            $query->whereColumn('seq.sequence_ranking', '=', 'dt.sequence')
                  ->whereNull('seq.date_in');
        } elseif ($this->stateFilter === 'anomalies') {
            $query->where(function ($q) use ($hasPendingCol) {
                $q->where(function ($sub) {
                    $sub->whereColumn('seq.sequence_ranking', '<', 'dt.sequence')
                        ->where(function ($missing) {
                            $missing->whereNull('seq.date_in')
                                    ->orWhereNull('seq.date_out');
                        });
                });
                if ($hasPendingCol) {
                    $q->orWhere('dt.pending_working_hours_dispatch', true);
                }
            });
        } else {
            $query->where(function ($q) {
                $q->whereNotNull('seq.date_in')
                  ->orWhereNotNull('seq.date_out')
                  ->orWhereColumn('seq.sequence_ranking', '<=', 'dt.sequence');
            });
        }

        if ($this->typeFilter !== '') {
            $query->where('dt.trans_type', $this->typeFilter);
        }

        if ($this->search !== '') {
            $searchVal = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($searchVal) {
                $q->where('dtd.control_number', 'ilike', $searchVal)
                  ->orWhere('dt.transaction_id', 'ilike', $searchVal)
                  ->orWhere('seq.office_code', 'ilike', $searchVal)
                  ->orWhere('dtd.originated_from', 'ilike', $searchVal)
                  ->orWhere('seq.action_needed', 'ilike', $searchVal)
                  ->orWhere('seq.note', 'ilike', $searchVal)
                  ->orWhere('r_acc.username', 'ilike', $searchVal)
                  ->orWhere('f_acc.username', 'ilike', $searchVal)
                  ->orWhere('r_ad.first_name', 'ilike', $searchVal)
                  ->orWhere('r_ad.last_name', 'ilike', $searchVal)
                  ->orWhere('f_ad.first_name', 'ilike', $searchVal)
                  ->orWhere('f_ad.last_name', 'ilike', $searchVal);
            });
        }

        $logs = $query
            ->orderByRaw('COALESCE(seq.date_out, seq.date_in, dtd.date_created) DESC')
            ->orderBy('seq.sequence_ranking', 'desc')
            ->paginate(15);

        return [
            'logs' => $logs,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/activity_logs.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .dts-upd-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .dts-upd-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        .dts-upd-tag {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            text-transform: uppercase;
        }
        .dts-upd-duration {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 2px 6px;
            margin-bottom: 3px;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <!-- Header -->
    <div class="page-header">
        <h1>Document Tracking - Sequence &amp; Station Update Logs</h1>
        <p>Debug station-level sequence state updates, receive/forward timestamps, durations, and routing anomalies across all DTS transactions.</p>
    </div>

    <!-- Controls Panel -->
    <div class="logs-controls-card">
        <div class="search-filter-group">
            <!-- Search Input -->
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text"
                       class="search-input"
                       placeholder="Search control no., trans ID, office, user, action, or note..."
                       wire:model.live.debounce.300ms="search">
            </div>

            <!-- Transaction Type Filter -->
            <select class="filter-select" wire:model.live="typeFilter">
                <option value="">All Types</option>
                <option value="internal">Internal</option>
                <option value="external">External</option>
                <option value="others">Application Letters</option>
                <option value="memorandom">Issuances</option>
            </select>

            <!-- Step State Filter -->
            <select class="filter-select" wire:model.live="stateFilter">
                <option value="">All Active &amp; Passed Steps</option>
                <option value="completed">Completed Steps</option>
                <option value="active">Active (At Station)</option>
                <option value="in_transit">In Transit (Pending Receive)</option>
                <option value="anomalies">Anomalies / After-Hours Pending</option>
            </select>

            <!-- Clear Button -->
            @if($search !== '' || $typeFilter !== '' || $stateFilter !== '')
                <button type="button" class="btn-clear-filters" wire:click="clearFilters">
                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                </button>
            @endif
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="logs-table-card">
        <div class="table-responsive">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th style="width: 18%">Transaction</th>
                        <th style="width: 12%">Step / Seq</th>
                        <th style="width: 16%">Station Office</th>
                        <th style="width: 15%">Received</th>
                        <th style="width: 15%">Forwarded</th>
                        <th style="width: 10%">Durations</th>
                        <th style="width: 14%">Action &amp; Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        @php
                            $seqRank = (int) $log->sequence_ranking;
                            $txSeq = (int) ($log->tx_sequence ?? 1);
                            $txStatus = strtolower($log->tx_status ?? 'ongoing');
                            $isAnomaly = ($seqRank < $txSeq) && (is_null($log->date_in) || is_null($log->date_out));

                            $isOriginPlaceholder = $log->raw_office_code === 'ORIGIN';
                            $resolvedOfficeCode = $isOriginPlaceholder ? ($log->originated_from ?: 'ORIGIN') : $log->raw_office_code;
                            $resolvedOfficeName = $isOriginPlaceholder ? ($log->origin_office_name ?: $resolvedOfficeCode) : ($log->station_office_name ?: $resolvedOfficeCode);

                            $typeLabel = match(strtolower($log->trans_type ?? '')) {
                                'internal' => 'Internal',
                                'external' => 'External',
                                'others' => 'App Letter',
                                'memorandom' => 'Issuance',
                                default => strtoupper($log->trans_type ?? 'N/A'),
                            };

                            if ($isAnomaly) {
                                $stepBadge = ['bg' => '#fee2e2', 'fg' => '#b91c1c', 'label' => 'Anomaly (Missing Timestamp)'];
                            } elseif (!is_null($log->date_in) && !is_null($log->date_out)) {
                                $stepBadge = ['bg' => '#dcfce7', 'fg' => '#15803d', 'label' => 'Completed'];
                            } elseif (!is_null($log->date_in) && is_null($log->date_out)) {
                                $stepBadge = ['bg' => '#fef3c7', 'fg' => '#b45309', 'label' => 'At Station'];
                            } elseif ($seqRank === $txSeq && is_null($log->date_in)) {
                                $stepBadge = ['bg' => '#e0f2fe', 'fg' => '#0369a1', 'label' => 'In Transit'];
                            } else {
                                $stepBadge = ['bg' => '#f1f5f9', 'fg' => '#475569', 'label' => 'Pending'];
                            }

                            $txPill = match($txStatus) {
                                'completed' => ['bg' => '#dcfce7', 'fg' => '#15803d'],
                                'revision'  => ['bg' => '#fef3c7', 'fg' => '#b45309'],
                                'cancelled' => ['bg' => '#fee2e2', 'fg' => '#b91c1c'],
                                default     => ['bg' => '#e0f2fe', 'fg' => '#0369a1'],
                            };

                            $recvName = !empty(trim($log->receiver_name ?? '')) ? $log->receiver_name : ($log->receiver_username ?: null);
                            $fwdName = !empty(trim($log->forwarder_name ?? '')) ? $log->forwarder_name : ($log->forwarder_username ?: null);
                        @endphp
                        <tr wire:key="dts-seq-{{ $log->control_id }}-{{ $log->sequence_ranking }}">
                            <td>
                                <div style="font-weight: 700; color: #003699;">{{ $log->control_number ?? $log->transaction_id }}</div>
                                <div class="dts-upd-sub" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <span class="dts-upd-tag">{{ $typeLabel }}</span>
                                    <span class="dts-upd-pill" style="background: {{ $txPill['bg'] }}; color: {{ $txPill['fg'] }};">{{ $txStatus }}</span>
                                </div>
                                <div class="dts-upd-sub" style="font-family: monospace; font-size: 10.5px;">{{ $log->transaction_id }}</div>
                                @if(!empty($log->pending_working_hours_dispatch))
                                    <div style="margin-top: 3px;">
                                        <span class="dts-upd-pill" style="background: #fef3c7; color: #92400e;">After-Hours Pending</span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #1e293b;">Step {{ $seqRank }}</div>
                                <div class="dts-upd-sub">Tx Seq: {{ $txSeq }}</div>
                                <div style="margin-top: 4px;">
                                    <span class="dts-upd-pill" style="background: {{ $stepBadge['bg'] }}; color: {{ $stepBadge['fg'] }};">
                                        {{ $stepBadge['label'] }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                    <span style="font-weight: 700; color: #0f172a;">{{ $resolvedOfficeCode }}</span>
                                    @if($isOriginPlaceholder)
                                        <span class="dts-upd-tag">ORIGIN</span>
                                    @endif
                                </div>
                                <div class="dts-upd-sub">{{ $resolvedOfficeName }}</div>
                            </td>
                            <td>
                                @if($log->date_in)
                                    <div class="log-timestamp">
                                        {{ \Carbon\Carbon::parse($log->date_in)->format('M d, Y h:i A') }}
                                        <span class="time-ago">{{ \Carbon\Carbon::parse($log->date_in)->diffForHumans() }}</span>
                                    </div>
                                @else
                                    <span style="color: #94a3b8; font-size: 12px;">Pending</span>
                                @endif
                                @if($recvName)
                                    <div class="dts-upd-sub"><i class="fa-solid fa-user-check" style="color: #10b981;"></i> {{ $recvName }}</div>
                                @endif
                            </td>
                            <td>
                                @if($log->date_out)
                                    <div class="log-timestamp">
                                        {{ \Carbon\Carbon::parse($log->date_out)->format('M d, Y h:i A') }}
                                        <span class="time-ago">{{ \Carbon\Carbon::parse($log->date_out)->diffForHumans() }}</span>
                                    </div>
                                @else
                                    <span style="color: #94a3b8; font-size: 12px;">{{ $log->date_in ? 'Pending' : '—' }}</span>
                                @endif
                                @if($fwdName)
                                    <div class="dts-upd-sub"><i class="fa-solid fa-paper-plane" style="color: #3b82f6;"></i> {{ $fwdName }}</div>
                                @endif
                            </td>
                            <td>
                                @if(!empty($log->total_time_received) && $log->total_time_received !== '-')
                                    <div class="dts-upd-duration">Recv: {{ $log->total_time_received }}</div>
                                @endif
                                @if(!empty($log->total_time_completed) && $log->total_time_completed !== '-')
                                    <div class="dts-upd-duration">Proc: {{ $log->total_time_completed }}</div>
                                @endif
                                @if((empty($log->total_time_received) || $log->total_time_received === '-') && (empty($log->total_time_completed) || $log->total_time_completed === '-'))
                                    <span style="color: #94a3b8; font-size: 12px;">—</span>
                                @endif
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b; font-size: 12px;">{{ $log->action_needed ?: '—' }}</div>
                                @if(!empty($log->note))
                                    <div class="dts-upd-sub" style="white-space: normal; word-break: break-word;">{{ $log->note }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fa-solid fa-code-branch"></i>
                                    <h3>No Sequence Update Logs Found</h3>
                                    <p>No DTS station sequence updates match your current search or filter criteria.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
            <div class="pagination-wrapper">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>

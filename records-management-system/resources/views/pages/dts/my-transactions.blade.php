<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('My Transactions - Document Tracking System')] class extends Component {
    use WithPagination;

    #[On('dts-transaction-updated')]
    #[On('refresh-transactions')]
    public function refreshTransactionsList(): void
    {
        $this->resetPage();
    }

    public string $searchQuery = '';
    public int $perPage = 10;
    public string $layoutMode = 'table';
    public array $expandedHubTransactions = [];

    public function toggleExpandHub(string $controlNumber): void
    {
        if (in_array($controlNumber, $this->expandedHubTransactions)) {
            $this->expandedHubTransactions = array_values(array_diff($this->expandedHubTransactions, [$controlNumber]));
        } else {
            $this->expandedHubTransactions[] = $controlNumber;
        }
    }

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_access_dts) {
            abort(403, 'Unauthorized access to DTS.');
        }
    }

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function updatingSearchQuery(): void
    {
        $this->resetPage();
    }

    public function getMyTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->where('dtd.is_active', 1)
            ->where(function($q) use ($userOfficeCode) {
                $q->where('dtd.originated_from', $userOfficeCode)
                  ->orWhere('dtd.created_by', auth()->id());
            })
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        // Hide child transactions from top-level list so they are collapsed under their parent
        if (empty($this->searchQuery)) {
            $query->where(function($q) {
                $q->whereRaw("dtd.control_number NOT LIKE '%-1'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-2'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-3'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-4'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-5'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-6'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-7'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-8'")
                  ->whereRaw("dtd.control_number NOT LIKE '%-9'");
            });
        }

        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $searchVal . '%')
                  ->orWhere('dt.qr_code', 'like', '%' . $searchVal . '%');
            });
        }

        $list = $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dt.trans_type',
            'dtd.control_number',
            'req.requestor_name',
            'dtd.subject',
            'dtd.date_created',
            'dtd.originated_from',
            'dtd.transaction_flow as dtd_transaction_flow',
            'flow.id as flow_control_id',
            DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->paginate($this->perPage);

        $list->getCollection()->transform(function ($t) {
            // Resolve actual Document Type Name (avoiding raw REF-CUSTOM-XX codes and 'Flow for ...' titles)
            $docType = $t->doc_type_name ?? '';
            if (empty($docType) || preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $docType, $mMatches) || str_starts_with($docType, 'Flow for ')) {
                $refId = null;
                if (preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $docType, $mMatches)) {
                    $refId = $mMatches[1];
                } elseif (!empty($t->dtd_transaction_flow) && preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $t->dtd_transaction_flow, $mMatches)) {
                    $refId = $mMatches[1];
                }
                
                if ($refId) {
                    $realName = DB::table('dts_transaction_flow')->where('id', $refId)->value('flow_name');
                    if (!empty($realName) && !str_starts_with($realName, 'Flow for ')) {
                        $docType = $realName;
                    }
                }

                if (empty($docType) || str_starts_with($docType, 'Flow for ') || str_starts_with($docType, 'REF-')) {
                    if (!empty($t->dtd_transaction_flow)) {
                        $flowObj = DB::table('dts_transaction_flow')->where('flow_code', $t->dtd_transaction_flow)->first();
                        if ($flowObj) {
                            if (!empty($flowObj->flow_name) && !str_starts_with($flowObj->flow_name, 'Flow for ') && !str_starts_with($flowObj->flow_name, 'REF-')) {
                                $docType = $flowObj->flow_name;
                            } elseif (!empty($flowObj->referenced_flow) && preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $flowObj->referenced_flow, $mMatches)) {
                                $realName = DB::table('dts_transaction_flow')->where('id', $mMatches[1])->value('flow_name');
                                if (!empty($realName) && !str_starts_with($realName, 'Flow for ')) {
                                    $docType = $realName;
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($docType) && !str_starts_with($docType, 'Flow for ') && !str_starts_with($docType, 'REF-')) {
                $t->doc_type_name = $docType;
            } else {
                $t->doc_type_name = !empty($t->classification) ? ucfirst($t->classification) : ucfirst($t->trans_type);
            }
            $flowControlId = $t->flow_control_id ?? null;
            $flowCode = $t->dtd_transaction_flow ?? ($t->transaction_flow ?? null);

            if (!$flowControlId && !empty($flowCode)) {
                $flowControlId = DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->value('id');
            }

            $originOfficeCode = $t->originated_from;
            $originOfficeName = $t->originated_office_name ?? 'Originated Office';

            $clusterHeadCode = $originOfficeCode;
            $clusterHeadName = $originOfficeName;
            if (!empty($originOfficeCode)) {
                $offRec = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $originOfficeCode)->first();
                if ($offRec && $offRec->cluster) {
                    $cluster = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_cluster') ? 'sys_cluster' : 'cluster')->where('cluster_code', $offRec->cluster)->first();
                    if ($cluster && $cluster->cluster_head) {
                        $clusterHeadCode = $cluster->cluster_head;
                        $headOffice = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $cluster->cluster_head)->first();
                        if ($headOffice) {
                            $clusterHeadName = $headOffice->office_name;
                        }
                    }
                }
            }

            $steps = collect();
            if ($flowControlId) {
                $steps = DB::table('dts_sequence_list as seq')
                    ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'office.office_code', '=', 'seq.office_code')
                    ->where('seq.control_id', $flowControlId)
                    ->select('seq.*', 'office.office_name')
                    ->orderBy('seq.sequence_ranking', 'asc')
                    ->get()
                    ->map(function ($step) use ($originOfficeCode, $originOfficeName, $clusterHeadCode, $clusterHeadName, $t) {
                        $isHub = ($step->office_code === '[HUB]');
                        $step->is_hub = $isHub;

                        if ($step->office_code === 'ORIGIN') {
                            $step->office_code = $originOfficeCode;
                            $step->office_name = $originOfficeName;
                        } elseif ($step->office_code === '[H]') {
                            $step->office_code = $clusterHeadCode;
                            $step->office_name = $clusterHeadName;
                        } elseif ($isHub) {
                            $cfRecord = DB::table('dts_copy_filled_transaction')->where('control_num', $t->control_number)->first();
                            $hubOffices = $cfRecord ? DB::table('dts_copy_filled_to_office')->where('control_id', $cfRecord->assign_offices_id)->pluck('office_code')->toArray() : [];
                            
                            $receivedCount = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                                ->where('transaction_id', $t->transaction_id)
                                ->whereIn('office_code', $hubOffices)
                                ->whereNotNull('date_in')
                                ->count();

                            $forwardedCount = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                                ->where('transaction_id', $t->transaction_id)
                                ->whereIn('office_code', $hubOffices)
                                ->whereNotNull('date_out')
                                ->count();

                            $step->hub_offices = $hubOffices;
                            $step->hub_received_count = $receivedCount;
                            $step->hub_forwarded_count = $forwardedCount;
                            $step->hub_total = count($hubOffices);
                            $step->office_name = 'Office Hub [Multi-Receiving] (' . (count($hubOffices) > 0 ? implode(', ', $hubOffices) : 'All Units') . ')';

                            if ($receivedCount > 0) {
                                $step->date_in = now();
                            }
                            if ($forwardedCount > 0 && $forwardedCount >= count($hubOffices)) {
                                $step->date_out = now();
                            }
                        } elseif (empty($step->office_name)) {
                            $off = DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office')->where('office_code', $step->office_code)->first();
                            $step->office_name = $off ? $off->office_name : $step->office_code;
                        }

                        $step->is_active_step = (
                            ($step->office_code === $t->current_office || ($isHub && $t->current_office === '[HUB]'))
                            && $step->sequence_ranking == $t->sequence
                            && in_array($t->status, ['ongoing', 'revision'])
                            && (!is_null($step->date_in) || $isHub)
                            && is_null($step->date_out)
                        );

                        if (!empty($step->date_in) && empty($step->total_time_completed) && ($step->date_out || $t->status === 'completed')) {
                            $dateIn = \Carbon\Carbon::parse($step->date_in);
                            $dateOut = $step->date_out ? \Carbon\Carbon::parse($step->date_out) : now();
                            $diff = $dateIn->diff($dateOut);
                            $parts = [];
                            if ($diff->d > 0) $parts[] = $diff->d . ' ' . \Illuminate\Support\Str::plural('day', $diff->d);
                            if ($diff->h > 0) $parts[] = $diff->h . ' ' . \Illuminate\Support\Str::plural('hour', $diff->h);
                            if ($diff->i > 0) $parts[] = $diff->i . ' ' . \Illuminate\Support\Str::plural('minute', $diff->i);
                            if (empty($parts)) $parts[] = 'less than a minute';
                            $step->total_time_completed = implode(' ', $parts);
                        }

                        return $step;
                    });
            }

            // Fallback to transaction logs if sequence_list has no steps
            if ($steps->isEmpty()) {
                $logs = DB::table((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . ' as log')
                    ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'office.office_code', '=', 'log.office_code')
                    ->where('log.transaction_id', $t->transaction_id)
                    ->select('log.*', 'office.office_name')
                    ->orderBy('log.id', 'asc')
                    ->get();

                if ($logs->isNotEmpty()) {
                    $steps = $logs->map(function ($logStep, $idx) use ($t) {
                        $step = new \stdClass();
                        $step->sequence_ranking = $logStep->sequence ?: ($idx + 1);
                        $step->office_code = $logStep->office_code;
                        $step->office_name = $logStep->office_name ?: $logStep->office_code;
                        $step->date_in = $logStep->date_in;
                        $step->date_out = $logStep->date_out;
                        $step->action_needed = $logStep->action_needed ?? ($logStep->type === 'forwarded' ? 'Forwarded' : 'Received');
                        $step->total_time_completed = null;
                        $step->is_active_step = ($logStep->office_code === $t->current_office && is_null($logStep->date_out));
                        return $step;
                    });
                }
            }

            $t->timeline_path = $steps;

            // Load child transactions if any exist for this root control number
            $childBranches = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office', 'current_office.office_code', '=', 'dt.current_office')
                ->where('dtd.control_number', 'like', $t->control_number . '-%')
                ->where('dtd.is_active', 1)
                ->whereNotIn('dt.status', ['cancelled'])
                ->select(
                    'dt.transaction_id',
                    'dt.status',
                    'dt.sequence',
                    'dt.qr_code',
                    'dt.current_office',
                    'dt.trans_type',
                    'dtd.control_number',
                    'dtd.subject',
                    'dtd.date_created',
                    'current_office.office_name as current_office_name'
                )
                ->orderBy('dtd.control_number', 'asc')
                ->get();

            $childBranches->transform(function ($child) {
                $childLogs = DB::table((\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs') . ' as log')
                    ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office'), 'office.office_code', '=', 'log.office_code')
                    ->where('log.transaction_id', $child->transaction_id)
                    ->select('log.*', 'office.office_name')
                    ->orderBy('log.id', 'asc')
                    ->get();

                $childSteps = $childLogs->map(function ($cLog, $cIdx) use ($child) {
                    $cStep = new \stdClass();
                    $cStep->sequence_ranking = $cIdx + 1;
                    $cStep->office_code = $cLog->office_code;
                    $cStep->office_name = $cLog->office_name ?: $cLog->office_code;
                    $cStep->date_in = $cLog->date_in;
                    $cStep->date_out = $cLog->date_out;
                    $cStep->is_hub = false;
                    $cStep->is_active_step = ($cLog->office_code === $child->current_office && is_null($cLog->date_out));
                    return $cStep;
                });

                $child->timeline_path = $childSteps;
                return $child;
            });

            $t->child_branches = $childBranches;

            return $t;
        });

        return $list;
    }
};
?>

<div wire:poll.5s.keep-alive>
    <link rel="stylesheet" href="{{ asset('css/dts/internal.css') }}">
    <style>
        .office-location-pill {
            background-color: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
    </style>

    {{-- Header Banner --}}
    <div style="background: #ffffff; padding: 20px 24px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <h1 style="font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
            <span>📋 My Transactions</span>
        </h1>
        <p style="color: #64748b; font-size: 0.875rem; margin: 4px 0 0 0;">
            All active transactions created by or originating from your office, showing real-time current office locations.
        </p>
    </div>

    {{-- Controls --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div style="position: relative; flex: 1; max-width: 400px;">
            <input type="text" wire:model.live.debounce.300ms="searchQuery" placeholder="Search control no., subject, or QR code..." style="width: 100%; padding: 10px 14px 10px 38px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </div>
        <button wire:click="toggleLayout" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; color: #475569; font-weight: 500;">
            {{ $layoutMode === 'table' ? '📦 Grid View' : '📋 Table View' }}
        </button>
    </div>

    @php $myList = $this->myTransactions; @endphp

    @if($myList->count() === 0)
        <div style="background: #ffffff; border-radius: 12px; padding: 48px; text-align: center; color: #64748b; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
            <div style="font-size: 2.5rem; margin-bottom: 12px;">📂</div>
            <h3 style="font-size: 1.1rem; font-weight: 600; color: #334155; margin-bottom: 4px;">No Active My Transactions</h3>
            <p style="font-size: 0.875rem; margin: 0;">You have not created any active transactions currently in progress.</p>
        </div>
    @else
        @if($layoutMode === 'table')
            <div style="background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600;">
                            <th style="padding: 14px 16px;">Control No.</th>
                            <th style="padding: 14px 16px;">Document Type</th>
                            <th style="padding: 14px 16px;">Subject</th>
                            <th style="padding: 14px 16px;">Timeline</th>
                            <th style="padding: 14px 16px;">Date Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($myList as $t)
                            @php
                                $hasBranches = !empty($t->child_branches) && $t->child_branches->isNotEmpty();
                                $isExpanded = in_array($t->control_number, $expandedHubTransactions);
                            @endphp
                            <tr style="border-bottom: 1px solid #f1f5f9; {{ $hasBranches && $isExpanded ? 'background: #f8fafc;' : '' }}">
                                <td style="padding: 14px 16px; font-weight: 700; color: #0f172a;">
                                    <div>{{ $t->control_number }}</div>
                                    @if($hasBranches)
                                        <button type="button" wire:click="toggleExpandHub('{{ $t->control_number }}')" style="margin-top: 6px; display: inline-flex; align-items: center; gap: 5px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.2s ease;">
                                            <i class="fa-solid fa-bolt" style="color: #2563eb;"></i> {{ count($t->child_branches) + 1 }} Hub Units
                                            <i class="fa-solid {{ $isExpanded ? 'fa-chevron-up' : 'fa-chevron-down' }}" style="font-size: 9px; margin-left: 2px;"></i>
                                        </button>
                                    @endif
                                </td>
                                <td style="padding: 14px 16px; color: #334155;">
                                    <span style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                        {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}
                                    </span>
                                </td>
                                <td style="padding: 14px 16px; color: #1e293b; max-width: 280px;">
                                    <div style="font-weight: 600;">{{ Str::limit($t->subject ?: 'No subject', 45) }}</div>
                                </td>
                                <td style="padding: 10px 12px; min-width: 220px; max-width: 320px;">
                                    <div style="display: flex; align-items: center; justify-content: flex-start; gap: 0; position: relative;">
                                         @forelse ($t->timeline_path as $index => $step)
                                            @php
                                                $isHub = !empty($step->is_hub);
                                                $isReceived = !is_null($step->date_in) || $t->status === 'completed';
                                                $isForwarded = !is_null($step->date_out) || $t->status === 'completed';

                                                $isCurrentOffice = ($step->office_code === $t->current_office || ($isHub && $t->current_office === '[HUB]')) 
                                                    && $step->sequence_ranking == $t->sequence 
                                                    && $t->status !== 'completed';

                                                $isInTransitToThisOffice = $step->sequence_ranking == $t->sequence 
                                                    && is_null($step->date_in) 
                                                    && !$isHub
                                                    && $t->status !== 'completed';

                                                if ($isHub) {
                                                    if ($step->hub_forwarded_count > 0 && $step->hub_forwarded_count >= ($step->hub_total ?: 1)) {
                                                        $dotColor = '#10b981';
                                                        $labelColor = '#10b981';
                                                    } elseif ($step->hub_received_count > 0) {
                                                        $dotColor = '#2563eb';
                                                        $labelColor = '#2563eb';
                                                    } else {
                                                        $dotColor = '#3b82f6';
                                                        $labelColor = '#3b82f6';
                                                    }
                                                } else {
                                                    $dotColor = $isReceived ? '#10b981' : '#dc2626';
                                                    $labelColor = $isReceived ? '#10b981' : '#dc2626';
                                                }

                                                $lineColor = $isForwarded ? '#10b981' : ($isHub && $step->hub_received_count > 0 ? '#93c5fd' : '#cbd5e1');

                                                $tooltipTitle = $isHub 
                                                    ? ("Step " . ($index + 1) . ": " . ($step->office_name ?? '[HUB]') . " — " . ($step->hub_received_count ?? 0) . " of " . ($step->hub_total ?? 0) . " Received (" . ($isForwarded ? 'Forwarded' : ($isReceived ? 'Holding/Active' : 'Pending')) . ")")
                                                    : ("Step " . ($index + 1) . ": " . ($step->office_name ?? $step->office_code) . " (" . ($isReceived ? ($isForwarded ? 'Forwarded' : 'Received/Holder') : 'Pending') . ")");
                                            @endphp

                                            <div class="dts-timeline-node-wrapper" style="position: relative; display: inline-flex; flex-direction: column; align-items: center; margin: 0;">
                                                <div class="dts-timeline-node-dot" style="width: 26px; height: 26px; border-radius: 50%; background: {{ $dotColor }}; color: #ffffff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 2; cursor: pointer; {{ $isCurrentOffice ? 'box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.3); border: 2px solid #ffffff;' : ($isInTransitToThisOffice ? 'box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.35);' : '') }}" title="{{ $tooltipTitle }}">
                                                    @if ($isHub)
                                                        @if ($isForwarded && $step->hub_forwarded_count >= ($step->hub_total ?: 1))
                                                            <i class="fa-solid fa-check" style="font-size: 10px;"></i>
                                                        @else
                                                            <i class="fa-solid fa-bolt" style="font-size: 10px;"></i>
                                                        @endif
                                                    @elseif ($isReceived)
                                                        <i class="fa-solid fa-check" style="font-size: 10px;"></i>
                                                    @else
                                                        {{ $index + 1 }}
                                                    @endif
                                                </div>

                                                <span style="margin-top: 4px; font-size: 10px; font-weight: 700; color: {{ $labelColor }}; font-family: 'Inter', sans-serif; white-space: nowrap;">
                                                    @if($isHub)
                                                        ⚡ [HUB]@if(!empty($step->hub_total))<span style="font-size: 8.5px; opacity: 0.85; margin-left: 1px;">({{ $step->hub_received_count }}/{{ $step->hub_total }})</span>@endif
                                                    @else
                                                        {{ $step->office_code }}
                                                    @endif
                                                </span>
                                            </div>

                                            @if (!$loop->last)
                                                <div style="flex: 1; height: 4px; background: {{ $lineColor }}; min-width: 16px; margin: 0 -2px 14px -2px; border-radius: 2px; z-index: 1; transition: all 0.3s ease;"></div>
                                            @endif
                                        @empty
                                            <span style="color: #94a3b8; font-size: 11px; font-style: italic;">No path data</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td style="padding: 14px 16px; color: #64748b; font-size: 0.8rem;">
                                    {{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y g:i A') }}
                                </td>
                            </tr>

                            {{-- Expanded Hub Sub-Rows --}}
                            @if($hasBranches && $isExpanded)
                                {{-- Primary Branch Sub-Row --}}
                                <tr style="background: #f8fafc; border-bottom: 1px dashed #e2e8f0;">
                                    <td style="padding: 10px 16px 10px 32px; font-size: 0.8rem; font-weight: 600; color: #334155;">
                                        <span style="color: #64748b; margin-right: 4px;">↳ Branch #1 (Primary):</span>
                                        <span style="color: #0f172a; font-weight: 700;">{{ $t->control_number }}</span>
                                    </td>
                                    <td style="padding: 10px 16px; font-size: 0.8rem; color: #475569;">
                                        <span class="office-location-pill" style="font-size: 11px; padding: 2px 8px;">🏢 {{ $t->current_office_name ?: $t->current_office }}</span>
                                    </td>
                                    <td style="padding: 10px 16px; font-size: 0.8rem; color: #64748b;" colspan="3">
                                        <span style="color: #059669; font-weight: 600;">Status:</span> {{ ucfirst($t->status) }}
                                    </td>
                                </tr>

                                {{-- Child Branches Sub-Rows --}}
                                @foreach($t->child_branches as $cIdx => $child)
                                    <tr style="background: #f8fafc; border-bottom: 1px dashed #e2e8f0;">
                                        <td style="padding: 10px 16px 10px 32px; font-size: 0.8rem; font-weight: 600; color: #334155;">
                                            <span style="color: #64748b; margin-right: 4px;">↳ Branch #{{ $cIdx + 2 }}:</span>
                                            <span style="color: #2563eb; font-weight: 700;">{{ $child->control_number }}</span>
                                        </td>
                                        <td style="padding: 10px 16px; font-size: 0.8rem; color: #475569;">
                                            <span class="office-location-pill" style="font-size: 11px; padding: 2px 8px;">🏢 {{ $child->current_office_name ?: $child->current_office }}</span>
                                        </td>
                                        <td style="padding: 8px 12px; min-width: 220px;" colspan="2">
                                            <div style="display: flex; align-items: center; justify-content: flex-start; gap: 0; position: relative;">
                                                @forelse ($child->timeline_path as $cStepIdx => $cStep)
                                                    @php
                                                        $cIsReceived = !is_null($cStep->date_in) || $child->status === 'completed';
                                                        $cIsForwarded = !is_null($cStep->date_out) || $child->status === 'completed';
                                                        $cIsCurrent = $cStep->office_code === $child->current_office && is_null($cStep->date_out) && $child->status !== 'completed';
                                                        $cDotColor = $cIsReceived ? '#10b981' : '#dc2626';
                                                        $cLineColor = $cIsForwarded ? '#10b981' : '#cbd5e1';
                                                    @endphp
                                                    <div class="dts-timeline-node-wrapper" style="position: relative; display: inline-flex; flex-direction: column; align-items: center; margin: 0;">
                                                        <div class="dts-timeline-node-dot" style="width: 22px; height: 22px; border-radius: 50%; background: {{ $cDotColor }}; color: #ffffff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 2; {{ $cIsCurrent ? 'box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.3); border: 2px solid #ffffff;' : '' }}" title="{{ $cStep->office_name ?: $cStep->office_code }}">
                                                            @if ($cIsReceived)
                                                                <i class="fa-solid fa-check" style="font-size: 9px;"></i>
                                                            @else
                                                                {{ $cStepIdx + 1 }}
                                                            @endif
                                                        </div>
                                                        <span style="margin-top: 2px; font-size: 9px; font-weight: 700; color: {{ $cDotColor }}; font-family: 'Inter', sans-serif;">
                                                            {{ $cStep->office_code }}
                                                        </span>
                                                    </div>
                                                    @if (!$loop->last)
                                                        <div style="flex: 1; height: 3px; background: {{ $cLineColor }}; min-width: 14px; margin: 0 -2px 10px -2px; border-radius: 2px; z-index: 1;"></div>
                                                    @endif
                                                @empty
                                                    <span style="color: #94a3b8; font-size: 11px; font-style: italic;">No path data</span>
                                                @endforelse
                                            </div>
                                        </td>
                                        <td style="padding: 10px 16px; color: #64748b; font-size: 0.75rem;">
                                            {{ \Carbon\Carbon::parse($child->date_created)->format('M d, Y g:i A') }}
                                        </td>
                                    </tr>
                                @endforeach
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px;">
                @foreach($myList as $t)
                    @php
                        $hasBranches = !empty($t->child_branches) && $t->child_branches->isNotEmpty();
                        $isExpanded = in_array($t->control_number, $expandedHubTransactions);
                    @endphp
                    <div style="background: #ffffff; border-radius: 12px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <div>
                                <span style="font-weight: 700; font-size: 1rem; color: #0f172a;">{{ $t->control_number }}</span>
                                @if($hasBranches)
                                    <div>
                                        <button type="button" wire:click="toggleExpandHub('{{ $t->control_number }}')" style="margin-top: 4px; display: inline-flex; align-items: center; gap: 4px; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; cursor: pointer;">
                                            <i class="fa-solid fa-bolt" style="color: #2563eb;"></i> {{ count($t->child_branches) + 1 }} Hub Units
                                            <i class="fa-solid {{ $isExpanded ? 'fa-chevron-up' : 'fa-chevron-down' }}" style="font-size: 9px;"></i>
                                        </button>
                                    </div>
                                @endif
                            </div>
                            <span class="office-location-pill">🏢 {{ $t->current_office_name ?: $t->current_office }}</span>
                        </div>
                        <h4 style="font-size: 0.95rem; font-weight: 600; color: #1e293b; margin: 0 0 6px 0;">
                            {{ Str::limit($t->subject ?: 'No subject', 50) }}
                        </h4>
                        <div style="font-size: 0.8rem; color: #64748b;">
                            <div><strong>Type:</strong> {{ $t->doc_type_name ?: ucfirst($t->trans_type) }}</div>
                            <div><strong>Created:</strong> {{ \Carbon\Carbon::parse($t->date_created)->format('M d, Y') }}</div>
                        </div>

                        @if($hasBranches && $isExpanded)
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 0.8rem;">
                                <div style="font-weight: 700; color: #334155; margin-bottom: 6px;">Hub Units:</div>
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="font-weight: 600; color: #0f172a;">{{ $t->control_number }}</span>
                                        <span style="color: #0369a1;">{{ $t->current_office }}</span>
                                    </div>
                                    @foreach($t->child_branches as $child)
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="font-weight: 600; color: #2563eb;">{{ $child->control_number }}</span>
                                            <span style="color: #0369a1;">{{ $child->current_office }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div style="margin-top: 16px;">
            {{ $myList->links() }}
        </div>
    @endif
</div>

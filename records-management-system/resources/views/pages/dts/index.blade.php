<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.dts')] #[Title('Document Tracking System')] class extends Component {
    use WithPagination;

    public string $activeTab = 'all';
    public string $searchQuery = '';
    public int $perPage = 10;
    public string $layoutMode = 'table'; // table or box

    // Modal state properties
    public string $selectedTransactionId = '';
    public $selectedTransaction = null;
    public string $controlNumber = '';
    public string $fileCode = '';
    public string $particulars = '';
    public string $classification = '';
    public string $actionNeeded = '';
    public string $activeAction = 'forwarded';
    public string $activeNotes = '';
    public bool $editingControl = false;
    public bool $editingFileCode = false;
    public bool $editingParticulars = false;
    public bool $showFullConfiguredPath = false;

    public function toggleLayout(): void
    {
        $this->layoutMode = $this->layoutMode === 'table' ? 'box' : 'table';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->searchQuery = '';
        $this->resetPage();
    }

    public function updatingSearchQuery(): void
    {
        $this->resetPage();
    }

    public function getTransactionsProperty()
    {
        $canViewAll = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_dts_view_all_current_trans;
        if (!$userOfficeCode && !$canViewAll) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage);
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dtd.is_active', 1);

        if (!$canViewAll) {
            $query->where(function($q) use ($userOfficeCode) {
                $q->where('dt.current_office', $userOfficeCode)
                  ->orWhere('dtd.originated_from', $userOfficeCode)
                  ->orWhereExists(function($subQuery) use ($userOfficeCode) {
                      $subQuery->select(DB::raw(1))
                          ->from('sub_document_tracking_system_logs')
                          ->whereColumn('transaction_id', 'dt.transaction_id')
                          ->where('office_code', $userOfficeCode);
                  })
                  ->orWhereExists(function($subQuery) use ($userOfficeCode) {
                      $subQuery->select(DB::raw(1))
                          ->from('dts_copy_filled_transaction as cf')
                          ->join('dts_copy_filled_to_office as cfo', 'cf.assign_offices_id', '=', 'cfo.control_id')
                          ->whereColumn('cf.id', 'dtd.copy_filled_id')
                          ->where(function($cfq) use ($userOfficeCode) {
                              $cfq->where('cfo.office_code', $userOfficeCode)
                                  ->orWhere('cfo.office_code', 'ALL');
                          });
                  });
            });
        }

        // Tab filters
        if ($this->activeTab === 'internal') {
            $query->where('dt.trans_type', 'internal');
        } elseif ($this->activeTab === 'external') {
            $query->where('dt.trans_type', 'external');
        } elseif ($this->activeTab === 'applications') {
            $query->where('dt.trans_type', 'others');
        } elseif ($this->activeTab === 'issuances') {
            $query->where('dt.trans_type', 'memorandom');
        }

        // Search filter
        if (!empty($this->searchQuery)) {
            $searchVal = trim($this->searchQuery);
            $decoded = base64_decode($searchVal, true);
            if ($decoded !== false && preg_match('/^[A-Z0-9-]+$/i', $decoded)) {
                $searchVal = $decoded;
            }
            $query->where(function($q) use ($searchVal) {
                $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                  ->orWhere('dtd.subject', 'like', '%' . $this->searchQuery . '%')
                  ->orWhere('dtd.requestor_name', 'like', '%' . $this->searchQuery . '%')
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
            'dtd.requestor_name',
            'dtd.requestor_label',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', 'desc')
        ->paginate($this->perPage);

        // Map elapsed days, next office, previous office, and received date/time
        $list->getCollection()->transform(function ($t) {
            // Find active/current log step
            $currentLog = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('office_code', $t->current_office)
                ->orderBy('id', 'desc')
                ->first();

            $dateReceived = $currentLog ? $currentLog->date_in : $t->date_created;
            $t->date_received = $dateReceived;

            // Only calculate elapsed days if the transaction has been forwarded
            $hasBeenForwarded = DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $t->transaction_id)
                ->where('type', 'forwarded')
                ->exists();

            if ($hasBeenForwarded) {
                $t->elapsed_days = $dateReceived ? now()->diffInDays(\Carbon\Carbon::parse($dateReceived)) : 0;
            } else {
                $t->elapsed_days = 0;
            }

            // Previous office (from office)
            $prevLog = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
                ->where('log.transaction_id', $t->transaction_id)
                ->whereNotNull('log.date_out')
                ->orderBy('log.id', 'desc')
                ->first();
            $t->from_office = $prevLog ? $prevLog->office_name : 'Originated';

            // Next office
            $flow = DB::table('dts_transaction_details')
                ->where('id', $t->transaction_id)
                ->first();
            $t->next_office_name = 'N/A';
            if ($flow && $flow->transaction_flow) {
                $flowRow = DB::table('dts_transaction_flow')
                    ->where('flow_code', $flow->transaction_flow)
                    ->first();
                if ($flowRow) {
                    $nextSequence = DB::table('dts_sequence_list')
                        ->where('control_id', $flowRow->id)
                        ->where('sequence_ranking', $t->sequence + 1)
                        ->first();
                    if ($nextSequence) {
                        $nextOffice = DB::table('office')
                            ->where('office_code', $nextSequence->office_code)
                            ->first();
                        $t->next_office_name = $nextOffice ? $nextOffice->office_name : 'N/A';
                    } else {
                        $t->next_office_name = 'Completed';
                    }
                }
            }

            return $t;
        });

        return $list;
    }

    // Modal methods
    public function getTransactionPathProperty()
    {
        if (!$this->selectedTransactionId) {
            return collect();
        }

        return DB::table('sub_document_tracking_system_logs as log')
            ->leftJoin('office', 'office.office_code', '=', 'log.office_code')
            ->leftJoin('sub_document_tracking_system_logs_types as lt', 'lt.type_id', '=', 'log.type')
            ->where('log.transaction_id', $this->selectedTransactionId)
            ->select('log.*', 'office.office_name', 'lt.description')
            ->orderBy('log.id', 'asc')
            ->get()
            ->map(function ($step) {
                $step->is_active_step = ($step->office_code === auth()->user()?->details?->office?->office_code && is_null($step->date_out));
                return $step;
            });
    }

    public function toggleFullConfiguredPath(): void
    {
        $this->showFullConfiguredPath = !$this->showFullConfiguredPath;
    }

    public function getFullFlowPathProperty()
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return collect();
        }
        $flowCode = $this->selectedTransaction->transaction_flow;
        $flow = DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
        if (!$flow) {
            return collect();
        }
        return DB::table('dts_sequence_list as seq')
            ->join('office', 'office.office_code', '=', 'seq.office_code')
            ->where('seq.control_id', $flow->id)
            ->select('seq.sequence_ranking', 'office.office_name', 'seq.office_code')
            ->orderBy('seq.sequence_ranking', 'asc')
            ->get()
            ->map(function ($step) {
                $log = DB::table('sub_document_tracking_system_logs')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->where('office_code', $step->office_code)
                    ->orderBy('id', 'desc')
                    ->first();

                $step->date_in = $log ? $log->date_in : null;
                $step->date_out = $log ? $log->date_out : null;
                $step->type = $log ? $log->type : 'pending';
                $step->notes = $log ? $log->notes : '';

                if ($log) {
                    $typeRow = DB::table('sub_document_tracking_system_logs_types')
                        ->where('type_id', $log->type)
                        ->first();
                    $step->description = $typeRow ? $typeRow->description : '';
                } else {
                    $step->description = 'Pending office flow step.';
                }

                $step->is_active_step = (
                    $step->office_code === auth()->user()?->details?->office?->office_code
                    && $step->office_code === $this->selectedTransaction->current_office
                    && $step->sequence_ranking === $this->selectedTransaction->sequence
                    && in_array($this->selectedTransaction->status, ['ongoing', 'revision'])
                    && !is_null($step->date_in)
                );
                return $step;
            });
    }

    public function getVisiblePathProperty()
    {
        return $this->showFullConfiguredPath ? $this->fullFlowPath : $this->transactionPath;
    }

    public function openTransaction(string $id): void
    {
        $this->selectedTransactionId = $id;
        $this->loadSelectedTransaction();
    }

    public function closeTransaction(): void
    {
        $this->selectedTransactionId = '';
        $this->selectedTransaction = null;
        $this->editingControl = false;
        $this->editingFileCode = false;
        $this->editingParticulars = false;
        $this->showFullConfiguredPath = false;
    }

    public function loadSelectedTransaction(): void
    {
        $this->selectedTransaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->where('dt.transaction_id', $this->selectedTransactionId)
            ->first();

        if ($this->selectedTransaction) {
            $this->controlNumber = $this->selectedTransaction->control_number;
            $this->fileCode = $this->selectedTransaction->copy_filled_id ?: '';
            $this->particulars = $this->selectedTransaction->subject ?: '';
            $this->classification = $this->selectedTransaction->classification ?: '';
            $this->actionNeeded = $this->selectedTransaction->action_needed ?: '';
        }
    }

    public function completeTransaction(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($this->selectedTransaction->current_office !== $userOfficeCode) {
            return;
        }

        if ($this->activeAction === 'forwarded') {
            $flow = DB::table('dts_transaction_flow')
                ->where('flow_code', $this->selectedTransaction->transaction_flow)
                ->first();

            if (!$flow) {
                return;
            }

            $nextSequence = DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->where('sequence_ranking', $this->selectedTransaction->sequence + 1)
                ->first();

            // Insert completion of current step
            DB::table('sub_document_tracking_system_logs')
                ->where('transaction_id', $this->selectedTransactionId)
                ->where('office_code', $userOfficeCode)
                ->whereNull('date_out')
                ->update([
                    'date_out' => now(),
                    'type' => 'received',
                    'notes' => $this->activeNotes,
                    'performed_by' => auth()->id(),
                ]);

            if ($nextSequence) {
                // Route to next office
                DB::table('dts_transactions')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->update([
                        'current_office' => $nextSequence->office_code,
                        'sequence' => $this->selectedTransaction->sequence + 1,
                        'status' => 'ongoing',
                    ]);

                // Create next pending log
                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $this->selectedTransactionId,
                    'office_code' => $nextSequence->office_code,
                    'type' => 'forwarded',
                    'date_in' => now(),
                    'date_out' => null,
                    'notes' => 'Forwarded from ' . auth()->user()?->details?->office?->office_name,
                    'performed_by' => auth()->id(),
                ]);
            } else {
                // Completion of flow
                DB::table('dts_transactions')
                    ->where('transaction_id', $this->selectedTransactionId)
                    ->update([
                        'status' => 'completed',
                    ]);

                DB::table('sub_document_tracking_system_logs')->insert([
                    'transaction_id' => $this->selectedTransactionId,
                    'office_code' => $userOfficeCode,
                    'type' => 'completed',
                    'date_in' => now(),
                    'date_out' => now(),
                    'notes' => 'Completed transaction flow.',
                    'performed_by' => auth()->id(),
                ]);
            }
        } elseif ($this->activeAction === 'returned') {
            DB::table('dts_transactions')
                ->where('transaction_id', $this->selectedTransactionId)
                ->update([
                    'current_office' => $this->selectedTransaction->originated_from,
                    'sequence' => 1,
                    'status' => 'revision',
                ]);

            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $this->selectedTransactionId,
                'office_code' => $this->selectedTransaction->originated_from,
                'type' => 'returned',
                'date_in' => now(),
                'date_out' => null,
                'notes' => 'Returned for revision: ' . $this->activeNotes,
                'performed_by' => auth()->id(),
            ]);
        }

        if (auth()->user()?->permissions?->is_sadm) {
            DB::table('dts_transaction_details')
                ->where('id', $this->selectedTransactionId)
                ->update([
                    'control_number' => $this->controlNumber,
                    'copy_filled_id' => $this->fileCode ?: null,
                    'subject' => $this->particulars,
                    'classification' => $this->classification ?: null,
                    'action_needed' => $this->actionNeeded ?: null,
                ]);
        }

        $this->closeTransaction();
    }

    public function deleteTransaction(): void
    {
        if (!$this->selectedTransactionId || !$this->selectedTransaction) {
            return;
        }

        $isCreator = ($this->selectedTransaction->created_by === auth()->id());
        $canDeleteState = in_array($this->selectedTransaction->status, ['revision', 'drafted', 'completed', 'cancelled']) 
                       || !is_null($this->selectedTransaction->append_transaction);

        if (!$isCreator || !$canDeleteState) {
            return;
        }

        if ($this->selectedTransactionId) {
            DB::table('dts_transactions')->where('transaction_id', $this->selectedTransactionId)->delete();
            $this->closeTransaction();
        }
    }

    public function startEdit(string $field): void
    {
        if (!auth()->user()?->permissions?->is_sadm) {
            return;
        }
        $this->editingControl = $field === 'control';
        $this->editingFileCode = $field === 'file_code';
        $this->editingParticulars = $field === 'particulars';
    }

    public function saveField(string $field): void
    {
        match ($field) {
            'control' => $this->editingControl = false,
            'file_code' => $this->editingFileCode = false,
            'particulars' => $this->editingParticulars = false,
            default => null,
        };
    }
};
?>

@push('styles')
    @vite(['resources/css/dts/internal.css', 'resources/css/dts/receive.css', 'resources/css/dts/list_transaction.css'])
@endpush

<div class="dts-page min-h-screen" wire:poll.30s>
    <div class="dts-topbar">
        <div class="dts-nav-group">

            <button wire:click="setTab('all')"
                class="dts-nav-btn dts-nav-btn--back {{ $activeTab === 'all' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive dts-nav-btn--pill' }}">
                <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 19 19" fill="none">
                    <path d="M6.98828 10.8447C7.31464 10.8447 7.57578 10.9523 7.81152 11.1885C8.04812 11.4256 8.15571 11.6876 8.15625 12.0137V17.083C8.15625 17.4094 8.04873 17.6705 7.8125 17.9062C7.57563 18.1426 7.31408 18.25 6.98828 18.25H1.91895C1.59147 18.25 1.33023 18.1422 1.09473 17.9062C0.887473 17.6985 0.77902 17.4723 0.754883 17.2012L0.75 17.082V12.0127C0.750012 11.6863 0.857514 11.4252 1.09375 11.1895C1.30126 10.9824 1.52792 10.8747 1.7998 10.8506L1.91895 10.8447H6.98828ZM17.082 10.8438C17.4084 10.8438 17.6695 10.9513 17.9053 11.1875C18.1124 11.3951 18.221 11.6216 18.2451 11.8936L18.25 12.0127V17.082C18.25 17.4084 18.1425 17.6695 17.9062 17.9053C17.6691 18.1419 17.4072 18.2495 17.0811 18.25H12.0127C11.6852 18.25 11.424 18.1422 11.1885 17.9062C10.9814 17.6987 10.8728 17.4722 10.8486 17.2002L10.8438 17.0811V12.0117C10.8438 11.6853 10.9513 11.4242 11.1875 11.1885C11.3951 10.9814 11.6216 10.8728 11.8936 10.8486L12.0127 10.8438H17.082ZM6.98828 0.75C7.31465 0.75 7.57577 0.857501 7.81152 1.09375C8.01861 1.3013 8.12722 1.52784 8.15137 1.7998L8.15625 1.91895V6.98828C8.15625 7.31465 8.04875 7.57577 7.8125 7.81152C7.57537 8.04812 7.31347 8.15576 6.9873 8.15625H1.91895C1.59147 8.15624 1.33023 8.0485 1.09473 7.8125C0.887637 7.60495 0.779032 7.37841 0.754883 7.10645L0.75 6.9873V1.91797C0.75 1.5916 0.857501 1.33048 1.09375 1.09473C1.3013 0.887637 1.52784 0.779032 1.7998 0.754883L1.91895 0.75H6.98828ZM17.082 0.75C17.4084 0.75 17.6695 0.857501 17.9053 1.09375C18.1124 1.3013 18.221 1.52784 18.2451 1.7998L18.25 1.91895V6.98828C18.25 7.31465 18.1425 7.57577 17.9062 7.81152C17.6691 8.04812 17.4072 8.15576 17.0811 8.15625H12.0127C11.6852 8.15624 11.424 8.0485 11.1885 7.8125C10.9814 7.60495 10.8728 7.37841 10.8486 7.10645L10.8438 6.9873V1.91797C10.8438 1.5916 10.9513 1.33048 11.1875 1.09473C11.3951 0.887637 11.6216 0.779032 11.8936 0.754883L12.0127 0.75H17.082Z" stroke="currentColor" stroke-width="1.5"/>
                </svg>
                All Records
            </button>

            <button wire:click="setTab('internal')"
                class="dts-nav-btn {{ $activeTab === 'internal' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 26 26" fill="none">
                    <path d="M22 22H3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="M17.25 22V6.8C17.25 5.0083 17.25 4.1134 16.6933 3.5567C16.1366 3 15.2417 3 13.45 3H11.55C9.75825 3 8.86335 3 8.30665 3.5567C7.74995 4.1134 7.74995 5.0083 7.74995 6.8V22M21.05 22V12.025C21.05 10.6902 21.05 10.0233 20.7298 9.54455C20.5911 9.337 20.413 9.15881 20.2054 9.02015C19.7266 8.7 19.0588 8.7 17.725 8.7M3.94995 22V12.025C3.94995 10.6902 3.94995 10.0233 4.2701 9.54455C4.40876 9.337 4.58695 9.15881 4.7945 9.02015C5.2733 8.7 5.94115 8.7 7.27495 8.7" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M12.5001 22.0001V19.1501M10.6001 5.8501H14.4001M10.6001 8.7001H14.4001M10.6001 11.5501H14.4001M10.6001 14.4001H14.4001" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Internal
            </button>

            <button wire:click="setTab('external')"
                class="dts-nav-btn {{ $activeTab === 'external' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="17" viewBox="0 0 17 21" fill="none">
                    <path d="M2.89286 19.75C2.32454 19.75 1.77949 19.5276 1.37763 19.1317C0.975765 18.7358 0.75 18.1988 0.75 17.6389V0.75H10.3929L15.75 6.02778V17.6389C15.75 18.1988 15.5242 18.7358 15.1224 19.1317C14.7205 19.5276 14.1755 19.75 13.6071 19.75H2.89286Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9.32153 0.75V7.08333H15.7501" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M5.03589 11.3055H11.4645M5.03589 15.5278H11.4645" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                External
            </button>

            <button wire:click="setTab('applications')"
                class="dts-nav-btn dts-nav-btn--pill {{ $activeTab === 'applications' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="15" viewBox="0 0 21 19" fill="none">
                    <path d="M8.25 7.125C8.25 6.91504 8.32902 6.71367 8.46967 6.56521C8.61032 6.41674 8.80109 6.33333 9 6.33333H15C15.1989 6.33333 15.3897 6.41674 15.5303 6.56521C15.671 6.71367 15.75 6.91504 15.75 7.125C15.75 7.33496 15.671 7.53633 15.5303 7.68479C15.3897 7.83326 15.1989 7.91667 15 7.91667H9C8.80109 7.91667 8.61032 7.83326 8.46967 7.68479C8.32902 7.53633 8.25 7.33496 8.25 7.125ZM9 11.0833H15C15.1989 11.0833 15.3897 10.9999 15.5303 10.8515C15.671 10.703 15.75 10.5016 15.75 10.2917C15.75 10.0817 15.671 9.88034 15.5303 9.73187C15.3897 9.58341 15.1989 9.5 15 9.5H9C8.80109 9.5 8.61032 9.58341 8.46967 9.73187C8.32902 9.88034 8.25 10.0817 8.25 10.2917C8.25 10.5016 8.32902 10.703 8.46967 10.8515C8.61032 10.9999 8.80109 11.0833 9 11.0833ZM21 15.8333C21 16.6732 20.6839 17.4786 20.1213 18.0725C19.5587 18.6664 18.7957 19 18 19H7.5C6.70435 19 5.94129 18.6664 5.37868 18.0725C4.81607 17.4786 4.5 16.6732 4.5 15.8333V3.16667C4.5 2.74674 4.34197 2.34401 4.06066 2.04708C3.77936 1.75015 3.39783 1.58333 3 1.58333C2.60218 1.58333 2.22064 1.75015 1.93934 2.04708C1.65804 2.34401 1.5 2.74674 1.5 3.16667C1.5 3.73469 1.95281 4.11865 1.9575 4.1226C2.08163 4.22343 2.17273 4.36275 2.21804 4.52101C2.26334 4.67927 2.26057 4.84853 2.21011 5.00505C2.15965 5.16156 2.06404 5.29747 1.93668 5.39371C1.80933 5.48995 1.65663 5.54169 1.5 5.54167C1.33782 5.54189 1.18006 5.48592 1.05094 5.38234C0.942187 5.29823 0 4.51349 0 3.16667C0 2.32681 0.316071 1.52136 0.87868 0.927495C1.44129 0.33363 2.20435 0 3 0H15.75C16.5457 0 17.3087 0.33363 17.8713 0.927495C18.4339 1.52136 18.75 2.32681 18.75 3.16667V13.4583H19.5C19.6623 13.4583 19.8202 13.5139 19.95 13.6167C20.0625 13.7018 21 14.4865 21 15.8333ZM8.27438 14.0006C8.32562 13.841 8.42342 13.7025 8.55376 13.6051C8.6841 13.5077 8.84031 13.4563 9 13.4583H17.25V3.16667C17.25 2.74674 17.092 2.34401 16.8107 2.04708C16.5294 1.75015 16.1478 1.58333 15.75 1.58333H5.59594C5.86124 2.06396 6.00069 2.61041 6 3.16667V15.8333C6 16.2533 6.15804 16.656 6.43934 16.9529C6.72065 17.2499 7.10218 17.4167 7.5 17.4167C7.89783 17.4167 8.27936 17.2499 8.56066 16.9529C8.84197 16.656 9 16.2533 9 15.8333C9 15.2653 8.54719 14.8814 8.5425 14.8774C8.41469 14.7809 8.31963 14.6436 8.27136 14.4857C8.22308 14.3279 8.22414 14.1578 8.27438 14.0006ZM19.5 15.8333C19.4906 15.54 19.3834 15.2596 19.1972 15.0417H10.3847C10.46 15.298 10.4982 15.5649 10.4981 15.8333C10.4989 16.3893 10.3602 16.9357 10.0959 17.4167H18C18.3978 17.4167 18.7794 17.2499 19.0607 16.9529C19.342 16.656 19.5 16.2533 19.5 15.8333Z" fill="currentColor"/>
                </svg>
                Application
            </button>

            <button wire:click="setTab('issuances')"
                class="dts-nav-btn dts-nav-btn--pill {{ $activeTab === 'issuances' ? 'dts-nav-btn--active' : 'dts-nav-btn--inactive' }}">
                <svg class="dts-nav-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="17" viewBox="0 0 21 22" fill="none">
                    <path d="M6.25 3.35718H3.41667C3.04094 3.35718 2.68061 3.50016 2.41493 3.75468C2.14926 4.00919 2 4.35438 2 4.71432V19.6429C2 20.0028 2.14926 20.348 2.41493 20.6025C2.68061 20.8571 3.04094 21 3.41667 21H17.5833C17.9591 21 18.3194 20.8571 18.5851 20.6025C18.8507 20.348 19 20.0028 19 19.6429V4.71432C19 4.35438 18.8507 4.00919 18.5851 3.75468C18.3194 3.50016 17.9591 3.35718 17.5833 3.35718H14.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9.08325 8.78571H16.1666M9.08325 12.8571H16.1666M9.08325 16.9286H16.1666M4.83325 8.78571H6.24992M4.83325 12.8571H6.24992M4.83325 16.9286H6.24992M7.66659 2H13.3333C13.709 2 14.0693 2.14298 14.335 2.3975C14.6007 2.65201 14.7499 2.99721 14.7499 3.35714C14.7499 3.71708 14.6007 4.06227 14.335 4.31679C14.0693 4.5713 13.709 4.71429 13.3333 4.71429H7.66659C7.29086 4.71429 6.93053 4.5713 6.66485 4.31679C6.39917 4.06227 6.24992 3.71708 6.24992 3.35714C6.24992 2.99721 6.39917 2.65201 6.66485 2.3975C6.93053 2.14298 7.29086 2 7.66659 2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Issuances
            </button>

        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="button" wire:click="toggleLayout" class="dts-nav-btn" style="border: 1.5px solid var(--border-gray); background: white; white-space: nowrap; height: 42px; box-sizing: border-box;">
                @if ($layoutMode === 'table')
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Grid View
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                    Table View
                @endif
            </button>

            <div class="dts-search-wrap">
                <input
                    type="text"
                    wire:model.live="searchQuery"
                    placeholder="Search control number, subject..."
                    class="dts-search-input"
                    style="margin-left: 0; padding-left: 40px; height: 42px;"
                />
                <svg class="dts-search-icon" style="left: 12px;" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 25 25" fill="none">
                    <path d="M17.1088 17.1091L21.1345 21.1347M3.01904 11.0706C3.01904 13.2059 3.8673 15.2538 5.37722 16.7637C6.88713 18.2736 8.93501 19.1219 11.0703 19.1219C13.2057 19.1219 15.2536 18.2736 16.7635 16.7637C18.2734 15.2538 19.1217 13.2059 19.1217 11.0706C19.1217 8.93525 18.2734 6.88737 16.7635 5.37746C15.2536 3.86755 13.2057 3.01929 11.0703 3.01929C8.93501 3.01929 6.88713 3.86755 5.37722 5.37746C3.8673 6.88737 3.01904 8.93525 3.01904 11.0706Z" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </div>
    </div>

    @if ($layoutMode === 'table')
        <div class="rms-table-responsive max-w-full mx-auto" style="background: white;">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th>SUBJECT</th>
                        <th>UNIT/COLLEGE</th>
                        <th>REQUESTOR</th>
                        <th>CONTROL NO.</th>
                        <th>DOC TYPE</th>
                        <th>FROM OFFICE</th>
                        <th>RECEIVED</th>
                        <th>NEXT OFFICE</th>
                        <th>ACTION NEEDED</th>
                        <th>ELAPSED DAY</th>
                        <th>STATUS</th>
                        <th style="width: 60px;">View</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($this->transactions as $t)
                        <tr>
                            <td style="max-width: 300px; white-space: normal; word-break: break-word;">{{ $t->subject }}</td>
                            <td>{{ $t->originated_office_name }}</td>
                             <td style="white-space: nowrap;">
                                 {{ $t->requestor_name }}
                                 @if(!empty($t->requestor_label))
                                     <div style="font-size: 11px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</div>
                                 @endif
                             </td>
                            <td style="font-weight: 600; color: #1e40af; text-align: center;">{{ $t->control_number }}</td>
                            <td>{{ $t->document_name ?? ucfirst($t->trans_type) }}</td>
                            <td>{{ $t->from_office }}</td>
                            <td>{{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</td>
                            <td>{{ $t->next_office_name }}</td>
                            <td style="color: #16a34a; font-weight: 500;">{{ $t->action_needed ?? 'For action' }}</td>
                            <td style="color: #dc2626; font-weight: 600; white-space: nowrap; text-align: center;">{{ $t->elapsed_days }} day(s)</td>
                            <td style="text-align: center;">
                                <span class="status-badge status-{{ $t->status }}">
                                    {{ $t->status }}
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="rms-select" style="text-decoration: none; display: inline-block; border: none; background: transparent; cursor: pointer; color: #043899; font-weight: 500;">View</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="rms-no-data" colspan="12">No records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <!-- Box Layout (Card Grid) -->
        <div class="dts-card-grid-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 20px; margin-bottom: 20px;">
            @forelse ($this->transactions as $t)
                <div class="dts-box-card" style="background: white; border: 1.5px solid var(--border-gray); border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                    <!-- Top Right Info & Icon -->
                    <div style="position: absolute; top: 16px; right: 16px; display: flex; align-items: center; gap: 8px; font-size: 11px; color: #6b7280;">
                        <span>{{ \Carbon\Carbon::parse($t->date_received)->diffForHumans(null, true) }} ago</span>
                        @if ($t->status === 'completed')
                            <span style="display: inline-block; width: 14px; height: 14px; background-color: #10b981; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Completed">
                                <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">✔</span>
                            </span>
                        @elseif ($t->classification === 'highly_technical')
                            <span style="display: inline-block; width: 14px; height: 14px; background-color: #ef4444; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Highly Technical">
                                <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                            </span>
                        @else
                            <span style="display: inline-block; width: 14px; height: 14px; background-color: #f59e0b; transform: rotate(45deg); display: flex; align-items: center; justify-content: center; border-radius: 2px;" title="Pending Action">
                                <span style="transform: rotate(-45deg); color: white; font-size: 9px; font-weight: bold;">!</span>
                            </span>
                        @endif
                    </div>

                    <!-- Card Body contents -->
                    <div style="font-size: 13px; color: #4b5563; line-height: 1.6; margin-top: 12px; font-family: Roboto, sans-serif;">
                        <div style="margin-bottom: 6px; word-break: break-word; overflow-wrap: break-word; white-space: normal;"><strong>Subject:</strong> {{ $t->subject }}</div>
                        <div style="margin-bottom: 6px;"><strong>Unit/College:</strong> {{ $t->originated_office_name }}</div>
                        <div style="margin-bottom: 6px;"><strong>Name of Requestor:</strong> {{ $t->requestor_name }} @if(!empty($t->requestor_label)) <span style="font-size: 12px; color: #6b7280; font-weight: normal;">({{ $t->requestor_label }})</span> @endif</div>
                        <div style="margin-bottom: 6px;"><strong>Control Number:</strong> <span style="font-weight: 600; color: #1e40af;">{{ $t->control_number }}</span></div>
                        <div style="margin-bottom: 14px;"><strong>Type of Document:</strong> {{ $t->document_name ?? ucfirst($t->trans_type) }}</div>

                        <div style="margin-bottom: 6px;"><strong>Receive From:</strong> <span style="color: #ef4444; font-weight: 500;">{{ $t->from_office }}</span></div>
                        <div style="margin-bottom: 14px;"><strong>Receive Date:</strong> {{ $t->date_received ? \Carbon\Carbon::parse($t->date_received)->format('Y-m-d H:i') : 'N/A' }}</div>

                        <div style="margin-bottom: 14px;"><strong>Next Receiving Office:</strong> {{ $t->next_office_name }}</div>

                        <div style="margin-bottom: 6px;"><strong>Action Needed:</strong> <span style="color: #16a34a; font-weight: 600;">{{ $t->action_needed ?? 'For action' }}</span></div>
                        <div style="margin-bottom: 6px;"><strong>Elapsed Day:</strong> <span style="color: #ef4444; font-style: italic;">{{ $t->elapsed_days }} day(s) </span></div>
                    </div>

                    <!-- Card Footer view action -->
                    <div style="display: flex; justify-content: flex-end; margin-top: 16px;">
                        <button type="button" wire:click="openTransaction('{{ $t->transaction_id }}')" class="dts-page-btn" style="background-color: #3b82f6; color: white; border: none; padding: 8px 18px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; font-weight: 500; text-decoration: none; font-size: 11px; letter-spacing: 0.3px; transition: background-color 0.2s ease; cursor: pointer;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            VIEW TRANSACTION
                        </button>
                    </div>
                </div>
            @empty
                <div style="grid-column: 1 / -1; background: white; border-radius: 12px; padding: 40px; text-align: center; color: #9CA3AF; font-style: italic; border: 1.5px solid var(--border-gray);">
                    No records found.
                </div>
            @endforelse
        </div>
    @endif

    <div class="dts-footer">
        <span class="dts-footer-total">Total Records: <strong>{{ $this->transactions->total() }}</strong></span>
        <div class="dts-pagination">
            @if ($this->transactions->onFirstPage())
                <button type="button" class="dts-page-btn" style="cursor: not-allowed; opacity: 0.5;" disabled>← Previous</button>
            @else
                <button type="button" class="dts-page-btn" wire:click="previousPage">← Previous</button>
            @endif
            <span class="dts-page-indicator">Page <strong>{{ $this->transactions->currentPage() }}</strong> of <strong>{{ $this->transactions->lastPage() }}</strong></span>
            @if ($this->transactions->hasMorePages())
                <button type="button" class="dts-page-btn" wire:click="nextPage">Next →</button>
            @else
                <button type="button" class="dts-page-btn" style="cursor: not-allowed; opacity: 0.5;" disabled>Next →</button>
            @endif
        </div>
    </div>

    <!-- Details Overlay Modal -->
    @if($selectedTransactionId && $selectedTransaction)
        <div class="modal-backdrop" wire:click="closeTransaction">
            <div class="modal-content" wire:click.stop>
                <button type="button" class="modal-close-btn" wire:click="closeTransaction">&times;</button>
                
                <form class="receive-card" method="post" action="#" onsubmit="return false;" style="box-shadow: none;">
                    <h1 class="receive-title">Transaction Details</h1>
                    
                    <div class="receive-fields">
                        <!-- Control Number field -->
                        <div class="receive-field-row">
                            <span class="receive-field-label">Control #:</span>
                            @if ($editingControl)
                                <div style="display: flex; gap: 8px; width: 100%;">
                                    <input type="text" class="receive-field-input" wire:model="controlNumber">
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="saveField('control')">Save</button>
                                    </div>
                                </div>
                            @else
                                <input type="text" class="receive-field-input" value="{{ $controlNumber }}" readonly>
                                @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="startEdit('control')">Update</button>
                                        <span>|</span>
                                        <button type="button" wire:click="startEdit('control')">Edit</button>
                                    </div>
                                @endif
                            @endif
                        </div>

                        <!-- File Code / Copy Furnished field -->
                        <div class="receive-field-row">
                            <span class="receive-field-label">File Code:</span>
                            @if ($editingFileCode)
                                <div style="display: flex; gap: 8px; width: 100%;">
                                    <input type="text" class="receive-field-input" wire:model="fileCode">
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="saveField('file_code')">Save</button>
                                    </div>
                                </div>
                            @else
                                <input type="text" class="receive-field-input" value="{{ $fileCode ?: 'N/A' }}" readonly>
                                @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                    <div class="receive-field-actions">
                                        <button type="button" wire:click="startEdit('file_code')">Update</button>
                                        <span>|</span>
                                        <button type="button" wire:click="startEdit('file_code')">Edit</button>
                                    </div>
                                @endif
                            @endif
                        </div>

                        <!-- Particulars / Subject field -->
                        <div class="receive-field-row receive-field-row--particulars">
                            <span class="receive-field-label">Particulars:</span>
                            @if ($editingParticulars)
                                <div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">
                                    <textarea class="receive-field-input" wire:model="particulars" style="min-height: 72px; resize: vertical;"></textarea>
                                    <div class="receive-field-actions" style="justify-content: flex-end;">
                                        <button type="button" wire:click="saveField('particulars')">Save</button>
                                    </div>
                                </div>
                            @else
                                @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                                    <div class="receive-particulars-display" wire:click="startEdit('particulars')" style="cursor: pointer; width: 100%;">
                                        {{ $particulars ?: 'Click to add particulars...' }}
                                    </div>
                                @else
                                    <div class="receive-particulars-display" style="width: 100%;">
                                        {{ $particulars ?: 'No particulars provided.' }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>

                    <hr class="receive-divider">

                    <!-- Transaction Path Section -->
                    <h2 class="receive-title" style="font-size: 16px; margin-top: 10px;">Transaction Path</h2>

                    <!-- Path Table -->
                    <div class="receive-table-wrap">
                        <table class="receive-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Office</th>
                                    <th>Date In</th>
                                    <th>Date Out</th>
                                    <th>Action Need</th>
                                    <th>Notes</th>
                                    <th>Info</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->visiblePath as $index => $step)
                                    <tr>
                                        <td>{{ $step->sequence_ranking ?? ($index + 1) }}</td>
                                        <td class="office-cell">{{ $step->office_name }}</td>
                                        <td>{{ $step->date_in ? \Carbon\Carbon::parse($step->date_in)->format('Y-m-d H:i') : 'N/A' }}</td>
                                        <td>{{ $step->date_out ? \Carbon\Carbon::parse($step->date_out)->format('Y-m-d H:i') : 'Pending' }}</td>
                                        <td>
                                            @if ($step->is_active_step)
                                                <span class="text-green font-semibold" style="text-transform: uppercase;">Active</span>
                                            @else
                                                {{ ucfirst($step->type) }}
                                            @endif
                                        </td>
                                        <td>
                                            @if ($step->is_active_step)
                                                <input type="text" wire:model="activeNotes" class="active-notes-input" placeholder="Type notes here...">
                                            @else
                                                {{ $step->notes ?: '-' }}
                                            @endif
                                        </td>
                                        <td>
                                            <button type="button" class="receive-info-btn" title="{{ $step->description ?? '' }}">
                                                <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px; stroke: currentColor;">
                                                    <circle cx="12" cy="12" r="10" stroke-width="1.5" fill="none"/>
                                                    <circle cx="12" cy="12" r="2" fill="currentColor"/>
                                                    <circle cx="17" cy="12" r="2" fill="currentColor"/>
                                                    <circle cx="7" cy="12" r="2" fill="currentColor"/>
                                                </svg>
                                            </button>
                                        </td>
                                        <td class="action-cell">
                                            @if ($step->is_active_step)
                                                <select wire:model="activeAction" class="receive-row-action-select">
                                                    <option value="forwarded">Forward</option>
                                                    <option value="returned">Revise</option>
                                                </select>
                                            @else
                                                <span style="color: #9ca3af; font-size: 11px; font-style: italic;">Processed</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" style="padding: 24px; color: #888; font-style: italic;">No transaction paths listed.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Popup Action Buttons -->
                    <div class="receive-actions">
                        <!-- VIEW LISTED PATH / VIEW LOGS Toggle -->
                        <button type="button" class="receive-action-btn" wire:click="toggleFullConfiguredPath">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            {{ $showFullConfiguredPath ? 'VIEW LOGS' : 'VIEW LISTED PATH' }}
                        </button>

                        <!-- COMPLETED -->
                        @if ($selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            <button type="button" class="receive-action-btn" wire:click="completeTransaction">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                COMPLETED
                            </button>
                        @endif

                        <!-- EDIT (Save Metadata changes manually without completing) -->
                        @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            <button type="button" class="receive-action-btn" wire:click="completeTransaction">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 3l4 4-7 7H10v-4l7-7z"/>
                                    <path d="M4 20h16"/>
                                </svg>
                                EDIT
                            </button>
                        @endif

                        <!-- DELETE (Creator and non-active/revised/amended/draft states only) -->
                        @php
                            $isCreator = ($selectedTransaction->created_by === auth()->id());
                            $canDeleteState = in_array($selectedTransaction->status, ['revision', 'drafted', 'completed', 'cancelled']) 
                                           || !is_null($selectedTransaction->append_transaction);
                        @endphp
                        @if ($isCreator && $canDeleteState)
                            <button type="button" class="receive-action-btn receive-action-btn--danger" wire:click="deleteTransaction">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    <line x1="10" y1="11" x2="10" y2="17"/>
                                    <line x1="14" y1="11" x2="14" y2="17"/>
                                </svg>
                                DELETE
                            </button>
                        @endif

                        <!-- + ADD CF (Dummy link to edit File Code) -->
                        @if (auth()->user()?->permissions?->is_sadm && $selectedTransaction->current_office === auth()->user()?->details?->office?->office_code)
                            <button type="button" class="receive-action-btn" wire:click="startEdit('file_code')">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 5v14M5 12h14"/>
                                </svg>
                                + ADD CF
                            </button>
                        @endif

                        <!-- BARCODE (Alert control number info) -->
                        <button type="button" class="receive-action-btn" onclick="alert('Barcode scan ID: ' + '{{ $selectedTransaction->qr_code ?? '' }}')">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 5h2v14H3zM7 5h2v14H7zM11 5h2v14h-2zM15 5h2v14h-2zM19 5h2v14h-2z"/>
                            </svg>
                            BARCODE
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

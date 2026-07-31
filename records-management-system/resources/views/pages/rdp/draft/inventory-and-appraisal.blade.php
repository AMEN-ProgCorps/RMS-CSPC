<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.rdp')] #[Title('Draft Inventory and Appraisal')] class extends Component {
    public string $search = '';
    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = Auth::user()?->permissions;
        if (!$perms || (!(bool)($perms->is_sadm ?? false) && !(bool)($perms->can_access_rdp ?? true))) {
            redirect()->route('rdp')->send();
            return;
        }
    }

    public function deleteDraft(int $recordId): void
    {
        try {
            DB::table('rdp_record')->where('id', $recordId)->where('is_draft', true)->delete();
            $this->successMessage = 'Draft record deleted successfully.';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete draft: ' . $e->getMessage();
        }
    }

    public function submitDraft(int $recordId): void
    {
        try {
            DB::table('rdp_record')->where('id', $recordId)->update([
                'is_draft'   => false,
                'updated_at' => now(),
            ]);
            $this->successMessage = 'Draft record submitted successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to submit draft: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $userOffice = Auth::user()?->details?->office_code;

        $query = DB::table('rdp_record')
            ->leftJoin('rdp_record_series', 'rdp_record.record_series_id', '=', 'rdp_record_series.id')
            ->leftJoin('rdp_retention_period', 'rdp_record.period_id', '=', 'rdp_retention_period.id')
            ->where('rdp_record.is_draft', true);

        if ($userOffice) {
            $query->where('rdp_record.office_own', $userOffice);
        }

        if (!empty(trim($this->search))) {
            $term = '%' . trim($this->search) . '%';
            $query->where(function($q) use ($term) {
                $q->where('rdp_record.description', 'ILIKE', $term)
                  ->orWhere('rdp_record_series.series_title', 'ILIKE', $term);
            });
        }

        $drafts = $query->select([
            'rdp_record.*',
            'rdp_record_series.series_title',
            'rdp_retention_period.total_period'
        ])
        ->orderBy('rdp_record.updated_at', 'desc')
        ->get();

        return [
            'drafts' => $drafts,
        ];
    }
}; ?>

<div class="draft-page">
    <style>
        .draft-page {
            padding: 24px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-title p {
            font-size: 14px;
            color: #64748b;
            margin: 0;
        }
        .search-input {
            padding: 9px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            width: 300px;
        }
        .alert-msg {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        .draft-table-wrapper {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .draft-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        .draft-table th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 600;
            padding: 14px 16px;
            border-right: 1px solid #334155;
        }
        .draft-table td {
            padding: 14px 16px;
            color: #334155;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
        }
        .draft-badge {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .btn-resume {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-submit {
            background: #16a34a;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 4px;
        }
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
    </style>

    <div class="header-card">
        <div class="header-title">
            <h1>Draft Inventory & Appraisal Records</h1>
            <p>View and manage unfinished NAP Form 1 draft entries saved by your office</p>
        </div>
        <div>
            <input type="text" wire:model.live.debounce.250ms="search" class="search-input" placeholder="Search draft records...">
        </div>
    </div>

    @if(!empty($successMessage))
        <div class="alert-msg alert-success">{{ $successMessage }}</div>
    @endif
    @if(!empty($errorMessage))
        <div class="alert-msg alert-error">{{ $errorMessage }}</div>
    @endif

    <div class="draft-table-wrapper">
        <table class="draft-table">
            <thead>
                <tr>
                    <th style="width: 80px;">ID</th>
                    <th>Series Title</th>
                    <th>Description / Details</th>
                    <th style="width: 140px;">Retention</th>
                    <th style="width: 110px;">Status</th>
                    <th style="width: 150px;">Last Saved</th>
                    <th style="width: 220px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($drafts as $d)
                    <tr>
                        <td><strong>#{{ $d->id }}</strong></td>
                        <td><strong>{{ $d->series_title ?? 'Unassigned Series' }}</strong></td>
                        <td>{{ $d->description ?: 'No description entered' }}</td>
                        <td>{{ $d->total_period ?? '—' }}</td>
                        <td><span class="draft-badge">Draft</span></td>
                        <td>{{ \Carbon\Carbon::parse($d->updated_at)->format('M d, Y g:i A') }}</td>
                        <td style="text-align: center;">
                            <button onclick="proccedto('{{ route('rdp.add-records.inventory-and-appraisal') }}')" class="btn-resume">Edit</button>
                            <button wire:click="submitDraft({{ $d->id }})" class="btn-submit">Submit</button>
                            <button wire:click="deleteDraft({{ $d->id }})" wire:confirm="Delete this draft?" class="btn-delete">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 48px; color: #64748b;">
                            No draft Inventory & Appraisal records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

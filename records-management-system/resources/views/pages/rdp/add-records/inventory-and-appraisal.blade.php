<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;

new #[Layout('layouts.rdp')] #[Title('Inventory and Appraisal')] class extends Component {
    use WithFileUploads;

    // Series Modal Hierarchy State
    public bool $showSeriesModal = false;
    public string $parentSeriesTitle = '';
    public bool $showParentDropdown = false;
    public array $subsections = [];
    public ?int $activeSubDropdownIndex = null;

    // Selected Series Staged State (Deferred DB save)
    public ?int $record_series_id = null;
    public ?string $selectedSeriesTitle = null;

    // Period Covered Modal & Staged State (Deferred DB save)
    public bool $showPeriodModal = false;
    public array $periodsCovered = [];

    // Form Input Properties
    public string $description = '';
    public string $volume = '';
    public ?int $records_medium = null;
    public ?string $restriction = null;
    public string $records_location = '';
    public ?string $frequence_use = null;
    public ?string $duplication = null;
    public ?string $time_value = null;
    public ?int $utility_value = null;
    public string $retention_period = '';
    public string $disposition_provision = '';
    
    public $uploadedFile = null;
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
    }

    // --- Record Series Modal Handlers ---
    public function openSeriesModal(): void
    {
        $this->showSeriesModal = true;
    }

    public function closeSeriesModal(): void
    {
        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
    }

    public function selectParentSuggestion(string $title): void
    {
        $this->parentSeriesTitle = $title;
        $this->showParentDropdown = false;
    }

    public function selectSubSuggestion(int $index, string $title): void
    {
        if (isset($this->subsections[$index])) {
            $this->subsections[$index] = $title;
        }
        $this->activeSubDropdownIndex = null;
    }

    public function addSubsection(): void
    {
        $this->subsections[] = '';
    }

    public function removeSubsection(int $index): void
    {
        if (isset($this->subsections[$index])) {
            unset($this->subsections[$index]);
            $this->subsections = array_values($this->subsections);
        }
    }

    /**
     * Stage Record Series in memory without direct DB write.
     */
    public function saveNewRecordSeries(): void
    {
        $this->validate([
            'parentSeriesTitle' => 'required|string|max:255',
        ]);

        $fullPathTitles = [trim($this->parentSeriesTitle)];

        foreach ($this->subsections as $subTitle) {
            $trimmed = trim($subTitle);
            if (!empty($trimmed)) {
                $fullPathTitles[] = $trimmed;
            }
        }

        $this->selectedSeriesTitle = implode(' ➔ ', $fullPathTitles);
        $this->showSeriesModal = false;
        $this->showParentDropdown = false;
        $this->activeSubDropdownIndex = null;
    }

    // --- Period Covered Modal Handlers ---
    public function openPeriodModal(): void
    {
        if (empty($this->periodsCovered)) {
            $this->periodsCovered = [
                ['start_month' => date('Y-01'), 'end_month' => date('Y-12')]
            ];
        }
        $this->showPeriodModal = true;
    }

    public function closePeriodModal(): void
    {
        $this->showPeriodModal = false;
    }

    public function addPeriodRange(): void
    {
        $this->periodsCovered[] = ['start_month' => '', 'end_month' => ''];
    }

    public function removePeriodRange(int $index): void
    {
        if (isset($this->periodsCovered[$index])) {
            unset($this->periodsCovered[$index]);
            $this->periodsCovered = array_values($this->periodsCovered);
        }
    }

    /**
     * Stage Period Covered in memory without direct DB write.
     */
    public function savePeriods(): void
    {
        $this->periodsCovered = array_values(array_filter($this->periodsCovered, function ($p) {
            return !empty($p['start_month']) || !empty($p['end_month']);
        }));

        $this->showPeriodModal = false;
    }

    public function getFormattedPeriodsProperty(): array
    {
        $formatted = [];
        foreach ($this->periodsCovered as $p) {
            $startStr = '';
            $endStr = '';

            if (!empty($p['start_month'])) {
                try {
                    $startStr = Carbon::createFromFormat('Y-m', $p['start_month'])->format('M Y');
                } catch (\Exception $e) {
                    $startStr = $p['start_month'];
                }
            }

            if (!empty($p['end_month'])) {
                try {
                    $endStr = Carbon::createFromFormat('Y-m', $p['end_month'])->format('M Y');
                } catch (\Exception $e) {
                    $endStr = $p['end_month'];
                }
            }

            if ($startStr && $endStr) {
                $formatted[] = $startStr . ' - ' . $endStr;
            } elseif ($startStr) {
                $formatted[] = $startStr;
            } elseif ($endStr) {
                $formatted[] = $endStr;
            }
        }
        return $formatted;
    }

    public function with(): array
    {
        $parentSuggestions = DB::table('rdp_record_series')
            ->select('series_title')
            ->whereNull('parent_id')
            ->when(!empty($this->parentSeriesTitle), function ($q) {
                $q->where('series_title', 'like', '%' . $this->parentSeriesTitle . '%');
            })
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(8)
            ->get();

        $allSeriesSuggestions = DB::table('rdp_record_series')
            ->select('series_title')
            ->distinct()
            ->orderBy('series_title', 'asc')
            ->limit(10)
            ->get();

        return [
            'parentSuggestions' => $parentSuggestions,
            'allSeriesSuggestions' => $allSeriesSuggestions,
            'mediaList'         => DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get(),
            'restrictionsList'  => DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get(),
            'frequenciesList'   => DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get(),
            'timeValuesList'    => DB::table('rdp_time_value')->orderBy('char_value', 'asc')->get(),
            'utilityValuesList' => DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get(),
        ];
    }

    public function createRecord(): void
    {
        if (empty($this->selectedSeriesTitle)) {
            $this->errorMessage = 'Please select or configure a Record Series first.';
            return;
        }

        $this->validate([
            'description'      => 'nullable|string',
            'volume'           => 'nullable|string',
            'records_medium'   => 'nullable|integer',
            'restriction'      => 'nullable|string',
            'records_location' => 'nullable|string',
            'frequence_use'    => 'nullable|string',
            'time_value'       => 'nullable|string',
            'utility_value'    => 'nullable|integer',
            'uploadedFile'     => 'nullable|file|max:20480',
        ]);

        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code;
            $documentIdHandler = null;

            // 1. Save Staged Record Series to DB only on form submission
            $titles = explode(' ➔ ', $this->selectedSeriesTitle);
            $lastSeriesId = null;

            foreach ($titles as $idx => $title) {
                $trimmed = trim($title);
                $existing = DB::table('rdp_record_series')
                    ->where('series_title', $trimmed)
                    ->where('parent_id', $lastSeriesId)
                    ->first();

                if ($existing) {
                    $lastSeriesId = $existing->id;
                } else {
                    $lastSeriesId = DB::table('rdp_record_series')->insertGetId([
                        'series_title'       => $trimmed,
                        'parent_id'          => $lastSeriesId,
                        'recorded_at_office' => $userOfficeCode,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                }
            }

            $this->record_series_id = $lastSeriesId;

            // 2. File Upload Handling
            if ($this->uploadedFile) {
                $documentIdHandler = 'DOC-' . strtoupper(Str::random(10));
                $originalName = $this->uploadedFile->getClientOriginalName();
                $storedPath = $this->uploadedFile->store('rdp_documents', 'local');

                DB::table('document_data')->insert([
                    'document_id'   => $documentIdHandler,
                    'document_name' => $originalName,
                    'document_path' => $storedPath,
                    'uploaded_by'   => $user?->id,
                    'user_office'   => $userOfficeCode,
                    'date_added'    => now(),
                    'date_modified' => now(),
                    'date_deleted'  => now(),
                ]);
            }

            // 3. Retention Period
            $periodId = null;
            if (!empty($this->retention_period)) {
                $periodId = DB::table('rdp_retention_period')->insertGetId([
                    'active_period'  => $this->retention_period,
                    'storage_period' => null,
                    'total_period'   => $this->retention_period,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            // 4. Create Main Record
            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $this->record_series_id,
                'description'            => $this->description,
                'period_id'              => $periodId,
                'volume'                 => $this->volume,
                'records_location'       => $this->records_location,
                'restriction'            => $this->restriction,
                'records_medium'         => $this->records_medium,
                'time_value'             => $this->time_value,
                'frequence_use'          => $this->frequence_use,
                'utility_value'          => $this->utility_value,
                'user_own'               => $user?->id,
                'office_own'             => $userOfficeCode,
                'upload_doc_id_handler'  => $documentIdHandler,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // 5. Period Covered - Saved to DB only on form submission
            foreach ($this->periodsCovered as $p) {
                $startAt = null;
                $endsAt = null;

                if (!empty($p['start_month'])) {
                    try {
                        $startAt = Carbon::createFromFormat('Y-m', $p['start_month'])->startOfMonth()->toDateTimeString();
                    } catch (\Exception $e) {
                        $startAt = null;
                    }
                }

                if (!empty($p['end_month'])) {
                    try {
                        $endsAt = Carbon::createFromFormat('Y-m', $p['end_month'])->endOfMonth()->toDateTimeString();
                    } catch (\Exception $e) {
                        $endsAt = null;
                    }
                }

                if ($startAt || $endsAt) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'start_at'     => $startAt,
                        'ends_at'      => $endsAt,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            DB::commit();

            $this->successMessage = 'Record created successfully!';
            $this->reset(['description', 'volume', 'records_location', 'uploadedFile', 'retention_period', 'disposition_provision', 'record_series_id', 'selectedSeriesTitle', 'parentSeriesTitle', 'subsections', 'periodsCovered']);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error creating record: ' . $e->getMessage();
        }
    }

    public function saveDraft(): void
    {
        try {
            DB::beginTransaction();

            $user = Auth::user();
            $userOfficeCode = $user?->details?->office?->office_code;

            // Resolve staged Record Series if present
            $seriesId = 1;
            if (!empty($this->selectedSeriesTitle)) {
                $titles = explode(' ➔ ', $this->selectedSeriesTitle);
                $lastSeriesId = null;

                foreach ($titles as $title) {
                    $trimmed = trim($title);
                    $existing = DB::table('rdp_record_series')
                        ->where('series_title', $trimmed)
                        ->where('parent_id', $lastSeriesId)
                        ->first();

                    if ($existing) {
                        $lastSeriesId = $existing->id;
                    } else {
                        $lastSeriesId = DB::table('rdp_record_series')->insertGetId([
                            'series_title'       => $trimmed,
                            'parent_id'          => $lastSeriesId,
                            'recorded_at_office' => $userOfficeCode,
                            'created_at'         => now(),
                            'updated_at'         => now(),
                        ]);
                    }
                }
                $seriesId = $lastSeriesId;
            }

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $seriesId,
                'description'            => '[DRAFT] ' . $this->description,
                'volume'                 => $this->volume,
                'records_location'       => $this->records_location,
                'restriction'            => $this->restriction,
                'records_medium'         => $this->records_medium,
                'time_value'             => $this->time_value,
                'frequence_use'          => $this->frequence_use,
                'utility_value'          => $this->utility_value,
                'user_own'               => $user?->id,
                'office_own'             => $userOfficeCode,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // Save Period Covered for draft
            foreach ($this->periodsCovered as $p) {
                $startAt = null;
                $endsAt = null;

                if (!empty($p['start_month'])) {
                    try {
                        $startAt = Carbon::createFromFormat('Y-m', $p['start_month'])->startOfMonth()->toDateTimeString();
                    } catch (\Exception $e) {
                        $startAt = null;
                    }
                }

                if (!empty($p['end_month'])) {
                    try {
                        $endsAt = Carbon::createFromFormat('Y-m', $p['end_month'])->endOfMonth()->toDateTimeString();
                    } catch (\Exception $e) {
                        $endsAt = null;
                    }
                }

                if ($startAt || $endsAt) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'start_at'     => $startAt,
                        'ends_at'      => $endsAt,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }

            DB::commit();

            $this->successMessage = 'Draft saved successfully!';
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Error saving draft: ' . $e->getMessage();
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/rdp/inventory-and-appraisal.css'])
@endpush

<div class="rms-container">
    <style>
        .form-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
        }
        .form-row-flex {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .form-label-bold {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            min-width: 160px;
        }
        .form-input-custom {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            background: #ffffff;
        }
        .form-input-custom:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .btn-set-inline {
            padding: 8px 22px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px !important;
            border-radius: 8px !important;
        }
        
        /* White Edit Button with Pencil Icon */
        .btn-edit-series {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            background: #ffffff;
            color: #2563eb;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
        }
        .btn-edit-series:hover {
            border-color: #2563eb;
            background: #f8fafc;
            color: #1d4ed8;
            box-shadow: 0 4px 8px rgba(37, 99, 235, 0.1);
        }
        .btn-edit-series svg {
            width: 15px;
            height: 15px;
            fill: #2563eb;
        }

        .selected-series-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Series & Period Modal Overlay */
        .series-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .series-modal-container {
            background: #ffffff;
            width: 100%;
            max-width: 580px;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            overflow: visible;
            border: 1px solid #e2e8f0;
        }
        .series-modal-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .series-modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .close-modal-btn {
            background: transparent;
            border: none;
            font-size: 20px;
            color: #64748b;
            cursor: pointer;
        }
        .series-modal-body {
            padding: 24px;
        }
        .form-group-modal {
            margin-bottom: 16px;
            position: relative;
        }
        .form-group-modal label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .modal-input-style {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            background: #fff;
        }
        .modal-input-style:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .dts-autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-height: 180px;
            overflow-y: auto;
            z-index: 100;
            margin-top: 4px;
        }
        .dts-autocomplete-item {
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
        }
        .dts-autocomplete-item:last-child {
            border-bottom: none;
        }
        .dts-autocomplete-item:hover {
            background-color: #eff6ff;
            color: #2563eb;
        }

        .btn-add-subsection {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-add-subsection:hover {
            background: #dbeafe;
        }
        .btn-remove-sub {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            width: 36px;
            height: 38px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-remove-sub:hover {
            background: #fca5a5;
        }
        .modal-actions-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
        }
        .btn-secondary-modal {
            padding: 10px 20px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-secondary-modal:hover {
            background: #e2e8f0;
        }
        .actions-bar {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .period-range-row {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 12px;
        }
    </style>

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Add Record (Inventory & Appraisal)</h2>
    </div>

    @if($successMessage)
        <div style="padding: 12px 16px; background-color: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; border-radius: 8px; margin-bottom: 16px; font-weight: 600;">
            {{ $successMessage }}
        </div>
    @endif

    @if($errorMessage)
        <div style="padding: 12px 16px; background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 8px; margin-bottom: 16px; font-weight: 600;">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="form-card">
        <!-- ===== Record Series Row ===== -->
        <div class="form-row-flex" style="align-items: center;">
            <span class="form-label-bold">Record Series:</span>
            
            @if($selectedSeriesTitle)
                <span class="selected-series-badge">
                    {{ $selectedSeriesTitle }}
                </span>
                <button type="button" class="btn-edit-series" wire:click="openSeriesModal">
                    <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                    <span>EDIT</span>
                </button>
            @else
                <button type="button" class="btn btn-primary btn-set-inline" wire:click="openSeriesModal">
                    SET
                </button>
            @endif
        </div>

        <!-- ===== Description ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Description:</span>
            <input type="text" class="form-input-custom" wire:model="description" placeholder="Enter detailed description...">
        </div>

        <!-- ===== Period Covered / Inclusive Dates ===== -->
        <div class="form-row-flex" style="align-items: center;">
            <span class="form-label-bold">Period Covered:</span>
            
            @if(count($this->formattedPeriods) > 0)
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    @foreach($this->formattedPeriods as $periodLabel)
                        <span class="selected-series-badge">
                            {{ $periodLabel }}
                        </span>
                    @endforeach
                    <button type="button" class="btn-edit-series" wire:click="openPeriodModal">
                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        <span>EDIT</span>
                    </button>
                </div>
            @else
                <button type="button" class="btn btn-primary btn-set-inline" wire:click="openPeriodModal">
                    ADD
                </button>
            @endif
        </div>

        <!-- ===== Volume ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Volume:</span>
            <input type="text" class="form-input-custom" wire:model="volume" placeholder="e.g., 5 boxes, 2.5 cubic meters">
        </div>

        <!-- ===== Records Medium ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Records Medium:</span>
            <select class="form-input-custom" wire:model="records_medium">
                <option value="" selected disabled>Select Records Medium...</option>
                @foreach($mediaList as $media)
                    <option value="{{ $media->id }}">{{ $media->medium_name }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Restrictions ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Restriction/s:</span>
            <select class="form-input-custom" wire:model="restriction">
                <option value="" selected disabled>Select Restriction...</option>
                @foreach($restrictionsList as $res)
                    <option value="{{ $res->restriction_value }}">{{ $res->restriction_value }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Location of Records ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Location of Records:</span>
            <input type="text" class="form-input-custom" wire:model="records_location" placeholder="e.g. On-site Storage Room 3B, Shelf 12">
        </div>

        <!-- ===== Frequency of Use ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Frequency of Use:</span>
            <select class="form-input-custom" wire:model="frequence_use">
                <option value="" selected disabled>Select Frequency of Use...</option>
                @foreach($frequenciesList as $freq)
                    <option value="{{ $freq->freq_type }}">{{ $freq->freq_type }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Time Value ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Time Value:</span>
            <select class="form-input-custom" wire:model="time_value">
                <option value="" selected disabled>Select Time Value...</option>
                @foreach($timeValuesList as $tv)
                    <option value="{{ $tv->char_value }}">{{ $tv->char_value }} - {{ $tv->description }}</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Utility Value ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Utility Value:</span>
            <select class="form-input-custom" wire:model="utility_value">
                <option value="" selected disabled>Select Utility Value...</option>
                @foreach($utilityValuesList as $uv)
                    <option value="{{ $uv->id }}">{{ $uv->utility_name }} ({{ $uv->description }})</option>
                @endforeach
            </select>
        </div>

        <!-- ===== Retention Period ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Retention Period:</span>
            <input type="text" class="form-input-custom" wire:model="retention_period" placeholder="e.g. 5 Years Active, 10 Years Storage">
        </div>

        <!-- ===== Disposition Provision ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Disposition Provision:</span>
            <input type="text" class="form-input-custom" wire:model="disposition_provision" placeholder="Enter disposition instructions or provisions...">
        </div>

        <!-- ===== File Attachment ===== -->
        <div class="form-row-flex">
            <span class="form-label-bold">Attach File:</span>
            <div style="flex:1;">
                <input type="file" wire:model="uploadedFile" style="font-size:13px;">
                <div wire:loading wire:target="uploadedFile" style="font-size:12px; color:#2563eb; margin-top:4px;">Uploading file...</div>
            </div>
        </div>

        <!-- Action Buttons Bar -->
        <div class="actions-bar">
            <button type="button" class="btn btn-primary" wire:click="saveDraft">
                SAVE DRAFT
            </button>

            <button type="button" class="btn btn-primary" wire:click="createRecord">
                CREATE RECORD
            </button>
        </div>
    </div>

    <!-- SELECT RECORD SERIES HIERARCHY MODAL CONTAINER -->
    @if($showSeriesModal)
        <div class="series-modal-overlay">
            <div class="series-modal-container">
                <div class="series-modal-header">
                    <h3>Select Record Series</h3>
                    <button type="button" class="close-modal-btn" wire:click="closeSeriesModal">&times;</button>
                </div>
                <div class="series-modal-body">
                    <!-- Main Parent Input with DTS-style Dropdown -->
                    <div class="form-group-modal" wire:click.outside="$set('showParentDropdown', false)">
                        <label for="parent">Parent Series Title:</label>
                        <input type="text" 
                               id="parent" 
                               class="modal-input-style" 
                               wire:model.live="parentSeriesTitle" 
                               wire:focus="$set('showParentDropdown', true)" 
                               placeholder="e.g. Receipts" 
                               autocomplete="off">

                        @if($showParentDropdown && count($parentSuggestions) > 0)
                            <div class="dts-autocomplete-dropdown">
                                @foreach($parentSuggestions as $suggestion)
                                    <div class="dts-autocomplete-item" wire:click="selectParentSuggestion('{{ addslashes($suggestion->series_title) }}')">
                                        {{ $suggestion->series_title }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Dynamic Subsections with DTS-style Autocomplete Dropdowns -->
                    @foreach($subsections as $index => $sub)
                        <div class="form-group-modal" 
                             wire:click.outside="$set('activeSubDropdownIndex', null)"
                             style="margin-left: {{ min(($index + 1) * 16, 64) }}px; border-left: 3px solid #2563eb; padding-left: 12px; margin-bottom: 14px;">
                            <label style="font-size: 11px; color: #2563eb;">
                                Subsection #{{ $index + 1 }} (Child of {{ $index === 0 ? ($parentSeriesTitle ?: 'Parent') : 'Subsection #' . $index }}):
                            </label>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" 
                                       id="subsection_{{ $index }}" 
                                       class="modal-input-style" 
                                       wire:model.live="subsections.{{ $index }}" 
                                       wire:focus="$set('activeSubDropdownIndex', {{ $index }})" 
                                       placeholder="e.g. {{ $index === 0 ? 'Official Receipts' : 'Tax Receipts' }}" 
                                       autocomplete="off">
                                <button type="button" class="btn-remove-sub" wire:click="removeSubsection({{ $index }})" title="Remove Subsection">&times;</button>
                            </div>

                            @if($activeSubDropdownIndex === $index && count($allSeriesSuggestions) > 0)
                                <div class="dts-autocomplete-dropdown">
                                    @foreach($allSeriesSuggestions as $suggestion)
                                        <div class="dts-autocomplete-item" wire:click="selectSubSuggestion({{ $index }}, '{{ addslashes($suggestion->series_title) }}')">
                                            {{ $suggestion->series_title }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <!-- Add Subsection Button -->
                    <div style="margin-top: 10px; margin-bottom: 20px;">
                        <button id="add" type="button" class="btn-add-subsection" wire:click="addSubsection">
                            + add subsection
                        </button>
                    </div>

                    <!-- Modal Actions Footer: Add Record | Cancel -->
                    <div class="modal-actions-footer">
                        <button type="button" class="btn btn-primary" wire:click="saveNewRecordSeries">
                            Add Record
                        </button>
                        <button type="button" class="btn-secondary-modal" wire:click="closeSeriesModal">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- PERIOD COVERED MODAL CONTAINER -->
    @if($showPeriodModal)
        <div class="series-modal-overlay">
            <div class="series-modal-container">
                <div class="series-modal-header">
                    <h3>Configure Period Covered</h3>
                    <button type="button" class="close-modal-btn" wire:click="closePeriodModal">&times;</button>
                </div>
                <div class="series-modal-body">
                    <div style="margin-bottom: 16px; font-size: 13px; color: #64748b;">
                        Add one or multiple period ranges covered by this record (e.g. Jan 2026 - Jan 2027, Jan 2029 - Jan 2030).
                    </div>

                    @foreach($periodsCovered as $index => $period)
                        <div class="period-range-row">
                            <div style="flex: 1;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Start Month & Year:</label>
                                <input type="month" class="modal-input-style" wire:model="periodsCovered.{{ $index }}.start_month">
                            </div>
                            <span style="font-weight: 700; color: #64748b; margin-top: 18px;">to</span>
                            <div style="flex: 1;">
                                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">End Month & Year:</label>
                                <input type="month" class="modal-input-style" wire:model="periodsCovered.{{ $index }}.end_month">
                            </div>
                            <button type="button" class="btn-remove-sub" style="margin-top: 18px;" wire:click="removePeriodRange({{ $index }})" title="Remove Period">&times;</button>
                        </div>
                    @endforeach

                    <!-- Add Period Button -->
                    <div style="margin-top: 12px; margin-bottom: 20px;">
                        <button type="button" class="btn-add-subsection" wire:click="addPeriodRange">
                            + add period range
                        </button>
                    </div>

                    <!-- Modal Actions Footer: Add Period | Cancel -->
                    <div class="modal-actions-footer">
                        <button type="button" class="btn btn-primary" wire:click="savePeriods">
                            Add Period
                        </button>
                        <button type="button" class="btn-secondary-modal" wire:click="closePeriodModal">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
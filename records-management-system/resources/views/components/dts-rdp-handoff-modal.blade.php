<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Services\DtsRdpIntakeService;

new class extends Component {
    public bool $isOpen = false;
    public mixed $transactionId = null;
    public string $controlNumber = '';
    public string $subject = '';

    // Form inputs (Locked & Pre-filled)
    public ?int $record_series_id = null;
    public string $series_title = '';
    public string $series_retention_info = '';
    public string $description = '';
    public ?int $records_medium = null;
    public string $restriction = 'Restricted';
    public string $frequence_use = 'Annually';
    public array $duplicate_offices = [];

    // Form inputs (Editable by user)
    public string $selected_date = '';
    public string $volume_amount = '';
    public string $volume_unit = 'Folder';
    public string $records_location = '';
    public array $utility_values = [];

    // Optional add office if unlocked
    public string $new_duplicate_office = '';

    // Validation & state feedback
    public string $validationError = '';

    // Available reference data
    public array $seriesOptions = [];
    public array $officesList = [];
    public array $mediaList = [];
    public array $restrictionsList = [];
    public array $frequenciesList = [];
    public array $utilitiesList = [];
    public array $volumeUnits = ['Folder', 'Folders', 'Pages', 'Box', 'Boxes', 'Bundle', 'Bundles', 'Volume(s)', 'Cubic Meter', 'Linear Meter'];

    public function mount(): void
    {
        $this->loadReferenceData();
    }

    public function loadReferenceData(): void
    {
        if (Schema::hasTable('rdp_record_series')) {
            $this->seriesOptions = DB::table('rdp_record_series')
                ->select('id', 'series_title', 'item_number', 'retention_period')
                ->orderBy('series_title', 'asc')
                ->limit(300)
                ->get()
                ->map(fn($s) => (array)$s)
                ->toArray();
        }

        if (Schema::hasTable('rdp_recorded_value')) {
            $this->mediaList = DB::table('rdp_recorded_value')->orderBy('medium_name', 'asc')->get()->map(fn($m) => (array)$m)->toArray();
        }

        if (Schema::hasTable('rdp_restriction_type')) {
            $this->restrictionsList = DB::table('rdp_restriction_type')->orderBy('restriction_value', 'asc')->get()->map(fn($r) => (array)$r)->toArray();
        }

        if (Schema::hasTable('rdp_frequence_use')) {
            $this->frequenciesList = DB::table('rdp_frequence_use')->orderBy('freq_type', 'asc')->get()->map(fn($f) => (array)$f)->toArray();
        }

        // Utility Values: Query rdp_utility_medium or use standard 4 NAP Form 1 values
        $utilities = [];
        if (Schema::hasTable('rdp_utility_medium')) {
            $utilities = DB::table('rdp_utility_medium')->orderBy('utility_name', 'asc')->get()->map(fn($u) => (array)$u)->toArray();
        }
        if (empty($utilities)) {
            $defaultNames = ['Administrative', 'Fiscal', 'Legal', 'Archival'];
            foreach ($defaultNames as $idx => $name) {
                $utilities[] = [
                    'id'           => $idx + 1,
                    'utility_name' => $name,
                    'description'  => $name,
                ];
            }
        }
        $this->utilitiesList = $utilities;

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        if (Schema::hasTable($officeTable)) {
            $this->officesList = DB::table($officeTable)
                ->where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
                ->orderBy('office_name', 'asc')
                ->get()
                ->map(fn($o) => (array)$o)
                ->toArray();
        }
    }

    #[On('open-dts-rdp-handoff')]
    public function openHandoff($transactionId = null, ...$rest): void
    {
        $this->resetForm();
        $this->loadReferenceData();

        // Extract transaction identifier from any format Livewire passes
        $resolved = null;
        if (is_array($transactionId)) {
            $resolved = $transactionId['transactionId'] ?? $transactionId['transaction_id'] ?? $transactionId['id'] ?? $transactionId['control_number'] ?? null;
            if (!$resolved && !empty($transactionId)) {
                $resolved = reset($transactionId);
            }
        } elseif (!empty($transactionId)) {
            $resolved = $transactionId;
        }

        if (empty($resolved) && !empty($rest)) {
            foreach ($rest as $arg) {
                if (is_array($arg)) {
                    $resolved = $arg['transactionId'] ?? $arg['transaction_id'] ?? $arg['id'] ?? $arg['control_number'] ?? null;
                    if ($resolved) break;
                } elseif (!empty($arg)) {
                    $resolved = $arg;
                    break;
                }
            }
        }

        $this->transactionId = $resolved;
        $this->loadData();
        $this->isOpen = true;
    }

    public function loadData(): void
    {
        if (empty($this->transactionId)) {
            return;
        }

        $user = auth()->user();
        $payload = DtsRdpIntakeService::prepareHandoffPayload($this->transactionId, $user);

        if (!$payload) {
            return;
        }

        $this->transactionId = $payload['transaction_id'] ?? $this->transactionId;
        $this->controlNumber = $payload['control_number'] ?? '';
        $this->subject = $payload['subject'] ?? '';
        $this->description = $payload['subject'] ?? '';
        $this->selected_date = Carbon::now()->format('Y-m-d');
        $this->records_medium = $payload['paper_medium_id'] ?? null;
        $this->restriction = 'Restricted';
        $this->frequence_use = 'Annually'; // Locked default: Annually
        $this->duplicate_offices = $payload['duplicate_offices'] ?? [];

        // Required fields to be filled by user
        $this->volume_amount = '';
        $this->volume_unit = 'Folder';
        $this->records_location = '';
        $this->utility_values = [];

        // Set series if found in payload
        if (!empty($payload['contin_rdp_id'])) {
            $this->selectSeries((int)$payload['contin_rdp_id']);
        } elseif (!empty($payload['series_details']['id'])) {
            $this->selectSeries((int)$payload['series_details']['id']);
        }

        // Default medium to Paper if not set
        if (!$this->records_medium && !empty($this->mediaList)) {
            foreach ($this->mediaList as $m) {
                if (strtolower($m['medium_name'] ?? '') === 'paper') {
                    $this->records_medium = (int)$m['id'];
                    break;
                }
            }
        }
    }

    public function selectSeries(int $id): void
    {
        $this->record_series_id = $id;

        if (Schema::hasTable('rdp_record_series')) {
            $s = DB::table('rdp_record_series')->where('id', $id)->first();
            if ($s) {
                $this->series_title = $s->series_title;
                $retText = '';
                if ($s->retention_period && Schema::hasTable('rdp_retention_period')) {
                    $rp = DB::table('rdp_retention_period')->where('id', $s->retention_period)->first();
                    if ($rp) {
                        $retText = "Active: {$rp->active_period} | Storage: {$rp->storage_period} | Total: {$rp->total_period}";
                    }
                }
                $this->series_retention_info = $retText;
            }
        }
    }

    public function toggleUtility(int $id): void
    {
        if (in_array($id, $this->utility_values)) {
            $this->utility_values = array_values(array_diff($this->utility_values, [$id]));
        } else {
            $this->utility_values[] = $id;
        }
    }

    public function addDuplicateOffice(): void
    {
        $code = trim($this->new_duplicate_office);
        if (!empty($code) && !in_array($code, $this->duplicate_offices)) {
            $this->duplicate_offices[] = $code;
        }
        $this->new_duplicate_office = '';
    }

    public function removeDuplicateOffice(string $code): void
    {
        $this->duplicate_offices = array_values(array_diff($this->duplicate_offices, [$code]));
    }

    public function getIsCompleteProperty(): bool
    {
        return !empty($this->record_series_id)
            && !empty(trim($this->description))
            && !empty(trim($this->selected_date))
            && !empty(trim($this->volume_amount))
            && !empty($this->records_medium)
            && !empty(trim($this->restriction))
            && !empty(trim($this->records_location))
            && !empty(trim($this->frequence_use ?? ''))
            && !empty($this->utility_values);
    }

    public function submit(): void
    {
        if ($this->isComplete) {
            $this->submitDirectToNap();
        } else {
            $this->submitIncomplete();
        }
    }

    public function submitDirectToNap(): void
    {
        $this->validationError = '';

        $missing = [];
        if (!$this->record_series_id) $missing[] = 'Record Series';
        if (empty(trim($this->description))) $missing[] = 'Description';
        if (empty(trim($this->selected_date))) $missing[] = 'Selected Date';
        if (empty(trim($this->volume_amount))) $missing[] = 'Volume Amount';
        if (!$this->records_medium) $missing[] = 'Records Medium';
        if (empty(trim($this->restriction))) $missing[] = 'Restriction';
        if (empty(trim($this->records_location))) $missing[] = 'Records Location';
        if (empty(trim($this->frequence_use ?? ''))) $missing[] = 'Frequency of Use';
        if (empty($this->utility_values)) $missing[] = 'Utility Value';

        if (!empty($missing)) {
            $this->validationError = 'Missing: ' . implode(', ', $missing);
            return;
        }

        $formattedVolume = trim($this->volume_amount . ' ' . $this->volume_unit);

        $data = [
            'transaction_id'    => $this->transactionId,
            'control_number'    => $this->controlNumber,
            'subject'           => $this->subject ?: $this->description,
            'record_series_id'  => (int)$this->record_series_id,
            'description'       => trim($this->description),
            'date_covered'      => $this->selected_date,
            'volume'            => $formattedVolume,
            'records_medium_id' => (int)$this->records_medium,
            'restriction_name'  => $this->restriction,
            'records_location'  => trim($this->records_location),
            'frequence_use'     => $this->frequence_use ?: 'Annually',
            'duplicate_offices' => $this->duplicate_offices,
            'utility_values'    => $this->utility_values,
            'time_value'        => 'T',
        ];

        $res = DtsRdpIntakeService::recordDirectToNap($data, auth()->user());

        if (!empty($res['success'])) {
            $this->isOpen = false;
            $this->dispatch('toast', message: 'Transaction successfully recorded directly into NAP Form 1!', type: 'success');
        } else {
            $this->validationError = $res['message'] ?? 'Failed to record to NAP Form 1.';
        }
    }

    public function submitIncomplete(): void
    {
        $this->validationError = '';
        $amt = trim((string)($this->volume_amount ?? ''));
        $formattedVolume = !empty($amt) ? trim($amt . ' ' . ($this->volume_unit ?: 'Folder')) : null;

        $data = [
            'transaction_id'    => $this->transactionId,
            'control_number'    => $this->controlNumber,
            'subject'           => $this->subject ?: $this->description,
            'record_series_id'  => $this->record_series_id ? (int)$this->record_series_id : null,
            'description'       => trim($this->description),
            'date_covered'      => $this->selected_date ?: null,
            'volume'            => $formattedVolume,
            'volume_amount'     => !empty($amt) ? $amt : null,
            'volume_unit'       => !empty($amt) ? ($this->volume_unit ?: 'Folder') : null,
            'records_medium_id' => $this->records_medium ? (int)$this->records_medium : null,
            'restriction_name'  => $this->restriction ?: 'Restricted',
            'records_location'  => trim($this->records_location ?? '') ?: null,
            'frequence_use'     => $this->frequence_use ?: 'Annually',
            'duplicate_offices' => $this->duplicate_offices,
            'utility_values'    => $this->utility_values ?: [],
            'time_value'        => 'T',
        ];

        $res = DtsRdpIntakeService::recordIncompleteToIntake($data, auth()->user());

        if (!empty($res['success'])) {
            $this->isOpen = false;
            $this->dispatch('toast', message: 'Transaction saved to RDP Received Documents with entered information.', type: 'info');
        } else {
            $this->validationError = $res['message'] ?? 'Failed to send to Received Documents.';
        }
    }

    public function closeModal(): void
    {
        // When user skips or cancels, safely preserve transaction in Received Documents
        if ($this->transactionId) {
            $this->submitIncomplete();
        }
        $this->isOpen = false;
    }

    public function resetForm(): void
    {
        $this->transactionId = null;
        $this->controlNumber = '';
        $this->subject = '';
        $this->record_series_id = null;
        $this->series_title = '';
        $this->series_retention_info = '';
        $this->description = '';
        $this->selected_date = '';
        $this->volume_amount = '';
        $this->volume_unit = 'Folder';
        $this->records_medium = null;
        $this->restriction = 'Restricted';
        $this->records_location = '';
        $this->frequence_use = 'Annually';
        $this->duplicate_offices = [];
        $this->new_duplicate_office = '';
        $this->utility_values = [];
        $this->validationError = '';
    }
};
?>

<div>
    @if ($isOpen)
        <div style="position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(5px); display: flex; align-items: center; justify-content: center; padding: 16px; overflow-y: auto;">
            <div style="background: #ffffff; width: 100%; max-width: 740px; max-height: 90vh; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); display: flex; flex-direction: column; overflow: hidden; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; animation: fadeIn 0.2s ease-out; box-sizing: border-box;">
                
                <!-- Modal Header -->
                <div style="padding: 18px 24px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; box-sizing: border-box;">
                    <div style="display: flex; align-items: center; gap: 14px; min-width: 0;">
                        <div style="width: 44px; height: 44px; border-radius: 12px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: inset 0 0 0 1px #bae6fd; flex-shrink: 0;">
                            <i class="fa-solid fa-folder-tree"></i>
                        </div>
                        <div style="min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0f172a;">Proceed this Transaction to RDP?</h3>
                                <span style="font-size: 11px; font-weight: 700; background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 999px;">Completed</span>
                            </div>
                            <span style="font-size: 12.5px; color: #64748b; font-weight: 500; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                Control No: <strong style="color: #1e293b;">{{ $controlNumber ?: 'DTS Document' }}</strong> &bull; Records Disposition Program
                            </span>
                        </div>
                    </div>
                    <button type="button" wire:click="closeModal" style="background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer; border-radius: 50%; width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; transition: background 0.15s; flex-shrink: 0;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='transparent'">&times;</button>
                </div>

                <!-- Modal Body (Scrollable) -->
                <div style="padding: 22px 26px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 18px; box-sizing: border-box;">
                    
                    <!-- Guide Callout Banner -->
                    <div style="background: {{ $this->isComplete ? '#f0fdf4' : '#f8fafc' }}; border: 1px solid {{ $this->isComplete ? '#bbf7d0' : '#e2e8f0' }}; border-radius: 12px; padding: 14px 16px; display: flex; gap: 12px; align-items: flex-start; transition: all 0.2s; box-sizing: border-box;">
                        <i class="fa-solid {{ $this->isComplete ? 'fa-circle-check' : 'fa-circle-info' }}" style="color: {{ $this->isComplete ? '#16a34a' : '#0284c7' }}; font-size: 16px; margin-top: 2px; flex-shrink: 0;"></i>
                        <div style="font-size: 12.5px; color: {{ $this->isComplete ? '#166534' : '#334155' }}; line-height: 1.5;">
                            @if ($this->isComplete)
                                <strong>All required information is complete!</strong> Click the green <strong>Submit</strong> button below to record directly to <strong>NAP Form 1</strong>.
                            @else
                                <strong>System defaults & pre-configured fields are locked.</strong> Fill out the remaining empty fields (<strong>Volume</strong>, <strong>Location</strong>, and <strong>Utility Value</strong>) to submit directly to <strong>NAP Form 1</strong>, or click <strong>Submit to Received Documents</strong> to finish later.
                            @endif
                        </div>
                    </div>

                    @if (!empty($validationError))
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 14px; font-size: 12.5px; color: #b91c1c; display: flex; gap: 10px; align-items: center; box-sizing: border-box;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>{{ $validationError }}</div>
                        </div>
                    @endif

                    <!-- Grid Form Fields -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; box-sizing: border-box;">
                        
                        <!-- 1. Record Series (Full Row - LOCKED) -->
                        <div style="grid-column: 1 / -1; box-sizing: border-box;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <span>Record Series</span>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #e2e8f0;">
                                        <i class="fa-solid fa-lock" style="font-size: 9px; color: #64748b;"></i> LOCKED
                                    </span>
                                </label>
                                <span style="font-size: 11px; color: #64748b; font-style: italic;">Preconfigured in Flow</span>
                            </div>
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px; box-sizing: border-box;">
                                <div style="min-width: 0;">
                                    <div style="font-size: 13.5px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                        <span>{{ $series_title ?: 'Standard General Record Series' }}</span>
                                        <span style="font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-weight: 800;">AUTO-MATCHED</span>
                                    </div>
                                    @if (!empty($series_retention_info))
                                        <div style="font-size: 11px; color: #64748b; margin-top: 3px;">{{ $series_retention_info }}</div>
                                    @endif
                                </div>
                                <div style="color: #64748b; font-size: 14px; flex-shrink: 0; padding-left: 10px;">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Description / Subject (Full Row - LOCKED) -->
                        <div style="grid-column: 1 / -1; box-sizing: border-box;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <span>Description / Subject</span>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #e2e8f0;">
                                        <i class="fa-solid fa-lock" style="font-size: 9px; color: #64748b;"></i> LOCKED
                                    </span>
                                </label>
                                <span style="font-size: 11px; color: #64748b; font-style: italic;">From DTS Transaction</span>
                            </div>
                            <div style="position: relative; box-sizing: border-box;">
                                <textarea wire:model="description" readonly rows="2" style="width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: inherit; background: #f8fafc; color: #334155; cursor: not-allowed; resize: none;"></textarea>
                                <div style="position: absolute; right: 12px; top: 10px; color: #94a3b8; font-size: 13px;">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Selected Date / Date Covered (Editable) -->
                        <div style="box-sizing: border-box;">
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                Selected Date / Date Covered <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="date" wire:model.live="selected_date" style="width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #ffffff;">
                        </div>

                        <!-- 4. Volume Amount & Unit (Editable - Required) -->
                        <div style="box-sizing: border-box;">
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                Volume Amount & Unit <span style="color: #ef4444;">*</span>
                            </label>
                            <div style="display: flex; gap: 8px; box-sizing: border-box; width: 100%;">
                                <input type="number" step="any" min="0.1" wire:model.live.debounce.200ms="volume_amount" placeholder="E.g. 1" style="width: 90px; flex-shrink: 0; box-sizing: border-box; padding: 9px 12px; border: 1.5px solid {{ empty($volume_amount) ? '#f87171' : '#cbd5e1' }}; border-radius: 8px; font-size: 13px; background: {{ empty($volume_amount) ? '#fff7f7' : '#ffffff' }}; font-weight: 600;">
                                <select wire:model.live="volume_unit" style="flex: 1; min-width: 0; box-sizing: border-box; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #ffffff;">
                                    @foreach($volumeUnits as $unit)
                                        <option value="{{ $unit }}">{{ $unit }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <!-- 5. Records Medium (LOCKED - Default: Paper) -->
                        <div style="box-sizing: border-box;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <span>Records Medium</span>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #e2e8f0;">
                                        <i class="fa-solid fa-lock" style="font-size: 9px; color: #64748b;"></i> LOCKED
                                    </span>
                                </label>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                                <span style="font-size: 13px; font-weight: 600; color: #334155; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-file-lines" style="color: #64748b;"></i> Paper (Physical Records)
                                </span>
                                <i class="fa-solid fa-lock" style="color: #94a3b8; font-size: 12px;"></i>
                            </div>
                        </div>

                        <!-- 6. Restriction / Access (LOCKED - Default: Restricted) -->
                        <div style="box-sizing: border-box;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <span>Restriction / Access</span>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #e2e8f0;">
                                        <i class="fa-solid fa-lock" style="font-size: 9px; color: #64748b;"></i> LOCKED
                                    </span>
                                </label>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                                <span style="font-size: 13px; font-weight: 600; color: #334155; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-shield-halved" style="color: #64748b;"></i> Restricted
                                </span>
                                <i class="fa-solid fa-lock" style="color: #94a3b8; font-size: 12px;"></i>
                            </div>
                        </div>

                        <!-- 7. Records Location (Editable - Required) -->
                        <div style="box-sizing: border-box;">
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                Records Location <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="text" wire:model.live.debounce.200ms="records_location" placeholder="E.g. Cabinet 2, Shelf B" style="width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1.5px solid {{ empty($records_location) ? '#f87171' : '#cbd5e1' }}; border-radius: 8px; font-size: 13px; background: {{ empty($records_location) ? '#fff7f7' : '#ffffff' }}; font-weight: 500;">
                        </div>

                        <!-- 8. Frequency of Use (LOCKED - Default: Annually) -->
                        <div style="box-sizing: border-box;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <span>Frequency of Use</span>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #e2e8f0;">
                                        <i class="fa-solid fa-lock" style="font-size: 9px; color: #64748b;"></i> LOCKED
                                    </span>
                                </label>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box;">
                                <span style="font-size: 13px; font-weight: 600; color: #334155; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-calendar" style="color: #64748b;"></i> Annually
                                </span>
                                <i class="fa-solid fa-lock" style="color: #94a3b8; font-size: 12px;"></i>
                            </div>
                        </div>

                        <!-- 9. Duplicate Copies / Copy Furnished (Full Row - LOCKED) -->
                        <div style="grid-column: 1 / -1; box-sizing: border-box;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <span>Duplicate Copies / Copy Furnished</span>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; border: 1px solid #e2e8f0;">
                                        <i class="fa-solid fa-lock" style="font-size: 9px; color: #64748b;"></i> LOCKED
                                    </span>
                                </label>
                                <span style="font-size: 11px; color: #64748b; font-style: italic;">From Transaction Flow</span>
                            </div>
                            
                            <!-- Badges Box (clean, inside the box, no overflow) -->
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; padding: 10px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; width: 100%; align-items: center;">
                                @forelse($duplicate_offices as $dupCode)
                                    <span style="display: inline-flex; align-items: center; gap: 6px; background: #e2e8f0; color: #1e293b; padding: 5px 12px; border-radius: 6px; font-size: 12.5px; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                                        <i class="fa-solid fa-building" style="font-size: 11px; color: #64748b;"></i>
                                        <span>{{ $dupCode }}</span>
                                    </span>
                                @empty
                                    <span style="font-size: 12.5px; color: #94a3b8; font-style: italic;">No duplicate copies assigned</span>
                                @endforelse
                                <div style="margin-left: auto; color: #94a3b8; font-size: 12px;">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                            </div>
                        </div>

                        <!-- 10. Utility Value (Full Row - Editable Required) -->
                        <div style="grid-column: 1 / -1; box-sizing: border-box;">
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                                Utility Value <span style="color: #ef4444;">*</span>
                            </label>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px; box-sizing: border-box; width: 100%;">
                                @foreach($utilitiesList as $u)
                                    @php $isSelected = in_array($u['id'], $utility_values); @endphp
                                    <button type="button" wire:click="toggleUtility({{ $u['id'] }})" style="padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1.5px solid {{ $isSelected ? '#16a34a' : '#cbd5e1' }}; background: {{ $isSelected ? '#dcfce7' : '#ffffff' }}; color: {{ $isSelected ? '#15803d' : '#475569' }}; display: flex; align-items: center; gap: 8px; transition: all 0.15s; box-shadow: {{ $isSelected ? '0 2px 4px rgba(22, 163, 74, 0.15)' : 'none' }};" onmouseover="if (!{{ $isSelected ? 1 : 0 }}) this.style.borderColor='#94a3b8'" onmouseout="if (!{{ $isSelected ? 1 : 0 }}) this.style.borderColor='#cbd5e1'">
                                        <i class="fa-solid {{ $isSelected ? 'fa-square-check' : 'fa-square' }}"></i>
                                        <span>{{ $u['utility_name'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                            @if(empty($utility_values))
                                <div style="font-size: 11.5px; color: #ef4444; margin-top: 5px;">* Select at least one utility value to complete for NAP Form 1</div>
                            @endif
                        </div>

                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; gap: 12px; box-sizing: border-box;">
                    <button type="button" wire:click="closeModal" style="background: none; border: none; font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer; text-decoration: underline;">
                        Skip / Save as Pending
                    </button>

                    <!-- Combined Smart Switching Button -->
                    <div>
                        @if ($this->isComplete)
                            <!-- COMPLETE: Green Submit button -> records directly to NAP Form 1 -->
                            <button type="button" wire:click="submit" style="padding: 11px 26px; border-radius: 9px; border: none; background: #16a34a; color: #ffffff; font-size: 13.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3); transition: background 0.15s;" onmouseover="this.style.backgroundColor='#15803d'" onmouseout="this.style.backgroundColor='#16a34a'">
                                <i class="fa-solid fa-check"></i>
                                <span>Submit</span>
                            </button>
                        @else
                            <!-- INCOMPLETE: White Submit to Received Documents button -> saves as pending received document -->
                            <button type="button" wire:click="submit" style="padding: 11px 22px; border-radius: 9px; border: 1.5px solid #cbd5e1; background: #ffffff; color: #334155; font-size: 13.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f8fafc'; this.style.borderColor='#94a3b8'" onmouseout="this.style.backgroundColor='#ffffff'; this.style.borderColor='#cbd5e1'">
                                <i class="fa-solid fa-inbox" style="color: #0284c7;"></i>
                                <span>Submit to Received Documents</span>
                            </button>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    @endif
</div>

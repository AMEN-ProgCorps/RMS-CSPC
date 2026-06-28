<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Application Letter')] class extends Component {
    public bool $enableBeta = false;
    public string $availabilityMessage = '';
    public ?bool $isAvailable = null;

    public string $seq_number = '';
    public string $type_of_document = '';
    public string $applicant_name = '';
    public string $position = '';
    public string $unit_college = '';
    public string $transaction_flow = '';
    public string $copy_furnished = 'Yes';

    public array $cf_selected_offices = [];
    public string $cf_search = '';

    public array $offices = [];
    public array $flows = [];

    // Flow Diagram modal states
    public bool $showFlowModal = false;
    public array $flow_offices = [];
    public ?int $selected_gap_index = null;
    public string $insert_office_code = '';
    public string $insert_office_search = '';

    public function mount(): void
    {
        $this->enableBeta = session('enable_beta', false);
        $this->offices = DB::table('office')
            ->where('is_active', true)
            ->where('office_code', '!=', 'ORIGIN')
            ->orderBy('office_name')
            ->get()
            ->map(fn($o) => (array)$o)
            ->toArray();

        $this->flows = DB::table('dts_transaction_flow')
            ->where('is_active', true)
            ->orderBy('flow_name')
            ->get()
            ->map(fn($f) => (array)$f)
            ->toArray();

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($userOfficeCode) {
            $this->unit_college = $userOfficeCode;
        }
    }

    public function updatedEnableBeta($value): void
    {
        session(['enable_beta' => $value]);
    }

    public function checkAvailability(): void
    {
        if (empty($this->seq_number)) {
            $this->availabilityMessage = 'Please enter a sequence number.';
            $this->isAvailable = false;
            return;
        }

        $controlNumber = 'APL-' . strtoupper(now()->format('Y-M')) . '-' . $this->seq_number;

        $exists = DB::table('dts_transaction_details')
            ->where('control_number', $controlNumber)
            ->exists();

        if ($exists) {
            $this->availabilityMessage = 'Control number is already taken.';
            $this->isAvailable = false;
        } else {
            $this->availabilityMessage = 'Control number is available!';
            $this->isAvailable = true;
        }
    }

    public function updatedSeqNumber(): void
    {
        $this->availabilityMessage = '';
        $this->isAvailable = null;
    }

    public function updatedTransactionFlow($value): void
    {
        $this->selected_gap_index = null;
        $this->insert_office_code = '';

        if (empty($value)) {
            $this->flow_offices = [];
            return;
        }

        $flow = DB::table('dts_transaction_flow')->where('flow_code', $value)->first();
        if ($flow) {
            $this->flow_offices = DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->orderBy('sequence_ranking', 'asc')
                ->pluck('office_code')
                ->toArray();

            // Load predefined copy furnished offices if any
            $predefinedCF = DB::table('dts_copy_filled_transaction')
                ->where('control_num', $value)
                ->first();

            if ($predefinedCF) {
                $this->cf_selected_offices = DB::table('dts_copy_filled_to_office')
                    ->where('control_id', $predefinedCF->assign_offices_id)
                    ->pluck('office_code')
                    ->toArray();
                $this->copy_furnished = 'Yes';
            } else {
                $this->cf_selected_offices = [];
                $this->copy_furnished = 'Yes';
            }
        } else {
            $this->flow_offices = [];
        }
    }

    public function openFlowDiagram(): void
    {
        if (!empty($this->transaction_flow)) {
            $this->selected_gap_index = null;
            $this->insert_office_code = '';
            $this->insert_office_search = '';
            $this->showFlowModal = true;
        }
    }

    public function selectOfficeForInsert(string $code, string $name): void
    {
        $this->insert_office_code = $code;
        $this->insert_office_search = "$name ($code)";
    }

    public function cancelInsert(): void
    {
        $this->selected_gap_index = null;
        $this->insert_office_code = '';
        $this->insert_office_search = '';
    }

    public function insertOffice(): void
    {
        if ($this->selected_gap_index !== null && !empty($this->insert_office_code)) {
            array_splice($this->flow_offices, $this->selected_gap_index, 0, $this->insert_office_code);
            $this->selected_gap_index = null;
            $this->insert_office_code = '';
            $this->insert_office_search = '';
        }
    }

    public function removeOfficeFromFlow(int $index): void
    {
        unset($this->flow_offices[$index]);
        $this->flow_offices = array_values($this->flow_offices);
        $this->selected_gap_index = null;
    }

    public function resetFlowToDefault(): void
    {
        $this->updatedTransactionFlow($this->transaction_flow);
    }

    public function selectCfOffice(string $officeCode): void
    {
        if (!in_array($officeCode, $this->cf_selected_offices)) {
            $this->cf_selected_offices[] = $officeCode;
        }
        $this->cf_search = '';
    }

    public ?string $generatedQrCode = null;

    private function splitString(string $str): array
    {
        $len = strlen($str);
        if ($len > 2) {
            return [
                'first' => $str[0],
                'last' => $str[$len - 1],
                'center' => substr($str, 1, $len - 2)
            ];
        } elseif ($len === 2) {
            return [
                'first' => $str[0],
                'last' => $str[1],
                'center' => ''
            ];
        } elseif ($len === 1) {
            return [
                'first' => '',
                'last' => '',
                'center' => $str
            ];
        } else {
            return [
                'first' => '',
                'last' => '',
                'center' => ''
            ];
        }
    }

    private function combine(string $a, string $b): string
    {
        if ($a === '') return $b;
        if ($b === '') return $a;

        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === 1 && $lenB === 1) {
            if ($a === $b) return $a;
            $aIsAlpha = ctype_alpha($a);
            $bIsAlpha = ctype_alpha($b);
            if ($aIsAlpha && !$bIsAlpha) return $a . $b;
            if (!$aIsAlpha && $bIsAlpha) return $b . $a;
            return $a . $b;
        }

        // Identify longer and shorter
        if ($lenA >= $lenB) {
            $L = $a;
            $S = $b;
        } else {
            $L = $b;
            $S = $a;
        }

        $lenL = strlen($L);
        $lenS = strlen($S);

        if ($lenL === $lenS && $lenL % 2 === 0) {
            $res = '';
            for ($i = 0; $i < $lenL; $i++) {
                $res .= $a[$i] . $b[$i];
            }
            return $res;
        }

        $splitA = $this->splitString($a);
        $splitB = $this->splitString($b);
        $splitL = $lenA >= $lenB ? $splitA : $splitB;
        $splitS = $lenA >= $lenB ? $splitB : $splitA;

        // Apply deduplication rule specifically for MONTH + (YEAR + TYPE)
        if ($lenS === 3 && $lenL === 8 && $splitS['first'] === 'M' && str_starts_with($splitL['center'], 'M')) {
            $splitL['center'] = substr($splitL['center'], 1);
        }

        $part1 = $splitA['first'] . $splitB['first'];
        $part3 = $splitL['last'] . $splitS['last'];

        $L_center = $splitL['center'];
        $S_center = $splitS['center'];

        $part2 = '';
        if ($L_center !== '' || $S_center !== '') {
            $lenLc = strlen($L_center);
            if ($lenLc > 0 && $lenLc % 2 === 0) {
                $mid = (int)($lenLc / 2);
                $left = substr($L_center, 0, $mid);
                $right = substr($L_center, $mid);
                $part2 = $left . $S_center . $right;
            } else {
                $part2 = $this->combine($L_center, $S_center);
            }
        }

        return $part1 . $part2 . $part3;
    }

    public function generateQrCode(): void
    {
        if ($this->generatedQrCode) {
            return;
        }

        if (empty($this->seq_number)) {
            $this->addError('seq_number', 'Please enter the Sequence Number first before generating the QR Code.');
            return;
        }

        // Prepare the Hacore formula variables
        $transCode = 'D' . preg_replace('/[^0-9]/', '', $this->seq_number);
        $month = strtoupper(now()->format('M'));
        $year = now()->format('Y');
        
        $type = !empty($this->type_of_document) ? strtoupper(trim($this->type_of_document)) : 'APPL';
        $type = preg_replace('/[^A-Z0-9]/', '', $type);
        if (strlen($type) === 0) {
            $type = 'APPL';
        }
        $type = substr($type, 0, 4);

        // Run the Hacore formula
        $rawCode = $this->combine($transCode, $this->combine($month, $this->combine($year, $type)));
        
        // Pad with '0' to make length a multiple of 4
        $len = strlen($rawCode);
        $remainder = $len % 4;
        if ($remainder !== 0) {
            $rawCode .= str_repeat('0', 4 - $remainder);
        }
        
        // Format to groups of 4 separated by dash
        $formatted = implode('-', str_split($rawCode, 4));
        $this->generatedQrCode = $formatted;

        // Register in the qr code table with 'not used' status
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $this->generatedQrCode],
            [
                'qr_status' => 'not used',
                'created_at' => now(),
            ]
        );
    }

    public function removeCfOffice(int $index): void
    {
        unset($this->cf_selected_offices[$index]);
        $this->cf_selected_offices = array_values($this->cf_selected_offices);
    }

    public function save()
    {
        $this->validate([
            'seq_number' => 'required|string|max:50',
            'type_of_document' => 'required|string|max:255',
            'applicant_name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'unit_college' => 'required|string|exists:office,office_code',
            'transaction_flow' => 'required|string|exists:dts_transaction_flow,flow_code',
            'copy_furnished' => 'required|string|in:Yes,No',
            'cf_selected_offices' => 'required_if:copy_furnished,Yes|array',
        ]);

        if (count($this->flow_offices) === 0) {
            $this->addError('transaction_flow', 'The transaction flow must contain at least one office.');
            return;
        }

        if (!$this->generatedQrCode) {
            $this->addError('seq_number', 'Please generate a QR Code first.');
            return;
        }

        $controlNumber = 'APL-' . strtoupper(now()->format('Y-M')) . '-' . $this->seq_number;

        // Check if control number already exists
        $exists = DB::table('dts_transaction_details')
            ->where('control_number', $controlNumber)
            ->exists();
        if ($exists) {
            $this->addError('seq_number', 'This control number is already taken.');
            return;
        }

        DB::beginTransaction();
        try {
            // Mark the QR code as used
            DB::table('dts_qr_code')
                ->where('code_id', $this->generatedQrCode)
                ->update(['qr_status' => 'used']);

            // Check if flow sequence was modified
            $flowCode = $this->transaction_flow;
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->transaction_flow)->first();
            
            // Resolve dynamic ORIGIN to the creator's office
            if (count($this->flow_offices) > 0 && $this->flow_offices[0] === 'ORIGIN') {
                $this->flow_offices[0] = $this->unit_college;
            }

            $defaultOffices = [];
            if ($flow) {
                $defaultOffices = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->orderBy('sequence_ranking', 'asc')
                    ->pluck('office_code')
                    ->toArray();

                if (count($defaultOffices) > 0 && $defaultOffices[0] === 'ORIGIN') {
                    $defaultOffices[0] = $this->unit_college;
                }
            }

            if ($defaultOffices !== $this->flow_offices) {
                // Generate custom flow
                $flowCode = 'FLOW-CUSTOM-' . strtoupper(Str::random(10));
                $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
                $newFlowId = $maxId + 1;

                DB::table('dts_transaction_flow')->insert([
                    'flow_code' => $flowCode,
                    'flow_name' => 'Modified Flow for ' . $controlNumber,
                    'id' => $newFlowId,
                    'is_active' => 1,
                ]);

                foreach ($this->flow_offices as $rank => $officeCode) {
                    DB::table('dts_sequence_list')->insert([
                        'control_id' => $newFlowId,
                        'sequence_ranking' => $rank + 1,
                        'office_code' => $officeCode,
                    ]);
                }
            }

            $currentOffice = $this->flow_offices[0] ?? $this->unit_college;

            $qrCodeId = $this->generatedQrCode;

            // Create document data record for type of document
            $docDir = null;
            if (!empty($this->type_of_document)) {
                $existingDoc = DB::table('dts_document_data')
                    ->where('document_name', $this->type_of_document)
                    ->first();
                if ($existingDoc) {
                    $docDir = $existingDoc->document_path;
                } else {
                    $docId = 'DOC-' . strtoupper(Str::random(8));
                    $docDir = 'docs/' . Str::slug($this->type_of_document) . '-' . time() . '.pdf';
                    DB::table('dts_document_data')->insert([
                        'document_id' => $docId,
                        'document_name' => $this->type_of_document,
                        'document_path' => $docDir,
                        'date_added' => now(),
                        'date_modified' => now(),
                        'date_deleted' => now(),
                    ]);
                }
            }

            $transactionId = 'TRANS-' . strtoupper(Str::random(10));

            // Insert into dts_transactions
            DB::table('dts_transactions')->insert([
                'transaction_id' => $transactionId,
                'enable_notif' => 1,
                'trans_type' => 'others',
                'doc_dir' => $docDir,
                'qr_code' => $qrCodeId,
                'current_office' => $currentOffice,
                'status' => 'ongoing',
                'sequence' => 1,
            ]);

            $copyFilledId = null;
            // Insert Copy Furnished records into dts_copy_filled_transaction and dts_copy_filled_to_office tables
            if ($this->copy_furnished === 'Yes' && count($this->cf_selected_offices) > 0) {
                $assignOfficesId = (DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                $copyFilledId = DB::table('dts_copy_filled_transaction')->insertGetId([
                    'control_num' => $controlNumber,
                    'total_office' => count($this->cf_selected_offices),
                    'assign_offices_id' => $assignOfficesId,
                    'data_created' => now(),
                    'date_modified' => now(),
                ]);

                foreach ($this->cf_selected_offices as $cfOffice) {
                    DB::table('dts_copy_filled_to_office')->insert([
                        'control_id' => $assignOfficesId,
                        'office_code' => $cfOffice,
                    ]);
                }
            }

            // Insert into dts_transaction_details
            DB::table('dts_transaction_details')->insert([
                'id' => $transactionId,
                'type' => 'others',
                'created_by' => auth()->id(),
                'originated_from' => $this->unit_college,
                'requestor_name' => $this->applicant_name,
                'subject' => 'Application for ' . $this->position,
                'classification' => 'Simple',
                'action_needed' => 'For action',
                'current_office_hold' => $currentOffice,
                'status' => 'ongoing',
                'document_password' => null,
                'email_access' => null,
                'transaction_flow' => $flowCode,
                'is_active' => 1,
                'date_created' => now(),
                'control_number' => $controlNumber,
                'copy_filled_id' => $copyFilledId,
            ]);

            // Log the creation
            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $transactionId,
                'office_code' => $this->unit_college,
                'type' => 'created',
                'date_in' => now(),
                'date_out' => null,
                'notes' => 'Created application document transaction',
                'performed_by' => auth()->id(),
            ]);

            DB::commit();

            session()->flash('message', 'Transaction created successfully!');
            return $this->redirectRoute('dts');

        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Error creating transaction: ' . $e->getMessage());
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/dts/create.css'])
@endpush

<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2>Application Document</h2>
        
        <!-- Enable Beta Toggle -->
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px;">Enable Beta</span>
            <label class="switch" style="position: relative; display: inline-block; width: 44px; height: 22px; margin: 0;">
                <input type="checkbox" wire:model.live="enableBeta" style="opacity: 0; width: 0; height: 0;">
                <span class="slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px;"></span>
            </label>
        </div>
    </div>

    @if($enableBeta)
        <!-- Beta Layout Form -->
        <form wire:submit.prevent="save">
            <div class="beta-layout-container">
                <!-- Generated Control Number Identity Badge -->
                <div class="beta-control-badge">
                    <span class="badge-label">Generated Control Number</span>
                    <div style="display: flex; gap: 8px; align-items: center; margin-top: 4px;">
                        <div class="beta-value" style="display: flex; align-items: center;">
                            <span class="beta-prefix">APL-{{ strtoupper(now()->format('Y-M')) }}-</span>
                            <input type="text" wire:model.live="seq_number" class="beta-input badge-input" placeholder="0001">
                        </div>
                        <button type="button" wire:click="checkAvailability" class="beta-btn-add" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.4); color: #ffffff; height: 32px; font-size: 11px; padding: 0 12px; border-radius: 6px;">
                            Check Availability
                        </button>
                    </div>
                    @if($availabilityMessage)
                        <span style="font-size: 12px; margin-top: 4px; display: block; font-weight: 600; color: {{ $isAvailable ? '#a7f3d0' : '#fca5a5' }};">
                            {{ $availabilityMessage }}
                        </span>
                    @endif
                    @error('seq_number')
                        <span class="beta-error" style="color: #fca5a5; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>

                <!-- Card 1: Document Details -->
                <div class="beta-card">
                    <h3 class="beta-card-title"><i class="fa-solid fa-file-invoice"></i> Document Details</h3>
                    <div class="beta-form-grid">
                        @if(auth()->user()?->permissions?->is_sadm)
                        <div class="beta-form-group full-width">
                            <label class="beta-label">Unit/College</label>
                            <select wire:model="unit_college" class="beta-select">
                                <option value="">Select Unit/College</option>
                                @foreach($offices as $office)
                                    <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }} ({{ $office['office_code'] }})</option>
                                @endforeach
                            </select>
                            @error('unit_college') <span class="beta-error">{{ $message }}</span> @enderror
                        </div>
                        @endif

                        <div class="beta-form-group">
                            <label class="beta-label">Type of Document</label>
                            <input type="text" wire:model="type_of_document" class="beta-input" placeholder="e.g. Application Letter">
                            @error('type_of_document') <span class="beta-error">{{ $message }}</span> @enderror
                        </div>

                        <div class="beta-form-group">
                            <label class="beta-label">Name of Applicant</label>
                            <input type="text" wire:model="applicant_name" class="beta-input" placeholder="e.g. Jane Smith">
                            @error('applicant_name') <span class="beta-error">{{ $message }}</span> @enderror
                        </div>

                        <div class="beta-form-group full-width">
                            <label class="beta-label">Position</label>
                            <input type="text" wire:model="position" class="beta-input" placeholder="e.g. Assistant Professor I">
                            @error('position') <span class="beta-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                <!-- Card 2: Routing Sequence -->
                <div class="beta-card">
                    <h3 class="beta-card-title"><i class="fa-solid fa-route"></i> Routing Sequence</h3>
                    
                    <div class="beta-form-group full-width" style="margin-bottom: 20px;">
                        <label class="beta-label">Transaction Flow Path</label>
                        <select wire:model.live="transaction_flow" class="beta-select">
                            <option value="">Select Path</option>
                            @foreach($flows as $flow)
                                <option value="{{ $flow['flow_code'] }}">{{ $flow['flow_name'] }} ({{ $flow['flow_code'] }})</option>
                            @endforeach
                        </select>
                        @error('transaction_flow') <span class="beta-error">{{ $message }}</span> @enderror
                    </div>

                    @if(!empty($transaction_flow))
                    <!-- Horizontal Inline Timeline Flow Visualizer -->
                    <div class="beta-timeline-container">
                        <div class="beta-timeline-scroll">
                            @foreach($flow_offices as $index => $officeCode)
                                @php
                                    $officeName = collect($offices)->firstWhere('office_code', $officeCode)['office_name'] ?? $officeCode;
                                @endphp
                                <div class="beta-timeline-node">
                                    <div class="node-badge">{{ $index + 1 }}</div>
                                    <div class="node-content">
                                        <span class="node-code">{{ $officeCode }}</span>
                                        <span class="node-name">{{ $officeName }}</span>
                                    </div>
                                    <button type="button" class="node-remove" wire:click="removeOfficeFromFlow({{ $index }})" title="Remove from sequence">×</button>
                                </div>
                                @if(!$loop->last)
                                    <div class="beta-timeline-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                                @endif
                            @endforeach
                        </div>
                        
                        <!-- Add Office Inline Control -->
                        <div class="beta-timeline-controls">
                            <div style="display: flex; gap: 8px; width: 100%; max-width: 450px;">
                                <select wire:model="selected_gap_index" class="beta-select" style="flex: 1;">
                                    <option value="">Insert Position...</option>
                                    <option value="0">At the Beginning</option>
                                    @for($i = 1; $i <= count($flow_offices); $i++)
                                        <option value="{{ $i }}">After Step {{ $i }}</option>
                                    @endfor
                                </select>
                                <div x-data="{ open: false }" @click.outside="open = false" style="position: relative; flex: 1.5; display: flex;">
                                    <div style="position: relative; flex: 1;">
                                        <input type="text" 
                                               class="beta-select" 
                                               placeholder="Search office..." 
                                               wire:model.live="insert_office_search"
                                               @focus="open = true"
                                               style="width: 100%; box-sizing: border-box; outline: none; background: #ffffff;">
                                        
                                        <div x-show="open" style="position: absolute; bottom: 100%; left: 0; right: 0; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 6px; max-height: 150px; overflow-y: auto; z-index: 2000; margin-bottom: 4px; box-shadow: 0 -4px 12px rgba(0,0,0,0.08);">
                                            @php
                                                $filtered = collect($offices)->filter(function($off) {
                                                    if (empty($this->insert_office_search)) return true;
                                                    return stripos($off['office_name'], $this->insert_office_search) !== false 
                                                        || stripos($off['office_code'], $this->insert_office_search) !== false;
                                                });
                                            @endphp
                                            
                                            @forelse($filtered as $off)
                                                <div @click="open = false"
                                                     wire:click="selectOfficeForInsert('{{ $off['office_code'] }}', '{{ $off['office_name'] }}')"
                                                     style="padding: 6px 10px; cursor: pointer; display: flex; justify-content: space-between; font-size: 11.5px; font-family: 'Inter', sans-serif; transition: background 0.15s ease; border-bottom: 1px solid #f1f5f9; text-align: left;"
                                                     onmouseover="this.style.backgroundColor='#f1f5f9'"
                                                     onmouseout="this.style.backgroundColor='transparent'">
                                                    <span style="font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">{{ $off['office_name'] }}</span>
                                                    <span style="color: #64748b; font-weight: 600; flex-shrink: 0;">{{ $off['office_code'] }}</span>
                                                </div>
                                            @empty
                                                <div style="padding: 6px 10px; color: #94a3b8; font-size: 11.5px; font-style: italic; text-align: center;">No offices found</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                                <button type="button" wire:click="insertOffice" class="beta-btn-add" {{ $selected_gap_index === null || empty($insert_office_code) ? 'disabled' : '' }}>
                                    <i class="fa-solid fa-plus"></i> Add
                                </button>
                            </div>
                            <button type="button" wire:click="resetFlowToDefault" class="beta-btn-reset">Reset to Default</button>
                        </div>
                    </div>
                    @endif
                </div>

                <!-- Card 3: Distribution & Remarks -->
                <div class="beta-card">
                    <h3 class="beta-card-title"><i class="fa-solid fa-share-nodes"></i> Distribution & Remarks</h3>
                    
                    <div class="beta-form-group full-width">
                        <label class="beta-label">Copy Furnished</label>
                        <div class="beta-segmented-toggle">
                            <button type="button" class="toggle-btn {{ $copy_furnished === 'No' ? 'active' : '' }}" wire:click="$set('copy_furnished', 'No')">No</button>
                            <button type="button" class="toggle-btn {{ $copy_furnished === 'Yes' ? 'active' : '' }}" wire:click="$set('copy_furnished', 'Yes')">Yes</button>
                        </div>
                    </div>

                    @if($copy_furnished === 'Yes')
                    <div class="beta-form-group full-width" style="margin-top: 15px;">
                        <label class="beta-label">Search & Add Recipient Offices</label>
                        <div class="beta-search-wrapper" style="position: relative;">
                            <i class="fa-solid fa-magnifying-glass search-icon" style="position: absolute; left: 12px; top: 12px; color: #94a3b8; font-size: 13px;"></i>
                            <input type="text" wire:model.live="cf_search" class="beta-input" placeholder="Type to search offices..." style="padding-left: 36px; width: 100%; box-sizing: border-box;">
                            
                            @if(!empty($cf_search))
                                <div class="beta-autocomplete-dropdown">
                                    @php
                                        $filteredCfOffices = collect($offices)
                                            ->filter(fn($o) => 
                                                (stripos($o['office_name'], $cf_search) !== false || 
                                                 stripos($o['office_code'], $cf_search) !== false) &&
                                                !in_array($o['office_code'], $cf_selected_offices) &&
                                                $o['office_code'] !== $unit_college
                                            )
                                            ->take(6);
                                    @endphp
                                    @forelse($filteredCfOffices as $office)
                                        <div class="dropdown-item" wire:click="selectCfOffice('{{ $office['office_code'] }}')">
                                            <strong>{{ $office['office_code'] }}</strong> - {{ $office['office_name'] }}
                                        </div>
                                    @empty
                                        <div class="dropdown-no-results">No offices found.</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                        @error('cf_selected_offices') <span class="beta-error">{{ $message }}</span> @enderror

                        <!-- Selected Office Badges -->
                        <div class="beta-badges-list" style="margin-top: 12px;">
                            @foreach($cf_selected_offices as $index => $officeCode)
                                @php
                                    $officeName = collect($offices)->firstWhere('office_code', $officeCode)['office_name'] ?? $officeCode;
                                @endphp
                                <span class="beta-badge">
                                    {{ $officeName }}
                                    <button type="button" class="badge-remove" wire:click="removeCfOffice({{ $index }})">×</button>
                                </span>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>

                <!-- QR Code Generation Card for Beta -->
                <div class="beta-card" style="border: 2px dashed rgba(37, 99, 235, 0.3); background: rgba(37, 99, 235, 0.02); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; gap: 16px; margin-bottom: 24px;">
                    <h3 class="beta-card-title" style="margin-bottom: 0; align-self: flex-start;">
                        <i class="fa-solid fa-qrcode"></i> Transaction QR Code
                    </h3>
                    
                    @if($generatedQrCode)
                        <div id="printable-qr-area-apl" style="display: flex; flex-direction: column; align-items: center; gap: 12px; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode($generatedQrCode) }}" alt="QR Code" style="width: 150px; height: 150px; border-radius: 4px;">
                            <span style="font-family: 'Space Mono', monospace; font-weight: 700; font-size: 15px; color: #1e293b; letter-spacing: 0.5px;">{{ $generatedQrCode }}</span>
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 4px;">
                            <button type="button" onclick="printQrCodeApl()" class="beta-btn-submit" style="background: #10b981; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.25); padding: 10px 20px; font-size: 13px;">
                                <i class="fa-solid fa-print"></i> Print QR Code
                            </button>
                        </div>
                    @else
                        <div style="text-align: center; color: #64748b; max-width: 400px;">
                            <i class="fa-solid fa-qrcode" style="font-size: 48px; margin-bottom: 12px; color: #94a3b8; display: block; opacity: 0.8;"></i>
                            <span style="font-size: 15px; font-weight: 700; display: block; color: #1e293b;">QR Code Generation Required</span>
                            <span style="font-size: 12.5px; display: block; margin-top: 6px; color: #64748b; line-height: 1.5;">You must generate and print a transaction QR code using the sequence number before you can proceed to create this transaction.</span>
                        </div>
                        <button type="button" wire:click="generateQrCode" class="beta-btn-submit" style="padding: 10px 20px; font-size: 13px;">
                            <i class="fa-solid fa-gear"></i> Generate QR Code
                        </button>
                    @endif
                </div>

                <!-- Submit Footer -->
                <div class="beta-form-footer">
                    @if (session()->has('error'))
                        <span class="beta-error" style="margin-right: 15px; align-self: center;">{{ session('error') }}</span>
                    @endif
                    <button type="submit" class="beta-btn-submit" @if(!$generatedQrCode) disabled style="background: #cbd5e1; color: #94a3b8; cursor: not-allowed; box-shadow: none;" @endif>
                        <i class="fa-solid fa-floppy-disk"></i> Create Transaction
                    </button>
                </div>
            </div>
        </form>
    @else
        <!-- Control Number Input Field -->
        <div class="control-wrapper">
            <label class="control-label">Control Number:</label>
            <div style="display: flex; gap: 8px; align-items: center;">
                <div style="display: flex; align-items: center; max-width: 300px; border: 1px solid #ced4da; border-radius: 4px; overflow: hidden; background: #e9ecef; height: 32px; box-sizing: border-box;">
                    <span style="padding: 0 10px; font-family: 'Inter', sans-serif; font-size: 13px; color: #495057; font-weight: 600; border-right: 1px solid #ced4da; user-select: none; line-height: 30px;">
                        APL-{{ strtoupper(now()->format('Y-M')) }}-
                    </span>
                    <input type="text" wire:model.live="seq_number" class="text-input" placeholder="0001" style="flex: 1; border: none; height: 100%; padding: 0 8px; font-size: 13px; background: transparent; outline: none; box-shadow: none;">
                </div>
                <button type="button" wire:click="checkAvailability" class="btn-primary" style="padding: 0 12px; height: 32px; font-size: 12px; background-color: #4b5563; border-radius: 4px;">
                    Check Availability
                </button>
            </div>
            @if($availabilityMessage)
                <span style="font-size: 12px; margin-top: 4px; display: block; font-weight: 600; color: {{ $isAvailable ? '#10b981' : '#dc2626' }};">
                    {{ $availabilityMessage }}
                </span>
            @endif
            @error('seq_number')
                <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
            @enderror
        </div>

        <!-- Document Transaction Form -->
        <form class="rms-form" wire:submit.prevent="save">
            
            <!-- Type of Document -->
            <div class="form-row">
                <div class="form-col small-input">
                    <label class="input-label">Type of Document</label>
                    <input type="text" wire:model="type_of_document" class="text-input" placeholder="Type of Document">
                    @error('type_of_document')
                        <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <!-- Name of Applicant -->
            <div class="form-row">
                <div class="form-col small-input">
                    <label class="input-label">Name of Applicant</label>
                    <input type="text" wire:model="applicant_name" class="text-input" placeholder="Name of Applicant">
                    @error('applicant_name')
                        <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            
            <!-- Position -->
            <div class="form-row">
                <div class="form-col small-input">
                    <label class="input-label">Position</label>
                    <input type="text" wire:model="position" class="text-input" placeholder="Position">
                    @error('position')
                        <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            
            <!-- Unit/College -->
            @if(auth()->user()?->permissions?->is_sadm)
            <div class="form-row">
                <div class="form-col medium-input">
                    <label class="input-label">Unit/College</label>
                    <select wire:model="unit_college" class="select-input">
                        <option value="">Select Unit/College</option>
                        @foreach($offices as $office)
                            <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }} ({{ $office['office_code'] }})</option>
                        @endforeach
                    </select>
                    @error('unit_college')
                        <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            @endif
            
            <!-- View Path Field -->
            <div class="form-row">
                <div class="form-col viewpath-wrapper">
                    <label class="input-label">Transaction Flow / Path</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <select wire:model.live="transaction_flow" class="select-input" style="flex: 1;">
                            <option value="">Select Path</option>
                            @foreach($flows as $flow)
                                <option value="{{ $flow['flow_code'] }}">{{ $flow['flow_name'] }} ({{ $flow['flow_code'] }})</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="openFlowDiagram" class="btn-primary" style="padding: 0 16px; height: 38px; font-size: 12px; font-weight: 600; background-color: #4b5563; border-radius: 4px;" {{ empty($transaction_flow) ? 'disabled' : '' }}>
                            View Flow Diagram
                        </button>
                    </div>
                    @error('transaction_flow')
                        <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <!-- Copy Furnished Toggle -->
            <div class="form-row">
                <div class="form-col small-input">
                    <label class="input-label">Copy Furnished</label>
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px; margin-top: 4px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #333; font-weight: 500;">
                            <input type="radio" wire:model.live="copy_furnished" value="Yes" style="cursor: pointer; width: 16px; height: 16px;"> Yes
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; color: #333; font-weight: 500;">
                            <input type="radio" wire:model.live="copy_furnished" value="No" style="cursor: pointer; width: 16px; height: 16px;"> No
                        </label>
                    </div>
                    @error('copy_furnished')
                        <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <!-- Copy Furnished Selector Box -->
            @if($copy_furnished === 'Yes')
                <div class="form-row">
                    <div class="form-col" style="max-width: 500px; padding: 16px; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #f8fafc; margin-bottom: 12px;">
                        <label class="input-label" style="margin-bottom: 8px;">Copy Furnished Offices</label>
                        
                        @if(count($cf_selected_offices) > 0)
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                                @foreach($cf_selected_offices as $index => $code)
                                    @php
                                        $officeName = collect($offices)->firstWhere('office_code', $code)['office_name'] ?? $code;
                                    @endphp
                                    <span style="display: inline-flex; align-items: center; background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; border: 1px solid #bae6fd;">
                                        {{ $officeName }}
                                        <button type="button" wire:click="removeCfOffice({{ $index }})" style="background: none; border: none; color: #ef4444; margin-left: 6px; cursor: pointer; font-weight: bold; font-size: 14px; padding: 0; line-height: 1;">&times;</button>
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p style="font-size: 12px; color: #64748b; font-style: italic; margin-bottom: 12px; margin-top: 0;">No offices added yet.</p>
                        @endif

                        <div style="position: relative; width: 100%;">
                            <input type="text" 
                                wire:model.live="cf_search" 
                                placeholder="Type to search and add office..." 
                                class="text-input" 
                                style="height: 34px; padding: 4px 10px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 4px; outline: none; background: #fff; width: 100%;"
                            >
                            
                            <!-- Dropdown results list -->
                            @if(!empty($cf_search))
                                @php
                                    $filteredOffices = array_filter($offices, function($office) use ($cf_selected_offices, $unit_college) {
                                        return !in_array($office['office_code'], $cf_selected_offices) && $office['office_code'] !== $unit_college;
                                    });
                                    
                                    $searchLower = strtolower($cf_search);
                                    $filteredOffices = array_filter($filteredOffices, function($office) use ($searchLower) {
                                        return str_contains(strtolower($office['office_code']), $searchLower) ||
                                               str_contains(strtolower($office['office_name']), $searchLower);
                                    });
                                @endphp

                                <div style="position: absolute; top: 38px; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 50; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                                    @if(count($filteredOffices) > 0)
                                        @foreach($filteredOffices as $office)
                                            <button type="button" 
                                                wire:click="selectCfOffice('{{ $office['office_code'] }}')" 
                                                style="width: 100%; text-align: left; padding: 8px 12px; border: none; background: none; font-size: 12px; cursor: pointer; color: #333; border-bottom: 1px solid #f1f5f9; display: block;"
                                                onmouseover="this.style.backgroundColor='#f1f5f9'" 
                                                onmouseout="this.style.backgroundColor='transparent'"
                                            >
                                                <strong>{{ $office['office_code'] }}</strong> - {{ $office['office_name'] }}
                                            </button>
                                        @endforeach
                                    @else
                                        <div style="padding: 8px 12px; font-size: 12px; color: #64748b;">
                                            No matching offices found.
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                        @error('cf_selected_offices')
                            <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            @endif
            
            <!-- QR Code Generation Panel -->
            <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: 20px; margin-bottom: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; box-sizing: border-box;">
                @if($generatedQrCode)
                    <div id="printable-qr-area-apl" style="display: flex; flex-direction: column; align-items: center; gap: 8px; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode($generatedQrCode) }}" alt="QR Code" style="width: 150px; height: 150px;">
                        <span style="font-family: monospace; font-weight: bold; font-size: 14px; color: #1e293b;">{{ $generatedQrCode }}</span>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 4px;">
                        <button type="button" onclick="printQrCodeApl()" style="background: #10b981; color: white; border: none; border-radius: 6px; padding: 8px 16px; font-weight: bold; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-print"></i> Print QR Code
                        </button>
                    </div>
                @else
                    <div style="text-align: center; color: #64748b;">
                        <i class="fa-solid fa-qrcode" style="font-size: 36px; margin-bottom: 8px; color: #94a3b8; display: block;"></i>
                        <span style="font-size: 13.5px; font-weight: 500; display: block;">QR Code Generation Required</span>
                        <span style="font-size: 12px; display: block; margin-top: 2px;">You must generate and print a QR code for this transaction before submitting it.</span>
                    </div>
                    <button type="button" wire:click="generateQrCode" style="background: #3b82f6; color: white; border: none; border-radius: 6px; padding: 10px 20px; font-weight: bold; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);">
                        <i class="fa-solid fa-gear"></i> Generate QR Code
                    </button>
                @endif
            </div>

            <!-- Submit Button -->
            <div class="actions-row">
                @if (session()->has('error'))
                    <span style="color: #dc2626; font-size: 13px; align-self: center; margin-right: 15px;">{{ session('error') }}</span>
                @endif
                <button type="submit" class="btn-primary" @if(!$generatedQrCode) disabled style="background-color: #cbd5e1; color: #94a3b8; cursor: not-allowed;" @endif>CREATE TRANSACTION</button>
            </div>
        </form>
<!-- QR-CODE FUNCTION HERE -->
        <script>
            function printQrCodeApl() {
                var printContents = document.getElementById('printable-qr-area-apl').innerHTML;
                var printWindow = window.open('', '_blank', 'width=350,height=350');
                printWindow.document.body.innerHTML = printContents;
                
                var style = printWindow.document.createElement('style');
                style.innerHTML = 'body{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;margin:0;font-family:monospace;} img { width: 150px; height: 150px; }';
                printWindow.document.head.appendChild(style);
                
                printWindow.document.close();
                printWindow.focus();
                setTimeout(function() {
                    printWindow.print();
                    printWindow.close();
                }, 500);
            }
        </script>
    @endif

    <!-- Flow Diagram Modal -->
    @if($showFlowModal)
        <div style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1050; padding: 20px; box-sizing: border-box;">
            <div style="background: #ffffff; width: 100%; max-width: 600px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 90vh;">
                
                <!-- Modal Header -->
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.1rem; color: #1e3a8a; font-weight: 700; text-transform: uppercase;">
                        Transaction Flow Diagram
                    </h3>
                    <button type="button" wire:click="$set('showFlowModal', false)" style="background: none; border: none; font-size: 24px; color: #9ca3af; cursor: pointer; line-height: 1;">&times;</button>
                </div>

                <!-- Modal Body -->
                <div style="padding: 20px; overflow-y: auto; flex: 1;">
                    <p style="font-size: 13px; color: #6b7280; margin-top: 0; margin-bottom: 20px;">
                        This is the routing path for this transaction. You can modify the offices in the flow by inserting a new office in the gaps (indicated by +).
                    </p>

                    <!-- Flow Path Nodes -->
                    <div style="display: flex; flex-direction: column; gap: 8px; max-width: 450px; margin: 0 auto;">
                        
                        @for($i = 0; $i < count($flow_offices); $i++)
                            <!-- Gap before Node $i -->
                            <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin: 4px 0;">
                                @if($selected_gap_index === $i)
                                    <div x-data="{ open: false }" @click.outside="open = false" style="position: relative; flex: 1; display: flex;">
                                        <div style="position: relative; flex: 1;">
                                            <input type="text" 
                                                   class="select-input" 
                                                   placeholder="Search office..." 
                                                   wire:model.live="insert_office_search"
                                                   @focus="open = true"
                                                   style="width: 100%; height: 28px; padding: 2px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; outline: none; background: #ffffff;">
                                            
                                            <div x-show="open" style="position: absolute; bottom: 100%; left: 0; right: 0; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 6px; max-height: 150px; overflow-y: auto; z-index: 2000; margin-bottom: 4px; box-shadow: 0 -4px 12px rgba(0,0,0,0.08);">
                                                @php
                                                    $filtered = collect($offices)->filter(function($off) {
                                                        if (empty($this->insert_office_search)) return true;
                                                        return stripos($off['office_name'], $this->insert_office_search) !== false 
                                                            || stripos($off['office_code'], $this->insert_office_search) !== false;
                                                    });
                                                @endphp
                                                
                                                @forelse($filtered as $off)
                                                    <div @click="open = false"
                                                         wire:click="selectOfficeForInsert('{{ $off['office_code'] }}', '{{ $off['office_name'] }}')"
                                                         style="padding: 6px 10px; cursor: pointer; display: flex; justify-content: space-between; font-size: 11.5px; font-family: 'Inter', sans-serif; transition: background 0.15s ease; border-bottom: 1px solid #f1f5f9;"
                                                         onmouseover="this.style.backgroundColor='#f1f5f9'"
                                                         onmouseout="this.style.backgroundColor='transparent'">
                                                        <span style="font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">{{ $off['office_name'] }}</span>
                                                        <span style="color: #64748b; font-weight: 600; flex-shrink: 0;">{{ $off['office_code'] }}</span>
                                                    </div>
                                                @empty
                                                    <div style="padding: 6px 10px; color: #94a3b8; font-size: 11.5px; font-style: italic; text-align: center;">No offices found</div>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" wire:click="insertOffice" class="btn-primary" style="padding: 4px 8px; font-size: 11px; background-color: #10b981; height: 28px; line-height: 20px; border-radius: 4px; border: none; color: white; cursor: pointer; font-weight: bold; flex-shrink: 0;">Insert</button>
                                    <button type="button" wire:click="cancelInsert" style="background: #ef4444; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 11px; cursor: pointer; height: 28px; font-weight: bold; flex-shrink: 0;">Cancel</button>
                                @else
                                    <button type="button" wire:click="$set('selected_gap_index', {{ $i }})" style="width: 20px; height: 20px; border-radius: 50%; border: 1px dashed #3b82f6; background: #eff6ff; color: #3b82f6; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; outline: none;" title="Insert office here">+</button>
                                    <span style="font-size: 11px; color: #9ca3af; font-style: italic;">Insert Gap</span>
                                @endif
                            </div>

                            <!-- Office Node Card -->
                            @php
                                $code = $flow_offices[$i];
                                $officeName = collect($offices)->firstWhere('office_code', $code)['office_name'] ?? $code;
                            @endphp
                            <div style="padding: 10px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #3b82f6; color: #ffffff; font-size: 11px; font-weight: bold;">
                                        {{ $i + 1 }}
                                    </span>
                                    <span style="font-size: 13px; font-weight: 600; color: #1e293b;">
                                        {{ $officeName }} ({{ $code }})
                                    </span>
                                </div>
                                <!-- Remove node button -->
                                @if(count($flow_offices) > 1)
                                    <button type="button" wire:click="removeOfficeFromFlow({{ $i }})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; padding: 0 4px;" title="Remove office">&times;</button>
                                @endif
                            </div>
                        @endfor

                        <!-- Final Gap after last node -->
                        <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin: 4px 0;">
                            @if($selected_gap_index === count($flow_offices))
                                <div x-data="{ open: false }" @click.outside="open = false" style="position: relative; flex: 1; display: flex;">
                                    <div style="position: relative; flex: 1;">
                                        <input type="text" 
                                               class="select-input" 
                                               placeholder="Search office..." 
                                               wire:model.live="insert_office_search"
                                               @focus="open = true"
                                               style="width: 100%; height: 28px; padding: 2px 8px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; outline: none; background: #ffffff;">
                                        
                                        <div x-show="open" style="position: absolute; bottom: 100%; left: 0; right: 0; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 6px; max-height: 150px; overflow-y: auto; z-index: 2000; margin-bottom: 4px; box-shadow: 0 -4px 12px rgba(0,0,0,0.08);">
                                            @php
                                                $filtered = collect($offices)->filter(function($off) {
                                                    if (empty($this->insert_office_search)) return true;
                                                    return stripos($off['office_name'], $this->insert_office_search) !== false 
                                                        || stripos($off['office_code'], $this->insert_office_search) !== false;
                                                    });
                                                @endphp
                                                
                                                @forelse($filtered as $off)
                                                    <div @click="open = false"
                                                         wire:click="selectOfficeForInsert('{{ $off['office_code'] }}', '{{ $off['office_name'] }}')"
                                                         style="padding: 6px 10px; cursor: pointer; display: flex; justify-content: space-between; font-size: 11.5px; font-family: 'Inter', sans-serif; transition: background 0.15s ease; border-bottom: 1px solid #f1f5f9;"
                                                         onmouseover="this.style.backgroundColor='#f1f5f9'"
                                                         onmouseout="this.style.backgroundColor='transparent'">
                                                        <span style="font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">{{ $off['office_name'] }}</span>
                                                        <span style="color: #64748b; font-weight: 600; flex-shrink: 0;">{{ $off['office_code'] }}</span>
                                                    </div>
                                                @empty
                                                    <div style="padding: 6px 10px; color: #94a3b8; font-size: 11.5px; font-style: italic; text-align: center;">No offices found</div>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" wire:click="insertOffice" class="btn-primary" style="padding: 4px 8px; font-size: 11px; background-color: #10b981; height: 28px; line-height: 20px; border-radius: 4px; border: none; color: white; cursor: pointer; font-weight: bold; flex-shrink: 0;">Insert</button>
                                    <button type="button" wire:click="cancelInsert" style="background: #ef4444; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 11px; cursor: pointer; height: 28px; font-weight: bold; flex-shrink: 0;">Cancel</button>
                            @else
                                <button type="button" wire:click="$set('selected_gap_index', {{ count($flow_offices) }})" style="width: 20px; height: 20px; border-radius: 50%; border: 1px dashed #3b82f6; background: #eff6ff; color: #3b82f6; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 12px; outline: none;" title="Insert office here">+</button>
                                <span style="font-size: 11px; color: #9ca3af; font-style: italic;">Insert Gap</span>
                            @endif
                        </div>

                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="padding: 16px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; background-color: #f9fafb;">
                    <button type="button" wire:click="resetFlowToDefault" style="background: #ef4444; color: white; border: none; border-radius: 6px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Reset to Default
                    </button>
                    <button type="button" wire:click="$set('showFlowModal', false)" style="background: #3b82f6; color: white; border: none; border-radius: 6px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Close
                    </button>
                </div>

            </div>
        </div>
    @endif

</div>
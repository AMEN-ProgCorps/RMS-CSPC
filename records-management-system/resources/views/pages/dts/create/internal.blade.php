<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Internal Transaction')] class extends Component {
    public string $seq_number = '';
    public string $unit_college = '';
    public string $requestor_name = '';
    public string $type_of_document = '';
    public string $classification = '';
    public string $subject = '';
    public string $action_needed = '';
    public string $transaction_flow = '';
    public string $copy_furnished = 'No';

    public array $cf_selected_offices = [];
    public string $cf_search = '';

    public array $offices = [];
    public array $flows = [];

    // Flow Diagram modal states
    public bool $showFlowModal = false;
    public array $flow_offices = [];
    public ?int $selected_gap_index = null;
    public string $insert_office_code = '';

    public function mount(): void
    {
        $this->offices = DB::table('office')
            ->where('is_active', true)
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
        } else {
            $this->flow_offices = [];
        }
    }

    public function openFlowDiagram(): void
    {
        if (!empty($this->transaction_flow)) {
            $this->selected_gap_index = null;
            $this->insert_office_code = '';
            $this->showFlowModal = true;
        }
    }

    public function insertOffice(): void
    {
        if ($this->selected_gap_index !== null && !empty($this->insert_office_code)) {
            array_splice($this->flow_offices, $this->selected_gap_index, 0, $this->insert_office_code);
            $this->selected_gap_index = null;
            $this->insert_office_code = '';
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

    public function removeCfOffice(int $index): void
    {
        unset($this->cf_selected_offices[$index]);
        $this->cf_selected_offices = array_values($this->cf_selected_offices);
    }

    public function save()
    {
        $this->validate([
            'seq_number' => 'required|string|max:50',
            'unit_college' => 'required|string|exists:office,office_code',
            'requestor_name' => 'required|string|max:255',
            'type_of_document' => 'nullable|string|max:255',
            'classification' => 'required|string',
            'subject' => 'required|string',
            'action_needed' => 'required|string',
            'transaction_flow' => 'required|string|exists:dts_transaction_flow,flow_code',
            'copy_furnished' => 'required|string|in:Yes,No',
            'cf_selected_offices' => 'required_if:copy_furnished,Yes|array',
        ]);

        if (count($this->flow_offices) === 0) {
            $this->addError('transaction_flow', 'The transaction flow must contain at least one office.');
            return;
        }

        $controlNumber = 'INT-' . now()->format('Y-m') . '-' . $this->seq_number;

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
            // Check if the flow has been modified
            $flowCode = $this->transaction_flow;
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->transaction_flow)->first();
            
            $defaultOffices = [];
            if ($flow) {
                $defaultOffices = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->orderBy('sequence_ranking', 'asc')
                    ->pluck('office_code')
                    ->toArray();
            }

            if ($defaultOffices !== $this->flow_offices) {
                // Generate a custom/modified flow
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

            // Find or create an unused QR code
            $qrCode = DB::table('dts_qr_code')
                ->where('qr_status', 'not used')
                ->first();
            
            if (!$qrCode) {
                $qrCodeId = 'QR-' . strtoupper(Str::random(8));
                DB::table('dts_qr_code')->insert([
                    'code_id' => $qrCodeId,
                    'qr_status' => 'used',
                    'created_at' => now(),
                ]);
            } else {
                $qrCodeId = $qrCode->code_id;
                DB::table('dts_qr_code')
                    ->where('code_id', $qrCodeId)
                    ->update(['qr_status' => 'used']);
            }

            // Create document data record if type of document is provided
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
                'trans_type' => 'internal',
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
                'type' => 'internal',
                'created_by' => auth()->id(),
                'originated_from' => $this->unit_college,
                'requestor_name' => $this->requestor_name,
                'subject' => $this->subject,
                'classification' => $this->classification,
                'action_needed' => $this->action_needed,
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
                'notes' => 'Created internal transaction',
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
    <div class="rms-header">
        <h2>Internal Transaction</h2>
    </div>

    <!-- Control Number Input Field -->
    <div class="control-wrapper">
        <label class="control-label">Control Number:</label>
        <div style="display: flex; align-items: center; max-width: 300px; border: 1px solid #ced4da; border-radius: 4px; overflow: hidden; background: #e9ecef; height: 32px; box-sizing: border-box;">
            <span style="padding: 0 10px; font-family: 'Inter', sans-serif; font-size: 13px; color: #495057; font-weight: 600; border-right: 1px solid #ced4da; user-select: none; line-height: 30px;">
                INT-{{ now()->format('Y-m') }}-
            </span>
            <input type="text" wire:model="seq_number" class="text-input" placeholder="0001" style="flex: 1; border: none; height: 100%; padding: 0 8px; font-size: 13px; background: transparent; outline: none; box-shadow: none;">
        </div>
        @error('seq_number')
            <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
        @enderror
    </div>

    <!-- Internal Transaction Form -->
    <form class="rms-form" wire:submit.prevent="save">
        
        <!-- Unit/College field -->
        @if(auth()->user()?->permissions?->is_sadm)
        <div class="form-row">
            <div class="form-col medium-input">
                <label class="input-label">Originating Unit/College</label>
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

        <!-- Name of Requestor field -->
        <div class="form-row">
            <div class="form-col small-input">
                <label class="input-label">Name of Requestor</label>
                <input type="text" wire:model="requestor_name" class="text-input" placeholder="Name of Requestor">
                @error('requestor_name')
                    <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <!-- Type of Document field -->
        <div class="form-row">
            <div class="form-col small-input">
                <label class="input-label">Type of Document</label>
                <input type="text" wire:model="type_of_document" class="text-input" placeholder="Type of Document">
                @error('type_of_document')
                    <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <!-- Classification dropdown -->
        <div class="form-row">
            <div class="form-col small-input">
                <label class="input-label">Classification</label>
                <select wire:model="classification" class="select-input">
                    <option value="">Classification</option>
                    <option value="Simple">Simple</option>
                    <option value="Complex">Complex</option>
                    <option value="Highly Technical">Highly Technical</option>
                </select>
                @error('classification')
                    <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <!-- Subject field with label and textarea -->
        <div class="form-row">
            <div class="form-col subject-wrapper">
                <label class="input-label">Subject:</label>
                <textarea wire:model="subject" class="textarea-input subject-area" placeholder="Enter subject..." rows="4"></textarea>
                @error('subject')
                    <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <!-- Action Needed dropdown -->
        <div class="form-row">
            <div class="form-col small-input">
                <label class="input-label">Action Needed</label>
                <select wire:model="action_needed" class="select-input">
                    <option value="">Select action</option>
                    <option value="For Information">For Information</option>
                    <option value="For Signature">For Signature</option>
                    <option value="For Approval">For Approval</option>
                    <option value="For Action">For Action</option>
                </select>
                @error('action_needed')
                    <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <!-- View Path field -->
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

        <!-- Submit button -->
        <div class="actions-row">
            @if (session()->has('error'))
                <span style="color: #dc2626; font-size: 13px; align-self: center; margin-right: 15px;">{{ session('error') }}</span>
            @endif
            <button type="submit" class="btn-primary">CREATE TRANSACTION</button>
        </div>
    </form>

</div>

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
                                <div style="display: flex; gap: 6px; align-items: center; width: 100%;">
                                    <select wire:model="insert_office_code" class="select-input" style="flex: 1; height: 28px; padding: 2px 6px; font-size: 12px;">
                                        <option value="">Select Office</option>
                                        @foreach($offices as $office)
                                            <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="insertOffice" class="btn-primary" style="padding: 4px 8px; font-size: 11px; background-color: #10b981; height: 28px; line-height: 20px;">Insert</button>
                                    <button type="button" wire:click="$set('selected_gap_index', null)" style="background: #ef4444; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 11px; cursor: pointer; height: 28px;">Cancel</button>
                                </div>
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
                            <div style="display: flex; gap: 6px; align-items: center; width: 100%;">
                                <select wire:model="insert_office_code" class="select-input" style="flex: 1; height: 28px; padding: 2px 6px; font-size: 12px;">
                                    <option value="">Select Office</option>
                                    @foreach($offices as $office)
                                        <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="insertOffice" class="btn-primary" style="padding: 4px 8px; font-size: 11px; background-color: #10b981; height: 28px; line-height: 20px;">Insert</button>
                                <button type="button" wire:click="$set('selected_gap_index', null)" style="background: #ef4444; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 11px; cursor: pointer; height: 28px;">Cancel</button>
                            </div>
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
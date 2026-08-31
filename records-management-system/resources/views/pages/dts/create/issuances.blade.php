<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Issuance')] class extends Component {
    public string $availabilityMessage = '';
    public ?bool $isAvailable = null;

    public array $free_flow_receiving_offices = [];
    public string $receiving_search = '';

    public string $issuance_type = 'NM';
    public string $seq_number = '';
    public string $subject = '';
    public string $transaction_flow = '';
    public string $receiving_office = '';
    public string $copy_furnished = 'Yes';

    public array $cf_selected_offices = [];
    public string $cf_search = '';

    public array $offices = [];
    public array $flows = [];
    public string $userOfficeCode = '';

    public function getHasHubProperty(): bool
    {
        return in_array('[HUB]', $this->flow_offices) 
            || in_array('[HUB]', $this->customFlowSequence);
    }

    public function selectFreeFlowOffice(string $officeCode): void
    {
        if ($officeCode === 'ALL') {
            $this->free_flow_receiving_offices = ['ALL'];
            $this->cf_selected_offices = ['ALL'];
            $this->copy_furnished = 'Yes';
        } else {
            $this->free_flow_receiving_offices = array_values(array_filter($this->free_flow_receiving_offices, fn($code) => $code !== 'ALL'));
            if (!in_array($officeCode, $this->free_flow_receiving_offices)) {
                $this->free_flow_receiving_offices[] = $officeCode;
            }

            // Auto-add to Copy Furnished
            $this->cf_selected_offices = array_values(array_filter($this->cf_selected_offices, fn($code) => $code !== 'ALL'));
            if (!in_array($officeCode, $this->cf_selected_offices)) {
                $this->cf_selected_offices[] = $officeCode;
            }
            $this->copy_furnished = 'Yes';
        }
        $this->receiving_search = '';
    }

    public function removeFreeFlowOffice(int $index): void
    {
        // Independent removal: only removes from receiving offices
        unset($this->free_flow_receiving_offices[$index]);
        $this->free_flow_receiving_offices = array_values($this->free_flow_receiving_offices);
    }

    public function selectAllReceivingOffices(): void
    {
        $this->free_flow_receiving_offices = ['ALL'];
        $this->cf_selected_offices = ['ALL'];
        $this->copy_furnished = 'Yes';
        $this->receiving_search = '';
    }

    public function clearReceivingOffices(): void
    {
        $this->free_flow_receiving_offices = [];
        $this->receiving_search = '';
    }

    public function selectAllCfOffices(): void
    {
        $this->cf_selected_offices = ['ALL'];
        $this->copy_furnished = 'Yes';
        $this->cf_search = '';
    }

    public function clearCfOffices(): void
    {
        $this->cf_selected_offices = [];
        $this->cf_search = '';
    }

    // Custom flow creator fields
    public bool $showCustomFlowModal = false;
    public string $customFlowDocType = '';
    public array $customFlowSequence = [];
    public string $customFlowSelectedOffice = '';
    public string $customFlowFor = 'user';
    public array $customFlowHubOffices = [];
    public string $customFlowSelectedHubOffice = '';
    public string $customFlowHubSearch = '';
    public string $toastMessage = '';

    public function selectCustomFlowHubOffice(string $officeCode): void
    {
        if ($officeCode === 'ALL') {
            $this->customFlowHubOffices = ['ALL'];
        } else {
            $this->customFlowHubOffices = array_values(array_filter($this->customFlowHubOffices, fn($code) => $code !== 'ALL'));
            if (!in_array($officeCode, $this->customFlowHubOffices)) {
                $this->customFlowHubOffices[] = $officeCode;
            }
        }
        $this->customFlowHubSearch = '';
    }

    public function removeCustomFlowHubOffice(int $index): void
    {
        unset($this->customFlowHubOffices[$index]);
        $this->customFlowHubOffices = array_values($this->customFlowHubOffices);
    }

    public function selectAllCustomFlowHubOffices(): void
    {
        $this->customFlowHubOffices = ['ALL'];
        $this->customFlowHubSearch = '';
    }

    public function clearCustomFlowHubOffices(): void
    {
        $this->customFlowHubOffices = [];
        $this->customFlowHubSearch = '';
    }

    // Success Modal properties
    public bool $showSuccessModal = false;
    public array $createdTransactionSummary = [];

    public function closeSuccessModal(): void
    {
        $this->showSuccessModal = false;
        $this->createdTransactionSummary = [];
    }

    // Flow Diagram modal states
    public bool $showFlowModal = false;
    public array $flow_offices = [];
    public ?int $selected_gap_index = null;
    public string $insert_office_code = '';
    public string $insert_office_search = '';

    private function ensureOriginBounds(array $offices, string $originOfficeCode): array
    {
        if (empty($offices)) {
            return ['ORIGIN', 'ORIGIN'];
        }

        $first = reset($offices);
        $last = end($offices);

        $needsStart = ($first !== 'ORIGIN' && $first !== $originOfficeCode);
        $needsEnd = ($last !== 'ORIGIN' && $last !== $originOfficeCode);

        if ($needsStart) {
            array_unshift($offices, 'ORIGIN');
        }

        if ($needsEnd) {
            $offices[] = 'ORIGIN';
        }

        return array_values($offices);
    }

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_issuance) {
            abort(403, 'Unauthorized access to Issuance transactions.');
        }

        $this->userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user()) 
            ?? (DB::table('sys_office')->where('is_active', true)->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])->value('office_code') ?: 'RFOIU');
        $this->offices = DB::table('sys_office')
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]'])
            ->orderBy('office_name')
            ->get()
            ->map(fn($o) => (array)$o)
            ->toArray();

        $userOfficeId = auth()->user()?->details?->office_id;
        $this->flows = DB::table('dts_transaction_flow')
            ->where('is_active', true)
            ->whereIn('flow_use', ['issuances', 'none'])
            ->where('flow_name', 'not like', 'Flow for %')
            ->where(function($query) use ($userOfficeId) {
                $query->where('flow_for', 'system')
                      ->orWhere(function($q) {
                          $q->where('flow_for', 'user')
                            ->where('added_by', auth()->id());
                      });
                if ($userOfficeId) {
                    $query->orWhere(function($q) use ($userOfficeId) {
                        $q->where('flow_for', 'office')
                          ->whereExists(function($sub) use ($userOfficeId) {
                              $sub->select(DB::raw(1))
                                  ->from(\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details')
                                  ->whereColumn('account_id', 'dts_transaction_flow.added_by')
                                  ->where('office_id', $userOfficeId);
                          });
                    });
                }
            })
            ->orderBy('flow_name')
            ->get()
            ->map(fn($f) => (array)$f)
            ->toArray();
    }

    public function checkAvailability(): void
    {
        if (empty($this->seq_number)) {
            $this->availabilityMessage = 'Please enter a sequence number.';
            $this->isAvailable = false;
            return;
        }

        $controlNumber = $this->issuance_type . '-' . now()->format('Y-m') . '-' . $this->seq_number;

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

    public function generateRandomSeq(): void
    {
        $prefix = $this->issuance_type . '-' . now()->format('Y-m') . '-';
        
        $attempts = 0;
        do {
            do {
                $randomStr = Str::random(6);
            } while (!preg_match('/[0-9]/', $randomStr));
            
            $controlNumber = $prefix . $randomStr;
            $exists = DB::table('dts_transaction_details')
                ->where('control_number', $controlNumber)
                ->exists();
            $attempts++;
        } while ($exists && $attempts < 100);

        $this->seq_number = $randomStr;
        $this->availabilityMessage = 'Control number is available!';
        $this->isAvailable = true;
    }


    public function updatedSeqNumber(): void
    {
        $this->availabilityMessage = '';
        $this->isAvailable = null;
    }

    public function updatedIssuanceType(): void
    {
        $this->availabilityMessage = '';
        $this->isAvailable = null;
    }

    public function updatedUnitCollege(): void
    {
        if (!empty($this->transaction_flow)) {
            $this->updatedTransactionFlow($this->transaction_flow);
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
            $rawOffices = DB::table('dts_sequence_list')
                ->where('control_id', $flow->id)
                ->orderBy('sequence_ranking', 'asc')
                ->pluck('office_code')
                ->toArray();

            $originOfficeCode = $this->userOfficeCode;
            $originOffice = DB::table('sys_office')->where('office_code', $originOfficeCode)->first();
            $clusterHead = null;
            if ($originOffice && $originOffice->cluster) {
                $cluster = \DB::table('sys_cluster')->where('cluster_code', $originOffice->cluster)->first();
                if ($cluster) {
                    $clusterHead = $cluster->cluster_head;
                }
            }

            $rawOffices = $this->ensureOriginBounds($rawOffices, $originOfficeCode);

            $resolvedOffices = [];
            foreach ($rawOffices as $officeCode) {
                $resolved = $officeCode;
                if ($officeCode === 'ORIGIN') {
                    $resolved = $originOfficeCode;
                } elseif ($officeCode === '[H]') {
                    $resolved = $clusterHead ?: $originOfficeCode;
                }
                
                // Deduplicate adjacent/consecutive identical offices (e.g. if ORIGIN is followed by same office)
                if (empty($resolvedOffices) || end($resolvedOffices) !== $resolved) {
                    $resolvedOffices[] = $resolved;
                }
            }
            $this->flow_offices = $resolvedOffices;

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

            // Load predefined HUB receiving offices if sequence contains [HUB]
            if (in_array('[HUB]', $rawOffices)) {
                $predefinedHubOffices = DB::table('dts_hub_flow_datas')
                    ->where('flow_owner', $flow->id)
                    ->pluck('offices_hub')
                    ->toArray();

                if (!empty($predefinedHubOffices)) {
                    $this->free_flow_receiving_offices = $predefinedHubOffices;
                    // Auto-sync into Copy Furnished according to one-way rule
                    foreach ($predefinedHubOffices as $hOff) {
                        if (!in_array($hOff, $this->cf_selected_offices)) {
                            $this->cf_selected_offices[] = $hOff;
                        }
                    }
                }
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
        if ($officeCode === 'ALL') {
            $this->cf_selected_offices = ['ALL'];
        } else {
            $this->cf_selected_offices = array_values(array_filter($this->cf_selected_offices, fn($code) => $code !== 'ALL'));
            if (!in_array($officeCode, $this->cf_selected_offices)) {
                $this->cf_selected_offices[] = $officeCode;
            }
        }
        $this->copy_furnished = 'Yes';
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
            $this->generateRandomSeq();
        }

        // Prepare the Hacore formula variables
        $transCode = strtoupper(trim($this->seq_number));
        $month = strtoupper(now()->format('M'));
        $year = now()->format('Y');
        
        $type = strtoupper($this->issuance_type);

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

    public function openCustomFlowCreator(): void
    {
        $hasPermission = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_dts_create_own_flow;

        if (!$hasPermission) {
            $this->toastMessage = 'Your account does not have permission to create its own transaction flow.';
            return;
        }

        $this->customFlowDocType = '';
        $this->customFlowSequence = [];
        $this->customFlowSelectedOffice = '';
        $this->customFlowFor = 'user';
        $this->customFlowHubOffices = [];
        $this->customFlowSelectedHubOffice = '';
        $this->customFlowHubSearch = '';
        $this->showCustomFlowModal = true;
    }

    public function addToCustomFlowSequence(): void
    {
        if (empty($this->customFlowSelectedOffice)) {
            return;
        }
        
        $this->customFlowSequence[] = $this->customFlowSelectedOffice;
        $this->customFlowSelectedOffice = '';
    }

    public function removeFromCustomFlowSequence(int $index): void
    {
        unset($this->customFlowSequence[$index]);
        $this->customFlowSequence = array_values($this->customFlowSequence);
    }

    public function moveUpCustomFlowSequence(int $index): void
    {
        if ($index > 0) {
            $temp = $this->customFlowSequence[$index];
            $this->customFlowSequence[$index] = $this->customFlowSequence[$index - 1];
            $this->customFlowSequence[$index - 1] = $temp;
        }
    }

    public function moveDownCustomFlowSequence(int $index): void
    {
        if ($index < count($this->customFlowSequence) - 1) {
            $temp = $this->customFlowSequence[$index];
            $this->customFlowSequence[$index] = $this->customFlowSequence[$index + 1];
            $this->customFlowSequence[$index + 1] = $temp;
        }
    }

    public function saveCustomFlow(): void
    {
        $this->validate([
            'customFlowDocType' => 'required|string|max:255',
        ]);

        if (count($this->customFlowSequence) === 0) {
            $this->addError('customFlowSequence', 'You must add at least one office to the sequence.');
            return;
        }

        try {
            DB::transaction(function () {
                $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
                $flowId = $maxId + 1;
                $flowCode = 'FLOW-CUSTOM-' . time() . '-' . rand(100, 999);

                // Insert into dts_transaction_flow
                DB::table('dts_transaction_flow')->insert([
                    'id' => $flowId,
                    'flow_name' => trim($this->customFlowDocType),
                    'flow_code' => $flowCode,
                    'is_active' => true,
                    'flow_use' => 'issuances',
                    'flow_for' => $this->customFlowFor,
                    'added_by' => auth()->id() ?? 1,
                    'date_added' => now(),
                ]);

                // Insert sequence list
                foreach ($this->customFlowSequence as $rank => $officeCode) {
                    DB::table('dts_sequence_list')->insert([
                        'control_id' => $flowId,
                        'sequence_ranking' => $rank + 1,
                        'office_code' => $officeCode,
                    ]);
                }

                // Insert custom predefined HUB receiving offices
                if (in_array('[HUB]', $this->customFlowSequence) && count($this->customFlowHubOffices) > 0) {
                    $officesToSave = $this->customFlowHubOffices;
                    if (in_array('ALL', $officesToSave)) {
                        $allOffices = DB::table('sys_office')
                            ->where('is_active', true)
                            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
                            ->pluck('office_code')
                            ->toArray();
                        $officesToSave = $allOffices;
                    }
                    foreach ($officesToSave as $hOff) {
                        DB::table('dts_hub_flow_datas')->insert([
                            'flow_owner' => $flowId,
                            'offices_hub' => $hOff,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $this->transaction_flow = $flowCode;
            });

            // Reload flows list
            $userOfficeId = auth()->user()?->details?->office_id;
            $this->flows = DB::table('dts_transaction_flow')
                ->where('is_active', true)
                ->whereIn('flow_use', ['issuances', 'none'])
                ->where(function($query) use ($userOfficeId) {
                    $query->where('flow_for', 'system')
                          ->orWhere(function($q) {
                              $q->where('flow_for', 'user')
                                ->where('added_by', auth()->id());
                          });
                    if ($userOfficeId) {
                        $query->orWhere(function($q) use ($userOfficeId) {
                            $q->where('flow_for', 'office')
                              ->whereExists(function($sub) use ($userOfficeId) {
                                  $sub->select(DB::raw(1))
                                      ->from(\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details')
                                      ->whereColumn('account_id', 'dts_transaction_flow.added_by')
                                      ->where('office_id', $userOfficeId);
                              });
                        });
                    }
                })
                ->orderBy('flow_name')
                ->get()
                ->map(fn($f) => (array)$f)
                ->toArray();

            $this->showCustomFlowModal = false;
            $this->updatedTransactionFlow($this->transaction_flow);

        } catch (\Exception $e) {
            $this->addError('customFlowDocType', 'Failed to save custom flow: ' . $e->getMessage());
        }
    }

    public function removeCfOffice(int $index): void
    {
        unset($this->cf_selected_offices[$index]);
        $this->cf_selected_offices = array_values($this->cf_selected_offices);
    }

    public function save()
    {
        if (empty($this->seq_number)) {
            $this->generateRandomSeq();
        }

        $rules = [
            'issuance_type' => 'required|string|in:NM,AM,EM,TO,OM,TR,EN,DES,TA,AO',
            'seq_number' => 'required|string|max:50',
            'subject' => 'required|string',
            'transaction_flow' => 'required|string|exists:dts_transaction_flow,flow_code',
            'copy_furnished' => 'required|string|in:Yes,No',
            'cf_selected_offices' => 'nullable|array',
        ];

        if ($this->hasHub) {
            $rules['free_flow_receiving_offices'] = 'required|array|min:1';
        }

        $this->validate($rules, [
            'free_flow_receiving_offices.required' => 'Please select at least one receiving office for the Office Hub [HUB].',
            'free_flow_receiving_offices.min' => 'Please select at least one receiving office for the Office Hub [HUB].',
        ]);

        if (count($this->flow_offices) === 0) {
            $this->addError('transaction_flow', 'The transaction flow must contain at least one office.');
            return;
        }

        DB::beginTransaction();
        try {
            $attempts = 0;
            $controlNumber = '';
            $collision = false;

            do {
                $attempts++;
                if ($attempts > 1 || empty($this->seq_number)) {
                    $this->generateRandomSeq();
                    $this->generatedQrCode = null;
                }

                if (!$this->generatedQrCode) {
                    $this->generateQrCode();
                }

                $controlNumber = $this->issuance_type . '-' . now()->format('Y-m') . '-' . $this->seq_number;
                $collision = DB::table('dts_transaction_details')->where('control_number', $controlNumber)->exists();
            } while ($collision && $attempts < 5);

            if ($collision) {
                DB::rollBack();
                $this->addError('seq_number', 'High concurrency detected. Please click Create Transaction again.');
                return;
            }

            // Ensure the QR code exists in dts_qr_code and mark as used
            DB::table('dts_qr_code')->updateOrInsert(
                ['code_id' => $this->generatedQrCode],
                [
                    'qr_status' => 'used',
                    'created_at' => now(),
                ]
            );

            // Resolve or create requestor in dts_requestor_history
            $reqName = auth()->user()?->details ? (auth()->user()->details->first_name . ' ' . auth()->user()->details->last_name) : 'Authorized User';
            $reqPos = auth()->user()?->details?->job_position ?? '';
            $reqOffice = $this->userOfficeCode;

            $existingReq = DB::table('dts_requestor_history')
                ->where('requestor_name', $reqName)
                ->where('office', $reqOffice)
                ->first();

            if ($existingReq) {
                $requestorId = $existingReq->id;
                if (!empty($reqPos) && $existingReq->requestor_position !== $reqPos) {
                    DB::table('dts_requestor_history')
                        ->where('id', $existingReq->id)
                        ->update(['requestor_position' => $reqPos]);
                }
            } else {
                $requestorId = DB::table('dts_requestor_history')->insertGetId([
                    'requestor_name' => $reqName,
                    'requestor_position' => $reqPos,
                    'office' => $reqOffice,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $allSystemOffices = DB::table('sys_office')
                ->where('is_active', true)
                ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]'])
                ->pluck('office_code')
                ->toArray();

            $qrCodeId = $this->generatedQrCode;
            $transactionId = 'TRANS-' . strtoupper(Str::random(10));
            $originOfficeCode = $this->userOfficeCode;

            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->transaction_flow)->first();
            
            $originOffice = DB::table('sys_office')->where('office_code', $originOfficeCode)->first();
            $clusterHead = null;
            if ($originOffice && $originOffice->cluster) {
                $cluster = \DB::table('sys_cluster')->where('cluster_code', $originOffice->cluster)->first();
                if ($cluster) {
                    $clusterHead = $cluster->cluster_head;
                }
            }

            $this->flow_offices = $this->ensureOriginBounds($this->flow_offices, $originOfficeCode);

            $resolvedOffices = [];
            foreach ($this->flow_offices as $officeCode) {
                $resolved = $officeCode;
                if ($officeCode === 'ORIGIN') {
                    $resolved = $originOfficeCode;
                } elseif ($officeCode === '[H]') {
                    $resolved = $clusterHead ?: $originOfficeCode;
                } elseif ($officeCode === '[HUB]') {
                    $resolved = '[HUB]';
                }
                
                if (empty($resolvedOffices) || end($resolvedOffices) !== $resolved) {
                    $resolvedOffices[] = $resolved;
                }
            }

            // Always create custom copied flow
            $flowCode = 'FLOW-CUSTOM-' . strtoupper(Str::random(10));
            $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
            $newFlowId = $maxId + 1;

            DB::table('dts_transaction_flow')->insert([
                'flow_code' => $flowCode,
                'flow_name' => 'Flow for ' . $controlNumber . ' (' . $flowCode . ')',
                'id' => $newFlowId,
                'is_active' => 1,
                'added_by' => auth()->id() ?? 1,
                'date_added' => now(),
                'flow_use' => 'issuances',
                'flow_for' => 'system',
                'referenced_flow' => $flow ? ('REF-' . (str_starts_with($flow->flow_code, 'FLOW-PREDEFINED') || str_starts_with($flow->flow_code, 'PREDEFINED') ? 'PREDEFINED' : 'CUSTOM') . '-' . $flow->id) : null,
            ]);

            $sysSettingsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            $autoFwdSetting = DB::table($sysSettingsTbl)->where('key', 'dts_auto_forward_created_transaction')->value('value');
            $shouldAutoForward = ($autoFwdSetting !== 'false') && (count($resolvedOffices) > 1);

            $nextOfficeCode = $resolvedOffices[1] ?? $originOfficeCode;
            $currentOffice = $shouldAutoForward ? $nextOfficeCode : ($resolvedOffices[0] ?? $originOfficeCode);
            $initialSequence = $shouldAutoForward ? 2 : 1;

            foreach ($this->flow_offices as $rank => $officeCode) {
                $toSave = $officeCode;
                if ($officeCode === $originOfficeCode) {
                    $toSave = 'ORIGIN';
                } elseif ($officeCode === $clusterHead) {
                    $toSave = '[H]';
                } elseif ($officeCode === '[HUB]') {
                    $toSave = '[HUB]';
                }

                DB::table('dts_sequence_list')->insert([
                    'control_id' => $newFlowId,
                    'sequence_ranking' => $rank + 1,
                    'office_code' => $toSave,
                    'date_in' => ($rank === 0) ? now() : null,
                    'account_received' => ($rank === 0) ? auth()->id() : null,
                    'date_out' => ($rank === 0 && $shouldAutoForward) ? now() : null,
                    'account_forwarded' => ($rank === 0 && $shouldAutoForward) ? auth()->id() : null,
                    'action_needed' => ($rank === 0) ? 'Created' : null,
                    'note' => ($rank === 0) ? ($shouldAutoForward ? 'Created & auto-forwarded transaction' : 'Created issuance transaction') : null,
                    'total_time_completed' => null,
                ]);
            }

            // Resolve Hub recipient offices
            $finalHubOffices = [];
            if ($this->hasHub && count($this->free_flow_receiving_offices) > 0) {
                foreach ($this->free_flow_receiving_offices as $hOffice) {
                    if ($hOffice === 'ALL') {
                        foreach ($allSystemOffices as $oCode) {
                            if ($oCode !== $originOfficeCode) {
                                $finalHubOffices[] = $oCode;
                            }
                        }
                    } else {
                        $finalHubOffices[] = $hOffice;
                    }
                }
                $finalHubOffices = array_values(array_unique(array_filter($finalHubOffices)));
            }

            // Resolve Copy Furnished offices
            $finalCfOffices = [];
            if ($this->copy_furnished === 'Yes' && count($this->cf_selected_offices) > 0) {
                foreach ($this->cf_selected_offices as $cfOffice) {
                    if ($cfOffice === 'ALL') {
                        foreach ($allSystemOffices as $oCode) {
                            if ($oCode !== $originOfficeCode) {
                                $finalCfOffices[] = $oCode;
                            }
                        }
                    } else {
                        $finalCfOffices[] = $cfOffice;
                    }
                }
                $finalCfOffices = array_values(array_unique(array_filter($finalCfOffices)));
            }

            $allIndexedOffices = array_values(array_unique(array_filter(array_merge($finalHubOffices, $finalCfOffices))));

            $copyFilledId = null;
            if (count($allIndexedOffices) > 0) {
                $assignOfficesId = (DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                $copyFilledId = DB::table('dts_copy_filled_transaction')->insertGetId([
                    'control_num' => $controlNumber,
                    'total_office' => count($allIndexedOffices),
                    'assign_offices_id' => $assignOfficesId,
                    'data_created' => now(),
                    'date_modified' => now(),
                ]);

                foreach ($allIndexedOffices as $offCode) {
                    DB::table('dts_copy_filled_to_office')->insert([
                        'control_id' => $assignOfficesId,
                        'office_code' => $offCode,
                    ]);
                }
            }

            // Insert into dts_transactions
            DB::table('dts_transactions')->insert([
                'transaction_id' => $transactionId,
                'enable_notif' => 1,
                'trans_type' => 'memorandom',
                'doc_dir' => null,
                'qr_code' => $qrCodeId,
                'current_office' => $currentOffice,
                'status' => 'ongoing',
                'sequence' => $initialSequence,
            ]);

            // Insert into dts_transaction_details
            DB::table('dts_transaction_details')->insert([
                'id' => $transactionId,
                'type' => 'memorandom',
                'created_by' => auth()->id(),
                'originated_from' => $originOfficeCode,
                'requestor_id' => $requestorId,
                'source_office' => null,
                'subject' => $this->subject,
                'classification' => null,
                'action_needed' => 'For action / dissemination',
                'current_office_hold' => $currentOffice,
                'status' => 'ongoing',
                'document_password' => null,
                'email_access' => null,
                'transaction_flow' => $flowCode,
                'is_active' => 1,
                'date_created' => now(),
                'control_number' => $controlNumber,
                'copy_filled_id' => $copyFilledId ?: null,
            ]);

            $originOfficeName = DB::table('sys_office')->where('office_code', $originOfficeCode)->value('office_name') ?: $originOfficeCode;

            // Step 1 log: Origin Office
            DB::table('dts_transaction_logs')->insert([
                'transaction_id' => $transactionId,
                'office_code' => $originOfficeCode,
                'type' => 'received',
                'date_in' => now(),
                'date_out' => $shouldAutoForward ? now() : null,
                'notes' => $shouldAutoForward ? 'Created & auto-forwarded transaction' : 'Created issuance transaction',
                'performed_by' => auth()->id(),
            ]);

            if ($shouldAutoForward) {
                if ($nextOfficeCode === '[HUB]' && count($finalHubOffices) > 0) {
                    // Office 1 uses the original transaction
                    $primaryHubOffice = $finalHubOffices[0];
                    DB::table('dts_transactions')->where('transaction_id', $transactionId)->update(['current_office' => $primaryHubOffice]);
                    DB::table('dts_transaction_details')->where('id', $transactionId)->update(['current_office_hold' => $primaryHubOffice]);

                    DB::table('dts_transaction_logs')->insert([
                        'transaction_id' => $transactionId,
                        'office_code' => $primaryHubOffice,
                        'type' => 'forwarded',
                        'date_in' => null,
                        'date_out' => null,
                        'notes' => 'Dispatched via Office Hub [HUB] (Primary)',
                        'performed_by' => auth()->id(),
                    ]);
                    \App\Services\DtsNotificationService::notifyWaitingToBeReceived($primaryHubOffice, $controlNumber, $transactionId);

                    // Offices 2..N are divided into child transactions with hyphenated suffixes (-1, -2, ...)
                    for ($i = 1; $i < count($finalHubOffices); $i++) {
                        $childOffice = $finalHubOffices[$i];
                        $childSeq = $this->seq_number . '-' . $i;
                        $childControlNumber = $this->issuance_type . '-' . now()->format('Y-m') . '-' . $childSeq;
                        $childQrCode = \App\Services\DtsQrCodeService::generate($this->issuance_type, $childSeq);
                        $childTransId = 'TRANS-' . strtoupper(Str::random(10));

                        // Insert child into dts_transactions
                        DB::table('dts_transactions')->insert([
                            'transaction_id' => $childTransId,
                            'enable_notif' => 1,
                            'trans_type' => 'memorandom',
                            'doc_dir' => null,
                            'qr_code' => $childQrCode,
                            'current_office' => $childOffice,
                            'status' => 'ongoing',
                            'sequence' => $initialSequence,
                        ]);

                        // Insert child into dts_transaction_details
                        DB::table('dts_transaction_details')->insert([
                            'id' => $childTransId,
                            'type' => 'memorandom',
                            'created_by' => auth()->id(),
                            'originated_from' => $originOfficeCode,
                            'requestor_id' => $requestorId,
                            'source_office' => null,
                            'subject' => $this->subject,
                            'classification' => null,
                            'action_needed' => 'For action / dissemination',
                            'current_office_hold' => $childOffice,
                            'status' => 'ongoing',
                            'document_password' => null,
                            'email_access' => null,
                            'transaction_flow' => $flowCode,
                            'is_active' => 1,
                            'date_created' => now(),
                            'control_number' => $childControlNumber,
                            'copy_filled_id' => $copyFilledId ?: null,
                        ]);

                        // Step 1 log: Origin office
                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $childTransId,
                            'office_code' => $originOfficeCode,
                            'type' => 'received',
                            'date_in' => now(),
                            'date_out' => now(),
                            'notes' => 'Created & auto-forwarded child transaction',
                            'performed_by' => auth()->id(),
                        ]);

                        // Step 2 log: Child recipient office
                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $childTransId,
                            'office_code' => $childOffice,
                            'type' => 'forwarded',
                            'date_in' => null,
                            'date_out' => null,
                            'notes' => 'Dispatched via Office Hub [HUB] (Child Branch #' . $i . ')',
                            'performed_by' => auth()->id(),
                        ]);

                        \App\Services\DtsNotificationService::notifyWaitingToBeReceived($childOffice, $childControlNumber, $childTransId);
                    }
                } else {
                    DB::table('dts_transaction_logs')->insert([
                        'transaction_id' => $transactionId,
                        'office_code' => $nextOfficeCode,
                        'type' => 'forwarded',
                        'date_in' => null,
                        'date_out' => null,
                        'notes' => 'Forwarded from ' . $originOfficeName,
                        'performed_by' => auth()->id(),
                    ]);
                    \App\Services\DtsNotificationService::notifyWaitingToBeReceived($nextOfficeCode, $controlNumber, $transactionId);
                }

                $userFirstName = auth()->user()?->details?->first_name ?: (auth()->user()?->username ?: 'User');
                \App\Services\DtsNotificationService::notifyForwarded($originOfficeCode, $userFirstName, $controlNumber, $transactionId);
            }

            DB::commit();

            // Save summary for modal
            $this->createdTransactionSummary = [
                'control_number' => $controlNumber,
                'subject' => $this->subject,
                'requestor' => $reqName,
                'requestor_position' => $reqPos,
                'qr_code' => $this->generatedQrCode,
                'office' => $this->userOfficeCode,
                'type' => 'Issuance (' . $this->issuance_type . ')' . ($this->hasHub ? ' - Hub Multi-Mode' : ' - Linear'),
            ];

            // Reset form
            $this->seq_number = '';
            $this->subject = '';
            $this->transaction_flow = '';
            $this->receiving_office = '';
            $this->copy_furnished = 'Yes';
            $this->cf_selected_offices = [];
            $this->free_flow_receiving_offices = [];
            $this->receiving_search = '';
            $this->cf_search = '';
            $this->generatedQrCode = null;
            $this->availabilityMessage = '';
            $this->isAvailable = null;
            $this->flow_offices = [];

            $this->showSuccessModal = true;
            $this->dispatch('dts-transaction-updated');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->addError('seq_number', 'Error creating transaction: ' . $e->getMessage());
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/dts/create.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Issuances</h2>
    </div>

    <!-- Issuances Form -->
    <form class="rms-form" wire:submit.prevent="save">
            
            <!-- Form Fields -->
            <div>
                    <!-- Control Number Selection Dropdown and Input -->
                    <div class="control-wrapper" style="margin-bottom: 20px;">
                        <label class="control-label">Control Number Type:</label>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <select wire:model.live="issuance_type" class="select-input" style="max-width: 240px; height: 32px; padding: 2px 6px;">
                                <option value="NM">Numbered Memo (NM)</option>
                                <option value="AM">Admin Memo (AM)</option>
                                <option value="EM">Executive Memo (EM)</option>
                                <option value="TO">Travel Order (TO)</option>
                                <option value="OM">Office Memo (OM)</option>
                                <option value="TR">Transmittal (TR)</option>
                                <option value="EN">Endorsement (EN)</option>
                                <option value="DES">Designation (DES)</option>
                                <option value="TA">Travel Authority (TA)</option>
                                <option value="AO">Admin Order (AO)</option>
                            </select>

                            @if(auth()->user()?->permissions?->can_dts_modify_control_no)
                                <div style="display: flex; align-items: center; max-width: 300px; border: 1px solid #ced4da; border-radius: 4px; overflow: hidden; background: #e9ecef; height: 32px; box-sizing: border-box;">
                                    <span style="padding: 0 10px; font-family: 'Inter', sans-serif; font-size: 13px; color: #495057; font-weight: 600; border-right: 1px solid #ced4da; user-select: none; line-height: 30px;">
                                        {{ $issuance_type }}-{{ now()->format('Y-m') }}-
                                    </span>
                                    <input type="text" wire:model.live="seq_number" class="text-input" placeholder="0001" style="flex: 1; border: none; height: 100%; padding: 0 8px; font-size: 13px; background: transparent; outline: none; box-shadow: none;">
                                </div>
                                @if(empty($seq_number))
                                    <button type="button" wire:click="generateRandomSeq" class="btn-primary" style="padding: 0 12px; height: 32px; font-size: 12px; background-color: #3b82f6; border-radius: 4px;">
                                        Generate
                                    </button>
                                @else
                                    <button type="button" wire:click="checkAvailability" class="btn-primary" style="padding: 0 12px; height: 32px; font-size: 12px; background-color: #4b5563; border-radius: 4px;">
                                        Check Availability
                                    </button>
                                @endif
                            @else
                                <span style="font-size: 13px; font-weight: 600; color: #4b5563; font-family: 'Inter', sans-serif;">-{{ now()->format('Y-m') }}-{{ $seq_number ?: 'Auto' }}</span>
                            @endif
                        </div>
                        @if(auth()->user()?->permissions?->can_dts_modify_control_no)
                            @if($availabilityMessage)
                                <span style="font-size: 12px; margin-top: 4px; display: block; font-weight: 600; color: {{ $isAvailable ? '#10b981' : '#dc2626' }};">
                                    {{ $availabilityMessage }}
                                </span>
                            @endif
                            @error('seq_number')
                                <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                            @enderror
                        @endif
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

                <!-- Transaction Path field -->
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
                        <a href="#" wire:click.prevent="openCustomFlowCreator" style="font-size: 11.5px; color: #2563eb; text-decoration: none; font-weight: 600; margin-top: 4px; display: inline-block;">Flow Can't be found?</a>
                    </div>
                </div>

                <!-- DYNAMIC OFFICE HUB [HUB] RECEIVING BOX (Visible only when [HUB] is in flow) -->
                @if($this->hasHub)
                    <div class="form-row">
                        <div class="form-col" style="max-width: 550px; padding: 16px; border: 1.5px solid #93c5fd; border-radius: 8px; background-color: #eff6ff; margin-bottom: 16px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                <span style="background: #2563eb; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;">
                                    <i class="fa-solid fa-bolt"></i> [HUB] Office Hub
                                </span>
                                <span style="font-size: 12px; color: #1e40af; font-weight: 600;">Multi-Mode Broadcast Recipients</span>
                            </div>

                            <p style="font-size: 11.5px; color: #3b82f6; margin-top: 0; margin-bottom: 10px; line-height: 1.4;">
                                When this transaction reaches the <strong>[HUB]</strong> stage in the routing sequence, it will broadcast simultaneously to all designated receiving offices below.
                            </p>

                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <label class="input-label" style="margin: 0; font-weight: 700; color: #0f172a;">
                                    Receiving Offices for [HUB] <span style="color: #dc2626;">*</span>
                                </label>
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" wire:click="selectAllReceivingOffices" style="background: none; border: none; font-size: 11.5px; color: #2563eb; font-weight: 600; cursor: pointer; text-decoration: underline;">Select All Units</button>
                                    @if(count($free_flow_receiving_offices) > 0)
                                        <button type="button" wire:click="clearReceivingOffices" style="background: none; border: none; font-size: 11.5px; color: #dc2626; font-weight: 600; cursor: pointer; text-decoration: underline;">Clear</button>
                                    @endif
                                </div>
                            </div>
                            
                            @if(count($free_flow_receiving_offices) > 0)
                                <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                                    @foreach($free_flow_receiving_offices as $index => $code)
                                        @php
                                            $rList = array_merge([
                                                ['office_code' => 'ALL', 'office_name' => 'All Offices / Units']
                                            ], $offices);
                                            $officeName = collect($rList)->firstWhere('office_code', $code)['office_name'] ?? $code;
                                        @endphp
                                        <span class="badge" style="display: inline-flex; align-items: center; gap: 6px; background-color: #ffffff; color: #1e40af; border: 1px solid #bfdbfe; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                            <i class="fa-solid fa-building" style="font-size: 10px; color: #3b82f6;"></i>
                                            {{ $officeName }}
                                            <button type="button" wire:click="removeFreeFlowOffice({{ $index }})" style="border: none; background: none; color: #64748b; cursor: pointer; font-weight: bold; font-size: 13px; padding: 0 2px; line-height: 1;">&times;</button>
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <div style="position: relative;">
                                <input type="text" 
                                    wire:model.live="receiving_search" 
                                    class="text-input" 
                                    placeholder="Search office code or name to add to [HUB]..." 
                                    style="width: 100%; font-size: 12.5px; padding: 7px 10px; border-radius: 4px; border: 1px solid #cbd5e1; background: #fff;"
                                >
                                
                                @if(!empty($receiving_search))
                                    @php
                                        $userOfficeCode = $this->userOfficeCode;
                                        $rList = array_merge([
                                            ['office_code' => 'ALL', 'office_name' => 'All Offices / Units']
                                        ], $offices);

                                        $filteredReceiving = array_filter($rList, function($office) use ($free_flow_receiving_offices, $userOfficeCode) {
                                            return !in_array($office['office_code'], $free_flow_receiving_offices) && $office['office_code'] !== $userOfficeCode && !in_array($office['office_code'], ['ORIGIN', '[H]', '[HUB]']);
                                        });
                                        
                                        $searchLower = strtolower($receiving_search);
                                        $filteredReceiving = array_filter($filteredReceiving, function($office) use ($searchLower) {
                                            return str_contains(strtolower($office['office_code']), $searchLower) ||
                                                   str_contains(strtolower($office['office_name']), $searchLower);
                                        });
                                    @endphp

                                    <div style="position: absolute; top: 38px; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; max-height: 200px; overflow-y: auto; z-index: 50; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                                        @if(count($filteredReceiving) > 0)
                                            @foreach($filteredReceiving as $office)
                                                <button type="button" 
                                                    wire:click="selectFreeFlowOffice('{{ $office['office_code'] }}')" 
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
                            @error('free_flow_receiving_offices')
                                <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                @else
                    <!-- Optional Single Receiving Office when no [HUB] in flow -->
                    <div class="form-row">
                        <div class="form-col small-input">
                            <label class="input-label">Receiving Office(s)</label>
                            <select wire:model="receiving_office" class="select-input">
                                <option value="">Receiving Office(s)</option>
                                <option value="All Units">All Units</option>
                                @foreach($offices as $office)
                                    <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }} ({{ $office['office_code'] }})</option>
                                @endforeach
                            </select>
                            @error('receiving_office')
                                <span class="error-msg" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: block;">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>
                @endif

                <!-- Copy Furnished Selector Box -->
                <div class="form-row">
                    <div class="form-col" style="max-width: 550px; padding: 16px; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #f8fafc; margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <label class="input-label" style="margin: 0; font-weight: 700; color: #0f172a;">Copy Furnished Offices</label>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" wire:click="selectAllCfOffices" style="background: none; border: none; font-size: 11.5px; color: #2563eb; font-weight: 600; cursor: pointer; text-decoration: underline;">Select All Units</button>
                                @if(count($cf_selected_offices) > 0)
                                    <button type="button" wire:click="clearCfOffices" style="background: none; border: none; font-size: 11.5px; color: #dc2626; font-weight: 600; cursor: pointer; text-decoration: underline;">Clear</button>
                                @endif
                            </div>
                        </div>
                        
                        @if(count($cf_selected_offices) > 0)
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                                @foreach($cf_selected_offices as $index => $code)
                                    @php
                                        $cfList = array_merge([
                                            ['office_code' => 'ALL', 'office_name' => 'All Office']
                                        ], $offices);
                                        $officeName = collect($cfList)->firstWhere('office_code', $code)['office_name'] ?? $code;
                                    @endphp
                                    <span class="badge" style="display: inline-flex; align-items: center; gap: 4px; background-color: #e2e8f0; color: #334155; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">
                                        {{ $officeName }}
                                        <button type="button" wire:click="removeCfOffice({{ $index }})" style="border: none; background: none; color: #64748b; cursor: pointer; font-weight: bold; font-size: 12px; padding: 0 2px;">&times;</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <div style="position: relative;">
                            <input type="text" 
                                wire:model.live="cf_search" 
                                class="text-input" 
                                placeholder="Search office code or name..." 
                                style="width: 100%; font-size: 12.5px; padding: 6px 10px; border-radius: 4px; border: 1px solid #cbd5e1;"
                            >
                            
                            <!-- Dropdown results list -->
                            @if(!empty($cf_search))
                                @php
                                    $userOfficeCode = $this->userOfficeCode;
                                    $cfList = array_merge([
                                        ['office_code' => 'ALL', 'office_name' => 'All Office']
                                    ], $offices);

                                    $filteredOffices = array_filter($cfList, function($office) use ($cf_selected_offices, $userOfficeCode) {
                                        return !in_array($office['office_code'], $cf_selected_offices) && $office['office_code'] !== $userOfficeCode;
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

            </div>

            <!-- Submit button -->
            <div class="actions-row" style="display: flex; gap: 12px; align-items: center; margin-top: 24px; clear: both;">
                @if (session()->has('error'))
                    <span style="color: #dc2626; font-size: 13px; align-self: center; margin-right: 15px;">{{ session('error') }}</span>
                @endif
                <button type="submit" class="btn-primary" style="background-color: #3b82f6; border-radius: 4px; padding: 10px 24px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);">
                    <i class="fa-solid fa-plus"></i> CREATE TRANSACTION
                </button>
            </div>
        </form>
        <!-- Dynamic QR Code Print Modal moved to root -->

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
                                                    $specialOffices = [
                                                        ['office_code' => '[HUB]', 'office_name' => 'Office Hub [Multi-Receiving]'],
                                                        ['office_code' => 'ORIGIN', 'office_name' => 'Originated Office'],
                                                        ['office_code' => '[H]', 'office_name' => 'Cluster Head'],
                                                    ];
                                                    $allAvailable = array_merge($specialOffices, $offices);
                                                    $filtered = collect($allAvailable)->filter(function($off) {
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
                                                        <span style="font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">
                                                            @if($off['office_code'] === '[HUB]') ⚡ @endif {{ $off['office_name'] }}
                                                        </span>
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
                                $isHub = ($code === '[HUB]');
                                $officeName = $isHub ? 'Office Hub [Multi-Receiving]' : (collect($offices)->firstWhere('office_code', $code)['office_name'] ?? $code);
                            @endphp
                            <div style="padding: 10px 14px; background: {{ $isHub ? '#eff6ff' : '#f8fafc' }}; border: 1px solid {{ $isHub ? '#93c5fd' : '#cbd5e1' }}; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: {{ $isHub ? '#2563eb' : '#3b82f6' }}; color: #ffffff; font-size: 11px; font-weight: bold;">
                                        {{ $i + 1 }}
                                    </span>
                                    <span style="font-size: 13px; font-weight: 600; color: {{ $isHub ? '#1e40af' : '#1e293b' }};">
                                        @if($isHub) <i class="fa-solid fa-bolt" style="color: #2563eb; margin-right: 4px;"></i> @endif
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
                                                $specialOffices = [
                                                    ['office_code' => '[HUB]', 'office_name' => 'Office Hub [Multi-Receiving]'],
                                                    ['office_code' => 'ORIGIN', 'office_name' => 'Originated Office'],
                                                    ['office_code' => '[H]', 'office_name' => 'Cluster Head'],
                                                ];
                                                $allAvailable = array_merge($specialOffices, $offices);
                                                $filtered = collect($allAvailable)->filter(function($off) {
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
                                                    <span style="font-weight: 500; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;">
                                                        @if($off['office_code'] === '[HUB]') ⚡ @endif {{ $off['office_name'] }}
                                                    </span>
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

    <!-- Custom Flow Creator Modal -->
    @if($showCustomFlowModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 16px; font-family: 'Inter', sans-serif;">
            <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 620px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e2e8f0; animation: modalEnter 0.3s ease-out;">
                <!-- Header -->
                <div style="padding: 18px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fafafa;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-route" style="font-size: 14px;"></i>
                        </div>
                        <div>
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;">Create Custom Flow</h3>
                            <span style="font-size: 11.5px; color: #64748b;">Build a custom routing sequence for your documents</span>
                        </div>
                    </div>
                    <button type="button" wire:click="$set('showCustomFlowModal', false)" style="background: none; border: none; color: #94a3b8; font-size: 20px; cursor: pointer; padding: 4px; line-height: 1; outline: none; transition: color 0.15s;" onmouseover="this.style.color='#475569'" onmouseout="this.style.color='#94a3b8'">&times;</button>
                </div>

                <!-- Body -->
                <div style="padding: 24px; display: flex; flex-direction: column; gap: 18px; max-height: 480px; overflow-y: auto;">
                    <!-- Type of Document / Flow Name -->
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 12.5px; font-weight: 600; color: #334155;">Type of Document (Flow Name)</label>
                        <input type="text" wire:model="customFlowDocType" placeholder="e.g. Clearance Form, Requisition Request" style="width: 100%; height: 38px; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13.5px; outline: none; transition: border-color 0.15s;" onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('customFlowDocType') <span style="font-size: 11.5px; color: #ef4444; font-weight: 500;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Who can use this flow (Visibility) -->
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 12.5px; font-weight: 600; color: #334155;">Who can use this flow?</label>
                        <select wire:model="customFlowFor" style="width: 100%; height: 38px; padding: 0 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #ffffff;">
                            <option value="user">Only Me</option>
                            @if(auth()->user()?->details?->office_id)
                                <option value="office">My Office</option>
                            @endif
                        </select>
                        @error('customFlowFor') <span style="font-size: 11.5px; color: #ef4444; font-weight: 500;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Flow Sequence Selector -->
                    <div style="display: flex; flex-direction: column; gap: 8px; padding: 16px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                        <label style="font-size: 12.5px; font-weight: 600; color: #334155; margin-bottom: 2px;">Add Offices to Routing Sequence</label>
                        <div style="display: flex; gap: 8px; width: 100%;">
                            <select wire:model="customFlowSelectedOffice" style="flex: 1; min-width: 0; height: 38px; padding: 0 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #ffffff; overflow: hidden; text-overflow: ellipsis;">
                                <option value="">Select an Office...</option>
                                <option value="[HUB]">⚡ [HUB] (Office Hub [Multi-Receiving])</option>
                                <option value="ORIGIN">ORIGIN (Your Current Office)</option>
                                <option value="[H]">[H] (Your Cluster Head Office)</option>
                                @foreach($offices as $off)
                                    @if(!in_array($off['office_code'], ['ORIGIN', '[H]', '[HUB]']))
                                        <option value="{{ $off['office_code'] }}">{{ $off['office_name'] }} ({{ $off['office_code'] }})</option>
                                    @endif
                                @endforeach
                            </select>
                            <button type="button" wire:click="addToCustomFlowSequence" style="background: #3b82f6; color: #ffffff; border: none; padding: 0 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.backgroundColor='#2563eb'" onmouseout="this.style.backgroundColor='#3b82f6'">Add</button>
                        </div>
                    </div>

                    <!-- Visualized Current Sequence -->
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <label style="font-size: 12.5px; font-weight: 600; color: #334155;">Routing Sequence Path</label>
                        @if(count($customFlowSequence) === 0)
                            <div style="text-align: center; padding: 30px; border: 2px dashed #cbd5e1; border-radius: 12px; color: #94a3b8; font-size: 13px;">
                                <i class="fa-solid fa-route" style="font-size: 24px; margin-bottom: 8px; display: block; color: #cbd5e1;"></i>
                                No offices added to the routing sequence yet.
                            </div>
                        @else
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                @foreach($customFlowSequence as $idx => $code)
                                    @php
                                        $isHub = ($code === '[HUB]');
                                        $name = $isHub ? 'Office Hub [Multi-Receiving]' : ($code === 'ORIGIN' ? 'ORIGIN (Your Current Office)' : ($code === '[H]' ? '[H] (Your Cluster Head Office)' : (collect($offices)->firstWhere('office_code', $code)['office_name'] ?? $code)));
                                    @endphp
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: {{ $isHub ? '#eff6ff' : '#ffffff' }}; border: 1.5px solid {{ $isHub ? '#93c5fd' : '#e2e8f0' }}; border-radius: 10px; transition: border-color 0.15s;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="width: 22px; height: 22px; border-radius: 50%; background: {{ $isHub ? '#2563eb' : '#3b82f6' }}; color: #ffffff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center;">{{ $idx + 1 }}</span>
                                            <div>
                                                <span style="font-size: 13px; font-weight: 600; color: {{ $isHub ? '#1e40af' : '#1e293b' }}; display: block;">
                                                    @if($isHub) <i class="fa-solid fa-bolt" style="color: #2563eb; margin-right: 4px;"></i> @endif
                                                    {{ $name }}
                                                </span>
                                                <span style="font-size: 11px; color: #64748b; font-weight: 500;">Code: {{ $code }}</span>
                                            </div>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <button type="button" wire:click="moveUpCustomFlowSequence({{ $idx }})" {{ $idx === 0 ? 'disabled' : '' }} style="background: none; border: none; color: {{ $idx === 0 ? '#cbd5e1' : '#64748b' }}; cursor: {{ $idx === 0 ? 'not-allowed' : 'pointer' }}; padding: 6px; border-radius: 6px; transition: background 0.15s;" onmouseover="if({{ $idx !== 0 ? 'true' : 'false' }}) this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                                <i class="fa-solid fa-arrow-up" style="font-size: 11px;"></i>
                                            </button>
                                            <button type="button" wire:click="moveDownCustomFlowSequence({{ $idx }})" {{ $idx === count($customFlowSequence) - 1 ? 'disabled' : '' }} style="background: none; border: none; color: {{ $idx === count($customFlowSequence) - 1 ? '#cbd5e1' : '#64748b' }}; cursor: {{ $idx === count($customFlowSequence) - 1 ? 'not-allowed' : 'pointer' }}; padding: 6px; border-radius: 6px; transition: background 0.15s;" onmouseover="if({{ $idx !== count($customFlowSequence) - 1 ? 'true' : 'false' }}) this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                                <i class="fa-solid fa-arrow-down" style="font-size: 11px;"></i>
                                            </button>
                                            <button type="button" wire:click="removeFromCustomFlowSequence({{ $idx }})" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 6px; border-radius: 6px; transition: background 0.15s;" onmouseover="this.style.backgroundColor='#fef2f2'" onmouseout="this.style.backgroundColor='transparent'">
                                                <i class="fa-solid fa-trash-can" style="font-size: 11px;"></i>
                                            </button>
                                        </div>
                                    </div>
                                    @if($idx < count($customFlowSequence) - 1)
                                        <div style="display: flex; justify-content: center; margin: -4px 0; color: #cbd5e1;">
                                            <i class="fa-solid fa-arrow-down" style="font-size: 12px;"></i>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                        @error('customFlowSequence') <span style="font-size: 11.5px; color: #ef4444; font-weight: 500;">{{ $message }}</span> @enderror
                    </div>

                    @if(in_array('[HUB]', $customFlowSequence))
                        <!-- Predefined HUB Receiving Offices Selection for this Custom Flow -->
                        <div style="display: flex; flex-direction: column; gap: 8px; padding: 16px; background: rgba(245, 158, 11, 0.05); border-radius: 12px; border: 1px solid rgba(245, 158, 11, 0.25);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <label style="font-size: 12.5px; font-weight: 700; color: #b45309; display: flex; align-items: center; gap: 6px; margin: 0;">
                                    <i class="fa-solid fa-layer-group"></i> Predefined HUB Receiving Offices
                                </label>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" wire:click="selectAllCustomFlowHubOffices" style="padding: 3px 8px; font-size: 11px; font-weight: 600; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 6px; cursor: pointer;">
                                        Select All
                                    </button>
                                    @if(count($customFlowHubOffices) > 0)
                                        <button type="button" wire:click="clearCustomFlowHubOffices" style="padding: 3px 8px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 6px; cursor: pointer;">
                                            Clear
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <span style="font-size: 11px; color: #64748b;">Specify target receiving offices to be pre-selected when this custom flow is chosen:</span>
                            
                            <div style="display: flex; gap: 8px; width: 100%;">
                                <select wire:model="customFlowSelectedHubOffice" style="flex: 1; min-width: 0; height: 38px; padding: 0 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; background: #ffffff;">
                                    <option value="">Select a Hub Recipient Office...</option>
                                    <option value="ALL">🌟 ALL OFFICES (Broadcast to All)</option>
                                    @foreach($offices as $off)
                                        @if(!in_array($off['office_code'], ['ORIGIN', '[H]', '[HUB]']))
                                            <option value="{{ $off['office_code'] }}">{{ $off['office_name'] }} ({{ $off['office_code'] }})</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="button" wire:click="selectCustomFlowHubOffice(customFlowSelectedHubOffice)" style="background: #f59e0b; color: #ffffff; border: none; padding: 0 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">Add</button>
                            </div>

                            <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; padding: 8px; background: #ffffff; border: 1px solid #fde68a; border-radius: 8px; min-height: 38px;">
                                @forelse($customFlowHubOffices as $idx => $code)
                                    @php
                                        $isAll = ($code === 'ALL');
                                        $name = $isAll ? '🌟 ALL OFFICES' : (collect($offices)->firstWhere('office_code', $code)['office_name'] ?? $code);
                                    @endphp
                                    <div style="display: inline-flex; align-items: center; gap: 6px; background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 16px; font-size: 11.5px; font-weight: 600; border: 1px solid #fcd34d;">
                                        <span>{{ $name }} ({{ $code }})</span>
                                        <button type="button" wire:click="removeCustomFlowHubOffice({{ $idx }})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 13px; font-weight: bold; line-height: 1; padding: 0;">&times;</button>
                                    </div>
                                @empty
                                    <div style="font-size: 11.5px; color: #94a3b8; font-style: italic; display: flex; align-items: center; justify-content: center; width: 100%;">
                                        No hub receiving offices selected yet.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Footer -->
                <div style="padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px; background: #fafafa;">
                    <button type="button" wire:click="$set('showCustomFlowModal', false)" style="background: #ffffff; border: 1.5px solid #cbd5e1; color: #334155; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#ffffff'">Cancel</button>
                    <button type="button" wire:click="saveCustomFlow" style="background: #10b981; border: none; color: #ffffff; padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.backgroundColor='#059669'" onmouseout="this.style.backgroundColor='#10b981'">Save & Select Flow</button>
                </div>
            </div>
        </div>
        <style>
            @keyframes modalEnter {
                from { transform: scale(0.95); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
        </style>
    @endif

    <!-- Toast Alert message -->
    @if(!empty($toastMessage))
        <div style="position: fixed; bottom: 20px; left: 20px; z-index: 99999; background: #ef4444; color: #fff; padding: 12px 18px; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); display: flex; align-items: center; gap: 10px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500; animation: toastSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); max-width: 380px;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size: 16px; color: #fee2e2;"></i>
            <span style="line-height: 1.4;">{{ $toastMessage }}</span>
            <button type="button" wire:click="$set('toastMessage', '')" style="background: none; border: none; color: #fee2e2; font-size: 18px; cursor: pointer; margin-left: auto; outline: none; padding: 0 4px; display: flex; align-items: center; justify-content: center; height: 20px; width: 20px; border-radius: 50%;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='transparent'">&times;</button>
        </div>
        <script>
            setTimeout(() => {
                @this.set('toastMessage', '');
            }, 6000);
        </script>
        <style>
            @keyframes toastSlideIn {
                from { transform: translateX(-100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        </style>
    @endif

    <!-- Success Modal with Print QR Code -->
    @if($showSuccessModal && !empty($createdTransactionSummary))
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 10000; font-family: 'Inter', sans-serif;">
            <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e2e8f0;">
                
                <!-- Success Header -->
                <div style="padding: 24px 24px 16px 24px; text-align: center; background: #f0fdf4; border-bottom: 1px solid #dcfce7;">
                    <div style="width: 56px; height: 56px; border-radius: 50%; background: #22c55e; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 12px auto; box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <h3 style="font-size: 18px; font-weight: 700; color: #15803d; margin: 0 0 4px 0;">Transaction Created Successfully!</h3>
                    <p style="font-size: 12px; color: #166534; margin: 0;">Control No: <strong>{{ $createdTransactionSummary['control_number'] }}</strong></p>
                </div>

                <!-- Modal Content Details -->
                <div style="padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; align-items: center;">
                    <!-- QR Code Image -->
                    <div style="padding: 12px; background: #ffffff; border: 2px solid #e2e8f0; border-radius: 12px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={{ urlencode($createdTransactionSummary['qr_code']) }}" alt="QR Code" style="width: 160px; height: 160px; display: block; margin: 0 auto 8px auto;">
                        <div style="font-size: 13px; font-weight: 700; color: #0f172a; font-family: monospace;">{{ $createdTransactionSummary['control_number'] }}</div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">{{ $createdTransactionSummary['office'] }} • {{ $createdTransactionSummary['type'] }}</div>
                    </div>

                    <!-- Summary Table -->
                    <div style="width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 12px;">
                        <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #f1f5f9;">
                            <span style="color: #64748b; font-weight: 500;">Issuer/Encoder:</span>
                            <span style="color: #0f172a; font-weight: 600;">{{ $createdTransactionSummary['requestor'] }} @if(!empty($createdTransactionSummary['requestor_position'])) ({{ $createdTransactionSummary['requestor_position'] }}) @endif</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #f1f5f9;">
                            <span style="color: #64748b; font-weight: 500;">Originating Office:</span>
                            <span style="color: #0f172a; font-weight: 600;">{{ $createdTransactionSummary['office'] }}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 4px 0;">
                            <span style="color: #64748b; font-weight: 500;">Subject:</span>
                            <span style="color: #0f172a; font-weight: 600; max-width: 260px; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $createdTransactionSummary['subject'] }}</span>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div style="padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fafafa;">
                    <button type="button" onclick="if(window.openDynamicPrintModal) { openDynamicPrintModal('{{ $createdTransactionSummary['qr_code'] }}'); } else { printQrCodeOnly(); }" style="background: #0284c7; border: none; color: #ffffff; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-print"></i> Print QR Code
                    </button>
                    <button type="button" wire:click="closeSuccessModal" style="background: #475569; border: none; color: #ffffff; padding: 9px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                        Done / Create Another
                    </button>
                </div>
            </div>
        </div>

        <script>
            function printQrCodeOnly() {
                const controlNo = "{{ $createdTransactionSummary['control_number'] }}";
                const office = "{{ $createdTransactionSummary['office'] }}";
                const type = "{{ $createdTransactionSummary['type'] }}";
                const subject = "{{ addslashes($createdTransactionSummary['subject']) }}";
                const requestor = "{{ addslashes($createdTransactionSummary['requestor']) }}";
                const qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={{ urlencode(base64_encode($createdTransactionSummary['qr_code'])) }}";

                const printWin = window.open('', '_blank', 'width=600,height=600');
                printWin.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Print QR Code - \${controlNo}</title>
                        <style>
                            body { font-family: 'Inter', sans-serif; text-align: center; padding: 40px; }
                            .print-box { border: 2px solid #000; padding: 24px; border-radius: 12px; display: inline-block; max-width: 350px; }
                            .qr-img { width: 200px; height: 200px; }
                            .ctrl-no { font-size: 18px; font-weight: bold; font-family: monospace; margin-top: 12px; }
                            .meta { font-size: 12px; color: #333; margin-top: 6px; }
                            @media print {
                                body { padding: 0; }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="print-box">
                            <img src="\${qrUrl}" class="qr-img" />
                        </div>
                        <script>
                            window.onload = function() { window.print(); window.close(); }
                        <\/script>
                    </body>
                    </html>
                `);
                printWin.document.close();
            }
        </script>
    @endif

    <!-- Dynamic QR Code Print Modal -->
    @include('components.dts.qr-print-modal')
</div>
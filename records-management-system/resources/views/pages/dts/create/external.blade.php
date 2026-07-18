<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create External Transaction')] class extends Component {
    public bool $enableBeta = false;
    public string $availabilityMessage = '';
    public ?bool $isAvailable = null;

    public string $seq_number = '';
    public string $source_office = '';
    public string $requestor_name = '';
    public string $requestor_label = '';
    public string $subject = '';
    public string $transaction_flow = '';
    public string $copy_furnished = 'Yes';

    public array $cf_selected_offices = [];
    public string $cf_search = '';

    public array $offices = [];
    public array $flows = [];

    // Custom flow creator fields
    public bool $showCustomFlowModal = false;
    public string $customFlowDocType = '';
    public array $customFlowSequence = [];
    public string $customFlowSelectedOffice = '';
    public string $customFlowFor = 'user';
    public string $toastMessage = '';

    // Email Access & Password management fields
    public bool $showEmailAccessModal = false;
    public string $email_access_input = '';
    public string $document_password_input = '';

    public function toggleEmailAccessModal(): void
    {
        $this->showEmailAccessModal = !$this->showEmailAccessModal;
    }

    // Flow Diagram modal states
    public bool $showFlowModal = false;
    public array $flow_offices = [];
    public ?int $selected_gap_index = null;
    public string $insert_office_code = '';
    public string $insert_office_search = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if ($perms && !$perms->is_sadm && !$perms->can_dts_use_external) {
            abort(403, 'Unauthorized access to External transactions.');
        }

        $this->enableBeta = session('enable_beta', false);
        $this->offices = DB::table('office')
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]'])
            ->orderBy('office_name')
            ->get()
            ->map(fn($o) => (array)$o)
            ->toArray();

        $userOfficeId = auth()->user()?->details?->office_id;
        $this->flows = DB::table('dts_transaction_flow')
            ->where('is_active', true)
            ->whereIn('flow_use', ['external', 'none'])
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
                                  ->from('account_details')
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

        $userOfficeCode = auth()->user()?->details?->office?->office_code;
        if ($userOfficeCode) {
            $this->source_office = $userOfficeCode;
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

        $controlNumber = 'EXT-' . now()->format('Y-m') . '-' . $this->seq_number;

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
        $prefix = 'EXT-' . now()->format('Y-m') . '-';
        
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

    public function updatedSourceOffice(): void
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

            $originOfficeCode = $this->source_office;
            $originOffice = DB::table('office')->where('office_code', $originOfficeCode)->first();
            $clusterHead = null;
            if ($originOffice && $originOffice->cluster) {
                $cluster = DB::table('cluster')->where('cluster_code', $originOffice->cluster)->first();
                if ($cluster) {
                    $clusterHead = $cluster->cluster_head;
                }
            }

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
        
        $type = 'EXT';

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
                    'flow_use' => 'external',
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

                $this->transaction_flow = $flowCode;
            });

            // Reload flows list
            $userOfficeId = auth()->user()?->details?->office_id;
            $this->flows = DB::table('dts_transaction_flow')
                ->where('is_active', true)
                ->whereIn('flow_use', ['external', 'none'])
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
                                      ->from('account_details')
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
        $isRequired = DB::table('system_settings')->where('key', 'dts_email_access_required_external')->value('value') === 'true';
        if ($isRequired) {
            $this->validate([
                'email_access_input' => 'required|email',
                'document_password_input' => 'required|string|min:4',
            ], [
                'email_access_input.required' => 'The Authorized Email Address is required.',
                'email_access_input.email' => 'The Authorized Email must be a valid email address.',
                'document_password_input.required' => 'The Document Password is required.',
                'document_password_input.min' => 'The Document Password must be at least 4 characters.',
            ]);
        } else {
            $this->validate([
                'email_access_input' => 'nullable|email',
                'document_password_input' => 'nullable|string|min:4',
            ], [
                'email_access_input.email' => 'The Authorized Email must be a valid email address.',
                'document_password_input.min' => 'The Document Password must be at least 4 characters.',
            ]);
        }

        $this->validate([
            'seq_number' => 'required|string|max:50',
            'source_office' => 'required|string|exists:office,office_code',
            'requestor_name' => 'required|string|max:255',
            'requestor_label' => 'required|string|max:255',
            'subject' => 'required|string',
            'transaction_flow' => 'required|string|exists:dts_transaction_flow,flow_code',
            'copy_furnished' => 'required|string|in:Yes,No',
            'cf_selected_offices' => 'nullable|array',
        ]);

        if (count($this->flow_offices) === 0) {
            $this->addError('transaction_flow', 'The transaction flow must contain at least one office.');
            return;
        }

        if (!$this->generatedQrCode) {
            $this->addError('seq_number', 'Please generate a QR Code first.');
            return;
        }

        $controlNumber = 'EXT-' . now()->format('Y-m') . '-' . $this->seq_number;

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
            
            // Find cluster head of the originating office
            $originOfficeCode = $this->source_office;
            $originOffice = DB::table('office')->where('office_code', $originOfficeCode)->first();
            $clusterHead = null;
            if ($originOffice && $originOffice->cluster) {
                $cluster = DB::table('cluster')->where('cluster_code', $originOffice->cluster)->first();
                if ($cluster) {
                    $clusterHead = $cluster->cluster_head;
                }
            }

            // Resolve dynamic ORIGIN and [H] to the creator's office / cluster head for all elements (used dynamically)
            $resolvedOffices = [];
            foreach ($this->flow_offices as $officeCode) {
                $resolved = $officeCode;
                if ($officeCode === 'ORIGIN') {
                    $resolved = $originOfficeCode;
                } elseif ($officeCode === '[H]') {
                    $resolved = $clusterHead ?: $originOfficeCode;
                }
                
                // Deduplicate adjacent/consecutive identical offices
                if (empty($resolvedOffices) || end($resolvedOffices) !== $resolved) {
                    $resolvedOffices[] = $resolved;
                }
            }

            $resolvedPredefined = [];
            if ($flow) {
                $predefinedOffices = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->orderBy('sequence_ranking', 'asc')
                    ->pluck('office_code')
                    ->toArray();

                foreach ($predefinedOffices as $officeCode) {
                    $resolved = $officeCode;
                    if ($officeCode === 'ORIGIN') {
                        $resolved = $originOfficeCode;
                    } elseif ($officeCode === '[H]') {
                        $resolved = $clusterHead ?: $originOfficeCode;
                    }
                    if (empty($resolvedPredefined) || end($resolvedPredefined) !== $resolved) {
                        $resolvedPredefined[] = $resolved;
                    }
                }
            }

            // Always copy the flow to dts_sequence_list to make it unique per transaction
            if (true) {
                 // Generate custom flow
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
                     'flow_use' => 'external',
                 ]);
 
                 foreach ($this->flow_offices as $rank => $officeCode) {
                     $toSave = $officeCode;
                     if ($officeCode === $originOfficeCode) {
                         $toSave = 'ORIGIN';
                     } elseif ($officeCode === $clusterHead) {
                         $toSave = '[H]';
                     }
 
                     DB::table('dts_sequence_list')->insert([
                         'control_id' => $newFlowId,
                         'sequence_ranking' => $rank + 1,
                         'office_code' => $toSave,
                         'date_in' => ($rank === 0) ? now() : null,
                         'date_out' => null,
                         'action_needed' => ($rank === 0) ? 'Created' : null,
                         'note' => ($rank === 0) ? 'Created external transaction' : null,
                         'total_time_completed' => null,
                     ]);
                 }
             }

            $currentOffice = $resolvedOffices[0] ?? $this->source_office;

            $qrCodeId = $this->generatedQrCode;

            $transactionId = 'TRANS-' . strtoupper(Str::random(10));

            // Insert into dts_transactions
            DB::table('dts_transactions')->insert([
                'transaction_id' => $transactionId,
                'enable_notif' => 1,
                'trans_type' => 'external',
                'doc_dir' => null,
                'qr_code' => $qrCodeId,
                'current_office' => $currentOffice,
                'status' => 'ongoing',
                'sequence' => 1,
            ]);

            $copyFilledId = null;
            // Insert Copy Furnished records into dts_copy_filled_transaction and dts_copy_filled_to_office tables
            if ($this->copy_furnished === 'Yes' && count($this->cf_selected_offices) > 0) {
                $assignOfficesId = (DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;

                $finalCfOffices = [];
                foreach ($this->cf_selected_offices as $cfOffice) {
                    if ($cfOffice === 'ALL') {
                        $allOffices = DB::table('office')->pluck('office_code')->toArray();
                        foreach ($allOffices as $oCode) {
                            $finalCfOffices[] = $oCode;
                        }
                    } else {
                        $finalCfOffices[] = $cfOffice;
                    }
                }
                $finalCfOffices = array_values(array_unique(array_filter($finalCfOffices)));

                $copyFilledId = DB::table('dts_copy_filled_transaction')->insertGetId([
                    'control_num' => $controlNumber,
                    'total_office' => count($finalCfOffices),
                    'assign_offices_id' => $assignOfficesId,
                    'data_created' => now(),
                    'date_modified' => now(),
                ]);

                foreach ($finalCfOffices as $cfOffice) {
                    DB::table('dts_copy_filled_to_office')->insert([
                        'control_id' => $assignOfficesId,
                        'office_code' => $cfOffice,
                    ]);
                }
            }

            $emailAccessId = null;
            if (!empty($this->email_access_input)) {
                $existingEmail = DB::table('dts_email_access')->where('email', $this->email_access_input)->first();
                if ($existingEmail) {
                    $emailAccessId = $existingEmail->id;
                } else {
                    $emailAccessId = DB::table('dts_email_access')->insertGetId([
                        'email' => $this->email_access_input,
                        'is_active' => true,
                        'date_created' => now(),
                    ]);
                }
            }

            // Insert into dts_transaction_details
            DB::table('dts_transaction_details')->insert([
                'id' => $transactionId,
                'type' => 'external',
                'created_by' => auth()->id(),
                'originated_from' => $this->source_office,
                'requestor_name' => $this->requestor_name,
                'requestor_label' => $this->requestor_label,
                'subject' => $this->subject,
                'classification' => null,
                'action_needed' => 'For action',
                'current_office_hold' => $currentOffice,
                'status' => 'ongoing',
                'document_password' => $this->document_password_input ?: null,
                'email_access' => $emailAccessId,
                'transaction_flow' => $flowCode,
                'is_active' => 1,
                'date_created' => now(),
                'control_number' => $controlNumber,
                'copy_filled_id' => $copyFilledId,
            ]);

            // Log the creation
            DB::table('sub_document_tracking_system_logs')->insert([
                'transaction_id' => $transactionId,
                'office_code' => $this->source_office,
                'type' => 'created',
                'date_in' => now(),
                'date_out' => null,
                'notes' => 'Created external transaction',
                'performed_by' => auth()->id(),
            ]);

            // Send notification to all users of the Origin office
            $subsystemId = DB::table('subsystems')->where('subsystem_name', 'Document Tracking System')->value('subsystem_id');
            if ($subsystemId) {
                $originOffice = DB::table('office')->where('office_code', $this->source_office)->first();
                if ($originOffice) {
                    $usersInOffice = DB::table('account')
                        ->join('account_details', 'account_details.account_id', '=', 'account.id')
                        ->where('account_details.office_id', $originOffice->id)
                        ->select('account.id')
                        ->get();

                    if ($usersInOffice->isNotEmpty()) {
                        $senderName = auth()->user()?->details ? (auth()->user()->details->first_name . ' ' . auth()->user()->details->last_name) : 'Authorized User';
                        $contentId = DB::table('notif_content')->insertGetId([
                            'system' => $subsystemId,
                            'content' => "A transaction has been created by {$senderName}. Transaction ID: {$controlNumber}",
                            'redirect_url' => '/dts',
                            'created_at' => now(),
                        ]);

                        $notificationId = DB::table('notifications')->insertGetId([
                            'office' => $this->source_office,
                            'contents' => $contentId,
                            'created_at' => now(),
                        ]);

                        foreach ($usersInOffice as $u) {
                            DB::table('notification_div')->insert([
                                'id' => $notificationId,
                                'account_rec' => $u->id,
                                'status' => 'unread',
                                'processed_on' => now(),
                                'is_in_user_list' => true,
                            ]);
                        }
                    }
                }
            }

            // Send notification to copy furnished offices
            if ($copyFilledId && $subsystemId) {
                if (in_array('ALL', $this->cf_selected_offices)) {
                    $cfNotifyOffices = DB::table('office')
                        ->where('is_active', 1)
                        ->where('office_code', '!=', $this->source_office)
                        ->get();
                } else {
                    $cfNotifyOffices = DB::table('office')
                        ->where('is_active', 1)
                        ->whereIn('office_code', $this->cf_selected_offices)
                        ->get();
                }

                $senderName = auth()->user()?->details ? (auth()->user()->details->first_name . ' ' . auth()->user()->details->last_name) : 'Authorized User';
                $cfContentId = DB::table('notif_content')->insertGetId([
                    'system' => $subsystemId,
                    'content' => "A document has been copy furnished to your office by {$senderName}. Transaction ID: {$controlNumber}",
                    'redirect_url' => '/dts',
                    'created_at' => now(),
                ]);

                foreach ($cfNotifyOffices as $officeRow) {
                    $usersInCFOffice = DB::table('account')
                        ->join('account_details', 'account_details.account_id', '=', 'account.id')
                        ->where('account_details.office_id', $officeRow->id)
                        ->select('account.id')
                        ->get();

                    if ($usersInCFOffice->isNotEmpty()) {
                        $cfNotificationId = DB::table('notifications')->insertGetId([
                            'office' => $officeRow->office_code,
                            'contents' => $cfContentId,
                            'created_at' => now(),
                        ]);

                        foreach ($usersInCFOffice as $u) {
                            DB::table('notification_div')->insert([
                                'id' => $cfNotificationId,
                                'account_rec' => $u->id,
                                'status' => 'unread',
                                'processed_on' => now(),
                                'is_in_user_list' => true,
                            ]);
                        }
                    }
                }
            }

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2>External Transaction</h2>
        
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
            <div class="beta-layout-container" style="position: relative; min-height: 520px; box-sizing: border-box;">
                
                <!-- Left Side Form Fields -->
                <div style="margin-right: 220px; display: flex; flex-direction: column; gap: 24px;">
                    
                    <!-- Generated Control Number Identity Badge -->
                    @if(auth()->user()?->permissions?->can_dts_modify_control_no)
                    <div class="beta-control-badge">
                        <span class="badge-label">Generated Control Number</span>
                        <div style="display: flex; gap: 8px; align-items: center; margin-top: 4px;">
                            <div class="beta-value" style="display: flex; align-items: center;">
                                <span class="beta-prefix">EXT-{{ now()->format('Y-m') }}-</span>
                                <input type="text" wire:model.live="seq_number" class="beta-input badge-input" placeholder="0001">
                            </div>
                            @if(empty($seq_number))
                                <button type="button" wire:click="generateRandomSeq" class="beta-btn-add" style="background: #3b82f6; border: 1px solid rgba(255, 255, 255, 0.4); color: #ffffff; height: 32px; font-size: 11px; padding: 0 12px; border-radius: 6px;">
                                    Generate
                                </button>
                            @else
                                <button type="button" wire:click="checkAvailability" class="beta-btn-add" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.4); color: #ffffff; height: 32px; font-size: 11px; padding: 0 12px; border-radius: 6px;">
                                    Check Availability
                                </button>
                            @endif

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
                    @endif

                    <!-- Card 1: Document Details -->
                    <div class="beta-card">
                        <h3 class="beta-card-title"><i class="fa-solid fa-file-invoice"></i> Document Details</h3>
                        <div class="beta-form-grid">
                            @if(auth()->user()?->permissions?->is_sadm)
                            <div class="beta-form-group full-width">
                                <label class="beta-label">Source Office</label>
                                <select wire:model="source_office" class="beta-select">
                                    <option value="">Select Source Office</option>
                                    @foreach($offices as $office)
                                        <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }} ({{ $office['office_code'] }})</option>
                                    @endforeach
                                </select>
                                @error('source_office') <span class="beta-error">{{ $message }}</span> @enderror
                            </div>
                            @endif

                            <div class="beta-form-group full-width">
                                <label class="beta-label">Name of Requestor</label>
                                <input type="text" wire:model="requestor_name" class="beta-input" placeholder="e.g. John Doe">
                                @error('requestor_name') <span class="beta-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="beta-form-group full-width">
                                <label class="beta-label">Requestor Job Position</label>
                                <input type="text" wire:model="requestor_label" class="beta-input" placeholder="e.g. Director, Manager, President, etc.">
                                @error('requestor_label') <span class="beta-error">{{ $message }}</span> @enderror
                            </div>

                            <div class="beta-form-group full-width">
                                <label class="beta-label">Subject</label>
                                <textarea wire:model="subject" class="beta-textarea" placeholder="Enter document subject or brief description..." rows="3"></textarea>
                                @error('subject') <span class="beta-error">{{ $message }}</span> @enderror
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
                            <a href="#" wire:click.prevent="openCustomFlowCreator" style="font-size: 11.5px; color: #3b82f6; text-decoration: none; font-weight: 600; margin-top: 4px; display: inline-block;">Flow Can't be found?</a>
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
                        
                        <div class="beta-form-group full-width" style="margin-top: 15px;">
                            <label class="beta-label">Search & Add Recipient Offices</label>
                            <div class="beta-search-wrapper" style="position: relative;">
                                <i class="fa-solid fa-magnifying-glass search-icon" style="position: absolute; left: 12px; top: 12px; color: #94a3b8; font-size: 13px;"></i>
                                <input type="text" wire:model.live="cf_search" class="beta-input" placeholder="Type to search offices..." style="padding-left: 36px; width: 100%; box-sizing: border-box;">
                                
                                @if(!empty($cf_search))
                                    <div class="beta-autocomplete-dropdown">
                                        @php
                                            $cfList = array_merge([
                                                ['office_code' => 'ALL', 'office_name' => 'All Office']
                                            ], $offices);

                                            $filteredCfOffices = collect($cfList)
                                                ->filter(fn($o) => 
                                                    (stripos($o['office_name'], $cf_search) !== false || 
                                                    stripos($o['office_code'], $cf_search) !== false) &&
                                                    !in_array($o['office_code'], $cf_selected_offices) &&
                                                    $o['office_code'] !== $source_office
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
                                        $cfList = array_merge([
                                            ['office_code' => 'ALL', 'office_name' => 'All Office']
                                        ], $offices);
                                        $officeName = collect($cfList)->firstWhere('office_code', $officeCode)['office_name'] ?? $officeCode;
                                    @endphp
                                    <span class="beta-badge">
                                        {{ $officeName }}
                                        <button type="button" class="remove-btn" wire:click="removeCfOffice({{ $index }})"><i class="fa-solid fa-xmark"></i></button>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Right Side Absolute QR Code Box -->
                <div style="position: absolute; top: 24px; right: 24px; width: 180px; display: flex; flex-direction: column; align-items: center; gap: 10px; box-sizing: border-box; z-index: 10;">
                    @if($generatedQrCode)
                        <div id="printable-qr-area-external" style="display: flex; flex-direction: column; align-items: center; gap: 8px; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #cbd5e1; box-sizing: border-box; width: 180px;">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode(base64_encode($generatedQrCode)) }}" alt="QR Code" style="width: 148px; height: 148px;">
                            <span style="font-family: monospace; font-weight: bold; font-size: 13px; color: #1e293b; text-align: center; word-break: break-all;">{{ $generatedQrCode }}</span>
                        </div>
                        <button type="button" onclick="openDynamicPrintModal('{{ $generatedQrCode }}')" style="background: #10b981; color: white; border: none; border-radius: 6px; padding: 8px 16px; font-weight: bold; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; width: 100%; justify-content: center; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);">
                            <i class="fa-solid fa-print"></i> Print QR Code
                        </button>
                    @else
                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: #ffffff; border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; box-sizing: border-box; width: 180px; height: 210px; color: #64748b; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <i class="fa-solid fa-qrcode" style="font-size: 36px; color: #94a3b8;"></i>
                            <span style="font-size: 12px; font-weight: 600; color: #1e293b;">QR Code Output</span>
                        </div>
                    @endif
                </div>

                <!-- Submit Footer -->
                <div class="beta-form-footer" style="display: flex; gap: 12px; align-items: center; margin-top: 24px; clear: both;">
                    @if (session()->has('error'))
                        <span class="beta-error" style="margin-right: 15px; align-self: center;">{{ session('error') }}</span>
                    @endif
                    <button type="button" wire:click="generateQrCode" class="beta-btn-submit" style="background-color: #3b82f6; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.25);" {{ $generatedQrCode ? 'disabled style=background-color:#cbd5e1;cursor:not-allowed;box-shadow:none;' : '' }}>
                        <i class="fa-solid fa-gear"></i> Generate QR Code
                    </button>
                    @php
                        $emailAccessRequired = DB::table('system_settings')->where('key', 'dts_email_access_required_external')->value('value') === 'true';
                        $isConfigured = !empty($email_access_input) && !empty($document_password_input);
                        $btnBg = $isConfigured ? '#10b981' : ($emailAccessRequired ? '#ef4444' : '#3b82f6');
                        $btnHoverBg = $isConfigured ? '#059669' : ($emailAccessRequired ? '#dc2626' : '#2563eb');
                        $btnShadow = $isConfigured ? 'rgba(16, 185, 129, 0.25)' : ($emailAccessRequired ? 'rgba(239, 68, 68, 0.25)' : 'rgba(59, 130, 246, 0.25)');
                        $btnIcon = $isConfigured ? 'fa-circle-check' : ($emailAccessRequired ? 'fa-triangle-exclamation' : 'fa-envelope');
                    @endphp
                    <button type="button" wire:click="toggleEmailAccessModal" class="beta-btn-submit" style="background-color: {{ $btnBg }}; box-shadow: 0 4px 10px {{ $btnShadow }}; transition: background-color 0.15s;" onmouseover="this.style.backgroundColor='{{ $btnHoverBg }}'" onmouseout="this.style.backgroundColor='{{ $btnBg }}'">
                        <i class="fa-solid {{ $btnIcon }}"></i> Manage Email Access
                    </button>
                    <button type="submit" class="beta-btn-submit" @if(!$generatedQrCode) disabled style="background: #cbd5e1; color: #94a3b8; cursor: not-allowed; box-shadow: none;" @endif>
                        <i class="fa-solid fa-floppy-disk"></i> Create Transaction
                    </button>
                </div>

            </div>
        </form>
    @else
        <!-- External Transaction Form -->
        <form class="rms-form" wire:submit.prevent="save" style="position: relative; min-height: 520px; box-sizing: border-box;">
            
            <!-- Left Side Form Fields -->
            <div style="margin-right: 220px;">
                
                @if(auth()->user()?->permissions?->can_dts_modify_control_no)
                    <!-- Original Control Number Input Field -->
                    <div class="control-wrapper" style="margin-bottom: 20px;">
                        <label class="control-label">Control Number:</label>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <div style="display: flex; align-items: center; max-width: 300px; border: 1px solid #ced4da; border-radius: 4px; overflow: hidden; background: #e9ecef; height: 32px; box-sizing: border-box;">
                                <span style="padding: 0 10px; font-family: 'Inter', sans-serif; font-size: 13px; color: #495057; font-weight: 600; border-right: 1px solid #ced4da; user-select: none; line-height: 30px;">
                                    EXT-{{ now()->format('Y-m') }}-
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
                @endif

                <!-- Source office field -->
                @if(auth()->user()?->permissions?->is_sadm)
                <div class="form-row">
                    <div class="form-col medium-input">
                        <label class="input-label">Source Office</label>
                        <select wire:model="source_office" class="select-input">
                            <option value="">Select Source Office</option>
                            @foreach($offices as $office)
                                <option value="{{ $office['office_code'] }}">{{ $office['office_name'] }} ({{ $office['office_code'] }})</option>
                            @endforeach
                        </select>
                        @error('source_office')
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

                <!-- Requestor Job Position field -->
                <div class="form-row">
                    <div class="form-col small-input">
                        <label class="input-label">Requestor Job Position</label>
                        <input type="text" wire:model="requestor_label" class="text-input" placeholder="Requestor Job Position">
                        @error('requestor_label')
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
                        <a href="#" wire:click.prevent="openCustomFlowCreator" style="font-size: 11.5px; color: #2563eb; text-decoration: none; font-weight: 600; margin-top: 4px; display: inline-block;">Flow Can't be found?</a>
                    </div>
                </div>

                <!-- Copy Furnished Selector Box -->
                <div class="form-row">
                    <div class="form-col" style="max-width: 500px; padding: 16px; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #f8fafc; margin-bottom: 12px;">
                        <label class="input-label" style="margin-bottom: 8px;">Copy Furnished Offices</label>
                        
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
                                    $cfList = array_merge([
                                        ['office_code' => 'ALL', 'office_name' => 'All Office']
                                    ], $offices);

                                    $filteredOffices = array_filter($cfList, function($office) use ($cf_selected_offices, $source_office) {
                                        return !in_array($office['office_code'], $cf_selected_offices) && $office['office_code'] !== $source_office;
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

            <!-- Right Side Absolute QR Code Box -->
            <div style="position: absolute; top: 0; right: 0; width: 180px; display: flex; flex-direction: column; align-items: center; gap: 10px; box-sizing: border-box;">
                @if($generatedQrCode)
                    <div id="printable-qr-area-external" style="display: flex; flex-direction: column; align-items: center; gap: 8px; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #cbd5e1; box-sizing: border-box; width: 180px;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode(base64_encode($generatedQrCode)) }}" alt="QR Code" style="width: 148px; height: 148px;">
                        <span style="font-family: monospace; font-weight: bold; font-size: 13px; color: #1e293b; text-align: center; word-break: break-all;">{{ $generatedQrCode }}</span>
                    </div>
                    <button type="button" onclick="openDynamicPrintModal('{{ $generatedQrCode }}')" style="background: #10b981; color: white; border: none; border-radius: 6px; padding: 8px 16px; font-weight: bold; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; width: 100%; justify-content: center; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);">
                        <i class="fa-solid fa-print"></i> Print QR Code
                    </button>
                @else
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; background: #f8fafc; border: 2px dashed #cbd5e1; padding: 20px; border-radius: 8px; box-sizing: border-box; width: 180px; height: 210px; color: #64748b; text-align: center;">
                        <i class="fa-solid fa-qrcode" style="font-size: 36px; color: #94a3b8;"></i>
                        <span style="font-size: 12px; font-weight: 600;">QR Code Output</span>
                    </div>
                @endif
            </div>

            <!-- Submit button and Generate QR next to it -->
            <div class="actions-row" style="display: flex; gap: 12px; align-items: center; margin-top: 24px; clear: both;">
                @if (session()->has('error'))
                    <span style="color: #dc2626; font-size: 13px; align-self: center; margin-right: 15px;">{{ session('error') }}</span>
                @endif
                <button type="button" wire:click="generateQrCode" class="btn-primary" style="background-color: #3b82f6; border-radius: 4px; padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);" {{ $generatedQrCode ? 'disabled style=background-color:#cbd5e1;cursor:not-allowed;box-shadow:none;' : '' }}>
                    <i class="fa-solid fa-gear"></i> Generate QR Code
                </button>
                @php
                    $emailAccessRequired = DB::table('system_settings')->where('key', 'dts_email_access_required_external')->value('value') === 'true';
                    $isConfigured = !empty($email_access_input) && !empty($document_password_input);
                    $btnBg = $isConfigured ? '#10b981' : ($emailAccessRequired ? '#ef4444' : '#3b82f6');
                    $btnHoverBg = $isConfigured ? '#059669' : ($emailAccessRequired ? '#dc2626' : '#2563eb');
                    $btnShadow = $isConfigured ? 'rgba(16, 185, 129, 0.2)' : ($emailAccessRequired ? 'rgba(239, 68, 68, 0.2)' : 'rgba(59, 130, 246, 0.2)');
                    $btnIcon = $isConfigured ? 'fa-circle-check' : ($emailAccessRequired ? 'fa-triangle-exclamation' : 'fa-envelope');
                @endphp
                <button type="button" wire:click="toggleEmailAccessModal" class="btn-primary" style="background-color: {{ $btnBg }}; border-radius: 4px; padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px {{ $btnShadow }}; transition: background-color 0.15s;" onmouseover="this.style.backgroundColor='{{ $btnHoverBg }}'" onmouseout="this.style.backgroundColor='{{ $btnBg }}'">
                    <i class="fa-solid {{ $btnIcon }}"></i> Manage Email Access
                </button>
                <button type="submit" class="btn-primary" @if(!$generatedQrCode) disabled style="background-color: #cbd5e1; color: #94a3b8; cursor: not-allowed; box-shadow: none;" @endif>CREATE TRANSACTION</button>
            </div>
        </form>
<!-- QR-CODE-MODIFY-HERE -->
        <!-- Dynamic QR Code Print Modal moved to root -->
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
                                <option value="ORIGIN">ORIGIN (Your Current Office)</option>
                                <option value="[H]">[H] (Your Cluster Head Office)</option>
                                @foreach($offices as $off)
                                    <option value="{{ $off['office_code'] }}">{{ $off['office_name'] }} ({{ $off['office_code'] }})</option>
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
                                        $name = $code === 'ORIGIN' ? 'ORIGIN (Your Current Office)' : ($code === '[H]' ? '[H] (Your Cluster Head Office)' : (collect($offices)->firstWhere('office_code', $code)['office_name'] ?? $code));
                                    @endphp
                                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 10px; transition: border-color 0.15s;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span style="width: 22px; height: 22px; border-radius: 50%; background: #3b82f6; color: #ffffff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center;">{{ $idx + 1 }}</span>
                                            <div>
                                                <span style="font-size: 13px; font-weight: 600; color: #1e293b; display: block;">{{ $name }}</span>
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

    <!-- Email Access & Password Modal -->
    @if($showEmailAccessModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 10000; font-family: 'Inter', sans-serif;">
            <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 440px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); display: flex; flex-direction: column; overflow: hidden; animation: modalEnter 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
                
                <!-- Header -->
                <div style="padding: 20px 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div style="width: 36px; height: 36px; border-radius: 8px; background: #e0e7ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; font-size: 16px;">
                            <i class="fa-solid fa-envelope"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 0;">Manage Email Access</h3>
                            <span style="font-size: 11px; color: #64748b; font-weight: 500;">Restrict document tracking permissions</span>
                        </div>
                    </div>
                    <button type="button" wire:click="toggleEmailAccessModal" style="background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">&times;</button>
                </div>

                <!-- Body -->
                <div style="padding: 24px; display: flex; flex-direction: column; gap: 16px;">
                    <!-- Authorized Email input -->
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 12px; font-weight: 600; color: #334155;">Authorized Email Address</label>
                        <input type="email" wire:model="email_access_input" placeholder="e.g. user@gmail.com" style="width: 100%; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; transition: border-color 0.15s; font-family: 'Inter', sans-serif;" onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('email_access_input') <span style="font-size: 11.5px; color: #ef4444; font-weight: 500;">{{ $message }}</span> @enderror
                        <p style="margin: 0; font-size: 11px; color: #64748b; line-height: 1.4;">Only this email address will be permitted to verify and track this document's lifecycle on the public portal.</p>
                    </div>

                    <!-- Password input -->
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <label style="font-size: 12px; font-weight: 600; color: #334155;">Document Password</label>
                        <input type="text" wire:model="document_password_input" placeholder="Enter secure password" style="width: 100%; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; outline: none; transition: border-color 0.15s; font-family: 'Inter', sans-serif;" onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('document_password_input') <span style="font-size: 11.5px; color: #ef4444; font-weight: 500;">{{ $message }}</span> @enderror
                        <p style="margin: 0; font-size: 11px; color: #64748b; line-height: 1.4;">Required for non-CSPC email addresses to view document tracking updates.</p>
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding: 16px 24px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 12px; background: #fafafa;">
                    <button type="button" wire:click="toggleEmailAccessModal" style="background: #ffffff; border: 1.5px solid #cbd5e1; color: #334155; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#ffffff'">Cancel</button>
                    <button type="button" wire:click="toggleEmailAccessModal" style="background: #3b82f6; border: none; color: #ffffff; padding: 8px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.backgroundColor='#2563eb'" onmouseout="this.style.backgroundColor='#3b82f6'">Save & Close</button>
                </div>
            </div>
        </div>
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

    <!-- Dynamic QR Code Print Modal -->
    @include('components.dts.qr-print-modal')
</div>
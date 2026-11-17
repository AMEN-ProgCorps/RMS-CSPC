<?php

use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

new class extends Component {
    public bool $isOpen = false;
    public string $scannedCode = '';
    public string $lastScannedCode = '';
    public ?array $activeTransaction = null;
    public string $actionNeeded = '';
    public string $forwardToOfficeCode = '';
    public string $notes = '';
    public string $successMessage = '';
    public string $errorMessage = '';
    public array $recentScans = [];
    public array $actionOptions = [];
    public string $resubmitTarget = 'start';

    // Advanced Scanner Modes & Available Directory
    public bool $isMaximized = false;
    public int $cooldownUntil = 0;
    public bool $isManualInput = false;
    public bool $availableCodesEnabled = true;
    public bool $showAvailableModal = false;
    public string $availableSearch = '';
    public string $availableFilter = 'all';

    /**
     * Toggles modal maximize state between standard docked dialog and full-viewport view.
     *
     * @return void
     */
    public function toggleMaximize(): void
    {
        $this->isMaximized = !$this->isMaximized;
    }

    /**
     * Resets the scanner console inputs, active transaction, Hub selections, and refocuses input.
     *
     * @return void
     */
    public function resetConsole(): void
    {
        $this->clearMessages();
        $this->activeTransaction = null;
        $this->scannedCode = '';
        $this->notes = '';
        $this->forwardToOfficeCode = '';
        $this->forwardHubOffices = [];
        $this->forwardHubTempSelected = [];
        $this->showForwardHubModal = false;
        $this->dispatch('focus-scanner-input');
    }

    // Forward HUB Selection Modal State
    public bool $showForwardHubModal = false;
    public string $forwardHubSearch = '';
    public array $forwardHubOffices = [];
    public array $forwardHubTempSelected = [];

    /**
     * Triggers manual search for code entered in input box ($isScan = false), requiring manual receipt verification.
     *
     * @return void
     */
    public function loadTransactionManual(): void
    {
        $this->loadTransaction(null, false);
    }

    /**
     * Toggles visibility of the Available Codes directory modal.
     *
     * @return void
     */
    public function toggleAvailableCodesModal(): void
    {
        if (!$this->availableCodesEnabled) {
            $this->showAvailableModal = false;
            return;
        }
        if ($this->showAvailableModal) {
            $this->closeAvailableCodesModal();
        } else {
            $this->openAvailableCodesModal();
        }
    }

    /**
     * Opens the Available Codes directory modal and resets search and filter states.
     *
     * @return void
     */
    public function openAvailableCodesModal(): void
    {
        if (!$this->availableCodesEnabled) {
            $this->showAvailableModal = false;
            return;
        }
        $this->availableSearch = '';
        $this->availableFilter = 'all';
        $this->showAvailableModal = true;
    }

    /**
     * Closes the Available Codes directory modal and clears the search query.
     *
     * @return void
     */
    public function closeAvailableCodesModal(): void
    {
        $this->showAvailableModal = false;
        $this->availableSearch = '';
    }

    /**
     * Loads a specific transaction selected from the Available Codes directory.
     *
     * @param string $qrCode Scanned QR code or control number to load.
     * @return void
     */
    public function loadAvailableTransaction(string $qrCode): void
    {
        $this->closeAvailableCodesModal();
        $this->loadTransaction($qrCode);
    }

    /**
     * Opens the Office Hub collective broadcast modal and initializes staging selection.
     *
     * @return void
     */
    public function openForwardHubModal(): void
    {
        $this->forwardHubTempSelected = $this->forwardHubOffices;
        $this->forwardHubSearch = '';
        $this->showForwardHubModal = true;
    }

    /**
     * Closes the Office Hub broadcast modal without applying uncommitted changes.
     *
     * @return void
     */
    public function closeForwardHubModal(): void
    {
        $this->showForwardHubModal = false;
        $this->forwardHubSearch = '';
    }

    /**
     * Toggles an office code in the staged Hub broadcast selection list.
     *
     * @param string $officeCode Office code to add or remove.
     * @return void
     */
    public function toggleForwardHubOffice(string $officeCode): void
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if ($officeCode === $userOfficeCode || in_array($officeCode, ['ORIGIN', '[H]', '[HUB]'])) {
            return;
        }

        if (in_array('ALL', $this->forwardHubTempSelected)) {
            $allActive = collect($this->availableTargetOffices)
                ->pluck('office_code')
                ->filter(fn($c) => $c !== $userOfficeCode && !in_array($c, ['ORIGIN', '[H]', '[HUB]', 'ALL']))
                ->toArray();
            $this->forwardHubTempSelected = array_values(array_diff($allActive, [$officeCode]));
            return;
        }

        if (in_array($officeCode, $this->forwardHubTempSelected)) {
            $this->forwardHubTempSelected = array_values(array_diff($this->forwardHubTempSelected, [$officeCode]));
        } else {
            $this->forwardHubTempSelected[] = $officeCode;
        }
    }

    /**
     * Selects all active system offices for full broadcast mode in the Office Hub modal.
     *
     * @return void
     */
    public function selectAllForwardHubOffices(): void
    {
        $this->forwardHubTempSelected = ['ALL'];
    }

    /**
     * Clears all selected offices in the staged Office Hub selection.
     *
     * @return void
     */
    public function clearForwardHubOffices(): void
    {
        $this->forwardHubTempSelected = [];
    }

    /**
     * Commits staged Office Hub selections into the active forwarding payload and closes modal.
     *
     * @return void
     */
    public function applyForwardHubModal(): void
    {
        $this->forwardHubOffices = $this->forwardHubTempSelected;
        $this->showForwardHubModal = false;
        $this->forwardHubSearch = '';
    }

    /**
     * Removes an office by index from the committed forward Hub offices list.
     *
     * @param int $index Zero-based array index to remove.
     * @return void
     */
    public function removeForwardHubOffice(int $index): void
    {
        if (isset($this->forwardHubOffices[$index])) {
            array_splice($this->forwardHubOffices, $index, 1);
        }
    }

    /**
     * Initializes component action options, recent scans from session, and system settings.
     *
     * @return void
     */
    public function mount(): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('dts_action_options')) {
                $query = DB::table('dts_action_options')->orderBy('option_name', 'asc');
                if (!\App\Services\DtsRoutingService::canForwardToOtherOffice()) {
                    $query->where('option_name', '!=', 'To be Forwarded to');
                }
                $this->actionOptions = $query->pluck('option_name')->toArray();
            }
        } catch (\Throwable $e) {
            $this->actionOptions = [];
        }

        if (empty($this->actionOptions)) {
            $this->actionOptions = ['For Approval', 'For Review', 'For Signature', 'For Release', 'For Filing', 'For Action'];
        }

        $this->actionNeeded = $this->actionOptions[0] ?? 'For Approval';
        $this->recentScans = session()->get('dts_recent_scans', []);

        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $setting = \Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'dts_enable_available_codes')->value('value');
        $this->availableCodesEnabled = ($setting !== 'false');
    }

    /**
     * Computed property retrieving available destination offices for dynamic forwarding.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getAvailableTargetOfficesProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());
        return \App\Services\DtsRoutingService::getAvailableTargetOffices($userOfficeCode);
    }

    /**
     * Computed property returning transactions available for station action at user's office.
     * Applies search keywords and incoming/received filters.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getAvailableTransactionsProperty()
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $canViewAllCodes = (bool) (auth()->user()?->permissions?->can_dts_view_all_scanner_codes ?? false);

        if (!$userOfficeCode && !$canViewAllCodes && !auth()->user()?->permissions?->is_sadm) {
            return collect();
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office_tb', 'current_office_tb.office_code', '=', 'dt.current_office')
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        if ($userOfficeCode && !$canViewAllCodes) {
            $query->where(function ($q) use ($userOfficeCode) {
                $q->where('dt.current_office', $userOfficeCode)
                  ->orWhere(function ($sub) use ($userOfficeCode) {
                      $sub->where('dt.current_office', 'ORIGIN')
                          ->where('dtd.originated_from', $userOfficeCode);
                  });
            });
        }

        if (!empty(trim($this->availableSearch))) {
            $s = '%' . trim($this->availableSearch) . '%';
            $query->where(function ($sub) use ($s) {
                $sub->where('dtd.control_number', 'like', $s)
                    ->orWhere('dt.qr_code', 'like', $s)
                    ->orWhere('dtd.subject', 'like', $s)
                    ->orWhere('dtd.classification', 'like', $s)
                    ->orWhere('dtd.originated_from', 'like', $s)
                    ->orWhere('req.requestor_name', 'like', $s)
                    ->orWhere('originated_office.office_name', 'like', $s);
            });
        }

        $transactions = $query->select(
            'dt.transaction_id',
            'dt.trans_type as type',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'current_office_tb.office_name as current_office_name',
            'dtd.control_number',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.originated_from',
            'originated_office.office_name as originated_office_name',
            'req.requestor_name',
            'dtd.date_created as tx_created_at'
        )->orderBy('dtd.date_created', 'desc')->limit(60)->get();

        return $transactions->map(function ($item) use ($userOfficeCode) {
            $checkOffice = $userOfficeCode ?: $item->current_office;
            $lastLog = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                ->where('transaction_id', $item->transaction_id)
                ->where('office_code', $checkOffice)
                ->orderBy('id', 'desc')
                ->first();

            $item->is_received = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));
            $item->document_type = $item->classification ?: $item->type ?: 'Document';
            return $item;
        })->filter(function ($item) {
            if ($this->availableFilter === 'incoming') {
                return !$item->is_received;
            }
            if ($this->availableFilter === 'received') {
                return (bool) $item->is_received;
            }
            return true;
        });
    }

    /**
     * Computed property summarizing count badges (total, incoming, received) for the Available Codes directory.
     *
     * @return array{all: int, incoming: int, received: int}
     */
    public function getAvailableCountsProperty(): array
    {
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        $canViewAllCodes = (bool) (auth()->user()?->permissions?->can_dts_view_all_scanner_codes ?? false);
        if (!$userOfficeCode && !$canViewAllCodes && !auth()->user()?->permissions?->is_sadm) {
            return ['all' => 0, 'incoming' => 0, 'received' => 0];
        }

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->whereNotIn('dt.status', ['completed', 'cancelled']);

        if ($userOfficeCode && !$canViewAllCodes) {
            $query->where(function ($q) use ($userOfficeCode) {
                $q->where('dt.current_office', $userOfficeCode)
                  ->orWhere(function ($sub) use ($userOfficeCode) {
                      $sub->where('dt.current_office', 'ORIGIN')
                          ->where('dtd.originated_from', $userOfficeCode);
                  });
            });
        }

        $allRows = $query->select('dt.transaction_id', 'dt.current_office')->get();
        $incomingCount = 0;
        $receivedCount = 0;

        foreach ($allRows as $row) {
            $checkOffice = $userOfficeCode ?: $row->current_office;
            $lastLog = DB::table(\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs')
                ->where('transaction_id', $row->transaction_id)
                ->where('office_code', $checkOffice)
                ->orderBy('id', 'desc')
                ->first();

            $isReceived = $lastLog && ($lastLog->type === 'received' || (!empty($lastLog->date_in) && $lastLog->type !== 'forwarded'));
            if ($isReceived) {
                $receivedCount++;
            } else {
                $incomingCount++;
            }
        }

        return [
            'all' => count($allRows),
            'incoming' => $incomingCount,
            'received' => $receivedCount,
        ];
    }

    /**
     * Toggles open/closed state of the scanner modal.
     *
     * @param string $code Optional barcode/QR code to prime modal with.
     * @return void
     */
    #[On('toggle-scanner-modal')]
    public function toggleModal(string $code = ''): void
    {
        if ($this->isOpen) {
            $this->closeModal();
        } else {
            $this->openModal($code);
        }
    }

    /**
     * Opens the scanner modal, notifies other widgets to close, and initializes camera scanner.
     *
     * @param string $code Optional code to immediately look up.
     * @param bool $isScan Whether this trigger originated from live optical scanning (true) or typing (false).
     * @return void
     */
    #[On('open-scanner-modal')]
    public function openModal(string $code = '', bool $isScan = false): void
    {
        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $setting = \Illuminate\Support\Facades\DB::table($sysTable)->where('key', 'dts_enable_available_codes')->value('value');
        $this->availableCodesEnabled = ($setting !== 'false');

        $this->isOpen = true;
        $this->showAvailableModal = false;
        $this->clearMessages();
        $this->activeTransaction = null;
        $this->forwardToOfficeCode = '';
        $this->forwardHubOffices = [];
        $this->forwardHubTempSelected = [];
        $this->showForwardHubModal = false;
        $this->scannedCode = trim($code);

        if (!empty($this->scannedCode)) {
            $this->loadTransaction($this->scannedCode, $isScan);
        }

        $this->dispatch('scanner-modal-opened');
        $this->dispatch('rms-widget-opened', widget: 'scanner');
        $this->dispatch('init-camera-scanner');
    }

    /**
     * Listener enforcing single-widget mutual exclusivity. Closes scanner modal when another widget opens.
     *
     * @param mixed $widget Widget name identifier string.
     * @param mixed $detail Optional payload array containing widget info.
     * @return void
     */
    #[On('rms-widget-opened')]
    public function handleOtherWidgetOpened($widget = null, $detail = null): void
    {
        $widgetName = $widget ?? (is_array($detail) ? ($detail['widget'] ?? null) : null);
        if ($widgetName && $widgetName !== 'scanner' && $this->isOpen) {
            $this->closeModal();
        }
    }

    /**
     * Closes the scanner modal, halts camera feed, and resets console states.
     *
     * @return void
     */
    #[On('close-scanner-modal')]
    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->isMaximized = false;
        $this->clearMessages();
        $this->activeTransaction = null;
        $this->scannedCode = '';
        $this->forwardToOfficeCode = '';
        $this->forwardHubOffices = [];
        $this->forwardHubTempSelected = [];
        $this->showForwardHubModal = false;
        $this->dispatch('scanner-modal-closed');
        $this->dispatch('stop-camera-scanner');
    }

    /**
     * Clears all active status alerts (successMessage and errorMessage).
     *
     * @return void
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Look up and load a transaction by barcode, QR code, or Control Number.
     *
     * Business rules:
     * - Auto-Receive ($isScan = true): Triggered when code comes from camera feed or barcode gun.
     *   If incoming to user's office, auto-receives custody, records receive log, updates sequence,
     *   dispatches notification, and activates a 5-second scanner cooldown.
     * - Manual Verification ($isScan = false): Triggered when code is typed into input box.
     *   Leaves the transaction in its current incoming state and requires manual receipt verification.
     * - Enforces office scoping: Rejects documents belonging to other stations.
     * - Checks working hours: Auto-receive is blocked outside working hours with policy banner.
     *
     * @param string|null $codeToLoad Raw barcode/QR code string.
     * @param bool $isScan Whether lookup originated from live optical scanning (true) or manual entry (false).
     * @return void
     */
    public function loadTransaction(?string $codeToLoad = null, bool $isScan = false): void
    {
        $this->clearMessages();
        $this->activeTransaction = null;

        if ($isScan) {
            $this->isManualInput = false;
            if ($this->cooldownUntil > time()) {
                $waitSec = $this->cooldownUntil - time();
                $this->errorMessage = "Scanner cooldown active ({$waitSec}s remaining). Please wait before scanning again.";
                $this->dispatch('scanner-code-invalid');
                $this->dispatch('scanner-audio-error');
                return;
            }
        } else {
            $this->isManualInput = true;
        }

        if ($codeToLoad !== null && trim($codeToLoad) !== '') {
            $this->scannedCode = trim($codeToLoad);
        }

        $rawCode = trim($this->scannedCode);
        if (empty($rawCode) && !empty($this->lastScannedCode)) {
            $rawCode = trim($this->lastScannedCode);
            $this->scannedCode = $rawCode;
        }

        if (empty($rawCode)) {
            return;
        }

        // Clean common barcode gun framing artifacts (trailing bracket, quotes, semicolons)
        $cleanedCode = trim($rawCode, " \t\n\r\0\x0B[]{}()\"'<>");

        if (str_contains($cleanedCode, '%')) {
            $cleanedCode = urldecode($cleanedCode);
        }

        // Attempt Base64 decode: accept if decoded result is printable ASCII (32–126)
        // or matches the XXXX-XXXX-... QR code dash-segment pattern.
        // Handles standard Base64 as well as URL-safe Base64 (- and _).
        $code = $cleanedCode;
        $candidateB64 = strtr($cleanedCode, '-_', '+/');
        if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $candidateB64) && strlen($candidateB64) >= 8) {
            $tryDecode = base64_decode($candidateB64, true);
            if ($tryDecode !== false) {
                $decodedClean = trim($tryDecode);
                // Accept if all chars are printable ASCII OR matches QR dash-segment pattern
                if (
                    preg_match('/^[\x20-\x7E]+$/', $decodedClean) ||
                    preg_match('/^[A-Z0-9]{4}(-[A-Z0-9]{4})+$/i', $decodedClean)
                ) {
                    $code = $decodedClean;
                }
            }
        }

        // Always synchronize scannedCode with the decoded code so the frontend input updates immediately
        $this->scannedCode = $code;
        $this->lastScannedCode = $code;

        // Log scan
        try {
            $logPath = storage_path('logs/dts_scans.log');
            $scanId = 'SCAN-' . strtoupper(Str::random(12));
            $username = auth()->user()?->username ?: 'Unknown';
            $officeName = auth()->user()?->details?->office?->office_name ?: 'Unknown Office';

            $logLine = json_encode([
                'scan_id' => $scanId,
                'scanned_data' => $rawCode,
                'decoded_data' => $code,
                'user' => $username,
                'office' => $officeName,
                'timestamp' => now()->toIso8601String(),
            ]) . PHP_EOL;

            File::append($logPath, $logLine);
        } catch (\Exception $e) {
            // Silently ignore log write failures
        }

        // Search by QR Code OR Control Number (supporting raw, cleaned, or decoded format)
        $transaction = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office') . ' as current_office_tb', 'current_office_tb.office_code', '=', 'dt.current_office')
            ->leftJoin((\Illuminate\Support\Facades\Schema::hasTable('sys_document_data') ? 'sys_document_data' : 'document_data') . ' as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where(function($q) use ($code, $cleanedCode, $rawCode) {
                $candidates = array_unique(array_filter([$code, $cleanedCode, $rawCode]));
                $q->whereIn('dt.qr_code', $candidates)
                  ->orWhereIn('dtd.control_number', $candidates);
            })
            ->select(
                'dt.transaction_id',
                'dt.trans_type as type',
                'dt.status',
                'dt.sequence',
                'dt.qr_code',
                'dt.current_office',
                'dt.revision_requested_by_office',
                'dt.revision_requested_by_sequence',
                'current_office_tb.office_name as current_office_name',
                'dtd.transaction_flow',
                'dtd.control_number',
                'req.requestor_name',
                'req.requestor_position as requestor_label',
                'dtd.subject',
                'dtd.classification',
                'dtd.originated_from',
                'originated_office.office_name as originated_office_name',
                'doc.document_name'
            )
            ->first();

        if (!$transaction) {
            $candidates = array_unique(array_filter([$code, $cleanedCode, $rawCode]));
            $qrExists = DB::table('dts_qr_code')->whereIn('code_id', $candidates)->exists();

            if ($qrExists) {
                $this->errorMessage = 'Inactive QR Code: Code registered in system but not yet linked to any transaction.';
            } else {
                $this->errorMessage = 'Invalid QR Code: Only valid, registered QR codes can be processed by the scanner.';
            }
            $this->dispatch('scanner-code-invalid');
            $this->dispatch('scanner-audio-error');
            return;
        }

        $rawStatus = strtolower($transaction->status);
        if ($rawStatus === 'completed') {
            $this->errorMessage = 'That QR code is already finished its transaction.';
            $this->dispatch('scanner-code-invalid');
            $this->dispatch('scanner-audio-error');
            return;
        }

        if ($rawStatus === 'cancelled') {
            $this->errorMessage = 'That QR code transaction has been cancelled.';
            $this->dispatch('scanner-code-invalid');
            $this->dispatch('scanner-audio-error');
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'User office code could not be resolved.';
            $this->dispatch('scanner-code-invalid');
            $this->dispatch('scanner-audio-error');
            return;
        }

        if ($transaction->current_office !== $userOfficeCode) {
            $currOfficeName = $transaction->current_office_name ?: $transaction->current_office;
            $this->errorMessage = "That QR code is no longer within your office transaction list. Current station: {$currOfficeName}.";
            $this->dispatch('scanner-code-invalid');
            $this->dispatch('scanner-audio-error');
            return;
        }

        $logsTbl = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $lastLog = DB::table($logsTbl)
            ->where('transaction_id', $transaction->transaction_id)
            ->where('office_code', $userOfficeCode)
            ->orderBy('id', 'desc')
            ->first();
        $isReceivedAtUserOffice = \App\Services\DtsReceiveGuardService::isReceived($lastLog);

        // Auto-Receive if scanned via camera or barcode gun and document is incoming to user office
        if ($isScan && !$isReceivedAtUserOffice) {
            if (!\App\Services\DtsWorkingHoursService::canProcessStationAction()) {
                $this->errorMessage = \App\Services\DtsWorkingHoursService::getStationActionRestrictionNotice();
                $this->dispatch('scanner-code-invalid');
                $this->dispatch('scanner-audio-error');
                return;
            }

            if (\App\Services\DtsReceiveGuardService::alreadyReceived((string) $transaction->transaction_id, $userOfficeCode)) {
                $this->errorMessage = "Transaction '{$transaction->control_number}' has already been received at your office.";
                $this->dispatch('scanner-code-invalid');
                $this->dispatch('scanner-audio-error');
                return;
            }

            try {
                DB::transaction(function () use ($transaction, $userOfficeCode) {
                    $transId = $transaction->transaction_id;
                    \App\Services\DtsReceiveGuardService::lockTransaction((string) $transId);
                    if (\App\Services\DtsReceiveGuardService::alreadyReceived((string) $transId, $userOfficeCode)) {
                        return;
                    }

                    $currentLog = DB::table('dts_transaction_logs')
                        ->where('transaction_id', $transId)
                        ->where('office_code', $userOfficeCode)
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($currentLog) {
                        DB::table('dts_transaction_logs')
                            ->where('id', $currentLog->id)
                            ->update([
                                'type' => 'received',
                                'date_in' => now(),
                                'performed_by' => auth()->id(),
                            ]);
                    } else {
                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $userOfficeCode,
                            'type' => 'received',
                            'date_in' => now(),
                            'notes' => 'Received via Global Scanner',
                            'performed_by' => auth()->id(),
                        ]);
                    }

                    if (!empty($transaction->transaction_flow)) {
                        $flow = DB::table('dts_transaction_flow')->where('flow_code', $transaction->transaction_flow)->first();
                        if ($flow) {
                            $seqRanking = $transaction->sequence;
                            $prevSeq = DB::table('dts_sequence_list')
                                ->where('control_id', $flow->id)
                                ->where('sequence_ranking', $seqRanking - 1)
                                ->first();
                            $totalTimeReceived = null;
                            if ($prevSeq && $prevSeq->date_out) {
                                $totalTimeReceived = \App\Services\DtsWorkingHoursService::calculateDurationString($prevSeq->date_out, now());
                            } elseif ($seqRanking == 1) {
                                $totalTimeReceived = '-';
                            }

                            DB::table('dts_sequence_list')
                                ->where('control_id', $flow->id)
                                ->where('sequence_ranking', $seqRanking)
                                ->update([
                                    'date_in' => now(),
                                    'account_received' => auth()->id(),
                                    'scanned_id' => true,
                                    'total_time_received' => $totalTimeReceived,
                                ]);
                        }
                    }

                    $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
                    $userFirstName = auth()->user()?->details?->first_name 
                        ?: DB::table($accDetailsTbl)->where('account_id', auth()->id())->value('first_name')
                        ?: auth()->user()?->username 
                        ?: 'User';

                    $controlNumber = $transaction->control_number ?? '';

                    if ($userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyReceived($userOfficeCode, $userFirstName, $controlNumber, $transId);
                    }

                    $originatedFrom = $transaction->originated_from ?? null;
                    if ($originatedFrom && $originatedFrom !== $userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyHubOfficeReceived($originatedFrom, $userOfficeCode, $controlNumber, $transId);
                    }
                });

                $isReceivedAtUserOffice = true;
                $currOfficeTitle = $transaction->current_office_name ?: $transaction->current_office;
                $this->successMessage = "Transaction '{$transaction->control_number}' received successfully at {$currOfficeTitle}!";
                $this->cooldownUntil = time() + 5;
                $this->dispatch('scanner-cooldown-start', ['seconds' => 5]);
                $this->dispatch('scanner-audio-action');
            } catch (\Exception $e) {
                $this->errorMessage = 'Failed to auto-receive transaction: ' . $e->getMessage();
                $this->dispatch('scanner-code-invalid');
                $this->dispatch('scanner-audio-error');
                return;
            }
        }

        // Get next office in sequence if flow exists
        $nextOfficeName = 'End of Flow';
        $nextOfficeCode = null;
        if (!empty($transaction->transaction_flow)) {
            $flow = DB::table('dts_transaction_flow')->where('flow_code', $transaction->transaction_flow)->first();
            if ($flow) {
                $nextSeq = DB::table('dts_sequence_list')
                    ->where('control_id', $flow->id)
                    ->where('sequence_ranking', $transaction->sequence + 1)
                    ->first();
                if ($nextSeq) {
                    $nextOfficeCode = $nextSeq->office_code;
                    if ($nextOfficeCode === 'ORIGIN') {
                        $nextOfficeCode = $transaction->originated_from;
                    } elseif ($nextOfficeCode === '[H]') {
                        // Resolve cluster head placeholder from the originating office's cluster
                        $originClusterCode = DB::table('sys_office')->where('office_code', $transaction->originated_from)->value('cluster');
                        $nextOfficeCode = $originClusterCode
                            ? (DB::table('sys_cluster')->where('cluster_code', $originClusterCode)->value('cluster_head') ?: $transaction->originated_from)
                            : $transaction->originated_from;
                    }
                    $nextOfficeName = DB::table('sys_office')->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                }
            }
        }

        $this->activeTransaction = [
            'id' => $transaction->transaction_id,
            'control_number' => $transaction->control_number,
            'qr_code' => $transaction->qr_code,
            'type' => ucfirst($transaction->type),
            'subject' => $transaction->subject ?: 'No subject specified',
            'requestor_name' => $transaction->requestor_name ?: 'N/A',
            'originated_office' => $transaction->originated_office_name ?: $transaction->originated_from,
            'originated_office_code' => $transaction->originated_from,
            'current_office' => $transaction->current_office_name ?: $transaction->current_office,
            'current_office_code' => $transaction->current_office,
            'next_office' => $nextOfficeName,
            'next_office_code' => $nextOfficeCode,
            'status' => ucfirst($transaction->status),
            'is_completed' => false,
            'sequence' => $transaction->sequence,
            'document_name' => $transaction->document_name,
            'is_received_here' => $isReceivedAtUserOffice,
            'revision_requested_by_office' => $transaction->revision_requested_by_office ?? null,
            'revision_requested_by_sequence' => $transaction->revision_requested_by_sequence ?? null,
            'transaction_flow' => $transaction->transaction_flow,
        ];

        // Push scan to session recent history
        array_unshift($this->recentScans, [
            'control_number' => $transaction->control_number,
            'subject' => Str::limit($transaction->subject ?: 'No subject', 35),
            'scanned_at' => now()->format('h:i:s A'),
            'status' => $transaction->status,
        ]);
        $this->recentScans = array_slice($this->recentScans, 0, 5);
        session()->put('dts_recent_scans', $this->recentScans);

        $this->dispatch('scanner-code-success');
    }

    /**
     * Executes station processing actions on the currently active loaded transaction.
     *
     * Handles:
     * - Case 1: Manual Receive of incoming document (with pessimistic lock & idempotency check).
     * - Case 2: Dynamic forwarding to another office or Office Hub broadcast.
     * - Case 3: Returning document to Origin for revision.
     * - Case 4: Normal forward along pre-defined sequence flow or marking document completed.
     *
     * @return void
     */
    public function processScanAction(): void
    {
        $this->clearMessages();

        if (!\App\Services\DtsWorkingHoursService::canProcessStationAction()) {
            $this->errorMessage = \App\Services\DtsWorkingHoursService::getStationActionRestrictionNotice();
            return;
        }

        if (!$this->activeTransaction) {
            $this->errorMessage = 'No transaction selected.';
            return;
        }

        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());

        if (!$userOfficeCode) {
            $this->errorMessage = 'User office code could not be resolved.';
            return;
        }

        if ($this->activeTransaction['current_office_code'] !== $userOfficeCode) {
            $this->errorMessage = 'That QR code is no longer within your office transaction list.';
            return;
        }

        // A document may only be received ONCE per office visit.
        $isReceived = (bool) ($this->activeTransaction['is_received_here'] ?? false);
        $receiveTransId = (string) $this->activeTransaction['id'];
        if (!$isReceived && \App\Services\DtsReceiveGuardService::alreadyReceived($receiveTransId, $userOfficeCode)) {
            $this->errorMessage = "Transaction '{$this->activeTransaction['control_number']}' has already been received at your office.";
            return;
        }

        $alreadyReceived = false;

        try {
            DB::transaction(function () use ($userOfficeCode, $isReceived, &$alreadyReceived) {
                $transId = $this->activeTransaction['id'];

                // Case 1: Receive incoming transaction
                if (!$isReceived) {
                    // Row lock makes the re-check authoritative against concurrent scans.
                    \App\Services\DtsReceiveGuardService::lockTransaction((string) $transId);
                    if (\App\Services\DtsReceiveGuardService::alreadyReceived((string) $transId, $userOfficeCode)) {
                        $alreadyReceived = true;
                        return;
                    }

                    $currentLog = DB::table('dts_transaction_logs')
                        ->where('transaction_id', $transId)
                        ->where('office_code', $userOfficeCode)
                        ->orderBy('id', 'desc')
                        ->first();

                    if ($currentLog) {
                        DB::table('dts_transaction_logs')
                            ->where('id', $currentLog->id)
                            ->update([
                                'type' => 'received',
                                'date_in' => now(),
                                'performed_by' => auth()->id(),
                            ]);
                    } else {
                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $userOfficeCode,
                            'type' => 'received',
                            'date_in' => now(),
                            'notes' => 'Received via Global Scanner',
                            'performed_by' => auth()->id(),
                        ]);
                    }

                    if (!empty($this->activeTransaction['transaction_flow'])) {
                        $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                        if ($flow) {
                            $seqRanking = $this->activeTransaction['sequence'];
                            $prevSeq = DB::table('dts_sequence_list')
                                ->where('control_id', $flow->id)
                                ->where('sequence_ranking', $seqRanking - 1)
                                ->first();
                            $totalTimeReceived = null;
                            if ($prevSeq && $prevSeq->date_out) {
                                $totalTimeReceived = \App\Services\DtsWorkingHoursService::calculateDurationString($prevSeq->date_out, now());
                            } elseif ($seqRanking == 1) {
                                $totalTimeReceived = '-';
                            }

                            DB::table('dts_sequence_list')
                                ->where('control_id', $flow->id)
                                ->where('sequence_ranking', $seqRanking)
                                ->update([
                                    'date_in' => now(),
                                    'account_received' => auth()->id(),
                                    'scanned_id' => true,
                                    'total_time_received' => $totalTimeReceived,
                                ]);
                        }
                    }

                    $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
                    $userFirstName = auth()->user()?->details?->first_name 
                        ?: DB::table($accDetailsTbl)->where('account_id', auth()->id())->value('first_name')
                        ?: auth()->user()?->username 
                        ?: 'User';

                    $controlNumber = $this->activeTransaction['control_number'] ?? '';

                    if ($userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyReceived($userOfficeCode, $userFirstName, $controlNumber, $transId);
                    }

                    $originatedFrom = $this->activeTransaction['originated_office_code'] ?? null;
                    if ($originatedFrom && $originatedFrom !== $userOfficeCode) {
                        \App\Services\DtsNotificationService::notifyHubOfficeReceived($originatedFrom, $userOfficeCode, $controlNumber, $transId);
                    }

                    $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' received successfully at {$this->activeTransaction['current_office']}!";
                    $this->activeTransaction['is_received_here'] = true;
                    $this->cooldownUntil = time() + 5;
                    $this->dispatch('scanner-cooldown-start', ['seconds' => 5]);
                } 
                // Case 2: Forward received transaction
                else {
                    if ($this->actionNeeded === 'To be Forwarded to') {
                        if (empty($this->forwardToOfficeCode)) {
                            $this->errorMessage = 'Please select a destination office to forward to.';
                            return;
                        }

                        if ($this->forwardToOfficeCode === '[HUB]' && empty($this->forwardHubOffices)) {
                            $this->errorMessage = 'Please select at least one recipient office for Office Hub broadcast.';
                            return;
                        }

                        try {
                            $result = \App\Services\DtsRoutingService::forwardToOtherOffice(
                                $transId,
                                $this->forwardToOfficeCode,
                                $this->notes,
                                auth()->id(),
                                $this->forwardHubOffices
                            );
                            $this->successMessage = "Transaction '{$result['control_number']}' forwarded to {$result['target_office_name']}!";
                            $this->logScan($this->activeTransaction['qr_code'], 'Forwarded to ' . $result['target_office_name'], 'success');
                            $this->activeTransaction = null;
                            $this->scannedCode = '';
                            $this->forwardToOfficeCode = '';
                            $this->forwardHubOffices = [];
                            $this->forwardHubTempSelected = [];
                            $this->showForwardHubModal = false;
                        } catch (\Throwable $e) {
                            $this->errorMessage = 'Failed to forward transaction: ' . $e->getMessage();
                        }
                        return;
                    }

                    $isRevisionAction = in_array($this->actionNeeded, ['For Revision', 'Returned for Revision']);

                    if ($isRevisionAction) {
                        $transDb = DB::table('dts_transaction_details')->where('id', $transId)->first();
                        $originatedFrom = $transDb?->originated_from ?? 'ORIGIN';

                        // Update date_out for user office
                        DB::table('dts_transaction_logs')
                            ->where('transaction_id', $transId)
                            ->where('office_code', $userOfficeCode)
                            ->whereNull('date_out')
                            ->update([
                                'date_out' => now(),
                                'notes' => $this->notes ?: 'Returned for Revision via Scanner',
                                'type' => 'returned',
                                'performed_by' => auth()->id(),
                            ]);

                        $updateTransData = [
                            'current_office' => $originatedFrom,
                            'sequence' => 1,
                            'status' => 'revision',
                        ];
                        if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_requested_by_sequence')) {
                            $updateTransData['revision_requested_by_sequence'] = $this->activeTransaction['sequence'];
                            $updateTransData['revision_requested_by_office'] = $userOfficeCode;
                        }
                        if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_count')) {
                            $updateTransData['revision_count'] = DB::raw('COALESCE(revision_count, 0) + 1');
                        }

                        DB::table('dts_transactions')
                            ->where('transaction_id', $transId)
                            ->update($updateTransData);

                        DB::table('dts_transaction_details')
                            ->where('id', $transId)
                            ->update([
                                'action_needed' => 'For Revision',
                            ]);

                        if (!empty($transDb->transaction_flow)) {
                            $flow = DB::table('dts_transaction_flow')->where('flow_code', $transDb->transaction_flow)->first();
                            if ($flow) {
                                // Close out the forwarding office's step
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $flow->id)
                                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                                    ->update([
                                        'date_out' => now(),
                                        'account_forwarded' => auth()->id(),
                                        'action_needed' => 'For Revision',
                                        'note' => $this->notes ?: 'Returned for Revision',
                                    ]);

                                // Update ORIGIN step to receive the revision
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $flow->id)
                                    ->where('sequence_ranking', 1)
                                    ->update([
                                        'date_in' => now(),
                                        'date_out' => null,
                                        'account_received' => auth()->id(),
                                        'account_forwarded' => null,
                                        'action_needed' => 'Returned for Revision',
                                        'note' => $this->notes,
                                        'total_time_completed' => null,
                                    ]);
                            }
                        }

                        DB::table('dts_transaction_logs')->insert([
                            'transaction_id' => $transId,
                            'office_code' => $originatedFrom,
                            'type' => 'returned',
                            'date_in' => now(),
                            'date_out' => null,
                            'notes' => 'Returned for revision from ' . $userOfficeCode . ': ' . ($this->notes ?: 'Please revise document'),
                            'performed_by' => auth()->id(),
                        ]);

                        $controlNumber = $this->activeTransaction['control_number'] ?? '';
                        \App\Services\DtsNotificationService::createNotification(
                            $originatedFrom,
                            "Transaction {$controlNumber} has been returned for revision by {$userOfficeCode}.",
                            '/dts?open=' . urlencode($transId)
                        );

                        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                        $originatedOfficeName = DB::table($officeTbl)->where('office_code', $originatedFrom)->value('office_name') ?: $originatedFrom;
                        $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' returned for revision to {$originatedOfficeName}!";
                    } else {
                        // Check if transaction is in revision status (fresh from revision at Originator)
                        $isRevisionResubmit = strtolower($this->activeTransaction['status']) === 'revision';
                        $targetSeqNum = $this->activeTransaction['sequence'] + 1;
                        $nextOfficeCode = $this->activeTransaction['next_office_code'];

                        if ($isRevisionResubmit) {
                            $reqSeq = $this->activeTransaction['revision_requested_by_sequence'] ?? null;
                            if ($this->resubmitTarget === 'requestor' && $reqSeq && $reqSeq > 1) {
                                $targetSeqNum = (int)$reqSeq;
                            } else {
                                $targetSeqNum = 2; // Option A: Restart at sequence 2 (e.g. VP)
                            }

                            if (!empty($this->activeTransaction['transaction_flow'])) {
                                $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                                if ($flow) {
                                    $nextSeq = DB::table('dts_sequence_list')
                                        ->where('control_id', $flow->id)
                                        ->where('sequence_ranking', $targetSeqNum)
                                        ->first();
                                    if ($nextSeq) {
                                        $nextOfficeCode = $nextSeq->office_code;
                                        if ($nextOfficeCode === 'ORIGIN') {
                                            $nextOfficeCode = $this->activeTransaction['originated_office_code'];
                                        } elseif ($nextOfficeCode === '[H]') {
                                            // Resolve cluster head placeholder from the originating office's cluster
                                            $originClusterCode = DB::table('sys_office')->where('office_code', $this->activeTransaction['originated_office_code'])->value('cluster');
                                            $nextOfficeCode = $originClusterCode
                                                ? (DB::table('sys_cluster')->where('cluster_code', $originClusterCode)->value('cluster_head') ?: $this->activeTransaction['originated_office_code'])
                                                : $this->activeTransaction['originated_office_code'];
                                        }
                                    }
                                }
                            }
                            if (!$nextOfficeCode) {
                                $nextOfficeCode = $this->activeTransaction['revision_requested_by_office'] ?? $this->activeTransaction['next_office_code'];
                            }
                        }

                        // Conditional sequence list wipe at resubmit time
                        if ($isRevisionResubmit && !empty($this->activeTransaction['transaction_flow'])) {
                            $revFlow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                            if ($revFlow) {
                                $reqSeq = $this->activeTransaction['revision_requested_by_sequence'] ?? null;
                                if ($reqSeq) {
                                    if ($this->resubmitTarget === 'start') {
                                        // Option A: Wipe steps from 2 up to requestor sequence
                                        DB::table('dts_sequence_list')
                                            ->where('control_id', $revFlow->id)
                                            ->where('sequence_ranking', '>', 1)
                                            ->where('sequence_ranking', '<=', $reqSeq)
                                            ->update([
                                                'date_in' => null, 'date_out' => null,
                                                'action_needed' => null, 'note' => null,
                                                'total_time_completed' => null,
                                            ]);
                                    } else {
                                        // Option B: Only reset the requestor's step
                                        DB::table('dts_sequence_list')
                                            ->where('control_id', $revFlow->id)
                                            ->where('sequence_ranking', $reqSeq)
                                            ->update([
                                                'date_in' => null, 'date_out' => null,
                                                'action_needed' => null, 'note' => null,
                                                'total_time_completed' => null,
                                            ]);
                                    }
                                }
                                // Close ORIGIN's date_out on resubmit
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $revFlow->id)
                                    ->where('sequence_ranking', 1)
                                    ->update([
                                        'date_out' => now(),
                                        'action_needed' => 'Resubmitted (' . ($this->resubmitTarget === 'start' ? 'Restart' : 'Fast-Track') . ')',
                                    ]);
                            }
                        }

                        // Update date_out for user office
                        $logNote = $isRevisionResubmit 
                            ? ($this->resubmitTarget === 'requestor' ? 'Resubmitted directly to ' : 'Resubmitted to ') . $nextOfficeCode
                            : ($this->notes ?: 'Forwarded via Global Scanner');

                        DB::table('dts_transaction_logs')
                            ->where('transaction_id', $transId)
                            ->where('office_code', $userOfficeCode)
                            ->whereNull('date_out')
                            ->update([
                                'date_out' => now(),
                                'notes' => $logNote,
                                'type' => 'forwarded',
                                'performed_by' => auth()->id(),
                            ]);

                        if (!empty($this->activeTransaction['transaction_flow'])) {
                            $flow = DB::table('dts_transaction_flow')->where('flow_code', $this->activeTransaction['transaction_flow'])->first();
                            if ($flow) {
                                DB::table('dts_sequence_list')
                                    ->where('control_id', $flow->id)
                                    ->where('sequence_ranking', $this->activeTransaction['sequence'])
                                    ->update([
                                        'date_out' => now(),
                                        'account_forwarded' => auth()->id(),
                                        'action_needed' => $this->actionNeeded,
                                        'note' => $this->notes ?: null,
                                    ]);
                            }
                        }

                        // If there is a next office in flow
                        if ($nextOfficeCode) {
                            $updateTransData = [
                                'current_office' => $nextOfficeCode,
                                'sequence' => $targetSeqNum,
                                'status' => 'ongoing',
                            ];
                            if (\Illuminate\Support\Facades\Schema::hasColumn('dts_transactions', 'revision_resubmit_type')) {
                                $updateTransData['revision_resubmit_type'] = $this->resubmitTarget;
                            }
                            // KEEP revision_requested_by_* — needed for timeline coloring

                            DB::table('dts_transactions')
                                ->where('transaction_id', $transId)
                                ->update($updateTransData);

                            DB::table('dts_transaction_details')
                                ->where('id', $transId)
                                ->update([
                                    'action_needed' => $this->actionNeeded,
                                ]);

                            DB::table('dts_transaction_logs')->insert([
                                'transaction_id' => $transId,
                                'office_code' => $nextOfficeCode,
                                'type' => 'forwarded',
                                'date_in' => null,
                                'notes' => $logNote,
                                'performed_by' => auth()->id(),
                            ]);

                            $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
                            $accDetailsTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

                            $destOfficeName = DB::table($officeTbl)->where('office_code', $nextOfficeCode)->value('office_name') ?: $nextOfficeCode;
                            $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' forwarded successfully to {$destOfficeName}!";

                            $userFirstName = auth()->user()?->details?->first_name 
                                ?: DB::table($accDetailsTbl)->where('account_id', auth()->id())->value('first_name')
                                ?: auth()->user()?->username 
                                ?: 'User';

                            $controlNumber = $this->activeTransaction['control_number'] ?? '';

                            if ($userOfficeCode) {
                                \App\Services\DtsNotificationService::notifyForwarded($userOfficeCode, $userFirstName, $controlNumber, $transId);
                            }

                            if (!empty($nextOfficeCode)) {
                                \App\Services\DtsNotificationService::notifyWaitingToBeReceived($nextOfficeCode, $controlNumber, $transId);
                            }

                            $originatedFrom = $this->activeTransaction['originated_office_code'] ?? null;
                            if ($originatedFrom && $originatedFrom !== $userOfficeCode) {
                                \App\Services\DtsNotificationService::notifyHubOfficeForwarded($originatedFrom, $userOfficeCode, $controlNumber, $transId, $userFirstName);
                            }
                        } else {
                            // End of flow -> Complete
                            DB::table('dts_transactions')
                                ->where('transaction_id', $transId)
                                ->update([
                                    'status' => 'completed',
                                ]);

                            $controlNumber = $this->activeTransaction['control_number'] ?? '';
                            if ($userOfficeCode) {
                                \App\Services\DtsNotificationService::notifyCompleted($userOfficeCode, $controlNumber, $transId);
                            }

                            // Hand fully-completed transaction to RDP intake
                            \App\Services\DtsRdpIntakeService::recordCompleted($transId);
                            $this->dispatch('open-dts-rdp-handoff', transactionId: $transId, controlNumber: $controlNumber);

                            $this->successMessage = "Transaction '{$this->activeTransaction['control_number']}' marked as COMPLETED!";
                        }
                    }
                }
            });

            if ($alreadyReceived) {
                $this->errorMessage = "Transaction '{$this->activeTransaction['control_number']}' has already been received at your office.";
                $this->dispatch('scanner-audio-error');
                return;
            }

            // Clear the scan so the same document cannot be actioned again
            // (e.g. accidentally Forwarding right after Receive).
            $this->activeTransaction = null;
            $this->scannedCode = '';
            $this->notes = '';
            $this->dispatch('scanner-audio-action');
            $this->dispatch('focus-scanner-input');
            $this->dispatch('dts-transaction-updated');
        } catch (\Throwable $e) {
            $this->errorMessage = 'Action failed: ' . $e->getMessage();
            $this->dispatch('scanner-audio-error');
        }
    }
};
?>

<div>
    <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
    <script>
    if (typeof Html5Qrcode === 'undefined') {
        // Dynamic injection instead of document.write(): document.write() wipes
        // the whole page when this runs after the initial page parse.
        (function () {
            var s = document.createElement('script');
            s.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
            document.head.appendChild(s);
        })();
    }
    </script>

    @if($isOpen)
        <div 
            id="dts-scanner-widget-card"
            class="dts-scanner-widget-card {{ $isMaximized ? 'is-maximized' : '' }}"
            @keydown.window.escape="$wire.closeModal()"
        >
            <!-- Chatify-Style Dark Header Bar -->
            <div class="dts-scanner-widget-header">
                <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                    <div class="scanner-live-dot" title="Live Scanner Armed"></div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="scanner-header-title">DTS Scanner</span>
                        <span class="scanner-header-tag">Live Console</span>
                    </div>
                </div>

                <!-- Header Actions Toolbar -->
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    @if($availableCodesEnabled)
                    <!-- Available Directory Button -->
                    <button
                        type="button"
                        wire:click="toggleAvailableCodesModal"
                        class="scanner-header-pill-btn btn-cyan {{ $showAvailableModal ? 'btn-cyan-active' : '' }}"
                        title="Browse pending documents for your station"
                    >
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                        <span>Available</span>
                        <span class="count-badge">{{ $this->availableCounts['all'] ?? 0 }}</span>
                    </button>
                    @endif


                    <!-- Reset / Reload Console -->
                    <button
                        type="button"
                        wire:click="resetConsole"
                        class="scanner-header-icon-btn"
                        title="Reset Scanner Console"
                    >
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>

                    <!-- Maximize / Restore Toggle -->
                    <button
                        type="button"
                        wire:click="toggleMaximize"
                        class="scanner-header-icon-btn"
                        title="{{ $isMaximized ? 'Restore Floating Size' : 'Expand Full Window' }}"
                    >
                        @if($isMaximized)
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>
                            </svg>
                        @else
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            </svg>
                        @endif
                    </button>

                    <!-- Minimize Button -->
                    <button
                        type="button"
                        wire:click="closeModal"
                        class="scanner-header-icon-btn"
                        title="Minimize Scanner"
                    >
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                </div>
            </div>

            <!-- Styles Scoped to Scanner Widget -->
            <style>
                .dts-scanner-widget-card {
                    position: fixed;
                    bottom: 88px;
                    right: 24px;
                    width: 1040px;
                    max-width: calc(100vw - 32px);
                    height: 520px;
                    max-height: calc(100vh - 105px);
                    border-radius: 16px;
                    background: #ffffff;
                    box-shadow: 0 24px 50px -10px rgba(15, 23, 42, 0.32), 0 0 0 1px rgba(148, 163, 184, 0.25);
                    z-index: 999999;
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                    animation: scannerWidgetPop 0.22s cubic-bezier(0.16, 1, 0.3, 1);
                    transition: width 0.2s ease, height 0.2s ease, bottom 0.2s ease, right 0.2s ease, border-radius 0.2s ease;
                    font-family: 'Inter', system-ui, -apple-system, sans-serif;
                }
                .dts-scanner-widget-card.is-maximized {
                    bottom: 16px;
                    right: 16px;
                    width: calc(100vw - 32px);
                    max-width: calc(100vw - 32px);
                    height: calc(100vh - 32px);
                    max-height: calc(100vh - 32px);
                    border-radius: 14px;
                    box-shadow: 0 25px 60px -10px rgba(15, 23, 42, 0.5), 0 0 0 1px rgba(148, 163, 184, 0.3);
                }
                @keyframes scannerWidgetPop {
                    0% {
                        opacity: 0;
                        transform: translateY(16px) scale(0.96);
                    }
                    100% {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }
                .dts-scanner-widget-header {
                    background: #0f172a;
                    padding: 10px 16px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                    user-select: none;
                    flex-shrink: 0;
                    gap: 12px;
                }
                .scanner-live-dot {
                    width: 9px;
                    height: 9px;
                    border-radius: 50%;
                    background: #10b981;
                    box-shadow: 0 0 8px #10b981;
                    animation: scannerLivePulse 2s infinite;
                }
                @keyframes scannerLivePulse {
                    0%, 100% { opacity: 1; transform: scale(1); }
                    50% { opacity: 0.65; transform: scale(0.85); }
                }
                .scanner-header-title {
                    color: #f8fafc;
                    font-weight: 700;
                    font-size: 13.5px;
                    letter-spacing: 0.2px;
                }
                .scanner-header-tag {
                    background: rgba(255, 255, 255, 0.14);
                    color: #94a3b8;
                    font-size: 10px;
                    font-weight: 700;
                    padding: 2px 7px;
                    border-radius: 999px;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                }
                .scanner-header-pill-btn {
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                    background: rgba(255, 255, 255, 0.08);
                    border: 1px solid rgba(255, 255, 255, 0.15);
                    color: #cbd5e1;
                    border-radius: 7px;
                    padding: 4px 9px;
                    font-size: 11.5px;
                    font-weight: 700;
                    cursor: pointer;
                    transition: all 0.15s ease;
                    white-space: nowrap;
                }
                .scanner-header-pill-btn:hover {
                    background: rgba(255, 255, 255, 0.16);
                    color: #ffffff;
                }
                .scanner-header-pill-btn.btn-cyan {
                    background: rgba(2, 132, 199, 0.25);
                    border-color: rgba(56, 189, 248, 0.45);
                    color: #38bdf8;
                }
                .scanner-header-pill-btn.btn-cyan:hover,
                .scanner-header-pill-btn.btn-cyan-active {
                    background: rgba(2, 132, 199, 0.5) !important;
                    border-color: #38bdf8 !important;
                    color: #ffffff !important;
                    box-shadow: 0 0 10px rgba(56, 189, 248, 0.4);
                }
                .scanner-header-pill-btn.btn-cyan .count-badge {
                    background: #0284c7;
                    color: #ffffff;
                    font-size: 10px;
                    padding: 1px 5px;
                    border-radius: 99px;
                }
                .scanner-header-pill-btn.btn-green-active {
                    background: rgba(22, 163, 74, 0.28);
                    border-color: rgba(74, 222, 128, 0.5);
                    color: #4ade80;
                }
                .scanner-header-pill-btn.btn-amber-active {
                    background: rgba(217, 119, 6, 0.28);
                    border-color: rgba(251, 191, 36, 0.5);
                    color: #fbbf24;
                }
                .scanner-header-icon-btn {
                    background: transparent;
                    border: none;
                    color: #94a3b8;
                    cursor: pointer;
                    padding: 4px;
                    border-radius: 6px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    transition: color 0.15s ease, background 0.15s ease;
                }
                .scanner-header-icon-btn:hover {
                    color: #f8fafc;
                    background: rgba(255, 255, 255, 0.1);
                }
                .dts-scanner-widget-body {
                    flex: 1;
                    min-height: 0;
                    display: flex;
                    flex-direction: row;
                    background: #ffffff;
                    overflow: hidden;
                    position: relative;
                }
                .dts-scanner-left-pane {
                    width: 390px;
                    flex-shrink: 0;
                    border-right: 1px solid #e2e8f0;
                    display: flex;
                    flex-direction: column;
                    padding: 12px 14px;
                    gap: 9px;
                    overflow-y: auto;
                    background: #ffffff;
                    box-sizing: border-box;
                }
                .dts-scanner-right-pane {
                    flex: 1;
                    min-width: 0;
                    display: flex;
                    flex-direction: column;
                    background: #f8fafc;
                    padding: 14px 18px;
                    overflow-y: auto;
                    box-sizing: border-box;
                }
                .scanner-alert {
                    padding: 9px 12px;
                    border-radius: 8px;
                    font-size: 12px;
                    font-weight: 600;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 8px;
                }
                .scanner-alert.alert-success {
                    background: #f0fdf4;
                    border: 1px solid #bbf7d0;
                    color: #166534;
                }
                .scanner-alert.alert-danger {
                    background: #fef2f2;
                    border: 1px solid #fecaca;
                    color: #991b1b;
                }
                .dts-scanner-viewport-container {
                    background: #0f172a;
                    border-radius: 10px;
                    overflow: hidden;
                    position: relative;
                    width: 100%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.6);
                }
                #modal-qr-preview {
                    width: 100% !important;
                    height: 100% !important;
                    border: none !important;
                    position: relative;
                    overflow: hidden;
                }
                #modal-qr-preview video {
                    width: 100% !important;
                    height: 100% !important;
                    object-fit: cover !important;
                    border-radius: 10px;
                    display: block;
                }
                #modal-qr-preview__scan_region {
                    border: 2.5px dashed #0284c7 !important;
                    border-radius: 12px !important;
                    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.55), 0 0 20px rgba(2, 132, 199, 0.45) !important;
                }
                #modal-qr-preview__scan_region img {
                    display: none !important;
                }
                #modal-qr-preview__dashboard {
                    display: none !important;
                }
                .scanner-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                    padding: 4px 10px;
                    border-radius: 20px;
                    font-size: 11.5px;
                    font-weight: 700;
                    line-height: 1.2;
                }
                .scanner-badge.badge-completed {
                    background: #f1f5f9;
                    color: #475569;
                    border: 1px solid #cbd5e1;
                }
                .scanner-badge.badge-received {
                    background: #f0fdf4;
                    color: #166534;
                    border: 1px solid #bbf7d0;
                }
                .scanner-badge.badge-incoming {
                    background: #eff6ff;
                    color: #1e40af;
                    border: 1px solid #bfdbfe;
                }
                .scanner-input-row {
                    display: flex;
                    gap: 6px;
                    width: 100%;
                    box-sizing: border-box;
                }
                .scanner-details-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 6px 12px;
                    font-size: 12px;
                    color: #334155;
                    word-break: break-word;
                }
                .scanner-action-btn {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                    padding: 9px 18px;
                    color: #ffffff;
                    border: none;
                    border-radius: 8px;
                    font-weight: 700;
                    font-size: 12.5px;
                    cursor: pointer;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
                    transition: transform 0.1s ease, box-shadow 0.15s ease;
                }
                .scanner-action-btn:active {
                    transform: scale(0.98);
                }

                @media (max-width: 860px) {
                    .dts-scanner-widget-card {
                        width: calc(100vw - 20px) !important;
                        height: calc(100vh - 90px) !important;
                        bottom: 75px !important;
                        right: 10px !important;
                    }
                    .dts-scanner-widget-body {
                        flex-direction: column !important;
                        overflow-y: auto !important;
                    }
                    .dts-scanner-left-pane {
                        width: 100% !important;
                        border-right: none !important;
                        border-bottom: 1px solid #e2e8f0 !important;
                    }
                    .scanner-details-grid {
                        grid-template-columns: 1fr !important;
                    }
                }

                /* ------------------------------------------------------------------ */
                /* Dark mode — match header chrome; no leftover white panels/inputs.  */
                /* ------------------------------------------------------------------ */
                [data-theme="dark"] .dts-scanner-widget-card {
                    background: #0f172a;
                    box-shadow: 0 24px 50px -10px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(51, 65, 85, 0.9);
                }
                [data-theme="dark"] .dts-scanner-widget-body {
                    background: #0f172a;
                }
                [data-theme="dark"] .dts-scanner-left-pane {
                    background: #111827;
                    border-right-color: #1e293b;
                }
                [data-theme="dark"] .dts-scanner-right-pane {
                    background: #0b1220;
                }
                [data-theme="dark"] .scanner-alert.alert-success {
                    background: rgba(22, 101, 52, 0.25);
                    border-color: #166534;
                    color: #86efac;
                }
                [data-theme="dark"] .scanner-alert.alert-danger {
                    background: rgba(127, 29, 29, 0.3);
                    border-color: #7f1d1d;
                    color: #fca5a5;
                }
                [data-theme="dark"] .scanner-badge.badge-completed {
                    background: #1e293b;
                    color: #cbd5e1;
                    border-color: #334155;
                }
                [data-theme="dark"] .scanner-badge.badge-received {
                    background: rgba(22, 101, 52, 0.25);
                    color: #86efac;
                    border-color: #166534;
                }
                [data-theme="dark"] .scanner-badge.badge-incoming {
                    background: rgba(30, 64, 175, 0.25);
                    color: #93c5fd;
                    border-color: #1e3a8a;
                }
                [data-theme="dark"] .scanner-details-grid {
                    color: #cbd5e1;
                }
                [data-theme="dark"] .scanner-code-input {
                    background: #0f172a !important;
                    border-color: #334155 !important;
                    color: #f8fafc !important;
                }
                [data-theme="dark"] .scanner-code-input::placeholder {
                    color: #64748b !important;
                }
                [data-theme="dark"] .scanner-code-input:focus {
                    border-color: #38bdf8 !important;
                }
                [data-theme="dark"] .scanner-standby-panel {
                    background: #111827 !important;
                    border-color: #334155 !important;
                }
                [data-theme="dark"] .scanner-standby-panel h4 {
                    color: #f8fafc !important;
                }
                [data-theme="dark"] .scanner-standby-panel p {
                    color: #94a3b8 !important;
                }
                [data-theme="dark"] .scanner-standby-icon {
                    background: #0c4a6e !important;
                    color: #38bdf8 !important;
                    box-shadow: 0 4px 14px rgba(2, 132, 199, 0.25) !important;
                }
                [data-theme="dark"] .scanner-stat-pending {
                    background: rgba(22, 101, 52, 0.2) !important;
                    border-color: #166534 !important;
                }
                [data-theme="dark"] .scanner-stat-pending > div {
                    color: #86efac !important;
                }
                [data-theme="dark"] .scanner-stat-custody {
                    background: rgba(30, 64, 175, 0.22) !important;
                    border-color: #1e40af !important;
                }
                [data-theme="dark"] .scanner-stat-custody > div {
                    color: #93c5fd !important;
                }
                [data-theme="dark"] .scanner-txn-card {
                    background: #111827 !important;
                    border-color: #334155 !important;
                    box-shadow: none !important;
                }
                [data-theme="dark"] .scanner-txn-card h4 {
                    color: #f8fafc !important;
                }
                [data-theme="dark"] .scanner-recent-item {
                    background: #0f172a !important;
                    border-color: #1e293b !important;
                }
                [data-theme="dark"] .scanner-recent-item:hover {
                    background: #1e293b !important;
                }
                [data-theme="dark"] #modal-camera-select {
                    background: #0f172a !important;
                    border-color: #334155 !important;
                    color: #e2e8f0 !important;
                }
                [data-theme="dark"] .dts-scanner-left-pane {
                    color: #cbd5e1;
                }
                [data-theme="dark"] .dts-scanner-left-pane div[style*="border-top: 1px solid #f1f5f9"],
                [data-theme="dark"] .dts-scanner-left-pane div[style*="border-top:1px solid #f1f5f9"] {
                    border-top-color: #1e293b !important;
                }
                [data-theme="dark"] .dts-scanner-right-pane span[style*="background: #f1f5f9"],
                [data-theme="dark"] .dts-scanner-right-pane span[style*="background:#f1f5f9"] {
                    background: #1e293b !important;
                    color: #cbd5e1 !important;
                    border-color: #334155 !important;
                }
                [data-theme="dark"] .dts-scanner-right-pane span[style*="color: #64748b"],
                [data-theme="dark"] .dts-scanner-right-pane span[style*="color:#64748b"] {
                    color: #94a3b8 !important;
                }
                [data-theme="dark"] .dts-scanner-left-pane button[style*="background: #eff6ff"],
                [data-theme="dark"] .dts-scanner-left-pane button[style*="background:#eff6ff"] {
                    background: #0c4a6e !important;
                    border-color: #0369a1 !important;
                    color: #7dd3fc !important;
                }
                @media (max-width: 860px) {
                    [data-theme="dark"] .dts-scanner-left-pane {
                        border-bottom-color: #1e293b !important;
                    }
                }
            </style>

            <!-- Two-Column Body (Landscape 2:1 Split) -->
            <div class="dts-scanner-widget-body">
                <!-- Left Pane: Viewport, Camera Controls, & Barcode Gun Input (Width: 390px) -->
                <div class="dts-scanner-left-pane">
                    @if($successMessage)
                        <div class="scanner-alert alert-success">
                            <span style="display: flex; align-items: center; gap: 6px; min-width: 0; word-break: break-word;">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                <span>{{ $successMessage }}</span>
                            </span>
                            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #166534; cursor: pointer; font-weight: bold; font-size: 15px; line-height: 1; padding: 2px;">&times;</button>
                        </div>
                    @endif

                    @if($errorMessage)
                        <div class="scanner-alert alert-danger">
                            <span style="display: flex; align-items: center; gap: 6px; min-width: 0; word-break: break-word;">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                <span>{{ $errorMessage }}</span>
                            </span>
                            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #991b1b; cursor: pointer; font-weight: bold; font-size: 15px; line-height: 1; padding: 2px;">&times;</button>
                        </div>
                    @endif

                    <!-- 5-Second Scan Cooldown Indicator -->
                    <div id="scanner-modal-cooldown-banner" style="display: none; align-items: center; justify-content: center; gap: 8px; background: #0284c7; color: #ffffff; padding: 7px 14px; border-radius: 8px; font-size: 11.5px; font-weight: 700; margin-bottom: 8px; box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span>Scan Cooldown Active &bull; Ready for next scan in <strong id="scanner-cooldown-timer-val">5</strong>s</span>
                    </div>

                    <!-- Webcam Viewport -->
                    <div wire:ignore class="dts-scanner-viewport-container" style="position: relative; height: 185px; border-radius: 10px; overflow: hidden; background: #0f172a; flex-shrink: 0;">
                        <div id="modal-qr-preview"></div>
                        <div id="camera-loading-placeholder" style="position: absolute; inset: 0; color: #94a3b8; font-size: 12px; font-weight: 500; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 12px; z-index: 5; background: rgba(15, 23, 42, 0.95);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.85;">
                                <path d="M23 7l-7 5 7 5V7z"></path>
                                <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                            </svg>
                            <span>Initializing Camera Feed...</span>
                        </div>

                        <!-- Floating Flip Camera Button -->
                        <button
                            type="button"
                            id="modal-flip-camera-btn"
                            onclick="window.flipModalCamera()"
                            title="Switch Camera"
                            style="display: none; position: absolute; top: 8px; right: 8px; z-index: 25; background: rgba(15, 23, 42, 0.75); color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 20px; padding: 4px 10px; font-size: 11px; font-weight: 600; cursor: pointer; backdrop-filter: blur(8px); align-items: center; gap: 5px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); transition: all 0.15s ease;"
                        >
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0-4.418-3.582-8-8-8s-8 3.582-8 8c0 1.94.693 3.72 1.85 5.11L4 19l4.5-1.5"></path>
                                <path d="M4 14c0 4.418 3.582 8 8 8s8-3.582 8-8c0-1.94-.693-3.72-1.85-5.11L20 5l-4.5 1.5"></path>
                            </svg>
                            <span id="modal-flip-camera-label">Flip</span>
                        </button>
                    </div>

                    <!-- Camera selector row -->
                    <div id="modal-camera-select-container" style="display: none; align-items: center; gap: 6px;">
                        <div style="flex: 1; min-width: 0;">
                            <select
                                id="modal-camera-select"
                                onchange="window.switchModalCamera(this.value)"
                                style="width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 11.5px; color: #334155; background: #f8fafc; outline: none; cursor: pointer;"
                            ></select>
                        </div>
                        <button
                            type="button"
                            onclick="window.flipModalCamera()"
                            style="padding: 6px 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 7px; font-size: 11.5px; font-weight: 700; color: #2563eb; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;"
                            title="Switch front/rear camera"
                        >
                            <span>Flip</span>
                        </button>
                    </div>

                    <!-- Manual Code Input Field -->
                    <div class="scanner-input-row">
                        <input 
                            type="text" 
                            id="global-scanner-code-input"
                            class="scanner-code-input"
                            wire:model.live.debounce.300ms="scannedCode" 
                            wire:keydown.enter="loadTransactionManual" 
                            placeholder="Enter Control Number or QR Code..." 
                            style="flex: 1; min-width: 0; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 12.5px; outline: none; transition: border-color 0.15s; box-sizing: border-box; background: #ffffff; color: #0f172a;" 
                            onfocus="this.style.borderColor='#0284c7';" 
                            onblur="this.style.borderColor=document.documentElement.getAttribute('data-theme')==='dark' ? '#334155' : '#cbd5e1';"
                        />
                        <button 
                            type="button" 
                            wire:click="loadTransactionManual" 
                            class="scanner-search-btn"
                            style="padding: 8px 14px; background: #0284c7; color: #ffffff; border: none; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; transition: background 0.15s; white-space: nowrap; flex-shrink: 0;"
                            onmouseover="this.style.background='#0369a1';" 
                            onmouseout="this.style.background='#0284c7';"
                        >
                            Search
                        </button>
                    </div>

                    <!-- Recent Session Scans (Compact) -->
                    @if(!empty($recentScans))
                        <div style="border-top: 1px solid #f1f5f9; padding-top: 8px; flex-shrink: 0;">
                            <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em;">Recent Session Scans</span>
                            <div style="display: flex; flex-direction: column; gap: 4px; margin-top: 6px; max-height: 100px; overflow-y: auto;">
                                @foreach(array_slice($recentScans, 0, 4) as $rs)
                                    <div class="scanner-recent-item" 
                                         wire:click="loadTransaction('{{ $rs['control_number'] }}')"
                                         style="display: flex; align-items: center; justify-content: space-between; font-size: 11px; background: #f8fafc; padding: 5px 8px; border-radius: 6px; border: 1px solid #e2e8f0; gap: 6px; cursor: pointer; transition: background 0.15s;"
                                         onmouseover="this.style.background='#e0f2fe';"
                                         onmouseout="this.style.background='#f8fafc';"
                                         title="Click to load this document"
                                    >
                                        <div style="display: flex; align-items: center; gap: 6px; min-width: 0; flex: 1;">
                                            <span style="font-weight: 700; color: #0284c7; font-family: monospace; flex-shrink: 0;">{{ $rs['control_number'] }}</span>
                                            <span style="color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $rs['subject'] }}</span>
                                        </div>
                                        <span style="color: #94a3b8; font-weight: 500; font-size: 10px; flex-shrink: 0;">{{ $rs['scanned_at'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Right Pane: Active Transaction Details / Action Station (Flex: 1) -->
                <div class="dts-scanner-right-pane">
                    @if($activeTransaction)
                        <div class="scanner-txn-card" style="background: #ffffff; border: 1.5px solid {{ $activeTransaction['is_completed'] ? '#cbd5e1' : ($activeTransaction['is_received_here'] ? '#bbf7d0' : '#bfdbfe') }}; border-radius: 12px; padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; box-sizing: border-box; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            
                            <!-- Header Status Line -->
                            <div style="display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <span style="font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Control Number</span>
                                    <h4 style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 2px 0 0 0; word-break: break-all; font-family: monospace;">{{ $activeTransaction['control_number'] }}</h4>
                                </div>

                                <div style="display: flex; align-items: center; gap: 6px;">
                                    @if($activeTransaction['is_completed'])
                                        <span class="scanner-badge badge-completed">
                                            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            Completed
                                        </span>
                                    @elseif($activeTransaction['is_received_here'])
                                        <span class="scanner-badge badge-received">
                                            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                            In Custody (Ready to Forward)
                                        </span>
                                    @else
                                        <span class="scanner-badge badge-incoming">
                                            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                                            Incoming (Ready to Receive)
                                        </span>
                                    @endif
                                    <span style="font-size: 11px; background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 6px; font-weight: 600;">
                                        {{ $activeTransaction['classification'] ?? $activeTransaction['type'] ?? 'Document' }}
                                    </span>
                                </div>
                            </div>

                            <!-- Detail Specs Grid -->
                            <div class="scanner-details-grid">
                                <div><strong style="color: #64748b;">Subject:</strong> {{ $activeTransaction['subject'] }}</div>
                                <div><strong style="color: #64748b;">Requestor:</strong> {{ $activeTransaction['requestor_name'] }}</div>
                                <div><strong style="color: #64748b;">Originator:</strong> {{ $activeTransaction['originated_office'] }}</div>
                                <div><strong style="color: #64748b;">Current Office:</strong> {{ $activeTransaction['current_office'] }}</div>
                                <div><strong style="color: #64748b;">Next Office:</strong> {{ $activeTransaction['next_office'] }}</div>
                                <div><strong style="color: #64748b;">Date Created:</strong> {{ $activeTransaction['created_at'] ?? 'N/A' }}</div>
                            </div>

                            <!-- Completed Transaction Alert -->
                            @if($activeTransaction['is_completed'])
                                <div style="background: #fff7ed; border: 1px solid #fed7aa; color: #c2410c; padding: 10px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                    <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                    <span>This transaction has already reached completion. No further routing actions are required.</span>
                                </div>
                            @else
                                <!-- Action Form Controls (If not completed) -->
                                <div style="border-top: 1px solid #e2e8f0; padding-top: 12px; display: flex; flex-direction: column; gap: 10px;">
                                    @if($activeTransaction['is_received_here'])
                                        @if(strtolower($activeTransaction['status']) === 'revision')
                                            <div style="padding: 10px 12px; background: #eff6ff; border: 1.5px solid #3b82f6; border-radius: 8px; font-size: 11.5px;">
                                                <div style="font-weight: 700; color: #1e40af; margin-bottom: 4px; display: flex; align-items: center; gap: 5px;">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                                    <span>Resubmission Route for Revised Document</span>
                                                </div>
                                                <div style="display: flex; flex-direction: column; gap: 6px; margin-top: 6px;">
                                                    <label style="display: flex; align-items: flex-start; gap: 6px; font-size: 11.5px; cursor: pointer; padding: 5px 8px; border-radius: 6px; background: #ffffff; border: 1px solid #cbd5e1;">
                                                        <input type="radio" wire:model="resubmitTarget" value="start" style="margin-top: 2px; accent-color: #2563eb;">
                                                        <div>
                                                            <strong style="color: #0f172a;">Option A: Restart Approval Chain</strong>
                                                            <div style="font-size: 10.5px; color: #64748b;">Resubmit sequentially to the next office in the approval chain.</div>
                                                        </div>
                                                    </label>
                                                    @if(!empty($activeTransaction['revision_requested_by_sequence']) && $activeTransaction['revision_requested_by_sequence'] > 1)
                                                        <label style="display: flex; align-items: flex-start; gap: 6px; font-size: 11.5px; cursor: pointer; padding: 5px 8px; border-radius: 6px; background: #ffffff; border: 1px solid #cbd5e1;">
                                                            <input type="radio" wire:model="resubmitTarget" value="requestor" style="margin-top: 2px; accent-color: #2563eb;">
                                                            <div>
                                                                <strong style="color: #2563eb;">Option B: Fast-Track to Revision Requestor</strong>
                                                                <div style="font-size: 10.5px; color: #64748b;">Bypass prior steps and send directly back to {{ $activeTransaction['revision_requested_by_office'] ?? 'Revision Requestor' }}.</div>
                                                            </div>
                                                        </label>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        <div class="scanner-action-form-row" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                            <div style="flex: 1; min-width: 150px;">
                                                <label style="font-size: 10.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px; text-transform: uppercase;">Action Needed</label>
                                                <select wire:model.live="actionNeeded" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12px; background: #ffffff; box-sizing: border-box;">
                                                    @foreach($actionOptions as $opt)
                                                        <option value="{{ $opt }}">{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div style="flex: 2; min-width: 170px;">
                                                <label style="font-size: 10.5px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px; text-transform: uppercase;">Remarks / Notes (Optional)</label>
                                                <input type="text" wire:model="notes" placeholder="Enter remarks..." style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12px; box-sizing: border-box;">
                                            </div>
                                        </div>

                                        @if($actionNeeded === 'To be Forwarded to')
                                            <div style="background: #eff6ff; padding: 10px; border-radius: 8px; border: 1.5px solid #3b82f6;">
                                                <label style="font-size: 10.5px; font-weight: 700; color: #1e40af; display: block; margin-bottom: 4px; text-transform: uppercase;">
                                                    Destination Office
                                                </label>
                                                <select wire:model.live="forwardToOfficeCode" style="width: 100%; padding: 7px 10px; border: 1px solid #93c5fd; border-radius: 7px; font-size: 12px; background: #ffffff; box-sizing: border-box;">
                                                    <option value="">-- Select Office to Forward To --</option>
                                                    @foreach($this->availableTargetOffices as $office)
                                                        <option value="{{ $office->office_code }}">{{ $office->office_name }} ({{ $office->office_code }})</option>
                                                    @endforeach
                                                </select>

                                                @if($forwardToOfficeCode === '[HUB]')
                                                    <div style="margin-top: 8px; padding: 8px; background: #ffffff; border: 1px dashed #3b82f6; border-radius: 6px;">
                                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                                            <span style="font-size: 11.5px; font-weight: 700; color: #1e40af;">Office Hub Broadcast:</span>
                                                            <button type="button" 
                                                                    wire:click="openForwardHubModal" 
                                                                    style="background: #2563eb; color: #ffffff; border: none; border-radius: 6px; padding: 3px 8px; font-size: 11px; font-weight: 700; cursor: pointer;">
                                                                Select Offices [ {{ in_array('ALL', $forwardHubOffices) ? 'ALL' : count($forwardHubOffices) }} ]
                                                            </button>
                                                        </div>

                                                        @if(empty($forwardHubOffices))
                                                            <div style="font-size: 11px; color: #dc2626; font-style: italic;">
                                                                Click above to select recipient offices for broadcast.
                                                            </div>
                                                        @else
                                                            <div style="display: flex; flex-wrap: wrap; gap: 4px; max-height: 80px; overflow-y: auto;">
                                                                @if(in_array('ALL', $forwardHubOffices))
                                                                    <span style="display: inline-flex; align-items: center; gap: 4px; background: #dbeafe; color: #1e40af; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 700;">
                                                                        ALL ACTIVE OFFICES
                                                                        <button type="button" wire:click="clearForwardHubOffices" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 12px; padding: 0;">&times;</button>
                                                                    </span>
                                                                @else
                                                                    @foreach($forwardHubOffices as $idx => $fCode)
                                                                        <span style="display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; color: #0f172a; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 600; border: 1px solid #cbd5e1;">
                                                                            <strong>{{ $fCode }}</strong>
                                                                            <button type="button" wire:click="removeForwardHubOffice({{ $idx }})" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 12px; padding: 0;">&times;</button>
                                                                        </span>
                                                                    @endforeach
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    @endif

                                    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px;">
                                        <button 
                                            type="button" 
                                            wire:click="processScanAction" 
                                            class="scanner-action-btn"
                                            style="background: {{ $activeTransaction['is_received_here'] ? '#16a34a' : '#2563eb' }}; padding: 9px 18px; font-size: 12.5px;"
                                        >
                                            @if($activeTransaction['is_received_here'])
                                                <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                                                <span>{{ $actionNeeded === 'To be Forwarded to' ? 'Forward to Office' : 'Forward Transaction' }}</span>
                                            @else
                                                <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 10.586V7z" clip-rule="evenodd"/></svg>
                                                <span>Receive Transaction</span>
                                            @endif
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <!-- Standby State / Empty Console Panel -->
                        <div class="scanner-standby-panel" style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 20px 24px; background: #ffffff; border: 1.5px dashed #cbd5e1; border-radius: 12px;">
                            <div class="scanner-standby-icon" style="width: 52px; height: 52px; border-radius: 14px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.2);">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
                                    <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
                                    <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
                                    <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
                                    <line x1="7" y1="12" x2="17" y2="12"></line>
                                </svg>
                            </div>
                            <h4 style="font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0;">DTS Scanner Console Armed</h4>
                            @if($availableCodesEnabled)
                                <p style="font-size: 12px; color: #64748b; margin: 0 0 16px 0; max-width: 380px; line-height: 1.45;">
                                    Aim your camera at a document QR code, or choose a document waiting at your station.
                                </p>

                                <!-- Quick Stats Cards -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 100%; max-width: 420px; margin-bottom: 14px;">
                                    <div class="scanner-stat-card scanner-stat-pending" wire:click="openAvailableCodesModal" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 12px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.borderColor='#4ade80';" onmouseout="this.style.borderColor=document.documentElement.getAttribute('data-theme')==='dark' ? '#166534' : '#bbf7d0';">
                                        <div style="font-size: 10px; font-weight: 800; text-transform: uppercase; color: #166534; letter-spacing: 0.04em;">Pending Receipt</div>
                                        <div style="font-size: 20px; font-weight: 800; color: #15803d; margin-top: 2px;">{{ $this->availableCounts['incoming'] ?? 0 }}</div>
                                        <div style="font-size: 10.5px; color: #166534; margin-top: 2px;">Incoming documents</div>
                                    </div>
                                    <div class="scanner-stat-card scanner-stat-custody" wire:click="openAvailableCodesModal" style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 12px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.borderColor='#60a5fa';" onmouseout="this.style.borderColor=document.documentElement.getAttribute('data-theme')==='dark' ? '#1e40af' : '#bfdbfe';">
                                        <div style="font-size: 10px; font-weight: 800; text-transform: uppercase; color: #1e40af; letter-spacing: 0.04em;">In Custody</div>
                                        <div style="font-size: 20px; font-weight: 800; color: #1d4ed8; margin-top: 2px;">{{ $this->availableCounts['received'] ?? 0 }}</div>
                                        <div style="font-size: 10.5px; color: #1e40af; margin-top: 2px;">Ready to forward</div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    wire:click="openAvailableCodesModal"
                                    style="background: #0284c7; color: #ffffff; border: none; border-radius: 8px; padding: 8px 16px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);"
                                    onmouseover="this.style.background='#0369a1';"
                                    onmouseout="this.style.background='#0284c7';"
                                >
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                    </svg>
                                    <span>Browse Available Documents ({{ $this->availableCounts['all'] ?? 0 }})</span>
                                </button>
                            @else
                                <p style="font-size: 12px; color: #64748b; margin: 0 0 16px 0; max-width: 400px; line-height: 1.45;">
                                    Aim your camera at a document QR code, or enter the Control Number or QR Code below to process a transaction.
                                </p>

                                <!-- Priority Hardware Scanning & Manual Input Instructions -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; width: 100%; max-width: 420px; margin-bottom: 14px;">
                                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; text-align: left;">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                            <div style="width: 22px; height: 22px; border-radius: 6px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 11px;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 7h.01M17 7h.01M7 17h.01M17 17h.01"/></svg>
                                            </div>
                                            <span style="font-size: 11.5px; font-weight: 700; color: #0f172a;">Camera QR Scanner</span>
                                        </div>
                                        <div style="font-size: 10.5px; color: #64748b; line-height: 1.35;">
                                            Position QR code in the viewfinder to automatically scan and load document.
                                        </div>
                                    </div>
                                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; text-align: left;">
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                            <div style="width: 22px; height: 22px; border-radius: 6px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 11px;">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 7V4h16v3M4 17v3h16v-3M9 12h6"/></svg>
                                            </div>
                                            <span style="font-size: 11.5px; font-weight: 700; color: #0f172a;">Manual Input</span>
                                        </div>
                                        <div style="font-size: 10.5px; color: #64748b; line-height: 1.35;">
                                            Type the Control Number or QR Code in the input box and click Search.
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <!-- Available Documents Directory View (Contained 100% Inside Widget Card) -->
            @if($showAvailableModal && $availableCodesEnabled)
                <div class="dts-available-inner-pane" style="position: absolute; top: 46px; left: 0; right: 0; bottom: 0; background: #ffffff; z-index: 50; display: flex; flex-direction: column; overflow: hidden;">
                    <!-- Sub-Header / Filters / Search -->
                    <div style="padding: 10px 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; flex-shrink: 0;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 28px; height: 28px; border-radius: 7px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                            </div>
                            <div>
                                <div style="font-size: 13px; font-weight: 700; color: #0f172a;">Available Station Documents</div>
                                <div style="font-size: 11px; color: #64748b;">Pending receipt or dispatch at your office</div>
                            </div>
                        </div>

                        <button type="button" wire:click="closeAvailableCodesModal" style="padding: 5px 11px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 11.5px; font-weight: 600; color: #475569; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#ffffff'">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            <span>Back to Console</span>
                        </button>
                    </div>

                    <!-- Filter Tabs & Search Row -->
                    <div style="padding: 8px 16px; border-bottom: 1px solid #f1f5f9; background: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; flex-shrink: 0;">
                        <div style="display: flex; gap: 4px; background: #f1f5f9; padding: 3px; border-radius: 8px;">
                            <button type="button" wire:click="$set('availableFilter', 'all')"
                                    style="padding: 4px 10px; border-radius: 6px; border: none; font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all 0.15s; {{ $availableFilter === 'all' ? 'background: #ffffff; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);' : 'background: transparent; color: #64748b;' }}">
                                All ({{ $this->availableCounts['all'] ?? 0 }})
                            </button>
                            <button type="button" wire:click="$set('availableFilter', 'incoming')"
                                    style="padding: 4px 10px; border-radius: 6px; border: none; font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all 0.15s; {{ $availableFilter === 'incoming' ? 'background: #ffffff; color: #0284c7; box-shadow: 0 1px 3px rgba(0,0,0,0.1);' : 'background: transparent; color: #64748b;' }}">
                                Incoming / To Receive ({{ $this->availableCounts['incoming'] ?? 0 }})
                            </button>
                            <button type="button" wire:click="$set('availableFilter', 'received')"
                                    style="padding: 4px 10px; border-radius: 6px; border: none; font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all 0.15s; {{ $availableFilter === 'received' ? 'background: #ffffff; color: #16a34a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);' : 'background: transparent; color: #64748b;' }}">
                                In Custody / To Forward ({{ $this->availableCounts['received'] ?? 0 }})
                            </button>
                        </div>

                        <div style="position: relative; flex: 1; min-width: 180px; max-width: 280px;">
                            <input type="text" wire:model.live.debounce.250ms="availableSearch" placeholder="Search control no, subject..." 
                                   style="width: 100%; height: 32px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 10px; font-size: 11.5px; outline: none; box-sizing: border-box;" />
                        </div>
                    </div>

                    <!-- Scrollable Document Cards List -->
                    <div style="padding: 12px 16px; overflow-y: auto; flex: 1; min-height: 0;">
                        @if($this->availableTransactions->isNotEmpty())
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                @foreach($this->availableTransactions as $item)
                                    <div style="border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #ffffff; transition: border-color 0.15s;" onmouseover="this.style.borderColor='#93c5fd'" onmouseout="this.style.borderColor='#e2e8f0'">
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 3px; flex-wrap: wrap;">
                                                <span style="font-family: monospace; font-size: 13px; font-weight: 800; color: #0f172a;">
                                                    {{ $item->control_number }}
                                                </span>
                                                @if(!$item->is_received)
                                                    <span style="font-size: 9.5px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                                        Pending Receipt
                                                    </span>
                                                @else
                                                    <span style="font-size: 9.5px; font-weight: 700; background: #dcfce7; color: #15803d; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                                        In Custody
                                                    </span>
                                                @endif
                                                <span style="font-size: 10.5px; background: #f1f5f9; color: #475569; padding: 1px 6px; border-radius: 4px;">
                                                    {{ $item->document_type ?? 'Document' }}
                                                </span>
                                            </div>
                                            <div style="font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                {{ $item->subject ?: 'Untitled Transaction' }}
                                            </div>
                                            <div style="font-size: 10.5px; color: #64748b; display: flex; gap: 10px; flex-wrap: wrap;">
                                                <span>Origin: <strong>{{ $item->originated_office_name ?? $item->originated_from }}</strong></span>
                                                @if($item->requestor_name)
                                                    <span>Requestor: <strong>{{ $item->requestor_name }}</strong></span>
                                                @endif
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="loadAvailableTransaction('{{ $item->qr_code }}')"
                                            style="background: #0284c7; color: #ffffff; border: none; border-radius: 7px; padding: 7px 13px; font-size: 11.5px; font-weight: 700; cursor: pointer; flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; transition: background 0.15s;"
                                            onmouseover="this.style.background='#0369a1'"
                                            onmouseout="this.style.background='#0284c7'"
                                        >
                                            <span>Load</span>
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div style="text-align: center; padding: 32px 16px; color: #94a3b8;">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 8px auto; opacity: 0.6;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <p style="font-size: 13px; font-weight: 600; color: #64748b; margin: 0 0 3px 0;">No Documents Found</p>
                                <p style="font-size: 11.5px; margin: 0;">No active documents matched the current station filter or search query.</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif

    <script>
    (function() {
        // Modal Camera Scanner State
        let modalHtml5QrCode = null;
        let modalAvailableCameras = [];
        let modalActiveCameraId = null;
        let modalFacingMode = 'environment';
        let isModalSwitching = false;
        let lastModalScannedText = '';
        let lastModalScanTime = 0;
        let modalWatchdogTimer = null;
        let modalAutoRestarts = 0;

        // ------------------------------------------------------------------
        // Shared scanner diagnostics: visible toasts for failures that would
        // otherwise be swallowed silently (html5-qrcode eats callback errors).
        // ------------------------------------------------------------------
        if (!window.__scannerToast) {
            window.__scannerToast = function (message, type) {
                type = type || 'error';
                console.warn('[Scanner]', message);
                try {
                    var wrap = document.getElementById('scanner-toast-wrap');
                    if (!wrap) {
                        wrap = document.createElement('div');
                        wrap.id = 'scanner-toast-wrap';
                        wrap.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999999;display:flex;flex-direction:column;gap:8px;max-width:380px;font-family:Outfit,"Segoe UI",sans-serif;';
                        document.body.appendChild(wrap);
                    }
                    // Prevent duplicate toasts with the exact same message
                    var items = wrap.querySelectorAll('.scanner-toast-item');
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].textContent === message) return;
                    }

                    var el = document.createElement('div');
                    el.className = 'scanner-toast-item';
                    var bg = type === 'success' ? '#f0fdf4' : (type === 'info' ? '#eff6ff' : '#fef2f2');
                    var bd = type === 'success' ? '#86efac' : (type === 'info' ? '#bfdbfe' : '#fca5a5');
                    var fg = type === 'success' ? '#166534' : (type === 'info' ? '#1e40af' : '#991b1b');
                    el.style.cssText = 'padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 10px 25px rgba(0,0,0,.18);word-break:break-word;transition:opacity .3s;';
                    el.style.background = bg;
                    el.style.border = '1px solid ' + bd;
                    el.style.color = fg;
                    el.textContent = message;
                    wrap.appendChild(el);
                    setTimeout(function () {
                        el.style.opacity = '0';
                        setTimeout(function () { el.remove(); }, 350);
                    }, 6000);
                } catch (e) { /* console warning already emitted */ }
            };
        }

        // Surface failed Livewire requests when scanner modal is active
        if (!window.__scannerConnectionHooked) {
            var attachScannerRequestHook = function () {
                if (window.__scannerConnectionHooked) return;
                if (!window.Livewire || typeof Livewire.hook !== 'function') return;
                window.__scannerConnectionHooked = true;
                try {
                    Livewire.hook('request', function (hooks) {
                        if (!hooks || typeof hooks.fail !== 'function') return;
                        hooks.fail(function (res) {
                            var status = res && res.status;
                            if (!status || status === 200) return;

                            // If session expired / unauthenticated, redirect to login
                            if (status === 401 || status === 419) {
                                window.location.href = '/login';
                                return;
                            }

                            // Only show scanner error if the global scanner modal is currently open in the DOM
                            var modal = document.querySelector('.dts-scanner-widget-card, .global-scanner-modal');
                            if (!modal) return;

                            window.__scannerToast('Scanner connection problem (HTTP ' + status + '). Please try again.', 'error');
                        });
                    });
                } catch (e) { console.warn('Scanner request hook unavailable:', e); }
            };
            attachScannerRequestHook();
            document.addEventListener('livewire:initialized', attachScannerRequestHook);
        }

        window.addEventListener('focus-scanner-input', () => {
            const input = document.getElementById('global-scanner-code-input');
            if (input) input.focus();
        });

        /**
         * Stops and releases all active hardware media streams and tracks attached to modal video elements.
         *
         * @returns {void}
         */
        function releaseAllModalMediaTracks() {
            try {
                const modal = document.querySelector('.dts-scanner-widget-card, .global-scanner-modal');
                const videos = modal ? modal.querySelectorAll('video') : document.querySelectorAll('#modal-qr-preview video');
                videos.forEach(v => {
                    if (v.srcObject && typeof v.srcObject.getTracks === 'function') {
                        v.srcObject.getTracks().forEach(track => {
                            try { track.stop(); } catch(e) {}
                        });
                        v.srcObject = null;
                    }
                });
            } catch (e) {
                console.warn('Error releasing modal tracks:', e);
            }
        }

        /**
         * Gracefully halts the Html5Qrcode camera instance, stops watchdog, releases hardware tracks, and clears DOM.
         *
         * @returns {Promise<void>}
         */
        async function stopModalScanner() {
            stopModalWatchdog();
            if (modalHtml5QrCode) {
                const instance = modalHtml5QrCode;
                modalHtml5QrCode = null;
                try {
                    if (instance.isScanning) {
                        // Time-boxed stop: html5-qrcode's stop() can hang forever when
                        // the stream reports zero video tracks, which used to leave
                        // isModalSwitching stuck true and the flip/retry buttons dead.
                        await Promise.race([
                            instance.stop(),
                            new Promise((_, reject) => setTimeout(() => reject(new Error('Scanner stop timed out')), 2500))
                        ]);
                    }
                } catch(e) {
                    console.warn('Scanner stop warning:', e);
                }
                try {
                    const clearing = instance.clear();
                    if (clearing && typeof clearing.catch === 'function') clearing.catch(() => {});
                } catch(e) {
                    console.warn('Scanner clear warning:', e);
                }
            }
            releaseAllModalMediaTracks();
            const previewEl = document.getElementById('modal-qr-preview');
            if (previewEl) {
                previewEl.innerHTML = '';
            }
        }

        // ------------------------------------------------------------------
        // Camera watchdog: html5-qrcode keeps looping over a dead stream, so the
        // preview stays alive but QR detection silently stops. Watch the track
        // and auto-restart when it dies.
        // ------------------------------------------------------------------
        /**
         * Halts the background camera watchdog interval timer.
         *
         * @returns {void}
         */
        function stopModalWatchdog() {
            if (modalWatchdogTimer) {
                clearInterval(modalWatchdogTimer);
                modalWatchdogTimer = null;
            }
        }

        /**
         * Starts periodic watchdog checking video track health, auto-restarting feed if stream unexpectedly ends.
         *
         * @returns {void}
         */
        function startModalWatchdog() {
            stopModalWatchdog();
            modalWatchdogTimer = setInterval(async () => {
                if (!modalHtml5QrCode || !modalHtml5QrCode.isScanning) { stopModalWatchdog(); return; }
                try {
                    const video = document.querySelector('#modal-qr-preview video');
                    const track = (video && video.srcObject && typeof video.srcObject.getVideoTracks === 'function')
                        ? (video.srcObject.getVideoTracks()[0] || null)
                        : null;
                    if (track && track.readyState === 'ended') {
                        if (modalAutoRestarts >= 2) {
                            await stopModalScanner();
                            const placeholder = document.getElementById('camera-loading-placeholder');
                            if (placeholder) {
                                placeholder.style.display = 'flex';
                                placeholder.innerHTML = '<div style="color:#ef4444;font-size:13px;text-align:center;padding:10px;">Camera connection was lost.<br><small style="opacity:.85;">Close and reopen the scanner to retry.</small></div>';
                            }
                            window.__scannerToast('Camera connection was lost. Reopen the scanner to restart it.', 'error');
                            return;
                        }
                        modalAutoRestarts++;
                        console.warn('Scanner watchdog: modal camera stream ended — restarting (attempt ' + modalAutoRestarts + ')...');
                        if (isModalSwitching) return;
                        isModalSwitching = true;
                        try {
                            await startModalCamera(modalActiveCameraId || modalFacingMode);
                            window.__scannerToast('Camera connection dropped and was restarted automatically.', 'info');
                        } finally {
                            isModalSwitching = false;
                        }
                    }
                } catch (err) { console.warn('Scanner watchdog error:', err); }
            }, 2500);
        }

        /**
         * Calculates responsive QR scanning viewfinder box bounding box.
         *
         * @param {number} viewfinderWidth Width of container element in pixels.
         * @param {number} viewfinderHeight Height of container element in pixels.
         * @returns {Object} Scanning box dimensions containing width and height numbers.
         */
        const calculateModalQrboxSize = function(viewfinderWidth, viewfinderHeight) {
            // Generous rectangular scanning zone (72% of minimum dimension)
            // Ensures QR box is always within viewfinder bounds and never exceeds height or width.
            const minDim = Math.min(viewfinderWidth || 360, viewfinderHeight || 185);
            const boxSize = Math.max(60, Math.floor(minDim * 0.72));
            return {
                width: Math.min(boxSize, Math.max(60, (viewfinderWidth || 360) - 10)),
                height: Math.min(boxSize, Math.max(60, (viewfinderHeight || 185) - 10))
            };
        };

        // Resilient constraints with 10 FPS (optimal for ZXing CPU throughput & zero stutter)
        const modalHdConfig = {
            fps: 10,
            qrbox: calculateModalQrboxSize
        };

        const modalBareConfig = {
            fps: 10,
            qrbox: calculateModalQrboxSize
        };

        /**
         * Tests whether a scanned raw string is Base64 encoded and decodes it if valid printable ASCII.
         *
         * @param {string} raw Scanned barcode or QR text.
         * @returns {string} Normalized or decoded code string.
         */
        const decodeIfBase64 = (raw) => {
            if (!raw) return raw;
            const str = raw.trim();
            // If already matches standard system QR code pattern, don't decode
            if (/^[A-Z0-9]{4}(-[A-Z0-9]{4}){2,}$/i.test(str)) return str;
            if (!/^[A-Za-z0-9+/_\-=]+$/.test(str) || str.length < 8) return str;
            try {
                const normalized = str.replace(/-/g, '+').replace(/_/g, '/');
                const padLen = (4 - (normalized.length % 4)) % 4;
                const padded = normalized + '='.repeat(padLen);
                const decoded = atob(padded);
                if (/^[\x20-\x7E]+$/.test(decoded)) {
                    const trimmed = decoded.trim();
                    if (trimmed.length >= 3) return trimmed;
                }
            } catch (e) { /* not valid Base64 */ }
            return str;
        };

        // ------------------------------------------------------------------
        // Scan Cooldown Manager (5-Second Breathing Gap)
        // ------------------------------------------------------------------
        let isModalCoolingDown = false;
        let modalCooldownInterval = null;

        /**
         * Activates a visual countdown cooldown timer after successful scan to give server breathing gap.
         *
         * @param {number} seconds Cooldown duration in seconds (default 5).
         * @returns {void}
         */
        const startModalCooldown = (seconds = 5) => {
            isModalCoolingDown = true;
            let remaining = seconds;
            const banner = document.getElementById('scanner-modal-cooldown-banner');
            const valEl = document.getElementById('scanner-cooldown-timer-val');
            if (banner) banner.style.display = 'flex';
            if (valEl) valEl.textContent = remaining;

            if (modalCooldownInterval) clearInterval(modalCooldownInterval);
            modalCooldownInterval = setInterval(() => {
                remaining -= 1;
                if (valEl) valEl.textContent = remaining;
                if (remaining <= 0) {
                    clearInterval(modalCooldownInterval);
                    modalCooldownInterval = null;
                    isModalCoolingDown = false;
                    if (banner) banner.style.display = 'none';
                }
            }, 1000);
        };

        window.addEventListener('scanner-cooldown-start', (e) => {
            const sec = (e.detail && e.detail.seconds) ? e.detail.seconds : 5;
            startModalCooldown(sec);
        });

        // Resolve the scanner component at call time (DOM wire:id first, compiled
        // "@this" as fallback) so scans never silently no-op when the compile-time
        // component reference is stale.
        /**
         * Resolves the Livewire component instance dynamically from DOM or Blade context.
         *
         * @returns {object|null} The resolved Livewire component proxy, or null if unresolvable.
         */
        const resolveModalComponent = () => {
            try {
                const modalEl = document.querySelector('.dts-scanner-widget-card')
                    || document.querySelector('.global-scanner-modal')
                    || document.querySelector('.global-scanner-backdrop')
                    || document.getElementById('global-scanner-code-input');
                const root = modalEl && modalEl.closest ? modalEl.closest('[wire\\:id]') : null;
                if (root && window.Livewire && typeof Livewire.find === 'function') {
                    const comp = Livewire.find(root.getAttribute('wire:id'));
                    if (comp) return comp;
                }
            } catch (e) { console.warn('Modal component lookup failed:', e); }
            try { if (typeof @this !== 'undefined' && @this) return @this; } catch (e) { return null; }
            return null;
        };

        /**
         * Dispatches loadTransaction call to the backend Livewire component.
         *
         * @param {string} code Scanned or entered barcode/QR string.
         * @param {boolean} isScan True if optical scan (triggers auto-receive), false if manual.
         * @returns {Promise<any>|any} Component call promise.
         * @throws {Error} When Livewire component is not reachable in DOM.
         */
        const callModalLoadTransaction = (code, isScan = true) => {
            const comp = resolveModalComponent();
            if (!comp) {
                throw new Error('Scanner lost its connection to the page. Refresh the page (F5) and try again.');
            }

            let result = null;
            if (typeof comp.call === 'function') {
                result = comp.call('loadTransaction', code, isScan);
            } else if (typeof comp.loadTransaction === 'function') {
                result = comp.loadTransaction(code, isScan);
            } else if (comp.$wire && typeof comp.$wire.call === 'function') {
                result = comp.$wire.call('loadTransaction', code, isScan);
            } else if (comp.$wire && typeof comp.$wire.loadTransaction === 'function') {
                result = comp.$wire.loadTransaction(code, isScan);
            } else if (typeof comp.$call === 'function') {
                result = comp.$call('loadTransaction', code, isScan);
            } else {
                throw new Error('Scanner connection method not found. Refresh the page (F5) and try again.');
            }

            if (result && typeof result.catch === 'function') {
                result.catch((err) => {
                    console.error('Scanner modal: loadTransaction request failed:', err);
                    window.__scannerToast('The scan could not reach the server. Check your connection or session, then refresh (F5).', 'error');
                });
            }
            return result;
        };

        /**
         * Callback fired by Html5Qrcode engine when a valid QR code is detected in camera feed.
         * Debounces rapid duplicates, respects the 5s cooldown gap, and invokes loadTransaction.
         *
         * @param {string} decodedText Raw decoded barcode or QR text from camera.
         * @returns {void}
         */
        const onModalScanSuccess = (decodedText) => {
            if (!decodedText) return;
            if (isModalCoolingDown) {
                return; // Respect 5-second cooldown gap
            }
            try {
                const now = Date.now();
                if (decodedText === lastModalScannedText && (now - lastModalScanTime) < 1500) {
                    return;
                }
                lastModalScannedText = decodedText;
                lastModalScanTime = now;
                modalAutoRestarts = 0;

                // A scan is user activity — keep the inactivity guard from timing
                // the session out while scanning with the camera.
                window.dispatchEvent(new CustomEvent('scanner-activity'));

                const code = decodeIfBase64(decodedText);
                const input = document.getElementById('global-scanner-code-input');
                if (input) {
                    input.value = code;
                }

                callModalLoadTransaction(code, true);
            } catch (err) {
                // html5-qrcode swallows callback exceptions silently — surface them.
                console.error('Scanner modal: failed to process scan:', err);
                window.__scannerToast('QR code detected, but processing failed: ' + ((err && err.message) || err), 'error');
            }
        };

        /**
         * Enumerates available video input devices and updates the camera selection dropdown and flip button UI.
         *
         * @returns {Promise<void>}
         */
        async function updateModalCameraUI() {
            try {
                const cameras = await Html5Qrcode.getCameras();
                modalAvailableCameras = cameras || [];
                const selectEl = document.getElementById('modal-camera-select');
                const selectCtr = document.getElementById('modal-camera-select-container');
                const flipBtn = document.getElementById('modal-flip-camera-btn');
                const flipLabel = document.getElementById('modal-flip-camera-label');

                const isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0) || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

                if (flipBtn) {
                    if (isTouch || modalAvailableCameras.length > 1) {
                        flipBtn.style.display = 'inline-flex';
                        if (flipLabel) {
                            flipLabel.textContent = modalFacingMode === 'user' ? 'Rear Cam' : 'Front Cam';
                        }
                    } else {
                        flipBtn.style.display = 'none';
                    }
                }

                if (selectEl && selectCtr) {
                    if (modalAvailableCameras.length > 1) {
                        selectEl.innerHTML = modalAvailableCameras.map((c, i) => {
                            let label = c.label || '';
                            if (!label) {
                                label = (i === 0 ? 'Rear / Back Camera' : 'Front / Selfie Camera');
                            } else {
                                if (/back|rear|environment/i.test(label) && !/back/i.test(label)) {
                                    label += ' (Rear)';
                                } else if (/front|user|selfie/i.test(label) && !/front/i.test(label)) {
                                    label += ' (Front)';
                                }
                            }
                            return `<option value="${c.id}">${label}</option>`;
                        }).join('');

                        if (modalActiveCameraId) {
                            selectEl.value = modalActiveCameraId;
                        }
                        selectCtr.style.display = 'flex';
                    } else if (isTouch) {
                        selectEl.innerHTML = `
                            <option value="environment" ${modalFacingMode === 'environment' ? 'selected' : ''}>Rear Camera (Back)</option>
                            <option value="user" ${modalFacingMode === 'user' ? 'selected' : ''}>Front Camera (Selfie)</option>
                        `;
                        selectCtr.style.display = 'flex';
                    } else {
                        selectCtr.style.display = 'none';
                    }
                }
            } catch (camErr) {
                console.warn('Could not enumerate cameras in modal:', camErr);
            }
        }

        /**
         * Initializes and starts camera optical scanning stream using Html5Qrcode.
         * Tries candidate targets in priority: explicit target deviceId, last active camera,
         * enumerated cameras, and facing modes (environment/user).
         *
         * @param {string} cameraTarget Device ID string or facingMode ('environment'|'user').
         * @returns {Promise<void>}
         * @throws {Error} If no compatible camera could be opened or permission was denied.
         */
        async function startModalCamera(cameraTarget) {
            const placeholder = document.getElementById('camera-loading-placeholder');
            if (placeholder) {
                placeholder.innerHTML = `
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.85;">
                        <path d="M23 7l-7 5 7 5V7z"></path>
                        <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                    </svg>
                    <span>Starting Camera Feed...</span>
                `;
                placeholder.style.display = 'flex';
            }

            if (modalHtml5QrCode) {
                try {
                    if (modalHtml5QrCode.isScanning) {
                        await Promise.race([
                            modalHtml5QrCode.stop(),
                            new Promise((_, reject) => setTimeout(() => reject(new Error('Scanner stop timed out')), 2500))
                        ]);
                    }
                } catch(e) { console.warn('Modal camera stop warning:', e); }
                try {
                    const clearing = modalHtml5QrCode.clear();
                    if (clearing && typeof clearing.catch === 'function') clearing.catch(() => {});
                } catch(e) {}
                modalHtml5QrCode = null;
            }
            releaseAllModalMediaTracks();
            const initPreview = document.getElementById('modal-qr-preview');
            if (initPreview) {
                initPreview.innerHTML = '';
            }
            await new Promise(r => setTimeout(r, 80));

            // Enumerate cameras while NO stream is active: calling getCameras()
            // (which opens a second getUserMedia) alongside a live scanning
            // stream can interrupt the active feed on some camera drivers.
            await updateModalCameraUI();

            const qrFormats = (typeof Html5QrcodeSupportedFormats !== 'undefined')
                ? [Html5QrcodeSupportedFormats.QR_CODE]
                : undefined;
            const createFreshModalDecoder = () => {
                const el = document.getElementById("modal-qr-preview");
                if (el) {
                    const strayVideos = el.querySelectorAll('video');
                    strayVideos.forEach(v => {
                        if (v.srcObject && typeof v.srcObject.getTracks === 'function') {
                            v.srcObject.getTracks().forEach(t => { try { t.stop(); } catch(e) {} });
                            v.srcObject = null;
                        }
                    });
                    el.innerHTML = '';
                }
                return new Html5Qrcode("modal-qr-preview", {
                    formatsToSupport: qrFormats,
                    useBarCodeDetectorIfSupported: true,
                    experimentalFeatures: { useBarCodeDetectorIfSupported: true },
                    verbose: false
                });
            };
            modalHtml5QrCode = createFreshModalDecoder();

            const isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0) || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);

            let candidates = [];

            // 1. Explicit camera device target passed
            if (cameraTarget && typeof cameraTarget === 'string' && cameraTarget !== 'environment' && cameraTarget !== 'user') {
                modalActiveCameraId = cameraTarget;
                candidates.push({ deviceId: cameraTarget });
            }

            // 2. Previously selected active camera
            if (modalActiveCameraId && !candidates.some(c => c.deviceId === modalActiveCameraId)) {
                candidates.push({ deviceId: modalActiveCameraId });
            }

            // 3. Available enumerated system cameras (webcams, USB cameras, integrated laptop cameras)
            if (modalAvailableCameras && modalAvailableCameras.length > 0) {
                if (isTouch) {
                    const backCam = modalAvailableCameras.find(c => /back|rear|environment/i.test(c.label || ''));
                    if (backCam) candidates.push({ deviceId: backCam.id });
                } else {
                    candidates.push({ deviceId: modalAvailableCameras[0].id });
                }
                for (const c of modalAvailableCameras) {
                    if (c.id && !candidates.some(cand => cand.deviceId === c.id)) {
                        candidates.push({ deviceId: c.id });
                    }
                }
            }

            // 4. Facing modes (environment for rear camera, user for front/selfie)
            if (isTouch) {
                if (cameraTarget === 'user') {
                    candidates.push({ facingMode: 'user' });
                    candidates.push({ facingMode: 'environment' });
                } else {
                    candidates.push({ facingMode: 'environment' });
                    candidates.push({ facingMode: 'user' });
                }
            } else {
                candidates.push({ facingMode: 'user' });
                candidates.push({ facingMode: 'environment' });
            }

            let started = false;
            let lastErr = null;

            for (const cand of candidates) {
                try {
                    await modalHtml5QrCode.start(cand, modalBareConfig, onModalScanSuccess, () => {});
                    started = true;
                    if (cand.deviceId) modalActiveCameraId = cand.deviceId;
                    break;
                } catch (err) {
                    lastErr = err;
                    console.warn('Modal camera start attempt failed with candidate:', cand, err);
                    try { if (modalHtml5QrCode.isScanning) await modalHtml5QrCode.stop(); } catch(e) {}
                    try {
                        const clearing = modalHtml5QrCode.clear();
                        if (clearing && typeof clearing.catch === 'function') clearing.catch(() => {});
                    } catch(e) {}
                    releaseAllModalMediaTracks();
                    modalHtml5QrCode = createFreshModalDecoder();
                }
            }

            if (!started) {
                throw lastErr || new Error('No compatible camera could be started');
            }

            if (placeholder) placeholder.style.display = 'none';

            // Refresh camera labels/ids now that permission may have just been granted
            if (!modalAvailableCameras.length || modalAvailableCameras.some(c => !c.id)) {
                try { await updateModalCameraUI(); } catch (e) { console.warn('Post-start camera refresh failed:', e); }
            }

            startModalWatchdog();
        }

        /**
         * Switches the active camera device to a specific target device or facing mode.
         * Renders informative troubleshooting guidance if camera permission or access fails.
         *
         * @param {string} [cameraTarget] Device ID or facing mode string.
         * @returns {Promise<void>}
         */
        window.switchModalCamera = async function(cameraTarget) {
            if (isModalSwitching) return;
            isModalSwitching = true;
            modalAutoRestarts = 0;
            try {
                await startModalCamera(cameraTarget);
            } catch (err) {
                console.error('switchModalCamera failed:', err);
                const placeholder = document.getElementById('camera-loading-placeholder');
                if (placeholder) {
                    const errName = (err && err.name) || '';
                    const errMsg = (err && (err.message || String(err))) || 'Camera switch failed';
                    let guidance = 'Please allow camera permission in your browser or connect a webcam.';
                    if (errName === 'NotAllowedError' || /permission/i.test(errMsg)) {
                        guidance = 'Camera access is blocked by your browser. Click the lock/tune icon in your address bar, select <b>Always allow</b>, and click Retry.';
                    } else if (errName === 'NotFoundError' || /device not found/i.test(errMsg)) {
                        guidance = 'No camera found. Check if your laptop privacy shutter is open or if Windows has "Camera off" toggled.';
                    } else if (errName === 'NotReadableError') {
                        guidance = 'Camera is in use by another application (e.g. Teams, Zoom, or another browser tab).';
                    }
                    placeholder.innerHTML = `
                        <svg style="width: 24px; height: 24px; margin-bottom: 5px; stroke: #f59e0b;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div style="font-weight: 700; font-size: 12px; color: #f8fafc;">Camera Access Unavailable</div>
                        <div style="font-size: 10px; color: #cbd5e1; max-width: 280px; text-align: center; margin-top: 3px; line-height: 1.35;">${guidance}</div>
                        <div style="font-size: 9.5px; font-family: monospace; color: #f87171; background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px; margin-top: 5px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${errMsg}">${errName ? errName + ': ' : ''}${errMsg}</div>
                        <button type="button" onclick="window.switchModalCamera()" style="margin-top: 7px; padding: 4px 12px; background: #0284c7; color: #ffffff; border: none; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#0284c7'">
                            Retry Camera
                        </button>
                    `;
                    placeholder.style.display = 'flex';
                }
            } finally {
                isModalSwitching = false;
            }
        };

        /**
         * Cycles to the next available system camera or toggles between user (front) and environment (rear) lenses.
         *
         * @returns {Promise<void>}
         */
        window.flipModalCamera = async function() {
            if (isModalSwitching) return;

            if (modalAvailableCameras && modalAvailableCameras.length > 1) {
                let currentIndex = modalAvailableCameras.findIndex(c => c.id === modalActiveCameraId);
                if (currentIndex === -1) {
                    if (modalFacingMode === 'environment') {
                        const rearIdx = modalAvailableCameras.findIndex(c => /back|rear|environment|main/i.test(c.label || ''));
                        currentIndex = rearIdx >= 0 ? rearIdx : 0;
                    } else {
                        const frontIdx = modalAvailableCameras.findIndex(c => /front|user|selfie/i.test(c.label || ''));
                        currentIndex = frontIdx >= 0 ? frontIdx : 1;
                    }
                }
                const nextIndex = (currentIndex + 1) % modalAvailableCameras.length;
                const nextCam = modalAvailableCameras[nextIndex];
                await window.switchModalCamera(nextCam.id);
            } else {
                const targetMode = (modalFacingMode === 'environment' ? 'user' : 'environment');
                await window.switchModalCamera(targetMode);
            }
        };

        /**
         * Global helper to open scanner modal from anywhere in the application.
         *
         * @param {string} [code=''] Optional barcode/QR code to pre-populate.
         * @returns {void}
         */
        window.openScannerModal = function(code = '') {
            if (window.Livewire) {
                Livewire.dispatch('open-scanner-modal', { code: code });
            }
        };

        let isModalStarting = false;
        let lastInitTime = 0;

        /**
         * Event handler for 'init-camera-scanner'. Waits for DOM container rendering,
         * focuses the input field, and starts the optical camera feed.
         *
         * @returns {Promise<void>}
         */
        const handleInitCameraScanner = async () => {
            const now = Date.now();
            if (now - lastInitTime < 500) return; // Prevent concurrent double-fires
            lastInitTime = now;

            if (isModalStarting) return;
            isModalStarting = true;

            try {
                const codeInput = document.getElementById('global-scanner-code-input');
                if (codeInput) {
                    codeInput.focus();
                    codeInput.select();
                }

                let attempts = 0;
                while (!document.getElementById('modal-qr-preview') && attempts < 30) {
                    await new Promise(r => setTimeout(r, 50));
                    attempts++;
                }

                const previewEl = document.getElementById('modal-qr-preview');
                if (!previewEl) return;

                // Wait until element is rendered with dimensions
                let layoutAttempts = 0;
                while (previewEl.clientHeight === 0 && layoutAttempts < 20) {
                    await new Promise(r => setTimeout(r, 50));
                    layoutAttempts++;
                }

                if (modalHtml5QrCode && modalHtml5QrCode.isScanning) {
                    const placeholder = document.getElementById('camera-loading-placeholder');
                    if (placeholder) placeholder.style.display = 'none';
                    return;
                }

                modalAutoRestarts = 0;
                await startModalCamera(modalActiveCameraId || 'environment');
            } catch (err) {
                console.error('Camera init error:', err);
                const placeholder = document.getElementById('camera-loading-placeholder');
                if (placeholder) {
                    placeholder.style.display = 'flex';
                    const errName = (err && err.name) || '';
                    const errMsg = (err && (err.message || String(err))) || 'Unknown camera error';
                    let guidance = 'Please allow camera permission in your browser or connect a webcam.';
                    if (errName === 'NotAllowedError' || /permission/i.test(errMsg)) {
                        guidance = 'Camera access is blocked by your browser. Click the lock/camera icon in your address bar, select <b>Always allow</b>, and click Retry.';
                    } else if (errName === 'NotFoundError' || /device not found/i.test(errMsg)) {
                        guidance = 'No camera found. Check if your laptop privacy shutter is open or if Windows has "Camera off" toggled.';
                    } else if (errName === 'NotReadableError') {
                        guidance = 'Camera is in use by another application (e.g. Teams, Zoom, or another browser tab).';
                    }
                    placeholder.innerHTML = `
                        <svg style="width: 24px; height: 24px; margin-bottom: 5px; stroke: #f59e0b;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div style="font-weight: 700; font-size: 12px; color: #f8fafc;">Camera Access Unavailable</div>
                        <div style="font-size: 10px; color: #cbd5e1; max-width: 280px; text-align: center; margin-top: 3px; line-height: 1.35;">${guidance}</div>
                        <div style="font-size: 9.5px; font-family: monospace; color: #f87171; background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px; margin-top: 5px; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${errMsg}">${errName ? errName + ': ' : ''}${errMsg}</div>
                        <button type="button" onclick="window.switchModalCamera()" style="margin-top: 7px; padding: 4px 12px; background: #0284c7; color: #ffffff; border: none; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: background 0.15s;" onmouseover="this.style.background='#0369a1'" onmouseout="this.style.background='#0284c7'">
                            Retry Camera
                        </button>
                    `;
                }
            } finally {
                isModalStarting = false;
            }
        };

        /**
         * Event handler for 'stop-camera-scanner'. Halts camera tracks when modal is dismissed.
         *
         * @returns {Promise<void>}
         */
        const handleStopCameraScanner = async () => {
            await stopModalScanner();
        };

        /**
         * Visual feedback handler when an invalid QR code or barcode is scanned. Flashes input red.
         *
         * @returns {void}
         */
        const handleScannerCodeInvalid = () => {
            const input = document.getElementById('global-scanner-code-input');
            if (input) {
                input.style.borderColor = '#ef4444';
                input.focus();
                input.select();
                setTimeout(() => { input.style.borderColor = '#cbd5e1'; }, 2000);
            }
        };

        // Web Audio API Synthesizer Feedback for Scanner
        let audioCtx = null;
        /**
         * Generates synthesized audio beep feedback via Web Audio API for scanner feedback.
         *
         * @param {number} freq Audio frequency in Hz (e.g., 880 for success, 250 for error).
         * @param {string} type Oscillator waveform type ('sine', 'triangle', 'sawtooth').
         * @param {number} duration Tone duration in seconds.
         * @returns {void}
         */
        function playBeep(freq, type, duration) {
            try {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = type || 'sine';
                osc.frequency.value = freq || 800;
                gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + duration);
            } catch (e) {
                // Audio context not allowed or unsupported
            }
        }

        window.addEventListener('scanner-audio-success', () => {
            playBeep(880, 'sine', 0.12);
            setTimeout(() => playBeep(1320, 'sine', 0.18), 120);
        });

        window.addEventListener('scanner-audio-action', () => {
            playBeep(587.33, 'triangle', 0.1);
            setTimeout(() => playBeep(880, 'triangle', 0.1), 100);
            setTimeout(() => playBeep(1174.66, 'triangle', 0.2), 200);
        });

        window.addEventListener('scanner-audio-error', () => {
            playBeep(250, 'sawtooth', 0.25);
        });

        // Hardware Barcode Gun Keypress Listener
        let gunBarcodeBuffer = '';
        let lastKeyTime = 0;
        window.addEventListener('keydown', (e) => {
            const modal = document.querySelector('.dts-scanner-widget-card, .global-scanner-modal');
            if (!modal) return;

            if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.id !== 'global-scanner-code-input')) {
                return;
            }

            const now = Date.now();
            if (now - lastKeyTime > 120) {
                gunBarcodeBuffer = '';
            }
            lastKeyTime = now;

            if (e.key === 'Enter') {
                if (gunBarcodeBuffer.length >= 4) {
                    e.preventDefault();
                    if (isModalCoolingDown) {
                        gunBarcodeBuffer = '';
                        window.__scannerToast('Scan cooldown active (5s). Please wait before scanning again.', 'info');
                        return;
                    }
                    const codeToScan = gunBarcodeBuffer.trim();
                    gunBarcodeBuffer = '';
                    if (window.Livewire) {
                        Livewire.dispatch('open-scanner-modal', { code: codeToScan, isScan: true });
                    }
                }
            } else if (e.key.length === 1) {
                gunBarcodeBuffer += e.key;
            }
        });

        // Livewire 3 dispatches both Livewire events and DOM CustomEvents to window.
        // We bind only to window once to prevent duplicate concurrent camera starts.
        if (!window.__scannerModalEventsBound) {
            window.__scannerModalEventsBound = true;
            window.addEventListener('init-camera-scanner', handleInitCameraScanner);
            window.addEventListener('stop-camera-scanner', handleStopCameraScanner);
            window.addEventListener('scanner-code-invalid', handleScannerCodeInvalid);

            window.addEventListener('scanner-modal-opened', () => {
                window.dispatchEvent(new CustomEvent('rms-widget-opened', { detail: { widget: 'scanner' } }));
                if (typeof window.closeMessifyWidget === 'function') {
                    window.closeMessifyWidget();
                }
            });

            window.addEventListener('rms-widget-opened', (e) => {
                const detail = e.detail;
                const w = (detail && typeof detail === 'object') ? (detail.widget || (Array.isArray(detail) ? detail[0]?.widget : null)) : detail;
                if (w && w !== 'scanner') {
                    const comp = resolveModalComponent();
                    if (comp) {
                        if (typeof comp.closeModal === 'function') {
                            comp.closeModal();
                        } else if (comp.$wire && typeof comp.$wire.closeModal === 'function') {
                            comp.$wire.closeModal();
                        } else if (typeof comp.call === 'function') {
                            comp.call('closeModal');
                        }
                    }
                }
            });
        }

        window.addEventListener('beforeunload', () => {
            stopModalScanner();
        });
    })();
    </script>

    <!-- Office Hub Collective Modal for Forwarding -->
    <x-dts.hub-collective-modal 
        :show="$showForwardHubModal" 
        :search="$forwardHubSearch" 
        :selected="$forwardHubTempSelected" 
        :offices="$this->availableTargetOffices"
        wireClose="closeForwardHubModal"
        wireToggle="toggleForwardHubOffice"
        wireSelectAll="selectAllForwardHubOffices"
        wireClear="clearForwardHubOffices"
        wireApply="applyForwardHubModal"
        wireSearch="forwardHubSearch"
    />
</div>

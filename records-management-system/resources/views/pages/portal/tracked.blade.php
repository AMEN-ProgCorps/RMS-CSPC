<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 *        Filestorage_data = data: { 
 *             device_id: string, 
 *             document_tracked_within_10_minutes: int / 3, //this one starts on 0
 *             last_document_tracked_at: timestamp,
 *             email_used_on_verification: string,
 *             is_email_not_cspc: boolean,
 *             device_blocked_until: timestamp     
 *          }
 *   since this page is only continuation of track-document while being in the same url, it will revolve around this phases like track-document:
 *   Phase 1: after going throught the verification on track-document, the url value will be passed on this page eg /track-document=?{traacking_number} and the document will be searched, 
 *        L if found which already been before they encounter the page here, the email verification form will be shown and wll proceed to next phaase
 *        L if not found or just maniputalted the url, it will check the browser local storage for the document_tracked_within_10_minutes value or the whole data if present in the browser file storage, 
 *                L if its present and the value of document_tracked_within_10_minutes is less than 3, then add one to the value because they forcing to access the page and return to track-document... 
 *                L if not then return back to track-document
 *   Phase 2: after the email verification it will rewrite the email value on the browser file storage
 *                L   if the email is a cspc email, then it will skip the document password step
 *                L   if the email is not a cspc email, then it will show the document password step, and after the correct password is inputed, it will proceed to the next phase
 *   Phase 3: after the document password verification, it will show the document data, while at it the backend will now send a value to the tracking_device_log for audit purpose and security purposes
 *            update the last_document_tracked_at to the current timestamp, and upload data to the backend: the date will be like this:           
 *                data: {
 *                      tracked_id: <the id of the documeent that the user inputed>
 *                      device_id: <the unique device id generated in phase 1.3> 
 *                      email: null, // due to the fact that the email verification is only for the documents that exist, the email_used_on_verification will be null if the tracking number does not exist,
 *                      current_timestamp: <the current timestamp when the user inputed the tracking number>
 *                      status: <document_tracked_within_10_minutes rated as if 1: warning, 2: danger, 3: blocked>
 *                    }                            
 */
new #[Layout('layouts.portal')] #[Title('Track Document — Results')] class extends Component
{
    #[Url(as: 'number')]
    public string $trackingNumber = '';

    public bool $documentFound = false;

    public string $email = '';
    public bool $emailReadonly = false;
    public string $emailStatus = '';

    public bool $showPasswordStep = false;
    public bool $showDocumentData = false;
    public string $documentPassword = '';

    public string $docType = '';
    public string $dateReceived = '';
    public string $senderOffice = '';
    public string $currentLocation = '';
    public string $docStatus = 'Ongoing';
    public string $docStatusColor = '#2563EB';

    public function mount(): void
    {
        if (blank($this->trackingNumber)) {
            $this->redirect(route('track-document'), navigate: true);
            return;
        }

        try {
            $this->documentFound = DB::table('dts_transactions')
                ->where('qr_code', $this->trackingNumber)
                ->exists();
        } catch (\Illuminate\Database\QueryException) {
            $this->documentFound = false;
        }

        if (! $this->documentFound) {
            $this->dispatch('document-not-found-on-tracked');
        }
    }

    // Called from JS after Google Sign-in resolves the email
    public function setEmailFromGoogle(string $email): void
    {
        $this->email = $email;
        $this->emailReadonly = true;
    }

    public function verifyEmail(): void
    {
        $this->validate(['email' => ['required', 'email']]);

        $isCspc = (bool) preg_match('/^[^@]+@(cspc\.edu\.ph|[^@]+\.cspc\.edu\.ph)$/i', $this->email);

        $this->dispatch('update-storage-email', email: $this->email, isCspc: $isCspc);

        if ($isCspc) {
            $this->emailStatus = 'CSPC email verified.';
            $this->loadDocumentData();
            $this->showDocumentData = true;
        } else {
            $this->emailStatus = 'Non-CSPC email. Document password is required.';
            $this->showPasswordStep = true;
        }
    }

    public function submitPassword(): void
    {
        $this->validate(['documentPassword' => ['required', 'string']]);

        try {
            $detail = DB::table('dts_transaction_details as dtd')
                ->join('dts_transactions as dt', 'dtd.id', '=', 'dt.transaction_id')
                ->where('dt.qr_code', $this->trackingNumber)
                ->select('dtd.document_password')
                ->first();

            if (! $detail || $detail->document_password !== $this->documentPassword) {
                $this->addError('documentPassword', 'Incorrect document password. Please try again.');
                return;
            }

            $this->loadDocumentData();
            $this->showPasswordStep = false;
            $this->showDocumentData = true;
        } catch (\Illuminate\Database\QueryException) {
            $this->addError('documentPassword', 'Database error. Please try again later.');
        }
    }

    // Invoked from JS after document data is displayed (Phase 3 audit)
    public function logAudit(string $deviceInfoJson): void
    {
        $deviceInfo = json_decode($deviceInfoJson, true) ?? [];
        $deviceId   = $deviceInfo['device_id'] ?? 'unknown';
        $attempts   = (int) ($deviceInfo['document_tracked_within_10_minutes'] ?? 1);

        $status = match (true) {
            $attempts >= 3  => 'blocked',
            $attempts === 2 => 'danger',
            default         => 'warning',
        };

        try {
            DB::table('tracking_devices_log')->insert([
                'tracked_id'        => null,
                'device_id'         => $deviceId,
                'email'             => $this->email ?: null,
                'current_timestamp' => now(),
                'status'            => $status,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        } catch (\Illuminate\Database\QueryException) {
            // Audit failure is non-critical — silently ignore
        }
    }

    private function loadDocumentData(): void
    {
        try {
            $data = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where('dt.qr_code', $this->trackingNumber)
                ->select(
                    'dtd.type as doc_type',
                    'dtd.date_created',
                    'dtd.originated_from as sender',
                    'dt.current_office',
                    'dt.status',
                )
                ->first();

            if ($data) {
                $this->docType         = ucfirst($data->doc_type ?? 'N/A');
                $this->dateReceived    = $data->date_created
                    ? \Carbon\Carbon::parse($data->date_created)->format('F j, Y')
                    : 'N/A';
                $this->senderOffice    = $data->sender ?? 'N/A';
                $this->currentLocation = $data->current_office ?? 'N/A';
                $this->docStatus       = ucfirst($data->status ?? 'Ongoing');
                $this->docStatusColor  = match ($data->status ?? '') {
                    'completed' => '#33A04B',
                    'ongoing'   => '#2563EB',
                    'revision'  => '#D97706',
                    'cancelled' => '#DC2626',
                    'drafted'   => '#6B7280',
                    default     => '#2563EB',
                };
            }

            $this->dispatch('log-audit-now');
        } catch (\Illuminate\Database\QueryException) {
            // Keep N/A defaults; document data block still renders
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/td.css'])
@endpush

<header>
    <div class="logo">
        <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
    </div>
    <span>Records and Freedom of Information Office</span>
</header>
<section>
    <div class="login">
        <a href="{{ route('track-document') }}" class="log">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="24" viewBox="0 0 48 24">
                <path fill="currentColor" d="M46,13V11H8L13.5,5.5L12.08,4.08L4.16,12L12.08,19.92L13.5,18.5L8,13H46Z" />
            </svg>
            Back
        </a>
    </div>
    <div class="container">
        <div class="td-label">
            <span class="top">Track Document</span>
            <span class="subtitle">Enter your tracking number to view your document status without login</span>
        </div>
        <div class="td-items">
            <div class="doc-ifs">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M8,12V14H16V12H8M8,16V18H13V16H8Z" />
                </svg>
                <span>Document </span>
                <span>{{ $documentFound ? 'Found' : 'Not Found' }}</span>
            </div>
            <div class="doc-tracking-number">
                <span>{{ $trackingNumber }}</span>
            </div>
            @if ($documentFound)
                @if (! $showPasswordStep && ! $showDocumentData)
                    <div id="doc-verification" class="data-containers" wire:ignore.self>
                        {{-- Google Sign-In button — on credential, email field is auto-filled --}}
                        <div id="g_id_onload"
                            data-client_id="{{ config('services.google.client_id') }}"
                            data-callback="handleGoogleCredential"
                            data-auto_prompt="false">
                        </div>
                        <div class="g_id_signin"
                            data-type="standard"
                            data-size="large"
                            data-theme="outline"
                            data-text="sign_in_with"
                            data-shape="rectangular"
                            data-logo_alignment="left">
                        </div>
                        <form wire:submit="verifyEmail" class="doc-verification">
                            <span class="subtitle">Email</span>
                            <input
                                wire:model="email"
                                type="email"
                                autocomplete="email"
                                name="email"
                                id="email-input"
                                @if ($emailReadonly) readonly @endif
                                placeholder="Waiting for Google sign-in..."
                                required
                            >
                            <div id="email-status" class="email-status">{{ $emailStatus }}</div>
                            <button type="submit" class="verification-btn">Verify</button>
                        </form>
                    </div>
                @endif
                @if ($showPasswordStep && ! $showDocumentData)
                    <div id="doc-password" class="data-containers">
                        <form wire:submit="submitPassword" class="doc-password">
                            <span class="subtitle">Enter Document Password:</span>
                            <input wire:model="documentPassword" type="password" placeholder="Document Password" required>
                            @error('documentPassword')
                                <span class="form-error">{{ $message }}</span>
                            @enderror
                            <button type="submit" class="password-btn">Submit</button>
                        </form>
                    </div>
                @endif
                @if ($showDocumentData)
                    <div id="document-data" class="data-containers">
                        <div class="doc-status" style="background-color: {{ $docStatusColor }}">
                            <div class="status-box">
                                <span>{{ $docStatus }}</span>
                            </div>
                        </div>
                        <div class="doc-details">
                            <div class="ddc-item">
                                <div class="ddc-question">Document Type</div>
                                <div class="ddc-answer">{{ $docType ?: 'N/A' }}</div>
                            </div>
                            <div class="ddc-item">
                                <div class="ddc-question">Date Received</div>
                                <div class="ddc-answer">{{ $dateReceived ?: 'N/A' }}</div>
                            </div>
                            <div class="ddc-item">
                                <div class="ddc-question">Sender</div>
                                <div class="ddc-answer">{{ $senderOffice ?: 'N/A' }}</div>
                            </div>
                            <div class="ddc-item">
                                <div class="ddc-question">Current Location</div>
                                <div class="ddc-answer">{{ $currentLocation ?: 'N/A' }}</div>
                            </div>
                        </div>
                    </div>
                @endif
            @else
                {{-- Shown briefly while JS increments the counter and redirects --}}
                <div class="data-containers" style="text-align:center; padding: 1.2rem; color: #B91C1C;">
                    <span>Document not found. Redirecting...</span>
                </div>
            @endif
        </div>
    </div>
</section>

@push('scripts')
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
(function () {
    const STORAGE_KEY = 'rms_tracking_device';

    function getDevice() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch { return null; }
    }
    function saveDevice(d) { localStorage.setItem(STORAGE_KEY, JSON.stringify(d)); }

    let cleanupListeners = null;

    // Must be on window — required by the Google Identity Services library
    window.handleGoogleCredential = function (response) {
        try {
            const parts   = response.credential.split('.');
            const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
            const email   = payload.email || '';
            if (email) {
                @this.call('setEmailFromGoogle', email);
            }
        } catch (err) {
            console.error('Google credential parse failed:', err);
        }
    };

    function setup() {
        if (cleanupListeners) { cleanupListeners(); cleanupListeners = null; }

        // Phase 1: URL was manipulated — document doesn't exist
        const offNotFound = Livewire.on('document-not-found-on-tracked', function () {
            let d = getDevice();
            if (d && typeof d === 'object') {
                const tracked = d.document_tracked_within_10_minutes ?? 0;
                if (tracked < 3) {
                    d.document_tracked_within_10_minutes = tracked + 1;
                    d.last_document_tracked_at           = new Date().toISOString();
                    saveDevice(d);
                }
            }
            setTimeout(function () {
                window.location.href = '{{ route('track-document') }}';
            }, 1500);
        });

        // Phase 2: persist verified email + CSPC flag back to localStorage
        const offEmail = Livewire.on('update-storage-email', function ({ email, isCspc }) {
            let d = getDevice();
            if (d) {
                d.email_used_on_verification = email;
                d.is_email_not_cspc          = !isCspc;
                saveDevice(d);
            }
        });

        // Phase 3: fire audit log with the current device snapshot
        const offAudit = Livewire.on('log-audit-now', function () {
            const d = getDevice();
            @this.call('logAudit', d ? JSON.stringify(d) : '{}');
        });

        cleanupListeners = function () { offNotFound(); offEmail(); offAudit(); };
    }

    document.addEventListener('livewire:navigated', setup);
    document.addEventListener('DOMContentLoaded', setup);
})();
</script>
<script>
window.checkDeviceStatus = function () {
    const KEY = 'rms_tracking_device';
    let d;
    try { d = JSON.parse(localStorage.getItem(KEY)); } catch { d = null; }

    if (!d) {
        console.warn('%c[RMS Debug] No device data found in localStorage.', 'color:#92400E;font-weight:700;');
        return null;
    }

    const now       = Date.now();
    const blockTs   = d.device_blocked_until ? new Date(d.device_blocked_until).getTime() : null;
    const isBlocked = blockTs !== null && now < blockTs;
    const minsLeft  = isBlocked ? Math.ceil((blockTs - now) / 60000) : 0;

    console.group('%c RMS — Device Status ', 'background:#1D4ED8;color:#fff;font-weight:700;border-radius:3px;padding:2px 8px;');
    console.log('%cdevice_id%c                         ', 'font-weight:700;color:#1D4ED8;', '', d.device_id ?? 'null');
    console.log('%cdocument_tracked_within_10_minutes%c', 'font-weight:700;color:#1D4ED8;', '', (d.document_tracked_within_10_minutes ?? 0) + ' / 3');
    console.log('%clast_document_tracked_at%c          ', 'font-weight:700;color:#1D4ED8;', '', d.last_document_tracked_at ?? 'null');
    console.log('%cemail_used_on_verification%c        ', 'font-weight:700;color:#1D4ED8;', '', d.email_used_on_verification ?? 'null');
    console.log('%cis_email_not_cspc%c                 ', 'font-weight:700;color:#1D4ED8;', '', d.is_email_not_cspc ?? false);
    console.log(
        '%cdevice_blocked_until%c              ',
        'font-weight:700;color:#1D4ED8;',
        isBlocked ? 'color:#B45309;font-weight:700;' : '',
        (d.device_blocked_until ?? 'null') + (isBlocked ? '  ⛔  blocked — ' + minsLeft + ' min remaining' : '')
    );
    console.groupEnd();

    return d;
};
</script>
@endpush

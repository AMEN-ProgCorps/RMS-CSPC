<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

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
    public string $emailStatus = '';

    public bool $isGoogleVerified = false;
    public string $verifiedGoogleName = '';
    public string $verifiedGoogleAvatar = '';

    public bool $showPasswordStep = false;
    public bool $showDocumentData = false;
    public string $documentPassword = '';

    public string $docType = '';
    public string $dateReceived = '';
    public string $senderOffice = '';
    public string $currentLocation = '';
    public string $docStatus = 'Ongoing';
    public string $docStatusColor = '#2563EB';

    public function mount(string $number = ''): void
    {
        if (!empty($number)) {
            $this->trackingNumber = $number;
        }

        if (blank($this->trackingNumber)) {
            $this->redirect(route('track-document'), navigate: true);
            return;
        }

        $input = trim($this->trackingNumber);
        $decoded = base64_decode($input, true);
        if ($decoded !== false && ctype_print($decoded)) {
            $this->trackingNumber = trim($decoded);
        }

        $code = $this->trackingNumber;
        try {
            $this->documentFound = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where(function($q) use ($code) {
                    $q->where('dt.qr_code', $code)
                      ->orWhere('dtd.control_number', $code);
                })
                ->whereNotNull('dtd.email_access')
                ->exists();
        } catch (\Illuminate\Database\QueryException) {
            $this->documentFound = false;
        }

        if (! $this->documentFound) {
            $this->dispatch('document-not-found-on-tracked');
            return;
        }

        // Check if user just verified via Google SSO
        if (session()->has('verified_tracker_email')) {
            $this->email = (string) session('verified_tracker_email');
            $this->isGoogleVerified = (session('verified_tracker_auth_type') === 'google');
            $this->verifiedGoogleName = (string) session('verified_tracker_name', '');
            $this->verifiedGoogleAvatar = (string) session('verified_tracker_avatar', '');

            // Auto-verify with the authenticated Google email
            $this->verifyEmail();
        }
    }

    public function switchAccount(): void
    {
        session()->forget(['verified_tracker_email', 'verified_tracker_name', 'verified_tracker_avatar', 'verified_tracker_auth_type']);
        $this->email = '';
        $this->emailStatus = '';
        $this->isGoogleVerified = false;
        $this->verifiedGoogleName = '';
        $this->verifiedGoogleAvatar = '';
        $this->showPasswordStep = false;
        $this->showDocumentData = false;
        $this->resetErrorBag();
    }

    public function verifyEmail(): void
    {
        $this->validate(['email' => ['required', 'email']]);

        try {
            $code = $this->trackingNumber;
            $transactionDetails = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->leftJoin('dts_email_access as dea', 'dea.id', '=', 'dtd.email_access')
                ->where(function($q) use ($code) {
                    $q->where('dt.qr_code', $code)
                      ->orWhere('dtd.control_number', $code);
                })
                ->select('dea.email as allowed_email', 'dea.is_active as is_email_active', 'dtd.document_password')
                ->first();

            if ($transactionDetails && !empty($transactionDetails->allowed_email)) {
                if (strtolower(trim($this->email)) !== strtolower(trim($transactionDetails->allowed_email))) {
                    $this->addError('email', "The email '{$this->email}' is not authorized to track this document.");
                    return;
                }
            }

            $isCspc = (bool) preg_match('/^[^@]+@(cspc\.edu\.ph|[^@]+\.cspc\.edu\.ph)$/i', $this->email);
            $this->dispatch('update-storage-email', email: $this->email, isCspc: $isCspc);

            $hasPassword = $transactionDetails && !empty($transactionDetails->document_password);

            if ($isCspc) {
                $this->emailStatus = 'CSPC account verified.';
                $this->loadDocumentData();
                $this->showDocumentData = true;
            } else {
                if ($hasPassword) {
                    $this->emailStatus = 'Email verified. Document password required.';
                    $this->showPasswordStep = true;
                } else {
                    $this->emailStatus = 'Email verified.';
                    $this->loadDocumentData();
                    $this->showDocumentData = true;
                }
            }
        } catch (\Exception $e) {
            $this->addError('email', 'Failed to verify email access: ' . $e->getMessage());
        }
    }

    public function submitPassword(): void
    {
        $this->validate(['documentPassword' => ['required', 'string']]);

        try {
            $code = $this->trackingNumber;
            $detail = DB::table('dts_transaction_details as dtd')
                ->join('dts_transactions as dt', 'dtd.id', '=', 'dt.transaction_id')
                ->where(function($q) use ($code) {
                    $q->where('dt.qr_code', $code)
                      ->orWhere('dtd.control_number', $code);
                })
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
            DB::table('sys_tracking_devices_log')->insert([
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
            $code = $this->trackingNumber;
            $data = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where(function($q) use ($code) {
                    $q->where('dt.qr_code', $code)
                      ->orWhere('dtd.control_number', $code);
                })
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
    <style>
        .google-sso-track-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 11px 16px;
            background: #ffffff;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            color: #374151;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .google-sso-track-btn:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .google-sso-track-btn svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        .auth-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 12px 0;
            color: #9ca3af;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e5e7eb;
        }
        .auth-divider span {
            padding: 0 12px;
        }
        .user-verified-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .user-verified-badge img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        .user-verified-badge .user-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .user-verified-badge .user-email {
            font-size: 13px;
            font-weight: 600;
            color: #065f46;
        }
        .user-verified-badge .user-tag {
            font-size: 11px;
            color: #047857;
        }
        .switch-account-link {
            font-size: 12px;
            color: #0059FF;
            text-decoration: underline;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            margin-top: 4px;
        }
        .doc-verification-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #D9D9D9;
            border-radius: 8px;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .doc-verification-input:focus {
            border-color: #0059FF;
            box-shadow: 0 0 0 2px rgba(0, 89, 255, 0.15);
        }
        .verification-btn, .password-btn {
            background-color: #0059FF;
            color: #FFFFFF;
            border: none;
            padding: 11px 20px;
            border-radius: 8px;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            width: 100%;
            margin-top: 8px;
        }
        .verification-btn:hover, .password-btn:hover {
            background-color: #003699;
        }
        .error-banner {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 12px;
            text-align: center;
        }
    </style>
@endpush

<div>
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
                    <div id="doc-verification" class="data-containers">
                        @error('email')
                            <div class="error-banner">
                                {{ $message }}
                                <div style="margin-top: 6px;">
                                    <a href="{{ route('auth.google.track', ['number' => $trackingNumber]) }}" class="switch-account-link">
                                        Try signing in with a different Google account
                                    </a>
                                </div>
                            </div>
                        @enderror

                        {{-- 1-Click Google SSO Verification --}}
                        <div>
                            <a href="{{ route('auth.google.track', ['number' => $trackingNumber]) }}" class="google-sso-track-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
                                    <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.66 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                                    <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                                    <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                                    <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.66 48 24 48z"/>
                                </svg>
                                <span>Verify with Google</span>
                            </a>
                        </div>

                        <div class="auth-divider">
                            <span>or enter email manually</span>
                        </div>

                        <form wire:submit="verifyEmail" class="doc-verification">
                            <span class="subtitle" style="display:block; margin-bottom: 6px;">Authorized Email Address:</span>
                            <input
                                wire:model="email"
                                type="email"
                                autocomplete="email"
                                name="email"
                                id="email-input"
                                class="doc-verification-input"
                                placeholder="e.g. name@cspc.edu.ph or personal@gmail.com"
                                required
                            >
                            @if ($emailStatus)
                                <div id="email-status" class="email-status">{{ $emailStatus }}</div>
                            @endif
                            <button type="submit" class="verification-btn">Verify Email</button>
                        </form>
                    </div>
                @endif

                @if ($showPasswordStep && ! $showDocumentData)
                    <div id="doc-password" class="data-containers">
                        @if ($isGoogleVerified)
                            <div class="user-verified-badge">
                                @if ($verifiedGoogleAvatar)
                                    <img src="{{ $verifiedGoogleAvatar }}" alt="Google Avatar">
                                @endif
                                <div class="user-info">
                                    <span class="user-email">✓ {{ $email }}</span>
                                    <span class="user-tag">Verified with Google</span>
                                </div>
                                <button type="button" wire:click="switchAccount" class="switch-account-link">Change</button>
                            </div>
                        @else
                            <div class="user-verified-badge" style="background:#F3F4F6; border-color:#E5E7EB;">
                                <div class="user-info">
                                    <span class="user-email" style="color:#374151;">{{ $email }}</span>
                                    <span class="user-tag" style="color:#6B7280;">Manual verification</span>
                                </div>
                                <button type="button" wire:click="switchAccount" class="switch-account-link">Change</button>
                            </div>
                        @endif

                        <form wire:submit="submitPassword" class="doc-password">
                            <span class="subtitle" style="display:block; margin-bottom: 6px;">Enter Document Password:</span>
                            <input wire:model="documentPassword" type="password" class="doc-verification-input" placeholder="Enter document password" required>
                            @error('documentPassword')
                                <div class="error-banner" style="margin-top: 8px;">{{ $message }}</div>
                            @enderror
                            <button type="submit" class="password-btn">Submit Password</button>
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
</div>

@push('scripts')
<script>
(function () {
    const STORAGE_KEY = 'rms_tracking_device';

    function getDevice() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch { return null; }
    }
    function saveDevice(d) { localStorage.setItem(STORAGE_KEY, JSON.stringify(d)); }

    let cleanupListeners = null;

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

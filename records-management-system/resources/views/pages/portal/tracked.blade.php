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

    public function mount(?string $number = null): void
    {
        if (!empty($number)) {
            $this->trackingNumber = $number;
        }

        if (blank($this->trackingNumber)) {
            $this->trackingNumber = (string) (request()->query('number') ?? session('tracking_target_number', ''));
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
        $this->showEmailErrorModal = false;
        $this->emailErrorMessage = '';
        $this->isUnauthorizedEmail = false;
        $this->resetErrorBag();
    }

    public bool $showEmailErrorModal = false;
    public string $emailErrorMessage = '';
    public bool $isUnauthorizedEmail = false;

    public function closeEmailErrorModal(): void
    {
        $this->showEmailErrorModal = false;
        $this->emailErrorMessage = '';
        $this->isUnauthorizedEmail = false;
    }

    public function verifyEmail(): void
    {
        $validated = \Illuminate\Support\Facades\Validator::make(
            ['email' => $this->email],
            ['email' => ['required', 'email']],
            [
                'email.required' => 'The email field is required.',
                'email.email'    => 'Please enter a valid email address.',
            ]
        );

        if ($validated->fails()) {
            $this->emailErrorMessage = $validated->errors()->first('email');
            $this->isUnauthorizedEmail = false;
            $this->showEmailErrorModal = true;
            return;
        }

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
                    $this->emailErrorMessage = "The email '{$this->email}' is not authorized to track this document.";
                    $this->isUnauthorizedEmail = true;
                    $this->showEmailErrorModal = true;
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
            $this->emailErrorMessage = 'Failed to verify email access: ' . $e->getMessage();
            $this->isUnauthorizedEmail = false;
            $this->showEmailErrorModal = true;
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
    <script>
        if (window.self !== window.top) {
            window.top.location.href = window.location.href;
        }
    </script>
    @vite(['resources/css/login.css', 'resources/css/track-document.css'])
@endpush

<div class="salesskip-viewport-root livewire-root" id="tracked-viewport-root">
    <div class="salesskip-split-container" id="main-swipe-wrapper">
        <!-- LEFT PANEL: Vibrant Royal Blue Hero with Curves, Welcome & Developers -->
        <div class="hero-blue-pane" id="pane-blue">
        <!-- Curved wireframe contour lines in background -->
        <div class="wireframe-waves-bg" aria-hidden="true">
            <svg viewBox="0 0 700 800" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
                <g opacity="0.25" stroke="url(#hero-wave-glow)" stroke-width="1.8">
                    <path d="M-60,90 C180,40 320,260 560,140 C740,40 840,280 1020,220" />
                    <path d="M-60,160 C200,110 340,330 590,210 C770,110 870,350 1050,290" />
                    <path d="M-60,230 C220,180 360,400 620,280 C800,180 900,420 1080,360" />
                    <path d="M-60,300 C240,250 380,470 650,350 C830,250 930,490 1110,430" />
                    <path d="M-60,370 C260,320 400,540 680,420 C860,320 960,560 1140,500" />
                    <path d="M-60,440 C280,390 420,610 710,490 C890,390 990,630 1170,570" />
                    <path d="M-60,510 C300,460 440,680 740,560 C920,460 1020,700 1200,640" />
                    <path d="M-60,580 C320,530 460,750 770,630 C950,530 1050,770 1230,710" />
                </g>
                <defs>
                    <linearGradient id="hero-wave-glow" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#BAE6FD" stop-opacity="0.9" />
                        <stop offset="50%" stop-color="#60A5FA" stop-opacity="0.5" />
                        <stop offset="100%" stop-color="#2563EB" stop-opacity="0.1" />
                    </linearGradient>
                </defs>
            </svg>
        </div>

        <div class="hero-inner-content">
            <!-- Middle: Main Heading, Welcome & Developers -->
            <div class="hero-middle-section">
                <h1 class="hero-heading">
                    CSPC<br>
                    <span>Records Management System</span>
                </h1>

                <p class="hero-welcome-text">
                    Welcome to the centralized portal for the Records Management System (RMS) at Camarines Sur Polytechnic Colleges (CSPC). Developed under the leadership of the Records and Freedom of Information Unit (RFIU) in collaboration with the Information and Communications Technology Unit (ICTU), this modernized web application platform enhances institutional transparency, document traceability, and operational accountability across all campus administrative offices.
                </p>

                <!-- Developers Section -->
                <div class="developers-plain-section">
                    <div class="developers-plain-tag">Developers</div>
                    <ul class="developers-plain-names">
                        <li>John Albert T. Lagriada</li>
                        <li>Jan Russel S. Luce&ntilde;a</li>
                        <li>Shanice R. Magbanua</li>
                        <li>Jeroboam T. Oliveros</li>
                        <li>Kurt Gabrielle B. Zabala</li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Copyright -->
            <div class="hero-bottom-section">
                <span>&copy; {{ date('Y') }} CSPC Records Management System. All rights reserved.</span>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: Clean White Minimalist Form (Default view on mobile) -->
    <div class="form-white-pane" id="pane-white">
        <!-- Top Header: Brand Name + Back to Track Document -->
        <div class="white-top-bar">
            <div class="white-brand-header">
                <img src="{{ asset('images/cspc.webp') }}" alt="CSPC Seal" class="white-brand-seal">
                <span class="white-brand-name">RMS CSPC</span>
            </div>

            <a href="{{ route('track-document') }}" class="white-track-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span>Back</span>
            </a>
        </div>

        <!-- Center Content Area -->
        <div class="white-center-area">
            <div class="white-form-box" style="max-width: 480px;">
                <div class="welcome-header-group">
                    <h2 class="welcome-heading">DOCUMENT STATUS</h2>
                    <p class="welcome-subheading">
                        View document transaction status and verification details.
                    </p>
                </div>

                {{-- Status Banner with Tracking Number --}}
                <div class="tracked-status-banner">
                    <div class="tracked-status-left">
                        <span class="tracked-status-dot" style="{{ !$documentFound ? 'background:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,0.2);' : '' }}"></span>
                        <span style="{{ !$documentFound ? 'color:#b91c1c;' : '' }}">Document {{ $documentFound ? 'Found' : 'Not Found' }}</span>
                    </div>
                    <span class="tracked-code-pill">{{ $trackingNumber }}</span>
                </div>

                @if ($documentFound)
                    {{-- PHASE 1: EMAIL VERIFICATION --}}
                    @if (! $showPasswordStep && ! $showDocumentData)
                        <div class="tracked-form-container">
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

                            <form wire:submit="verifyEmail" class="tracked-field-group" novalidate>
                                <label for="email-input" class="tracked-field-label">Authorized Email Address:</label>
                                <input
                                    wire:model="email"
                                    type="email"
                                    autocomplete="email"
                                    name="email"
                                    id="email-input"
                                    class="tracked-input"
                                    placeholder="Enter your email"
                                >
                                @if ($emailStatus)
                                    <div class="form-error" style="color:#059669; font-size:13px;">{{ $emailStatus }}</div>
                                @endif
                                <button type="submit" class="tracked-primary-btn" style="margin-top: 6px;">Verify Email</button>
                            </form>
                        </div>
                    @endif

                    {{-- PHASE 2: DOCUMENT PASSWORD --}}
                    @if ($showPasswordStep && ! $showDocumentData)
                        <div class="tracked-form-container">
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
                                <div class="user-verified-badge" style="background:#f8fafc; border-color:#e2e8f0;">
                                    <div class="user-info">
                                        <span class="user-email" style="color:#1e293b;">{{ $email }}</span>
                                        <span class="user-tag" style="color:#64748b;">Manual email verification</span>
                                    </div>
                                    <button type="button" wire:click="switchAccount" class="switch-account-link">Change</button>
                                </div>
                            @endif

                            <form wire:submit="submitPassword" class="tracked-field-group">
                                <label for="doc-password-input" class="tracked-field-label">Enter Document Password:</label>
                                <input wire:model="documentPassword" id="doc-password-input" type="password" class="tracked-input" placeholder="Enter document password" required>
                                @error('documentPassword')
                                    <div class="form-error">{{ $message }}</div>
                                @enderror
                                <button type="submit" class="tracked-primary-btn" style="margin-top: 6px;">Submit Password</button>
                            </form>
                        </div>
                    @endif

                    {{-- PHASE 3: DOCUMENT DATA DETAILS --}}
                    @if ($showDocumentData)
                        <div class="document-result-card">
                            <div class="doc-status-header" style="background-color: {{ $docStatusColor }};">
                                <span>Status: {{ $docStatus }}</span>
                                <span style="font-size: 13px; font-weight: 500; opacity: 0.9;">Tracking Active</span>
                            </div>

                            <div class="doc-details-grid">
                                <div class="doc-detail-row">
                                    <span class="doc-detail-label">Document Type</span>
                                    <span class="doc-detail-value">{{ $docType ?: 'N/A' }}</span>
                                </div>
                                <div class="doc-detail-row">
                                    <span class="doc-detail-label">Date Received</span>
                                    <span class="doc-detail-value">{{ $dateReceived ?: 'N/A' }}</span>
                                </div>
                                <div class="doc-detail-row">
                                    <span class="doc-detail-label">Sender</span>
                                    <span class="doc-detail-value">{{ $senderOffice ?: 'N/A' }}</span>
                                </div>
                                <div class="doc-detail-row">
                                    <span class="doc-detail-label">Current Location</span>
                                    <span class="doc-detail-value">{{ $currentLocation ?: 'N/A' }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                @else
                    {{-- Not found notice while redirecting --}}
                    <div class="clean-alert-error" role="alert" style="margin-top: 10px;">
                        <svg class="alert-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <div class="alert-msg">
                            <span>Document not found in the records system. Redirecting...</span>
                        </div>
                    </div>
                @endif

                <div class="bottom-support-info">
                    <p class="unit-text">Records and Freedom of Information Unit (RFIU)</p>
                    <p class="college-text">Camarines Sur Polytechnic Colleges</p>
                </div>
            </div>
        </div>
    </div>
</div> <!-- /.salesskip-split-container -->

{{-- Clean Email Error Modal (Rendered outside split-container to prevent desktop clipping) --}}
@if ($showEmailErrorModal)
    <div class="clean-modal-backdrop" wire:key="email-error-modal" wire:click.self="closeEmailErrorModal">
        <div class="clean-modal-card">
            <div class="clean-modal-icon-wrap error">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>

            <h3 class="clean-modal-title">Verification Notice</h3>

            <p class="clean-modal-body">
                {{ $emailErrorMessage }}
            </p>

            @if ($isUnauthorizedEmail)
                <div style="margin-top: 16px; width: 100%;">
                    <a href="{{ route('auth.google.track', ['number' => $trackingNumber]) }}" class="switch-account-link" style="display: block; text-align: center; font-size: 13.5px; padding: 10px 14px; border-radius: 10px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; text-decoration: none; font-weight: 600;">
                        Try signing in with a different Google account
                    </a>
                </div>
            @endif

            <button type="button" class="clean-modal-btn" wire:click="closeEmailErrorModal">
                Understood
            </button>
        </div>
    </div>
@endif
</div> <!-- /.salesskip-viewport-root -->

@push('scripts')
<script>
    // ==========================================
    // 1. PREVENT PINCH ZOOM & DOUBLE-TAP ZOOM
    // ==========================================
    (function preventPinchZoom() {
        const metaViewport = document.querySelector('meta[name="viewport"]');
        if (metaViewport) {
            metaViewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
        }

        // Prevent multi-touch gestures (pinch zoom)
        document.addEventListener('touchstart', (e) => {
            if (e.touches.length > 1) {
                e.preventDefault();
            }
        }, { passive: false });

        // Prevent fast double-tap zooming on iOS Safari & mobile Chrome
        let lastTouchEnd = 0;
        document.addEventListener('touchend', (e) => {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        // Prevent iOS Safari gesture zoom events
        document.addEventListener('gesturestart', (e) => e.preventDefault());
        document.addEventListener('gesturechange', (e) => e.preventDefault());
        document.addEventListener('gestureend', (e) => e.preventDefault());
    })();

    // ==========================================
    // 2. MOBILE TOUCH SWIPE INTERACTION
    // ==========================================
    let currentActivePane = 'white';

    function isMobile() {
        return window.innerWidth <= 992;
    }

    function initMobileSwipe() {
        const wrapper = document.getElementById('main-swipe-wrapper');
        const whitePane = document.getElementById('pane-white');
        if (isMobile() && wrapper && whitePane) {
            wrapper.scrollTo({ left: whitePane.offsetLeft, behavior: 'instant' });
            currentActivePane = 'white';
        }
    }

    function setupSwipe() {
        const wrapper = document.getElementById('main-swipe-wrapper');
        const whitePane = document.getElementById('pane-white');

        initMobileSwipe();

        if (wrapper && !wrapper.dataset.scrollBound) {
            wrapper.dataset.scrollBound = "true";
            wrapper.addEventListener('scroll', () => {
                if (isMobile() && whitePane) {
                    currentActivePane = (wrapper.scrollLeft < whitePane.offsetLeft / 2) ? 'blue' : 'white';
                }
            }, { passive: true });
        }
    }

    document.addEventListener('DOMContentLoaded', setupSwipe);
    document.addEventListener('livewire:navigated', setupSwipe);

    window.addEventListener('resize', () => {
        const wrapper = document.getElementById('main-swipe-wrapper');
        if (!isMobile()) {
            if (wrapper) wrapper.scrollTo({ left: 0, behavior: 'instant' });
        } else {
            const whitePane = document.getElementById('pane-white');
            if (wrapper && whitePane) {
                const targetLeft = (currentActivePane === 'blue') ? 0 : whitePane.offsetLeft;
                wrapper.scrollTo({ left: targetLeft, behavior: 'instant' });
            }
        }
    });
</script>
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

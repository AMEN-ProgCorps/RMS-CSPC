<?php

use App\Helpers\NetworkHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('Track Document')] class extends Component
{
    public string $trackingNumber = '';
    public bool $isProcessing = false;
    public string $deviceInfoJson = '';

    public function track(): void
    {
        $this->validate([
            'trackingNumber' => ['required', 'string'],
        ]);

        $ip = NetworkHelper::getClientIp();
        $rateKey = 'track_doc:' . $ip;

        // Server-side brute force protection: max 20 attempts per 10 min window per IP
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $mins = max(1, (int) ceil($seconds / 60));
            $this->dispatch('track-result', status: 'rate-limited', message: "Too many tracking attempts from your network. Please try again in {$mins} minute(s).");
            return;
        }
        RateLimiter::hit($rateKey, 600);

        $input = trim($this->trackingNumber);
        $decoded = base64_decode($input, true);
        if ($decoded !== false && ctype_print($decoded)) {
            $this->trackingNumber = trim($decoded);
        }

        $code = $this->trackingNumber;
        $deviceInfo = $this->deviceInfoJson ? json_decode($this->deviceInfoJson, true) : [];
        $deviceId   = $deviceInfo['device_id'] ?? 'unknown';
        $attempts   = (int) ($deviceInfo['document_tracked_within_10_minutes'] ?? 1);

        $status = match (true) {
            $attempts >= 3 => 'blocked',
            $attempts === 2 => 'danger',
            default        => 'warning',
        };

        // First level validation: check if QR exists or matches a control number
        $qrExists = DB::table('dts_qr_code')->where('code_id', $code)->exists();
        $cnExists = DB::table('dts_transaction_details')->where('control_number', $code)->exists();

        if (!$qrExists && !$cnExists) {
            try {
                DB::table('tracking_devices_log')->insert([
                    'tracked_id'        => null,
                    'device_id'         => $deviceId,
                    'email'             => null,
                    'current_timestamp' => now(),
                    'status'            => $status,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            } catch (\Exception $e) {}

            $this->dispatch('track-result', status: 'not-found');
            return;
        }

        try {
            // Phase 2: Check if tracking number exists in dts_transactions or transaction details and has email access
            $exists = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where(function($q) use ($code) {
                    $q->where('dt.qr_code', $code)
                      ->orWhere('dtd.control_number', $code);
                })
                ->whereNotNull('dtd.email_access')
                ->exists();

            if (! $exists) {
                DB::table('tracking_devices_log')->insert([
                    'tracked_id'        => null,
                    'device_id'         => $deviceId,
                    'email'             => null,
                    'current_timestamp' => now(),
                    'status'            => $status,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                $this->dispatch('track-result', status: 'not-found');
                return;
            }

            // Phase 3: Redirect to tracked results page
            $this->dispatch('track-result', status: 'found');
            $this->redirect(route('tracked') . '?number=' . urlencode($code), navigate: true);

        } catch (\Illuminate\Database\QueryException $e) {
            $this->dispatch('track-result', status: 'db-error');
        }
    }
};
?>

@push('styles')
    @vite(['resources/css/td.css'])
    <style>
        @keyframes si-slidein {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes si-pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.55; }
        }
        @keyframes si-shake {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-5px); }
            40%       { transform: translateX(5px); }
            60%       { transform: translateX(-4px); }
            80%       { transform: translateX(4px); }
        }
        #status_indicator {
            display: none;
            margin: 0.75rem 0;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            border-left: 4px solid currentColor;
            animation: si-slidein 0.2s ease;
        }
        #status_indicator[data-type="checking"] {
            background: #EFF6FF;
            color: #1D4ED8;
            animation: si-slidein 0.2s ease, si-pulse 1.4s ease-in-out infinite;
        }
        #status_indicator[data-type="blocked"] {
            background: #FFFBEB;
            color: #92400E;
            border-color: #D97706;
            animation: si-slidein 0.2s ease, si-shake 0.45s ease;
        }
        #status_indicator[data-type="error"] {
            background: #FEF2F2;
            color: #B91C1C;
            border-color: #EF4444;
            animation: si-slidein 0.2s ease, si-shake 0.45s ease;
        }
        #status_indicator[data-type="success"] {
            background: #F0FDF4;
            color: #15803D;
            border-color: #22C55E;
            animation: si-slidein 0.2s ease;
        }
        #status_indicator[data-type="db-error"] {
            background: #FDF4FF;
            color: #7E22CE;
            border-color: #A855F7;
            animation: si-slidein 0.2s ease, si-shake 0.45s ease;
        }
        #status_indicator .si-phase {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            opacity: 0.7;
            margin-bottom: 0.2rem;
        }
        #status_indicator .si-message {
            font-size: 0.9rem;
            font-weight: 600;
        }
    </style>
@endpush

<div class="livewire-root">
<header>
    <div class="logo">
        <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
    </div>
    <span>Records and Freedom of Information Office</span>
</header>
<section>
    <div class="login">
        <a href="{{ route('login') }}" class="log">
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
        @if (session('error'))
            <div style="background:#FEF2F2;border:1px solid #F87171;color:#991B1B;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.875rem;margin-bottom:0.5rem;text-align:center;">
                {{ session('error') }}
            </div>
        @endif
        <form id="track-form" class="td-search">
            <div class="search-container">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M9.5,3A6.5,6.5,0,1,0,16,9.5,6.51,6.51,0,0,0,9.5,3Zm0,11A4.5,4.5,0,1,1,14,9.5,4.51,4.51,0,0,1,9.5,14ZM20.71,19.29l-3.4-3.39a1,1,0,1,0-1.42,1.42l3.4,3.39a1,1,0,0,0,1.42-1.42Z" />
                </svg>
                <input wire:model="trackingNumber" type="text" placeholder="Enter Tracking Number" required>
            </div>
            <div id="status_indicator" wire:ignore>
                <div class="si-phase"></div>
                <div class="si-message"></div>
            </div>
            @error('trackingNumber')
                <span class="form-error">{{ $message }}</span>
            @enderror
            <button type="submit" class="search-btn" wire:loading.attr="disabled">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M9.5,3A6.5,6.5,0,1,0,16,9.5,6.51,6.51,0,0,0,9.5,3Zm0,11A4.5,4.5,0,1,1,14,9.5,4.51,4.51,0,0,1,9.5,14ZM20.71,19.29l-3.4-3.39a1,1,0,1,0-1.42,1.42l3.4,3.39a1,1,0,0,0,1.42-1.42Z" />
                </svg>
                <span wire:loading.remove wire:target="track">Track</span>
                <span wire:loading wire:target="track">Searching…</span>
            </button>
        </form>
    </div>
    <span class="Empty">
        Please Enter the tracking number to view your document transaction status. If you don't have a tracking number,<br>
        please contact the Records and Freedom of Information Office for assistance.
    </span>
</section>
</div>

@push('scripts')
<script>
(function () {
    const STORAGE_KEY  = 'rms_tracking_device';
    const MAX_ATTEMPTS = 3;
    const WINDOW_MS    = 10 * 60 * 1000; // 10 minutes
    const BLOCK_MS     = 50 * 60 * 1000; // 50 minutes
    const CSPC_PATTERN = /^[^@]+@(cspc\.edu\.ph|[^@]+\.cspc\.edu\.ph)$/i;

    const wait = ms => new Promise(r => setTimeout(r, ms));
    const step = () => wait(160);
    let submitting = false;
    let cleanupTrackResult = null;

    function getDevice() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch { return null; }
    }

    function saveDevice(d) { localStorage.setItem(STORAGE_KEY, JSON.stringify(d)); }

    function initDevice() {
        let d = getDevice();
        if (!d || typeof d !== 'object') {
            d = {
                device_id: 'dev_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10),
                document_tracked_within_10_minutes: 0,
                last_document_tracked_at: null,
                email_used_on_verification: null,
                is_email_not_cspc: false,
                device_blocked_until: null,
            };
            saveDevice(d);
        }
        return d;
    }

    function setStatus(phase, message, type) {
        const el = document.getElementById('status_indicator');
        if (!el) return;
        el.dataset.type = type;
        el.style.display = '';
        el.querySelector('.si-phase').textContent = phase;
        el.querySelector('.si-message').textContent = message;
    }

    function resetStatus() {
        const el = document.getElementById('status_indicator');
        if (el) el.style.display = 'none';
    }

    function setup() {
        const form = document.getElementById('track-form');
        if (!form) return;

        initDevice();

        // Remove any previously registered track-result listener
        if (cleanupTrackResult) { cleanupTrackResult(); cleanupTrackResult = null; }

        cleanupTrackResult = Livewire.on('track-result', function (data) {
            submitting = false;
            let status = null;
            let msg = null;
            if (typeof data === 'string') {
                status = data;
            } else if (data && typeof data === 'object') {
                status = data.status || (data[0] && (data[0].status || data[0])) || null;
                msg = data.message || (data[0] && data[0].message) || null;
            }

            if (status === 'not-found') {
                setStatus('Phase 2 — Result', 'Document Cannot Be Found. Please check your tracking number and try again.', 'error');
            } else if (status === 'found') {
                setStatus('Phase 3', 'Document Found! Redirecting to results...', 'success');
            } else if (status === 'rate-limited') {
                setStatus('Rate Limited', msg || 'Too many attempts. Please wait a few minutes.', 'blocked');
            } else if (status === 'db-error') {
                setStatus('Phase 2 — Error', 'Cannot connect to the database server. Please try again later or contact the Records Office.', 'db-error');
            }
        });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (submitting) return;
            submitting = true;
            resetStatus();

            // Phase 1 — read input
            setStatus('Phase 1', 'Extracting input data...', 'checking');
            await step();

            // Phase 1.2 — check localStorage
            setStatus('Phase 1.2', 'Checking device storage...', 'checking');
            let d = initDevice();
            await step();

            // Phase 1.3 — verify device status
            setStatus('Phase 1.3', 'Verifying device status...', 'checking');
            await step();
            const nowMs = Date.now();

            // Unblock if block period has expired
            if (d.device_blocked_until) {
                const blockedUntil = new Date(d.device_blocked_until).getTime();
                if (nowMs < blockedUntil) {
                    const mins = Math.ceil((blockedUntil - nowMs) / 60000);
                    setStatus('Blocked', 'Your device is currently blocked due to excessive attempts. Please try again in ' + mins + ' minute(s).', 'blocked');
                    submitting = false;
                    return;
                }
                d.device_blocked_until = null;
                d.document_tracked_within_10_minutes = 0;
                d.last_document_tracked_at = null;
                saveDevice(d);
            }

            // Reset counter if 10-minute window expired
            if (d.last_document_tracked_at) {
                const lastMs = new Date(d.last_document_tracked_at).getTime();
                if (nowMs - lastMs > WINDOW_MS) {
                    d.document_tracked_within_10_minutes = 0;
                    d.last_document_tracked_at = null;
                    saveDevice(d);
                }
            }

            // Block if limit reached on this attempt
            if (d.document_tracked_within_10_minutes >= MAX_ATTEMPTS) {
                d.device_blocked_until = new Date(nowMs + BLOCK_MS).toISOString();
                saveDevice(d);
                setStatus('Blocked', 'Too many attempts. Your device has been blocked for 50 minutes.', 'blocked');
                submitting = false;
                return;
            }

            // Record this attempt
            d.document_tracked_within_10_minutes += 1;
            d.last_document_tracked_at = new Date(nowMs).toISOString();
            saveDevice(d);

            // Phase 1.4 — evaluate email domain
            setStatus('Phase 1.4', 'Evaluating access permissions...', 'checking');
            await step();
            if (d.email_used_on_verification) {
                d.is_email_not_cspc = !CSPC_PATTERN.test(d.email_used_on_verification);
                saveDevice(d);
            }

            // Phase 2 — server validation
            setStatus('Phase 2', 'Validating tracking number with the server...', 'checking');
            @this.set('deviceInfoJson', JSON.stringify(d)).then(function () {
                @this.call('track');
            });
        });
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

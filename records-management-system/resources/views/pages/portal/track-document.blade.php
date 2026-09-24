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
                DB::table('sys_tracking_devices_log')->insert([
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
                DB::table('sys_tracking_devices_log')->insert([
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
            session(['tracking_target_number' => $code]);
            $this->dispatch('track-result', status: 'found');
            $this->redirect(route('tracked', ['number' => $code]), navigate: true);

        } catch (\Illuminate\Database\QueryException $e) {
            $this->dispatch('track-result', status: 'db-error');
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

<div class="salesskip-split-container livewire-root" id="main-swipe-wrapper">
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
        <!-- Top Header: Brand Name + Back to Login -->
        <div class="white-top-bar">
            <div class="white-brand-header">
                <img src="{{ asset('images/cspc.webp') }}" alt="CSPC Seal" class="white-brand-seal">
                <span class="white-brand-name">RMS CSPC</span>
            </div>

            <a href="{{ route('login') }}" class="white-track-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span>Login</span>
            </a>
        </div>

        <!-- Center Tracking Area -->
        <div class="white-center-area">
            <div class="white-form-box">
                <div class="welcome-header-group">
                    <h2 class="welcome-heading">TRACK DOCUMENT</h2>
                    <p class="welcome-subheading">
                        Enter your tracking number or scan QR code to check your document status without logging in.
                    </p>
                </div>

                @if(session('error') || session('status') || session('warning') || $errors->any())
                    <div class="clean-alert-error" role="alert">
                        <svg class="alert-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <div class="alert-msg">
                            @if(session('error'))
                                <span>{{ session('error') }}</span>
                            @elseif(session('warning'))
                                <span>{{ session('warning') }}</span>
                            @elseif(session('status'))
                                <span>{{ session('status') }}</span>
                            @elseif($errors->any())
                                <span>{{ $errors->first() }}</span>
                            @endif
                        </div>
                    </div>
                @endif

                <form id="track-form" class="track-form-box">
                    <div class="track-input-wrapper">
                        <svg class="track-input-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input wire:model.live.debounce.250ms="trackingNumber" id="tracking-input" type="text" placeholder="Enter tracking number" class="track-input-field" required autocomplete="off">
                    </div>

                    <div class="track-btn-row">
                        <button type="button" id="open-public-scanner-btn" class="track-scan-btn" title="Scan QR Code">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                            </svg>
                            <span>Scan QR</span>
                        </button>

                        <button type="submit" class="track-submit-btn" wire:loading.attr="disabled">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" wire:loading.remove wire:target="track">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <span wire:loading.remove wire:target="track">Track Document</span>
                            <span wire:loading wire:target="track">Searching…</span>
                        </button>
                    </div>

                    <div id="status_indicator" wire:ignore>
                        <div class="si-phase"></div>
                        <div class="si-message"></div>
                    </div>

                    @error('trackingNumber')
                        <span class="form-error">{{ $message }}</span>
                    @enderror
                </form>

                <div class="bottom-support-info">
                    <p class="unit-text">Records and Freedom of Information Unit (RFIU)</p>
                    <p class="college-text">Camarines Sur Polytechnic Colleges</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Public QR Scanner Modal -->
    <div id="public-scanner-modal" class="scanner-backdrop" style="display: none;" wire:ignore>
        <div class="scanner-modal-card">
            <div class="scanner-modal-header">
                <div>
                    <h3 class="scanner-modal-title">Scan Document QR Code</h3>
                    <p class="scanner-modal-desc">Point your camera at the QR code or upload an image file</p>
                </div>
                <button type="button" id="close-public-scanner-btn" class="scanner-modal-close" aria-label="Close modal">&times;</button>
            </div>

            <div class="scanner-tabs">
                <button type="button" id="tab-camera-btn" class="scanner-tab-btn active">Camera Scan</button>
                <button type="button" id="tab-file-btn" class="scanner-tab-btn">Upload Image</button>
            </div>

            <!-- Camera Viewport Panel -->
            <div id="scanner-camera-panel" class="scanner-panel">
                <div class="scanner-viewport-wrapper">
                    <div id="public-qr-preview"></div>
                    <div id="public-camera-placeholder" class="scanner-placeholder">
                        <div class="scanner-loading-spinner"></div>
                        <span>Initializing Camera Feed...</span>
                    </div>
                </div>
                <div class="scanner-camera-controls">
                    <button type="button" id="scanner-switch-camera-btn" class="scanner-secondary-btn" style="display: none;">
                        Switch Camera
                    </button>
                    <span id="scanner-camera-status" class="scanner-camera-status">Position QR code inside the frame</span>
                </div>
            </div>

            <!-- Image File Upload Panel -->
            <div id="scanner-file-panel" class="scanner-panel" style="display: none;">
                <label for="public-qr-file-input" class="scanner-file-dropzone" id="scanner-dropzone">
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    <span class="dropzone-text">Click to choose or drag & drop QR code image</span>
                    <span class="dropzone-hint">Supports PNG, JPG, JPEG, WEBP, BMP</span>
                    <input type="file" id="public-qr-file-input" accept="image/*" style="display: none;">
                </label>
                <div id="scanner-file-status" class="scanner-file-status" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

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
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function() {
    let publicHtml5QrCode = null;
    let availableCameras = [];
    let currentCameraIndex = 0;

    function getModal() { return document.getElementById('public-scanner-modal'); }
    function getInput() { return document.getElementById('tracking-input'); }
    function getForm() { return document.getElementById('track-form'); }

    async function stopPublicScanner() {
        if (publicHtml5QrCode) {
            try {
                if (publicHtml5QrCode.isScanning) {
                    await publicHtml5QrCode.stop();
                }
                publicHtml5QrCode.clear();
            } catch(e) {}
            publicHtml5QrCode = null;
        }
    }

    async function openScannerModal() {
        const modal = getModal();
        if (!modal) return;
        modal.style.display = 'flex';
        switchTab('camera');
    }

    async function closeScannerModal() {
        const modal = getModal();
        if (modal) modal.style.display = 'none';
        await stopPublicScanner();
        resetFileStatus();
    }

    function switchTab(tab) {
        const camBtn = document.getElementById('tab-camera-btn');
        const fileBtn = document.getElementById('tab-file-btn');
        const camPanel = document.getElementById('scanner-camera-panel');
        const filePanel = document.getElementById('scanner-file-panel');

        if (tab === 'camera') {
            if (camBtn) camBtn.classList.add('active');
            if (fileBtn) fileBtn.classList.remove('active');
            if (camPanel) camPanel.style.display = '';
            if (filePanel) filePanel.style.display = 'none';
            startCamera();
        } else {
            if (fileBtn) fileBtn.classList.add('active');
            if (camBtn) camBtn.classList.remove('active');
            if (filePanel) filePanel.style.display = '';
            if (camPanel) camPanel.style.display = 'none';
            stopPublicScanner();
        }
    }

    async function startCamera(cameraId = null) {
        const placeholder = document.getElementById('public-camera-placeholder');
        const statusEl = document.getElementById('scanner-camera-status');
        const switchBtn = document.getElementById('scanner-switch-camera-btn');

        if (placeholder) {
            placeholder.style.display = 'flex';
            placeholder.innerHTML = '<div class="scanner-loading-spinner"></div><span>Starting camera feed...</span>';
        }
        if (statusEl) statusEl.textContent = 'Position QR code inside the frame';

        await stopPublicScanner();

        if (typeof Html5Qrcode === 'undefined') {
            if (placeholder) placeholder.innerHTML = '⚠️ Scanner library loading... Please check your network connection.';
            return;
        }

        try {
            publicHtml5QrCode = new Html5Qrcode("public-qr-preview");

            // Enumerate cameras if not already done
            if (availableCameras.length === 0) {
                try {
                    const devices = await Html5Qrcode.getCameras();
                    if (devices && devices.length > 0) {
                        availableCameras = devices;
                    }
                } catch(e) {}
            }

            if (switchBtn) {
                switchBtn.style.display = availableCameras.length > 1 ? 'inline-block' : 'none';
            }

            const qrConfig = {
                fps: 20,
                qrbox: function(viewfinderWidth, viewfinderHeight) {
                    const minDimension = Math.min(viewfinderWidth, viewfinderHeight);
                    let boxSize = Math.floor(minDimension * 0.75);
                    if (boxSize < 200 && minDimension >= 200) boxSize = 200;
                    return { width: boxSize, height: boxSize };
                }
            };

            const cameraConfig = cameraId 
                ? { deviceId: { exact: cameraId } } 
                : { facingMode: "environment" };

            await publicHtml5QrCode.start(
                cameraConfig,
                qrConfig,
                (decodedText) => {
                    handleSuccessfulScan(decodedText);
                },
                () => {}
            );

            if (placeholder) placeholder.style.display = 'none';
        } catch (err) {
            if (placeholder) {
                placeholder.style.display = 'flex';
                placeholder.innerHTML = '⚠️ Camera access unavailable or permission denied.<br><small style="margin-top:6px;opacity:0.8;">You can switch to the "Upload Image" tab to scan a QR image.</small>';
            }
            if (statusEl) statusEl.textContent = 'Camera unavailable';
        }
    }

    async function switchCamera() {
        if (availableCameras.length <= 1) return;
        currentCameraIndex = (currentCameraIndex + 1) % availableCameras.length;
        const targetCamera = availableCameras[currentCameraIndex];
        await startCamera(targetCamera.id);
    }

    function handleSuccessfulScan(decodedText) {
        const cleaned = decodedText ? decodedText.trim() : '';
        if (!cleaned) return;

        const input = getInput();
        if (input) {
            input.value = cleaned;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Update Livewire model directly
        if (typeof @this !== 'undefined' && @this.set) {
            @this.set('trackingNumber', cleaned);
        } else {
            let livewireRoot = document.querySelector('.livewire-root');
            let component = (typeof Livewire !== 'undefined' && livewireRoot) 
                ? Livewire.find(livewireRoot.getAttribute('wire:id')) 
                : null;
            if (component) {
                component.set('trackingNumber', cleaned);
            }
        }

        closeScannerModal();

        // Automatically trigger full tracking validation pipeline
        setTimeout(() => {
            const form = getForm();
            if (form) {
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        }, 150);
    }

    function setFileStatus(msg, type) {
        const el = document.getElementById('scanner-file-status');
        if (!el) return;
        el.className = 'scanner-file-status ' + (type || '');
        el.textContent = msg;
        el.style.display = 'block';
    }

    function resetFileStatus() {
        const el = document.getElementById('scanner-file-status');
        if (el) {
            el.style.display = 'none';
            el.textContent = '';
        }
        const fileInput = document.getElementById('public-qr-file-input');
        if (fileInput) fileInput.value = '';
    }

    async function handleFileScan(file) {
        if (!file || !file.type.startsWith('image/')) {
            setFileStatus('Please select a valid image file (PNG, JPG, WEBP).', 'error');
            return;
        }

        setFileStatus('Scanning image for QR code...', '');

        if (typeof Html5Qrcode === 'undefined') {
            setFileStatus('Scanner library not loaded yet.', 'error');
            return;
        }

        try {
            const tempScanner = new Html5Qrcode("public-qr-preview");
            const decodedText = await tempScanner.scanFile(file, true);
            setFileStatus('QR Code detected! Loading document...', 'success');
            setTimeout(() => {
                handleSuccessfulScan(decodedText);
            }, 300);
        } catch (err) {
            setFileStatus('No QR code found in this image. Please try another image or use the camera.', 'error');
        }
    }

    function setupPublicScanner() {
        const openBtn = document.getElementById('open-public-scanner-btn');
        const closeBtn = document.getElementById('close-public-scanner-btn');
        const modal = getModal();
        const tabCam = document.getElementById('tab-camera-btn');
        const tabFile = document.getElementById('tab-file-btn');
        const switchBtn = document.getElementById('scanner-switch-camera-btn');
        const fileInput = document.getElementById('public-qr-file-input');
        const dropzone = document.getElementById('scanner-dropzone');

        if (openBtn) {
            openBtn.onclick = (e) => { e.preventDefault(); openScannerModal(); };
        }
        if (closeBtn) {
            closeBtn.onclick = (e) => { e.preventDefault(); closeScannerModal(); };
        }
        if (modal) {
            modal.onclick = (e) => {
                if (e.target === modal) closeScannerModal();
            };
        }
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
                closeScannerModal();
            }
        });

        if (tabCam) tabCam.onclick = () => switchTab('camera');
        if (tabFile) tabFile.onclick = () => switchTab('file');
        if (switchBtn) switchBtn.onclick = () => switchCamera();

        if (fileInput) {
            fileInput.onchange = (e) => {
                if (e.target.files && e.target.files[0]) {
                    handleFileScan(e.target.files[0]);
                }
            };
        }

        if (dropzone) {
            ['dragenter', 'dragover'].forEach(name => {
                dropzone.addEventListener(name, (e) => {
                    e.preventDefault();
                    dropzone.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(name => {
                dropzone.addEventListener(name, (e) => {
                    e.preventDefault();
                    dropzone.classList.remove('dragover');
                });
            });
            dropzone.addEventListener('drop', (e) => {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                    handleFileScan(e.dataTransfer.files[0]);
                }
            });
        }
    }

    document.addEventListener('livewire:navigated', setupPublicScanner);
    document.addEventListener('DOMContentLoaded', setupPublicScanner);
})();
</script>
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

            let d = getDevice();

            if (status === 'not-found') {
                if (d && typeof d === 'object') {
                    const nowMs = Date.now();
                    d.document_tracked_within_10_minutes = (d.document_tracked_within_10_minutes ?? 0) + 1;
                    d.last_document_tracked_at = new Date(nowMs).toISOString();
                    if (d.document_tracked_within_10_minutes >= MAX_ATTEMPTS) {
                        d.device_blocked_until = new Date(nowMs + BLOCK_MS).toISOString();
                    }
                    saveDevice(d);
                }
                setStatus('Phase 2 — Result', 'Document Cannot Be Found. Please check your tracking number and try again.', 'error');
            } else if (status === 'found') {
                if (d) {
                    d.document_tracked_within_10_minutes = 0;
                    d.device_blocked_until = null;
                    saveDevice(d);
                }
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

            const inputEl = document.getElementById('tracking-input');
            const codeVal = inputEl ? inputEl.value.trim() : '';
            if (!codeVal) {
                if (inputEl) inputEl.focus();
                return;
            }

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
                    setStatus('Blocked', 'Your device is temporarily rate-limited (' + mins + ' min remaining). <a href="#" id="reset-device-btn" style="color:#b45309;text-decoration:underline;margin-left:6px;font-weight:700;">Reset</a>', 'blocked');
                    setTimeout(() => {
                        const rBtn = document.getElementById('reset-device-btn');
                        if (rBtn) {
                            rBtn.onclick = (ev) => {
                                ev.preventDefault();
                                d.device_blocked_until = null;
                                d.document_tracked_within_10_minutes = 0;
                                saveDevice(d);
                                resetStatus();
                            };
                        }
                    }, 50);
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

            // Block if limit reached
            if (d.document_tracked_within_10_minutes >= MAX_ATTEMPTS) {
                d.device_blocked_until = new Date(nowMs + BLOCK_MS).toISOString();
                saveDevice(d);
                setStatus('Blocked', 'Too many failed attempts. Your device has been blocked for 50 minutes. <a href="#" id="reset-device-btn" style="color:#b45309;text-decoration:underline;margin-left:6px;font-weight:700;">Reset</a>', 'blocked');
                setTimeout(() => {
                    const rBtn = document.getElementById('reset-device-btn');
                    if (rBtn) {
                        rBtn.onclick = (ev) => {
                            ev.preventDefault();
                            d.device_blocked_until = null;
                            d.document_tracked_within_10_minutes = 0;
                            saveDevice(d);
                            resetStatus();
                        };
                    }
                }, 50);
                submitting = false;
                return;
            }

            // Phase 1.4 — evaluate email domain
            setStatus('Phase 1.4', 'Evaluating access permissions...', 'checking');
            await step();
            if (d.email_used_on_verification) {
                d.is_email_not_cspc = !CSPC_PATTERN.test(d.email_used_on_verification);
                saveDevice(d);
            }

            // Phase 2 — server validation
            setStatus('Phase 2', 'Validating tracking number with the server...', 'checking');

            let comp = null;
            if (typeof @this !== 'undefined' && @this) {
                comp = @this;
            } else {
                let livewireRoot = document.querySelector('.livewire-root');
                comp = (typeof Livewire !== 'undefined' && livewireRoot)
                    ? Livewire.find(livewireRoot.getAttribute('wire:id'))
                    : null;
            }

            if (comp) {
                try {
                    await comp.set('trackingNumber', codeVal);
                    await comp.set('deviceInfoJson', JSON.stringify(d));
                    await comp.call('track');
                } catch (err) {
                    submitting = false;
                    setStatus('Phase 2 — Error', 'Validation request failed. Please check connection and try again.', 'error');
                }
            } else {
                submitting = false;
                setStatus('Phase 2 — Error', 'Connection failed. Please refresh the page.', 'error');
            }
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
window.resetTrackingDevice = function () {
    const KEY = 'rms_tracking_device';
    localStorage.removeItem(KEY);
    console.log('%c[RMS] Device tracking state reset.', 'color:#16a34a;font-weight:700;');
    return true;
};
</script>
@endpush

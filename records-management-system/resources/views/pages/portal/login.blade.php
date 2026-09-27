<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('RMS CSPC Login')] class extends Component {};
?>

@push('styles')
    <script>
        if (window.self !== window.top) {
            window.top.location.href = window.location.href;
        }
    </script>
    @vite(['resources/css/login.css'])
@endpush

<div class="salesskip-split-container" id="main-swipe-wrapper" style="--login-bg: url('{{ asset('images/background.png') }}');">
    <!-- LEFT PANEL: Campus Photo Hero with Welcome & Developers -->
    <div class="hero-blue-pane" id="pane-blue">
        <div class="hero-inner-content">
            <!-- Symmetrical top spacer matching white top bar -->
            <div class="hero-top-spacer" aria-hidden="true"></div>

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
        <!-- Top Header: Brand Name + Track Document -->
        <div class="white-top-bar">
            <div class="white-brand-header">
                <img src="{{ asset('images/cspc.webp') }}" alt="CSPC Seal" class="white-brand-seal">
                <span class="white-brand-name">RMS CSPC</span>
            </div>

            <a href="{{ route('track-document') }}" class="white-track-btn">
                <span>Track Document</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
        </div>

        <!-- Center Login Area -->
        <div class="white-center-area">
            <div class="white-form-box">
                <div class="welcome-header-group">
                    <h2 class="welcome-heading">LOGIN</h2>
                    <p class="welcome-subheading">
                        Single Sign-On enabled. Please sign in with your authorized Google account.
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

                <!-- Google SSO Button -->
                <div class="google-action-group">
                    <a href="{{ route('auth.google') }}" class="ref-google-button" id="google-sso-btn">
                        <svg class="google-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
                            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.66 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.66 48 24 48z"/>
                        </svg>
                        <span>Login with Google</span>
                    </a>
                </div>

                <div class="bottom-support-info">
                    <p class="unit-text">Records and Freedom of Information Unit (RFIU)</p>
                    <p class="college-text">Camarines Sur Polytechnic Colleges</p>
                </div>
            </div>
        </div>

        <!-- Symmetrical bottom spacer matching hero bottom copyright -->
        <div class="white-bottom-spacer" aria-hidden="true"></div>
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

    function setupPage() {
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

    document.addEventListener('DOMContentLoaded', setupPage);
    document.addEventListener('livewire:navigated', setupPage);

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
@endpush

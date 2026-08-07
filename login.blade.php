<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('RMS CSPC Login')] class extends Component
{
    public function with(): array
    {
        $allowGoogleLogin = \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'allow_google_login')->value('value') !== 'false';

        return [
            'allowGoogleLogin' => $allowGoogleLogin,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/login.css'])
    <style data-navigate-track>
        body {
            position: relative;
            background-image: url('{{ asset('images/Login.png') }}');
            background-repeat: no-repeat !important;
            background-size: cover !important;
            background-attachment: fixed !important;
            background-position: center center !important;
            min-height: 95vh !important;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100px;
            width: clamp(600px, 40vw, 750px);
            height: clamp(766px, 50vh, 820px);
            z-index: 0;
            background-image: url('{{ asset('images/Welcomebg.png') }}');
            background-repeat: no-repeat;
            background-size: 100% 100%;
            background-position: top left;
            border-radius: 0 28px 28px 0;
            border: 4px solid rgb(255, 183, 0);
            pointer-events: none;
        }

        .body-container {
            position: relative;
            z-index: 1;
        }

        @media screen and (min-width: 769px) {
            :root {
                zoom: clamp(0.72, calc(100vw / 1920), 1);
            }
        }
    </style>
@endpush

<div class="body-container">
    <div class="welcome-panel">
        <div class="welcome-inner">
            <h1 class="welcome-title">WELCOME!</h1>
            <p class="welcome-copy">to the centralized portal for the Records Management System (RMS) at Camarines Sur Polytechnic Colleges (CSPC). Developed under the leadership of the Records and Freedom of Information Unit (RFIU) in collaboration with the Information and Communications Technology Unit (ICTU), this modernized web application platform enhances institutional transparency, document traceability, and operational accountability across all campus administrative offices.</p>

            <div class="developers">
                <h3>Developers</h3>
                <ul>
                    <li>John Albert T. Lagriada</li>
                    <li>Jan Russel S. Lucena</li>
                    <li>Shanice R. Magbanua</li>
                    <li>Jeroboam T. Oliveros</li>
                    <li>Kurt Gabrielle B. Zabala</li>
                </ul>
            </div>

            <div class="welcome-footer">CSPC Records Management System</div>
        </div>
    </div>
    <div class="td-container">
        <a href="{{ route('track-document') }}" class="td-button">
            Track Document
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="24" viewBox="0 0 48 24">
                <path fill="currentColor" d="M2,11V13H40L34.5,18.5L35.92,19.92L43.84,12L35.92,4.08L34.5,5.5L40,11H2Z" />
            </svg>
        </a>
    </div>
    <div class="login-container sso-only">
        <div class="logo-container">
            <div class="logo">
                <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
            </div>
        </div>
        <div class="lo-login">LOGIN</div>
        <div class="lo-instruction">
            Single Sign-On enabled. Please sign in with your authorized Google account.
        </div>

        <div class="login-body-wrapper">
            @if(session('error'))
                <div class="login-alert-error">
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            @if($allowGoogleLogin)
            <div class="google-login-con">
                <a href="{{ route('auth.google') }}" class="google-button">
                    <svg class="google-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
                        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.66 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.66 48 24 48z"/>
                    </svg>
                    <span>Sign in with Google</span>
                </a>
            </div>
            @else
            <div class="login-alert-error">
                <span>Google Sign-In is currently disabled. Please contact system administrator.</span>
            </div>
            @endif
        </div>

        <span class="notice">RECORDS AND FREEDOM OF INFORMATION UNIT</span>
    </div>
</div>

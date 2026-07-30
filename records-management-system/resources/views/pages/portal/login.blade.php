<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('RMS CSPC Login')] class extends Component
{
    public string $username = '';

    public string $password = '';

    public function login(): void
    {
        // check authentication input
        $credentials = $this->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Find user first to log failed login if account exists
        $userObj = DB::table('account')->where('username', $this->username)->first();

        $credentials['account_active'] = true; // only allow active accounts to login
        // check if the credentials are correct
        if (Auth::attempt($credentials)) {
            session()->regenerate();

            $user = Auth::user();
            if ($user) {
                // Log Login Successful
                DB::table('security_logs')->insert([
                    'status'      => 1, // Login Successful
                    'account'     => $user->id,
                    'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
                    'time'        => now(),
                ]);

                if ($user->details) {
                    $user->details->update([
                        'is_currently_online' => true,
                        'last_online_time'    => now(),
                    ]);
                }
            }

            $this->redirect(route('portal'));
            return;
        }

        // If login failed, log the attempt (account ID is null if user doesn't exist)
        DB::table('security_logs')->insert([
            'status'      => 2, // Login Failed
            'account'     => $userObj ? $userObj->id : null,
            'user_ipaddr' => \App\Helpers\NetworkHelper::getClientIp(),
            'time'        => now(),
        ]);

        // if the credentials are incorrect, show an error message
        throw ValidationException::withMessages([
            'username' => __('auth.failed'),
        ]);
    }

    public function with(): array
    {
        $allowManualLogin = DB::table('system_settings')->where('key', 'allow_manual_login')->value('value') !== 'false';
        $allowGoogleLogin = DB::table('system_settings')->where('key', 'allow_google_login')->value('value') !== 'false';

        return [
            'allowManualLogin' => $allowManualLogin,
            'allowGoogleLogin' => $allowGoogleLogin,
            'showOrDivider'    => $allowManualLogin && $allowGoogleLogin,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/login.css'])
    <style data-navigate-track>
        body {
            background-image: url('{{ asset('images/1cw2k34d.webp') }}');
            background-repeat: no-repeat !important;
            background-size: cover !important;
            background-attachment: fixed !important;
            background-position: center !important;
            min-height: 100vh !important;
        }
    </style>
@endpush

<div class="body-container">
    <div class="td-container">
        <a href="{{ route('track-document') }}" class="td-button">
            Track Document
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="24" viewBox="0 0 48 24">
                <path fill="currentColor" d="M2,11V13H40L34.5,18.5L35.92,19.92L43.84,12L35.92,4.08L34.5,5.5L40,11H2Z" />
            </svg>
        </a>
    </div>
    <div class="header-container">
        <div class="institute">CSPC</div>
        <div class="system">Records Management System</div>
    </div>
    <div class="login-container">
        <div class="logo-container">
            <div class="logo">
                <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
            </div>
        </div>
        <div class="lo-login">LOGIN</div>
        <div class="lo-instruction">Please Enter your login details to login your account</div>

        @if(session('error'))
            <div class="login-alert-error">
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if($allowManualLogin)
        <form wire:submit="login" method="post" action="{{ route('login') }}" class="lo-form">
            <div class="form-group">
                <div class="icon-usr">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z" />
                    </svg>
                </div>
                <input wire:model="username" autocomplete="username" type="text" id="username" name="username" placeholder="Username" required>
            </div>
            @error('username')
                <span class="form-error">{{ $message }}</span>
            @enderror
            <div class="form-group" x-data="{ show: false }">
                <div class="icon-psk">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M12,17A2,2 0 0,0 14,15C14,13.89 13.11,13 12,13A2,2 0 0,0 10,15A2,2 0 0,0 12,17M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V10C4,8.89 4.9,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z" />
                    </svg>
                </div>
                <input wire:model="password" autocomplete="current-password" :type="show ? 'text' : 'password'" id="password" name="password" placeholder="Password" required>
                <button type="button" class="toggle-password" @click="show = !show" :title="show ? 'Hide password' : 'Show password'" aria-label="Toggle password visibility">
                    <template x-if="!show">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </template>
                    <template x-if="show">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                            <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                            <path d="M6.61 6.61A13.52 13.52 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                            <line x1="2" y1="2" x2="22" y2="22"/>
                        </svg>
                    </template>
                </button>
            </div>
            @error('password')
                <span class="form-error">{{ $message }}</span>
            @enderror
            <button class="la-button" type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">LOGIN</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </button>
        </form>
        @endif

        @if($showOrDivider)
        <div class="or-divider">
            <span>OR</span>
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
        @endif

        <span class="notice">RECORDS AND FREEDOM OF INFORMATION UNIT</span>
    </div>
</div>

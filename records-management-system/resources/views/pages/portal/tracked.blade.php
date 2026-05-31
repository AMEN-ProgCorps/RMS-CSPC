<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
/*
*   since this page is only continuation of track-document while being in the same url, it will revolve around this phases like track-document:
*   Phase 1: after going throught the verification on track-document, the url value will be passed on this page eg /track-document
*/


new #[Layout('layouts.portal')] #[Title('Track Document — Results')] class extends Component
{
    #[Url(as: 'number')]
    public string $trackingNumber = '';
    public string $email = '';
    public string $documentPassword = '';
    public string $emailStatus = 'Initializing Google sign-in...';
    public bool $emailReadonly = true;
    public bool $showPasswordStep = false;
    public bool $showDocumentData = false;
    public function mount(): void
    {
        if ($this->trackingNumber === '') {
            $this->redirect(route('track-document'), navigate: true);
        }
    }

    public function verifyEmail(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
        ]);

        // TODO: check institute domain vs external — toggle password step
        $this->showPasswordStep = true;
    }

    public function submitPassword(): void
    {
        $this->validate([
            'documentPassword' => ['required', 'string'],
        ]);

        // TODO: validate document password
        $this->showDocumentData = true;
    }
};
?>

@push('styles')
@endpush

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
        <div class="td-items">
            <div class="doc-ifs">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M8,12V14H16V12H8M8,16V18H13V16H8Z" />
                </svg>
                <span>Document </span>
                <span>Found</span>
            </div>
            <div class="doc-tracking-number">
                <span>{{ $trackingNumber }}</span>
            </div>
            @if (! $showPasswordStep && ! $showDocumentData)
                <div id="doc-verification" class="data-containers" wire:ignore.self>
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
                    <div class="doc-status" style="background-color: #33A04B">
                        <div class="status-box">
                            <span>Ongoing</span>
                        </div>
                    </div>
                    <div class="doc-details">
                        <div class="ddc-item">
                            <div class="ddc-question">Document Type</div>
                            <div class="ddc-answer">{doc_type}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Date Received</div>
                            <div class="ddc-answer">{Month-Date-Year}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Sender</div>
                            <div class="ddc-answer">{Office}</div>
                        </div>
                        <div class="ddc-item">
                            <div class="ddc-question">Current Location</div>
                            <div class="ddc-answer">{current_office}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>

@push('scripts')
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        document.addEventListener('livewire:navigated', initGoogleEmail);
        document.addEventListener('DOMContentLoaded', initGoogleEmail);

        function initGoogleEmail() {
            const emailInput = document.getElementById('email-input');
            const statusText = document.getElementById('email-status');

            if (!emailInput || !statusText) {
                return;
            }

            function setEmail(value) {
                if (value && value.includes('@')) {
                    @this.set('email', value);
                    emailInput.placeholder = '';
                    @this.set('emailStatus', 'Email loaded from Google account.');
                }
            }

            if (typeof google === 'undefined' || !google.accounts) {
                setTimeout(function () {
                    if (!emailInput.value) {
                        const stored = window.localStorage.getItem('rms_email_autofill');
                        if (stored) {
                            setEmail(stored);
                        } else {
                            @this.set('emailStatus', 'Please sign in with Google or enter email manually.');
                            @this.set('emailReadonly', false);
                        }
                    }
                }, 3000);
                return;
            }

            google.accounts.id.initialize({
                client_id: '{{ config('services.google.client_id') }}',
                callback: function (response) {
                    const payload = decodeJwtResponse(response.credential);
                    setEmail(payload.email);
                },
                auto_select: true,
                cancel_on_tap_outside: false,
            });

            google.accounts.id.prompt();
        }

        function decodeJwtResponse(token) {
            const base64Url = token.split('.')[1];
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            const jsonPayload = decodeURIComponent(atob(base64).split('').map(function (c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(jsonPayload);
        }
    </script>
@endpush

<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('RMS CSPC — Portal')] class extends Component
{
    public string $userName = 'User';

    public function logout(): void
    {
        // TODO: clear session / auth when implemented
        $this->redirect(route('login'), navigate: true);
    }
};
?>

@push('styles')
    @vite('resources/css/accesspoint.css')
    <style>
        body {
            background-image: url('{{ asset('images/1cw2k34d.webp') }}');
        }
    </style>
@endpush

<header>
    <span class="office-name">Records and Freedom of Information Office</span>
</header>
<section>
    <div class="logout-con">
        <button type="button" title="Logout" class="logout" wire:click="logout">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <polyline points="16 17 21 12 16 7" /><line x1="21" y1="12" x2="9" y2="12" />
            </svg>
        </button>
    </div>
    <div class="portal_header">
        <div class="ico_con">
            <img class="ico" src="{{ asset('images/cspc.png') }}" alt="CSPC">
        </div>
        <span>Welcome, {{ $userName }}</span>
    </div>
    <div class="systems-container">
        <a href="{{ route('profile') }}" wire:navigate class="system-con" id="profile">
            <div class="display-box">
                <span>Profile</span>
            </div>
        </a>
        <a href="{{ route('dts') }}" wire:navigate class="system-con" id="dts">
            <div class="display-box">
                <span>Document Tracking System</span>
            </div>
        </a>
    </div>
</section>
<footer>
    <div class="copy-right">Copyright 2026. All Rights Reserved.</div>
</footer>

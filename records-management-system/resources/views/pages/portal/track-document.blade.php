<?php

use App\Models\TrackingDevice;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Track Document')] class extends Component
{
    public $isProcessing = false;
    public $errorMessage = null;
}
?>

@push('styles')
    @vite(['resources/css/td.css'])
@endpush
@if ($isProcessing)
    <div class="track-processing">
        <div class="track-processing__spinner" aria-hidden="true"></div>
        <p>Preparing your device and loading tracking details…</p>
        @if ($errorMessage)
            <p class="track-processing__error">{{ $errorMessage }}</p>
        @endif
    </div>
@endif
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
        <form wire:submit="track" class="td-search">
            <div class="search-container">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <path fill="currentColor" d="M9.5,3A6.5,6.5,0,1,0,16,9.5,6.51,6.51,0,0,0,9.5,3Zm0,11A4.5,4.5,0,1,1,14,9.5,4.51,4.51,0,0,1,9.5,14ZM20.71,19.29l-3.4-3.39a1,1,0,1,0-1.42,1.42l3.4,3.39a1,1,0,0,0,1.42-1.42Z" />
                </svg>
                <input wire:model="trackingNumber" type="text" placeholder="Enter Tracking Number" @disabled($isProcessing) required>
            </div>
            @error('trackingNumber')
                <span class="form-error">{{ $message }}</span>
            @enderror
            <button type="submit" class="search-btn" wire:loading.attr="disabled" @disabled($isProcessing)>
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
    
</script>
@endpush

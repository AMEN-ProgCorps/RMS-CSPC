<?php

use App\Models\TrackingDevice;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Track Document')] class extends Component
{
    public string $trackingNumber = '';

    public bool $isProcessing = false;

    public ?string $errorMessage = null;

    public function track(): void
    {
        $this->validate([
            'trackingNumber' => ['required', 'string'],
        ]);

        $this->trackingNumber = trim($this->trackingNumber);
        $this->isProcessing = true;
        $this->errorMessage = null;

        $this->dispatch('device-check-and-redirect');
    }

    public function registerDevice(string $duid, string $dateCreated): void
    {
        validator([
            'duid' => $duid,
            'dateCreated' => $dateCreated,
        ], [
            'duid' => ['required', 'uuid'],
            'dateCreated' => ['required', 'date'],
        ])->validate();

        TrackingDevice::firstOrCreate(
            ['duid' => $duid],
            ['date_created' => $dateCreated],
        );
    }

    public function proceedToTracked(): void
    {
        $this->redirect(
            route('tracked', ['number' => $this->trackingNumber]),
            navigate: true,
        );
    }
};
?>

@push('styles')
    @vite('resources/css/td.css')
    <style>
        .track-processing {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            background: rgba(255, 255, 255, 0.92);
            text-align: center;
        }

        .track-processing__spinner {
            width: 2.5rem;
            height: 2.5rem;
            border: 3px solid rgba(65, 144, 187, 0.25);
            border-top-color: #4190bb;
            border-radius: 50%;
            animation: track-processing-spin 0.8s linear infinite;
        }

        @keyframes track-processing-spin {
            to { transform: rotate(360deg); }
        }

        .track-processing__error {
            color: #b42318;
        }
    </style>
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

<header>
    <div class="logo">
        <img src="{{ asset('images/cspc.png') }}" alt="CSPC Logo">
    </div>
    <span>Records and Freedom of Information Office</span>
</header>
<section>
    <div class="login">
        <a href="{{ route('login') }}" wire:navigate class="log">
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

@push('scripts')
<script>
    const STORAGE_KEY = 'rms_device_uuid';

    function readStoredDevice() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                return null;
            }

            const parsed = JSON.parse(raw);
            if (parsed?.DUID && parsed?.DateCreated) {
                return parsed;
            }
        } catch (e) {
            localStorage.removeItem(STORAGE_KEY);
        }

        return null;
    }

    function writeStoredDevice(duid, dateCreated) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            DUID: duid,
            DateCreated: dateCreated,
        }));
    }

    function generateDevice() {
        return {
            DUID: crypto.randomUUID(),
            DateCreated: new Date().toISOString().slice(0, 10),
        };
    }

    async function ensureDeviceAndProceed() {
        try {
            let device = readStoredDevice();

            if (!device) {
                device = generateDevice();
                writeStoredDevice(device.DUID, device.DateCreated);
            }

            await @this.registerDevice(device.DUID, device.DateCreated);
            await @this.proceedToTracked();
        } catch (e) {
            @this.set('isProcessing', false);
            @this.set('errorMessage', 'Unable to register this device. Please try again.');
            console.error(e);
        }
    }

    document.addEventListener('livewire:init', () => {
        Livewire.on('device-check-and-redirect', () => {
            ensureDeviceAndProceed();
        });
    });
</script>
@endpush

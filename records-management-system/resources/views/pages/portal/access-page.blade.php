<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('RMS CSPC Portal')] class extends Component
{
    public string $userNameDisplay = '';

    public ?int $currentRoleId = null;
    public array $currentPermissions = [];

    public function mount(): mixed
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();
        if ($user && $user->details) {
            $this->userNameDisplay = trim($user->details->first_name . ' ' . $user->details->last_name)
                ?: $user->username;
        }

        $this->checkRoleUpdate();

        return null;
    }

    public function checkRoleUpdate()
    {
        $user = Auth::user();
        if (!$user) return;
        $user = $user->fresh();
        
        $perms = $user->permissions;
        $permValues = $perms ? [
            $perms->is_sadm, $perms->can_access_dts, $perms->can_access_rdp, $perms->can_access_dcs,
            $perms->can_dts_modify_docflow, $perms->can_sadm_modify_accountlist, $perms->can_sadm_modify_pass,
            $perms->can_sadm_modify_account, $perms->can_dts_view_all_list, $perms->can_dts_view_all_archive,
            $perms->can_dts_view_all_current_trans, $perms->can_dts_create_own_flow
        ] : [];

        if ($this->currentRoleId === null) {
            $this->currentRoleId = $user->account_role;
            $this->currentPermissions = $permValues;
            return;
        }

        if ($user->account_role !== $this->currentRoleId || $permValues !== $this->currentPermissions) {
            $this->js('window.location.reload();');
        }
    }

    /**
     * Handles manual session destruction.
     */
    public function logout(): void
    {
        $user = Auth::user();
        if ($user) {
            \Illuminate\Support\Facades\DB::table('account_details')
                ->where('account_id', $user->id)
                ->update([
                    'is_currently_online' => false,
                    'last_online_time'    => now(),
                ]);
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect(route('login'));
    }

    public function with(): array
    {
        $activeSubsystems = \DB::table('subsystems')
            ->where('is_active', true)
            ->pluck('subsystem_name')
            ->toArray();

        $user = Auth::user();
        $perms = $user?->permissions;

        $dtsActive = in_array('Document Tracking System', $activeSubsystems);
        $rdpActive = in_array('Records Disposition Program', $activeSubsystems);
        $adminActive = in_array('Admin Console', $activeSubsystems);
        $chatifyActive = in_array('Chatify', $activeSubsystems);

        // Personal / Left-side subsystems
        $personalItems = [];
        $personalItems[] = [
            'id' => 'profile',
            'route' => route('profile'),
            'label' => 'Profile',
            'target' => '_self',
            'is_desktop_only' => true,
        ];
        if ($chatifyActive) {
            $personalItems[] = [
                'id' => 'chatify',
                'route' => route('open-chat'),
                'label' => 'Chatify',
                'target' => '_blank',
                'is_desktop_only' => false,
            ];
        }
        if ($perms?->is_sadm && $adminActive) {
            $personalItems[] = [
                'id' => 'admin',
                'route' => '/admin/console/',
                'label' => 'Admin Console',
                'target' => '_self',
                'is_desktop_only' => true,
            ];
        }

        // Main / Right-side subsystems
        $mainItems = [];
        if (($perms?->is_sadm || $perms?->can_access_dts) && $dtsActive) {
            $mainItems[] = [
                'id' => 'dts',
                'route' => route('dts'),
                'label' => 'Document Tracking System',
                'target' => '_self',
                'is_desktop_only' => true,
            ];
        }
        if (($perms?->is_sadm || $perms?->can_access_rdp) && $rdpActive) {
            $mainItems[] = [
                'id' => 'rdp',
                'route' => route('rdp'),
                'label' => 'Records Disposition Program',
                'target' => '_self',
                'is_desktop_only' => true,
            ];
        }

        // Dynamic subsystems in DB
        $knownNames = ['Document Tracking System', 'Records Disposition Program', 'Admin Console', 'Chatify', 'Profile Manager'];
        $extraSubsystems = \DB::table('subsystems')
            ->where('is_active', true)
            ->whereNotIn('subsystem_name', $knownNames)
            ->get();

        foreach ($extraSubsystems as $sub) {
            $mainItems[] = [
                'id' => \Str::slug($sub->subsystem_name),
                'route' => $sub->url ?? '#',
                'label' => $sub->subsystem_name,
                'target' => '_self',
                'is_desktop_only' => true,
            ];
        }

        $desktopCount = count($personalItems) + count($mainItems);

        if ($desktopCount <= 3) {
            $containerClass = 'cols-1';
        } elseif ($desktopCount <= 6) {
            $containerClass = 'cols-2';
        } else {
            $containerClass = 'cols-3';
        }

        // Interleave items for grid column pairing (Main Subsystems on Left, Personal/Admin on Right)
        $desktopItems = [];
        if ($containerClass === 'cols-2') {
            $pCount = count($personalItems);
            $mCount = count($mainItems);
            $maxRows = max($pCount, $mCount, (int)ceil($desktopCount / 2));
            for ($r = 0; $r < $maxRows; $r++) {
                if (isset($mainItems[$r])) {
                    $desktopItems[] = $mainItems[$r];
                }
                if (isset($personalItems[$r])) {
                    $desktopItems[] = $personalItems[$r];
                }
            }
            $remainingMain = array_slice($mainItems, $maxRows);
            $remainingPersonal = array_slice($personalItems, $maxRows);
            $desktopItems = array_merge($desktopItems, $remainingMain, $remainingPersonal);
        } elseif ($containerClass === 'cols-3') {
            $pCount = count($personalItems);
            $mCount = count($mainItems);
            $pIdx = 0;
            $mIdx = 0;
            while ($pIdx < $pCount || $mIdx < $mCount) {
                // Col 1 (Left): Main subsystem first
                if ($mIdx < $mCount) {
                    $desktopItems[] = $mainItems[$mIdx++];
                } elseif ($pIdx < $pCount) {
                    $desktopItems[] = $personalItems[$pIdx++];
                }
                // Col 2 (Middle): Main subsystem next
                if ($mIdx < $mCount) {
                    $desktopItems[] = $mainItems[$mIdx++];
                } elseif ($pIdx < $pCount) {
                    $desktopItems[] = $personalItems[$pIdx++];
                }
                // Col 3 (Right): Personal item
                if ($pIdx < $pCount) {
                    $desktopItems[] = $personalItems[$pIdx++];
                } elseif ($mIdx < $mCount) {
                    $desktopItems[] = $mainItems[$mIdx++];
                }
            }
        } else {
            $desktopItems = array_merge($mainItems, $personalItems);
        }

        return [
            'desktopItems' => $desktopItems,
            'containerClass' => $containerClass,
            'canDtsScanner' => ($perms?->is_sadm || $perms?->can_dts_user_received) && $dtsActive,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/accesspoint.css'])
    <style data-navigate-track>
        body {
            background-image: url('{{ asset('images/1cw2k34d.webp') }}');
            background-repeat: no-repeat !important;
            background-size: cover !important;
            background-attachment: fixed !important;
            background-position: center !important;
            min-height: 100vh !important;
        }
        
        /* Device Segregation Classes */
        @media (max-width: 768px) {
            .desktop-only {
                display: none !important;
            }
        }
        @media (min-width: 769px) {
            .mobile-only {
                display: none !important;
            }
            :root {
                zoom: clamp(0.72, calc(100vw / 1920), 1);
            }
        }
    </style>
@endpush

<div class="livewire-root" wire:poll.5s="checkRoleUpdate">

<header>
    <span class="office-name">{{ auth()->user()?->details?->office?->office_name ?? 'Records and Freedom of Information Office' }}</span>
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
        <span>Welcome, {{ $userNameDisplay }}</span>
    </div>
    <div class="systems-container {{ $containerClass }}">
        @foreach($desktopItems as $item)
        <a href="{{ $item['route'] }}"
           @if(($item['target'] ?? '_self') === '_blank') target="_blank" rel="noopener noreferrer" @endif
           class="system-con @if($item['is_desktop_only']) desktop-only @endif"
           id="{{ $item['id'] }}">
            <div class="display-box">
                <span>{{ $item['label'] }}</span>
            </div>
        </a>
        @endforeach

        @if($canDtsScanner)
        <a href="javascript:void(0)" onclick="if(window.openScannerModal) window.openScannerModal();" class="system-con mobile-only" id="dts-scanner">
            <div class="display-box">
                <span>DTS QR Scanner</span>
            </div>
        </a>
        @endif
    </div>
</section>
<footer>
    <div class="copy-right">Copyright 2026. All Rights Reserved.</div>
</footer>

</div>

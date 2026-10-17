<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('RMS CSPC Portal')] class extends Component
{
    public string $userNameDisplay = '';

    public ?int $currentRoleId = null;
    public array $currentPermissions = [];
    public array $subsystemUnreadCounts = [];

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

        $this->loadSubsystemUnreadCounts();
        $this->checkRoleUpdate();

        return null;
    }

    #[On('rms-notification-updated')]
    #[On('rms-notification-ping')]
    public function loadSubsystemUnreadCounts(): void
    {
        $user = Auth::user();
        if (! $user) {
            $this->subsystemUnreadCounts = [];
            return;
        }

        $office = $user->details?->office;
        $officeCode = (string) ($office?->office_code ?? '');
        if ($officeCode === '') {
            $this->subsystemUnreadCounts = [];
            return;
        }

        $perms = $user->permissions;
        $allowedSubsystems = ['Profile Manager'];
        if ($perms) {
            if ($perms->is_sadm) {
                $allowedSubsystems[] = 'Document Tracking System';
                $allowedSubsystems[] = 'Records Disposition Program';
                $allowedSubsystems[] = 'Document Control System';
                $allowedSubsystems[] = 'Admin Console';
            } else {
                if ($perms->can_access_dts) {
                    $allowedSubsystems[] = 'Document Tracking System';
                }
                if ($perms->can_access_rdp) {
                    $allowedSubsystems[] = 'Records Disposition Program';
                }
                if ($perms->can_access_dcs) {
                    $allowedSubsystems[] = 'Document Control System';
                }
            }
        }
        $allowedSubsystems = \App\Helpers\RegisterQueryHelper::scopeBellSubsystemsForRequest($allowedSubsystems);

        $rawCounts = \App\Helpers\RegisterQueryHelper::countVisibleUnreadNotificationsBySubsystem(
            (int) $user->id,
            $officeCode,
            $allowedSubsystems
        );

        // Map to subsystem keys.
        // Exceptions per specification: Admin Console, Chatify, and Profile do NOT show notification badges on the portal page.
        $this->subsystemUnreadCounts = [
            'dts' => (int) ($rawCounts['Document Tracking System'] ?? 0),
            'rdp' => (int) ($rawCounts['Records Disposition Program'] ?? 0),
            'dcs' => (int) ($rawCounts['Document Control System'] ?? 0),
            'profile' => 0, // Exception: Profile does not show badge on portal
            'chatify' => 0,
            'admin' => 0,
        ];

        // Any dynamic subsystems in DB
        foreach ($rawCounts as $subName => $cnt) {
            $slug = \Illuminate\Support\Str::slug($subName);
            if (! isset($this->subsystemUnreadCounts[$slug])) {
                $this->subsystemUnreadCounts[$slug] = (int) $cnt;
            }
        }
    }

    public function checkRoleUpdate()
    {
        $user = Auth::user();
        if (!$user) return;
        $user = $user->fresh();
        
        $this->loadSubsystemUnreadCounts();

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
            \Illuminate\Support\Facades\DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details')
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
        $activeSubsystems = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
            ->where('is_active', true)
            ->pluck('subsystem_name')
            ->toArray();

        $user = Auth::user();
        $perms = $user?->permissions;

        $dtsActive = in_array('Document Tracking System', $activeSubsystems);
        $rdpActive = in_array('Records Disposition Program', $activeSubsystems);
        $dcsActive = in_array('Document Control System', $activeSubsystems);
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
            'unread_count' => 0, // Exception: Profile does not show badge on portal
        ];
        if ($chatifyActive) {
            $personalItems[] = [
                'id' => 'chatify',
                'route' => route('open-chat'),
                'label' => 'Chatify',
                'target' => '_blank',
                'is_desktop_only' => false,
                'unread_count' => 0, // Exception: Chatify has its own floating widget
            ];
        }
        if ($perms?->is_sadm && $adminActive) {
            $personalItems[] = [
                'id' => 'admin',
                'route' => '/admin/console/',
                'label' => 'Admin Console',
                'target' => '_self',
                'is_desktop_only' => true,
                'unread_count' => 0, // Exception: Admin Console has no user-action required
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
                'unread_count' => (int) ($this->subsystemUnreadCounts['dts'] ?? 0),
            ];
        }
        if (($perms?->is_sadm || $perms?->can_access_rdp) && $rdpActive) {
            $mainItems[] = [
                'id' => 'rdp',
                'route' => route('rdp'),
                'label' => 'Records Disposition Program',
                'target' => '_self',
                'is_desktop_only' => true,
                'unread_count' => (int) ($this->subsystemUnreadCounts['rdp'] ?? 0),
            ];
        }
        if (($perms?->is_sadm || $perms?->can_access_dcs) && $dcsActive) {
            $mainItems[] = [
                'id' => 'dcs',
                'route' => route('dcs'),
                'label' => 'Document Control System',
                'target' => '_self',
                'is_desktop_only' => true,
                'unread_count' => (int) ($this->subsystemUnreadCounts['dcs'] ?? 0),
            ];
        }

        // Dynamic subsystems in DB
        $knownNames = ['Document Tracking System', 'Records Disposition Program', 'Document Control System', 'Admin Console', 'Chatify', 'Profile Manager'];
        $extraSubsystems = \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')
            ->where('is_active', true)
            ->whereNotIn('subsystem_name', $knownNames)
            ->get();

        foreach ($extraSubsystems as $sub) {
            $slug = \Illuminate\Support\Str::slug($sub->subsystem_name);
            $mainItems[] = [
                'id' => $slug,
                'route' => $sub->url ?? '#',
                'label' => $sub->subsystem_name,
                'target' => '_self',
                'is_desktop_only' => true,
                'unread_count' => (int) ($this->subsystemUnreadCounts[$slug] ?? 0),
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

        .portal-server-indicator {
            font-family: 'Manrope', 'Inter', system-ui, sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9);
            background: rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            padding: 5px 18px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: -6px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
        }
        .portal-server-indicator .server-label-highlight {
            font-weight: 700;
            color: #FFD166;
            letter-spacing: 0.02em;
        }

        .portal-header-actions {
            position: absolute;
            top: 20px;
            right: 20px;
            display: inline-flex;
            align-items: center;
            gap: 14px;
            z-index: 100;
        }

        .portal-notif-btn-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .portal-notif-btn-wrapper .notif-bell-btn {
            width: 32px;
            height: 32px;
            padding: 4px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            transition: all 0.2s ease;
        }

        .portal-notif-btn-wrapper .notif-bell-btn:hover {
            background-color: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.4);
            transform: scale(1.06);
        }

        .portal-notif-btn-wrapper .notif-bell-svg {
            width: 20px;
            height: 20px;
            stroke: #ffffff;
        }

        .system-con {
            position: relative !important;
        }

        .subsystem-notif-badge {
            position: absolute;
            top: -7px;
            right: -7px;
            min-width: 22px;
            height: 22px;
            padding: 0 6px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #ffffff !important;
            font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            line-height: 20px;
            text-align: center;
            border-radius: 9999px;
            border: 2px solid #ffffff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(220, 38, 38, 0.2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            z-index: 20;
            pointer-events: none;
            box-sizing: border-box;
            user-select: none;
            animation: pulseSubsystemBadge 3s infinite ease-in-out;
        }

        @keyframes pulseSubsystemBadge {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.08);
            }
        }

        @media (max-width: 768px) {
            .portal-header-actions {
                top: 14px;
                right: 14px;
                gap: 10px;
            }
            .portal-notif-btn-wrapper,
            .notif-overlay-container {
                display: none !important;
            }
        }
    </style>
@endpush

<div class="livewire-root" wire:poll.5s="checkRoleUpdate">

<header>
    <span class="office-name">{{ auth()->user()?->details?->office?->office_name ?? 'Records and Freedom of Information Office' }}</span>
</header>
<section>
    <div class="portal-header-actions">
        <div class="portal-notif-btn-wrapper desktop-only">
            <livewire:components.notification.notifications />
        </div>
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
        @if(\App\Services\ServerManagementService::isMultiServerActive())
        <div class="portal-server-indicator">
            your currently at <span class="server-label-highlight">{{ \App\Services\ServerManagementService::getServerLabel() }}</span>
        </div>
        @endif
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
            @if(!empty($item['unread_count']) && $item['unread_count'] > 0)
                <span class="subsystem-notif-badge" title="{{ $item['unread_count'] }} unread {{ \Illuminate\Support\Str::plural('notification', $item['unread_count']) }}">
                    {{ $item['unread_count'] > 99 ? '99+' : $item['unread_count'] }}
                </span>
            @endif
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

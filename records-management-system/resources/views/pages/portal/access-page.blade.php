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
            $perms->can_sadm_modify_account, $perms->can_dts_view_all_list, $perms->can_dts_view_all_archive
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

        return [
            'dtsActive' => in_array('Document Tracking System', $activeSubsystems),
            'rdpActive' => in_array('Records Disposition Program', $activeSubsystems),
            'adminActive' => in_array('Admin Console', $activeSubsystems),
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/accesspoint.css'])
    <style data-navigate-track>
        body { background-image: url('{{ asset('images/1cw2k34d.webp') }}'); }
    </style>
@endpush

<div class="livewire-root" wire:poll.5s="checkRoleUpdate">

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
        <span>Welcome, {{ $userNameDisplay }}</span>
    </div>
    <div class="systems-container">
        <a href="{{ route('profile') }}" class="system-con" id="profile">
            <div class="display-box">
                <span>Profile</span>
            </div>
        </a>
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_dts) && $dtsActive)
        <a href="{{ route('dts') }}" class="system-con" id="dts">
            <div class="display-box">
                <span>Document Tracking System</span>
            </div>
        </a>
        @endif
        @if(auth()->user()?->permissions?->is_sadm && $adminActive)
        <a href="/admin/console/" class="system-con" id="admin">
            <div class="display-box">
                <span>Admin Console</span>
            </div>
        </a>
        @endif
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp) && $rdpActive)
        <a href="{{ route('rdp') }}" class="system-con" id="rdp">
            <div class="display-box">
                <span>Records Disposition Program</span>
            </div>
        </a>
        @endif
    </div>
</section>
<footer>
    <div class="copy-right">Copyright 2026. All Rights Reserved.</div>
</footer>

</div>

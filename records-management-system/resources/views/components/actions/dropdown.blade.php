@php
    $activeSubsystems = \DB::table('subsystems')
        ->where('is_active', true)
        ->pluck('subsystem_name')
        ->toArray();
    $dtsActive = in_array('Document Tracking System', $activeSubsystems);
    $rdpActive = in_array('Records Disposition Program', $activeSubsystems);
    $dcsActive = in_array('Document Control System', $activeSubsystems);
    $adminActive = in_array('Admin Console', $activeSubsystems);
@endphp
<div class="actions-container">
    <button class="action_button" onclick="toggleDropdown()">
        <span>ACTIONS</span>
        <img id="dropdown-icon" src="{{ asset('icons/dropdown-icon.svg') }}" alt="Dropdown Icon">
    </button>
    <div class="drop_down-container" id="dropdown">
        <span>Move To</span>
        @unless(request()->routeIs('portal'))
        <button class="subSystem" onclick="window.location.href='/portal'">
            <img src="{{ asset('icons/portal.svg') }}" alt="Portal Icon">
            <span>Portal</span>
        </button>
        @endunless
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->is_admin) && !request()->is('admin*') && $adminActive)
        <button class="subSystem" onclick="window.location.href='/admin/console/'">
            <img src="{{ asset('icons/user-admin.svg') }}" alt="Admin Console Icon">
            <span>Admin Console</span>
        </button>
        @endif
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_dts) && !request()->is('dts*') && $dtsActive)
        <button class="subSystem" onclick="window.location.href='/dts'">
            <img src="{{ asset('icons/dts.svg') }}" alt="Document Tracking Icon">
            <span>Document Tracking</span>
        </button>
        @endif
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp) && !request()->is('rdp*') && $rdpActive)
        <button class="subSystem" onclick="window.location.href='/rdp'">
            <img src="{{ asset('icons/rdp.svg') }}" alt="Records Disposition Icon">
            <span>Records Disposition</span>
        </button>
        @endif
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_dcs) && !request()->is('dcs*') && $dcsActive)
        <button class="subSystem" onclick="window.location.href='/dcs'">
            <img src="{{ asset('icons/dcs.svg') }}" alt="Document Control Icon">
            <span>Document Control</span>
        </button>
        @endif
        <hr>
        <button class="subSystem" onclick="window.location.href='/profile'">
            <img src="{{ asset('icons/profile.svg') }}" alt="Profile Icon">
            <span>Profile</span>
        </button>
        <button class="subSystem" onclick="window.open('/open-chat', '_blank', 'noopener,noreferrer')" style="position: relative; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <img src="{{ asset('icons/chat.svg') }}" alt="Chat Icon">
                <span>Chatify</span>
            </div>
            <span id="chatify-dropdown-unread-badge" style="display: none; background: #ef4444; color: #ffffff; font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; border-radius: 9px; padding: 0 5px; align-items: center; justify-content: center;">0</span>
        </button>

        <button class="subSystem subSystem--logout" onclick="window.location.href='/logout'">
            <img src="{{ asset('icons/logout.svg') }}" alt="Logout Icon">
            <span>LOGOUT</span>
        </button>
    </div>
</div>

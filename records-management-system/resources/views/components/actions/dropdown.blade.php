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
        @if(auth()->user()?->permissions?->is_sadm && !request()->is('admin*'))
        <button class="subSystem" onclick="window.location.href='/admin/console/'">
            <img src="{{ asset('icons/user-admin.svg') }}" alt="Admin Console Icon">
            <span>Admin Console</span>
        </button>
        @endif
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_dts) && !request()->is('dts*'))
        <button class="subSystem" onclick="window.location.href='/dts'">
            <img src="{{ asset('icons/dts.svg') }}" alt="Document Control Icon">
            <span>Document Tracking</span>
        </button>
        @endif
        @if((auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp) && !request()->is('rdp*'))
        <button class="subSystem" onclick="window.location.href='/rdp'">
            <img src="{{ asset('icons/rdp.svg') }}" alt="Records Disposition Icon">
            <span>Records Disposition</span>
        </button>
        @endif
        <hr>
        <button class="subSystem" onclick="window.location.href='/profile'">
            <img src="{{ asset('icons/profile.svg') }}" alt="Profile Icon">
            <span>Profile</span>
        </button>
        <button class="subSystem" onclick="window.location.href='/logout'">
            <img src="{{ asset('icons/Logout.svg') }}" alt="Logout Icon">
            <span>LOGOUT</span>
        </button>
    </div>
</div>

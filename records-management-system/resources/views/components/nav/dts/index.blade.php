@php
    $perms = auth()->user()?->permissions;
    $isSadm = $perms?->is_sadm ?? false;
    $canInternal = $isSadm || ($perms?->can_dts_use_internal ?? false);
    $canExternal = $isSadm || ($perms?->can_dts_use_external ?? false);
    $canApplication = $isSadm || ($perms?->can_dts_use_application ?? false);
    $canIssuance = $isSadm || ($perms?->can_dts_use_issuance ?? false);
    $canReceive = $isSadm || ($perms?->can_dts_user_received ?? false);
    $canAnyCreate = $canInternal || $canExternal || $canApplication || $canIssuance;
@endphp

{{-- 1. Transactions Section (Collapsible Dropdown) --}}
<div id="dts-transactions-id" class="button-section-container {{ (request()->routeIs('dts') || request()->routeIs('dts.incoming') || request()->routeIs('dts.my-transactions') || request()->routeIs('dts.received') || request()->routeIs('dts.forwarded')) ? 'show' : '' }}">
    <div class="button-container {{ (request()->routeIs('dts') || request()->routeIs('dts.incoming') || request()->routeIs('dts.my-transactions') || request()->routeIs('dts.received') || request()->routeIs('dts.forwarded')) ? 'force-active' : '' }}" onclick="showButtonSection('dts-transactions-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <mask id="mask0_1539_8759" style="mask-type:luminance" maskUnits="userSpaceOnUse" x="5" y="2" width="21" height="27">
                    <path d="M22.9167 5.41669H7.20833C6.54099 5.41669 6 5.95768 6 6.62502V25.9584C6 26.6257 6.54099 27.1667 7.20833 27.1667H22.9167C23.584 27.1667 24.125 26.6257 24.125 25.9584V6.62502C24.125 5.95768 23.584 5.41669 22.9167 5.41669Z" fill="white" stroke="white" stroke-width="2" stroke-linejoin="round"/>
                    <path d="M11.4375 3V6.625M18.6875 3V6.625" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10.2285 12.0625H19.8952M10.2285 16.8958H17.4785M10.2285 21.7292H15.0618" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </mask>
                <g mask="url(#mask0_1539_8759)">
                    <path d="M5 0H34V29H5V0Z" fill="#4F4F4F"/>
                </g>
            </svg>
        </div>
        <div class="button-label">
            <span>Transactions</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715 9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169 9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25 8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273 7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        <div class="function-button {{ request()->routeIs('dts.my-transactions') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.my-transactions') }}')">My Transactions</div>
        <div class="function-button {{ (request()->routeIs('dts.incoming') || (request()->routeIs('dts') && !request()->routeIs('dts.*'))) ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.incoming') }}')">Incoming Transactions</div>
        <div class="function-button {{ request()->routeIs('dts.received') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.received') }}')">Received Transactions</div>
        <div class="function-button {{ request()->routeIs('dts.forwarded') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.forwarded') }}')">Forwarded Transactions</div>
    </div>
</div>



{{-- 2. Scanner Section --}}
<div id="dts-scanner-id" class="button-section-container">
    <div class="button-container {{ request()->routeIs('dts.scanner') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.scanner') }}')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#4F4F4F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 7V5a2 2 0 0 1 2-2h2"/>
                <path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                <path d="M21 17v2a2 2 0 0 1-2 2h-2"/>
                <path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                <line x1="7" y1="12" x2="17" y2="12"/>
                <rect x="7" y="7" width="3" height="3" fill="#4F4F4F"/>
                <rect x="14" y="7" width="3" height="3" fill="#4F4F4F"/>
                <rect x="7" y="14" width="3" height="3" fill="#4F4F4F"/>
                <rect x="14" y="14" width="3" height="3" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Scanner</span>
        </div>
    </div>
</div>

{{-- 3. Create Transaction Section --}}
@if($canAnyCreate)
<div id="dts-create-id" class="button-section-container {{ request()->routeIs('dts.create.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('dts.create.*') ? 'force-active' : '' }}" onclick="showButtonSection('dts-create-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M14.5002 2.90002V9.42502C14.5002 10.0019 14.7293 10.5551 15.1372 10.963C15.5451 11.3709 16.0983 11.6 16.6752 11.6H23.2002V23.925C23.2002 24.5019 22.971 25.0551 22.5632 25.463C22.1553 25.8709 21.602 26.1 21.0252 26.1H14.1275C15.2105 24.7886 15.8458 23.1653 15.9408 21.4671C16.0357 19.769 15.5853 18.085 14.6552 16.661C13.7252 15.237 12.3643 14.1477 10.7712 13.5521C9.1781 12.9564 7.43633 12.8857 5.8002 13.3502V5.07502C5.8002 4.49818 6.02935 3.94496 6.43724 3.53707C6.84513 3.12918 7.39835 2.90002 7.9752 2.90002H14.5002ZM15.9502 3.26252V9.42502C15.9502 9.61731 16.0266 9.80171 16.1625 9.93768C16.2985 10.0736 16.4829 10.15 16.6752 10.15H22.8377L15.9502 3.26252ZM14.5002 21.025C14.5002 22.7556 13.8127 24.4152 12.5891 25.6389C11.3654 26.8626 9.70573 27.55 7.9752 27.55C6.24466 27.55 4.585 26.8626 3.36132 25.6389C2.13765 24.4152 1.4502 22.7556 1.4502 21.025C1.4502 19.2945 2.13765 17.6348 3.36132 16.4112C4.585 15.1875 6.24466 14.5 7.9752 14.5C9.70573 14.5 11.3654 15.1875 12.5891 16.4112C13.8127 17.6348 14.5002 19.2945 14.5002 21.025ZM8.7002 18.125C8.7002 17.9327 8.62381 17.7483 8.48785 17.6124C8.35188 17.4764 8.16748 17.4 7.9752 17.4C7.78291 17.4 7.59851 17.4764 7.46254 17.6124C7.32658 17.7483 7.2502 17.9327 7.2502 18.125V20.3H5.0752C4.88291 20.3 4.69851 20.3764 4.56254 20.5124C4.42658 20.6483 4.3502 20.8327 4.3502 21.025C4.3502 21.2173 4.42658 21.4017 4.56254 21.5377C4.69851 21.6736 4.88291 21.75 5.0752 21.75H7.2502V23.925C7.2502 24.1173 7.32658 24.3017 7.46254 24.4377C7.59851 24.5736 7.78291 24.65 7.9752 24.65C8.16748 24.65 8.35188 24.5736 8.48785 24.4377C8.62381 24.3017 8.7002 24.1173 8.7002 23.925V21.75H10.8752C11.0675 21.75 11.2519 21.6736 11.3878 21.5377C11.5238 21.4017 11.6002 21.2173 11.6002 21.025C11.6002 20.8327 11.5238 20.6483 11.3878 20.5124C11.2519 20.3764 11.0675 20.3 10.8752 20.3H8.7002V18.125Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Create Transaction</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715 9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169 9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25 8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273 7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        @if($canInternal)
            <div class="function-button {{ request()->routeIs('dts.create.internal') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.create.internal') }}')">Internal Transaction</div>
        @endif
        @if($canExternal)
            <div class="function-button {{ request()->routeIs('dts.create.external') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.create.external') }}')">External Transaction</div>
        @endif
        @if($canApplication)
            <div class="function-button {{ request()->routeIs('dts.create.application-letters') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.create.application-letters') }}')">Application Letters</div>
        @endif
        @if($canIssuance)
            <div class="function-button {{ request()->routeIs('dts.create.issuances') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.create.issuances') }}')">Issuances</div>
        @endif
    </div>
</div>
@endif

{{-- 4. List of Transactions Section --}}
@if($canAnyCreate)
<div id="dts-list-id" class="button-section-container {{ request()->routeIs('dts.list.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('dts.list.*') ? 'force-active' : '' }}" onclick="showButtonSection('dts-list-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M9.66667 10.875C9.32431 10.875 9.03753 10.759 8.80634 10.527C8.57514 10.295 8.45914 10.0082 8.45834 9.66668C8.45753 9.32512 8.57353 9.03834 8.80634 8.80634C9.03914 8.57434 9.32592 8.45834 9.66667 8.45834H24.1667C24.509 8.45834 24.7962 8.57434 25.0282 8.80634C25.2602 9.03834 25.3758 9.32512 25.375 9.66668C25.3742 10.0082 25.2582 10.2954 25.027 10.5282C24.7958 10.761 24.509 10.8766 24.1667 10.875H9.66667ZM9.66667 15.7083C9.32431 15.7083 9.03753 15.5923 8.80634 15.3603C8.57514 15.1283 8.45914 14.8416 8.45834 14.5C8.45753 14.1585 8.57353 13.8717 8.80634 13.6397C9.03914 13.4077 9.32592 13.2917 9.66667 13.2917H24.1667C24.509 13.2917 24.7962 13.4077 25.0282 13.6397C25.2602 13.8717 25.3758 14.1585 25.375 14.5C25.3742 14.8416 25.2582 15.1287 25.027 15.3616C24.7958 15.5944 24.509 15.71 24.1667 15.7083H9.66667ZM9.66667 20.5417C9.32431 20.5417 9.03753 20.4257 8.80634 20.1937C8.57514 19.9617 8.45914 19.6749 8.45834 19.3333C8.45753 18.9918 8.57353 18.705 8.80634 18.473C9.03914 18.241 9.32592 18.125 9.66667 18.125H24.1667C24.509 18.125 24.7962 18.241 25.0282 18.473C25.2602 18.705 25.3758 18.9918 25.375 19.3333C25.3742 19.6749 25.2582 19.9621 25.027 20.1949C24.7958 20.4277 24.509 20.5433 24.1667 20.5417H9.66667ZM4.83334 10.875C4.49098 10.875 4.2042 10.759 3.973 10.527C3.74181 10.295 3.62581 10.0082 3.625 9.66668C3.6242 9.32512 3.7402 9.03834 3.973 8.80634C4.20581 8.57434 4.49259 8.45834 4.83334 8.45834C5.17409 8.45834 5.46127 8.57434 5.69488 8.80634C5.92849 9.03834 6.04409 9.32512 6.04167 9.66668C6.03925 10.0082 5.92325 10.2954 5.69367 10.5282C5.46409 10.761 5.17731 10.8766 4.83334 10.875ZM4.83334 15.7083C4.49098 15.7083 4.2042 15.5923 3.973 15.3603C3.74181 15.1283 3.62581 14.8416 3.625 14.5C3.6242 14.1585 3.7402 13.8717 3.973 13.6397C4.20581 13.4077 4.49259 13.2917 4.83334 13.2917C5.17409 13.2917 5.46127 13.4077 5.69488 13.6397C5.92849 13.8717 6.04409 14.1585 6.04167 14.5C6.03925 14.8416 5.92325 15.1287 5.69367 15.3616C5.46409 15.5944 5.17731 15.71 4.83334 15.7083ZM4.83334 20.5417C4.49098 20.5417 4.2042 20.4257 3.973 20.1937C3.74181 19.9617 3.62581 19.6749 3.625 19.3333C3.6242 18.9918 3.7402 18.705 3.973 18.473C4.20581 18.241 4.49259 18.125 4.83334 18.125C5.17409 18.125 5.46127 18.241 5.69488 18.473C5.92849 18.705 6.04409 18.9918 6.04167 19.3333C6.03925 19.6749 5.92325 19.9621 5.69367 20.1949C5.46409 20.4277 5.17731 20.5433 4.83334 20.5417Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>List of Transactions</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715 9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169 9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25 8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273 7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        @if($canInternal)
            <div class="function-button {{ request()->routeIs('dts.list.internal') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.list.internal') }}')">Internal Transaction</div>
        @endif
        @if($canExternal)
            <div class="function-button {{ request()->routeIs('dts.list.external') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.list.external') }}')">External Transaction</div>
        @endif
        @if($canApplication)
            <div class="function-button {{ request()->routeIs('dts.list.application-letters') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.list.application-letters') }}')">Application Letters</div>
        @endif
        @if($canIssuance)
            <div class="function-button {{ request()->routeIs('dts.list.issuances') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.list.issuances') }}')">Issuances</div>
        @endif
    </div>
</div>
@endif

{{-- 5. Transaction History Section --}}
@if($canAnyCreate)
<div id="dts-history-id" class="button-section-container {{ request()->routeIs('dts.history.*') ? 'show' : '' }}">
    <div class="button-container {{ request()->routeIs('dts.history.*') ? 'force-active' : '' }}" onclick="showButtonSection('dts-history-id')">
        <div class="button-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="29" height="29" viewBox="0 0 29 29" fill="none">
                <path d="M14.5 4.83331C9.16 4.83331 4.83334 9.15998 4.83334 14.5C4.83334 19.84 9.16 24.1666 14.5 24.1666C19.84 24.1666 24.1667 19.84 24.1667 14.5C24.1667 9.15998 19.84 4.83331 14.5 4.83331ZM14.5 22.2333C10.2307 22.2333 6.76667 18.7693 6.76667 14.5C6.76667 10.2306 10.2307 6.76665 14.5 6.76665C18.7693 6.76665 22.2333 10.2306 22.2333 14.5C22.2333 18.7693 18.7693 22.2333 14.5 22.2333ZM13.5333 9.66665H15.4667V14.5L19.3333 16.82L18.3667 18.3666L13.5333 15.4666V9.66665Z" fill="#4F4F4F"/>
            </svg>
        </div>
        <div class="button-label">
            <span>Transaction History</span>
        </div>
        <div class="show-arrow">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="11" viewBox="0 0 18 11" fill="none">
                <path d="M2.03223 9.76953L2.20898 9.59277L8.8125 2.98828L15.418 9.59375L15.5947 9.77148L15.7715 9.59375L17.0967 8.26758L17.2734 8.09082L17.0967 7.91406L9.875 0.69043H9.87402C9.73533 0.551169 9.57112 0.439765 9.38965 0.364258C9.20774 0.28857 9.01246 0.250036 8.81543 0.25C8.61847 0.25 8.42308 0.28866 8.24121 0.364258C8.05943 0.439892 7.89373 0.550835 7.75488 0.69043L0.530273 7.91406L0.353516 8.09082L2.03223 9.76953Z" fill="#646464" stroke="#646464" stroke-width="0.5"/>
            </svg>
        </div>
    </div>
    <div class="functions-container">
        @if($canInternal)
            <div class="function-button {{ request()->routeIs('dts.history.internal') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.history.internal') }}')">Internal Transaction</div>
        @endif
        @if($canExternal)
            <div class="function-button {{ request()->routeIs('dts.history.external') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.history.external') }}')">External Transaction</div>
        @endif
        @if($canApplication)
            <div class="function-button {{ request()->routeIs('dts.history.application-letters') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.history.application-letters') }}')">Application Letters</div>
        @endif
        @if($canIssuance)
            <div class="function-button {{ request()->routeIs('dts.history.issuances') ? 'force-active' : '' }}" onclick="proccedto('{{ route('dts.history.issuances') }}')">Issuances</div>
        @endif
    </div>
</div>
@endif

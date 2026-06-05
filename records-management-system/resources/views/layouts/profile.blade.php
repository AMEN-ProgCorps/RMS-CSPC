<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="{{ asset('images/cspc.webp') }}" type="image/webp">
    <script>
        function toggleDropdown() {
            const dropdownIcon = document.getElementById('dropdown-icon');
            const dropdown = document.getElementById('dropdown');
            if (dropdownIcon.classList.contains('rotate')) {
                dropdownIcon.classList.remove('rotate');
                dropdownIcon.classList.toggle('revert');
            } else {
                if (dropdownIcon.classList.contains('revert')) {
                    dropdownIcon.classList.remove('revert');
                }
                dropdownIcon.classList.add('rotate');
            }
            dropdown.classList.toggle('show');
        }
        function toggleNavProperties() {
            const navigation = document.getElementById('navigation');
            const navMainIcon = document.getElementById('nav-main-icon');
            const articleContainer = document.getElementById('article-container');
            if (navigation.classList.contains('imup')) {
                navigation.classList.remove('imup');
                navigation.classList.add('imdown');
                articleContainer.classList.remove('imdown');
                articleContainer.classList.add('imup');
                navMainIcon.src = "{{ asset('icons/toggle-nav-section.svg') }}";
            } else {
                navigation.classList.remove('imdown');
                navigation.classList.add('imup');
                articleContainer.classList.remove('imup');
                articleContainer.classList.add('imdown');
                navMainIcon.src = "{{ asset('icons/toggle-nav-default.svg') }}";
            }
        }
        function resetNavProperties() {
            const navigation = document.getElementById('navigation');
            const navMainIcon = document.getElementById('nav-main-icon');
            const articleContainer = document.getElementById('article-container');
            navigation.classList.remove('imdown');
            navigation.classList.add('imup');
            articleContainer.classList.remove('imup');
            articleContainer.classList.add('imdown');
            navMainIcon.src = "{{ asset('icons/toggle-nav-default.svg') }}";
        }
        function showButtonSection(button_target) {
            const navigation = document.getElementById('navigation');
            let pla = document.getElementById(button_target);
            if (navigation.classList.contains('imup')) {
                if (pla.classList.contains('show')) {
                    pla.classList.remove('show');
                } else {
                    pla.classList.add('show');
                }
            }
        }
        function proccedto(url) {
            window.location.href = url;
        }
    </script>
    @vite('resources/css/dashboard.css')
    <title>CSPC - Profile Manager</title>
    @stack('styles')
</head>
<body>
    <header>
        <div class="cspc-logo">
            <img class="ico" src="{{ asset('images/cspc.png') }}" alt="CSPC">
        </div>
        <div class="label-container">
            <span class="subtitle">Camarines Sur Polytechnic Colleges</span>
            <span class="title">Records Management System</span>
        </div>
        <div class="notification-container">
            {{-- notification livewire component goes here --}}
        </div>
        <span class="office_name">Records and Freedom of Information Office</span>
        <div class="actions-container">
            <button class="action_button" onclick="toggleDropdown()">
                <span>ACTIONS</span>
                <img id="dropdown-icon" src="{{ asset('icons/dropdown-icon.svg') }}" alt="Dropdown Icon">
            </button>
            <div class="drop_down-container" id="dropdown">
                <span>Move To</span>
                @if(auth()->user()?->permissions?->is_sadm)
                <button class="subSystem" onclick="window.location.href='/admin/console/'">
                    <img src="{{ asset('icons/user-admin.svg') }}" alt="Admin Console Icon">
                    <span>Admin Console</span>
                </button>
                @endif
                @if(auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_dts)
                <button class="subSystem" onclick="window.location.href='/dts'">
                    <img src="{{ asset('icons/dts.svg') }}" alt="Document Control Icon">
                    <span>Document Control</span>
                </button>
                @endif
                @if(auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_access_rdp)
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
    </header>
    <section>
        <div class="navigation imup" id="navigation">
            <div class="nav">
                <div class="nav-header-container">
                    <div class="toggle-btn" onclick="toggleNavProperties()">
                        <img id="nav-main-icon" src="{{ asset('icons/toggle-nav-section.svg') }}" alt="Toggle Icon">
                    </div>
                    <div id="nav-contexts" class="subsystem-indicator">
                        <div class="subsystem-name">Profile Manager</div>
                        <div class="subsystem-version">Version: {{ \DB::table('subsystems')->where('subsystem_name', 'Profile Manager')->value('subsystem_version') ?? 'N/A' }}</div>
                    </div>
                </div>
                <hr>
                <div class="nav-list-container" onclick="resetNavProperties()">
                    <x-nav.profile />
                </div>
                <div class="account-container">
                    <div class="account-label">
                        <span class="account-email">{{ auth()->user()?->details?->email }}</span>
                        <span class="account-office">{{ auth()->user()?->details?->office?->office_name }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div id="article-container" class="article-container imdown">
            {{ $slot }}
        </div>
    </section>
    @stack('scripts')
</body>
</html>

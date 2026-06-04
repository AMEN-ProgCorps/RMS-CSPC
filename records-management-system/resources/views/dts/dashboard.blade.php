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
            console.log('Dropdown toggled'); // Debugging log
        }
    </script>
    @vite('resources/css/dashboard.css')
    <!-- below will be the modification for the header -->
    <title>CSPC - Document Tracking System</title>
    @yield('styles')
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
            <!-- using livewire because the notifaction icon has 3 stages, and the content is dynamic -->
        </div>
        <span class="office_name">Records and Freedom of Information Office</span>
        <div class="actions-container">
            <button class="action_button" onclick="toggleDropdown()">
                <span>ACTIONS</span>
                <img id="dropdown-icon" src="{{ asset('icons/dropdown-icon.svg') }}" alt="Dropdown Icon">
            </button>
            <div class="drop_down-container" id="dropdown">
                <span>Move To</span>
                <!-- Subsystem button access will be available on the account role e.g. condition_key.modified_key if it value is true, 
                        e.g. can_access_dts value is true on the account the subsystem button will be visible, and can_access_rdp is false then this button will be hidden, and etc., 
                        also note if account role is_admin value is true then the user is an admin which means it can access all subsystem regardless of the value of the condition_key.modified_key, and an special button which is te Admin Console will be visible, 
                        but if the user is not an admin then the subsystem button will be visible based on the value of the condition_key.modified_key

                        incase a new subsystem is added in the future just add a new button here and follow the same logic for the visibility of the button based on the value of the condition_key.modified_key
                        it uses this layout
                        <button class="subSystem" onclick="window.location.href='system_url'">
                            <img src="{{ asset('icons/subsystemicon') }}" alt="subsystem_icon">            
                            <span>Subsystem Name</span>
                        </button>
                -->
                <button class="subSystem" onclick="window.location.href='/admin/console/'">
                    <img src="{{ asset('icons/user-admin.svg') }}" alt="Document Control Icon">            
                    <span>Admin Console</span>
                </button>
                <button class="subSystem" onclick="window.location.href='/dts'">
                    <img src="{{ asset('icons/dts.svg') }}" alt="Document Control Icon">            
                    <span>Document Control</span>
                </button>
                <button class="subSystem" onclick="window.location.href='/rdp'">
                    <img src="{{ asset('icons/rdp.svg') }}" alt="Records Disposition Icon">
                    <span>Records Disposition</span>
                </button>
                <!-- Logout -->
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
                    <script>
                        function toggleNavProperties() {
                            const navigation = document.getElementById('navigation');
                            const navMainIcon = document.getElementById('nav-main-icon');
                            const articleContainer = document.getElementById('article-container');
                            if(navigation.classList.contains('imup')) {
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
                    </script>
                    <div id="nav-contexts" class="subsystem-indicator">
                        <div class="subsystem-name">Document Tracking System
                            @yield('subsystem_name')
                        </div>
                        <div class="subsystem-version">Version: @yield('subsystem_version')</div>
                    </div>
                </div> 
                <hr>
                <div class="nav-list-container">
                    section
                </div>
                <div class="account-logo-container">
                    logo
                </div>
            </div>
        </div>
        <div id="article-container" class="article-container imdown">
            top
            @yield('content')
            down
        </article>
    </section>
</body>
</html>
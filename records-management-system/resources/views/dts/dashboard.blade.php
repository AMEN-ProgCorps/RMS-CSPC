<!DOCTYPE html>
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
        <span class="office_name">Records and Freedom of Information Office</span>
    </header>
    <div class="actions-container">
        <button class="action_button" onclick="toggleDropdown()">
            <span>ACTIONS</span>
            <img id="dropdown-icon" src="{{ asset('icons/dropdown-icon.svg') }}" alt="Dropdown Icon">
        </button>
        <div class="drop_down-container" id="dropdown">
            <span>Move To</span>
            <!-- Subsystem available on the account-->
            <button class="subSystem" onclick="window.location.href='/dts'">
                <img src="{{ asset('icons/dts.svg') }}" alt="Document Control Icon">            
                <span>Document Control</span>
            </button>
            <button class="subSystem" onclick="window.location.href='/rdp'">
                <img src="{{ asset('icons/rdp.svg') }}" alt="Records Disposition Icon">
                <span>Records Disposition</span>
            </button>
            <hr>
            <!-- Logout -->
            <button class="subSystem" onclick="window.location.href='/logout'">
                <img src="{{ asset('icons/Logout.svg') }}" alt="Logout Icon">
                <span>LOGOUT</span>
            </button>
        </div>
    </div>
    <section>
        <span class="section">This is section</span>
    </section>
</body>
</html>
<label for="dcs-nav-open" class="nav-backdrop" aria-hidden="true"></label>

<nav class="side-nav" id="sideNav">
    <label class="collapse-btn" for="dcs-sidebar-collapsed" id="collapseBtn">
        <div class="collapse-btn-inner">
            <div class="toggle-icon-wrap">
                <img src="{{ asset('icons/toggle-nav-default.svg') }}" alt="" class="toggle-icon default-icon">
                <img src="{{ asset('icons/toggle-nav-section.svg') }}" alt="" class="toggle-icon active-icon">
            </div>
            <span class="collapse-text">
                <span class="collapse-label">Document Control System</span>
                <span class="collapse-version">Version: {{ \DB::table('subsystems')->where('subsystem_name', 'Document Control System')->value('subsystem_version') ?? 'N/A' }}</span>
            </span>
        </div>
        <div class="collapse-indicator">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path class="chevron-path" d="M10 4L6 8L10 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
    </label>

    <div class="nav-divider"></div>

    <ul class="nav-links">
        <li data-page="dashboard" class="nav-item {{ request()->routeIs('dcs') || request()->routeIs('dcs.dashboard') ? 'active' : '' }}">
            <a href="{{ route('dcs', absolute: false) }}">
                <i class="fa-regular fa-square"></i>
                <span>Dashboard</span>
                <span class="tooltip">Dashboard</span>
            </a>
        </li>
        <li class="nav-item dropdown {{ request()->is('dcs/register*') ? 'active' : '' }}">
            <details {{ request()->is('dcs/register*') ? 'open' : '' }}>
                <summary class="dropdown-trigger">
                    <i class="fa-regular fa-pen-to-square"></i>
                    <span>Document Registration</span>
                    <i class="fas fa-caret-down arrow"></i>
                    <span class="tooltip">Document Registration</span>
                </summary>
                <ul class="sub-dropdown">
                    <li>
                        <a href="{{ route('dcs.register.create', absolute: false) }}" class="{{ request()->routeIs('dcs.register.create') || request()->routeIs('dcs.register.revised') ? 'active-sub' : '' }}">Register</a>
                    </li>
                    <li>
                        <a href="{{ route('dcs.register.update', absolute: false) }}" class="{{ request()->routeIs('dcs.register.update') || request()->routeIs('dcs.register.edit') || request()->routeIs('dcs.register.history') ? 'active-sub' : '' }}">Update</a>
                    </li>
                </ul>
            </details>
        </li>
        <li class="nav-item dropdown {{ request()->is('dcs/reports*') ? 'active' : '' }}">
            <details {{ request()->is('dcs/reports*') ? 'open' : '' }}>
                <summary class="dropdown-trigger">
                    <i class="fa-regular fa-file-lines"></i>
                    <span>Generate Report</span>
                    <i class="fas fa-caret-down arrow"></i>
                    <span class="tooltip">Generate Report</span>
                </summary>
                <ul class="sub-dropdown">
                    <li><a href="{{ route('dcs.reports.masterlist', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.masterlist') ? 'active-sub' : '' }}">Masterlists</a></li>
                    <li><a href="{{ route('dcs.reports.monitoring', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.monitoring') ? 'active-sub' : '' }}">Monitoring Reports</a></li>
                    <li><a href="{{ route('dcs.reports.opcr', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.opcr') ? 'active-sub' : '' }}">OPCR Targets</a></li>
                    <li><a href="{{ route('dcs.reports.others', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.others') ? 'active-sub' : '' }}">Others</a></li>
                </ul>
            </details>
        </li>
        <li class="nav-item {{ request()->routeIs('dcs.stamping.*') || request()->routeIs('dcs.stamp.*') ? 'active' : '' }}">
            <a href="{{ route('dcs.stamping.index', absolute: false) }}">
                <i class="fa-solid fa-stamp"></i>
                <span>Stamp Document</span>
                <span class="tooltip">Stamp Document</span>
            </a>
        </li>
        <li class="nav-item {{ request()->routeIs('dcs.database.*') ? 'active' : '' }}">
            <a href="{{ route('dcs.database.index', absolute: false) }}">
                <i class="fa-solid fa-database"></i>
                <span>Database</span>
                <span class="tooltip">Database</span>
            </a>
        </li>
        <li data-page="settings" class="nav-item {{ request()->routeIs('dcs.settings.*') ? 'active' : '' }}">
            <a href="{{ route('dcs.settings.index', absolute: false) }}">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
                <span class="tooltip">Settings</span>
            </a>
        </li>
    </ul>
</nav>

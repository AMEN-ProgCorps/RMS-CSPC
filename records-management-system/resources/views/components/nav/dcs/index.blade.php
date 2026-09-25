@php
    $enableTopTabs = auth()->user()?->enableTopTabs() ?? true;
    $isLimitedDcs = \App\Helpers\RegisterQueryHelper::isLimitedDcsUser();
    $isFullDcs = \App\Helpers\RegisterQueryHelper::isFullDcsUser();
    $canRegister = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('register');
    $canReports = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('reports');
    $canReview = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('review');
    $canStamping = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('stamping');
    $canDatabase = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('database');
    $canManageFiles = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('manage_files');
    $canRandomCheck = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('random_check');
    $canSettings = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('settings');
    $canRecycleBin = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('recycle_bin');
@endphp

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
                <span class="collapse-version">Version: {{ \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems')->where('subsystem_name', 'Document Control System')->value('subsystem_version') ?? 'N/A' }}</span>
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

        @if($isLimitedDcs)
            @php
                $officeDocGroups = \App\Helpers\OfficeIntakeHelper::officeDocumentGroups(null, false);
                $activeDocType = request()->query('type', 'all');
            @endphp
            <li class="nav-item {{ request()->routeIs('dcs.office.drf.*') ? 'active' : '' }}">
                <a href="{{ route('dcs.office.drf.index', absolute: false) }}">
                    <i class="fa-regular fa-file-lines"></i>
                    <span>My DRF</span>
                    <span class="tooltip">My Document Request Forms</span>
                </a>
            </li>
            <li class="nav-item {{ request()->routeIs('dcs.office.dcn.*') ? 'active' : '' }}">
                <a href="{{ route('dcs.office.dcn.index', absolute: false) }}">
                    <i class="fa-solid fa-file-pen"></i>
                    <span>My DCN</span>
                    <span class="tooltip">My Document Change Notices</span>
                </a>
            </li>
            @if($enableTopTabs)
                <li class="nav-item {{ request()->routeIs('dcs.office.documents') ? 'active' : '' }}">
                    <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}">
                        <i class="fa-solid fa-folder-open"></i>
                        <span>Documents</span>
                        <span class="tooltip">Office Documents</span>
                    </a>
                </li>
            @else
                <li class="nav-item dropdown {{ request()->routeIs('dcs.office.documents') ? 'active' : '' }}">
                    <details {{ request()->routeIs('dcs.office.documents') ? 'open' : '' }}>
                        <summary class="dropdown-trigger">
                            <i class="fa-solid fa-folder-open"></i>
                            <span>Documents</span>
                            <i class="fas fa-caret-down arrow"></i>
                            <span class="tooltip">Office Documents</span>
                        </summary>
                        <ul class="sub-dropdown">
                            <li>
                                <a
                                    href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}"
                                    class="{{ request()->routeIs('dcs.office.documents') && $activeDocType === 'all' ? 'active-sub' : '' }}"
                                >All</a>
                            </li>
                            @foreach($officeDocGroups as $group)
                                <li>
                                    <a
                                        href="{{ route('dcs.office.documents', ['type' => $group['key']], absolute: false) }}"
                                        class="{{ request()->routeIs('dcs.office.documents') && $activeDocType === $group['key'] ? 'active-sub' : '' }}"
                                    >
                                        {{ $group['label'] }}
                                        @if($group['count'] < 1)
                                            <span class="ofi-nav-empty-hint">0</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </li>
            @endif
        @endif

        @if($isFullDcs)
            @php $canReviewIntake = \App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake(); @endphp
            @if($canRegister)
                @if($enableTopTabs)
                    <li class="nav-item {{ request()->is('dcs/register*') || request()->routeIs('dcs.requests.*') ? 'active' : '' }}">
                        <a href="{{ route('dcs.register.create', absolute: false) }}">
                            <i class="fa-regular fa-pen-to-square"></i>
                            <span>Document Registration</span>
                            <span class="tooltip">Document Registration</span>
                        </a>
                    </li>
                @else
                    <li class="nav-item dropdown {{ request()->is('dcs/register*') || request()->routeIs('dcs.requests.*') ? 'active' : '' }}">
                        <details {{ request()->is('dcs/register*') || request()->routeIs('dcs.requests.*') ? 'open' : '' }}>
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
                                    <a href="{{ route('dcs.register.drafts', absolute: false) }}" class="{{ request()->routeIs('dcs.register.drafts') ? 'active-sub' : '' }}">Drafts</a>
                                </li>
                                <li>
                                    <a href="{{ route('dcs.register.update', absolute: false) }}" class="{{ request()->routeIs('dcs.register.update') || request()->routeIs('dcs.register.edit') || request()->routeIs('dcs.register.history') ? 'active-sub' : '' }}">Update</a>
                                </li>
                                @if($canReviewIntake)
                                    <li>
                                        <a href="{{ route('dcs.requests.index', absolute: false) }}" class="{{ request()->routeIs('dcs.requests.*') ? 'active-sub' : '' }}">Request</a>
                                    </li>
                                @endif
                            </ul>
                        </details>
                    </li>
                @endif
            @elseif($canReviewIntake)
                <li class="nav-item {{ request()->routeIs('dcs.requests.*') ? 'active' : '' }}">
                    <a href="{{ route('dcs.requests.index', absolute: false) }}">
                        <i class="fa-solid fa-inbox"></i>
                        <span>Request</span>
                        <span class="tooltip">Pending office DRF &amp; DCN</span>
                    </a>
                </li>
            @endif

            @if($canReports)
                @if($enableTopTabs)
                    <li class="nav-item {{ request()->is('dcs/reports*') ? 'active' : '' }}">
                        <a href="{{ route('dcs.reports.masterlist', absolute: false) }}">
                            <i class="fa-regular fa-file-lines"></i>
                            <span>Generate Report</span>
                            <span class="tooltip">Generate Report</span>
                        </a>
                    </li>
                @else
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
                                <li><a href="{{ route('dcs.reports.syllabiTos', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.syllabiTos') ? 'active-sub' : '' }}">Syllabi &amp; TOS/Rubrics</a></li>
                                <li><a href="{{ route('dcs.reports.opcr', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.opcr') ? 'active-sub' : '' }}">OPCR Targets</a></li>
                                <li><a href="{{ route('dcs.reports.others', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.others') ? 'active-sub' : '' }}">Others</a></li>
                            </ul>
                        </details>
                    </li>
                @endif
            @endif

            @if($canReview)
                <li class="nav-item {{ request()->routeIs('dcs.review') ? 'active' : '' }}">
                    <a href="{{ route('dcs.review', absolute: false) }}">
                        <i class="fa-solid fa-code-compare"></i>
                        <span>Document Review</span>
                        <span class="tooltip">Document Review</span>
                    </a>
                </li>
            @endif

            @if($canStamping)
                <li class="nav-item {{ request()->routeIs('dcs.stamping.*') || request()->routeIs('dcs.stamp.*') ? 'active' : '' }}">
                    <a href="{{ route('dcs.stamping.index', absolute: false) }}">
                        <i class="fa-solid fa-stamp"></i>
                        <span>Stamp Document</span>
                        <span class="tooltip">Stamp Document</span>
                    </a>
                </li>
            @endif

            @if($canDatabase)
                <li class="nav-item {{ request()->routeIs('dcs.database.*') ? 'active' : '' }}">
                    <a href="{{ route('dcs.database.index', absolute: false) }}">
                        <i class="fa-solid fa-database"></i>
                        <span>Database</span>
                        <span class="tooltip">Database</span>
                    </a>
                </li>
            @endif

            @if($canRandomCheck)
                <li class="nav-item {{ request()->routeIs('dcs.random-check') ? 'active' : '' }}">
                    <a href="{{ route('dcs.random-check', absolute: false) }}">
                        <i class="fa-solid fa-clipboard-check"></i>
                        <span>Random Check</span>
                        <span class="tooltip">Random Check</span>
                    </a>
                </li>
            @endif

            @if($canManageFiles)
                <li class="nav-item {{ request()->routeIs('dcs.manage-files') ? 'active' : '' }}">
                    <a href="{{ route('dcs.manage-files', absolute: false) }}">
                        <i class="fa-solid fa-folder-open"></i>
                        <span>Manage Files</span>
                        <span class="tooltip">Browse DCS scans on Google Drive</span>
                    </a>
                </li>
            @endif

            @if($canSettings)
                <li data-page="settings" class="nav-item {{ request()->routeIs('dcs.settings.*') ? 'active' : '' }}">
                    <a href="{{ route('dcs.settings.index', absolute: false) }}">
                        <i class="fa-solid fa-gear"></i>
                        <span>Settings</span>
                        <span class="tooltip">Settings</span>
                    </a>
                </li>
            @endif

            @if($canRecycleBin)
                <li class="nav-item {{ request()->routeIs('dcs.recycle-bin') ? 'active' : '' }}">
                    <a href="{{ route('dcs.recycle-bin', absolute: false) }}">
                        <i class="fa-solid fa-trash-can"></i>
                        <span>Recycle Bin</span>
                        <span class="tooltip">HEAD Admin — Recycle Bin</span>
                    </a>
                </li>
            @endif
        @endif
    </ul>
</nav>

@php
    $enableTopTabs = auth()->user()?->enableTopTabs() ?? true;
    $isLimitedDcs = \App\Helpers\RegisterQueryHelper::isLimitedDcsUser();
    $isFullDcs = \App\Helpers\RegisterQueryHelper::isFullDcsUser();
    $isDcsPathPending = \App\Helpers\RegisterQueryHelper::isDcsPathPending();
    $canOfficeIntake = \App\Helpers\RegisterQueryHelper::canAccessOfficeIntake();
    $canRegister = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('register');
    $canReports = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('reports');
    $canReview = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('review');
    $canStamping = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('stamping');
    $canDatabase = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('database');
    $canManageFiles = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('manage_files');
    $canRandomCheck = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('random_check');
    $canSettings = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('settings');
    $canRecycleBin = \App\Helpers\RegisterQueryHelper::canAccessDcsModule('recycle_bin');
    $navGuides = \App\Services\ManualService::getNavGuides();
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
        <li data-page="dashboard"
            class="nav-item {{ request()->routeIs('dcs') || request()->routeIs('dcs.dashboard') ? 'active' : '' }}"
            data-nav-guide="{{ $isDcsPathPending
                ? ($navGuides['Home'] ?? 'Waiting for DCS clearance')
                : ($isLimitedDcs
                    ? ($navGuides['Home'] ?? 'Office Intake home for DRF, DCN, Incoming, and Masterlist')
                    : ($navGuides['DCS Dashboard'] ?? 'Admin DCS home for search, register actions, and request attention')) }}">
            <a href="{{ route('dcs', absolute: false) }}">
                <i class="fa-regular fa-square"></i>
                <span>{{ $isDcsPathPending ? 'Home' : 'Dashboard' }}</span>
                <span class="tooltip">{{ $isDcsPathPending
                    ? ($navGuides['Home'] ?? 'Waiting for DCS clearance')
                    : ($isLimitedDcs
                        ? ($navGuides['Home'] ?? 'Office Intake home')
                        : ($navGuides['DCS Dashboard'] ?? 'DCS Dashboard')) }}</span>
            </a>
        </li>

        @if($isLimitedDcs && $canOfficeIntake)
            @php
                $officeDocGroups = \App\Helpers\OfficeIntakeHelper::officeDocumentGroups(null, false, 'received');
                $incomingCount = \App\Helpers\OfficeIntakeHelper::officeIncomingAttentionTotal();
                $activeDocType = request()->query('type', 'all');
            @endphp
            <li class="nav-item {{ request()->routeIs('dcs.office.incoming') ? 'active' : '' }}"
                data-nav-guide="{{ $navGuides['Incoming'] ?? 'Receive distributed controlled documents and finish office appraisal' }}">
                <a href="{{ route('dcs.office.incoming', absolute: false) }}">
                    <i class="fa-solid fa-inbox"></i>
                    <span>Incoming</span>
                    <span
                        id="ofiIncomingBadge"
                        class="ofi-nav-badge"
                        style="{{ $incomingCount > 0 ? '' : 'display:none;' }}"
                        data-ofi-incoming-badge
                        @if($incomingCount < 1) hidden @endif
                    >{{ $incomingCount }}</span>
                    <span class="tooltip">{{ $navGuides['Incoming'] ?? 'Documents to receive' }}</span>
                </a>
            </li>
            <li class="nav-item {{ request()->routeIs('dcs.office.drf.*') ? 'active' : '' }}"
                data-nav-guide="{{ $navGuides['My DRF'] ?? 'Create and track Document Request Forms' }}">
                <a href="{{ route('dcs.office.drf.index', absolute: false) }}">
                    <i class="fa-regular fa-file-lines"></i>
                    <span>My DRF</span>
                    <span class="tooltip">{{ $navGuides['My DRF'] ?? 'My Document Request Forms' }}</span>
                </a>
            </li>
            <li class="nav-item {{ request()->routeIs('dcs.office.dcn.*') ? 'active' : '' }}"
                data-nav-guide="{{ $navGuides['My DCN'] ?? 'Create and track Document Change Notices' }}">
                <a href="{{ route('dcs.office.dcn.index', absolute: false) }}">
                    <i class="fa-solid fa-file-pen"></i>
                    <span>My DCN</span>
                    <span class="tooltip">{{ $navGuides['My DCN'] ?? 'My Document Change Notices' }}</span>
                </a>
            </li>
            @if($enableTopTabs)
                <li class="nav-item {{ request()->routeIs('dcs.office.documents') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Masterlist'] ?? 'Browse controlled documents your office has received' }}">
                    <a href="{{ route('dcs.office.documents', ['type' => 'all'], absolute: false) }}">
                        <i class="fa-solid fa-book"></i>
                        <span>Masterlist</span>
                        <span class="tooltip">{{ $navGuides['Masterlist'] ?? 'Received office masterlist' }}</span>
                    </a>
                </li>
            @else
                <li class="nav-item dropdown {{ request()->routeIs('dcs.office.documents') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Masterlist'] ?? 'Browse controlled documents your office has received' }}">
                    <details {{ request()->routeIs('dcs.office.documents') ? 'open' : '' }}>
                        <summary class="dropdown-trigger">
                            <i class="fa-solid fa-book"></i>
                            <span>Masterlist</span>
                            <i class="fas fa-caret-down arrow"></i>
                            <span class="tooltip">{{ $navGuides['Masterlist'] ?? 'Received office masterlist' }}</span>
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
            @php
                $canReviewIntake = \App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake();
                $pendingRequestCount = $canReviewIntake
                    ? \App\Helpers\OfficeIntakeHelper::pendingOfficeRequestTotal()
                    : 0;
            @endphp
            @if($canRegister)
                @if($enableTopTabs)
                    <li class="nav-item {{ request()->is('dcs/register*') || request()->routeIs('dcs.requests.*') ? 'active' : '' }}"
                        data-nav-guide="{{ $navGuides['Document Registration'] ?? 'Register new or revised controlled documents' }}">
                        <a href="{{ route('dcs.register.create', absolute: false) }}">
                            <i class="fa-regular fa-pen-to-square"></i>
                            <span>Document Registration</span>
                            @if($canReviewIntake)
                                <span
                                    id="ofiRequestBadge"
                                    class="ofi-nav-badge"
                                    style="{{ $pendingRequestCount > 0 ? '' : 'display:none;' }}"
                                    data-ofi-request-badge
                                    @if($pendingRequestCount < 1) hidden @endif
                                >{{ $pendingRequestCount }}</span>
                            @endif
                            <span class="tooltip">{{ $navGuides['Document Registration'] ?? 'Document Registration' }}{{ $canReviewIntake && $pendingRequestCount > 0 ? ' · '.$pendingRequestCount.' pending request'.($pendingRequestCount === 1 ? '' : 's') : '' }}</span>
                        </a>
                    </li>
                @else
                    <li class="nav-item dropdown {{ request()->is('dcs/register*') || request()->routeIs('dcs.requests.*') ? 'active' : '' }}"
                        data-nav-guide="{{ $navGuides['Document Registration'] ?? 'Register new or revised controlled documents' }}">
                        <details {{ request()->is('dcs/register*') || request()->routeIs('dcs.requests.*') ? 'open' : '' }}>
                            <summary class="dropdown-trigger">
                                <i class="fa-regular fa-pen-to-square"></i>
                                <span>Document Registration</span>
                                @if($canReviewIntake)
                                    <span
                                        class="ofi-nav-badge ofi-nav-badge-parent"
                                        style="{{ $pendingRequestCount > 0 ? '' : 'display:none;' }}"
                                        data-ofi-request-badge
                                        @if($pendingRequestCount < 1) hidden @endif
                                    >{{ $pendingRequestCount }}</span>
                                @endif
                                <i class="fas fa-caret-down arrow"></i>
                                <span class="tooltip">{{ $navGuides['Document Registration'] ?? 'Document Registration' }}</span>
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
                                        <a href="{{ route('dcs.requests.index', absolute: false) }}" class="{{ request()->routeIs('dcs.requests.*') ? 'active-sub' : '' }}">
                                            Request
                                            <span
                                                id="ofiRequestBadge"
                                                class="ofi-nav-badge ofi-nav-badge-inline"
                                                style="{{ $pendingRequestCount > 0 ? '' : 'display:none;' }}"
                                                data-ofi-request-badge
                                                @if($pendingRequestCount < 1) hidden @endif
                                            >{{ $pendingRequestCount }}</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </details>
                    </li>
                @endif
            @elseif($canReviewIntake)
                <li class="nav-item {{ request()->routeIs('dcs.requests.*') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Request'] ?? 'Review pending office DRF and DCN submissions' }}">
                    <a href="{{ route('dcs.requests.index', absolute: false) }}">
                        <i class="fa-solid fa-inbox"></i>
                        <span>Request</span>
                        <span
                            id="ofiRequestBadge"
                            class="ofi-nav-badge"
                            style="{{ $pendingRequestCount > 0 ? '' : 'display:none;' }}"
                            data-ofi-request-badge
                            @if($pendingRequestCount < 1) hidden @endif
                        >{{ $pendingRequestCount }}</span>
                        <span class="tooltip">{{ $navGuides['Request'] ?? 'Pending office DRF & DCN' }}</span>
                    </a>
                </li>
            @endif

            @if($canReports)
                @php
                    $onMonitoring = request()->routeIs('dcs.reports.monitoring', 'dcs.reports.distributionRetrieval', 'dcs.reports.syllabiTos');
                    $onGenerateReport = request()->routeIs('dcs.reports.masterlist', 'dcs.reports.opcr', 'dcs.reports.others');
                @endphp
                @if($enableTopTabs)
                    <li class="nav-item {{ $onGenerateReport ? 'active' : '' }}"
                        data-nav-guide="{{ $navGuides['Generate Report'] ?? 'Export masterlists and other DCS report packs' }}">
                        <a href="{{ route('dcs.reports.masterlist', absolute: false) }}">
                            <i class="fa-regular fa-file-lines"></i>
                            <span>Generate Report</span>
                            <span class="tooltip">{{ $navGuides['Generate Report'] ?? 'Generate Report' }}</span>
                        </a>
                    </li>
                    <li class="nav-item {{ $onMonitoring ? 'active' : '' }}"
                        data-nav-guide="{{ $navGuides['Monitoring'] ?? 'Track distribution, retrieval, and compliance monitors' }}">
                        <a href="{{ route('dcs.reports.monitoring', absolute: false) }}">
                            <i class="fa-solid fa-chart-line"></i>
                            <span>Monitoring</span>
                            <span class="tooltip">{{ $navGuides['Monitoring'] ?? 'Monitoring' }}</span>
                        </a>
                    </li>
                @else
                    <li class="nav-item dropdown {{ $onGenerateReport ? 'active' : '' }}"
                        data-nav-guide="{{ $navGuides['Generate Report'] ?? 'Export masterlists and other DCS report packs' }}">
                        <details {{ $onGenerateReport ? 'open' : '' }}>
                            <summary class="dropdown-trigger">
                                <i class="fa-regular fa-file-lines"></i>
                                <span>Generate Report</span>
                                <i class="fas fa-caret-down arrow"></i>
                                <span class="tooltip">{{ $navGuides['Generate Report'] ?? 'Generate Report' }}</span>
                            </summary>
                            <ul class="sub-dropdown">
                                <li><a href="{{ route('dcs.reports.masterlist', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.masterlist') ? 'active-sub' : '' }}">Masterlists</a></li>
                                <li><a href="{{ route('dcs.reports.opcr', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.opcr') ? 'active-sub' : '' }}">OPCR Targets</a></li>
                                <li><a href="{{ route('dcs.reports.others', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.others') ? 'active-sub' : '' }}">Others</a></li>
                            </ul>
                        </details>
                    </li>
                    <li class="nav-item dropdown {{ $onMonitoring ? 'active' : '' }}"
                        data-nav-guide="{{ $navGuides['Monitoring'] ?? 'Track distribution, retrieval, and compliance monitors' }}">
                        <details {{ $onMonitoring ? 'open' : '' }}>
                            <summary class="dropdown-trigger">
                                <i class="fa-solid fa-chart-line"></i>
                                <span>Monitoring</span>
                                <i class="fas fa-caret-down arrow"></i>
                                <span class="tooltip">{{ $navGuides['Monitoring'] ?? 'Monitoring' }}</span>
                            </summary>
                            <ul class="sub-dropdown">
                                <li><a href="{{ route('dcs.reports.monitoring', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.monitoring') ? 'active-sub' : '' }}">Monitoring Reports</a></li>
                                <li><a href="{{ route('dcs.reports.distributionRetrieval', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.distributionRetrieval') ? 'active-sub' : '' }}">Distribution &amp; Retrieval</a></li>
                                <li><a href="{{ route('dcs.reports.syllabiTos', absolute: false) }}" class="{{ request()->routeIs('dcs.reports.syllabiTos') ? 'active-sub' : '' }}">Syllabi &amp; TOS/Rubrics</a></li>
                            </ul>
                        </details>
                    </li>
                @endif
            @endif

            @if($canReview)
                <li class="nav-item {{ request()->routeIs('dcs.review') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Document Review'] ?? 'Compare scans and registration data before release' }}">
                    <a href="{{ route('dcs.review', absolute: false) }}">
                        <i class="fa-solid fa-code-compare"></i>
                        <span>Document Review</span>
                        <span class="tooltip">{{ $navGuides['Document Review'] ?? 'Document Review' }}</span>
                    </a>
                </li>
            @endif

            @if($canStamping)
                <li class="nav-item {{ request()->routeIs('dcs.stamping.*') || request()->routeIs('dcs.stamp.*') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Stamp Document'] ?? 'Apply official control stamps to scanned PDFs' }}">
                    <a href="{{ route('dcs.stamping.index', absolute: false) }}">
                        <i class="fa-solid fa-stamp"></i>
                        <span>Stamp Document</span>
                        <span class="tooltip">{{ $navGuides['Stamp Document'] ?? 'Stamp Document' }}</span>
                    </a>
                </li>
            @endif

            @if($canDatabase)
                <li class="nav-item {{ request()->routeIs('dcs.database.*') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Database'] ?? 'Inventory spreadsheet of registered documents' }}">
                    <a href="{{ route('dcs.database.index', absolute: false) }}">
                        <i class="fa-solid fa-database"></i>
                        <span>Database</span>
                        <span class="tooltip">{{ $navGuides['Database'] ?? 'Inventory of registered documents' }}</span>
                    </a>
                </li>
            @endif

            @if($canRandomCheck)
                <li class="nav-item {{ request()->routeIs('dcs.random-check*') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Random Check'] ?? 'Run compliance sampling checks and export reports' }}">
                    <a href="{{ route('dcs.random-check', absolute: false) }}">
                        <i class="fa-solid fa-clipboard-check"></i>
                        <span>Random Check</span>
                        <span class="tooltip">{{ $navGuides['Random Check'] ?? 'Random Check' }}</span>
                    </a>
                </li>
            @endif

            @if($canManageFiles)
                <li class="nav-item {{ request()->routeIs('dcs.manage-files') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Manage Files'] ?? 'Browse and retrieve DCS scan files' }}">
                    <a href="{{ route('dcs.manage-files', absolute: false) }}">
                        <i class="fa-solid fa-folder-open"></i>
                        <span>Manage Files</span>
                        <span class="tooltip">{{ $navGuides['Manage Files'] ?? 'Browse DCS scans on Google Drive' }}</span>
                    </a>
                </li>
            @endif

            @if($canSettings)
                <li data-page="settings" class="nav-item {{ request()->routeIs('dcs.settings.*') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Settings'] ?? 'Maintain document types, signatories, originators, and related catalogs' }}">
                    <a href="{{ route('dcs.settings.index', absolute: false) }}">
                        <i class="fa-solid fa-gear"></i>
                        <span>Settings</span>
                        <span class="tooltip">{{ $navGuides['Settings'] ?? 'DCS catalog settings' }}</span>
                    </a>
                </li>
            @endif

            @if($canRecycleBin)
                <li class="nav-item {{ request()->routeIs('dcs.recycle-bin') ? 'active' : '' }}"
                    data-nav-guide="{{ $navGuides['Recycle Bin'] ?? 'Restore or permanently remove deleted DCS records' }}">
                    <a href="{{ route('dcs.recycle-bin', absolute: false) }}">
                        <i class="fa-solid fa-trash-can"></i>
                        <span>Recycle Bin</span>
                        <span class="tooltip">{{ $navGuides['Recycle Bin'] ?? 'HEAD Admin — Recycle Bin' }}</span>
                    </a>
                </li>
            @endif
        @endif
    </ul>

    <div class="dcs-account-footer">
        @if(\App\Services\ServerManagementService::isMultiServerActive())
            <span class="dcs-account-server">{{ \App\Services\ServerManagementService::getServerLabel() }}</span>
        @endif
        <span class="dcs-account-email">{{ auth()->user()?->details?->email }}</span>
    </div>
</nav>

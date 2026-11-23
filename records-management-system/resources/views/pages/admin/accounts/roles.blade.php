<?php
/**
 * Admin Console - Accounts Management Roles Volt Component
 * 
 * ESXi File Manager / Miller Columns Inspired Access Control List (ACL) Interface.
 * Features:
 *  - 4-Column Miller Columns browser (Roles Directory -> Clearance Modules -> Permission Toggles -> Role Inspector)
 *  - Real-time role search and column filtering
 *  - Module-by-module clearance grouping (Core, DTS, RDP, DCS, Admin Console)
 *  - Granular permission switches with dependency detection and path exclusivity
 *  - Bulk grant/revoke shortcuts per module
 *  - Live assigned users inspection and role metrics
 *  - Soft-deactivation (deletes) of roles to maintain system transparency
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Roles & Clearances')] class extends Component {
    use \Livewire\WithPagination;

    /** @var string View layout mode: 'default' (Classic) or 'horizontal' (ESXi Miller Columns) */
    public string $layoutMode = 'horizontal';

    /** @var string Classic layout directory view mode ('table' or 'grid') */
    public string $viewMode = 'table';

    /** @var string Holds the active search input query for roles */
    public string $search = '';

    /** @var string Holds column key to sort roles by */
    public string $sortBy = 'name';

    /** @var string Sorting direction ('asc' or 'desc') */
    public string $sortDir = 'asc';

    /** @var int|null ID of the selected role. (null = placeholder, -1 = create mode, >0 = edit mode) */
    public ?int $selectedRoleId = null;

    /** @var string Selected clearance module in Column 2 ('core' | 'dts' | 'rdp' | 'dcs' | 'admin') */
    public string $selectedModule = 'core';

    /** @var string Search query inside Column 3 clearances */
    public string $permSearch = '';

    // Form fields
    /** @var string Holds the role name */
    public string $keyName = '';

    /** @var string Holds the role description */
    public string $keyDescription = '';

    /** @var bool Active status flag of the role */
    public bool $isActive = true;

    // Permissions check flags (mapped to condition_details table columns)
    public bool $isSadm = false;
    public bool $isAdmin = false;
    public bool $canAccessDts = false;
    public bool $canAccessArchv = false;
    public bool $canAccessDcs = false;
    public bool $dcsCanOfficeIntake = false;
    public bool $dcsCanAdmin = false;
    public bool $dcsCanRecycleBin = false;
    public bool $canModifyDocflow = false;
    public bool $canModifyAccountlist = false;
    public bool $canModifyPass = false;
    public bool $canModifyUser = false;
    public bool $canViewAllList = false;
    public bool $canViewAllArchive = false;
    public bool $canViewAllCurrentTrans = false;
    public bool $canViewAllScannerCodes = false;
    public bool $canCreateOwnFlow = false;
    public bool $canDtsCreateInternal = false;
    public bool $canDtsCreateExternal = false;
    public bool $canDtsCreateApplication = false;
    public bool $canDtsCreateIssuance = false;
    public bool $canDtsReceiveInternal = false;
    public bool $canDtsReceiveExternal = false;
    public bool $canDtsReceiveApplication = false;
    public bool $canDtsReceiveIssuance = false;
    public bool $canDtsUseInternal = false;
    public bool $canDtsUseExternal = false;
    public bool $canDtsUseApplication = false;
    public bool $canDtsUseIssuance = false;
    public bool $canDtsIssuanceReceivingOffice = false;
    public bool $canDtsIssuanceTransactionFlow = false;
    public bool $canDtsUserReceived = false;
    public bool $canDtsForwardOtherOffice = false;
    public bool $canDtsModifyTransaction = false;
    public bool $canDtsModifyControlNo = false;
    public bool $canDtsModifyIssuanceControlNo = false;

    // Admin Console Clearance flags
    public bool $canAccessActivityLogs = false;
    public bool $canAccessSubsystems = false;
    public bool $canAccessDtsAdmin = false;
    public bool $canAccessRdpAdmin = false;
    public bool $canAccessDcsAdmin = false;
    public bool $canAccessSettings = false;
    public bool $canAccessRecycleBin = false;

    // RDP Specific Clearance flags
    public bool $rdpViewAllFiles = false;
    public bool $isRdpViewAllPendingList = false;
    public bool $isRdpViewAllReceivedDocs = false;
    public bool $canRdpAccessReceivedDocs = true;
    public bool $canRdpAccessAddRecords = true;
    public bool $canRdpAccessDrafts = true;
    public bool $canRdpAccessPending = true;
    public bool $canRdpAccessReferences = true;
    public bool $canRdpAccessReports = true;
    public bool $canRdpAccessManageFiles = true;
    public bool $canRdpAccessForApproval = false;
    public bool $canRdpModifySeries = true;
    public bool $canRdpGenerateReports = true;

    // Toast notifications
    public string $successMessage = '';
    public string $errorMessage = '';

    // Super Admin Verification Modal
    public bool $showVerificationModal = false;
    public string $verifyUsername = '';
    public string $verifyPassword = '';
    public string $verificationError = '';

    /**
     * Component mount hook - initializes authorization.
     */
    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_sadm_modify_accountlist)) {
            $this->redirect(route('portal'));
            return;
        }
        $this->layoutMode = session('admin_roles_layout_mode', session('admin_management_layout_mode', 'horizontal'));
        $this->cancelSelection();
    }

    /**
     * Clears all session alert banners.
     */
    public function setLayoutMode(string $mode): void
    {
        if (in_array($mode, ['default', 'horizontal'])) {
            $this->layoutMode = $mode;
            session(['admin_roles_layout_mode' => $mode]);
            session(['admin_management_layout_mode' => $mode]);
        }
    }

    /**
     * Toggles the sort dir setting or state.
     * @return void
     */
    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    }

    /**
     * Lifecycle hook executed before updating the search property.
     * @return void
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Resets messages and clears active selections.
     * @return void
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Resets form fields and returns to the selection placeholder screen.
     */
    public function cancelSelection(): void
    {
        $this->selectedRoleId = null;
        $this->selectedModule = 'core';
        $this->permSearch = '';
        $this->keyName = '';
        $this->keyDescription = '';
        $this->isActive = true;
        
        // Reset all permission flags
        $this->isSadm = false;
        $this->isAdmin = false;
        $this->canAccessDts = false;
        $this->canAccessArchv = false;
        $this->canAccessDcs = false;
        $this->dcsCanOfficeIntake = false;
        $this->dcsCanAdmin = false;
        $this->dcsCanRecycleBin = false;
        $this->canModifyDocflow = false;
        $this->canModifyAccountlist = false;
        $this->canModifyPass = false;
        $this->canModifyUser = false;
        $this->canViewAllList = false;
        $this->canViewAllArchive = false;
        $this->canViewAllCurrentTrans = false;
        $this->canViewAllScannerCodes = false;
        $this->canCreateOwnFlow = false;
        $this->canDtsCreateInternal = false;
        $this->canDtsCreateExternal = false;
        $this->canDtsCreateApplication = false;
        $this->canDtsCreateIssuance = false;
        $this->canDtsReceiveInternal = false;
        $this->canDtsReceiveExternal = false;
        $this->canDtsReceiveApplication = false;
        $this->canDtsReceiveIssuance = false;
        $this->canDtsUseInternal = false;
        $this->canDtsUseExternal = false;
        $this->canDtsUseApplication = false;
        $this->canDtsUseIssuance = false;
        $this->canDtsIssuanceReceivingOffice = false;
        $this->canDtsIssuanceTransactionFlow = false;
        $this->canDtsUserReceived = false;
        $this->canDtsForwardOtherOffice = false;
        $this->canDtsModifyTransaction = false;
        $this->canDtsModifyControlNo = false;
        $this->canDtsModifyIssuanceControlNo = false;
        $this->canAccessActivityLogs = false;
        $this->canAccessSubsystems = false;
        $this->canAccessDtsAdmin = false;
        $this->canAccessRdpAdmin = false;
        $this->canAccessDcsAdmin = false;
        $this->canAccessSettings = false;
        $this->canAccessRecycleBin = false;
        $this->rdpViewAllFiles = false;
        $this->isRdpViewAllPendingList = false;
        $this->isRdpViewAllReceivedDocs = false;
        $this->canRdpAccessReceivedDocs = true;
        $this->canRdpAccessAddRecords = true;
        $this->canRdpAccessDrafts = true;
        $this->canRdpAccessPending = true;
        $this->canRdpAccessReferences = true;
        $this->canRdpAccessReports = true;
        $this->canRdpAccessManageFiles = true;
        $this->canRdpAccessForApproval = false;
        $this->canRdpModifySeries = true;
        $this->canRdpGenerateReports = true;
        
        $this->showVerificationModal = false;
        $this->verifyUsername = '';
        $this->verifyPassword = '';
        $this->verificationError = '';
        
        $this->clearMessages();
    }

    /**
     * Initializes "Create Mode" for configuring a new role.
     */
    public function startCreate(): void
    {
        $this->cancelSelection();
        $this->selectedRoleId = -1; // -1 denotes "Create Mode"
        $this->selectedModule = 'core';
        $this->keyName = 'New Custom Role';
    }

    /**
     * Selects a role from the directory to load details and permission flags.
     */
    public function selectRole(int $id): void
    {
        $this->cancelSelection();
        $this->selectedRoleId = $id;
        $this->selectedModule = 'core';

        $role = \App\Models\role_list::with('permissions')->find($id);
        if ($role) {
            $this->keyName = $role->key_name;
            $this->keyDescription = $role->key_description ?? '';
            $this->isActive = (bool) $role->is_active;

            $perms = $role->permissions;
            if ($perms) {
                $this->isSadm = (bool) $perms->is_sadm;
                $this->isAdmin = (bool) $perms->is_admin;
                $this->canAccessDts = (bool) $perms->can_access_dts;
                $this->canAccessArchv = (bool) $perms->can_access_rdp;
                $this->canAccessDcs = (bool) $perms->can_access_dcs;
                $this->dcsCanOfficeIntake = (bool) ($perms->dcs_can_office_intake ?? false);
                $this->dcsCanAdmin = $this->roleHasDcsAdminPages($perms);
                $this->dcsCanRecycleBin = (bool) ($perms->dcs_can_recycle_bin ?? false);
                $this->applyDcsClearanceExclusivity();
                $this->canModifyDocflow = (bool) $perms->can_dts_modify_docflow;
                $this->canModifyAccountlist = (bool) $perms->can_sadm_modify_accountlist;
                $this->canModifyPass = (bool) $perms->can_sadm_modify_pass;
                $this->canModifyUser = (bool) $perms->can_sadm_modify_account;
                $this->canViewAllList = (bool) $perms->can_dts_view_all_list;
                $this->canViewAllArchive = (bool) $perms->can_dts_view_all_archive;
                $this->canViewAllCurrentTrans = (bool) $perms->can_dts_view_all_current_trans;
                $this->canViewAllScannerCodes = (bool) ($perms->can_dts_view_all_scanner_codes ?? false);
                $this->canCreateOwnFlow = (bool) $perms->can_dts_create_own_flow;
                $this->canDtsCreateInternal = (bool) ($perms->can_dts_create_internal ?? $perms->can_dts_use_internal ?? false);
                $this->canDtsCreateExternal = (bool) ($perms->can_dts_create_external ?? $perms->can_dts_use_external ?? false);
                $this->canDtsCreateApplication = (bool) ($perms->can_dts_create_application ?? $perms->can_dts_use_application ?? false);
                $this->canDtsCreateIssuance = (bool) ($perms->can_dts_create_issuance ?? $perms->can_dts_use_issuance ?? false);
                $this->canDtsReceiveInternal = (bool) ($perms->can_dts_receive_internal ?? $perms->can_dts_use_internal ?? false);
                $this->canDtsReceiveExternal = (bool) ($perms->can_dts_receive_external ?? $perms->can_dts_use_external ?? false);
                $this->canDtsReceiveApplication = (bool) ($perms->can_dts_receive_application ?? $perms->can_dts_use_application ?? false);
                $this->canDtsReceiveIssuance = (bool) ($perms->can_dts_receive_issuance ?? $perms->can_dts_use_issuance ?? false);
                $this->canDtsUseInternal = (bool) $perms->can_dts_use_internal || $this->canDtsCreateInternal || $this->canDtsReceiveInternal;
                $this->canDtsUseExternal = (bool) $perms->can_dts_use_external || $this->canDtsCreateExternal || $this->canDtsReceiveExternal;
                $this->canDtsUseApplication = (bool) $perms->can_dts_use_application || $this->canDtsCreateApplication || $this->canDtsReceiveApplication;
                $this->canDtsUseIssuance = (bool) $perms->can_dts_use_issuance || $this->canDtsCreateIssuance || $this->canDtsReceiveIssuance;
                $this->canDtsIssuanceReceivingOffice = (bool) ($perms->can_dts_issuance_receiving_office ?? false);
                $this->canDtsIssuanceTransactionFlow = (bool) ($perms->can_dts_issuance_transaction_flow ?? false);
                $this->canDtsUserReceived = (bool) $perms->can_dts_user_received;
                $this->canDtsForwardOtherOffice = (bool) ($perms->can_dts_forward_to_other_office ?? false);
                $this->canDtsModifyTransaction = (bool) $perms->can_dts_modify_transaction;
                $this->canDtsModifyControlNo = (bool) ($perms->can_dts_modify_control_no ?? false);
                $this->canDtsModifyIssuanceControlNo = (bool) ($perms->can_dts_modify_issuance_control_no ?? false);

                // Admin Console Clearance flags
                $this->canAccessActivityLogs = (bool) ($perms->can_access_activity_logs ?? false);
                $this->canAccessSubsystems = (bool) ($perms->can_access_subsystems ?? false);
                $this->canAccessDtsAdmin = (bool) ($perms->can_access_dts_admin ?? false);
                $this->canAccessRdpAdmin = (bool) ($perms->can_access_rdp_admin ?? false);
                $this->canAccessDcsAdmin = (bool) ($perms->can_access_dcs_admin ?? false);
                $this->canAccessSettings = (bool) ($perms->can_access_settings ?? false);
                $this->canAccessRecycleBin = (bool) ($perms->can_access_recycle_bin ?? false);

                // RDP Clearances
                $this->rdpViewAllFiles = (bool) ($perms->rdp_view_all_files ?? false);
                $this->isRdpViewAllPendingList = (bool) ($perms->is_rdp_view_all_pending_list ?? false);
                $this->isRdpViewAllReceivedDocs = (bool) ($perms->is_rdp_view_all_received_docs ?? false);
                $this->canRdpAccessReceivedDocs = (bool) ($perms->can_rdp_access_received_docs ?? true);
                $this->canRdpAccessAddRecords = (bool) ($perms->can_rdp_access_add_records ?? true);
                $this->canRdpAccessDrafts = (bool) ($perms->can_rdp_access_drafts ?? true);
                $this->canRdpAccessPending = (bool) ($perms->can_rdp_access_pending ?? true);
                $this->canRdpAccessReferences = (bool) ($perms->can_rdp_access_references ?? true);
                $this->canRdpAccessReports = (bool) ($perms->can_rdp_access_reports ?? true);
                $this->canRdpAccessManageFiles = (bool) ($perms->can_rdp_access_manage_files ?? true);
                $this->canRdpAccessForApproval = (bool) ($perms->can_rdp_access_for_approval ?? false);
                $this->canRdpModifySeries = (bool) ($perms->can_rdp_modify_series ?? true);
                $this->canRdpGenerateReports = (bool) ($perms->can_rdp_generate_reports ?? true);
            }
        }
    }

    /**
     * Selects module and populates the details panel.
     *
     * @param string $module
     *
     * @return void
     */
    public function selectModule(string $module): void
    {
        $this->selectedModule = $module;
        $this->permSearch = '';
    }

    /**
     * Calculates active/total clearance stats per module.
     */
    public function getModuleCounts(): array
    {
        // Core Access (5)
        $coreActive = ($this->isSadm ? 1 : 0)
            + ($this->isAdmin ? 1 : 0)
            + ($this->canAccessDts ? 1 : 0)
            + ($this->canAccessArchv ? 1 : 0)
            + ($this->canAccessDcs ? 1 : 0);

        // DTS
        $dtsActive = ($this->canModifyDocflow ? 1 : 0)
            + ($this->canViewAllList ? 1 : 0)
            + ($this->canViewAllArchive ? 1 : 0)
            + ($this->canViewAllCurrentTrans ? 1 : 0)
            + ($this->canCreateOwnFlow ? 1 : 0)
            + ($this->canDtsCreateInternal ? 1 : 0)
            + ($this->canDtsCreateExternal ? 1 : 0)
            + ($this->canDtsCreateApplication ? 1 : 0)
            + ($this->canDtsCreateIssuance ? 1 : 0)
            + ($this->canDtsReceiveInternal ? 1 : 0)
            + ($this->canDtsReceiveExternal ? 1 : 0)
            + ($this->canDtsReceiveApplication ? 1 : 0)
            + ($this->canDtsReceiveIssuance ? 1 : 0)
            + ($this->canDtsIssuanceReceivingOffice ? 1 : 0)
            + ($this->canDtsIssuanceTransactionFlow ? 1 : 0)
            + ($this->canDtsUserReceived ? 1 : 0)
            + ($this->canDtsForwardOtherOffice ? 1 : 0)
            + ($this->canDtsModifyTransaction ? 1 : 0)
            + ($this->canDtsModifyControlNo ? 1 : 0)
            + ($this->canDtsModifyIssuanceControlNo ? 1 : 0)
            + ($this->canViewAllScannerCodes ? 1 : 0);

        // RDP (14)
        $rdpActive = ($this->canAccessArchv ? 1 : 0)
            + ($this->canRdpAccessReceivedDocs ? 1 : 0)
            + ($this->canRdpAccessAddRecords ? 1 : 0)
            + ($this->canRdpAccessDrafts ? 1 : 0)
            + ($this->canRdpAccessPending ? 1 : 0)
            + ($this->canRdpAccessReferences ? 1 : 0)
            + ($this->canRdpAccessReports ? 1 : 0)
            + ($this->canRdpAccessManageFiles ? 1 : 0)
            + ($this->rdpViewAllFiles ? 1 : 0)
            + ($this->isRdpViewAllPendingList ? 1 : 0)
            + ($this->canRdpAccessForApproval ? 1 : 0)
            + ($this->isRdpViewAllReceivedDocs ? 1 : 0)
            + ($this->canRdpModifySeries ? 1 : 0)
            + ($this->canRdpGenerateReports ? 1 : 0);

        // DCS (4)
        $dcsActive = ($this->canAccessDcs ? 1 : 0)
            + ($this->dcsCanOfficeIntake ? 1 : 0)
            + ($this->dcsCanAdmin ? 1 : 0)
            + ($this->dcsCanRecycleBin ? 1 : 0);

        // Admin Console (10)
        $adminActive = ($this->canModifyUser ? 1 : 0)
            + ($this->canModifyAccountlist ? 1 : 0)
            + ($this->canModifyPass ? 1 : 0)
            + ($this->canAccessActivityLogs ? 1 : 0)
            + ($this->canAccessSubsystems ? 1 : 0)
            + ($this->canAccessDtsAdmin ? 1 : 0)
            + ($this->canAccessRdpAdmin ? 1 : 0)
            + ($this->canAccessDcsAdmin ? 1 : 0)
            + ($this->canAccessSettings ? 1 : 0)
            + ($this->canAccessRecycleBin ? 1 : 0);

        $totalActive = $coreActive + $dtsActive + $rdpActive + $dcsActive + $adminActive;

        return [
            'core'  => ['active' => $coreActive, 'total' => 5],
            'dts'   => ['active' => $dtsActive, 'total' => 17],
            'rdp'   => ['active' => $rdpActive, 'total' => 14],
            'dcs'   => ['active' => $dcsActive, 'total' => 4],
            'admin' => ['active' => $adminActive, 'total' => 10],
            'totalActive' => $totalActive,
            'totalClearances' => 5 + 17 + 14 + 4 + 10,
        ];
    }

    /**
     * Bulk grant all permissions within the specified module.
     */
    public function grantModuleAll(string $module): void
    {
        if ($module === 'core') {
            $this->isAdmin = true;
            $this->canAccessDts = true;
            $this->canAccessArchv = true;
            $this->canAccessDcs = true;
        } elseif ($module === 'dts') {
            $this->canAccessDts = true;
            $this->canModifyDocflow = true;
            if ($this->isSadm || $this->isAdmin) {
                $this->canViewAllList = true;
                $this->canViewAllArchive = true;
                $this->canViewAllCurrentTrans = true;
                $this->canDtsModifyTransaction = true;
                $this->canViewAllScannerCodes = true;
            }
            $this->canCreateOwnFlow = true;
            $this->canDtsCreateInternal = true;
            $this->canDtsCreateExternal = true;
            $this->canDtsCreateApplication = true;
            $this->canDtsCreateIssuance = true;
            $this->canDtsReceiveInternal = true;
            $this->canDtsReceiveExternal = true;
            $this->canDtsReceiveApplication = true;
            $this->canDtsReceiveIssuance = true;
            $this->canDtsUseInternal = true;
            $this->canDtsUseExternal = true;
            $this->canDtsUseApplication = true;
            $this->canDtsUseIssuance = true;
            $this->canDtsIssuanceReceivingOffice = true;
            $this->canDtsIssuanceTransactionFlow = true;
            $this->canDtsUserReceived = true;
            $this->canDtsForwardOtherOffice = true;
            $this->canDtsModifyControlNo = true;
            $this->canDtsModifyIssuanceControlNo = true;
        } elseif ($module === 'rdp') {
            $this->canAccessArchv = true;
            $this->canRdpAccessReceivedDocs = true;
            $this->canRdpAccessAddRecords = true;
            $this->canRdpAccessDrafts = true;
            $this->canRdpAccessPending = true;
            $this->canRdpAccessReferences = true;
            $this->canRdpAccessReports = true;
            $this->canRdpAccessManageFiles = true;
            if ($this->isSadm || $this->isAdmin) {
                $this->rdpViewAllFiles = true;
            }
            $this->isRdpViewAllPendingList = true;
            $this->canRdpAccessForApproval = true;
            $this->isRdpViewAllReceivedDocs = true;
            $this->canRdpModifySeries = true;
            $this->canRdpGenerateReports = true;
        } elseif ($module === 'dcs') {
            $this->canAccessDcs = true;
            $this->dcsCanAdmin = true;
            $this->dcsCanOfficeIntake = false;
        } elseif ($module === 'admin') {
            if ($this->isSadm || $this->isAdmin) {
                $this->canModifyUser = true;
                $this->canModifyAccountlist = true;
                $this->canModifyPass = true;
                $this->canAccessActivityLogs = true;
                $this->canAccessSubsystems = true;
                $this->canAccessDtsAdmin = true;
                $this->canAccessRdpAdmin = true;
                $this->canAccessDcsAdmin = true;
                $this->canAccessSettings = true;
                $this->canAccessRecycleBin = true;
            }
        }
    }

    /**
     * Bulk revoke all permissions within the specified module.
     */
    public function revokeModuleAll(string $module): void
    {
        if ($module === 'core') {
            $this->isSadm = false;
            $this->isAdmin = false;
            $this->canAccessDts = false;
            $this->canAccessArchv = false;
            $this->canAccessDcs = false;
        } elseif ($module === 'dts') {
            $this->canModifyDocflow = false;
            $this->canViewAllList = false;
            $this->canViewAllArchive = false;
            $this->canViewAllCurrentTrans = false;
            $this->canCreateOwnFlow = false;
            $this->canDtsCreateInternal = false;
            $this->canDtsCreateExternal = false;
            $this->canDtsCreateApplication = false;
            $this->canDtsCreateIssuance = false;
            $this->canDtsReceiveInternal = false;
            $this->canDtsReceiveExternal = false;
            $this->canDtsReceiveApplication = false;
            $this->canDtsReceiveIssuance = false;
            $this->canDtsUseInternal = false;
            $this->canDtsUseExternal = false;
            $this->canDtsUseApplication = false;
            $this->canDtsUseIssuance = false;
            $this->canDtsIssuanceReceivingOffice = false;
            $this->canDtsIssuanceTransactionFlow = false;
            $this->canDtsUserReceived = false;
            $this->canDtsForwardOtherOffice = false;
            $this->canDtsModifyTransaction = false;
            $this->canDtsModifyControlNo = false;
            $this->canDtsModifyIssuanceControlNo = false;
            $this->canViewAllScannerCodes = false;
        } elseif ($module === 'rdp') {
            $this->canAccessArchv = false;
            $this->canRdpAccessReceivedDocs = false;
            $this->canRdpAccessAddRecords = false;
            $this->canRdpAccessDrafts = false;
            $this->canRdpAccessPending = false;
            $this->canRdpAccessReferences = false;
            $this->canRdpAccessReports = false;
            $this->canRdpAccessManageFiles = false;
            $this->rdpViewAllFiles = false;
            $this->isRdpViewAllPendingList = false;
            $this->canRdpAccessForApproval = false;
            $this->isRdpViewAllReceivedDocs = false;
            $this->canRdpModifySeries = false;
            $this->canRdpGenerateReports = false;
        } elseif ($module === 'dcs') {
            $this->canAccessDcs = false;
            $this->dcsCanOfficeIntake = false;
            $this->dcsCanAdmin = false;
            $this->dcsCanRecycleBin = false;
        } elseif ($module === 'admin') {
            $this->canModifyUser = false;
            $this->canModifyAccountlist = false;
            $this->canModifyPass = false;
            $this->canAccessActivityLogs = false;
            $this->canAccessSubsystems = false;
            $this->canAccessDtsAdmin = false;
            $this->canAccessRdpAdmin = false;
            $this->canAccessDcsAdmin = false;
            $this->canAccessSettings = false;
            $this->canAccessRecycleBin = false;
        }
    }

    /**
     * Creates a new role or updates an existing role in the database.
     */
    public function saveRoleChanges(): void
    {
        if ($this->selectedRoleId === null) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_accountlist clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_accountlist)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify subsystem roles.';
            return;
        }

        $condKeyTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';

        // Validation rules: unique check excludes selected record if updating
        $uniqueRule = $this->selectedRoleId > 0 
            ? "unique:{$condKeyTbl},key_name," . $this->selectedRoleId . ',id'
            : "unique:{$condKeyTbl},key_name";

        $this->validate([
            'keyName' => 'required|string|max:255|' . $uniqueRule,
            'keyDescription' => 'nullable|string',
        ]);

        try {
            \DB::transaction(function () {
                if ($this->selectedRoleId === -1) {
                    // --- CREATE MODE ---
                    $perms = new \App\Models\role_permission();
                    $this->setPermissionModelAttributes($perms);
                    $perms->save();

                    $role = new \App\Models\role_list();
                    $role->key_name = $this->keyName;
                    $role->key_description = $this->keyDescription;
                    $role->modifier_key = $perms->key_id;
                    $role->is_active = true;
                    $role->save();
                    
                    \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                        'changes' => "Created role: {$this->keyName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);
                    
                    $this->selectedRoleId = $role->id;
                } else {
                    // --- EDIT MODE ---
                    $role = \App\Models\role_list::findOrFail($this->selectedRoleId);
                    $statusChanged = $role->is_active != $this->isActive;

                    $role->update([
                        'key_name' => $this->keyName,
                        'key_description' => $this->keyDescription,
                        'is_active' => $this->isActive,
                        'date_updated' => now(),
                    ]);

                    $perms = \App\Models\role_permission::findOrFail($role->modifier_key);
                    $this->setPermissionModelAttributes($perms);
                    $perms->save();

                    \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                        'changes' => "Updated permissions/details for role: {$this->keyName}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3,
                        'when_changes' => now()
                    ]);

                    if ($statusChanged) {
                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                            'changes' => "Toggled active status (Value: " . ($this->isActive ? '1' : '0') . ") for role: {$this->keyName}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now()
                        ]);
                    }
                }
            });

            $currentUserRole = auth()->user() ? auth()->user()->account_role : null;
            if ($currentUserRole && $currentUserRole === $this->selectedRoleId) {
                session()->flash('success', 'Role configuration updated successfully!');
                $this->js('window.location.reload();');
            } else {
                $this->successMessage = 'Role configuration updated successfully!';
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save changes: ' . $e->getMessage();
        }
    }

    /**
     * Soft-deactivates the selected role for transparency.
     */
    public function deleteRole(): void
    {
        if ($this->selectedRoleId === null || $this->selectedRoleId === -1) {
            return;
        }

        $this->clearMessages();

        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_accountlist)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to delete subsystem roles.';
            return;
        }

        try {
            \DB::transaction(function () {
                $role = \App\Models\role_list::findOrFail($this->selectedRoleId);
                
                $role->update([
                    'is_active' => false,
                    'date_updated' => now(),
                ]);

                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
                    'changes' => "Soft-deleted role (Deactivated for transparency): {$role->key_name}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now()
                ]);

                $roleId = $this->selectedRoleId;
                $this->cancelSelection();
                
                $currentUserRole = auth()->user() ? auth()->user()->account_role : null;
                if ($currentUserRole && $currentUserRole === $roleId) {
                    session()->flash('success', 'Role deactivated successfully!');
                    $this->js('window.location.reload();');
                } else {
                    $this->successMessage = 'Role deactivated successfully!';
                }
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete role: ' . $e->getMessage();
        }
    }

    /**
     * Map checkbox property states directly to Eloquent permission model.
     */
    private function setPermissionModelAttributes(\App\Models\role_permission $perms): void
    {
        $perms->is_sadm = $this->isSadm;
        $perms->is_admin = $this->isAdmin;
        $perms->can_access_dts = $this->canAccessDts;
        $perms->can_access_rdp = $this->canAccessArchv;
        $perms->can_access_dcs = $this->canAccessDcs;
        $this->applyDcsClearanceExclusivity();
        $adminPages = $this->canAccessDcs && $this->dcsCanAdmin;
        if (\Illuminate\Support\Facades\Schema::hasColumn($perms->getTable(), 'dcs_can_office_intake')) {
            $perms->dcs_can_office_intake = $this->canAccessDcs && $this->dcsCanOfficeIntake;
        }
        $perms->dcs_can_register = $adminPages;
        $perms->dcs_can_settings = $adminPages;
        $perms->dcs_can_recycle_bin = $this->canAccessDcs && $this->dcsCanRecycleBin;
        $perms->dcs_can_review_intake = $adminPages;
        $perms->dcs_can_reports = $adminPages;
        $perms->dcs_can_review = $adminPages;
        $perms->dcs_can_stamping = $adminPages;
        $perms->dcs_can_database = $adminPages;
        $perms->dcs_can_manage_files = $adminPages;
        $perms->dcs_can_random_check = $adminPages;
        $perms->can_dts_modify_docflow = $this->canModifyDocflow;
        $perms->can_sadm_modify_accountlist = $this->canModifyAccountlist;
        $perms->can_sadm_modify_pass = $this->canModifyPass;
        $perms->can_sadm_modify_account = $this->canModifyUser;
        $perms->can_dts_view_all_list = $this->canViewAllList;
        $perms->can_dts_view_all_archive = $this->canViewAllArchive;
        $perms->can_dts_view_all_current_trans = $this->canViewAllCurrentTrans;
        $perms->can_dts_view_all_scanner_codes = $this->canViewAllScannerCodes;
        $perms->can_dts_create_own_flow = $this->canCreateOwnFlow;
        $perms->can_dts_create_internal = $this->canDtsCreateInternal;
        $perms->can_dts_create_external = $this->canDtsCreateExternal;
        $perms->can_dts_create_application = $this->canDtsCreateApplication;
        $perms->can_dts_create_issuance = $this->canDtsCreateIssuance;
        $perms->can_dts_receive_internal = $this->canDtsReceiveInternal;
        $perms->can_dts_receive_external = $this->canDtsReceiveExternal;
        $perms->can_dts_receive_application = $this->canDtsReceiveApplication;
        $perms->can_dts_receive_issuance = $this->canDtsReceiveIssuance;
        $perms->can_dts_use_internal = $this->canDtsCreateInternal || $this->canDtsReceiveInternal;
        $perms->can_dts_use_external = $this->canDtsCreateExternal || $this->canDtsReceiveExternal;
        $perms->can_dts_use_application = $this->canDtsCreateApplication || $this->canDtsReceiveApplication;
        $perms->can_dts_use_issuance = $this->canDtsCreateIssuance || $this->canDtsReceiveIssuance;
        $perms->can_dts_issuance_receiving_office = $this->canDtsIssuanceReceivingOffice;
        $perms->can_dts_issuance_transaction_flow = $this->canDtsIssuanceTransactionFlow;
        $perms->can_dts_user_received = $this->canDtsUserReceived;
        $perms->can_dts_forward_to_other_office = $this->canDtsForwardOtherOffice;
        $perms->can_dts_modify_transaction = $this->canDtsModifyTransaction;
        $perms->can_dts_modify_control_no = $this->canDtsModifyControlNo;
        $perms->can_dts_modify_issuance_control_no = $this->canDtsModifyIssuanceControlNo;

        // Admin Console Clearance flags
        $perms->can_access_activity_logs = $this->canAccessActivityLogs;
        $perms->can_access_subsystems = $this->canAccessSubsystems;
        $perms->can_access_dts_admin = $this->canAccessDtsAdmin;
        $perms->can_access_rdp_admin = $this->canAccessRdpAdmin;
        $perms->can_access_dcs_admin = $this->canAccessDcsAdmin;
        $perms->can_access_settings = $this->canAccessSettings;
        $perms->can_access_recycle_bin = $this->canAccessRecycleBin;

        // RDP Clearances
        $perms->can_rdp_access_received_docs = $this->canRdpAccessReceivedDocs;
        $perms->can_rdp_access_add_records   = $this->canRdpAccessAddRecords;
        $perms->can_rdp_access_drafts        = $this->canRdpAccessDrafts;
        $perms->can_rdp_access_pending       = $this->canRdpAccessPending;
        $perms->can_rdp_access_references    = $this->canRdpAccessReferences;
        $perms->can_rdp_access_reports       = $this->canRdpAccessReports;
        $perms->can_rdp_access_manage_files  = $this->canRdpAccessManageFiles;
        $perms->rdp_view_all_files        = ($this->isSadm || $this->isAdmin) ? $this->rdpViewAllFiles : false;
        $perms->is_rdp_view_all_pending_list = $this->isRdpViewAllPendingList;
        $perms->is_rdp_view_all_received_docs = $this->isRdpViewAllReceivedDocs;
        $perms->can_rdp_access_for_approval = $this->canRdpAccessForApproval;
        $perms->can_rdp_modify_series     = $this->canRdpModifySeries;
        $perms->can_rdp_generate_reports  = $this->canRdpGenerateReports;
    }

    /**
     * Roles has dcs admin pages.
     *
     * @param object $perms
     *
     * @return bool
     */
    private function roleHasDcsAdminPages(object $perms): bool
    {
        foreach ([
            'dcs_can_register',
            'dcs_can_settings',
            'dcs_can_review_intake',
            'dcs_can_reports',
            'dcs_can_review',
            'dcs_can_stamping',
            'dcs_can_database',
            'dcs_can_manage_files',
            'dcs_can_random_check',
        ] as $column) {
            if (! empty($perms->{$column})) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lifecycle hook executed after updating the is admin property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedIsAdmin($value): void
    {
        if (! $value) {
            $this->isSadm = false;
            $this->canViewAllList = false;
            $this->canViewAllArchive = false;
            $this->canViewAllCurrentTrans = false;
            $this->canModifyUser = false;
            $this->canModifyAccountlist = false;
            $this->canModifyPass = false;
            $this->canDtsModifyTransaction = false;
            $this->rdpViewAllFiles = false;
            $this->canAccessDcsAdmin = false;
        }
    }

    /**
     * Lifecycle hook executed after updating the can access dcs property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedCanAccessDcs($value): void
    {
        if (! $value) {
            $this->dcsCanOfficeIntake = false;
            $this->dcsCanAdmin = false;
            $this->dcsCanRecycleBin = false;
            $this->canAccessDcsAdmin = false;
            return;
        }

        if (! $this->dcsCanAdmin && ! $this->dcsCanRecycleBin) {
            $this->dcsCanOfficeIntake = true;
        }
    }

    /**
     * Lifecycle hook executed after updating the dcs can office intake property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedDcsCanOfficeIntake($value): void
    {
        if ($value) {
            $this->dcsCanAdmin = false;
            $this->dcsCanRecycleBin = false;
        }
    }

    /**
     * Lifecycle hook executed after updating the dcs can admin property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedDcsCanAdmin($value): void
    {
        if ($value) {
            $this->dcsCanOfficeIntake = false;
        }
    }

    /**
     * Lifecycle hook executed after updating the dcs can recycle bin property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedDcsCanRecycleBin($value): void
    {
        if ($value) {
            $this->dcsCanOfficeIntake = false;
        }
    }

    /**
     * Filters and queries dcs clearance exclusivity based on criteria.
     * @return void
     */
    private function applyDcsClearanceExclusivity(): void
    {
        if ($this->dcsCanOfficeIntake) {
            $this->dcsCanAdmin = false;
            $this->dcsCanRecycleBin = false;
        }
    }

    /**
     * Lifecycle hook executed after updating the is sadm property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedIsSadm($value): void
    {
        if ($value) {
            $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
            if ($currentUserPerms && $currentUserPerms->is_sadm) {
                $this->isSadm = true;
                $this->enableAllPermissions();
            } else {
                $this->isSadm = false;
                $this->showVerificationModal = true;
                $this->verifyUsername = '';
                $this->verifyPassword = '';
                $this->verificationError = '';
            }
        }
    }

    /**
     * Submits and processes verification.
     * @return void
     */
    public function submitVerification(): void
    {
        $this->verificationError = '';

        if (empty($this->verifyUsername) || empty($this->verifyPassword)) {
            $this->verificationError = 'Please enter both username and password.';
            return;
        }

        $superAdmin = \App\Models\User::where('username', $this->verifyUsername)->first();
        if ($superAdmin && $superAdmin->permissions && $superAdmin->permissions->is_sadm && \Hash::check($this->verifyPassword, $superAdmin->password)) {
            $this->isSadm = true;
            $this->enableAllPermissions();
            $this->showVerificationModal = false;
            $this->verifyUsername = '';
            $this->verifyPassword = '';
        } else {
            $this->verificationError = 'Invalid Super Admin credentials.';
        }
    }

    /**
     * Resets verification and clears active selections.
     * @return void
     */
    public function cancelVerification(): void
    {
        $this->showVerificationModal = false;
        $this->isSadm = false;
    }

    /**
     * Enables all permissions.
     * @return void
     */
    private function enableAllPermissions(): void
    {
        $this->isAdmin = true;
        $this->canAccessDts = true;
        $this->canAccessArchv = true;
        $this->canAccessDcs = true;
        $this->dcsCanOfficeIntake = false;
        $this->dcsCanAdmin = true;
        $this->dcsCanRecycleBin = true;
        $this->canModifyDocflow = true;
        $this->canModifyAccountlist = true;
        $this->canModifyPass = true;
        $this->canModifyUser = true;
        $this->canViewAllList = true;
        $this->canViewAllArchive = true;
        $this->canViewAllCurrentTrans = true;
        $this->canViewAllScannerCodes = true;
        $this->canCreateOwnFlow = true;
        $this->canDtsCreateInternal = true;
        $this->canDtsCreateExternal = true;
        $this->canDtsCreateApplication = true;
        $this->canDtsCreateIssuance = true;
        $this->canDtsReceiveInternal = true;
        $this->canDtsReceiveExternal = true;
        $this->canDtsReceiveApplication = true;
        $this->canDtsReceiveIssuance = true;
        $this->canDtsUseInternal = true;
        $this->canDtsUseExternal = true;
        $this->canDtsUseApplication = true;
        $this->canDtsUseIssuance = true;
        $this->canDtsIssuanceReceivingOffice = true;
        $this->canDtsIssuanceTransactionFlow = true;
        $this->canDtsUserReceived = true;
        $this->canDtsForwardOtherOffice = true;
        $this->canDtsModifyTransaction = true;
        $this->canDtsModifyControlNo = true;
        $this->canDtsModifyIssuanceControlNo = true;
        $this->canAccessActivityLogs = true;
        $this->canAccessSubsystems = true;
        $this->canAccessDtsAdmin = true;
        $this->canAccessRdpAdmin = true;
        $this->canAccessDcsAdmin = true;
        $this->canAccessSettings = true;
        $this->canAccessRecycleBin = true;
        $this->rdpViewAllFiles = true;
        $this->isRdpViewAllPendingList = true;
        $this->isRdpViewAllReceivedDocs = true;
        $this->canRdpAccessReceivedDocs = true;
        $this->canRdpAccessAddRecords = true;
        $this->canRdpAccessDrafts = true;
        $this->canRdpAccessPending = true;
        $this->canRdpAccessReferences = true;
        $this->canRdpAccessReports = true;
        $this->canRdpAccessManageFiles = true;
        $this->canRdpAccessForApproval = true;
        $this->canRdpModifySeries = true;
        $this->canRdpGenerateReports = true;
    }

    /**
     * Lifecycle hook executed after updating the can dts use issuance property.
     *
     * @param mixed $value
     *
     * @return void
     */
    public function updatedCanDtsCreateIssuance($value): void
    {
        if ($value) {
            $this->canDtsIssuanceReceivingOffice = true;
            $this->canDtsIssuanceTransactionFlow = true;
        } else {
            $this->canDtsIssuanceReceivingOffice = false;
            $this->canDtsIssuanceTransactionFlow = false;
        }
    }

    public function updatedCanDtsUseIssuance($value): void
    {
        if ($value) {
            $this->canDtsIssuanceReceivingOffice = true;
            $this->canDtsIssuanceTransactionFlow = true;
        } else {
            $this->canDtsIssuanceReceivingOffice = false;
            $this->canDtsIssuanceTransactionFlow = false;
        }
    }

    /**
     * Supplies view data, query collections, and metrics to the Blade template.
     * @return array
     */
    public function with(): array
    {
        $query = \App\Models\role_list::query()->where('is_active', true);

        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function($q) use ($searchVal) {
                $q->where('key_name', 'ilike', $searchVal)
                  ->orWhere('key_description', 'ilike', $searchVal);
            });
        }

        switch ($this->sortBy) {
            case 'description':
                $query->orderBy('key_description', $this->sortDir);
                break;
            case 'id':
                $query->orderBy('id', $this->sortDir);
                break;
            case 'name':
            default:
                $query->orderBy('key_name', $this->sortDir);
                break;
        }

        $roles = $this->layoutMode === 'default' ? $query->paginate(20) : $query->get();

        // Calculate assigned user counts per role for fast display
        $userCounts = \App\Models\User::groupBy('account_role')
            ->selectRaw('account_role, count(*) as count')
            ->pluck('count', 'account_role')
            ->toArray();

        // Assigned users for inspector
        $assignedUsers = collect();
        if ($this->selectedRoleId && $this->selectedRoleId > 0) {
            $assignedUsers = \App\Models\User::where('account_role', $this->selectedRoleId)->limit(8)->get();
        }

        return [
            'roles' => $roles,
            'userCounts' => $userCounts,
            'assignedUsers' => $assignedUsers,
            'moduleCounts' => $this->getModuleCounts(),
        ];
    }
};
?>
@push('styles')
    @vite(['resources/css/admin/accounts_roles.css', 'resources/css/admin/accounts_users.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            user-select: none;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* 4-Column Miller Browser Layout inspired by ESXi File Manager */
        .miller-browser {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 280px 250px 1fr 340px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px 10px 0 0;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            min-height: 540px;
        }

        .miller-column {
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            min-height: 0;
            position: relative;
        }
        .miller-column:nth-child(even) {
            background: #fafbfc;
        }
        .miller-column:last-child {
            border-right: none;
        }

        .m-col-header {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .m-col-header span {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .m-col-search {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            color: #0f172a;
        }
        .m-col-search input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-col-list {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 4px 0;
        }

        .m-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
            gap: 8px;
        }
        .m-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .m-item.active {
            background: #0284c7;
            color: #ffffff;
            border-left-color: #0369a1;
            font-weight: 600;
        }
        .m-item.active .m-item-icon,
        .m-item.active .m-item-arrow,
        .m-item.active .m-item-sub {
            color: #ffffff !important;
            opacity: 0.9;
        }
        .m-item.active .m-item-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.35);
        }

        .m-item-left {
            display: flex;
            align-items: center;
            gap: 9px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1 1 auto;
        }
        .m-item-avatar {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: #e0f2fe;
            color: #0369a1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10.5px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .m-item.active .m-item-avatar {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        .m-item-text {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .m-item-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12.5px;
            font-weight: 600;
        }
        .m-item-sub {
            font-size: 10.5px;
            color: #64748b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .m-item-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .m-item-badge {
            font-size: 10.5px;
            padding: 2px 7px;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 700;
            border: 1px solid #cbd5e1;
        }
        .m-item-arrow {
            font-size: 11px;
            color: #94a3b8;
        }

        /* Permission Toggle Row in Column 3 */
        .m-permission-card {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            transition: background 0.15s ease;
        }
        .m-permission-card:hover {
            background: #f8fafc;
        }
        .m-permission-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1 1 auto;
        }
        .m-permission-title {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
        }
        .m-permission-desc {
            font-size: 11.5px;
            color: #64748b;
            line-height: 1.4;
        }

        /* Switch styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 22px;
            flex-shrink: 0;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .2s;
            border-radius: 22px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        input:checked + .slider {
            background-color: #0284c7;
        }
        input:focus + .slider {
            box-shadow: 0 0 1px #0284c7;
        }
        input:checked + .slider:before {
            transform: translateX(16px);
        }

        /* Column 4: Role Inspector Panel */
        .m-inspector {
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            flex: 1 1 auto;
        }
        .m-inspector-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        .m-inspector-avatar {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.25);
        }
        .m-inspector-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
            margin: 0;
        }
        .m-inspector-sub {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .m-field-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .m-field-label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .m-field-input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12.5px;
            color: #0f172a;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }
        .m-field-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
        }

        .m-metric-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .m-metric-label {
            font-size: 11.5px;
            font-weight: 600;
            color: #64748b;
        }
        .m-metric-value {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
        }

        /* Action Buttons */
        .m-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        .m-btn-primary {
            background: #0284c7;
            color: #ffffff;
        }
        .m-btn-primary:hover {
            background: #0369a1;
        }
        .m-btn-danger {
            background: #ef4444;
            color: #ffffff;
        }
        .m-btn-danger:hover {
            background: #dc2626;
        }
        .m-btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .m-btn-secondary:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .m-btn-pill {
            padding: 3px 8px;
            font-size: 10.5px;
            font-weight: 700;
            border-radius: 9999px;
            cursor: pointer;
            border: 1px solid transparent;
        }

        /* Bottom Breadcrumb Bar */
        .miller-path-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 9px 16px;
            font-size: 12px;
            color: #475569;
            overflow-x: auto;
            white-space: nowrap;
        }
        .miller-path-tag {
            font-weight: 700;
            color: #0f172a;
        }

        /* Empty State */
        .m-empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            text-align: center;
            color: #94a3b8;
            height: 100%;
            box-sizing: border-box;
        }
        .m-empty-icon {
            font-size: 32px;
            margin-bottom: 12px;
            color: #cbd5e1;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .miller-browser,
        [data-theme="dark"] .miller-column {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .miller-column:nth-child(even) {
            background: #182234;
        }
        [data-theme="dark"] .m-col-header,
        [data-theme="dark"] .miller-path-bar {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-col-search {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .m-col-search input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-item {
            color: #cbd5e1;
        }
        [data-theme="dark"] .m-item:hover {
            background: #334155;
            color: #f8fafc;
        }
        [data-theme="dark"] .m-item.active {
            background: #0284c7;
            color: #ffffff;
        }
        [data-theme="dark"] .m-permission-card {
            border-color: #334155;
        }
        [data-theme="dark"] .m-permission-card:hover {
            background: #1e293b;
        }
        [data-theme="dark"] .m-permission-title {
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-permission-desc {
            color: #94a3b8;
        }
        [data-theme="dark"] .m-field-input {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-metric-card {
            background: #0f172a;
            border-color: #334155;
        }
        [data-theme="dark"] .m-metric-label {
            color: #94a3b8;
        }
        [data-theme="dark"] .m-metric-value {
            color: #f1f5f9;
        }
    </style>
@endpush

<div>

@if($layoutMode === 'default')
<div class="admin-roles-outer">
    {{-- ======================================================== --}}
    {{-- MODE: DEFAULT (CLASSIC DUAL-PANE VIEW) --}}
    {{-- ======================================================== --}}
    <div style="max-width: 1600px; margin: 0 auto; padding: 16px 20px 0 20px; box-sizing: border-box;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <h1 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-users-gear" style="color: #0284c7;"></i> Roles & Clearances Console
                </h1>
                <span style="font-size: 11px; font-weight: 700; background: #f1f5f9; color: #475569; padding: 3px 8px; border-radius: 9999px; border: 1px solid #cbd5e1;">
                    Default Layout
                </span>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <x-admin.layout-trigger :mode="$layoutMode" />
                <button type="button" class="btn-create-new" wire:click="startCreate" style="padding: 7px 14px; background: #003699; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
                    <i class="fa-solid fa-plus"></i> New Role
                </button>
            </div>
        </div>
    </div>

    <div class="admin-roles-container {{ !$selectedRoleId ? 'no-selection' : 'has-selection' }}">
    <!-- Left Pane: Roles Directory -->
    <div class="directory-panel">
        <div class="directory-header-row">
            <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Roles Directory</span>
            <button type="button" class="btn-create-new" wire:click="startCreate">
                <i class="fa-solid fa-plus"></i> New Role
            </button>
        </div>

        <div class="directory-controls-bar">
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" class="search-box" placeholder="Search roles..." wire:model.live="search">
            </div>

            <div class="sort-controls-wrapper">
                <span class="sort-label">Sort By:</span>
                <select class="sort-select" wire:model.live="sortBy">
                    <option value="name">Name</option>
                    <option value="description">Description</option>
                    <option value="status">Status</option>
                    <option value="id">ID</option>
                </select>
                <button type="button" wire:click="toggleSortDir" class="btn-sort-dir" title="Toggle Order Direction ({{ strtoupper($sortDir) }})">
                    @if($sortDir === 'asc')
                        <i class="fa-solid fa-arrow-up-a-z"></i>
                    @else
                        <i class="fa-solid fa-arrow-down-z-a"></i>
                    @endif
                </button>
            </div>

            <!-- View Mode Toggle -->
            <div class="view-mode-toggle" style="display: flex; gap: 2px; background: #f1f5f9; padding: 2px; border-radius: 6px; border: 1px solid #cbd5e1; margin-left: auto;">
                <button type="button" wire:click="$set('viewMode', 'grid')" title="Cards Grid Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $viewMode === 'grid' ? '#ffffff' : 'transparent' }}; color: {{ $viewMode === 'grid' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $viewMode === 'grid' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                    <i class="fa-solid fa-border-all"></i> Cards
                </button>
                <button type="button" wire:click="$set('viewMode', 'table')" title="Table Layout" style="padding: 4px 10px; border-radius: 4px; border: none; font-size: 11px; font-weight: 600; cursor: pointer; background: {{ $viewMode === 'table' ? '#ffffff' : 'transparent' }}; color: {{ $viewMode === 'table' ? '#0f172a' : '#64748b' }}; box-shadow: {{ $viewMode === 'table' ? '0 1px 2px rgba(0,0,0,0.08)' : 'none' }}; display: flex; align-items: center; gap: 4px; font-family: 'Inter', sans-serif;">
                    <i class="fa-solid fa-table-list"></i> Table
                </button>
            </div>
        </div>
        
        @if($viewMode === 'table')
            <div class="roles-table-container" style="margin-top: 12px; overflow-x: auto;">
                <table class="users-data-table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; color: #475569;">
                            <th style="padding: 10px 12px;">Role Name</th>
                            <th style="padding: 10px 12px;">Description</th>
                            <th style="padding: 10px 12px;">Assigned Users</th>
                            <th style="padding: 10px 12px;">Status</th>
                            <th style="padding: 10px 12px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($roles as $role)
                            @php
                                $userCount = \App\Models\User::where('account_role', $role->id)->count();
                                $roleInitials = strtoupper(substr($role->key_name ?: '?', 0, 2));
                            @endphp
                            <tr style="border-bottom: 1px solid #f1f5f9; cursor: pointer; background: {{ $selectedRoleId === $role->id ? '#f0f9ff' : 'transparent' }};" wire:click="selectRole({{ $role->id }})" wire:key="role-tbl-{{ $role->id }}">
                                <td style="padding: 10px 12px; font-weight: 600; color: #0f172a;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 28px; height: 28px; flex-shrink: 0; border-radius: 50%; background: #003699; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700;">
                                            {{ $roleInitials }}
                                        </div>
                                        <span>{{ $role->key_name }}</span>
                                    </div>
                                </td>
                                <td style="padding: 10px 12px; color: #475569; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ $role->key_description ?: 'No description provided.' }}
                                </td>
                                <td style="padding: 10px 12px; color: #334155;">
                                    <span style="font-weight: 600;">{{ $userCount }}</span> {{ \Illuminate\Support\Str::plural('user', $userCount) }}
                                </td>
                                <td style="padding: 10px 12px;">
                                    @if($role->is_active)
                                        <span class="role-status-badge active-badge">Active</span>
                                    @else
                                        <span class="role-status-badge inactive-badge">Suspended</span>
                                    @endif
                                </td>
                                <td style="padding: 10px 12px; text-align: right;">
                                    <button type="button" class="btn-table-action" wire:click.stop="selectRole({{ $role->id }})">
                                        <i class="fa-solid fa-pen-to-square"></i> Configure
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="padding: 24px; text-align: center; color: #94a3b8;">
                                    No roles configured.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="roles-list" style="margin-top: 12px;">
                @forelse($roles as $role)
                    @php
                        $roleInitials = strtoupper(substr($role->key_name ?: '?', 0, 2));
                    @endphp
                    <div class="role-item-card {{ $selectedRoleId === $role->id ? 'active' : '' }}" wire:key="role-{{ $role->id }}" wire:click="selectRole({{ $role->id }})">
                        <div class="role-avatar-small">
                            <span>{{ $roleInitials }}</span>
                        </div>
                        <div class="role-meta-info">
                            <span class="role-display-name">{{ $role->key_name }}</span>
                            <span class="role-display-desc">{{ $role->key_description ?: 'No description provided.' }}</span>
                            @if($role->is_active)
                                <span class="role-status-badge active-badge">Active</span>
                            @else
                                <span class="role-status-badge inactive-badge">Suspended</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <div style="text-align: center; color: #94a3b8; padding: 20px; font-family: 'Inter', sans-serif; font-size: 13.5px;">
                        No roles configured.
                    </div>
                @endforelse
            </div>
        @endif

        @if($roles->hasPages())
            <div class="roles-pagination-bar" style="padding-top: 10px; border-top: 1px solid #f1f5f9; display: flex; justify-content: center;">
                {{ $roles->links('components.pagination') }}
            </div>
        @endif
    </div>

    <!-- Right Pane: Role Form Configurator -->
    @if($selectedRoleId)
        <div class="details-panel">
            <!-- Header -->
            <div class="details-header">
                <div class="details-header-avatar">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div class="details-header-info">
                    <h2 class="details-header-name">
                        {{ $selectedRoleId === -1 ? 'Configure New Custom Role' : $keyName }}
                    </h2>
                    <span class="details-header-sub">
                        {{ $selectedRoleId === -1 ? 'Configure access clearance permissions from scratch' : 'Review & adjust active clearance clearances' }}
                    </span>
                </div>
                <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close Details Panel">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Body Form -->
            <div class="details-body">
                @if($successMessage || session('success'))
                    <div class="toast-alert success">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $successMessage ?: session('success') }}</span>
                    </div>
                @endif

                @if($errorMessage || session('error'))
                    <div class="toast-alert error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>{{ $errorMessage ?: session('error') }}</span>
                    </div>
                @endif

                <form wire:submit.prevent="saveRoleChanges" style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Role Name -->
                    <div class="form-group">
                        <span class="form-label">Role Title / Designation</span>
                        <input type="text" class="form-input" placeholder="e.g. Records Liaison Officer" wire:model="keyName">
                        @error('keyName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Role Description -->
                    <div class="form-group">
                        <span class="form-label">Description / Scope of Authority</span>
                        <input type="text" class="form-input" placeholder="e.g. Handles document tracking within college division" wire:model="keyDescription">
                        @error('keyDescription') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Active Toggle Wrapper (Only shown for edit mode, not create mode) -->
                    @if($selectedRoleId > 0)
                        <div class="form-group">
                            <div class="status-toggle-wrapper">
                                <div class="status-toggle-label">
                                    <span class="status-toggle-title">Clearance Active Status</span>
                                    <span class="status-toggle-desc">Toggle whether this role is active or soft-deactivated for transparency.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="isActive">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    @endif

                    <!-- Permissions Clearance Matrix -->
                    <div class="form-group" style="margin-top: 8px;">
                        <span class="form-label" style="display: block; margin-bottom: 12px;">Access Permissions Matrix</span>
                        
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <!-- Category 1: Platform/Subsystem Access -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-desktop"></i> System Access Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- Super Admin -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Super Administrator</span>
                                            <span class="permission-toggle-desc">Unrestricted platform-wide control.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="isSadm">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Normal Admin -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Administrator Access</span>
                                            <span class="permission-toggle-desc">Access Admin Console with restricted permissions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="isAdmin">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- DTS -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DTS</span>
                                            <span class="permission-toggle-desc">Clearance to use Document Tracking System.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDts">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Archives -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Archives</span>
                                            <span class="permission-toggle-desc">Clearance to view/query records disposal archives.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessArchv">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- DCS -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DCS Subsystem</span>
                                            <span class="permission-toggle-desc">Unlocks DCS and defaults to Office Intake below. Switch to Document Controller (Admin DCS) if needed — not both.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="canAccessDcs">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Category 2: Document Tracking System Clearances -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-file-signature"></i> Document Tracking System Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- Modify Docflow -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Doc Flow</span>
                                            <span class="permission-toggle-desc">Clearance to override document track routes.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyDocflow">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all list -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Lists</span>
                                            <span class="permission-toggle-desc">View documents registered across all offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllList" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all archive -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Archives</span>
                                            <span class="permission-toggle-desc">Query and search historical files.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllArchive" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View all current trans -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Current Transactions</span>
                                            <span class="permission-toggle-desc">View all active transactions from all offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllCurrentTrans" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Create own transaction flow -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Create Own Transaction Flow</span>
                                            <span class="permission-toggle-desc">Define and construct custom transaction flows.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canCreateOwnFlow">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Subheader: Create Clearances -->
                                    <div style="grid-column: 1 / -1; margin-top: 8px; margin-bottom: 2px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; font-size: 11px; font-weight: 700; color: #1e40af; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-file-circle-plus" style="color: #2563eb;"></i> Create Transaction Clearances
                                    </div>

                                    <!-- Create Internal Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Create Internal Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to originate and create new Internal Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsCreateInternal">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Create External Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Create External Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to originate and create new External Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsCreateExternal">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Create Application Letter Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Create Application Letter Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to originate and create new Application Letter Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsCreateApplication">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Create Issuance Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Create Issuance Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to originate and create new Issuance Transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="canDtsCreateIssuance">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Issuance Receiving Office Clearance -->
                                    <div class="permission-toggle-row" style="{{ !$canDtsCreateIssuance ? 'opacity: 0.6;' : '' }}">
                                        <div class="permission-toggle-info" style="padding-left: 14px;">
                                            <span class="permission-toggle-title">Issuance - Receiving Office</span>
                                            <span class="permission-toggle-desc">Clearance to route issuances using Receiving Office(s) and Hub broadcast.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsIssuanceReceivingOffice" {{ !$canDtsCreateIssuance ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Issuance Transaction Flow Clearance -->
                                    <div class="permission-toggle-row" style="{{ !$canDtsCreateIssuance ? 'opacity: 0.6;' : '' }}">
                                        <div class="permission-toggle-info" style="padding-left: 14px;">
                                            <span class="permission-toggle-title">Issuance - Transaction Flow</span>
                                            <span class="permission-toggle-desc">Clearance to route issuances using Transaction Flow / Path sequences.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsIssuanceTransactionFlow" {{ !$canDtsCreateIssuance ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>

                                    <!-- Subheader: Receive Clearances -->
                                    <div style="grid-column: 1 / -1; margin-top: 12px; margin-bottom: 2px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; font-size: 11px; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-inbox" style="color: #059669;"></i> Receive & Process Clearances
                                    </div>

                                    <!-- Receive / Process Internal Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Receive Internal Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to view, track, receive, and process incoming Internal Transactions at this station.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsReceiveInternal">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Receive / Process External Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Receive External Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to view, track, receive, and process incoming External Transactions at this station.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsReceiveExternal">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Receive / Process Application Letter Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Receive Application Letters</span>
                                            <span class="permission-toggle-desc">Clearance to view, track, receive, and process incoming Application Letters at this station.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsReceiveApplication">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Receive / Process Issuance Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Receive Issuance Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to view, track, receive, and process incoming Issuances at this station.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsReceiveIssuance">
                                            <span class="slider"></span>
                                        </label>
                                    </div>

                                    <!-- Subheader: Actions & Routing Clearances -->
                                    <div style="grid-column: 1 / -1; margin-top: 12px; margin-bottom: 2px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-sliders" style="color: #64748b;"></i> Processing & Routing Actions
                                    </div>
                                    <!-- Receive Transactions -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Receive & Forward Transactions</span>
                                            <span class="permission-toggle-desc">Clearance to receive, forward, update, or complete transactions. If disabled, actions will be read-only.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsUserReceived">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Forward Transaction to Other office -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Forward Transaction to Other office</span>
                                            <span class="permission-toggle-desc">Clearance to dynamically route and insert an ad-hoc office into the active transaction sequence flow using "To be Forwarded to".</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsForwardOtherOffice">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Transactions -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Transactions Details</span>
                                            <span class="permission-toggle-desc">Clearance to modify metadata details (Control #, Subject/Particulars, CF, etc.) on active transactions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsModifyTransaction" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Control Number on Create -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Control Number</span>
                                            <span class="permission-toggle-desc">Allows user to see and manually edit the control number on the create transaction form. When disabled, control numbers are auto-generated.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsModifyControlNo">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Issuance Control Number -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Issuance Control Number</span>
                                            <span class="permission-toggle-desc">Allows user to manually edit control numbers on Issuances. Restrict exclusively to President/VP functions without full general communication modification clearance.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canDtsModifyIssuanceControlNo">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View All Scanner Codes -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Scanner Codes</span>
                                            <span class="permission-toggle-desc">Scanner's Available Codes lists documents from every office instead of only this station's. Without it, only documents targeted to this station's office appear. Requires Super Administrator or Administrator Access.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canViewAllScannerCodes" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Category: RDP Clearances -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-folder-tree"></i> Records Disposition Program (RDP) Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- RDP Subsystem Clearance -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Portal</span>
                                            <span class="permission-toggle-desc">General clearance to access the Records Disposition Program subsystem.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessArchv">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Received Documents -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Received Documents</span>
                                            <span class="permission-toggle-desc">Clearance to access and view incoming documents in the Received Documents section (DTS, DCS, and Record Requests).</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessReceivedDocs">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Add Records -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Add Records</span>
                                            <span class="permission-toggle-desc">Clearance to access Add Records and encode Inventory & Appraisal or Records Disposition Schedules.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessAddRecords">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Drafts -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Drafts</span>
                                            <span class="permission-toggle-desc">Clearance to access and resume unsubmitted draft appraisals and disposition schedules.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessDrafts">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Pending List -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Pending List</span>
                                            <span class="permission-toggle-desc">Clearance to access the Pending section queue to monitor submitted appraisals.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessPending">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access References -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access References</span>
                                            <span class="permission-toggle-desc">Clearance to access and browse official Record Series classification tables (PH-NAP, CHED, SUC).</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessReferences">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access RDP Reports -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Reports</span>
                                            <span class="permission-toggle-desc">Clearance to access and view RDP report pages (NAP Forms 1, 2, 3 and Generalized Records).</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessReports">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Manage Files -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Manage Files</span>
                                            <span class="permission-toggle-desc">Clearance to access and browse archived files in the Manage Files repository.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessManageFiles">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Generate & Print RDP Reports -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Generate & Print RDP Reports</span>
                                            <span class="permission-toggle-desc">Clearance to configure, create forms (cluster batches), export, and print official NAP forms (Forms 1, 2, 3) and Generalized records.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpGenerateReports">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Record Series & Appraisal -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Record Series & Appraisal</span>
                                            <span class="permission-toggle-desc">Clearance to add, edit, or update record series, inventory appraisal schedules, and retention values.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpModifySeries">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access For Approval Page -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access For Approval Page (RDP)</span>
                                            <span class="permission-toggle-desc">Clearance to view and access the RDP Pending For Approval page to evaluate and approve submissions.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canRdpAccessForApproval">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View All Office Files -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Office Files</span>
                                            <span class="permission-toggle-desc">Clearance to search and inspect repository document files across all college offices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="rdpViewAllFiles" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View All Pending List -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Pending List (RDP)</span>
                                            <span class="permission-toggle-desc">Clearance to view pending submission clusters from all college offices instead of only user's own office.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="isRdpViewAllPendingList">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View All Received Documents -->
                                    <div class="permission-toggle-row">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">View All Received Documents (RDP)</span>
                                            <span class="permission-toggle-desc">Clearance to view received documents from all college offices and use the office filter dropdown.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="isRdpViewAllReceivedDocs">
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Document Control System Clearances (gated by Access DCS only — not Administrative Access) -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-stamp"></i> Document Control System (DCS) Clearances</span>
                                <p class="permission-toggle-desc" style="margin: 0 0 0.75rem;">Access DCS turns Office Intake on by default. Switch to Admin DCS when needed — they cannot be on together. Super Admin already has Admin DCS pages. Recycle Bin is Head Admin only and also cannot combine with Office Intake.</p>
                                <div class="permissions-grid-layout">
                                    <div class="permission-toggle-row" style="{{ (!$canAccessDcs || $dcsCanAdmin || $dcsCanRecycleBin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Office Intake (Client)</span>
                                            <span class="permission-toggle-desc">Client path: My DRF, My DCN, and office documents for submitting offices. Disabled while Admin DCS or Recycle Bin is on.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="dcsCanOfficeIntake" {{ (!$canAccessDcs || $dcsCanAdmin || $dcsCanRecycleBin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="permission-toggle-row" style="{{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Document Controller (Admin DCS)</span>
                                            <span class="permission-toggle-desc">Admin DCS path: Register, Settings, Review intake, Reports, Document Review, Stamping, Database, Manage Files, and Random Check. Granted by role clearance only — any office.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="dcsCanAdmin" {{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <div class="permission-toggle-row" style="{{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Recycle Bin (Head Admin)</span>
                                            <span class="permission-toggle-desc">Head Admin of DCS only. Stacks with Admin DCS: review, restore, or permanently delete soft-deleted documents. Not included with Super Admin or Admin DCS alone.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model.live="dcsCanRecycleBin" {{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Category 3: Security & Accounts Control -->
                            <div class="permissions-section-card">
                                <span class="permissions-section-title"><i class="fa-solid fa-user-lock"></i> Administration Clearances</span>
                                <div class="permissions-grid-layout">
                                    <!-- Modify Users -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Accounts Directory</span>
                                            <span class="permission-toggle-desc">Clearance to alter personal user data.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyUser" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Role lists -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Subsystem Roles</span>
                                            <span class="permission-toggle-desc">Clearance to manage roles and access matrices.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyAccountlist" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Modify Passwords -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Modify Passwords</span>
                                            <span class="permission-toggle-desc">Override and reset user passwords.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canModifyPass" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- View Activity Logs -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Activity Logs</span>
                                            <span class="permission-toggle-desc">Clearance to view the login, transaction, and update log history.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessActivityLogs" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Subsystems -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Manage Subsystems</span>
                                            <span class="permission-toggle-desc">Clearance to add, activate, or deactivate portal subsystems.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessSubsystems" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access DTS Admin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DTS Admin Log</span>
                                            <span class="permission-toggle-desc">Clearance to view transaction log history and predefined flow patterns.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDtsAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access RDP Admin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access RDP Admin Log</span>
                                            <span class="permission-toggle-desc">Clearance to view retention schedules and records logs.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessRdpAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access DCS Admin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access DCS Admin Log</span>
                                            <span class="permission-toggle-desc">Clearance to view Document Control System activity logs in Admin Console.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessDcsAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access System Settings -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Manage System Settings</span>
                                            <span class="permission-toggle-desc">Clearance to adjust portal variables and configurations.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessSettings" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                    <!-- Access Recycle Bin -->
                                    <div class="permission-toggle-row" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5; transition: opacity 0.2s ease;' : '' }}">
                                        <div class="permission-toggle-info">
                                            <span class="permission-toggle-title">Access Recycle Bin</span>
                                            <span class="permission-toggle-desc">Clearance to view and restore deactivated portal items.</span>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" wire:model="canAccessRecycleBin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                            <span class="slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Footer Actions -->
            <div class="details-footer">
                @if($selectedRoleId > 0)
                    <button type="button" class="btn-delete" wire:click="deleteRole" style="margin-right: auto;">
                        <i class="fa-solid fa-trash-can"></i> Delete Role
                    </button>
                @endif
                <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                <button type="button" class="btn-save" wire:click="saveRoleChanges">
                    <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                </button>
            </div>
        </div>
    @endif
</div>
</div>

@else
<div class="miller-container">
    {{-- Top Action Toolbar --}}
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
        <div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <h1 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-users-gear" style="color: #0284c7;"></i> Roles & Clearances Console
                </h1>
                <span style="font-size: 11px; font-weight: 700; background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 9999px; border: 1px solid #bae6fd;">
                    ESXi Miller Browser
                </span>
            </div>
            <p style="font-size: 12px; color: #64748b; margin: 2px 0 0 0;">
                Hierarchical Access Control List (ACL) manager: inspect subsystems, assign granular clearings, and audit roles.
            </p>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            <x-admin.layout-trigger :mode="$layoutMode" />
            <button type="button" wire:click="startCreate" class="m-btn m-btn-primary">
                <i class="fa-solid fa-plus"></i> New Role
            </button>
            @if($selectedRoleId)
                <button type="button" wire:click="cancelSelection" class="m-btn m-btn-secondary" title="Deselect active role">
                    <i class="fa-solid fa-xmark"></i> Close Selection
                </button>
            @endif
        </div>
    </div>

    {{-- Feedback Messages --}}
    @if($successMessage || session('success'))
        <div role="status" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage ?: session('success') }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    @if($errorMessage || session('error'))
        <div role="alert" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage ?: session('error') }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #b91c1c; cursor: pointer; font-size: 16px;">&times;</button>
        </div>
    @endif

    {{-- 4-Column Miller Browser Area --}}
    <div class="miller-browser">
        {{-- COLUMN 1: ROLES DIRECTORY --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span><i class="fa-solid fa-id-badge" style="color: #64748b;"></i> 1. Roles Directory</span>
                <span class="m-item-badge">{{ count($roles) }}</span>
            </div>

            <div class="m-col-search">
                <i class="fa-solid fa-magnifying-glass" style="font-size: 11px; color: #94a3b8;"></i>
                <input type="text" placeholder="Search roles..." wire:model.live="search">
            </div>

            <div class="m-col-list">
                @if($selectedRoleId === -1)
                    <div class="m-item active" style="background: #0284c7;">
                        <div class="m-item-left">
                            <div class="m-item-avatar" style="background: rgba(255,255,255,0.25); color: #fff;">
                                <i class="fa-solid fa-plus"></i>
                            </div>
                            <div class="m-item-text">
                                <span class="m-item-title">{{ $keyName ?: 'New Custom Role' }}</span>
                                <span class="m-item-sub">[Draft Configuration]</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">New</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                @endif

                @forelse($roles as $role)
                    @php
                        $roleInitials = strtoupper(substr($role->key_name ?: '?', 0, 2));
                        $usersCount = $userCounts[$role->id] ?? 0;
                        $isSelected = ($selectedRoleId === $role->id);
                    @endphp
                    <div class="m-item {{ $isSelected ? 'active' : '' }}" wire:click="selectRole({{ $role->id }})" wire:key="role-col-{{ $role->id }}">
                        <div class="m-item-left">
                            <div class="m-item-avatar">
                                {{ $roleInitials }}
                            </div>
                            <div class="m-item-text">
                                <span class="m-item-title">{{ $role->key_name }}</span>
                                <span class="m-item-sub">{{ $role->key_description ?: 'No description' }}</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $usersCount }} {{ $usersCount === 1 ? 'user' : 'users' }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                @empty
                    <div class="m-empty-state">
                        <i class="fa-solid fa-users-slash m-empty-icon"></i>
                        <span style="font-size: 12px; font-weight: 600;">No roles found</span>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- COLUMN 2: CLEARANCE MODULES --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span><i class="fa-solid fa-cubes" style="color: #64748b;"></i> 2. Clearance Modules</span>
                <span class="m-item-badge">5</span>
            </div>

            @if($selectedRoleId)
                <div class="m-col-list">
                    {{-- 1. Core Access & Clearances --}}
                    <div class="m-item {{ $selectedModule === 'core' ? 'active' : '' }}" wire:click="selectModule('core')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-shield-halved m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Core System Authority</span>
                                <span class="m-item-sub">Super Admin & Subsystem Entitlements</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $moduleCounts['core']['active'] }}/{{ $moduleCounts['core']['total'] }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    {{-- 2. Document Tracking System (DTS) --}}
                    <div class="m-item {{ $selectedModule === 'dts' ? 'active' : '' }}" wire:click="selectModule('dts')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-route m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Document Tracking (DTS)</span>
                                <span class="m-item-sub">Transactions, Routing & Scanner</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $moduleCounts['dts']['active'] }}/{{ $moduleCounts['dts']['total'] }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    {{-- 3. Records Disposition Program (RDP) --}}
                    <div class="m-item {{ $selectedModule === 'rdp' ? 'active' : '' }}" wire:click="selectModule('rdp')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-box-archive m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Records Disposal (RDP)</span>
                                <span class="m-item-sub">Series, Appraisal & NAP Reports</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $moduleCounts['rdp']['active'] }}/{{ $moduleCounts['rdp']['total'] }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    {{-- 4. Document Control System (DCS) --}}
                    <div class="m-item {{ $selectedModule === 'dcs' ? 'active' : '' }}" wire:click="selectModule('dcs')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-stamp m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Document Control (DCS)</span>
                                <span class="m-item-sub">Office Intake, Admin DCS & Recycle</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $moduleCounts['dcs']['active'] }}/{{ $moduleCounts['dcs']['total'] }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    {{-- 5. Admin Console Management --}}
                    <div class="m-item {{ $selectedModule === 'admin' ? 'active' : '' }}" wire:click="selectModule('admin')">
                        <div class="m-item-left">
                            <i class="fa-solid fa-sliders m-item-icon" style="font-size: 13px; color: #64748b; width: 18px; text-align: center;"></i>
                            <div class="m-item-text">
                                <span class="m-item-title">Admin Console Logs & Tools</span>
                                <span class="m-item-sub">Logs, Settings & Account Controls</span>
                            </div>
                        </div>
                        <div class="m-item-right">
                            <span class="m-item-badge">{{ $moduleCounts['admin']['active'] }}/{{ $moduleCounts['admin']['total'] }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                </div>
            @else
                <div class="m-empty-state">
                    <i class="fa-solid fa-hand-pointer m-empty-icon"></i>
                    <span style="font-size: 12px; font-weight: 600;">Select a role from Column 1</span>
                    <span style="font-size: 11px; margin-top: 4px;">to view clearance modules</span>
                </div>
            @endif
        </div>

        {{-- COLUMN 3: MODULE CLEARANCES & TOGGLES --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span>
                    @if($selectedModule === 'core')
                        <i class="fa-solid fa-shield-halved" style="color: #64748b;"></i> 3. Core System Clearances
                    @elseif($selectedModule === 'dts')
                        <i class="fa-solid fa-route" style="color: #64748b;"></i> 3. DTS Clearances
                    @elseif($selectedModule === 'rdp')
                        <i class="fa-solid fa-box-archive" style="color: #64748b;"></i> 3. RDP Clearances
                    @elseif($selectedModule === 'dcs')
                        <i class="fa-solid fa-stamp" style="color: #64748b;"></i> 3. DCS Clearances
                    @elseif($selectedModule === 'admin')
                        <i class="fa-solid fa-sliders" style="color: #64748b;"></i> 3. Admin Console Clearances
                    @endif
                </span>

                @if($selectedRoleId)
                    <div style="display: flex; gap: 4px;">
                        <button type="button" wire:click="grantModuleAll('{{ $selectedModule }}')" class="m-btn-pill" style="background: #e0f2fe; color: #0369a1; border-color: #bae6fd;" title="Grant all permissions in this module">
                            Grant All
                        </button>
                        <button type="button" wire:click="revokeModuleAll('{{ $selectedModule }}')" class="m-btn-pill" style="background: #f1f5f9; color: #64748b; border-color: #cbd5e1;" title="Revoke all permissions in this module">
                            Clear
                        </button>
                    </div>
                @endif
            </div>

            @if($selectedRoleId)
                <div class="m-col-search">
                    <i class="fa-solid fa-filter" style="font-size: 11px; color: #94a3b8;"></i>
                    <input type="text" placeholder="Filter permissions in this module..." wire:model.live="permSearch">
                </div>

                <div class="m-col-list">
                    {{-- ========================================== --}}
                    {{-- 1. CORE CLEARANCES --}}
                    {{-- ========================================== --}}
                    @if($selectedModule === 'core')
                        @if(empty($permSearch) || str_contains(strtolower('Super Administrator'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Super Administrator</span>
                                    <span class="m-permission-desc">Unrestricted platform-wide control across all subsystems and system databases.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="isSadm">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Administrator Access'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Administrator Access</span>
                                    <span class="m-permission-desc">Access Admin Console with restricted permissions and administrative authority.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="isAdmin">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access DTS Subsystem'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access DTS Subsystem</span>
                                    <span class="m-permission-desc">Clearance to enter and use the Document Tracking System.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessDts">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access RDP Archives'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access RDP Archives</span>
                                    <span class="m-permission-desc">Clearance to view, query, and manage records disposal archives.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessArchv">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access DCS Subsystem'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access DCS Subsystem</span>
                                    <span class="m-permission-desc">Unlocks DCS and defaults to Office Intake. Mutually exclusive with Document Controller.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="canAccessDcs">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif
                    @endif

                    {{-- ========================================== --}}
                    {{-- 2. DTS CLEARANCES --}}
                    {{-- ========================================== --}}
                    @if($selectedModule === 'dts')
                        @if(empty($permSearch) || str_contains(strtolower('Modify Doc Flow'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Doc Flow</span>
                                    <span class="m-permission-desc">Clearance to override document track routes and station pathways.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canModifyDocflow">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Lists'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Lists (All Offices)</span>
                                    <span class="m-permission-desc">View documents registered across all offices. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canViewAllList" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Archives'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Archives (All Offices)</span>
                                    <span class="m-permission-desc">Query and search historical files across all college departments. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canViewAllArchive" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Current Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Current Transactions</span>
                                    <span class="m-permission-desc">View all active transactions from all offices. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canViewAllCurrentTrans" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Create Own Transaction Flow'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Create Own Transaction Flow</span>
                                    <span class="m-permission-desc">Define and construct custom transaction flows on the fly.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canCreateOwnFlow">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        <!-- Create Clearances -->
                        @if(empty($permSearch) || str_contains(strtolower('Create Internal Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Create Internal Transactions</span>
                                    <span class="m-permission-desc">Clearance to originate and create new Internal Transactions.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsCreateInternal">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Create External Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Create External Transactions</span>
                                    <span class="m-permission-desc">Clearance to originate and create new External Transactions.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsCreateExternal">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Create Application Letter Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Create Application Letter Transactions</span>
                                    <span class="m-permission-desc">Clearance to originate and create new Application Letter Transactions.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsCreateApplication">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Create Issuance Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Create Issuance Transactions</span>
                                    <span class="m-permission-desc">Clearance to originate and create new Issuance Transactions.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="canDtsCreateIssuance">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Issuance - Receiving Office'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ !$canDtsCreateIssuance ? 'opacity: 0.6;' : '' }}">
                                <div class="m-permission-info" style="padding-left: 12px;">
                                    <span class="m-permission-title">Issuance - Receiving Office</span>
                                    <span class="m-permission-desc">Route issuances using Receiving Office(s) and Hub broadcast.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsIssuanceReceivingOffice" {{ !$canDtsCreateIssuance ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Issuance - Transaction Flow'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ !$canDtsCreateIssuance ? 'opacity: 0.6;' : '' }}">
                                <div class="m-permission-info" style="padding-left: 12px;">
                                    <span class="m-permission-title">Issuance - Transaction Flow</span>
                                    <span class="m-permission-desc">Route issuances using Transaction Flow / Path sequences.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsIssuanceTransactionFlow" {{ !$canDtsCreateIssuance ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        <!-- Receive Clearances -->
                        @if(empty($permSearch) || str_contains(strtolower('Receive Internal Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Receive Internal Transactions</span>
                                    <span class="m-permission-desc">Clearance to view, track, receive, and process incoming Internal Transactions at this station.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsReceiveInternal">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Receive External Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Receive External Transactions</span>
                                    <span class="m-permission-desc">Clearance to view, track, receive, and process incoming External Transactions at this station.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsReceiveExternal">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Receive Application Letters'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Receive Application Letters</span>
                                    <span class="m-permission-desc">Clearance to view, track, receive, and process incoming Application Letters at this station.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsReceiveApplication">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Receive Issuance Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Receive Issuance Transactions</span>
                                    <span class="m-permission-desc">Clearance to view, track, receive, and process incoming Issuances at this station.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsReceiveIssuance">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Receive & Forward Transactions'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Receive & Forward Transactions</span>
                                    <span class="m-permission-desc">Clearance to receive, forward, update, or complete transactions. If disabled, actions will be read-only.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsUserReceived">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Forward Transaction to Other office'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Forward Transaction to Other Office</span>
                                    <span class="m-permission-desc">Dynamically route and insert an ad-hoc office into the active transaction sequence flow.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsForwardOtherOffice">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Modify Transactions Details'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Transaction Details</span>
                                    <span class="m-permission-desc">Modify metadata details (Control #, Subject, etc.) on active transactions. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsModifyTransaction" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Modify Control Number'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Control Number</span>
                                    <span class="m-permission-desc">Manually edit the control number on Communications (Internal, External, Application Letters).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsModifyControlNo">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Modify Issuance Control Number'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Issuance Control Number</span>
                                    <span class="m-permission-desc">Manually edit the control number for Issuances (President & VP issuance functions).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canDtsModifyIssuanceControlNo">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Scanner Codes'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Scanner Codes</span>
                                    <span class="m-permission-desc">Scanner lists documents from every office instead of only user's station. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canViewAllScannerCodes" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif
                    @endif

                    {{-- ========================================== --}}
                    {{-- 3. RDP CLEARANCES --}}
                    {{-- ========================================== --}}
                    @if($selectedModule === 'rdp')
                        @if(empty($permSearch) || str_contains(strtolower('Access RDP Portal'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access RDP Portal</span>
                                    <span class="m-permission-desc">General clearance to access the Records Disposition Program subsystem.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessArchv">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Received Documents'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Received Documents</span>
                                    <span class="m-permission-desc">Clearance to access and view incoming documents in the Received Documents section (DTS, DCS, and Record Requests).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessReceivedDocs">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Add Records'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Add Records</span>
                                    <span class="m-permission-desc">Clearance to access Add Records and encode Inventory & Appraisal or Records Disposition Schedules.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessAddRecords">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Drafts'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Drafts</span>
                                    <span class="m-permission-desc">Clearance to access and resume unsubmitted draft appraisals and disposition schedules.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessDrafts">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Pending List'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Pending List</span>
                                    <span class="m-permission-desc">Clearance to access the Pending section queue to monitor submitted appraisals.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessPending">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access References'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access References</span>
                                    <span class="m-permission-desc">Clearance to access and browse official Record Series classification tables (PH-NAP, CHED, SUC).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessReferences">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access RDP Reports'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access RDP Reports</span>
                                    <span class="m-permission-desc">Clearance to access and view RDP report pages (NAP Forms 1, 2, 3 and Generalized Records).</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessReports">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Manage Files'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Manage Files</span>
                                    <span class="m-permission-desc">Clearance to access and browse archived files in the Manage Files repository.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessManageFiles">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Generate & Print RDP Reports'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Generate & Print RDP Reports</span>
                                    <span class="m-permission-desc">Clearance to configure, create forms (cluster batches), export, and print official NAP forms (Forms 1, 2, 3) and Generalized records.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpGenerateReports">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Modify Record Series & Appraisal'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Record Series & Appraisal</span>
                                    <span class="m-permission-desc">Add, edit, or update record series, inventory appraisal schedules, and retention values.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpModifySeries">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access For Approval Page'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access For Approval Page</span>
                                    <span class="m-permission-desc">View and access the RDP Pending For Approval page to evaluate and approve submissions.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canRdpAccessForApproval">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Office Files'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Office Files</span>
                                    <span class="m-permission-desc">Search and inspect repository document files across all college offices. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="rdpViewAllFiles" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Pending List'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Pending List (RDP)</span>
                                    <span class="m-permission-desc">View pending submission clusters from all college offices instead of only user's office.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="isRdpViewAllPendingList">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('View All Received Documents'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">View All Received Documents</span>
                                    <span class="m-permission-desc">View received documents from all college offices and use the office filter dropdown.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="isRdpViewAllReceivedDocs">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif
                    @endif

                    {{-- ========================================== --}}
                    {{-- 4. DCS CLEARANCES --}}
                    {{-- ========================================== --}}
                    @if($selectedModule === 'dcs')
                        <div style="padding: 10px 14px; background: #f0fdf4; border-bottom: 1px solid #bbf7d0; font-size: 11.5px; color: #166534; font-weight: 500;">
                            <i class="fa-solid fa-circle-info" style="margin-right: 4px;"></i> Note: <strong>Office Intake</strong> (client path) and <strong>Document Controller</strong> (admin path) are mutually exclusive.
                        </div>

                        @if(empty($permSearch) || str_contains(strtolower('Access DCS Subsystem'), strtolower($permSearch)))
                            <div class="m-permission-card">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access DCS Subsystem</span>
                                    <span class="m-permission-desc">Clearance to enter the Document Control System.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="canAccessDcs">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Office Intake (Client)'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$canAccessDcs || $dcsCanAdmin || $dcsCanRecycleBin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Office Intake (Client Path)</span>
                                    <span class="m-permission-desc">Client path: My DRF, My DCN, and office documents for submitting offices.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="dcsCanOfficeIntake" {{ (!$canAccessDcs || $dcsCanAdmin || $dcsCanRecycleBin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Document Controller (Admin DCS)'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Document Controller (Admin DCS)</span>
                                    <span class="m-permission-desc">Full Document Central admin pages: Register, Settings, Intake Review, Stamping, Database, Files, and Random Check.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="dcsCanAdmin" {{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Recycle Bin (Head Admin)'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Recycle Bin (Head Admin)</span>
                                    <span class="m-permission-desc">Review, restore, or permanently delete soft-deleted documents. Not combined with Office Intake.</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model.live="dcsCanRecycleBin" {{ (!$canAccessDcs || $dcsCanOfficeIntake) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif
                    @endif

                    {{-- ========================================== --}}
                    {{-- 5. ADMIN CONSOLE CLEARANCES --}}
                    {{-- ========================================== --}}
                    @if($selectedModule === 'admin')
                        @if(empty($permSearch) || str_contains(strtolower('Modify Accounts Directory'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Accounts Directory</span>
                                    <span class="m-permission-desc">Clearance to alter personal user data and staff accounts. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canModifyUser" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Modify Subsystem Roles'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Subsystem Roles</span>
                                    <span class="m-permission-desc">Clearance to manage roles, create new designations, and edit access matrices. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canModifyAccountlist" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Modify Passwords'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Modify Passwords</span>
                                    <span class="m-permission-desc">Override and reset user passwords directly. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canModifyPass" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Activity Logs'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Activity Logs</span>
                                    <span class="m-permission-desc">Clearance to view the login, transaction, and update audit log history. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessActivityLogs" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Manage Subsystems'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Manage Subsystems</span>
                                    <span class="m-permission-desc">Clearance to add, activate, or deactivate portal subsystems. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessSubsystems" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access DTS Admin Log'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access DTS Admin Log</span>
                                    <span class="m-permission-desc">Clearance to view DTS transaction history and predefined flow patterns. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessDtsAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access RDP Admin Log'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access RDP Admin Log</span>
                                    <span class="m-permission-desc">Clearance to view retention schedules and records logs. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessRdpAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access DCS Admin Log'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access DCS Admin Log</span>
                                    <span class="m-permission-desc">Clearance to view Document Control activity logs in Admin Console. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessDcsAdmin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Manage System Settings'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Manage System Settings</span>
                                    <span class="m-permission-desc">Adjust portal parameters, SSO, Google Drive, and NAS/S3 storage. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessSettings" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif

                        @if(empty($permSearch) || str_contains(strtolower('Access Recycle Bin'), strtolower($permSearch)))
                            <div class="m-permission-card" style="{{ (!$isSadm && !$isAdmin) ? 'opacity: 0.5;' : '' }}">
                                <div class="m-permission-info">
                                    <span class="m-permission-title">Access Recycle Bin</span>
                                    <span class="m-permission-desc">View and restore deactivated portal items and records. (Requires Admin Access)</span>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" wire:model="canAccessRecycleBin" {{ (!$isSadm && !$isAdmin) ? 'disabled' : '' }}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        @endif
                    @endif
                </div>
            @else
                <div class="m-empty-state">
                    <i class="fa-solid fa-list-check m-empty-icon"></i>
                    <span style="font-size: 12px; font-weight: 600;">No role selected</span>
                    <span style="font-size: 11px; margin-top: 4px;">Select a role to configure granular permissions</span>
                </div>
            @endif
        </div>

        {{-- COLUMN 4: ROLE SUMMARY & INSPECTOR --}}
        <div class="miller-column">
            <div class="m-col-header">
                <span><i class="fa-solid fa-magnifying-glass-chart" style="color: #64748b;"></i> 4. Role Inspector</span>
                @if($selectedRoleId)
                    <span class="m-item-badge" style="background: {{ $isActive ? '#dcfce7' : '#fee2e2' }}; color: {{ $isActive ? '#15803d' : '#991b1b' }}; border-color: {{ $isActive ? '#bbf7d0' : '#fecaca' }};">
                        {{ $isActive ? 'Active' : 'Suspended' }}
                    </span>
                @endif
            </div>

            @if($selectedRoleId)
                <div class="m-inspector">
                    {{-- Header Profile Card --}}
                    <div class="m-inspector-header">
                        <div class="m-inspector-avatar">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <div style="overflow: hidden; flex: 1 1 auto;">
                            <h3 class="m-inspector-title">{{ $keyName ?: 'Untitled Role' }}</h3>
                            <span class="m-inspector-sub">{{ $selectedRoleId === -1 ? 'New Role Configuration' : 'Role ID #' . $selectedRoleId }}</span>
                        </div>
                    </div>

                    {{-- Form Inputs --}}
                    <div class="m-field-group">
                        <label class="m-field-label">Role Title / Designation</label>
                        <input type="text" class="m-field-input" placeholder="e.g. Records Officer" wire:model="keyName">
                        @error('keyName') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                    </div>

                    <div class="m-field-group">
                        <label class="m-field-label">Scope / Authority Description</label>
                        <textarea class="m-field-input" rows="2" placeholder="e.g. Handles document tracking within college division" wire:model="keyDescription" style="resize: vertical;"></textarea>
                        @error('keyDescription') <span style="color:#ef4444; font-size:11px;">{{ $message }}</span> @enderror
                    </div>

                    @if($selectedRoleId > 0)
                        <div class="m-metric-card">
                            <span class="m-metric-label">Active Status</span>
                            <label class="switch">
                                <input type="checkbox" wire:model="isActive">
                                <span class="slider"></span>
                            </label>
                        </div>
                    @endif

                    {{-- Coverage Meter --}}
                    <div class="m-metric-card" style="flex-direction: column; align-items: stretch; gap: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span class="m-metric-label">Clearance Coverage</span>
                            <span class="m-metric-value" style="color: #0284c7;">{{ $moduleCounts['totalActive'] }} / {{ $moduleCounts['totalClearances'] }}</span>
                        </div>
                        <div style="width: 100%; height: 6px; background: #e2e8f0; border-radius: 9999px; overflow: hidden;">
                            @php
                                $pct = round(($moduleCounts['totalActive'] / max(1, $moduleCounts['totalClearances'])) * 100);
                            @endphp
                            <div style="width: {{ $pct }}%; height: 100%; background: #0284c7; border-radius: 9999px; transition: width 0.3s ease;"></div>
                        </div>
                    </div>

                    {{-- Assigned Users Metric --}}
                    @if($selectedRoleId > 0)
                        <div class="m-metric-card" style="flex-direction: column; align-items: stretch; gap: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="m-metric-label">Assigned Users</span>
                                <span class="m-metric-value">{{ $userCounts[$selectedRoleId] ?? 0 }}</span>
                            </div>

                            @if(count($assignedUsers) > 0)
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    @foreach($assignedUsers as $u)
                                        <span style="font-size: 11px; background: #e2e8f0; color: #334155; padding: 2px 7px; border-radius: 9999px; font-weight: 600;">
                                            {{ $u->username }}
                                        </span>
                                    @endforeach
                                    @if(($userCounts[$selectedRoleId] ?? 0) > count($assignedUsers))
                                        <span style="font-size: 11px; color: #64748b; padding: 2px 4px;">
                                            +{{ ($userCounts[$selectedRoleId] ?? 0) - count($assignedUsers) }} more
                                        </span>
                                    @endif
                                </div>
                            @else
                                <span style="font-size: 11px; color: #94a3b8; font-style: italic;">No users assigned this role yet.</span>
                            @endif
                        </div>
                    @endif

                    {{-- Sticky Action Buttons at bottom of Inspector --}}
                    <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                        <button type="button" class="m-btn m-btn-primary" wire:click="saveRoleChanges" wire:loading.attr="disabled" style="width: 100%;">
                            <i class="fa-solid fa-floppy-disk" wire:loading.remove wire:target="saveRoleChanges"></i>
                            <i class="fa-solid fa-spinner fa-spin" wire:loading wire:target="saveRoleChanges"></i>
                            <span>Save Configuration</span>
                        </button>

                        <div style="display: flex; gap: 8px;">
                            @if($selectedRoleId > 0)
                                <button type="button" class="m-btn m-btn-danger" wire:click="deleteRole" wire:confirm="Are you sure you want to soft-deactivate this role?" style="flex: 1;" title="Soft-deactivate role">
                                    <i class="fa-solid fa-trash-can"></i> Deactivate
                                </button>
                            @endif
                            <button type="button" class="m-btn m-btn-secondary" wire:click="cancelSelection" style="flex: 1;">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            @else
                <div class="m-empty-state">
                    <i class="fa-solid fa-user-shield m-empty-icon"></i>
                    <span style="font-size: 13px; font-weight: 700; color: #475569;">Role Inspector</span>
                    <span style="font-size: 11.5px; margin-top: 4px; max-width: 220px; line-height: 1.4;">
                        Select a role or click <strong>+ New Role</strong> to inspect details and assign permission matrices.
                    </span>
                </div>
            @endif
        </div>
    </div>

    {{-- Bottom Breadcrumb Path Bar (ESXi Style) --}}
    <div class="miller-path-bar">
        <div style="display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-folder-tree" style="color: #64748b;"></i>
            <span>Roles Console</span>
            <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
            @if($selectedRoleId)
                <span class="miller-path-tag">{{ $keyName ?: 'Draft Role' }}</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span>{{ strtoupper($selectedModule) }}</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span style="color: #0284c7; font-weight: 700;">{{ $moduleCounts['totalActive'] }} Active Clearances</span>
            @else
                <span style="color: #94a3b8; font-style: italic;">No role selected</span>
            @endif
        </div>

        @if($selectedRoleId)
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" wire:click="saveRoleChanges" style="background: none; border: none; color: #0284c7; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                    <i class="fa-solid fa-floppy-disk"></i> Quick Save
                </button>
            </div>
        @endif
    </div>

    
@endif

{{-- Super Admin Verification Modal --}}
    @if($showVerificationModal)
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center;">
            <div style="background: white; border-radius: 12px; padding: 24px; width: 420px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); display: flex; flex-direction: column; gap: 18px; border: 1px solid #cbd5e1; font-family: 'Inter', system-ui, sans-serif;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0369a1;">
                        <i class="fa-solid fa-shield-halved" style="font-size: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 15px; font-weight: 800; color: #0f172a; margin: 0;">Super Admin Authorization</h3>
                        <p style="font-size: 11.5px; color: #64748b; margin: 2px 0 0 0;">Authentication required to toggle Super Admin clearance.</p>
                    </div>
                </div>
                
                @if($verificationError)
                    <div style="background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; padding: 9px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>{{ $verificationError }}</span>
                    </div>
                @endif

                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em;">Super Admin Username</label>
                        <input type="text" placeholder="Enter username..." wire:model="verifyUsername" style="height: 36px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 10px; font-size: 13px; outline: none; width: 100%; box-sizing: border-box;" required>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.04em;">Super Admin Password</label>
                        <input type="password" placeholder="Enter password..." wire:model="verifyPassword" style="height: 36px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0 10px; font-size: 13px; outline: none; width: 100%; box-sizing: border-box;" required>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; border-top: 1px solid #f1f5f9; padding-top: 14px;">
                    <button type="button" wire:click="cancelVerification" class="m-btn m-btn-secondary">Cancel</button>
                    <button type="button" wire:click="submitVerification" class="m-btn m-btn-primary">Verify & Enable</button>
                </div>
            </div>
        </div>
    @endif
</div>

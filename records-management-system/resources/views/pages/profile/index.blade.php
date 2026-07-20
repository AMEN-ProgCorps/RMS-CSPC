<?php
/**
 * Profile Manager - Details Volt Component
 * 
 * This component provides an interface for users to view their account details,
 * active role, and system permissions. Sensitive details like email and contacts
 * are masked by default and can be unmasked interactively.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.profile')] #[Title('Profile Manager - Details')] class extends Component {
    /** @var string First name of the authenticated user */
    public string $firstName     = '';

    /** @var string Last name of the authenticated user */
    public string $lastName      = '';

    /** @var string Middle name of the authenticated user */
    public string $middleName    = '';

    /** @var string Masked email address of the user */
    public string $email         = '';

    /** @var string Masked contact number of the user */
    public string $contactNumber = '';

    /** @var int Account ID of the authenticated user */
    public int    $accountId     = 0;

    /** @var string Role name associated with the user account */
    public string $roleName      = '';

    /** @var array<int, string> List of human-readable permissions assigned to this user */
    public array  $enabledPermissions = [];

    public ?int $currentRoleId = null;

    /**
     * Component Mount hook - populates user attributes and maps roles/permissions.
     */
    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $user = auth()->user()->fresh();
        
        $newRoleId = $user->account_role;
        $newPermissions = [];

        $perms = $user->permissions;
        if ($perms) {
            $labels = [
                'is_sadm'                        => 'Super Administrator',
                'is_admin'                       => 'Administrator',
                'can_access_dts'                 => 'Access Document Tracking System',
                'can_access_rdp'                 => 'Access Archive',
                'can_access_dcs'                 => 'Access DCS',
                'can_dts_modify_docflow'         => 'Modify Document Flow',
                'can_sadm_modify_accountlist'    => 'Modify Account List',
                'can_sadm_modify_pass'           => 'Modify Passwords',
                'can_sadm_modify_account'        => 'Modify Users',
                'can_dts_view_all_list'          => 'View All Lists',
                'can_dts_view_all_archive'       => 'View All Archives',
                'can_dts_view_all_current_trans' => 'View All Current Transactions',
                'can_dts_create_own_flow'        => 'Create Own Transaction Flow',
                'can_dts_use_internal'           => 'Access Internal Transactions',
                'can_dts_use_external'           => 'Access External Transactions',
                'can_dts_use_application'        => 'Access Application Letters',
                'can_dts_use_issuance'           => 'Access Issuances',
                'can_dts_user_received'          => 'Receive Documents',
                'can_dts_modify_transaction'     => 'Modify Transactions',
                'can_dts_modify_control_no'      => 'Modify Control Number',
                'can_access_activity_logs'       => 'Access Activity Logs',
                'can_access_subsystems'          => 'Access Subsystems',
                'can_access_dts_admin'           => 'Access DTS Admin',
                'can_access_rdp_admin'           => 'Access RDP Admin',
                'can_access_settings'            => 'Access Settings',
                'can_access_recycle_bin'         => 'Access Recycle Bin',
            ];

            foreach ($labels as $key => $label) {
                if ($perms->$key) {
                    $newPermissions[] = $label;
                }
            }
        }

        // If this is a poll request and we detect a change in role or permissions
        if ($this->currentRoleId !== null) {
            if ($newRoleId !== $this->currentRoleId || $newPermissions !== $this->enabledPermissions) {
                $this->js('window.location.reload();');
                return;
            }
        }

        $this->currentRoleId = $newRoleId;
        $this->enabledPermissions = $newPermissions;

        $details = $user->details;
        if ($details) {
            $this->firstName     = $details->first_name     ?? '';
            $this->lastName      = $details->last_name      ?? '';
            $this->middleName    = $details->middle_name    ?? '';
            $this->email         = $details->email          ?? '';
            $this->contactNumber = $details->contact_number ?? '';
            $this->accountId     = $details->account_id;
        }

        // Fetch and map Role name from database condition keys
        $this->roleName = 'Unknown';
        if ($user->account_role) {
            $role = \DB::table('condition_key')->where('id', $user->account_role)->first();
            $this->roleName = $role?->key_name ?? 'Unknown';
        }
    }
};
?>

@push('styles')
    @vite('resources/css/profile/personal_details.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="container personal-details-container" wire:poll.5s="refresh">
    <!-- Hero Banner -->
    <div class="profile-hero-banner">
        <div class="hero-left">
            <div class="avatar-circle">
                <span>{{ strtoupper(substr($firstName ?: '?', 0, 1) . substr($lastName ?: '?', 0, 1)) }}</span>
            </div>
            <div class="hero-user-info">
                <span class="hero-greeting">Welcome back,</span>
                <h1 class="hero-name">{{ $firstName }} {{ $lastName }}</h1>
                <span class="hero-role-badge">
                    <i class="fa-solid fa-shield-halved"></i> {{ $roleName ?: 'User' }}
                </span>
            </div>
        </div>
        <div class="hero-right">
            <div class="hero-clock" wire:ignore>
                <span id="currTime" class="clock-time">--:--:--</span>
                <span id="currDate" class="clock-date">--</span>
            </div>
        </div>
    </div>

    <!-- Details Grid -->
    <div class="grid-layout">
        <!-- Account Details Card -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-user-gear"></i> Account Details
            </h2>
            
            <div class="detail-row" setid="account">
                <span class="detail-label">Account ID</span>
                <span class="detail-value">{{ $accountId }}</span>
            </div>
            
            <div class="detail-row" setid="firstname">
                <span class="detail-label">First Name</span>
                <span class="detail-value">{{ $firstName }}</span>
            </div>
            
            <div class="detail-row" setid="middlename">
                <span class="detail-label">Middle Name</span>
                <span class="detail-value">{{ $middleName ?: '—' }}</span>
            </div>
            
            <div class="detail-row" setid="Lastname">
                <span class="detail-label">Last Name</span>
                <span class="detail-value">{{ $lastName }}</span>
            </div>
            
            <div class="detail-row" setid="email" x-data="{ masked: true }">
                <span class="detail-label">Email</span>
                <div class="masked-field">
                    <span class="masked-value" data-masked="true" x-text="masked ? '{{ str_repeat('•', max(strlen($email), 12)) }}' : '{{ addslashes($email) }}'">{{ str_repeat('•', max(strlen($email), 12)) }}</span>
                    <button type="button" class="mask-toggle" data-target="email" @click="masked = !masked" aria-label="Show hidden email">
                        <span class="eye-icon">
                            <i class="fa-solid" :class="masked ? 'fa-eye' : 'fa-eye-slash'"></i>
                        </span>
                    </button>
                </div>
            </div>
            
            <div class="detail-row" setid="contacts" x-data="{ masked: true }">
                <span class="detail-label">Contact Number</span>
                <div class="masked-field">
                    <span class="masked-value" data-masked="true" x-text="masked ? '••••••••' : '{{ addslashes($contactNumber ?: '—') }}'">••••••••</span>
                    <button type="button" class="mask-toggle" data-target="contacts" @click="masked = !masked" aria-label="Show hidden contact number">
                        <span class="eye-icon">
                            <i class="fa-solid" :class="masked ? 'fa-eye' : 'fa-eye-slash'"></i>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Role & Permissions Card -->
        <div class="profile-card">
            <h2 class="card-title">
                <i class="fa-solid fa-key"></i> System Access & Permissions
            </h2>
            
            <div class="detail-row" setid="role_status">
                <span class="detail-label">Current Role</span>
                <span class="detail-value" style="font-weight: 700; color: #003699;">{{ $roleName ?: 'No Role Assigned' }}</span>
            </div>

            <div style="margin-top: 15px;">
                <span class="detail-label" style="display: block; margin-bottom: 10px;">Assigned Privileges</span>
                <div class="permissions-grid">
                    @forelse($enabledPermissions as $index => $permission)
                        <div class="permission-badge" setid="function_{{ $index + 1 }}">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>{{ $permission }}</span>
                        </div>
                    @empty
                        <div class="no-permissions" setid="function_none">
                            <span>No permissions assigned to this account.</span>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>


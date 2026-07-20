<?php
/**
 * Admin Console - Accounts Management Users Volt Component
 * 
 * This component provides an interface for administrators to manage user accounts.
 * Features:
 *  - Real-time search (by name, email, or username)
 *  - Interactive directory listing
 *  - Configurable sorting (by name, username, assigned role, assigned office, or date created)
 *  - Custom sort direction toggles (ascending / descending)
 *  - Inline form editor (updates personal details, role, office, active state)
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Admin Console - Users')] class extends Component {
    use WithPagination;

    /** @var string Holds the active search input value */
    public string $search = '';

    /** @var string Holds the column key to sort users by */
    public string $sortBy = 'name';

    /** @var string Sorting order direction ('asc' or 'desc') */
    public string $sortDir = 'asc';

    /** @var int|null The ID of the currently selected user profile being viewed/edited. (-1 = create mode) */
    public ?int $selectedUserId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSortBy(): void
    {
        $this->resetPage();
    }

    public function updatingSortDir(): void
    {
        $this->resetPage();
    }

    /**
     * Toggles the sort direction between ascending (asc) and descending (desc).
     * Automatically clears any active success or error banners.
     */
    public function toggleSortDir(): void
    {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
        $this->clearMessages();
    }
    
    /** @var string Holds the active user's first name for editing */
    public string $firstName = '';

    /** @var string Holds the active user's last name for editing */
    public string $lastName = '';

    /** @var string Holds the active user's middle name for editing */
    public string $middleName = '';

    /** @var string Holds the active user's email address for editing */
    public string $email = '';

    /** @var string Holds the active user's contact number for editing */
    public string $contactNumber = '';

    /** @var string Holds the active user's username */
    public string $username = '';

    /** @var string Holds the password for creation mode */
    public string $password = '';

    /** @var string Holds the password confirmation for creation mode */
    public string $password_confirmation = '';

    /** @var int|null ID of the selected role (references key_id/id in condition_key) */
    public ?int $roleId = null;

    /** @var int|null ID of the selected office (references id in office) */
    public ?int $officeId = null;

    /** @var bool Active status of the user account */
    public bool $isActive = true;

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->is_admin && !$perms->can_sadm_modify_accountlist && !$perms->can_sadm_modify_account)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /** @var string Holds successful transaction alert messages */
    public string $successMessage = '';

    /** @var string Holds failure transaction alert messages */
    public string $errorMessage = '';

    // Searchable dropdown properties
    public string $roleSearch = '';
    public bool $showRoleDropdown = false;
    public string $officeSearch = '';
    public bool $showOfficeDropdown = false;
    public bool $showResetPasswordContainer = false;

    public function toggleResetPasswordContainer(): void
    {
        $this->showResetPasswordContainer = !$this->showResetPasswordContainer;
        if (!$this->showResetPasswordContainer) {
            $this->password = '';
            $this->password_confirmation = '';
        }
    }

    /**
     * Initializes "Create Mode" for configuring a new user profile.
     */
    public function startCreate(): void
    {
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            return;
        }
        $this->cancelSelection();
        $this->selectedUserId = -1; // -1 denotes "Create Mode"
    }

    /**
     * Selects a user from the left-hand directory list.
     * Fetches user credentials and details, populating form fields.
     * 
     * @param int $id The ID of the user record to select
     */
    public function selectUser(int $id): void
    {
        $this->cancelSelection();
        $this->selectedUserId = $id;
        
        $user = \App\Models\User::with('details')->find($id);
        if ($user) {
            $this->username = $user->username;
            $this->roleId = $user->account_role;
            if ($this->roleId) {
                $role = \App\Models\role_list::find($this->roleId);
                $this->roleSearch = $role ? $role->key_name : '';
            } else {
                $this->roleSearch = '';
            }
            $this->isActive = (bool) $user->account_active;
            
            $details = $user->details;
            if ($details) {
                $this->firstName = $details->first_name ?? '';
                $this->lastName = $details->last_name ?? '';
                $this->middleName = $details->middle_name ?? '';
                $this->email = $details->email ?? '';
                $this->contactNumber = $details->contact_number ?? '';
                $this->officeId = $details->office_id;
                if ($this->officeId) {
                    $office = \App\Models\office::find($this->officeId);
                    $this->officeSearch = $office ? $office->office_name : '';
                } else {
                    $this->officeSearch = '';
                }
            }
        }
    }

    /**
     * Clears all session toast/alert message banners.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Resets the selected user state, hiding the edit form.
     */
    public function cancelSelection(): void
    {
        $this->selectedUserId = null;
        $this->username = '';
        $this->firstName = '';
        $this->lastName = '';
        $this->middleName = '';
        $this->email = '';
        $this->contactNumber = '';
        $this->password = '';
        $this->password_confirmation = '';
        $this->roleId = null;
        $this->officeId = null;
        $this->isActive = true;
        $this->roleSearch = '';
        $this->showRoleDropdown = false;
        $this->officeSearch = '';
        $this->showOfficeDropdown = false;
        $this->showResetPasswordContainer = false;
        $this->clearMessages();
    }

    public function selectRole(?int $id, string $name): void
    {
        $this->roleId = $id;
        $this->roleSearch = $name;
        $this->showRoleDropdown = false;
    }

    public function selectOffice(?int $id, string $name): void
    {
        $this->officeId = $id;
        $this->officeSearch = $name;
        $this->showOfficeDropdown = false;
    }

    /**
     * Validates and saves changes made to the user's details and settings.
     * Performed inside a Database Transaction to preserve data integrity.
     */
    public function saveUserChanges(): void
    {
        if (!$this->selectedUserId) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_account clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to modify user accounts.';
            return;
        }

        // 1. Validation Rules
        if ($this->selectedUserId === -1) {
            // Create mode validation rules
            $this->validate([
                'username' => 'required|string|max:255|unique:account,username',
                'password' => 'required|string|min:8|confirmed',
                'firstName' => 'required|string|max:255',
                'lastName' => 'required|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => 'required|email|max:255|unique:account_details,email',
                'contactNumber' => 'nullable|string|max:25',
                'roleId' => 'required|exists:condition_key,id',
                'officeId' => 'nullable|exists:office,id',
            ]);
        } else {
            // Edit mode validation rules
            $rules = [
                'username' => 'required|string|max:255|unique:account,username,' . $this->selectedUserId . ',id',
                'firstName' => 'required|string|max:255',
                'lastName' => 'required|string|max:255',
                'middleName' => 'nullable|string|max:255',
                'email' => 'required|email|max:255|unique:account_details,email,' . $this->selectedUserId . ',account_id',
                'contactNumber' => 'nullable|string|max:25',
                'roleId' => 'required|exists:condition_key,id',
                'officeId' => 'nullable|exists:office,id',
            ];

            if ($this->password !== '') {
                $rules['password'] = 'required|string|min:8|confirmed';
            }

            $this->validate($rules);
        }

        try {
            \DB::transaction(function () {
                if ($this->selectedUserId === -1) {
                    // Create account record
                    $user = new \App\Models\User();
                    $user->username = $this->username;
                    $user->password = \Hash::make($this->password);
                    $user->account_status = 1;
                    $user->account_role = $this->roleId;
                    $user->account_active = $this->isActive;
                    $user->date_created = now();
                    $user->date_updated = now();
                    $user->save();

                    // Create account details record
                    $details = new \App\Models\AccountDetail();
                    $details->account_id = $user->id;
                    $details->first_name = $this->firstName;
                    $details->last_name = $this->lastName;
                    $details->middle_name = $this->middleName;
                    $details->email = $this->email;
                    $details->contact_number = $this->contactNumber;
                    $details->office_id = $this->officeId;
                    $details->is_currently_online = false;
                    $details->save();

                    // Audit Log: created user account
                    \DB::table('admin_logs')->insert([
                        'changes' => "Created user account: {$user->username} ({$this->firstName} {$this->lastName})",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Update local state to switch into edit mode for the new user
                    $this->selectedUserId = $user->id;
                    
                    // Clear out sensitive password inputs
                    $this->password = '';
                    $this->password_confirmation = '';
                    
                    $this->successMessage = 'User account created successfully!';
                } else {
                    // Update general Account details
                    $user = \App\Models\User::findOrFail($this->selectedUserId);
                    
                    // Detect if status changed
                    $statusChanged = $user->account_active != $this->isActive;

                    $userData = [
                        'username' => $this->username,
                        'account_role' => $this->roleId,
                        'account_active' => $this->isActive,
                        'date_updated' => now(),
                    ];

                    if ($this->password !== '') {
                        $userData['password'] = \Hash::make($this->password);
                    }

                    $user->update($userData);

                    if ($this->password !== '') {
                        \DB::table('admin_logs')->insert([
                            'changes' => "Reset password for user: {$user->username}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3, // Admin Console
                            'when_changes' => now()
                        ]);
                    }

                    // Clear out sensitive password inputs
                    $this->password = '';
                    $this->password_confirmation = '';

                    // Update/Create personal Profile details
                    $details = \App\Models\AccountDetail::find($this->selectedUserId);
                    if (!$details) {
                        $details = new \App\Models\AccountDetail();
                        $details->account_id = $this->selectedUserId;
                    }
                    $details->first_name = $this->firstName;
                    $details->last_name = $this->lastName;
                    $details->middle_name = $this->middleName;
                    $details->email = $this->email;
                    $details->contact_number = $this->contactNumber;
                    $details->office_id = $this->officeId;
                    $details->save();

                    // Audit Log: updated details
                    \DB::table('admin_logs')->insert([
                        'changes' => "Updated details for user: {$user->username}",
                        'admin_id' => auth()->id(),
                        'what_system' => 3, // Admin Console
                        'when_changes' => now()
                    ]);

                    // Audit Log: status changed
                    if ($statusChanged) {
                        \DB::table('admin_logs')->insert([
                            'changes' => "Toggled active status (Value: " . ($this->isActive ? '1' : '0') . ") for user: {$user->username}",
                            'admin_id' => auth()->id(),
                            'what_system' => 3, // Admin Console
                            'when_changes' => now()
                        ]);
                    }
                    
                    $this->successMessage = 'User account details updated successfully!';
                }
            });

            if ($this->selectedUserId === auth()->id()) {
                session()->flash('success', 'User account details updated successfully!');
                $this->js('window.location.reload();');
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save changes: ' . $e->getMessage();
        }
    }

    /**
     * Soft-deactivates (soft-deletes) the selected user for transparency.
     */
    public function deleteUser(): void
    {
        if ($this->selectedUserId === null || $this->selectedUserId === -1) {
            return;
        }

        $this->clearMessages();

        // Authorization check: User must be is_sadm or have can_sadm_modify_account clearance
        $currentUserPerms = auth()->user() ? auth()->user()->permissions : null;
        if (! $currentUserPerms || (! $currentUserPerms->is_sadm && ! $currentUserPerms->can_sadm_modify_account)) {
            $this->errorMessage = 'Unauthorized: You do not have permission to delete user accounts.';
            return;
        }

        if ($this->selectedUserId === auth()->id()) {
            $this->errorMessage = 'You cannot delete your own logged-in account!';
            return;
        }

        try {
            \DB::transaction(function () {
                $user = \App\Models\User::findOrFail($this->selectedUserId);
                
                $user->update([
                    'account_active' => false,
                    'date_updated' => now(),
                ]);

                // Audit Log: soft-deleted/deactivated
                \DB::table('admin_logs')->insert([
                    'changes' => "Soft-deleted user account (Deactivated for transparency): {$user->username}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3, // Admin Console
                    'when_changes' => now()
                ]);

                // Reset selection to close edit details pane but set success message
                $this->cancelSelection();
                $this->successMessage = 'User account soft-deleted successfully!';
            });
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete user: ' . $e->getMessage();
        }
    }

    /**
     * Component Lifecycle Hook - passes computed query variables to the view.
     * Queries users with live search criteria and applies custom sorting.
     * 
     * @return array Contains queried users list, available roles, and offices
     */
    public function with(): array
    {
        // Only return active users in the directory to match deletion expectations
        $query = \App\Models\User::with(['details'])->where('account_active', true);

        // Handle Search Filters (searches across credentials and details)
        if ($this->search !== '') {
            $searchVal = '%' . $this->search . '%';
            $query->where(function($q) use ($searchVal) {
                $q->where('username', 'like', $searchVal)
                  ->orWhereHas('details', function($qDet) use ($searchVal) {
                      $qDet->where('first_name', 'like', $searchVal)
                           ->orWhere('last_name', 'like', $searchVal)
                           ->orWhere('email', 'like', $searchVal);
                  });
            });
        }

        // Handle Sorting Options using subquery ordering
        switch ($this->sortBy) {
            case 'username':
                $query->orderBy('username', $this->sortDir);
                break;
            case 'role':
                $query->orderBy(
                    \DB::raw('(select key_name from condition_key where condition_key.id = account.account_role)'),
                    $this->sortDir
                );
                break;
            case 'office':
                $query->orderBy(
                    \DB::raw('(select office_name from office join account_details on account_details.office_id = office.id where account_details.account_id = account.id)'),
                    $this->sortDir
                );
                break;
            case 'date_created':
                $query->orderBy('date_created', $this->sortDir);
                break;
            case 'name':
            default:
                $query->orderBy(
                    \DB::raw('(select last_name from account_details where account_details.account_id = account.id)'),
                    $this->sortDir
                )->orderBy(
                    \DB::raw('(select first_name from account_details where account_details.account_id = account.id)'),
                    $this->sortDir
                );
                break;
        }

        $users = $query->paginate(20);

        // Fetch auxiliary lists for form dropdown lists (only active ones)
        $roles = \App\Models\role_list::where('is_active', true)->orderBy('key_name')->get();
        $offices = \App\Models\office::where('is_active', true)->orderBy('office_name')->get();

        return [
            'users' => $users,
            'roles' => $roles,
            'offices' => $offices,
        ];
    }
};
?>

@push('styles')
    @vite('resources/css/admin/accounts_users.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
@endpush

<div class="admin-users-container {{ !$selectedUserId ? 'no-selection' : 'has-selection' }}">
    <!-- Left Pane: Users Directory -->
    <div class="directory-panel">
        <div class="directory-header-row">
            <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">Users Directory</span>
            @php
                $canModify = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_sadm_modify_account;
            @endphp
            @if($canModify)
            <button type="button" class="btn-create-new" wire:click="startCreate">
                <i class="fa-solid fa-plus"></i> New User
            </button>
            @endif
        </div>

        <div class="directory-controls-bar">
            <div class="search-box-wrapper">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" class="search-box" placeholder="Search by name, email, user..." wire:model.live="search">
            </div>
            
            <div class="sort-controls-wrapper">
                <span class="sort-label">Sort By:</span>
                <select class="sort-select" wire:model.live="sortBy">
                    <option value="name">Name</option>
                    <option value="username">Username</option>
                    <option value="role">Role</option>
                    <option value="office">Office</option>
                    <option value="date_created">Date Created</option>
                </select>
                <button type="button" wire:click="toggleSortDir" class="btn-sort-dir" title="Toggle Order Direction ({{ strtoupper($sortDir) }})">
                    @if($sortDir === 'asc')
                        <i class="fa-solid fa-arrow-up-a-z"></i>
                    @else
                        <i class="fa-solid fa-arrow-down-z-a"></i>
                    @endif
                </button>
            </div>
        </div>
        
        <div class="users-list">
            @forelse($users as $user)
                @php
                    $userDet = $user->details;
                    $initials = strtoupper(substr($userDet?->first_name ?: '?', 0, 1) . substr($userDet?->last_name ?: '?', 0, 1));
                    $displayName = $userDet ? ($userDet->first_name . ' ' . $userDet->last_name) : $user->username;
                    $roleKey = \DB::table('condition_key')->where('id', $user->account_role)->first();
                @endphp
                <div class="user-item-card {{ $selectedUserId === $user->id ? 'active' : '' }}" wire:key="user-{{ $user->id }}" wire:click="selectUser({{ $user->id }})">
                    <div class="user-avatar-small">
                        <span>{{ $initials }}</span>
                    </div>
                    <div class="user-meta-info">
                        <span class="user-display-name">{{ $displayName }}</span>
                        <span class="user-display-email">{{ $userDet?->email ?: '@' . $user->username }}</span>
                        <span class="user-display-role-badge">{{ $roleKey?->key_name ?: 'User' }}</span>
                    </div>
                    @if(!$user->account_active)
                        <i class="fa-solid fa-ban" style="color: #ef4444; font-size: 13px;" title="Account Blocked"></i>
                    @endif
                </div>
            @empty
                <div style="text-align: center; color: #94a3b8; padding: 20px; font-family: 'Inter', sans-serif; font-size: 13.5px;">
                    No users found matching your search.
                </div>
            @endforelse
        </div>

        @if($users->hasPages())
            <div class="users-pagination-bar">
                {{ $users->links('components.pagination') }}
            </div>
        @endif
    </div>

    <!-- Right Pane: User Details & Form (Only rendered when a user is selected or creating a new user) -->
    @if($selectedUserId)
        <div class="details-panel">
            @php
                if ($selectedUserId > 0) {
                    $selectedUser = \App\Models\User::with('details')->find($selectedUserId);
                    $selectedUserDet = $selectedUser?->details;
                    $selInitials = strtoupper(substr($selectedUserDet?->first_name ?: '?', 0, 1) . substr($selectedUserDet?->last_name ?: '?', 0, 1));
                    $selDisplayName = $selectedUserDet ? ($selectedUserDet->first_name . ' ' . $selectedUserDet->last_name) : $selectedUser->username;
                    $selRoleKey = \DB::table('condition_key')->where('id', $selectedUser->account_role)->first();
                    $selRoleName = $selRoleKey?->key_name ?: 'User';
                    $isOnline = $selectedUserDet?->is_currently_online;
                } else {
                    $selInitials = 'NU';
                    $selDisplayName = 'Configure New User';
                    $selRoleName = 'Guest';
                    $isOnline = false;
                }
            @endphp
            
            <!-- Header -->
            <div class="details-header">
                <div class="details-header-avatar">
                    @if($selectedUserId === -1)
                        <i class="fa-solid fa-user-plus" style="font-size: 20px;"></i>
                    @else
                        <span>{{ $selInitials }}</span>
                    @endif
                </div>
                <div class="details-header-info">
                    <h2 class="details-header-name">{{ $selDisplayName }}</h2>
                    <span class="details-header-sub">
                        @if($selectedUserId === -1)
                            Configure access credentials & profile details for a new platform account
                        @else
                            <i class="fa-solid fa-user-shield"></i> {{ $selRoleName }} &nbsp;•&nbsp; 
                            <i class="fa-solid fa-circle {{ $isOnline ? 'online' : 'offline' }}" style="color: {{ $isOnline ? '#10b981' : '#cbd5e1' }}; font-size: 8px;"></i>
                            {{ $isOnline ? 'Online Now' : 'Offline' }}
                        @endif
                    </span>
                </div>
                <button type="button" class="btn-close-details" wire:click="cancelSelection" title="Close Details Panel">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Body/Form -->
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

                <form wire:submit.prevent="saveUserChanges" class="form-grid-layout">
                    <!-- Username -->
                    <div class="form-group">
                        <span class="form-label">Username</span>
                        <input type="text" class="form-input" placeholder="e.g. jdoe" wire:model="username">
                        @error('username') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <span class="form-label">Email Address</span>
                        <input type="email" class="form-input" placeholder="e.g. john.doe@cspc.edu.ph" wire:model="email">
                        @error('email') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    @if($selectedUserId === -1)
                        <!-- Password -->
                        <div class="form-group">
                            <span class="form-label">Password</span>
                            <input type="password" class="form-input" placeholder="Choose a secure password..." wire:model="password">
                            @error('password') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group">
                            <span class="form-label">Confirm Password</span>
                            <input type="password" class="form-input" placeholder="Confirm your password..." wire:model="password_confirmation">
                            @error('password_confirmation') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    <!-- First Name -->
                    <div class="form-group">
                        <span class="form-label">First Name</span>
                        <input type="text" class="form-input" placeholder="Enter first name..." wire:model="firstName">
                        @error('firstName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Last Name -->
                    <div class="form-group">
                        <span class="form-label">Last Name</span>
                        <input type="text" class="form-input" placeholder="Enter last name..." wire:model="lastName">
                        @error('lastName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Middle Name -->
                    <div class="form-group">
                        <span class="form-label">Middle Name</span>
                        <input type="text" class="form-input" placeholder="Enter middle name..." wire:model="middleName">
                        @error('middleName') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Contact Number -->
                    <div class="form-group">
                        <span class="form-label">Contact Number</span>
                        <input type="text" class="form-input" placeholder="Enter contact number..." wire:model="contactNumber">
                        @error('contactNumber') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Role -->
                    <div class="form-group" wire:click.outside="$set('showRoleDropdown', false)">
                        <span class="form-label">Assigned Role</span>
                        <div style="position: relative;">
                            <input type="text" 
                                   class="form-input" 
                                   placeholder="Search and select role..." 
                                   wire:model.live="roleSearch" 
                                   wire:focus="$set('showRoleDropdown', true)" 
                                   autocomplete="off" 
                                   style="padding-right: 32px; background-color: white; font-family: 'Inter', sans-serif;">
                            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 10px;">▼</span>
                            
                            @if($showRoleDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-height: 160px; overflow-y: auto; z-index: 50; font-family: 'Inter', sans-serif;">
                                    <div wire:click="selectRole(null, '')" style="padding: 9px 14px; font-size: 13px; color: #64748b; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-style: italic;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                        Select Role
                                    </div>
                                    @php
                                        $rSearchLower = strtolower($roleSearch);
                                        $filteredRoles = $roles->filter(function($r) use ($rSearchLower) {
                                            return empty($rSearchLower) || str_contains(strtolower($r->key_name), $rSearchLower);
                                        });
                                    @endphp
                                    @forelse($filteredRoles as $role)
                                        <div wire:click="selectRole({{ $role->id }}, '{{ addslashes($role->key_name) }}')" style="padding: 9px 14px; font-size: 13px; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                            {{ $role->key_name }}
                                        </div>
                                    @empty
                                        <div style="padding: 12px 14px; font-size: 13px; color: #94a3b8; text-align: center;">No matching roles found</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                        @error('roleId') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Office -->
                    <div class="form-group" wire:click.outside="$set('showOfficeDropdown', false)">
                        <span class="form-label">Assigned Office</span>
                        <div style="position: relative;">
                            <input type="text" 
                                   class="form-input" 
                                   placeholder="Search and select office..." 
                                   wire:model.live="officeSearch" 
                                   wire:focus="$set('showOfficeDropdown', true)" 
                                   autocomplete="off" 
                                   style="padding-right: 32px; background-color: white; font-family: 'Inter', sans-serif;">
                            <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 10px;">▼</span>
                            
                            @if($showOfficeDropdown)
                                <div style="position: absolute; top: 100%; left: 0; right: 0; margin-top: 4px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); max-height: 160px; overflow-y: auto; z-index: 50; font-family: 'Inter', sans-serif;">
                                    <div wire:click="selectOffice(null, '')" style="padding: 9px 14px; font-size: 13px; color: #64748b; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-style: italic;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                        No Office Assigned
                                    </div>
                                    @php
                                        $oSearchLower = strtolower($officeSearch);
                                        $filteredOffices = $offices->filter(function($o) use ($oSearchLower) {
                                            return empty($oSearchLower) 
                                                || str_contains(strtolower($o->office_name), $oSearchLower)
                                                || str_contains(strtolower($o->office_code), $oSearchLower);
                                        });
                                    @endphp
                                    @forelse($filteredOffices as $office)
                                        <div wire:click="selectOffice({{ $office->id }}, '{{ addslashes($office->office_name) }}')" style="padding: 9px 14px; font-size: 13px; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='transparent'">
                                            {{ $office->office_name }} ({{ $office->office_code }})
                                        </div>
                                    @empty
                                        <div style="padding: 12px 14px; font-size: 13px; color: #94a3b8; text-align: center;">No matching offices found</div>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                        @error('officeId') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                    </div>

                    <!-- Reset Password Container (Edit Mode Only, toggled by Reset Password button) -->
                    @if($selectedUserId > 0 && $showResetPasswordContainer)
                        @if(auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_sadm_modify_pass)
                            <div class="form-group full-width" style="margin-top: 15px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 16px; animation: fadeIn 0.2s ease-out;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                    <span style="font-size: 13.5px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                                        <i class="fa-solid fa-key" style="color: #0284c7;"></i> Reset Account Password
                                    </span>
                                    <button type="button" wire:click="toggleResetPasswordContainer" style="background: none; border: none; font-size: 18px; color: #94a3b8; cursor: pointer; padding: 0 4px;" onmouseover="this.style.color='#64748b'" onmouseout="this.style.color='#94a3b8'">&times;</button>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; width: 100%;">
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <span class="form-label">New Password</span>
                                        <input type="password" class="form-input" style="height: 38px; background: #ffffff;" placeholder="Enter new password..." wire:model="password">
                                        @error('password') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <span class="form-label">Confirm New Password</span>
                                        <input type="password" class="form-input" style="height: 38px; background: #ffffff;" placeholder="Confirm new password..." wire:model="password_confirmation">
                                        @error('password_confirmation') <span style="color:#ef4444; font-size:11px; margin-top:2px;">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif

                    <!-- Active Toggle Wrapper -->
                    <div class="form-group full-width">
                        <div class="status-toggle-wrapper">
                            <div class="status-toggle-label">
                                <span class="status-toggle-title">Account Status</span>
                                <span class="status-toggle-desc">Toggle whether this user account is active or suspended.</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" wire:model="isActive">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Footer -->
            <div class="details-footer">
                @php
                    $canModify = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_sadm_modify_account;
                    $canResetPass = auth()->user()?->permissions?->is_sadm || auth()->user()?->permissions?->can_sadm_modify_pass;
                @endphp
                @if($selectedUserId > 0)
                    <button type="button" class="btn-delete" wire:click="deleteUser" style="margin-right: auto;" {{ !$canModify ? 'disabled style=opacity:0.6;cursor:not-allowed;' : '' }}>
                        <i class="fa-solid fa-trash-can"></i> Delete Account
                    </button>
                    @if($canResetPass)
                        <button type="button" class="btn-cancel" wire:click="toggleResetPasswordContainer" style="margin-right: 8px; border-color: #0284c7; color: #0284c7; background-color: {{ $showResetPasswordContainer ? '#e0f2fe' : '#ffffff' }}; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 20px;" onmouseover="this.style.backgroundColor='#e0f2fe'" onmouseout="this.style.backgroundColor='{{ $showResetPasswordContainer ? '#e0f2fe' : '#ffffff' }}'">
                            <i class="fa-solid fa-key"></i> Reset Password
                        </button>
                    @endif
                @endif
                <button type="button" class="btn-cancel" wire:click="cancelSelection">Cancel</button>
                <button type="button" class="btn-save" wire:click="saveUserChanges" {{ !$canModify ? 'disabled style=opacity:0.6;cursor:not-allowed;' : '' }}>
                    <i class="fa-solid fa-floppy-disk"></i> {{ $selectedUserId === -1 ? 'Create User' : 'Save Changes' }}
                </button>
            </div>
        </div>
    @endif
</div>

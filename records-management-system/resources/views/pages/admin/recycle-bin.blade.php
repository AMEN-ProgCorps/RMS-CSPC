<?php
/**
 * Admin Console - Recycle Bin Volt Component
 *
 * Provides a unified view of all soft-deleted (deactivated) items across
 * Offices, Clusters, and Transaction Flows with restore capabilities.
 */

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Recycle Bin')] class extends Component {
    /** @var string Active tab: 'offices', 'clusters', 'roles', 'flows', or 'users' */
    public string $activeTab = 'offices';

    /** @var string Real-time search query */
    public string $search = '';

    /** @var string Success toast message */
    public string $successMessage = '';

    /** @var string Error toast message */
    public string $errorMessage = '';

    /** @var array Selected item IDs for bulk restore */
    public array $selectedIds = [];

    /** @var string Flow purpose filter (used on flows tab) */
    public string $flowPurposeFilter = 'all';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_recycle_bin)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    /**
     * Reset selection array on search updates.
     */
    public function updatingSearch(): void
    {
        $this->selectedIds = [];
    }

    /**
     * Reset selection array on flow purpose filter updates.
     */
    public function updatingFlowPurposeFilter(): void
    {
        $this->selectedIds = [];
    }

    /**
     * Clears all alert messages.
     */
    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    /**
     * Restore a single soft-deleted office.
     */
    public function restoreOffice(int $id): void
    {
        $this->clearMessages();

        try {
            \DB::transaction(function () use ($id) {
                $office = \App\Models\office::findOrFail($id);

                $office->update(['is_active' => true]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Restored office from Recycle Bin: {$office->office_name} ({$office->office_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'Office restored successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to restore office: ' . $e->getMessage();
        }
    }

    /**
     * Restore a single soft-deleted cluster.
     */
    public function restoreCluster(int $id): void
    {
        $this->clearMessages();

        try {
            \DB::transaction(function () use ($id) {
                $cluster = \App\Models\Cluster::findOrFail($id);

                $cluster->update(['is_active' => true]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Restored cluster from Recycle Bin: {$cluster->cluster_name} ({$cluster->cluster_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'Cluster restored successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to restore cluster: ' . $e->getMessage();
        }
    }

    /**
     * Restore a single soft-deleted role.
     */
    public function restoreRole(int $id): void
    {
        $this->clearMessages();

        try {
            \DB::transaction(function () use ($id) {
                $role = \App\Models\role_list::findOrFail($id);

                $role->update([
                    'is_active' => true,
                    'date_updated' => now(),
                ]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Restored role from Recycle Bin: {$role->key_name}",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'Role restored successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to restore role: ' . $e->getMessage();
        }
    }

    /**
     * Restore a single soft-deleted user.
     */
    public function restoreUser(int $id): void
    {
        $this->clearMessages();

        try {
            \DB::transaction(function () use ($id) {
                $user = \App\Models\User::findOrFail($id);

                $user->update(['account_active' => true]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Restored user from Recycle Bin: {$user->username} (" . ($user->details ? $user->details->first_name . ' ' . $user->details->last_name : 'N/A') . ")",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'User restored successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to restore user: ' . $e->getMessage();
        }
    }

    /**
     * Restore a single soft-deleted transaction flow.
     */
    public function restoreFlow(int $id): void
    {
        $this->clearMessages();

        try {
            \DB::transaction(function () use ($id) {
                $flow = \DB::table('dts_transaction_flow')->where('id', $id)->first();

                if (!$flow) {
                    throw new \Exception('Transaction flow not found.');
                }

                \DB::table('dts_transaction_flow')->where('id', $id)->update(['is_active' => true]);

                \DB::table('admin_logs')->insert([
                    'changes' => "Restored transaction flow from Recycle Bin: {$flow->flow_name} ({$flow->flow_code})",
                    'admin_id' => auth()->id(),
                    'what_system' => 3,
                    'when_changes' => now(),
                ]);
            });

            $this->successMessage = 'Transaction flow restored successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to restore flow: ' . $e->getMessage();
        }
    }

    /**
     * Switch active tab and clear selection/messages.
     */
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->selectedIds = [];
        $this->search = '';
        $this->flowPurposeFilter = 'all';
        $this->clearMessages();
    }

    /**
     * Toggle a record ID in the selection array.
     */
    public function toggleSelection(int $id): void
    {
        if (in_array($id, $this->selectedIds)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    /**
     * Toggle select/deselect all visible deactivated records under the current active tab.
     */
    public function toggleAll(): void
    {
        $visibleIds = [];
        $searchVal = '%' . $this->search . '%';

        if ($this->activeTab === 'offices') {
            $query = \App\Models\office::where('is_active', false)
                ->whereNotIn('office_code', ['ORIGIN', '[H]']);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('office_name', 'like', $searchVal)
                      ->orWhere('office_code', 'like', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'clusters') {
            $query = \App\Models\Cluster::where('is_active', false);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('cluster_name', 'like', $searchVal)
                      ->orWhere('cluster_code', 'like', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'roles') {
            $query = \App\Models\role_list::where('is_active', false);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('key_name', 'like', $searchVal)
                      ->orWhere('key_description', 'like', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'flows') {
            $query = \DB::table('dts_transaction_flow')
                ->where('is_active', false);
            if ($this->flowPurposeFilter !== 'all') {
                $query->where('flow_use', $this->flowPurposeFilter);
            }
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('flow_name', 'like', $searchVal)
                      ->orWhere('flow_code', 'like', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'users') {
            $query = \App\Models\User::where('account_active', false);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('username', 'like', $searchVal)
                      ->orWhereHas('details', function ($sub) use ($searchVal) {
                          $sub->where('first_name', 'like', $searchVal)
                              ->orWhere('last_name', 'like', $searchVal);
                      });
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        }

        if (count($this->selectedIds) === count($visibleIds) && !array_diff($visibleIds, $this->selectedIds)) {
            $this->selectedIds = [];
        } else {
            $this->selectedIds = $visibleIds;
        }
    }

    /**
     * Bulk restore all selected records.
     */
    public function bulkRestore(): void
    {
        $this->clearMessages();

        if (empty($this->selectedIds)) {
            $this->errorMessage = 'No items selected for restoration.';
            return;
        }

        try {
            \DB::transaction(function () {
                if ($this->activeTab === 'offices') {
                    $records = \App\Models\office::whereIn('id', $this->selectedIds)->get();
                    if ($records->isNotEmpty()) {
                        $names = $records->pluck('office_name')->implode(', ');
                        \App\Models\office::whereIn('id', $records->pluck('id')->toArray())->update(['is_active' => true]);
                        
                        \DB::table('admin_logs')->insert([
                            'changes' => \Str::limit("Bulk restored " . $records->count() . " office(s) from Recycle Bin: {$names}", 245),
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                    }
                } elseif ($this->activeTab === 'clusters') {
                    $records = \App\Models\Cluster::whereIn('id', $this->selectedIds)->get();
                    if ($records->isNotEmpty()) {
                        $names = $records->pluck('cluster_name')->implode(', ');
                        \App\Models\Cluster::whereIn('id', $records->pluck('id')->toArray())->update(['is_active' => true]);

                        \DB::table('admin_logs')->insert([
                            'changes' => \Str::limit("Bulk restored " . $records->count() . " cluster(s) from Recycle Bin: {$names}", 245),
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                    }
                } elseif ($this->activeTab === 'roles') {
                    $records = \App\Models\role_list::whereIn('id', $this->selectedIds)->get();
                    if ($records->isNotEmpty()) {
                        $names = $records->pluck('key_name')->implode(', ');
                        \App\Models\role_list::whereIn('id', $records->pluck('id')->toArray())->update([
                            'is_active' => true,
                            'date_updated' => now(),
                        ]);

                        \DB::table('admin_logs')->insert([
                            'changes' => \Str::limit("Bulk restored " . $records->count() . " role(s) from Recycle Bin: {$names}", 245),
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                    }
                } elseif ($this->activeTab === 'flows') {
                    $records = \DB::table('dts_transaction_flow')->whereIn('id', $this->selectedIds)->get();
                    if ($records->isNotEmpty()) {
                        $names = $records->pluck('flow_name')->implode(', ');
                        \DB::table('dts_transaction_flow')->whereIn('id', $this->selectedIds)->update(['is_active' => true]);

                        \DB::table('admin_logs')->insert([
                            'changes' => \Str::limit("Bulk restored " . $records->count() . " transaction flow(s) from Recycle Bin: {$names}", 245),
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                    }
                } elseif ($this->activeTab === 'users') {
                    $records = \App\Models\User::whereIn('id', $this->selectedIds)->get();
                    if ($records->isNotEmpty()) {
                        $names = $records->map(fn($r) => $r->username)->implode(', ');
                        \App\Models\User::whereIn('id', $records->pluck('id')->toArray())->update(['account_active' => true]);

                        \DB::table('admin_logs')->insert([
                            'changes' => \Str::limit("Bulk restored " . $records->count() . " user(s) from Recycle Bin: {$names}", 245),
                            'admin_id' => auth()->id(),
                            'what_system' => 3,
                            'when_changes' => now(),
                        ]);
                    }
                }
            });

            $count = count($this->selectedIds);
            $this->selectedIds = [];
            $this->successMessage = "Successfully restored {$count} item(s)!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to bulk restore items: ' . $e->getMessage();
        }
    }

    /**
     * Provide deactivated items to the Blade template.
     */
    public function with(): array
    {
        $searchVal = '%' . $this->search . '%';

        // Deactivated offices (exclude system placeholders)
        $officesQuery = \App\Models\office::query()
            ->where('is_active', false)
            ->whereNotIn('office_code', ['ORIGIN', '[H]']);

        if ($this->search !== '' && $this->activeTab === 'offices') {
            $officesQuery->where(function ($q) use ($searchVal) {
                $q->where('office_name', 'like', $searchVal)
                  ->orWhere('office_code', 'like', $searchVal);
            });
        }
        $deactivatedOffices = $officesQuery->orderBy('office_name', 'asc')->get();

        // Deactivated clusters
        $clustersQuery = \App\Models\Cluster::query()
            ->where('is_active', false);

        if ($this->search !== '' && $this->activeTab === 'clusters') {
            $clustersQuery->where(function ($q) use ($searchVal) {
                $q->where('cluster_name', 'like', $searchVal)
                  ->orWhere('cluster_code', 'like', $searchVal);
            });
        }
        $deactivatedClusters = $clustersQuery->orderBy('cluster_name', 'asc')->get();

        // Deactivated roles
        $rolesQuery = \App\Models\role_list::query()
            ->with('permissions')
            ->where('is_active', false);

        if ($this->search !== '' && $this->activeTab === 'roles') {
            $rolesQuery->where(function ($q) use ($searchVal) {
                $q->where('key_name', 'like', $searchVal)
                  ->orWhere('key_description', 'like', $searchVal);
            });
        }
        $deactivatedRoles = $rolesQuery->orderBy('key_name', 'asc')->get();

        // Deactivated flows (predefined and custom)
        $flowsQuery = \DB::table('dts_transaction_flow')
            ->where('is_active', false);

        if ($this->flowPurposeFilter !== 'all') {
            $flowsQuery->where('flow_use', $this->flowPurposeFilter);
        }

        if ($this->search !== '' && $this->activeTab === 'flows') {
            $flowsQuery->where(function ($q) use ($searchVal) {
                $q->where('flow_name', 'like', $searchVal)
                  ->orWhere('flow_code', 'like', $searchVal);
            });
        }
        $deactivatedFlows = $flowsQuery->orderBy('flow_name', 'asc')->get();

        // Deactivated users
        $usersQuery = \App\Models\User::query()
            ->with(['details.office'])
            ->where('account_active', false);

        if ($this->search !== '' && $this->activeTab === 'users') {
            $usersQuery->where(function ($q) use ($searchVal) {
                $q->where('username', 'like', $searchVal)
                  ->orWhereHas('details', function ($sub) use ($searchVal) {
                      $sub->where('first_name', 'like', $searchVal)
                          ->orWhere('last_name', 'like', $searchVal);
                  });
            });
        }
        $deactivatedUsers = $usersQuery->orderBy('username', 'asc')->get();

        return [
            'deactivatedOffices' => $deactivatedOffices,
            'deactivatedClusters' => $deactivatedClusters,
            'deactivatedRoles' => $deactivatedRoles,
            'deactivatedFlows' => $deactivatedFlows,
            'deactivatedUsers' => $deactivatedUsers,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/activity_logs.css', 'resources/css/admin/accounts_offices.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .tabs-header {
            display: flex;
            gap: 12px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 20px;
            font-size: 14.5px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            position: relative;
            outline: none;
            font-family: 'Inter', sans-serif;
            transition: color 0.2s ease;
        }
        .tab-btn:hover {
            color: #334155;
        }
        .tab-btn.active {
            color: #003699;
        }
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #003699;
            border-radius: 1px;
        }
        .activity-logs-container {
            padding: 12px 24px;
        }
        .admin-offices-container {
            display: block !important;
            min-height: calc(100vh - 190px) !important;
            height: auto !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .directory-panel {
            max-height: none !important;
            height: auto !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .offices-list {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)) !important;
            gap: 16px !important;
            overflow-y: visible !important;
            height: auto !important;
            padding-right: 0 !important;
        }
        .search-box-wrapper {
            position: relative;
            width: 100% !important;
            max-width: none !important;
            min-width: 0 !important;
            flex: none !important;
            margin-bottom: 12px;
        }
    </style>
@endpush

<div class="activity-logs-container">
    {{-- Tabs Header --}}
    <div class="tabs-header">
        <button type="button" class="tab-btn {{ $activeTab === 'offices' ? 'active' : '' }}" wire:click="switchTab('offices')">
            <i class="fa-solid fa-building" style="margin-right: 6px;"></i> Offices
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'clusters' ? 'active' : '' }}" wire:click="switchTab('clusters')">
            <i class="fa-solid fa-sitemap" style="margin-right: 6px;"></i> Clusters
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'roles' ? 'active' : '' }}" wire:click="switchTab('roles')">
            <i class="fa-solid fa-sliders" style="margin-right: 6px;"></i> Roles & Clearances
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'flows' ? 'active' : '' }}" wire:click="switchTab('flows')">
            <i class="fa-solid fa-route" style="margin-right: 6px;"></i> Transaction Flows
        </button>
        <button type="button" class="tab-btn {{ $activeTab === 'users' ? 'active' : '' }}" wire:click="switchTab('users')">
            <i class="fa-solid fa-users" style="margin-right: 6px;"></i> Users
        </button>
    </div>

    {{-- ==================== OFFICES TAB ==================== --}}
    @if($activeTab === 'offices')
        <div class="admin-offices-container" wire:key="tab-recycle-offices">
            <div class="directory-panel" style="width: 100%; max-width: 100%; max-height: none;">
                {{-- Header Row --}}
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">
                        <i class="fa-solid fa-building" style="margin-right: 4px; color: #dc2626;"></i>
                        Deactivated Offices
                        <span style="font-weight: 400; color: #94a3b8; margin-left: 4px;">({{ $deactivatedOffices->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated offices..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #065f46; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #065f46; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #991b1b; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #065f46;">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected office(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            style="background: #059669; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedOffices->count() > 0)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px;">
                        <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedOffices->count() ? 'checked' : '' }}>
                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedOffices as $office)
                        @php
                            $officeInitials = strtoupper(substr($office->office_code ?: '?', 0, 3));
                        @endphp
                        <div wire:key="recycle-office-{{ $office->id }}" style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #fecdd3; border-radius: 10px; background: #fff5f5; transition: all 0.2s ease;">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $office->id }})" {{ in_array($office->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; font-family: 'Inter', sans-serif;">
                                {{ $officeInitials }}
                            </div>
                            {{-- Info --}}
                            <div style="flex: 1; min-width: 0;">
                                <span style="font-size: 13.5px; font-weight: 600; color: #334155; display: block; font-family: 'Inter', sans-serif;">{{ $office->office_name }}</span>
                                <span style="font-size: 12px; color: #94a3b8; font-family: 'Inter', sans-serif;">Code: {{ $office->office_code }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreOffice({{ $office->id }})"
                                    wire:confirm="Are you sure you want to restore this office? It will be reactivated and visible again."
                                    style="background: #059669; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fa-solid fa-recycle" style="font-size: 48px; margin-bottom: 16px; display: block; color: #cbd5e1;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #64748b; margin: 0 0 8px;">Recycle Bin is Empty</h3>
                            <p style="font-size: 13px; margin: 0; font-family: 'Inter', sans-serif;">No deactivated offices found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== CLUSTERS TAB ==================== --}}
    @if($activeTab === 'clusters')
        <div class="admin-offices-container" wire:key="tab-recycle-clusters">
            <div class="directory-panel" style="width: 100%; max-width: 100%; max-height: none;">
                {{-- Header Row --}}
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">
                        <i class="fa-solid fa-sitemap" style="margin-right: 4px; color: #dc2626;"></i>
                        Deactivated Clusters
                        <span style="font-weight: 400; color: #94a3b8; margin-left: 4px;">({{ $deactivatedClusters->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated clusters..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #065f46; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #065f46; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #991b1b; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #065f46;">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected cluster(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            style="background: #059669; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedClusters->count() > 0)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px;">
                        <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedClusters->count() ? 'checked' : '' }}>
                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedClusters as $cluster)
                        @php
                            $clusterInitials = strtoupper(substr($cluster->cluster_code ?: '?', 0, 3));
                        @endphp
                        <div wire:key="recycle-cluster-{{ $cluster->id }}" style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #fecdd3; border-radius: 10px; background: #fff5f5; transition: all 0.2s ease;">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $cluster->id }})" {{ in_array($cluster->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; font-family: 'Inter', sans-serif;">
                                {{ $clusterInitials }}
                            </div>
                            {{-- Info --}}
                            <div style="flex: 1; min-width: 0;">
                                <span style="font-size: 13.5px; font-weight: 600; color: #334155; display: block; font-family: 'Inter', sans-serif;">{{ $cluster->cluster_name }}</span>
                                <span style="font-size: 12px; color: #94a3b8; font-family: 'Inter', sans-serif;">Code: {{ $cluster->cluster_code }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreCluster({{ $cluster->id }})"
                                    wire:confirm="Are you sure you want to restore this cluster? It will be reactivated and visible again."
                                    style="background: #059669; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fa-solid fa-recycle" style="font-size: 48px; margin-bottom: 16px; display: block; color: #cbd5e1;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #64748b; margin: 0 0 8px;">Recycle Bin is Empty</h3>
                            <p style="font-size: 13px; margin: 0; font-family: 'Inter', sans-serif;">No deactivated clusters found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== ROLES TAB ==================== --}}
    @if($activeTab === 'roles')
        <div class="admin-offices-container" wire:key="tab-recycle-roles">
            <div class="directory-panel" style="width: 100%; max-width: 100%; max-height: none;">
                {{-- Header Row --}}
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">
                        <i class="fa-solid fa-sliders" style="margin-right: 4px; color: #dc2626;"></i>
                        Deactivated Roles & Clearances
                        <span style="font-weight: 400; color: #94a3b8; margin-left: 4px;">({{ $deactivatedRoles->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated roles..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #065f46; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #065f46; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #991b1b; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #065f46;">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected role(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            style="background: #059669; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedRoles->count() > 0)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px;">
                        <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedRoles->count() ? 'checked' : '' }}>
                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedRoles as $role)
                        @php
                            $roleInitials = strtoupper(substr($role->key_name ?: 'R', 0, 3));
                        @endphp
                        <div wire:key="recycle-role-{{ $role->id }}" style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #fecdd3; border-radius: 10px; background: #fff5f5; transition: all 0.2s ease;">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $role->id }})" {{ in_array($role->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; font-family: 'Inter', sans-serif;">
                                {{ $roleInitials }}
                            </div>
                            {{-- Info --}}
                            <div style="flex: 1; min-width: 0;">
                                <span style="font-size: 13.5px; font-weight: 600; color: #334155; display: block; font-family: 'Inter', sans-serif;">{{ $role->key_name }}</span>
                                <span style="font-size: 12px; color: #94a3b8; font-family: 'Inter', sans-serif;">{{ $role->key_description ?: 'No description provided' }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreRole({{ $role->id }})"
                                    wire:confirm="Are you sure you want to restore this role? It will be reactivated and visible again."
                                    style="background: #059669; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fa-solid fa-recycle" style="font-size: 48px; margin-bottom: 16px; display: block; color: #cbd5e1;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #64748b; margin: 0 0 8px;">Recycle Bin is Empty</h3>
                            <p style="font-size: 13px; margin: 0; font-family: 'Inter', sans-serif;">No deactivated roles found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== TRANSACTION FLOWS TAB ==================== --}}
    @if($activeTab === 'flows')
        <div class="admin-offices-container" wire:key="tab-recycle-flows">
            <div class="directory-panel" style="width: 100%; max-width: 100%; max-height: none;">
                {{-- Header Row --}}
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">
                        <i class="fa-solid fa-route" style="margin-right: 4px; color: #dc2626;"></i>
                        Deactivated Transaction Flows
                        <span style="font-weight: 400; color: #94a3b8; margin-left: 4px;">({{ $deactivatedFlows->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated flows..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #065f46; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #065f46; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #991b1b; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #065f46;">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected flow(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            style="background: #059669; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All & Filter Row --}}
                <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px;">
                    @if($deactivatedFlows->count() > 0)
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedFlows->count() ? 'checked' : '' }}>
                            <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                        </div>
                    @else
                        <div></div>
                    @endif
                    <div>
                        <select wire:model.live="flowPurposeFilter" style="padding: 6px 12px; border-radius: 6px; border: 1.5px solid #e2e8f0; outline: none; font-size: 12px; font-family: 'Inter', sans-serif; color: #64748b; cursor: pointer; transition: all 0.2s ease; background: #fff; font-weight: 500;">
                            <option value="all">All Purposes</option>
                            <option value="internal">Internal</option>
                            <option value="external">External</option>
                            <option value="issuances">Issuances</option>
                            <option value="application">Application</option>
                            <option value="others">Others</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                </div>

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedFlows as $flow)
                        @php
                            $flowInitials = strtoupper(substr($flow->flow_code ?: '?', 0, 3));
                        @endphp
                        <div wire:key="recycle-flow-{{ $flow->id }}" style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #fecdd3; border-radius: 10px; background: #fff5f5; transition: all 0.2s ease;">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $flow->id }})" {{ in_array($flow->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; font-family: 'Inter', sans-serif;">
                                {{ $flowInitials }}
                            </div>
                            {{-- Info --}}
                            <div style="flex: 1; min-width: 0;">
                                <span style="font-size: 13.5px; font-weight: 600; color: #334155; display: block; font-family: 'Inter', sans-serif;">{{ $flow->flow_name }}</span>
                                <span style="font-size: 12px; color: #94a3b8; font-family: 'Inter', sans-serif;">Code: {{ $flow->flow_code }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreFlow({{ $flow->id }})"
                                    wire:confirm="Are you sure you want to restore this transaction flow? It will be reactivated and visible again."
                                    style="background: #059669; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fa-solid fa-recycle" style="font-size: 48px; margin-bottom: 16px; display: block; color: #cbd5e1;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #64748b; margin: 0 0 8px;">Recycle Bin is Empty</h3>
                            <p style="font-size: 13px; margin: 0; font-family: 'Inter', sans-serif;">No deactivated transaction flows found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- ==================== USERS TAB ==================== --}}
    @if($activeTab === 'users')
        <div class="admin-offices-container" wire:key="tab-recycle-users">
            <div class="directory-panel" style="width: 100%; max-width: 100%; max-height: none;">
                {{-- Header Row --}}
                <div class="directory-header-row">
                    <span class="form-label" style="margin: 0; font-size: 13px; color: #334155;">
                        <i class="fa-solid fa-users" style="margin-right: 4px; color: #dc2626;"></i>
                        Deactivated Users
                        <span style="font-weight: 400; color: #94a3b8; margin-left: 4px;">({{ $deactivatedUsers->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated users..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #065f46; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #065f46; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 10px; font-size: 12.5px; color: #991b1b; font-family: 'Inter', sans-serif;">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: #991b1b; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #065f46;">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected user(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            style="background: #059669; color: #fff; border: none; padding: 5px 12px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif;">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedUsers->count() > 0)
                    <div style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #e2e8f0; margin-bottom: 12px;">
                        <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedUsers->count() ? 'checked' : '' }}>
                        <span style="font-size: 12px; color: #64748b; font-weight: 500;">Select All</span>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedUsers as $usr)
                        @php
                            $first = $usr->details->first_name ?? '';
                            $last = $usr->details->last_name ?? '';
                            $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1)) ?: strtoupper(substr($usr->username, 0, 2));
                            $fullName = $first || $last ? trim($first . ' ' . $last) : $usr->username;
                            $officeName = $usr->details?->office?->office_name ?? 'No Office Assigned';
                        @endphp
                        <div wire:key="recycle-user-{{ $usr->id }}" style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #fecdd3; border-radius: 10px; background: #fff5f5; transition: all 0.2s ease;">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $usr->id }})" {{ in_array($usr->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div style="width: 40px; height: 40px; border-radius: 10px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; font-family: 'Inter', sans-serif;">
                                {{ $initials }}
                            </div>
                            {{-- Info --}}
                            <div style="flex: 1; min-width: 0;">
                                <span style="font-size: 13.5px; font-weight: 600; color: #334155; display: block; font-family: 'Inter', sans-serif;">{{ $fullName }}</span>
                                <span style="font-size: 12px; color: #64748b; font-family: 'Inter', sans-serif; display: block;">Username: <strong>{{ $usr->username }}</strong></span>
                                <span style="font-size: 11px; color: #94a3b8; font-family: 'Inter', sans-serif;">{{ $officeName }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreUser({{ $usr->id }})"
                                    wire:confirm="Are you sure you want to restore this user? They will be reactivated and able to log in again."
                                    style="background: #059669; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fa-solid fa-recycle" style="font-size: 48px; margin-bottom: 16px; display: block; color: #cbd5e1;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #64748b; margin: 0 0 8px;">Recycle Bin is Empty</h3>
                            <p style="font-size: 13px; margin: 0; font-family: 'Inter', sans-serif;">No deactivated users found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>

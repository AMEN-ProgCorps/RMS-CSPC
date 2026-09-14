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

                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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
                    $q->where('office_name', 'ilike', $searchVal)
                      ->orWhere('office_code', 'ilike', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'clusters') {
            $query = \App\Models\Cluster::where('is_active', false);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('cluster_name', 'ilike', $searchVal)
                      ->orWhere('cluster_code', 'ilike', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'roles') {
            $query = \App\Models\role_list::where('is_active', false);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('key_name', 'ilike', $searchVal)
                      ->orWhere('key_description', 'ilike', $searchVal);
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
                    $q->where('flow_name', 'ilike', $searchVal)
                      ->orWhere('flow_code', 'ilike', $searchVal);
                });
            }
            $visibleIds = $query->pluck('id')->toArray();
        } elseif ($this->activeTab === 'users') {
            $query = \App\Models\User::where('account_active', false);
            if ($this->search !== '') {
                $query->where(function ($q) use ($searchVal) {
                    $q->where('username', 'ilike', $searchVal)
                      ->orWhereHas('details', function ($sub) use ($searchVal) {
                          $sub->where('first_name', 'ilike', $searchVal)
                              ->orWhere('last_name', 'ilike', $searchVal);
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
                        
                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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

                        \DB::table(\Illuminate\Support\Facades\Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs')->insert([
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
                $q->where('office_name', 'ilike', $searchVal)
                  ->orWhere('office_code', 'ilike', $searchVal);
            });
        }
        $deactivatedOffices = $officesQuery->orderBy('office_name', 'asc')->get();

        // Deactivated clusters
        $clustersQuery = \App\Models\Cluster::query()
            ->where('is_active', false);

        if ($this->search !== '' && $this->activeTab === 'clusters') {
            $clustersQuery->where(function ($q) use ($searchVal) {
                $q->where('cluster_name', 'ilike', $searchVal)
                  ->orWhere('cluster_code', 'ilike', $searchVal);
            });
        }
        $deactivatedClusters = $clustersQuery->orderBy('cluster_name', 'asc')->get();

        // Deactivated roles
        $rolesQuery = \App\Models\role_list::query()
            ->with('permissions')
            ->where('is_active', false);

        if ($this->search !== '' && $this->activeTab === 'roles') {
            $rolesQuery->where(function ($q) use ($searchVal) {
                $q->where('key_name', 'ilike', $searchVal)
                  ->orWhere('key_description', 'ilike', $searchVal);
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
                $q->where('flow_name', 'ilike', $searchVal)
                  ->orWhere('flow_code', 'ilike', $searchVal);
            });
        }
        $deactivatedFlows = $flowsQuery->orderBy('flow_name', 'asc')->get();

        // Deactivated users
        $usersQuery = \App\Models\User::query()
            ->with(['details.office'])
            ->where('account_active', false);

        if ($this->search !== '' && $this->activeTab === 'users') {
            $usersQuery->where(function ($q) use ($searchVal) {
                $q->where('username', 'ilike', $searchVal)
                  ->orWhereHas('details', function ($sub) use ($searchVal) {
                      $sub->where('first_name', 'ilike', $searchVal)
                          ->orWhere('last_name', 'ilike', $searchVal);
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

        /* Retention Notice Banner */
        .retention-notice-banner {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin: 0 0 16px;
            padding: 12px 16px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
        }
        .retention-notice-icon {
            color: #2563eb;
            margin-top: 2px;
            font-size: 16px;
        }
        .retention-notice-content {
            font-size: 12.5px;
            color: #1e3a8a;
            line-height: 1.45;
        }
        .retention-notice-content strong {
            color: #1e40af;
        }

        /* Header Title */
        .recycle-header-title {
            margin: 0;
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'Inter', sans-serif;
        }
        .recycle-header-title .title-icon {
            color: #dc2626;
        }
        .recycle-header-title .count-badge {
            font-weight: 400;
            color: #94a3b8;
            margin-left: 4px;
        }

        /* Toast Messages */
        .recycle-toast-success {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 12.5px;
            color: #065f46;
            font-family: 'Inter', sans-serif;
        }
        .recycle-toast-error {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            margin-bottom: 10px;
            font-size: 12.5px;
            color: #991b1b;
            font-family: 'Inter', sans-serif;
        }

        /* Bulk Action Bar */
        .recycle-bulk-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 14px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .recycle-bulk-bar .selected-count {
            font-size: 12px;
            font-weight: 600;
            color: #065f46;
        }

        /* Select All Row */
        .recycle-select-all-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 4px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 14px;
        }
        .recycle-select-all-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            cursor: pointer;
        }

        /* Filter Select */
        .recycle-filter-select {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1.5px solid #e2e8f0;
            outline: none;
            font-size: 12px;
            font-family: 'Inter', sans-serif;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #fff;
            font-weight: 500;
        }

        /* Item Card */
        .recycle-item-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 1px solid #fecdd3;
            border-radius: 10px;
            background: #fff5f5;
            transition: all 0.2s ease;
        }
        .recycle-item-card:hover {
            border-color: #fda4af;
            box-shadow: 0 2px 8px rgba(225, 29, 72, 0.08);
        }
        .recycle-item-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #fee2e2;
            color: #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
            font-family: 'Inter', sans-serif;
        }
        .recycle-item-info {
            flex: 1;
            min-width: 0;
        }
        .recycle-item-title {
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            display: block;
            font-family: 'Inter', sans-serif;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .recycle-item-sub {
            font-size: 12px;
            color: #94a3b8;
            font-family: 'Inter', sans-serif;
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-top: 2px;
        }
        .recycle-item-sub strong {
            color: #64748b;
        }
        .recycle-btn-restore {
            background: #059669;
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            transition: background 0.15s ease;
        }
        .recycle-btn-restore:hover {
            background: #047857;
        }

        /* Empty State */
        .recycle-empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        .recycle-empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
            color: #cbd5e1;
        }
        .recycle-empty-title {
            font-size: 16px;
            font-weight: 600;
            color: #64748b;
            margin: 0 0 8px;
            font-family: 'Outfit', sans-serif;
        }
        .recycle-empty-desc {
            font-size: 13px;
            margin: 0;
            font-family: 'Inter', sans-serif;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .retention-notice-banner {
            background: rgba(30, 58, 138, 0.2) !important;
            border-color: rgba(59, 130, 246, 0.3) !important;
        }
        [data-theme="dark"] .retention-notice-icon {
            color: #60a5fa !important;
        }
        [data-theme="dark"] .retention-notice-content {
            color: #bfdbfe !important;
        }
        [data-theme="dark"] .retention-notice-content strong {
            color: #93c5fd !important;
        }

        [data-theme="dark"] .tabs-header {
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] .tab-btn {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .tab-btn:hover {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .tab-btn.active {
            color: #60a5fa !important;
        }
        [data-theme="dark"] .tab-btn.active::after {
            background: #3b82f6 !important;
        }

        [data-theme="dark"] .recycle-header-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .recycle-header-title .title-icon {
            color: #f87171 !important;
        }
        [data-theme="dark"] .recycle-header-title .count-badge {
            color: #64748b !important;
        }

        [data-theme="dark"] .search-box-wrapper .search-box {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .search-box-wrapper .search-icon {
            color: #64748b !important;
        }

        [data-theme="dark"] .recycle-toast-success {
            background: rgba(6, 95, 70, 0.25) !important;
            border-color: rgba(16, 185, 129, 0.35) !important;
            color: #6ee7b7 !important;
        }
        [data-theme="dark"] .recycle-toast-error {
            background: rgba(153, 27, 27, 0.25) !important;
            border-color: rgba(239, 68, 68, 0.35) !important;
            color: #fca5a5 !important;
        }

        [data-theme="dark"] .recycle-bulk-bar {
            background: rgba(6, 95, 70, 0.25) !important;
            border-color: rgba(16, 185, 129, 0.35) !important;
        }
        [data-theme="dark"] .recycle-bulk-bar .selected-count {
            color: #6ee7b7 !important;
        }

        [data-theme="dark"] .recycle-select-all-row {
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] .recycle-select-all-label {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .recycle-filter-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }

        [data-theme="dark"] .recycle-item-card {
            background: #131c2e !important;
            border: 1px solid #29233b !important;
        }
        [data-theme="dark"] .recycle-item-card:hover {
            background: #18233a !important;
            border-color: #3d3156 !important;
        }
        [data-theme="dark"] .recycle-item-avatar {
            background: rgba(220, 38, 38, 0.2) !important;
            color: #f87171 !important;
            border: 1px solid rgba(220, 38, 38, 0.3) !important;
        }
        [data-theme="dark"] .recycle-item-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .recycle-item-sub {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .recycle-item-sub strong {
            color: #cbd5e1 !important;
        }

        [data-theme="dark"] .recycle-empty-icon {
            color: #334155 !important;
        }
        [data-theme="dark"] .recycle-empty-title {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .recycle-empty-desc {
            color: #64748b !important;
        }
    </style>
@endpush

<div class="activity-logs-container">
    {{-- Retention notice (same 1-year window as DCS Recycle Bin) --}}
    <div class="retention-notice-banner">
        <i class="fa-solid fa-circle-info retention-notice-icon"></i>
        <div class="retention-notice-content">
            <strong style="display:block; margin-bottom:2px;">1-year retention</strong>
            Soft-deleted items remain available in the Recycle Bin for <strong>1 year</strong> and can be restored during that period.
        </div>
    </div>

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
                    <span class="recycle-header-title">
                        <i class="fa-solid fa-building title-icon"></i>
                        Deactivated Offices
                        <span class="count-badge">({{ $deactivatedOffices->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated offices..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div class="recycle-toast-success">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div class="recycle-toast-error">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div class="recycle-bulk-bar">
                        <span class="selected-count">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected office(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            class="recycle-btn-restore">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedOffices->count() > 0)
                    <div class="recycle-select-all-row">
                        <label class="recycle-select-all-label">
                            <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedOffices->count() ? 'checked' : '' }}>
                            <span>Select All</span>
                        </label>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedOffices as $office)
                        @php
                            $officeInitials = strtoupper(substr($office->office_code ?: '?', 0, 3));
                        @endphp
                        <div wire:key="recycle-office-{{ $office->id }}" class="recycle-item-card">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $office->id }})" {{ in_array($office->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div class="recycle-item-avatar">
                                {{ $officeInitials }}
                            </div>
                            {{-- Info --}}
                            <div class="recycle-item-info">
                                <span class="recycle-item-title">{{ $office->office_name }}</span>
                                <span class="recycle-item-sub">Code: <strong>{{ $office->office_code }}</strong></span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreOffice({{ $office->id }})"
                                    wire:confirm="Are you sure you want to restore this office? It will be reactivated and visible again."
                                    class="recycle-btn-restore">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div class="recycle-empty-state">
                            <i class="fa-solid fa-recycle recycle-empty-icon"></i>
                            <h3 class="recycle-empty-title">Recycle Bin is Empty</h3>
                            <p class="recycle-empty-desc">No deactivated offices found.</p>
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
                    <span class="recycle-header-title">
                        <i class="fa-solid fa-sitemap title-icon"></i>
                        Deactivated Clusters
                        <span class="count-badge">({{ $deactivatedClusters->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated clusters..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div class="recycle-toast-success">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div class="recycle-toast-error">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div class="recycle-bulk-bar">
                        <span class="selected-count">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected cluster(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            class="recycle-btn-restore">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedClusters->count() > 0)
                    <div class="recycle-select-all-row">
                        <label class="recycle-select-all-label">
                            <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedClusters->count() ? 'checked' : '' }}>
                            <span>Select All</span>
                        </label>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedClusters as $cluster)
                        @php
                            $clusterInitials = strtoupper(substr($cluster->cluster_code ?: '?', 0, 3));
                        @endphp
                        <div wire:key="recycle-cluster-{{ $cluster->id }}" class="recycle-item-card">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $cluster->id }})" {{ in_array($cluster->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div class="recycle-item-avatar">
                                {{ $clusterInitials }}
                            </div>
                            {{-- Info --}}
                            <div class="recycle-item-info">
                                <span class="recycle-item-title">{{ $cluster->cluster_name }}</span>
                                <span class="recycle-item-sub">Code: <strong>{{ $cluster->cluster_code }}</strong></span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreCluster({{ $cluster->id }})"
                                    wire:confirm="Are you sure you want to restore this cluster? It will be reactivated and visible again."
                                    class="recycle-btn-restore">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div class="recycle-empty-state">
                            <i class="fa-solid fa-recycle recycle-empty-icon"></i>
                            <h3 class="recycle-empty-title">Recycle Bin is Empty</h3>
                            <p class="recycle-empty-desc">No deactivated clusters found.</p>
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
                    <span class="recycle-header-title">
                        <i class="fa-solid fa-sliders title-icon"></i>
                        Deactivated Roles & Clearances
                        <span class="count-badge">({{ $deactivatedRoles->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated roles..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div class="recycle-toast-success">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div class="recycle-toast-error">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div class="recycle-bulk-bar">
                        <span class="selected-count">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected role(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            class="recycle-btn-restore">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedRoles->count() > 0)
                    <div class="recycle-select-all-row">
                        <label class="recycle-select-all-label">
                            <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedRoles->count() ? 'checked' : '' }}>
                            <span>Select All</span>
                        </label>
                    </div>
                @endif

                {{-- Items List --}}
                <div class="offices-list">
                    @forelse($deactivatedRoles as $role)
                        @php
                            $roleInitials = strtoupper(substr($role->key_name ?: 'R', 0, 3));
                        @endphp
                        <div wire:key="recycle-role-{{ $role->id }}" class="recycle-item-card">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $role->id }})" {{ in_array($role->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div class="recycle-item-avatar">
                                {{ $roleInitials }}
                            </div>
                            {{-- Info --}}
                            <div class="recycle-item-info">
                                <span class="recycle-item-title">{{ $role->key_name }}</span>
                                <span class="recycle-item-sub">{{ $role->key_description ?: 'No description provided' }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreRole({{ $role->id }})"
                                    wire:confirm="Are you sure you want to restore this role? It will be reactivated and visible again."
                                    class="recycle-btn-restore">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div class="recycle-empty-state">
                            <i class="fa-solid fa-recycle recycle-empty-icon"></i>
                            <h3 class="recycle-empty-title">Recycle Bin is Empty</h3>
                            <p class="recycle-empty-desc">No deactivated roles found.</p>
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
                    <span class="recycle-header-title">
                        <i class="fa-solid fa-route title-icon"></i>
                        Deactivated Transaction Flows
                        <span class="count-badge">({{ $deactivatedFlows->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated flows..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div class="recycle-toast-success">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div class="recycle-toast-error">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div class="recycle-bulk-bar">
                        <span class="selected-count">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected flow(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            class="recycle-btn-restore">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All & Filter Row --}}
                <div class="recycle-select-all-row">
                    @if($deactivatedFlows->count() > 0)
                        <label class="recycle-select-all-label">
                            <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedFlows->count() ? 'checked' : '' }}>
                            <span>Select All</span>
                        </label>
                    @else
                        <div></div>
                    @endif
                    <div>
                        <select wire:model.live="flowPurposeFilter" class="recycle-filter-select">
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
                        <div wire:key="recycle-flow-{{ $flow->id }}" class="recycle-item-card">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $flow->id }})" {{ in_array($flow->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div class="recycle-item-avatar">
                                {{ $flowInitials }}
                            </div>
                            {{-- Info --}}
                            <div class="recycle-item-info">
                                <span class="recycle-item-title">{{ $flow->flow_name }}</span>
                                <span class="recycle-item-sub">Code: <strong>{{ $flow->flow_code }}</strong></span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreFlow({{ $flow->id }})"
                                    wire:confirm="Are you sure you want to restore this transaction flow? It will be reactivated and visible again."
                                    class="recycle-btn-restore">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div class="recycle-empty-state">
                            <i class="fa-solid fa-recycle recycle-empty-icon"></i>
                            <h3 class="recycle-empty-title">Recycle Bin is Empty</h3>
                            <p class="recycle-empty-desc">No deactivated transaction flows found.</p>
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
                    <span class="recycle-header-title">
                        <i class="fa-solid fa-users title-icon"></i>
                        Deactivated Users
                        <span class="count-badge">({{ $deactivatedUsers->count() }})</span>
                    </span>
                </div>

                {{-- Search --}}
                <div class="search-box-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" class="search-box" placeholder="Search deactivated users..." wire:model.live="search">
                </div>

                {{-- Toast Messages --}}
                @if($successMessage)
                    <div class="recycle-toast-success">
                        <i class="fa-solid fa-circle-check" style="color: #059669;"></i>
                        {{ $successMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif
                @if($errorMessage)
                    <div class="recycle-toast-error">
                        <i class="fa-solid fa-circle-xmark" style="color: #dc2626;"></i>
                        {{ $errorMessage }}
                        <button type="button" wire:click="clearMessages" style="margin-left: auto; background: none; border: none; color: inherit; cursor: pointer; font-size: 14px;">&times;</button>
                    </div>
                @endif

                {{-- Bulk Action Bar --}}
                @if(count($selectedIds) > 0)
                    <div class="recycle-bulk-bar">
                        <span class="selected-count">{{ count($selectedIds) }} selected</span>
                        <button type="button" x-data
                            x-on:click="if(confirm('Are you sure you want to restore {{ count($selectedIds) }} selected user(s)?')) { $el.disabled = true; $el.querySelector('.btn-idle').style.display = 'none'; $el.querySelector('.btn-loading').style.display = 'inline-flex'; $wire.bulkRestore(); }"
                            class="recycle-btn-restore">
                            <span class="btn-idle" style="display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-rotate-left"></i> Restore Selected</span>
                            <span class="btn-loading" style="display: none; align-items: center; gap: 5px;"><i class="fa-solid fa-circle-notch fa-spin"></i> Restoring {{ count($selectedIds) }} item(s)...</span>
                        </button>
                    </div>
                @endif

                {{-- Select All Row --}}
                @if($deactivatedUsers->count() > 0)
                    <div class="recycle-select-all-row">
                        <label class="recycle-select-all-label">
                            <input type="checkbox" wire:click="toggleAll" style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6;" {{ count($selectedIds) > 0 && count($selectedIds) === $deactivatedUsers->count() ? 'checked' : '' }}>
                            <span>Select All</span>
                        </label>
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
                        <div wire:key="recycle-user-{{ $usr->id }}" class="recycle-item-card">
                            {{-- Checkbox --}}
                            <input type="checkbox" wire:click="toggleSelection({{ $usr->id }})" {{ in_array($usr->id, $selectedIds) ? 'checked' : '' }} style="width: 16px; height: 16px; cursor: pointer; accent-color: #3b82f6; flex-shrink: 0; margin-right: 4px;">
                            {{-- Avatar --}}
                            <div class="recycle-item-avatar">
                                {{ $initials }}
                            </div>
                            {{-- Info --}}
                            <div class="recycle-item-info">
                                <span class="recycle-item-title">{{ $fullName }}</span>
                                <span class="recycle-item-sub">Username: <strong>{{ $usr->username }}</strong></span>
                                <span class="recycle-item-sub" style="font-size: 11px;">{{ $officeName }}</span>
                            </div>
                            {{-- Restore Button --}}
                            <button type="button"
                                    wire:click="restoreUser({{ $usr->id }})"
                                    wire:confirm="Are you sure you want to restore this user? They will be reactivated and able to log in again."
                                    class="recycle-btn-restore">
                                <i class="fa-solid fa-rotate-left"></i> Restore
                            </button>
                        </div>
                    @empty
                        <div class="recycle-empty-state">
                            <i class="fa-solid fa-recycle recycle-empty-icon"></i>
                            <h3 class="recycle-empty-title">Recycle Bin is Empty</h3>
                            <p class="recycle-empty-desc">No deactivated users found.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>

<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - Record Series Brackets')] class extends Component {
    // --- Selected State for Miller Columns Browser ---
    public ?int $selectedTypeId = null;
    public ?int $selectedMainBracketId = null;
    public string|int|null $selectedSubBracketId = null;
    public ?string $selectedOfficeCode = null;
    public ?int $selectedOfficeMappingId = null;

    // Search filter per column
    public string $typeSearch = '';
    public string $mainSearch = '';
    public string $subSearch = '';
    public string $officeSearch = '';

    // --- Modal States ---
    // Record Type Modal
    public bool $showTypeModal = false;
    public ?int $editingTypeId = null;
    public string $typeName = '';
    public string $typeShortCode = '';
    public bool $typeActive = true;

    // Main Bracket Modal
    public bool $showMainModal = false;
    public ?int $editingMainId = null;
    public string $mainName = '';
    public ?int $mainSeriesTypeId = null;
    public bool $mainActive = true;

    // Compile Bracket (Sub-Bracket) Modal
    public bool $showSubModal = false;
    public ?int $editingSubId = null;
    public string $subName = '';
    public ?int $subManagerId = null;
    public bool $subActive = true;

    // Quick Assign Office Modal
    public bool $showAssignOfficeModal = false;
    public array $quickSelectedOffices = [];
    public string $assignModalTargetLabel = '';

    // Delete Confirmation Modal
    public bool $showDeleteModal = false;
    public string $deleteType = ''; // 'type' | 'main' | 'sub' | 'office'
    public ?int $deleteTargetId = null;
    public string $deleteTargetName = '';

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_rdp_admin)) {
            $this->redirect(route('portal'));
            return;
        }

        // On initial opening, no path is pre-selected.
        // It will be restored from the browser's localStorage in Alpine init() if previously opened.
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    private function logAdminAction(string $changes): void
    {
        try {
            $logTable = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
            if (Schema::hasTable($logTable)) {
                DB::table($logTable)->insert([
                    'changes'      => $changes,
                    'admin_id'     => auth()->id(),
                    'what_system'  => 2, // RDP
                    'when_changes' => now(),
                ]);
            }
        } catch (\Throwable $e) {}
    }

    // ==========================================
    // COLUMN SELECTION HANDLERS
    // ==========================================
    public function selectType(int $typeId): void
    {
        $this->selectedTypeId = $typeId;
        $this->selectedMainBracketId = null;
        $this->selectedSubBracketId = null;
        $this->selectedOfficeCode = null;
        $this->selectedOfficeMappingId = null;
        $this->clearMessages();
        $this->broadcastPath();
    }

    public function selectMainBracket(int $mainId): void
    {
        $this->selectedMainBracketId = $mainId;
        $this->selectedSubBracketId = null;
        $this->selectedOfficeCode = null;
        $this->selectedOfficeMappingId = null;
        $this->clearMessages();
        $this->broadcastPath();
    }

    public function selectSubBracket(string|int $subId): void
    {
        $this->selectedSubBracketId = $subId;
        $this->selectedOfficeCode = null;
        $this->selectedOfficeMappingId = null;
        $this->clearMessages();
        $this->broadcastPath();
    }

    public function selectOffice(int $mappingId, string $code): void
    {
        $this->selectedOfficeMappingId = $mappingId;
        $this->selectedOfficeCode = $code;
        $this->broadcastPath();
    }

    public function clearPath(): void
    {
        $this->selectedTypeId = null;
        $this->selectedMainBracketId = null;
        $this->selectedSubBracketId = null;
        $this->selectedOfficeCode = null;
        $this->selectedOfficeMappingId = null;
        $this->clearMessages();
        $this->dispatch('path-cleared');
    }

    public function restorePath(?int $typeId = null, ?int $mainId = null, string|int|null $subId = null, ?string $officeCode = null, ?int $officeMapId = null): void
    {
        if (!$typeId || !DB::table('rdp_record_series_type')->where('id', $typeId)->exists()) {
            $this->clearPath();
            return;
        }

        $this->selectedTypeId = $typeId;

        if ($mainId && DB::table('rdp_record_series_brackets')->where('id', $mainId)->where('series_type_id', $typeId)->exists()) {
            $this->selectedMainBracketId = $mainId;

            if ($subId === 'generalized') {
                $this->selectedSubBracketId = 'generalized';
            } elseif ($subId && DB::table('rdp_record_series_sub_brackets')->where('id', $subId)->where('main_bracket_id', $mainId)->exists()) {
                $this->selectedSubBracketId = $subId;
            } else {
                $this->selectedSubBracketId = null;
            }

            $this->selectedOfficeCode = $officeCode;
            $this->selectedOfficeMappingId = $officeMapId;
        } else {
            $this->selectedMainBracketId = null;
            $this->selectedSubBracketId = null;
            $this->selectedOfficeCode = null;
            $this->selectedOfficeMappingId = null;
        }
    }

    private function broadcastPath(): void
    {
        $this->dispatch('path-updated', [
            'typeId'      => $this->selectedTypeId,
            'mainId'      => $this->selectedMainBracketId,
            'subId'       => $this->selectedSubBracketId,
            'officeCode'  => $this->selectedOfficeCode,
            'officeMapId' => $this->selectedOfficeMappingId,
        ]);
    }

    public function removeFirstOffice(): void
    {
        $this->clearMessages();
        if ($this->selectedOfficeMappingId) {
            $this->removeOfficeMapping($this->selectedOfficeMappingId);
            return;
        }

        $query = DB::table('rdp_office_bracket');
        if ($this->selectedSubBracketId === 'generalized') {
            $query->where('main_bracket_id', $this->selectedMainBracketId)->whereNull('sub_bracket_id');
        } else {
            $query->where('sub_bracket_id', $this->selectedSubBracketId);
        }
        $first = $query->first();

        if ($first) {
            $this->removeOfficeMapping($first->id);
        } else {
            $this->errorMessage = 'No office found to remove.';
        }
    }

    // ==========================================
    // LOCK / UNLOCK RECORD TYPE
    // ==========================================
    public function toggleLockRecordType(?int $typeId = null): void
    {
        $this->clearMessages();
        $id = $typeId ?: $this->selectedTypeId;
        if (!$id) return;

        $t = DB::table('rdp_record_series_type')->where('id', $id)->first();
        if (!$t) return;

        $newLocked = !((bool) ($t->is_locked ?? false));
        DB::table('rdp_record_series_type')->where('id', $id)->update([
            'is_locked'  => $newLocked,
            'updated_at' => now(),
        ]);

        $state = $newLocked ? 'LOCKED' : 'UNLOCKED';
        $this->logAdminAction("Set Record Series Type #{$id} status to {$state}");
        $this->successMessage = "Record Type '{$t->type_name}' is now {$state}.";
    }

    // ==========================================
    // RECORD TYPE ACTIONS
    // ==========================================
    public function openAddTypeModal(): void
    {
        $this->clearMessages();
        $this->editingTypeId = null;
        $this->typeName = '';
        $this->typeShortCode = '';
        $this->typeActive = true;
        $this->showTypeModal = true;
    }

    public function openEditTypeModal(?int $id = null): void
    {
        $this->clearMessages();
        $targetId = $id ?: $this->selectedTypeId;
        if (!$targetId) return;

        $t = DB::table('rdp_record_series_type')->where('id', $targetId)->first();
        if (!$t) return;

        $this->editingTypeId = $t->id;
        $this->typeName = $t->type_name;
        $this->typeShortCode = $t->shorted_type ?? '';
        $this->typeActive = (bool) $t->is_active;
        $this->showTypeModal = true;
    }

    public function saveType(): void
    {
        $this->clearMessages();
        $name = trim($this->typeName);
        $code = trim($this->typeShortCode);

        if (empty($name) || empty($code)) {
            $this->errorMessage = 'Type name and short code are required.';
            return;
        }

        try {
            if ($this->editingTypeId) {
                DB::table('rdp_record_series_type')->where('id', $this->editingTypeId)->update([
                    'type_name'    => $name,
                    'shorted_type' => $code,
                    'is_active'    => $this->typeActive,
                    'updated_at'   => now(),
                ]);
                $this->logAdminAction("Updated Record Series Type #{$this->editingTypeId} '{$name}'");
                $this->successMessage = "Record type '{$name}' updated successfully.";
            } else {
                $newId = DB::table('rdp_record_series_type')->insertGetId([
                    'type_name'    => $name,
                    'shorted_type' => $code,
                    'is_active'    => $this->typeActive,
                    'is_locked'    => false,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $this->selectedTypeId = $newId;
                $this->logAdminAction("Created Record Series Type #{$newId} '{$name}'");
                $this->successMessage = "Record type '{$name}' created.";
            }
            $this->showTypeModal = false;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save record type: ' . $e->getMessage();
        }
    }

    // ==========================================
    // MAIN BRACKET (CLUSTER) ACTIONS
    // ==========================================
    public function openAddMainModal(): void
    {
        $this->clearMessages();
        $this->editingMainId = null;
        $this->mainName = '';
        $this->mainSeriesTypeId = $this->selectedTypeId ?: 2;
        $this->mainActive = true;
        $this->showMainModal = true;
    }

    public function openEditMainModal(?int $id = null): void
    {
        $this->clearMessages();
        $targetId = $id ?: $this->selectedMainBracketId;
        if (!$targetId) return;

        $mb = DB::table('rdp_record_series_brackets')->where('id', $targetId)->first();
        if (!$mb) return;

        $this->editingMainId = $mb->id;
        $this->mainName = $mb->bracket_name;
        $this->mainSeriesTypeId = $mb->series_type_id ?? $this->selectedTypeId;
        $this->mainActive = (bool) $mb->is_active;
        $this->showMainModal = true;
    }

    public function saveMainBracket(): void
    {
        $this->clearMessages();
        $name = trim($this->mainName);
        if (empty($name)) {
            $this->errorMessage = 'Cluster bracket name is required.';
            return;
        }

        try {
            if ($this->editingMainId) {
                DB::table('rdp_record_series_brackets')->where('id', $this->editingMainId)->update([
                    'bracket_name'   => $name,
                    'series_type_id' => $this->mainSeriesTypeId,
                    'is_active'      => $this->mainActive,
                    'updated_at'     => now(),
                ]);
                $this->logAdminAction("Updated Cluster Main Bracket #{$this->editingMainId} '{$name}'");
                $this->successMessage = "Cluster bracket '{$name}' updated.";
            } else {
                $newId = DB::table('rdp_record_series_brackets')->insertGetId([
                    'bracket_name'   => $name,
                    'series_type_id' => $this->mainSeriesTypeId,
                    'is_active'      => $this->mainActive,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
                $this->selectedMainBracketId = $newId;
                $this->logAdminAction("Created Cluster Main Bracket #{$newId} '{$name}'");
                $this->successMessage = "Cluster bracket '{$name}' created.";
            }
            $this->showMainModal = false;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save cluster bracket: ' . $e->getMessage();
        }
    }

    // ==========================================
    // COMPILE BRACKET (SUB-BRACKET) ACTIONS
    // ==========================================
    public function openAddSubModal(): void
    {
        $this->clearMessages();
        if (!$this->selectedMainBracketId) {
            $this->errorMessage = 'Please select a Main Cluster Bracket first.';
            return;
        }
        $this->editingSubId = null;
        $this->subName = '';
        $this->subManagerId = null;
        $this->subActive = true;
        $this->showSubModal = true;
    }

    public function openEditSubModal(?int $id = null): void
    {
        $this->clearMessages();
        $targetId = $id ?: ($this->selectedSubBracketId !== 'generalized' ? $this->selectedSubBracketId : null);
        if (!$targetId) return;

        $sub = DB::table('rdp_record_series_sub_brackets')->where('id', $targetId)->first();
        if (!$sub) return;

        $this->editingSubId = $sub->id;
        $this->subName = $sub->sub_name;
        $this->subManagerId = $sub->office_manager_id;
        $this->subActive = (bool) $sub->is_active;
        $this->showSubModal = true;
    }

    public function saveSubBracket(): void
    {
        $this->clearMessages();
        $name = trim($this->subName);
        if (empty($name)) {
            $this->errorMessage = 'Bracket name is required.';
            return;
        }

        if (!$this->selectedMainBracketId) {
            $this->errorMessage = 'Parent Main Cluster Bracket is missing.';
            return;
        }

        try {
            if ($this->editingSubId) {
                DB::table('rdp_record_series_sub_brackets')->where('id', $this->editingSubId)->update([
                    'sub_name'          => $name,
                    'office_manager_id' => $this->subManagerId,
                    'is_active'         => $this->subActive,
                    'updated_at'        => now(),
                ]);
                $this->logAdminAction("Updated Sub-Bracket #{$this->editingSubId} '{$name}'");
                $this->successMessage = "Bracket '{$name}' updated.";
            } else {
                $newId = DB::table('rdp_record_series_sub_brackets')->insertGetId([
                    'main_bracket_id'   => $this->selectedMainBracketId,
                    'sub_name'          => $name,
                    'office_manager_id' => $this->subManagerId,
                    'is_active'         => $this->subActive,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $this->selectedSubBracketId = $newId;
                $this->logAdminAction("Created Sub-Bracket #{$newId} '{$name}'");
                $this->successMessage = "Bracket '{$name}' created.";
            }
            $this->showSubModal = false;
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to save bracket: ' . $e->getMessage();
        }
    }

    // ==========================================
    // OFFICE ASSIGNMENT ACTIONS (GENERALIZED & SUB)
    // ==========================================
    public function openAssignOfficeModal(): void
    {
        $this->clearMessages();
        if (!$this->selectedMainBracketId) {
            $this->errorMessage = 'Select a Main Bracket first.';
            return;
        }

        $mbName = DB::table('rdp_record_series_brackets')->where('id', $this->selectedMainBracketId)->value('bracket_name');

        if ($this->selectedSubBracketId === 'generalized') {
            $this->assignModalTargetLabel = "Generalized for Cluster: {$mbName}";
            $this->quickSelectedOffices = DB::table('rdp_office_bracket')
                ->where('main_bracket_id', $this->selectedMainBracketId)
                ->whereNull('sub_bracket_id')
                ->pluck('office_code')
                ->toArray();
        } else {
            $subName = DB::table('rdp_record_series_sub_brackets')->where('id', $this->selectedSubBracketId)->value('sub_name');
            $this->assignModalTargetLabel = "Sub-Bracket: {$subName} ({$mbName})";
            $this->quickSelectedOffices = DB::table('rdp_office_bracket')
                ->where('sub_bracket_id', $this->selectedSubBracketId)
                ->pluck('office_code')
                ->toArray();
        }

        $this->showAssignOfficeModal = true;
    }

    public function toggleOfficeSelection(string $code): void
    {
        if (in_array($code, $this->quickSelectedOffices)) {
            $this->quickSelectedOffices = array_values(array_diff($this->quickSelectedOffices, [$code]));
        } else {
            $this->quickSelectedOffices[] = $code;
        }
    }

    public function saveOfficeAssignments(): void
    {
        $this->clearMessages();
        if (!$this->selectedMainBracketId) {
            $this->showAssignOfficeModal = false;
            return;
        }

        try {
            DB::beginTransaction();

            if ($this->selectedSubBracketId === 'generalized') {
                DB::table('rdp_office_bracket')
                    ->where('main_bracket_id', $this->selectedMainBracketId)
                    ->whereNull('sub_bracket_id')
                    ->delete();

                foreach ($this->quickSelectedOffices as $code) {
                    DB::table('rdp_office_bracket')->insert([
                        'main_bracket_id' => $this->selectedMainBracketId,
                        'sub_bracket_id'  => null,
                        'office_code'     => $code,
                        'is_active'       => true,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
                $this->logAdminAction("Updated Generalized Cluster Offices for Main Bracket #{$this->selectedMainBracketId}");
                $this->successMessage = "Generalized offices updated for the cluster.";
            } else {
                DB::table('rdp_office_bracket')
                    ->where('sub_bracket_id', $this->selectedSubBracketId)
                    ->delete();

                foreach ($this->quickSelectedOffices as $code) {
                    DB::table('rdp_office_bracket')->insert([
                        'main_bracket_id' => $this->selectedMainBracketId,
                        'sub_bracket_id'  => $this->selectedSubBracketId,
                        'office_code'     => $code,
                        'is_active'       => true,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
                $this->logAdminAction("Updated Sub-Bracket #{$this->selectedSubBracketId} Offices");
                $this->successMessage = "Offices mapped to bracket.";
            }

            DB::commit();
            $this->showAssignOfficeModal = false;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to assign offices: ' . $e->getMessage();
        }
    }

    public function removeOfficeMapping(int $mappingId): void
    {
        $this->clearMessages();
        try {
            DB::table('rdp_office_bracket')->where('id', $mappingId)->delete();
            $this->logAdminAction("Removed office bracket mapping #{$mappingId}");
            $this->successMessage = "Office unlinked.";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to remove office: ' . $e->getMessage();
        }
    }

    // ==========================================
    // DELETE / REMOVE MODAL ACTIONS
    // ==========================================
    public function confirmDelete(string $type, int $id, string $name): void
    {
        $this->clearMessages();
        $this->deleteType = $type;
        $this->deleteTargetId = $id;
        $this->deleteTargetName = $name;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        $this->clearMessages();
        if (!$this->deleteTargetId) return;

        try {
            DB::beginTransaction();

            if ($this->deleteType === 'type') {
                $mainIds = DB::table('rdp_record_series_brackets')
                    ->where('series_type_id', $this->deleteTargetId)
                    ->pluck('id')
                    ->toArray();

                if (!empty($mainIds)) {
                    $subIds = DB::table('rdp_record_series_sub_brackets')->whereIn('main_bracket_id', $mainIds)->pluck('id')->toArray();
                    DB::table('rdp_office_bracket')->whereIn('main_bracket_id', $mainIds)->delete();
                    if (!empty($subIds)) {
                        DB::table('rdp_office_bracket')->whereIn('sub_bracket_id', $subIds)->delete();
                        DB::table('rdp_record_series_sub_brackets')->whereIn('id', $subIds)->delete();
                    }
                    DB::table('rdp_record_series')->whereIn('bracket_id', $mainIds)->update(['bracket_id' => null, 'sub_bracket_id' => null]);
                    DB::table('rdp_record_series_brackets')->whereIn('id', $mainIds)->delete();
                }

                DB::table('rdp_record_series_type')->where('id', $this->deleteTargetId)->delete();
                $this->selectedTypeId = DB::table('rdp_record_series_type')->value('id');
                $this->selectedMainBracketId = null;
                $this->successMessage = "Record type '{$this->deleteTargetName}' removed.";
            } elseif ($this->deleteType === 'main') {
                $subIds = DB::table('rdp_record_series_sub_brackets')
                    ->where('main_bracket_id', $this->deleteTargetId)
                    ->pluck('id')
                    ->toArray();

                DB::table('rdp_office_bracket')->where('main_bracket_id', $this->deleteTargetId)->delete();
                if (!empty($subIds)) {
                    DB::table('rdp_office_bracket')->whereIn('sub_bracket_id', $subIds)->delete();
                    DB::table('rdp_record_series_sub_brackets')->whereIn('id', $subIds)->delete();
                }

                DB::table('rdp_record_series')->where('bracket_id', $this->deleteTargetId)->update(['bracket_id' => null, 'sub_bracket_id' => null]);
                DB::table('rdp_record_series_brackets')->where('id', $this->deleteTargetId)->delete();

                if ($this->selectedMainBracketId === $this->deleteTargetId) {
                    $this->selectedMainBracketId = null;
                }
                $this->successMessage = "Cluster bracket '{$this->deleteTargetName}' removed.";
            } elseif ($this->deleteType === 'sub') {
                DB::table('rdp_office_bracket')->where('sub_bracket_id', $this->deleteTargetId)->delete();
                DB::table('rdp_record_series')->where('sub_bracket_id', $this->deleteTargetId)->update(['sub_bracket_id' => null]);
                DB::table('rdp_record_series_sub_brackets')->where('id', $this->deleteTargetId)->delete();

                if ($this->selectedSubBracketId === $this->deleteTargetId) {
                    $this->selectedSubBracketId = 'generalized';
                }
                $this->successMessage = "Bracket '{$this->deleteTargetName}' removed.";
            }

            if ($this->selectedTypeId) {
                $this->broadcastPath();
            } else {
                $this->dispatch('path-cleared');
            }

            DB::commit();
            $this->showDeleteModal = false;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to remove: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        // 1. Column 1: Record Types
        $typesQuery = DB::table('rdp_record_series_type');
        if (!empty($this->typeSearch)) {
            $typesQuery->where(function($q) {
                $q->where('type_name', 'ilike', '%' . $this->typeSearch . '%')
                  ->orWhere('shorted_type', 'ilike', '%' . $this->typeSearch . '%');
            });
        }
        $types = $typesQuery->orderBy('id', 'asc')->get();

        foreach ($types as $t) {
            $t->bracket_count = DB::table('rdp_record_series_brackets')->where('series_type_id', $t->id)->count();
            $t->series_count = DB::table('rdp_record_series')->where('series_type', $t->id)->count();
            $t->is_locked = (bool) ($t->is_locked ?? false);
        }

        // Selected Record Type Object & Lock State
        $selectedTypeObj = $this->selectedTypeId ? DB::table('rdp_record_series_type')->where('id', $this->selectedTypeId)->first() : null;
        $isTypeLocked = (bool) ($selectedTypeObj->is_locked ?? false);

        // 2. Column 2: Main Brackets (Cluster Brackets)
        $mainBrackets = collect();
        if ($this->selectedTypeId) {
            $mbQuery = DB::table('rdp_record_series_brackets')
                ->where('series_type_id', $this->selectedTypeId);

            if (!empty($this->mainSearch)) {
                $mbQuery->where('bracket_name', 'ilike', '%' . $this->mainSearch . '%');
            }

            $mainBrackets = $mbQuery->orderBy('id', 'asc')->get();

            foreach ($mainBrackets as $mb) {
                $mb->sub_count = DB::table('rdp_record_series_sub_brackets')->where('main_bracket_id', $mb->id)->count();
                $mb->series_count = DB::table('rdp_record_series')->where('bracket_id', $mb->id)->count();
                $mb->gen_office_count = DB::table('rdp_office_bracket')
                    ->where('main_bracket_id', $mb->id)
                    ->whereNull('sub_bracket_id')
                    ->count();
            }
        }

        // 3. Column 3: Compile Brackets (Sub-Brackets)
        $subBrackets = collect();
        $generalizedCount = 0;
        if ($this->selectedMainBracketId) {
            $generalizedCount = DB::table('rdp_office_bracket')
                ->where('main_bracket_id', $this->selectedMainBracketId)
                ->whereNull('sub_bracket_id')
                ->count();

            $sbQuery = DB::table('rdp_record_series_sub_brackets')
                ->where('main_bracket_id', $this->selectedMainBracketId);

            if (!empty($this->subSearch)) {
                $sbQuery->where('sub_name', 'ilike', '%' . $this->subSearch . '%');
            }

            $subBrackets = $sbQuery->orderBy('sub_name', 'asc')->get();

            foreach ($subBrackets as $sb) {
                $sb->office_count = DB::table('rdp_office_bracket')->where('sub_bracket_id', $sb->id)->count();
                $sb->series_count = DB::table('rdp_record_series')->where('sub_bracket_id', $sb->id)->count();
            }
        }

        // 4. Column 4: Offices (Automation & Routing)
        $activeOffices = collect();
        if ($this->selectedMainBracketId && $this->selectedSubBracketId) {
            $officeQuery = DB::table('rdp_office_bracket');

            if ($this->selectedSubBracketId === 'generalized') {
                $officeQuery->where('main_bracket_id', $this->selectedMainBracketId)
                            ->whereNull('sub_bracket_id');
            } else {
                $officeQuery->where('sub_bracket_id', $this->selectedSubBracketId);
            }

            if (Schema::hasTable($officeTable)) {
                $officeQuery->leftJoin($officeTable . ' as off', 'rdp_office_bracket.office_code', '=', 'off.office_code')
                    ->select([
                        'rdp_office_bracket.*',
                        'off.office_name',
                    ]);
            } else {
                $officeQuery->select([
                    'rdp_office_bracket.*',
                    DB::raw("rdp_office_bracket.office_code as office_name"),
                ]);
            }

            if (!empty($this->officeSearch)) {
                $officeQuery->where(function($q) {
                    $q->where('rdp_office_bracket.office_code', 'ilike', '%' . $this->officeSearch . '%')
                      ->orWhere('off.office_name', 'ilike', '%' . $this->officeSearch . '%');
                });
            }

            $activeOffices = $officeQuery->orderBy('rdp_office_bracket.office_code', 'asc')->get();
        }

        // All system offices for assignment modal
        $allOffices = Schema::hasTable($officeTable)
            ? DB::table($officeTable)->where('is_active', true)->orderBy('office_name', 'asc')->get()
            : collect();

        $selectedMainObj = $this->selectedMainBracketId ? DB::table('rdp_record_series_brackets')->where('id', $this->selectedMainBracketId)->first() : null;
        $selectedSubObj = ($this->selectedSubBracketId !== 'generalized' && $this->selectedSubBracketId)
            ? DB::table('rdp_record_series_sub_brackets')->where('id', $this->selectedSubBracketId)->first()
            : null;

        return [
            'types'            => $types,
            'isTypeLocked'     => $isTypeLocked,
            'mainBrackets'     => $mainBrackets,
            'subBrackets'      => $subBrackets,
            'generalizedCount' => $generalizedCount,
            'activeOffices'    => $activeOffices,
            'allOffices'       => $allOffices,
            'selectedTypeObj'  => $selectedTypeObj,
            'selectedMainObj'  => $selectedMainObj,
            'selectedSubObj'   => $selectedSubObj,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css', 'resources/css/admin/activity_logs.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .miller-container {
            max-width: 1560px;
            margin: 0 auto;
            padding: 16px 20px;
            height: calc(100vh - 100px);
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            user-select: none;
        }

        /* 4-Column Miller Browser Layout */
        .miller-browser {
            flex: 1 1 auto;
            display: grid;
            grid-template-columns: 240px 300px 320px 1fr 280px;
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

        /* Locked Record Type Columns Grey Layout & Filter Overlay */
        .miller-column.column-locked {
            background-color: #f1f5f9 !important;
            filter: grayscale(85%) contrast(92%);
            opacity: 0.82;
            position: relative;
            transition: all 0.2s ease-in-out;
        }
        .miller-column.column-locked .m-col-header {
            background-color: #e2e8f0 !important;
            color: #64748b !important;
            border-bottom-color: #cbd5e1 !important;
        }
        .miller-column.column-locked .m-col-search {
            background-color: #f1f5f9 !important;
            border-bottom-color: #e2e8f0 !important;
        }
        .miller-column.column-locked .m-col-search input {
            background-color: #e2e8f0 !important;
            color: #64748b !important;
            border-color: #cbd5e1 !important;
        }
        .miller-column.column-locked .m-item {
            color: #64748b;
        }
        .miller-column.column-locked .m-item:hover {
            background-color: #e2e8f0;
            color: #334155;
        }
        .miller-column.column-locked .m-item.active {
            background-color: #64748b !important;
            color: #ffffff !important;
            border-left-color: #475569 !important;
        }
        .miller-column.column-locked .m-item-generalized {
            background-color: #e2e8f0 !important;
            color: #64748b !important;
            border-bottom-color: #cbd5e1 !important;
        }
        .miller-column.column-locked .m-item-generalized.active {
            background-color: #64748b !important;
            color: #ffffff !important;
            border-left-color: #475569 !important;
        }
        .miller-column.column-locked .m-office-item {
            color: #64748b;
            border-bottom-color: #e2e8f0;
        }
        .miller-column.column-locked .m-office-badge {
            background-color: #e2e8f0;
            color: #64748b;
            border-color: #cbd5e1;
        }
        /* Grey Filter Overlay */
        .miller-column.column-locked::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(148, 163, 184, 0.18);
            pointer-events: none;
            z-index: 5;
        }
        .m-col-header {
            padding: 11px 14px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .m-col-header span {
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .m-col-search {
            padding: 6px 10px;
            border-bottom: 1px solid #f1f5f9;
            background: #ffffff;
        }
        .m-col-search input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }
        .m-col-search input:focus {
            border-color: #2563eb;
        }
        .m-col-list {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 4px 0;
        }
        .m-item {
            padding: 9px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
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
        .m-item.active .m-item-icon {
            color: #ffffff !important;
        }
        .m-item.active .m-item-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }
        .m-item.active .m-item-arrow {
            color: #ffffff;
        }
        .m-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .m-item-icon {
            font-size: 14px;
            color: #64748b;
            flex-shrink: 0;
        }
        .m-item-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .m-item-badge {
            font-size: 11px;
            padding: 1px 6px;
            border-radius: 10px;
            background: #e2e8f0;
            color: #475569;
            font-weight: 700;
            flex-shrink: 0;
        }
        .m-item-arrow {
            font-size: 11px;
            color: #94a3b8;
            margin-left: 6px;
        }

        /* Special Generalized Item */
        .m-item-generalized {
            background: #fefce8;
            border-bottom: 1px solid #fef08a;
            color: #854d0e;
            font-weight: 700;
        }
        .m-item-generalized:hover {
            background: #fef9c3;
        }
        .m-item-generalized.active {
            background: #ca8a04 !important;
            color: #ffffff !important;
            border-left-color: #a16207;
        }
        .m-item-generalized.active .m-item-icon {
            color: #ffffff !important;
        }

        /* Office Item in Col 4 */
        .m-office-item {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12.5px;
            cursor: pointer;
            transition: all 0.1s ease;
            border-left: 3px solid transparent;
        }
        .m-office-item:hover {
            background: #f8fafc;
        }
        .m-office-item.active {
            background: #f0fdf4;
            border-left-color: #16a34a;
        }
        .m-office-badge {
            padding: 2px 7px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 4px;
            font-weight: 800;
            font-size: 11px;
            margin-right: 6px;
        }

        /* Inspector Pane (Right Side) */
        .m-inspector {
            background: #ffffff;
            display: flex;
            flex-direction: column;
            padding: 18px;
            overflow-y: auto;
        }
        .m-inspector-preview {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 16px 0 20px 0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 18px;
        }
        .m-inspector-icon {
            font-size: 42px;
            color: #64748b;
            margin-bottom: 12px;
        }
        .m-inspector-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.35;
            margin: 0 0 4px 0;
            word-break: break-word;
        }
        .m-inspector-kind {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .m-inspector-prop {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            margin-bottom: 9px;
            color: #64748b;
        }
        .m-inspector-prop strong {
            color: #1e293b;
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
            padding: 8px 16px;
            font-size: 12px;
            color: #475569;
            overflow-x: auto;
            white-space: nowrap;
        }
        .miller-path-tag {
            font-weight: 700;
            color: #0f172a;
        }

        /* ========================================================
           UNIFIED FLOATING RIGHT-CLICK CONTEXT MODAL / MENU
           ======================================================== */
        .m-floating-popout {
            position: fixed;
            z-index: 3500;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 20px 30px -8px rgba(15, 23, 42, 0.22), 0 8px 12px -5px rgba(15, 23, 42, 0.1);
            width: 220px;
            padding: 6px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            animation: ctxFadeIn 0.1s ease-out;
            font-family: inherit;
        }
        @keyframes ctxFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .m-ctx-header {
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 4px;
        }
        .m-ctx-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border: none;
            background: transparent;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            color: #1e293b;
            cursor: pointer;
            text-align: left;
            width: 100%;
            transition: all 0.1s ease;
            box-sizing: border-box;
        }
        .m-ctx-item:hover {
            background: #f1f5f9;
            color: #2563eb;
        }
        /* Unified icon color */
        .m-ctx-icon {
            font-size: 13px;
            width: 16px;
            text-align: center;
            color: #64748b !important;
            transition: color 0.1s ease;
        }
        .m-ctx-item:hover .m-ctx-icon {
            color: #2563eb !important;
        }
        .m-ctx-item.danger {
            color: #1e293b;
        }
        .m-ctx-item.danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }
        .m-ctx-item.danger:hover .m-ctx-icon {
            color: #dc2626 !important;
        }
        .m-ctx-divider {
            height: 1px;
            background: #f1f5f9;
            margin: 4px 0;
        }

        /* Tool Buttons */
        .m-tool-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            color: #334155;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .m-tool-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
        .m-tool-btn.primary {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
        .m-tool-btn.primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        /* Modal Dialogs */
        .m-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 4000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .m-modal-dialog {
            background: #ffffff;
            width: 100%;
            max-width: 580px;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            border: 1px solid #cbd5e1;
            display: flex;
            flex-direction: column;
            max-height: 85vh;
        }
        .m-modal-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            color: #0f172a;
        }
        .m-modal-body {
            padding: 20px;
            overflow-y: auto;
        }
        .m-modal-footer {
            padding: 14px 20px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .miller-browser,
        [data-theme="dark"] .miller-column,
        [data-theme="dark"] .m-inspector,
        [data-theme="dark"] .m-floating-popout {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .miller-column:nth-child(even) {
            background: #182234;
        }
        [data-theme="dark"] .m-col-header,
        [data-theme="dark"] .miller-path-bar,
        [data-theme="dark"] .m-modal-header,
        [data-theme="dark"] .m-modal-footer {
            background: #0f172a;
            border-color: #334155;
            color: #f1f5f9;
        }
        [data-theme="dark"] .miller-column.column-locked {
            background: #0b1120 !important;
            opacity: 0.75;
        }
        [data-theme="dark"] .miller-column.column-locked .m-col-header {
            background: #070a12 !important;
            color: #64748b !important;
            border-bottom-color: #1e293b !important;
        }
        [data-theme="dark"] .miller-column.column-locked .m-col-search {
            background: #0b1120 !important;
        }
        [data-theme="dark"] .miller-column.column-locked .m-col-search input {
            background: #1e293b !important;
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .miller-column.column-locked::after {
            background: rgba(15, 23, 42, 0.45);
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
        [data-theme="dark"] .m-item-badge {
            background: #334155;
            color: #cbd5e1;
        }
        [data-theme="dark"] .m-ctx-header {
            color: #94a3b8;
            border-bottom-color: #334155;
        }
        [data-theme="dark"] .m-ctx-item {
            color: #e2e8f0;
        }
        [data-theme="dark"] .m-ctx-item:hover {
            background: #334155;
            color: #60a5fa;
        }
        [data-theme="dark"] .m-ctx-icon {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .m-ctx-item:hover .m-ctx-icon {
            color: #60a5fa !important;
        }
        [data-theme="dark"] .m-ctx-item.danger:hover .m-ctx-icon {
            color: #f87171 !important;
        }
        [data-theme="dark"] .m-ctx-divider {
            background: #334155;
        }
        [data-theme="dark"] .m-tool-btn {
            background: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        [data-theme="dark"] .m-tool-btn:hover {
            background: #0f172a;
        }
        [data-theme="dark"] .m-inspector-title {
            color: #f1f5f9;
        }
        [data-theme="dark"] .m-inspector-prop strong {
            color: #e2e8f0;
        }
        [data-theme="dark"] .m-inspector-preview {
            border-bottom-color: #334155;
        }
        [data-theme="dark"] .m-modal-dialog {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .m-item-generalized {
            background: #422006;
            border-bottom-color: #713f12;
            color: #fef08a;
        }
        [data-theme="dark"] .m-item-generalized:hover {
            background: #713f12;
        }
        [data-theme="dark"] .m-office-item {
            border-bottom-color: #334155;
        }
        [data-theme="dark"] .m-office-item:hover {
            background: #0f172a;
        }
    </style>
@endpush

<div class="miller-container"
     x-data="{
        ctxOpen: false,
        ctxX: 0,
        ctxY: 0,
        ctxZone: 'type', // 'type' | 'main' | 'sub' | 'office'
        ctxItemId: null,
        init() {
            try {
                const saved = localStorage.getItem('rms_brackets_saved_path');
                if (saved) {
                    const data = JSON.parse(saved);
                    if (data && data.typeId) {
                        this.$wire.restorePath(data.typeId, data.mainId, data.subId, data.officeCode, data.officeMapId);
                    }
                }
            } catch (e) {
                console.error('Error restoring saved path:', e);
            }

            window.addEventListener('path-updated', (e) => {
                try {
                    localStorage.setItem('rms_brackets_saved_path', JSON.stringify(e.detail));
                } catch (err) {}
            });

            window.addEventListener('path-cleared', () => {
                try {
                    localStorage.removeItem('rms_brackets_saved_path');
                } catch (err) {}
            });
        },
        showCtx(e, zone, itemId = null) {
            e.preventDefault();
            e.stopPropagation();
            this.ctxZone = zone;
            this.ctxItemId = itemId;
            this.ctxX = e.clientX;
            this.ctxY = e.clientY;
            this.ctxOpen = true;
            this.$nextTick(() => {
                const el = this.$refs.ctxFloating;
                if (!el) return;
                this.ctxX = Math.max(10, Math.min(e.clientX, window.innerWidth - el.offsetWidth - 12));
                this.ctxY = Math.max(10, Math.min(e.clientY, window.innerHeight - el.offsetHeight - 12));
            });
        },
        closeCtx() {
            this.ctxOpen = false;
        }
     }">

    {{-- Feedback Messages --}}
    @if($successMessage)
        <div role="status" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-circle-check" style="margin-right: 8px;"></i> {{ $successMessage }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #15803d; cursor: pointer;">&times;</button>
        </div>
    @endif

    @if($errorMessage)
        <div role="alert" style="margin-bottom: 12px; padding: 10px 14px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-size: 13px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
            <div><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> {{ $errorMessage }}</div>
            <button type="button" wire:click="clearMessages" style="background: none; border: none; color: #b91c1c; cursor: pointer;">&times;</button>
        </div>
    @endif

    {{-- 4-Column Miller Browser Area (Right-click in any column to pop out dynamic menu) --}}
    <div class="miller-browser">
        {{-- COLUMN 1: RECORDS TYPE --}}
        <div class="miller-column" @contextmenu="showCtx($event, 'type')">
            <div class="m-col-header">
                <span><i class="fa-solid fa-database m-ctx-icon"></i> 1. Records Type</span>
                <span class="m-item-badge">{{ count($types) }}</span>
            </div>
            <div class="m-col-search">
                <input type="text" wire:model.live.debounce.200ms="typeSearch" placeholder="Filter type...">
            </div>
            <div class="m-col-list">
                @forelse($types as $t)
                    <div class="m-item {{ $selectedTypeId === $t->id ? 'active' : '' }}" 
                         wire:click="selectType({{ $t->id }})" 
                         @contextmenu="$wire.selectType({{ $t->id }}); showCtx($event, 'type', {{ $t->id }})"
                         wire:key="type-{{ $t->id }}">
                        <div class="m-item-left">
                            <i class="fa-solid fa-box-archive m-item-icon"></i>
                            <span class="m-item-title" title="{{ $t->type_name }}">
                                {{ $t->shorted_type ?: $t->type_name }}
                            </span>
                            @if($t->is_locked)
                                <i class="fa-solid fa-lock" style="font-size: 10.5px; color: currentColor; opacity: 0.85; margin-left: 5px;" title="Locked"></i>
                            @endif
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <span class="m-item-badge">{{ $t->bracket_count }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                @empty
                    <div style="padding: 16px; font-size: 12px; color: #94a3b8; text-align: center;">No types found</div>
                @endforelse
            </div>
        </div>

        {{-- COLUMN 2: MAIN BRACKET (CLUSTER BRACKET) --}}
        <div class="miller-column {{ $isTypeLocked ? 'column-locked' : '' }}" @contextmenu="showCtx($event, 'main')">
            <div class="m-col-header">
                <span>
                    <i class="fa-solid fa-folder-tree m-ctx-icon"></i> 2. Main (Cluster) Bracket
                    @if($isTypeLocked)
                        <i class="fa-solid fa-lock" style="font-size: 10px; color: currentColor; opacity: 0.7; margin-left: 4px;" title="Locked by Record Type"></i>
                    @endif
                </span>
                <span class="m-item-badge">{{ count($mainBrackets) }}</span>
            </div>
            <div class="m-col-search">
                <input type="text" wire:model.live.debounce.200ms="mainSearch" placeholder="Filter main bracket...">
            </div>
            <div class="m-col-list">
                @forelse($mainBrackets as $mb)
                    <div class="m-item {{ $selectedMainBracketId === $mb->id ? 'active' : '' }}" 
                         wire:click="selectMainBracket({{ $mb->id }})" 
                         @contextmenu="$wire.selectMainBracket({{ $mb->id }}); showCtx($event, 'main', {{ $mb->id }})"
                         wire:key="main-{{ $mb->id }}">
                        <div class="m-item-left">
                            <i class="fa-solid fa-folder-closed m-item-icon"></i>
                            <span class="m-item-title" title="{{ $mb->bracket_name }}">{{ $mb->bracket_name }}</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <span class="m-item-badge" title="{{ $mb->sub_count }} compile brackets">{{ $mb->sub_count }}</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>
                @empty
                    <div style="padding: 16px; font-size: 12px; color: #94a3b8; text-align: center;">
                        {{ $selectedTypeId ? 'No main brackets in this type' : 'Select a records type' }}
                    </div>
                @endforelse
            </div>
        </div>

        {{-- COLUMN 3: COMPILE BRACKET (SUB BRACKET) --}}
        <div class="miller-column {{ $isTypeLocked ? 'column-locked' : '' }}" @contextmenu="showCtx($event, 'sub')">
            <div class="m-col-header">
                <span>
                    <i class="fa-solid fa-diagram-project m-ctx-icon"></i> 3. Compile Bracket
                    @if($isTypeLocked)
                        <i class="fa-solid fa-lock" style="font-size: 10px; color: currentColor; opacity: 0.7; margin-left: 4px;" title="Locked by Record Type"></i>
                    @endif
                </span>
                <span class="m-item-badge">{{ count($subBrackets) }}</span>
            </div>
            <div class="m-col-search">
                <input type="text" wire:model.live.debounce.200ms="subSearch" placeholder="Filter compile bracket...">
            </div>
            <div class="m-col-list">
                @if($selectedMainBracketId)
                    {{-- SPECIAL ROW: Generalized for Cluster --}}
                    <div class="m-item m-item-generalized {{ $selectedSubBracketId === 'generalized' ? 'active' : '' }}" 
                         wire:click="selectSubBracket('generalized')"
                         @contextmenu="$wire.selectSubBracket('generalized'); showCtx($event, 'sub', 'generalized')"
                         title="Offices generalized directly across this entire cluster bracket">
                        <div class="m-item-left">
                            <i class="fa-solid fa-star m-item-icon"></i>
                            <span class="m-item-title">[Generalized for Cluster]</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <span class="m-item-badge" style="background: rgba(0,0,0,0.1);">{{ $generalizedCount }} off</span>
                            <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                        </div>
                    </div>

                    {{-- Departmental Compile Brackets --}}
                    @forelse($subBrackets as $sb)
                        <div class="m-item {{ $selectedSubBracketId === $sb->id ? 'active' : '' }}" 
                             wire:click="selectSubBracket({{ $sb->id }})" 
                             @contextmenu="$wire.selectSubBracket({{ $sb->id }}); showCtx($event, 'sub', {{ $sb->id }})"
                             wire:key="sub-{{ $sb->id }}">
                            <div class="m-item-left">
                                <i class="fa-solid fa-turn-up m-item-icon" style="transform: rotate(90deg);"></i>
                                <span class="m-item-title" title="{{ $sb->sub_name }}">{{ $sb->sub_name }}</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <span class="m-item-badge">{{ $sb->office_count }} off</span>
                                <i class="fa-solid fa-chevron-right m-item-arrow"></i>
                            </div>
                        </div>
                    @empty
                        <div style="padding: 16px; font-size: 12px; color: #94a3b8; text-align: center;">No compile brackets yet</div>
                    @endforelse
                @else
                    <div style="padding: 16px; font-size: 12px; color: #94a3b8; text-align: center;">Select a main bracket</div>
                @endif
            </div>
        </div>

        {{-- COLUMN 4: OFFICES (AUTOMATION & ROUTING) --}}
        <div class="miller-column {{ $isTypeLocked ? 'column-locked' : '' }}" @contextmenu="showCtx($event, 'office')">
            <div class="m-col-header">
                <span>
                    <i class="fa-solid fa-building m-ctx-icon"></i> 
                    4. Offices ({{ $selectedSubBracketId === 'generalized' ? 'Cluster General' : 'Sub-Bracket' }})
                    @if($isTypeLocked)
                        <i class="fa-solid fa-lock" style="font-size: 10px; color: currentColor; opacity: 0.7; margin-left: 4px;" title="Locked by Record Type"></i>
                    @endif
                </span>
                <span class="m-item-badge">{{ count($activeOffices) }}</span>
            </div>
            <div class="m-col-search">
                <input type="text" wire:model.live.debounce.200ms="officeSearch" placeholder="Filter assigned office...">
            </div>
            <div class="m-col-list">
                @if($selectedMainBracketId && $selectedSubBracketId)
                    <div style="padding: 8px 12px; background: #eff6ff; border-bottom: 1px solid #bfdbfe; font-size: 11.5px; color: #1e40af; display: flex; justify-content: space-between; align-items: center;">
                        <span>
                            @if($selectedSubBracketId === 'generalized')
                                <strong>Cluster Level:</strong> Applies to whole bracket
                            @else
                                <strong>Compile Level:</strong> Sub-bracket routing
                            @endif
                        </span>
                        @if(!$isTypeLocked)
                            <button type="button" wire:click="openAssignOfficeModal" style="background: #2563eb; color: #fff; border: none; border-radius: 4px; padding: 2px 7px; font-size: 11px; cursor: pointer; font-weight: 700;">
                                + Assign
                            </button>
                        @endif
                    </div>

                    @forelse($activeOffices as $off)
                        <div class="m-office-item {{ $selectedOfficeMappingId === $off->id ? 'active' : '' }}" 
                             wire:click="selectOffice({{ $off->id }}, '{{ $off->office_code }}')"
                             @contextmenu="$wire.selectOffice({{ $off->id }}, '{{ $off->office_code }}'); showCtx($event, 'office', {{ $off->id }})"
                             wire:key="off-map-{{ $off->id }}">
                            <div style="display: flex; align-items: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <span class="m-office-badge">{{ $off->office_code }}</span>
                                <span style="color: #1e293b; font-weight: 600;" title="{{ $off->office_name }}">{{ Str::limit($off->office_name, 22) }}</span>
                            </div>
                            @if(!$isTypeLocked)
                                <button type="button" wire:click.stop="removeOfficeMapping({{ $off->id }})" title="Unlink office" style="background: none; border: none; color: #94a3b8; cursor: pointer; padding: 4px 6px;">
                                    <i class="fa-solid fa-xmark" style="color: #ef4444;"></i>
                                </button>
                            @endif
                        </div>
                    @empty
                        <div style="padding: 24px 16px; text-align: center; color: #94a3b8; font-size: 12.5px;">
                            <i class="fa-solid fa-network-wired m-ctx-icon" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                            No offices assigned.<br>
                            @if(!$isTypeLocked)
                                <button type="button" wire:click="openAssignOfficeModal" style="margin-top: 8px; background: none; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 4px 10px; color: #2563eb; font-weight: 700; cursor: pointer;">
                                    + Assign Office Now
                                </button>
                            @endif
                        </div>
                    @endforelse
                @else
                    <div style="padding: 16px; font-size: 12px; color: #94a3b8; text-align: center;">Select a bracket</div>
                @endif
            </div>
        </div>

        {{-- COLUMN 5: INSPECTOR / DETAILS PREVIEW --}}
        <div class="m-inspector">
            @if($selectedSubObj)
                {{-- Compile Bracket Details --}}
                <div class="m-inspector-preview">
                    <i class="fa-solid fa-diagram-project m-inspector-icon"></i>
                    <h4 class="m-inspector-title">{{ $selectedSubObj->sub_name }}</h4>
                    <span class="m-inspector-kind">Compile Bracket (Sub)</span>
                </div>
                <div style="margin-bottom: 16px;">
                    <div class="m-inspector-prop"><span>Parent Cluster:</span> <strong>{{ $selectedMainObj->bracket_name ?? '—' }}</strong></div>
                    <div class="m-inspector-prop"><span>Status:</span> <strong>{{ $selectedSubObj->is_active ? 'Active' : 'Inactive' }}</strong></div>
                    <div class="m-inspector-prop"><span>Mapped Offices:</span> <strong>{{ count($activeOffices) }} offices</strong></div>
                    <div class="m-inspector-prop"><span>Record Series:</span> <strong>{{ DB::table('rdp_record_series')->where('sub_bracket_id', $selectedSubObj->id)->count() }}</strong></div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
                    @if(!$isTypeLocked)
                        <button type="button" wire:click="openEditSubModal({{ $selectedSubObj->id }})" class="m-tool-btn" style="justify-content: center;">
                            <i class="fa-solid fa-pen m-ctx-icon"></i> Configure Bracket
                        </button>
                        <button type="button" wire:click="openAssignOfficeModal" class="m-tool-btn primary" style="justify-content: center;">
                            <i class="fa-solid fa-building-circle-check"></i> Manage Offices
                        </button>
                        <button type="button" wire:click="confirmDelete('sub', {{ $selectedSubObj->id }}, '{{ addslashes($selectedSubObj->sub_name) }}')" class="m-tool-btn" style="justify-content: center; color: #ef4444; border-color: #fecaca;">
                            <i class="fa-solid fa-trash m-ctx-icon" style="color: #ef4444;"></i> Remove Bracket
                        </button>
                    @else
                        <div style="padding: 10px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; font-size: 11.5px; color: #92400e; text-align: center;">
                            <i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Record Type is locked. Right-click to unlock.
                        </div>
                    @endif
                </div>
            @elseif($selectedSubBracketId === 'generalized' && $selectedMainObj)
                {{-- Generalized Cluster Details --}}
                <div class="m-inspector-preview">
                    <i class="fa-solid fa-star m-inspector-icon"></i>
                    <h4 class="m-inspector-title">{{ $selectedMainObj->bracket_name }}</h4>
                    <span class="m-inspector-kind">Generalized Cluster Scope</span>
                </div>
                <div style="margin-bottom: 16px;">
                    <div class="m-inspector-prop"><span>Scope:</span> <strong>Whole Cluster</strong></div>
                    <div class="m-inspector-prop"><span>Records Type:</span> <strong>{{ $selectedTypeObj->shorted_type ?? 'CSPC' }}</strong></div>
                    <div class="m-inspector-prop"><span>Generalized Offices:</span> <strong>{{ count($activeOffices) }}</strong></div>
                    <div class="m-inspector-prop"><span>Compile Brackets:</span> <strong>{{ count($subBrackets) }}</strong></div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
                    @if(!$isTypeLocked)
                        <button type="button" wire:click="openAssignOfficeModal" class="m-tool-btn primary" style="justify-content: center;">
                            <i class="fa-solid fa-building-circle-check"></i> Assign Cluster Offices
                        </button>
                        <button type="button" wire:click="openEditMainModal({{ $selectedMainObj->id }})" class="m-tool-btn" style="justify-content: center;">
                            <i class="fa-solid fa-pen m-ctx-icon"></i> Configure Cluster
                        </button>
                        <button type="button" wire:click="openAddSubModal" class="m-tool-btn" style="justify-content: center;">
                            <i class="fa-solid fa-plus m-ctx-icon"></i> Add Bracket
                        </button>
                        <button type="button" wire:click="confirmDelete('main', {{ $selectedMainObj->id }}, '{{ addslashes($selectedMainObj->bracket_name) }}')" class="m-tool-btn" style="justify-content: center; color: #ef4444; border-color: #fecaca;">
                            <i class="fa-solid fa-trash m-ctx-icon" style="color: #ef4444;"></i> Remove Cluster
                        </button>
                    @else
                        <div style="padding: 10px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; font-size: 11.5px; color: #92400e; text-align: center;">
                            <i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Record Type is locked. Right-click to unlock.
                        </div>
                    @endif
                </div>
            @elseif($selectedMainObj)
                {{-- Main Cluster Details (When cluster is clicked before sub-bracket is picked) --}}
                <div class="m-inspector-preview">
                    <i class="fa-solid fa-folder-closed m-inspector-icon"></i>
                    <h4 class="m-inspector-title">{{ $selectedMainObj->bracket_name }}</h4>
                    <span class="m-inspector-kind">Main (Cluster) Bracket</span>
                </div>
                <div style="margin-bottom: 16px;">
                    <div class="m-inspector-prop"><span>Scope:</span> <strong>Cluster Level</strong></div>
                    <div class="m-inspector-prop"><span>Records Type:</span> <strong>{{ $selectedTypeObj->shorted_type ?? 'CSPC' }}</strong></div>
                    <div class="m-inspector-prop"><span>Compile Brackets:</span> <strong>{{ count($subBrackets) }}</strong></div>
                    <div class="m-inspector-prop"><span>Total Series:</span> <strong>{{ DB::table('rdp_record_series')->where('bracket_id', $selectedMainObj->id)->count() }}</strong></div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
                    @if(!$isTypeLocked)
                        <button type="button" wire:click="openAddSubModal" class="m-tool-btn primary" style="justify-content: center;">
                            <i class="fa-solid fa-plus m-ctx-icon" style="color: #fff;"></i> Add Bracket
                        </button>
                        <button type="button" wire:click="openEditMainModal({{ $selectedMainObj->id }})" class="m-tool-btn" style="justify-content: center;">
                            <i class="fa-solid fa-pen m-ctx-icon"></i> Configure Cluster
                        </button>
                        <button type="button" wire:click="confirmDelete('main', {{ $selectedMainObj->id }}, '{{ addslashes($selectedMainObj->bracket_name) }}')" class="m-tool-btn" style="justify-content: center; color: #ef4444; border-color: #fecaca;">
                            <i class="fa-solid fa-trash m-ctx-icon" style="color: #ef4444;"></i> Remove Cluster
                        </button>
                    @else
                        <div style="padding: 10px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; font-size: 11.5px; color: #92400e; text-align: center;">
                            <i class="fa-solid fa-lock" style="margin-right: 4px;"></i> Record Type is locked. Right-click to unlock.
                        </div>
                    @endif
                </div>
            @elseif($selectedTypeObj)
                {{-- Records Type Details --}}
                <div class="m-inspector-preview">
                    <i class="fa-solid fa-database m-inspector-icon"></i>
                    <h4 class="m-inspector-title">{{ $selectedTypeObj->type_name }}</h4>
                    <span class="m-inspector-kind">Records Type ({{ $selectedTypeObj->shorted_type }})</span>
                </div>
                <div style="margin-bottom: 16px;">
                    <div class="m-inspector-prop"><span>Short Code:</span> <strong>{{ $selectedTypeObj->shorted_type }}</strong></div>
                    <div class="m-inspector-prop"><span>Status:</span> <strong>{{ $isTypeLocked ? 'Locked' : 'Unlocked' }}</strong></div>
                    <div class="m-inspector-prop"><span>Cluster Brackets:</span> <strong>{{ count($mainBrackets) }}</strong></div>
                    <div class="m-inspector-prop"><span>Total Series:</span> <strong>{{ DB::table('rdp_record_series')->where('series_type', $selectedTypeObj->id)->count() }}</strong></div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-top: auto;">
                    @if(!$isTypeLocked)
                        <button type="button" wire:click="openAddMainModal" class="m-tool-btn primary" style="justify-content: center;">
                            <i class="fa-solid fa-plus m-ctx-icon" style="color: #fff;"></i> Add Cluster
                        </button>
                        <button type="button" wire:click="openEditTypeModal({{ $selectedTypeObj->id }})" class="m-tool-btn" style="justify-content: center;">
                            <i class="fa-solid fa-pen m-ctx-icon"></i> Configure Type
                        </button>
                    @endif
                    <button type="button" wire:click="toggleLockRecordType({{ $selectedTypeObj->id }})" class="m-tool-btn" style="justify-content: center;">
                        <i class="fa-solid {{ $isTypeLocked ? 'fa-lock-open' : 'fa-lock' }} m-ctx-icon"></i>
                        <span>{{ $isTypeLocked ? 'Unlock Record Type' : 'Lock Record Type' }}</span>
                    </button>
                </div>
            @else
                <div style="padding: 24px; text-align: center; color: #94a3b8; font-size: 13px;">
                    <i class="fa-regular fa-folder-open m-ctx-icon" style="font-size: 32px; margin-bottom: 12px; display: block;"></i>
                    Select an item in any column to view properties and actions.
                </div>
            @endif
        </div>
    </div>

    {{-- Bottom Breadcrumb / Path Bar --}}
    <div class="miller-path-bar">
        <div style="display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-terminal m-ctx-icon" style="font-size: 12px;"></i>
            <span>Path:</span>
            @if($selectedTypeObj)
                <span class="miller-path-tag">[{{ $selectedTypeObj->shorted_type }}]</span>
                @if($isTypeLocked)
                    <span style="font-size: 10px; font-weight: 700; background: #e2e8f0; color: #475569; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fa-solid fa-lock" style="font-size: 9px; color: currentColor;"></i> Locked
                    </span>
                @endif
                @if($selectedMainObj)
                    <span>/</span>
                    <span class="miller-path-tag">{{ $selectedMainObj->bracket_name }}</span>
                @endif
                @if($selectedSubBracketId)
                    <span>/</span>
                    <span class="miller-path-tag" style="color: {{ $selectedSubBracketId === 'generalized' ? '#ca8a04' : '#2563eb' }};">
                        {{ $selectedSubBracketId === 'generalized' ? '[Generalized for Cluster]' : ($selectedSubObj->sub_name ?? '—') }}
                    </span>
                @endif
                @if($selectedOfficeCode)
                    <span>/</span>
                    <span class="miller-path-tag" style="color: #059669;">{{ $selectedOfficeCode }}</span>
                @endif
                <button type="button" wire:click="clearPath" title="Clear path selection" style="background: none; border: none; color: #94a3b8; cursor: pointer; padding: 2px 6px; font-size: 12px; margin-left: 4px;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </button>
            @else
                <span style="color: #94a3b8; font-style: italic;">No path selected</span>
            @endif
        </div>
        <div style="font-size: 11.5px; color: #64748b; display: flex; align-items: center; gap: 6px;">
            <i class="fa-regular fa-hand-pointer m-ctx-icon"></i> <strong>Right-click</strong> in any column for actions
        </div>
    </div>

    {{-- ========================================================
         DYNAMIC FLOATING RIGHT-CLICK CONTEXT MODAL (POPS OUT ON RIGHT CLICK)
         ======================================================== --}}
    <div x-cloak
         x-show="ctxOpen"
         x-ref="ctxFloating"
         class="m-floating-popout"
         :style="'left:' + ctxX + 'px; top:' + ctxY + 'px;'"
         @click.outside="closeCtx()"
         @keydown.escape.window="closeCtx()">

        {{-- 1. RIGHT CLICK ON RECORD TYPE (COLUMN 1) --}}
        <template x-if="ctxZone === 'type'">
            <div>
                <div class="m-ctx-header">Record Type</div>
                
                <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.openAddTypeModal()">
                    <i class="fa-solid fa-plus m-ctx-icon"></i>
                    <span>Add Record Type</span>
                </button>

                @if($selectedTypeObj)
                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.openEditTypeModal({{ $selectedTypeObj->id }})">
                        <i class="fa-solid fa-gear m-ctx-icon"></i>
                        <span>Configure Record Type</span>
                    </button>

                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.toggleLockRecordType({{ $selectedTypeObj->id }})">
                        <i class="fa-solid {{ $isTypeLocked ? 'fa-lock-open' : 'fa-lock' }} m-ctx-icon"></i>
                        <span>{{ $isTypeLocked ? 'Unlock Record Type' : 'Lock Record Type' }}</span>
                    </button>

                    <button type="button" class="m-ctx-item danger" @click="closeCtx(); $wire.confirmDelete('type', {{ $selectedTypeObj->id }}, '{{ addslashes($selectedTypeObj->type_name) }}')">
                        <i class="fa-solid fa-trash-can m-ctx-icon"></i>
                        <span>Remove Record Type</span>
                    </button>
                @endif

                <div class="m-ctx-divider"></div>

                <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.$refresh()">
                    <i class="fa-solid fa-arrows-rotate m-ctx-icon"></i>
                    <span>Refresh View</span>
                </button>
            </div>
        </template>

        {{-- 2. RIGHT CLICK IN MAIN CLUSTER BRACKET (COLUMN 2) --}}
        <template x-if="ctxZone === 'main'">
            <div>
                <div class="m-ctx-header">Cluster Bracket</div>

                @if($isTypeLocked)
                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.toggleLockRecordType({{ $selectedTypeObj?->id }})">
                        <i class="fa-solid fa-lock-open m-ctx-icon"></i>
                        <span>Unlock Record Type</span>
                    </button>
                @else
                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.openAddMainModal()">
                        <i class="fa-solid fa-plus m-ctx-icon"></i>
                        <span>Add Cluster</span>
                    </button>

                    @if($selectedMainObj)
                        <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.openEditMainModal({{ $selectedMainObj->id }})">
                            <i class="fa-solid fa-gear m-ctx-icon"></i>
                            <span>Configure Cluster</span>
                        </button>

                        <button type="button" class="m-ctx-item danger" @click="closeCtx(); $wire.confirmDelete('main', {{ $selectedMainObj->id }}, '{{ addslashes($selectedMainObj->bracket_name) }}')">
                            <i class="fa-solid fa-trash-can m-ctx-icon"></i>
                            <span>Remove Cluster</span>
                        </button>
                    @endif
                @endif

                <div class="m-ctx-divider"></div>

                <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.$refresh()">
                    <i class="fa-solid fa-arrows-rotate m-ctx-icon"></i>
                    <span>Refresh View</span>
                </button>
            </div>
        </template>

        {{-- 3. RIGHT CLICK IN COMPILE BRACKET (COLUMN 3) --}}
        <template x-if="ctxZone === 'sub'">
            <div>
                <div class="m-ctx-header">Compile Bracket</div>

                @if($isTypeLocked)
                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.toggleLockRecordType({{ $selectedTypeObj?->id }})">
                        <i class="fa-solid fa-lock-open m-ctx-icon"></i>
                        <span>Unlock Record Type</span>
                    </button>
                @else
                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.openAddSubModal()">
                        <i class="fa-solid fa-plus m-ctx-icon"></i>
                        <span>Add Bracket</span>
                    </button>

                    @if($selectedSubObj)
                        <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.openEditSubModal({{ $selectedSubObj->id }})">
                            <i class="fa-solid fa-gear m-ctx-icon"></i>
                            <span>Configure Bracket</span>
                        </button>

                        <button type="button" class="m-ctx-item danger" @click="closeCtx(); $wire.confirmDelete('sub', {{ $selectedSubObj->id }}, '{{ addslashes($selectedSubObj->sub_name) }}')">
                            <i class="fa-solid fa-trash-can m-ctx-icon"></i>
                            <span>Remove Bracket</span>
                        </button>
                    @endif
                @endif

                <div class="m-ctx-divider"></div>

                <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.$refresh()">
                    <i class="fa-solid fa-arrows-rotate m-ctx-icon"></i>
                    <span>Refresh View</span>
                </button>
            </div>
        </template>

        {{-- 4. RIGHT CLICK IN OFFICE PART (COLUMN 4) --}}
        <template x-if="ctxZone === 'office'">
            <div>
                <div class="m-ctx-header">Office</div>

                @if($isTypeLocked)
                    <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.toggleLockRecordType({{ $selectedTypeObj?->id }})">
                        <i class="fa-solid fa-lock-open m-ctx-icon"></i>
                        <span>Unlock Record Type</span>
                    </button>
                @else
                    <button type="button" class="m-ctx-item danger" @click="closeCtx(); if (ctxItemId) { $wire.removeOfficeMapping(ctxItemId); } else { $wire.removeFirstOffice(); }">
                        <i class="fa-solid fa-trash-can m-ctx-icon"></i>
                        <span>Remove Office</span>
                    </button>
                @endif

                <div class="m-ctx-divider"></div>

                <button type="button" class="m-ctx-item" @click="closeCtx(); $wire.$refresh()">
                    <i class="fa-solid fa-arrows-rotate m-ctx-icon"></i>
                    <span>Refresh View</span>
                </button>
            </div>
        </template>
    </div>

    {{-- MODAL 1: ADD / EDIT RECORDS TYPE --}}
    @if($showTypeModal)
        <div class="m-modal-overlay">
            <div class="m-modal-dialog">
                <div class="m-modal-header">
                    <span><i class="fa-solid fa-box-archive m-ctx-icon" style="margin-right: 6px;"></i> {{ $editingTypeId ? 'Configure Record Type' : 'Add Record Type' }}</span>
                    <button type="button" wire:click="$set('showTypeModal', false)" style="background:none; border:none; cursor:pointer; font-size:18px;">&times;</button>
                </div>
                <div class="m-modal-body">
                    <div style="margin-bottom: 14px;">
                        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:5px;">Type Name *</label>
                        <input type="text" wire:model="typeName" placeholder="e.g. Camarines Sur Polytechnic Colleges" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom: 14px;">
                        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:5px;">Short Identifier Code *</label>
                        <input type="text" wire:model="typeShortCode" placeholder="e.g. CSPC NAP" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                    </div>
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
                        <input type="checkbox" wire:model="typeActive"> Active
                    </label>
                </div>
                <div class="m-modal-footer">
                    <button type="button" class="m-tool-btn" wire:click="$set('showTypeModal', false)">Cancel</button>
                    <button type="button" class="m-tool-btn primary" wire:click="saveType">Save Type</button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 2: ADD / EDIT MAIN BRACKET --}}
    @if($showMainModal)
        <div class="m-modal-overlay">
            <div class="m-modal-dialog">
                <div class="m-modal-header">
                    <span><i class="fa-solid fa-layer-group m-ctx-icon" style="margin-right: 6px;"></i> {{ $editingMainId ? 'Configure Cluster' : 'Add Cluster' }}</span>
                    <button type="button" wire:click="$set('showMainModal', false)" style="background:none; border:none; cursor:pointer; font-size:18px;">&times;</button>
                </div>
                <div class="m-modal-body">
                    <div style="margin-bottom: 14px;">
                        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:5px;">Belongs to Records Type *</label>
                        <select wire:model="mainSeriesTypeId" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px;">
                            @foreach($types as $t)
                                <option value="{{ $t->id }}">{{ $t->type_name }} ({{ $t->shorted_type }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="margin-bottom: 14px;">
                        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:5px;">Cluster Bracket Name *</label>
                        <input type="text" wire:model="mainName" placeholder="e.g. OFFICE OF THE PRESIDENT" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                    </div>
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
                        <input type="checkbox" wire:model="mainActive"> Active in system
                    </label>
                </div>
                <div class="m-modal-footer">
                    <button type="button" class="m-tool-btn" wire:click="$set('showMainModal', false)">Cancel</button>
                    <button type="button" class="m-tool-btn primary" wire:click="saveMainBracket">Save Cluster</button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 3: ADD / EDIT COMPILE BRACKET (SUB-BRACKET) --}}
    @if($showSubModal)
        <div class="m-modal-overlay">
            <div class="m-modal-dialog">
                <div class="m-modal-header">
                    <span><i class="fa-solid fa-diagram-project m-ctx-icon" style="margin-right: 6px;"></i> {{ $editingSubId ? 'Configure Bracket' : 'Add Bracket' }}</span>
                    <button type="button" wire:click="$set('showSubModal', false)" style="background:none; border:none; cursor:pointer; font-size:18px;">&times;</button>
                </div>
                <div class="m-modal-body">
                    <div style="margin-bottom: 14px;">
                        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:5px;">Parent Cluster Bracket</label>
                        <input type="text" value="{{ $selectedMainObj->bracket_name ?? '' }}" readonly style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f1f5f9; box-sizing:border-box;">
                    </div>
                    <div style="margin-bottom: 14px;">
                        <label style="display:block; font-size:12px; font-weight:700; margin-bottom:5px;">Bracket Name *</label>
                        <input type="text" wire:model="subName" placeholder="e.g. REGISTRAR, HUMAN RESOURCE MANAGEMENT" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                    </div>
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
                        <input type="checkbox" wire:model="subActive"> Active in system
                    </label>
                </div>
                <div class="m-modal-footer">
                    <button type="button" class="m-tool-btn" wire:click="$set('showSubModal', false)">Cancel</button>
                    <button type="button" class="m-tool-btn primary" wire:click="saveSubBracket">Save Bracket</button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 4: ASSIGN OFFICES MODAL --}}
    @if($showAssignOfficeModal)
        <div class="m-modal-overlay">
            <div class="m-modal-dialog" style="max-width: 640px;">
                <div class="m-modal-header">
                    <span><i class="fa-solid fa-building-circle-check m-ctx-icon" style="margin-right: 6px;"></i> Assign Offices: {{ $assignModalTargetLabel }}</span>
                    <button type="button" wire:click="$set('showAssignOfficeModal', false)" style="background:none; border:none; cursor:pointer; font-size:18px;">&times;</button>
                </div>
                <div class="m-modal-body">
                    <p style="font-size: 12.5px; color: #64748b; margin-top: 0; margin-bottom: 12px;">
                        Check all offices that should automatically route under <strong>{{ $assignModalTargetLabel }}</strong> during RDP disposition and approval:
                    </p>

                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; max-height: 280px; overflow-y: auto; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fafafa;">
                        @foreach($allOffices as $off)
                            <label style="display: flex; align-items: center; gap: 7px; padding: 6px 8px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; cursor: pointer;"
                                   wire:click.prevent="toggleOfficeSelection('{{ $off->office_code }}')">
                                <input type="checkbox" {{ in_array($off->office_code, $quickSelectedOffices) ? 'checked' : '' }} readonly>
                                <span><strong>{{ $off->office_code }}</strong> - {{ Str::limit($off->office_name, 18) }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div style="margin-top: 10px; font-size: 12px; color: #475569; display: flex; justify-content: space-between;">
                        <span>Selected: <strong>{{ count($quickSelectedOffices) }}</strong> offices</span>
                        <a href="javascript:void(0)" wire:click="$set('quickSelectedOffices', [])" style="color: #ef4444; text-decoration: none;">Clear all</a>
                    </div>
                </div>
                <div class="m-modal-footer">
                    <button type="button" class="m-tool-btn" wire:click="$set('showAssignOfficeModal', false)">Cancel</button>
                    <button type="button" class="m-tool-btn primary" wire:click="saveOfficeAssignments">Apply Assignments</button>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL 5: DELETE / REMOVE CONFIRMATION --}}
    @if($showDeleteModal)
        <div class="m-modal-overlay">
            <div class="m-modal-dialog" style="max-width: 440px;">
                <div class="m-modal-header" style="background: #fef2f2; color: #b91c1c;">
                    <span><i class="fa-solid fa-triangle-exclamation m-ctx-icon" style="color: #b91c1c; margin-right: 6px;"></i> Confirm Removal</span>
                    <button type="button" wire:click="$set('showDeleteModal', false)" style="background:none; border:none; cursor:pointer; font-size:18px;">&times;</button>
                </div>
                <div class="m-modal-body">
                    <p style="font-size: 13.5px; color: #334155; margin: 0 0 10px 0;">
                        Are you sure you want to remove <strong>{{ $deleteTargetName }}</strong>?
                    </p>
                </div>
                <div class="m-modal-footer">
                    <button type="button" class="m-tool-btn" wire:click="$set('showDeleteModal', false)">Cancel</button>
                    <button type="button" class="m-tool-btn primary" wire:click="executeDelete" style="background: #dc2626; border-color: #dc2626;">Remove</button>
                </div>
            </div>
        </div>
    @endif
</div>

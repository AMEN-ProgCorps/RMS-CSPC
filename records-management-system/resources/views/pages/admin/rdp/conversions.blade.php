<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new #[Layout('layouts.admin')] #[Title('Admin Console - Volume Conversions')] class extends Component {
    public string $search = '';

    // Unit Addition Form
    public string $newUnitName = '';

    // Volume Unit Editing State
    public bool $showUnitsModal = false;
    public ?int $editingUnitId = null;
    public string $editingUnitName = '';

    // Conversion Form Fields for Add
    public int $amountStandard = 100;
    public mixed $valueStandard = null;
    public int $amountConverted = 1;
    public mixed $valueConverted = null;

    // Edit State for Conversion Rules
    public ?int $editingId = null;
    public int $editAmountStandard = 100;
    public mixed $editValueStandard = null;
    public int $editAmountConverted = 1;
    public mixed $editValueConverted = null;

    public string $successMessage = '';
    public string $errorMessage = '';

    public function mount(): void
    {
        $perms = auth()->user()?->permissions;
        if (!$perms || (!$perms->is_sadm && !$perms->can_access_rdp_admin)) {
            $this->redirect(route('portal'));
            return;
        }
    }

    public function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    public function toggleUnitsModal(): void
    {
        $this->showUnitsModal = !$this->showUnitsModal;
        $this->editingUnitId = null;
    }

    // --- Volume Unit Management Handlers ---
    public function addVolumeUnit(): void
    {
        $this->clearMessages();
        $name = trim($this->newUnitName);

        if (empty($name)) {
            $this->errorMessage = 'Volume unit name is required.';
            return;
        }

        try {
            $exists = DB::table('rdp_volume_value')->whereRaw('LOWER(value_standard) = ?', [strtolower($name)])->exists();
            if ($exists) {
                $this->errorMessage = "Volume unit '{$name}' already exists.";
                return;
            }

            DB::table('rdp_volume_value')->insert([
                'value_standard'     => $name,
                'cur_used_standard'  => false,
                'cur_used_converted' => false,
                'is_active'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $this->newUnitName = '';
            $this->successMessage = "Volume unit '{$name}' created successfully!";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to create volume unit: ' . $e->getMessage();
        }
    }

    public function startEditUnit(int $volumeId, string $name): void
    {
        $this->clearMessages();
        $this->editingUnitId = $volumeId;
        $this->editingUnitName = $name;
    }

    public function cancelEditUnit(): void
    {
        $this->editingUnitId = null;
    }

    public function updateVolumeUnit(): void
    {
        $this->clearMessages();
        $name = trim($this->editingUnitName);

        if (empty($name)) {
            $this->errorMessage = 'Volume unit name cannot be empty.';
            return;
        }

        try {
            $exists = DB::table('rdp_volume_value')
                ->whereRaw('LOWER(value_standard) = ?', [strtolower($name)])
                ->where('volume_id', '!=', $this->editingUnitId)
                ->exists();

            if ($exists) {
                $this->errorMessage = "Another volume unit named '{$name}' already exists.";
                return;
            }

            DB::table('rdp_volume_value')
                ->where('volume_id', $this->editingUnitId)
                ->update([
                    'value_standard' => $name,
                    'updated_at'     => now(),
                ]);

            DB::table('admin_logs')->insert([
                'changes'      => "Renamed RDP Volume Unit #{$this->editingUnitId} to '{$name}'",
                'admin_id'     => auth()->id(),
                'what_system'  => 2, // RDP
                'when_changes' => now(),
            ]);

            $this->editingUnitId = null;
            $this->successMessage = 'Volume unit updated successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to update volume unit: ' . $e->getMessage();
        }
    }

    public function deleteVolumeUnit(int $volumeId): void
    {
        $this->clearMessages();
        try {
            $unit = DB::table('rdp_volume_value')->where('volume_id', $volumeId)->first();
            if (!$unit) return;

            if ($unit->cur_used_standard || $unit->cur_used_converted) {
                $this->errorMessage = "Cannot delete volume unit '{$unit->value_standard}' because it is currently used in an active conversion rule.";
                return;
            }

            DB::table('rdp_volume_value')->where('volume_id', $volumeId)->delete();

            DB::table('admin_logs')->insert([
                'changes'      => "Deleted RDP Volume Unit '{$unit->value_standard}'",
                'admin_id'     => auth()->id(),
                'what_system'  => 2, // RDP
                'when_changes' => now(),
            ]);

            $this->successMessage = 'Volume unit deleted successfully!';
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete volume unit: ' . $e->getMessage();
        }
    }

    // --- Conversion Rules Handlers ---
    public function addRule(): void
    {
        $this->clearMessages();

        $stdId  = $this->valueStandard !== null && $this->valueStandard !== '' ? (int)$this->valueStandard : null;
        $convId = $this->valueConverted !== null && $this->valueConverted !== '' ? (int)$this->valueConverted : null;

        if ($this->amountStandard <= 0 || !$stdId) {
            $this->errorMessage = 'Standard amount and unit (e.g. 100 Pages) are required.';
            return;
        }

        if ($this->amountConverted <= 0 || !$convId) {
            $this->errorMessage = 'Converted amount and unit (e.g. 1 Folder) are required.';
            return;
        }

        if ($stdId === $convId) {
            $this->errorMessage = 'Standard unit and converted unit cannot be identical.';
            return;
        }

        $stdUnit = DB::table('rdp_volume_value')->where('volume_id', $stdId)->first();
        if ($stdUnit && $stdUnit->cur_used_standard) {
            $this->errorMessage = "The unit '{$stdUnit->value_standard}' is already set as a standard unit in an active conversion rule. Each standard unit can only have one primary conversion rule.";
            return;
        }

        try {
            DB::beginTransaction();

            $ruleId = DB::table('rdp_volume_conversion')->insertGetId([
                'amount_standard' => $this->amountStandard,
                'value_standard'  => $stdId,
                'amount_converted'=> $this->amountConverted,
                'value_converted' => $convId,
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            DB::table('rdp_volume_value')->where('volume_id', $stdId)->update(['cur_used_standard' => true]);
            DB::table('rdp_volume_value')->where('volume_id', $convId)->update(['cur_used_converted' => true]);

            $convUnit = DB::table('rdp_volume_value')->where('volume_id', $convId)->first();
            $logMsg = "Added RDP Volume Conversion rule: {$this->amountStandard} {$stdUnit->value_standard} = {$this->amountConverted} {$convUnit->value_standard}";

            DB::table('admin_logs')->insert([
                'changes'      => $logMsg,
                'admin_id'     => auth()->id(),
                'what_system'  => 2, // RDP
                'when_changes' => now(),
            ]);

            DB::commit();

            $this->valueStandard = null;
            $this->valueConverted = null;
            $this->amountStandard = 100;
            $this->amountConverted = 1;
            $this->successMessage = "Conversion rule added successfully!";
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to add conversion rule: ' . $e->getMessage();
        }
    }

    public function startEdit(int $ruleId): void
    {
        $this->clearMessages();
        $rule = DB::table('rdp_volume_conversion')->where('id', $ruleId)->first();
        if ($rule) {
            $this->editingId           = $rule->id;
            $this->editAmountStandard  = $rule->amount_standard ?? 100;
            $this->editValueStandard   = $rule->value_standard;
            $this->editAmountConverted = $rule->amount_converted ?? 1;
            $this->editValueConverted  = $rule->value_converted;
        }
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function updateRule(): void
    {
        $this->clearMessages();

        $stdId  = $this->editValueStandard !== null && $this->editValueStandard !== '' ? (int)$this->editValueStandard : null;
        $convId = $this->editValueConverted !== null && $this->editValueConverted !== '' ? (int)$this->editValueConverted : null;

        if ($this->editAmountStandard <= 0 || !$stdId) {
            $this->errorMessage = 'Standard amount and unit are required.';
            return;
        }

        if ($this->editAmountConverted <= 0 || !$convId) {
            $this->errorMessage = 'Converted amount and unit are required.';
            return;
        }

        if ($stdId === $convId) {
            $this->errorMessage = 'Standard unit and converted unit cannot be identical.';
            return;
        }

        try {
            DB::beginTransaction();

            $oldRule = DB::table('rdp_volume_conversion')->where('id', $this->editingId)->first();

            DB::table('rdp_volume_conversion')
                ->where('id', $this->editingId)
                ->update([
                    'amount_standard'  => $this->editAmountStandard,
                    'value_standard'   => $stdId,
                    'amount_converted' => $this->editAmountConverted,
                    'value_converted'  => $convId,
                    'updated_at'       => now(),
                ]);

            DB::table('admin_logs')->insert([
                'changes'      => "Updated RDP Volume Conversion rule #{$this->editingId}",
                'admin_id'     => auth()->id(),
                'what_system'  => 2, // RDP
                'when_changes' => now(),
            ]);

            DB::commit();

            $this->editingId = null;
            $this->successMessage = 'Conversion rule updated successfully!';
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to update rule: ' . $e->getMessage();
        }
    }

    public function toggleActive(int $id): void
    {
        $this->clearMessages();
        try {
            DB::beginTransaction();

            $rule = DB::table('rdp_volume_conversion')->where('id', $id)->first();
            if ($rule) {
                $newStatus = !$rule->is_active;

                // Check if activating would conflict with another active rule on the same standard unit
                if ($newStatus) {
                    $stdConflict = DB::table('rdp_volume_conversion')
                        ->where('value_standard', $rule->value_standard)
                        ->where('is_active', true)
                        ->where('id', '!=', $id)
                        ->exists();

                    if ($stdConflict) {
                        $stdUnit = DB::table('rdp_volume_value')->where('volume_id', $rule->value_standard)->first();
                        $this->errorMessage = "Cannot activate rule: Unit '{$stdUnit?->value_standard}' is already set as standard in another active rule.";
                        DB::rollBack();
                        return;
                    }
                }

                DB::table('rdp_volume_conversion')->where('id', $id)->update([
                    'is_active'  => $newStatus,
                    'updated_at' => now(),
                ]);

                $statusText = $newStatus ? 'activated' : 'deactivated';
                DB::table('admin_logs')->insert([
                    'changes'      => "Toggled RDP Volume Conversion rule #{$id} to {$statusText}",
                    'admin_id'     => auth()->id(),
                    'what_system'  => 2, // RDP
                    'when_changes' => now(),
                ]);

                DB::commit();
                $this->successMessage = "Rule {$statusText} successfully!";
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to toggle status: ' . $e->getMessage();
        }
    }

    public function deleteRule(int $id): void
    {
        $this->clearMessages();
        try {
            DB::beginTransaction();

            $rule = DB::table('rdp_volume_conversion')->where('id', $id)->first();
            if ($rule) {
                DB::table('rdp_volume_conversion')->where('id', $id)->delete();

                DB::table('admin_logs')->insert([
                    'changes'      => "Deleted RDP Volume Conversion rule #{$id}",
                    'admin_id'     => auth()->id(),
                    'what_system'  => 2, // RDP
                    'when_changes' => now(),
                ]);
            }

            DB::commit();
            $this->successMessage = 'Conversion rule deleted successfully!';
        } catch (\Exception $e) {
            DB::rollBack();
            $this->errorMessage = 'Failed to delete rule: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        // Automatically synchronize cur_used_standard and cur_used_converted flags based ONLY on currently active rules
        $activeStdIds = DB::table('rdp_volume_conversion')->where('is_active', true)->pluck('value_standard')->toArray();
        $activeConvIds = DB::table('rdp_volume_conversion')->where('is_active', true)->pluck('value_converted')->toArray();

        DB::table('rdp_volume_value')->update(['cur_used_standard' => false, 'cur_used_converted' => false]);
        if (!empty($activeStdIds)) {
            DB::table('rdp_volume_value')->whereIn('volume_id', $activeStdIds)->update(['cur_used_standard' => true]);
        }
        if (!empty($activeConvIds)) {
            DB::table('rdp_volume_value')->whereIn('volume_id', $activeConvIds)->update(['cur_used_converted' => true]);
        }

        $query = DB::table('rdp_volume_conversion')
            ->join('rdp_volume_value as std', 'rdp_volume_conversion.value_standard', '=', 'std.volume_id')
            ->join('rdp_volume_value as conv', 'rdp_volume_conversion.value_converted', '=', 'conv.volume_id')
            ->select([
                'rdp_volume_conversion.*',
                'std.value_standard as std_name',
                'conv.value_standard as conv_name',
            ]);

        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('std.value_standard', 'like', '%' . $this->search . '%')
                  ->orWhere('conv.value_standard', 'like', '%' . $this->search . '%');
            });
        }

        $allUnits = DB::table('rdp_volume_value')
            ->leftJoin('rdp_volume_conversion', function($join) {
                $join->on('rdp_volume_value.volume_id', '=', 'rdp_volume_conversion.value_standard')
                     ->where('rdp_volume_conversion.is_active', '=', true);
            })
            ->leftJoin('rdp_volume_value as conv_target', 'rdp_volume_conversion.value_converted', '=', 'conv_target.volume_id')
            ->select([
                'rdp_volume_value.*',
                'conv_target.value_standard as converts_to_name',
            ])
            ->orderBy('rdp_volume_value.value_standard', 'asc')
            ->get();

        $availableStdUnits = DB::table('rdp_volume_value')->where('is_active', true)->where('cur_used_standard', false)->orderBy('value_standard', 'asc')->get();

        // Auto-select initial defaults if not set
        if ($this->valueStandard === null && count($availableStdUnits) > 0) {
            $this->valueStandard = $availableStdUnits->first()->volume_id;
        }

        if ($this->valueConverted === null && count($allUnits) > 0) {
            foreach ($allUnits as $u) {
                if ($u->volume_id != $this->valueStandard) {
                    $this->valueConverted = $u->volume_id;
                    break;
                }
            }
        }

        return [
            'conversions'       => $query->orderBy('rdp_volume_conversion.is_active', 'desc')->get(),
            'allUnits'          => $allUnits,
            'availableStdUnits' => $availableStdUnits,
        ];
    }
};
?>

@push('styles')
    @vite(['resources/css/admin/console.css', 'resources/css/admin/activity_logs.css'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .rdp-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
        }
        .rdp-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 0;
            margin-bottom: 16px;
        }
        .rdp-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 5px;
        }
        .rdp-input, .rdp-select {
            padding: 9px 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            width: 100%;
            font-size: 13.5px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .rdp-input:focus, .rdp-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .rdp-btn-primary {
            padding: 9px 22px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .rdp-btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
        .rdp-btn-manage {
            padding: 10px 18px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-weight: 700;
            color: #2563eb;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }
        .rdp-btn-manage:hover {
            background: #f8fafc;
            border-color: #2563eb;
        }
        .rdp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
        }
        .rdp-table th {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            color: #475569;
            padding: 12px 16px;
            font-weight: 700;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .rdp-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .rdp-table tr:hover td {
            background: #f8fafc;
        }
        .rdp-btn-action {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .rdp-btn-toggle {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .rdp-btn-toggle:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .rdp-btn-edit {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }
        .rdp-btn-edit:hover {
            background: #dbeafe;
        }
        .rdp-btn-delete {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }
        .rdp-btn-delete:hover {
            background: #fecaca;
        }

        /* Modal Styles */
        .rdp-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(5px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .rdp-modal-dialog {
            background: #ffffff;
            width: 100%;
            max-width: 680px;
            border-radius: 14px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .rdp-modal-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .rdp-modal-title {
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .rdp-modal-close {
            background: transparent;
            border: none;
            font-size: 20px;
            color: #64748b;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .rdp-modal-close:hover {
            color: #0f172a;
            background: #e2e8f0;
        }
        .rdp-modal-add-bar {
            padding: 16px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .rdp-modal-footer {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
        }

        /* Dark Mode Overrides */
        [data-theme="dark"] .rdp-card {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        }
        [data-theme="dark"] .rdp-card-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .rdp-label {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .rdp-input,
        [data-theme="dark"] .rdp-select {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #f8fafc !important;
        }
        [data-theme="dark"] .rdp-input:focus,
        [data-theme="dark"] .rdp-select:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important;
        }
        [data-theme="dark"] .rdp-btn-manage {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #60a5fa !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3) !important;
        }
        [data-theme="dark"] .rdp-btn-manage:hover {
            background: #2563eb !important;
            color: #ffffff !important;
            border-color: #2563eb !important;
        }
        [data-theme="dark"] .rdp-table th {
            background: #0f172a !important;
            color: #94a3b8 !important;
            border-bottom: 1px solid #1e293b !important;
        }
        [data-theme="dark"] .rdp-table td {
            color: #cbd5e1 !important;
            border-bottom: 1px solid #1e293b !important;
        }
        [data-theme="dark"] .rdp-table tr:hover td {
            background: #1a253c !important;
        }
        [data-theme="dark"] .rdp-btn-toggle {
            background: #0f172a !important;
            color: #cbd5e1 !important;
            border-color: #334155 !important;
        }
        [data-theme="dark"] .rdp-btn-toggle:hover {
            background: #1e293b !important;
            color: #f8fafc !important;
            border-color: #475569 !important;
        }
        [data-theme="dark"] .rdp-btn-edit {
            background: rgba(37, 99, 235, 0.15) !important;
            color: #60a5fa !important;
            border-color: rgba(37, 99, 235, 0.3) !important;
        }
        [data-theme="dark"] .rdp-btn-edit:hover {
            background: #2563eb !important;
            color: #ffffff !important;
        }
        [data-theme="dark"] .rdp-btn-delete {
            background: rgba(239, 68, 68, 0.15) !important;
            color: #f87171 !important;
            border-color: rgba(239, 68, 68, 0.3) !important;
        }
        [data-theme="dark"] .rdp-btn-delete:hover {
            background: #dc2626 !important;
            color: #ffffff !important;
        }
        [data-theme="dark"] .rdp-modal-dialog {
            background: #131c2e !important;
            border-color: #1e293b !important;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6) !important;
        }
        [data-theme="dark"] .rdp-modal-header,
        [data-theme="dark"] .rdp-modal-add-bar,
        [data-theme="dark"] .rdp-modal-footer {
            background: #0f172a !important;
            border-color: #1e293b !important;
        }
        [data-theme="dark"] .rdp-modal-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .rdp-modal-close {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .rdp-modal-close:hover {
            color: #f8fafc !important;
            background: #1e293b !important;
        }
        [data-theme="dark"] .rdp-header-title {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .rdp-header-sub {
            color: #94a3b8 !important;
        }
        [data-theme="dark"] .rdp-equals {
            color: #60a5fa !important;
        }
    </style>
@endpush

<div class="activity-logs-container">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 class="rdp-header-title" style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">Volume Conversion Settings</h1>
            <p class="rdp-header-sub" style="font-size: 14px; color: #64748b; margin: 4px 0 0 0;">Manage standard volume units and conversion rules for RDP records (e.g. 100 Pages = 1 Folder).</p>
        </div>
        <button type="button" class="rdp-btn-manage" wire:click="toggleUnitsModal">
            <i class="fa-solid fa-folder-open" style="color: #60a5fa;"></i>
            <span>Manage Volume Units ({{ count($allUnits) }})</span>
        </button>
    </div>

    @if($successMessage)
        <div style="padding: 12px 16px; background-color: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-check"></i>
            {{ $successMessage }}
        </div>
    @endif

    @if($errorMessage)
        <div style="padding: 12px 16px; background-color: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-circle-xmark"></i>
            {{ $errorMessage }}
        </div>
    @endif

    <!-- Add Conversion Rule Form Card -->
    <div class="rdp-card">
        <h3 class="rdp-card-title">Add New Conversion Rule</h3>
        <form wire:submit.prevent="addRule" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 130px;">
                <label class="rdp-label">Standard Amount:</label>
                <input type="number" min="1" wire:model.live="amountStandard" class="rdp-input" placeholder="100">
            </div>

            <div style="flex: 1.5; min-width: 180px;">
                <label class="rdp-label">Standard Unit:</label>
                <select wire:model.live="valueStandard" class="rdp-select">
                    @foreach($availableStdUnits as $unit)
                        <option value="{{ $unit->volume_id }}">{{ $unit->value_standard }}</option>
                    @endforeach
                </select>
            </div>

            <div class="rdp-equals" style="font-size: 20px; font-weight: 800; color: #64748b; padding-bottom: 8px;">=</div>

            <div style="flex: 1; min-width: 130px;">
                <label class="rdp-label">Converted Amount:</label>
                <input type="number" min="1" wire:model.live="amountConverted" class="rdp-input" placeholder="1">
            </div>

            <div style="flex: 1.5; min-width: 180px;">
                <label class="rdp-label">Converted Unit:</label>
                <select wire:model.live="valueConverted" class="rdp-select">
                    @foreach($allUnits as $unit)
                        <option value="{{ $unit->volume_id }}">{{ $unit->value_standard }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <button type="submit" class="rdp-btn-primary">
                    <i class="fa-solid fa-plus"></i> ADD RULE
                </button>
            </div>
        </form>
    </div>

    <!-- Conversion Rules List Table Card -->
    <div class="rdp-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <h3 class="rdp-card-title" style="margin: 0;">Active Conversion Rules</h3>
            <div style="position: relative; width: 240px;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px;"></i>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search conversions..." class="rdp-input" style="padding-left: 36px; height: 38px;">
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="rdp-table">
                <thead>
                    <tr>
                        <th style="padding: 12px 16px;">STANDARD UNIT</th>
                        <th style="padding: 12px 16px;">CONVERTS TO</th>
                        <th style="padding: 12px 16px;">STATUS</th>
                        <th style="padding: 12px 16px; text-align: right;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($conversions as $rule)
                        <tr wire:key="rule-{{ $rule->id }}">
                            @if($editingId === $rule->id)
                                <td style="padding: 12px 16px;" colspan="2">
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <input type="number" min="1" wire:model.live="editAmountStandard" class="rdp-input" style="width: 80px;">
                                        <select wire:model.live="editValueStandard" class="rdp-select" style="min-width: 140px;">
                                            @foreach($allUnits as $u)
                                                <option value="{{ $u->volume_id }}">{{ $u->value_standard }}</option>
                                            @endforeach
                                        </select>
                                        <span class="rdp-equals" style="font-weight: 800; font-size: 18px;">=</span>
                                        <input type="number" min="1" wire:model.live="editAmountConverted" class="rdp-input" style="width: 80px;">
                                        <select wire:model.live="editValueConverted" class="rdp-select" style="min-width: 140px;">
                                            @foreach($allUnits as $u)
                                                <option value="{{ $u->volume_id }}">{{ $u->value_standard }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span style="font-size: 12px; color: #94a3b8; font-style: italic;">Editing in progress...</span>
                                </td>
                                <td style="padding: 12px 16px; text-align: right;">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <button type="button" wire:click="updateRule" class="rdp-btn-action" style="background: #10b981; color: #ffffff; padding: 6px 14px;">SAVE</button>
                                        <button type="button" wire:click="cancelEdit" class="rdp-btn-action rdp-btn-toggle" style="padding: 6px 14px;">CANCEL</button>
                                    </div>
                                </td>
                            @else
                                <td style="padding: 12px 16px; font-weight: 700; color: #f8fafc;">
                                    <span style="color: #38bdf8; font-family: monospace; font-size: 14px;">{{ $rule->amount_standard }}</span> 
                                    {{ Str::plural($rule->std_name, $rule->amount_standard) }}
                                </td>
                                <td style="padding: 12px 16px; color: #60a5fa; font-weight: 600;">
                                    <span style="color: #38bdf8; font-family: monospace; font-size: 14px;">{{ $rule->amount_converted }}</span> 
                                    {{ Str::plural($rule->conv_name, $rule->amount_converted) }}
                                </td>
                                <td style="padding: 12px 16px;">
                                    @if($rule->is_active)
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 99px; font-size: 11px; font-weight: 700;">
                                            <i class="fa-solid fa-circle" style="font-size: 6px;"></i> ACTIVE
                                        </span>
                                    @else
                                        <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: #0f172a; color: #94a3b8; border: 1px solid #334155; border-radius: 99px; font-size: 11px; font-weight: 700;">
                                            <i class="fa-solid fa-circle" style="font-size: 6px;"></i> INACTIVE
                                        </span>
                                    @endif
                                </td>
                                <td style="padding: 12px 16px; text-align: right;">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <button type="button" wire:click="toggleActive({{ $rule->id }})" class="rdp-btn-action rdp-btn-toggle" title="{{ $rule->is_active ? 'Deactivate Rule' : 'Activate Rule' }}">
                                            <i class="fa-solid {{ $rule->is_active ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                                            {{ $rule->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                        <button type="button" wire:click="startEdit({{ $rule->id }})" class="rdp-btn-action rdp-btn-edit" title="Edit Rule">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </button>
                                        <button type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Are you sure you want to delete this conversion rule?" class="rdp-btn-action rdp-btn-delete" title="Delete Rule">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="padding: 36px; text-align: center; color: #94a3b8; font-style: italic;">
                                <i class="fa-solid fa-scale-balanced" style="font-size: 28px; display: block; margin-bottom: 8px; color: #cbd5e1;"></i>
                                No conversion rules found matching your search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- MANAGE VOLUME UNITS MODAL CONTAINER -->
    @if($showUnitsModal)
        <div class="rdp-modal-backdrop">
            <div class="rdp-modal-dialog">
                <div class="rdp-modal-header">
                    <h3 class="rdp-modal-title">
                        <i class="fa-solid fa-boxes-stacked" style="color: #60a5fa; margin-right: 6px;"></i>
                        Manage Volume Units
                    </h3>
                    <button type="button" wire:click="toggleUnitsModal" class="rdp-modal-close">&times;</button>
                </div>

                <!-- Add New Volume Unit Form Inside Modal -->
                <div class="rdp-modal-add-bar">
                    <form wire:submit.prevent="addVolumeUnit" style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" wire:model.live="newUnitName" class="rdp-input" placeholder="Enter new unit name (e.g. Box, Bundle, Envelope)" style="flex: 1;">
                        <button type="submit" class="rdp-btn-primary" style="white-space: nowrap;">
                            <i class="fa-solid fa-plus"></i> ADD UNIT
                        </button>
                    </form>
                </div>

                <div style="padding: 20px 24px; max-height: 420px; overflow-y: auto;">
                    <table class="rdp-table">
                        <thead>
                            <tr>
                                <th style="padding: 10px 12px; text-align: left;">UNIT NAME</th>
                                <th style="padding: 10px 12px; text-align: center;">STD USAGE</th>
                                <th style="padding: 10px 12px; text-align: center;">CONVERTS TO</th>
                                <th style="padding: 10px 12px; text-align: right;">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($allUnits as $unit)
                                <tr wire:key="unit-{{ $unit->volume_id }}">
                                    @if($editingUnitId === $unit->volume_id)
                                        <td style="padding: 10px 12px;" colspan="3">
                                            <div style="display: flex; gap: 6px;">
                                                <input type="text" wire:model.live="editingUnitName" class="rdp-input">
                                                <button type="button" wire:click="updateVolumeUnit" class="rdp-btn-action" style="background: #10b981; color: #fff; padding: 6px 12px;">SAVE</button>
                                                <button type="button" wire:click="cancelEditUnit" class="rdp-btn-action rdp-btn-toggle" style="padding: 6px 12px;">CANCEL</button>
                                            </div>
                                        </td>
                                    @else
                                        <td style="padding: 10px 12px; font-weight: 700; color: #f8fafc;">
                                            {{ $unit->value_standard }}
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center;">
                                            @if($unit->cur_used_standard)
                                                <span style="display: inline-block; padding: 3px 8px; background: rgba(37, 99, 235, 0.15); color: #60a5fa; border: 1px solid rgba(37, 99, 235, 0.3); border-radius: 6px; font-size: 11px; font-weight: 700;">IN USE</span>
                                            @else
                                                <span style="font-size: 11px; color: #64748b;">--</span>
                                            @endif
                                        </td>
                                        <td style="padding: 10px 12px; text-align: center;">
                                            @if($unit->converts_to_name)
                                                <span style="display: inline-block; padding: 3px 10px; background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 6px; font-size: 12px; font-weight: 700;">
                                                    {{ $unit->converts_to_name }}
                                                </span>
                                            @else
                                                <span style="font-size: 11px; color: #64748b;">--</span>
                                            @endif
                                        </td>
                                        <td style="padding: 10px 12px; text-align: right;">
                                            <div style="display: inline-flex; gap: 6px;">
                                                <button type="button" wire:click="startEditUnit({{ $unit->volume_id }}, '{{ addslashes($unit->value_standard) }}')" class="rdp-btn-action rdp-btn-edit">
                                                    <i class="fa-solid fa-pen"></i> Rename
                                                </button>
                                                <button type="button" wire:click="deleteVolumeUnit({{ $unit->volume_id }})" wire:confirm="Are you sure you want to delete unit '{{ $unit->value_standard }}'?" class="rdp-btn-action rdp-btn-delete">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="rdp-modal-footer">
                    <button type="button" wire:click="toggleUnitsModal" class="rdp-btn-action rdp-btn-toggle" style="padding: 8px 18px; font-size: 13px;">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>


<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Admin Console - Remark Key Triggers')] class extends Component {
    public string $newKeyWord = '';
    public bool $newIsActionBefore = false;

    public ?int $editingTriggerId = null;
    public string $editingKeyWord = '';
    public bool $editingIsActionBefore = false;

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

    private function clearMessages(): void
    {
        $this->successMessage = '';
        $this->errorMessage = '';
    }

    private function duplicateKeyWord(string $keyWord, ?int $ignoreId = null): bool
    {
        return DB::table('rdp_remark_key_triggers')
            ->whereRaw('LOWER(key_word) = ?', [mb_strtolower($keyWord)])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function addTrigger(): void
    {
        $this->clearMessages();
        $keyWord = trim($this->newKeyWord);

        if ($keyWord === '') {
            $this->errorMessage = 'A remark key trigger is required.';
            return;
        }

        if ($this->duplicateKeyWord($keyWord)) {
            $this->errorMessage = "The trigger '{$keyWord}' already exists.";
            return;
        }

        try {
            DB::table('rdp_remark_key_triggers')->insert([
                'key_word' => $keyWord,
                'is_action_before' => $this->newIsActionBefore,
                'created_at' => now(),
            ]);

            $this->newKeyWord = '';
            $this->newIsActionBefore = false;
            $this->successMessage = "Remark key trigger '{$keyWord}' added.";
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to add remark key trigger: ' . $e->getMessage();
        }
    }

    public function startEdit(int $triggerId): void
    {
        $this->clearMessages();
        $trigger = DB::table('rdp_remark_key_triggers')->where('id', $triggerId)->first();

        if (!$trigger) {
            $this->errorMessage = 'The selected remark key trigger could not be found.';
            return;
        }

        $this->editingTriggerId = (int) $trigger->id;
        $this->editingKeyWord = (string) $trigger->key_word;
        $this->editingIsActionBefore = (bool) $trigger->is_action_before;
    }

    public function cancelEdit(): void
    {
        $this->editingTriggerId = null;
        $this->editingKeyWord = '';
        $this->editingIsActionBefore = false;
    }

    public function updateTrigger(): void
    {
        $this->clearMessages();

        if ($this->editingTriggerId === null) {
            $this->errorMessage = 'Select a remark key trigger to edit.';
            return;
        }

        $keyWord = trim($this->editingKeyWord);
        if ($keyWord === '') {
            $this->errorMessage = 'A remark key trigger is required.';
            return;
        }

        $exists = DB::table('rdp_remark_key_triggers')->where('id', $this->editingTriggerId)->exists();
        if (!$exists) {
            $this->errorMessage = 'The selected remark key trigger no longer exists.';
            $this->cancelEdit();
            return;
        }

        if ($this->duplicateKeyWord($keyWord, $this->editingTriggerId)) {
            $this->errorMessage = "The trigger '{$keyWord}' already exists.";
            return;
        }

        try {
            DB::table('rdp_remark_key_triggers')
                ->where('id', $this->editingTriggerId)
                ->update([
                    'key_word' => $keyWord,
                    'is_action_before' => $this->editingIsActionBefore,
                ]);

            $this->successMessage = "Remark key trigger '{$keyWord}' updated.";
            $this->cancelEdit();
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to update remark key trigger: ' . $e->getMessage();
        }
    }

    public function deleteTrigger(int $triggerId): void
    {
        $this->clearMessages();
        $trigger = DB::table('rdp_remark_key_triggers')->where('id', $triggerId)->first();

        if (!$trigger) {
            $this->errorMessage = 'The selected remark key trigger could not be found.';
            return;
        }

        if (DB::table('rdp_remarks_logic')->where('key_word_id', $triggerId)->exists()) {
            $this->errorMessage = "Cannot delete '{$trigger->key_word}' because it is used by one or more remarks.";
            return;
        }

        try {
            DB::table('rdp_remark_key_triggers')->where('id', $triggerId)->delete();
            $this->successMessage = "Remark key trigger '{$trigger->key_word}' deleted.";

            if ($this->editingTriggerId === $triggerId) {
                $this->cancelEdit();
            }
        } catch (\Exception $e) {
            $this->errorMessage = 'Failed to delete remark key trigger: ' . $e->getMessage();
        }
    }

    public function with(): array
    {
        $triggers = DB::table('rdp_remark_key_triggers')
            ->leftJoin('rdp_remarks_logic', 'rdp_remark_key_triggers.id', '=', 'rdp_remarks_logic.key_word_id')
            ->select([
                'rdp_remark_key_triggers.id',
                'rdp_remark_key_triggers.key_word',
                'rdp_remark_key_triggers.is_action_before',
                DB::raw('COUNT(rdp_remarks_logic.id) as usage_count'),
            ])
            ->groupBy(
                'rdp_remark_key_triggers.id',
                'rdp_remark_key_triggers.key_word',
                'rdp_remark_key_triggers.is_action_before'
            )
            ->orderBy('rdp_remark_key_triggers.key_word')
            ->get();

        return ['triggers' => $triggers];
    }
};
?>

<div style="max-width: 1100px; margin: 0 auto; padding: 24px;">
    <div style="margin-bottom: 22px;">
        <h1 style="margin: 0 0 6px; color: #0f172a; font-size: 24px; font-weight: 800;">Remark Key Triggers</h1>
        <p style="margin: 0; color: #64748b; font-size: 14px;">
            Manage words used to interpret remarks and choose whether the remark-specific retention applies before or after the action.
        </p>
    </div>

    @if($successMessage)
        <div role="status" style="margin-bottom: 16px; padding: 12px 16px; border: 1px solid #bbf7d0; border-radius: 8px; background: #f0fdf4; color: #15803d; font-weight: 600;">
            {{ $successMessage }}
        </div>
    @endif

    @if($errorMessage)
        <div role="alert" style="margin-bottom: 16px; padding: 12px 16px; border: 1px solid #fecaca; border-radius: 8px; background: #fef2f2; color: #b91c1c; font-weight: 600;">
            {{ $errorMessage }}
        </div>
    @endif

    <section style="margin-bottom: 22px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;">
        <h2 style="margin: 0 0 14px; color: #1e293b; font-size: 16px; font-weight: 700;">Add trigger</h2>
        <form wire:submit="addTrigger" style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
            <input type="text"
                   wire:model="newKeyWord"
                   maxlength="255"
                   placeholder="e.g. If, After"
                   aria-label="New trigger word"
                   style="flex: 1 1 240px; min-width: 180px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 7px;">
            <label style="display: inline-flex; align-items: center; gap: 7px; color: #334155; font-size: 13px; font-weight: 600;">
                <input type="checkbox" wire:model="newIsActionBefore">
                Action before
            </label>
            <button type="submit" style="padding: 10px 16px; border: 0; border-radius: 7px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer;">
                Add trigger
            </button>
        </form>
        <p style="margin: 10px 0 0; color: #64748b; font-size: 12px;">
            When enabled, remarks using this trigger apply their own retention period. When disabled, the normal retention period is used.
        </p>
    </section>

    <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;">
        <table style="width: 100%; border-collapse: collapse; min-width: 650px;">
            <thead>
                <tr style="background: #f8fafc; color: #475569; text-align: left; font-size: 12px; text-transform: uppercase;">
                    <th style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0;">Trigger word</th>
                    <th style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0;">Action before</th>
                    <th style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0;">Remarks using trigger</th>
                    <th style="padding: 12px 16px; border-bottom: 1px solid #e2e8f0; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($triggers as $trigger)
                    <tr wire:key="remark-trigger-{{ $trigger->id }}" style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px 16px; color: #0f172a; font-weight: 700;">
                            @if($editingTriggerId === (int) $trigger->id)
                                <input type="text" wire:model="editingKeyWord" maxlength="255" aria-label="Edit trigger word" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            @else
                                {{ $trigger->key_word }}
                            @endif
                        </td>
                        <td style="padding: 12px 16px; color: #334155;">
                            @if($editingTriggerId === (int) $trigger->id)
                                <label style="display: inline-flex; align-items: center; gap: 7px;">
                                    <input type="checkbox" wire:model="editingIsActionBefore">
                                    Enabled
                                </label>
                            @elseif($trigger->is_action_before)
                                <span style="display: inline-block; padding: 4px 9px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700;">Enabled</span>
                            @else
                                <span style="display: inline-block; padding: 4px 9px; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: 12px; font-weight: 700;">Disabled</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; color: #475569;">
                            {{ $trigger->usage_count }}
                        </td>
                        <td style="padding: 12px 16px; text-align: right; white-space: nowrap;">
                            @if($editingTriggerId === (int) $trigger->id)
                                <button type="button" wire:click="updateTrigger" style="padding: 7px 11px; border: 0; border-radius: 6px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer;">Save</button>
                                <button type="button" wire:click="cancelEdit" style="padding: 7px 11px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #334155; font-weight: 600; cursor: pointer;">Cancel</button>
                            @else
                                <button type="button" wire:click="startEdit({{ $trigger->id }})" style="padding: 7px 11px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; color: #1d4ed8; font-weight: 600; cursor: pointer;">Edit</button>
                                <button type="button" wire:click="deleteTrigger({{ $trigger->id }})" wire:confirm="Delete trigger '{{ $trigger->key_word }}'?" style="padding: 7px 11px; border: 1px solid #fecaca; border-radius: 6px; background: #fff; color: #dc2626; font-weight: 600; cursor: pointer;">Delete</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="padding: 28px; color: #64748b; text-align: center;">No remark key triggers have been added.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

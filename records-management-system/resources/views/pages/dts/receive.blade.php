<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.dts')] #[Title('Document Tracking System - Receive Transactions')] class extends Component {
    public string $controlNumber = 'CTRL-2023-00142';

    public string $fileCode = 'FC-ADM-2023-089';

    public string $particulars = '';

    public bool $editingControl = false;

    public bool $editingFileCode = false;

    public bool $editingParticulars = false;

    /** @var array<int, array<string, mixed>> */
    public array $transactionPath = [
        [
            'office' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
            'date_in' => '2023-12-11 10:36:50',
            'date_out' => '2023-12-11 02:15:30',
            'action_needed' => 'Created',
            'notes' => '',
        ],
        [
            'office' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
            'date_in' => '2023-12-11 10:36:50',
            'date_out' => '2023-12-11 02:15:30',
            'action_needed' => 'Forwarded',
            'notes' => '',
        ],
    ];

    public function startEdit(string $field): void
    {
        $this->editingControl = $field === 'control';
        $this->editingFileCode = $field === 'file_code';
        $this->editingParticulars = $field === 'particulars';
    }

    public function saveField(string $field): void
    {
        match ($field) {
            'control' => $this->editingControl = false,
            'file_code' => $this->editingFileCode = false,
            'particulars' => $this->editingParticulars = false,
            default => null,
        };
    }
};
?>

@push('styles')
    @vite(['resources/css/dts/receive.css'])
@endpush

<div class="receive-page">
    <div class="receive-card">
        <h1 class="receive-title">Receive Transaction</h1>

        <div class="receive-fields">
            <div class="receive-field-row">
                <label class="receive-field-label" for="control-number">Control #</label>
                <input
                    id="control-number"
                    type="text"
                    class="receive-field-input"
                    wire:model="controlNumber"
                    @readonly(! $editingControl)
                >
                <div class="receive-field-actions">
                    @if ($editingControl)
                        <button type="button" wire:click="saveField('control')">Save</button>
                    @else
                        <button type="button" wire:click="startEdit('control')">Update</button>
                        <span>|</span>
                        <button type="button" wire:click="startEdit('control')">Edit</button>
                    @endif
                </div>
            </div>

            <div class="receive-field-row">
                <label class="receive-field-label" for="file-code">File Code</label>
                <input
                    id="file-code"
                    type="text"
                    class="receive-field-input"
                    wire:model="fileCode"
                    @readonly(! $editingFileCode)
                >
                <div class="receive-field-actions">
                    @if ($editingFileCode)
                        <button type="button" wire:click="saveField('file_code')">Save</button>
                    @else
                        <button type="button" wire:click="startEdit('file_code')">Update</button>
                        <span>|</span>
                        <button type="button" wire:click="startEdit('file_code')">Edit</button>
                    @endif
                </div>
            </div>

            <div class="receive-field-row receive-field-row--particulars">
                <span class="receive-field-label">Particulars</span>
                <div>
                    @if ($editingParticulars)
                        <textarea
                            id="particulars"
                            class="receive-field-input"
                            rows="4"
                            wire:model="particulars"
                            placeholder="Enter transaction particulars..."
                        ></textarea>
                        <div class="receive-field-actions" style="margin-top: 8px;">
                            <button type="button" wire:click="saveField('particulars')">Save</button>
                        </div>
                    @else
                        <div class="receive-particulars-display">{{ $particulars }}</div>
                        <div class="receive-field-actions" style="margin-top: 8px;">
                            <button type="button" wire:click="startEdit('particulars')">Update</button>
                            <span>|</span>
                            <button type="button" wire:click="startEdit('particulars')">Edit</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <hr class="receive-divider">

        <div class="receive-table-wrap">
            <table class="receive-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Office</th>
                        <th>Date In</th>
                        <th>Date Out</th>
                        <th>Action Needed</th>
                        <th>Notes</th>
                        <th>Info</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactionPath as $index => $step)
                        <tr wire:key="path-{{ $index }}">
                            <td>{{ $index + 1 }}</td>
                            <td class="office-cell">{{ $step['office'] }}</td>
                            <td>{{ $step['date_in'] }}</td>
                            <td>{{ $step['date_out'] }}</td>
                            <td>{{ $step['action_needed'] }}</td>
                            <td>{{ $step['notes'] }}</td>
                            <td>
                                <button type="button" class="receive-info-btn" title="More information" aria-label="More information">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <circle cx="5" cy="12" r="2"/>
                                        <circle cx="12" cy="12" r="2"/>
                                        <circle cx="19" cy="12" r="2"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">No transaction path records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="receive-actions">
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                View Listed Path
            </button>
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Completed
            </button>
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                Edit
            </button>
            <button type="button" class="receive-action-btn receive-action-btn--danger">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                Delete
            </button>
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Add CF
            </button>
            <button type="button" class="receive-action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 5v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"/>
                    <line x1="7" y1="8" x2="17" y2="8"/>
                    <line x1="7" y1="12" x2="17" y2="12"/>
                    <line x1="7" y1="16" x2="13" y2="16"/>
                </svg>
                Barcode
            </button>
        </div>
    </div>
</div>

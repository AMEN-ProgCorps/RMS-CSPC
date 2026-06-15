<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and page title attributes
new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Issuance')] class extends Component {
};
?>

@push('styles')
    @vite(['resources/css/dts/create.css'])
@endpush

<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Issuances</h2>
    </div>

    <!-- Control Number Selection Dropdown -->
    <div class="control-wrapper">
        <label class="control-label">Control Number:</label>
        <select class="control-input">
            <option value="">(NUMBER)-(YEAR)</option>
            <option>INT-2026-0001</option>
            <option>INT-2026-0002</option>
            <option>INT-2026-0003</option>
        </select>
    </div>

    <!-- Issuances Form -->
    <form class="rms-form" method="post" action="#" onsubmit="return false;">
        
        <!-- Subject field with label and textarea -->
        <div class="form-row">
            <div class="form-col subject-wrapper">
                <label class="input-label">Subject:</label>
                <textarea class="textarea-input subject-area" placeholder="" rows="4"></textarea>
            </div>
        </div>

        <!-- Receiving Office(s) dropdown -->
        <div class="form-row">
            <div class="form-col small-input">
                <select class="select-input">
                    <option value="">Receiving Office(s)</option>
                    <option>All Units</option>
                </select>
            </div>
        </div>
        <!-- Submit button -->
        <div class="actions-row">
            <button type="button" class="btn-primary" aria-disabled="true">CREATE TRANSACTIONS</button>
        </div>
    </form>
</div>
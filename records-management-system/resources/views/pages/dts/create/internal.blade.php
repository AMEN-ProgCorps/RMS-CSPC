<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and page title attributes
new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Internal Transaction')] class extends Component {

};
?>

@push('styles')
    @vite(['resources/css/dts/create.css'])
@endpush

<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Internal Transaction</h2>
    </div>

    <!-- Control Number Input Field -->
    <div class="control-wrapper">
        <label class="control-label">Control Number:</label>
        <input type="text" class="control-input" placeholder="INT (YEAR)-(MONTH)-(NUMBER)">
    </div>

    <!-- Internal Transaction Form -->
    <form class="rms-form" method="post" action="#" onsubmit="return false;">
        
        <!-- Unit/College field with Edit link -->
        <div class="form-row">
            <div class="form-col medium-input">
                <div style="display:flex; align-items:center; gap:8px; width: 100%;">
                    <input type="text" class="text-input unit-college-input" placeholder="Unit/College">
                    <a href="#" class="muted-link">Update | Edit</a>
                </div>
            </div>
        </div>

        <!-- Name of Requestor field -->
        <div class="form-row">
            <div class="form-col small-input">
                <input type="text" class="text-input" placeholder="Name of Requestor">
            </div>
        </div>

        <!-- Type of Document field -->
        <div class="form-row">
            <div class="form-col small-input">
                <input type="text" class="text-input" placeholder="Type of Document">
            </div>
        </div>

        <!-- Classification dropdown -->
        <div class="form-row">
            <div class="form-col small-input">
                <select class="select-input">
                    <option value="">Classification</option>
                    <option>PLACHOLDER 1</option>
                    <option>PLACHOLDER 2</option>
                    <option>PLACHOLDER 3</option>
                </select>
            </div>
        </div>

        <!-- Subject field with label and textarea -->
        <div class="form-row">
            <div class="form-col subject-wrapper">
                <label class="input-label">Subject:</label>
                <textarea class="textarea-input subject-area" placeholder="" rows="4"></textarea>
            </div>
        </div>

        <!-- Action Needed dropdown -->
        <div class="form-row">
            <div class="form-col small-input">
                <label class="input-label">Action Needed</label>
                <select class="select-input">
                    <option value="">Select action</option>
                    <option>For Information</option>
                    <option>For Signature</option>
                    <option>For Approval</option>
                </select>
            </div>
        </div>

        <!-- View Path field -->
        <div class="form-row">
            <div class="form-col viewpath-wrapper">
                <input type="text" class="text-input" placeholder="View Path">
            </div>
        </div>

        <!-- Copy Furnished dropdown -->
        <div class="form-row">
            <div class="form-col small-input">
                <label class="input-label">Copy Furnished</label>
                <select class="select-input">
                    <option value="">Select</option>
                    <option>Yes</option>
                    <option>No</option>
                </select>
            </div>
        </div>

        <!-- Submit button -->
        <div class="actions-row">
            <button type="button" class="btn-primary" aria-disabled="true">CREATE TRANSACTIONS</button>
        </div>
    </form>

</div>
<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and title attributes
new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Application Letter')] class extends Component {
};
?>

@push('styles')
    @vite(['resources/css/dts/create.css'])
@endpush

<div class="rms-container">

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Application Document</h2>
    </div>

    <!-- Control Number Input Field -->
    <div class="control-wrapper">
        <label class="control-label">Control Number:</label>
        <input type="text" class="control-input" placeholder="APL (YEAR)-(MONTH)-(NUMBER)">
    </div>

    <!-- Document Transaction Form -->
    <form class="rms-form" method="post" action="#" onsubmit="return false;">
        <!-- Type of Document -->
        <div class="form-row">
            <div class="form-col small-input">
                <input type="text" class="text-input" placeholder="Type of Document">
            </div>
        </div>

        <!-- Name of Applicant -->
        <div class="form-row">
            <div class="form-col small-input">
                <input type="text" class="text-input" placeholder="Name of Applicant">
            </div>
        </div>
        
        <!-- Position -->
        <div class="form-row">
            <div class="form-col small-input">
                <input type="text" class="text-input" placeholder="Position">
            </div>
        </div>
        
        <!-- Unit/College with Edit Link -->
        <div class="form-row">
            <div class="form-col medium-input">
                <div style="display:flex; align-items:center; gap:8px; width: 100%;">
                    <input type="text" class="text-input unit-college-input" placeholder="Unit/College">
                    <a href="#" class="muted-link">Update | Edit</a>
                </div>
            </div>
        </div>
        
        <!-- View Path Field -->
        <div class="form-row">
            <div class="form-col viewpath-wrapper">
                <input type="text" class="text-input" placeholder="View Path">
            </div>
        </div>
        
        <!-- Submit Button -->
        <div class="actions-row">
            <button type="button" class="btn-primary" aria-disabled="true">CREATE TRANSACTIONS</button>
        </div>
    </form>
    
</div>
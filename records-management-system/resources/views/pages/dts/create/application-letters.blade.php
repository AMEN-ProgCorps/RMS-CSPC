<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and title attributes
new #[Layout('layouts.dts')] #[Title('Document Tracking System - Create Internal Transaction')] class extends Component {
    // Component logic can be added here in the future
};
?>

<div class="rms-container">
    <style>
        /* Main container styles */
        .rms-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); margin: 20px; padding: 24px; color: #333333; }
        
        /* Header section styles */
        .rms-header { margin-bottom: 24px; border-bottom: 1px solid #e9ecef; padding-bottom: 12px; }
        .rms-header h2 { font-size: 1.15rem; color: #1e40af; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
        
        /* Toolbar and filter styles */
        .rms-toolbar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; gap: 15px; flex-wrap: wrap; flex-direction: column; }
        .rms-toolbar-top, .rms-toolbar-bottom { display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .rms-filters { display: flex; gap: 12px; }
        .rms-select { padding: 6px 32px 6px 12px; font-size: 0.85rem; color: #495057; background-color: #fff; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer; outline: none; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 12px center; background-size: 12px 12px; -webkit-appearance: none; appearance: none; }
        .rms-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
        .rms-entries { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #6c757d; }
        .rms-entries select { width: 60px; padding-right: 20px; }
        .rms-actions { display: flex; align-items: center; gap: 12px; }
        .rms-search-wrapper { position: relative; }
        .btn-icon { width: 14px; height: 14px; fill: currentColor; }
        
        /* Table styles */
        .rms-table-responsive { width: 100%; overflow-x: auto; border: 1px solid #dee2e6; border-radius: 4px; }
        .rms-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.825rem; min-width: 1200px; }
        .rms-table th { background-color: #f8f9fa; color: #212529; font-weight: 700; padding: 12px 10px; border-bottom: 2px solid #dee2e6; border-right: 1px solid #dee2e6; text-align: center; vertical-align: middle; }
        .rms-table th:last-child { border-right: none; }
        .rms-table td { padding: 12px 10px; border-bottom: 1px solid #dee2e6; border-right: 1px solid #dee2e6; color: #495057; vertical-align: middle; }
        .rms-table td:last-child { border-right: none; text-align: center; }
        .rms-no-data { text-align: center !important; color: #868e96; font-style: italic; padding: 24px !important; background-color: #fdfdfd; }
        
        /* Control number input styles */
        .control-wrapper {
            margin-bottom: 20px;
            width: 100%;
        }
        .control-label {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 14px;
            line-height: 121%;
            margin-bottom: 4px;
            display: block;
            color: #333333;
        }
        .control-input {
            width: 100%;
            max-width: 300px;
            height: 32px;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 2px 6px;
            outline: none;
        }
        .control-input:focus {
            border-color: #3b82f6;
        }
        
        /* Form styles */
        .form-row { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
        .form-col { flex: 1; }
        .input-label { display:block; font-weight:600; margin-bottom:6px; font-size:13px; color:#333; }
        .text-input, .select-input, .textarea-input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:13px; color:#222; box-sizing: border-box; }
        .text-input:focus, .select-input:focus, .textarea-input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.08); }
        .small-input { max-width: 360px; }
        .medium-input { max-width: 900px; }
        .subject-area { min-height:92px; resize:vertical; }
        
        /* Subject textarea container styles */
        .subject-wrapper {
            width: 100%;
            max-width: 800px;
        }
        .subject-wrapper .textarea-input {
            width: 100%;
            max-width: 800px;
        }
        
        /* View path container styles */
        .viewpath-wrapper {
            width: 100%;
            max-width: 800px;
        }
        .viewpath-wrapper .text-input {
            width: 100%;
            max-width: 800px;
            height: 40px;
        }
        
        /* Unit/College field styles */
        .unit-college-wrapper { margin-bottom: 12px; }
        .unit-college-label { display:block; font-weight:600; margin-bottom:6px; font-size:13px; color:#333; }
        .unit-college-input-row { display: flex; align-items: center; gap: 12px; }
        .unit-college-input { 
            flex: 1; 
            width: 100%;
            min-width: 500px;
            max-width: 800px;
        }
        
        /* Link and button styles */
        .muted-link { color:#1e3a8a; font-size:13px; text-decoration:none; }
        .muted-link:hover { text-decoration:underline; }
        .actions-row { display:flex; justify-content:flex-end; margin-top:20px; }
        .btn-primary { background:#2563eb; color:#fff; padding:10px 16px; border-radius:6px; border:none; cursor:pointer; font-weight:600; }
        .btn-primary:hover { background:#1e40af; }
    </style>

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
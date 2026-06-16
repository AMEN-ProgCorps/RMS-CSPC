<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

// Define the Livewire Volt component with layout and page title attributes
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
        
        /* Toolbar and filter styles (reserved for future use) */
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
        .rms-table-responsive { width: 100%; overflow-x: auto; border: 1px solid #dee2e6; border-radius: 4px; margin-top: 20px; }
        .rms-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.825rem; min-width: 800px; }
        .rms-table th { background-color: #f8f9fa; color: #212529; font-weight: 700; padding: 12px 10px; border-bottom: 2px solid #dee2e6; border-right: 1px solid #dee2e6; text-align: center; vertical-align: middle; }
        .rms-table th:last-child { border-right: none; }
        .rms-table td { padding: 12px 10px; border-bottom: 1px solid #dee2e6; border-right: 1px solid #dee2e6; color: #495057; vertical-align: middle; text-align: center; }
        .rms-table td:last-child { border-right: none; }
        .rms-no-data { text-align: center !important; color: #868e96; font-style: italic; padding: 24px !important; background-color: #fdfdfd; }
        
        /* Control Number Input Styles */
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
        
        /* Form layout and input field styles */
        .form-row { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
        .form-col { flex: 1; }
        .input-label { display:block; font-weight:600; margin-bottom:6px; font-size:13px; color:#333; }
        .text-input, .select-input, .textarea-input { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:13px; color:#222; box-sizing: border-box; }
        .text-input:focus, .select-input:focus, .textarea-input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.08); }
        .small-input { max-width: 360px; }
        .medium-input { max-width: 900px; }
        .subject-area { min-height:150px; resize:vertical; }
        
        /* Subject textarea container styles */
        .subject-wrapper {
            width: 100%;
            max-width: 800px;
        }
        .subject-wrapper .textarea-input {
            width: 100%;
            max-width: 800px;
        }
        
        /* View Path container styles */
        .viewpath-wrapper {
            width: 100%;
            max-width: 800px;
        }
        .viewpath-wrapper .text-input {
            width: 100%;
            max-width: 800px;
            height: 40px;
        }
        
        /* Unit/College field container styles */
        .unit-college-wrapper { margin-bottom: 12px; }
        .unit-college-label { display:block; font-weight:600; margin-bottom:6px; font-size:13px; color:#333; }
        .unit-college-input-row { display: flex; align-items: center; gap: 12px; }
        .unit-college-input { 
            flex: 1; 
            width: 100%;
            min-width: 500px;
            max-width: 100px;
        }
        
        /* Link and button styles */
        .muted-link { color:#1e3a8a; font-size:13px; text-decoration:none; }
        .muted-link:hover { text-decoration:underline; }
        
        /* BUTTONS SECTION - exact same style as original CREATE TRANSACTIONS button */
        .actions-row {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 100px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            background: #2563eb;
            color: #fff;
            padding: 10px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s ease;
        }
        
        .action-btn:hover {
            background: #1e40af;
        }
        
        .action-btn svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: white;
            stroke-width: 2;
        }
        
        /* Side-by-side layout styles */
        .two-columns-row {
            display: flex;
            gap: 30px;
            align-items: flex-start;
            width: 100%;
        }
        .two-columns-row .form-col {
            flex: 1;
        }
        .two-columns-row .medium-input {
            max-width: 100%;
        }
        
        /* LINE STYLES - exactly as specified */
        .separator-line {
            width: 100%;
            height: 0px;
            border: none;
            border-top: 2px solid #C8C8C8;
            margin: 0;
            opacity: 1;
        }
        
        /* PARTICULARS HEADING - same size and font as Control Number label (.input-label) */
        .particulars-heading {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 13px;
            color: #333;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        
        /* Section wrapper for particulars + line */
        .particulars-section {
            margin-top: 24px;
            margin-bottom: 24px;
        }
        
        /* LINE CONTAINER - adds space between "Particulars" text and the line */
        .line-container {
            margin-top: 50px;
        }
        
        /* TRANSACTION PATH SECTION - heading only (table name) */
        .transaction-path-section {
            margin-top: 50px;
        }
        
        .transaction-path-heading {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 13px;
            color: #333;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        
        /* Icon inside table styles */
        .table-icon {
            width: 20px;
            height: 20px;
            cursor: pointer;
            transition: opacity 0.2s ease;
            display: inline-block;
        }
        
        .table-icon:hover {
            opacity: 0.7;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .two-columns-row {
                flex-direction: column;
                gap: 12px;
            }
            .actions-row {
                justify-content: center;
            }
        }
    </style>

    <!-- Header Section -->
    <div class="rms-header">
        <h2>Receive Transactions</h2>
    </div>

    <!-- Internal Transaction Form -->
    <form class="rms-form" method="post" action="#" onsubmit="return false;">
        
        <!-- Control Number and File Code side by side in one row -->
        <div class="two-columns-row">
            <!-- Control Number field (LEFT) -->
            <div class="form-col medium-input">
                <label for="unit-college" class="input-label">Control #:</label>
                <div style="display:flex; align-items:center; gap:8px; width: 100%;">
                    <input type="text" class="text-input unit-college-input" placeholder="" id="unit-college">
                    <a href="#" class="muted-link">Update | Edit</a>
                </div>
            </div>

            <!-- File Code field (RIGHT) -->
            <div class="form-col medium-input">
                <label for="file-code" class="input-label">File Code:</label>
                <div style="display:flex; align-items:center; gap:8px; width: 100%;">
                    <input type="text" class="text-input unit-college-input" placeholder="" id="file-code">
                    <a href="#" class="muted-link">Update | Edit</a>
                </div>
            </div>
        </div>

        <!-- PARTICULARS SECTION: Heading + Line below it with generous spacing -->
        <div class="particulars-section">
            <div class="particulars-heading">Particulars:</div>
            <!-- Extra spacing container for the line -->
            <div class="line-container">
                <div class="separator-line"></div>
            </div>
        </div>

        <!-- TRANSACTION PATH SECTION: Heading only (table name) -->
        <div class="transaction-path-section">
            <div class="transaction-path-heading">Transaction Path</div>
        </div>

        <!-- TABLE SECTION with empty rows and icon inside Info column + blank column -->
        <div class="rms-table-responsive">
            <table class="rms-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Office</th>
                        <th>Date In</th>
                        <th>Date Out</th>
                        <th>Action Need</th>
                        <th>Notes</th>
                        <th>Info</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Row 1 - Empty fields, icon in Info column, blank column -->
                    <tr>
                        <td>1</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>
                            <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="#1e3a8a" stroke-width="1.5" fill="white"/>
                                <circle cx="12" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="17" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="7" cy="12" r="2" fill="#1e3a8a"/>
                            </svg>
                        </td>
                        <td></td>
                    </tr>
                    <!-- Row 2 - Empty fields, icon in Info column, blank column -->
                    <tr>
                        <td>2</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>
                            <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="#1e3a8a" stroke-width="1.5" fill="white"/>
                                <circle cx="12" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="17" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="7" cy="12" r="2" fill="#1e3a8a"/>
                            </svg>
                        </td>
                        <td></td>
                    </tr>
                    <!-- Row 3 - Empty fields, icon in Info column, blank column -->
                    <tr>
                        <td>3</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td>
                            <svg class="table-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="#1e3a8a" stroke-width="1.5" fill="white"/>
                                <circle cx="12" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="17" cy="12" r="2" fill="#1e3a8a"/>
                                <circle cx="7" cy="12" r="2" fill="#1e3a8a"/>
                            </svg>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
             </table>
        </div>

        <!-- BUTTONS SECTION - arranged from LEFT to RIGHT, aligned to the RIGHT side -->
        <div class="actions-row">
            <!-- VIEW LISTED PATH -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                VIEW LISTED PATH
            </button>

            <!-- COMPLETED -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                COMPLETED
            </button>

            <!-- EDIT -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 3l4 4-7 7H10v-4l7-7z"/>
                    <path d="M4 20h16"/>
                </svg>
                EDIT
            </button>

            <!-- DELETE -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                </svg>
                DELETE
            </button>

            <!-- ADD CF -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                ADD CF
            </button>

            <!-- BARCODE -->
            <button type="button" class="action-btn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 5h2v14H3zM7 5h2v14H7zM11 5h2v14h-2zM15 5h2v14h-2zM19 5h2v14h-2z"/>
                </svg>
                BARCODE
            </button>
        </div>
    </form>

</div>
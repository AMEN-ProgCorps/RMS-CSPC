<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 3')] class extends Component {
    //
};
?>

<div class="container">
    <style>
        /* Base Container & Toolbar */
        .container { font-family: sans-serif; background: #fff; padding: 20px; }
        .toolbar { text-align: right; margin-bottom: 20px; display: flex; justify-content: flex-end; }
        .search-box { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 250px; }
        .buttons { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .btn { padding: 8px 20px; background: #3b82f6; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 6px; }
        .btn-print { background: #3b82f6; }
        .btn-back { background: #3b82f6; } /* Matching the blue buttons in your UI */
        .icon { width: 14px; height: 14px; fill: currentColor; }

        /* Viewer & Page Layout */
        .viewer { display: flex; justify-content: center; background: #f1f5f9; padding: 40px; overflow-x: auto; border-radius: 4px; border: 1px solid #e2e8f0; }
        
        .page { 
            width: 600px; /* Adjusted width for Form 3 proportions */
            min-height: 800px;
            background: #fff; 
            border: 1px solid #ccc; 
            position: relative; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            padding: 50px 40px; 
            box-sizing: border-box;
            color: #000;
            font-family: Arial, sans-serif;
        }

        /* Top Labels outside the table */
        .doc-top-labels {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 5px;
            font-size: 8px;
        }
        .top-label-left { line-height: 1.2; }
        .top-label-right { text-align: right; }

        /* Main Form Table */
        .nap3-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000; /* Thicker outer border */
            font-size: 8px;
        }
        
        .nap3-table th, .nap3-table td {
            border: 1px solid #000;
            padding: 6px;
            vertical-align: top;
        }

        /* Specific Cell Styling */
        .title-cell {
            text-align: center;
            vertical-align: middle !important;
            padding: 15px 5px !important;
        }
        
        .main-title-1 { font-size: 9px; font-weight: bold; margin-bottom: 2px; }
        .main-title-2 { font-size: 7px; font-style: italic; margin-bottom: 12px; }
        .main-title-3 { font-size: 11px; font-weight: bold; }

        .field-label {
            font-weight: bold;
            font-size: 7px;
            display: block;
            margin-bottom: 15px; /* Creates space for written content */
        }

        /* Data Columns Headers */
        .data-header th {
            text-align: center;
            vertical-align: middle;
            font-weight: bold;
            font-size: 7px;
            padding: 8px 4px;
        }

        /* Empty Area for Records */
        .records-area td {
            height: 350px; /* Big empty space for items */
        }

        /* Certification Area */
        .cert-cell {
            text-align: center;
            padding: 15px !important;
        }
        
        .cert-label {
            text-align: left;
            font-weight: bold;
            font-size: 7px;
            margin-bottom: 10px;
        }

        .cert-text {
            font-size: 8px;
            margin: 10px auto 30px auto;
            width: 80%;
            line-height: 1.4;
        }

        .cert-signature-line {
            border-bottom: 1px solid #000;
            width: 250px;
            margin: 0 auto;
            height: 15px;
        }

        .cert-signature-label {
            font-size: 7px;
            margin-top: 2px;
        }
    </style>

    <div class="toolbar">
        <input type="text" class="search-box" placeholder="Search">
    </div>

    <div class="viewer">
        <div class="page">
            
            <div class="doc-top-labels">
                <div class="top-label-left">
                    NAP Form No. 3<br>
                    Revised 2012
                </div>
                <div class="top-label-right">
                    Accomplish in 3 copies
                </div>
            </div>

            <table class="nap3-table">
                <tbody>
                    <!-- Row 1: Titles and Agency -->
                    <tr>
                        <td rowspan="2" colspan="2" style="width: 50%;" class="title-cell">
                            <div class="main-title-1">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                            <div class="main-title-2">Pambansang Sinupan ng Pilipinas</div>
                            <div class="main-title-3">REQUEST FOR AUTHORITY TO DISPOSE<br>OF RECORDS</div>
                        </td>
                        <td colspan="2" style="width: 50%;">
                            <span class="field-label">AGENCY NAME:</span>
                        </td>
                    </tr>
                    
                    <!-- Row 2: Address -->
                    <tr>
                        <td colspan="2">
                            <span class="field-label">ADDRESS:</span>
                        </td>
                    </tr>

                    <!-- Row 3: Date and Phone -->
                    <tr>
                        <td colspan="2">
                            <span class="field-label">DATE:</span>
                        </td>
                        <td colspan="2">
                            <span class="field-label">TELEPHONE NUMBER:</span>
                        </td>
                    </tr>

                    <!-- Row 4: Data Table Headers -->
                    <tr class="data-header">
                        <th style="width: 12%;">GRDS/<br>RDS ITEM<br>NO.</th>
                        <th style="width: 45%;">RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th style="width: 18%;">PERIOD COVERED</th>
                        <th style="width: 25%;">RETENTION PERIOD<br>AND PROVISION/S<br>COMPLIED <i>(If Any)</i></th>
                    </tr>

                    <!-- Row 5: Data Entry Area (Empty space) -->
                    <tr class="records-area">
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>

                    <!-- Row 6: Location and Volume -->
                    <tr>
                        <td colspan="2">
                            <span class="field-label">LOCATION OF RECORDS:</span>
                        </td>
                        <td colspan="2">
                            <span class="field-label">VOLUME IN CUBIC METER:</span>
                        </td>
                    </tr>

                    <!-- Row 7: Prepared By and Position -->
                    <tr>
                        <td colspan="2">
                            <span class="field-label">PREPARED BY: <span style="font-weight:normal;">(Name & Signature)</span></span>
                        </td>
                        <td colspan="2">
                            <span class="field-label">POSITION:</span>
                        </td>
                    </tr>

                    <!-- Row 8: Certification block -->
                    <tr>
                        <td colspan="4" class="cert-cell">
                            <div class="cert-label">CERTIFIED AND APPROVED BY:</div>
                            
                            <div class="cert-text">
                                This is to certify that the above mentioned records are no longer needed and<br>
                                not involved nor connected in any administrative or judicial cases.
                            </div>

                            <div class="cert-signature-line"></div>
                            <div class="cert-signature-label">
                                Name and Signature of Agency Head<br>
                                or Duly Authorized Representative
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

        </div>
    </div>

    <div class="buttons">
        <button class="btn btn-print">
            <svg class="icon" viewBox="0 0 16 16"><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 9h6v2a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-2zm7-5a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/></svg>
            PRINT
        </button>
        <button class="btn btn-back">
            <svg class="icon" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1 8a7 7 0 1 0 14 0A7 7 0 0 0 1 8zm15 0A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-4.5-.5a.5.5 0 0 1 0 1H5.707l2.147 2.146a.5.5 0 0 1-.708.708l-3-3a.5.5 0 0 1 0-.708l3-3a.5.5 0 1 1 .708.708L5.707 7.5H11.5z"/></svg>
            BACK
        </button>
    </div>
</div>
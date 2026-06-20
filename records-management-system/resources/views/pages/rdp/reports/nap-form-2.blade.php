<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 2')] class extends Component {
    //
};
?>

<div class="container">
    <style>
        /* Base Container & Toolbar */
        .container { font-family: sans-serif; background: #fff; padding: 20px; }
        .toolbar { text-align: right; margin-bottom: 20px; }
        .search-box { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 200px; }
        .buttons { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        .btn { padding: 8px 20px; background: #3b82f6; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        
        /* Viewer & Page Layout */
        .viewer { display: flex; justify-content: center; gap: 30px; background: #f1f5f9; padding: 40px; overflow-x: auto; border-radius: 4px; }
        
        .page { 
            width: 550px; /* Adjusted width to fit the document content */
            min-height: 750px; /* Adjusted height for standard paper aspect ratio */
            background: #fff; 
            border: 1px solid #ccc; 
            position: relative; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1); 
            padding: 45px 35px 50px 35px; /* Padding to act as document margins */
            box-sizing: border-box;
            color: #000;
        }

        .label-bottom { position: absolute; bottom: 15px; right: 35px; font-size: 9px; color: #333; }

        /* Document Specific CSS (Inside the Page) */
        .doc-wrapper { margin-top: 15px; width: 100%; font-family: Arial, sans-serif; }
        
        .doc-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 8px; }
        .doc-table th, .doc-table td { border: 1px solid #000; padding: 4px; vertical-align: top; }
        
        .header-cell { text-align: center; width: 45%; vertical-align: middle !important; }
        .header-main-text { font-weight: bold; font-size: 10px; margin-top: 4px; }
        .header-sub-text { font-style: italic; font-size: 8px; margin-bottom: 12px; }
        .header-doc-title { font-weight: bold; font-size: 11px; margin-bottom: 4px; }
        
        .field-label { font-weight: bold; font-size: 7px; display: block; margin-bottom: 2px; }
        
        .data-table { border-top: none; }
        .data-table th { text-align: center; vertical-align: middle; font-weight: bold; font-size: 7px; }
        .sub-header th { font-weight: normal; font-size: 6px; }
        .empty-row td { height: 420px; } /* Empty space for table data */
        
        .important-note { font-size: 7px; margin-top: 8px; text-align: justify; line-height: 1.3; }

        /* Page 2 Signatures & Accomblishment Section */
        .signatures-table { width: 100%; border-collapse: collapse; border: 1px solid #000; font-size: 8px; margin-bottom: 15px; }
        .signatures-table td { border: 1px solid #000; padding: 8px; width: 50%; vertical-align: top; height: 90px; }
        
        .sig-block { display: flex; flex-direction: column; align-items: center; margin-top: 35px; }
        .sig-line { border-bottom: 1px solid #000; width: 70%; text-align: center; margin-bottom: 3px; font-size: 7px; }
        .sig-label { font-size: 7px; }

        .nap-accomplish-section { border: 1px solid #000; padding: 10px; font-size: 8px; min-height: 200px; }
        .section-header { text-align: center; font-weight: bold; font-size: 7px; border-bottom: 1px solid #000; margin: -10px -10px 10px -10px; padding: 5px; }
        
        .checkbox-item { margin-bottom: 5px; display: flex; align-items: center; font-size: 7px; }
        .checkbox { display: inline-block; width: 8px; height: 8px; border: 1px solid #000; margin-right: 5px; }
        
        .approval-area { display: flex; justify-content: space-between; margin-top: 50px; padding: 0 30px; text-align: center; }
        .approval-text { font-size: 7px; }
    </style>

    <div class="toolbar">
        <input type="text" class="search-box" placeholder="Search">
    </div>

    <div class="viewer">
        
        <!-- PAGE 1 -->
        <div class="page">
            
            <div class="doc-wrapper">
                <table class="doc-table">
                    <tr>
                        <td rowspan="2" class="header-cell">
                            <div class="header-main-text">NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                            <div class="header-sub-text">Pambansang Sinupan ng Pilipinas</div>
                            <div class="header-doc-title">RECORDS DISPOSITION SCHEDULE</div>
                        </td>
                        <td>
                            <span class="field-label">1. AGENCY NAME:</span>
                            <br><br>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span class="field-label">2. ADDRESS:</span>
                            <br><br>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span class="field-label">3. SCHEDULE NO.:</span>
                            <br><br>
                        </td>
                        <td>
                            <span class="field-label">4. DATE PREPARED:</span>
                            <br><br>
                        </td>
                    </tr>
                </table>

                <table class="doc-table data-table">
                    <tr>
                        <th rowspan="2" style="width: 8%;">5. ITEM NO.</th>
                        <th rowspan="2" style="width: 42%;">6. RECORD SERIES TITLE AND DESCRIPTION</th>
                        <th colspan="3">7. RETENTION PERIOD</th>
                        <th rowspan="2" style="width: 20%;">8. REMARKS</th>
                    </tr>
                    <tr class="sub-header">
                        <th>Active</th>
                        <th>Storage</th>
                        <th>Total</th>
                    </tr>
                    <tr class="empty-row">
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                </table>

                <div class="important-note">
                    <strong>IMPORTANT:</strong> Pursuant to Section 18, Article III, RA 9470 s. 2007, "No government department, bureau, agency and instrumentality shall dispose of, destroy or authorize the disposal or destruction of any public records, which are in the custody or under its control except with the prior written authority of the executive director."
                </div>
            </div>

            <div class="label-bottom">Page 1 of 2 Pages</div>
        </div>

        <!-- PAGE 2 -->
        <div class="page">
            
            <div class="doc-wrapper">
                <table class="signatures-table">
                    <tr>
                        <td>
                            <span class="field-label">9. Prepared by:</span>
                            <div class="sig-block">
                                <div class="sig-line">Name</div>
                                <div class="sig-label">Position</div>
                            </div>
                        </td>
                        <td>
                            <span class="field-label">11. Recommending Approval:</span>
                            <div class="sig-block">
                                <div class="sig-line">Name</div>
                                <div class="sig-label">Position</div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span class="field-label">10. Assisted by:</span>
                            <div class="sig-block">
                                <div class="sig-line">Name</div>
                                <div class="sig-label">Position</div>
                            </div>
                        </td>
                        <td>
                            <span class="field-label">12. Approved:</span>
                            <div class="sig-block">
                                <div class="sig-line">Name</div>
                                <div class="sig-label">Position</div>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="nap-accomplish-section">
                    <div class="section-header">TO BE ACCOMPLISHED BY THE NATIONAL ARCHIVES OF THE PHILIPPINES</div>
                    
                    <div style="margin-bottom: 8px;">This Records Disposition Schedule</div>
                    <div class="checkbox-item"><span class="checkbox"></span> is being returned for improvement / correction</div>
                    <div class="checkbox-item"><span class="checkbox"></span> is being recommended for approval</div>

                    <div class="approval-area">
                        <div class="approval-text">
                            <br><br>
                            <div>Chairman</div>
                            <div>Records Management Evaluation Committee</div>
                            <div style="margin-top: 8px;">Date</div>
                        </div>

                        <div class="approval-text">
                            <div style="text-align: left;">APPROVED:</div>
                            <br><br>
                            <div>Executive Director</div>
                            <div style="margin-top: 8px;">Date</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="label-bottom">Page 2 of 2 Pages</div>
        </div>

    </div>

    <div class="buttons">
        <button class="btn">Print</button>
        <button class="btn">Back</button>
    </div>
</div>
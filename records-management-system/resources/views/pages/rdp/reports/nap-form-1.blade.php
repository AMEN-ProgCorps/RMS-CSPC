<?php
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.rdp')] #[Title('Records Disposition Program - NAP Form 1')] class extends Component {
    //
};
?>

<div class="container">
    <style>
        .container { font-family: sans-serif; background: #fff; padding: 20px; }
        .toolbar { text-align: right; margin-bottom: 20px; }
        .search-box { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 200px; }
        
        .table-layout { display: flex; gap: 15px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font-size: 11px; text-align: left; }
        th, td { border: 1px solid #000; padding: 5px; vertical-align: top; }
        th { text-align: center; background: #f9f9f9; text-transform: uppercase; }
        
        .text-center { text-align: center; }
        .val { color: #d946ef; font-weight: bold; text-transform: uppercase; display: block; margin-top: 2px; }
        
        .side-actions { display: flex; flex-direction: column; gap: 15px; margin-top: 130px; font-size: 11px; }
        .side-actions a { color: #1d4ed8; text-decoration: none; font-weight: bold; }
        
        .footer { margin-top: 20px; font-size: 11px; }
        .sigs { display: flex; justify-content: space-between; margin-top: 30px; }
        .sig-box { width: 30%; }
        .sig-line { border-bottom: 1px solid #000; color: #d946ef; font-weight: bold; height: 20px; margin: 10px 0 5px; }
        
        .buttons { display: flex; justify-content: flex-end; gap: 10px; margin-top: 30px; }
        .btn { padding: 8px 20px; background: #3b82f6; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
    </style>

    <div class="toolbar">
        <input type="text" class="search-box" placeholder="Search">
    </div>

    <div class="table-layout">
        <div style="flex-grow: 1;">
            <table>
                <tr>
                    <td rowspan="2" class="text-center" style="width: 30%; vertical-align: middle;">
                        <b>NATIONAL ARCHIVES OF THE PHILIPPINES</b><br>
                        <i>Pambansang Sinupan ng Pilipinas</i><br>
                        <b>RECORDS INVENTORY AND APPRAISAL</b>
                    </td>
                    <td>Agency <span class="val">Camarines Sur Polytechnic Colleges</span></td>
                    <td>Organizational Unit <span class="val">Records and Freedom of Info Unit</span></td>
                    <td>Telephone No: <span class="val">(058) 288-44-21 to loc. 113</span></td>
                </tr>
                <tr>
                    <td>Address <span class="val">San Miguel, Nabua, Camarines Sur</span></td>
                    <td>Person-in-Charge <span class="val">Gennica Aprille S. Penetrante</span></td>
                    <td>Date Prepared <span class="val">&nbsp;</span></td>
                </tr>
            </table>

            <table style="border-top: none;">
                <tr>
                    <th rowspan="2">Records Series Title & Description</th>
                    <th rowspan="2">Period Covered</th>
                    <th rowspan="2">Vol in Cubic Meter</th>
                    <th rowspan="2">Location of Records</th>
                    <th rowspan="2">Freq of Use</th>
                    <th rowspan="2">Duplication</th>
                    <th rowspan="2">Time Value<br>T / P</th>
                    <th rowspan="2">Utility Value<br>adm/F/L/Arc</th>
                    <th colspan="3">Retention Period</th>
                    <th rowspan="2">Disposition Provision</th>
                </tr>
                <tr>
                    <th>Active</th><th>Storage</th><th>Total</th>
                </tr>
                <tr style="height: 250px;">
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            </table>
        </div>

        <div class="side-actions">
            <div><a href="#">View</a> &nbsp; <a href="#">Status</a></div>
            <div><a href="#">View</a> &nbsp; <a href="#">Status</a></div>
            <div><a href="#">View</a> &nbsp; <a href="#">Status</a></div>
        </div>
    </div>

    <div class="footer">
        <p><b>LEGEND:</b> TIME VALUE: T-Temporary P-Permanent | UTILITY VALUE: Adm-Administrative F-Fiscal L-Legal Arc-Archival</p>
        <div class="sigs">
            <div class="sig-box">
                PREPARED BY:
                <div class="sig-line">GENNICA APRILLE S. PENETRANTE/AO V</div>
                Name and Position
            </div>
            <div class="sig-box">
                ASSISTED BY:
                <div class="sig-line"></div>
                NAP Records Management Analyst
            </div>
            <div class="sig-box">
                APPROVED BY:
                <div class="sig-line">DR. LUNINGNING Q. BREGALA/CAO</div>
                Chief of the Division/Department
            </div>
        </div>
    </div>

    <div class="buttons">
        <button class="btn">Print</button>
        <button class="btn" style="background: #475569;">Back</button>
    </div>
</div>
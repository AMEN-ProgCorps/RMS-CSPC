<!-- Dynamic Movable QR Print Modal Component -->
<div id="dynamicQrPrintModal" wire:ignore style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.7); z-index: 99999; flex-direction: column;">
    <style>
        #dynamicQrPrintModal .qr-toolbar-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            user-select: none;
        }
        [data-theme="dark"] #dynamicQrPrintModal .qr-toolbar-label {
            color: #cbd5e1 !important;
        }
        #dynamicQrPrintModal .qr-toolbar-divider {
            width: 1px;
            height: 24px;
            background: #cbd5e1;
        }
        [data-theme="dark"] #dynamicQrPrintModal .qr-toolbar-divider {
            background: #334155 !important;
        }
        #dynamicQrPrintModal .qr-toggle-wrapper {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
            margin: 0;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background-color 0.15s ease;
        }
        #dynamicQrPrintModal .qr-toggle-wrapper:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        [data-theme="dark"] #dynamicQrPrintModal .qr-toggle-wrapper:hover {
            background-color: rgba(255, 255, 255, 0.08) !important;
        }
        #dynamicQrPrintModal .qr-toggle-switch {
            position: relative;
            display: inline-block;
            width: 36px;
            height: 20px;
            flex-shrink: 0;
        }
        #dynamicQrPrintModal .qr-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
            pointer-events: none;
        }
        #dynamicQrPrintModal .qr-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: background-color 0.2s ease;
            border-radius: 20px;
        }
        [data-theme="dark"] #dynamicQrPrintModal .qr-toggle-slider {
            background-color: #475569;
        }
        #dynamicQrPrintModal .qr-toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            bottom: 2px;
            background-color: #ffffff !important;
            transition: transform 0.2s ease;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
        }
        #dynamicQrPrintModal .qr-toggle-switch input:checked + .qr-toggle-slider {
            background-color: #3b82f6 !important;
        }
        #dynamicQrPrintModal .qr-toggle-switch input:checked + .qr-toggle-slider:before {
            transform: translateX(16px);
        }
    </style>
    
    <!-- Header/Toolbar -->
    <div style="background: #ffffff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 2;">
        <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 700;">
            <i class="fa-solid fa-arrows-up-down-left-right" style="margin-right: 8px;"></i> Arrange QR Code for Printing
        </h3>
        <div style="display: flex; gap: 10px; align-items: center;">
            <label class="qr-toggle-wrapper" title="Include QR Code digits below the QR image">
                <span class="qr-toolbar-label">Include Code:</span>
                <span class="qr-toggle-switch">
                    <input type="checkbox" id="toggleQrCodeTextCheckbox" onchange="toggleIncludeQrDigits(this.checked)">
                    <span class="qr-toggle-slider"></span>
                </span>
            </label>
            <div class="qr-toolbar-divider"></div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span class="qr-toolbar-label">Size:</span>
                <select id="qrSizeSelect" onchange="changeQrSize(this.value)" style="background: #ffffff; color: #1e293b; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; outline: none;">
                    <option value="small">Small</option>
                    <option value="medium">Medium</option>
                    <option value="big" selected>Big (Default)</option>
                </select>
            </div>
            <div class="qr-toolbar-divider"></div>
            <button type="button" onclick="togglePageLayout()" id="btnToggleLayout" style="background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                <i class="fa-solid fa-rotate"></i> <span id="layoutLabel">Landscape</span>
            </button>
            <button type="button" onclick="resetQrPosition()" style="background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                <i class="fa-solid fa-arrows-rotate"></i> Reset Position
            </button>
            <div class="qr-toolbar-divider"></div>
            <button type="button" onclick="closeDynamicPrintModal()" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;">
                Cancel
            </button>
            <button type="button" onclick="executeDynamicPrint()" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                <i class="fa-solid fa-print"></i> Confirm & Print
            </button>
        </div>
    </div>

    <!-- Paper Scroll Area -->
    <div style="flex: 1; overflow: auto; padding: 40px; display: flex; justify-content: center; align-items: flex-start; background: #e2e8f0;">
        
        <!-- Standard Letter Paper (8.5 x 11 inches at 96 DPI = 816 x 1056 px) -->
        <div id="printPaperContainer" style="width: 816px; height: 1056px; background: #ffffff; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.1); flex-shrink: 0;">
            
            <!-- Page Top/Bottom Indicators (Editor only, not printed) -->
            <div style="position: absolute; top: 12px; left: 0; width: 100%; text-align: center; font-size: 11px; font-weight: bold; color: #cbd5e1; text-transform: uppercase; letter-spacing: 2px; user-select: none; pointer-events: none; border-bottom: 1px dashed #e2e8f0; padding-bottom: 8px;">
                ↑ Top of Page ↑
            </div>
            
            <div style="position: absolute; bottom: 12px; left: 0; width: 100%; text-align: center; font-size: 11px; font-weight: bold; color: #cbd5e1; text-transform: uppercase; letter-spacing: 2px; user-select: none; pointer-events: none; border-top: 1px dashed #e2e8f0; padding-top: 8px;">
                ↓ Bottom of Page ↓
            </div>
            
            <!-- Draggable QR Code -->
            <div id="draggableQrContainer" style="position: absolute; top: 40px; right: 40px; width: 150px; display: flex; flex-direction: column; align-items: center; gap: 4px; cursor: grab; padding: 8px; border: 2px dashed #94a3b8; background: rgba(255,255,255,0.8); border-radius: 8px; user-select: none; z-index: 10; box-sizing: border-box;">
                <img id="dynamicQrImage" crossorigin="anonymous" src="" alt="QR Code" style="width: 130px; height: 130px; pointer-events: none;">
                <span id="dynamicQrText" style="display: none; font-family: monospace; font-weight: bold; font-size: 11px; color: #0f172a !important; text-align: center; white-space: nowrap; overflow: hidden; width: 100%; pointer-events: none; line-height: 1.2;"></span>
            </div>

        </div>
    </div>
</div>

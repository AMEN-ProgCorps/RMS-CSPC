<!-- Dynamic Movable QR Print Modal Component -->
<div id="dynamicQrPrintModal" wire:ignore style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.7); z-index: 99999; flex-direction: column;">
    
    <!-- Header/Toolbar -->
    <div style="background: #ffffff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 2;">
        <h3 style="margin: 0; font-size: 1.1rem; color: #1e293b; font-weight: 700;">
            <i class="fa-solid fa-arrows-up-down-left-right" style="margin-right: 8px;"></i> Arrange QR Code for Printing
        </h3>
        <div style="display: flex; gap: 10px; align-items: center;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 12px; font-weight: 600; color: #475569;">Size:</span>
                <select id="qrSizeSelect" onchange="changeQrSize(this.value)" style="background: #ffffff; color: #1e293b; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; outline: none;">
                    <option value="small">Small</option>
                    <option value="medium">Medium</option>
                    <option value="big" selected>Big (Default)</option>
                </select>
            </div>
            <div style="width: 1px; height: 24px; background: #cbd5e1;"></div>
            <button type="button" onclick="togglePageLayout()" id="btnToggleLayout" style="background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                <i class="fa-solid fa-rotate"></i> <span id="layoutLabel">Landscape</span>
            </button>
            <button type="button" onclick="resetQrPosition()" style="background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                <i class="fa-solid fa-arrows-rotate"></i> Reset Position
            </button>
            <div style="width: 1px; height: 24px; background: #cbd5e1;"></div>
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
            <div id="draggableQrContainer" style="position: absolute; top: 40px; right: 40px; width: 150px; display: flex; flex-direction: column; align-items: center; gap: 4px; cursor: grab; padding: 8px; border: 2px dashed #94a3b8; background: rgba(255,255,255,0.8); border-radius: 8px; user-select: none; z-index: 10;">
                <img id="dynamicQrImage" src="" alt="QR Code" style="width: 130px; height: 130px; pointer-events: none;">
                <span id="dynamicQrText" style="display: none; font-family: monospace; font-weight: bold; font-size: 11px; color: #000; text-align: center; word-break: break-all; pointer-events: none;"></span>
            </div>

        </div>
    </div>
</div>

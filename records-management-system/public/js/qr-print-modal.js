/**
 * Dynamic QR Code Print Modal Script
 * Handles the movable QR code arrangement, sizing, and printing functionality.
 */

// Track current page layout: 'portrait' or 'landscape'
var _qrPageLayout = 'portrait';
// Track current QR code size: 'small', 'medium', or 'big'
var _qrCodeSize = 'big';
// Calculated font size to ensure text fits in a single line
var _calculatedFontSize = '11px';

// Paper dimensions at 96 DPI
var PORTRAIT_W = 816;  // 8.5in
var PORTRAIT_H = 1056; // 11in
var LANDSCAPE_W = 1056; // 11in
var LANDSCAPE_H = 816;  // 8.5in

// QR Code Sizing Configurations
var SIZE_CONFIGS = {
    small: {
        containerW: 80,
        imgW: 64,
        imgH: 64,
        fontSize: '8px',
        fontSizePt: '8pt',
        gap: '2px',
        padding: '6px'
    },
    medium: {
        containerW: 110,
        imgW: 90,
        imgH: 90,
        fontSize: '9px',
        fontSizePt: '9.5pt',
        gap: '3px',
        padding: '7px'
    },
    big: {
        containerW: 150,
        imgW: 130,
        imgH: 130,
        fontSize: '11px',
        fontSizePt: '11pt',
        gap: '4px',
        padding: '8px'
    }
};

window.openDynamicPrintModal = function(qrCodeValue) {
    console.log('openDynamicPrintModal called with value:', qrCodeValue);
    if (!qrCodeValue) {
        console.warn('QR code value is empty!');
        alert('Please generate a QR code first before printing.');
        return;
    }
    var qrModal = document.getElementById('dynamicQrPrintModal');
    var dragContainer = document.getElementById('draggableQrContainer');
    console.log('Found modal element:', qrModal);
    console.log('Found drag container:', dragContainer);
    if (!qrModal || !dragContainer) {
        console.error('Failed to find modal or drag container elements in the DOM.');
        return;
    }

    // Set display to flex FIRST so layout calculations and scrollWidth measurements work
    qrModal.style.display = 'flex';
    qrModal.style.zIndex = '99999';
    document.body.style.overflow = 'hidden';

    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(btoa(qrCodeValue));
    console.log('Generated QR URL:', qrUrl);
    document.getElementById('dynamicQrImage').src = qrUrl;
    document.getElementById('dynamicQrText').textContent = qrCodeValue;

    // Reset to portrait layout
    _qrPageLayout = 'portrait';
    var pc = document.getElementById('printPaperContainer');
    pc.style.width = PORTRAIT_W + 'px';
    pc.style.height = PORTRAIT_H + 'px';
    var label = document.getElementById('layoutLabel');
    if (label) label.textContent = 'Landscape';

    // Reset size dropdown and apply default 'big' sizing (with display set to flex, this works perfectly)
    var select = document.getElementById('qrSizeSelect');
    if (select) select.value = 'big';
    window.changeQrSize('big');

    // Reset position to top-right (respecting the size of the container)
    dragContainer.style.top = '40px';
    dragContainer.style.right = '';
    dragContainer.style.left = (PORTRAIT_W - dragContainer.offsetWidth - 40) + 'px';

    console.log('Modal opened successfully');
};

window.closeDynamicPrintModal = function() {
    console.log('closeDynamicPrintModal called');
    var qrModal = document.getElementById('dynamicQrPrintModal');
    if (qrModal) qrModal.style.display = 'none';
    document.body.style.overflow = '';
};

// Dynamically change QR code sizing
window.changeQrSize = function(sizeValue) {
    _qrCodeSize = sizeValue;
    var dc = document.getElementById('draggableQrContainer');
    var img = document.getElementById('dynamicQrImage');
    var txt = document.getElementById('dynamicQrText');
    if (!dc || !img || !txt) return;

    var config = SIZE_CONFIGS[sizeValue];
    dc.style.width = config.containerW + 'px';
    dc.style.padding = config.padding;
    dc.style.gap = config.gap;
    
    img.style.width = config.imgW + 'px';
    img.style.height = config.imgH + 'px';
    
    txt.style.whiteSpace = 'nowrap';
    txt.style.display = 'none';
    txt.style.width = '100%';

    // Auto-adjust font size to fit container width
    var paddingVal = parseFloat(config.padding) || 0;
    var maxTextWidth = config.containerW - (paddingVal * 2); // available width inside container
    var baseFontSize = parseFloat(config.fontSize) || 11;
    
    var size = baseFontSize;
    txt.style.fontSize = size + 'px';
    while (txt.scrollWidth > maxTextWidth && size > 5) {
        size -= 0.5;
        txt.style.fontSize = size + 'px';
    }
    
    // Store calculated font size for printing
    _calculatedFontSize = txt.style.fontSize;

    // Clamp position within current paper bounds so it doesn't overflow when changing size
    var pc = document.getElementById('printPaperContainer');
    if (pc) {
        var maxLeft = pc.offsetWidth - dc.offsetWidth;
        var maxTop = pc.offsetHeight - dc.offsetHeight;
        var curLeft = parseFloat(dc.style.left) || 0;
        var curTop = parseFloat(dc.style.top) || 0;
        dc.style.left = Math.max(0, Math.min(curLeft, maxLeft)) + 'px';
        dc.style.top = Math.max(0, Math.min(curTop, maxTop)) + 'px';
    }
    console.log('Size updated to:', sizeValue, 'Calculated font size:', _calculatedFontSize);
};

// Toggle between Portrait and Landscape layouts
window.togglePageLayout = function() {
    var pc = document.getElementById('printPaperContainer');
    var dc = document.getElementById('draggableQrContainer');
    if (!pc || !dc) return;

    if (_qrPageLayout === 'portrait') {
        _qrPageLayout = 'landscape';
        pc.style.width = LANDSCAPE_W + 'px';
        pc.style.height = LANDSCAPE_H + 'px';
        var label = document.getElementById('layoutLabel');
        if (label) label.textContent = 'Portrait';
    } else {
        _qrPageLayout = 'portrait';
        pc.style.width = PORTRAIT_W + 'px';
        pc.style.height = PORTRAIT_H + 'px';
        var label = document.getElementById('layoutLabel');
        if (label) label.textContent = 'Landscape';
    }

    // Clamp QR position to new paper bounds
    var maxLeft = pc.offsetWidth - dc.offsetWidth;
    var maxTop = pc.offsetHeight - dc.offsetHeight;
    var curLeft = parseFloat(dc.style.left) || 0;
    var curTop = parseFloat(dc.style.top) || 0;
    dc.style.left = Math.max(0, Math.min(curLeft, maxLeft)) + 'px';
    dc.style.top = Math.max(0, Math.min(curTop, maxTop)) + 'px';
};

// Reset QR position to top-right corner
window.resetQrPosition = function() {
    var pc = document.getElementById('printPaperContainer');
    var dc = document.getElementById('draggableQrContainer');
    if (!pc || !dc) return;
    dc.style.top = '40px';
    dc.style.left = (pc.offsetWidth - dc.offsetWidth - 40) + 'px';
};

window.executeDynamicPrint = function() {
    var dragContainer = document.getElementById('draggableQrContainer');
    if (!dragContainer) return;
    var topPx = parseFloat(dragContainer.style.top);
    var leftPx = parseFloat(dragContainer.style.left);
    var qrImageSrc = document.getElementById('dynamicQrImage').src;
    var qrTextVal = document.getElementById('dynamicQrText').textContent;

    var pageSize = _qrPageLayout === 'portrait' ? 'letter portrait' : 'letter landscape';
    var bodyW = _qrPageLayout === 'portrait' ? '8.5in' : '11in';
    var bodyH = _qrPageLayout === 'portrait' ? '11in' : '8.5in';

    var config = SIZE_CONFIGS[_qrCodeSize];

    var printWindow = window.open('', '_blank');
    var css = '@page { size: ' + pageSize + '; margin: 0; }'
        + 'body { margin:0; padding:0; width:' + bodyW + '; height:' + bodyH + '; position:relative; background:white; }'
        + '.qr-wrapper { position:absolute; top:' + topPx + 'px; left:' + leftPx + 'px; width:' + config.containerW + 'px; display:flex; flex-direction:column; align-items:center; gap:' + config.gap + '; padding:' + config.padding + '; }'
        + '.qr-wrapper img { width:' + config.imgW + 'px; height:' + config.imgH + 'px; }'
        + '.qr-wrapper span { font-family:monospace; font-weight:bold; font-size:' + _calculatedFontSize + '; color:#000; text-align:center; white-space:nowrap; overflow:hidden; }';
    printWindow.document.open();
    var doc = printWindow.document;
    var htmlEl = doc.createElement('html');
    var headEl = doc.createElement('head');
    var styleEl = doc.createElement('style');
    styleEl.textContent = css;
    headEl.appendChild(styleEl);

    var bodyEl = doc.createElement('body');
    var wrapperEl = doc.createElement('div');
    wrapperEl.className = 'qr-wrapper';

    var imgEl = doc.createElement('img');
    imgEl.setAttribute('src', qrImageSrc);
    imgEl.setAttribute('alt', 'QR');

    wrapperEl.appendChild(imgEl);
    bodyEl.appendChild(wrapperEl);

    htmlEl.appendChild(headEl);
    htmlEl.appendChild(bodyEl);
    doc.appendChild(htmlEl);
    doc.close();
    printWindow.focus();
    setTimeout(function() { printWindow.print(); printWindow.close(); }, 500);
};

// Drag logic
(function() {
    var isDragging = false, startX, startY, initLeft, initTop;
    document.addEventListener('mousedown', function(e) {
        var dc = document.getElementById('draggableQrContainer');
        if (dc && dc.contains(e.target)) {
            isDragging = true;
            dc.style.cursor = 'grabbing';
            startX = e.clientX;
            startY = e.clientY;
            initLeft = dc.offsetLeft;
            initTop = dc.offsetTop;
            e.preventDefault();
        }
    });
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var dc = document.getElementById('draggableQrContainer');
        var pc = document.getElementById('printPaperContainer');
        if (!dc || !pc) return;
        var nl = initLeft + (e.clientX - startX);
        var nt = initTop + (e.clientY - startY);
        var ml = pc.offsetWidth - dc.offsetWidth;
        var mt = pc.offsetHeight - dc.offsetHeight;
        dc.style.left = Math.max(0, Math.min(nl, ml)) + 'px';
        dc.style.top = Math.max(0, Math.min(nt, mt)) + 'px';
    });
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            var dc = document.getElementById('draggableQrContainer');
            if (dc) dc.style.cursor = 'grab';
        }
    });
})();

// Global helper for opening the DTS QR View Modal
window.currentQrCodeValue = '';

window.openQrViewModal = function(qrCode) {
    window.currentQrCodeValue = qrCode || '';
    var modal = document.getElementById('dts-qr-view-modal');
    var img = document.getElementById('dts-qr-image');
    var loading = document.getElementById('dts-qr-loading');
    var text = document.getElementById('dts-qr-code-text');

    if (!modal || !img || !loading || !text) return;

    text.innerText = qrCode;
    img.style.display = 'none';
    loading.style.display = 'block';
    modal.style.display = 'flex';

    // Base64 encode the QR Code string
    var qrData = btoa(qrCode);
    var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(qrData);

    img.src = qrUrl;
    img.onload = function() {
        loading.style.display = 'none';
        img.style.display = 'block';
    };
};

window.closeQrViewModal = function() {
    var modal = document.getElementById('dts-qr-view-modal');
    if (modal) {
        modal.style.display = 'none';
    }
};

window.printQrCodeFromModal = function() {
    var code = window.currentQrCodeValue || (document.getElementById('dts-qr-code-text') ? document.getElementById('dts-qr-code-text').innerText : '');
    if (!code) return;
    if (window.openDynamicPrintModal) {
        window.closeQrViewModal();
        window.openDynamicPrintModal(code);
    } else {
        var printWin = window.open('', '_blank', 'width=600,height=600');
        var qrData = btoa(code);
        var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(qrData);
        printWin.document.write('<html><head><title>Print QR Code - ' + code + '</title>'
            + '<style>body{font-family:Roboto,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:90vh;margin:0;}'
            + '.print-box{border:2px solid #000;padding:24px;border-radius:12px;display:inline-flex;flex-direction:column;align-items:center;gap:12px;}'
            + '.print-box img{width:200px;height:200px;}.print-box span{font-family:monospace;font-weight:bold;font-size:15px;}'
            + '@media print{body{min-height:auto;}}</style></head><body>'
            + '<div class="print-box"><img src="' + qrUrl + '" alt="QR Code"><span>' + code + '</span></div>'
            + '</body></html>');
        printWin.document.close();
        printWin.focus();
        setTimeout(function() { printWin.print(); printWin.close(); }, 500);
    }
};

function openQrViewModal(qrCode) { return window.openQrViewModal(qrCode); }
function closeQrViewModal() { return window.closeQrViewModal(); }
function printQrCodeFromModal() { return window.printQrCodeFromModal(); }


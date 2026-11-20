document.addEventListener('DOMContentLoaded', function () {

    // ═══════════════════════════════════════════
    // ELEMENTS
    // ═══════════════════════════════════════════

    const stampModal      = document.getElementById('stampModal');
    const closeModal      = document.getElementById('closeModal');
    const cancelBtn       = document.getElementById('cancelBtn');
    const modalDocInfo    = document.getElementById('modalDocInfo');

    const fileSection     = document.getElementById('fileSection');
    const fileOptions     = document.getElementById('fileOptions');

    const typePills       = document.getElementById('typePills');

    const positionMap     = document.getElementById('positionMap');
    const positionLabel   = document.getElementById('positionLabel');

    const stampAllPages   = document.getElementById('stampAllPages');

    const previewFallback = document.getElementById('previewFallback');
    const openPdfLink     = document.getElementById('openPdfLink');
    const refreshPreview  = document.getElementById('refreshPreview');
    const stampOverlay    = document.getElementById('stampOverlay');
    const pageStage       = document.getElementById('pageStage');
    const pdfCanvas       = document.getElementById('pdfCanvas');
    const previewScroll   = document.getElementById('previewScroll');

    const overlayTitle    = document.getElementById('overlayTitle');
    const overlayDivider  = document.getElementById('overlayDivider');
    const overlayDate     = document.getElementById('overlayDate');
    const overlaySub      = document.getElementById('overlaySub');
    const overlayPages    = document.getElementById('overlayPages');

    const downloadBtn     = document.getElementById('downloadBtn');
    const applyBtn        = document.getElementById('applyBtn');
    const restoreBtn      = document.getElementById('restoreBtn');

    const confirmModal    = document.getElementById('confirmModal');
    const confirmCancel   = document.getElementById('confirmCancel');
    const confirmApply    = document.getElementById('confirmApply');
    const confirmLabel    = document.getElementById('confirmStampLabel');
    const restoreModal    = document.getElementById('restoreModal');
    const restoreCancel   = document.getElementById('restoreCancel');
    const restoreConfirm  = document.getElementById('restoreConfirm');

    const stampBox        = document.getElementById('stampBox');

    const autoPlace   = document.getElementById('autoPlace');
    const manualWrap  = document.getElementById('manualPosWrap');
    const autoBadge   = document.getElementById('autoBadge');
    const detectingBadge = document.getElementById('detectingBadge');
    const prevPageBtn = document.getElementById('prevPage');
    const nextPageBtn = document.getElementById('nextPage');
    const pageIndicator = document.getElementById('pageIndicator');
    const customPosHint = document.getElementById('customPosHint');

    // ═══════════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════════

    let state = {
        files: [],
        selectedFile: null,
        requestId: 0,
        docTitle: '',
        docNo: '',
        rev: '',
        stampType: null,
        position: 'auto',
        allPages: true,
        currentPage: 1,
        pageCount: 1,
        placement: null,
        placementPending: false,
        xPct: null,
        yPct: null,
        widthPct: null,
        pageWidthMm: null,
        pageHeightMm: null,
        stampWidthMm: 36,
        stampHeightMm: 13,
        pdfDoc: null,
        pdfUrl: '',
    };

    let placementTimer = null;
    let placementRequestId = 0;
    let dragState = null;
    let renderToken = 0;

    const POS_LABELS = {
        'top-left': 'Top Left',
        'top-right': 'Top Right',
        'center': 'Center',
        'bottom-left': 'Bottom Left',
        'bottom-right': 'Bottom Right',
        'auto': 'Auto',
        'custom': 'Custom (dragged)',
    };

    /** Masterlist scan basename without extension. */
    function scanBaseName() {
        var raw = String(
            (state.selectedFile && (state.selectedFile.label || state.selectedFile.abbr || state.selectedFile.path))
            || state.docTitle
            || state.docNo
            || 'document'
        ).trim();
        raw = raw.replace(/^.*[\\/]/, ''); // strip path
        raw = raw.replace(/\.pdf$/i, '');
        return raw || 'document';
    }

    /** Download / display name: REFERENCE (scan-name). */
    function referenceDocTitle() {
        return 'REFERENCE (' + scanBaseName() + ')';
    }

    function sanitizeDownloadName(name) {
        return String(name || 'REFERENCE (document)')
            .replace(/[\\/:*?"<>|]+/g, '-')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 180);
    }

    if (autoPlace) {
        autoPlace.addEventListener('change', function () {
            if (this.checked) {
                state.position = 'auto';
                state.xPct = null;
                state.yPct = null;
                state.placement = null;
                if (manualWrap) manualWrap.style.display = 'none';
                if (customPosHint) customPosHint.style.display = 'none';
            } else {
                state.position = document.querySelector('.st-pos-dot.active')?.dataset.pos || 'bottom-right';
                state.xPct = null;
                state.yPct = null;
                state.placementPending = false;
                if (manualWrap) manualWrap.style.display = 'block';
                state.placement = null;
                if (detectingBadge) detectingBadge.style.display = 'none';
                if (customPosHint) customPosHint.style.display = 'none';
                placePresetOnPage(state.position);
            }
            updateOverlay();
            checkReady();
        });
    }

    function selectDefaultStampType() {
        if (state.selectedFile && state.selectedFile.stamped) return;
        const pill = document.querySelector('.st-type-pill[data-type="reference"]')
            || document.querySelector('.st-type-pill');
        if (pill && !pill.classList.contains('selected')) {
            pill.click();
        }
    }

    // ═══════════════════════════════════════════
    // OPEN MODAL
    // ═══════════════════════════════════════════

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.st-btn-stamp');
        if (!btn) return;

        let files = [];
        try { files = JSON.parse(btn.dataset.files || '[]'); } catch (_) { files = []; }

        if (files.length === 0) {
            showToast('No files available for this document.', 'error');
            return;
        }

        state.files       = files;
        state.requestId   = parseInt(btn.dataset.requestId) || 0;
        state.docTitle    = btn.dataset.title || '';
        state.docNo       = btn.dataset.docNo || '';
        state.rev         = btn.dataset.rev   || '0';
        state.allPages    = true;
        state.currentPage = 1;
        state.pageCount   = 1;
        state.placement   = null;
        state.position    = 'auto';
        state.stampType   = null;
        state.xPct = null;
        state.yPct = null;
        state.widthPct = null;

        let defaultFile = files.find(f => !f.stamped) || files[0];
        state.selectedFile = defaultFile;

        modalDocInfo.textContent = referenceDocTitle() + (state.docNo ? ' (' + state.docNo + ', Rev ' + state.rev + ')' : '');

        buildFileSelector();
        resetConfigUI();
        checkReady();
        loadPreview();
        stampModal.style.display = 'flex';
        // Show stamp on the preview immediately so auto-place can be dragged before Apply.
        selectDefaultStampType();
    });

    function buildFileSelector() {
        fileOptions.innerHTML = '';

        if (state.files.length <= 1) {
            fileSection.style.display = 'none';
            return;
        }

        fileSection.style.display = 'block';

        state.files.forEach(function (file, idx) {
            const lbl = document.createElement('label');
            lbl.className = 'st-file-option';

            const radio = document.createElement('input');
            radio.type    = 'radio';
            radio.name    = 'stampFile';
            radio.value   = idx;
            if (file === state.selectedFile) radio.checked = true;

            const span = document.createElement('span');
            span.className = 'st-file-option-label';

            const icon = document.createElement('i');
            icon.className = file.stamped ? 'fa-solid fa-stamp' : 'fa-solid fa-file-pdf';
            if (file.stamped) icon.style.color = 'var(--st-accent)';
            span.appendChild(icon);
            span.appendChild(document.createTextNode(' ' + (file.label || '')));

            if (file.stamped) {
                const small = document.createElement('small');
                small.style.color = 'var(--st-text-subtle)';
                small.style.marginLeft = 'auto';
                small.textContent = '(' + String(file.stamp_type || '').replace(/_/g, ' ') + ')';
                span.appendChild(small);
            }

            lbl.appendChild(radio);
            lbl.appendChild(span);
            fileOptions.appendChild(lbl);

            radio.addEventListener('change', function () {
                if (this.checked) {
                    state.selectedFile = state.files[idx];
                    resetConfigUI();
                    checkReady();
                    loadPreview();
                }
            });
        });
    }

    function resetConfigUI() {
        document.querySelectorAll('.st-type-pill').forEach(p => p.classList.remove('selected'));
        if (autoPlace) autoPlace.checked = true;
        if (manualWrap) manualWrap.style.display = 'none';
        state.position = 'auto';
        state.xPct = null;
        state.yPct = null;
        state.widthPct = null;

        document.querySelectorAll('.st-pos-dot').forEach(d => d.classList.remove('active'));
        const br = document.querySelector('.st-pos-dot[data-pos="bottom-right"]');
        if (br) br.classList.add('active');
        if (positionLabel) positionLabel.textContent = 'Bottom Right';
        if (customPosHint) customPosHint.style.display = 'none';

        if (stampAllPages) stampAllPages.checked = true;
        state.allPages = true;
        if (stampOverlay) stampOverlay.style.display = 'none';
        if (autoBadge) autoBadge.style.display = 'none';
        if (detectingBadge) detectingBadge.style.display = 'none';
        state.currentPage = 1;
        state.placement = null;
        state.placementPending = false;
        state.stampType = null;
        updatePageNav();
        updateOverlay();
        checkReady();
    }

    // ═══════════════════════════════════════════
    // PDF.js PAGE PREVIEW (page-accurate)
    // ═══════════════════════════════════════════

    function dcsPdfUrl(path, bust) {
        path = String(path || '').replace(/^\/+/, '');
        var base;
        if (path.indexOf('scans/') === 0) {
            base = '/storage/' + path;
        } else {
            base = '/dcs/api/signed-scan-url?path=' + encodeURIComponent(path) + '&redirect=1';
        }
        if (!bust) return base;
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(bust);
    }

    function loadPreview(cacheBust) {
        if (!state.selectedFile) return;

        const bust = cacheBust || Date.now();
        const url  = state.selectedFile.preview_url
            ? (state.selectedFile.preview_url + (state.selectedFile.preview_url.indexOf('?') >= 0 ? '&' : '?') + 'v=' + encodeURIComponent(bust))
            : dcsPdfUrl(state.selectedFile.path, bust);

        state.pdfUrl = url;
        if (openPdfLink) openPdfLink.href = url;
        state.currentPage = 1;
        state.placement = null;
        updatePageNav();

        if (!window.pdfjsLib) {
            showFallbackPreview(url);
            updateOverlay();
            return;
        }

        if (previewFallback) previewFallback.style.display = 'none';
        if (previewScroll) previewScroll.style.display = 'flex';

        const token = ++renderToken;
        window.pdfjsLib.getDocument({ url: url, withCredentials: true }).promise
            .then(function (pdf) {
                if (token !== renderToken) return;
                state.pdfDoc = pdf;
                state.pageCount = pdf.numPages || 1;
                updatePageNav();
                return renderCurrentPage(token);
            })
            .then(function () {
                if (token !== renderToken) return;
                updateOverlay();
            })
            .catch(function (err) {
                console.warn('Stamp PDF preview failed', err);
                if (token !== renderToken) return;
                showFallbackPreview(url);
                updateOverlay();
            });
    }

    function showFallbackPreview(url) {
        if (previewScroll) previewScroll.style.display = 'none';
        if (previewFallback) previewFallback.style.display = 'flex';
        if (openPdfLink) openPdfLink.href = url || '#';
        state.pdfDoc = null;
    }

    function renderCurrentPage(token) {
        if (!state.pdfDoc || !pdfCanvas || !pageStage) return Promise.resolve();
        const pageNo = Math.max(1, Math.min(state.currentPage, state.pageCount || 1));
        return state.pdfDoc.getPage(pageNo).then(function (page) {
            if (token != null && token !== renderToken) return;

            const unscaled = page.getViewport({ scale: 1 });
            // Match FPDI mm units used by StampService (approx 72dpi points → mm).
            state.pageWidthMm = unscaled.width * 25.4 / 72;
            state.pageHeightMm = unscaled.height * 25.4 / 72;

            const scrollW = (previewScroll && previewScroll.clientWidth) ? previewScroll.clientWidth - 32 : 700;
            const fitScale = Math.max(0.5, Math.min(1.6, scrollW / unscaled.width));
            const viewport = page.getViewport({ scale: fitScale });

            const outputScale = window.devicePixelRatio || 1;
            pdfCanvas.width = Math.floor(viewport.width * outputScale);
            pdfCanvas.height = Math.floor(viewport.height * outputScale);
            pdfCanvas.style.width = Math.floor(viewport.width) + 'px';
            pdfCanvas.style.height = Math.floor(viewport.height) + 'px';
            pageStage.style.width = Math.floor(viewport.width) + 'px';
            pageStage.style.height = Math.floor(viewport.height) + 'px';

            const ctx = pdfCanvas.getContext('2d');
            const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null;

            return page.render({
                canvasContext: ctx,
                viewport: viewport,
                transform: transform,
            }).promise;
        });
    }

    function updatePageNav() {
        if (!pageIndicator) return;
        pageIndicator.textContent = 'Page ' + state.currentPage + ' / ' + Math.max(1, state.pageCount);
        if (prevPageBtn) prevPageBtn.disabled = state.currentPage <= 1;
        if (nextPageBtn) nextPageBtn.disabled = state.currentPage >= state.pageCount;
    }

    if (prevPageBtn) {
        prevPageBtn.addEventListener('click', function () {
            if (state.currentPage <= 1) return;
            state.currentPage--;
            state.placement = null;
            updatePageNav();
            renderCurrentPage().then(function () { updateOverlay(); });
        });
    }

    if (nextPageBtn) {
        nextPageBtn.addEventListener('click', function () {
            if (state.currentPage >= state.pageCount) return;
            state.currentPage++;
            state.placement = null;
            updatePageNav();
            renderCurrentPage().then(function () { updateOverlay(); });
        });
    }

    // ═══════════════════════════════════════════
    // PAGE-ACCURATE STAMP PLACEMENT
    // ═══════════════════════════════════════════

    function stampWidthPct() {
        if (state.pageWidthMm && state.stampWidthMm) {
            return Math.max(8, Math.min(40, (state.stampWidthMm / state.pageWidthMm) * 100));
        }
        if (state.widthPct) return state.widthPct;
        if (state.placement && state.placement.width_pct) return Math.max(14, state.placement.width_pct);
        return 16;
    }

    function stampHeightPct() {
        if (state.pageHeightMm && state.stampHeightMm) {
            return Math.max(3, Math.min(25, (state.stampHeightMm / state.pageHeightMm) * 100));
        }
        return 6;
    }

    function presetPercents(pos) {
        const w = stampWidthPct();
        const h = stampHeightPct();
        const m = 4; // visual margin % ≈ server 15mm on typical pages
        switch (pos) {
            case 'top-left': return { x: m, y: m, w: w };
            case 'top-right': return { x: Math.max(0, 100 - w - m), y: m, w: w };
            case 'bottom-left': return { x: m, y: Math.max(0, 100 - h - m), w: w };
            case 'center': return { x: Math.max(0, (100 - w) / 2), y: Math.max(0, (100 - h) / 2), w: w };
            case 'bottom-right':
            default: return { x: Math.max(0, 100 - w - m), y: Math.max(0, 100 - h - m), w: w };
        }
    }

    function placeOnPage(xPct, yPct, widthPct, mode) {
        if (!stampOverlay) return;
        const w = widthPct != null ? widthPct : stampWidthPct();
        const h = stampHeightPct();
        const maxX = Math.max(0, 100 - w);
        const maxY = Math.max(0, 100 - h);
        const x = clampPct(Math.min(maxX, xPct));
        const y = clampPct(Math.min(maxY, yPct));

        stampOverlay.style.display = 'block';
        stampOverlay.className = 'st-stamp-overlay pos-' + (mode || 'custom');
        stampOverlay.style.left = x + '%';
        stampOverlay.style.top = y + '%';
        stampOverlay.style.right = 'auto';
        stampOverlay.style.bottom = 'auto';
        stampOverlay.style.width = w + '%';
        stampOverlay.style.minWidth = '0';
        stampOverlay.style.transform = 'none';

        state.xPct = Math.round(x * 100) / 100;
        state.yPct = Math.round(y * 100) / 100;
        state.widthPct = Math.round(w * 100) / 100;

        if (state.placementPending) {
            if (detectingBadge) detectingBadge.style.display = 'inline-flex';
            if (autoBadge) autoBadge.style.display = 'none';
            if (customPosHint) customPosHint.style.display = 'block';
            return;
        }

        if (detectingBadge) detectingBadge.style.display = 'none';

        if (mode === 'auto') {
            if (autoBadge) autoBadge.style.display = 'inline-flex';
            if (customPosHint) customPosHint.style.display = 'block';
            if (positionLabel) positionLabel.textContent = 'Auto (preview)';
        } else if (mode === 'custom') {
            if (autoBadge) autoBadge.style.display = 'none';
            if (customPosHint) customPosHint.style.display = 'block';
            if (positionLabel) positionLabel.textContent = 'Custom (dragged)';
        } else {
            if (autoBadge) autoBadge.style.display = 'none';
            if (customPosHint) customPosHint.style.display = 'block';
        }
    }

    function placePresetOnPage(pos) {
        const p = presetPercents(pos);
        placeOnPage(p.x, p.y, p.w, pos);
    }

    function applyAutoPlacement(data) {
        if (!data) return;
        if (data.stamp_width_mm) state.stampWidthMm = data.stamp_width_mm;
        if (data.stamp_height_mm) state.stampHeightMm = data.stamp_height_mm;
        if (data.page_width_mm) state.pageWidthMm = data.page_width_mm;
        if (data.page_height_mm) state.pageHeightMm = data.page_height_mm;
        placeOnPage(data.x_pct, data.y_pct, stampWidthPct(), 'auto');
    }

    function clampPct(v) {
        return Math.max(0, Math.min(100, v));
    }

    /** Read stamp box position relative to the rendered PDF page stage. */
    function captureStampPagePercents() {
        if (!stampOverlay || !pageStage || stampOverlay.style.display === 'none') {
            return null;
        }
        // Prefer style percentages already stored in page-space.
        if (state.xPct != null && state.yPct != null) {
            return {
                x_pct: state.xPct,
                y_pct: state.yPct,
                width_pct: state.widthPct || stampWidthPct(),
            };
        }
        const stage = pageStage.getBoundingClientRect();
        const box = (stampBox || stampOverlay).getBoundingClientRect();
        if (!stage.width || !stage.height) return null;
        return {
            x_pct: clampPct(((box.left - stage.left) / stage.width) * 100),
            y_pct: clampPct(((box.top - stage.top) / stage.height) * 100),
            width_pct: clampPct((box.width / stage.width) * 100),
        };
    }

    function beginStampDrag(clientX, clientY) {
        if (!stampOverlay || !pageStage || stampOverlay.style.display === 'none') return;
        const stageRect = pageStage.getBoundingClientRect();
        const overlayRect = stampOverlay.getBoundingClientRect();
        dragState = {
            offsetX: clientX - overlayRect.left,
            offsetY: clientY - overlayRect.top,
            stageW: stageRect.width,
            stageH: stageRect.height,
            stageLeft: stageRect.left,
            stageTop: stageRect.top,
            widthPct: state.widthPct || stampWidthPct(),
        };
        stampOverlay.classList.add('is-dragging');
    }

    function moveStampDrag(clientX, clientY) {
        if (!dragState || !stampOverlay) return;
        const leftPx = clientX - dragState.stageLeft - dragState.offsetX;
        const topPx = clientY - dragState.stageTop - dragState.offsetY;
        const widthPct = dragState.widthPct || stampWidthPct();
        const heightPct = stampHeightPct();
        const maxLeft = Math.max(0, 100 - widthPct);
        const maxTop = Math.max(0, 100 - heightPct);
        const xPct = clampPct(Math.min(maxLeft, (leftPx / dragState.stageW) * 100));
        const yPct = clampPct(Math.min(maxTop, (topPx / dragState.stageH) * 100));

        state.position = 'custom';
        if (autoPlace) autoPlace.checked = false;
        if (manualWrap) manualWrap.style.display = 'block';
        placeOnPage(xPct, yPct, widthPct, 'custom');
    }

    function endStampDrag() {
        if (!dragState) return;
        dragState = null;
        if (stampOverlay) stampOverlay.classList.remove('is-dragging');
    }

    if (stampOverlay) {
        stampOverlay.addEventListener('pointerdown', function (e) {
            if (e.button != null && e.button !== 0) return;
            e.preventDefault();
            stampOverlay.setPointerCapture?.(e.pointerId);
            beginStampDrag(e.clientX, e.clientY);
        });
        stampOverlay.addEventListener('pointermove', function (e) {
            if (!dragState) return;
            e.preventDefault();
            moveStampDrag(e.clientX, e.clientY);
        });
        stampOverlay.addEventListener('pointerup', endStampDrag);
        stampOverlay.addEventListener('pointercancel', endStampDrag);
    }

    function setPlacementPending(pending) {
        state.placementPending = !!pending;
        if (detectingBadge) {
            detectingBadge.style.display = pending ? 'inline-flex' : 'none';
        }
        if (pending && autoBadge) autoBadge.style.display = 'none';
        checkReady();
    }

    function fetchStampPlacement() {
        if (!state.selectedFile || !state.stampType || state.position !== 'auto') return;

        clearTimeout(placementTimer);
        setPlacementPending(true);
        // Temporary visible spot while detection runs — Apply stays locked until ready.
        if (state.xPct == null || state.yPct == null) {
            placePresetOnPage('bottom-right');
            if (autoBadge) autoBadge.style.display = 'none';
            if (detectingBadge) detectingBadge.style.display = 'inline-flex';
        }

        placementTimer = setTimeout(function () {
            const reqId = ++placementRequestId;
            const payload = Object.assign(buildPayload(true), { page: state.currentPage });

            fetch('/dcs/stamp/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            })
            .then(function (res) {
                return res.json().catch(function () {
                    throw new Error(res.status === 419
                        ? 'Session expired — refresh the page and try again.'
                        : 'Could not detect stamp placement (server error).');
                }).then(function (data) {
                    if (!res.ok) throw new Error(data.message || 'Preview failed');
                    return data;
                });
            })
            .then(function (data) {
                if (reqId !== placementRequestId) return;
                if (!data.success) throw new Error(data.message || 'Preview failed');

                state.placement = data;
                if (data.page_count) {
                    state.pageCount = data.page_count;
                    updatePageNav();
                }
                renderOverlayContent();
                applyAutoPlacement(data);
                setPlacementPending(false);
            })
            .catch(function (err) {
                if (reqId !== placementRequestId) return;
                if (autoBadge) autoBadge.style.display = 'none';
                // Fall back to a visible bottom-right page placement.
                placePresetOnPage('bottom-right');
                setPlacementPending(false);
                showToast(err.message || 'Could not detect stamp placement.', 'error');
            });
        }, 250);
    }

    // ═══════════════════════════════════════════
    // STAMP TYPE
    // ═══════════════════════════════════════════

    if (typePills) {
        typePills.addEventListener('click', function (e) {
            const pill = e.target.closest('.st-type-pill');
            if (!pill) return;

            document.querySelectorAll('.st-type-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');

            state.stampType = pill.dataset.type;
            state.xPct = null;
            state.yPct = null;
            if (autoPlace && autoPlace.checked) {
                state.position = 'auto';
                state.placement = null;
            } else {
                state.position = document.querySelector('.st-pos-dot.active')?.dataset.pos || 'bottom-right';
                state.placementPending = false;
            }

            // Immediate visible place on the page while auto-detect runs.
            placePresetOnPage(state.position === 'auto' ? 'bottom-right' : state.position);
            if (state.position === 'auto' && detectingBadge) {
                detectingBadge.style.display = 'inline-flex';
                if (autoBadge) autoBadge.style.display = 'none';
            }
            renderOverlayContent();
            updateOverlay();
            checkReady();
        });
    }

    // ═══════════════════════════════════════════
    // POSITION PRESETS
    // ═══════════════════════════════════════════

    if (positionMap) {
        positionMap.addEventListener('click', function (e) {
            const dot = e.target.closest('.st-pos-dot');
            if (!dot) return;

            document.querySelectorAll('.st-pos-dot').forEach(d => d.classList.remove('active'));
            dot.classList.add('active');

            state.position = dot.dataset.pos;
            state.xPct = null;
            state.yPct = null;
            if (autoPlace) autoPlace.checked = false;
            if (manualWrap) manualWrap.style.display = 'block';
            if (customPosHint) customPosHint.style.display = 'none';
            if (positionLabel) positionLabel.textContent = POS_LABELS[state.position] || state.position;

            placePresetOnPage(state.position);
            updateOverlay();
        });
    }

    if (stampAllPages) {
        stampAllPages.addEventListener('change', function () {
            state.allPages = this.checked;
            updateOverlay();
        });
    }

    // ═══════════════════════════════════════════
    // UPDATE OVERLAY
    // ═══════════════════════════════════════════

    var STAMP_STYLES = {
        reference: { title: 'REFERENCE', color: '#b03030', sub: '', image: true },
    };

    function updateOverlay() {
        if (!state.stampType) {
            if (stampOverlay) stampOverlay.style.display = 'none';
            if (autoBadge) autoBadge.style.display = 'none';
            return;
        }

        renderOverlayContent();

        if (state.position === 'custom' && state.xPct != null && state.yPct != null) {
            placeOnPage(state.xPct, state.yPct, state.widthPct, 'custom');
            return;
        }

        if (state.position === 'auto') {
            fetchStampPlacement();
            return;
        }

        placePresetOnPage(state.position);
    }

    function renderOverlayContent() {
        if (!state.stampType) return;

        var cfg = STAMP_STYLES[state.stampType] || STAMP_STYLES.reference;
        var art = document.getElementById('overlayStampArt');
        var fallback = document.getElementById('overlayStampFallback');

        if (fallback) fallback.style.display = 'none';
        if (art) {
            art.style.display = 'block';
            art.onerror = function () {
                art.style.display = 'none';
                if (fallback) fallback.style.display = 'block';
            };
            if (art.complete && art.naturalWidth === 0) {
                art.style.display = 'none';
                if (fallback) fallback.style.display = 'block';
            }
        } else if (fallback) {
            fallback.style.display = 'block';
        }

        if (overlayTitle) {
            overlayTitle.textContent = cfg.title;
            overlayTitle.style.color = cfg.color;
        }
        if (overlayDivider) overlayDivider.style.display = 'none';
        if (overlayDate) overlayDate.style.display = 'none';
        if (overlaySub) overlaySub.style.display = 'none';

        if (overlayPages) {
            overlayPages.textContent = state.allPages ? 'All pages' : ('Page ' + state.currentPage + ' only');
        }
    }

    function previewPlacementReady() {
        if (!state.stampType) return false;
        if (state.placementPending) return false;
        if (state.xPct == null || state.yPct == null) return false;
        if (!stampOverlay || stampOverlay.style.display === 'none') return false;
        return true;
    }

    function syncStampActions() {
        var stamped = !!(state.selectedFile && state.selectedFile.stamped);
        if (applyBtn) {
            applyBtn.style.display = stamped ? 'none' : '';
            // Apply only after the stamp is visible on the preview (and auto-detect finished).
            applyBtn.disabled = stamped || !previewPlacementReady();
            var applyLabel = applyBtn.querySelector('span');
            if (applyLabel) {
                applyLabel.textContent = state.placementPending
                    ? 'Finding position…'
                    : 'Apply Stamp';
            }
        }
        if (restoreBtn) {
            restoreBtn.style.display = stamped ? '' : 'none';
            restoreBtn.disabled = !stamped;
        }
        var headerH3 = document.querySelector('.st-modal-header h3');
        if (headerH3) {
            headerH3.textContent = stamped ? 'Remove Stamp' : 'Apply Stamp';
        }
        if (downloadBtn) {
            // Allow download of live stamped file, or a stamped copy while placing.
            downloadBtn.disabled = !(stamped || previewPlacementReady());
            var label = downloadBtn.querySelector('span');
            if (label) label.textContent = stamped ? 'Download Stamped' : 'Download Copy';
        }
    }

    function checkReady() {
        syncStampActions();
    }

    function hideStampModal() {
        stampModal.style.display = 'none';
        state.pdfDoc = null;
        renderToken++;
        if (stampOverlay) stampOverlay.style.display = 'none';
        if (pdfCanvas) {
            const ctx = pdfCanvas.getContext('2d');
            if (ctx) ctx.clearRect(0, 0, pdfCanvas.width, pdfCanvas.height);
        }
    }

    if (closeModal) closeModal.addEventListener('click', hideStampModal);
    if (cancelBtn) cancelBtn.addEventListener('click', hideStampModal);
    if (stampModal) {
        stampModal.addEventListener('click', function (e) {
            if (e.target === stampModal) hideStampModal();
        });
    }

    if (refreshPreview) {
        refreshPreview.addEventListener('click', function () {
            if (state.selectedFile) loadPreview();
        });
    }

    // ═══════════════════════════════════════════
    // PAYLOAD — always page-accurate custom coords when placing
    // ═══════════════════════════════════════════

    function buildPayload(forPreview) {
        var payload = {
            file_path:    state.selectedFile.path,
            file_key:     state.selectedFile.key,
            request_id:   state.requestId,
            doc_no:       state.docNo,
            doc_title:    state.docTitle,
            rev:          String(state.rev),
            stamp_type:   state.stampType || 'reference',
            position:     state.position || 'auto',
            all_pages:    state.allPages,
        };

        if (forPreview) {
            return payload;
        }

        // Convert whatever is on the page into custom page percentages for FPDI.
        var captured = captureStampPagePercents();
        if (captured) {
            payload.position = 'custom';
            payload.x_pct = Math.round(captured.x_pct * 100) / 100;
            payload.y_pct = Math.round(captured.y_pct * 100) / 100;
            state.position = 'custom';
            state.xPct = payload.x_pct;
            state.yPct = payload.y_pct;
        } else if (state.position === 'custom') {
            payload.x_pct = state.xPct;
            payload.y_pct = state.yPct;
        }

        return payload;
    }

    // ═══════════════════════════════════════════
    // DOWNLOAD
    // ═══════════════════════════════════════════

    if (downloadBtn) {
        downloadBtn.addEventListener('click', function () {
            if (!state.selectedFile) return;
            var stamped = !!state.selectedFile.stamped;
            if (!stamped && !state.stampType) return;

            var originalHTML = downloadBtn.innerHTML;
            downloadBtn.disabled  = true;
            downloadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Preparing...</span>';

            var body;
            if (stamped) {
                body = {
                    use_live_file: true,
                    request_id: state.requestId,
                    file_key: state.selectedFile.key,
                    scan_name: scanBaseName(),
                };
            } else {
                body = Object.assign(buildPayload(false), { scan_name: scanBaseName() });
            }

            fetch('/dcs/stamp/download', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/pdf',
                },
                body: JSON.stringify(body),
            })
            .then(function (res) {
                if (!res.ok) throw new Error('Download failed');
                return res.blob();
            })
            .then(function (blob) {
                var url = URL.createObjectURL(blob);
                var a   = document.createElement('a');
                a.href     = url;
                a.download = sanitizeDownloadName(referenceDocTitle()) + '.pdf';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast(stamped ? 'Downloaded stamped file.' : 'Downloaded stamped copy.', 'success');
            })
            .catch(function () {
                showToast('Download failed. Please try again.', 'error');
            })
            .finally(function () {
                downloadBtn.innerHTML = originalHTML;
                checkReady();
            });
        });
    }

    // ═══════════════════════════════════════════
    // APPLY
    // ═══════════════════════════════════════════

    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            if (!state.selectedFile || !state.stampType) return;
            if (!previewPlacementReady()) {
                showToast('Wait for the stamp preview, then drag it if needed before applying.', 'error');
                return;
            }
            var cfg = STAMP_STYLES[state.stampType] || {};
            if (confirmLabel) confirmLabel.textContent = cfg.title || state.stampType;
            confirmModal.style.display = 'flex';
        });
    }

    if (confirmCancel) {
        confirmCancel.addEventListener('click', function () {
            if (confirmApply && confirmApply.classList.contains('is-loading')) return;
            confirmModal.style.display = 'none';
        });
    }

    if (confirmModal) {
        confirmModal.addEventListener('click', function (e) {
            if (e.target === confirmModal) {
                if (confirmApply && confirmApply.classList.contains('is-loading')) return;
                confirmModal.style.display = 'none';
            }
        });
    }

    if (confirmApply) {
        confirmApply.addEventListener('click', function () {
            if (!state.selectedFile || !state.stampType) return;
            if (confirmApply.disabled || confirmApply.classList.contains('is-loading')) return;

            var originalHTML = confirmApply.innerHTML;
            confirmApply.disabled  = true;
            confirmApply.classList.add('is-loading');
            confirmApply.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Applying...';
            if (confirmCancel) confirmCancel.disabled = true;

            fetch('/dcs/stamp/apply', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(buildPayload(false)),
            })
            .then(function (res) {
                return res.json().catch(function () {
                    var msg = 'Stamping failed (server error).';
                    if (res.status === 419) {
                        msg = 'Session expired — refresh the page and try again.';
                    } else if (res.status === 504) {
                        msg = 'Stamping timed out. Large PDFs can take several minutes — please wait and try again.';
                    }
                    throw new Error(msg);
                }).then(function (data) {
                    if (!res.ok) throw new Error(data.message || 'Failed to apply stamp');
                    return data;
                });
            })
            .then(function () {
                showToast('Stamp applied successfully!', 'success');
                confirmModal.style.display = 'none';
                if (state.selectedFile) {
                    state.selectedFile.stamped = true;
                    state.selectedFile.stamp_type = state.stampType;
                }
                loadPreview(Date.now());
                checkReady();
                document.querySelectorAll('.st-btn-stamp[data-request-id="' + state.requestId + '"]').forEach(function (btn) {
                    btn.classList.add('st-btn-stamp-change');
                    var tag = btn.closest('tr') && btn.closest('tr').querySelector('.st-file-tag');
                    if (tag) {
                        tag.classList.add('st-file-stamped');
                        var icon = tag.querySelector('i');
                        if (icon) icon.className = 'fa-solid fa-stamp';
                    }
                });
                if (window.Livewire && typeof Livewire.dispatch === 'function') {
                    Livewire.dispatch('stamp-applied');
                }
            })
            .catch(function (err) {
                showToast(err.message || 'Failed to apply stamp.', 'error');
            })
            .finally(function () {
                confirmApply.innerHTML = originalHTML;
                confirmApply.classList.remove('is-loading');
                confirmApply.disabled  = false;
                if (confirmCancel) confirmCancel.disabled = false;
            });
        });
    }

    if (restoreBtn) {
        restoreBtn.addEventListener('click', function () {
            if (!state.selectedFile || !state.selectedFile.stamped) return;
            restoreModal.style.display = 'flex';
        });
    }

    if (restoreCancel) {
        restoreCancel.addEventListener('click', function () {
            restoreModal.style.display = 'none';
        });
    }

    if (restoreModal) {
        restoreModal.addEventListener('click', function (e) {
            if (e.target === restoreModal) restoreModal.style.display = 'none';
        });
    }

    if (restoreConfirm) {
        restoreConfirm.addEventListener('click', function () {
            if (!state.selectedFile) return;
            var originalHTML = restoreConfirm.innerHTML;
            restoreConfirm.disabled = true;
            restoreConfirm.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Restoring...';

            fetch('/dcs/stamp/remove', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    request_id: state.requestId,
                    file_key: state.selectedFile.key,
                }),
            })
            .then(function (res) {
                return res.json().catch(function () {
                    throw new Error('Failed to restore original');
                }).then(function (data) {
                    if (!res.ok) throw new Error(data.message || 'Failed to restore original');
                    return data;
                });
            })
            .then(function () {
                restoreModal.style.display = 'none';
                showToast('Stamp removed. Original restored.', 'success');
                if (state.selectedFile) {
                    state.selectedFile.stamped = false;
                    state.selectedFile.stamp_type = null;
                }
                resetConfigUI();
                loadPreview(Date.now());
                checkReady();
                if (window.Livewire && typeof Livewire.dispatch === 'function') {
                    Livewire.dispatch('stamp-applied');
                }
            })
            .catch(function (err) {
                showToast(err.message || 'Could not restore the original file.', 'error');
            })
            .finally(function () {
                restoreConfirm.innerHTML = originalHTML;
                restoreConfirm.disabled = false;
            });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (restoreModal && restoreModal.style.display === 'flex') {
            restoreModal.style.display = 'none';
        } else if (confirmModal && confirmModal.style.display === 'flex') {
            confirmModal.style.display = 'none';
        } else if (stampModal && stampModal.style.display === 'flex') {
            hideStampModal();
        }
    });

    function showToast(message, type) {
        if (window.dcsShowToast) {
            window.dcsShowToast(message, type || 'success');
        }
    }

});

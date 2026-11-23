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
    const pageStack       = document.getElementById('pageStack');
    const previewScroll   = document.getElementById('previewScroll');
    const stampOverlayTpl = document.getElementById('stampOverlayTpl');

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

    const autoPlace   = document.getElementById('autoPlace');
    const manualWrap  = document.getElementById('manualPosWrap');
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
        /** @type {Object.<string, {x_pct:number, y_pct:number, mode:string}>} */
        pagePlacements: {},
        activePage: 1,
        pageWidthMm: null,
        pageHeightMm: null,
        pageMetricsLocked: false,
        stampWidthMm: 36,
        stampHeightMm: 13,
        pdfDoc: null,
        pdfUrl: '',
    };

    let placementTimer = null;
    let placementRequestId = 0;
    let dragState = null;
    let renderToken = 0;
    let pageObserver = null;
    let pageRenderQueue = [];
    let pageRenderActive = 0;
    let autoPlaceQueue = [];
    let autoPlaceActive = 0;
    let autoPlaceInflight = {};
    let autoPlaceDone = {};
    let autoPlaceSession = 0;
    const PAGE_RENDER_CONCURRENCY = 2;
    const AUTO_PLACE_CONCURRENCY = 2;
    const PREVIEW_MAX_DPR = 1.25;
    const PREVIEW_MAX_SCALE = 1.15;

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
                state.pagePlacements = {};
                state.placement = null;
                resetAutoPlaceQueue();
                if (manualWrap) manualWrap.style.display = 'none';
                if (customPosHint) customPosHint.style.display = 'none';
            } else {
                state.position = document.querySelector('.st-pos-dot.active')?.dataset.pos || 'bottom-right';
                state.xPct = null;
                state.yPct = null;
                state.placementPending = false;
                resetAutoPlaceQueue();
                if (manualWrap) manualWrap.style.display = 'block';
                state.placement = null;
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
        state.pagePlacements = {};
        state.activePage = 1;
        resetAutoPlaceQueue();

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
        state.pagePlacements = {};
        state.activePage = 1;
        resetAutoPlaceQueue();

        document.querySelectorAll('.st-pos-dot').forEach(d => d.classList.remove('active'));
        const br = document.querySelector('.st-pos-dot[data-pos="bottom-right"]');
        if (br) br.classList.add('active');
        if (positionLabel) positionLabel.textContent = 'Bottom Right';
        if (customPosHint) customPosHint.style.display = 'none';

        if (stampAllPages) stampAllPages.checked = true;
        state.allPages = true;
        hideAllOverlays();
        state.currentPage = 1;
        state.placement = null;
        state.placementPending = false;
        state.stampType = null;
        updatePageNav();
        updateOverlay();
        checkReady();
    }

    // ═══════════════════════════════════════════
    // PDF.js MULTI-PAGE PREVIEW (scrollable stack)
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

    function clearPageStack() {
        stopPageObserver();
        pageRenderQueue = [];
        pageRenderActive = 0;
        if (pageStack) pageStack.innerHTML = '';
    }

    function stopPageObserver() {
        if (pageObserver) {
            pageObserver.disconnect();
            pageObserver = null;
        }
    }

    function allPageStages() {
        return pageStack ? Array.from(pageStack.querySelectorAll('.st-page-stage')) : [];
    }

    function allOverlays() {
        return pageStack ? Array.from(pageStack.querySelectorAll('.st-stamp-overlay')) : [];
    }

    function primaryOverlay() {
        var overlays = allOverlays();
        return overlays.length ? overlays[0] : null;
    }

    function hideAllOverlays() {
        allOverlays().forEach(function (ov) {
            ov.style.display = 'none';
            ov.classList.remove('is-dragging');
            var detecting = ov.querySelector('.st-detecting-badge');
            var auto = ov.querySelector('.st-auto-place-badge');
            if (detecting) detecting.style.display = 'none';
            if (auto) auto.style.display = 'none';
        });
    }

    function cloneStampOverlay() {
        if (!stampOverlayTpl || !stampOverlayTpl.content) return null;
        return stampOverlayTpl.content.firstElementChild.cloneNode(true);
    }

    function syncStampOnStages() {
        if (!state.stampType) return;
        paintAllPageOverlays();
        renderOverlayContent();
    }

    function previewFitScale(pageWidthPts) {
        const scrollW = (previewScroll && previewScroll.clientWidth) ? previewScroll.clientWidth - 32 : 700;
        return Math.max(0.45, Math.min(PREVIEW_MAX_SCALE, scrollW / Math.max(1, pageWidthPts)));
    }

    function previewOutputScale() {
        return Math.min(window.devicePixelRatio || 1, PREVIEW_MAX_DPR);
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
        state.pageMetricsLocked = false;
        updatePageNav();

        if (!window.pdfjsLib) {
            showFallbackPreview(url);
            updateOverlay();
            return;
        }

        if (previewFallback) previewFallback.style.display = 'none';
        if (previewScroll) previewScroll.style.display = 'flex';
        clearPageStack();
        if (pageStack) {
            pageStack.innerHTML = '<div class="st-preview-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading preview…</div>';
        }

        const token = ++renderToken;
        window.pdfjsLib.getDocument({
            url: url,
            withCredentials: true,
            // Faster first paint: don't download the whole PDF before page 1 can render.
            disableAutoFetch: true,
            disableStream: false,
        }).promise
            .then(function (pdf) {
                if (token !== renderToken) return;
                state.pdfDoc = pdf;
                state.pageCount = pdf.numPages || 1;
                updatePageNav();
                // Once page count is known, seed missing pages (keeps custom drags).
                if (state.xPct != null && state.yPct != null) {
                    seedPlacementsForAllPages(
                        state.xPct,
                        state.yPct,
                        state.position === 'custom' ? 'custom' : (state.position || 'auto')
                    );
                }
                return setupLazyPages(token);
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
        clearPageStack();
    }

    /**
     * Build all page slots immediately (cheap), render page 1 right away,
     * then lazily paint other pages when they enter the scroll viewport.
     */
    function setupLazyPages(token) {
        if (!state.pdfDoc || !pageStack) return Promise.resolve();

        clearPageStack();
        const total = state.pageCount || 1;

        return state.pdfDoc.getPage(1).then(function (firstPage) {
            if (token != null && token !== renderToken) return;

            const unscaled = firstPage.getViewport({ scale: 1 });
            state.pageWidthMm = unscaled.width * 25.4 / 72;
            state.pageHeightMm = unscaled.height * 25.4 / 72;

            const fitScale = previewFitScale(unscaled.width);
            const viewport = firstPage.getViewport({ scale: fitScale });
            const cssW = Math.floor(viewport.width);
            const cssH = Math.floor(viewport.height);

            for (let n = 1; n <= total; n++) {
                pageStack.appendChild(createPageStage(n, total, cssW, cssH));
            }

            startPageObserver(token);
            // Page 1 is already measured — render it immediately (no wait for observer).
            return enqueuePageRender(1, token, true);
        });
    }

    function createPageStage(pageNo, total, cssW, cssH) {
        const stage = document.createElement('div');
        stage.className = 'st-page-stage is-pending';
        stage.dataset.page = String(pageNo);
        stage.dataset.renderState = 'pending';
        stage.style.width = cssW + 'px';
        stage.style.height = cssH + 'px';

        if (total > 1) {
            const label = document.createElement('span');
            label.className = 'st-page-label';
            label.textContent = 'Page ' + pageNo;
            stage.appendChild(label);
        }

        const placeholder = document.createElement('div');
        placeholder.className = 'st-page-placeholder';
        placeholder.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        stage.appendChild(placeholder);

        const canvas = document.createElement('canvas');
        canvas.setAttribute('aria-label', 'PDF page ' + pageNo);
        canvas.style.display = 'none';
        stage.appendChild(canvas);

        const overlay = cloneStampOverlay();
        if (overlay) stage.appendChild(overlay);

        return stage;
    }

    function startPageObserver(token) {
        stopPageObserver();
        if (!previewScroll || !window.IntersectionObserver) {
            // Fallback: queue first few pages so the stack still fills without IO.
            const warm = Math.min(state.pageCount || 1, 3);
            for (let n = 1; n <= warm; n++) enqueuePageRender(n, token);
            return;
        }

        pageObserver = new IntersectionObserver(function (entries) {
            if (token != null && token !== renderToken) return;
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                const stage = entry.target;
                const pageNo = parseInt(stage.dataset.page, 10);
                if (!pageNo) return;
                enqueuePageRender(pageNo, token);
            });
        }, {
            root: previewScroll,
            // Prefetch a bit above/below the visible area.
            rootMargin: '240px 0px',
            threshold: 0.01,
        });

        allPageStages().forEach(function (stage) {
            pageObserver.observe(stage);
        });
    }

    function enqueuePageRender(pageNo, token, urgent) {
        const stage = pageStack && pageStack.querySelector('.st-page-stage[data-page="' + pageNo + '"]');
        if (!stage) return Promise.resolve();
        const status = stage.dataset.renderState;
        if (status === 'ready' || status === 'rendering' || status === 'queued') {
            return stage._renderPromise || Promise.resolve();
        }

        stage.dataset.renderState = 'queued';
        const job = { pageNo: pageNo, token: token, stage: stage };
        if (urgent) pageRenderQueue.unshift(job);
        else pageRenderQueue.push(job);

        stage._renderPromise = new Promise(function (resolve) {
            stage._resolveRender = resolve;
        });
        pumpPageRenderQueue();
        return stage._renderPromise;
    }

    function pumpPageRenderQueue() {
        while (pageRenderActive < PAGE_RENDER_CONCURRENCY && pageRenderQueue.length) {
            const job = pageRenderQueue.shift();
            if (!job) break;
            if (job.token != null && job.token !== renderToken) {
                finishStageRender(job.stage);
                continue;
            }
            pageRenderActive++;
            renderPageIntoStage(job.pageNo, job.stage, job.token)
                .catch(function (err) {
                    console.warn('Stamp page render failed', job.pageNo, err);
                    if (job.stage) {
                        job.stage.dataset.renderState = 'error';
                        const ph = job.stage.querySelector('.st-page-placeholder');
                        if (ph) ph.innerHTML = '<span>Preview unavailable</span>';
                    }
                })
                .finally(function () {
                    pageRenderActive = Math.max(0, pageRenderActive - 1);
                    finishStageRender(job.stage);
                    pumpPageRenderQueue();
                });
        }
    }

    function finishStageRender(stage) {
        if (!stage) return;
        if (typeof stage._resolveRender === 'function') {
            stage._resolveRender();
            stage._resolveRender = null;
        }
    }

    function renderPageIntoStage(pageNo, stage, token) {
        if (!state.pdfDoc || !stage) return Promise.resolve();
        if (token != null && token !== renderToken) return Promise.resolve();

        stage.dataset.renderState = 'rendering';

        return state.pdfDoc.getPage(pageNo).then(function (page) {
            if (token != null && token !== renderToken) return;

            const unscaled = page.getViewport({ scale: 1 });
            if (pageNo === 1 && !state.pageMetricsLocked) {
                state.pageWidthMm = unscaled.width * 25.4 / 72;
                state.pageHeightMm = unscaled.height * 25.4 / 72;
            }

            const fitScale = previewFitScale(unscaled.width);
            const viewport = page.getViewport({ scale: fitScale });
            const cssW = Math.floor(viewport.width);
            const cssH = Math.floor(viewport.height);
            const outputScale = previewOutputScale();

            stage.style.width = cssW + 'px';
            stage.style.height = cssH + 'px';

            const canvas = stage.querySelector('canvas');
            if (!canvas) return;

            canvas.width = Math.floor(viewport.width * outputScale);
            canvas.height = Math.floor(viewport.height * outputScale);
            canvas.style.width = cssW + 'px';
            canvas.style.height = cssH + 'px';

            return page.render({
                canvasContext: canvas.getContext('2d', { alpha: false }),
                viewport: viewport,
                transform: outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null,
            }).promise.then(function () {
                if (token != null && token !== renderToken) return;
                canvas.style.display = 'block';
                stage.classList.remove('is-pending');
                stage.classList.add('is-ready');
                stage.dataset.renderState = 'ready';
                const ph = stage.querySelector('.st-page-placeholder');
                if (ph) ph.remove();
                if (pageObserver) pageObserver.unobserve(stage);
                syncStampOnStages();
                // Per-page empty-area detection as pages become ready.
                if (state.position === 'auto' && state.stampType) {
                    queueAutoPlaceForPage(pageNo);
                }
            });
        });
    }

    function updatePageNav() {
        if (!pageIndicator) return;
        var count = Math.max(1, state.pageCount || 1);
        pageIndicator.textContent = count === 1 ? '1 page' : (count + ' pages');
    }

    // ═══════════════════════════════════════════
    // PAGE-ACCURATE STAMP PLACEMENT
    // ═══════════════════════════════════════════

    function stampWidthPct() {
        // Exact FPDI stamp/page ratio — do not clamp, or preview drift vs applied stamp.
        if (state.pageWidthMm && state.stampWidthMm) {
            return (state.stampWidthMm / state.pageWidthMm) * 100;
        }
        if (state.widthPct) return state.widthPct;
        if (state.placement && state.placement.width_pct) return state.placement.width_pct;
        return 16;
    }

    function stampHeightPct() {
        if (state.pageHeightMm && state.stampHeightMm) {
            return (state.stampHeightMm / state.pageHeightMm) * 100;
        }
        if (state.placement && state.placement.height_pct) return state.placement.height_pct;
        return 6;
    }

    /** PDF content box used for placement (canvas when ready, else stage). */
    function pageContentEl(stage) {
        if (!stage) return null;
        const canvas = stage.querySelector('canvas');
        if (canvas && canvas.style.display !== 'none' && canvas.getBoundingClientRect().width > 0) {
            return canvas;
        }
        return stage;
    }

    function presetPercents(pos) {
        const w = stampWidthPct();
        const h = stampHeightPct();
        const m = 4; // visual margin % ≈ server 15mm on typical pages
        const midY = Math.max(0, (100 - h) / 2);
        switch (pos) {
            case 'top-left': return { x: m, y: m, w: w };
            case 'top-right': return { x: Math.max(0, 100 - w - m), y: m, w: w };
            case 'middle-left': return { x: m, y: midY, w: w };
            case 'middle-right': return { x: Math.max(0, 100 - w - m), y: midY, w: w };
            case 'bottom-left': return { x: m, y: Math.max(0, 100 - h - m), w: w };
            case 'center': return { x: Math.max(0, (100 - w) / 2), y: midY, w: w };
            case 'bottom-right':
            default: return { x: Math.max(0, 100 - w - m), y: Math.max(0, 100 - h - m), w: w };
        }
    }

    /** Temporary/fallback auto spot — middle-right, never bottom. */
    function autoFallbackPercents() {
        return presetPercents('middle-right');
    }

    function setPagePlacement(pageNo, xPct, yPct, mode) {
        const w = stampWidthPct();
        const h = stampHeightPct();
        const x = clampPct(Math.min(Math.max(0, 100 - w), xPct));
        const y = clampPct(Math.min(Math.max(0, 100 - h), yPct));
        const key = String(pageNo);
        state.pagePlacements[key] = {
            x_pct: Math.round(x * 1000) / 1000,
            y_pct: Math.round(y * 1000) / 1000,
            mode: mode || 'custom',
        };
        // Keep legacy single-point fields in sync with the active/edited page.
        if (pageNo === state.activePage || pageNo === 1) {
            state.xPct = state.pagePlacements[key].x_pct;
            state.yPct = state.pagePlacements[key].y_pct;
            state.widthPct = Math.round(w * 1000) / 1000;
        }
        return state.pagePlacements[key];
    }

    function seedPlacementsForAllPages(xPct, yPct, mode) {
        const total = Math.max(1, state.pageCount || 1);
        const targetPages = state.allPages ? total : 1;
        for (let n = 1; n <= targetPages; n++) {
            // Do not overwrite a page the user already dragged.
            const existing = state.pagePlacements[String(n)];
            if (existing && existing.mode === 'custom') continue;
            setPagePlacement(n, xPct, yPct, mode || 'auto');
        }
        // Drop placements for pages no longer in scope when unchecking all-pages.
        Object.keys(state.pagePlacements).forEach(function (key) {
            if (parseInt(key, 10) > targetPages) delete state.pagePlacements[key];
        });
    }

    function paintOverlayAt(ov, pageNo, placement) {
        if (!ov || !placement) return;
        const w = stampWidthPct();
        const h = stampHeightPct();
        const mode = placement.mode || 'custom';
        ov.style.display = 'block';
        ov.className = 'st-stamp-overlay pos-' + mode;
        ov.style.left = placement.x_pct + '%';
        ov.style.top = placement.y_pct + '%';
        ov.style.right = 'auto';
        ov.style.bottom = 'auto';
        ov.style.width = w + '%';
        ov.style.height = h + '%';
        ov.style.minWidth = '0';
        ov.style.transform = 'none';

        const detecting = ov.querySelector('.st-detecting-badge');
        const auto = ov.querySelector('.st-auto-place-badge');
        const pages = ov.querySelector('.st-stamp-pages');
        if (pages) {
            pages.textContent = state.allPages ? ('Page ' + pageNo) : 'Page 1 only';
        }
        if (state.placementPending && pageNo === 1) {
            if (detecting) detecting.style.display = 'inline-flex';
            if (auto) auto.style.display = 'none';
        } else if (mode === 'auto') {
            if (detecting) detecting.style.display = 'none';
            if (auto) auto.style.display = pageNo === state.activePage ? 'inline-flex' : 'none';
        } else {
            if (detecting) detecting.style.display = 'none';
            if (auto) auto.style.display = 'none';
        }
    }

    function paintAllPageOverlays() {
        const overlays = allOverlays();
        if (!overlays.length) return;
        overlays.forEach(function (ov, idx) {
            const pageNo = idx + 1;
            const show = state.allPages || pageNo === 1;
            if (!show) {
                ov.style.display = 'none';
                return;
            }
            let placement = state.pagePlacements[String(pageNo)];
            // While auto-detect is still running for this page, show a temporary
            // page-1-based preview without locking it as final.
            if (!placement && state.position === 'auto' && state.xPct != null && state.yPct != null) {
                placement = {
                    x_pct: state.xPct,
                    y_pct: state.yPct,
                    mode: 'auto',
                };
            } else if (!placement && state.xPct != null && state.yPct != null && state.position !== 'auto') {
                placement = setPagePlacement(pageNo, state.xPct, state.yPct, state.position || 'custom');
            }
            if (!placement) {
                ov.style.display = 'none';
                return;
            }
            paintOverlayAt(ov, pageNo, placement);
        });
        if (customPosHint) customPosHint.style.display = 'block';
    }

    /** Place/update one page only (used while dragging). */
    function placeOnSinglePage(pageNo, xPct, yPct, widthPct, mode) {
        const placement = setPagePlacement(pageNo, xPct, yPct, mode || 'custom');
        state.widthPct = widthPct != null ? widthPct : stampWidthPct();
        state.activePage = pageNo;
        const stage = pageStack && pageStack.querySelector('.st-page-stage[data-page="' + pageNo + '"]');
        const ov = stage && stage.querySelector('.st-stamp-overlay');
        if (ov) paintOverlayAt(ov, pageNo, placement);
        if (customPosHint) customPosHint.style.display = 'block';
        if (mode === 'custom' && positionLabel) positionLabel.textContent = 'Custom (page ' + pageNo + ')';
    }

    /** Seed/update every stamped page (presets / auto-detect). */
    function placeOnPage(xPct, yPct, widthPct, mode) {
        state.widthPct = widthPct != null ? widthPct : stampWidthPct();
        seedPlacementsForAllPages(xPct, yPct, mode || 'auto');
        state.xPct = Math.round(clampPct(xPct) * 1000) / 1000;
        state.yPct = Math.round(clampPct(yPct) * 1000) / 1000;
        paintAllPageOverlays();
        if (customPosHint) customPosHint.style.display = 'block';
        if (mode === 'auto' && positionLabel) positionLabel.textContent = 'Auto (preview)';
        if (mode === 'custom' && positionLabel) positionLabel.textContent = 'Custom (per page)';
    }

    function placePresetOnPage(pos) {
        const p = presetPercents(pos);
        // Preset resets all page placements to the same corner.
        state.pagePlacements = {};
        placeOnPage(p.x, p.y, p.w, pos);
    }

    function applyAutoPlacementMetrics(data) {
        if (!data) return;
        if (data.stamp_width_mm) state.stampWidthMm = data.stamp_width_mm;
        if (data.stamp_height_mm) state.stampHeightMm = data.stamp_height_mm;
        if (data.page_width_mm) state.pageWidthMm = data.page_width_mm;
        if (data.page_height_mm) state.pageHeightMm = data.page_height_mm;
        if (data.page_width_mm && data.page_height_mm) state.pageMetricsLocked = true;
        if (data.width_pct) state.widthPct = data.width_pct;
    }

    /** Apply empty-area result to one page only (never mirrors across pages). */
    function applyAutoPlacementForPage(pageNo, data) {
        if (!data) return;
        applyAutoPlacementMetrics(data);
        const existing = state.pagePlacements[String(pageNo)];
        if (existing && existing.mode === 'custom') return;

        const placement = setPagePlacement(pageNo, data.x_pct, data.y_pct, 'auto');
        const stage = pageStack && pageStack.querySelector('.st-page-stage[data-page="' + pageNo + '"]');
        const ov = stage && stage.querySelector('.st-stamp-overlay');
        if (ov) paintOverlayAt(ov, pageNo, placement);
        if (pageNo === 1) {
            state.xPct = placement.x_pct;
            state.yPct = placement.y_pct;
        }
        if (customPosHint) customPosHint.style.display = 'block';
        if (positionLabel) positionLabel.textContent = 'Auto (per page)';
    }

    function resetAutoPlaceQueue() {
        autoPlaceSession++;
        autoPlaceQueue = [];
        autoPlaceActive = 0;
        autoPlaceInflight = {};
        autoPlaceDone = {};
    }

    function hasCustomPagePlacement() {
        return Object.keys(state.pagePlacements).some(function (key) {
            return state.pagePlacements[key] && state.pagePlacements[key].mode === 'custom';
        });
    }

    function setPageDetecting(pageNo, detecting) {
        const stage = pageStack && pageStack.querySelector('.st-page-stage[data-page="' + pageNo + '"]');
        const ov = stage && stage.querySelector('.st-stamp-overlay');
        if (!ov) return;
        const badge = ov.querySelector('.st-detecting-badge');
        const auto = ov.querySelector('.st-auto-place-badge');
        if (badge) badge.style.display = detecting ? 'inline-flex' : 'none';
        if (detecting && auto) auto.style.display = 'none';
    }

    function queueAutoPlaceForPage(pageNo) {
        if (!state.selectedFile || !state.stampType || state.position !== 'auto') return;
        if (!state.allPages && pageNo !== 1) return;
        pageNo = parseInt(pageNo, 10) || 0;
        if (pageNo < 1) return;
        if (autoPlaceDone[pageNo] || autoPlaceInflight[pageNo]) return;
        const existing = state.pagePlacements[String(pageNo)];
        if (existing && existing.mode === 'custom') return;

        autoPlaceInflight[pageNo] = true;
        autoPlaceQueue.push(pageNo);
        pumpAutoPlaceQueue();
    }

    function queueAutoPlaceForRenderedPages() {
        allPageStages().forEach(function (stage) {
            if (stage.dataset.renderState !== 'ready') return;
            queueAutoPlaceForPage(parseInt(stage.dataset.page, 10));
        });
    }

    function pumpAutoPlaceQueue() {
        while (autoPlaceActive < AUTO_PLACE_CONCURRENCY && autoPlaceQueue.length) {
            const pageNo = autoPlaceQueue.shift();
            if (!pageNo) break;
            if (state.position !== 'auto') {
                delete autoPlaceInflight[pageNo];
                continue;
            }
            autoPlaceActive++;
            requestAutoPlaceForPage(pageNo)
                .catch(function () { /* handled inside */ })
                .finally(function () {
                    autoPlaceActive = Math.max(0, autoPlaceActive - 1);
                    delete autoPlaceInflight[pageNo];
                    pumpAutoPlaceQueue();
                    refreshAutoPlacePending();
                });
        }
    }

    function requestAutoPlaceForPage(pageNo) {
        const session = autoPlaceSession;
        setPageDetecting(pageNo, true);
        if (pageNo === 1) setPlacementPending(true);

        const payload = Object.assign(buildPayload(true), {
            page: pageNo,
            position: 'auto',
        });

        return fetch('/dcs/stamp/preview', {
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
            if (session !== autoPlaceSession) return;
            if (state.position !== 'auto') return;
            if (!data.success) throw new Error(data.message || 'Preview failed');

            if (data.page_count) {
                state.pageCount = data.page_count;
                updatePageNav();
            }
            applyAutoPlacementForPage(pageNo, data);
            autoPlaceDone[pageNo] = true;
            renderOverlayContent();
            if (pageNo === 1) setPlacementPending(false);
            checkReady();
        })
        .catch(function (err) {
            if (session !== autoPlaceSession) return;
            // Fallback only for this page — keep other pages untouched.
            const existing = state.pagePlacements[String(pageNo)];
            if (!(existing && existing.mode === 'custom')) {
                const p = autoFallbackPercents();
                const placement = setPagePlacement(pageNo, p.x, p.y, 'auto');
                const stage = pageStack && pageStack.querySelector('.st-page-stage[data-page="' + pageNo + '"]');
                const ov = stage && stage.querySelector('.st-stamp-overlay');
                if (ov) paintOverlayAt(ov, pageNo, placement);
            }
            autoPlaceDone[pageNo] = true;
            if (pageNo === 1) {
                setPlacementPending(false);
                showToast(err.message || 'Could not detect stamp placement.', 'error');
            }
            checkReady();
        })
        .finally(function () {
            if (session === autoPlaceSession) setPageDetecting(pageNo, false);
        });
    }

    function refreshAutoPlacePending() {
        if (state.position !== 'auto') {
            setPlacementPending(false);
            return;
        }
        // Apply unlocks once page 1 has an empty-area result; other pages keep detecting in background.
        if (autoPlaceDone[1] || (state.pagePlacements['1'] && state.pagePlacements['1'].mode !== undefined)) {
            if (!autoPlaceInflight[1]) setPlacementPending(false);
        }
    }

    function clampPct(v) {
        return Math.max(0, Math.min(100, v));
    }

    function measureOverlayPercents(overlay) {
        const stage = overlay && overlay.closest('.st-page-stage');
        if (!overlay || !stage || overlay.style.display === 'none') return null;
        const pageEl = pageContentEl(stage);
        const boxEl = overlay.querySelector('.st-stamp-box') || overlay;
        const pageRect = pageEl.getBoundingClientRect();
        const boxRect = boxEl.getBoundingClientRect();
        if (!pageRect.width || !pageRect.height) return null;
        return {
            x_pct: clampPct(((boxRect.left - pageRect.left) / pageRect.width) * 100),
            y_pct: clampPct(((boxRect.top - pageRect.top) / pageRect.height) * 100),
            width_pct: clampPct((boxRect.width / pageRect.width) * 100),
        };
    }

    /** Capture independent coordinates for every page that will be stamped. */
    function captureAllPagePositions() {
        const total = Math.max(1, state.pageCount || 1);
        const targetPages = state.allPages ? total : 1;
        const rows = [];

        for (let n = 1; n <= targetPages; n++) {
            const stage = pageStack && pageStack.querySelector('.st-page-stage[data-page="' + n + '"]');
            const overlay = stage && stage.querySelector('.st-stamp-overlay');
            let measured = overlay ? measureOverlayPercents(overlay) : null;
            const stored = state.pagePlacements[String(n)];

            if (measured) {
                setPagePlacement(n, measured.x_pct, measured.y_pct, (stored && stored.mode) || 'custom');
                rows.push({
                    page: n,
                    x_pct: Math.round(measured.x_pct * 1000) / 1000,
                    y_pct: Math.round(measured.y_pct * 1000) / 1000,
                });
            } else if (stored) {
                rows.push({
                    page: n,
                    x_pct: stored.x_pct,
                    y_pct: stored.y_pct,
                });
            } else if (state.xPct != null && state.yPct != null) {
                rows.push({
                    page: n,
                    x_pct: state.xPct,
                    y_pct: state.yPct,
                });
            }
        }

        return rows;
    }

    /** Legacy single-point capture (page 1 / active). */
    function captureStampPagePercents() {
        const rows = captureAllPagePositions();
        if (!rows.length) return null;
        const first = rows[0];
        return {
            x_pct: first.x_pct,
            y_pct: first.y_pct,
            width_pct: state.widthPct || stampWidthPct(),
        };
    }

    function beginStampDrag(overlay, clientX, clientY) {
        if (!overlay || overlay.style.display === 'none') return;
        const stage = overlay.closest('.st-page-stage');
        if (!stage) return;
        const pageNo = parseInt(stage.dataset.page, 10) || 1;
        const pageEl = pageContentEl(stage);
        const pageRect = pageEl.getBoundingClientRect();
        const overlayRect = overlay.getBoundingClientRect();
        dragState = {
            overlay: overlay,
            pageNo: pageNo,
            offsetX: clientX - overlayRect.left,
            offsetY: clientY - overlayRect.top,
            stageW: pageRect.width,
            stageH: pageRect.height,
            stageLeft: pageRect.left,
            stageTop: pageRect.top,
            widthPct: state.widthPct || stampWidthPct(),
        };
        state.activePage = pageNo;
        overlay.classList.add('is-dragging');
    }

    function moveStampDrag(clientX, clientY) {
        if (!dragState) return;

        // Refresh page rect each move so scroll during drag stays accurate.
        const stage = dragState.overlay && dragState.overlay.closest('.st-page-stage');
        if (stage) {
            const pageRect = pageContentEl(stage).getBoundingClientRect();
            dragState.stageW = pageRect.width;
            dragState.stageH = pageRect.height;
            dragState.stageLeft = pageRect.left;
            dragState.stageTop = pageRect.top;
        }

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
        // Only the dragged page moves — other pages keep their own positions.
        placeOnSinglePage(dragState.pageNo || 1, xPct, yPct, widthPct, 'custom');
    }

    function endStampDrag() {
        if (!dragState) return;
        if (dragState.overlay) dragState.overlay.classList.remove('is-dragging');
        dragState = null;
    }

    if (pageStack) {
        pageStack.addEventListener('pointerdown', function (e) {
            const overlay = e.target.closest('.st-stamp-overlay');
            if (!overlay || !pageStack.contains(overlay)) return;
            if (e.button != null && e.button !== 0) return;
            e.preventDefault();
            overlay.setPointerCapture?.(e.pointerId);
            beginStampDrag(overlay, e.clientX, e.clientY);
        });
        pageStack.addEventListener('pointermove', function (e) {
            if (!dragState) return;
            e.preventDefault();
            moveStampDrag(e.clientX, e.clientY);
        });
        pageStack.addEventListener('pointerup', endStampDrag);
        pageStack.addEventListener('pointercancel', endStampDrag);
    }

    function setPlacementPending(pending) {
        state.placementPending = !!pending;
        // Only page 1 drives the Apply lock; other pages detect in the background.
        setPageDetecting(1, !!pending && state.position === 'auto');
        checkReady();
    }

    function fetchStampPlacement() {
        if (!state.selectedFile || !state.stampType || state.position !== 'auto') return;

        clearTimeout(placementTimer);
        placementTimer = setTimeout(function () {
            resetAutoPlaceQueue();
            setPlacementPending(true);

            // Temporary mid-right spot while empty-area detection runs (never bottom).
            if (!state.pagePlacements['1'] || state.pagePlacements['1'].mode !== 'custom') {
                const p = autoFallbackPercents();
                const placement = setPagePlacement(1, p.x, p.y, 'auto');
                state.xPct = placement.x_pct;
                state.yPct = placement.y_pct;
                paintAllPageOverlays();
            }

            // Detect empty area per rendered page (page 1 first, then as you scroll).
            queueAutoPlaceForPage(1);
            queueAutoPlaceForRenderedPages();
        }, 200);
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
            state.pagePlacements = {};
            state.activePage = 1;
            resetAutoPlaceQueue();
            if (autoPlace && autoPlace.checked) {
                state.position = 'auto';
                state.placement = null;
            } else {
                state.position = document.querySelector('.st-pos-dot.active')?.dataset.pos || 'bottom-right';
                state.placementPending = false;
            }

            // Immediate visible place while auto-detect runs — mid-right, not bottom.
            placePresetOnPage(state.position === 'auto' ? 'middle-right' : state.position);
            if (state.position === 'auto') setPlacementPending(true);
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
            if (state.xPct != null && state.yPct != null) {
                seedPlacementsForAllPages(state.xPct, state.yPct, state.position || 'auto');
            }
            paintAllPageOverlays();
            renderOverlayContent();
            checkReady();
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
            hideAllOverlays();
            return;
        }

        renderOverlayContent();

        if (state.position === 'custom') {
            paintAllPageOverlays();
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

        allOverlays().forEach(function (ov, idx) {
            var art = ov.querySelector('.st-stamp-art');
            var fallback = ov.querySelector('.st-stamp-inner');
            var title = ov.querySelector('.st-stamp-title');
            var divider = ov.querySelector('.st-stamp-divider');
            var date = ov.querySelector('.st-stamp-date');
            var sub = ov.querySelector('.st-stamp-sub');
            var pages = ov.querySelector('.st-stamp-pages');
            var pageNo = idx + 1;

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

            if (title) {
                title.textContent = cfg.title;
                title.style.color = cfg.color;
            }
            if (divider) divider.style.display = 'none';
            if (date) date.style.display = 'none';
            if (sub) sub.style.display = 'none';
            if (pages) {
                pages.textContent = state.allPages ? ('Page ' + pageNo) : 'Page 1 only';
            }
        });
    }

    function previewPlacementReady() {
        if (!state.stampType) return false;
        if (state.placementPending) return false;
        var first = state.pagePlacements['1'];
        if (first && first.x_pct != null && first.y_pct != null) return true;
        if (state.xPct == null || state.yPct == null) return false;
        var overlay = primaryOverlay();
        if (!overlay || overlay.style.display === 'none') return false;
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
        hideAllOverlays();
        clearPageStack();
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

        // Manual drags → send ONLY customized pages (server auto-fills the rest).
        // Pure auto → server finds an empty area on EVERY page (accurate for large PDFs).
        if (hasCustomPagePlacement()) {
            var pagePositions = captureAllPagePositions().filter(function (row) {
                var stored = state.pagePlacements[String(row.page)];
                return stored && stored.mode === 'custom';
            });
            payload.position = 'custom';
            payload.page_positions = pagePositions;
            if (pagePositions.length) {
                payload.x_pct = pagePositions[0].x_pct;
                payload.y_pct = pagePositions[0].y_pct;
            } else if (state.xPct != null && state.yPct != null) {
                payload.x_pct = state.xPct;
                payload.y_pct = state.yPct;
            }
        } else if (state.position === 'auto') {
            payload.position = 'auto';
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
                // Clear drag overlay so the reloaded preview shows only the baked stamp.
                state.stampType = null;
                state.xPct = null;
                state.yPct = null;
                state.widthPct = null;
                state.pagePlacements = {};
                state.activePage = 1;
                state.placement = null;
                state.placementPending = false;
                hideAllOverlays();
                document.querySelectorAll('.st-type-pill').forEach(function (p) { p.classList.remove('selected'); });
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

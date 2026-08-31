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
    const certifiedFields = document.getElementById('certifiedFields');
    const certifiedByInp  = document.getElementById('certifiedBy');
    const designationInp  = document.getElementById('designation');

    const positionMap     = document.getElementById('positionMap');
    const positionLabel   = document.getElementById('positionLabel');

    const stampAllPages   = document.getElementById('stampAllPages');

    const pdfPreview      = document.getElementById('pdfPreview');
    const previewFallback = document.getElementById('previewFallback');
    const openPdfLink     = document.getElementById('openPdfLink');
    const refreshPreview  = document.getElementById('refreshPreview');
    const stampOverlay    = document.getElementById('stampOverlay');

    const overlayTitle    = document.getElementById('overlayTitle');
    const overlayDivider  = document.getElementById('overlayDivider');
    const overlayFields   = document.getElementById('overlayFields');
    const overlayCertBy   = document.getElementById('overlayCertBy');
    const overlayDesig    = document.getElementById('overlayDesig');
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

    const searchInput     = document.getElementById('stSearch');
    const typeFilter      = document.getElementById('stTypeFilter');
    const clearBtn        = document.getElementById('stClearBtn');

    const stampBox        = document.getElementById('stampBox');

    const autoPlace   = document.getElementById('autoPlace');
    const manualWrap  = document.getElementById('manualPosWrap');
    const autoBadge   = document.getElementById('autoBadge');
    const prevPageBtn = document.getElementById('prevPage');
    const nextPageBtn = document.getElementById('nextPage');
    const pageIndicator = document.getElementById('pageIndicator');

    // ═══════════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════════

    let state = {
        files: [],
        selectedFile: null,
        docTitle: '',
        docNo: '',
        rev: '',
        stampType: null,
        position: 'auto',
        allPages: true,
        certBy: '',
        desig: '',
        currentPage: 1,
        pageCount: 1,
        placement: null,
    };

    let placementTimer = null;
    let placementRequestId = 0;

    autoPlace.addEventListener('change', function () {
        if (this.checked) {
            state.position = 'auto';
            manualWrap.style.display = 'none';
        } else {
            state.position = document.querySelector('.st-pos-dot.active')?.dataset.pos || 'bottom-right';
            manualWrap.style.display = 'block';
            state.placement = null;
        }
        updateOverlay();
    });

    // ═══════════════════════════════════════════
    // SEARCH handled server-side via Livewire
    // ═══════════════════════════════════════════

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
        state.certBy      = '';
        state.desig       = '';
        state.currentPage = 1;
        state.pageCount   = 1;
        state.placement   = null;
        state.position    = 'auto';
        // Only Reference stamp is allowed.
        state.stampType   = 'reference';

        // Find the first file — prefer unstamped, fallback to first stamped
        let defaultFile = files.find(f => !f.stamped) || files[0];
        state.selectedFile = defaultFile;

        // Header info
        modalDocInfo.textContent = state.docNo + ' — ' + state.docTitle + ' (Rev ' + state.rev + ')';

        // Build file selector
        buildFileSelector();

        // Reset config UI then lock Reference
        resetConfigUI();
        document.querySelectorAll('.st-type-pill').forEach(p => p.classList.remove('selected'));
        const refPill = document.querySelector('.st-type-pill[data-type="reference"]');
        if (refPill) refPill.classList.add('selected');
        state.stampType = 'reference';
        if (certifiedFields) certifiedFields.style.display = 'none';
        updateOverlay();
        checkReady();

        // Update modal title
        const headerH3 = document.querySelector('.st-modal-header h3');
        headerH3.textContent = defaultFile.stamped ? 'Change Stamp' : 'Apply Stamp';

        // Load first preview
        loadPreview();

        // Show
        stampModal.style.display = 'flex';
    });

    // ═══════════════════════════════════════════
    // FILE SELECTOR
    // ═══════════════════════════════════════════

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
                    updateOverlay();
                    checkReady();
                    loadPreview();
                }
            });
        });
    }

    // ═══════════════════════════════════════════
    // RESET CONFIG UI
    // ═══════════════════════════════════════════

    function resetConfigUI() {
        document.querySelectorAll('.st-type-pill').forEach(p => p.classList.remove('selected'));
        const refPill = document.querySelector('.st-type-pill[data-type="reference"]');
        if (refPill) refPill.classList.add('selected');
        if (certifiedFields) certifiedFields.style.display = 'none';
        if (certifiedByInp) certifiedByInp.value = '';
        if (designationInp) designationInp.value = '';
        autoPlace.checked = true;
        manualWrap.style.display = 'none';
        state.position = 'auto';

        document.querySelectorAll('.st-pos-dot').forEach(d => d.classList.remove('active'));
        document.querySelector('.st-pos-dot[data-pos="bottom-right"]').classList.add('active');
        positionLabel.textContent = 'Bottom Right';

        stampAllPages.checked = true;
        stampOverlay.style.display = 'none';
        if (autoBadge) autoBadge.style.display = 'none';
        state.currentPage = 1;
        state.pageCount = 1;
        state.placement = null;
        state.stampType = 'reference';
        updatePageNav();

        downloadBtn.disabled = true;
        applyBtn.disabled    = true;
    }

    // ═══════════════════════════════════════════
    // LOAD PREVIEW
    // ═══════════════════════════════════════════

    function dcsPdfUrl(path, bust) {
        path = String(path || '').replace(/^\/+/, '');
        var base;
        if (path.indexOf('scans/') === 0) {
            base = '/storage/' + path;
        } else {
            base = '/dcs/view-document?path=' + encodeURIComponent(path);
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

        previewFallback.style.display = 'none';
        pdfPreview.style.display      = 'block';
        pdfPreview.src                = url;
        openPdfLink.href              = url;

        state.currentPage = 1;
        state.placement = null;
        updatePageNav();
        updateOverlay();
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
            updateOverlay();
        });
    }

    if (nextPageBtn) {
        nextPageBtn.addEventListener('click', function () {
            if (state.currentPage >= state.pageCount) return;
            state.currentPage++;
            state.placement = null;
            updatePageNav();
            updateOverlay();
        });
    }

    function fetchStampPlacement() {
        if (!state.selectedFile || !state.stampType || state.position !== 'auto') return;

        if (state.stampType === 'certified_true_copy' &&
            (!state.certBy.trim() || !state.desig.trim())) {
            return;
        }

        clearTimeout(placementTimer);
        placementTimer = setTimeout(function () {
            const reqId = ++placementRequestId;
            const payload = Object.assign(buildPayload(), { page: state.currentPage });

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
                state.pageCount = data.page_count || 1;
                updatePageNav();
                renderOverlayContent();
                applyAutoPlacement(data);
            })
            .catch(function (err) {
                if (reqId !== placementRequestId) return;
                if (autoBadge) autoBadge.style.display = 'none';
                showToast(err.message || 'Could not detect stamp placement.', 'error');
            });
        }, 250);
    }

    function applyAutoPlacement(data) {
        if (!stampOverlay || !data) return;

        stampOverlay.style.display = 'block';
        stampOverlay.className = 'st-stamp-overlay pos-auto';
        stampOverlay.style.left = data.x_pct + '%';
        stampOverlay.style.top = data.y_pct + '%';
        stampOverlay.style.width = data.width_pct + '%';

        if (autoBadge) autoBadge.style.display = 'inline-flex';
    }

    // Show fallback on iframe error
    pdfPreview.addEventListener('error', function () {
        previewFallback.style.display = 'flex';
        pdfPreview.style.display      = 'none';
    });

    // ═══════════════════════════════════════════
    // STAMP TYPE
    // ═══════════════════════════════════════════

    typePills.addEventListener('click', function (e) {
        const pill = e.target.closest('.st-type-pill');
        if (!pill) return;

        document.querySelectorAll('.st-type-pill').forEach(p => p.classList.remove('selected'));
        pill.classList.add('selected');

        state.stampType = pill.dataset.type;

        certifiedFields.style.display = (state.stampType === 'certified_true_copy') ? 'block' : 'none';

        updateOverlay();
        checkReady();
    });

    // ═══════════════════════════════════════════
    // CERTIFIED FIELDS
    // ═══════════════════════════════════════════

    certifiedByInp.addEventListener('input', function () {
        state.certBy = this.value;
        updateOverlay();
        checkReady();
    });

    designationInp.addEventListener('input', function () {
        state.desig = this.value;
        updateOverlay();
        checkReady();
    });

    // ═══════════════════════════════════════════
    // POSITION
    // ═══════════════════════════════════════════

    positionMap.addEventListener('click', function (e) {
        const dot = e.target.closest('.st-pos-dot');
        if (!dot) return;

        document.querySelectorAll('.st-pos-dot').forEach(d => d.classList.remove('active'));
        dot.classList.add('active');

        state.position = dot.dataset.pos;

        var labels = {
            'top-left': 'Top Left',
            'top-right': 'Top Right',
            'center': 'Center',
            'bottom-left': 'Bottom Left',
            'bottom-right': 'Bottom Right',
        };
        positionLabel.textContent = labels[state.position] || state.position;

        updateOverlay();
    });

    // ═══════════════════════════════════════════
    // ALL PAGES
    // ═══════════════════════════════════════════

    stampAllPages.addEventListener('change', function () {
        state.allPages = this.checked;
        updateOverlay();
    });

    // ═══════════════════════════════════════════
    // UPDATE STAMP OVERLAY
    // ═══════════════════════════════════════════

    var STAMP_STYLES = {
        reference: { title: 'REFERENCE', color: '#b03030', sub: '', image: true },
    };

    function updateOverlay() {
        if (!state.stampType) {
            stampOverlay.style.display = 'none';
            if (autoBadge) autoBadge.style.display = 'none';
            return;
        }

        renderOverlayContent();

        if (state.position === 'auto') {
            fetchStampPlacement();
            return;
        }

        if (autoBadge) autoBadge.style.display = 'none';
        stampOverlay.style.display = 'block';
        stampOverlay.className = 'st-stamp-overlay pos-' + state.position;
        stampOverlay.style.left = '';
        stampOverlay.style.top = '';
        stampOverlay.style.right = '';
        stampOverlay.style.bottom = '';
        stampOverlay.style.width = '';
        stampOverlay.style.transform = '';
    }

    function renderOverlayContent() {
        if (!state.stampType) return;

        var cfg = STAMP_STYLES[state.stampType] || STAMP_STYLES.reference;
        var art = document.getElementById('overlayStampArt');
        var fallback = document.getElementById('overlayStampFallback');

        if (art) {
            art.style.display = 'block';
        }
        if (fallback) {
            fallback.style.display = 'none';
        }

        if (overlayTitle) {
            overlayTitle.textContent = cfg.title;
            overlayTitle.style.color = cfg.color;
        }
        if (overlayDivider) overlayDivider.style.display = 'none';
        if (overlayFields) overlayFields.style.display = 'none';
        if (overlayDate) overlayDate.style.display = 'none';
        if (overlaySub) overlaySub.style.display = 'none';

        overlayPages.textContent = state.allPages ? 'All pages' : 'First page only';

        if (state.position === 'auto' && state.placement) {
            applyAutoPlacement(state.placement);
        }
    }

    // ═══════════════════════════════════════════
    // CHECK IF READY
    // ═══════════════════════════════════════════

    function checkReady() {
        var ready = !!state.stampType;

        if (state.stampType === 'certified_true_copy') {
            ready = ready && state.certBy.trim() !== '' && state.desig.trim() !== '';
        }

        downloadBtn.disabled = !ready;
        applyBtn.disabled    = !ready;
        if (restoreBtn) {
            restoreBtn.disabled = !(state.selectedFile && state.selectedFile.stamped);
        }
    }

    // ═══════════════════════════════════════════
    // CLOSE MODAL
    // ═══════════════════════════════════════════

    function hideStampModal() {
        stampModal.style.display = 'none';
        pdfPreview.src = '';
        stampOverlay.style.display = 'none';
    }

    closeModal.addEventListener('click', hideStampModal);
    cancelBtn.addEventListener('click', hideStampModal);
    stampModal.addEventListener('click', function (e) {
        if (e.target === stampModal) hideStampModal();
    });

    // ═══════════════════════════════════════════
    // REFRESH PREVIEW
    // ═══════════════════════════════════════════

    refreshPreview.addEventListener('click', function () {
        if (state.selectedFile) loadPreview();
    });

    // ═══════════════════════════════════════════
    // BUILD REQUEST PAYLOAD
    // ═══════════════════════════════════════════

    function buildPayload() {
        return {
            file_path:    state.selectedFile.path,
            file_key:     state.selectedFile.key,
            request_id:   state.requestId,
            doc_no:       state.docNo,
            doc_title:    state.docTitle,
            rev:          String(state.rev),
            stamp_type:   state.stampType,
            position:     state.position,
            all_pages:    state.allPages,
            certified_by: state.stampType === 'certified_true_copy' ? state.certBy : null,
            designation:  state.stampType === 'certified_true_copy' ? state.desig  : null,
        };
    }

    // ═══════════════════════════════════════════
    // DOWNLOAD (stamped copy, no overwrite)
    // ═══════════════════════════════════════════

    downloadBtn.addEventListener('click', function () {
        if (!state.selectedFile || !state.stampType) return;

        var originalHTML = downloadBtn.innerHTML;
        downloadBtn.disabled  = true;
        downloadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Preparing...</span>';

        fetch('/dcs/stamp/download', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/pdf',
            },
            body: JSON.stringify(buildPayload()),
        })
        .then(function (res) {
            if (!res.ok) throw new Error('Download failed');
            return res.blob();
        })
        .then(function (blob) {
            var url = URL.createObjectURL(blob);
            var a   = document.createElement('a');
            a.href     = url;
            a.download = '[' + state.stampType.toUpperCase().replace(/_/g, ' ') + '] ' + state.docNo + '.pdf';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast('Downloaded stamped copy.', 'success');
        })
        .catch(function () {
            showToast('Download failed. Please try again.', 'error');
        })
        .finally(function () {
            downloadBtn.innerHTML = originalHTML;
            checkReady();
        });
    });

    // ═══════════════════════════════════════════
    // APPLY (permanent — confirm first)
    // ═══════════════════════════════════════════

    applyBtn.addEventListener('click', function () {
        if (!state.selectedFile || !state.stampType) return;

        var cfg = STAMP_STYLES[state.stampType] || {};
        confirmLabel.textContent = cfg.title || state.stampType;
        confirmModal.style.display = 'flex';
    });

    confirmCancel.addEventListener('click', function () {
        confirmModal.style.display = 'none';
    });

    confirmModal.addEventListener('click', function (e) {
        if (e.target === confirmModal) confirmModal.style.display = 'none';
    });

    confirmApply.addEventListener('click', function () {
        if (!state.selectedFile || !state.stampType) return;

        var originalHTML = confirmApply.innerHTML;
        confirmApply.disabled  = true;
        confirmApply.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Applying...';

        fetch('/dcs/stamp/apply', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(buildPayload()),
        })
        .then(function (res) {
            return res.json().catch(function () {
                var msg = 'Stamping failed (server error).';
                if (res.status === 419) {
                    msg = 'Session expired — refresh the page and try again.';
                } else if (res.status === 504) {
                    msg = 'Stamping timed out. Large PDFs (400+ pages) can take several minutes — please wait and try again.';
                }
                throw new Error(msg);
            }).then(function (data) {
                if (!res.ok) throw new Error(data.message || 'Failed to apply stamp');
                return data;
            });
        })
        .then(function (data) {
            showToast('Stamp applied successfully!', 'success');
            confirmModal.style.display = 'none';
            if (state.selectedFile) {
                state.selectedFile.stamped    = true;
                state.selectedFile.stamp_type   = state.stampType;
            }
            loadPreview(Date.now());
            setTimeout(function () { location.reload(); }, 1200);
        })
        .catch(function (err) {
            showToast(err.message || 'Failed to apply stamp.', 'error');
        })
        .finally(function () {
            confirmApply.innerHTML = originalHTML;
            confirmApply.disabled  = false;
        });
    });

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
                return res.json().then(function (data) {
                    if (!res.ok) throw new Error(data.message || 'Failed to restore original');
                    return data;
                });
            })
            .then(function () {
                showToast('Original file restored.', 'success');
                restoreModal.style.display = 'none';
                setTimeout(function () { location.reload(); }, 800);
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

    // ═══════════════════════════════════════════
    // KEYBOARD: ESC to close
    // ═══════════════════════════════════════════

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (restoreModal && restoreModal.style.display === 'flex') {
            restoreModal.style.display = 'none';
        } else if (confirmModal.style.display === 'flex') {
            confirmModal.style.display = 'none';
        } else if (stampModal.style.display === 'flex') {
            hideStampModal();
        }
    });

    // ═══════════════════════════════════════════
    // TOAST
    // ═══════════════════════════════════════════

    function showToast(message, type) {
        if (window.dcsShowToast) {
            window.dcsShowToast(message, type || 'success');
        }
    }

});
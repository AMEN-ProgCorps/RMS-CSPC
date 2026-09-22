<script>
(function () {
    const form = document.getElementById('ofiDcnForm');
    const input = document.getElementById('dcnDocumentNo');
    const titleInput = document.getElementById('dcnDocumentTitle');
    const results = document.getElementById('dcnDocNoResults');
    const confirmed = document.getElementById('dcnDocNoConfirmed');
    const chipRow = document.getElementById('dcnDocNoChip');
    const chipLabel = document.getElementById('dcnDocNoChipLabel');
    const clearBtn = document.getElementById('dcnDocNoClear');
    const useModal = document.getElementById('dcnDocNoUseModal');
    const useNo = document.getElementById('dcnDocNoUseNo');
    const useTitleText = document.getElementById('dcnDocNoUseTitleText');
    const useRev = document.getElementById('dcnDocNoUseRev');
    const useType = document.getElementById('dcnDocNoUseType');
    const useConfirmBtn = document.getElementById('dcnDocNoUseConfirm');
    const useCancelBtn = document.getElementById('dcnDocNoUseCancel');
    const useCloseBtn = document.getElementById('dcnDocNoUseClose');
    if (!form || !input || !results || !confirmed) return;

    const searchUrl = @json(url('/dcs/api/office/revisable-documents'));
    let debounceTimer = null;
    let lastQuery = '';
    let activeItems = [];
    let pendingItem = null;
    let previousFocus = null;

    function setConfirmed(on, meta) {
        confirmed.value = on ? '1' : '';
        if (!chipRow || !chipLabel) return;
        if (on && meta) {
            const rev = meta.revise_no != null ? ' (Rev ' + meta.revise_no + ')' : '';
            const titlePart = meta.doc_title ? ' · ' + meta.doc_title : '';
            chipLabel.textContent = 'Selected registered document — ' + (meta.doc_no || '') + titlePart + rev;
            chipRow.hidden = false;
        } else if (on) {
            chipRow.hidden = false;
        } else {
            chipRow.hidden = true;
            chipLabel.textContent = 'Selected registered document';
        }
    }

    function hideResults() {
        results.style.display = 'none';
        results.innerHTML = '';
        input.setAttribute('aria-expanded', 'false');
        activeItems = [];
    }

    function clearSelection() {
        setConfirmed(false);
        input.value = '';
        if (titleInput) titleInput.value = '';
        input.readOnly = false;
        input.focus();
        hideResults();
    }

    function commitSelection(item) {
        const docNo = String(item.doc_no || '').trim();
        const title = String(item.doc_title || '').trim();
        const rev = item.revise_no != null ? item.revise_no : 0;
        input.value = docNo;
        if (titleInput) {
            titleInput.value = title;
            titleInput.dataset.autofilled = '1';
        }
        setConfirmed(true, { doc_no: docNo, doc_title: title, revise_no: rev });
        hideResults();
    }

    function openUseModal(item) {
        pendingItem = item;
        if (!useModal) {
            // Fallback if modal markup is missing
            if (window.confirm(
                'Use ' + String(item.doc_no || '').trim() +
                ' — ' + (String(item.doc_title || '').trim() || '(no title)') +
                ' (Rev ' + (item.revise_no != null ? item.revise_no : 0) + ')?'
            )) {
                commitSelection(item);
            } else {
                clearSelection();
            }
            return;
        }
        previousFocus = document.activeElement;
        if (useNo) useNo.textContent = String(item.doc_no || '').trim() || '—';
        if (useTitleText) useTitleText.textContent = String(item.doc_title || '').trim() || '(no title)';
        if (useRev) useRev.textContent = 'Rev ' + (item.revise_no != null ? item.revise_no : 0);
        if (useType) useType.textContent = String(item.doc_type || 'Document').trim() || 'Document';
        // Escape .ofi-page stacking context so overlay covers header + sidebar
        if (useModal.parentElement !== document.body) {
            document.body.appendChild(useModal);
        }
        useModal.hidden = false;
        useModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ofi-docno-use-open');
        hideResults();
        if (useConfirmBtn) useConfirmBtn.focus();
    }

    function closeUseModal(accepted) {
        const item = pendingItem;
        pendingItem = null;
        if (useModal) {
            useModal.hidden = true;
            useModal.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('ofi-docno-use-open');
        if (accepted && item) {
            commitSelection(item);
        } else if (!accepted) {
            clearSelection();
        }
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
        previousFocus = null;
    }

    function applySelection(item) {
        openUseModal(item);
    }

    function renderResults(items) {
        activeItems = items || [];
        results.innerHTML = '';
        if (!activeItems.length) {
            const empty = document.createElement('div');
            empty.className = 'ofi-docno-empty';
            empty.textContent = 'No revisable registered documents found.';
            results.appendChild(empty);
            results.style.display = 'block';
            input.setAttribute('aria-expanded', 'true');
            return;
        }
        activeItems.forEach(function (item, idx) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ofi-docno-option';
            btn.setAttribute('role', 'option');
            btn.dataset.index = String(idx);
            const rev = item.revise_no != null ? item.revise_no : 0;
            btn.innerHTML =
                '<span class="ofi-docno-opt-main">' + escapeHtml(item.doc_no || '') +
                ' · ' + escapeHtml(item.doc_title || '') + '</span>' +
                '<span class="ofi-docno-opt-meta">Rev ' + escapeHtml(String(rev)) +
                ' · ' + escapeHtml(item.doc_type || 'Document') + '</span>';
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                applySelection(item);
            });
            results.appendChild(btn);
        });
        results.style.display = 'block';
        input.setAttribute('aria-expanded', 'true');
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function fetchSuggestions(q) {
        lastQuery = q;
        try {
            const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                hideResults();
                return;
            }
            const data = await res.json();
            if (q !== lastQuery) return;
            renderResults(Array.isArray(data) ? data : []);
        } catch (err) {
            hideResults();
        }
    }

    input.addEventListener('input', function () {
        setConfirmed(false);
        const q = input.value.trim();
        if (debounceTimer) clearTimeout(debounceTimer);
        if (q.length < 1) {
            hideResults();
            return;
        }
        debounceTimer = setTimeout(function () {
            fetchSuggestions(q);
        }, 280);
    });

    input.addEventListener('blur', function () {
        setTimeout(function () {
            if (useModal && !useModal.hidden) return;
            hideResults();
        }, 180);
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            clearSelection();
        });
    }

    if (useConfirmBtn) {
        useConfirmBtn.addEventListener('click', function () {
            closeUseModal(true);
        });
    }
    if (useCancelBtn) {
        useCancelBtn.addEventListener('click', function () {
            closeUseModal(false);
        });
    }
    if (useCloseBtn) {
        useCloseBtn.addEventListener('click', function () {
            closeUseModal(false);
        });
    }
    if (useModal) {
        useModal.addEventListener('click', function (e) {
            if (e.target === useModal) closeUseModal(false);
        });
    }
    document.addEventListener('keydown', function (e) {
        if (!useModal || useModal.hidden) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            closeUseModal(false);
        }
    });

    if (titleInput) {
        titleInput.addEventListener('input', function () {
            titleInput.dataset.autofilled = '0';
        });
        if (titleInput.value) {
            titleInput.dataset.autofilled = confirmed.value === '1' ? '1' : '0';
        }
    }

    // Restore confirmed state when old input / edit has a doc no.
    if (confirmed.value === '1' && input.value.trim() !== '') {
        setConfirmed(true, {
            doc_no: input.value.trim(),
            doc_title: titleInput ? titleInput.value.trim() : '',
            revise_no: null,
        });
    }

    form.addEventListener('submit', function (e) {
        if (confirmed.value !== '1' || !input.value.trim()) {
            e.preventDefault();
            alert('Select and confirm an existing registered Document No. before saving.');
            input.focus();
            return;
        }
    });
})();
</script>

/**
 * Suggest next DRF/DCN and Document No. Confirm insert-and-shift when a number is taken.
 */
(function () {
    'use strict';

    function excludeRequestId() {
        const hidden = document.getElementById('requestId');
        const fromHidden = parseInt(hidden && hidden.value ? hidden.value : '0', 10) || 0;
        const fromDraft = parseInt(window.__draftRequestId || '0', 10) || 0;
        return fromHidden || fromDraft;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setHint(el, html) {
        if (!el) return;
        if (!html) {
            el.innerHTML = '';
            el.style.display = 'none';
            return;
        }
        el.style.display = 'block';
        el.innerHTML = html;
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        return res.json();
    }

    function hasDateValue(date) {
        return String(date || '').trim() !== '';
    }

    async function suggestFormNo(kind, date, inputId, hintId) {
        const input = document.getElementById(inputId);
        const hint = document.getElementById(hintId);
        if (!input || !hint) return;
        if (!hasDateValue(date)) {
            hint.dataset.suggested = '';
            setHint(hint, '');
            return;
        }
        const url = '/dcs/register/suggest-form-no?kind=' + encodeURIComponent(kind)
            + '&date=' + encodeURIComponent(date)
            + '&exclude_request_id=' + encodeURIComponent(String(excludeRequestId()));
        try {
            const data = await fetchJson(url);
            const suggested = String(data.suggested || '').trim();
            if (!suggested) {
                setHint(hint, '');
                return;
            }
            const label = kind === 'dcn' ? 'DCN' : 'DRF';
            const when = kind === 'dcn' && data.month
                ? escapeHtml(String(data.year)) + '-' + String(data.month).padStart(2, '0')
                : escapeHtml(String(data.year || ''));
            hint.dataset.suggested = suggested;
            setHint(
                hint,
                'Suggested next ' + label + ' no. (' + when + '): <strong>' + escapeHtml(suggested)
                    + '</strong> <button type="button" data-use-form-no="' + escapeHtml(suggested) + '">Use</button>'
            );
            hint.querySelector('[data-use-form-no]')?.addEventListener('click', function () {
                input.value = suggested;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            syncFormSuggestVisibility(input, hint);
        } catch (_) {}
    }

    function syncFormSuggestVisibility(input, hint) {
        if (!input || !hint) return;
        const suggested = String(hint.dataset.suggested || '').trim();
        if (!suggested || !hint.innerHTML) {
            hint.style.display = 'none';
            return;
        }
        const used = String(input.value || '').trim().toLowerCase() === suggested.toLowerCase();
        hint.style.display = used ? 'none' : 'block';
    }

    function bindFormSuggest() {
        const bindOne = function (dateEl, numberEl, kind, numberId, hintId) {
            if (!dateEl) return;
            const run = function () {
                void suggestFormNo(kind, dateEl.value, numberId, hintId);
            };
            dateEl.addEventListener('change', run);
            dateEl.addEventListener('input', run);
            if (numberEl) {
                numberEl.addEventListener('input', function () {
                    syncFormSuggestVisibility(numberEl, document.getElementById(hintId));
                });
            }
            if (hasDateValue(dateEl.value)) {
                run();
            } else {
                const hint = document.getElementById(hintId);
                if (hint) {
                    hint.dataset.suggested = '';
                    setHint(hint, '');
                }
            }
        };

        bindOne(document.getElementById('drfDate'), document.getElementById('drfNo'), 'drf', 'drfNo', 'drfNoSuggestHint');
        bindOne(document.getElementById('noticeDate'), document.getElementById('dcnNumber'), 'dcn', 'dcnNumber', 'dcnNoSuggestHint');
    }

    async function suggestDocNo() {
        const input = document.getElementById('masterlistDocNo');
        const hint = document.getElementById('docNoSuggestHint');
        if (!input || !hint) return;
        const typed = String(input.value || '').trim();
        const docTypeId = document.getElementById('docType')?.value || '';
        const subTypeId = document.getElementById('subType')?.value || '';
        const syllabiLike = (typeof window.isSyllabiLikeSubType === 'function' && subTypeId && window.isSyllabiLikeSubType(subTypeId))
            || !!window.__isSyllabiMode
            || /^CSPC-F-COL-1[36]$/i.test(typed);
        if (typed.length < 6 || !docTypeId || syllabiLike) {
            hint.dataset.suggested = '';
            window.__suggestedDocNo = '';
            setHint(hint, '');
            return;
        }
        const url = '/dcs/register/suggest-docno?doc_no=' + encodeURIComponent(typed)
            + '&doc_type_id=' + encodeURIComponent(docTypeId)
            + (subTypeId ? '&sub_type_id=' + encodeURIComponent(subTypeId) : '')
            + '&exclude_request_id=' + encodeURIComponent(String(excludeRequestId()));
        try {
            const data = await fetchJson(url);
            const suggested = String(data.suggested || '').trim();
            if (!suggested) {
                hint.dataset.suggested = '';
                window.__suggestedDocNo = '';
                setHint(hint, '');
                return;
            }
            window.__suggestedDocNo = suggested;
            hint.dataset.suggested = suggested;
            setHint(
                hint,
                'Suggested next Document no.: <strong>' + escapeHtml(suggested)
                    + '</strong> <button type="button" data-use-form-no="' + escapeHtml(suggested) + '">Use</button>'
            );
            hint.querySelector('[data-use-form-no]')?.addEventListener('click', function () {
                input.value = suggested;
                clearInsertShift();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            syncFormSuggestVisibility(input, hint);
        } catch (_) {}
    }

    let docNoTimer = null;
    function scheduleDocNoSuggest() {
        if (docNoTimer) clearTimeout(docNoTimer);
        docNoTimer = setTimeout(function () { void suggestDocNo(); }, 320);
    }

    window.refreshDocNoSuggestion = function () {
        const hint = document.getElementById('docNoSuggestHint');
        window.__suggestedDocNo = '';
        if (hint) {
            hint.dataset.suggested = '';
            setHint(hint, '');
        }
        scheduleDocNoSuggest();
    };

    function clearInsertShift() {
        const confirmed = document.getElementById('insertShiftConfirmed');
        const docNo = document.getElementById('insertShiftDocNo');
        const letters = document.getElementById('insertShiftRenameLetters');
        const allowDup = document.getElementById('allowDuplicateDocNo');
        if (confirmed) confirmed.value = '0';
        if (docNo) docNo.value = '';
        if (letters) letters.value = '0';
        if (allowDup) allowDup.value = '0';
    }

    function currentDocNo() {
        return String(document.getElementById('masterlistDocNo')?.value || '').trim();
    }

    function mountInsertModal() {
        const modal = document.getElementById('docNoInsertModal');
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
        return modal;
    }

    function closeInsertModal() {
        const modal = mountInsertModal();
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        const preview = document.getElementById('docNoInsertPreview');
        if (preview) preview.hidden = true;
    }

    function openInsertModal() {
        const modal = mountInsertModal();
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    window.openDocNoInsertModal = function () {
        const docNo = currentDocNo();
        const lead = document.getElementById('docNoInsertLead');
        if (lead) {
            lead.textContent = docNo + ' is already registered. What should happen?';
        }
        const nextLabel = document.getElementById('docNoInsertNextLabel');
        if (nextLabel) {
            nextLabel.textContent = window.__suggestedDocNo
                ? 'Use ' + window.__suggestedDocNo + '.'
                : 'Use the next number in this group.';
        }
        const preview = document.getElementById('docNoInsertPreview');
        if (preview) preview.hidden = true;
        void suggestDocNo();
        openInsertModal();
    };

    function switchToRevised() {
        const sel = document.getElementById('versionType');
        const docNo = currentDocNo();
        const match = [...(sel ? sel.options : [])].find((o) => /revis/i.test(o.text || ''));
        if (sel && match && match.value) {
            sel.value = match.value;
            sel.dataset.lastValid = match.value;
            document.querySelectorAll('input[type="hidden"][name="version_id"]').forEach(function (el) {
                el.value = match.value;
            });
        }
        const modeEl = document.getElementById('registrationMode');
        if (modeEl) modeEl.value = 'revised';
        if (typeof window.updateRegistrationMode === 'function') {
            window.updateRegistrationMode();
        }
        const versionId = sel && sel.value;
        const byVersion = (window.__registerCatalog && window.__registerCatalog.checklistsByVersion) || {};
        if (typeof window.renderChecklists === 'function' && versionId) {
            window.renderChecklists(byVersion[String(versionId)] || [], false);
        }
        if (docNo) {
            const input = document.getElementById('masterlistDocNo');
            if (input) input.value = docNo;
            if (typeof window.runDocNoLookup === 'function') {
                window.runDocNoLookup(
                    docNo,
                    document.getElementById('docNoHint'),
                    document.getElementById('masterlistRevisionNo')
                );
            }
        }
    }

    async function loadShiftPreview() {
        const preview = document.getElementById('docNoInsertPreview');
        const body = document.getElementById('docNoInsertPreviewBody');
        const err = document.getElementById('docNoInsertPreviewError');
        if (!preview || !body) return;
        preview.hidden = false;
        body.innerHTML = '<tr><td colspan="3">Loading…</td></tr>';
        if (err) {
            err.hidden = true;
            err.textContent = '';
        }
        const docNo = currentDocNo();
        const docTypeId = document.getElementById('docType')?.value || '';
        const subTypeId = document.getElementById('subType')?.value || '';
        const rename = document.getElementById('docNoInsertRenameLetters')?.checked ? '1' : '0';
        const url = '/dcs/register/preview-docno-shift?doc_no=' + encodeURIComponent(docNo)
            + '&doc_type_id=' + encodeURIComponent(docTypeId)
            + (subTypeId ? '&sub_type_id=' + encodeURIComponent(subTypeId) : '')
            + '&rename_letters=' + rename
            + '&exclude_request_id=' + encodeURIComponent(String(excludeRequestId()));
        try {
            const data = await fetchJson(url);
            if (!data.ok) {
                body.innerHTML = '';
                if (err) {
                    err.hidden = false;
                    err.textContent = data.error || 'Could not build a shift preview.';
                }
                return;
            }
            body.innerHTML = (data.shifts || []).map(function (row) {
                return '<tr><td>' + escapeHtml(row.from) + '</td><td>' + escapeHtml(row.to)
                    + '</td><td>' + escapeHtml(row.title || '') + '</td></tr>';
            }).join('') || '<tr><td colspan="3">No rows to rename.</td></tr>';
        } catch (_) {
            body.innerHTML = '';
            if (err) {
                err.hidden = false;
                err.textContent = 'Could not load the preview.';
            }
        }
    }

    function confirmShift() {
        const docNo = currentDocNo();
        const confirmed = document.getElementById('insertShiftConfirmed');
        const stored = document.getElementById('insertShiftDocNo');
        const letters = document.getElementById('insertShiftRenameLetters');
        if (confirmed) confirmed.value = '1';
        if (stored) stored.value = docNo;
        if (letters) {
            letters.value = document.getElementById('docNoInsertRenameLetters')?.checked ? '1' : '0';
        }
        const revField = document.getElementById('masterlistRevisionNo');
        if (revField) {
            revField.value = '0';
            revField.dataset.userEdited = 'true';
            revField.readOnly = false;
            revField.style.background = '';
            revField.style.borderColor = '';
            revField.classList.remove('reg-input-invalid');
        }
        const revHint = document.getElementById('revNoHint');
        if (revHint) {
            revHint.innerHTML = '<i class="fa-solid fa-circle-check"></i> New document at Rev 0. Existing numbers in this group will shift when you save.';
            revHint.style.color = '#16a34a';
            revHint.dataset.valid = 'insert';
        }
        if (typeof window.markDocNoInsertReady === 'function') {
            window.markDocNoInsertReady();
        } else if (typeof window.setSaveEnabled === 'function') {
            window.setSaveEnabled(true);
        }
        const hint = document.getElementById('docNoHint');
        if (hint) {
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Insert confirmed. Later numbers in this group will shift when you save.';
            hint.style.color = '#16a34a';
            hint.dataset.valid = 'insert';
        }
        closeInsertModal();
        if (typeof window.scheduleRevNoCheck === 'function') {
            window.scheduleRevNoCheck();
        }
    }

    function confirmKeepDuplicate() {
        clearInsertShift();
        const allowDup = document.getElementById('allowDuplicateDocNo');
        if (allowDup) allowDup.value = '1';
        const revField = document.getElementById('masterlistRevisionNo');
        if (revField) {
            revField.value = '0';
            revField.dataset.userEdited = 'true';
            revField.readOnly = true;
            revField.style.background = '#f1f5f9';
            revField.style.borderColor = '';
            revField.classList.remove('reg-input-invalid');
        }
        const revHint = document.getElementById('revNoHint');
        if (revHint) {
            revHint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Same number kept as a new registration (Rev 0). Other numbers will not move.';
            revHint.style.color = '#16a34a';
            revHint.dataset.valid = 'duplicate-copy';
        }
        if (typeof window.acceptDuplicateDocNoCopy === 'function') {
            window.acceptDuplicateDocNoCopy();
        } else if (typeof window.markDocNoInsertReady === 'function') {
            window.markDocNoInsertReady();
        } else if (typeof window.setSaveEnabled === 'function') {
            window.setSaveEnabled(true);
        }
        const hint = document.getElementById('docNoHint');
        if (hint) {
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> This number will be saved as another registration, not a revision.';
            hint.style.color = '#16a34a';
            hint.dataset.valid = 'duplicate-copy';
        }
        closeInsertModal();
    }

    function bindInsertModal() {
        mountInsertModal();
        document.addEventListener('click', function (e) {
            const modal = document.getElementById('docNoInsertModal');
            if (!modal || !modal.classList.contains('is-open')) {
                return;
            }
            if (e.target === modal || e.target.closest('#docNoInsertClose')) {
                closeInsertModal();
                return;
            }
            if (e.target.closest('#docNoInsertRevise')) {
                clearInsertShift();
                closeInsertModal();
                switchToRevised();
                return;
            }
            if (e.target.closest('#docNoInsertNext')) {
                clearInsertShift();
                const input = document.getElementById('masterlistDocNo');
                const next = window.__suggestedDocNo;
                if (input && next) {
                    input.value = next;
                    input.dispatchEvent(new Event('input'));
                }
                closeInsertModal();
                return;
            }
            if (e.target.closest('#docNoInsertShift')) {
                const lettersWrap = document.getElementById('docNoInsertLettersWrap');
                if (lettersWrap) lettersWrap.hidden = false;
                void loadShiftPreview();
                return;
            }
            if (e.target.closest('#docNoInsertConfirmShift')) {
                confirmShift();
                return;
            }
            if (e.target.closest('#docNoInsertKeep')) {
                confirmKeepDuplicate();
            }
        });
        document.addEventListener('change', function (e) {
            if (e.target && e.target.id === 'docNoInsertRenameLetters') {
                void loadShiftPreview();
            }
        });
    }

    function bindDocNo() {
        const input = document.getElementById('masterlistDocNo');
        if (!input) return;
        input.addEventListener('input', function () {
            clearInsertShift();
            syncFormSuggestVisibility(input, document.getElementById('docNoSuggestHint'));
            scheduleDocNoSuggest();
        });
    }

    function bind() {
        bindFormSuggest();
        bindDocNo();
        bindInsertModal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();

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

    function clearInsertShift(options) {
        const forceClearAllow = !!(options && options.forceClearAllow);
        const confirmed = document.getElementById('insertShiftConfirmed');
        const docNo = document.getElementById('insertShiftDocNo');
        const letters = document.getElementById('insertShiftRenameLetters');
        const requestIds = document.getElementById('insertShiftRequestIds');
        const allowDup = document.getElementById('allowDuplicateDocNo');
        if (confirmed) confirmed.value = '0';
        if (docNo) docNo.value = '';
        if (letters) letters.value = '0';
        if (requestIds) requestIds.value = '';
        window.__docNoInsertCandidates = [];
        window.__docNoInsertFreePool = [];
        const kept = String(window.__keptDuplicateDocNo || '').trim().toLowerCase();
        const stillKept = kept !== '' && currentDocNo().toLowerCase() === kept;
        if (allowDup && (forceClearAllow || !stillKept)) {
            allowDup.value = '0';
            if (forceClearAllow || !stillKept) {
                window.__keptDuplicateDocNo = '';
            }
        }
        const stackWith = document.getElementById('stackWithRequestId');
        const stacked = String(window.__stackedDocNo || '');
        if (stackWith && (!stacked || currentDocNo().toLowerCase() !== stacked.toLowerCase())) {
            stackWith.value = '';
        }
    }

    function currentDocNo() {
        return String(document.getElementById('masterlistDocNo')?.value || '').trim();
    }

    function hasStickyKeepDuplicate() {
        const allowDup = document.getElementById('allowDuplicateDocNo')?.value === '1';
        const kept = String(window.__keptDuplicateDocNo || '').trim().toLowerCase();
        return allowDup && kept !== '' && currentDocNo().toLowerCase() === kept;
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

    window.openDocNoInsertModal = function (data) {
        // User already chose Keep for this document number — do not reopen.
        if (hasStickyKeepDuplicate()) {
            return;
        }
        window.__docNoInsertLookup = data || {};
        const docNo = currentDocNo();
        const draftOnly = !!(data && (data.draft_only || (data.draft_exists && !data.exists)));
        const lead = document.getElementById('docNoInsertLead');
        if (lead) {
            if (draftOnly) {
                const title = String((data && (data.draft_title || data.latest_title)) || '').trim();
                lead.textContent = docNo + ' is already used in a draft'
                    + (title ? ' (“' + title + '”)' : '')
                    + '. What should happen?';
            } else {
                lead.textContent = docNo + ' is already registered. What should happen?';
            }
        }
        const titleEl = document.querySelector('#docNoInsertModal .reg-modal-header h3');
        if (titleEl) {
            titleEl.textContent = draftOnly
                ? 'Document number used in a draft'
                : 'Document number already exists';
        }
        const shiftBtn = document.getElementById('docNoInsertShift');
        if (shiftBtn) {
            shiftBtn.hidden = draftOnly;
            shiftBtn.style.display = draftOnly ? 'none' : '';
        }
        const keepBtn = document.getElementById('docNoInsertKeep');
        if (keepBtn) {
            const keepStrong = keepBtn.querySelector('strong');
            const keepSpan = keepBtn.querySelector('span');
            if (draftOnly) {
                if (keepStrong) keepStrong.textContent = 'Keep this number anyway';
                if (keepSpan) {
                    keepSpan.textContent = 'Save as another registration with the same number. The draft stays separate until you finish or delete it.';
                }
            } else {
                if (keepStrong) keepStrong.textContent = 'Register another copy with this number';
                if (keepSpan) {
                    keepSpan.textContent = 'Keep this number as its own registration. It will not become a revision, and other numbers stay where they are.';
                }
            }
        }
        const nextLabel = document.getElementById('docNoInsertNextLabel');
        if (nextLabel) {
            nextLabel.textContent = window.__suggestedDocNo
                ? 'Use ' + window.__suggestedDocNo + '.'
                : 'Use the next number in this group.';
        }
        showInsertPanel('choices');
        void suggestDocNo();
        openInsertModal();
    };

    function showInsertPanel(which) {
        const choices = document.getElementById('docNoInsertChoices');
        const stackPick = document.getElementById('docNoInsertStackPick');
        const preview = document.getElementById('docNoInsertPreview');
        const letters = document.getElementById('docNoInsertLettersWrap');
        if (choices) choices.hidden = which !== 'choices';
        if (stackPick) stackPick.hidden = which !== 'stack';
        if (preview) preview.hidden = which !== 'shift';
        // Exact-number free mode never cascades letter variants.
        if (letters) letters.hidden = true;
    }

    function selectedShiftRequestIds() {
        return [...document.querySelectorAll('#docNoInsertPreviewBody input[data-shift-request]:checked')]
            .map(function (el) { return String(el.getAttribute('data-shift-request') || ''); })
            .filter(Boolean);
    }

    function refreshShiftToColumns() {
        const pool = Array.isArray(window.__docNoInsertFreePool) ? window.__docNoInsertFreePool : [];
        const rows = [...document.querySelectorAll('#docNoInsertPreviewBody tr[data-request-id]')];
        let idx = 0;
        rows.forEach(function (tr) {
            const cb = tr.querySelector('input[data-shift-request]');
            const toCell = tr.querySelector('[data-to-cell]');
            if (!toCell) return;
            if (cb && cb.checked) {
                toCell.textContent = pool[idx] || '—';
                toCell.classList.remove('is-keep');
                idx += 1;
            } else {
                toCell.textContent = 'Keep';
                toCell.classList.add('is-keep');
            }
        });
        const confirmBtn = document.getElementById('docNoInsertConfirmShift');
        if (confirmBtn) {
            confirmBtn.disabled = selectedShiftRequestIds().length < 1;
        }
        const err = document.getElementById('docNoInsertPreviewError');
        if (err && selectedShiftRequestIds().length > 0) {
            err.hidden = true;
            err.textContent = '';
        }
    }

    function renderShiftCandidates(candidates) {
        const body = document.getElementById('docNoInsertPreviewBody');
        if (!body) return;
        const rows = Array.isArray(candidates) ? candidates : [];
        window.__docNoInsertCandidates = rows;
        window.__docNoInsertFreePool = rows.map(function (row) { return String(row.to || ''); }).filter(Boolean);
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="4">No registrations currently use this exact number.</td></tr>';
            const confirmBtn = document.getElementById('docNoInsertConfirmShift');
            if (confirmBtn) confirmBtn.disabled = true;
            return;
        }
        body.innerHTML = rows.map(function (row) {
            const id = Number(row.request_id || 0);
            const title = escapeHtml(row.title || 'Untitled');
            const from = escapeHtml(row.from || '');
            const to = escapeHtml(row.to || '—');
            const rev = Number(row.revise_no || 0);
            const copyBadge = row.is_copy
                ? '<span class="reg-insert-badge">Copy</span>'
                : '';
            const checked = row.selected ? ' checked' : '';
            return '<tr data-request-id="' + id + '">'
                + '<td class="col-check"><input type="checkbox" data-shift-request="' + id + '"' + checked + ' aria-label="Renumber ' + title + '"></td>'
                + '<td class="col-doc"><strong>' + title + '</strong>'
                + '<span class="reg-insert-meta">Rev ' + rev + copyBadge + '</span></td>'
                + '<td class="col-from"><code>' + from + '</code></td>'
                + '<td class="col-to" data-to-cell>' + to + '</td>'
                + '</tr>';
        }).join('');
        refreshShiftToColumns();
    }

    function checkedChecklistIds() {
        return [...document.querySelectorAll('#dynamicCheckboxes input[name="checklists[]"]')]
            .filter(function (el) { return el.checked || el.dataset.lastChecked === 'true'; })
            .map(function (el) { return String(el.value); });
    }

    function restoreChecklistIds(ids) {
        (ids || []).forEach(function (id) {
            const cb = document.querySelector('#dynamicCheckboxes input[name="checklists[]"][value="' + id + '"]');
            if (!cb) return;
            cb.checked = true;
            cb.dataset.lastChecked = 'true';
            if (typeof window.toggleSection === 'function') {
                window.toggleSection(parseInt(id, 10), true);
            }
        });
        if (typeof window.lockMasterlistChecklistOn === 'function') {
            window.lockMasterlistChecklistOn();
        }
    }

    function switchToNewRegistration() {
        const sel = document.getElementById('versionType');
        const currentText = String(sel && sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '').trim();
        const fromInput = document.getElementById('revisedFromDocNo');
        if (fromInput) fromInput.value = '';
        window.__revisedFromDocNo = null;
        const modeEl = document.getElementById('registrationMode');
        if (modeEl) modeEl.value = 'new';
        if (/^new\b/i.test(currentText)) {
            return;
        }
        const kept = checkedChecklistIds();
        const match = [...(sel ? sel.options : [])].find(function (o) {
            const text = String(o.text || '').trim();
            return /^new\b/i.test(text);
        });
        if (sel && match && match.value) {
            sel.value = match.value;
            sel.dataset.lastValid = match.value;
            document.querySelectorAll('input[type="hidden"][name="version_id"]').forEach(function (el) {
                el.value = match.value;
            });
        }
        if (typeof window.updateRegistrationMode === 'function') {
            window.updateRegistrationMode();
        }
        const versionId = sel && sel.value;
        const byVersion = (window.__registerCatalog && window.__registerCatalog.checklistsByVersion) || {};
        if (typeof window.renderChecklists === 'function' && versionId) {
            window.renderChecklists(byVersion[String(versionId)] || [], false);
        }
        restoreChecklistIds(kept);
    }

    function stackMeta(copy) {
        const status = String(copy.revision_status || 'latest');
        return [
            'Rev ' + (copy.revise_no || 0),
            status.charAt(0).toUpperCase() + status.slice(1),
            String(copy.effectivity_date || '').trim(),
            String(copy.originator_name || '').trim(),
        ].filter(Boolean);
    }

    function showStackPicker() {
        const lookup = window.__docNoInsertLookup || {};
        const copies = Array.isArray(lookup.copies) && lookup.copies.length
            ? lookup.copies
            : (lookup.existing_request_id ? [{
                request_id: lookup.existing_request_id,
                doc_title: lookup.latest_title || 'Existing registration',
                revise_no: lookup.latest_rev || 0,
                originator_name: lookup.latest_originator || '',
                revision_status: lookup.revision_status || 'latest',
                effectivity_date: lookup.latest_effectivity_date || '',
            }] : []);
        const list = document.getElementById('docNoInsertStackList');
        if (!list || !copies.length) {
            confirmStackWithExisting(lookup.existing_request_id || 0);
            return;
        }
        list.innerHTML = copies.map(function (copy) {
            const id = Number(copy.request_id || 0);
            const title = String(copy.doc_title || 'Untitled').replace(/[<>]/g, '');
            const chips = stackMeta(copy).map(function (bit) {
                return '<span>' + String(bit).replace(/[<>]/g, '') + '</span>';
            }).join('');
            return '<button type="button" class="reg-stack-card" data-stack-request="' + id + '">'
                + '<strong>' + title + '</strong>'
                + '<span class="reg-stack-meta">' + chips + '</span>'
                + '</button>';
        }).join('');
        showInsertPanel('stack');
    }

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
        showInsertPanel('shift');
        const titleEl = document.getElementById('docNoInsertPreviewTitle');
        const hintEl = document.getElementById('docNoInsertPreviewHint');
        const docNo = currentDocNo();
        if (titleEl) titleEl.textContent = 'Free ' + docNo;
        if (hintEl) {
            hintEl.textContent = 'Select the registration(s) you want to renumber. Nothing is moved until you check a row and confirm.';
        }
        body.innerHTML = '<tr><td colspan="4">Loading…</td></tr>';
        if (err) {
            err.hidden = true;
            err.textContent = '';
        }
        const confirmBtn = document.getElementById('docNoInsertConfirmShift');
        if (confirmBtn) confirmBtn.disabled = true;
        const docTypeId = document.getElementById('docType')?.value || '';
        const subTypeId = document.getElementById('subType')?.value || '';
        const effectivity = document.getElementById('masterlistEffectivityDate')?.value || '';
        const url = '/dcs/register/preview-docno-shift?doc_no=' + encodeURIComponent(docNo)
            + '&doc_type_id=' + encodeURIComponent(docTypeId)
            + (subTypeId ? '&sub_type_id=' + encodeURIComponent(subTypeId) : '')
            + '&effectivity_date=' + encodeURIComponent(effectivity)
            + '&exclude_request_id=' + encodeURIComponent(String(excludeRequestId()));
        try {
            const data = await fetchJson(url);
            if (!data.ok) {
                body.innerHTML = '';
                if (err) {
                    err.hidden = false;
                    err.textContent = data.error || 'Could not build a renumber preview.';
                }
                return;
            }
            const candidates = Array.isArray(data.candidates) && data.candidates.length
                ? data.candidates
                : (data.shifts || []).map(function (row) {
                    return {
                        request_id: row.request_id,
                        from: row.from,
                        to: row.to,
                        title: row.title,
                        revise_no: row.revise_no || 0,
                        is_copy: !!row.is_copy,
                        selected: false,
                    };
                });
            renderShiftCandidates(candidates);
            const dateWarn = document.getElementById('docNoInsertDateWarning');
            if (dateWarn) {
                dateWarn.hidden = true;
                dateWarn.textContent = '';
            }
        } catch (_) {
            body.innerHTML = '';
            if (err) {
                err.hidden = false;
                err.textContent = 'Could not load the preview.';
            }
        }
    }

    function confirmShift() {
        const ids = selectedShiftRequestIds();
        const err = document.getElementById('docNoInsertPreviewError');
        if (ids.length < 1) {
            if (err) {
                err.hidden = false;
                err.textContent = 'Select at least one document to renumber.';
            }
            return;
        }
        const docNo = currentDocNo();
        const confirmed = document.getElementById('insertShiftConfirmed');
        const stored = document.getElementById('insertShiftDocNo');
        const letters = document.getElementById('insertShiftRenameLetters');
        const requestIds = document.getElementById('insertShiftRequestIds');
        if (confirmed) confirmed.value = '1';
        if (stored) stored.value = docNo;
        if (letters) letters.value = '0';
        if (requestIds) requestIds.value = ids.join(',');
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
            revHint.innerHTML = '<i class="fa-solid fa-circle-check"></i> New document at Rev 0. Selected registration(s) on '
                + escapeHtml(docNo) + ' will be renumbered when you save.';
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
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Renumber confirmed for '
                + escapeHtml(docNo) + ' (' + ids.length + ' selected). Other document numbers stay put.';
            hint.style.color = '#16a34a';
            hint.dataset.valid = 'insert';
        }
        closeInsertModal();
        if (typeof window.scheduleRevNoCheck === 'function') {
            window.scheduleRevNoCheck();
        }
    }

    function confirmKeepDuplicate() {
        clearInsertShift({ forceClearAllow: true });
        const allowDup = document.getElementById('allowDuplicateDocNo');
        if (allowDup) allowDup.value = '1';
        window.__keptDuplicateDocNo = currentDocNo();
        const revField = document.getElementById('masterlistRevisionNo');
        if (revField) {
            revField.value = '0';
            revField.dataset.userEdited = 'true';
            revField.readOnly = false;
            revField.classList.remove('reg-rev-locked');
            revField.style.background = '';
            revField.style.borderColor = '';
            revField.classList.remove('reg-input-invalid');
        }
        const revHint = document.getElementById('revNoHint');
        if (revHint) {
            revHint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Same number kept as a new registration (suggested Rev 0). Other numbers will not move.';
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

    function confirmStackWithExisting(requestId) {
        switchToNewRegistration();
        confirmKeepDuplicate();
        const id = Number(requestId || 0);
        const stackWith = document.getElementById('stackWithRequestId');
        if (stackWith) stackWith.value = id > 0 ? String(id) : '';
        window.__stackedDocNo = currentDocNo();
        const hint = document.getElementById('docNoHint');
        if (hint) {
            hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> This copy stays a New document (Rev 0) and will stack with the registration you chose.';
            hint.style.color = '#16a34a';
            hint.dataset.valid = 'duplicate-copy';
        }
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
            if (e.target.closest('#docNoInsertNext')) {
                clearInsertShift({ forceClearAllow: true });
                window.__keptDuplicateDocNo = '';
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
                void loadShiftPreview();
                return;
            }
            if (e.target.closest('#docNoInsertStackBack') || e.target.closest('#docNoInsertShiftBack')) {
                showInsertPanel('choices');
                return;
            }
            if (e.target.closest('#docNoInsertConfirmShift')) {
                confirmShift();
                return;
            }
            if (e.target.closest('#docNoInsertKeep')) {
                const stackWith = document.getElementById('stackWithRequestId');
                if (stackWith) stackWith.value = '';
                confirmKeepDuplicate();
                return;
            }
            if (e.target.closest('#docNoInsertStack')) {
                showStackPicker();
                return;
            }
            const stackChoice = e.target.closest('[data-stack-request]');
            if (stackChoice) {
                confirmStackWithExisting(stackChoice.getAttribute('data-stack-request'));
            }
        });
        document.addEventListener('change', function (e) {
            if (e.target && e.target.id === 'docNoInsertRenameLetters') {
                void loadShiftPreview();
                return;
            }
            if (e.target && e.target.matches('#docNoInsertPreviewBody input[data-shift-request]')) {
                refreshShiftToColumns();
            }
        });
    }

    function bindDocNo() {
        const input = document.getElementById('masterlistDocNo');
        if (!input) return;
        input.addEventListener('input', function () {
            const stacked = String(window.__stackedDocNo || '');
            const typed = currentDocNo().toLowerCase();
            if (!stacked || typed !== stacked.toLowerCase()) {
                clearInsertShift();
                window.__stackedDocNo = '';
            }
            const kept = String(window.__keptDuplicateDocNo || '').trim().toLowerCase();
            if (kept && typed !== kept) {
                window.__keptDuplicateDocNo = '';
                const allowDup = document.getElementById('allowDuplicateDocNo');
                if (allowDup) allowDup.value = '0';
            }
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

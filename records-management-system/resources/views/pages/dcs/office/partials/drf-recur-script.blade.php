{{--
DCS file: Looks up the DRF recurring finder values (same API as DCN) and seeds title / originator / type / distribution.
--}}
<script>
(function () {
    const form = document.getElementById('ofiDrfForm');
    const finderNo = document.getElementById('drfFinderDocumentNo');
    const finderTitle = document.getElementById('drfFinderCurrentTitle');
    const finderOriginator = document.getElementById('drfFinderOriginator');
    const findBtn = document.getElementById('drfDocNoFind');
    const statusEl = document.getElementById('drfDocNoFindStatus');
    const suggestEl = document.getElementById('drfDocNoSuggestions');
    const storedNo = document.getElementById('drfDocumentNo');
    const confirmed = document.getElementById('drfDocNoConfirmed');
    const reviseInput = document.getElementById('drfFinderReviseNo');
    const typeInput = document.getElementById('drfFinderDocType');
    const masterlistIdInput = document.getElementById('drfFinderMasterlistId');
    const requestIdInput = document.getElementById('drfFinderRequestId');
    const selectedBox = document.getElementById('drfSelectedDocument');
    const reselectBtn = document.getElementById('drfDocNoReselect');
    const finderCard = form && form.querySelector('.ofi-drf-recur-finder');
    if (!form || !finderNo || !finderTitle || !finderOriginator || !storedNo || !confirmed) return;
    if (finderCard && !finderCard.hasAttribute('tabindex')) {
        finderCard.setAttribute('tabindex', '-1');
    }

    const searchUrl = @json(url('/dcs/api/office/revisable-documents'));
    let duplicateMessage = '';

    function setStatus(message, ok) {
        if (!statusEl) return;
        statusEl.hidden = false;
        statusEl.textContent = message;
        statusEl.dataset.state = ok ? 'ok' : 'bad';
    }

    function clearSuggestions() {
        if (!suggestEl) return;
        suggestEl.hidden = true;
        suggestEl.innerHTML = '';
    }

    function clearFieldSuggestions() {
        ['drfSuggestTitle', 'drfSuggestOriginator'].forEach(function (id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.hidden = true;
            el.innerHTML = '';
        });
    }

    function setFieldText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value || '—';
    }

    function lockFinder(locked) {
        [finderNo, finderTitle, finderOriginator].forEach(function (el) {
            el.readOnly = locked;
        });
        if (findBtn) findBtn.hidden = locked;
        if (finderCard) finderCard.classList.toggle('is-selected', locked);
        if (selectedBox) selectedBox.hidden = !locked;
    }

    function showSelected(data) {
        const docNo = (data.document_no || finderNo.value || '').trim();
        const title = (data.current_title || finderTitle.value || '').trim();
        const originator = (data.originator || finderOriginator.value || '').trim();
        const type = (data.doc_type || (typeInput && typeInput.value) || '').trim();
        const rev = data.revise_no != null && data.revise_no !== ''
            ? String(data.revise_no)
            : (reviseInput ? reviseInput.value : '');
        setFieldText('drfSelectedNo', docNo);
        setFieldText('drfSelectedTitle', title);
        setFieldText('drfSelectedOriginator', originator);
        setFieldText('drfSelectedType', type);
        setFieldText('drfSelectedRev', rev !== '' ? ('Rev ' + rev + ' · latest') : 'Latest');
        lockFinder(true);
    }

    let applyingMatch = false;
    let suggestTitle = '';
    let suggestOriginator = '';

    function showOfficeNotice(message) {
        let root = document.getElementById('ofiNotice');
        if (!root) {
            root = document.createElement('div');
            root.id = 'ofiNotice';
            root.className = 'ofi-notice';
            root.hidden = true;
            root.innerHTML = ''
                + '<div class="ofi-notice-card" role="alertdialog" aria-modal="true" aria-labelledby="ofiNoticeTitle">'
                + '<p class="ofi-notice-kicker">Already submitted</p>'
                + '<h2 id="ofiNoticeTitle">This document already has a request</h2>'
                + '<p id="ofiNoticeMessage"></p>'
                + '<button type="button" class="ofi-notice-ok" id="ofiNoticeOk">OK</button>'
                + '</div>';
            document.body.appendChild(root);
            root.addEventListener('click', function (event) {
                if (event.target === root) root.hidden = true;
            });
            root.querySelector('#ofiNoticeOk').addEventListener('click', function () {
                root.hidden = true;
            });
        }
        const text = root.querySelector('#ofiNoticeMessage');
        if (text) text.textContent = message;
        root.hidden = false;
        root.querySelector('#ofiNoticeOk')?.focus();
    }

    function showDuplicate(message) {
        duplicateMessage = message || '';
        form.dataset.openRequestMessage = duplicateMessage;
        const box = document.getElementById('drfSelectedDuplicate');
        if (!box) return;
        box.hidden = duplicateMessage === '';
        box.textContent = duplicateMessage;
    }

    function clearSeededFormFields() {
        const titleInput = document.getElementById('drfTitle');
        const originInput = document.getElementById('drfOriginatorName')
            || form.querySelector('input[name="originatorName"]');
        if (titleInput) {
            if (!titleInput.value.trim() || titleInput.value.trim() === suggestTitle) {
                titleInput.value = '';
            }
            titleInput.placeholder = 'Enter document title';
        }
        if (originInput) {
            if (!originInput.value.trim() || originInput.value.trim() === suggestOriginator) {
                originInput.value = '';
            }
            originInput.placeholder = 'Separate multiple originators with a comma';
        }
        clearDistributeOffices();
        unlockDocTypeKind();
    }

    function clearDistributeOffices() {
        const widget = window.__sourceWidgets && window.__sourceWidgets.distribute;
        if (widget && typeof widget.clear === 'function') {
            widget.clear();
            return;
        }
        form.querySelectorAll('input[name="distributeToOffice[]"]').forEach(function (el) {
            el.remove();
        });
        const countEl = form.querySelector('[data-ofi-selected-count="distribute"]');
        if (countEl) countEl.textContent = '0';
        const search = document.getElementById('drfDistributeSearch');
        if (search) search.value = '';
    }

    function clearMatch() {
        if (applyingMatch) return;
        showDuplicate('');
        confirmed.value = '';
        storedNo.value = '';
        if (reviseInput) reviseInput.value = '';
        if (typeInput) typeInput.value = '';
        if (masterlistIdInput) masterlistIdInput.value = '';
        if (requestIdInput) requestIdInput.value = '';
        clearSuggestions();
        clearFieldSuggestions();
        clearSeededFormFields();
        suggestTitle = '';
        suggestOriginator = '';
        lockFinder(false);
        if (statusEl && statusEl.dataset.state === 'ok') {
            statusEl.hidden = true;
            statusEl.textContent = '';
        }
    }


    function suggestionButton(label, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ofi-suggest-btn';
        btn.textContent = label;
        // Keep focus on the finder — a click would move focus into the button,
        // and removing it in clearSuggestions() jumps the caret to Distribute.
        btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            onClick();
        });
        return btn;
    }

    function hint(box, text) {
        if (!box) return;
        box.hidden = false;
        box.innerHTML = '';
        const label = document.createElement('p');
        label.className = 'ofi-suggest-label';
        label.textContent = text;
        box.appendChild(label);
    }

    function bindTabFill(input, read) {
        if (!input || input.dataset.tabFillBound === '1') return;
        input.dataset.tabFillBound = '1';
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || e.shiftKey || input.value.trim() !== '') return;
            const value = read();
            if (!value) return;
            e.preventDefault();
            input.value = value;
            const end = input.value.length;
            try { input.setSelectionRange(end, end); } catch (_) { /* date inputs */ }
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function ensureHidden(name, value) {
        let el = form.querySelector('input[type="hidden"][name="' + name + '"][data-drf-recur-lock="1"]');
        if (!el) {
            el = document.createElement('input');
            el.type = 'hidden';
            el.name = name;
            el.dataset.drfRecurLock = '1';
            form.appendChild(el);
        }
        el.value = value == null ? '' : String(value);
        return el;
    }

    function lockDocTypeKind(kind, docTypeId, subTypeId) {
        const value = kind === 'external' ? 'external' : 'internal';
        ensureHidden('docTypeKind', value);

        const radios = form.querySelectorAll('input[type="radio"][name="docTypeKind"]');
        radios.forEach(function (radio) {
            radio.checked = radio.value === value;
            radio.disabled = true;
        });
        // Rebuild parent list for this kind before selecting.
        radios.forEach(function (radio) {
            if (radio.value === value) {
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        const parentSelect = document.getElementById('drfDocTypeId');
        const subSelect = document.getElementById('drfSubTypeId');
        const parentId = docTypeId ? String(docTypeId) : '';
        const childId = subTypeId ? String(subTypeId) : '';

        if (parentSelect && parentSelect.tagName === 'SELECT') {
            if (parentId) parentSelect.value = parentId;
            parentSelect.disabled = true;
            parentSelect.removeAttribute('name');
            parentSelect.dispatchEvent(new Event('change', { bubbles: true }));
            ensureHidden('docTypeId', parentId);
            if (subSelect) {
                const applySub = function () {
                    if (childId && [].some.call(subSelect.options, function (o) { return o.value === childId; })) {
                        subSelect.value = childId;
                        subSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    subSelect.disabled = true;
                    subSelect.removeAttribute('name');
                    ensureHidden('subTypeId', subSelect.value || childId || '');
                };
                applySub();
                setTimeout(applySub, 0);
            }
        }

        const hintEl = document.getElementById('drfRecurDocTypeHint');
        if (hintEl) hintEl.hidden = false;
    }

    function unlockDocTypeKind() {
        const parentSelect = document.getElementById('drfDocTypeId');
        const subSelect = document.getElementById('drfSubTypeId');
        if (parentSelect && parentSelect.tagName === 'SELECT') {
            parentSelect.disabled = false;
            parentSelect.setAttribute('name', 'docTypeId');
        }
        if (subSelect && subSelect.tagName === 'SELECT') {
            subSelect.disabled = !parentSelect || !parentSelect.value;
            subSelect.setAttribute('name', 'subTypeId');
        }
        form.querySelectorAll('input[type="hidden"][data-drf-recur-lock="1"]').forEach(function (el) {
            el.remove();
        });
        const radios = form.querySelectorAll('input[type="radio"][name="docTypeKind"]');
        radios.forEach(function (radio) {
            radio.disabled = false;
        });
        const hintEl = document.getElementById('drfRecurDocTypeHint');
        if (hintEl) hintEl.hidden = true;
    }

    function seedDistributeOffices(ids) {
        const list = Array.isArray(ids)
            ? ids.map(Number).filter(function (id) { return id > 0; })
            : [];
        form.dataset.ofiSeedDistributeIds = list.join(',');
        const widget = window.__sourceWidgets && window.__sourceWidgets.distribute;
        if (!widget) return;
        if (typeof widget.seedQuiet === 'function') {
            widget.seedQuiet(list);
            if (typeof widget.syncHiddenInputs === 'function') {
                widget.syncHiddenInputs();
            }
            return;
        }
        if (typeof widget.clear === 'function') widget.clear();
        if (typeof widget.pick !== 'function') return;
        list.forEach(function (id) {
            widget.pick(id, { quiet: true });
        });
        if (typeof widget.syncHiddenInputs === 'function') {
            widget.syncHiddenInputs();
        }
    }

    function showFieldSuggestions(data) {
        const titleBox = document.getElementById('drfSuggestTitle');
        const titleInput = document.getElementById('drfTitle');
        const originBox = document.getElementById('drfSuggestOriginator');
        const originInput = document.getElementById('drfOriginatorName')
            || form.querySelector('input[name="originatorName"]');
        clearFieldSuggestions();
        suggestTitle = (data.current_title || '').trim();
        suggestOriginator = (data.originator || '').trim();

        if (titleInput) titleInput.placeholder = suggestTitle || 'Enter document title';
        if (originInput) originInput.placeholder = suggestOriginator || 'Separate multiple originators with a comma';
        if (titleBox && suggestTitle) hint(titleBox, 'Press Tab in the empty title to use the registered title.');
        if (originBox && suggestOriginator) hint(originBox, 'Press Tab in the empty originator to use the registered originator.');

        bindTabFill(titleInput, function () { return suggestTitle; });
        bindTabFill(originInput, function () { return suggestOriginator; });
    }

    function applyMatch(data) {
        applyingMatch = true;
        const scrollY = window.scrollY || window.pageYOffset || 0;
        const html = document.documentElement;
        const body = document.body;
        const prevHtmlOverflow = html.style.overflow;
        const prevBodyOverflow = body ? body.style.overflow : '';
        const distSearch = document.getElementById('drfDistributeSearch');
        const distWidget = document.getElementById('drfDistributeWidget');
        const prevDistTab = distSearch ? distSearch.getAttribute('tabindex') : null;
        if (distSearch) distSearch.setAttribute('tabindex', '-1');
        if (distWidget) distWidget.setAttribute('inert', '');
        html.style.overflow = 'hidden';
        if (body) body.style.overflow = 'hidden';
        const restoreScroll = function () {
            window.scrollTo(0, scrollY);
        };
        const releaseFreeze = function () {
            html.style.overflow = prevHtmlOverflow;
            if (body) body.style.overflow = prevBodyOverflow;
            if (distSearch) {
                if (prevDistTab === null) distSearch.removeAttribute('tabindex');
                else distSearch.setAttribute('tabindex', prevDistTab);
            }
            if (distWidget) distWidget.removeAttribute('inert');
            restoreScroll();
        };
        try {
            if (document.activeElement && typeof document.activeElement.blur === 'function') {
                document.activeElement.blur();
            }
            // Drop previous document’s seeded fields before applying the new match.
            const titleInput = document.getElementById('drfTitle');
            const originInput = document.getElementById('drfOriginatorName')
                || form.querySelector('input[name="originatorName"]');
            if (titleInput && (!titleInput.value.trim() || titleInput.value.trim() === suggestTitle)) {
                titleInput.value = '';
            }
            if (originInput && (!originInput.value.trim() || originInput.value.trim() === suggestOriginator)) {
                originInput.value = '';
            }
            clearDistributeOffices();
            unlockDocTypeKind();
            const canonicalNo = (data.document_no || finderNo.value || '').trim();
            confirmed.value = '1';
            storedNo.value = canonicalNo;
            if (reviseInput) reviseInput.value = data.revise_no != null && data.revise_no !== '' ? String(data.revise_no) : '';
            if (typeInput) typeInput.value = data.doc_type || '';
            if (masterlistIdInput) {
                masterlistIdInput.value = data.masterlist_id ? String(data.masterlist_id) : '';
            }
            if (requestIdInput) {
                requestIdInput.value = data.request_id ? String(data.request_id) : '';
            }
            if (data.document_no) finderNo.value = data.document_no;
            if (data.current_title) finderTitle.value = data.current_title;
            if (data.originator) finderOriginator.value = data.originator;
            clearSuggestions();
            showDuplicate(data.open_request_message ? String(data.open_request_message) : '');
            if (duplicateMessage) {
                setStatus(duplicateMessage, false);
            } else {
                setStatus('Document selected. Choose a different document if this is not the one to recur.', true);
            }
            showFieldSuggestions(data);
            if (data.doc_type_kind || data.doc_type_id || data.sub_type_id) {
                lockDocTypeKind(data.doc_type_kind, data.doc_type_id, data.sub_type_id);
            }
            showSelected(data);
            // Park focus on the finder card — never Distribute, never a removed suggestion btn.
            if (finderCard && typeof finderCard.focus === 'function') {
                finderCard.focus({ preventScroll: true });
            } else if (reselectBtn && typeof reselectBtn.focus === 'function') {
                reselectBtn.focus({ preventScroll: true });
            }
            restoreScroll();
            // Seed offices on the next frame so layout/focus settle first.
            const officeIds = data.distribute_office_ids || [];
            requestAnimationFrame(function () {
                seedDistributeOffices(officeIds);
                if (document.activeElement && document.activeElement.id === 'drfDistributeSearch') {
                    document.activeElement.blur();
                    if (finderCard) finderCard.focus({ preventScroll: true });
                }
                restoreScroll();
                if (duplicateMessage) {
                    showOfficeNotice(duplicateMessage);
                }
            });
        } finally {
            applyingMatch = false;
            restoreScroll();
            requestAnimationFrame(restoreScroll);
            setTimeout(restoreScroll, 50);
            setTimeout(restoreScroll, 150);
            setTimeout(releaseFreeze, 320);
        }
    }

    function showMatchList(matches) {
        if (!suggestEl) return;
        suggestEl.hidden = false;
        suggestEl.innerHTML = '';
        const label = document.createElement('p');
        label.className = 'ofi-suggest-label';
        label.textContent = matches.length === 1
            ? 'Select this document to continue:'
            : 'Select the document that matches your copy:';
        suggestEl.appendChild(label);
        matches.forEach(function (item) {
            const rev = item.revise_no != null && item.revise_no !== '' ? ('Rev ' + item.revise_no) : '';
            const type = (item.doc_type || '').trim();
            const bits = [item.document_no, item.current_title, item.originator, type, rev].filter(Boolean);
            suggestEl.appendChild(suggestionButton(bits.join(' · '), function () {
                applyMatch(item);
            }));
        });
    }

    function resetSelectionForNewSearch() {
        // Keep finder typed values; clear only the previous pick + seeded form fields.
        applyingMatch = false;
        showDuplicate('');
        confirmed.value = '';
        storedNo.value = '';
        if (reviseInput) reviseInput.value = '';
        if (typeInput) typeInput.value = '';
        if (masterlistIdInput) masterlistIdInput.value = '';
        if (requestIdInput) requestIdInput.value = '';
        clearSuggestions();
        clearFieldSuggestions();
        clearSeededFormFields();
        suggestTitle = '';
        suggestOriginator = '';
        lockFinder(false);
    }

    async function findDocument() {
        const docNo = finderNo.value.trim();
        const title = finderTitle.value.trim();
        const originator = finderOriginator.value.trim();
        if (!docNo || !title || !originator) {
            clearMatch();
            setStatus('Enter the document number, current title, and originator from your copy.', false);
            return;
        }
        if (findBtn) findBtn.disabled = true;
        const scrollY = window.scrollY || window.pageYOffset || 0;
        try {
            resetSelectionForNewSearch();
            const params = new URLSearchParams({
                documentNo: docNo,
                currentTitle: title,
                originator: originator,
            });
            const res = await fetch(searchUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = res.ok ? await res.json() : { found: false, matches: [] };
            const matches = Array.isArray(data.matches) && data.matches.length
                ? data.matches.slice()
                : (Array.isArray(data.suggestions) ? data.suggestions.slice() : []);
            // Exact single hit from older API shape — still list it; do not auto-select.
            if (!matches.length && data && data.found === true && data.document_no) {
                matches.push(data);
            }
            if (matches.length) {
                setStatus('Select a document from the list below.', true);
                showMatchList(matches);
            } else {
                setStatus('No document matched those details.', false);
            }
        } catch (err) {
            clearMatch();
            setStatus('Could not check the document. Try again.', false);
        } finally {
            if (findBtn) findBtn.disabled = false;
            // Restore scroll if anything below the finder shifted the viewport.
            window.scrollTo(0, scrollY);
        }
    }

    if (reselectBtn) {
        reselectBtn.addEventListener('click', function () {
            const scrollY = window.scrollY || window.pageYOffset || 0;
            clearMatch();
            // Always wipe form fields that came from the previous document.
            const titleInput = document.getElementById('drfTitle');
            const originInput = document.getElementById('drfOriginatorName')
                || form.querySelector('input[name="originatorName"]');
            if (titleInput) {
                titleInput.value = '';
                titleInput.placeholder = 'Enter document title';
            }
            if (originInput) {
                originInput.value = '';
                originInput.placeholder = 'Separate multiple originators with a comma';
            }
            setStatus('Enter the latest document number, title, and originator, then find it again.', false);
            finderNo.focus({ preventScroll: true });
            window.scrollTo(0, scrollY);
        });
    }
    if (confirmed.value === '1' && storedNo.value.trim() !== '') {
        showSelected({
            document_no: storedNo.value,
            current_title: finderTitle.value,
            originator: finderOriginator.value,
            doc_type: typeInput ? typeInput.value : '',
            revise_no: reviseInput ? reviseInput.value : '',
        });
    }

    if (findBtn) findBtn.addEventListener('click', findDocument);
    [finderNo, finderTitle, finderOriginator].forEach(function (el) {
        el.addEventListener('input', clearMatch);
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                findDocument();
            }
        });
    });
    document.addEventListener('submit', function (e) {
        if (e.target !== form) return;
        const submitter = e.submitter;
        if (submitter && submitter.hasAttribute('formnovalidate')) {
            return;
        }
        if (duplicateMessage) {
            e.preventDefault();
            e.stopPropagation();
            setStatus(duplicateMessage, false);
            showDuplicate(duplicateMessage);
            showOfficeNotice(duplicateMessage);
            return;
        }
        if (confirmed.value !== '1' || storedNo.value.trim() === '') {
            e.preventDefault();
            e.stopPropagation();
            setStatus('Find the registered document before saving.', false);
            finderNo.focus();
        }
    }, true);
})();
</script>

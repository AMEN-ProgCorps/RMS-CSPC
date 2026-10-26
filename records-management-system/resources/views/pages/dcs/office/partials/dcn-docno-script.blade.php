{{--
DCS file: Looks up the DCN finder values and fills the change form when the latest revision matches.
--}}
<script>
(function () {
    const form = document.getElementById('ofiDcnForm');
    const finderNo = document.getElementById('dcnFinderDocumentNo');
    const finderTitle = document.getElementById('dcnFinderCurrentTitle');
    const finderOriginator = document.getElementById('dcnFinderOriginator');
    const findBtn = document.getElementById('dcnDocNoFind');
    const statusEl = document.getElementById('dcnDocNoFindStatus');
    const suggestEl = document.getElementById('dcnDocNoSuggestions');
    const storedNo = document.getElementById('dcnDocumentNo');
    const confirmed = document.getElementById('dcnDocNoConfirmed');
    const reviseInput = document.getElementById('dcnFinderReviseNo');
    const typeInput = document.getElementById('dcnFinderDocType');
    const selectedBox = document.getElementById('dcnSelectedDocument');
    const reselectBtn = document.getElementById('dcnDocNoReselect');
    const finderCard = form.querySelector('.ofi-dcn-finder-card');
    if (!form || !finderNo || !finderTitle || !finderOriginator || !storedNo || !confirmed) return;

    const searchUrl = @json(url('/dcs/api/office/revisable-documents'));
    const exceptDcnId = form.getAttribute('data-except-dcn') || '';
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
        ['dcnSuggestTitle', 'dcnSuggestOriginator', 'dcnSuggestApprovals'].forEach(function (id) {
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
        setFieldText('dcnSelectedNo', docNo);
        setFieldText('dcnSelectedTitle', title);
        setFieldText('dcnSelectedOriginator', originator);
        setFieldText('dcnSelectedType', type);
        setFieldText('dcnSelectedRev', rev !== '' ? ('Rev ' + rev + ' · latest') : 'Latest');
        lockFinder(true);
    }

    let applyingMatch = false;

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
        const box = document.getElementById('dcnSelectedDuplicate');
        if (!box) return;
        box.hidden = duplicateMessage === '';
        box.textContent = duplicateMessage;
    }

    function clearMatch() {
        if (applyingMatch) return;
        showDuplicate('');
        confirmed.value = '';
        storedNo.value = '';
        suggestTitle = '';
        suggestOriginator = '';
        suggestApprovals = [];
        if (reviseInput) reviseInput.value = '';
        if (typeInput) typeInput.value = '';
        clearSuggestions();
        clearFieldSuggestions();
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
        btn.addEventListener('click', onClick);
        return btn;
    }

    function fillApprovals(approvals) {
        const list = document.getElementById('ofiApprovalsList');
        const addBtn = document.getElementById('ofiAddApproval');
        if (!list || !Array.isArray(approvals) || !approvals.length) return;
        let rows = Array.from(list.querySelectorAll('[data-approval-row]'));
        approvals.forEach(function (appr, index) {
            if (!rows[index] && addBtn) {
                addBtn.click();
                rows = Array.from(list.querySelectorAll('[data-approval-row]'));
            }
            const row = rows[index];
            if (!row) return;
            const position = row.querySelector('input[name="approvalPosition[]"]');
            const name = row.querySelector('input[name="approvalName[]"]');
            if (position && position.value.trim() === '') position.value = appr.position || '';
            if (name && name.value.trim() === '') name.value = appr.name || '';
            if (position) position.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    let suggestTitle = '';
    let suggestOriginator = '';
    let suggestApprovals = [];

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
        });
    }

    function showFieldSuggestions(data) {
        const titleBox = document.getElementById('dcnSuggestTitle');
        const titleInput = document.getElementById('dcnDocumentTitle');
        const originBox = document.getElementById('dcnSuggestOriginator');
        const originInput = document.getElementById('originatorName');
        const approvalBox = document.getElementById('dcnSuggestApprovals');
        clearFieldSuggestions();
        suggestTitle = (data.current_title || '').trim();
        suggestOriginator = (data.originator || '').trim();
        suggestApprovals = Array.isArray(data.approvals) ? data.approvals.filter(function (row) {
            return row && ((row.position || '').trim() !== '' || (row.name || '').trim() !== '');
        }) : [];

        if (titleInput) titleInput.placeholder = suggestTitle || 'New document title';
        if (originInput) originInput.placeholder = suggestOriginator || 'Enter originator name';
        if (titleBox && suggestTitle) hint(titleBox, 'Press Tab in the empty title to use the previous title.');
        if (originBox && suggestOriginator) hint(originBox, 'Press Tab in the empty originator to use the registered originator.');
        if (approvalBox && suggestApprovals.length) hint(approvalBox, 'Press Tab in an empty Position to use the previous approvals.');

        bindTabFill(titleInput, function () { return suggestTitle; });
        bindTabFill(originInput, function () { return suggestOriginator; });
    }

    function applyMatch(data) {
        applyingMatch = true;
        const canonicalNo = (data.document_no || finderNo.value || '').trim();
        confirmed.value = '1';
        storedNo.value = canonicalNo;
        if (reviseInput) reviseInput.value = data.revise_no != null && data.revise_no !== '' ? String(data.revise_no) : '';
        if (typeInput) typeInput.value = data.doc_type || '';
        if (data.document_no) finderNo.value = data.document_no;
        if (data.current_title) finderTitle.value = data.current_title;
        if (data.originator) finderOriginator.value = data.originator;
        clearSuggestions();
        showDuplicate(data.open_request_message ? String(data.open_request_message) : '');
        if (duplicateMessage) {
            setStatus(duplicateMessage, false);
            showOfficeNotice(duplicateMessage);
        } else {
            setStatus('Latest revision selected. Choose a different document if this is not the one to revise.', true);
        }
        showFieldSuggestions(data);
        showSelected(data);
        applyingMatch = false;
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
            // Clear previous selection only — keep the typed finder values.
            showDuplicate('');
            confirmed.value = '';
            storedNo.value = '';
            if (reviseInput) reviseInput.value = '';
            if (typeInput) typeInput.value = '';
            clearSuggestions();
            clearFieldSuggestions();
            lockFinder(false);
            const params = new URLSearchParams({
                documentNo: docNo,
                currentTitle: title,
                originator: originator,
            });
            if (exceptDcnId) params.set('exceptId', exceptDcnId);
            const res = await fetch(searchUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = res.ok ? await res.json() : { found: false, matches: [] };
            const matches = Array.isArray(data.matches) && data.matches.length
                ? data.matches.slice()
                : (Array.isArray(data.suggestions) ? data.suggestions.slice() : []);
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
            window.scrollTo(0, scrollY);
        }
    }

    document.getElementById('ofiApprovalsList')?.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) return;
        const input = e.target;
        if (!input || !input.matches || !input.matches('input[name="approvalPosition[]"]')) return;
        if (input.value.trim() !== '' || !suggestApprovals.length) return;
        e.preventDefault();
        fillApprovals(suggestApprovals);
    });

    if (reselectBtn) {
        reselectBtn.addEventListener('click', function () {
            clearMatch();
            setStatus('Enter the latest document number, title, and originator, then find it again.', false);
            finderNo.focus();
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

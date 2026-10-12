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
    if (!form || !finderNo || !finderTitle || !finderOriginator || !storedNo || !confirmed) return;

    const searchUrl = @json(url('/dcs/api/office/revisable-documents'));

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

    function clearMatch() {
        confirmed.value = '';
        storedNo.value = '';
        suggestTitle = '';
        suggestOriginator = '';
        suggestApprovals = [];
        if (reviseInput) reviseInput.value = '';
        if (typeInput) typeInput.value = '';
        clearSuggestions();
        clearFieldSuggestions();
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
        const canonicalNo = (data.document_no || finderNo.value || '').trim();
        confirmed.value = '1';
        storedNo.value = canonicalNo;
        if (reviseInput) reviseInput.value = data.revise_no != null ? String(data.revise_no) : '';
        if (typeInput) typeInput.value = data.doc_type || '';
        clearSuggestions();
        const rev = data.revise_no != null && data.revise_no !== '' ? (' · Rev ' + data.revise_no) : '';
        const type = data.doc_type ? (' · ' + data.doc_type) : '';
        setStatus('Document found' + type + rev + '.', true);
        showFieldSuggestions(data);
    }

    function showDoYouMean(suggestions) {
        if (!suggestEl) return;
        suggestEl.hidden = false;
        suggestEl.innerHTML = '';
        const label = document.createElement('p');
        label.className = 'ofi-suggest-label';
        label.textContent = 'Do you mean…';
        suggestEl.appendChild(label);
        suggestions.forEach(function (item) {
            const bits = [item.document_no, item.current_title, item.originator].filter(Boolean);
            suggestEl.appendChild(suggestionButton(bits.join(' · '), function () {
                if (item.document_no) finderNo.value = item.document_no;
                if (item.current_title) finderTitle.value = item.current_title;
                if (item.originator) finderOriginator.value = item.originator;
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
        try {
            const params = new URLSearchParams({
                documentNo: docNo,
                currentTitle: title,
                originator: originator,
            });
            const res = await fetch(searchUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = res.ok ? await res.json() : { found: false };
            if (data && data.found === true) {
                applyMatch(data);
                return;
            }
            clearMatch();
            const suggestions = Array.isArray(data && data.suggestions) ? data.suggestions : [];
            if (suggestions.length) {
                setStatus('No exact match. Select the document below if it is the one on your copy.', false);
                showDoYouMean(suggestions);
                return;
            }
            setStatus('No document matched those details.', false);
        } catch (err) {
            clearMatch();
            setStatus('Could not check the document. Try again.', false);
        } finally {
            if (findBtn) findBtn.disabled = false;
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
    form.addEventListener('submit', function (e) {
        const submitter = e.submitter;
        if (submitter && submitter.hasAttribute('formnovalidate')) {
            return;
        }
        if (confirmed.value !== '1' || storedNo.value.trim() === '') {
            e.preventDefault();
            setStatus('Find the registered document before saving.', false);
            finderNo.focus();
        }
    });
})();
</script>

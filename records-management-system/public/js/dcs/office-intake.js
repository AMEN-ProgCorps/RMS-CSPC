(function () {
    'use strict';

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Source Unit (same behavior as Register createSourceUnitWidget) ──
    window.__sourceWidgets = window.__sourceWidgets || {};

    function filterOffices(list, q) {
        q = String(q || '').trim().toLowerCase();
        if (!q) return list.slice(0, 40);
        return list
            .filter(
                (o) =>
                    String(o.office_name || '').toLowerCase().includes(q) ||
                    String(o.office_code || '').toLowerCase().includes(q)
            )
            .slice(0, 40);
    }

    function createOfficeSourceWidget(opts) {
        const offices = Array.isArray(window.__ofiOffices) ? window.__ofiOffices : [];
        let selected = [];
        let panelOpen = false;
        let scrollResizeHandler = null;

        function isSelected(id) {
            return selected.some((i) => String(i.id) === String(id));
        }

        function syncInputText() {
            const inputEl = document.getElementById(opts.inputId);
            if (!inputEl) return;
            if (selected.length === 0) {
                inputEl.value = '';
                return;
            }
            inputEl.value = selected.map((i) => i.label).join(', ') + ', ';
            const len = inputEl.value.length;
            inputEl.setSelectionRange(len, len);
        }

        function render() {
            const widget = document.getElementById(opts.widgetId);
            if (!widget) return;
            widget.querySelectorAll('input[data-source-hidden]').forEach((el) => el.remove());
            selected.forEach((item) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.dataset.sourceHidden = 'true';
                input.name = opts.officeFieldName;
                input.value = item.id;
                widget.appendChild(input);
            });

            const chipsEl = document.getElementById(opts.chipsId);
            if (chipsEl) {
                chipsEl.innerHTML =
                    selected.length === 0
                        ? '<div class="reg-reldocs-empty">Nothing selected yet</div>'
                        : selected
                              .map(function (item) {
                                  return (
                                      '<div class="reg-inline-chip">' +
                                      '<span>' +
                                      escapeHtml(item.label) +
                                      '</span>' +
                                      '<button type="button" onclick="event.stopPropagation(); window.__sourceWidgets[\'' +
                                      opts.key +
                                      '\'].removeItem(\'office\',\'' +
                                      escapeHtml(String(item.id)) +
                                      '\')"><i class="fa-solid fa-xmark"></i></button>' +
                                      '</div>'
                                  );
                              })
                              .join('');
            }
        }

        function getCurrentQuery(input) {
            const raw = input.value;
            const lastComma = raw.lastIndexOf(',');
            return (lastComma === -1 ? raw : raw.slice(lastComma + 1)).trim();
        }

        function handleSearch(input) {
            const dropdown = document.getElementById(opts.resultsId);
            const q = getCurrentQuery(input);
            if (q.length < 1) {
                dropdown.style.display = 'none';
                return;
            }
            const filtered = filterOffices(offices, q).filter((o) => !isSelected(o.office_id));
            if (filtered.length === 0) {
                dropdown.innerHTML = '<div class="reg-reldocs-noresult">No matching offices found</div>';
                dropdown.style.display = 'block';
                return;
            }
            dropdown.innerHTML = filtered
                .map((o) => {
                    const extra = o.office_code ? ' (' + escapeHtml(o.office_code) + ')' : '';
                    return (
                        '<div onmousedown="window.__sourceWidgets[\'' +
                        opts.key +
                        "'].pick(" +
                        o.office_id +
                        ')">' +
                        escapeHtml(o.office_name) +
                        extra +
                        '</div>'
                    );
                })
                .join('');
            dropdown.style.display = 'block';
        }

        function addOffice(itemId) {
            const item = offices.find((o) => Number(o.office_id) === Number(itemId));
            if (!item || isSelected(itemId)) return;
            selected.push({ type: 'office', id: item.office_id, label: item.office_name });
            render();
        }

        function pick(itemId) {
            addOffice(itemId);
            const inputEl = document.getElementById(opts.inputId);
            if (inputEl) inputEl.focus();
            syncInputText();
            document.getElementById(opts.resultsId).style.display = 'none';
        }

        function removeItem(type, id) {
            selected = selected.filter((i) => !(i.type === type && String(i.id) === String(id)));
            render();
            syncInputText();
        }

        function positionPanel(panelEl, anchorEl) {
            const rect = anchorEl.getBoundingClientRect();
            panelEl.style.position = 'fixed';
            panelEl.style.top = rect.bottom + 6 + 'px';
            panelEl.style.left = rect.left + 'px';
            panelEl.style.width = rect.width + 'px';
            panelEl.style.zIndex = 9500;
        }

        function togglePanel(e) {
            if (e) e.stopPropagation();
            document.getElementById(opts.resultsId).style.display = 'none';
            panelOpen = !panelOpen;
            const chipsEl = document.getElementById(opts.chipsId);
            const widget = document.getElementById(opts.widgetId);
            if (!chipsEl || !widget) return;

            if (panelOpen) {
                document.body.appendChild(chipsEl);
                positionPanel(chipsEl, widget);
                chipsEl.style.display = 'block';
                scrollResizeHandler = () => positionPanel(chipsEl, widget);
                window.addEventListener('scroll', scrollResizeHandler, true);
                window.addEventListener('resize', scrollResizeHandler);
            } else {
                chipsEl.style.display = 'none';
                if (scrollResizeHandler) {
                    window.removeEventListener('scroll', scrollResizeHandler, true);
                    window.removeEventListener('resize', scrollResizeHandler);
                    scrollResizeHandler = null;
                }
            }
        }

        function closePanel() {
            panelOpen = false;
            const chipsEl = document.getElementById(opts.chipsId);
            if (chipsEl) chipsEl.style.display = 'none';
            if (scrollResizeHandler) {
                window.removeEventListener('scroll', scrollResizeHandler, true);
                window.removeEventListener('resize', scrollResizeHandler);
                scrollResizeHandler = null;
            }
        }

        const inputEl = document.getElementById(opts.inputId);
        const arrowEl = document.getElementById(opts.arrowId);
        if (!inputEl || !arrowEl) return null;

        inputEl.addEventListener('input', function () {
            closePanel();
            handleSearch(this);
        });
        inputEl.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ',') return;
            e.preventDefault();
            const q = getCurrentQuery(this);
            if (!q) return;
            const exact = offices.find(
                (o) =>
                    !isSelected(o.office_id) &&
                    (String(o.office_name).toLowerCase() === q.toLowerCase() ||
                        String(o.office_code || '').toLowerCase() === q.toLowerCase())
            );
            if (exact) pick(exact.office_id);
        });
        function jumpCaretToEnd() {
            setTimeout(() => {
                const len = inputEl.value.length;
                inputEl.setSelectionRange(len, len);
            }, 0);
        }
        inputEl.addEventListener('focus', jumpCaretToEnd);
        inputEl.addEventListener('click', jumpCaretToEnd);
        arrowEl.addEventListener('click', togglePanel);

        document.addEventListener('click', function (e) {
            const widget = document.getElementById(opts.widgetId);
            const chipsEl = document.getElementById(opts.chipsId);
            const insideWidget = widget && widget.contains(e.target);
            const insidePanel = chipsEl && chipsEl.contains(e.target);
            if (!insideWidget && !insidePanel) {
                document.getElementById(opts.resultsId).style.display = 'none';
                closePanel();
            }
        });

        const api = { pick, removeItem, get selected() { return selected; } };
        window.__sourceWidgets[opts.key] = api;

        // Seed from old input / default office
        const oldIds = Array.isArray(window.__ofiOldSource) ? window.__ofiOldSource.map(Number) : [];
        oldIds.forEach((id) => {
            const o = offices.find((x) => Number(x.office_id) === Number(id));
            if (o) selected.push({ type: 'office', id: o.office_id, label: o.office_name });
        });
        if (
            selected.length === 0 &&
            window.__ofiDefaultOffice &&
            window.__ofiDefaultOffice.office_id
        ) {
            selected.push({
                type: 'office',
                id: window.__ofiDefaultOffice.office_id,
                label: window.__ofiDefaultOffice.office_name,
            });
        }
        render();
        syncInputText();
        return api;
    }

    function initSourceWidgets() {
        const configs = Array.isArray(window.__ofiSourceConfigs) ? window.__ofiSourceConfigs : [];
        configs.forEach((cfg) => createOfficeSourceWidget(cfg));
    }

    // ── Revision document search (DCN) ─────────────────────────────────
    let revisionRowUidCounter = 0;
    const revSearchCache = {};
    const revSearchTimers = {};

    function revisionRowCellsHTML() {
        return (
            '<td>' +
            '<input type="text" name="documentNo[]" placeholder="Search or enter document no." autocomplete="off">' +
            '<input type="hidden" name="revisionScannedPath[]" value="">' +
            '<input type="hidden" name="revisionMasterlistId[]" value="">' +
            '<input type="hidden" name="revisionLinked[]" value="0">' +
            '</td>' +
            '<td><input type="text" name="documentTitle[]" placeholder="Search or enter document title" autocomplete="off"></td>' +
            '<td><input type="date" name="effectiveDate[]" readonly class="reg-revrow-locked" tabindex="-1"></td>' +
            '<td><input type="number" name="revisionNo[]" placeholder="—" readonly class="reg-revrow-locked" tabindex="-1"></td>' +
            '<td class="reg-rev-scan-cell" style="text-align:center;color:#94a3b8;">—</td>' +
            '<td class="reg-rev-purpose-cell">' +
            '<input type="hidden" name="revisionPurpose[]" value="">' +
            '<span class="reg-rev-purpose-text">—</span>' +
            '</td>' +
            '<td><button type="button" class="reg-row-del" onclick="ofiRemoveRevisionRow(this)"><i class="fa-solid fa-trash-can"></i></button></td>'
        );
    }

    function getOrCreateRevSearchDropdown(key) {
        let dd = document.getElementById('ofiRevSearch_' + key);
        if (!dd) {
            dd = document.createElement('div');
            dd.id = 'ofiRevSearch_' + key;
            dd.className = 'ofi-rev-dropdown';
            document.body.appendChild(dd);
        }
        return dd;
    }

    function positionFixedDropdown(dd, input) {
        const r = input.getBoundingClientRect();
        dd.style.position = 'fixed';
        dd.style.left = r.left + 'px';
        dd.style.top = r.bottom + 4 + 'px';
        dd.style.minWidth = Math.max(r.width, 280) + 'px';
        dd.style.zIndex = '9999';
    }

    function closeRevSearchDropdown(key) {
        const dd = document.getElementById('ofiRevSearch_' + key);
        if (dd) dd.style.display = 'none';
    }

    function removeRevSearchDropdown(key) {
        const dd = document.getElementById('ofiRevSearch_' + key);
        if (dd) dd.remove();
    }

    function removeRevisionRowDropdowns(tr) {
        if (!tr.dataset.uid) return;
        removeRevSearchDropdown(tr.dataset.uid + '_title');
        removeRevSearchDropdown(tr.dataset.uid + '_no');
    }

    function lockRevisionRowFields(row) {
        row.dataset.linked = 'true';
        const linked = row.querySelector('input[name="revisionLinked[]"]');
        if (linked) linked.value = '1';
        row.querySelectorAll(
            'input[name="documentNo[]"], input[name="documentTitle[]"], input[name="effectiveDate[]"], input[name="revisionNo[]"]'
        ).forEach((el) => {
            el.readOnly = true;
            el.classList.add('reg-revrow-locked');
        });
    }

    function lockRevisionScannedCopyCell(row, scannedCopyUrl) {
        const cell = row.querySelector('.reg-rev-scan-cell, .ofi-rev-scan');
        if (!cell) return;
        if (scannedCopyUrl) {
            cell.innerHTML =
                '<button type="button" class="reg-revrow-viewfile reg-revrow-viewfile-pdf" onclick="window.open(\'' +
                escapeHtml(scannedCopyUrl) +
                '\', \'_blank\')" title="View PDF"><i class="fa-solid fa-file-pdf"></i></button>';
        } else {
            cell.style.textAlign = 'center';
            cell.innerHTML = '<span style="color:#94a3b8;">—</span>';
        }
    }

    function populateRevisionRowFromDoc(row, doc) {
        if (!row || !doc) return;
        const titleInput = row.querySelector('input[name="documentTitle[]"]');
        const noInput = row.querySelector('input[name="documentNo[]"]');
        const effField = row.querySelector('input[name="effectiveDate[]"]');
        const revField = row.querySelector('input[name="revisionNo[]"]');
        const pathInput = row.querySelector('input[name="revisionScannedPath[]"]');
        const mlInput = row.querySelector('input[name="revisionMasterlistId[]"]');
        const purposeField = row.querySelector('input[name="revisionPurpose[]"]');
        const purposeText = row.querySelector('.reg-rev-purpose-text, .ofi-rev-purpose-text');

        if (titleInput) titleInput.value = doc.doc_title || '';
        if (noInput) noInput.value = doc.doc_no || '';
        if (effField) effField.value = doc.effectivity_date || '';
        if (revField) revField.value = doc.revise_no != null ? doc.revise_no : '';
        if (pathInput) pathInput.value = doc.scanned_copy_path || '';
        if (mlInput) mlInput.value = doc.masterlist_id || '';

        // Rev 0 has no DCN → no Brief Purpose. Later revs use DCN justification only.
        const reviseNo = Number(doc.revise_no ?? 0);
        const purpose = reviseNo > 0 ? String(doc.brief_purpose || '').trim() : '';
        if (purposeField) purposeField.value = purpose;
        if (purposeText) {
            purposeText.textContent = purpose || '—';
            purposeText.classList.toggle('is-wrap', purpose.length > 42);
        }

        lockRevisionRowFields(row);
        lockRevisionScannedCopyCell(row, doc.scanned_copy_url);
    }

    function clearAllLinkedRevisionRows(tbody, exceptRow) {
        if (!tbody) return;
        [...tbody.querySelectorAll('tr')].forEach((tr) => {
            if (tr === exceptRow) return;
            if (tr.dataset.linked === 'true') {
                removeRevisionRowDropdowns(tr);
                tr.remove();
            }
        });
    }

    async function fillRevisionTableWithDocumentHistory(anchorRow, docs, options) {
        const tbody = document.getElementById('revisionTableBody');
        if (!tbody || !anchorRow || !docs.length) return;
        const pickedDocNo = String((options && options.docNo) || docs[0].doc_no || '')
            .trim()
            .toLowerCase();

        clearAllLinkedRevisionRows(tbody, anchorRow);
        populateRevisionRowFromDoc(anchorRow, docs[0]);
        anchorRow.dataset.linked = 'true';
        anchorRow.dataset.linkedDocNo = pickedDocNo;

        let insertAfter = anchorRow;
        for (let i = 1; i < docs.length; i++) {
            const tr = document.createElement('tr');
            tr.innerHTML = revisionRowCellsHTML();
            insertAfter.after(tr);
            bindRevisionRowSearch(tr);
            populateRevisionRowFromDoc(tr, docs[i]);
            tr.dataset.linkedDocNo = pickedDocNo;
            insertAfter = tr;
        }
    }

    window.ofiPickRevisionDocument = async function (key, idx) {
        const doc = (revSearchCache[key] || [])[idx];
        if (!doc) return;
        const uid = key.split('_')[0];
        const row = document.querySelector('#revisionTableBody tr[data-uid="' + uid + '"]');
        if (!row) return;
        closeRevSearchDropdown(key);

        let revisions = [];
        try {
            if (doc.request_id) {
                const params = new URLSearchParams({ request_id: String(doc.request_id) });
                if (doc.doc_no) params.set('doc_no', doc.doc_no);
                revisions = await fetch('/dcs/api/documents/revisions?' + params.toString()).then((r) => r.json());
            }
        } catch (e) {
            console.error('Failed to load document revisions:', e);
        }
        if (!Array.isArray(revisions) || revisions.length === 0) {
            revisions = [doc];
        }
        await fillRevisionTableWithDocumentHistory(row, revisions, {
            docNo: String(doc.doc_no || '').trim().toLowerCase(),
        });
    };

    function handleRevisionSearchInput(input, key, field) {
        if (input.readOnly) return;
        clearTimeout(revSearchTimers[key]);
        const dd = getOrCreateRevSearchDropdown(key);
        const q = input.value.trim();
        if (q.length < 1) {
            dd.style.display = 'none';
            return;
        }

        revSearchTimers[key] = setTimeout(async () => {
            try {
                const params = new URLSearchParams({
                    q,
                    field: field || '',
                    originator_self: window.__ofiOriginatorSelf ? '1' : '0',
                });
                const data = await fetch('/dcs/api/documents/search?' + params.toString()).then((r) => r.json());
                revSearchCache[key] = data;
                dd.innerHTML =
                    data.length === 0
                        ? '<div class="ofi-source-empty">No matching documents (originator must be you)</div>'
                        : data
                              .map(
                                  (d, idx) =>
                                      '<div class="ofi-source-item" onmousedown="ofiPickRevisionDocument(\'' +
                                      key +
                                      "', " +
                                      idx +
                                      ')">' +
                                      escapeHtml(d.label) +
                                      '</div>'
                              )
                              .join('');
                positionFixedDropdown(dd, input);
                dd.style.display = 'block';
            } catch (e) {
                console.error('Revision doc search failed:', e);
            }
        }, 300);
    }

    function bindRevisionSearchInput(input, uid, field) {
        if (!input || input.dataset.searchBound) return;
        input.dataset.searchBound = 'true';
        const key = uid + '_' + field;
        if (!input.id) input.id = 'ofiRevInput_' + key;
        input.addEventListener('input', () => handleRevisionSearchInput(input, key, field));
        input.addEventListener('focus', () => {
            if (!input.readOnly && input.value.trim().length >= 1) handleRevisionSearchInput(input, key, field);
        });
    }

    function bindRevisionRowSearch(tr) {
        if (!tr.dataset.uid) tr.dataset.uid = String(++revisionRowUidCounter);
        const uid = tr.dataset.uid;
        bindRevisionSearchInput(tr.querySelector('input[name="documentNo[]"]'), uid, 'no');
        bindRevisionSearchInput(tr.querySelector('input[name="documentTitle[]"]'), uid, 'title');
        if (tr.dataset.linked === 'true') lockRevisionRowFields(tr);
    }

    window.ofiAddRevisionRow = function () {
        const tbody = document.getElementById('revisionTableBody');
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.innerHTML = revisionRowCellsHTML();
        tbody.appendChild(tr);
        bindRevisionRowSearch(tr);
    };

    window.ofiRemoveRevisionRow = function (btn) {
        const tbody = document.getElementById('revisionTableBody');
        const tr = btn.closest('tr');
        if (!tbody || !tr) return;
        removeRevisionRowDropdowns(tr);
        if (tbody.querySelectorAll('tr').length <= 1) {
            tr.innerHTML = revisionRowCellsHTML();
            delete tr.dataset.linked;
            delete tr.dataset.linkedDocNo;
            delete tr.dataset.uid;
            bindRevisionRowSearch(tr);
            return;
        }
        tr.remove();
    };

    function initRevisionTable() {
        const tbody = document.getElementById('revisionTableBody');
        if (!tbody) return;
        const first = tbody.querySelector('tr');
        if (first) bindRevisionRowSearch(first);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initSourceWidgets();
        initRevisionTable();
    });
})();

<script>
(function () {
    const GROUP_INDEX_URL = @json(route('dcs.register.distributionOfficeGroups.index'));
    const GROUP_STORE_URL = @json(route('dcs.register.distributionOfficeGroups.store'));
    const GROUP_DESTROY_URL = @json(url('/dcs/register/distribution-office-groups'));
    const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value;

    window.__distOfficeGroups = Array.isArray(window.__distOfficeGroups)
        ? window.__distOfficeGroups
        : ((window.__registerCatalog || {}).distributionOfficeGroups || []);

    function distTotalId() {
        return document.getElementById('totalDistCopies') ? 'totalDistCopies' : 'distTotal';
    }

    function getDistRows() {
        return [...document.querySelectorAll('#distBody tr.reg-office-added')];
    }

    function getSelectedDistRows() {
        return getDistRows().filter((tr) => tr.querySelector('.dist-office-check')?.checked);
    }

    function syncDistSelectAllState() {
        const rows = getDistRows();
        const selected = getSelectedDistRows();
        const btn = document.getElementById('distSelectAllBtn');
        if (btn) {
            btn.classList.toggle('is-active', rows.length > 0 && selected.length === rows.length);
            btn.innerHTML = (rows.length > 0 && selected.length === rows.length)
                ? '<i class="fa-solid fa-xmark"></i> Clear'
                : '<i class="fa-solid fa-check-double"></i> Select all';
        }
        rows.forEach((tr) => tr.classList.toggle('is-selected', !!tr.querySelector('.dist-office-check')?.checked));
        const header = document.getElementById('distSelectAllHeader');
        if (header) {
            header.checked = rows.length > 0 && selected.length === rows.length;
            header.indeterminate = selected.length > 0 && selected.length < rows.length;
        }
    }

    window.toggleSelectAllDistOffices = function () {
        const rows = getDistRows();
        const allSelected = rows.length > 0 && getSelectedDistRows().length === rows.length;
        rows.forEach((tr) => {
            const cb = tr.querySelector('.dist-office-check');
            if (cb) cb.checked = !allSelected;
        });
        syncDistSelectAllState();
    };

    window.onDistOfficeCheckChange = function () {
        syncDistSelectAllState();
    };

    window.moveSelectedDistOffices = function (direction) {
        const selected = getSelectedDistRows();
        if (!selected.length) {
            alert('Select one or more offices first, then use Up / Down to reorder them.');
            return;
        }
        if (direction < 0) {
            selected.forEach((tr) => {
                const prev = tr.previousElementSibling;
                if (prev && prev.classList.contains('reg-office-added') && !prev.querySelector('.dist-office-check')?.checked) {
                    tr.parentNode.insertBefore(tr, prev);
                }
            });
        } else {
            [...selected].reverse().forEach((tr) => {
                const next = tr.nextElementSibling;
                if (next && next.classList.contains('reg-office-added') && !next.querySelector('.dist-office-check')?.checked) {
                    tr.parentNode.insertBefore(next, tr);
                }
            });
        }
        syncDistSelectAllState();
    };

    /** HTML for one distribution office row (checkbox + drag + copies). */
    window.buildDistOfficeRowHTML = function (officeId, officeName, copies, totalId) {
        const tid = totalId || distTotalId();
        return `
        <td class="reg-dist-check-cell">
            <input type="checkbox" class="dist-office-check" onchange="onDistOfficeCheckChange()" title="Select to reorder">
        </td>
        <td>
            <input type="hidden" name="distOffice[]" value="${officeId}">
            <div class="reg-office-name">
                <span class="reg-dist-drag-handle" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span>
                <div class="reg-office-icon"><i class="fa-solid fa-building"></i></div>
                <span class="reg-office-text">${escapeHtml(officeName)}</span>
            </div>
        </td>
        <td style="text-align: center;">
            <input type="number" name="distCopies[]" value="${copies}" min="1" oninput="updateTotal('${tid}', 'distBody')">
        </td>
        ${document.querySelector('#distBody')?.closest('table')?.querySelector('.reg-dist-receipt-head')
            ? '<td class="reg-dist-receipt-cell"><div class="reg-dist-receipt-status is-pending">Pending</div></td>'
            : ''}
        <td>
            <button type="button" class="btn-remove" onclick="removeOffice(this, '${tid}', 'distBody')">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </td>`;
    };

    function ensureDistTableHeader() {
        const table = document.querySelector('#distBody')?.closest('table');
        if (!table) return;
        const headRow = table.querySelector('thead tr');
        if (!headRow || headRow.querySelector('.reg-dist-check-head')) return;
        const th = document.createElement('th');
        th.className = 'reg-dist-check-head';
        th.style.width = '36px';
        th.innerHTML = '<input type="checkbox" id="distSelectAllHeader" title="Select all" onchange="toggleSelectAllDistOffices()">';
        headRow.insertBefore(th, headRow.firstElementChild);
        const footRow = table.querySelector('tfoot tr');
        if (footRow && !footRow.querySelector('.reg-dist-check-foot')) {
            const td = document.createElement('td');
            td.className = 'reg-dist-check-foot';
            footRow.insertBefore(td, footRow.firstElementChild);
        }
        // Existing edit rows may lack checkbox cells — upgrade them.
        getDistRows().forEach((tr) => {
            if (tr.querySelector('.dist-office-check')) return;
            const first = tr.firstElementChild;
            if (!first) return;
            const checkTd = document.createElement('td');
            checkTd.className = 'reg-dist-check-cell';
            checkTd.innerHTML = '<input type="checkbox" class="dist-office-check" onchange="onDistOfficeCheckChange()" title="Select to reorder">';
            tr.insertBefore(checkTd, first);
            const nameWrap = tr.querySelector('.reg-office-name');
            if (nameWrap && !nameWrap.querySelector('.reg-dist-drag-handle')) {
                nameWrap.insertAdjacentHTML('afterbegin', '<span class="reg-dist-drag-handle" title="Drag to reorder"><i class="fa-solid fa-grip-vertical"></i></span>');
            }
        });
        const empty = document.querySelector('#distBody tr.reg-empty-row td');
        if (empty) empty.colSpan = table.querySelector('.reg-dist-receipt-head') ? 5 : 4;
    }

    // Patch empty-row colspan for distBody.
    const _emptyOfficeRowHTML = window.emptyOfficeRowHTML;
    if (typeof _emptyOfficeRowHTML === 'function') {
        window.emptyOfficeRowHTML = function (bodyId) {
            if (bodyId === 'distBody') {
                const cols = document.querySelector('#distBody')?.closest('table')?.querySelector('.reg-dist-receipt-head') ? 5 : 4;
                return '<tr class="reg-empty-row"><td colspan="' + cols + '"><div class="reg-empty-state">' +
                    '<i class="fa-solid fa-building-circle-xmark"></i><span>No offices added yet</span></div></td></tr>';
            }
            return _emptyOfficeRowHTML(bodyId);
        };
    }

    function renderDistOfficeGroupChips() {
        const wrap = document.getElementById('distOfficeGroupChips');
        if (!wrap) return;
        const groups = window.__distOfficeGroups || [];
        if (!groups.length) {
            wrap.innerHTML = '<span class="reg-dist-groups-empty">No saved groups yet</span>';
            return;
        }
        wrap.innerHTML = groups.map((g) => (
            '<span class="reg-dist-group-chip" data-group-id="' + Number(g.id) + '">' +
                '<button type="button" class="reg-dist-group-apply" title="Apply this group">' +
                    escapeHtml(g.name) +
                    ' <small>(' + (g.offices || []).length + ')</small>' +
                '</button>' +
                '<button type="button" class="reg-dist-group-delete" title="Delete group" aria-label="Delete group">' +
                    '<i class="fa-solid fa-xmark"></i>' +
                '</button>' +
            '</span>'
        )).join('');

        wrap.querySelectorAll('.reg-dist-group-chip').forEach((chip) => {
            const id = Number(chip.getAttribute('data-group-id'));
            chip.querySelector('.reg-dist-group-apply')?.addEventListener('click', () => applyDistOfficeGroup(id));
            chip.querySelector('.reg-dist-group-delete')?.addEventListener('click', () => deleteDistOfficeGroup(id));
        });
    }

    function findGroup(id) {
        return (window.__distOfficeGroups || []).find((g) => Number(g.id) === Number(id)) || null;
    }

    let pendingDeleteGroupId = null;

    window.applyDistOfficeGroup = function (groupId) {
        if (typeof isDistOfficesLockedFromDrf === 'function' && isDistOfficesLockedFromDrf()) {
            alert('Distribution offices from the submitted DRF cannot be replaced.');
            return;
        }
        const group = findGroup(groupId);
        if (!group || !Array.isArray(group.offices) || !group.offices.length) {
            alert('That group has no active offices.');
            return;
        }
        const existing = getDistRows().length;
        if (existing > 0 && !confirm('Replace the current distribution offices with "' + group.name + '"?')) {
            return;
        }

        const tbody = document.getElementById('distBody');
        if (!tbody) return;
        // Clear current (preserve retrieval-linked cleanup when possible).
        getDistRows().forEach((tr) => {
            if (typeof maybeRestoreDistOfficeToRetrieval === 'function') {
                maybeRestoreDistOfficeToRetrieval(tr);
            }
            tr.remove();
        });
        tbody.innerHTML = '';

        const totalId = distTotalId();
        group.offices.forEach((o) => {
            if (typeof seedOfficeRow === 'function') {
                seedOfficeRow('distBody', totalId, o.office_id, o.office_name, o.copies || 1);
            } else if (typeof addOffice === 'function') {
                addOffice(o.office_id, o.office_name, 'distBody', totalId, 'distResults');
                const row = [...tbody.querySelectorAll('input[name="distOffice[]"]')]
                    .find((inp) => String(inp.value) === String(o.office_id))
                    ?.closest('tr');
                const copiesInp = row?.querySelector('input[name="distCopies[]"]');
                if (copiesInp) copiesInp.value = String(o.copies || 1);
            }
        });

        if (!getDistRows().length) {
            tbody.innerHTML = emptyOfficeRowHTML('distBody');
        }
        if (typeof updateTotal === 'function') updateTotal(totalId, 'distBody');
        if (typeof syncDistClusterChipState === 'function') syncDistClusterChipState();
        if (typeof refreshOfficeSeeMore === 'function') refreshOfficeSeeMore('distBody');
        ensureDistTableHeader();
        syncDistSelectAllState();
    };

    window.openSaveDistOfficeGroupModal = function () {
        if (!getDistRows().length) {
            alert('Add offices to the distribution list before saving a group.');
            return;
        }
        const modal = document.getElementById('distOfficeGroupModal');
        const input = document.getElementById('distOfficeGroupName');
        const err = document.getElementById('distOfficeGroupModalError');
        if (err) { err.style.display = 'none'; err.textContent = ''; }
        if (input) input.value = '';
        if (modal) {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(() => input?.focus(), 0);
        }
    };

    window.closeSaveDistOfficeGroupModal = function () {
        const modal = document.getElementById('distOfficeGroupModal');
        if (modal) {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }
    };

    window.submitSaveDistOfficeGroup = async function (overwrite) {
        const name = (document.getElementById('distOfficeGroupName')?.value || '').trim();
        const err = document.getElementById('distOfficeGroupModalError');
        const showErr = (msg) => {
            if (!err) { alert(msg); return; }
            err.style.display = 'block';
            err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(msg);
        };
        if (!name) {
            showErr('Enter a group name.');
            return;
        }
        const offices = getDistRows().map((tr) => {
            const id = tr.querySelector('input[name="distOffice[]"]')?.value;
            const copies = tr.querySelector('input[name="distCopies[]"]')?.value || 1;
            return { office_id: Number(id), copies: Number(copies) || 1 };
        }).filter((o) => o.office_id > 0);

        if (!offices.length) {
            showErr('Add at least one office.');
            return;
        }

        const btn = document.getElementById('distOfficeGroupSaveBtn');
        if (btn) btn.disabled = true;
        try {
            const res = await fetch(GROUP_STORE_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF(),
                },
                body: JSON.stringify({ name, offices, overwrite: !!overwrite }),
            });
            const data = await res.json().catch(() => ({}));
            if (data.exists && !overwrite) {
                if (confirm(data.message || 'Overwrite existing group?')) {
                    return submitSaveDistOfficeGroup(true);
                }
                return;
            }
            if (!data.ok) {
                showErr(data.message || 'Could not save group.');
                return;
            }
            window.__distOfficeGroups = data.groups || [];
            renderDistOfficeGroupChips();
            closeSaveDistOfficeGroupModal();
        } catch (e) {
            showErr('Could not save group. Try again.');
            console.error(e);
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    window.deleteDistOfficeGroup = function (groupId) {
        const group = findGroup(groupId);
        if (!group) return;
        pendingDeleteGroupId = Number(groupId);
        const nameEl = document.getElementById('distOfficeGroupDeleteName');
        const err = document.getElementById('distOfficeGroupDeleteError');
        if (nameEl) nameEl.textContent = '"' + group.name + '"';
        if (err) { err.style.display = 'none'; err.textContent = ''; }
        const modal = document.getElementById('distOfficeGroupDeleteModal');
        if (modal) {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(() => document.getElementById('distOfficeGroupDeleteConfirmBtn')?.focus(), 0);
        }
    };

    window.closeDeleteDistOfficeGroupModal = function () {
        pendingDeleteGroupId = null;
        const modal = document.getElementById('distOfficeGroupDeleteModal');
        if (modal) {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }
    };

    window.confirmDeleteDistOfficeGroup = async function () {
        const groupId = pendingDeleteGroupId;
        if (!groupId) return;
        const err = document.getElementById('distOfficeGroupDeleteError');
        const showErr = (msg) => {
            if (!err) { alert(msg); return; }
            err.style.display = 'block';
            err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + escapeHtml(msg);
        };
        const btn = document.getElementById('distOfficeGroupDeleteConfirmBtn');
        if (btn) btn.disabled = true;
        try {
            const res = await fetch(GROUP_DESTROY_URL + '/' + encodeURIComponent(groupId), {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': CSRF(),
                },
            });
            const data = await res.json().catch(() => ({}));
            if (!data.ok) {
                showErr(data.message || 'Could not delete group.');
                return;
            }
            window.__distOfficeGroups = data.groups || [];
            renderDistOfficeGroupChips();
            closeDeleteDistOfficeGroupModal();
        } catch (e) {
            showErr('Could not delete group.');
            console.error(e);
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    /** Multi-select aware drag reorder for #distBody. */
    window.bindDistBodyDrag = function () {
        const tbody = document.getElementById('distBody');
        if (!tbody || tbody.dataset.dragBound) return;
        tbody.dataset.dragBound = 'true';
        let dragRows = [];

        tbody.addEventListener('mousedown', (e) => {
            const tr = e.target.closest('tr.reg-office-added');
            if (!tr) return;
            // Don't start drag from interactive controls.
            tr.draggable = !e.target.closest('input, button, a, select, textarea');
        });

        tbody.addEventListener('dragstart', (e) => {
            const tr = e.target.closest('tr.reg-office-added');
            if (!tr) return;
            const selected = getSelectedDistRows();
            dragRows = (selected.length && selected.includes(tr)) ? selected : [tr];
            dragRows.forEach((r) => r.classList.add('is-dragging'));
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', 'dist-reorder'); } catch (_) {}
        });

        tbody.addEventListener('dragend', () => {
            dragRows.forEach((r) => r.classList.remove('is-dragging'));
            dragRows = [];
            syncDistSelectAllState();
        });

        tbody.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!dragRows.length) return;
            const tr = e.target.closest('tr.reg-office-added');
            if (!tr || dragRows.includes(tr)) return;
            const rect = tr.getBoundingClientRect();
            const after = (e.clientY - rect.top) > (rect.height / 2);
            const ref = after ? tr.nextSibling : tr;
            // Keep relative order of the dragged block.
            dragRows.forEach((r) => {
                if (r.parentNode) r.parentNode.insertBefore(r, ref);
            });
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        window.__distOfficeGroups = (window.__registerCatalog || {}).distributionOfficeGroups || window.__distOfficeGroups || [];
        ensureDistTableHeader();
        renderDistOfficeGroupChips();
        syncDistSelectAllState();
        // Re-bind drag with multi-select support (clear prior flag if old binder ran first).
        const tbody = document.getElementById('distBody');
        if (tbody) {
            delete tbody.dataset.dragBound;
            bindDistBodyDrag();
        }
        const nameInput = document.getElementById('distOfficeGroupName');
        nameInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitSaveDistOfficeGroup(false);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const deleteModal = document.getElementById('distOfficeGroupDeleteModal');
            if (deleteModal?.classList.contains('is-open')) {
                closeDeleteDistOfficeGroupModal();
                return;
            }
            const saveModal = document.getElementById('distOfficeGroupModal');
            if (saveModal?.classList.contains('is-open')) {
                closeSaveDistOfficeGroupModal();
            }
        });
    });

    // After offices are added dynamically, keep header/checkbox in sync.
    const _addOffice = window.addOffice;
    // Patch later via MutationObserver — addOffice may be defined after this script.
    const observer = new MutationObserver(() => {
        ensureDistTableHeader();
        syncDistSelectAllState();
    });
    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.getElementById('distBody');
        if (tbody) observer.observe(tbody, { childList: true, subtree: false });
    });
})();
</script>

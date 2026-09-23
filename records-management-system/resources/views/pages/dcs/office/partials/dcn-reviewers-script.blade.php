<script>
(function () {
    const root = document.getElementById('ofiReviewers');
    const list = document.getElementById('ofiReviewersList');
    const addBtn = document.getElementById('ofiAddReviewer');
    const tpl = document.getElementById('ofiReviewerRowTpl');
    if (!root || !list || !addBtn || !tpl) return;

    const max = Math.max(1, parseInt(root.getAttribute('data-max') || '9', 10) || 9);

    function rows() {
        return Array.from(list.querySelectorAll('[data-reviewer-row]'));
    }

    function renumber() {
        const items = rows();
        items.forEach((row, i) => {
            const badge = row.querySelector('[data-reviewer-index]');
            if (badge) badge.textContent = String(i + 1);
            const remove = row.querySelector('[data-reviewer-remove]');
            if (remove) remove.hidden = i === 0 || items.length < 2;

            const nameInput = row.querySelector('input[name="reviewedByName[]"]');
            const nameLabel = nameInput ? nameInput.closest('.reg-field')?.querySelector('label') : null;
            const reqHtml = ' <span class="ofi-req">*</span>';
            if (i === 0) {
                if (nameInput) nameInput.required = true;
                if (nameLabel) nameLabel.innerHTML = 'Name' + reqHtml;
            } else {
                if (nameInput) nameInput.required = false;
                if (nameLabel) nameLabel.textContent = 'Name';
            }
        });
        addBtn.hidden = items.length >= max;
    }

    addBtn.addEventListener('click', function () {
        if (rows().length >= max) return;
        const node = tpl.content.cloneNode(true);
        list.appendChild(node);
        renumber();
        const focus = list.querySelector('[data-reviewer-row]:last-child input[name="reviewedByName[]"]');
        if (focus) focus.focus();
    });

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-reviewer-remove]');
        if (!btn) return;
        const row = btn.closest('[data-reviewer-row]');
        const items = rows();
        if (!row || items.length < 2) return;
        if (items.indexOf(row) === 0) return;
        row.remove();
        renumber();
    });

    renumber();
})();
</script>

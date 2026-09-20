<script>
(function () {
    const root = document.getElementById('ofiApprovals');
    const list = document.getElementById('ofiApprovalsList');
    const addBtn = document.getElementById('ofiAddApproval');
    const tpl = document.getElementById('ofiApprovalRowTpl');
    if (!root || !list || !addBtn || !tpl) return;

    const max = Math.max(1, parseInt(root.getAttribute('data-max') || '9', 10) || 9);

    function rows() {
        return Array.from(list.querySelectorAll('[data-approval-row]'));
    }

    function renumber() {
        const items = rows();
        items.forEach((row, i) => {
            const badge = row.querySelector('[data-approval-index]');
            if (badge) badge.textContent = String(i + 1);
            const remove = row.querySelector('[data-approval-remove]');
            if (remove) remove.hidden = items.length < 2;
        });
        addBtn.hidden = items.length >= max;
    }

    addBtn.addEventListener('click', function () {
        if (rows().length >= max) return;
        const node = tpl.content.cloneNode(true);
        list.appendChild(node);
        renumber();
        const focus = list.querySelector('[data-approval-row]:last-child input[name="approvalPosition[]"]');
        if (focus) focus.focus();
    });

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-approval-remove]');
        if (!btn) return;
        const row = btn.closest('[data-approval-row]');
        if (!row || rows().length < 2) return;
        row.remove();
        renumber();
    });

    renumber();
})();
</script>

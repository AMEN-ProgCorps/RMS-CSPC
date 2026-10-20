{{--
DCS file: Polls dcs.api.office-intake.print-ready every 2 seconds and replaces the locked Print control. The route must stay on EnforceDcsIntakeAllowlist or an office user gets 403 and the button never unlocks.
--}}
@if(! ($canPrint ?? false))
<script>
(function () {
    var timer = null;
    function control() {
        return document.getElementById('ofiPrintControl');
    }
    function unlock(node) {
        if (timer) clearInterval(timer);
        var printUrl = node.getAttribute('data-print-url');
        if (!printUrl) return;
        var link = document.createElement('a');
        link.href = printUrl;
        link.target = '_blank';
        link.className = 'reg-btn reg-btn-save';
        link.innerHTML = '<i class="fa-solid fa-print"></i> Print form';
        node.replaceWith(link);
        var hint = document.getElementById('ofiPrintHint');
        if (hint) hint.remove();
    }
    async function tick() {
        var node = control();
        if (!node || node.getAttribute('data-locked') !== '1') {
            if (timer) clearInterval(timer);
            return;
        }
        var statusUrl = node.getAttribute('data-status-url');
        if (!statusUrl) return;
        try {
            var res = await fetch(statusUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            var data = await res.json();
            var ready = data && Array.isArray(data.ready) ? data.ready.map(String) : [];
            if (ready.indexOf(String(node.getAttribute('data-intake-id'))) !== -1) unlock(node);
        } catch (e) {}
    }
    timer = setInterval(tick, 1000);
    tick();
})();
</script>
@endif

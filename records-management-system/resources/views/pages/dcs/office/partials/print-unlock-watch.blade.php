{{--
DCS file: Polls dcs.api.office-intake.print-ready and replaces the locked Print control.
Pauses while the tab is hidden. The route must stay on EnforceDcsIntakeAllowlist.
--}}
@if(! ($canPrint ?? false))
<script>
(function () {
    var timer = null;
    var activeMs = 2500;
    var hiddenMs = 15000;

    function control() {
        return document.getElementById('ofiPrintControl');
    }
    function clearTimer() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }
    function schedule(ms) {
        clearTimer();
        timer = setInterval(tick, ms);
    }
    function unlock(node) {
        clearTimer();
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
        if (typeof document !== 'undefined' && document.hidden) return;
        var node = control();
        if (!node || node.getAttribute('data-locked') !== '1') {
            clearTimer();
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

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            schedule(hiddenMs);
            return;
        }
        schedule(activeMs);
        tick();
    });

    schedule(activeMs);
    tick();
})();
</script>
@endif

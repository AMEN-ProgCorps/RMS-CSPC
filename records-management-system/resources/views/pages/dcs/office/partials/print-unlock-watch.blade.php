@if(! ($canPrint ?? false))
<script>
(function () {
    var control = document.getElementById('ofiPrintControl');
    if (!control || control.getAttribute('data-locked') !== '1') return;
    var statusUrl = control.getAttribute('data-status-url');
    var printUrl = control.getAttribute('data-print-url');
    if (!statusUrl || !printUrl) return;
    var timer = null;
    function unlock() {
        if (timer) clearInterval(timer);
        var link = document.createElement('a');
        link.href = printUrl;
        link.target = '_blank';
        link.className = 'reg-btn reg-btn-save';
        link.innerHTML = '<i class="fa-solid fa-print"></i> Print form';
        control.replaceWith(link);
        var hint = document.getElementById('ofiPrintHint');
        if (hint) hint.remove();
    }
    async function tick() {
        try {
            var res = await fetch(statusUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            var data = await res.json();
            var ready = data && Array.isArray(data.ready) ? data.ready.map(String) : [];
            if (ready.indexOf(String(control.getAttribute('data-intake-id'))) !== -1) unlock();
        } catch (e) {}
    }
    timer = setInterval(tick, 2000);
    tick();
})();
</script>
@endif

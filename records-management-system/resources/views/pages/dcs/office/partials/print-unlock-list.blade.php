{{--
  DCS PARTIAL: Office Intake partial (print-unlock-list.blade.php)
  FILE: resources/views/pages/dcs/office/partials/print-unlock-list.blade.php
  DEBUG: OfficeIntakeHelper + office-intake.js / office-intake.css
--}}
{{--
DCS file: Same print-ready poll for the lock icons on My DRF and My DCN.
--}}
<script>
(function () {
    if (window.__ofiPrintUnlockListInit) {
        if (typeof window.__ofiPrintUnlockListBoot === 'function') {
            window.__ofiPrintUnlockListBoot();
        }
        return;
    }
    window.__ofiPrintUnlockListInit = true;

    var timer = null;
    var activeMs = 2000;
    var hiddenMs = 10000;

    function pending() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-print-locked]'));
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
    async function tick() {
        if (typeof document !== 'undefined' && document.hidden) return;
        var left = pending();
        if (!left.length) {
            clearTimer();
            return;
        }
        var type = left[0].getAttribute('data-intake-type');
        if (!type) return;
        var ids = left.map(function (node) { return node.getAttribute('data-intake-id'); }).filter(Boolean).join(',');
        if (!ids) return;
        try {
            var res = await fetch('/dcs/api/office-intake/' + encodeURIComponent(type) + '/print-ready?ids=' + encodeURIComponent(ids), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            var data = await res.json();
            var ready = data && Array.isArray(data.ready) ? data.ready.map(String) : [];
            left.forEach(function (node) {
                if (ready.indexOf(String(node.getAttribute('data-intake-id'))) === -1) return;
                var link = document.createElement('a');
                link.className = 'ofi-icon-action';
                link.href = node.getAttribute('data-print-url') || '#';
                link.target = '_blank';
                link.title = 'Print';
                link.innerHTML = '<i class="fa-solid fa-print"></i><span>Print</span>';
                node.replaceWith(link);
            });
            if (!pending().length) clearTimer();
        } catch (e) {}
    }

    function boot() {
        clearTimer();
        if (!pending().length) return;
        schedule(activeMs);
        tick();
    }

    window.__ofiPrintUnlockListBoot = boot;

    document.addEventListener('visibilitychange', function () {
        if (!pending().length) {
            clearTimer();
            return;
        }
        if (document.hidden) {
            schedule(hiddenMs);
            return;
        }
        schedule(activeMs);
        tick();
    });
    document.addEventListener('livewire:navigated', boot);
    window.addEventListener('focus', function () {
        if (pending().length) tick();
    });

    boot();
})();
</script>

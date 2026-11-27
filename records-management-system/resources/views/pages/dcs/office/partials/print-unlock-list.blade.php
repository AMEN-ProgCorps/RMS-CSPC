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
    var busy = false;
    var activeMs = 1500;
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
        if (busy) return;
        if (typeof document !== 'undefined' && document.hidden) return;
        var left = pending();
        if (!left.length) {
            clearTimer();
            return;
        }

        // Group locked nodes by intake type (drf / dcn)
        var byType = {};
        left.forEach(function (node) {
            var type = node.getAttribute('data-intake-type');
            var id = node.getAttribute('data-intake-id');
            if (!type || !id) return;
            if (!byType[type]) byType[type] = [];
            byType[type].push(node);
        });

        var types = Object.keys(byType);
        if (!types.length) return;

        busy = true;
        try {
            await Promise.all(types.map(async function (type) {
                var nodes = byType[type];
                var ids = nodes.map(function (n) { return n.getAttribute('data-intake-id'); }).filter(Boolean).join(',');
                if (!ids) return;
                var url = '/dcs/api/office-intake/' + encodeURIComponent(type)
                    + '/print-ready?ids=' + encodeURIComponent(ids)
                    + '&_t=' + Date.now();
                var res = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!res.ok) return;
                var data = await res.json();
                var ready = data && Array.isArray(data.ready) ? data.ready.map(String) : [];
                if (!ready.length) return;
                nodes.forEach(function (node) {
                    if (!node.isConnected) return;
                    if (ready.indexOf(String(node.getAttribute('data-intake-id'))) === -1) return;
                    var link = document.createElement('a');
                    link.className = 'ofi-icon-action';
                    link.href = node.getAttribute('data-print-url') || '#';
                    link.target = '_blank';
                    link.title = 'Print';
                    link.innerHTML = '<i class="fa-solid fa-print"></i><span>Print</span>';
                    node.replaceWith(link);
                });
            }));
            if (!pending().length) clearTimer();
        } catch (e) {
            // Keep polling on transient network errors.
        } finally {
            busy = false;
        }
    }

    function boot() {
        clearTimer();
        if (!pending().length) return;
        schedule(document.hidden ? hiddenMs : activeMs);
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
    document.addEventListener('livewire:init', function () {
        if (window.Livewire && typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('commit', function (_ref) {
                if (_ref && typeof _ref.succeed === 'function') {
                    _ref.succeed(function () {
                        queueMicrotask(function () {
                            if (pending().length) {
                                if (!timer) schedule(document.hidden ? hiddenMs : activeMs);
                                tick();
                            } else {
                                clearTimer();
                            }
                        });
                    });
                }
            });
        }
    });
    window.addEventListener('focus', function () {
        if (pending().length) {
            if (!timer) schedule(activeMs);
            tick();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>

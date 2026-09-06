import { runPdfCompare, buildCompareCacheKey } from './pdf-compare.js';
import { hashString } from './pdf-compare-cache.js';

function stableUrlForHash(url) {
    try {
        const u = new URL(url, window.location.origin);
        const pathParam = u.searchParams.get('path');
        if (pathParam) {
            return `${u.origin}${u.pathname}?path=${pathParam}`;
        }
        return u.origin + u.pathname;
    } catch {
        return String(url || '');
    }
}

function hasCompareContent(root) {
    return Boolean(
        root.querySelector(
            '[data-review-side="left"] canvas, [data-review-side="left"] img, '
            + '[data-review-side="right"] canvas, [data-review-side="right"] img'
        )
    );
}

async function runReviewCompare() {
    const root = document.getElementById('drr-pdf-compare');
    if (!root) {
        return;
    }
    const leftUrl = root.dataset.leftUrl || '';
    const rightUrl = root.dataset.rightUrl || '';
    // Need both PDFs for smart compare; view-only single PDF is rendered by the blade alone.
    if (!leftUrl || !rightUrl) {
        return;
    }

    const cacheKey = await buildCompareCacheKey(
        await hashString(stableUrlForHash(leftUrl)),
        await hashString(stableUrlForHash(rightUrl))
    );
    root.dataset.cacheKey = cacheKey;

    // Already comparing this pair — do not restart (Livewire commits caused OCR loops).
    if (root.__drrRunning && root.dataset.cacheKey === cacheKey) {
        return;
    }

    // Same pair already on screen — do not recompute.
    if (root.dataset.cacheRestored === cacheKey && hasCompareContent(root)) {
        return;
    }

    await runPdfCompare(root, { leftUrl, rightUrl, cacheKey });
    if (hasCompareContent(root)) {
        root.dataset.cacheRestored = cacheKey;
    }
}

function scheduleCompare() {
    window.clearTimeout(scheduleCompare.timer);
    scheduleCompare.timer = window.setTimeout(() => {
        runReviewCompare().catch((err) => {
            console.error('DRR compare failed to start', err);
            const root = document.getElementById('drr-pdf-compare');
            if (!root) return;
            // Don't clobber an in-progress or finished compare with a start error.
            if (root.__drrRunning || hasCompareContent(root)) return;
            let host = root.parentElement?.querySelector('[data-review-status-host="1"]');
            if (!host) {
                host = document.createElement('div');
                host.setAttribute('data-review-status-host', '1');
                host.className = 'drr-compare-status-host';
                root.parentElement?.insertBefore(host, root);
            }
            host.innerHTML = '<div class="drr-compare-status is-error" role="alert">'
                + '<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>'
                + '<span class="drr-compare-status-text">Could not start document comparison. Please refresh and try again.</span>'
                + '</div>';
            host.style.display = '';
        });
    }, 80);
}

document.addEventListener('DOMContentLoaded', scheduleCompare);
document.addEventListener('livewire:init', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => scheduleCompare());
    });
});
document.addEventListener('livewire:navigated', scheduleCompare);

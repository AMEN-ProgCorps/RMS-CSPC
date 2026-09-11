import { runPdfCompare, buildCompareCacheKey } from './pdf-compare.js';
import { hashFile, hashString } from './pdf-compare-cache.js';

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
    if (root.__drrRunning && root.dataset.activeCompareKey === cacheKey) {
        return;
    }

    // Hard OCR/compare failure for this pair — do not spin forever on Livewire commits.
    if (root.dataset.compareFailed === cacheKey) {
        return;
    }

    // Same pair already on screen — do not recompute.
    if (root.dataset.cacheRestored === cacheKey && hasCompareContent(root)) {
        return;
    }

    root.dataset.activeCompareKey = cacheKey;
    delete root.dataset.compareFailed;

    await runPdfCompare(root, { leftUrl, rightUrl, cacheKey });
    if (hasCompareContent(root)) {
        root.dataset.cacheRestored = cacheKey;
        delete root.dataset.compareFailed;
    } else if (root.dataset.compareFailed !== cacheKey) {
        // Soft fail without explicit flag — still avoid immediate relaunch loops.
        root.dataset.compareFailed = cacheKey;
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

/* ── Ad-hoc: compare two uploaded PDFs (no registration) ── */

let adhocLeftUrl = '';
let adhocRightUrl = '';

function isPdfFile(file) {
    if (!file) return false;
    const name = String(file.name || '').toLowerCase();
    return file.type === 'application/pdf' || name.endsWith('.pdf');
}

function setAdhocError(msg) {
    const el = document.getElementById('drrAdhocError');
    if (!el) return;
    if (!msg) {
        el.hidden = true;
        el.textContent = '';
        return;
    }
    el.hidden = false;
    el.textContent = msg;
}

function refreshAdhocRunState() {
    const left = document.getElementById('drrAdhocLeftFile')?.files?.[0] || null;
    const right = document.getElementById('drrAdhocRightFile')?.files?.[0] || null;
    const btn = document.getElementById('drrAdhocRun');
    const leftName = document.getElementById('drrAdhocLeftName');
    const rightName = document.getElementById('drrAdhocRightName');
    const leftDrop = document.getElementById('drrAdhocLeftDrop');
    const rightDrop = document.getElementById('drrAdhocRightDrop');
    if (leftName) leftName.textContent = left ? left.name : 'No file chosen';
    if (rightName) rightName.textContent = right ? right.name : 'No file chosen';
    leftDrop?.classList.toggle('has-file', Boolean(left));
    rightDrop?.classList.toggle('has-file', Boolean(right));
    if (btn) btn.disabled = !(left && right);
}

function assignFileToInput(input, file) {
    if (!input || !file) return;
    try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (err) {
        console.warn('Could not assign dropped file', err);
    }
}

function bindDropZone(dropEl, input) {
    if (!dropEl || !input || dropEl.dataset.dropBound === '1') return;
    dropEl.dataset.dropBound = '1';

    ['dragenter', 'dragover'].forEach((evt) => {
        dropEl.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropEl.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach((evt) => {
        dropEl.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropEl.classList.remove('is-dragover');
        });
    });
    dropEl.addEventListener('drop', (e) => {
        const file = e.dataTransfer?.files?.[0] || null;
        if (!file) return;
        if (!isPdfFile(file)) {
            setAdhocError('Both files must be PDFs.');
            return;
        }
        setAdhocError('');
        assignFileToInput(input, file);
    });
}

function revokeAdhocUrls() {
    if (adhocLeftUrl) {
        URL.revokeObjectURL(adhocLeftUrl);
        adhocLeftUrl = '';
    }
    if (adhocRightUrl) {
        URL.revokeObjectURL(adhocRightUrl);
        adhocRightUrl = '';
    }
}

function openAdhocModal() {
    const modal = document.getElementById('drrAdhocModal');
    if (!modal) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeAdhocModal() {
    const modal = document.getElementById('drrAdhocModal');
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    revokeAdhocUrls();
    const root = document.getElementById('drr-adhoc-pdf-compare');
    if (root) {
        root.querySelectorAll('[data-review-side]').forEach((stage) => {
            stage.innerHTML = '';
        });
        root.querySelectorAll('[data-review-note]').forEach((note) => {
            note.textContent = '';
        });
        delete root.dataset.cacheKey;
        delete root.dataset.activeCompareKey;
        delete root.dataset.compareFailed;
        delete root.dataset.cacheRestored;
        root.__drrRunning = false;
    }
}

async function runAdhocCompare() {
    const leftFile = document.getElementById('drrAdhocLeftFile')?.files?.[0] || null;
    const rightFile = document.getElementById('drrAdhocRightFile')?.files?.[0] || null;
    const runBtn = document.getElementById('drrAdhocRun');
    setAdhocError('');

    if (!leftFile || !rightFile) {
        setAdhocError('Choose both PDF files to compare.');
        return;
    }
    if (!isPdfFile(leftFile) || !isPdfFile(rightFile)) {
        setAdhocError('Both files must be PDFs.');
        return;
    }

    revokeAdhocUrls();
    adhocLeftUrl = URL.createObjectURL(leftFile);
    adhocRightUrl = URL.createObjectURL(rightFile);

    const leftLabel = document.getElementById('drrAdhocLeftLabel');
    const rightLabel = document.getElementById('drrAdhocRightLabel');
    if (leftLabel) leftLabel.textContent = `Older / original · ${leftFile.name}`;
    if (rightLabel) rightLabel.textContent = `Newer / revised · ${rightFile.name}`;

    openAdhocModal();

    const root = document.getElementById('drr-adhoc-pdf-compare');
    if (!root) return;

    if (runBtn) {
        runBtn.disabled = true;
        runBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Comparing…';
    }

    try {
        const cacheKey = await buildCompareCacheKey(
            await hashFile(leftFile),
            await hashFile(rightFile)
        );
        root.dataset.cacheKey = cacheKey;
        root.dataset.activeCompareKey = cacheKey;
        delete root.dataset.compareFailed;
        delete root.dataset.cacheRestored;

        await runPdfCompare(root, {
            leftUrl: adhocLeftUrl,
            rightUrl: adhocRightUrl,
            leftFile,
            rightFile,
            cacheKey,
        });
    } catch (err) {
        console.error('Ad-hoc DRR compare failed', err);
        setAdhocError('Comparison failed. Please try again with different PDFs.');
        closeAdhocModal();
    } finally {
        if (runBtn) {
            runBtn.disabled = false;
            runBtn.innerHTML = '<i class="fa-solid fa-code-compare"></i> Compare files';
            refreshAdhocRunState();
        }
    }
}

function bindAdhocCompare() {
    const leftInput = document.getElementById('drrAdhocLeftFile');
    const rightInput = document.getElementById('drrAdhocRightFile');
    const runBtn = document.getElementById('drrAdhocRun');
    const closeBtn = document.getElementById('drrAdhocClose');
    const modal = document.getElementById('drrAdhocModal');
    const leftDrop = document.getElementById('drrAdhocLeftDrop');
    const rightDrop = document.getElementById('drrAdhocRightDrop');

    if (!leftInput || !rightInput || !runBtn) {
        return;
    }
    if (runBtn.dataset.adhocBound === '1') {
        refreshAdhocRunState();
        return;
    }
    runBtn.dataset.adhocBound = '1';

    bindDropZone(leftDrop, leftInput);
    bindDropZone(rightDrop, rightInput);

    leftInput.addEventListener('change', () => {
        setAdhocError('');
        refreshAdhocRunState();
    });
    rightInput.addEventListener('change', () => {
        setAdhocError('');
        refreshAdhocRunState();
    });
    runBtn.addEventListener('click', () => {
        runAdhocCompare().catch((err) => console.error(err));
    });

    closeBtn?.addEventListener('click', closeAdhocModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeAdhocModal();
    });

    refreshAdhocRunState();
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('drrAdhocModal');
        if (modal && !modal.hidden) {
            closeAdhocModal();
        }
    }
});


document.addEventListener('DOMContentLoaded', () => {
    scheduleCompare();
    bindAdhocCompare();
});
document.addEventListener('livewire:init', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => {
            scheduleCompare();
            // Re-bind after Livewire morphs the list view DOM.
            bindAdhocCompare();
        });
    });
});
document.addEventListener('livewire:navigated', () => {
    scheduleCompare();
    bindAdhocCompare();
});

import { runPdfCompare, buildCompareCacheKey } from './pdf-compare.js';
import { hashFile, hashString } from './pdf-compare-cache.js';

function stableUrlForHash(url) {
    try {
        const u = new URL(url, window.location.origin);
        return u.origin + u.pathname;
    } catch {
        return String(url || '');
    }
}

window.runRegisterPdfCompare = async function runRegisterPdfCompare(leftUrl, rightUrl, options = {}) {
    const root = document.getElementById('registerPdfCompare');
    if (!root) {
        return;
    }
    root.dataset.leftUrl = leftUrl || '';
    root.dataset.rightUrl = rightUrl || '';

    const rightFile = options.rightFile || document.getElementById('uploadScannedCopy')?.files?.[0] || null;
    let cacheKey = options.cacheKey || '';
    if (!cacheKey) {
        // Key by previous PDF path + uploaded file bytes — NOT blob: URLs (those change every open).
        const leftPart = await hashString(stableUrlForHash(leftUrl || ''));
        const rightPart = rightFile ? await hashFile(rightFile) : await hashString(stableUrlForHash(rightUrl || ''));
        cacheKey = await buildCompareCacheKey(leftPart, rightPart);
    }
    root.dataset.cacheKey = cacheKey;

    return runPdfCompare(root, {
        leftUrl,
        rightUrl,
        rightFile,
        cacheKey,
    });
};

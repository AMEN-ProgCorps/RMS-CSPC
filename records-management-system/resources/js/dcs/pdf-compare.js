import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { getCompareCache, putCompareCache, hashString, peekMemoryCompareCache } from './pdf-compare-cache.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const FILL = {
    del: 'rgba(252, 165, 165, 0.45)',
    ins: 'rgba(134, 239, 172, 0.45)',
    chg: 'rgba(253, 230, 138, 0.5)',
};

const CHUNK_SIZE = 40;
const OCR_BATCH = 3;
const TOKEN_CAP = 1500;
const SIG_WORDS = 64;
/** Minimum Jaccard score to pair pages across revisions (handles moved content). */
const ALIGN_MIN_SCORE = 0.14;
const CACHE_VERSION = 5;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function lcsOps(a, b, eqFn) {
    const equal = eqFn || ((x, y) => x === y);
    const n = a.length;
    const m = b.length;
    const dp = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1));
    for (let i = 1; i <= n; i++) {
        for (let j = 1; j <= m; j++) {
            dp[i][j] = equal(a[i - 1], b[j - 1])
                ? dp[i - 1][j - 1] + 1
                : Math.max(dp[i - 1][j], dp[i][j - 1]);
        }
    }
    const ops = [];
    let i = n;
    let j = m;
    while (i > 0 || j > 0) {
        if (i > 0 && j > 0 && equal(a[i - 1], b[j - 1])) {
            ops.push({ k: 'eq', left: a[i - 1], right: b[j - 1], li: i - 1, ri: j - 1 });
            i--;
            j--;
        } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
            ops.push({ k: 'ins', right: b[j - 1], ri: j - 1 });
            j--;
        } else {
            ops.push({ k: 'del', left: a[i - 1], li: i - 1 });
            i--;
        }
    }
    return ops.reverse();
}

function normalizeWord(w) {
    return String(w || '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, '');
}

function tokensFromItems(items) {
    return items
        .map((item) => ({ t: (item.str || '').trim(), item, norm: normalizeWord(item.str), box: null }))
        .filter((row) => row.t !== '' && row.norm !== '');
}

function tokensFromPlainText(text) {
    return String(text || '')
        .split(/\s+/)
        .map((t) => t.trim())
        .filter(Boolean)
        .map((t) => ({ t, item: null, norm: normalizeWord(t), ocr: true, box: null }))
        .filter((row) => row.norm !== '');
}

/** OCR words with normalized page boxes (0–1) for on-PDF highlighting. */
function tokensFromOcrWords(words) {
    return (Array.isArray(words) ? words : [])
        .map((w) => {
            const t = String(w?.t || '').trim();
            const norm = normalizeWord(t);
            const x = Number(w?.x);
            const y = Number(w?.y);
            const bw = Number(w?.w);
            const bh = Number(w?.h);
            const hasBox = [x, y, bw, bh].every((n) => Number.isFinite(n));
            return {
                t,
                norm,
                ocr: true,
                item: null,
                box: hasBox ? { x, y, w: Math.max(bw, 0.002), h: Math.max(bh, 0.004) } : null,
            };
        })
        .filter((row) => row.t !== '' && row.norm !== '');
}

function pageSignature(tokens) {
    const words = tokens.map((t) => t.norm).filter(Boolean);
    if (!words.length) {
        return '';
    }
    const head = words.slice(0, SIG_WORDS);
    const mid = words.slice(Math.max(0, Math.floor(words.length / 2) - 10), Math.floor(words.length / 2) + 10);
    const tail = words.slice(-Math.min(SIG_WORDS, words.length));
    return [...head, '|', ...mid, '|', ...tail].join(' ');
}

function signaturesSimilar(a, b) {
    if (!a || !b) return false;
    if (a === b) return true;
    const aw = new Set(a.split(/\s+/).filter((w) => w && w !== '|'));
    const bw = b.split(/\s+/).filter((w) => w && w !== '|');
    if (!aw.size || !bw.length) return false;
    let hit = 0;
    for (const w of bw) {
        if (aw.has(w)) hit++;
    }
    return hit / Math.max(aw.size, bw.length) >= 0.28;
}

function pageAnchors(tokens) {
    const joined = (tokens || []).map((t) => t.norm).join(' ');
    const found = new Set();
    const patterns = [
        /article\s*(i{1,3}|iv|v|vi{0,3}|ix|x|[0-9]{1,2})\b/g,
        /section\s*([0-9]{1,2})\b/g,
        /annex\s*([a-z0-9]{1,2})\b/g,
    ];
    for (const re of patterns) {
        let m;
        while ((m = re.exec(joined)) !== null) {
            const kind = m[0].startsWith('article') ? 'article' : (m[0].startsWith('section') ? 'section' : 'annex');
            found.add(kind + String(m[1] || '').replace(/\s+/g, ''));
        }
    }
    return found;
}

function pageContentScore(leftPage, rightPage) {
    const aw = leftPage?.tokens || [];
    const bw = rightPage?.tokens || [];
    if (!aw.length || !bw.length) return 0;

    const aList = aw.map((t) => t.norm).filter(Boolean);
    const bList = bw.map((t) => t.norm).filter(Boolean);
    const aSet = new Set(aList);
    const bSet = new Set(bList);
    if (!aSet.size || !bSet.size) return 0;

    let hit = 0;
    for (const w of bSet) {
        if (aSet.has(w)) hit++;
    }
    // Use the smaller page as denominator so a moved section still scores high
    // even when surrounding page content differs.
    let score = hit / Math.min(aSet.size, bSet.size);

    const rareA = [...aSet].filter((w) => w.length >= 6);
    const rareHits = rareA.filter((w) => bSet.has(w)).length;
    if (rareA.length) {
        const rareRatio = rareHits / rareA.length;
        score = Math.max(score, rareRatio);
        if (rareHits >= 10) score = Math.max(score, 0.45);
        else if (rareHits >= 5) score = Math.max(score, 0.28);
    }

    // Shared structural headings (Article I, Section 1, Annex B, …)
    const leftAnchors = pageAnchors(aw);
    const rightAnchors = pageAnchors(bw);
    let anchorHits = 0;
    for (const a of leftAnchors) {
        if (rightAnchors.has(a)) anchorHits++;
    }
    if (anchorHits > 0 && leftAnchors.size && rightAnchors.size) {
        score = Math.max(score, 0.55 + 0.1 * Math.min(anchorHits, 3));
    }

    // Overlapping 4-word phrases (strong signal for moved paragraphs)
    const grams = (list) => {
        const out = new Set();
        for (let i = 0; i < list.length - 3; i++) {
            out.add(list.slice(i, i + 4).join(' '));
        }
        return out;
    };
    const ga = grams(aList);
    const gb = grams(bList);
    if (ga.size && gb.size) {
        let gHit = 0;
        for (const g of gb) {
            if (ga.has(g)) gHit++;
        }
        const gRatio = gHit / Math.min(ga.size, gb.size);
        if (gRatio >= 0.08) score = Math.max(score, 0.5);
        if (gRatio >= 0.15) score = Math.max(score, 0.65);
    }

    if (signaturesSimilar(leftPage.signature, rightPage.signature)) {
        score = Math.max(score, 0.42);
    }
    return Math.min(score, 1);
}

function editDistanceAtMost1(a, b) {
    if (a === b) return true;
    const n = a.length;
    const m = b.length;
    if (Math.abs(n - m) > 1) return false;
    let i = 0;
    let j = 0;
    let edits = 0;
    while (i < n && j < m) {
        if (a[i] === b[j]) {
            i++;
            j++;
            continue;
        }
        if (++edits > 1) return false;
        if (n > m) i++;
        else if (m > n) j++;
        else {
            i++;
            j++;
        }
    }
    return true;
}

/** OCR-tolerant word equality for smarter in-page diffs. */
function tokensEqual(a, b) {
    if (!a || !b) return false;
    if (a.norm === b.norm) return true;
    const x = a.norm;
    const y = b.norm;
    if (!x || !y) return false;
    if (x.length < 4 || y.length < 4) return false;
    if (x.length >= 6 && y.length >= 6) {
        const shorter = x.length <= y.length ? x : y;
        const longer = x.length <= y.length ? y : x;
        if (longer.startsWith(shorter) && shorter.length / longer.length >= 0.75) return true;
    }
    return editDistanceAtMost1(x, y);
}

function transform(m1, m2) {
    return [
        m1[0] * m2[0] + m1[2] * m2[1],
        m1[1] * m2[0] + m1[3] * m2[1],
        m1[0] * m2[2] + m1[2] * m2[3],
        m1[1] * m2[2] + m1[3] * m2[3],
        m1[0] * m2[4] + m1[2] * m2[5] + m1[4],
        m1[1] * m2[4] + m1[3] * m2[5] + m1[5],
    ];
}

function highlightItem(ctx, viewport, item, color) {
    if (!item || !viewport) return;
    const tx = transform(viewport.transform, item.transform);
    const x = tx[4];
    const y = tx[5];
    const width = item.width * viewport.scale;
    const height = Math.abs(item.height * viewport.scale) || 10;
    ctx.fillStyle = color;
    ctx.fillRect(x, y - height, Math.max(width, 4), height);
}

/** Paint a mark on the PDF canvas (embedded text item or OCR normalized box). */
function paintMark(ctx, viewport, mark, color) {
    if (!mark) return;
    if (mark.box && Number.isFinite(mark.box.x)) {
        const cw = ctx.canvas.width;
        const ch = ctx.canvas.height;
        ctx.fillStyle = color;
        ctx.fillRect(
            mark.box.x * cw,
            mark.box.y * ch,
            Math.max(mark.box.w * cw, 3),
            Math.max(mark.box.h * ch, 3)
        );
        return;
    }
    if (mark.item) {
        highlightItem(ctx, viewport, mark.item, color);
    }
}

function markFromToken(k, token) {
    if (!token) return null;
    if (token.box) return { k, box: token.box };
    if (token.item) return { k, item: token.item };
    return null;
}

function allTokenMarks(tokens, k) {
    return (tokens || [])
        .map((tok) => markFromToken(k, tok))
        .filter(Boolean);
}

async function pageItems(doc, pageNumber) {
    if (!doc || pageNumber > doc.numPages) {
        return [];
    }
    const page = await doc.getPage(pageNumber);
    const content = await page.getTextContent();
    return content.items || [];
}

function storagePathFromUrl(url) {
    if (!url) return '';
    try {
        const u = new URL(url, window.location.origin);
        const m = u.pathname.match(/\/storage\/(.+)$/);
        return m ? decodeURIComponent(m[1]) : '';
    } catch {
        const m = String(url).match(/\/storage\/(.+)$/);
        return m ? decodeURIComponent(m[1]) : '';
    }
}

async function ocrPageBatch({ file, storagePath, pages }) {
    if (!pages.length) return [];
    const body = new FormData();
    pages.forEach((p) => body.append('pages[]', String(p)));
    if (file) {
        body.append('file', file);
    } else if (storagePath) {
        body.append('storage_path', storagePath);
    } else {
        return pages.map((page) => ({ page, text: '', words: [], used_ocr: true, ok: false }));
    }

    let res;
    try {
        res = await fetch('/dcs/api/drr/ocr-pages', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body,
            credentials: 'same-origin',
        });
    } catch (err) {
        const error = new Error(friendlyError(err, 'OCR request failed.'));
        error.cause = err;
        throw error;
    }

    if (!res.ok) {
        let detail = '';
        try {
            const data = await res.json();
            detail = data?.message || data?.reason || '';
        } catch {
            // ignore body parse errors
        }
        throw new Error(
            detail
                ? `OCR failed (${res.status}): ${detail}`
                : `OCR failed with HTTP ${res.status}. Scanned pages may not highlight correctly.`
        );
    }

    const data = await res.json();
    if (data && data.ok === false) {
        throw new Error(data.message || data.reason || 'OCR could not process these pages.');
    }
    return Array.isArray(data.pages) ? data.pages : [];
}

async function ensurePageTexts(doc, sideMeta, setStatus) {
    const pages = [];
    const missing = [];
    const warnings = [];

    for (let p = 1; p <= doc.numPages; p++) {
        const items = tokensFromItems(await pageItems(doc, p)).slice(0, TOKEN_CAP);
        const entry = {
            page: p,
            tokens: items,
            signature: pageSignature(items),
            usedOcr: false,
            hasText: items.length > 0,
        };
        pages.push(entry);
        if (!entry.hasText) {
            missing.push(p);
        }
    }

    for (let i = 0; i < missing.length; i += OCR_BATCH) {
        const batch = missing.slice(i, i + OCR_BATCH);
        if (setStatus) {
            setStatus(
                `OCR ${sideMeta.label}: pages ${batch[0]}–${batch[batch.length - 1]} of ${doc.numPages}…`,
                'loading'
            );
        }

        let results = [];
        try {
            results = await ocrPageBatch({
                file: sideMeta.file || null,
                storagePath: sideMeta.storagePath || '',
                pages: batch,
            });
        } catch (err) {
            warnings.push(friendlyError(err, `OCR failed for ${sideMeta.label} pages ${batch[0]}–${batch[batch.length - 1]}.`));
            results = batch.map((page) => ({ page, text: '', words: [], used_ocr: true, ok: false }));
        }

        const byPage = new Map(results.map((r) => [Number(r.page), r]));
        for (const pageNo of batch) {
            const row = byPage.get(pageNo);
            let tokens = tokensFromOcrWords(row?.words).slice(0, TOKEN_CAP);
            if (!tokens.length) {
                tokens = tokensFromPlainText(row?.text || '').slice(0, TOKEN_CAP);
            }
            const idx = pageNo - 1;
            pages[idx] = {
                page: pageNo,
                tokens,
                signature: pageSignature(tokens),
                usedOcr: true,
                hasText: tokens.length > 0,
            };
        }
    }

    pages.__ocrWarnings = warnings;
    return pages;
}

/**
 * Content-aware page alignment (never forced page-index pairing).
 * Finds the best matching new page for each old page by text similarity so
 * moved sections (e.g. Article I on old p.10 → new p.3) still align.
 * Display order follows the LATEST pages so every new page stays visible.
 */
function alignPages(leftPages, rightPages) {
    const candidates = [];
    for (let i = 0; i < leftPages.length; i++) {
        for (let j = 0; j < rightPages.length; j++) {
            const score = pageContentScore(leftPages[i], rightPages[j]);
            if (score >= ALIGN_MIN_SCORE) {
                candidates.push({ i, j, score });
            }
        }
    }
    candidates.sort((a, b) => b.score - a.score || Math.abs(a.i - a.j) - Math.abs(b.i - b.j));

    const usedL = new Set();
    const usedR = new Set();
    const matches = [];
    for (const c of candidates) {
        if (usedL.has(c.i) || usedR.has(c.j)) continue;
        usedL.add(c.i);
        usedR.add(c.j);
        matches.push(c);
    }

    const alignment = [];
    const matchByRight = new Map(matches.map((m) => [m.j, m]));

    for (let j = 0; j < rightPages.length; j++) {
        const m = matchByRight.get(j);
        if (m) {
            alignment.push({
                type: 'match',
                leftPage: leftPages[m.i].page,
                rightPage: rightPages[j].page,
                left: leftPages[m.i],
                right: rightPages[j],
            });
        } else {
            alignment.push({
                type: 'added',
                leftPage: null,
                rightPage: rightPages[j].page,
                left: null,
                right: rightPages[j],
            });
        }
    }

    for (let i = 0; i < leftPages.length; i++) {
        if (usedL.has(i)) continue;
        alignment.push({
            type: 'removed',
            leftPage: leftPages[i].page,
            rightPage: null,
            left: leftPages[i],
            right: null,
        });
    }

    return alignment;
}

/** Word LCS → marks painted on both old and new PDF canvases. */
function wordDiffMarks(leftTokens, rightTokens) {
    const ops = lcsOps(leftTokens, rightTokens, tokensEqual);
    const leftMarks = [];
    const rightMarks = [];
    let del = 0;
    let ins = 0;
    let chg = 0;

    for (let i = 0; i < ops.length; i++) {
        const op = ops[i];
        const next = ops[i + 1];
        if (op.k === 'eq') continue;

        if (op.k === 'del' && next && next.k === 'ins') {
            const lm = markFromToken('chg', op.left);
            const rm = markFromToken('chg', next.right);
            if (lm) leftMarks.push(lm);
            if (rm) rightMarks.push(rm);
            chg++;
            i++;
            continue;
        }
        if (op.k === 'del') {
            const lm = markFromToken('del', op.left);
            if (lm) leftMarks.push(lm);
            del++;
        } else if (op.k === 'ins') {
            const rm = markFromToken('ins', op.right);
            if (rm) rightMarks.push(rm);
            ins++;
        }
    }

    return { leftMarks, rightMarks, del, ins, chg };
}

async function renderPdfPage(doc, pageNumber, width, marks, frameClass) {
    const wrap = document.createElement('div');
    wrap.className = 'drr-page-wrap ' + (frameClass || '');
    if (!doc || !pageNumber) {
        wrap.innerHTML = '<div class="drr-page-empty">No page</div>';
        return wrap;
    }
    const page = await doc.getPage(pageNumber);
    const base = page.getViewport({ scale: 1 });
    const viewport = page.getViewport({ scale: width / base.width });
    const canvas = document.createElement('canvas');
    canvas.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    const ctx = canvas.getContext('2d');
    await page.render({ canvasContext: ctx, viewport }).promise;

    const list = marks || [];
    if (list.length) {
        list.forEach((mark) => paintMark(ctx, viewport, mark, FILL[mark.k] || FILL.chg));
    } else if (frameClass === 'is-added') {
        // Whole new page with no word boxes — still show a green wash so it isn't "blank".
        ctx.fillStyle = 'rgba(134, 239, 172, 0.18)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    } else if (frameClass === 'is-removed') {
        ctx.fillStyle = 'rgba(252, 165, 165, 0.18)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    wrap.appendChild(canvas);
    return wrap;
}

function captionForSlot(slot) {
    if (slot.type === 'match') {
        if (slot.leftPage === slot.rightPage) {
            return `Old p.${slot.leftPage} ↔ New p.${slot.rightPage}`;
        }
        return `Old p.${slot.leftPage} → New p.${slot.rightPage} (moved)`;
    }
    if (slot.type === 'removed') {
        return `Removed · Old p.${slot.leftPage}`;
    }
    return `Added · New p.${slot.rightPage}`;
}

function summaryForSlot(slot, stats) {
    const bits = [];
    if (slot.type === 'removed') bits.push('Entire page removed');
    if (slot.type === 'added') bits.push('Entire page added');
    if (stats) {
        if (stats.chg) bits.push(`${stats.chg} changed`);
        if (stats.del) bits.push(`${stats.del} removed`);
        if (stats.ins) bits.push(`${stats.ins} added`);
    }
    if (slot.left?.usedOcr || slot.right?.usedOcr) bits.push('OCR used');
    return bits.join(' · ');
}

function setStatus(root, text, type = 'loading') {
    let host = root.parentElement?.querySelector('[data-review-status-host="1"]');
    if (!host) {
        host = document.createElement('div');
        host.setAttribute('data-review-status-host', '1');
        host.className = 'drr-compare-status-host';
        root.parentElement?.insertBefore(host, root);
    }

    let el = host.querySelector('[data-review-status]');
    if (!el) {
        el = document.createElement('div');
        el.className = 'drr-compare-status reg-compare-status';
        el.setAttribute('data-review-status', '1');
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        host.appendChild(el);
    }

    if (!text) {
        el.innerHTML = '';
        host.style.display = 'none';
        root.classList.remove('is-comparing', 'has-compare-error');
        return;
    }

    const kind = type === 'error' || type === 'success' || type === 'info' ? type : 'loading';
    el.dataset.state = kind;
    el.classList.toggle('is-error', kind === 'error');
    el.classList.toggle('is-success', kind === 'success');
    el.classList.toggle('is-loading', kind === 'loading');

    root.classList.toggle('is-comparing', kind === 'loading');
    root.classList.toggle('has-compare-error', kind === 'error');

    if (kind === 'loading') {
        el.innerHTML = '<span class="drr-compare-spinner" aria-hidden="true"></span>'
            + `<span class="drr-compare-status-text">${escapeAttr(text)}</span>`;
    } else if (kind === 'error') {
        el.innerHTML = '<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>'
            + `<span class="drr-compare-status-text">${escapeAttr(text)}</span>`;
    } else if (kind === 'success') {
        el.innerHTML = '<i class="fa-solid fa-circle-check" aria-hidden="true"></i>'
            + `<span class="drr-compare-status-text">${escapeAttr(text)}</span>`;
    } else {
        el.innerHTML = `<span class="drr-compare-status-text">${escapeAttr(text)}</span>`;
    }
    host.style.display = '';
}

function escapeAttr(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function showStagePlaceholder(stage, message) {
    if (!stage) return;
    stage.innerHTML = '';
    const ph = document.createElement('div');
    ph.className = 'drr-page-empty drr-compare-placeholder';
    ph.innerHTML = '<span class="drr-compare-spinner" aria-hidden="true"></span>'
        + `<span>${escapeAttr(message || 'Loading…')}</span>`;
    stage.appendChild(ph);
}

function friendlyError(err, fallback) {
    const msg = String(err?.message || err || '').trim();
    if (!msg) return fallback || 'Something went wrong while comparing.';
    if (/Failed to fetch|NetworkError|network/i.test(msg)) {
        return 'Network error while comparing. Check your connection and try again.';
    }
    if (/timeout/i.test(msg)) {
        return 'Comparison timed out. Try again with a smaller PDF or fewer pages.';
    }
    if (/Invalid PDF|Missing PDF|pdf/i.test(msg)) {
        return 'Could not read one of the PDF files. Re-upload the scanned masterlist and try again.';
    }
    return fallback || msg;
}

async function canvasToBlob(canvas) {
    return new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), 'image/png', 0.85);
    });
}

async function paintCached(root, cached) {
    const leftStage = root.querySelector('[data-review-side="left"]');
    const rightStage = root.querySelector('[data-review-side="right"]');
    const leftNote = root.querySelector('[data-review-note="left"]');
    const rightNote = root.querySelector('[data-review-note="right"]');

    if (!leftStage || !rightStage) return false;
    if (!(cached.leftPages?.length) && !(cached.rightPages?.length)) return false;
    // v4 = no blank placeholders; sides are independent page streams
    if (Number(cached.version) < CACHE_VERSION) return false;

    leftStage.innerHTML = '';
    rightStage.innerHTML = '';

    for (const blob of (cached.leftPages || [])) {
        if (!blob) continue;
        const wrap = document.createElement('div');
        wrap.className = 'drr-page-wrap';
        const img = document.createElement('img');
        img.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
        img.src = URL.createObjectURL(blob);
        wrap.appendChild(img);
        leftStage.appendChild(wrap);
    }

    for (const blob of (cached.rightPages || [])) {
        if (!blob) continue;
        const wrap = document.createElement('div');
        wrap.className = 'drr-page-wrap';
        const img = document.createElement('img');
        img.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
        img.src = URL.createObjectURL(blob);
        wrap.appendChild(img);
        rightStage.appendChild(wrap);
    }

    if (leftNote) leftNote.textContent = cached.leftNote || '';
    if (rightNote) rightNote.textContent = cached.rightNote || '';
    return true;
}

/**
 * Smart PDF comparison:
 * - content-based page pairing (handles moved pages)
 * - word LCS highlights painted on both PDF canvases
 * - OCR with word boxes for scanned PDFs
 * - IndexedDB cache + chunked render
 */
export async function runPdfCompare(root, options = {}) {
    if (!root) return;

    const leftUrl = options.leftUrl || root.dataset.leftUrl || '';
    const rightUrl = options.rightUrl || root.dataset.rightUrl || '';
    const leftFile = options.leftFile || null;
    const rightFile = options.rightFile || null;
    const cacheKey = options.cacheKey || root.dataset.cacheKey || '';

    const leftStage = root.querySelector('[data-review-side="left"]');
    const rightStage = root.querySelector('[data-review-side="right"]');
    const leftNote = root.querySelector('[data-review-note="left"]');
    const rightNote = root.querySelector('[data-review-note="right"]');

    if (leftStage) leftStage.innerHTML = '';
    if (rightStage) rightStage.innerHTML = '';
    if (leftNote) leftNote.textContent = '';
    if (rightNote) rightNote.textContent = '';

    root.__drrAbort = false;
    showStagePlaceholder(leftStage, 'Preparing comparison…');
    showStagePlaceholder(rightStage, 'Preparing comparison…');
    setStatus(root, 'Starting document comparison…', 'loading');

    try {
        if (cacheKey) {
            try {
                const memHit = peekMemoryCompareCache(cacheKey);
                if (memHit) {
                    setStatus(root, 'Restoring comparison from this session…', 'loading');
                    const ok = await paintCached(root, memHit);
                    if (ok) {
                        setStatus(root, 'Comparison restored from local cache.', 'success');
                        return;
                    }
                }

                setStatus(root, 'Checking local compare cache…', 'loading');
                const cached = await getCompareCache(cacheKey);
                // Trust cacheKey identity (file/URL hashes). Do NOT require leftUrl/rightUrl
                // equality — blob: URLs change every time the revised compare modal opens.
                if (cached) {
                    const ok = await paintCached(root, cached);
                    if (ok) {
                        setStatus(root, 'Comparison restored from local cache.', 'success');
                        return;
                    }
                }
            } catch (err) {
                console.warn('DRR cache restore failed; recomputing.', err);
            }
        }

        if (!leftUrl || !rightUrl) {
            if (leftStage) leftStage.innerHTML = '';
            if (rightStage) rightStage.innerHTML = '';
            setStatus(root, 'Both revisions need a masterlist PDF to compare.', 'error');
            return;
        }

        setStatus(root, 'Loading PDFs…', 'loading');
        showStagePlaceholder(leftStage, 'Loading previous PDF…');
        showStagePlaceholder(rightStage, 'Loading latest PDF…');

        let leftDoc = null;
        let rightDoc = null;
        let leftLoadError = null;
        let rightLoadError = null;

        try {
            leftDoc = await pdfjsLib.getDocument({ url: leftUrl }).promise;
        } catch (err) {
            leftLoadError = err;
        }
        try {
            rightDoc = await pdfjsLib.getDocument({ url: rightUrl }).promise;
        } catch (err) {
            rightLoadError = err;
        }

        if (!leftDoc || !rightDoc) {
            if (leftStage) {
                leftStage.innerHTML = '';
                const ph = document.createElement('div');
                ph.className = 'drr-page-empty';
                ph.textContent = leftLoadError
                    ? 'Could not load previous PDF.'
                    : '—';
                leftStage.appendChild(ph);
            }
            if (rightStage) {
                rightStage.innerHTML = '';
                const ph = document.createElement('div');
                ph.className = 'drr-page-empty';
                ph.textContent = rightLoadError
                    ? 'Could not load latest PDF.'
                    : '—';
                rightStage.appendChild(ph);
            }
            if (leftNote) leftNote.textContent = leftLoadError ? 'Failed to load previous PDF' : '';
            if (rightNote) rightNote.textContent = rightLoadError ? 'Failed to load latest PDF' : '';
            setStatus(
                root,
                friendlyError(
                    leftLoadError || rightLoadError,
                    'Could not load one or both PDF files for comparison.'
                ),
                'error'
            );
            return;
        }

        const leftMeta = {
            label: 'previous',
            file: leftFile,
            storagePath: storagePathFromUrl(leftUrl),
        };
        const rightMeta = {
            label: 'new',
            file: rightFile,
            storagePath: storagePathFromUrl(rightUrl),
        };

        setStatus(root, 'Reading text (OCR scanned pages if needed)…', 'loading');
        showStagePlaceholder(leftStage, 'Reading previous PDF…');
        showStagePlaceholder(rightStage, 'Reading latest PDF…');

        const statusFn = (t, kind = 'loading') => setStatus(root, t, kind);
        // Full OCR first so content alignment can match moved sections across page numbers.
        const leftPages = await ensurePageTexts(leftDoc, leftMeta, statusFn);
        if (root.__drrAbort) {
            setStatus(root, 'Comparison cancelled.', 'info');
            return;
        }
        const rightPages = await ensurePageTexts(rightDoc, rightMeta, statusFn);

        if (root.__drrAbort) {
            setStatus(root, 'Comparison cancelled.', 'info');
            return;
        }

        setStatus(root, 'Aligning pages by content…', 'loading');
        if (leftStage) leftStage.innerHTML = '';
        if (rightStage) rightStage.innerHTML = '';

        const alignment = alignPages(leftPages, rightPages);
        const width = Math.max(leftStage?.clientWidth || rightStage?.clientWidth || 480, 280);

        const leftBlobs = [];
        const rightBlobs = [];
        let ocrUsed = false;
        let matchCount = 0;
        let removedCount = 0;
        let addedCount = 0;
        let movedCount = 0;
        let renderErrors = 0;

        const total = alignment.length;
        let offset = 0;

        if (!total) {
            setStatus(root, 'No pages found to compare in either PDF.', 'error');
            return;
        }

        const loadMoreBtn = document.createElement('button');
        loadMoreBtn.type = 'button';
        loadMoreBtn.className = 'drr-load-more reg-btn reg-btn-cancel';
        loadMoreBtn.style.display = 'none';
        loadMoreBtn.textContent = 'Load more pages';

        const ensureLoadMoreHost = () => {
            let host = root.querySelector('[data-review-load-more]');
            if (!host) {
                host = document.createElement('div');
                host.setAttribute('data-review-load-more', '1');
                host.style.marginTop = '10px';
                host.style.textAlign = 'center';
                root.appendChild(host);
            }
            host.innerHTML = '';
            host.appendChild(loadMoreBtn);
        };
        ensureLoadMoreHost();

        async function renderChunk() {
            const end = Math.min(offset + CHUNK_SIZE, total);
            const slice = alignment.slice(offset, end);

            setStatus(root, `Highlighting differences · pages ${offset + 1}–${end} of ${total}…`, 'loading');

            for (let i = offset; i < end; i++) {
                if (root.__drrAbort) return;
                const slot = alignment[i];

                let stats = null;
                let leftMarks = [];
                let rightMarks = [];

                if (slot.type === 'match') {
                    matchCount++;
                    if (slot.leftPage !== slot.rightPage) movedCount++;
                    stats = wordDiffMarks(slot.left.tokens || [], slot.right.tokens || []);
                    leftMarks = stats.leftMarks;
                    rightMarks = stats.rightMarks;
                } else if (slot.type === 'removed') {
                    removedCount++;
                    leftMarks = allTokenMarks(slot.left?.tokens || [], 'del');
                } else {
                    addedCount++;
                    rightMarks = allTokenMarks(slot.right?.tokens || [], 'ins');
                }

                if (slot.left?.usedOcr || slot.right?.usedOcr) ocrUsed = true;

                const frame = slot.type === 'removed'
                    ? 'is-removed'
                    : (slot.type === 'added' ? 'is-added' : '');

                let leftWrap = null;
                let rightWrap = null;
                try {
                    if (slot.leftPage) {
                        leftWrap = await renderPdfPage(leftDoc, slot.leftPage, width, leftMarks, frame);
                    }
                    if (slot.rightPage) {
                        rightWrap = await renderPdfPage(rightDoc, slot.rightPage, width, rightMarks, frame);
                    }
                } catch (err) {
                    renderErrors++;
                    console.warn('DRR page render failed', err);
                    if (slot.leftPage && !leftWrap) {
                        leftWrap = document.createElement('div');
                        leftWrap.className = 'drr-page-wrap ' + (frame || '');
                        leftWrap.innerHTML = '<div class="drr-page-empty">Could not render this page.</div>';
                    }
                    if (slot.rightPage && !rightWrap) {
                        rightWrap = document.createElement('div');
                        rightWrap.className = 'drr-page-wrap ' + (frame || '');
                        rightWrap.innerHTML = '<div class="drr-page-empty">Could not render this page.</div>';
                    }
                }

                const cap = document.createElement('div');
                cap.className = 'drr-page-caption';
                cap.textContent = captionForSlot(slot);
                const sum = document.createElement('div');
                sum.className = 'drr-page-summary';
                sum.textContent = summaryForSlot(slot, stats);

                if (leftStage && leftWrap && slot.leftPage) {
                    leftWrap.prepend(cap.cloneNode(true));
                    if (sum.textContent) leftWrap.appendChild(sum.cloneNode(true));
                    leftStage.appendChild(leftWrap);
                    const canvas = leftWrap.querySelector('canvas');
                    leftBlobs.push(canvas ? await canvasToBlob(canvas) : null);
                }

                if (rightStage && rightWrap && slot.rightPage) {
                    rightWrap.prepend(cap);
                    if (sum.textContent) rightWrap.appendChild(sum);
                    rightStage.appendChild(rightWrap);
                    const canvas = rightWrap.querySelector('canvas');
                    rightBlobs.push(canvas ? await canvasToBlob(canvas) : null);
                }
            }

            offset = end;
            loadMoreBtn.style.display = offset < total ? 'inline-flex' : 'none';
            loadMoreBtn.textContent = `Load more pages (${offset}/${total})`;

            const ocrWarnings = [
                ...(leftPages.__ocrWarnings || []),
                ...(rightPages.__ocrWarnings || []),
            ];

            const leftMsg = [
                `${matchCount} aligned`,
                movedCount ? `${movedCount} moved` : '',
                removedCount ? `${removedCount} removed` : '',
                ocrUsed ? 'OCR used on some pages' : '',
            ].filter(Boolean).join(' · ');
            const rightMsg = [
                `${matchCount} aligned`,
                movedCount ? `${movedCount} moved` : '',
                addedCount ? `${addedCount} added` : '',
                ocrUsed ? 'OCR used on some pages' : '',
            ].filter(Boolean).join(' · ');
            if (leftNote) leftNote.textContent = leftMsg;
            if (rightNote) rightNote.textContent = rightMsg;

            if (cacheKey && offset >= total) {
                try {
                    const saved = await putCompareCache({
                        key: cacheKey,
                        leftUrl: String(leftUrl || '').startsWith('blob:') ? '' : leftUrl,
                        rightUrl: String(rightUrl || '').startsWith('blob:') ? '' : rightUrl,
                        leftPages: leftBlobs.filter(Boolean),
                        rightPages: rightBlobs.filter(Boolean),
                        leftNote: leftMsg,
                        rightNote: rightMsg,
                        alignment: alignment.map((s) => ({
                            type: s.type,
                            leftPage: s.leftPage,
                            rightPage: s.rightPage,
                        })),
                        pageOffset: offset,
                        totalPages: total,
                        version: CACHE_VERSION,
                    });
                    if (saved) {
                        setStatus(
                            root,
                            `Comparison complete · saved locally for faster reopen · ${matchCount} aligned`
                                + (movedCount ? `, ${movedCount} moved` : '')
                                + (removedCount ? `, ${removedCount} removed` : '')
                                + (addedCount ? `, ${addedCount} added` : '')
                                + '.',
                            'success'
                        );
                        return;
                    }
                } catch (err) {
                    console.warn('DRR cache save error', err);
                }
            }

            if (offset < total) {
                setStatus(root, `Showing ${offset} of ${total} pages · click “Load more pages” to continue.`, 'info');
                return;
            }

            const warnBits = [];
            if (ocrWarnings.length) warnBits.push(ocrWarnings[0]);
            if (renderErrors) warnBits.push(`${renderErrors} page(s) failed to render.`);

            if (warnBits.length) {
                setStatus(
                    root,
                    `Comparison finished with warnings: ${warnBits.join(' ')}`,
                    'info'
                );
            } else {
                setStatus(
                    root,
                    `Comparison complete · ${matchCount} aligned`
                        + (movedCount ? `, ${movedCount} moved` : '')
                        + (removedCount ? `, ${removedCount} removed` : '')
                        + (addedCount ? `, ${addedCount} added` : '')
                        + '.',
                    'success'
                );
            }
        }

        loadMoreBtn.onclick = () => {
            renderChunk().catch((err) => {
                console.error(err);
                setStatus(root, friendlyError(err, 'Failed while loading more pages.'), 'error');
            });
        };

        await renderChunk();
    } catch (err) {
        console.error('DRR compare failed', err);
        if (leftStage && !leftStage.querySelector('canvas, img')) {
            showStagePlaceholder(leftStage, 'Comparison failed');
            leftStage.querySelector('.drr-compare-spinner')?.remove();
        }
        if (rightStage && !rightStage.querySelector('canvas, img')) {
            showStagePlaceholder(rightStage, 'Comparison failed');
            rightStage.querySelector('.drr-compare-spinner')?.remove();
        }
        setStatus(root, friendlyError(err, 'Document comparison failed. Please try again.'), 'error');
    }
}

export async function buildCompareCacheKey(leftId, rightId) {
    return 'drr-v5:' + await hashString(String(leftId) + '||' + String(rightId));
}

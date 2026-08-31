import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import {
    getCompareCache,
    putCompareCache,
    hashString,
    peekMemoryCompareCache,
    deleteCompareCache,
    clearMemoryCompareCache,
} from './pdf-compare-cache.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const FILL = {
    del: 'rgba(252, 165, 165, 0.45)',
    ins: 'rgba(134, 239, 172, 0.45)',
    chg: 'rgba(253, 230, 138, 0.5)',
};

const CHUNK_SIZE = 25;
const OCR_BATCH = 1;
const OCR_CONCURRENCY = 3;
const TOKEN_CAP = 4000;
/** When content alignment finds nothing but docs share vocabulary, pair by page index. */
const DOC_SIMILARITY_INDEX_FALLBACK = 0.18;
const CACHE_VERSION = 15;
/** Pixel diff below this ⇒ treat page pair as visually unchanged (skip OCR). */
const VISUAL_UNCHANGED_THRESHOLD = 0.015;
/** Token overlap above this ⇒ suppress highlight noise on near-identical pages. */
const PAGE_SIMILARITY_SKIP = 0.97;
const LARGE_DOC_PAGES = 12;

const ROMAN_TO_ARABIC = {
    i: '1', ii: '2', iii: '3', iv: '4', v: '5', vi: '6', vii: '7', viii: '8', ix: '9', x: '10',
    xi: '11', xii: '12',
};

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
    let s = String(w || '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}]+/gu, '');
    if (ROMAN_TO_ARABIC[s]) {
        s = ROMAN_TO_ARABIC[s];
    }
    return s;
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
function isValidOcrWord(w) {
    const t = String(w?.t || '').trim();
    if (!t) return false;

    const conf = Number(w?.conf);
    if (Number.isFinite(conf) && conf >= 0 && conf < 35) return false;

    const x = Number(w?.x);
    const y = Number(w?.y);
    const bw = Number(w?.w);
    const bh = Number(w?.h);
    if (![x, y, bw, bh].every((n) => Number.isFinite(n))) return false;
    if (x < -0.01 || y < -0.01 || x + bw > 1.02 || y + bh > 1.02) return false;
    if (bw < 0.0005 || bh < 0.0005) return false;
    if (bw > 0.82 && t.length < 30) return false;
    if (bh > 0.14 && t.length < 18) return false;

    return true;
}

/** Split OCR line boxes into word tokens when word-level boxes are sparse. */
function tokensFromOcrLines(lines) {
    const out = [];
    for (const line of (Array.isArray(lines) ? lines : [])) {
        const text = String(line?.t || '').trim();
        if (!text) continue;
        const x = Number(line.x);
        const y = Number(line.y);
        const lw = Number(line.w);
        const lh = Number(line.h);
        if (![x, y, lw, lh].every((n) => Number.isFinite(n))) continue;

        const words = text.split(/\s+/).filter(Boolean);
        if (!words.length) continue;

        const sliceW = lw / words.length;
        words.forEach((t, idx) => {
            const norm = normalizeWord(t);
            if (!norm) return;
            out.push({
                t,
                norm,
                ocr: true,
                item: null,
                box: {
                    x: x + sliceW * idx,
                    y,
                    w: Math.max(sliceW * 0.92, 0.002),
                    h: lh,
                },
            });
        });
    }
    return out;
}

function tokensFromOcrPage(row) {
    let tokens = tokensFromOcrWords(row?.words).slice(0, TOKEN_CAP);
    if (tokens.length < 12) {
        const fromLines = tokensFromOcrLines(row?.lines).slice(0, TOKEN_CAP);
        if (fromLines.length > tokens.length) {
            tokens = fromLines;
        }
    }
    return tokens;
}

function tokensFromOcrWords(words) {
    return (Array.isArray(words) ? words : [])
        .filter(isValidOcrWord)
        .map((w) => {
            const t = String(w.t || '').trim();
            const norm = normalizeWord(t);
            const x = Number(w.x);
            const y = Number(w.y);
            const bw = Number(w.w);
            const bh = Number(w.h);
            return {
                t,
                norm,
                ocr: true,
                item: null,
                box: { x, y, w: bw, h: bh },
            };
        })
        .filter((row) => row.t !== '' && row.norm !== '');
}

function prepareTokensForDiff(tokens) {
    return ensureHighlightTokens(tokens);
}

/** Build word tokens with page boxes from plain OCR text (last-resort highlight geometry). */
function inferBoxesFromText(text) {
    const lines = String(text || '').split(/\n+/).map((l) => l.trim()).filter(Boolean);
    if (!lines.length) {
        const flat = String(text || '').split(/\s+/).map((t) => t.trim()).filter(Boolean);
        if (!flat.length) return [];
        lines.push(flat.join(' '));
    }

    const out = [];
    const lineCount = Math.max(lines.length, 1);
    lines.forEach((line, li) => {
        const words = line.split(/\s+/).filter(Boolean);
        const wordCount = Math.max(words.length, 1);
        const lh = 0.88 / lineCount;
        const y = 0.05 + li * lh;
        const sliceW = 0.88 / wordCount;
        words.forEach((t, wi) => {
            const norm = normalizeWord(t);
            if (!norm) return;
            out.push({
                t,
                norm,
                ocr: true,
                item: null,
                box: {
                    x: 0.06 + wi * sliceW,
                    y,
                    w: Math.max(sliceW * 0.92, 0.008),
                    h: Math.max(lh * 0.82, 0.012),
                },
            });
        });
    });
    return out;
}

/** Every compared word must have a box so highlights can be painted on the PDF. */
function ensureHighlightTokens(tokens, rawText = '') {
    const list = (tokens || []).map((t) => ({ ...t }));
    const missing = list.filter((t) => t.norm && !t.box && !t.item);
    if (!missing.length) return list.filter((t) => t.norm);

    if (!list.length && rawText) {
        return inferBoxesFromText(rawText);
    }

    if (missing.length === list.length && rawText) {
        return inferBoxesFromText(rawText);
    }

    let n = 0;
    for (const t of list) {
        if (t.box || t.item || !t.norm) continue;
        const row = Math.floor(n / 12);
        const col = n % 12;
        t.box = {
            x: 0.04 + col * 0.078,
            y: 0.05 + row * 0.026,
            w: 0.074,
            h: 0.022,
        };
        t.ocr = true;
        n++;
    }
    return list.filter((t) => t.norm && (t.box || t.item));
}

function countPaintablePages(pages) {
    return (pages || []).filter((p) => (p.tokens || []).some((t) => t.box || t.item)).length;
}

function findFuzzyBucket(token, buckets) {
    for (const [norm, list] of buckets.entries()) {
        if (!list.length) continue;
        if (tokensEqual({ norm: token.norm }, { norm })) {
            return list;
        }
    }
    return null;
}

function documentWordSet(pages) {
    const set = new Set();
    for (const page of pages || []) {
        for (const token of page.tokens || []) {
            if (token.norm && token.norm.length >= 3) {
                set.add(token.norm);
            }
        }
    }
    return set;
}

/** Shared vocabulary ratio — detects same document even when page breaks differ. */
function documentSimilarity(leftPages, rightPages) {
    const left = documentWordSet(leftPages);
    const right = documentWordSet(rightPages);
    if (!left.size || !right.size) {
        return 0;
    }
    let hit = 0;
    for (const word of right) {
        if (left.has(word)) {
            hit++;
        }
    }
    return hit / Math.min(left.size, right.size);
}

function countAlignmentMatches(alignment) {
    return alignment.filter((slot) => slot.type === 'match').length;
}

function isBadCachedCompare(cached) {
    if (!cached) return true;
    if (Number(cached.version) < CACHE_VERSION) return true;
    const notes = `${cached.leftNote || ''} ${cached.rightNote || ''}`;
    if (/0 aligned|0 page pairs/.test(notes) && (cached.totalPages || 0) > 2) return true;
    const aligned = (cached.alignment || []).filter((s) => s.type === 'match').length;
    if (aligned === 0 && (cached.totalPages || 0) > 2) return true;
    if (cached.hasHighlights !== true) return true;
    return false;
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

function editDistanceAtMost(a, b, maxEdits) {
    if (a === b) return true;
    const n = a.length;
    const m = b.length;
    if (Math.abs(n - m) > maxEdits) return false;
    if (maxEdits === 1) {
        return editDistanceAtMost1(a, b);
    }

    const dp = Array.from({ length: n + 1 }, () => new Uint8Array(m + 1));
    for (let i = 0; i <= n; i++) dp[i][0] = i;
    for (let j = 0; j <= m; j++) dp[0][j] = j;

    for (let i = 1; i <= n; i++) {
        let rowMin = maxEdits + 1;
        for (let j = 1; j <= m; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            dp[i][j] = Math.min(
                dp[i - 1][j] + 1,
                dp[i][j - 1] + 1,
                dp[i - 1][j - 1] + cost
            );
            rowMin = Math.min(rowMin, dp[i][j]);
        }
        if (rowMin > maxEdits) return false;
    }
    return dp[n][m] <= maxEdits;
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
    const maxLen = Math.max(x.length, y.length);
    const maxEdits = maxLen >= 8 ? 2 : 1;
    return editDistanceAtMost(x, y, maxEdits);
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
    ctx.fillRect(x, y - height, Math.max(width, 2), Math.max(height, 2));
}

/** Paint a mark on the PDF canvas (embedded text item or OCR normalized box). */
function paintMark(ctx, viewport, mark, color) {
    if (!mark) return;
    if (mark.box && Number.isFinite(mark.box.x)) {
        const cw = ctx.canvas.width;
        const ch = ctx.canvas.height;
        let x = mark.box.x * cw;
        let y = mark.box.y * ch;
        let w = mark.box.w * cw;
        let h = mark.box.h * ch;

        x = Math.max(0, Math.min(x, cw - 1));
        y = Math.max(0, Math.min(y, ch - 1));
        w = Math.min(w, cw - x);
        h = Math.min(h, ch - y);
        if (w < 1.5 || h < 1.5) return;

        ctx.fillStyle = color;
        ctx.fillRect(x, y, w, h);
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

function tokenPaintable(token) {
    return Boolean(token?.box || token?.item);
}

function pageHasPaintableTokens(page) {
    return (page?.tokens || []).some(tokenPaintable);
}

/** Re-OCR when the other side uses OCR boxes but this page only has sparse/missing text. */
function pageNeedsOcrForCompare(page, partnerPage) {
    if (!page) return false;
    if (!page.tokens.length) return true;
    if (!pageHasPaintableTokens(page)) return true;
    if (partnerPage?.usedOcr && !page.usedOcr) return true;
    if (
        partnerPage?.usedOcr
        && partnerPage.tokens.length > 20
        && page.tokens.length < partnerPage.tokens.length * 0.45
    ) {
        return true;
    }
    return false;
}

async function applyOcrToPages(pages, pageNumbers, sideMeta, setStatus, warnings = []) {
    const unique = [...new Set((pageNumbers || []).map(Number).filter((n) => n > 0))].sort((a, b) => a - b);
    if (!unique.length) {
        return;
    }

    let done = 0;
    const total = unique.length;
    let cursor = 0;

    const ocrOne = async (pageNo) => {
        if (setStatus) {
            setStatus(
                `OCR ${sideMeta.label}: page ${pageNo} (${done + 1}/${total})…`,
                'loading'
            );
        }

        let results = [];
        try {
            results = await ocrPageBatch({
                file: sideMeta.file || null,
                storagePath: sideMeta.storagePath || '',
                pages: [pageNo],
            });
        } catch (err) {
            warnings.push(friendlyError(err, `OCR failed for ${sideMeta.label} page ${pageNo}.`));
            results = [{ page: pageNo, text: '', words: [], used_ocr: true, ok: false }];
        }

        const row = results.find((r) => Number(r.page) === pageNo) || results[0];
        let tokens = tokensFromOcrPage(row).slice(0, TOKEN_CAP);
        if (!tokens.length && row?.text) {
            tokens = inferBoxesFromText(row.text).slice(0, TOKEN_CAP);
        }
        tokens = ensureHighlightTokens(tokens, row?.text || '').slice(0, TOKEN_CAP);
        const idx = pageNo - 1;
        if (idx >= 0 && idx < pages.length) {
            pages[idx] = {
                page: pageNo,
                tokens,
                usedOcr: true,
                hasText: tokens.length > 0,
            };
        }
        done++;
    };

    const workers = Array.from({ length: Math.min(OCR_CONCURRENCY, unique.length) }, async () => {
        while (cursor < unique.length) {
            const pageNo = unique[cursor];
            cursor += 1;
            await ocrOne(pageNo);
        }
    });
    await Promise.all(workers);
}

/**
 * When one revision is OCR'd and the other isn't, run OCR on both so highlights
 * paint on previous and current pages (word boxes required for scanned PDFs).
 */
async function harmonizeCompareTokens(leftPages, rightPages, leftMeta, rightMeta, setStatus) {
    const warnings = [];
    const leftOcr = new Set();
    const rightOcr = new Set();
    const shared = Math.min(leftPages.length, rightPages.length);

    for (let i = 0; i < shared; i++) {
        if (leftPages[i]?.unchanged && rightPages[i]?.unchanged) continue;
        if (pageNeedsOcrForCompare(leftPages[i], rightPages[i])) {
            leftOcr.add(leftPages[i].page);
        }
        if (pageNeedsOcrForCompare(rightPages[i], leftPages[i])) {
            rightOcr.add(rightPages[i].page);
        }
    }
    for (let i = shared; i < leftPages.length; i++) {
        if (leftPages[i]?.unchanged) continue;
        if (!pageHasPaintableTokens(leftPages[i])) {
            leftOcr.add(leftPages[i].page);
        }
    }
    for (let i = shared; i < rightPages.length; i++) {
        if (rightPages[i]?.unchanged) continue;
        if (!pageHasPaintableTokens(rightPages[i])) {
            rightOcr.add(rightPages[i].page);
        }
    }

    if (leftOcr.size || rightOcr.size) {
        await Promise.all([
            leftOcr.size
                ? applyOcrToPages(leftPages, [...leftOcr], leftMeta, setStatus, warnings)
                : Promise.resolve(),
            rightOcr.size
                ? applyOcrToPages(rightPages, [...rightOcr], rightMeta, setStatus, warnings)
                : Promise.resolve(),
        ]);
    }

    leftPages.__ocrWarnings = [...(leftPages.__ocrWarnings || []), ...warnings];
    rightPages.__ocrWarnings = [...(rightPages.__ocrWarnings || []), ...warnings];
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
        const pathParam = u.searchParams.get('path');
        if (pathParam) {
            return decodeURIComponent(pathParam.replace(/\+/g, ' '));
        }
        const m = u.pathname.match(/\/storage\/(.+)$/);
        return m ? decodeURIComponent(m[1]) : '';
    } catch {
        const q = String(url).match(/[?&]path=([^&]+)/);
        if (q) {
            return decodeURIComponent(q[1].replace(/\+/g, ' '));
        }
        const m = String(url).match(/\/storage\/(.+)$/);
        return m ? decodeURIComponent(m[1]) : '';
    }
}

async function ocrPageBatch({ file, storagePath, pages }, attempt = 0) {
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
        if (attempt < 1) {
            await new Promise((r) => setTimeout(r, 800));
            return ocrPageBatch({ file, storagePath, pages }, attempt + 1);
        }
        const error = new Error(friendlyError(err, 'OCR request failed.'));
        error.cause = err;
        throw error;
    }

    if (res.status === 504 && attempt < 1) {
        await new Promise((r) => setTimeout(r, 800));
        return ocrPageBatch({ file, storagePath, pages }, attempt + 1);
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

async function ensurePageTexts(doc, sideMeta, setStatus, options = {}) {
    const forceOcr = options.forceOcr === true;
    const unchangedPages = options.unchangedPages || new Set();
    const pages = [];
    const missing = [];
    const warnings = [];

    for (let p = 1; p <= doc.numPages; p++) {
        const unchanged = unchangedPages.has(p);

        if (forceOcr) {
            pages.push({
                page: p,
                tokens: [],
                usedOcr: false,
                hasText: false,
                unchanged,
            });
            if (!unchanged) missing.push(p);
            continue;
        }

        const items = tokensFromItems(await pageItems(doc, p)).slice(0, TOKEN_CAP);
        const entry = {
            page: p,
            tokens: items,
            usedOcr: false,
            hasText: items.length > 0,
            unchanged,
        };
        pages.push(entry);
        if (!unchanged && !entry.hasText) {
            missing.push(p);
        }
    }

    await applyOcrToPages(pages, missing, sideMeta, setStatus, warnings);

    pages.__ocrWarnings = warnings;
    return pages;
}

/**
 * Keep each page at its real PDF page number: old p.N ↔ new p.N side by side.
 * Extra pages at the end of the longer revision show a blank slot on the other side.
 */
function alignPagesByPosition(leftPages, rightPages) {
    const total = Math.max(leftPages.length, rightPages.length);
    const alignment = [];

    for (let i = 0; i < total; i++) {
        const left = leftPages[i] || null;
        const right = rightPages[i] || null;

        if (left && right) {
            alignment.push({
                type: 'match',
                leftPage: left.page,
                rightPage: right.page,
                left,
                right,
                pairedBy: 'position',
            });
        } else if (left) {
            alignment.push({
                type: 'removed',
                leftPage: left.page,
                rightPage: null,
                left,
                right: null,
                pairedBy: 'position',
            });
        } else {
            alignment.push({
                type: 'added',
                leftPage: null,
                rightPage: right.page,
                left: null,
                right,
                pairedBy: 'position',
            });
        }
    }

    return alignment;
}

function alignPagesSmart(leftPages, rightPages) {
    const similarity = documentSimilarity(leftPages, rightPages);
    const alignment = alignPagesByPosition(leftPages, rightPages);
    const matchCount = countAlignmentMatches(alignment);
    return { alignment, mode: 'position', similarity, matchCount };
}

/** Count-based diff — shared words (incl. WHEREAS × N) stay unhighlighted. */
function wordDiffMarksFrequency(leftTokens, rightTokens) {
    const left = prepareTokensForDiff(leftTokens);
    const right = prepareTokensForDiff(rightTokens);

    const rightBuckets = new Map();
    for (const t of right) {
        if (!rightBuckets.has(t.norm)) rightBuckets.set(t.norm, []);
        rightBuckets.get(t.norm).push(t);
    }

    const leftUnmatched = [];

    for (const lt of left) {
        let bucket = rightBuckets.get(lt.norm);
        if (!bucket?.length) {
            bucket = findFuzzyBucket(lt, rightBuckets);
        }
        if (bucket?.length) {
            bucket.pop();
        } else {
            leftUnmatched.push(lt);
        }
    }

    const rightSurplus = [];
    for (const bucket of rightBuckets.values()) {
        while (bucket.length) {
            rightSurplus.push(bucket.pop());
        }
    }

    return pairUnmatchedTokens(leftUnmatched, rightSurplus);
}

/** Pair leftover tokens as del/ins or fuzzy chg (yellow on both sides). */
function pairUnmatchedTokens(leftUnmatched, rightSurplus) {
    const leftMarks = [];
    const rightMarks = [];
    const usedRight = new Set();
    let del = 0;
    let ins = 0;
    let chg = 0;

    for (const lt of leftUnmatched) {
        let bestIdx = -1;
        for (let ri = 0; ri < rightSurplus.length; ri++) {
            if (usedRight.has(ri)) continue;
            if (tokensEqual(lt, rightSurplus[ri])) {
                bestIdx = ri;
                break;
            }
        }
        if (bestIdx >= 0) {
            const rt = rightSurplus[bestIdx];
            usedRight.add(bestIdx);
            if (lt.norm !== rt.norm) {
                const lm = markFromToken('chg', lt);
                const rm = markFromToken('chg', rt);
                if (lm) leftMarks.push(lm);
                if (rm) rightMarks.push(rm);
                chg++;
            }
        } else {
            const lm = markFromToken('del', lt);
            if (lm) leftMarks.push(lm);
            del++;
        }
    }

    for (let ri = 0; ri < rightSurplus.length; ri++) {
        if (usedRight.has(ri)) continue;
        const rm = markFromToken('ins', rightSurplus[ri]);
        if (rm) rightMarks.push(rm);
        ins++;
    }

    return { leftMarks, rightMarks, del, ins, chg };
}

/** Word LCS → marks painted on both old and new PDF canvases. */
function wordDiffMarksSequential(leftTokens, rightTokens) {
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

function wordDiffMarks(leftTokens, rightTokens, slot = null) {
    const left = ensureHighlightTokens(leftTokens);
    const right = ensureHighlightTokens(rightTokens);

    if (slot?.left?.unchanged && slot?.right?.unchanged) {
        return { leftMarks: [], rightMarks: [], del: 0, ins: 0, chg: 0 };
    }

    if (!left.length && !right.length) {
        return { leftMarks: [], rightMarks: [], del: 0, ins: 0, chg: 0 };
    }
    if (!left.length) {
        const rightMarks = right.map((t) => markFromToken('ins', t)).filter(Boolean);
        return { leftMarks: [], rightMarks, del: 0, ins: rightMarks.length, chg: 0 };
    }
    if (!right.length) {
        const leftMarks = left.map((t) => markFromToken('del', t)).filter(Boolean);
        return { leftMarks, rightMarks: [], del: leftMarks.length, ins: 0, chg: 0 };
    }

    if (pageTextSimilarity(left, right) >= PAGE_SIMILARITY_SKIP) {
        return { leftMarks: [], rightMarks: [], del: 0, ins: 0, chg: 0 };
    }

    const ocrHeavy = (slot?.left?.usedOcr || slot?.right?.usedOcr)
        || left.some((t) => t.ocr)
        || right.some((t) => t.ocr);

    if (ocrHeavy) {
        return wordDiffMarksFrequency(left, right);
    }
    return wordDiffMarksSequential(left, right);
}

function pageTextSimilarity(leftTokens, rightTokens) {
    const left = prepareTokensForDiff(leftTokens);
    const right = prepareTokensForDiff(rightTokens);
    if (!left.length && !right.length) return 1;
    if (!left.length || !right.length) return 0;

    const rightCounts = new Map();
    for (const t of right) {
        rightCounts.set(t.norm, (rightCounts.get(t.norm) || 0) + 1);
    }
    let matched = 0;
    for (const t of left) {
        const c = rightCounts.get(t.norm) || 0;
        if (c > 0) {
            matched++;
            rightCounts.set(t.norm, c - 1);
        }
    }
    return matched / Math.max(left.length, right.length, 1);
}

async function pageVisualHash(doc, pageNum) {
    const page = await doc.getPage(pageNum);
    const base = page.getViewport({ scale: 1 });
    const targetW = 24;
    const scale = targetW / base.width;
    const viewport = page.getViewport({ scale });
    const w = Math.max(8, Math.floor(viewport.width));
    const h = Math.max(8, Math.floor(viewport.height));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    await page.render({ canvasContext: ctx, viewport }).promise;
    const data = ctx.getImageData(0, 0, w, h).data;
    const bins = new Uint8Array(w * h);
    for (let i = 0, p = 0; i < data.length; i += 4, p++) {
        bins[p] = (data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114) > 140 ? 1 : 0;
    }
    return bins;
}

function visualHashDiff(a, b) {
    if (!a || !b || a.length !== b.length) return 1;
    let diff = 0;
    for (let i = 0; i < a.length; i++) {
        if (a[i] !== b[i]) diff++;
    }
    return diff / a.length;
}

/** Quick visual scan — unchanged pairs skip expensive OCR on large documents. */
async function findUnchangedPagePairs(leftDoc, rightDoc, onProgress) {
    const pairs = [];
    const total = Math.min(leftDoc.numPages, rightDoc.numPages);
    const batch = 6;

    for (let start = 1; start <= total; start += batch) {
        const end = Math.min(start + batch - 1, total);
        if (onProgress) onProgress(end, total);

        const jobs = [];
        for (let p = start; p <= end; p++) {
            jobs.push(
                Promise.all([
                    pageVisualHash(leftDoc, p),
                    pageVisualHash(rightDoc, p),
                    pageItems(leftDoc, p),
                    pageItems(rightDoc, p),
                ]).then(([lh, rh, leftItems, rightItems]) => {
                    if (visualHashDiff(lh, rh) > VISUAL_UNCHANGED_THRESHOLD) {
                        return;
                    }
                    const lt = tokensFromItems(leftItems);
                    const rt = tokensFromItems(rightItems);
                    if (lt.length && rt.length && pageTextSimilarity(lt, rt) < PAGE_SIMILARITY_SKIP) {
                        return;
                    }
                    pairs.push({ leftPage: p, rightPage: p });
                })
            );
        }
        await Promise.all(jobs);
        await new Promise((r) => setTimeout(r, 0));
    }

    return pairs;
}

function computeSlotDiff(slot) {
    if (slot.type === 'match') {
        return wordDiffMarks(slot.left?.tokens || [], slot.right?.tokens || [], slot);
    }
    if (slot.type === 'removed') {
        const leftMarks = allTokenMarks(ensureHighlightTokens(slot.left?.tokens || []), 'del');
        return { leftMarks, rightMarks: [], del: leftMarks.length, ins: 0, chg: 0 };
    }
    const rightMarks = allTokenMarks(ensureHighlightTokens(slot.right?.tokens || []), 'ins');
    return { leftMarks: [], rightMarks, del: 0, ins: rightMarks.length, chg: 0 };
}

function slotHasChanges(slot, stats) {
    if (slot.type === 'removed' || slot.type === 'added') return true;
    if (!stats) return false;
    return (stats.del + stats.ins + stats.chg) > 0;
}

function analyzeAlignmentChanges(alignment) {
    const changedRows = [];
    const rowStats = [];
    let edited = 0;
    let added = 0;
    let removed = 0;

    for (let i = 0; i < alignment.length; i++) {
        const slot = alignment[i];
        const stats = computeSlotDiff(slot);
        rowStats[i] = stats;
        if (slot.type === 'added') {
            added++;
            changedRows.push(i);
        } else if (slot.type === 'removed') {
            removed++;
            changedRows.push(i);
        } else if (slotHasChanges(slot, stats)) {
            edited++;
            changedRows.push(i);
        }
    }

    return {
        changedRows,
        rowStats,
        stats: {
            edited,
            added,
            removed,
            unchanged: alignment.length - changedRows.length,
            total: alignment.length,
            changed: changedRows.length,
        },
    };
}

function scrollToChangeRow(leftStage, rightStage, rowIndex) {
    const sel = `[data-compare-row="${rowIndex}"]`;
    const leftEl = leftStage?.querySelector(sel);
    const rightEl = rightStage?.querySelector(sel);
    const target = leftEl || rightEl;
    if (!target || !leftStage) return;
    const top = target.offsetTop - leftStage.offsetTop - 8;
    leftStage.scrollTop = Math.max(0, top);
    if (rightStage) rightStage.scrollTop = Math.max(0, top);
}

function applyChangedOnlyFilter(root, leftStage, rightStage, showChangedOnly) {
    root.classList.toggle('drr-show-changed-only', showChangedOnly);
    const rows = new Set(root.__drrChangedRows || []);
    [leftStage, rightStage].forEach((stage) => {
        if (!stage) return;
        stage.querySelectorAll('[data-compare-row]').forEach((el) => {
            const row = Number(el.dataset.compareRow);
            const changed = rows.has(row);
            el.classList.toggle('drr-page-unchanged', !changed);
            el.style.display = showChangedOnly && !changed ? 'none' : '';
        });
    });
}

function setupChangeNavigator(root, analysis, alignment, leftStage, rightStage, fullyRendered = false) {
    const section = root.closest('.drr-section') || root.parentElement;
    if (!section) return;

    let nav = section.querySelector('[data-drr-change-nav]');
    if (!nav) {
        nav = document.createElement('div');
        nav.className = 'drr-change-nav';
        nav.dataset.drrChangeNav = '1';
        section.insertBefore(nav, root);
    }

    root.__drrChangedRows = analysis.changedRows;
    root.__drrChangeStats = analysis.stats;

    const { changedRows, stats } = analysis;
    if (stats.total < 4 || changedRows.length === 0) {
        nav.style.display = 'none';
        return;
    }

    nav.style.display = '';
    const defaultHide = fullyRendered
        && stats.total >= LARGE_DOC_PAGES
        && changedRows.length < stats.total * 0.85;
    let cursor = 0;
    let showChangedOnly = root.__drrShowChangedOnly ?? defaultHide;

    const summaryParts = [];
    if (stats.edited) summaryParts.push(`${stats.edited} edited`);
    if (stats.added) summaryParts.push(`${stats.added} new page${stats.added > 1 ? 's' : ''}`);
    if (stats.removed) summaryParts.push(`${stats.removed} removed`);
    const summaryText = summaryParts.length
        ? summaryParts.join(' · ')
        : `${stats.changed} page${stats.changed > 1 ? 's' : ''} with changes`;

    nav.innerHTML = '';

    const summary = document.createElement('div');
    summary.className = 'drr-change-nav-summary';
    summary.innerHTML = `<strong>${stats.changed}</strong> of ${stats.total} pages differ`
        + `<span class="drr-change-nav-detail">${summaryText}</span>`;

    const controls = document.createElement('div');
    controls.className = 'drr-change-nav-controls';

    const toggleLabel = document.createElement('label');
    toggleLabel.className = 'drr-change-nav-toggle';
    const toggle = document.createElement('input');
    toggle.type = 'checkbox';
    toggle.checked = showChangedOnly;
    toggleLabel.appendChild(toggle);
    toggleLabel.append(' Show changed pages only');

    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.className = 'drr-change-nav-btn';
    prevBtn.innerHTML = '<i class="fa-solid fa-chevron-up"></i> Prev change';

    const jump = document.createElement('select');
    jump.className = 'drr-change-nav-jump';
    changedRows.forEach((rowIdx) => {
        const slot = alignment[rowIdx];
        const opt = document.createElement('option');
        opt.value = String(rowIdx);
        const pageNo = slot?.leftPage || slot?.rightPage || rowIdx + 1;
        const kind = slot?.type === 'added' ? ' (new)' : slot?.type === 'removed' ? ' (removed)' : '';
        opt.textContent = `Page ${pageNo}${kind}`;
        jump.appendChild(opt);
    });

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.className = 'drr-change-nav-btn';
    nextBtn.innerHTML = 'Next change <i class="fa-solid fa-chevron-down"></i>';

    controls.appendChild(toggleLabel);
    controls.appendChild(prevBtn);
    controls.appendChild(jump);
    controls.appendChild(nextBtn);

    nav.appendChild(summary);
    nav.appendChild(controls);

    const goTo = (index) => {
        if (!changedRows.length) return;
        cursor = ((index % changedRows.length) + changedRows.length) % changedRows.length;
        const row = changedRows[cursor];
        jump.value = String(row);
        const rendered = leftStage?.querySelector(`[data-compare-row="${row}"]`);
        if (!rendered) return;
        scrollToChangeRow(leftStage, rightStage, row);
    };

    toggle.addEventListener('change', () => {
        showChangedOnly = toggle.checked;
        root.__drrShowChangedOnly = showChangedOnly;
        applyChangedOnlyFilter(root, leftStage, rightStage, showChangedOnly);
    });

    prevBtn.addEventListener('click', () => goTo(cursor - 1));
    nextBtn.addEventListener('click', () => goTo(cursor + 1));
    jump.addEventListener('change', () => {
        const idx = changedRows.indexOf(Number(jump.value));
        if (idx >= 0) goTo(idx);
    });

    applyChangedOnlyFilter(root, leftStage, rightStage, showChangedOnly);

    if (changedRows.length && fullyRendered) {
        requestAnimationFrame(() => goTo(0));
    }
}

function scrollToCompareStart(leftStage, rightStage) {
    if (leftStage) leftStage.scrollTop = 0;
    if (rightStage) rightStage.scrollTop = 0;
}

function pageCaptionForRow(cached, rowIndex) {
    const slot = (cached?.alignment || [])[rowIndex];
    if (!slot) return `Page ${rowIndex + 1}`;
    if (slot.type === 'match' || slot.type === 'removed') {
        return `Page ${slot.leftPage || rowIndex + 1}`;
    }
    return `Page ${slot.rightPage || rowIndex + 1}`;
}

function appendPageCaption(wrap, text) {
    const cap = document.createElement('div');
    cap.className = 'drr-page-caption';
    cap.textContent = text;
    wrap.prepend(cap);
}

function balanceCompareRow(leftWrap, rightWrap) {
    const minRow = 140;
    const h = Math.max(leftWrap?.offsetHeight || 0, rightWrap?.offsetHeight || 0, minRow);
    if (leftWrap) leftWrap.style.minHeight = `${h}px`;
    if (rightWrap) rightWrap.style.minHeight = `${h}px`;
}

function setupCompareScrollSync(leftStage, rightStage) {
    if (!leftStage || !rightStage) return;
    if (leftStage.dataset.scrollSync === '1') return;
    leftStage.dataset.scrollSync = '1';
    rightStage.dataset.scrollSync = '1';

    let lock = false;
    const sync = (src, dst) => {
        if (lock) return;
        lock = true;
        dst.scrollTop = src.scrollTop;
        requestAnimationFrame(() => {
            lock = false;
        });
    };

    leftStage.addEventListener('scroll', () => sync(leftStage, rightStage), { passive: true });
    rightStage.addEventListener('scroll', () => sync(rightStage, leftStage), { passive: true });
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
    }

    wrap.appendChild(canvas);
    return wrap;
}

function emptyPagePlaceholder(label) {
    const wrap = document.createElement('div');
    wrap.className = 'drr-page-wrap is-empty';
    wrap.innerHTML = `<div class="drr-page-empty">${label}</div>`;
    return wrap;
}

function captionForSlot(slot) {
    if (slot.type === 'match') {
        return `Page ${slot.leftPage}`;
    }
    if (slot.type === 'removed') {
        return `Page ${slot.leftPage}`;
    }
    return `Page ${slot.rightPage}`;
}

function summaryForSlot(slot, stats, side = 'both') {
    const bits = [];
    if (slot.type === 'removed') bits.push('Page only in previous revision');
    if (slot.type === 'added') bits.push('Page only in current revision');
    if (stats) {
        if (side === 'left' || side === 'both') {
            if (stats.del) bits.push(`${stats.del} removed`);
            if (stats.chg) bits.push(`${stats.chg} changed`);
        }
        if (side === 'right' || side === 'both') {
            if (stats.ins) bits.push(`${stats.ins} added`);
        }
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

async function paintCached(root, cached, cacheKey = '') {
    const leftStage = root.querySelector('[data-review-side="left"]');
    const rightStage = root.querySelector('[data-review-side="right"]');
    const leftNote = root.querySelector('[data-review-note="left"]');
    const rightNote = root.querySelector('[data-review-note="right"]');

    if (!leftStage || !rightStage) return false;
    if (!(cached.leftPages?.length) && !(cached.rightPages?.length)) return false;

    const key = cacheKey || cached.key || root.dataset.cacheKey || '';
    if (isBadCachedCompare(cached)) {
        if (key) {
            clearMemoryCompareCache(key);
            deleteCompareCache(key).catch(() => {});
        }
        return false;
    }

    leftStage.innerHTML = '';
    rightStage.innerHTML = '';

    const leftList = cached.leftPages || [];
    const rightList = cached.rightPages || [];
    const rows = Math.max(leftList.length, rightList.length);

    for (let i = 0; i < rows; i++) {
        const leftBlob = leftList[i];
        const rightBlob = rightList[i];
        let leftWrap = null;
        let rightWrap = null;

        if (leftBlob) {
            leftWrap = document.createElement('div');
            leftWrap.className = 'drr-page-wrap';
            leftWrap.dataset.compareRow = String(i);
            appendPageCaption(leftWrap, pageCaptionForRow(cached, i));
            const img = document.createElement('img');
            img.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
            img.src = URL.createObjectURL(leftBlob);
            leftWrap.appendChild(img);
            leftStage.appendChild(leftWrap);
        } else {
            leftWrap = emptyPagePlaceholder('No page in previous revision');
            leftWrap.dataset.compareRow = String(i);
            appendPageCaption(leftWrap, pageCaptionForRow(cached, i));
            leftStage.appendChild(leftWrap);
        }

        if (rightBlob) {
            rightWrap = document.createElement('div');
            rightWrap.className = 'drr-page-wrap';
            rightWrap.dataset.compareRow = String(i);
            appendPageCaption(rightWrap, pageCaptionForRow(cached, i));
            const img = document.createElement('img');
            img.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
            img.src = URL.createObjectURL(rightBlob);
            rightWrap.appendChild(img);
            rightStage.appendChild(rightWrap);
        } else {
            rightWrap = emptyPagePlaceholder('No page in current revision');
            rightWrap.dataset.compareRow = String(i);
            appendPageCaption(rightWrap, pageCaptionForRow(cached, i));
            rightStage.appendChild(rightWrap);
        }

        balanceCompareRow(leftWrap, rightWrap);
    }

    setupCompareScrollSync(leftStage, rightStage);
    scrollToCompareStart(leftStage, rightStage);

    if (leftNote) leftNote.textContent = cached.leftNote || '';
    if (rightNote) rightNote.textContent = cached.rightNote || '';

    if (cached.changedRows?.length && cached.alignment?.length) {
        const analysis = {
            changedRows: cached.changedRows,
            stats: cached.changeStats || {
                changed: cached.changedRows.length,
                total: cached.alignment.length,
            },
        };
        setupChangeNavigator(root, analysis, cached.alignment, leftStage, rightStage);
    }

    return true;
}

/**
 * PDF comparison:
 * - page N in previous ↔ page N in current (real page positions)
 * - word highlights on both sides (red = removed/changed on previous, green = added on current)
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

    if (leftStage) {
        leftStage.innerHTML = '';
        delete leftStage.dataset.scrollSync;
    }
    if (rightStage) {
        rightStage.innerHTML = '';
        delete rightStage.dataset.scrollSync;
    }
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
                    const ok = await paintCached(root, memHit, cacheKey);
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
                    const ok = await paintCached(root, cached, cacheKey);
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

        if (!leftMeta.storagePath && !leftMeta.file) {
            setStatus(root, 'Could not resolve the previous PDF path for OCR. Try refreshing the page.', 'error');
            return;
        }
        if (!rightMeta.storagePath && !rightMeta.file) {
            setStatus(root, 'Could not resolve the current PDF path for OCR. Try refreshing the page.', 'error');
            return;
        }

        setStatus(root, 'Scanning for changed pages…', 'loading');
        const unchangedPairs = await findUnchangedPagePairs(leftDoc, rightDoc, (cur, tot) => {
            setStatus(root, `Scanning pages ${cur}/${tot} for changes…`, 'loading');
        });
        const skipLeft = new Set(unchangedPairs.map((p) => p.leftPage));
        const skipRight = new Set(unchangedPairs.map((p) => p.rightPage));
        const skipCount = unchangedPairs.length;

        setStatus(root, 'Reading text (OCR only on changed pages)…', 'loading');
        showStagePlaceholder(leftStage, 'Reading previous PDF…');
        showStagePlaceholder(rightStage, 'Reading latest PDF…');

        const statusFn = (t, kind = 'loading') => setStatus(root, t, kind);
        const [leftPages, rightPages] = await Promise.all([
            ensurePageTexts(leftDoc, leftMeta, statusFn, { unchangedPages: skipLeft }),
            ensurePageTexts(rightDoc, rightMeta, statusFn, { unchangedPages: skipRight }),
        ]);
        if (root.__drrAbort) {
            setStatus(root, 'Comparison cancelled.', 'info');
            return;
        }

        setStatus(root, 'Aligning OCR for highlights…', 'loading');
        await harmonizeCompareTokens(leftPages, rightPages, leftMeta, rightMeta, statusFn);

        if (root.__drrAbort) {
            setStatus(root, 'Comparison cancelled.', 'info');
            return;
        }

        const leftReadable = countPaintablePages(leftPages);
        const rightReadable = countPaintablePages(rightPages);
        if (!leftReadable && !rightReadable) {
            setStatus(
                root,
                'Could not read text from either PDF (OCR failed). Check that Tesseract is installed on the server and the scan files are accessible.',
                'error'
            );
            return;
        }
        if (leftReadable < Math.min(leftPages.length, 1) || rightReadable < Math.min(rightPages.length, 1)) {
            setStatus(root, 'OCR partially failed — some pages may not highlight. Continuing…', 'info');
        }

        setStatus(root, 'Preparing page-by-page comparison…', 'loading');
        if (leftStage) {
            leftStage.innerHTML = '';
            delete leftStage.dataset.scrollSync;
        }
        if (rightStage) {
            rightStage.innerHTML = '';
            delete rightStage.dataset.scrollSync;
        }

        const { alignment, mode: alignMode, similarity: docSimilarity } = alignPagesSmart(leftPages, rightPages);
        const changeAnalysis = analyzeAlignmentChanges(alignment);
        const width = Math.max(leftStage?.clientWidth || rightStage?.clientWidth || 480, 280);

        const leftBlobs = [];
        const rightBlobs = [];
        let ocrUsed = false;
        const removedCount = changeAnalysis.stats.removed;
        const addedCount = changeAnalysis.stats.added;
        let renderErrors = 0;
        let highlightRows = changeAnalysis.stats.changed;
        const changedRowSet = new Set(changeAnalysis.changedRows);

        const matchCount = countAlignmentMatches(alignment);

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

                const stats = changeAnalysis.rowStats[i];
                const leftMarks = stats?.leftMarks || [];
                const rightMarks = stats?.rightMarks || [];
                const isChanged = changedRowSet.has(i);

                if (slot.left?.usedOcr || slot.right?.usedOcr) ocrUsed = true;

                const frame = '';

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
                const sumLeft = document.createElement('div');
                sumLeft.className = 'drr-page-summary';
                sumLeft.textContent = summaryForSlot(slot, stats, 'left');
                const sumRight = document.createElement('div');
                sumRight.className = 'drr-page-summary';
                sumRight.textContent = summaryForSlot(slot, stats, 'right');

                let leftRowWrap = null;
                let rightRowWrap = null;

                if (leftStage) {
                    if (leftWrap && slot.leftPage) {
                        leftWrap.classList.toggle('drr-page-changed', isChanged);
                        leftWrap.classList.toggle('drr-page-unchanged', !isChanged);
                        leftWrap.prepend(cap.cloneNode(true));
                        if (sumLeft.textContent) leftWrap.appendChild(sumLeft);
                        leftWrap.dataset.compareRow = String(i);
                        leftStage.appendChild(leftWrap);
                        leftRowWrap = leftWrap;
                        const canvas = leftWrap.querySelector('canvas');
                        leftBlobs.push(canvas ? await canvasToBlob(canvas) : null);
                    } else {
                        const ph = emptyPagePlaceholder('No page in previous revision');
                        ph.prepend(cap.cloneNode(true));
                        ph.dataset.compareRow = String(i);
                        leftStage.appendChild(ph);
                        leftRowWrap = ph;
                        leftBlobs.push(null);
                    }
                }

                if (rightStage) {
                    if (rightWrap && slot.rightPage) {
                        rightWrap.classList.toggle('drr-page-changed', isChanged);
                        rightWrap.classList.toggle('drr-page-unchanged', !isChanged);
                        rightWrap.prepend(cap);
                        if (sumRight.textContent) rightWrap.appendChild(sumRight);
                        rightWrap.dataset.compareRow = String(i);
                        rightStage.appendChild(rightWrap);
                        rightRowWrap = rightWrap;
                        const canvas = rightWrap.querySelector('canvas');
                        rightBlobs.push(canvas ? await canvasToBlob(canvas) : null);
                    } else {
                        const ph = emptyPagePlaceholder('No page in current revision');
                        ph.prepend(cap.cloneNode(true));
                        ph.dataset.compareRow = String(i);
                        rightStage.appendChild(ph);
                        rightRowWrap = ph;
                        rightBlobs.push(null);
                    }
                }

                balanceCompareRow(leftRowWrap, rightRowWrap);
            }

            setupCompareScrollSync(leftStage, rightStage);
            if (offset === 0) {
                scrollToCompareStart(leftStage, rightStage);
            }

            offset = end;
            loadMoreBtn.style.display = offset < total ? 'inline-flex' : 'none';
            loadMoreBtn.textContent = `Load more pages (${offset}/${total})`;

            if (!root.__drrNavReady || offset >= total) {
                setupChangeNavigator(root, changeAnalysis, alignment, leftStage, rightStage, offset >= total);
                root.__drrNavReady = true;
            }

            const ocrWarnings = [
                ...(leftPages.__ocrWarnings || []),
                ...(rightPages.__ocrWarnings || []),
            ];

            const leftMsg = [
                `${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} with changes`,
                skipCount ? `${skipCount} unchanged (OCR skipped)` : '',
                removedCount ? `${removedCount} previous-only` : '',
                ocrUsed ? 'OCR on changed pages' : '',
            ].filter(Boolean).join(' · ');
            const rightMsg = [
                `${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} with changes`,
                skipCount ? `${skipCount} unchanged (OCR skipped)` : '',
                addedCount ? `${addedCount} current-only` : '',
                ocrUsed ? 'OCR on changed pages' : '',
            ].filter(Boolean).join(' · ');
            if (leftNote) leftNote.textContent = leftMsg;
            if (rightNote) rightNote.textContent = rightMsg;

            if (cacheKey && offset >= total) {
                scrollToCompareStart(leftStage, rightStage);

                if (highlightRows === 0) {
                    setStatus(
                        root,
                        'Comparison finished but no highlights were generated. OCR may have failed — check server Tesseract/Ghostscript, then hard-refresh and compare again.',
                        'error'
                    );
                    return;
                }

                const shouldCache = highlightRows > 0 && matchCount > 0;
                if (!shouldCache) {
                    setStatus(
                        root,
                        `Comparison complete · ${matchCount} page pairs`
                            + (removedCount ? `, ${removedCount} previous-only` : '')
                            + (addedCount ? `, ${addedCount} current-only` : '')
                            + ' · re-run to refresh cache.',
                        'success'
                    );
                    return;
                }

                try {
                    const saved = await putCompareCache({
                        key: cacheKey,
                        leftUrl: String(leftUrl || '').startsWith('blob:') ? '' : leftUrl,
                        rightUrl: String(rightUrl || '').startsWith('blob:') ? '' : rightUrl,
                        leftPages: leftBlobs,
                        rightPages: rightBlobs,
                        leftNote: leftMsg,
                        rightNote: rightMsg,
                        alignment: alignment.map((s) => ({
                            type: s.type,
                            leftPage: s.leftPage,
                            rightPage: s.rightPage,
                            pairedBy: s.pairedBy || 'content',
                        })),
                        alignMode,
                        docSimilarity,
                        pageOffset: offset,
                        totalPages: total,
                        version: CACHE_VERSION,
                        hasHighlights: highlightRows > 0,
                        changedRows: changeAnalysis.changedRows,
                        changeStats: changeAnalysis.stats,
                    });
                    if (saved) {
                        setStatus(
                            root,
                            `Comparison complete · ${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} with changes`
                                + (skipCount ? ` · ${skipCount} unchanged pages skipped OCR` : '')
                                + ' · saved locally.',
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
                scrollToCompareStart(leftStage, rightStage);
                if (highlightRows === 0) {
                    setStatus(
                        root,
                        'Comparison finished but no word highlights were painted. Re-run after confirming OCR works on the server (Tesseract + Ghostscript).',
                        'error'
                    );
                    return;
                }
                setStatus(
                    root,
                    `Comparison complete · ${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} with changes`
                        + (skipCount ? ` · ${skipCount} unchanged pages skipped OCR` : '')
                        + (removedCount ? ` · ${removedCount} previous-only` : '')
                        + (addedCount ? ` · ${addedCount} current-only` : '')
                        + '. Use Prev/Next change to jump between edits.',
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
    return 'drr-v15:' + await hashString(String(leftId) + '||' + String(rightId));
}

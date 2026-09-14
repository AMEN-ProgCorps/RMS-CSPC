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
    del: 'rgba(239, 68, 68, 0.42)',
    ins: 'rgba(34, 197, 94, 0.42)',
    chg: 'rgba(234, 179, 8, 0.55)',
    approx: 'rgba(253, 230, 138, 0.28)',
};

const CHUNK_SIZE = 6;
const OCR_BATCH = 1;
/** Parallel OCR requests per document side. */
const OCR_CONCURRENCY = 3;
/** Prefer embedded PDF text when this many tokens exist; otherwise OCR. */
const RICH_EMBEDDED_TEXT = 20;
const TOKEN_CAP = 4000;
/** When content alignment finds nothing but docs share vocabulary, pair by page index. */
const DOC_SIMILARITY_INDEX_FALLBACK = 0.18;
/** Bump whenever highlight algorithm changes so stale IndexedDB caches are discarded. */
const CACHE_VERSION = 44;
/**
 * Pixel diff below this ⇒ candidate for identical (must also pass text check).
 */
const VISUAL_UNCHANGED_THRESHOLD = 0.006;
/** Exact (or near-exact) token overlap required before suppressing highlights. */
const PAGE_SIMILARITY_SKIP = 0.995;
/** Max normalized distance to treat OCR words as the same cell/position (forms only). */
const SPATIAL_MATCH_RADIUS = 0.055;
/** Kept for identical-page scan only — never painted as highlights. */
const VISUAL_TILE_COLS = 16;
const VISUAL_TILE_ROWS = 22;
const VISUAL_TILE_DIFF = 0.12;
/** Common canvas size for visual compare (identical-page detection). */
const VISUAL_NORM_W = 64;
const VISUAL_NORM_H = 88;
const LARGE_DOC_PAGES = 12;
/** Line cluster Y tolerance (normalized page coords). */
const LINE_Y_TOL = 0.018;
/** Page content match threshold for content-based alignment. */
const PAGE_CONTENT_MATCH = 0.26;
/** Label heavily rewritten pages (still paint word underlines). */
const DENSE_REWRITE_RATIO = 0.55;
/** Sentences equal enough to leave completely unhighlighted. */
const LINE_MATCH_SAME = 0.90;
/** Sentences similar enough to treat as an UPDATE (whole sentence yellow). */
const LINE_MATCH_RELATED = 0.55;
/** Skip painting tiny function words — they add noise without helping review. */
const NOISE_DIFF_WORDS = new Set([
    'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'by', 'as', 'at',
    'is', 'are', 'be', 'been', 'was', 'were', 'it', 'its', 'this', 'that', 'with',
    'from', 'into', 'than', 'then', 'also', 'such', 'any', 'all', 'not', 'no',
]);

const ROMAN_TO_ARABIC = {
    i: '1', ii: '2', iii: '3', iv: '4', v: '5', vi: '6', vii: '7', viii: '8', ix: '9', x: '10',
    xi: '11', xii: '12', xiii: '13', xiv: '14', xv: '15', xvi: '16', xvii: '17', xviii: '18',
    xix: '19', xx: '20',
};

/** Article / Section heading at start of a reading-order line. */
const HEADING_ARTICLE_RE = /^\s*articles?\s+([ivxlcdm]+|\d+)\b(?:\s*[-–—:.]+\s*|\s+)?(.*)$/i;
const HEADING_SECTION_RE = /^\s*sections?\s+(\d+)\b(?:\s*[-–—:.]+\s*|\s+)?(.*)$/i;

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
        // Fold common OCR confusions before stripping punctuation.
        .replace(/['’`]/g, '')
        .replace(/[^\p{L}\p{N}]+/gu, '');
    if (ROMAN_TO_ARABIC[s]) {
        s = ROMAN_TO_ARABIC[s];
    }
    // Light OCR cleanup: rn↔m is handled by edit distance; strip leading zeros on numbers.
    if (/^\d+$/.test(s)) {
        s = String(Number(s));
    }
    return s;
}

/** Paddle has used both 0–1 and 0–100 confidence scales across releases. */
function ocrConfidence(value) {
    if (value === null || value === undefined || value === '') return null;
    const confidence = Number(value);
    if (!Number.isFinite(confidence) || confidence < 0) return null;
    return confidence <= 1 ? confidence * 100 : confidence;
}

/**
 * Estimate a word's visual width when OCR gives one box for a whole phrase.
 * Equal slices visibly shift highlights for short words beside long ones.
 */
function visualTextWidth(text) {
    let width = 0;
    for (const char of String(text || '')) {
        if (/[ilI1|!.,:;'`]/.test(char)) width += 0.48;
        else if (/[mwMW@%&]/.test(char)) width += 1.35;
        else if (/[A-Z0-9]/.test(char)) width += 1.08;
        else width += 0.95;
    }
    return Math.max(width, 0.5);
}

/** Return proportional word boxes while reserving a small amount for spaces. */
function splitPhraseBox(box, parts) {
    if (!box || !parts?.length) return [];
    const weights = parts.map(visualTextWidth);
    const gap = 0.30;
    const total = weights.reduce((sum, weight) => sum + weight, 0)
        + gap * Math.max(parts.length - 1, 0);
    if (!Number.isFinite(total) || total <= 0) return [];

    const unit = box.w / total;
    let cursor = box.x;
    return parts.map((part, index) => {
        const fullWidth = Math.max(unit * weights[index], 0.002);
        const wordBox = {
            x: cursor,
            y: box.y,
            w: Math.max(fullWidth * 0.98, 0.002),
            h: box.h,
        };
        cursor += fullWidth + (index < parts.length - 1 ? unit * gap : 0);
        return wordBox;
    });
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

    const conf = ocrConfidence(w?.conf);
    if (Number.isFinite(conf) && conf >= 0 && conf < 20) return false;

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

        const boxes = splitPhraseBox({ x, y, w: lw, h: lh }, words);
        words.forEach((t, idx) => {
            const norm = normalizeWord(t);
            if (!norm) return;
            out.push({
                t,
                norm,
                ocr: true,
                item: null,
                box: boxes[idx] || null,
            });
        });
    }
    return out;
}

function tokensFromOcrPage(row) {
    const fromWords = tokensFromOcrWords(row?.words).slice(0, TOKEN_CAP);
        const fromLines = tokensFromOcrLines(row?.lines).slice(0, TOKEN_CAP);
    const wordPaint = fromWords.filter(tokenPaintable).length;
    // Prefer real word boxes so shared wording can cancel word-by-word.
    // Line tokens made "INTELLECTUAL PROPERTY" one unit and falsely highlighted
    // when the other side had the same words separately.
    if (wordPaint >= 8) return fromWords;
    if (fromWords.length >= fromLines.length && fromWords.length) return fromWords;
    if (fromLines.length) return fromLines;
    return fromWords;
}

function tokensFromOcrWords(words) {
    return (Array.isArray(words) ? words : [])
        .flatMap((w) => {
            const t = String(w?.t || '').trim();
            if (!t) return [];

            if (w?.synthetic === true) {
                return explodePhraseToken({
                    t,
                    norm: normalizeWord(t),
                    ocr: true,
                    synthetic: true,
                    item: null,
                    box: null,
                });
            }
            if (!isValidOcrWord(w)) return [];

            const x = Number(w.x);
            const y = Number(w.y);
            const bw = Number(w.w);
            const bh = Number(w.h);
            return explodePhraseToken({
                t,
                norm: normalizeWord(t),
                ocr: true,
                conf: ocrConfidence(w.conf),
                synthetic: false,
                item: null,
                box: { x, y, w: bw, h: bh },
            });
        });
}

/**
 * Split multi-word OCR detections into single words with sliced boxes.
 * Critical so "INTELLECTUAL PROPERTY" matches separate "INTELLECTUAL"+"PROPERTY".
 */
function explodePhraseToken(token) {
    if (!token) return [];
    const parts = String(token.t || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return [];
    if (parts.length === 1) {
        const norm = normalizeWord(parts[0]);
        return norm ? [{ ...token, t: parts[0], norm }] : [];
    }

    const box = token.box;
    if (box && Number.isFinite(box.x) && Number.isFinite(box.w)) {
        const boxes = splitPhraseBox(box, parts);
        return parts.map((part, idx) => {
            const norm = normalizeWord(part);
            if (!norm) return null;
            return {
                ...token,
                t: part,
                norm,
                item: null,
                box: boxes[idx] || null,
            };
        }).filter(Boolean);
    }

    return parts.map((part) => {
        const norm = normalizeWord(part);
        if (!norm) return null;
        return { ...token, t: part, norm, item: null };
    }).filter(Boolean);
}

/** Keep tokens for matching; always word-level so shared vocab cancels cleanly. */
function prepareTokensForDiff(tokens) {
    return explodePhraseTokenList(tokens || [])
        .map((t) => ({ ...t }))
        .filter((t) => t.norm);
}

function explodePhraseTokenList(tokens) {
    const out = [];
    for (const token of tokens || []) {
        const parts = String(token?.t || '').trim().split(/\s+/).filter(Boolean);
        if (parts.length <= 1) {
            if (token?.norm || normalizeWord(token?.t)) {
            out.push({
                    ...token,
                    t: parts[0] || token.t,
                    norm: token.norm || normalizeWord(token.t),
                });
            }
            continue;
        }
        out.push(...explodePhraseToken(token));
    }
    return out;
}

function normalizeArticleNum(raw) {
    const s = String(raw || '').trim().toLowerCase();
    if (!s) return null;
    if (ROMAN_TO_ARABIC[s]) return ROMAN_TO_ARABIC[s];
    if (/^\d+$/.test(s)) return String(Number(s));
    return s;
}

function sectionTitleStem(title) {
    return normalizeWord(title).slice(0, 40);
}

/** Full key (article + section) — useful for debugging / UI later. */
function makeSectionKey(article, section, sectionTitle) {
    if (!article && !section) return 'preamble';
    const parts = [];
    if (article) parts.push(`article:${article}`);
    if (section) {
        const stem = sectionTitleStem(sectionTitle || '');
        parts.push(stem ? `section:${section}:${stem}` : `section:${section}`);
    }
    return parts.join('/');
}

/**
 * Matching identity: titled sections match by number+title even if they moved
 * to another Article (e.g. Art I §1 Purpose → Art II §1 Purpose).
 * Article bodies (no section) stay article-scoped.
 * Letterhead + pre-Article front matter share doc-front so shared banners cancel.
 */
function makeSectionSoftKey(article, section, sectionTitle, special = null) {
    if (special === 'running-header' || special === 'doc-front') return 'doc-front';
    if (special) return special;
    if (section) {
        const stem = sectionTitleStem(sectionTitle || '');
        return stem ? `section:${section}:${stem}` : `section:${section}`;
    }
    if (article) return `article:${article}`;
    return 'doc-front';
}

function parseHeadingFromRaw(raw) {
    const text = String(raw || '').trim();
    if (!text) return null;
    let m = text.match(HEADING_ARTICLE_RE);
    if (m) {
        const num = normalizeArticleNum(m[1]);
        if (!num) return null;
        return { kind: 'article', num, title: String(m[2] || '').trim(), consumeNext: false };
    }
    m = text.match(HEADING_SECTION_RE);
    if (m) {
        const num = String(Number(m[1]));
        if (!num || num === 'NaN') return null;
        return { kind: 'section', num, title: String(m[2] || '').trim(), consumeNext: false };
    }
    return null;
}

/** OCR often puts "Article" / "I" on separate lines — peek the next line. */
function parseHeadingWithPeek(line, nextLine) {
    const direct = parseHeadingFromRaw(line?.raw);
    if (direct) return direct;

    const words = String(line?.raw || '').trim();
    const nextRaw = String(nextLine?.raw || '').trim();
    if (!nextRaw) return null;

    if (/^articles?$/i.test(words)) {
        const m = nextRaw.match(/^([ivxlcdm]+|\d+)\b(?:\s*[-–—:.]+\s*|\s+)?(.*)$/i);
        if (m) {
            const num = normalizeArticleNum(m[1]);
            if (num) {
                return {
                    kind: 'article',
                    num,
                    title: String(m[2] || '').trim(),
                    consumeNext: true,
                };
            }
        }
    }
    if (/^sections?$/i.test(words)) {
        const m = nextRaw.match(/^(\d+)\b(?:\s*[-–—:.]+\s*|\s+)?(.*)$/i);
        if (m) {
            return {
                kind: 'section',
                num: String(Number(m[1])),
                title: String(m[2] || '').trim(),
                consumeNext: true,
            };
        }
    }
    return null;
}

function lineAvgY(line) {
    const tokens = line?.tokens || [];
    if (!tokens.length) return Number(line?.y) || 0;
    let sum = 0;
    let n = 0;
    for (const t of tokens) {
        if (t?.box && Number.isFinite(t.box.y)) {
            sum += t.box.y;
            n++;
        }
    }
    if (n) return sum / n;
    return Number(line?.y) || 0;
}

/**
 * Letterhead / org banner repeated at the top of scanned pages must not inherit
 * the previous page's Article/Section — that caused shared headers to highlight.
 * Do NOT treat resolution titles / body ALL-CAPS lines as headers.
 */
function looksLikeRunningHeader(line) {
    const y = lineAvgY(line);
    if (y > 0.18) return false;
    const raw = String(line?.raw || '').trim();
    if (!raw || raw.length > 100) return false;
    if (parseHeadingFromRaw(raw)) return false;
    // Body / resolution content — never a running header.
    if (/approving|whereas|governing|researches|innovations|faculty|students|therefore|resolved|certification|certify|guidelines\s+governing|intellectual\s+propert/i.test(raw)) {
        return false;
    }
    if (/^board of trustees$/i.test(raw)) return true;
    if (/camarines|polytechnic|colleges/i.test(raw) && raw.length <= 70) return true;
    if (/republic of the philippines|nabua/i.test(raw) && raw.length <= 80) return true;
    const letters = raw.replace(/[^a-zA-Z]/g, '');
    // Short all-caps banner only (not long title lines).
    if (letters.length >= 8 && letters === letters.toUpperCase() && raw.length <= 48 && y <= 0.14) {
        return true;
    }
    return false;
}

/** Reading-order lines for Article/Section tagging (preserves token refs). */
function linesForSectionTagging(tokens) {
    const list = (tokens || []).filter((t) => t && (t.norm || normalizeWord(t.t)));
    if (!list.length) return [];
    const hasGeom = list.some((t) => t.box || t.item);
    if (!hasGeom) {
        // Split before Article/Section tokens so multi-heading pages still group.
        const lines = [];
        let current = [];
    for (const t of list) {
            const word = String(t.t || '').trim();
            if (/^articles?$/i.test(word) || /^sections?$/i.test(word)) {
                if (current.length) {
                    lines.push({
                        tokens: current,
                        raw: current.map((x) => x.t).join(' '),
                    });
                }
                current = [t];
            } else {
                current.push(t);
            }
        }
        if (current.length) {
            lines.push({
                tokens: current,
                raw: current.map((x) => x.t).join(' '),
            });
        }
        return lines;
    }
    const lines = clusterTokensIntoLines(list);
    for (const line of lines) {
        line.raw = line.tokens.map((t) => t.t).join(' ');
    }
    return lines;
}

function applySectionTagsToTokens(tokens, article, section, sectionTitle, special, isHeading) {
    const sectionKey = special || makeSectionKey(article, section, sectionTitle);
    const sectionSoftKey = makeSectionSoftKey(article, section, sectionTitle, special);
    for (const t of tokens || []) {
        t.sectionKey = sectionKey;
        t.sectionSoftKey = sectionSoftKey;
        t.isHeading = !!isHeading;
    }
}

/**
 * Tag every token with sectionKey / sectionSoftKey from the nearest heading above
 * it. Heading state carries across page breaks; running headers stay isolated.
 */
function assignSectionKeysToPages(pages) {
    let article = null;
    let section = null;
    let sectionTitle = '';

    for (const page of pages || []) {
        const lines = linesForSectionTagging(page.tokens || []);
        if (!lines.length) {
            applySectionTagsToTokens(page.tokens || [], article, section, sectionTitle, null, false);
            continue;
        }

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];

            if (looksLikeRunningHeader(line)) {
                applySectionTagsToTokens(line.tokens, null, null, '', 'running-header', false);
                continue;
            }

            const heading = parseHeadingWithPeek(line, lines[i + 1]);
            if (heading?.kind === 'article') {
                article = heading.num;
                section = null;
                sectionTitle = '';
                applySectionTagsToTokens(line.tokens, article, section, sectionTitle, null, true);
                if (heading.consumeNext && lines[i + 1]) {
                    i += 1;
                    applySectionTagsToTokens(lines[i].tokens, article, section, sectionTitle, null, true);
                }
                continue;
            }
            if (heading?.kind === 'section') {
                section = heading.num;
                sectionTitle = heading.title;
                applySectionTagsToTokens(line.tokens, article, section, sectionTitle, null, true);
                if (heading.consumeNext && lines[i + 1]) {
                    i += 1;
                    // Title may live on the peeked line — prefer its remainder as title.
                    if (!sectionTitle && lines[i].raw) {
                        const m = String(lines[i].raw).trim().match(
                            /^(\d+|[ivxlcdm]+)\b(?:\s*[-–—:.]+\s*|\s+)?(.*)$/i
                        );
                        if (m) sectionTitle = String(m[2] || '').trim();
                    }
                    applySectionTagsToTokens(lines[i].tokens, article, section, sectionTitle, null, true);
                }
                continue;
            }
            applySectionTagsToTokens(line.tokens, article, section, sectionTitle, null, false);
        }
    }
}

function tokenSectionMatchKey(token) {
    return token?.sectionSoftKey || token?.sectionKey || 'preamble';
}

function clearDiffAnnotations(pages) {
    for (const page of pages || []) {
        for (const t of page?.tokens || []) {
            if (t && '__diff' in t) delete t.__diff;
        }
    }
}

/** Reading-order paragraph blocks across the whole document (Draftable-style units). */
function collectAllSentences(pages) {
    const blocks = [];
    for (const page of pages || []) {
        if (page?.unchanged) continue;
        const headerToks = [];
        const bodyToks = [];
        for (const t of page.tokens || []) {
            if (!t?.norm) continue;
            if (t.sectionKey === 'running-header') headerToks.push(t);
            else bodyToks.push(t);
        }
        for (const group of [headerToks, bodyToks]) {
            if (!group.length) continue;
            for (const block of clusterTokensIntoBlocks(group)) {
                if (!block.tokens.length) continue;
                block.page = page.page || 0;
                block.sectionSoftKey = tokenSectionMatchKey(block.tokens[0]);
                blocks.push(block);
            }
        }
    }
    return blocks;
}

function significantWordSet(sentence) {
    const set = new Set();
    for (const w of String(sentence?.significant || sentence?.text || '').split(/\s+/)) {
        if (!w || w.length <= 3) continue;
        if (NOISE_DIFF_WORDS.has(w)) continue;
        set.add(w);
        // excerpt ↔ excerpts
        if (w.endsWith('s') && w.length > 5) set.add(w.slice(0, -1));
    }
    return set;
}

/** Related blocks can only be updates when they belong to the same section. */
function sectionKeysCompatible(left, right) {
    const leftKey = String(left?.sectionSoftKey || 'doc-front');
    const rightKey = String(right?.sectionSoftKey || 'doc-front');
    if (leftKey === rightKey) return true;

    // A renamed Section 2 is still Section 2; its title should be highlighted
    // as an edit instead of presenting the complete section as delete/add.
    const sectionNumber = (key) => key.match(/^section:(\d+)(?::|$)/)?.[1] || null;
    const leftSection = sectionNumber(leftKey);
    const rightSection = sectionNumber(rightKey);
    return Boolean(leftSection && leftSection === rightSection);
}

/**
 * How alike two sentences are for "updated" pairing.
 * Bag overlap + significant-word Jaccard + same-role template boosts.
 * Different discourse roles (WHEREAS vs CERTIFY) never count as updates.
 */
function sentenceUpdateSimilarity(a, b) {
    if (!a || !b) return 0;
    if (a.significant && b.significant && a.significant === b.significant) return 1;
    if (a.text && b.text && a.text === b.text) return 1;

    const la = (a.tokens || []).length;
    const lb = (b.tokens || []).length;
    if (!la || !lb) return 0;
    const lengthRatio = Math.min(la, lb) / Math.max(la, lb);
    // Very different lengths are almost never the same sentence updated.
    if (lengthRatio < 0.40 && Math.max(la, lb) >= 10) {
        return 0;
    }

    const bag = pageTextSimilarity(a.tokens || [], b.tokens || []);
    const aSet = significantWordSet(a);
    const bSet = significantWordSet(b);
    let hit = 0;
    for (const w of aSet) {
        if (bSet.has(w)) hit++;
    }
    const union = aSet.size + bSet.size - hit;
    const jaccard = union > 0 ? hit / union : 0;
    let score = Math.max(bag, jaccard);

    const has = (set, ...words) => words.some((w) => set.has(w));
    const roleOf = (set) => {
        if (has(set, 'whereas')) return 'whereas';
        if (has(set, 'certify', 'certification', 'concern')) return 'certify';
        if (has(set, 'excerpt', 'excerpts') && has(set, 'minutes')) return 'excerpt';
        if (has(set, 'approving') || (has(set, 'resolution') && has(set, 'policy', 'guidelines', 'intellectual'))) {
            return 'resolution-title';
        }
        if (has(set, 'resolution') && set.size <= 8) return 'resolution-id';
        return 'other';
    };
    const roleA = roleOf(aSet);
    const roleB = roleOf(bSet);
    if (roleA !== 'other' && roleB !== 'other' && roleA !== roleB) {
        return 0;
    }

    if (roleA === 'excerpt' && roleB === 'excerpt') {
        score = Math.max(score, 0.70);
    }
    if (roleA === 'resolution-title' && roleB === 'resolution-title') {
        score = Math.max(score, 0.62);
    }
    if (roleA === 'resolution-id' && roleB === 'resolution-id') {
        score = Math.max(score, 0.72);
    }
    if (roleA === 'whereas' && roleB === 'whereas') {
        // Only pair WHEREAS clauses that actually share substance.
        score = jaccard >= 0.45 ? Math.max(score, jaccard) : Math.min(score, 0.35);
    }
    if ((a.sectionSoftKey || '') === (b.sectionSoftKey || '') && a.sectionSoftKey) {
        score = Math.min(1, score + 0.04);
    }
    // Prefer similar length updates.
    score *= 0.75 + 0.25 * lengthRatio;
    return score;
}

/**
 * Sentence-level diff (document-wide):
 * - same sentence → quiet
 * - related/updated sentence → whole sentence yellow on both sides
 * - unmatched sentence → full red (old) or green (new)
 */
function annotateSectionLinesDiff(leftLines, rightLines) {
    const usedRight = new Set();
    const paired = [];

    const tryPair = (minScore) => {
        for (let li = 0; li < leftLines.length; li++) {
            if (paired.some((p) => p.left === leftLines[li])) continue;
            let best = -1;
            let bestScore = 0;
            for (let ri = 0; ri < rightLines.length; ri++) {
                if (usedRight.has(ri)) continue;
                if (!sectionKeysCompatible(leftLines[li], rightLines[ri])) continue;
                const raw = sentenceUpdateSimilarity(leftLines[li], rightLines[ri]);
                const pageGap = Math.abs((leftLines[li].page || 0) - (rightLines[ri].page || 0));
                const score = raw - Math.min(pageGap, 8) * 0.015;
                if (score > bestScore) {
                    bestScore = score;
                    best = ri;
                }
            }
            if (best < 0 || bestScore < minScore) continue;

            const shortHeading = (leftLines[li].tokens.length <= 5)
                || (rightLines[best].tokens.length <= 5);
            if (shortHeading && bestScore < Math.max(minScore, 0.84)) {
                continue;
            }

            usedRight.add(best);
            paired.push({ left: leftLines[li], right: rightLines[best], score: bestScore });
        }
    };

    tryPair(LINE_MATCH_SAME);
    tryPair(LINE_MATCH_RELATED);

    const leftPaired = new Set(paired.map((p) => p.left));
    const rightPaired = new Set(paired.map((p) => p.right));

    for (const pair of paired) {
        if (pair.score >= LINE_MATCH_SAME) {
            continue;
        }
        annotateTokensAsKind(pair.left.tokens, 'chg');
        annotateTokensAsKind(pair.right.tokens, 'chg');
    }

    annotateTokensAsKind(
        leftLines.filter((l) => !leftPaired.has(l)).flatMap((l) => l.tokens),
        'del'
    );
    annotateTokensAsKind(
        rightLines.filter((l) => !rightPaired.has(l)).flatMap((l) => l.tokens),
        'ins'
    );
}

/**
 * One visual line → one highlight color, then one paragraph block → one color.
 * Draftable-style: no mixed red/yellow/green speckles inside a block.
 */
function dominantDiffKind(tokens) {
    const counts = { del: 0, ins: 0, chg: 0, none: 0 };
    for (const t of tokens || []) {
        if (t.__diff === 'del' || t.__diff === 'ins' || t.__diff === 'chg') {
            counts[t.__diff]++;
        } else {
            counts.none++;
        }
    }
    const marked = counts.del + counts.ins + counts.chg;
    const total = (tokens || []).length || 1;
    if (marked === 0) return null;
    if (marked / total < 0.22) return null;

    let best = null;
    let bestN = -1;
    for (const k of ['del', 'ins', 'chg']) {
        if (counts[k] > bestN) {
            bestN = counts[k];
            best = k;
        }
    }
    if (counts.del && counts.chg) {
        best = counts.chg >= counts.del ? 'chg' : 'del';
    } else if (counts.ins && counts.chg) {
        best = counts.chg >= counts.ins ? 'chg' : 'ins';
    } else if (counts.del && counts.ins) {
        best = counts.del >= counts.ins ? 'del' : 'ins';
    }
    return best;
}

function unifyLineDiffAnnotations(pages) {
    for (const page of pages || []) {
        const lines = clusterTokensIntoLines(page.tokens || []);
        for (const line of lines) {
            const toks = line.tokens || [];
            if (toks.length < 2) continue;
            const best = dominantDiffKind(toks);
            if (!best) continue;
            annotateTokensAsKind(toks, best);
        }
    }
}

function unifyBlockDiffAnnotations(pages) {
    for (const page of pages || []) {
        if (page?.unchanged) continue;
        const headerToks = [];
        const bodyToks = [];
        for (const t of page.tokens || []) {
            if (!t?.norm) continue;
            if (t.sectionKey === 'running-header') headerToks.push(t);
            else bodyToks.push(t);
        }
        for (const group of [headerToks, bodyToks]) {
            if (!group.length) continue;
            for (const block of clusterTokensIntoBlocks(group)) {
                const best = dominantDiffKind(block.tokens);
                if (!best) continue;
                annotateTokensAsKind(block.tokens, best);
            }
        }
    }
}

/**
 * Draftable-style block compare:
 * yellow = updated paragraph, red = only in old, green = only in new.
 */
function annotateSectionWordDiff(leftPages, rightPages) {
    clearDiffAnnotations(leftPages);
    clearDiffAnnotations(rightPages);
    const leftLines = collectAllSentences(leftPages);
    const rightLines = collectAllSentences(rightPages);
    if (!leftLines.length && !rightLines.length) return;
    if (!leftLines.length) {
        annotateTokensAsKind(rightLines.flatMap((l) => l.tokens), 'ins');
        unifyLineDiffAnnotations(rightPages);
        unifyBlockDiffAnnotations(rightPages);
        return;
    }
    if (!rightLines.length) {
        annotateTokensAsKind(leftLines.flatMap((l) => l.tokens), 'del');
        unifyLineDiffAnnotations(leftPages);
        unifyBlockDiffAnnotations(leftPages);
        return;
    }
    annotateSectionLinesDiff(leftLines, rightLines);
    unifyLineDiffAnnotations(leftPages);
    unifyLineDiffAnnotations(rightPages);
    unifyBlockDiffAnnotations(leftPages);
    unifyBlockDiffAnnotations(rightPages);
}

/**
 * @deprecated Invented grids mislead reviewers. Kept only for matching when OCR
 * returns text with zero geometry — highlights then use visual approx fallback.
 */
function inferBoxesFromText(text) {
    return tokensFromPlainText(text).map((t) => ({ ...t, ocr: true, synthetic: true, box: null }));
}

/** Prefer real OCR/PDF geometry; never invent a fake highlight grid. */
function ensureHighlightTokens(tokens, rawText = '') {
    const list = prepareTokensForDiff(tokens);
    if (list.length) return list;
    if (rawText) return inferBoxesFromText(rawText);
    return [];
}

function countPaintablePages(pages) {
    return (pages || []).filter((p) => (p.tokens || []).some((t) => tokenPaintable(t))).length;
}

function emptyMarks(extra = {}) {
    return { leftMarks: [], rightMarks: [], del: 0, ins: 0, chg: 0, ...extra };
}

function countPaintableMarks(result) {
    if (!result) return 0;
    return (result.leftMarks || []).length + (result.rightMarks || []).length;
}

/**
 * Only used when a real diff exists but nothing is drawable.
 * NEVER repaint every word as "changed" — that falsely highlighted identical text.
 */
function ensureDiffVisible(result, leftTokens = [], rightTokens = []) {
    if (!result) return emptyMarks();
    if (countPaintableMarks(result) > 0) return result;

    const changed = (result.del || 0) + (result.ins || 0) + (result.chg || 0)
        + (result.pageAdded ? 1 : 0) + (result.pageRemoved ? 1 : 0);

    // Shared wording matched — leave the page clean even if layout/OCR looks "dense".
    if (!changed) {
        return { ...result, denseRewrite: false, leftMarks: [], rightMarks: [] };
    }

    if (result.pageRemoved) {
        return { ...result, leftMarks: [pageCoverMark('del')], rightMarks: [] };
    }
    if (result.pageAdded) {
        return { ...result, leftMarks: [], rightMarks: [pageCoverMark('ins')] };
    }

    // Real word changes without geometry: one light page cue, not per-word false chg.
    const leftMarks = (result.del || result.chg) ? [pageCoverMark(result.ins ? 'chg' : 'del')] : [];
    const rightMarks = (result.ins || result.chg) ? [pageCoverMark(result.del ? 'chg' : 'ins')] : [];
    if (!leftMarks.length && !rightMarks.length) {
        return { ...result, leftMarks: [pageCoverMark('chg')], rightMarks: [pageCoverMark('chg')] };
    }
    return { ...result, leftMarks, rightMarks };
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
    const ver = Number(cached.version);
    // Missing/NaN version must invalidate — `NaN < 32` is false and used to keep stale caches.
    if (!Number.isFinite(ver) || ver < CACHE_VERSION) return true;
    const notes = `${cached.leftNote || ''} ${cached.rightNote || ''}`;
    if (/0 aligned|0 page pairs/.test(notes) && (cached.totalPages || 0) > 2) return true;
    const aligned = (cached.alignment || []).filter((s) => s.type === 'match').length;
    if (aligned === 0 && (cached.totalPages || 0) > 2) return true;
    // Old false-"identical" / highlight-less caches — always recompute.
    if (cached.hasHighlights !== true) return true;
    if ((cached.changeStats?.changed || 0) > 0 && (cached.wordMarks || cached.changeStats?.wordMarks || 0) === 0) {
        return true;
    }
    if (/look identical/i.test(notes) && (cached.changeStats?.changed || 0) === 0) {
        if ((cached.changeStats?.unchanged || 0) < (cached.totalPages || 0)) return true;
    }
    // "Added pages only" caches from old content-alignment — hide real page edits.
    if (
        (cached.changeStats?.added || 0) > 0
        && (cached.changeStats?.edited || 0) === 0
        && aligned >= 3
    ) {
        return true;
    }
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

    // Accept common scan substitutions only when at least one side was OCR.
    // Numeric-only values deliberately remain exact: 2025 → 2026 is a real edit.
    if ((a.ocr || b.ocr) && /\p{L}/u.test(x) && /\p{L}/u.test(y)) {
        const skeleton = (word) => word.replace(/[0158]/g, (char) => ({
            0: 'o',
            1: 'l',
            5: 's',
            8: 'b',
        }[char]));
        if (skeleton(x) === skeleton(y)) return true;
    }
    if (x.length < 4 || y.length < 4) return false;

    // Plural / stem: excerpt↔excerpts, guideline↔guidelines, minute↔minutes.
    if (x.length >= 5 && y.length >= 5) {
        const shorter = x.length <= y.length ? x : y;
        const longer = x.length <= y.length ? y : x;
        if (longer.startsWith(shorter) && shorter.length / longer.length >= 0.72) return true;
        if (longer === `${shorter}s` || longer === `${shorter}es`) return true;
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

function highlightItem(ctx, viewport, item, color, k = 'chg') {
    if (!item || !viewport) return;
    const tx = transform(viewport.transform, item.transform);
    const x = tx[4];
    const y = tx[5];
    const width = item.width * viewport.scale;
    const height = Math.abs(item.height * viewport.scale) || 10;
    const top = y - height;
    ctx.fillStyle = color;
    ctx.fillRect(x, top, Math.max(width, 2), Math.max(height, 2));
    const uh = 1;
    ctx.fillStyle = k === 'ins'
        ? 'rgba(22, 163, 74, 0.95)'
        : k === 'del'
            ? 'rgba(220, 38, 38, 0.95)'
            : 'rgba(217, 119, 6, 0.95)';
    ctx.fillRect(x, top + height + 0.5, Math.max(width, 2), uh);
}

/** Soft word/sentence fill + thin underline for real changes only. */
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

        // Cap tall OCR boxes to a text-line band so fills look like word highlights.
        // Sentence/block marks get a slightly taller band (Draftable-style).
        const maxH = mark.sentence
            ? Math.max(13, ch * 0.026)
            : Math.max(11, ch * 0.018);
        if (h > maxH) {
            y = y + (h - maxH) * 0.35;
            h = maxH;
        }

        if (mark.pageCover) {
            ctx.fillStyle = mark.k === 'ins'
                ? 'rgba(34, 197, 94, 0.22)'
                : mark.k === 'del'
                    ? 'rgba(239, 68, 68, 0.22)'
                    : 'rgba(245, 158, 11, 0.22)';
            ctx.fillRect(x, y, w, h);
            ctx.save();
            ctx.strokeStyle = mark.k === 'ins'
                ? 'rgba(22, 163, 74, 0.95)'
                : mark.k === 'del'
                    ? 'rgba(220, 38, 38, 0.95)'
                    : 'rgba(217, 119, 6, 0.95)';
            ctx.lineWidth = 3;
            ctx.strokeRect(x + 2, y + 2, Math.max(w - 4, 1), Math.max(h - 4, 1));
            ctx.restore();
            return;
        }

        // Word/sentence highlight fill.
        ctx.fillStyle = color;
        ctx.fillRect(x, y, w, h);
        // Thin underline accent.
        const uh = 1;
        const uy = Math.min(y + h + 0.5, ch - uh);
        ctx.fillStyle = mark.k === 'ins'
            ? 'rgba(22, 163, 74, 0.95)'
            : mark.k === 'del'
                ? 'rgba(220, 38, 38, 0.95)'
                : 'rgba(217, 119, 6, 0.95)';
        ctx.fillRect(x, uy, w, uh);
        return;
    }
    if (mark.item) {
        highlightItem(ctx, viewport, mark.item, color, mark.k);
    }
}

function markFromToken(k, token) {
    if (!token) return null;
    if (token.box) return { k, box: token.box };
    if (token.item) return { k, item: token.item };
    return null;
}

function tokenPaintable(token) {
    return Boolean(token?.item || (token?.box && token.synthetic !== true));
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

async function applyOcrToPages(pages, pageNumbers, sideMeta, setStatus, warnings = [], doc = null) {
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

        let row = results.find((r) => Number(r.page) === pageNo) || results[0];
        let tokens = tokensFromOcrPage(row).slice(0, TOKEN_CAP);

        // Storage OCR returned nothing usable — OCR the rendered page image once.
        if (!tokens.some(tokenPaintable) && !(row?.text) && doc) {
            try {
                if (setStatus) {
                    setStatus(
                        `OCR ${sideMeta.label}: re-reading page ${pageNo} from image…`,
                        'loading'
                    );
                }
                const blob = await renderPageJpegBlob(doc, pageNo);
                if (blob) {
                    const imageFile = new File([blob], `drr-page-${pageNo}.jpg`, { type: 'image/jpeg' });
                    const imgResults = await ocrPageBatch({
                        file: imageFile,
                        storagePath: '',
                        pages: [1],
                    });
                    const imgRow = imgResults[0] || null;
                    if (imgRow) {
                        row = { ...imgRow, page: pageNo };
                        tokens = tokensFromOcrPage(row).slice(0, TOKEN_CAP);
                    }
                }
            } catch (err) {
                warnings.push(friendlyError(err, `Image OCR failed for ${sideMeta.label} page ${pageNo}.`));
            }
        }

        if (!tokens.length && row?.text) {
            tokens = inferBoxesFromText(row.text).slice(0, TOKEN_CAP);
        }
        tokens = prepareTokensForDiff(tokens).slice(0, TOKEN_CAP);
        const idx = pageNo - 1;
        if (idx >= 0 && idx < pages.length) {
            pages[idx] = {
                page: pageNo,
                tokens,
                usedOcr: true,
                hasText: tokens.length > 0,
                rawText: String(row?.text || ''),
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

/** Render a PDF page to JPEG for OCR when storage-path OCR has no word boxes. */
async function renderPageJpegBlob(doc, pageNum, maxW = 1600) {
    if (!doc || !pageNum) return null;
    const page = await doc.getPage(pageNum);
    const base = page.getViewport({ scale: 1 });
    const scale = Math.min(2.2, maxW / base.width);
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.floor(viewport.width));
    canvas.height = Math.max(1, Math.floor(viewport.height));
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    await page.render({ canvasContext: ctx, viewport }).promise;
    return new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.92);
    });
}

/**
 * Harmonize only within an allowed page set (changed pages). Never expands OCR
 * to the whole document — that caused endless re-scanning.
 */
async function harmonizeCompareTokens(
    leftPages,
    rightPages,
    leftMeta,
    rightMeta,
    setStatus,
    leftDoc = null,
    rightDoc = null,
    allowedPages = null
) {
    const warnings = [];
    const leftOcr = new Set();
    const rightOcr = new Set();
    const allow = allowedPages instanceof Set ? allowedPages : null;
    const shared = Math.min(leftPages.length, rightPages.length);

    const allowed = (pageNo) => !allow || allow.has(pageNo);

    for (let i = 0; i < shared; i++) {
        if (leftPages[i]?.unchanged && rightPages[i]?.unchanged) continue;
        if (!allowed(leftPages[i]?.page) && !allowed(rightPages[i]?.page)) continue;

        if (allowed(leftPages[i]?.page) && pageNeedsOcrForCompare(leftPages[i], rightPages[i])) {
            // Skip if this page already has OCR word boxes.
            if (!leftPages[i]?.usedOcr || !pageHasPaintableTokens(leftPages[i])) {
            leftOcr.add(leftPages[i].page);
        }
        }
        if (allowed(rightPages[i]?.page) && pageNeedsOcrForCompare(rightPages[i], leftPages[i])) {
            if (!rightPages[i]?.usedOcr || !pageHasPaintableTokens(rightPages[i])) {
            rightOcr.add(rightPages[i].page);
            }
        }
    }
    for (let i = shared; i < leftPages.length; i++) {
        if (leftPages[i]?.unchanged || !allowed(leftPages[i]?.page)) continue;
        if (!pageHasPaintableTokens(leftPages[i]) && !leftPages[i]?.usedOcr) {
            leftOcr.add(leftPages[i].page);
        }
    }
    for (let i = shared; i < rightPages.length; i++) {
        if (rightPages[i]?.unchanged || !allowed(rightPages[i]?.page)) continue;
        if (!pageHasPaintableTokens(rightPages[i]) && !rightPages[i]?.usedOcr) {
            rightOcr.add(rightPages[i].page);
        }
    }

    if (leftOcr.size || rightOcr.size) {
        await Promise.all([
            leftOcr.size
                ? applyOcrToPages(leftPages, [...leftOcr], leftMeta, setStatus, warnings, leftDoc)
                : Promise.resolve(),
            rightOcr.size
                ? applyOcrToPages(rightPages, [...rightOcr], rightMeta, setStatus, warnings, rightDoc)
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
    const unchangedPages = options.unchangedPages || new Set();
    /** Optional: only OCR these page numbers in this pass (progressive compare). */
    const ocrPageFilter = options.ocrPages instanceof Set ? options.ocrPages : null;
    const pages = [];
    const missing = [];
    const warnings = [];

    for (let p = 1; p <= doc.numPages; p++) {
        const unchanged = unchangedPages.has(p);

        if (unchanged) {
            pages.push({
                page: p,
                tokens: [],
                usedOcr: false,
                hasText: false,
                unchanged: true,
            });
            continue;
        }

        const items = tokensFromItems(await pageItems(doc, p)).slice(0, TOKEN_CAP);
        const richText = items.length >= RICH_EMBEDDED_TEXT;
        const inOcrWave = !ocrPageFilter || ocrPageFilter.has(p);
        pages.push({
            page: p,
            tokens: items,
            usedOcr: false,
            hasText: items.length > 0,
            unchanged: false,
        });

        // Always OCR pages in this wave. Scanned masterlists often have a thin/wrong
        // text layer that looked "identical" and skipped real content diffs.
        if (inOcrWave) {
            missing.push(p);
        } else if (!richText || !items.some(tokenPaintable)) {
            // Keep previous behavior when filtering waves.
            missing.push(p);
        }
    }

    await applyOcrToPages(pages, missing, sideMeta, setStatus, warnings, doc);

    pages.__ocrWarnings = warnings;
    return pages;
}

/**
 * Keep each page at its real PDF page number: old p.N ↔ new p.N side by side.
 * Extra pages at the end of the longer revision show a blank slot on the other side.
 */
function alignPagesByPosition(leftPages, rightPages, visualByPage = null) {
    const total = Math.max(leftPages.length, rightPages.length);
    const alignment = [];

    for (let i = 0; i < total; i++) {
        const left = leftPages[i] || null;
        const right = rightPages[i] || null;
        const pageKey = left?.page || right?.page || (i + 1);
        const visualMarks = visualByPage?.get?.(pageKey) || null;

        if (left && right) {
            alignment.push({
                type: 'match',
                leftPage: left.page,
                rightPage: right.page,
                left,
                right,
                pairedBy: 'position',
                visualMarks,
            });
        } else if (left) {
            alignment.push({
                type: 'removed',
                leftPage: left.page,
                rightPage: null,
                left,
                right: null,
                pairedBy: 'position',
                visualMarks: null,
            });
        } else {
            alignment.push({
                type: 'added',
                leftPage: null,
                rightPage: right.page,
                left: null,
                right,
                pairedBy: 'position',
                visualMarks: null,
            });
        }
    }

    return alignment;
}

function pageSignature(page) {
    const norms = (page?.tokens || [])
        .map((t) => t.norm)
        .filter((n) => n && n.length >= 3);
    return {
        set: new Set(norms),
        count: norms.length,
        head: norms.slice(0, 48).join(' '),
    };
}

function pageSigSimilarity(a, b) {
    if (!a?.count || !b?.count) return 0;
    let hit = 0;
    for (const w of a.set) {
        if (b.set.has(w)) hit++;
    }
    return hit / Math.max(a.set.size, b.set.size, 1);
}

/**
 * Content-based page pairing when page counts diverge (inserts/deletes).
 * Falls back cleanly when signatures are too weak.
 */
function alignPagesByContent(leftPages, rightPages, visualByPage = null) {
    const leftSigs = leftPages.map(pageSignature);
    const rightSigs = rightPages.map(pageSignature);
    const usedRight = new Set();
    const alignment = [];
    let matchCount = 0;

    const tryMatch = (li) => {
        let best = -1;
        let bestScore = 0;
        for (let ri = 0; ri < rightPages.length; ri++) {
            if (usedRight.has(ri)) continue;
            const score = pageSigSimilarity(leftSigs[li], rightSigs[ri]);
            // Prefer nearby page indexes when scores are close.
            const indexPenalty = Math.abs(li - ri) * 0.015;
            const adjusted = score - indexPenalty;
            if (adjusted > bestScore) {
                bestScore = adjusted;
                best = ri;
            }
        }
        if (best >= 0 && bestScore >= PAGE_CONTENT_MATCH) {
            return best;
        }
        return -1;
    };

    let li = 0;
    let ri = 0;
    while (li < leftPages.length || ri < rightPages.length) {
        if (li < leftPages.length && ri < rightPages.length && !usedRight.has(ri)) {
            const samePos = pageSigSimilarity(leftSigs[li], rightSigs[ri]);
            if (samePos >= PAGE_CONTENT_MATCH) {
                usedRight.add(ri);
                const pageKey = leftPages[li]?.page || rightPages[ri]?.page;
                alignment.push({
                    type: 'match',
                    leftPage: leftPages[li].page,
                    rightPage: rightPages[ri].page,
                    left: leftPages[li],
                    right: rightPages[ri],
                    pairedBy: 'content',
                    visualMarks: visualByPage?.get?.(pageKey) || null,
                });
                matchCount++;
                li++;
                ri++;
                continue;
            }
        }

        if (li < leftPages.length) {
            const matchedRi = tryMatch(li);
            if (matchedRi >= 0) {
                while (ri < matchedRi) {
                    if (!usedRight.has(ri)) {
                        alignment.push({
                            type: 'added',
                            leftPage: null,
                            rightPage: rightPages[ri].page,
                            left: null,
                            right: rightPages[ri],
                            pairedBy: 'content',
                            visualMarks: null,
                        });
                        usedRight.add(ri);
                    }
                    ri++;
                }
                usedRight.add(matchedRi);
                const pageKey = leftPages[li]?.page || rightPages[matchedRi]?.page;
                alignment.push({
                    type: 'match',
                    leftPage: leftPages[li].page,
                    rightPage: rightPages[matchedRi].page,
                    left: leftPages[li],
                    right: rightPages[matchedRi],
                    pairedBy: 'content',
                    visualMarks: visualByPage?.get?.(pageKey) || null,
                });
                matchCount++;
                li++;
                ri = matchedRi + 1;
                continue;
            }

            alignment.push({
                type: 'removed',
                leftPage: leftPages[li].page,
                rightPage: null,
                left: leftPages[li],
                right: null,
                pairedBy: 'content',
                visualMarks: null,
            });
            li++;
            continue;
        }

        if (ri < rightPages.length) {
            if (!usedRight.has(ri)) {
                alignment.push({
                    type: 'added',
                    leftPage: null,
                    rightPage: rightPages[ri].page,
                    left: null,
                    right: rightPages[ri],
                    pairedBy: 'content',
                    visualMarks: null,
                });
            }
            ri++;
        }
    }

    return { alignment, matchCount };
}

function alignPagesSmart(leftPages, rightPages, visualByPage = null) {
    // Revision compare: always pair by page number (old p.N ↔ new p.N).
    // Content alignment was collapsing real edits into trailing "added only"
    // pages, so "Show changed pages only" hid the actual changed pages.
    const similarity = documentSimilarity(leftPages, rightPages);
    const alignment = alignPagesByPosition(leftPages, rightPages, visualByPage);
    const matchCount = countAlignmentMatches(alignment);
    return { alignment, mode: 'position', similarity, matchCount };
}

/**
 * Document + sentence aware matching.
 * 1) Pair identical/near-identical lines anywhere in either PDF (handles page shifts).
 * 2) Same sentences → no highlights.
 * 3) Related sentences → word highlight only where words differ.
 * 4) Unmatched lines → document word surplus (true adds/removes).
 */
function collectDocumentLines(pages) {
    const lines = [];
    for (const page of pages || []) {
        if (page?.unchanged) continue;
        for (const line of clusterTokensIntoLines(page?.tokens || [])) {
            if (!line.tokens.length) continue;
            line.page = page.page || 0;
            lines.push(line);
        }
    }
    return lines;
}

function lineTextSimilarity(a, b) {
    if (!a || !b) return 0;
    if (a.significant && b.significant && a.significant === b.significant) return 1;
    if (a.text && b.text && a.text === b.text) return 1;
    return pageTextSimilarity(a.tokens || [], b.tokens || []);
}

/** Mark every token in a sentence (including short words) for continuous bands. */
function annotateTokensAsKind(tokens, kind) {
    for (const t of tokens || []) {
        if (t?.norm && !isUnreliableOcrToken(t)) {
            t.__diff = kind;
        }
    }
}

/**
 * Merge same-kind tokens into continuous highlight bands (sentence look, not
 * per-word speckles).
 */
function markFromTokenGroup(kind, tokens) {
    const list = (tokens || []).filter(Boolean);
    if (!list.length) return null;
    const withBox = list.filter((t) => t.box && Number.isFinite(t.box.x));
    if (withBox.length) {
        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;
        for (const t of withBox) {
            minX = Math.min(minX, t.box.x);
            minY = Math.min(minY, t.box.y);
            maxX = Math.max(maxX, t.box.x + (Number(t.box.w) || 0));
            maxY = Math.max(maxY, t.box.y + (Number(t.box.h) || 0));
        }
        return {
            k: kind,
            box: {
                x: minX,
                y: minY,
                w: Math.max(maxX - minX, 0.01),
                h: Math.max(maxY - minY, 0.008),
            },
            sentence: true,
        };
    }
    return markFromToken(kind, list[0]);
}

/**
 * Draftable-style continuous bands: one highlight per visual line of a change,
 * spanning first→last word so OCR gaps don't leave holes.
 */
function sentenceMarksFromTokens(tokens, kind) {
    const marked = (tokens || []).filter((t) => t && t.__diff === kind);
    if (!marked.length) return [];

    // Prefer painting from full visual lines that contain these marks so quiet
    // gaps between words on the same line are filled.
    const lineMap = new Map();
    const allLines = clusterTokensIntoLines(tokens || []);
    for (const line of allLines) {
        const lineMarked = (line.tokens || []).filter((t) => t.__diff === kind);
        if (!lineMarked.length) continue;
        // If majority of the line is this kind (post-unify), paint the whole line.
        const ratio = lineMarked.length / Math.max(line.tokens.length, 1);
        const paintToks = ratio >= 0.35 ? line.tokens : lineMarked;
        const key = `${tokenSortY(paintToks[0]).toFixed(4)}`;
        if (!lineMap.has(key)) lineMap.set(key, []);
        lineMap.get(key).push(...paintToks);
    }

    if (!lineMap.size) {
        // Fallback: geometric merge of marked tokens only.
        const sorted = marked.slice().sort((a, b) => {
            const dy = tokenSortY(a) - tokenSortY(b);
            if (Math.abs(dy) > LINE_Y_TOL) return dy;
            return tokenSortX(a) - tokenSortX(b);
        });
        const groups = [];
        let cur = [];
        for (const t of sorted) {
            if (!cur.length) {
                cur = [t];
                continue;
            }
            const prev = cur[cur.length - 1];
            const sameLine = Math.abs(tokenSortY(t) - tokenSortY(prev)) <= LINE_Y_TOL * 2;
            if (sameLine) cur.push(t);
            else {
                groups.push(cur);
                cur = [t];
            }
        }
        if (cur.length) groups.push(cur);
        return groups.map((g) => markFromTokenGroup(kind, g)).filter(Boolean);
    }

    return [...lineMap.values()]
        .map((g) => {
            const mark = markFromTokenGroup(kind, g);
            if (mark) mark.sentence = true;
            return mark;
        })
        .filter(Boolean);
}

function marksFromDocAnnotation(tokens, kind) {
    return sentenceMarksFromTokens(tokens, kind);
}

function wordDiffMarksFromDocAnnotation(leftTokens, rightTokens, slot = null) {
    if (slot?.left?.unchanged && slot?.right?.unchanged) {
        return emptyMarks();
    }
    const leftDel = marksFromDocAnnotation(leftTokens, 'del');
    const leftChg = marksFromDocAnnotation(leftTokens, 'chg');
    const rightIns = marksFromDocAnnotation(rightTokens, 'ins');
    const rightChg = marksFromDocAnnotation(rightTokens, 'chg');
    const leftMarks = leftDel.concat(leftChg);
    const rightMarks = rightIns.concat(rightChg);
    const del = leftDel.length;
    const ins = rightIns.length;
    const chg = Math.max(leftChg.length, rightChg.length);
    const result = { leftMarks, rightMarks, del, ins, chg };
    const denom = Math.max((leftTokens || []).length, (rightTokens || []).length, 1);
    if (denom >= 30 && (del + ins + chg) / denom >= DENSE_REWRITE_RATIO) {
        result.denseRewrite = true;
    }
    return ensureDiffVisible(result, leftTokens || [], rightTokens || []);
}

function annotateDocumentWordDiff(leftPages, rightPages) {
    for (const pages of [leftPages, rightPages]) {
        for (const page of pages || []) {
            for (const t of page?.tokens || []) {
                if (t && '__diff' in t) delete t.__diff;
            }
        }
    }

    const leftLines = collectDocumentLines(leftPages);
    const rightLines = collectDocumentLines(rightPages);
    const usedRight = new Set();
    const paired = [];

    const tryPair = (minScore) => {
        for (let li = 0; li < leftLines.length; li++) {
            if (paired.some((p) => p.left === leftLines[li])) continue;
            let best = -1;
            let bestScore = 0;
            for (let ri = 0; ri < rightLines.length; ri++) {
                if (usedRight.has(ri)) continue;
                const raw = lineTextSimilarity(leftLines[li], rightLines[ri]);
                // Prefer same/nearby pages so a new p.3 paragraph is not paired
                // with a loosely similar deleted line from p.20.
                const pageGap = Math.abs((leftLines[li].page || 0) - (rightLines[ri].page || 0));
                const score = raw - Math.min(pageGap, 8) * 0.025;
                if (score > bestScore) {
                    bestScore = score;
                    best = ri;
                }
            }
            if (best < 0 || bestScore < minScore) continue;

            // Short headings need a stronger match (Article I vs Article II).
            const shortHeading = (leftLines[li].tokens.length <= 6)
                || (rightLines[best].tokens.length <= 6);
            if (shortHeading && bestScore < Math.max(minScore, 0.88)) {
                continue;
            }

            usedRight.add(best);
            paired.push({ left: leftLines[li], right: rightLines[best], score: bestScore });
        }
    };

    // Exact/near-exact sentences first so short headings don't steal the wrong pair.
    tryPair(LINE_MATCH_SAME);
    tryPair(LINE_MATCH_RELATED);

    const leftPaired = new Set(paired.map((p) => p.left));
    const rightPaired = new Set(paired.map((p) => p.right));

    // Phase 2: identical sentences stay clean; near-matches = whole sentence yellow.
    for (const pair of paired) {
        if (pair.score >= LINE_MATCH_SAME) {
            continue; // exact/near-exact sentence — no highlights
        }
        annotateTokensAsKind(pair.left.tokens, 'chg');
        annotateTokensAsKind(pair.right.tokens, 'chg');
    }

    // Phase 3: unmatched lines are true inserts/deletes.
    // Do NOT frequency-match leftovers across the whole document — that cancels
    // new sentences that share common words with deleted sentences elsewhere.
    annotateTokensAsKind(
        leftLines.filter((l) => !leftPaired.has(l)).flatMap((l) => l.tokens),
        'del'
    );
    annotateTokensAsKind(
        rightLines.filter((l) => !rightPaired.has(l)).flatMap((l) => l.tokens),
        'ins'
    );
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

/** Low-confidence OCR is retained for matching but should not create a diff alone. */
function isUnreliableOcrToken(token) {
    if (!token?.ocr) return false;
    const confidence = ocrConfidence(token.conf);
    if (confidence === null) return false;
    const length = String(token.norm || '').length;
    return confidence < 30 || (confidence < 42 && length <= 3);
}

/** Tiny function words and unreliable OCR clutter highlights without helping reviewers. */
function isNoiseDiffToken(token) {
    const n = token?.norm || '';
    if (!n) return true;
    if (isUnreliableOcrToken(token)) return true;
    if (n.length <= 2) return true;
    if (NOISE_DIFF_WORDS.has(n)) return true;
    // Pure digits / bullets rarely help policy review.
    if (/^\d{1,4}$/.test(n)) return true;
    return false;
}

/** Pair leftover tokens as del/ins only. OCR-fuzzy equals stay unhighlighted. */
function pairUnmatchedTokens(leftUnmatched, rightSurplus) {
    const leftMarks = [];
    const rightMarks = [];
    const usedLeft = new Set();
    const usedRight = new Set();
    let del = 0;
    let ins = 0;

    // Second-chance fuzzy match (OCR variants) — same section soft-key only.
    for (let li = 0; li < leftUnmatched.length; li++) {
        const lt = leftUnmatched[li];
        const leftKey = tokenSectionMatchKey(lt);
        let bestIdx = -1;
        let bestScore = Infinity;
        for (let ri = 0; ri < rightSurplus.length; ri++) {
            if (usedRight.has(ri)) continue;
            const rt = rightSurplus[ri];
            if (tokenSectionMatchKey(rt) !== leftKey) continue;
            if (!tokensEqual(lt, rt)) continue;
            const score = tokenNearScore(lt, rt);
            const ranked = Number.isFinite(score) ? score : 0;
            if (ranked < bestScore) {
                bestScore = ranked;
                bestIdx = ri;
            }
        }
        if (bestIdx >= 0) {
            usedLeft.add(li);
            usedRight.add(bestIdx);
        }
    }

    for (let li = 0; li < leftUnmatched.length; li++) {
        if (usedLeft.has(li)) continue;
        const lt = leftUnmatched[li];
        if (isNoiseDiffToken(lt)) continue;
            const lm = markFromToken('del', lt);
            if (lm) leftMarks.push(lm);
            del++;
    }

    for (let ri = 0; ri < rightSurplus.length; ri++) {
        if (usedRight.has(ri)) continue;
        const rt = rightSurplus[ri];
        if (isNoiseDiffToken(rt)) continue;
        const rm = markFromToken('ins', rt);
        if (rm) rightMarks.push(rm);
        ins++;
    }

    return { leftMarks, rightMarks, del, ins, chg: 0 };
}

function tokenCenter(token) {
    const box = token?.box;
    if (!box || !Number.isFinite(box.x)) return null;
    return {
        x: box.x + (Number(box.w) || 0) / 2,
        y: box.y + (Number(box.h) || 0) / 2,
    };
}

function tokenNear(a, b, radius = SPATIAL_MATCH_RADIUS) {
    const ca = tokenCenter(a);
    const cb = tokenCenter(b);
    if (!ca || !cb) return false;
    const dx = ca.x - cb.x;
    const dy = ca.y - cb.y;
    return Math.hypot(dx, dy) <= radius;
}

function tokenNearScore(a, b) {
    const ca = tokenCenter(a);
    const cb = tokenCenter(b);
    if (!ca || !cb) return Infinity;
    return Math.hypot(ca.x - cb.x, ca.y - cb.y);
}

/**
 * Position-aware OCR diff for forms: same-cell value edits → yellow "changed".
 * Not used for narrative policy rewrites (those use line-aware LCS).
 */
function wordDiffMarksSpatial(leftTokens, rightTokens) {
    const left = prepareTokensForDiff(leftTokens);
    const right = prepareTokensForDiff(rightTokens);
    const leftMarks = [];
    const rightMarks = [];
    const usedRight = new Set();
    const leftUnmatched = [];
    let del = 0;
    let ins = 0;
    let chg = 0;

    for (const lt of left) {
        let bestExact = -1;
        let bestExactScore = Infinity;
        let bestNear = -1;
        let bestNearScore = Infinity;

        for (let ri = 0; ri < right.length; ri++) {
            if (usedRight.has(ri)) continue;
            const rt = right[ri];
            const score = tokenNearScore(lt, rt);
            if (score === Infinity) continue;

            if (lt.norm === rt.norm || tokensEqual(lt, rt)) {
                if (score < bestExactScore) {
                    bestExactScore = score;
                    bestExact = ri;
                }
            } else if (score <= SPATIAL_MATCH_RADIUS && score < bestNearScore) {
                bestNearScore = score;
                bestNear = ri;
            }
        }

        if (bestExact >= 0 && bestExactScore <= SPATIAL_MATCH_RADIUS * 1.8) {
            usedRight.add(bestExact);
            continue;
        }

        if (bestNear >= 0) {
            const rt = right[bestNear];
            usedRight.add(bestNear);
            const lm = markFromToken('chg', lt);
            const rm = markFromToken('chg', rt);
            if (lm) leftMarks.push(lm);
            if (rm) rightMarks.push(rm);
            chg++;
            continue;
        }

        leftUnmatched.push(lt);
    }

    const rightSurplus = [];
    for (let ri = 0; ri < right.length; ri++) {
        if (!usedRight.has(ri)) rightSurplus.push(right[ri]);
    }

    const freq = pairUnmatchedTokens(leftUnmatched, rightSurplus);
    return {
        leftMarks: leftMarks.concat(freq.leftMarks),
        rightMarks: rightMarks.concat(freq.rightMarks),
        del: del + freq.del,
        ins: ins + freq.ins,
        chg: chg + freq.chg,
    };
}

function tokenSortY(token) {
    if (token?.box && Number.isFinite(token.box.y)) return token.box.y;
    if (token?.item?.transform) return Number(token.item.transform[5]) || 0;
    return 0;
}

function tokenSortX(token) {
    if (token?.box && Number.isFinite(token.box.x)) return token.box.x;
    if (token?.item?.transform) return Number(token.item.transform[4]) || 0;
    return 0;
}

/** Cluster tokens into reading-order lines — keeps original token refs for annotation. */
function clusterTokensIntoLines(tokens, yTol = LINE_Y_TOL) {
    const sorted = (tokens || [])
        .filter((t) => t && t.norm)
        .slice()
        .sort((a, b) => {
            const dy = tokenSortY(a) - tokenSortY(b);
            if (Math.abs(dy) > yTol) return dy;
            return tokenSortX(a) - tokenSortX(b);
        });

    const lines = [];
    for (const t of sorted) {
        const y = tokenSortY(t);
        const last = lines[lines.length - 1];
        if (last && Math.abs(last.y - y) <= yTol) {
            last.tokens.push(t);
            last.y = (last.y * (last.tokens.length - 1) + y) / last.tokens.length;
        } else {
            lines.push({ y, tokens: [t], text: '' });
        }
    }
    for (const line of lines) {
        line.tokens.sort((a, b) => tokenSortX(a) - tokenSortX(b));
        line.text = line.tokens.map((t) => t.norm).join(' ');
        line.significant = line.tokens
            .filter((t) => !isNoiseDiffToken(t))
            .map((t) => t.norm)
            .join(' ');
    }
    return lines;
}

function lineRawText(line) {
    return (line?.tokens || []).map((t) => String(t.t || '').trim()).filter(Boolean).join(' ');
}

function lineIsAllCaps(line) {
    const letters = lineRawText(line).replace(/[^a-zA-Z]/g, '');
    return letters.length >= 8 && letters === letters.toUpperCase();
}

function lineIsBlockStarter(line) {
    const toks = line?.tokens || [];
    if (!toks.length) return false;
    const first = String(toks[0].t || '').trim();
    const raw = lineRawText(line);
    if (/^(whereas|now|therefore|resolved|resolving|article|section|excerpt|excerpts|certification|approving)$/i.test(first)) {
        return true;
    }
    if (/^bot$/i.test(first) && /resolution/i.test(raw)) return true;
    if (/^resolution$/i.test(first)) return true;
    if (/^to$/i.test(first) && /whom/i.test(raw)) return true;
    if (/^this$/i.test(first) && /certify/i.test(raw)) return true;
    if (/^issued$/i.test(first)) return true;
    if (/^approved\.?$/i.test(first) && toks.length <= 3) return true;
    return false;
}

/**
 * Draftable-style paragraph blocks: discourse starters + vertical gaps +
 * ALL-CAPS title runs stay together until prose resumes.
 */
function clusterTokensIntoBlocks(tokens) {
    const lines = clusterTokensIntoLines(tokens);
    if (!lines.length) return [];

    const blocks = [];
    let currentLines = [];
    let currentWasCaps = null;

    const finalize = () => {
        if (!currentLines.length) return;
        const sentTokens = currentLines.flatMap((l) => l.tokens);
        currentLines = [];
        currentWasCaps = null;
        if (!sentTokens.length) return;
        blocks.push({
            tokens: sentTokens,
            text: sentTokens.map((t) => t.norm).join(' '),
            significant: sentTokens
                .filter((t) => !isNoiseDiffToken(t))
                .map((t) => t.norm)
                .join(' '),
            y: tokenSortY(sentTokens[0]),
        });
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const caps = lineIsAllCaps(line);
        const starter = lineIsBlockStarter(line);
        const prev = currentLines[currentLines.length - 1];
        const gap = prev ? Math.abs(line.y - prev.y) : 0;

        if (currentLines.length) {
            if (starter) {
                finalize();
            } else if (gap > 0.038) {
                finalize();
            } else if (currentWasCaps === true && !caps) {
                // Leaving a title block into mixed-case body.
                const raw = lineRawText(line);
                if (/[a-z]/.test(raw) && raw.replace(/[^a-zA-Z]/g, '').length >= 12) {
                    finalize();
                }
            }
        }

        currentLines.push(line);
        if (currentWasCaps === null) currentWasCaps = caps;
        else currentWasCaps = currentWasCaps && caps;
    }
    finalize();
    return blocks.filter((b) => b.tokens.length);
}

/** Forms keep labels in place; narrative rewrites do not. */
function pageLooksLikeForm(leftTokens, rightTokens) {
    const all = [...leftTokens, ...rightTokens];
    if (all.length < 24) return false;
    // Scanned policy PDFs are almost all OCR — never use spatial form matching
    // (it paints yellow "changed" on nearby different words and looks noisy).
    const ocrRatio = all.filter((t) => t.ocr).length / all.length;
    if (ocrRatio > 0.45) return false;
    const withBox = all.filter((t) => t.box).length;
    if (withBox / all.length < 0.85) return false;
    const avgLen = all.reduce((s, t) => s + (t.norm?.length || 0), 0) / all.length;
    const sim = pageTextSimilarity(leftTokens, rightTokens);
    return avgLen <= 8 && sim >= 0.45;
}

function approxVisualMarks() {
    // Region/tile boxes are intentionally disabled — reviewers need word highlights only.
    return emptyMarks();
}

function wordDiffMarks(leftTokens, rightTokens, slot = null) {
    const left = prepareTokensForDiff(leftTokens);
    const right = prepareTokensForDiff(rightTokens);

    if (slot?.left?.unchanged && slot?.right?.unchanged) {
        return emptyMarks();
    }

    if (!left.length && !right.length) {
        return emptyMarks();
    }
    if (!left.length) {
        const rightMarks = right.map((t) => markFromToken('ins', t)).filter(Boolean);
        if (!rightMarks.length) {
            return { leftMarks: [], rightMarks: [pageCoverMark('ins')], del: 0, ins: 1, chg: 0 };
        }
        return { leftMarks: [], rightMarks, del: 0, ins: rightMarks.length, chg: 0 };
    }
    if (!right.length) {
        const leftMarks = left.map((t) => markFromToken('del', t)).filter(Boolean);
        if (!leftMarks.length) {
            return { leftMarks: [pageCoverMark('del')], rightMarks: [], del: 1, ins: 0, chg: 0 };
        }
        return { leftMarks, rightMarks: [], del: leftMarks.length, ins: 0, chg: 0 };
    }

    const similarity = pageTextSimilarity(left, right);
    if (similarity >= PAGE_SIMILARITY_SKIP) {
        return emptyMarks();
    }

    // Forms: spatial cell match. Narrative pages go through annotateSectionWordDiff.
    const result = pageLooksLikeForm(left, right)
        ? wordDiffMarksSpatial(left, right)
        : wordDiffMarksFrequency(left, right);

    const changed = result.del + result.ins + result.chg;
    const denom = Math.max(left.length, right.length, 1);
    if (denom >= 30 && changed / denom >= DENSE_REWRITE_RATIO) {
        result.denseRewrite = true;
    }

    return ensureDiffVisible(result, left, right);
}

/** Word LCS → marks painted on both old and new PDF canvases. */
function wordDiffMarksSequential(leftTokens, rightTokens) {
    const left = prepareTokensForDiff(leftTokens);
    const right = prepareTokensForDiff(rightTokens);
    const ops = lcsOps(left, right, tokensEqual);
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

function pageTextSimilarity(leftTokens, rightTokens) {
    const left = prepareTokensForDiff(leftTokens);
    const right = prepareTokensForDiff(rightTokens);
    if (!left.length && !right.length) return 1;
    if (!left.length || !right.length) return 0;

    const rightBuckets = new Map();
    for (const t of right) {
        if (!rightBuckets.has(t.norm)) rightBuckets.set(t.norm, []);
        rightBuckets.get(t.norm).push(t);
    }

    let matched = 0;
    for (const t of left) {
        let bucket = rightBuckets.get(t.norm);
        if (!bucket?.length) {
            bucket = findFuzzyBucket(t, rightBuckets);
        }
        if (bucket?.length) {
            bucket.pop();
            matched++;
        }
    }
    return matched / Math.max(left.length, right.length, 1);
}

async function pageVisualHash(doc, pageNum, targetW = 48) {
    const page = await doc.getPage(pageNum);
    const base = page.getViewport({ scale: 1 });
    const scale = targetW / base.width;
    const viewport = page.getViewport({ scale });
    const w = Math.max(12, Math.floor(viewport.width));
    const h = Math.max(12, Math.floor(viewport.height));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, w, h);
    await page.render({ canvasContext: ctx, viewport }).promise;
    const data = ctx.getImageData(0, 0, w, h).data;
    const bins = new Float32Array(w * h);
    for (let i = 0, p = 0; i < data.length; i += 4, p++) {
        bins[p] = (data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114) / 255;
    }
    return { bins, w, h };
}

function visualHashDiff(a, b) {
    if (!a?.bins || !b?.bins || a.bins.length !== b.bins.length) return 1;
    let diff = 0;
    for (let i = 0; i < a.bins.length; i++) {
        diff += Math.abs(a.bins[i] - b.bins[i]);
    }
    return diff / a.bins.length;
}

/** Resample page hashes to a shared grid so aspect-ratio differences still compare. */
function normalizeVisualHash(hash, tw = VISUAL_NORM_W, th = VISUAL_NORM_H) {
    if (!hash?.bins || !hash.w || !hash.h) return null;
    if (hash.w === tw && hash.h === th) return hash;
    const out = new Float32Array(tw * th);
    for (let y = 0; y < th; y++) {
        for (let x = 0; x < tw; x++) {
            const sx = Math.min(hash.w - 1, Math.floor((x * hash.w) / tw));
            const sy = Math.min(hash.h - 1, Math.floor((y * hash.h) / th));
            out[y * tw + x] = hash.bins[sy * hash.w + sx];
        }
    }
    return { bins: out, w: tw, h: th };
}

/** Region boxes where two page renders differ — last-resort approximate highlights. */
function visualRegionMarks(leftHash, rightHash) {
    const left = normalizeVisualHash(leftHash);
    const right = normalizeVisualHash(rightHash);
    if (!left?.bins || !right?.bins || left.w !== right.w || left.h !== right.h) {
        return null;
    }

    const { w, h } = left;
    const cols = Math.min(VISUAL_TILE_COLS, w);
    const rows = Math.min(VISUAL_TILE_ROWS, h);
    const tileW = Math.max(1, Math.floor(w / cols));
    const tileH = Math.max(1, Math.floor(h / rows));
    const hot = [];

    for (let ty = 0; ty < rows; ty++) {
        for (let tx = 0; tx < cols; tx++) {
            const x0 = tx * tileW;
            const y0 = ty * tileH;
            const x1 = tx === cols - 1 ? w : x0 + tileW;
            const y1 = ty === rows - 1 ? h : y0 + tileH;
            let sum = 0;
            let count = 0;
            for (let y = y0; y < y1; y++) {
                for (let x = x0; x < x1; x++) {
                    const i = y * w + x;
                    sum += Math.abs(left.bins[i] - right.bins[i]);
                    count++;
                }
            }
            const avg = count ? sum / count : 0;
            if (avg >= VISUAL_TILE_DIFF) {
                hot.push({
                    x: x0 / w,
                    y: y0 / h,
                    w: (x1 - x0) / w,
                    h: (y1 - y0) / h,
                });
            }
        }
    }

    if (!hot.length) return null;

    const merged = mergeRegionBoxes(hot);
    const leftMarks = merged.map((box) => ({ k: 'chg', box, approx: true }));
    const rightMarks = merged.map((box) => ({ k: 'chg', box: { ...box }, approx: true }));
    return {
        leftMarks,
        rightMarks,
        del: 0,
        ins: 0,
        chg: merged.length,
        approx: true,
    };
}

function mergeRegionBoxes(boxes) {
    if (!boxes.length) return [];
    const sorted = [...boxes].sort((a, b) => (a.y - b.y) || (a.x - b.x));
    const out = [];
    for (const box of sorted) {
        const last = out[out.length - 1];
        if (
            last
            && Math.abs(last.y - box.y) < 0.01
            && Math.abs((last.x + last.w) - box.x) < 0.02
            && Math.abs(last.h - box.h) < 0.02
        ) {
            const right = Math.max(last.x + last.w, box.x + box.w);
            last.w = right - last.x;
            continue;
        }
        if (
            last
            && Math.abs(last.x - box.x) < 0.01
            && Math.abs((last.y + last.h) - box.y) < 0.02
            && Math.abs(last.w - box.w) < 0.02
        ) {
            const bottom = Math.max(last.y + last.h, box.y + box.h);
            last.h = bottom - last.y;
            continue;
        }
        out.push({ ...box });
    }
    return out;
}

/**
 * Quick visual + text scan.
 * Only skip OCR when BOTH look identical AND rich embedded text agrees.
 * Sparse PDF text (forms, stamps, image-only lines) must always OCR — visual
 * hash at low res routinely missed small additions and falsely said "identical".
 */
async function findUnchangedPagePairs(leftDoc, rightDoc, onProgress) {
    const pairs = [];
    const changedPages = [];
    const visualByPage = new Map();
    const total = Math.min(leftDoc.numPages, rightDoc.numPages);
    const batch = 6;

    for (let start = 1; start <= total; start += batch) {
        const end = Math.min(start + batch - 1, total);
        if (onProgress) onProgress(end, total);

        const jobs = [];
        for (let p = start; p <= end; p++) {
            jobs.push(
                Promise.all([
                    pageVisualHash(leftDoc, p, 64),
                    pageVisualHash(rightDoc, p, 64),
                    pageItems(leftDoc, p),
                    pageItems(rightDoc, p),
                ]).then(([lh, rh, leftItems, rightItems]) => {
                    const leftNorm = normalizeVisualHash(lh);
                    const rightNorm = normalizeVisualHash(rh);
                    const visualDiff = visualHashDiff(leftNorm, rightNorm);

                    const lt = tokensFromItems(leftItems);
                    const rt = tokensFromItems(rightItems);
                    const eitherHasText = lt.length > 0 || rt.length > 0;
                    const sparse = lt.length < RICH_EMBEDDED_TEXT || rt.length < RICH_EMBEDDED_TEXT;
                    const textSim = eitherHasText ? pageTextSimilarity(lt, rt) : 1;
                    const textSame = eitherHasText && textSim >= PAGE_SIMILARITY_SKIP;
                    const textClearlyDiffers = eitherHasText && textSim < PAGE_SIMILARITY_SKIP;

                    if (textClearlyDiffers) {
                        changedPages.push(p);
                        return;
                    }

                    // Sparse / image-heavy pages: never skip — OCR must catch stamps & form edits.
                    if (sparse) {
                        changedPages.push(p);
                        return;
                    }

                    // Rich text on both sides + visual match + text agrees.
                    if (visualDiff <= VISUAL_UNCHANGED_THRESHOLD && textSame) {
                    pairs.push({ leftPage: p, rightPage: p });
                        return;
                    }

                    changedPages.push(p);
                })
            );
        }
        await Promise.all(jobs);
        await new Promise((r) => setTimeout(r, 0));
    }

    const maxPages = Math.max(leftDoc.numPages, rightDoc.numPages);
    for (let p = total + 1; p <= maxPages; p++) {
        changedPages.push(p);
    }

    return { pairs, changedPages, visualByPage };
}

/** Full-page wash when a page is only on one side and OCR has no word boxes yet. */
function pageCoverMark(k) {
    return { k, box: { x: 0.015, y: 0.015, w: 0.97, h: 0.97 }, pageCover: true };
}

function computeSlotDiff(slot) {
    if (slot.type === 'match') {
        const leftTok = slot.left?.tokens || [];
        const rightTok = slot.right?.tokens || [];
        if (slot?.left?.unchanged && slot?.right?.unchanged) {
            return emptyMarks();
        }
        if (pageTextSimilarity(leftTok, rightTok) >= PAGE_SIMILARITY_SKIP) {
            return emptyMarks();
        }
        // Forms keep cell-aware spatial diff; narrative uses section annotations.
        if (pageLooksLikeForm(leftTok, rightTok)) {
            return wordDiffMarks(leftTok, rightTok, slot);
        }
        return wordDiffMarksFromDocAnnotation(leftTok, rightTok, slot);
    }
    if (slot.type === 'removed') {
        // Whole previous-only page — paint every word red.
        let leftMarks = (slot.left?.tokens || [])
            .filter((t) => t?.norm && !isNoiseDiffToken(t))
            .map((t) => markFromToken('del', t))
            .filter(Boolean);
        if (!leftMarks.length) {
            leftMarks = [pageCoverMark('del')];
        }
        return {
            leftMarks,
            rightMarks: [],
            del: Math.max(leftMarks.length, 1),
            ins: 0,
            chg: 0,
            pageRemoved: true,
        };
    }
    // Whole current-only page — paint every word green.
    let rightMarks = (slot.right?.tokens || [])
        .filter((t) => t?.norm && !isNoiseDiffToken(t))
        .map((t) => markFromToken('ins', t))
        .filter(Boolean);
    if (!rightMarks.length) {
        rightMarks = [pageCoverMark('ins')];
    }
    return {
        leftMarks: [],
        rightMarks,
        del: 0,
        ins: Math.max(rightMarks.length, 1),
        chg: 0,
        pageAdded: true,
    };
}

function slotHasChanges(slot, stats) {
    if (slot.type === 'removed' || slot.type === 'added') return true;
    if (!stats) return false;
    return (stats.del + stats.ins + stats.chg) > 0;
}

function analyzeAlignmentChanges(alignment, leftPages = null, rightPages = null) {
    if (leftPages) assignSectionKeysToPages(leftPages);
    if (rightPages) assignSectionKeysToPages(rightPages);
    if (leftPages && rightPages) {
        annotateSectionWordDiff(leftPages, rightPages);
    }

    const changedRows = [];
    const rowStats = [];
    let edited = 0;
    let added = 0;
    let removed = 0;
    let approxPages = 0;
    let wordMarks = 0;

    for (let i = 0; i < alignment.length; i++) {
        const slot = alignment[i];
        const stats = computeSlotDiff(slot);
        rowStats[i] = stats;
        if (stats.approx) approxPages++;
        wordMarks += countPaintableMarks(stats);
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
            approxPages,
            wordMarks,
        },
    };
}

function scrollToChangeRow(leftStage, rightStage, rowIndex) {
    const sel = `[data-compare-row="${rowIndex}"]`;
    const leftEl = leftStage?.querySelector(sel);
    const rightEl = rightStage?.querySelector(sel);
    // Scroll each pane to its own row element so free-scroll panes still land
    // on the paired change (placeholders vs real pages can have different heights).
    if (leftEl && leftStage) {
        leftStage.scrollTop = Math.max(0, leftEl.offsetTop - leftStage.offsetTop - 8);
    }
    if (rightEl && rightStage) {
        rightStage.scrollTop = Math.max(0, rightEl.offsetTop - rightStage.offsetTop - 8);
    }
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
    // Never auto-hide unchanged pages — reviewers need to browse freely first.
    const defaultHide = false;
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
        const kind = slot?.type === 'added'
            ? ' (new)'
            : slot?.type === 'removed'
                ? ' (removed)'
                : ' (edited)';
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

    // Do not auto-jump to the first change (often a trailing "new" page).
    // Start at the top so reviewers see page-1 content diffs first.
    if (fullyRendered) {
        requestAnimationFrame(() => scrollToCompareStart(leftStage, rightStage));
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

/** Independent scrolling — each PDF pane scrolls on its own. */
function setupCompareScrollSync(leftStage, rightStage) {
    if (leftStage) delete leftStage.dataset.scrollSync;
    if (rightStage) delete rightStage.dataset.scrollSync;
}

async function renderPdfPage(doc, pageNumber, width, marks, frameClass) {
    const wrap = document.createElement('div');
    wrap.className = ('drr-page-wrap ' + (frameClass || '')).trim();
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

function emptyPagePlaceholder(label, frameClass = '') {
    const wrap = document.createElement('div');
    wrap.className = ('drr-page-wrap is-empty ' + (frameClass || '')).trim();
    wrap.innerHTML = `<div class="drr-page-empty">${escapeAttr(label)}</div>`;
    return wrap;
}

function captionForSlot(slot) {
    if (slot.type === 'match') {
        const left = slot.leftPage;
        const right = slot.rightPage;
        if (left && right && left !== right) {
            return `Prev p.${left} ↔ Current p.${right}`;
        }
        return `Page ${left || right}`;
    }
    if (slot.type === 'removed') {
        return `Removed · previous page ${slot.leftPage}`;
    }
    return `New page · current page ${slot.rightPage}`;
}

function summaryForSlot(slot, stats, side = 'both') {
    const bits = [];
    if (slot.type === 'removed') bits.push('Only in previous revision');
    if (slot.type === 'added') bits.push('Only in current revision (new)');
    if (stats?.denseRewrite) bits.push('page heavily rewritten');
    if (stats) {
        if (side === 'left' || side === 'both') {
            if (stats.pageRemoved) bits.push('page removed');
            else if (stats.del) bits.push(`${stats.del} words removed`);
            if (stats.chg) bits.push(`${stats.chg} words changed`);
        }
        if (side === 'right' || side === 'both') {
            if (stats.pageAdded) bits.push('page added');
            else if (stats.ins) bits.push(`${stats.ins} words added`);
            if (side === 'right' && stats.chg) bits.push(`${stats.chg} words changed`);
        }
    }
    if (slot.left?.usedOcr || slot.right?.usedOcr) bits.push('OCR used');
    return bits.join(' · ');
}

function frameClassForSlot(slot, side, stats = null) {
    if (stats?.denseRewrite) return 'is-rewrite';
    if (slot.type === 'added' && side === 'right') return 'is-added';
    if (slot.type === 'removed' && side === 'left') return 'is-removed';
    if (slot.type === 'added' && side === 'left') return 'is-added-gap';
    if (slot.type === 'removed' && side === 'right') return 'is-removed-gap';
    return '';
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
    const changedSet = new Set(cached.changedRows || []);
    const alignment = cached.alignment || [];

    for (let i = 0; i < rows; i++) {
        const leftBlob = leftList[i];
        const rightBlob = rightList[i];
        const slot = alignment[i] || {};
        const isChanged = changedSet.has(i);
        let frameExtra = '';
        if (slot.type === 'added') frameExtra = 'is-added';
        else if (slot.type === 'removed') frameExtra = 'is-removed';
        let leftWrap = null;
        let rightWrap = null;

        if (leftBlob) {
            leftWrap = document.createElement('div');
            leftWrap.className = ('drr-page-wrap ' + frameExtra).trim();
            leftWrap.classList.toggle('drr-page-changed', isChanged);
            leftWrap.classList.toggle('drr-page-unchanged', !isChanged);
            leftWrap.dataset.compareRow = String(i);
            appendPageCaption(leftWrap, pageCaptionForRow(cached, i));
            const img = document.createElement('img');
            img.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
            img.src = URL.createObjectURL(leftBlob);
            leftWrap.appendChild(img);
            leftStage.appendChild(leftWrap);
        } else {
            leftWrap = emptyPagePlaceholder('No page in previous revision', frameExtra);
            leftWrap.classList.toggle('drr-page-changed', isChanged);
            leftWrap.classList.toggle('drr-page-unchanged', !isChanged);
            leftWrap.dataset.compareRow = String(i);
            appendPageCaption(leftWrap, pageCaptionForRow(cached, i));
            leftStage.appendChild(leftWrap);
        }

        if (rightBlob) {
            rightWrap = document.createElement('div');
            rightWrap.className = ('drr-page-wrap ' + frameExtra).trim();
            rightWrap.classList.toggle('drr-page-changed', isChanged);
            rightWrap.classList.toggle('drr-page-unchanged', !isChanged);
            rightWrap.dataset.compareRow = String(i);
            appendPageCaption(rightWrap, pageCaptionForRow(cached, i));
            const img = document.createElement('img');
            img.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
            img.src = URL.createObjectURL(rightBlob);
            rightWrap.appendChild(img);
            rightStage.appendChild(rightWrap);
        } else {
            rightWrap = emptyPagePlaceholder('No page in current revision', frameExtra);
            rightWrap.classList.toggle('drr-page-changed', isChanged);
            rightWrap.classList.toggle('drr-page-unchanged', !isChanged);
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
        setupChangeNavigator(root, analysis, cached.alignment, leftStage, rightStage, true);
    }

    return true;
}


/**
 * PDF comparison:
 * - one OCR pass on changed pages only (no re-scan loop)
 * - per-page: added = green, removed = red, matched = word underlines
 * - dense rewrites use a light tint so PDF text stays readable
 * - IndexedDB cache + chunked render
 */
export async function runPdfCompare(root, options = {}) {
    if (!root) return;

    // Prevent Livewire/re-entry from restarting OCR mid-run (endless loop).
    if (root.__drrRunning) {
        return;
    }
    root.__drrRunning = true;
    root.__drrAbort = false;

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

    showStagePlaceholder(leftStage, 'Preparing comparison…');
    showStagePlaceholder(rightStage, 'Preparing comparison…');
    setStatus(root, 'Starting document comparison…', 'loading');
    // Reset filter so a prior "changed only" session does not stick.
    root.__drrShowChangedOnly = false;

    try {
        if (cacheKey) {
            try {
                const memHit = peekMemoryCompareCache(cacheKey);
                if (memHit) {
                    setStatus(root, 'Restoring comparison from this session…', 'loading');
                    const ok = await paintCached(root, memHit, cacheKey);
                    if (ok) {
                        setStatus(root, 'Comparison restored from local cache.', 'success');
                        root.dataset.cacheRestored = cacheKey;
                        return;
                    }
                }

                setStatus(root, 'Checking local compare cache…', 'loading');
                const cached = await getCompareCache(cacheKey);
                if (cached) {
                    const ok = await paintCached(root, cached, cacheKey);
                    if (ok) {
                        setStatus(root, 'Comparison restored from local cache.', 'success');
                        root.dataset.cacheRestored = cacheKey;
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
                ph.textContent = leftLoadError ? 'Could not load previous PDF.' : '—';
                leftStage.appendChild(ph);
            }
            if (rightStage) {
                rightStage.innerHTML = '';
                const ph = document.createElement('div');
                ph.className = 'drr-page-empty';
                ph.textContent = rightLoadError ? 'Could not load latest PDF.' : '—';
                rightStage.appendChild(ph);
            }
            setStatus(
                root,
                friendlyError(leftLoadError || rightLoadError, 'Could not load one or both PDF files for comparison.'),
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

        setStatus(root, 'Preparing page-by-page content compare…', 'loading');
        // Never treat overlapping pages as "unchanged" / skip OCR. That was why
        // only trailing "new" pages got highlights while Page 2 diffs stayed blank.
        const visualByPage = new Map();
        const skipLeft = new Set();
        const skipRight = new Set();
        const skipCount = 0;
        const maxPageNo = Math.max(leftDoc.numPages, rightDoc.numPages);
        const changedPages = Array.from({ length: maxPageNo }, (_, i) => i + 1);
        const ocrPageSet = new Set(changedPages);

        setStatus(
            root,
            `Reading words on all ${maxPageNo} page${maxPageNo === 1 ? '' : 's'} (full content compare)…`,
            'loading'
        );
        showStagePlaceholder(leftStage, 'Reading previous PDF…');
        showStagePlaceholder(rightStage, 'Reading latest PDF…');

        const statusFn = (t, kind = 'loading') => {
            if (!root.__drrRunning || root.__drrAbort) return;
            setStatus(root, t, kind);
        };

        const [leftPages, rightPages] = await Promise.all([
            ensurePageTexts(leftDoc, leftMeta, statusFn, {
                unchangedPages: skipLeft,
                ocrPages: ocrPageSet,
            }),
            ensurePageTexts(rightDoc, rightMeta, statusFn, {
                unchangedPages: skipRight,
                ocrPages: ocrPageSet,
            }),
        ]);
        if (root.__drrAbort) {
            setStatus(root, 'Comparison cancelled.', 'info');
            return;
        }

        setStatus(root, 'Aligning pages…', 'loading');
        await harmonizeCompareTokens(
            leftPages,
            rightPages,
            leftMeta,
            rightMeta,
            statusFn,
            leftDoc,
            rightDoc,
            ocrPageSet
        );
        if (root.__drrAbort) {
            setStatus(root, 'Comparison cancelled.', 'info');
            return;
        }

        const leftReadable = countPaintablePages(leftPages);
        const rightReadable = countPaintablePages(rightPages);
        if (!leftReadable && !rightReadable) {
            if (cacheKey) {
                root.dataset.compareFailed = cacheKey;
            }
            if (leftStage) {
                leftStage.innerHTML = '<div class="drr-page-empty">OCR could not read this PDF.</div>';
            }
            if (rightStage) {
                rightStage.innerHTML = '<div class="drr-page-empty">OCR could not read this PDF.</div>';
            }
            setStatus(
                root,
                'Could not read words from either PDF. Word highlights need OCR (PaddleOCR). Check the server OCR setup, then hard-refresh and compare again.',
                'error'
            );
            return;
        }
        delete root.dataset.compareFailed;

        setStatus(root, 'Rendering comparison…', 'loading');
        if (leftStage) {
            leftStage.innerHTML = '';
            delete leftStage.dataset.scrollSync;
        }
        if (rightStage) {
            rightStage.innerHTML = '';
            delete rightStage.dataset.scrollSync;
        }

        const { alignment, mode: alignMode, similarity: docSimilarity } = alignPagesSmart(
            leftPages,
            rightPages,
            visualByPage
        );
        setStatus(root, 'Comparing page-by-page and highlighting differences…', 'loading');
        const changeAnalysis = analyzeAlignmentChanges(alignment, leftPages, rightPages);
        const width = Math.max(leftStage?.clientWidth || rightStage?.clientWidth || 480, 280);

        const leftBlobs = [];
        const rightBlobs = [];
        let ocrUsed = false;
        const removedCount = changeAnalysis.stats.removed;
        const addedCount = changeAnalysis.stats.added;
        let renderErrors = 0;
        const highlightRows = changeAnalysis.stats.changed;
        const changedRowSet = new Set(changeAnalysis.changedRows);
        const matchCount = countAlignmentMatches(alignment);
        const total = alignment.length;
        let offset = 0;

        if (!total) {
            setStatus(root, 'No pages found to compare in either PDF.', 'error');
            return;
        }

        async function renderChunk(limit = CHUNK_SIZE) {
            const end = Math.min(offset + limit, total);
            setStatus(root, `Rendering pages ${offset + 1}–${end} of ${total}…`, 'loading');

            for (let i = offset; i < end; i++) {
                if (root.__drrAbort) return;
                const slot = alignment[i];
                const stats = changeAnalysis.rowStats[i];
                const leftMarks = stats?.leftMarks || [];
                const rightMarks = stats?.rightMarks || [];
                const isChanged = changedRowSet.has(i);

                if (slot.left?.usedOcr || slot.right?.usedOcr) ocrUsed = true;

                let leftWrap = null;
                let rightWrap = null;
                try {
                    if (slot.leftPage) {
                        leftWrap = await renderPdfPage(
                            leftDoc,
                            slot.leftPage,
                            width,
                            leftMarks,
                            frameClassForSlot(slot, 'left', stats)
                        );
                    }
                    if (slot.rightPage) {
                        rightWrap = await renderPdfPage(
                            rightDoc,
                            slot.rightPage,
                            width,
                            rightMarks,
                            frameClassForSlot(slot, 'right', stats)
                        );
                    }
                } catch (err) {
                    renderErrors++;
                    console.warn('DRR page render failed', err);
                    if (slot.leftPage && !leftWrap) {
                        leftWrap = document.createElement('div');
                        leftWrap.className = ('drr-page-wrap ' + frameClassForSlot(slot, 'left', stats)).trim();
                        leftWrap.innerHTML = '<div class="drr-page-empty">Could not render this page.</div>';
                    }
                    if (slot.rightPage && !rightWrap) {
                        rightWrap = document.createElement('div');
                        rightWrap.className = ('drr-page-wrap ' + frameClassForSlot(slot, 'right', stats)).trim();
                        rightWrap.innerHTML = '<div class="drr-page-empty">Could not render this page.</div>';
                    }
                }

                const cap = document.createElement('div');
                cap.className = 'drr-page-caption';
                if (slot.type === 'added') cap.classList.add('is-added');
                if (slot.type === 'removed') cap.classList.add('is-removed');
                if (stats?.denseRewrite) cap.classList.add('is-rewrite');
                cap.textContent = captionForSlot(slot) + (stats?.denseRewrite ? ' · heavily rewritten' : '');

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
                        leftWrap.dataset.pageKind = slot.type;
                        leftStage.appendChild(leftWrap);
                        leftRowWrap = leftWrap;
                        const canvas = leftWrap.querySelector('canvas');
                        leftBlobs.push(canvas ? await canvasToBlob(canvas) : null);
                    } else {
                        const ph = emptyPagePlaceholder(
                            slot.type === 'added' ? 'No matching previous page (new on the right)' : 'No page in previous revision',
                            frameClassForSlot(slot, 'left', stats)
                        );
                        ph.prepend(cap.cloneNode(true));
                        ph.dataset.compareRow = String(i);
                        ph.dataset.pageKind = slot.type;
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
                        rightWrap.dataset.pageKind = slot.type;
                        rightStage.appendChild(rightWrap);
                        rightRowWrap = rightWrap;
                        const canvas = rightWrap.querySelector('canvas');
                        rightBlobs.push(canvas ? await canvasToBlob(canvas) : null);
                    } else {
                        const ph = emptyPagePlaceholder(
                            slot.type === 'removed' ? 'No matching current page (removed on the left)' : 'No page in current revision',
                            frameClassForSlot(slot, 'right', stats)
                        );
                        ph.prepend(cap.cloneNode(true));
                        ph.dataset.compareRow = String(i);
                        ph.dataset.pageKind = slot.type;
                        rightStage.appendChild(ph);
                        rightRowWrap = ph;
                        rightBlobs.push(null);
                    }
                }

                balanceCompareRow(leftRowWrap, rightRowWrap);
            }

            setupCompareScrollSync(leftStage, rightStage);
            if (offset === 0) scrollToCompareStart(leftStage, rightStage);
            offset = end;
                setupChangeNavigator(root, changeAnalysis, alignment, leftStage, rightStage, offset >= total);
                root.__drrNavReady = true;
            }

        while (offset < total) {
            if (root.__drrAbort) return;
            await renderChunk(CHUNK_SIZE);
        }

            const ocrWarnings = [
                ...(leftPages.__ocrWarnings || []),
                ...(rightPages.__ocrWarnings || []),
            ];

            const leftMsg = [
                `${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} with changes`,
            skipCount ? `${skipCount} identical (skipped)` : '',
                removedCount ? `${removedCount} previous-only` : '',
            ocrUsed ? 'OCR used' : '',
            ].filter(Boolean).join(' · ');
            const rightMsg = [
                `${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} with changes`,
            skipCount ? `${skipCount} identical (skipped)` : '',
                addedCount ? `${addedCount} current-only` : '',
            ocrUsed ? 'OCR used' : '',
            ].filter(Boolean).join(' · ');
            if (leftNote) leftNote.textContent = leftMsg;
            if (rightNote) rightNote.textContent = rightMsg;

                scrollToCompareStart(leftStage, rightStage);
        root.dataset.cacheRestored = cacheKey;

                if (highlightRows === 0) {
            // Never call pages "identical" unless every page was verified unchanged.
            const trulyIdentical = skipCount === total && changedPages.length === 0;
                    setStatus(
                        root,
                trulyIdentical
                    ? 'Comparison complete — these revisions look identical.'
                    : 'Comparison finished but no word highlights could be painted. Hard-refresh and try again if text should differ.',
                trulyIdentical ? 'success' : 'info'
                    );
                    return;
                }

        if (cacheKey && matchCount > 0) {
            try {
                await putCompareCache({
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
                    hasHighlights: (changeAnalysis.stats.wordMarks || 0) > 0,
                    wordMarks: changeAnalysis.stats.wordMarks || 0,
                        changedRows: changeAnalysis.changedRows,
                        changeStats: changeAnalysis.stats,
                    });
                } catch (err) {
                    console.warn('DRR cache save error', err);
                }
            }

            const warnBits = [];
            if (ocrWarnings.length) warnBits.push(ocrWarnings[0]);
            if (renderErrors) warnBits.push(`${renderErrors} page(s) failed to render.`);

                setStatus(
                    root,
            warnBits.length
                ? `Comparison complete with warnings: ${warnBits.join(' ')}`
                : `Comparison complete · ${changeAnalysis.stats.changed} page${changeAnalysis.stats.changed === 1 ? '' : 's'} differ`
                    + (skipCount ? ` · ${skipCount} identical skipped` : '')
                    + (addedCount ? ` · ${addedCount} new` : '')
                    + (removedCount ? ` · ${removedCount} removed` : '')
                    + '.',
            warnBits.length ? 'info' : 'success'
        );
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
    } finally {
        root.__drrRunning = false;
    }
}

export async function buildCompareCacheKey(leftId, rightId) {
    // Include CACHE_VERSION so algorithm changes never restore old paint blobs.
    return `drr-v${CACHE_VERSION}:` + await hashString(String(leftId) + '||' + String(rightId));
}

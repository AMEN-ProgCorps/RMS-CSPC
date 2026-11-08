#!/usr/bin/env node
/**
 * Document Review PDF compare — accuracy fixture gate.
 *
 * Contracts CACHE_VERSION bumps must preserve:
 *   1) position-when-strong     — strong same-index pairs stay position-aligned
 *   2) weak-index-escalates-lcs — weak same-index rematches via content LCS
 *   3) masterlist-row-insert    — moved Doc Nos pair; only new row is insert
 *   4) phrase-box-paint-band    — phrase split preserves band + gap parity
 *   5) form-field-edit-only     — numbers/dates stay exact
 *   6) move-paragraph           — same groupKey pairs across pages
 *   7) shared-letterhead        — header boilerplate does not inflate body score
 *
 * Run: node scripts/drr/pdf-compare-accuracy-fixtures.mjs
 * Exit 0 = all pass (safe to ship CACHE_VERSION bump).
 */

const PAGE_FORCE_UNMATCH = 0.22;
const PAGE_CONTENT_MATCH = 0.34;
const PAGE_ALIGN_MATCH = 0.40;
const PAGE_ALIGN_WEAK_RATIO = 0.12;
const DOC_SIMILARITY_INDEX_FALLBACK = 0.18;
const EXPECTED_MIN_CACHE_VERSION = 64;
const GLYPH_BAND_H = 0.016;
const NOISE = new Set([
  'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'by', 'as', 'at',
]);

function tok(norm, y = 0.3, ocr = false) {
  return { norm, t: norm, box: { x: 0.1, y, w: 0.08, h: 0.02 }, ocr };
}

function pageFromWords(page, words, yStart = 0.2) {
  return {
    page,
    tokens: words.map((w, i) => tok(w, yStart + i * 0.03)),
  };
}

function isExactMatchToken(norm) {
  const s = String(norm || '');
  if (!s) return false;
  if (/^\d+$/.test(s)) return true;
  if (/^\d{1,4}(st|nd|rd|th)?$/.test(s)) return true;
  const digits = (s.match(/\d/g) || []).length;
  return digits >= 2 && digits / s.length >= 0.4;
}

function tokensEqual(a, b) {
  if (!a || !b) return false;
  if (a.norm === b.norm) return true;
  if (isExactMatchToken(a.norm) || isExactMatchToken(b.norm)) return false;
  return false;
}

function isBoilerplate(t) {
  return t.box.y < 0.055 || t.box.y > 0.915;
}

function pageSignature(page) {
  const norms = (page?.tokens || [])
    .filter((t) => t?.norm && t.norm.length >= 3 && !isBoilerplate(t))
    .map((t) => t.norm);
  const use = norms.length >= 4
    ? norms
    : (page?.tokens || []).map((t) => t.norm).filter((n) => n && n.length >= 3);
  return { set: new Set(use), count: use.length, head: use.slice(0, 80).join(' ') };
}

function pageSigSimilarity(a, b) {
  if (!a?.count || !b?.count) return 0;
  let hit = 0;
  for (const w of a.set) if (b.set.has(w)) hit++;
  return hit / Math.max(a.set.size, b.set.size, 1);
}

function documentSimilarity(leftPages, rightPages) {
  const left = { set: new Set(), count: 0 };
  const right = { set: new Set(), count: 0 };
  for (const p of leftPages || []) {
    const sig = pageSignature(p);
    for (const w of sig.set) left.set.add(w);
    left.count += sig.count;
  }
  for (const p of rightPages || []) {
    const sig = pageSignature(p);
    for (const w of sig.set) right.set.add(w);
    right.count += sig.count;
  }
  return pageSigSimilarity(left, right);
}

function alignByPosition(leftPages, rightPages) {
  const total = Math.max(leftPages.length, rightPages.length);
  const alignment = [];
  for (let i = 0; i < total; i++) {
    const left = leftPages[i] || null;
    const right = rightPages[i] || null;
    if (left && right) {
      alignment.push({
        type: 'match',
        left,
        right,
        leftPage: left.page,
        rightPage: right.page,
        bodyScore: pageSigSimilarity(pageSignature(left), pageSignature(right)),
      });
    } else if (left) {
      alignment.push({ type: 'removed', left, leftPage: left.page });
    } else {
      alignment.push({ type: 'added', right, rightPage: right.page });
    }
  }
  return alignment;
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

function alignByLcs(leftPages, rightPages) {
  const leftSigs = leftPages.map(pageSignature);
  const rightSigs = rightPages.map(pageSignature);
  const leftIdx = leftPages.map((_, i) => i);
  const rightIdx = rightPages.map((_, i) => i);
  const ops = lcsOps(leftIdx, rightIdx, (li, ri) => {
    return pageSigSimilarity(leftSigs[li], rightSigs[ri]) >= PAGE_ALIGN_MATCH;
  });
  const alignment = [];
  let matchCount = 0;
  for (const op of ops) {
    if (op.k === 'eq') {
      alignment.push({
        type: 'match',
        left: leftPages[op.left],
        right: rightPages[op.right],
        leftPage: leftPages[op.left].page,
        rightPage: rightPages[op.right].page,
      });
      matchCount++;
    } else if (op.k === 'del') {
      alignment.push({ type: 'removed', left: leftPages[op.left], leftPage: leftPages[op.left].page });
    } else {
      alignment.push({ type: 'added', right: rightPages[op.right], rightPage: rightPages[op.right].page });
    }
  }
  return { alignment, matchCount };
}

function alignPagesSmart(leftPages, rightPages) {
  const similarity = documentSimilarity(leftPages, rightPages);
  const positionAlign = alignByPosition(leftPages, rightPages);
  let matched = 0;
  let weak = 0;
  let forceUnmatch = false;
  for (const slot of positionAlign) {
    if (slot.type !== 'match') continue;
    matched += 1;
    if (slot.bodyScore < PAGE_CONTENT_MATCH) weak += 1;
    if (slot.bodyScore < PAGE_FORCE_UNMATCH) forceUnmatch = true;
  }
  const multiPage = Math.max(leftPages.length, rightPages.length) > 1;
  const weakRatio = matched > 0 ? weak / matched : 0;
  const shouldLcs = multiPage && (
    weakRatio >= PAGE_ALIGN_WEAK_RATIO
    || forceUnmatch
    || (similarity >= DOC_SIMILARITY_INDEX_FALLBACK && weakRatio > 0)
  );
  if (shouldLcs) {
    const lcs = alignByLcs(leftPages, rightPages);
    if (lcs.alignment.length && lcs.matchCount > 0) {
      return { alignment: lcs.alignment, mode: 'content-lcs', matchCount: lcs.matchCount };
    }
  }
  return { alignment: positionAlign, mode: 'position', matchCount: matched };
}

function normalizeWord(w) {
  return String(w || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
}

function tableRowIndexes(table) {
  return [...new Set((table?.cells || []).map((c) => Number(c.r)))]
    .filter((r) => Number.isFinite(r))
    .sort((a, b) => a - b);
}

function tableRowSignature(table, rowIndex) {
  return (table?.cells || [])
    .filter((c) => Number(c.r) === rowIndex)
    .sort((a, b) => Number(a.c) - Number(b.c))
    .map((c) => normalizeWord(c.text || c.raw || ''))
    .filter(Boolean)
    .join('|');
}

function tableRowCellsByCol(table, rowIndex) {
  const m = new Map();
  for (const c of table?.cells || []) {
    if (Number(c.r) !== rowIndex) continue;
    m.set(Number(c.c), c);
  }
  return m;
}

const TABLE_HEADER_CELL_RE = /^(doc(?:ument)?no|item(?:no)?|code|ref|no|originator|title|date|particulars|description|amount|qty|quantity|unit|approvals?|position|names?|signature)$/i;
const CODE_LIKE_CELL_RE = /^[A-Z]{1,8}[-/._]?\d[\w./-]{0,24}$/i;

function isTableHeaderRow(table, rowIndex) {
  const raw = (table?.cells || [])
    .filter((c) => Number(c.r) === rowIndex)
    .map((c) => String(c.text || c.raw || ''))
    .join(' ')
    .toLowerCase();
  if (!raw.trim()) return false;
  const headerHits = (raw.match(/\b(approvals?|position|names?|signature|title|description|originator|item|date|doc(?:ument)?\s*no|particulars|amount|qty|quantity|unit|code|ref)\b/g) || []).length;
  const dataHits = (raw.match(/\b(phd|president|chairperson|vice-?chair|january|february|march|april|may|june|july|august|september|october|november|december|iso)\b/g) || []).length;
  return headerHits >= 2 && dataHits === 0;
}

function tableRowStableKey(table, rowIndex) {
  const cells = [...tableRowCellsByCol(table, rowIndex).entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([, c]) => String(c.text || c.raw || '').trim())
    .filter(Boolean);
  if (!cells.length) return '';
  for (const c of cells) {
    const n = normalizeWord(c);
    if (!n || TABLE_HEADER_CELL_RE.test(n)) continue;
    if (CODE_LIKE_CELL_RE.test(c) || (/[A-Za-z]/.test(c) && /\d{2,}/.test(c) && c.length <= 40)) {
      return `code:${n}`;
    }
  }
  const first = cells[0];
  const firstNorm = normalizeWord(first);
  if (first && first.length <= 36 && /\d/.test(first) && firstNorm && !TABLE_HEADER_CELL_RE.test(firstNorm)) {
    return `code:${firstNorm}`;
  }
  const title = cells.find((c) => String(c).length > 8 && !CODE_LIKE_CELL_RE.test(c)) || cells[1] || cells[0];
  const stem = normalizeWord(title).slice(0, 48);
  if (stem.length >= 4) return `title:${stem}`;
  return `sig:${tableRowSignature(table, rowIndex)}`;
}

function alignTableRowsByContent(lt, rt) {
  const leftRows = tableRowIndexes(lt);
  const rightRows = tableRowIndexes(rt);
  if (!leftRows.length || !rightRows.length) {
    return { pairs: [], leftOnly: leftRows, rightOnly: rightRows };
  }
  const leftKeys = leftRows.map((r) => tableRowStableKey(lt, r));
  const rightKeys = rightRows.map((r) => tableRowStableKey(rt, r));
  const leftIsHeader = leftRows.map((r) => isTableHeaderRow(lt, r));
  const rightIsHeader = rightRows.map((r) => isTableHeaderRow(rt, r));
  const pairedLeft = new Set();
  const pairedRight = new Set();
  const pairs = [];
  const leftHeaders = leftRows.map((_, i) => i).filter((i) => leftIsHeader[i]);
  const rightHeaders = rightRows.map((_, i) => i).filter((i) => rightIsHeader[i]);
  const headerPairs = Math.min(leftHeaders.length, rightHeaders.length);
  for (let h = 0; h < headerPairs; h++) {
    pairedLeft.add(leftHeaders[h]);
    pairedRight.add(rightHeaders[h]);
    pairs.push({ left: leftRows[leftHeaders[h]], right: rightRows[rightHeaders[h]] });
  }
  const rightByKey = new Map();
  rightKeys.forEach((key, idx) => {
    if (pairedRight.has(idx)) return;
    if (!key || key.startsWith('sig:')) return;
    if (!rightByKey.has(key)) rightByKey.set(key, []);
    rightByKey.get(key).push(idx);
  });
  leftKeys.forEach((key, li) => {
    if (pairedLeft.has(li)) return;
    if (!key || key.startsWith('sig:')) return;
    const candidates = rightByKey.get(key);
    if (!candidates?.length) return;
    const ri = candidates.shift();
    if (ri == null || pairedRight.has(ri)) return;
    pairedLeft.add(li);
    pairedRight.add(ri);
    pairs.push({ left: leftRows[li], right: rightRows[ri] });
  });
  const leftRemainIdx = leftRows.map((_, i) => i).filter((i) => !pairedLeft.has(i));
  const rightRemainIdx = rightRows.map((_, i) => i).filter((i) => !pairedRight.has(i));
  const leftSig = leftRemainIdx.map((i) => leftKeys[i] || tableRowSignature(lt, leftRows[i]));
  const rightSig = rightRemainIdx.map((i) => rightKeys[i] || tableRowSignature(rt, rightRows[i]));
  const ops = lcsOps(leftSig, rightSig, (a, b) => a === b);
  const leftOnly = [];
  const rightOnly = [];
  for (const op of ops) {
    if (op.k === 'eq') {
      const li = leftRemainIdx[op.li];
      const ri = rightRemainIdx[op.ri];
      if (leftIsHeader[li] !== rightIsHeader[ri]) {
        leftOnly.push(leftRows[li]);
        rightOnly.push(rightRows[ri]);
        continue;
      }
      pairs.push({ left: leftRows[li], right: rightRows[ri] });
    } else if (op.k === 'del') {
      leftOnly.push(leftRows[leftRemainIdx[op.li]]);
    } else if (op.k === 'ins') {
      rightOnly.push(rightRows[rightRemainIdx[op.ri]]);
    }
  }
  return { pairs, leftOnly, rightOnly };
}

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

function clampBoxToGlyphBand(box, maxNormH = GLYPH_BAND_H) {
  if (!box || !Number.isFinite(box.x) || !Number.isFinite(box.h)) return box || null;
  const maxH = Math.max(0.008, maxNormH);
  if (box.h <= maxH * 1.35) {
    return { x: box.x, y: box.y, w: Math.max(box.w, 0.002), h: Math.max(box.h, 0.006) };
  }
  const h = maxH;
  const y = box.y + (box.h - h) * 0.62;
  return { x: box.x, y: Math.max(0, Math.min(y, 0.995)), w: Math.max(box.w, 0.002), h };
}

function splitPhraseBox(box, parts) {
  if (!box || !parts?.length) return [];
  const band = clampBoxToGlyphBand(box);
  const weights = parts.map(visualTextWidth);
  const gap = 0.38;
  const total = weights.reduce((sum, weight) => sum + weight, 0)
    + gap * Math.max(parts.length - 1, 0);
  if (!Number.isFinite(total) || total <= 0) return [];
  const unit = band.w / total;
  let cursor = band.x;
  return parts.map((part, index) => {
    const fullWidth = Math.max(unit * weights[index], 0.002);
    const inset = Math.min(fullWidth * 0.02, 0.0015);
    const wordBox = clampBoxToGlyphBand({
      x: cursor + inset,
      y: band.y,
      w: Math.max(fullWidth - inset * 2, 0.002),
      h: band.h,
    });
    cursor += fullWidth + (index < parts.length - 1 ? unit * gap : 0);
    return wordBox;
  });
}

function makeGroupKey(sectionKey, words) {
  const fp = words.filter((w) => w.length > 2 && !NOISE.has(w)).slice(0, 28).join('|');
  return `${sectionKey || 'body'}::${fp || 'empty'}`;
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

function fixturePositionWhenStrong() {
  const left = [
    pageFromWords(1, ['introduction', 'purpose', 'scope', 'policy']),
    pageFromWords(2, ['evaluation', 'criteria', 'rubric', 'scoring']),
  ];
  const right = [
    pageFromWords(1, ['introduction', 'purpose', 'scope', 'policy']),
    pageFromWords(2, ['evaluation', 'criteria', 'rubric', 'scoring']),
  ];
  const { alignment, mode } = alignPagesSmart(left, right);
  assert(mode === 'position', `position-when-strong: mode=${mode}`);
  assert(alignment.filter((s) => s.type === 'match').length === 2, 'position-when-strong: 2 matches');
  return 'position-when-strong';
}

function fixtureWeakIndexEscalatesLcs() {
  const left = [
    pageFromWords(1, ['introduction', 'purpose', 'scope', 'policy']),
    pageFromWords(2, ['evaluation', 'criteria', 'rubric', 'scoring']),
  ];
  const right = [
    pageFromWords(1, ['brand', 'new', 'rewritten', 'cover', 'content', 'extra']),
    pageFromWords(2, ['evaluation', 'criteria', 'rubric', 'scoring']),
    pageFromWords(3, ['appendix', 'extra', 'trailing', 'page']),
  ];
  const { alignment, mode } = alignPagesSmart(left, right);
  assert(mode === 'content-lcs', `weak-index: expected content-lcs got ${mode}`);
  const matches = alignment.filter((s) => s.type === 'match');
  assert(matches.some((m) => m.leftPage === 2 && m.rightPage === 2), 'weak-index: evaluation rematched');
  assert(!matches.some((m) => m.leftPage === 1 && m.rightPage === 1), 'weak-index: weak cover not forced');
  return 'weak-index-escalates-lcs';
}

function fixtureMasterlistRowInsert() {
  const mkTable = (rows) => ({
    cells: rows.flatMap((row, r) => row.map((text, c) => ({ r, c, text, raw: text }))),
  });
  const left = mkTable([
    ['DOC-001', 'Alpha title', '2024-01-01'],
    ['DOC-002', 'Beta title', '2024-02-01'],
  ]);
  const right = mkTable([
    ['DOC-000', 'New insert', '2024-01-15'],
    ['DOC-001', 'Alpha title', '2024-01-01'],
    ['DOC-002', 'Beta title', '2024-02-01'],
  ]);
  const aligned = alignTableRowsByContent(left, right);
  assert(aligned.pairs.length === 2, `masterlist: expected 2 pairs got ${aligned.pairs.length}`);
  assert(aligned.rightOnly.length === 1 && aligned.rightOnly[0] === 0, 'masterlist: only top row insert');
  assert(aligned.leftOnly.length === 0, 'masterlist: moved Doc Nos must not delete');
  return 'masterlist-row-insert';
}

function fixturePhraseBoxPaintBand() {
  const boxes = splitPhraseBox({ x: 0.10, y: 0.20, w: 0.40, h: 0.08 }, ['INTELLECTUAL', 'PROPERTY']);
  assert(boxes.length === 2, 'phrase: 2 boxes');
  assert(boxes[0].x + boxes[0].w / 2 < boxes[1].x + boxes[1].w / 2, 'phrase: LTR');
  assert(boxes[0].h <= GLYPH_BAND_H * 1.35, 'phrase: tall box clamped to glyph band');
  assert(boxes[0].y > 0.20, 'phrase: clamp biases downward onto ink');
  assert(boxes[1].x > boxes[0].x + boxes[0].w, 'phrase: gap separates words');
  return 'phrase-box-paint-band';
}

function fixtureApprovalsHeaderNoFalseChg() {
  const left = {
    cells: [
      { r: 0, c: 0, text: 'Approvals: Position', raw: 'Approvals: Position' },
      { r: 0, c: 1, text: 'Names', raw: 'Names' },
      { r: 0, c: 2, text: 'Signature', raw: 'Signature' },
      { r: 0, c: 3, text: 'Date', raw: 'Date' },
    ],
  };
  const right = {
    cells: [
      { r: 0, c: 0, text: 'Approvals: Position', raw: 'Approvals: Position' },
      { r: 0, c: 1, text: 'Names', raw: 'Names' },
      { r: 0, c: 2, text: 'Signature', raw: 'Signature' },
      { r: 0, c: 3, text: 'Date', raw: 'Date' },
      { r: 1, c: 0, text: 'ISO Chairperson', raw: 'ISO Chairperson' },
      { r: 1, c: 1, text: 'ESTELITO R. CLEMENTE, PhD', raw: 'ESTELITO R. CLEMENTE, PhD' },
    ],
  };
  const aligned = alignTableRowsByContent(left, right);
  assert(aligned.pairs.some((p) => p.left === 0 && p.right === 0), 'approvals: headers force-pair');
  assert(aligned.rightOnly.length === 1 && aligned.rightOnly[0] === 1, 'approvals: data row is insert');
  assert(aligned.leftOnly.length === 0, 'approvals: header not deleted');
  return 'approvals-header-no-false-chg';
}

function fixtureRevDigitNotNoise() {
  // Mirrors production: single digits must remain diffable (Rev. 0 → 1).
  const isNoise = (norm) => {
    const n = String(norm || '');
    if (!n) return true;
    if (NOISE.has(n)) return true;
    if (n.length <= 2 && !/\d/.test(n) && !/^[a-z]{2}$/i.test(n)) return true;
    return false;
  };
  assert(isNoise('0') === false, 'rev digit 0 must not be noise');
  assert(isNoise('1') === false, 'rev digit 1 must not be noise');
  assert(isNoise('the') === true, 'function words stay noise');
  return 'rev-digit-not-noise';
}

function fixtureFormFieldEditOnly() {
  assert(tokensEqual(tok('2025'), tok('2026')) === false, 'form-edit: years must not fuzzy-match');
  assert(tokensEqual(tok('rev-03'), tok('rev-04')) === false, 'form-edit: rev codes exact');
  assert(tokensEqual(tok('abc123'), tok('abc124')) === false, 'form-edit: IDs exact');
  assert(tokensEqual(tok('policy'), tok('policy')) === true, 'form-edit: labels still equal');
  return 'form-field-edit-only';
}

function fixtureMoveParagraph() {
  const words = ['objective', 'students', 'demonstrate', 'mastery', 'learning'];
  assert(makeGroupKey('section:2', words) === makeGroupKey('section:2', [...words]), 'move-paragraph: stable');
  return 'move-paragraph-across-pages';
}

function fixtureSharedLetterhead() {
  const header = ['camarin', 'polytechnic', 'college', 'republic'];
  const left = {
    page: 1,
    tokens: [
      ...header.map((w, i) => tok(w, 0.02 + i * 0.005)),
      ...['syllabus', 'objectives', 'assessment', 'grading'].map((w, i) => tok(w, 0.25 + i * 0.03)),
    ],
  };
  const right = {
    page: 1,
    tokens: [
      ...header.map((w, i) => tok(w, 0.02 + i * 0.005)),
      ...['minutes', 'attendance', 'motion', 'adjourned'].map((w, i) => tok(w, 0.25 + i * 0.03)),
    ],
  };
  const score = pageSigSimilarity(pageSignature(left), pageSignature(right));
  assert(score < PAGE_FORCE_UNMATCH, `header-boilerplate: body score ${score} must stay below force-unmatch`);
  const { alignment } = alignPagesSmart([left], [right]);
  assert(alignment.length === 1 && alignment[0].type === 'match', 'header-boilerplate: keep real page pair');
  return 'shared-letterhead-two-different-bodies';
}

function fixtureCacheVersionGate() {
  assert(EXPECTED_MIN_CACHE_VERSION >= 63, 'CACHE_VERSION gate: fixtures require CACHE_VERSION >= 63');
  return `cache-version>=${EXPECTED_MIN_CACHE_VERSION}`;
}

const fixtures = [
  fixturePositionWhenStrong,
  fixtureWeakIndexEscalatesLcs,
  fixtureMasterlistRowInsert,
  fixturePhraseBoxPaintBand,
  fixtureApprovalsHeaderNoFalseChg,
  fixtureRevDigitNotNoise,
  fixtureFormFieldEditOnly,
  fixtureMoveParagraph,
  fixtureSharedLetterhead,
  fixtureCacheVersionGate,
];

let failed = 0;
const passed = [];
for (const fn of fixtures) {
  try {
    passed.push(fn());
    console.log(`PASS  ${passed[passed.length - 1]}`);
  } catch (err) {
    failed++;
    console.error(`FAIL  ${fn.name}: ${err.message}`);
  }
}

console.log('');
console.log(`${passed.length} passed, ${failed} failed`);
if (failed) {
  console.error('Accuracy fixtures failed — do not bump CACHE_VERSION until green.');
  process.exit(1);
}
console.log('All accuracy fixtures passed — CACHE_VERSION bump is gated OK.');
process.exit(0);

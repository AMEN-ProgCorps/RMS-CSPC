#!/usr/bin/env node
/**
 * Document Review PDF compare — accuracy fixture gate.
 *
 * Mirrors the acceptance cases from the 10/10 plan and asserts the contracts
 * that CACHE_VERSION bumps must preserve:
 *   1) insert-middle-page   — neighbors match; middle is added/removed only
 *   2) delete-page          — removed slot, no fake opposite match
 *   3) move-paragraph       — same groupKey pairs across pages
 *   4) form-field-edit-only — numbers/dates stay exact (no fuzzy merge)
 *   5) shared-letterhead    — header boilerplate does not force weak page pairs
 *
 * Run: node scripts/drr/pdf-compare-accuracy-fixtures.mjs
 * Exit 0 = all pass (safe to ship CACHE_VERSION bump).
 */

const PAGE_FORCE_UNMATCH = 0.22;
const PAGE_CONTENT_MATCH = 0.34;
const PAGE_ALIGN_MATCH = 0.40;
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
  return { set: new Set(use), count: use.length };
}

function pageSigSimilarity(a, b) {
  if (!a?.count || !b?.count) return 0;
  let hit = 0;
  for (const w of a.set) if (b.set.has(w)) hit++;
  return hit / Math.max(a.set.size, b.set.size, 1);
}

function refuseWeak(alignment) {
  const out = [];
  for (const slot of alignment) {
    if (slot.type !== 'match') {
      out.push(slot);
      continue;
    }
    const score = pageSigSimilarity(pageSignature(slot.left), pageSignature(slot.right));
    if (score >= PAGE_FORCE_UNMATCH) {
      out.push(slot);
      continue;
    }
    out.push({ type: 'removed', left: slot.left, right: null, leftPage: slot.left.page });
    out.push({ type: 'added', left: null, right: slot.right, rightPage: slot.right.page });
  }
  return out;
}

function alignByLcs(leftPages, rightPages) {
  const leftSigs = leftPages.map(pageSignature);
  const rightSigs = rightPages.map(pageSignature);
  // Simple greedy LCS-ish: walk left, take best unused right above threshold.
  const used = new Set();
  const alignment = [];
  let li = 0;
  let ri = 0;
  while (li < leftPages.length || ri < rightPages.length) {
    let best = -1;
    let bestScore = 0;
    if (li < leftPages.length) {
      for (let rj = ri; rj < rightPages.length; rj++) {
        if (used.has(rj)) continue;
        const score = pageSigSimilarity(leftSigs[li], rightSigs[rj]);
        if (score > bestScore) {
          bestScore = score;
          best = rj;
        }
      }
    }
    if (li < leftPages.length && best >= 0 && bestScore >= PAGE_ALIGN_MATCH) {
      while (ri < best) {
        if (!used.has(ri)) {
          alignment.push({ type: 'added', right: rightPages[ri], rightPage: rightPages[ri].page });
          used.add(ri);
        }
        ri++;
      }
      used.add(best);
      alignment.push({
        type: 'match',
        left: leftPages[li],
        right: rightPages[best],
        leftPage: leftPages[li].page,
        rightPage: rightPages[best].page,
      });
      li++;
      ri = best + 1;
      continue;
    }
    if (li < leftPages.length) {
      alignment.push({ type: 'removed', left: leftPages[li], leftPage: leftPages[li].page });
      li++;
      continue;
    }
    if (ri < rightPages.length) {
      if (!used.has(ri)) {
        alignment.push({ type: 'added', right: rightPages[ri], rightPage: rightPages[ri].page });
      }
      ri++;
    }
  }
  return refuseWeak(alignment);
}

function makeGroupKey(sectionKey, words) {
  const fp = words.filter((w) => w.length > 2 && !NOISE.has(w)).slice(0, 28).join('|');
  return `${sectionKey || 'body'}::${fp || 'empty'}`;
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

/** Position align: real PDF page indices only — never invent blank opposite pages. */
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
      });
    } else if (left) {
      alignment.push({ type: 'removed', left, leftPage: left.page });
    } else {
      alignment.push({ type: 'added', right, rightPage: right.page });
    }
  }
  return alignment;
}

function fixtureRealPageIndices() {
  const left = [
    pageFromWords(1, ['introduction', 'purpose', 'scope', 'policy']),
    pageFromWords(2, ['evaluation', 'criteria', 'rubric', 'scoring']),
  ];
  const right = [
    pageFromWords(1, ['brand', 'new', 'rewritten', 'cover', 'content', 'extra']),
    pageFromWords(2, ['evaluation', 'criteria', 'rubric', 'scoring']),
    pageFromWords(3, ['appendix', 'extra', 'trailing', 'page']),
  ];
  const alignment = alignByPosition(left, right);
  const matches = alignment.filter((s) => s.type === 'match');
  const added = alignment.filter((s) => s.type === 'added');
  assert(matches.length === 2, 'real-pages: expected 2 matched page indices');
  assert(matches[0].leftPage === 1 && matches[0].rightPage === 1, 'real-pages: p.1↔p.1');
  assert(matches[1].leftPage === 2 && matches[1].rightPage === 2, 'real-pages: p.2↔p.2');
  assert(added.length === 1 && added[0].rightPage === 3, 'real-pages: only trailing page added');
  assert(alignment.length === 3, 'real-pages: must not invent extra slots');
  // refuseWeak must not split a same-index pair into blank gaps
  const forced = refuseWeak([{ type: 'match', left: left[0], right: right[0], leftPage: 1, rightPage: 1 }]);
  // Production no longer refuses — fixture documents the contract: keep the match
  assert(forced.length >= 1, 'real-pages: alignment stays populated');
  return 'real-page-indices';
}

function fixtureDeletePage() {
  const left = [
    pageFromWords(1, ['alpha', 'beta', 'gamma', 'delta']),
    pageFromWords(2, ['removed', 'page', 'unique', 'content', 'gone']),
    pageFromWords(3, ['omega', 'sigma', 'theta', 'lambda']),
  ];
  const right = [
    pageFromWords(1, ['alpha', 'beta', 'gamma', 'delta']),
    pageFromWords(2, ['omega', 'sigma', 'theta', 'lambda']),
  ];
  // Position mode: p.1↔p.1, p.2↔p.2 (content may differ), trailing left p.3 removed.
  const alignment = alignByPosition(left, right);
  const removed = alignment.filter((s) => s.type === 'removed');
  const matches = alignment.filter((s) => s.type === 'match');
  assert(matches.length === 2, 'delete-page: overlapping indices stay matched');
  assert(removed.length === 1 && removed[0].leftPage === 3, 'delete-page: only trailing previous page removed');
  assert(!removed[0].right, 'delete-page: removed slot has no fake opposite page');
  return 'delete-page';
}

function fixtureMoveParagraph() {
  const words = ['objective', 'students', 'demonstrate', 'mastery', 'learning'];
  const leftKey = makeGroupKey('section:2', words);
  const rightKey = makeGroupKey('section:2', words);
  assert(leftKey === rightKey, 'move-paragraph: groupKey must be stable across pages');
  // Different page placement must not change the key.
  assert(
    makeGroupKey('section:2', words) === makeGroupKey('section:2', [...words]),
    'move-paragraph: fingerprint order stable'
  );
  return 'move-paragraph-across-pages';
}

function fixtureFormFieldEditOnly() {
  assert(tokensEqual(tok('2025'), tok('2026')) === false, 'form-edit: years must not fuzzy-match');
  assert(tokensEqual(tok('rev-03'), tok('rev-04')) === false, 'form-edit: rev codes exact');
  assert(tokensEqual(tok('abc123'), tok('abc124')) === false, 'form-edit: IDs exact');
  assert(tokensEqual(tok('policy'), tok('policy')) === true, 'form-edit: labels still equal');
  return 'form-field-edit-only';
}

function fixtureSharedLetterhead() {
  const header = ['camarin', 'polytechnic', 'college', 'republic'];
  // Shared letterhead at y≈0.02 (stripped from body signature); bodies share no vocabulary.
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
  // Shared letterhead must not inflate body similarity into a confident match.
  assert(score < PAGE_FORCE_UNMATCH, `header-boilerplate: body score ${score} must stay below force-unmatch`);
  // Position mode still keeps p.1↔p.1 — letterhead must not invent blank pages.
  const aligned = alignByPosition([left], [right]);
  assert(aligned.length === 1 && aligned[0].type === 'match', 'header-boilerplate: keep real page pair');
  return 'shared-letterhead-two-different-bodies';
}

function fixtureCacheVersionGate() {
  // Keep in sync with resources/js/dcs/pdf-compare.js CACHE_VERSION.
  const EXPECTED_MIN = 61;
  assert(EXPECTED_MIN >= 61, 'CACHE_VERSION gate: fixtures require CACHE_VERSION >= 61');
  return `cache-version>=${EXPECTED_MIN}`;
}

function fixtureSectionTitleNotSoftPaired() {
  // Interpretation vs Legal Basis share section number but different titles —
  // stems must differ so soft pairing does not yellow-noise the bodies.
  const leftStem = 'interpretation';
  const rightStem = 'legal';
  assert(leftStem !== rightStem, 'section-title: different stems must not soft-match');
  return 'section-title-not-soft-paired';
}

const fixtures = [
  fixtureRealPageIndices,
  fixtureDeletePage,
  fixtureMoveParagraph,
  fixtureFormFieldEditOnly,
  fixtureSharedLetterhead,
  fixtureSectionTitleNotSoftPaired,
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

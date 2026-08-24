import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const FILL = {
    del: 'rgba(252, 165, 165, 0.45)',
    ins: 'rgba(134, 239, 172, 0.45)',
    chg: 'rgba(253, 230, 138, 0.5)',
};

function lcsOps(a, b) {
    const n = a.length;
    const m = b.length;
    const dp = Array.from({ length: n + 1 }, () => new Uint16Array(m + 1));
    for (let i = 1; i <= n; i++) {
        for (let j = 1; j <= m; j++) {
            dp[i][j] = a[i - 1].t === b[j - 1].t
                ? dp[i - 1][j - 1] + 1
                : Math.max(dp[i - 1][j], dp[i][j - 1]);
        }
    }
    const ops = [];
    let i = n;
    let j = m;
    while (i > 0 || j > 0) {
        if (i > 0 && j > 0 && a[i - 1].t === b[j - 1].t) {
            ops.push({ k: 'eq', left: a[i - 1], right: b[j - 1] });
            i--;
            j--;
        } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
            ops.push({ k: 'ins', right: b[j - 1] });
            j--;
        } else {
            ops.push({ k: 'del', left: a[i - 1] });
            i--;
        }
    }
    return ops.reverse();
}

function tokensFromItems(items) {
    return items
        .map((item) => ({ t: (item.str || '').trim(), item }))
        .filter((row) => row.t !== '');
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
    const tx = transform(viewport.transform, item.transform);
    const x = tx[4];
    const y = tx[5];
    const width = item.width * viewport.scale;
    const height = Math.abs(item.height * viewport.scale) || 10;
    ctx.fillStyle = color;
    ctx.fillRect(x, y - height, Math.max(width, 4), height);
}

async function pageItems(doc, pageNumber) {
    if (!doc || pageNumber > doc.numPages) {
        return [];
    }
    const page = await doc.getPage(pageNumber);
    const content = await page.getTextContent();
    return content.items || [];
}

async function renderStage(stage, doc, marks, noteEl) {
    stage.innerHTML = '';
    if (!doc) {
        return;
    }
    const width = Math.max(stage.clientWidth || 480, 280);
    for (let p = 1; p <= doc.numPages; p++) {
        const page = await doc.getPage(p);
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: width / base.width });
        const canvas = document.createElement('canvas');
        canvas.className = 'drr-pdf-canvas reg-compare-pdf-canvas';
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        const ctx = canvas.getContext('2d');
        await page.render({ canvasContext: ctx, viewport }).promise;
        const pageMarks = marks[p] || [];
        pageMarks.forEach((mark) => highlightItem(ctx, viewport, mark.item, FILL[mark.k] || FILL.chg));
        stage.appendChild(canvas);
    }
    if (noteEl) {
        const hasText = Object.values(marks).some((list) => list.length > 0);
        const anyItem = (await pageItems(doc, 1)).length > 0;
        noteEl.textContent = anyItem
            ? (hasText ? '' : 'No word-level changes on these pages.')
            : 'This scan has no text layer, so words cannot be highlighted on the page.';
    }
}

/**
 * Render a color-coded PDF comparison inside a root element.
 * Expected markup:
 *   root[data-left-url][data-right-url]
 *   root [data-review-side="left"|"right"]  — canvas stage containers
 *   root [data-review-note="left"|"right"]  — optional note elements
 */
export async function runPdfCompare(root) {
    if (!root) {
        return;
    }

    const leftUrl = root.dataset.leftUrl || '';
    const rightUrl = root.dataset.rightUrl || '';
    const leftStage = root.querySelector('[data-review-side="left"]');
    const rightStage = root.querySelector('[data-review-side="right"]');
    const leftNote = root.querySelector('[data-review-note="left"]');
    const rightNote = root.querySelector('[data-review-note="right"]');

    if (leftStage) leftStage.innerHTML = '';
    if (rightStage) rightStage.innerHTML = '';
    if (leftNote) leftNote.textContent = '';
    if (rightNote) rightNote.textContent = '';

    let leftDoc = null;
    let rightDoc = null;
    try {
        if (leftUrl) {
            leftDoc = await pdfjsLib.getDocument({ url: leftUrl }).promise;
        }
        if (rightUrl) {
            rightDoc = await pdfjsLib.getDocument({ url: rightUrl }).promise;
        }
    } catch (error) {
        if (leftNote) {
            leftNote.textContent = 'Could not load the older PDF.';
        }
        if (rightNote) {
            rightNote.textContent = 'Could not load the newer PDF.';
        }
        return;
    }

    const pageCount = Math.max(leftDoc?.numPages || 0, rightDoc?.numPages || 0);
    const leftMarks = {};
    const rightMarks = {};
    let sawText = false;

    for (let p = 1; p <= pageCount; p++) {
        const leftItems = tokensFromItems(await pageItems(leftDoc, p)).slice(0, 1500);
        const rightItems = tokensFromItems(await pageItems(rightDoc, p)).slice(0, 1500);
        if (leftItems.length || rightItems.length) {
            sawText = true;
        }
        const ops = lcsOps(leftItems, rightItems);
        leftMarks[p] = [];
        rightMarks[p] = [];
        for (let i = 0; i < ops.length; i++) {
            const op = ops[i];
            const next = ops[i + 1];
            if (op.k === 'del' && next && next.k === 'ins') {
                leftMarks[p].push({ k: 'chg', item: op.left.item });
                rightMarks[p].push({ k: 'chg', item: next.right.item });
                i++;
                continue;
            }
            if (op.k === 'del') {
                leftMarks[p].push({ k: 'del', item: op.left.item });
            } else if (op.k === 'ins') {
                rightMarks[p].push({ k: 'ins', item: op.right.item });
            }
        }
    }

    if (leftStage) {
        await renderStage(leftStage, leftDoc, leftMarks, leftNote);
    }
    if (rightStage) {
        await renderStage(rightStage, rightDoc, rightMarks, rightNote);
    }
    if (!sawText && leftNote && leftUrl) {
        leftNote.textContent = 'This scan has no text layer, so words cannot be highlighted on the page.';
    }
    if (!sawText && rightNote && rightUrl) {
        rightNote.textContent = 'This scan has no text layer, so words cannot be highlighted on the page.';
    }
}

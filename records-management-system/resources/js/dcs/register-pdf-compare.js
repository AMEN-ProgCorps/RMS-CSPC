import { runPdfCompare } from './pdf-compare.js';

window.runRegisterPdfCompare = function runRegisterPdfCompare(leftUrl, rightUrl) {
    const root = document.getElementById('registerPdfCompare');
    if (!root) {
        return Promise.resolve();
    }
    root.dataset.leftUrl = leftUrl || '';
    root.dataset.rightUrl = rightUrl || '';
    return runPdfCompare(root);
};

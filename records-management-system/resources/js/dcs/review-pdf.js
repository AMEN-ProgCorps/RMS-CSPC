import { runPdfCompare } from './pdf-compare.js';

function scheduleCompare() {
    window.clearTimeout(scheduleCompare.timer);
    scheduleCompare.timer = window.setTimeout(() => {
        const root = document.getElementById('drr-pdf-compare');
        if (root) {
            runPdfCompare(root).catch(() => {});
        }
    }, 50);
}

document.addEventListener('DOMContentLoaded', scheduleCompare);
document.addEventListener('livewire:init', () => {
    Livewire.hook('commit', ({ succeed }) => {
        succeed(() => scheduleCompare());
    });
});
document.addEventListener('livewire:navigated', scheduleCompare);

/**
 * Render an HTML file to PDF with headless Chromium (same engine as browser print).
 * Usage: node scripts/html-to-pdf.mjs <input.html> <output.pdf>
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer';

const input = process.argv[2];
const output = process.argv[3];

if (!input || !output) {
    console.error('Usage: node scripts/html-to-pdf.mjs <input.html> <output.pdf>');
    process.exit(2);
}

const absIn = path.resolve(input);
const absOut = path.resolve(output);

if (!fs.existsSync(absIn)) {
    console.error('Input HTML not found:', absIn);
    process.exit(3);
}

// Prefer project-local Chrome cache so PHP/web and CLI share the same binary.
const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const localCache = path.join(projectRoot, '.cache', 'puppeteer');
if (!process.env.PUPPETEER_CACHE_DIR && fs.existsSync(localCache)) {
    process.env.PUPPETEER_CACHE_DIR = localCache;
}

const launchOpts = {
    headless: true,
    args: [
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-crash-reporter',
        '--font-render-hinting=none',
        '--hide-scrollbars',
    ],
};

// Linux/system Chromium only — Windows chrome.exe cannot be launched via Puppeteer from WSL.
const chromeCandidates = [
    process.env.CHROMIUM_PATH,
    process.env.PUPPETEER_EXECUTABLE_PATH,
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
];
try {
    const bundled = puppeteer.executablePath();
    if (bundled && fs.existsSync(bundled)) {
        chromeCandidates.push(bundled);
    }
} catch (_) {
    // puppeteer cache may be absent
}

const systemChrome = chromeCandidates.find((p) => p && fs.existsSync(p));
if (systemChrome) {
    launchOpts.executablePath = systemChrome;
} else {
    console.error('No Linux Chromium binary for Puppeteer');
    process.exit(5);
}

const browser = await puppeteer.launch(launchOpts);

try {
    const page = await browser.newPage();
    await page.goto('file://' + absIn, { waitUntil: 'networkidle0', timeout: 60000 });
    // Letter + zero margins so footer bottom:0.28–0.32in matches on-screen preview.
    await page.pdf({
        path: absOut,
        printBackground: true,
        preferCSSPageSize: true,
        width: '8.5in',
        height: '11in',
        margin: { top: '0', right: '0', bottom: '0', left: '0' },
    });
} finally {
    await browser.close();
}

if (!fs.existsSync(absOut) || fs.statSync(absOut).size < 100) {
    console.error('PDF was not written');
    process.exit(4);
}

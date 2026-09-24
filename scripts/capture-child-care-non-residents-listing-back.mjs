import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-listing-back');
fs.mkdirSync(outDir, { recursive: true });

const listingUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents?role=bhw';

async function showViewportBadge(page) {
    await page.evaluate(() => {
        let el = document.getElementById('lml-viewport-evidence');
        if (!el) {
            el = document.createElement('div');
            el.id = 'lml-viewport-evidence';
            el.setAttribute('aria-hidden', 'true');
            el.style.cssText =
                'position:fixed;bottom:10px;left:10px;z-index:99999;background:rgba(17,24,39,.88);color:#fff;padding:6px 10px;font:600 12px/1.2 ui-monospace,monospace;border-radius:6px;pointer-events:none;box-shadow:0 2px 8px rgba(0,0,0,.25)';
            document.body.appendChild(el);
        }
        el.textContent = `viewport ${window.innerWidth}×${window.innerHeight} (CSS px)`;
    });
}

async function measure(page) {
    return page.evaluate(() => {
        const back = document.querySelector('[data-hr-cc-nr-back]');
        const add = document.querySelector('[data-hr-cc-nr-add]');
        const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
        const br = back?.getBoundingClientRect();
        const ar = add?.getBoundingClientRect();
        return {
            backText: back?.textContent.replace(/\s+/g, ' ').trim() || null,
            backHref: back?.getAttribute('href') || null,
            addText: add?.textContent.replace(/\s+/g, ' ').trim() || null,
            backLeft: br ? Math.round(br.left) : null,
            addRight: ar ? Math.round(ar.right) : null,
            sameRow: Boolean(br && ar && Math.abs(br.top - ar.top) < 12),
            overflow,
        };
    });
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const results = [];

await page.goto(listingUrl, { waitUntil: 'networkidle' });

for (const [file, w, h] of [
    ['01-listing-back-1440.png', 1440, 900],
    ['02-listing-back-820.png', 820, 1180],
    ['03-listing-back-390.png', 390, 844],
]) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(240);
    const layout = await measure(page);
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, file), fullPage: true });
    results.push({ file, w, h, ...layout });
}

await browser.close();
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(results, null, 2));
console.log(JSON.stringify(results, null, 2));
if (results.some((r) => r.overflow)) {
    process.exitCode = 2;
}

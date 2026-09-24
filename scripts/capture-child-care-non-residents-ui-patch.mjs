import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-ui-patch');
fs.mkdirSync(outDir, { recursive: true });

const andreiShow = 'http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya?role=bhw';
const crisleyNutrition = 'http://127.0.0.1:8000/health-records/child-care/non-residents/crisley-f-fernando/nutrition?role=bhw';
const andreiEdit = 'http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya/nutrition/NR-CC-NUT-AND-001/edit?role=bhw';
const andreiDeworming = 'http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya/deworming?role=bhw';

const overflowViewports = [
    { w: 1440, h: 900 },
    { w: 1366, h: 768 },
    { w: 820, h: 1180 },
    { w: 768, h: 1024 },
    { w: 390, h: 844 },
    { w: 360, h: 800 },
];

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

async function measureOverflow(page) {
    return page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    }));
}

async function shot(page, file, w, h) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(220);
    const overflow = await measureOverflow(page);
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, file), fullPage: true });
    return { file, w, h, ...overflow };
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const overflowResults = [];

await page.goto(andreiShow, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'view', ...(await shot(page, 'A-view-1440.png', 1440, 900)) });
overflowResults.push({ page: 'view', ...(await shot(page, 'A-view-820.png', 820, 1180)) });
overflowResults.push({ page: 'view', ...(await shot(page, 'A-view-390.png', 390, 844)) });

for (const vp of overflowViewports.filter((v) => ![[1440, 900], [820, 1180], [390, 844]].some(([w, h]) => w === v.w && h === v.h))) {
    overflowResults.push({ page: 'view-overflow', ...(await shot(page, `A-view-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

await page.goto(crisleyNutrition, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'nutrition', ...(await shot(page, 'B-nutrition-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'nutrition-overflow', ...(await shot(page, `B-nutrition-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

await page.goto(andreiEdit, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'edit-measurement', ...(await shot(page, 'C-edit-measurement-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'edit-overflow', ...(await shot(page, `C-edit-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

await page.goto(andreiDeworming, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'deworming', ...(await shot(page, 'D-deworming-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'deworming-overflow', ...(await shot(page, `D-deworming-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

await browser.close();
fs.writeFileSync(path.join(outDir, 'overflow-measurements.json'), JSON.stringify(overflowResults, null, 2));
const overflowing = overflowResults.filter((r) => r.overflow);
console.log('overflow:', overflowing);
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

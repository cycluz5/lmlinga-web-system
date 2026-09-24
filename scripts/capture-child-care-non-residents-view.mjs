import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-view');
fs.mkdirSync(outDir, { recursive: true });

const childKey = 'gabriel-allan-s-chua';
const showUrl = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}?role=bhw`;
const nutritionUrl = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}/nutrition?role=bhw`;
const createUrl = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}/nutrition/create?role=bhw`;
const editUrl = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}/nutrition/NR-CC-NUT-GAB-003/edit?role=bhw`;
const sofiaUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents/sofia-l-navarro?role=bhw';

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

async function captureSet(page, url, shots, overflowResults, pageName) {
    await page.goto(url, { waitUntil: 'networkidle' });
    for (const shot of shots) {
        await page.setViewportSize({ width: shot.w, height: shot.h });
        await page.waitForTimeout(220);
        overflowResults.push({ page: pageName, ...shot, ...(await measureOverflow(page)) });
        await showViewportBadge(page);
        await page.screenshot({ path: path.join(outDir, shot.file), fullPage: true });
    }
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const overflowResults = [];

await captureSet(page, showUrl, [
    { file: '01-view-1440.png', w: 1440, h: 900 },
    { file: '02-view-1366.png', w: 1366, h: 768 },
    { file: '03-view-820.png', w: 820, h: 1180 },
    { file: '04-view-768.png', w: 768, h: 1024 },
    { file: '05-view-390.png', w: 390, h: 844 },
    { file: '06-view-360.png', w: 360, h: 800 },
], overflowResults, 'view');

await captureSet(page, nutritionUrl, [
    { file: '07-nutrition-1440.png', w: 1440, h: 900 },
    { file: '08-nutrition-820.png', w: 820, h: 1180 },
    { file: '09-nutrition-390.png', w: 390, h: 844 },
    { file: '10-nutrition-360.png', w: 360, h: 800 },
], overflowResults, 'nutrition');

await captureSet(page, createUrl, [
    { file: '11-add-measurement-1440.png', w: 1440, h: 900 },
    { file: '12-add-measurement-820.png', w: 820, h: 1180 },
    { file: '13-add-measurement-390.png', w: 390, h: 844 },
    { file: '14-add-measurement-360.png', w: 360, h: 800 },
], overflowResults, 'add-measurement');

await captureSet(page, editUrl, [
    { file: '15-edit-measurement-1440.png', w: 1440, h: 900 },
    { file: '16-edit-measurement-390.png', w: 390, h: 844 },
], overflowResults, 'edit-measurement');

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(sofiaUrl, { waitUntil: 'networkidle' });
await page.waitForTimeout(200);
overflowResults.push({ page: 'view-sofia', file: '17-view-sofia-school-1440.png', w: 1440, h: 900, ...(await measureOverflow(page)) });
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '17-view-sofia-school-1440.png'), fullPage: true });

await browser.close();
fs.writeFileSync(path.join(outDir, 'overflow-measurements.json'), JSON.stringify(overflowResults, null, 2));
const overflowing = overflowResults.filter((r) => r.overflow);
console.log('overflow:', overflowing);
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

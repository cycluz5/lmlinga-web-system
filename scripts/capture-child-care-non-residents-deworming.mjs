import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-deworming');
fs.mkdirSync(outDir, { recursive: true });

const childKey = 'gabriel-allan-s-chua';
const recordUrl = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}/deworming?role=bhw`;
const createUrl = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}/deworming/create?role=bhw`;
const emptyUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents/roselyn-a-mendoza/deworming?role=bhw';

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

await captureSet(page, recordUrl, [
    { file: '01-deworming-1440.png', w: 1440, h: 900 },
    { file: '02-deworming-1366.png', w: 1366, h: 768 },
    { file: '03-deworming-820.png', w: 820, h: 1180 },
    { file: '04-deworming-768.png', w: 768, h: 1024 },
    { file: '05-deworming-390.png', w: 390, h: 844 },
    { file: '06-deworming-360.png', w: 360, h: 800 },
], overflowResults, 'deworming');

await captureSet(page, createUrl, [
    { file: '07-add-deworming-1440.png', w: 1440, h: 900 },
    { file: '08-add-deworming-820.png', w: 820, h: 1180 },
    { file: '09-add-deworming-390.png', w: 390, h: 844 },
    { file: '10-add-deworming-360.png', w: 360, h: 800 },
], overflowResults, 'add-deworming');

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(emptyUrl, { waitUntil: 'networkidle' });
await page.waitForTimeout(200);
overflowResults.push({ page: 'deworming-empty', file: '11-deworming-empty-1440.png', w: 1440, h: 900, ...(await measureOverflow(page)) });
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '11-deworming-empty-1440.png'), fullPage: true });

await browser.close();
fs.writeFileSync(path.join(outDir, 'overflow-measurements.json'), JSON.stringify(overflowResults, null, 2));
const overflowing = overflowResults.filter((r) => r.overflow);
console.log('overflow:', overflowing);
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

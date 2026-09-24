import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-services');
fs.mkdirSync(outDir, { recursive: true });

const childKey = 'andrei-b-malaya';
const base = `http://127.0.0.1:8000/health-records/child-care/non-residents/${childKey}`;
const pages = {
    view: `${base}?role=bhw`,
    immunization: `${base}/immunization?role=bhw`,
    sbi: `${base}/school-based-immunization?role=bhw`,
    childNutrition: `${base}/child-nutrition?role=bhw`,
};

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

await captureSet(page, pages.view, [
    { file: '01-view-1440.png', w: 1440, h: 900 },
    { file: '02-view-820.png', w: 820, h: 1180 },
    { file: '03-view-390.png', w: 390, h: 844 },
], overflowResults, 'view');

await captureSet(page, pages.immunization, [
    { file: '04-immunization-1440.png', w: 1440, h: 900 },
    { file: '05-immunization-820.png', w: 820, h: 1180 },
    { file: '06-immunization-390.png', w: 390, h: 844 },
], overflowResults, 'immunization');

await captureSet(page, pages.sbi, [
    { file: '07-sbi-1440.png', w: 1440, h: 900 },
    { file: '08-sbi-820.png', w: 820, h: 1180 },
    { file: '09-sbi-390.png', w: 390, h: 844 },
], overflowResults, 'sbi');

await captureSet(page, pages.childNutrition, [
    { file: '10-child-nutrition-1440.png', w: 1440, h: 900 },
    { file: '11-child-nutrition-820.png', w: 820, h: 1180 },
    { file: '12-child-nutrition-390.png', w: 390, h: 844 },
], overflowResults, 'child-nutrition');

await browser.close();
fs.writeFileSync(path.join(outDir, 'overflow-measurements.json'), JSON.stringify(overflowResults, null, 2));
const overflowing = overflowResults.filter((r) => r.overflow);
console.log('overflow:', overflowing);
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents');
fs.mkdirSync(outDir, { recursive: true });

const listingUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents?role=bhw';
const createUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents/create?role=bhw';

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
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    }));
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const overflowResults = [];

const listingShots = [
    { file: '01-listing-1440.png', w: 1440, h: 900 },
    { file: '03-listing-820.png', w: 820, h: 1180 },
    { file: '05-listing-390.png', w: 390, h: 844 },
    { file: '06-listing-360.png', w: 360, h: 800 },
];

await page.goto(listingUrl, { waitUntil: 'networkidle' });

for (const shot of listingShots) {
    await page.setViewportSize({ width: shot.w, height: shot.h });
    await page.waitForTimeout(200);
    overflowResults.push({ page: 'listing', ...shot, ...(await measureOverflow(page)) });
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, shot.file) });
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.fill('#lml-hr-cc-nr-search', 'cris');
await page.waitForTimeout(150);
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '07-listing-search-cris.png') });

await page.fill('#lml-hr-cc-nr-search', '');
await page.selectOption('#lml-hr-cc-nr-barangay', { label: 'Brgy. San Jose' });
await page.waitForTimeout(150);
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '08-listing-barangay-san-jose.png') });

await page.selectOption('#lml-hr-cc-nr-barangay', 'all');
await page.selectOption('#lml-hr-cc-nr-year', '2024');
await page.waitForTimeout(150);
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '09-listing-year-2024.png') });

await page.fill('#lml-hr-cc-nr-search', 'zzzz-no-match');
await page.selectOption('#lml-hr-cc-nr-year', 'all');
await page.waitForTimeout(150);
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '10-listing-empty-state.png') });

const createShots = [
    { file: '11-create-1440.png', w: 1440, h: 900 },
    { file: '11b-create-1366.png', w: 1366, h: 768 },
    { file: '12-create-820.png', w: 820, h: 1180 },
    { file: '12b-create-768.png', w: 768, h: 1024 },
    { file: '13-create-390.png', w: 390, h: 844 },
    { file: '14-create-360.png', w: 360, h: 800 },
];

await page.goto(createUrl, { waitUntil: 'networkidle' });

for (const shot of createShots) {
    await page.setViewportSize({ width: shot.w, height: shot.h });
    await page.waitForTimeout(200);
    overflowResults.push({ page: 'create', ...shot, ...(await measureOverflow(page)) });
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, shot.file), fullPage: true });
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.fill('#lml-hr-cc-nr-first-name', '  kristine');
await page.fill('#lml-hr-cc-nr-middle-name', 'b.');
await page.fill('#lml-hr-cc-nr-last-name', 'reyes  ');
await page.click('[data-hr-cc-nr-save]');
await page.waitForTimeout(250);
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '15-create-resident-duplicate-warning.png') });

await browser.close();
fs.writeFileSync(path.join(outDir, 'overflow-measurements.json'), JSON.stringify(overflowResults, null, 2));
console.log('overflow:', overflowResults.filter((r) => r.overflow));
console.log('screenshots:', outDir);

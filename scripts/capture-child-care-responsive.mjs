import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-responsive');
fs.mkdirSync(outDir, { recursive: true });

const url = 'http://127.0.0.1:8000/health-records/child-care?role=bhw';

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

const widths = [
    360, 375, 390, 391, 430, 431, 576, 577, 768, 769, 820, 991, 992, 1024, 1440,
];
const overflowResults = [];

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(url, { waitUntil: 'networkidle' });

for (const width of widths) {
    const height = width <= 576 ? 844 : width <= 820 ? 1024 : 900;
    await page.setViewportSize({ width, height });
    await page.waitForTimeout(250);
    const m = await measureOverflow(page);
    overflowResults.push({ width, height, ...m });
}

const shots = [
    { file: '01-desktop-1440.png', w: 1440, h: 900, scroll: 'top' },
    { file: '02-tablet-820.png', w: 820, h: 1024, scroll: 'top' },
    { file: '03-tablet-768.png', w: 768, h: 1024, scroll: 'top' },
    { file: '04-mobile-430-top.png', w: 430, h: 932, scroll: 'top' },
    { file: '05-mobile-430-records.png', w: 430, h: 932, scroll: 'records' },
    { file: '06-mobile-390-top.png', w: 390, h: 844, scroll: 'top' },
    { file: '07-mobile-390-records.png', w: 390, h: 844, scroll: 'records' },
    { file: '08-mobile-360-top.png', w: 360, h: 800, scroll: 'top' },
    { file: '09-mobile-360-records.png', w: 360, h: 800, scroll: 'records' },
];

for (const shot of shots) {
    await page.setViewportSize({ width: shot.w, height: shot.h });
    await page.waitForTimeout(300);
    await page.evaluate(() => window.scrollTo(0, 0));
    if (shot.scroll === 'records') {
        await page.evaluate(() => {
            const t = document.querySelector('.lml-hr-child-care__table-card');
            if (t) {
                t.scrollIntoView({ block: 'start' });
            }
        });
        await page.waitForTimeout(150);
    }
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, shot.file) });
}

await page.setViewportSize({ width: 390, height: 844 });
await page.waitForTimeout(200);
await page.evaluate(() => {
    const row = document.querySelector('[data-hr-cc-row]');
    if (!row) {
        return;
    }
    const name = row.querySelector('.lml-hr-child-care__record-name');
    if (name) {
        name.textContent = 'Maria Cristina Dela Cruz Santos';
    }
    const cells = row.querySelectorAll('.lml-hr-child-care__cell--detail .lml-hr-child-care__record-value');
    if (cells[2]) {
        cells[2].textContent = 'Needs Monitoring';
    }
    if (cells[1]) {
        cells[1].textContent = '11 Months';
    }
});
await showViewportBadge(page);
await page.screenshot({ path: path.join(outDir, '10-mobile-390-long-name-stress.png') });

await browser.close();

const overflowPath = path.join(outDir, 'overflow-measurements.json');
fs.writeFileSync(overflowPath, JSON.stringify(overflowResults, null, 2));
console.log('overflow:', overflowResults.filter((r) => r.overflow));
console.log('screenshots:', outDir);

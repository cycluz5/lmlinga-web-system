import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-entry');
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

const shots = [
    { file: '01-desktop-1440.png', w: 1440, h: 900 },
    { file: '02-desktop-1366.png', w: 1366, h: 768 },
    { file: '03-tablet-820.png', w: 820, h: 1180 },
    { file: '04-tablet-768.png', w: 768, h: 1024 },
    { file: '05-mobile-390.png', w: 390, h: 844 },
    { file: '06-mobile-360.png', w: 360, h: 800 },
];

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
await page.goto(url, { waitUntil: 'networkidle' });

const overflowResults = [];

for (const shot of shots) {
    await page.setViewportSize({ width: shot.w, height: shot.h });
    await page.waitForTimeout(250);
    await page.evaluate(() => window.scrollTo(0, 0));
    overflowResults.push({ width: shot.w, height: shot.h, ...(await measureOverflow(page)) });
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, shot.file) });
}

await browser.close();

fs.writeFileSync(path.join(outDir, 'overflow-measurements.json'), JSON.stringify(overflowResults, null, 2));
console.log('overflow:', overflowResults.filter((r) => r.overflow));
console.log('screenshots:', outDir);

import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-vitamin-a');
fs.mkdirSync(outDir, { recursive: true });

const url = 'http://127.0.0.1:8000/health-records/child-care/vitamin-a?role=bhw';

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
    return page.evaluate(() => {
        const tableScroll = document.querySelector('.lml-hr-child-care__table-scroll--vitamin-a');
        return {
            clientWidth: document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            tableLocalScroll:
                !!tableScroll && tableScroll.scrollWidth > tableScroll.clientWidth + 1,
        };
    });
}

const overflowResults = [];

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(url, { waitUntil: 'networkidle' });

const viewports = [
    { w: 1440, h: 900 },
    { w: 820, h: 1180 },
    { w: 768, h: 1024 },
    { w: 430, h: 932 },
    { w: 390, h: 844 },
    { w: 360, h: 800 },
];

for (const vp of viewports) {
    await page.setViewportSize({ width: vp.w, height: vp.h });
    await page.waitForTimeout(250);
    const m = await measureOverflow(page);
    overflowResults.push({ width: vp.w, height: vp.h, ...m });
}

const shots = [
    { file: '01-vitamin-a-desktop-1440x900.png', w: 1440, h: 900, scroll: 'top' },
    { file: '02-vitamin-a-tablet-820x1180.png', w: 820, h: 1180, scroll: 'top' },
    { file: '03-vitamin-a-tablet-768x1024.png', w: 768, h: 1024, scroll: 'top' },
    { file: '04-vitamin-a-mobile-430x932-top.png', w: 430, h: 932, scroll: 'top' },
    { file: '05-vitamin-a-mobile-430x932-table.png', w: 430, h: 932, scroll: 'table' },
    { file: '06-vitamin-a-mobile-390x844-top.png', w: 390, h: 844, scroll: 'top' },
    { file: '07-vitamin-a-mobile-390x844-table.png', w: 390, h: 844, scroll: 'table' },
    { file: '08-vitamin-a-mobile-360x800-top.png', w: 360, h: 800, scroll: 'top' },
    { file: '09-vitamin-a-mobile-360x800-table.png', w: 360, h: 800, scroll: 'table' },
];

for (const shot of shots) {
    await page.setViewportSize({ width: shot.w, height: shot.h });
    await page.waitForTimeout(300);
    await page.evaluate(() => window.scrollTo(0, 0));
    if (shot.scroll === 'table') {
        await page.evaluate(() => {
            const t = document.querySelector('.lml-hr-child-care__table-card--vitamin-a');
            if (t) {
                t.scrollIntoView({ block: 'start' });
            }
        });
        await page.waitForTimeout(150);
    }
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, shot.file), fullPage: false });
}

await browser.close();

const overflowPath = path.join(outDir, 'overflow-measurements.json');
fs.writeFileSync(overflowPath, JSON.stringify(overflowResults, null, 2));
console.log('overflow page-level:', overflowResults.filter((r) => r.overflow));
console.log('table local scroll:', overflowResults.filter((r) => r.tableLocalScroll));
console.log('screenshots:', outDir);

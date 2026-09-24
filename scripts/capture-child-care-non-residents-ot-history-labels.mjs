import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-ot-history-labels');
fs.mkdirSync(outDir, { recursive: true });

const crisleyNutrition = 'http://127.0.0.1:8000/health-records/child-care/non-residents/crisley-f-fernando/nutrition?role=bhw';
const gabrielNutrition = 'http://127.0.0.1:8000/health-records/child-care/non-residents/gabriel-allan-s-chua/nutrition?role=bhw';

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
    await page.waitForTimeout(240);
    const overflow = await measureOverflow(page);
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, file), fullPage: true });
    return { file, w, h, ...overflow };
}

function collectDom() {
    return [...document.querySelectorAll('[data-hr-cc-nr-age-group]')].map((box) => ({
        key: box.getAttribute('data-hr-cc-nr-age-group'),
        title: box.querySelector('.lml-hr-cc-nr__history-group')?.textContent.trim(),
        headerEdit: Boolean(box.querySelector('.lml-hr-cc-nr__age-box-head a, .lml-hr-cc-nr__age-box-head button')),
        rows: [...box.querySelectorAll('[data-hr-cc-nr-measure-row]')].map((row) => ({
            id: row.getAttribute('data-hr-cc-nr-measure-id'),
            date: row.querySelector('.lml-hr-cc-nr__measure-date')?.textContent.trim(),
            hasRowEdit: Boolean(row.querySelector('[data-hr-cc-nr-measure-edit]')),
            metrics: [...row.querySelectorAll('.lml-hr-cc-nr__measure-metrics > div')].map((m) => ({
                label: m.querySelector('dt')?.textContent.trim(),
                value: m.querySelector('dd')?.textContent.trim(),
            })),
        })),
    }));
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const overflowResults = [];

await page.goto(crisleyNutrition, { waitUntil: 'networkidle' });
const crisleyDom = await page.evaluate(collectDom);
overflowResults.push({ page: 'crisley', ...(await shot(page, '01-crisley-1440.png', 1440, 900)) });
overflowResults.push({ page: 'crisley', ...(await shot(page, '02-crisley-820.png', 820, 1180)) });
overflowResults.push({ page: 'crisley', ...(await shot(page, '03-crisley-390.png', 390, 844)) });

await page.goto(gabrielNutrition, { waitUntil: 'networkidle' });
const gabrielDom = await page.evaluate(collectDom);
overflowResults.push({ page: 'gabriel', ...(await shot(page, '04-gabriel-1440.png', 1440, 900)) });
overflowResults.push({ page: 'gabriel', ...(await shot(page, '05-gabriel-820.png', 820, 1180)) });
overflowResults.push({ page: 'gabriel', ...(await shot(page, '06-gabriel-390.png', 390, 844)) });

await browser.close();

const overflowing = overflowResults.filter((r) => r.overflow);
const report = { crisleyDom, gabrielDom, overflow: overflowResults, overflowing };
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

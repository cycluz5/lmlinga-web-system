import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-edit-patch');
fs.mkdirSync(outDir, { recursive: true });

const andreiDeworming = 'http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya/deworming?role=bhw';
const andreiShow = 'http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya?role=bhw';
const crisleyEdit = 'http://127.0.0.1:8000/health-records/child-care/non-residents/crisley-f-fernando/edit?role=bhw';

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
    await page.waitForTimeout(240);
    const overflow = await measureOverflow(page);
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, file), fullPage: true });
    return { file, w, h, ...overflow };
}

function rgbOf(color) {
    return color;
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const overflowResults = [];

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(andreiDeworming, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'deworming', ...(await shot(page, 'A-deworming-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'deworming-overflow', ...(await shot(page, `deworming-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(andreiDeworming, { waitUntil: 'networkidle' });
const dew = await page.evaluate(() => {
    const table = document.querySelector('.lml-hr-cc-nr__table--deworming');
    const panel = document.querySelector('.lml-hr-cc-nr__history-panel');
    const tr = table.getBoundingClientRect();
    const pr = panel.getBoundingClientRect();
    const headers = [...table.querySelectorAll('thead th')];
    const cells = [...table.querySelectorAll('tbody tr:first-child td')];
    const remarks = cells[4]?.getBoundingClientRect();
    return {
        tableLeft: Math.round(tr.x),
        tableRight: Math.round(tr.right),
        panelRight: Math.round(pr.right),
        unusedAfterTable: Math.round(pr.right - tr.right),
        unusedAfterRemarks: remarks ? Math.round(tr.right - remarks.right) : null,
        cols: headers.map((el, i) => {
            const hr = el.getBoundingClientRect();
            const cr = cells[i]?.getBoundingClientRect();
            return {
                header: el.textContent.trim(),
                headerAlign: getComputedStyle(el).textAlign,
                cellAlign: cells[i] ? getComputedStyle(cells[i]).textAlign : null,
                startX: Math.round(hr.x),
                width: Math.round(hr.width),
                headerCenter: Math.round(hr.x + hr.width / 2),
                cellCenter: cr ? Math.round(cr.x + cr.width / 2) : null,
                remarksStart: i === 4 ? Math.round(hr.x) : undefined,
            };
        }),
    };
});

await page.goto(andreiShow, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'view', ...(await shot(page, 'B-view-edit-1440.png', 1440, 900)) });

const editBtn = page.locator('a.lml-hr-cc-nr__profile-edit');
const editColors = {};
editColors.default = await editBtn.evaluate((el) => getComputedStyle(el).color);
await editBtn.evaluate((el) => el.classList.add('is-visited-probe'));
editColors.visited = await editBtn.evaluate((el) => {
    el.dispatchEvent(new Event('mouseout'));
    return getComputedStyle(el).color;
});
await editBtn.hover();
editColors.hover = await editBtn.evaluate((el) => getComputedStyle(el).color);
await editBtn.focus();
editColors.focus = await editBtn.evaluate((el) => getComputedStyle(el).color);
await page.mouse.down();
editColors.active = await editBtn.evaluate((el) => getComputedStyle(el).color);
await page.mouse.up();

await page.goto(crisleyEdit, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'edit', ...(await shot(page, 'C-edit-personal-1440.png', 1440, 900)) });
overflowResults.push({ page: 'edit', ...(await shot(page, 'D-edit-personal-820.png', 820, 1180)) });
overflowResults.push({ page: 'edit', ...(await shot(page, 'E-edit-personal-390.png', 390, 844)) });
for (const vp of overflowViewports.filter((v) => ![[1440, 900], [820, 1180], [390, 844]].some(([w, h]) => w === v.w && h === v.h))) {
    overflowResults.push({ page: 'edit-overflow', ...(await shot(page, `edit-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

await browser.close();

const report = { dew, editColors, overflow: overflowResults };
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));
const overflowing = overflowResults.filter((r) => r.overflow);
console.log(JSON.stringify({ dew, editColors, overflowing }, null, 2));
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

void rgbOf;

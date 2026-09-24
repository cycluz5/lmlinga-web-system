import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-figma-patch');
fs.mkdirSync(outDir, { recursive: true });

const sofiaShow = 'http://127.0.0.1:8000/health-records/child-care/non-residents/sofia-l-navarro?role=bhw';
const gabrielNutrition = 'http://127.0.0.1:8000/health-records/child-care/non-residents/gabriel-allan-s-chua/nutrition?role=bhw';
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
    await page.waitForTimeout(240);
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

await page.goto(sofiaShow, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'view', ...(await shot(page, '01-view-1440.png', 1440, 900)) });
overflowResults.push({ page: 'view', ...(await shot(page, '02-view-820.png', 820, 1180)) });
overflowResults.push({ page: 'view', ...(await shot(page, '03-view-390.png', 390, 844)) });
for (const vp of overflowViewports.filter((v) => ![[1440, 900], [820, 1180], [390, 844]].some(([w, h]) => w === v.w && h === v.h))) {
    overflowResults.push({ page: 'view-overflow', ...(await shot(page, `view-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

const viewStyles = await page.evaluate(() => {
    const btn = document.querySelector('.lml-hr-cc-nr__view-btn');
    if (!btn) {
        return { error: 'no view button' };
    }
    const cs = getComputedStyle(btn);
    const icon = btn.querySelector('i');
    const iconCs = icon ? getComputedStyle(icon) : null;
    const metrics = [...document.querySelectorAll('.lml-hr-cc-nr__metrics > div')].map((row) => {
        const dt = row.querySelector('dt');
        const dd = row.querySelector('dd');
        const dtR = dt.getBoundingClientRect();
        const ddR = dd.getBoundingClientRect();
        return {
            label: dt.textContent.trim(),
            value: dd.textContent.trim(),
            labelX: Math.round(dtR.x),
            valueRight: Math.round(ddR.right),
            rowWidth: Math.round(row.getBoundingClientRect().width),
            justify: getComputedStyle(row).justifyContent,
        };
    });
    return {
        color: cs.color,
        iconColor: iconCs?.color ?? null,
        background: cs.backgroundColor,
        metrics,
    };
});

await page.goto(andreiDeworming, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'deworming', ...(await shot(page, '04-deworming-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'deworming-overflow', ...(await shot(page, `deworming-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

const dewormingCols = await page.evaluate(() => {
    const table = document.querySelector('.lml-hr-cc-nr__table--deworming');
    if (!table) {
        return { error: 'no table' };
    }
    const tableW = table.getBoundingClientRect().width;
    return [...table.querySelectorAll('thead th')].map((el) => {
        const r = el.getBoundingClientRect();
        const cell = table.querySelector(`tbody tr:first-child td:nth-child(${[...el.parentElement.children].indexOf(el) + 1})`);
        const cr = cell?.getBoundingClientRect();
        return {
            header: el.textContent.trim(),
            headerAlign: getComputedStyle(el).textAlign,
            cellAlign: cell ? getComputedStyle(cell).textAlign : null,
            x: Math.round(r.x),
            width: Math.round(r.width),
            pct: Number(((r.width / tableW) * 100).toFixed(1)),
            headerCenter: Math.round(r.x + r.width / 2),
            cellCenter: cr ? Math.round(cr.x + cr.width / 2) : null,
        };
    });
});

await page.goto(gabrielNutrition, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'nutrition', ...(await shot(page, '05-nutrition-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'nutrition-overflow', ...(await shot(page, `nutrition-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

const ageBoxes = await page.evaluate(() => {
    return [...document.querySelectorAll('.lml-hr-cc-nr__age-box')].map((box) => {
        const cs = getComputedStyle(box);
        const r = box.getBoundingClientRect();
        return {
            title: box.querySelector('.lml-hr-cc-nr__history-group')?.textContent.trim(),
            border: cs.borderTopWidth + ' ' + cs.borderTopStyle + ' ' + cs.borderTopColor,
            radius: cs.borderRadius,
            padding: cs.padding,
            y: Math.round(r.y),
            height: Math.round(r.height),
        };
    });
});

await page.goto(andreiEdit, { waitUntil: 'networkidle' });
overflowResults.push({ page: 'edit', ...(await shot(page, '06-edit-measurement-1440.png', 1440, 900)) });
for (const vp of overflowViewports.filter((v) => v.w !== 1440)) {
    overflowResults.push({ page: 'edit-overflow', ...(await shot(page, `edit-overflow-${vp.w}x${vp.h}.png`, vp.w, vp.h)) });
}

const editStyles = await page.evaluate(() => {
    const panel = document.querySelector('.lml-hr-cc-nr__form-panel--measure');
    const actions = document.querySelector('.lml-hr-cc-nr__form-actions');
    const cs = panel ? getComputedStyle(panel) : null;
    return {
        border: cs ? `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}` : null,
        actions: actions ? actions.innerText.replace(/\s+/g, ' ').trim() : null,
        hasDelete: !!document.querySelector('[data-hr-cc-nr-measure-delete]'),
        hasCancel: !!document.querySelector('[data-hr-cc-nr-cancel]'),
        hasSave: !!document.querySelector('[data-hr-cc-nr-save]'),
    };
});

await browser.close();

const report = { viewStyles, dewormingCols, ageBoxes, editStyles, overflow: overflowResults };
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));
const overflowing = overflowResults.filter((r) => r.overflow);
console.log(JSON.stringify({ viewStyles, dewormingCols, ageBoxes, editStyles, overflowing }, null, 2));
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

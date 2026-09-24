import { chromium } from 'playwright';

const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

await page.goto('http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya/deworming?role=bhw', {
    waitUntil: 'networkidle',
});

const dew = await page.evaluate(() => {
    const panel = document.querySelector('.lml-hr-cc-nr__history-panel');
    const scroll = document.querySelector('.lml-hr-cc-nr__table-scroll');
    const table = document.querySelector('.lml-hr-cc-nr__table--deworming');
    const pr = panel.getBoundingClientRect();
    const sr = scroll.getBoundingClientRect();
    const tr = table.getBoundingClientRect();
    const cols = [...table.querySelectorAll('thead th')].map((el) => {
        const r = el.getBoundingClientRect();
        return {
            header: el.textContent.trim(),
            x: Math.round(r.x),
            width: Math.round(r.width),
            pctOfTable: Number(((r.width / tr.width) * 100).toFixed(1)),
            align: getComputedStyle(el).textAlign,
        };
    });
    return {
        panelWidth: Math.round(pr.width),
        scrollWidth: Math.round(sr.width),
        tableWidth: Math.round(tr.width),
        tableX: Math.round(tr.x),
        tableRight: Math.round(tr.right),
        panelRight: Math.round(pr.right),
        unusedAfterTable: Math.round(pr.right - tr.right),
        cols,
    };
});

await page.goto('http://127.0.0.1:8000/health-records/child-care/non-residents/sofia-l-navarro?role=bhw', {
    waitUntil: 'networkidle',
});

const view = await page.evaluate(() => {
    const btn = document.querySelector('.lml-hr-cc-nr__view-btn');
    const cs = getComputedStyle(btn);
    const metrics = document.querySelector('.lml-hr-cc-nr__metrics');
    const mr = metrics.getBoundingClientRect();
    const rows = [...metrics.querySelectorAll(':scope > div')].map((row) => {
        const dt = row.querySelector('dt').getBoundingClientRect();
        const dd = row.querySelector('dd').getBoundingClientRect();
        return {
            label: row.querySelector('dt').textContent.trim(),
            labelX: Math.round(dt.x),
            valueRight: Math.round(dd.right),
            rowWidth: Math.round(row.getBoundingClientRect().width),
            justify: getComputedStyle(row).justifyContent,
            gap: Math.round(dd.x - dt.right),
        };
    });
    return {
        viewColor: cs.color,
        viewIconColor: getComputedStyle(btn.querySelector('i')).color,
        metricsWidth: Math.round(mr.width),
        rows,
    };
});

await page.goto('http://127.0.0.1:8000/health-records/child-care/non-residents/gabriel-allan-s-chua/nutrition?role=bhw', {
    waitUntil: 'networkidle',
});

const boxes = await page.evaluate(() => {
    const list = [...document.querySelectorAll('.lml-hr-cc-nr__age-box')];
    return list.map((box, i) => {
        const r = box.getBoundingClientRect();
        const next = list[i + 1]?.getBoundingClientRect();
        const cs = getComputedStyle(box);
        return {
            title: box.querySelector('.lml-hr-cc-nr__history-group')?.textContent.trim(),
            border: `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}`,
            y: Math.round(r.y),
            height: Math.round(r.height),
            gapToNext: next ? Math.round(next.y - r.bottom) : null,
        };
    });
});

console.log(JSON.stringify({ dew, view, boxes }, null, 2));
await browser.close();

import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-ui-patch');

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();

async function collectFacts(url, w, h) {
    await page.setViewportSize({ width: w, height: h });
    await page.goto(url, { waitUntil: 'networkidle' });
    return page.evaluate(() => {
        const facts = document.querySelector('.lml-hr-cc-nr__facts');
        if (!facts) {
            return { error: 'no facts' };
        }
        const style = getComputedStyle(facts);
        const dts = [...facts.querySelectorAll('dt')].map((el) => {
            const r = el.getBoundingClientRect();
            return { text: el.textContent.trim(), x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width) };
        });
        const dds = [...facts.querySelectorAll('dd')].map((el) => {
            const r = el.getBoundingClientRect();
            return { text: el.textContent.trim(), x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width) };
        });
        return {
            gridTemplateColumns: style.gridTemplateColumns,
            maxWidth: style.maxWidth,
            display: style.display,
            dtX: dts.map((d) => d.x),
            ddX: dds.map((d) => d.x),
            dtY: dts.map((d) => d.y),
            rows: dts.map((dt, i) => ({
                label: dt.text,
                value: dds[i]?.text,
                labelX: dt.x,
                valueX: dds[i]?.x,
                labelY: dt.y,
                valueY: dds[i]?.y,
                sameRow: Math.abs(dt.y - (dds[i]?.y ?? 0)) < 8,
            })),
        };
    });
}

async function collectDeworming() {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya/deworming?role=bhw', {
        waitUntil: 'networkidle',
    });
    return page.evaluate(() => {
        const table = document.querySelector('.lml-hr-cc-nr__table--deworming');
        if (!table) {
            return { error: 'no table' };
        }
        const headers = [...table.querySelectorAll('thead th')].map((el) => {
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            return {
                text: el.textContent.trim(),
                x: Math.round(r.x),
                w: Math.round(r.width),
                center: Math.round(r.x + r.width / 2),
                align: cs.textAlign,
            };
        });
        const cells = [...table.querySelectorAll('tbody tr:first-child td')].map((el) => {
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            return {
                text: el.textContent.trim(),
                x: Math.round(r.x),
                w: Math.round(r.width),
                center: Math.round(r.x + r.width / 2),
                align: cs.textAlign,
            };
        });
        return {
            headers,
            cells,
            centerDelta: headers.map((h, i) => ({
                col: h.text,
                headerCenter: h.center,
                cellCenter: cells[i]?.center,
                delta: (cells[i]?.center ?? 0) - h.center,
                headerAlign: h.align,
                cellAlign: cells[i]?.align,
            })),
        };
    });
}

async function collectEdit() {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya/nutrition/NR-CC-NUT-AND-001/edit?role=bhw', {
        waitUntil: 'networkidle',
    });
    return page.evaluate(() => {
        const actions = document.querySelector('.lml-hr-cc-nr__form-actions');
        return {
            texts: actions ? actions.innerText.replace(/\s+/g, ' ').trim() : null,
            hasDelete: !!document.querySelector('[data-hr-cc-nr-measure-delete]'),
            hasCancel: !!document.querySelector('[data-hr-cc-nr-cancel]'),
            hasSave: !!document.querySelector('[data-hr-cc-nr-save]'),
        };
    });
}

async function collectCrisleySummary() {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('http://127.0.0.1:8000/health-records/child-care/non-residents/crisley-f-fernando?role=bhw', {
        waitUntil: 'networkidle',
    });
    await page.screenshot({ path: path.join(outDir, 'B-view-crisley-first-record-1440.png'), fullPage: true });
    return page.evaluate(() => {
        const metrics = document.querySelector('[data-hr-cc-nr-nutrition-summary="first"]');
        return metrics ? metrics.innerText.replace(/\s+/g, ' ').trim() : null;
    });
}

const report = {
    facts1440: await collectFacts('http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya?role=bhw', 1440, 900),
    facts820: await collectFacts('http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya?role=bhw', 820, 1180),
    facts390: await collectFacts('http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya?role=bhw', 390, 844),
    deworming: await collectDeworming(),
    edit: await collectEdit(),
    crisleySummary: await collectCrisleySummary(),
};

await browser.close();
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));

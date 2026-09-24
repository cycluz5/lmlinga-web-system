/**
 * Health Records → Risk Assessment targeted UI refinement evidence.
 * Captures 1440 / 820 / 390 and records page-level overflow.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-risk-assessment-ui-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.RA_CAPTURE_BASE || 'http://127.0.0.1:8765';
const url = `${base}/health-records/risk-assessment`;

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({
  headless: true,
  channel: process.env.RA_CAPTURE_CHANNEL || 'msedge',
});
const page = await browser.newPage();

async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const root = document.querySelector('[data-lml-hr-risk]');
    const search = document.querySelector('[data-hr-ra-search]');
    const zone = document.querySelector('[data-hr-ra-zone]');
    const year = document.querySelector('[data-hr-ra-year]');
    const exportBtn = document.querySelector('[data-hr-ra-export]');
    const addBtn = document.querySelector('[data-hr-ra-add]');
    const headers = Array.from(
      document.querySelectorAll('.lml-hr-risk__table thead .lml-hr-risk__th-main')
    ).map((el) => el.textContent.trim());
    const bmiTh = Array.from(document.querySelectorAll('.lml-hr-risk__table thead th')).find(
      (th) => th.querySelector('.lml-hr-risk__th-main')?.textContent.trim() === 'BMI Status'
    );
    const nameTh = Array.from(document.querySelectorAll('.lml-hr-risk__table thead th')).find(
      (th) => th.querySelector('.lml-hr-risk__th-main')?.textContent.trim() === 'Full Name'
    );
    const bpTh = Array.from(document.querySelectorAll('.lml-hr-risk__table thead th')).find(
      (th) => th.querySelector('.lml-hr-risk__th-main')?.textContent.trim() === 'BP Status'
    );

    const box = (el) => {
      if (!el) {
        return null;
      }
      const r = el.getBoundingClientRect();
      return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
    };

    return {
      scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
      clientWidth: doc.clientWidth,
      overflow: Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1,
      hasRoot: Boolean(root),
      hasAdd: Boolean(addBtn),
      hasExport: Boolean(exportBtn),
      headers,
      search: box(search),
      zone: box(zone),
      year: box(year),
      exportBtn: box(exportBtn),
      nameTh: box(nameTh),
      bmiTh: box(bmiTh),
      bpTh: box(bpTh),
    };
  });
}

const report = [];

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-risk]');
  const metrics = await measure(page);
  const file = `risk-assessment-${vp.name}-ui-refinement.png`;
  await page.screenshot({
    path: path.join(outDir, file),
    fullPage: false,
  });
  report.push({ viewport: vp.name, file, ...metrics });
  console.log(
    JSON.stringify(
      {
        viewport: vp.name,
        overflow: metrics.overflow,
        hasAdd: metrics.hasAdd,
        hasExport: metrics.hasExport,
        searchW: metrics.search?.w,
        zoneW: metrics.zone?.w,
        yearW: metrics.year?.w,
        bmiSeparated:
          metrics.nameTh && metrics.bmiTh && metrics.bpTh
            ? metrics.bmiTh.x > metrics.nameTh.x && metrics.bpTh.x > metrics.bmiTh.x
            : false,
      },
      null,
      0
    )
  );
}

fs.writeFileSync(
  path.join(outDir, 'layout-measurements.json'),
  JSON.stringify(report, null, 2)
);

const overflowing = report.filter((row) => row.overflow);
const addPresent = report.some((row) => row.hasAdd);
if (overflowing.length > 0 || addPresent) {
  console.error('REFINEMENT CHECK FAILED', { overflowing, addPresent });
  await browser.close();
  process.exit(1);
}

console.log('saved measurements', path.join(outDir, 'layout-measurements.json'));
await browser.close();

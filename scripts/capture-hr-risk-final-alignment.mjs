/**
 * Health Records → Risk Assessment final Figma listing alignment evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-risk-assessment-final-alignment'
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
    const box = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
    };

    const search = document.querySelector('[data-hr-ra-search]');
    const zone = document.querySelector('[data-hr-ra-zone]');
    const year = document.querySelector('[data-hr-ra-year]');
    const filters = document.querySelector('.lml-hr-risk__filters');
    const filtersW = filters?.getBoundingClientRect().width || 0;
    const gap = 12; // 0.75rem approx at default
    const usable = filtersW > 0 ? filtersW - gap * 2 : 0;

    const ths = Array.from(document.querySelectorAll('.lml-hr-risk__table thead th'));
    const headers = ths.map((th) => {
      const main = th.querySelector('.lml-hr-risk__th-main');
      const sub = th.querySelector('.lml-hr-risk__th-sub');
      const subStyle = sub ? getComputedStyle(sub) : null;
      return {
        title: main?.textContent.trim() || '',
        hasSub: Boolean(sub),
        borderTop: subStyle ? `${subStyle.borderTopWidth} ${subStyle.borderTopStyle}` : null,
        width: Math.round(th.getBoundingClientRect().width),
      };
    });

    const nameW = headers[0]?.width || 0;
    const statusWs = headers.slice(1).map((h) => h.width);
    const tableW = document.querySelector('.lml-hr-risk__table')?.getBoundingClientRect().width || 0;

    return {
      scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
      clientWidth: doc.clientWidth,
      overflow: Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1,
      hasAdd: Boolean(document.querySelector('[data-hr-ra-add]')),
      hasExport: Boolean(document.querySelector('[data-hr-ra-export]')),
      search: box(search),
      zone: box(zone),
      year: box(year),
      filterPct:
        usable > 0 && search && zone && year
          ? {
              search: Number(((search.getBoundingClientRect().width / usable) * 100).toFixed(1)),
              zone: Number(((zone.getBoundingClientRect().width / usable) * 100).toFixed(1)),
              year: Number(((year.getBoundingClientRect().width / usable) * 100).toFixed(1)),
            }
          : null,
      headers,
      namePct: tableW ? Number(((nameW / tableW) * 100).toFixed(1)) : null,
      statusPctAvg: tableW && statusWs.length
        ? Number(((statusWs.reduce((a, b) => a + b, 0) / statusWs.length / tableW) * 100).toFixed(1))
        : null,
      verticalBorder: getComputedStyle(ths[0] || document.body).borderRightWidth,
      bodyVerticalBorder: getComputedStyle(
        document.querySelector('.lml-hr-risk__table tbody td') || document.body
      ).borderRightWidth,
    };
  });
}

const report = [];

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-risk]');
  const metrics = await measure(page);
  const file = `risk-assessment-${vp.name}-final-alignment.png`;
  await page.screenshot({ path: path.join(outDir, file), fullPage: false });

  if (vp.width === 1440) {
    await page.locator('.lml-hr-risk__table thead').screenshot({
      path: path.join(outDir, 'risk-assessment-1440x900-header-crop.png'),
    });
  }

  report.push({ viewport: vp.name, file, ...metrics });
  console.log(
    JSON.stringify({
      viewport: vp.name,
      overflow: metrics.overflow,
      hasAdd: metrics.hasAdd,
      filterPct: metrics.filterPct,
      namePct: metrics.namePct,
      statusPctAvg: metrics.statusPctAvg,
      fullNameHasSub: metrics.headers[0]?.hasSub,
      statusSeparators: metrics.headers.slice(1).every((h) => h.hasSub && h.borderTop?.startsWith('1px')),
      verticalBorder: metrics.verticalBorder,
    })
  );
}

fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));

const failing = report.filter((row) => {
  const separatorsOk =
    row.headers[0]?.hasSub === false &&
    row.headers.slice(1).every((h) => h.hasSub && h.borderTop?.startsWith('1px'));
  return row.overflow || row.hasAdd || !separatorsOk;
});

if (failing.length > 0) {
  console.error('FINAL ALIGNMENT CHECK FAILED', JSON.stringify(failing, null, 2));
  await browser.close();
  process.exit(1);
}

console.log('saved', path.join(outDir, 'layout-measurements.json'));
await browser.close();

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const base = process.env.LML_BASE_URL || 'http://127.0.0.1:8000';
const roleQ = 'role=bhw';

const pages = [
  { key: 'deworming-summary', path: '/health-records/child-care/deworming' },
  { key: 'deworming-individual', path: '/health-records/child-care/deworming/kristine-b-reyes' },
  { key: 'deworming-add', path: '/health-records/child-care/deworming/kristine-b-reyes/create' },
];

const viewports = [
  { w: 1440, h: 900 },
  { w: 1366, h: 768 },
  { w: 820, h: 1180 },
  { w: 390, h: 844 },
];

const measurements = [];

const browser = await chromium.launch({
  channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
  headless: true,
});
const context = await browser.newContext();
const page = await context.newPage();

for (const spec of pages) {
  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.w, height: vp.h });
    await page.goto(`${base}${spec.path}?${roleQ}`, { waitUntil: 'networkidle' });
    const file = `${spec.key}-${vp.w}x${vp.h}.png`;
    await page.screenshot({ path: path.join(__dirname, file), fullPage: false });
    const metrics = await page.evaluate(() => {
      const el = document.documentElement;
      const title = document.querySelector('.lml-topbar__title')?.textContent?.trim() ?? null;
      const pills = document.querySelector('.lml-hr-child-care__nav-pills');
      const dashCells = Array.from(document.querySelectorAll('td, dd')).filter((node) =>
        /^(\s*[—\-–]{1,3}\s*)$/u.test(node.textContent || '')
      );
      return {
        title,
        clientWidth: el.clientWidth,
        scrollWidth: el.scrollWidth,
        overflow: el.scrollWidth > el.clientWidth + 1,
        hasServicePills: Boolean(pills),
        hasNrPill: Boolean(document.querySelector('[data-hr-cc-non-residents]')),
        dashPlaceholderCount: dashCells.length,
        viewCount: document.querySelectorAll('[data-hr-dw-view]').length,
      };
    });
    measurements.push({ page: spec.key, file, w: vp.w, h: vp.h, ...metrics });
  }
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(`${base}/health-records/child-care/deworming?${roleQ}`, { waitUntil: 'networkidle' });
await page.screenshot({
  path: path.join(__dirname, 'deworming-summary-all-views-1440x900.png'),
  fullPage: false,
});

fs.writeFileSync(path.join(__dirname, 'layout-measurements.json'), JSON.stringify(measurements, null, 2));
await browser.close();
console.log(JSON.stringify(measurements, null, 2));

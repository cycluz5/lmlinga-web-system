/**
 * Review-package evidence capture only. Not production code.
 * Health Records → Family Planning after Non-Residents UI removal.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(__dirname, '../04-screenshots');
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_CAPTURE_BASE || 'http://127.0.0.1:8765';
const url = `${base}/health-records/family-planning?role=bns`;

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({
  channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
  headless: true,
});
const page = await browser.newPage();
const measurements = [];

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-fp]');

  const metrics = await page.evaluate(() => {
    const el = document.documentElement;
    const body = document.body;
    const html = document.body?.innerHTML ?? '';
    const tableScroll = document.querySelector('.lml-hr-fp__table-scroll, .lml-hr-fp__table-wrap');
    return {
      heading: document.querySelector('#lml-hr-fp-heading')?.textContent?.trim() ?? null,
      hasTitleRow: Boolean(document.querySelector('.lml-hr-fp__title-row')),
      hasBadge: Boolean(document.querySelector('.lml-hr-fp__badge')),
      hasNrText: /Non\s*-\s*Residents Client/i.test(html),
      hasNrAria: /Open Non-Residents Client listing/i.test(html),
      hasTablist: Boolean(document.querySelector('[data-lml-hr-fp] [role="tablist"]')),
      hasTab: Boolean(document.querySelector('[data-lml-hr-fp] [role="tab"]')),
      hasAdd: Boolean(document.querySelector('[data-hr-fp-add]')),
      hasExport: Boolean(document.querySelector('[data-hr-fp-export]')),
      hasSearch: Boolean(document.querySelector('[data-hr-fp-search]')),
      sidebarFpActive: Boolean(
        document.querySelector('.lml-sidebar__sublink--active[aria-current="page"]')
      ),
      clientWidth: el.clientWidth,
      scrollWidth: Math.max(el.scrollWidth, body.scrollWidth),
      pageOverflow: Math.max(el.scrollWidth, body.scrollWidth) > el.clientWidth + 1,
      tableScrollClientWidth: tableScroll?.clientWidth ?? null,
      tableScrollScrollWidth: tableScroll?.scrollWidth ?? null,
    };
  });

  const file = `family-planning-${vp.name}.png`;
  await page.screenshot({ path: path.join(outDir, file), fullPage: false });

  measurements.push({
    viewportWidth: vp.width,
    viewportHeight: vp.height,
    screenshot: file,
    ...metrics,
  });
}

const jsonPath = path.join(outDir, 'layout-measurements.json');
fs.writeFileSync(jsonPath, JSON.stringify(measurements, null, 2));
fs.writeFileSync(
  path.join(__dirname, 'layout-measurements.json'),
  JSON.stringify(measurements, null, 2)
);
await browser.close();
console.log(JSON.stringify(measurements, null, 2));

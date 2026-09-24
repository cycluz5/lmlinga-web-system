import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const base = process.env.LML_BASE_URL || 'http://127.0.0.1:8000';
const roleQ = 'role=bhw';

const pages = [
  { key: 'summary', path: '/health-records/child-care' },
  { key: 'vitamin-a', path: '/health-records/child-care/vitamin-a' },
  { key: 'deworming', path: '/health-records/child-care/deworming' },
  { key: 'operation-timbang', path: '/health-records/child-care/operation-timbang' },
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
      const pill = document.querySelector('[data-hr-cc-non-residents], .lml-hr-child-care__scope-pill');
      const title = document.querySelector('.lml-hr-child-care__title');
      const cluster = document.querySelector('.lml-hr-child-care__title-cluster');
      return {
        title: title?.textContent?.trim() ?? null,
        hasNrPill: Boolean(pill),
        hasTitleCluster: Boolean(cluster),
        clientWidth: el.clientWidth,
        scrollWidth: el.scrollWidth,
        overflow: el.scrollWidth > el.clientWidth + 1,
      };
    });
    measurements.push({ page: spec.key, file, w: vp.w, h: vp.h, ...metrics });
  }
}

fs.writeFileSync(path.join(__dirname, 'layout-measurements.json'), JSON.stringify(measurements, null, 2));
await browser.close();
console.log(JSON.stringify(measurements, null, 2));

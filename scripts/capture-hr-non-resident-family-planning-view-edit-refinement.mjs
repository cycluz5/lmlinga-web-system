/**
 * View Client + Edit Visit Figma refinement evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-view-edit-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_NR_CAPTURE_BASE || 'http://127.0.0.1:8765';
const role = '?role=bns';

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });

async function capture(page, filename) {
  const file = path.join(outDir, filename);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`saved ${file}`);
}

async function measureShow(page) {
  return page.evaluate(() => {
    const historyWrap = document.querySelector('.lml-hr-fp-nr__detail-table-wrap');
    const historyTable = document.querySelector('.lml-hr-fp-nr__detail-table--history');
    const commoditiesTable = document.querySelector('.lml-hr-fp-nr__detail-table--commodities');
    const infoRow = document.querySelector('.lml-hr-fp-nr__info-row');

    const scrollInfo = (el) => {
      if (!el) return null;
      const styles = getComputedStyle(el);
      return {
        overflowY: styles.overflowY,
        maxHeight: styles.maxHeight,
        scrollHeight: el.scrollHeight,
        clientHeight: el.clientHeight,
        hasInternalVerticalScroll: el.scrollHeight > el.clientHeight + 1,
      };
    };

    return {
      tableWrapPresent: historyWrap != null,
      historyScroll: scrollInfo(historyTable?.parentElement === historyTable ? historyTable : historyTable),
      commoditiesScroll: scrollInfo(commoditiesTable),
      infoRowGap: infoRow
        ? Math.round(Number.parseFloat(getComputedStyle(infoRow).columnGap || '0'))
        : null,
    };
  });
}

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(`${base}/health-records/family-planning/non-residents/roselyn-a-mendoza${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  await capture(page, `view-client-${vp.name}.png`);
  if (vp.name === '1440x900') {
    const metrics = await measureShow(page);
    fs.writeFileSync(
      path.join(outDir, 'layout-measurements.json'),
      `${JSON.stringify({ viewport: vp.name, ...metrics }, null, 2)}\n`
    );
  }
  await page.close();
}

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(
    `${base}/health-records/family-planning/non-residents/roselyn-a-mendoza/visits/NR-FP-001/edit${role}`,
    { waitUntil: 'networkidle', timeout: 60000 }
  );
  await capture(page, `edit-visit-${vp.name}.png`);
  await page.close();
}

await browser.close();

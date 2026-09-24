/**
 * View Individual page — Figma accuracy evidence (view only).
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-view-figma-refinement'
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

async function measure(page) {
  return page.evaluate(() => {
    const root = document.querySelector('[data-lml-hr-fp-nr-mode="show"]');
    const banner = document.querySelector('.lml-hr-fp-nr__client-banner');
    const panel = document.querySelector('.lml-hr-fp-nr__detail-panel');
    const icon = document.querySelector('.lml-hr-fp-nr__client-icon .bi');
    const heading = document.querySelector('.lml-hr-fp-nr__detail-heading');
    const infoDd = document.querySelector('.lml-hr-fp-nr__info-row dd');
    const historyTable = document.querySelector('.lml-hr-fp-nr__detail-table--history');

    const styles = (el) => (el ? getComputedStyle(el) : null);

    return {
      bannerBackground: styles(banner)?.backgroundColor ?? null,
      panelPaddingLeft: panel ? Math.round(Number.parseFloat(styles(panel).paddingLeft)) : null,
      panelBorderColor: styles(panel)?.borderTopColor ?? null,
      headingColor: styles(heading)?.color ?? null,
      bodyFontSize: infoDd ? styles(infoDd).fontSize : null,
      iconClass: icon?.className ?? null,
      iconBackground: styles(document.querySelector('.lml-hr-fp-nr__client-icon'))?.backgroundColor ?? null,
      historyTableWidth: historyTable ? Math.round(historyTable.getBoundingClientRect().width) : null,
      panelWidth: panel ? Math.round(panel.getBoundingClientRect().width) : null,
      rootPresent: root != null,
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
  const file = path.join(outDir, `view-client-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`saved ${file}`);

  if (vp.name === '1440x900') {
    const metrics = await measure(page);
    fs.writeFileSync(
      path.join(outDir, 'layout-measurements.json'),
      `${JSON.stringify({ viewport: vp.name, ...metrics }, null, 2)}\n`
    );
  }

  await page.close();
}

await browser.close();

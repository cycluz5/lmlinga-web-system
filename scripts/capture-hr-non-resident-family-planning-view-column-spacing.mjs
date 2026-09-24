/**
 * View Individual — label/value column spacing evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-view-column-spacing'
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
    const doc = document.documentElement;
    const body = document.body;
    const pageOverflow =
      Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1;

    const infoRow = document.querySelector('.lml-hr-fp-nr__info-row');
    const infoDt = infoRow?.querySelector('dt');
    const infoDd = infoRow?.querySelector('dd');
    const panel = document.querySelector('.lml-hr-fp-nr__detail-panel');
    const historyTable = document.querySelector('.lml-hr-fp-nr__detail-table--history');
    const commoditiesTable = document.querySelector('.lml-hr-fp-nr__detail-table--commodities');

    const gapBetween = (leftEl, rightEl) => {
      if (!leftEl || !rightEl) return null;
      const l = leftEl.getBoundingClientRect();
      const r = rightEl.getBoundingClientRect();
      return Math.round(r.left - l.right);
    };

    const historyHeader = historyTable?.querySelectorAll('thead th');
    const historyRow = historyTable?.querySelector('tbody tr');
    const historyCells = historyRow?.querySelectorAll('td');

    const commHeader = commoditiesTable?.querySelectorAll('thead th');
    const commRow = commoditiesTable?.querySelector('tbody tr');
    const commCells = commRow?.querySelectorAll('td');

    return {
      pageOverflow,
      panelWidth: panel ? Math.round(panel.getBoundingClientRect().width) : null,
      panelPaddingLeft: panel
        ? Math.round(Number.parseFloat(getComputedStyle(panel).paddingLeft))
        : null,
      clientInfoColumnGap: infoRow
        ? Math.round(Number.parseFloat(getComputedStyle(infoRow).columnGap))
        : null,
      clientInfoLabelToValueGap: gapBetween(infoDt, infoDd),
      visitDateToRemarksGap: gapBetween(historyCells?.[0], historyCells?.[1]),
      remarksToEditGap: gapBetween(historyCells?.[1], historyCells?.[2]),
      historyTableWidth: historyTable
        ? Math.round(historyTable.getBoundingClientRect().width)
        : null,
      commodityToQuantityGap: gapBetween(commCells?.[0], commCells?.[1]),
      quantityToDateGap: gapBetween(commCells?.[1], commCells?.[2]),
      commoditiesTableWidth: commoditiesTable
        ? Math.round(commoditiesTable.getBoundingClientRect().width)
        : null,
      previousClientInfoColumnGapPx: 8,
      previousVisitDatePaddingRightPx: 16,
      previousCommodityPaddingRightPx: 12,
    };
  });
}

const measurements = [];

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(`${base}/health-records/family-planning/non-residents/roselyn-a-mendoza${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  const file = path.join(outDir, `view-client-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  const metrics = await measure(page);
  measurements.push({ viewport: vp.name, ...metrics });
  console.log(`saved ${file} infoGap=${metrics.clientInfoLabelToValueGap}`);
  await page.close();
}

fs.writeFileSync(
  path.join(outDir, 'layout-measurements.json'),
  `${JSON.stringify(measurements, null, 2)}\n`
);

await browser.close();

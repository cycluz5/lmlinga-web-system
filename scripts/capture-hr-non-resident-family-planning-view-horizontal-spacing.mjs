/**
 * View Individual — Figma horizontal information spacing evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-view-horizontal-spacing'
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

    const panel = document.querySelector('.lml-hr-fp-nr__detail-panel');
    const panelLeft = panel ? panel.getBoundingClientRect().left : 0;

    const contentStartX = (el) => {
      if (!el) return null;
      const range = document.createRange();
      range.selectNodeContents(el);
      const rects = range.getClientRects();
      if (rects.length > 0) {
        return Math.round(rects[0].left - panelLeft);
      }
      return Math.round(el.getBoundingClientRect().left - panelLeft);
    };

    const infoRows = [...document.querySelectorAll('.lml-hr-fp-nr__info-row')];
    const firstRow = infoRows[0];
    const infoDt = firstRow?.querySelector('dt');
    const infoDd = firstRow?.querySelector('dd');
    const valueStarts = infoRows.map((row) => contentStartX(row.querySelector('dd')));
    const labelStarts = infoRows.map((row) => contentStartX(row.querySelector('dt')));

    const historyTable = document.querySelector('.lml-hr-fp-nr__detail-table--history');
    const historyRow = historyTable?.querySelector('tbody tr');
    const historyCells = historyRow ? [...historyRow.querySelectorAll('td')] : [];
    const historyHeaders = historyTable
      ? [...historyTable.querySelectorAll('thead th')]
      : [];

    const commoditiesTable = document.querySelector(
      '.lml-hr-fp-nr__detail-table--commodities'
    );
    const commRow = commoditiesTable?.querySelector('tbody tr');
    const commCells = commRow ? [...commRow.querySelectorAll('td')] : [];
    const commHeaders = commoditiesTable
      ? [...commoditiesTable.querySelectorAll('thead th')]
      : [];

    const labelStart = contentStartX(infoDt);
    const valueStart = contentStartX(infoDd);
    const visitDateStart = contentStartX(historyHeaders[0] || historyCells[0]);
    const remarksStart = contentStartX(historyHeaders[1] || historyCells[1]);
    const editStart = contentStartX(
      historyCells[2]?.querySelector('a, button, i') || historyCells[2] || historyHeaders[2]
    );
    const commodityStart = contentStartX(commHeaders[0] || commCells[0]);
    const quantityStart = contentStartX(commHeaders[1] || commCells[1]);
    const dateGivenStart = contentStartX(commHeaders[2] || commCells[2]);

    return {
      pageOverflow,
      panelWidth: panel ? Math.round(panel.getBoundingClientRect().width) : null,
      panelPaddingLeft: panel
        ? Math.round(Number.parseFloat(getComputedStyle(panel).paddingLeft))
        : null,
      clientInfoColumnGap: firstRow
        ? Math.round(Number.parseFloat(getComputedStyle(firstRow).columnGap) || 0)
        : null,
      clientInfoLabelStart: labelStart,
      clientInfoValueStart: valueStart,
      clientInfoLabelToValueGap:
        labelStart !== null && valueStart !== null ? valueStart - labelStart : null,
      clientInfoLabelTextToValueGap: (() => {
        if (!infoDt || valueStart === null) return null;
        const range = document.createRange();
        range.selectNodeContents(infoDt);
        const rects = range.getClientRects();
        if (!rects.length) return null;
        return Math.round(valueStart - (rects[0].right - panelLeft));
      })(),
      clientInfoAllLabelStarts: labelStarts,
      clientInfoAllValueStarts: valueStarts,
      visitDateStart,
      remarksStart,
      editActionStart: editStart,
      visitDateToRemarksGap:
        visitDateStart !== null && remarksStart !== null
          ? remarksStart - visitDateStart
          : null,
      remarksToEditGap:
        remarksStart !== null && editStart !== null ? editStart - remarksStart : null,
      historyTableWidth: historyTable
        ? Math.round(historyTable.getBoundingClientRect().width)
        : null,
      commodityStart,
      quantityStart,
      dateGivenStart,
      commodityToQuantityGap:
        commodityStart !== null && quantityStart !== null
          ? quantityStart - commodityStart
          : null,
      quantityToDateGap:
        quantityStart !== null && dateGivenStart !== null
          ? dateGivenStart - quantityStart
          : null,
      commoditiesTableWidth: commoditiesTable
        ? Math.round(commoditiesTable.getBoundingClientRect().width)
        : null,
      previousClientInfoColumnGapPx: 32,
      previousHistoryTableWidthPx: 384,
      previousCommoditiesTableWidthPx: 356,
    };
  });
}

const measurements = [];

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(
    `${base}/health-records/family-planning/non-residents/roselyn-a-mendoza${role}`,
    {
      waitUntil: 'networkidle',
      timeout: 60000,
    }
  );
  const file = path.join(outDir, `view-client-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  const metrics = await measure(page);
  measurements.push({ viewport: vp.name, ...metrics });
  console.log(
    `saved ${file} label→value=${metrics.clientInfoLabelTextToValueGap}px historyW=${metrics.historyTableWidth}`
  );
  await page.close();
}

fs.writeFileSync(
  path.join(outDir, 'layout-measurements.json'),
  `${JSON.stringify(measurements, null, 2)}\n`
);

await browser.close();

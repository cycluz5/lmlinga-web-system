/**
 * View Individual — final desktop column-alignment evidence.
 * Main value column: Client values / Remarks / Quantity
 * Right column: Date Given / Edit icon
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-view-final-alignment'
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
    if (!panel) return { pageOverflow, error: 'no panel' };

    const panelRect = panel.getBoundingClientRect();
    const padLeft = Number.parseFloat(getComputedStyle(panel).paddingLeft) || 0;
    const contentLeft = panelRect.left + padLeft;
    const contentWidth =
      panelRect.width - padLeft - (Number.parseFloat(getComputedStyle(panel).paddingRight) || 0);

    const rel = (el) => {
      if (!el) return null;
      const range = document.createRange();
      range.selectNodeContents(el);
      const rects = range.getClientRects();
      const left = rects.length ? rects[0].left : el.getBoundingClientRect().left;
      const x = Math.round(left - contentLeft);
      return {
        x,
        ratio: contentWidth > 0 ? Number((x / contentWidth).toFixed(4)) : null,
      };
    };

    const infoRows = [...document.querySelectorAll('.lml-hr-fp-nr__info-row')];
    const first = infoRows[0];
    const label = rel(first?.querySelector('dt'));
    const value = rel(first?.querySelector('dd'));
    const heading = rel(document.querySelector('.lml-hr-fp-nr__detail-heading'));

    const history = document.querySelector('.lml-hr-fp-nr__detail-table--history');
    const histTh = history ? [...history.querySelectorAll('thead th')] : [];
    const histTd = history?.querySelector('tbody tr')
      ? [...history.querySelector('tbody tr').querySelectorAll('td')]
      : [];

    const commodities = document.querySelector('.lml-hr-fp-nr__detail-table--commodities');
    const commTh = commodities ? [...commodities.querySelectorAll('thead th')] : [];
    const commTd = commodities?.querySelector('tbody tr')
      ? [...commodities.querySelector('tbody tr').querySelectorAll('td')]
      : [];

    const sectionBorders = [
      ...document.querySelectorAll('.lml-hr-fp-nr__detail-block + .lml-hr-fp-nr__detail-block'),
    ].map((el) => getComputedStyle(el).borderTopWidth);

    const historyHeaderBg = history
      ? getComputedStyle(history.querySelector('thead th')).backgroundColor
      : null;

    const clientInfoValueStart = value;
    const remarksHeaderStart = rel(histTh[1]);
    const remarksValueStart = rel(histTd[1]);
    const quantityHeaderStart = rel(commTh[1]);
    const quantityValueStart = rel(commTd[1]);
    const dateGivenHeaderStart = rel(commTh[2]);
    const dateGivenValueStart = rel(commTd[2]);
    const editActionStart = rel(
      histTd[2]?.querySelector('a, button, i') || histTd[2] || histTh[2]
    );

    const mainXs = [
      clientInfoValueStart?.x,
      remarksHeaderStart?.x,
      remarksValueStart?.x,
      quantityHeaderStart?.x,
      quantityValueStart?.x,
    ].filter((n) => typeof n === 'number');
    const mainAligned =
      mainXs.length === 5 && Math.max(...mainXs) - Math.min(...mainXs) <= 2;

    const dateXs = [dateGivenHeaderStart?.x, dateGivenValueStart?.x].filter(
      (n) => typeof n === 'number'
    );
    const dateAligned =
      dateXs.length === 2 && Math.max(...dateXs) - Math.min(...dateXs) <= 2;

    return {
      pageOverflow,
      panelWidth: Math.round(panelRect.width),
      panelPaddingLeft: Math.round(padLeft),
      contentWidth: Math.round(contentWidth),
      headingStart: heading,
      clientInfoLabelStart: label,
      clientInfoValueStart,
      clientInfoValueAnchorPx: clientInfoValueStart?.x ?? null,
      clientInfoAllValueStarts: infoRows.map((r) => rel(r.querySelector('dd'))?.x),
      visitDateStart: rel(histTh[0] || histTd[0]),
      remarksHeaderStart,
      remarksValueStart,
      remarksStart: remarksHeaderStart,
      editActionStart,
      historyTableWidth: history ? Math.round(history.getBoundingClientRect().width) : null,
      historyTableRightEdge: history
        ? Math.round(history.getBoundingClientRect().right - contentLeft)
        : null,
      commodityStart: rel(commTh[0]),
      quantityHeaderStart,
      quantityValueStart,
      quantityStart: quantityHeaderStart,
      dateGivenHeaderStart,
      dateGivenValueStart,
      dateGivenStart: dateGivenHeaderStart,
      commoditiesTableWidth: commodities
        ? Math.round(commodities.getBoundingClientRect().width)
        : null,
      commoditiesTableRightEdge: commodities
        ? Math.round(commodities.getBoundingClientRect().right - contentLeft)
        : null,
      sectionBorderTopWidths: sectionBorders,
      historyHeaderBackground: historyHeaderBg,
      passMainValueColumn: mainAligned,
      passDateGivenColumn: dateAligned,
      beforeSnapshot: {
        clientInfoValueStartPx: 401,
        remarks: 401,
        quantity: 201,
        dateGiven: 401,
        edit: 533,
        note: 'prior date-anchor pass (Quantity mid-track; Date Given on main)',
      },
    };
  });
}

const measurements = [];

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(
    `${base}/health-records/family-planning/non-residents/roselyn-a-mendoza${role}`,
    { waitUntil: 'networkidle', timeout: 60000 }
  );
  const file = path.join(outDir, `view-client-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  const metrics = await measure(page);
  measurements.push({ viewport: vp.name, ...metrics });
  console.log(
    `saved ${file} valueX=${metrics.clientInfoValueAnchorPx} qtyX=${metrics.quantityHeaderStart?.x} dateX=${metrics.dateGivenHeaderStart?.x} editX=${metrics.editActionStart?.x} mainPass=${metrics.passMainValueColumn} datePass=${metrics.passDateGivenColumn}`
  );
  await page.close();
}

fs.writeFileSync(
  path.join(outDir, 'layout-measurements.json'),
  `${JSON.stringify(measurements, null, 2)}\n`
);

await browser.close();

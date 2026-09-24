/**
 * Non-Resident Family Planning listing density + pagination evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-list-density-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_NR_CAPTURE_BASE || 'http://127.0.0.1:8765';
const listingPath = `/health-records/family-planning/non-residents?role=bns`;

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

    const filters = document.querySelector('.lml-hr-fp-nr__filters');
    const tableCard = document.querySelector('.lml-hr-fp-nr__table-card');
    const table = document.querySelector('.lml-hr-fp-nr__table');
    const headerCell = table?.querySelector('thead th');
    const firstRow = table?.querySelector('tbody tr');
    const firstCell = firstRow?.querySelector('th, td');
    const tbody = table?.querySelector('tbody');
    const footer = document.querySelector('.lml-hr-fp-nr__table-foot');
    const pager = document.querySelector('.lml-hr-fp-nr__pager');
    const prev = document.querySelector('[data-hr-fp-nr-page-prev]');
    const next = document.querySelector('[data-hr-fp-nr-page-next]');
    const current = document.querySelector('.lml-hr-fp-nr__pager-page[aria-current="page"]');
    const pageSize = document.querySelector('[data-hr-fp-nr-page-size]');
    const perPageText = Array.from(document.querySelectorAll('body *')).some((el) =>
      /\bper page\b/i.test(el.textContent || '') && el.children.length === 0
    );

    const fr = filters?.getBoundingClientRect();
    const tr = tableCard?.getBoundingClientRect();
    const tbodyRect = tbody?.getBoundingClientRect();
    const footerRect = footer?.getBoundingClientRect();
    const firstRect = firstRow?.getBoundingClientRect();
    const cellStyles = firstCell ? getComputedStyle(firstCell) : null;
    const headerStyles = headerCell ? getComputedStyle(headerCell) : null;

    return {
      pageOverflow,
      tableBodyFontSize: cellStyles ? cellStyles.fontSize : null,
      tableHeaderFontSize: headerStyles ? headerStyles.fontSize : null,
      firstRowHeight: firstRect ? Math.round(firstRect.height) : null,
      firstRowPaddingTop: cellStyles ? Math.round(Number.parseFloat(cellStyles.paddingTop)) : null,
      firstRowPaddingBottom: cellStyles ? Math.round(Number.parseFloat(cellStyles.paddingBottom)) : null,
      filterTableGap: fr && tr ? Math.round(tr.top - fr.bottom) : null,
      footerTableGap: footer
        ? Math.round(Number.parseFloat(getComputedStyle(footer).paddingTop))
        : null,
      paginationVisible: Boolean(pager && pager.getClientRects().length > 0),
      perPageControlPresent: Boolean(pageSize) || perPageText,
      currentPage: current?.textContent?.trim() ?? null,
      previousDisabled: prev instanceof HTMLButtonElement ? prev.disabled : null,
      nextDisabled: next instanceof HTMLButtonElement ? next.disabled : null,
    };
  });
}

const measurements = [];

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  const response = await page.goto(`${base}${listingPath}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  const ok = response?.ok() ?? false;
  const metrics = await measure(page);
  const file = path.join(outDir, `listing-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  measurements.push({
    viewport: vp.name,
    ok,
    ...metrics,
  });
  console.log(`saved ${file} ok=${ok} pageOverflow=${metrics.pageOverflow}`);
  await page.close();
}

const jsonPath = path.join(outDir, 'layout-measurements.json');
fs.writeFileSync(jsonPath, `${JSON.stringify(measurements, null, 2)}\n`);
console.log(`saved ${jsonPath}`);

await browser.close();

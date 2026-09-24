/**
 * Non-Resident Family Planning listing refinement — visual evidence + measurements.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-listing-refinement'
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

    const header = document.querySelector('[data-hr-fp-nr-listing-header]');
    const back = document.querySelector('[data-hr-fp-nr-back]');
    const add = document.querySelector('[data-hr-fp-nr-add]');
    const exp = document.querySelector('[data-hr-fp-nr-export]');
    const search = document.querySelector('.lml-hr-fp-nr__search');
    const barangay = document.querySelector('.lml-hr-fp-nr__select-wrap:not(.lml-hr-fp-nr__select-wrap--year)');
    const year = document.querySelector('.lml-hr-fp-nr__select-wrap--year');
    const filters = document.querySelector('.lml-hr-fp-nr__filters');
    const table = document.querySelector('.lml-hr-fp-nr__table-card');
    const pager = document.querySelector('.lml-hr-fp-nr__pager');
    const pageSize = document.querySelector('[data-hr-fp-nr-page-size]');
    const results = document.querySelector('[data-hr-fp-nr-results]');
    const panel = document.querySelector('.lml-hr-fp-nr__panel');

    const hr = header?.getBoundingClientRect();
    const br = back?.getBoundingClientRect();
    const ar = add?.getBoundingClientRect();
    const er = exp?.getBoundingClientRect();
    const sr = search?.getBoundingClientRect();
    const bar = barangay?.getBoundingClientRect();
    const yr = year?.getBoundingClientRect();
    const fr = filters?.getBoundingClientRect();
    const tr = table?.getBoundingClientRect();
    const pr = panel?.getBoundingClientRect();

    const filterStyles = filters ? getComputedStyle(filters) : null;
    const gapRaw = filterStyles?.columnGap || filterStyles?.gap || '0';
    const filterGap = Number.parseFloat(gapRaw) || 0;

    return {
      pageOverflow,
      actionRowSameLine: Boolean(br && ar && Math.abs(br.top - ar.top) < 8),
      backLeft: br ? Math.round(br.left) : null,
      addVisitRight: ar ? Math.round(ar.right) : null,
      exportRight: er ? Math.round(er.right) : null,
      searchWidth: sr ? Math.round(sr.width) : null,
      barangayWidth: bar ? Math.round(bar.width) : null,
      yearWidth: yr ? Math.round(yr.width) : null,
      filterGap: Math.round(filterGap),
      filterTableGap: fr && tr ? Math.round(tr.top - fr.bottom) : null,
      paginationVisible: Boolean(
        pager &&
          pageSize &&
          results &&
          pager.getClientRects().length > 0 &&
          pageSize.getClientRects().length > 0
      ),
      panelPaddingTop: panel ? Math.round(Number.parseFloat(getComputedStyle(panel).paddingTop)) : null,
      actionRowBottomGap: br && fr ? Math.round(fr.top - br.bottom) : null,
      headerJustify: header ? getComputedStyle(header).justifyContent : null,
      resultsText: results?.textContent?.trim() ?? null,
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

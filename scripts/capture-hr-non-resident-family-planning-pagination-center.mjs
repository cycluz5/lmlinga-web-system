/**
 * Non-Resident Family Planning pagination center-alignment evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-pagination-center'
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

    const footer = document.querySelector('.lml-hr-fp-nr__table-foot');
    const pager = document.querySelector('.lml-hr-fp-nr__pager');
    const summary = document.querySelector('[data-hr-fp-nr-results]');
    const prev = document.querySelector('[data-hr-fp-nr-page-prev]');
    const next = document.querySelector('[data-hr-fp-nr-page-next]');
    const current = document.querySelector('.lml-hr-fp-nr__pager-page[aria-current="page"]');
    const pageSize = document.querySelector('[data-hr-fp-nr-page-size]');
    const perPageText = Array.from(document.querySelectorAll('body *')).some(
      (el) => /\bper page\b/i.test(el.textContent || '') && el.children.length === 0
    );

    const footerRect = footer?.getBoundingClientRect();
    const pagerRect = pager?.getBoundingClientRect();
    const summaryRect = summary?.getBoundingClientRect();

    const footerCenterX = footerRect ? footerRect.left + footerRect.width / 2 : null;
    const paginationCenterX = pagerRect ? pagerRect.left + pagerRect.width / 2 : null;
    const paginationCenterOffset =
      footerCenterX != null && paginationCenterX != null
        ? Math.round(paginationCenterX - footerCenterX)
        : null;

    let paginationPosition = 'unknown';
    if (footerRect && pagerRect && paginationCenterOffset != null) {
      const rightEdgeGap = footerRect.right - pagerRect.right;
      if (Math.abs(paginationCenterOffset) <= 4) {
        paginationPosition = 'center';
      } else if (rightEdgeGap <= 24 && paginationCenterOffset > 4) {
        paginationPosition = 'right';
      } else if (pagerRect.left - footerRect.left <= 24 && paginationCenterOffset < -4) {
        paginationPosition = 'left';
      } else {
        paginationPosition = paginationCenterOffset > 0 ? 'right-of-center' : 'left-of-center';
      }
    }

    let entrySummaryPosition = 'unknown';
    if (footerRect && summaryRect) {
      const leftGap = summaryRect.left - footerRect.left;
      if (leftGap <= 24) {
        entrySummaryPosition = 'left';
      } else if (Math.abs(summaryRect.left + summaryRect.width / 2 - (footerRect.left + footerRect.width / 2)) <= 8) {
        entrySummaryPosition = 'center';
      } else {
        entrySummaryPosition = 'other';
      }
    }

    return {
      pageOverflow,
      footerWidth: footerRect ? Math.round(footerRect.width) : null,
      footerCenterX: footerCenterX != null ? Math.round(footerCenterX) : null,
      paginationWidth: pagerRect ? Math.round(pagerRect.width) : null,
      paginationCenterX: paginationCenterX != null ? Math.round(paginationCenterX) : null,
      paginationCenterOffset,
      paginationPosition,
      entrySummaryPosition,
      currentPage: current?.textContent?.trim() ?? null,
      previousDisabled: prev instanceof HTMLButtonElement ? prev.disabled : null,
      nextDisabled: next instanceof HTMLButtonElement ? next.disabled : null,
      perPageControlPresent: Boolean(pageSize) || perPageText,
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
  console.log(
    `saved ${file} ok=${ok} pageOverflow=${metrics.pageOverflow} pager=${metrics.paginationPosition} offset=${metrics.paginationCenterOffset}`
  );
  await page.close();
}

const jsonPath = path.join(outDir, 'layout-measurements.json');
fs.writeFileSync(jsonPath, `${JSON.stringify(measurements, null, 2)}\n`);
console.log(`saved ${jsonPath}`);

await browser.close();

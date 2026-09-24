/**
 * Maternal Care resident listing after removing Non-Residents nav (QA evidence).
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  process.env.MC_CAPTURE_OUT ||
    path.join(__dirname, '../docs/qa/screenshots/health-records-maternal-hide-nr-nav')
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.MC_CAPTURE_BASE || 'http://127.0.0.1:8765';
const listingPath = '/health-records/maternal?role=bns';

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const page = await browser.newPage();

async function checkOverflow(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const tableScroll = document.querySelector('.lml-hr-mc__table-scroll');
    const tableOverflow =
      tableScroll != null && tableScroll.scrollWidth > tableScroll.clientWidth + 1;
    return {
      viewportWidth: window.innerWidth,
      scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
      clientWidth: doc.clientWidth,
      pageLevelHorizontalOverflow:
        Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1,
      tableScrollWidth: tableScroll ? tableScroll.scrollWidth : null,
      tableClientWidth: tableScroll ? tableScroll.clientWidth : null,
      intentionalInternalTableScrolling: tableOverflow,
    };
  });
}

async function inspectNav(page) {
  return page.evaluate(() => {
    const pill = document.querySelector('[data-hr-mc-non-residents]');
    const tablist = document.querySelector('[role="tablist"]');
    const actionLeft = document.querySelector('.lml-hr-mc__action-left');
    const maternalActive = document.querySelector(
      '.lml-sidebar__sublink--active[aria-current="page"]'
    );
    const heading = document.querySelector('#lml-hr-mc-heading');
    const actionGroup = document.querySelector('[data-hr-mc-action-row] [role="group"]');
    const add = document.querySelector('[data-hr-mc-add]');
    const exp = document.querySelector('[data-hr-mc-export]');
    const nrText = Array.from(document.querySelectorAll('a, button')).some((el) =>
      /^\s*Non Residents\s*$/i.test(el.textContent || '')
    );
    const maternalHref = document.querySelector('.lml-sidebar__sublink--active[aria-current="page"]');
    return {
      nonResidentsControlPresent: Boolean(pill),
      nonResidentsVisibleLabelPresent: nrText,
      tablistPresent: Boolean(tablist),
      emptyActionLeft: Boolean(actionLeft) && (actionLeft.textContent || '').trim() === '',
      actionLeftPresent: Boolean(actionLeft),
      maternalSidebarText: maternalActive ? maternalActive.textContent.trim() : null,
      maternalSidebarHref: maternalHref ? maternalHref.getAttribute('href') : null,
      headingText: heading ? heading.textContent.trim() : null,
      actionGroupAriaLabel: actionGroup ? actionGroup.getAttribute('aria-label') : null,
      addAriaLabel: add ? add.getAttribute('aria-label') : null,
      exportAriaLabel: exp ? exp.getAttribute('aria-label') : null,
    };
  });
}

const overflowReport = [];
const navReport = {};

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(`${base}${listingPath}`, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-mc]');
  const overflow = await checkOverflow(page);
  overflowReport.push({ viewport: vp.name, ...overflow });
  navReport[vp.name] = await inspectNav(page);
  const file = path.join(outDir, `maternal-resident-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log(
    'saved',
    file,
    overflow.pageLevelHorizontalOverflow ? 'PAGE-OVERFLOW' : 'ok',
    overflow.intentionalInternalTableScrolling ? 'TABLE-SCROLL' : 'table-fits'
  );
}

await browser.close();
const payload = {
  capturedAt: new Date().toISOString(),
  url: `${base}${listingPath}`,
  overflow: overflowReport,
  nav: navReport,
};
fs.writeFileSync(path.join(outDir, 'measure-report.json'), JSON.stringify(payload, null, 2));
console.log(JSON.stringify(payload, null, 2));

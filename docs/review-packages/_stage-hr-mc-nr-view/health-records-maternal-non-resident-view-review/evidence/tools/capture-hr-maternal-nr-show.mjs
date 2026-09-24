/**
 * Non-Resident Maternal listing View + individual page evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-maternal-nr-show-figma'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.MC_CAPTURE_BASE || 'http://127.0.0.1:8765';

const pages = [
  { slug: 'listing', path: '/health-records/maternal/non-residents' },
  { slug: 'show-ana', path: '/health-records/maternal/non-residents/ana-p-villanueva' },
];

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

async function checkOverflow() {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    return {
      scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
      clientWidth: doc.clientWidth,
      overflow: Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1,
    };
  });
}

const report = [];

await page.goto(`${base}/health-records/maternal/non-residents?role=bns`, { waitUntil: 'load' });

for (const screen of pages) {
  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    try {
      await page.goto(`${base}${screen.path}`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    } catch (err) {
      if (!String(err).includes('ERR_ABORTED')) {
        throw err;
      }
    }
    await page.waitForSelector('[data-lml-hr-mc]', { timeout: 15000 });
    const overflow = await checkOverflow();
    const metrics = screen.slug === 'show-ana' && vp.name === '1440x900'
      ? await page.evaluate(() => {
          const box = (sel) => {
            const el = document.querySelector(sel);
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return {
              x: Math.round(r.x),
              y: Math.round(r.y),
              w: Math.round(r.width),
              h: Math.round(r.height),
            };
          };
          return {
            workspace: box('.lml-hr-mc-show'),
            client: box('.lml-hr-mc-show__client'),
            avatar: box('.lml-hr-mc-show__avatar'),
            care: box('.lml-hr-mc-show__care'),
            pregnancy: box('.lml-hr-mc-show__pregnancy'),
            title: box('.lml-topbar__title'),
            subtitle: box('.lml-topbar__subtitle'),
            titleText: document.querySelector('.lml-topbar__title')?.textContent.trim() ?? '',
            subtitleText: document.querySelector('.lml-topbar__subtitle')?.textContent.trim() ?? '',
            titleHidden: getComputedStyle(document.querySelector('.lml-topbar__titles')).clip.includes('rect'),
          };
        })
      : null;
    report.push({ page: screen.slug, viewport: vp.name, ...overflow, metrics });
    await page.screenshot({
      path: path.join(outDir, `${screen.slug}-${vp.name}.png`),
      fullPage: true,
    });
    if (screen.slug === 'listing' && vp.name === '1440x900') {
      const listingMetrics = await page.evaluate(() => {
        const box = (sel) => {
          const el = document.querySelector(sel);
          if (!el) return null;
          const r = el.getBoundingClientRect();
          return {
            x: Math.round(r.x),
            y: Math.round(r.y),
            w: Math.round(r.width),
            right: Math.round(r.right),
          };
        };
        return {
          title: box('.lml-topbar__title'),
          content: box('.lml-dashboard__content'),
          listingRoot: box('[data-lml-hr-mc]'),
          listingPanel: box('.lml-hr-mc__panel'),
        };
      });
      fs.writeFileSync(
        path.join(outDir, 'listing-desktop-metrics.json'),
        JSON.stringify(listingMetrics, null, 2)
      );
    }
    if (screen.slug === 'show-ana' && vp.name === '1440x900') {
      const metrics = await page.evaluate(() => {
        const box = (sel) => {
          const el = document.querySelector(sel);
          if (!el) {
            return null;
          }
          const r = el.getBoundingClientRect();
          return {
            x: Math.round(r.x),
            y: Math.round(r.y),
            w: Math.round(r.width),
            h: Math.round(r.height),
            right: Math.round(r.right),
          };
        };
        return {
          content: box('.lml-dashboard__content'),
          show: box('.lml-hr-mc-show'),
          workspace: box('.lml-hr-mc-show__workspace'),
          client: box('.lml-hr-mc-show__client'),
          avatar: box('.lml-hr-mc-show__avatar'),
          identity: box('.lml-hr-mc-show__identity'),
          care: box('.lml-hr-mc-show__care'),
          history: box('.lml-hr-mc-show__history-bar'),
          pregnancy: box('.lml-hr-mc-show__pregnancy'),
          aside: box('.lml-hr-mc-show__pregnancy-aside'),
          add: box('[data-hr-mc-nr-add-record]'),
          back: box('[data-hr-mc-show-back]'),
          title: box('.lml-topbar__title'),
          subtitle: box('.lml-topbar__subtitle'),
          titleText: document.querySelector('.lml-topbar__title')?.textContent.trim() ?? '',
          subtitleText: document.querySelector('.lml-topbar__subtitle')?.textContent.trim() ?? '',
        };
      });
      fs.writeFileSync(
        path.join(outDir, 'desktop-metrics.json'),
        JSON.stringify(metrics, null, 2)
      );
    }
  }
}

fs.writeFileSync(path.join(outDir, 'overflow-report.json'), JSON.stringify(report, null, 2));

const extraWidths = [1024, 768, 600, 480, 430, 390];
const extraOverflow = [];
await page.setViewportSize({ width: 1440, height: 900 });
try {
  await page.goto(`${base}/health-records/maternal/non-residents/ana-p-villanueva`, {
    waitUntil: 'domcontentloaded',
    timeout: 15000,
  });
} catch (err) {
  if (!String(err).includes('ERR_ABORTED')) {
    throw err;
  }
}
await page.waitForSelector('[data-lml-hr-mc]', { timeout: 15000 });
for (const width of extraWidths) {
  await page.setViewportSize({ width, height: 844 });
  await page.waitForTimeout(150);
  extraOverflow.push({ width, height: 844, ...(await checkOverflow()) });
}
fs.writeFileSync(
  path.join(outDir, 'overflow-extra-widths.json'),
  JSON.stringify(extraOverflow, null, 2)
);

const listingPath = path.join(outDir, 'listing-desktop-metrics.json');
const showPath = path.join(outDir, 'desktop-metrics.json');
if (fs.existsSync(listingPath) && fs.existsSync(showPath)) {
  const listing = JSON.parse(fs.readFileSync(listingPath, 'utf8'));
  const show = JSON.parse(fs.readFileSync(showPath, 'utf8'));
  fs.writeFileSync(
    path.join(outDir, 'width-compare-1440.json'),
    JSON.stringify(
      {
        listingRoot: listing.listingRoot,
        listingPanel: listing.listingPanel,
        listingTitle: listing.title,
        viewShow: show.show,
        viewWorkspace: show.workspace,
        viewClient: show.client,
        viewCare: show.care,
        viewTitle: show.title,
      },
      null,
      2
    )
  );
}

await browser.close();
console.log(`Wrote screenshots to ${outDir}`);

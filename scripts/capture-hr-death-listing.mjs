/**
 * Health Records → Death listing visual evidence (NOT production code).
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(__dirname, '../docs/qa/screenshots/health-records-death-listing');
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.DEATH_CAPTURE_BASE || 'http://127.0.0.1:8765';
const target = `${base}/health-records/death?role=bhw`;

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const report = [];

async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const scrollWidth = Math.max(doc.scrollWidth, body.scrollWidth);
    const clientWidth = doc.clientWidth;
    const tableScroll = document.querySelector('.lml-hr-death__table-scroll');
    const filters = document.querySelector('.lml-hr-death__filters');
    const stats = document.querySelector('.lml-hr-death__stats');
    const heading = document.querySelector('#lml-hr-death-heading');
    const deathLink = document.querySelector('.lml-sidebar__sublink--active');

    const box = (el) => {
      if (!el) {
        return null;
      }
      const r = el.getBoundingClientRect();
      return {
        x: Math.round(r.x),
        y: Math.round(r.y),
        width: Math.round(r.width),
        height: Math.round(r.height),
      };
    };

    const panel = document.querySelector('.lml-hr-death');
    const innerPanel = document.querySelector('.lml-hr-death__panel');
    const exportBtn = document.querySelector('[data-hr-death-export]');
    const firstFilter = document.querySelector('.lml-hr-death__search, .lml-hr-death__select-wrap');
    const tableCard = document.querySelector('.lml-hr-death__table-card');
    const cards = Array.from(document.querySelectorAll('.lml-hr-death__card'));
    const cardStyles = cards.map((card) => {
      const style = getComputedStyle(card);
      return {
        label: card.querySelector('.lml-hr-death__card-label')?.textContent?.trim() || '',
        backgroundImage: style.backgroundImage,
        backgroundColor: style.backgroundColor,
        height: Math.round(card.getBoundingClientRect().height),
      };
    });
    const panelBox = panel ? panel.getBoundingClientRect() : null;
    const content = document.querySelector('.lml-dashboard__content');
    const contentBox = content ? content.getBoundingClientRect() : null;
    let centered = null;
    if (panelBox && contentBox) {
      const leftGap = panelBox.left - contentBox.left;
      const rightGap = contentBox.right - panelBox.right;
      centered = {
        leftGap: Math.round(leftGap),
        rightGap: Math.round(rightGap),
        aligned: Math.abs(leftGap - rightGap) <= 8,
      };
    }

    let columnAlignment = null;
    if (innerPanel) {
      const xs = [exportBtn, ...cards, filters, tableCard]
        .filter(Boolean)
        .map((el) => Math.round(el.getBoundingClientRect().left));
      const rights = [exportBtn, ...cards, filters, tableCard]
        .filter(Boolean)
        .map((el) => Math.round(el.getBoundingClientRect().right));
      columnAlignment = {
        lefts: xs,
        rights,
        leftSpread: xs.length ? Math.max(...xs) - Math.min(...xs) : 0,
        rightSpread: rights.length ? Math.max(...rights) - Math.min(...rights) : 0,
      };
    }

    const desc = document.querySelector('#lml-hr-death-desc');
    const search = document.querySelector('.lml-hr-death__search');
    const selects = Array.from(document.querySelectorAll('.lml-hr-death__select-wrap'));
    const descBox = desc ? desc.getBoundingClientRect() : null;
    const expBox = exportBtn ? exportBtn.getBoundingClientRect() : null;

    return {
      title: heading?.textContent?.trim() || '',
      overflow: scrollWidth > clientWidth + 1,
      scrollWidth,
      clientWidth,
      documentElement: {
        scrollWidth: doc.scrollWidth,
        clientWidth: doc.clientWidth,
      },
      deathPanel: box(innerPanel),
      deathWrapper: box(panel),
      titleExportGap:
        descBox && expBox ? Math.round(expBox.top - descBox.bottom) : null,
      descriptionBottomY: descBox ? Math.round(descBox.bottom) : null,
      exportTopY: expBox ? Math.round(expBox.top) : null,
      centered,
      columnAlignment,
      export: box(exportBtn),
      search: box(search),
      selects: selects.map((el) => {
        const label = el.querySelector('select')?.id || '';
        return { id: label, ...box(el) };
      }),
      cardStyles,
      filters: box(filters),
      stats: box(stats),
      tableScroll: tableScroll
        ? {
            ...box(tableScroll),
            scrollWidth: tableScroll.scrollWidth,
            clientWidth: tableScroll.clientWidth,
            hidden: tableScroll.hidden,
          }
        : null,
      activeSidebar: deathLink?.textContent?.trim() || '',
      cards: Array.from(document.querySelectorAll('.lml-hr-death__card')).map((card) => ({
        label: card.querySelector('.lml-hr-death__card-label')?.textContent?.trim() || '',
        value: card.querySelector('.lml-hr-death__card-value')?.textContent?.trim() || '',
        box: box(card),
      })),
    };
  });
}

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(target, { waitUntil: 'networkidle' });
  const metrics = await measure(page);
  const file = path.join(outDir, `death-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  report.push({ viewport: vp.name, file, ...metrics });
}

fs.writeFileSync(path.join(outDir, 'overflow-report.json'), JSON.stringify(report, null, 2));
await browser.close();
console.log(JSON.stringify(report, null, 2));

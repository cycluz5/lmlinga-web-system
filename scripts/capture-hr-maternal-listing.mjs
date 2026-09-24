/**
 * Maternal Care listing visual evidence after refinement (NOT production code).
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-maternal-nr-ui-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.MC_CAPTURE_BASE || 'http://127.0.0.1:8765';

const pages = [
  { slug: 'non-resident', path: '/health-records/maternal/non-residents?role=bns' },
];

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

async function checkOverflow(page) {
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

async function measureDesktop(page) {
  return page.evaluate(() => {
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
        right: Math.round(r.right),
      };
    };

    const cards = Array.from(document.querySelectorAll('.lml-hr-mc__card'));
    const firstCard = cards[0] || null;
    const lastCard = cards[cards.length - 1] || null;
    const table = document.querySelector('.lml-hr-mc__table-card');
    const actionRow = document.querySelector('[data-hr-mc-action-row]');
    const add = document.querySelector('[data-hr-mc-add]');
    const exp = document.querySelector('[data-hr-mc-export]');
    const back = document.querySelector('[data-hr-mc-back]');
    const filters = document.querySelector('.lml-hr-mc__filters');

    const sameRow = (a, b) => {
      if (!a || !b) {
        return null;
      }
      return Math.abs(a.getBoundingClientRect().y - b.getBoundingClientRect().y) < 12;
    };

    return {
      back: box(back),
      add: box(add),
      export: box(exp),
      actionRow: box(actionRow),
      firstCard: box(firstCard),
      lastCard: box(lastCard),
      filters: box(filters),
      table: box(table),
      addExportSameRow: sameRow(add, exp),
      backAddSameRow: sameRow(back, add),
    };
  });
}

const overflowReport = [];
const measureReport = {};

for (const screen of pages) {
  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.goto(`${base}${screen.path}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-lml-hr-mc]');
    const overflow = await checkOverflow(page);
    overflowReport.push({ page: screen.slug, viewport: vp.name, ...overflow });
    if (vp.name === '1440x900') {
      measureReport[screen.slug] = await measureDesktop(page);
    }
    const file = path.join(outDir, `maternal-${screen.slug}-${vp.name}.png`);
    await page.screenshot({ path: file, fullPage: false });
    console.log('saved', file, overflow.overflow ? 'OVERFLOW' : 'ok');
  }
}

await browser.close();
const payload = { overflow: overflowReport, measure1440: measureReport };
fs.writeFileSync(path.join(outDir, 'measure-report.json'), JSON.stringify(payload, null, 2));
console.log(JSON.stringify(payload, null, 2));

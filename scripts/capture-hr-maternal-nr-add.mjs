/**
 * Add Non-Resident Maternal Client visual evidence (NOT production code).
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-maternal-nr-add-structure'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.MC_CAPTURE_BASE || 'http://127.0.0.1:8765';
const addPath = '/health-records/maternal/non-residents/create?role=bns';

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

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(`${base}${addPath}`, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-mc-add]');
  const overflow = await checkOverflow();
  const layout = await page.evaluate(() => {
    const box = (el) => {
      if (!el) {
        return null;
      }
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return {
        x: Math.round(r.x),
        y: Math.round(r.y),
        width: Math.round(r.width),
        height: Math.round(r.height),
        bottom: Math.round(r.bottom),
        border: cs.borderTopWidth,
      };
    };
    const personal = document.querySelector('[data-hr-mc-add-card="personal"]');
    const pregnancy = document.querySelector('[data-hr-mc-add-card="pregnancy"]');
    const nutrition = document.querySelector('[data-hr-mc-add-nutrition]');
    const banner = document.querySelector('[data-hr-mc-add-banner]');
    const actions = document.querySelector('.lml-hr-mc-add__actions');
    return {
      sameElement: personal === pregnancy,
      nutritionInsidePregnancy: Boolean(pregnancy && nutrition && pregnancy.contains(nutrition)),
      nutritionInsidePersonal: Boolean(
        document.querySelector('[data-hr-mc-add-card="personal"]')?.contains(nutrition)
      ),
      banner: box(banner),
      personal: box(personal),
      pregnancy: box(pregnancy),
      actions: box(actions),
      widthsAligned:
        personal && pregnancy
          ? Math.abs(personal.getBoundingClientRect().width - pregnancy.getBoundingClientRect().width) < 2
          : false,
      verticalGap:
        personal && pregnancy
          ? Math.round(pregnancy.getBoundingClientRect().top - personal.getBoundingClientRect().bottom)
          : null,
    };
  });
  fs.writeFileSync(
    path.join(outDir, `layout-${vp.name}.json`),
    JSON.stringify({ overflow, layout }, null, 2)
  );
  await page.screenshot({
    path: path.join(outDir, `add-${vp.name}.png`),
    fullPage: true,
  });
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(`${base}${addPath}`, { waitUntil: 'networkidle' });
await page.fill('#lml-hr-mc-weight', '60');
await page.fill('#lml-hr-mc-height', '160');
await page.waitForFunction(() => {
  const el = document.querySelector('#lml-hr-mc-bmi');
  return el && el.value === '23.4';
});
await page.screenshot({
  path: path.join(outDir, 'add-1440x900-bmi-calculated.png'),
  fullPage: true,
});

const bmiValue = await page.inputValue('#lml-hr-mc-bmi');
fs.writeFileSync(
  path.join(outDir, 'bmi-calculated.json'),
  JSON.stringify({ weight: 60, height: 160, bmi: bmiValue }, null, 2)
);

await browser.close();
console.log(`Wrote screenshots to ${outDir}`);

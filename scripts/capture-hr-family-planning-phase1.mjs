/**
 * Family Planning Phase 1 — visual evidence capture (NOT production code).
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-family-planning-phase1.1'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_CAPTURE_BASE || 'http://127.0.0.1:8765';
const url = `${base}/health-records/family-planning?role=bns`;

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '768x1024', width: 768, height: 1024 },
  { name: '390x844', width: 390, height: 844 },
  { name: '360x800', width: 360, height: 800 },
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

const overflowReport = [];

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-fp]');
  const overflow = await checkOverflow(page);
  overflowReport.push({ viewport: vp.name, ...overflow });
  const file = path.join(outDir, `family-planning-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log('saved', file, overflow.overflow ? 'OVERFLOW' : 'ok');
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(url, { waitUntil: 'networkidle' });
await page.fill('[data-hr-fp-search]', 'zzzz-no-match');
await page.waitForTimeout(250);
await page.screenshot({
  path: path.join(outDir, 'family-planning-1440x900-empty-filter.png'),
  fullPage: false,
});
console.log('saved empty filter');

await page.goto(url, { waitUntil: 'networkidle' });
await page.click('[data-hr-fp-add]');
await page.waitForSelector('[data-hr-fp-toast]:not([hidden])');
await page.waitForTimeout(150);
await page.screenshot({
  path: path.join(outDir, 'family-planning-1440x900-add-toast.png'),
  fullPage: false,
});
console.log('saved add toast');

await page.goto(url, { waitUntil: 'networkidle' });
await page.click('[data-hr-fp-export]');
await page.waitForSelector('[data-hr-fp-toast]:not([hidden])');
await page.waitForTimeout(150);
await page.screenshot({
  path: path.join(outDir, 'family-planning-1440x900-export-toast.png'),
  fullPage: false,
});
console.log('saved export toast');

fs.writeFileSync(
  path.join(outDir, 'overflow-report.json'),
  JSON.stringify(overflowReport, null, 2)
);
console.log('overflow report', JSON.stringify(overflowReport, null, 2));

await browser.close();

import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(__dirname, '../docs/qa/screenshots/health-records-risk-assessment-phase3');
const url = 'http://127.0.0.1:8765/health-records/risk-assessment';

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

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-risk]');
  const file = path.join(outDir, `risk-assessment-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  console.log('saved', file);
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(url, { waitUntil: 'networkidle' });
await page.fill('[data-hr-ra-search]', 'zzzz-no-match');
await page.waitForTimeout(250);
await page.screenshot({
  path: path.join(outDir, 'risk-assessment-1440x900-empty-filter.png'),
  fullPage: false,
});
console.log('saved empty filter');

await page.goto(url, { waitUntil: 'networkidle' });
await page.click('[data-hr-ra-add]');
await page.waitForSelector('[data-hr-ra-toast]:not([hidden])');
await page.waitForTimeout(150);
await page.screenshot({
  path: path.join(outDir, 'risk-assessment-1440x900-add-toast.png'),
  fullPage: false,
});
console.log('saved add toast');

await page.goto(url, { waitUntil: 'networkidle' });
await page.click('[data-hr-ra-export]');
await page.waitForSelector('[data-hr-ra-toast]:not([hidden])');
await page.waitForTimeout(150);
await page.screenshot({
  path: path.join(outDir, 'risk-assessment-1440x900-export-toast.png'),
  fullPage: false,
});
console.log('saved export toast');

await browser.close();

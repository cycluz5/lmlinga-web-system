import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-risk-assessment-phase3'
);
fs.mkdirSync(outDir, { recursive: true });
const url = 'http://127.0.0.1:8765/health-records/risk-assessment';

const shots = [
  { name: 'risk-assessment-1440x900-post-review.png', width: 1440, height: 900 },
  { name: 'risk-assessment-820x1180-post-review.png', width: 820, height: 1180 },
  { name: 'risk-assessment-390x844-post-review.png', width: 390, height: 844 },
  { name: 'risk-assessment-360x800-post-review.png', width: 360, height: 800 },
];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

for (const shot of shots) {
  await page.setViewportSize({ width: shot.width, height: shot.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-risk]');
  await page.screenshot({
    path: path.join(outDir, shot.name),
    fullPage: false,
  });
  console.log('saved', shot.name);
}

await browser.close();

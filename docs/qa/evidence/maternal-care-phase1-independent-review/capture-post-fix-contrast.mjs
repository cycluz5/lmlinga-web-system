/**
 * Supplemental post-fix contrast screenshots only (evidence, not app source).
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(__dirname, '04-interactions', 'post-fix-contrast');
fs.mkdirSync(outDir, { recursive: true });

const base = 'http://127.0.0.1:8765';
const member = 'HH-151/members/MB-001';
const q = 'role=bhw&v=mc-contrast-fix';

const browser = await chromium.launch({ headless: true, channel: 'chrome' });
const page = await browser.newPage();

async function ensureOverview() {
  await page.goto(`${base}/household-profiling/${member}/maternal-care?${q}`, {
    waitUntil: 'networkidle',
  });
  const mode = await page.locator('[data-lml-mc]').getAttribute('data-lml-mc-mode');
  if (mode === 'overview') {
    return;
  }
  await page.goto(`${base}/household-profiling/${member}/maternal-care/register?${q}`, {
    waitUntil: 'networkidle',
  });
  await page.fill('#lml-mc-lmp', '2026-01-15');
  await page.fill('#lml-mc-gravida', '1');
  await page.fill('#lml-mc-parity', '0');
  await Promise.all([
    page.waitForURL(/maternal-care/),
    page.click('[data-mc-save]'),
  ]);
  await page.waitForSelector('[data-lml-mc-mode="overview"]');
}

await ensureOverview();

const shots = [
  [1440, 900, 'contrast-primary-accent-1440x900.png'],
  [820, 1180, 'contrast-primary-accent-820x1180.png'],
  [390, 844, 'contrast-primary-accent-390x844.png'],
];

for (const [w, h, name] of shots) {
  await page.setViewportSize({ width: w, height: h });
  await page.goto(`${base}/household-profiling/${member}/maternal-care/prenatal?${q}`, {
    waitUntil: 'networkidle',
  });
  await page.waitForTimeout(250);
  await page.screenshot({ path: path.join(outDir, name), fullPage: false });
  console.log('saved', name);
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(`${base}/household-profiling/HH-151/members/MB-002/maternal-care?${q}`, {
  waitUntil: 'networkidle',
});
await page.waitForTimeout(200);
await page.screenshot({
  path: path.join(outDir, 'contrast-register-cta-1440x900.png'),
  fullPage: false,
});
console.log('saved contrast-register-cta-1440x900.png');

await browser.close();

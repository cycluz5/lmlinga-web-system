/**
 * Non-Residents Actions column + workflow pages evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-actions'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_NR_CAPTURE_BASE || 'http://127.0.0.1:8765';
const role = '?role=bns';

const listingViewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });

async function screenshot(page, filename) {
  const file = path.join(outDir, filename);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`saved ${file}`);
}

for (const vp of listingViewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(`${base}/health-records/family-planning/non-residents${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  await screenshot(page, `listing-actions-${vp.name}.png`);
  await page.close();
}

{
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}/health-records/family-planning/non-residents/roselyn-a-mendoza${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  await screenshot(page, 'client-details-1440x900.png');
  await page.close();
}

{
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}/health-records/family-planning/non-residents/roselyn-a-mendoza/visits/create${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  await screenshot(page, 'add-visit-1440x900.png');
  await page.close();
}

{
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}/health-records/family-planning/non-residents/roselyn-a-mendoza/visits/NR-FP-001/edit${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  await screenshot(page, 'edit-visit-1440x900.png');
  await page.close();
}

{
  const page = await browser.newPage();
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${base}/health-records/family-planning/non-residents${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  await page.locator('[data-hr-fp-nr-delete-client]').first().click();
  await page.waitForSelector('[data-hr-fp-nr-delete-dialog]:not([hidden])');
  await screenshot(page, 'delete-confirmation-1440x900.png');
  await page.close();
}

const measurements = {
  capturedAt: new Date().toISOString(),
  listingColumns: ['Full Name', 'Age', 'Method', 'Start Date', 'Last Visit', 'Actions'],
  editRule: 'Edit targets latest visit by visited_at; falls back to Add Visit when no visits exist',
  deletePersistence: 'UI preview only — confirmation modal + toast, no backend delete route',
};

fs.writeFileSync(
  path.join(outDir, 'layout-measurements.json'),
  `${JSON.stringify(measurements, null, 2)}\n`
);

await browser.close();

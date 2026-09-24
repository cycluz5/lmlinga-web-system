/**
 * View Individual — vertical spacing refinement evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-view-vertical-spacing'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_NR_CAPTURE_BASE || 'http://127.0.0.1:8765';
const role = '?role=bns';

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });

async function measure(page) {
  return page.evaluate(() => {
    const infoList = document.querySelector('.lml-hr-fp-nr__info-list--compact');
    const blocks = Array.from(document.querySelectorAll('.lml-hr-fp-nr__detail-block'));
    const gapBetweenSections = blocks.length >= 2
      ? Math.round(
          blocks[1].getBoundingClientRect().top -
            blocks[0].getBoundingClientRect().bottom
        )
      : null;

    return {
      infoRowGap: infoList
        ? Math.round(Number.parseFloat(getComputedStyle(infoList).gap))
        : null,
      sectionBlockPaddingBottom: blocks[0]
        ? Math.round(Number.parseFloat(getComputedStyle(blocks[0]).paddingBottom))
        : null,
      sectionBlockPaddingTop: blocks[1]
        ? Math.round(Number.parseFloat(getComputedStyle(blocks[1]).paddingTop))
        : null,
      gapBetweenClientInfoAndVisitHistory: gapBetweenSections,
    };
  });
}

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(`${base}/health-records/family-planning/non-residents/roselyn-a-mendoza${role}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  const file = path.join(outDir, `view-client-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  console.log(`saved ${file}`);

  if (vp.name === '1440x900') {
    const metrics = await measure(page);
    fs.writeFileSync(
      path.join(outDir, 'layout-measurements.json'),
      `${JSON.stringify({ viewport: vp.name, ...metrics }, null, 2)}\n`
    );
  }

  await page.close();
}

await browser.close();

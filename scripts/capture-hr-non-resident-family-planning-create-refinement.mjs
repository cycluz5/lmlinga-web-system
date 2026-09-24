/**
 * Add New Non Resident create-form Figma refinement evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-create-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.FP_NR_CAPTURE_BASE || 'http://127.0.0.1:8765';
const createPath = `/health-records/family-planning/non-residents/create?role=bns`;

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({ headless: true });

async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const pageOverflow =
      Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1;

    const main = document.querySelector('#lml-dashboard-content') || document.querySelector('main');
    const root = document.querySelector('[data-lml-hr-fp-nr-mode="create-client"]');
    const formPanel = document.querySelector('.lml-hr-fp-nr__form-panel');
    const personal = document.querySelector('.lml-hr-fp-nr__section-box:not(.lml-hr-fp-nr__section-box--service)');
    const service = document.querySelector('.lml-hr-fp-nr__section-box--service');
    const input = document.querySelector('#lml-hr-fp-nr-first-name');
    const remarks = document.querySelector('#lml-hr-fp-nr-remarks');
    const commodity = document.querySelector('[data-hr-fp-nr-commodity-name]');
    const quantity = document.querySelector('[data-hr-fp-nr-commodity-qty]');
    const actions = document.querySelector('.lml-hr-fp-nr__form-actions');
    const personalGrid = document.querySelector('.lml-hr-fp-nr__field-grid--3');
    const split = document.querySelector('.lml-hr-fp-nr__form-split');
    const age = document.querySelector('#lml-hr-fp-nr-age');
    const method = document.querySelector('#lml-hr-fp-nr-method');

    const mainRect = main?.getBoundingClientRect();
    const formRect = formPanel?.getBoundingClientRect();
    const personalRect = personal?.getBoundingClientRect();
    const serviceRect = service?.getBoundingClientRect();
    const remarksRect = remarks?.getBoundingClientRect();
    const commodityRect = commodity?.getBoundingClientRect();
    const quantityRect = quantity?.getBoundingClientRect();
    const actionsRect = actions?.getBoundingClientRect();

    const personalCols = personalGrid
      ? getComputedStyle(personalGrid).gridTemplateColumns.split(' ').filter(Boolean).length
      : null;
    const serviceCols = split
      ? getComputedStyle(split).gridTemplateColumns.split(' ').filter(Boolean).length
      : null;

    const formCenterX = formRect ? formRect.left + formRect.width / 2 : null;
    const mainCenterX = mainRect ? mainRect.left + mainRect.width / 2 : null;

    return {
      pageOverflow,
      contentWidth: mainRect ? Math.round(mainRect.width) : null,
      formWidth: formRect ? Math.round(formRect.width) : null,
      personalInfoHeight: personalRect ? Math.round(personalRect.height) : null,
      serviceRecordHeight: serviceRect ? Math.round(serviceRect.height) : null,
      inputHeight: input ? Math.round(input.getBoundingClientRect().height) : null,
      personalInfoColumns: personalCols,
      serviceRecordColumns: serviceCols,
      remarksHeight: remarksRect ? Math.round(remarksRect.height) : null,
      commodityWidth: commodityRect ? Math.round(commodityRect.width) : null,
      quantityWidth: quantityRect ? Math.round(quantityRect.width) : null,
      actionsVisible: Boolean(actionsRect && actionsRect.height > 0),
      horizontalOverflow: pageOverflow,
      formCenterOffset:
        formCenterX != null && mainCenterX != null
          ? Math.round(formCenterX - mainCenterX)
          : null,
      ageFieldPresent: Boolean(age),
      methodFieldPresent: Boolean(method),
      rootMaxWidth: root ? getComputedStyle(root).maxWidth : null,
    };
  });
}

const measurements = [];

for (const vp of viewports) {
  const page = await browser.newPage();
  await page.setViewportSize({ width: vp.width, height: vp.height });
  const response = await page.goto(`${base}${createPath}`, {
    waitUntil: 'networkidle',
    timeout: 60000,
  });
  const ok = response?.ok() ?? false;
  const metrics = await measure(page);
  const file = path.join(outDir, `create-${vp.name}.png`);
  await page.screenshot({ path: file, fullPage: true });
  measurements.push({
    viewport: vp.name,
    ok,
    ...metrics,
  });
  console.log(
    `saved ${file} ok=${ok} overflow=${metrics.pageOverflow} formWidth=${metrics.formWidth} cols=${metrics.personalInfoColumns}/${metrics.serviceRecordColumns}`
  );
  await page.close();
}

const jsonPath = path.join(outDir, 'layout-measurements.json');
fs.writeFileSync(jsonPath, `${JSON.stringify(measurements, null, 2)}\n`);
console.log(`saved ${jsonPath}`);

await browser.close();

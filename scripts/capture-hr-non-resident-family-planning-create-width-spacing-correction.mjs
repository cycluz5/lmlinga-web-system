/**
 * Add Non Resident — width + internal spacing correction evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-create-width-spacing-correction'
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

function px(value) {
  return value == null ? null : Math.round(value);
}

async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const pageOverflow =
      Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1;

    const main = document.querySelector('#lml-dashboard-content') || document.querySelector('main');
    const root = document.querySelector('[data-lml-hr-fp-nr-mode="create-client"]');
    const formPanel = document.querySelector('.lml-hr-fp-nr__form-panel');
    const formInner = document.querySelector('.lml-hr-fp-nr__form');
    const personal = document.querySelector('.lml-hr-fp-nr__section-box:not(.lml-hr-fp-nr__section-box--service)');
    const service = document.querySelector('.lml-hr-fp-nr__section-box--service');
    const personalGrid = document.querySelector('.lml-hr-fp-nr__field-grid--3');
    const split = document.querySelector('.lml-hr-fp-nr__form-split');
    const visitCol = document.querySelector('.lml-hr-fp-nr__form-split-col:first-child');
    const commodityCol = document.querySelector('[data-hr-fp-nr-commodities]');
    const visitDate = document.querySelector('#lml-hr-fp-nr-visit-date');
    const method = document.querySelector('#lml-hr-fp-nr-method');
    const remarks = document.querySelector('#lml-hr-fp-nr-remarks');
    const commodity = document.querySelector('[data-hr-fp-nr-commodity-name]');
    const quantity = document.querySelector('[data-hr-fp-nr-commodity-qty]');
    const firstInput = document.querySelector('#lml-hr-fp-nr-first-name');
    const actions = document.querySelector('.lml-hr-fp-nr__form-actions--centered');

    const mainRect = main?.getBoundingClientRect();
    const formRect = formPanel?.getBoundingClientRect();
    const personalRect = personal?.getBoundingClientRect();
    const serviceRect = service?.getBoundingClientRect();
    const visitColRect = visitCol?.getBoundingClientRect();
    const commodityColRect = commodityCol?.getBoundingClientRect();

    const personalStyles = personal ? getComputedStyle(personal) : null;
    const personalGridStyles = personalGrid ? getComputedStyle(personalGrid) : null;
    const splitStyles = split ? getComputedStyle(split) : null;
    const formStyles = formInner ? getComputedStyle(formInner) : null;
    const commodityRow = document.querySelector('.lml-hr-fp-nr__commodity-row');
    const commodityRowStyles = commodityRow ? getComputedStyle(commodityRow) : null;
    const actionsStyles = actions ? getComputedStyle(actions) : null;

    const formCenterX = formRect ? formRect.left + formRect.width / 2 : null;
    const mainCenterX = mainRect ? mainRect.left + mainRect.width / 2 : null;

    const parseGap = (styles, prop = 'gap') => {
      if (!styles) return null;
      const raw = styles[prop] || styles.columnGap || styles.rowGap || '0';
      return Math.round(Number.parseFloat(raw) || 0);
    };

    return {
      viewportWidth: window.innerWidth,
      pageOverflow,
      mainContentWidth: mainRect ? Math.round(mainRect.width) : null,
      formWidth: formRect ? Math.round(formRect.width) : null,
      formCenterOffset:
        formCenterX != null && mainCenterX != null
          ? Math.round(formCenterX - mainCenterX)
          : null,
      outerFormHorizontalPadding: formStyles
        ? Math.round(Number.parseFloat(formStyles.paddingLeft))
        : null,
      personalInfoWidth: personalRect ? Math.round(personalRect.width) : null,
      personalInfoHorizontalPadding: personalStyles
        ? Math.round(Number.parseFloat(personalStyles.paddingLeft))
        : null,
      personalInfoColumnGap: personalGridStyles
        ? Math.round(Number.parseFloat(personalGridStyles.columnGap))
        : null,
      personalInfoRowGap: personalGridStyles
        ? Math.round(Number.parseFloat(personalGridStyles.rowGap))
        : null,
      personalInfoInputHeight: firstInput ? Math.round(firstInput.getBoundingClientRect().height) : null,
      serviceRecordWidth: serviceRect ? Math.round(serviceRect.width) : null,
      serviceRecordHorizontalPadding: service
        ? Math.round(Number.parseFloat(getComputedStyle(service).paddingLeft))
        : null,
      serviceColumnGap: splitStyles
        ? Math.round(Number.parseFloat(splitStyles.columnGap))
        : null,
      visitInformationWidth: visitColRect ? Math.round(visitColRect.width) : null,
      commoditiesWidth: commodityColRect ? Math.round(commodityColRect.width) : null,
      visitDateWidth: visitDate ? Math.round(visitDate.getBoundingClientRect().width) : null,
      methodWidth: method ? Math.round(method.getBoundingClientRect().width) : null,
      remarksWidth: remarks ? Math.round(remarks.getBoundingClientRect().width) : null,
      remarksHeight: remarks ? Math.round(remarks.getBoundingClientRect().height) : null,
      commodityWidth: commodity ? Math.round(commodity.getBoundingClientRect().width) : null,
      quantityWidth: quantity ? Math.round(quantity.getBoundingClientRect().width) : null,
      sectionVerticalGap: formStyles ? Math.round(Number.parseFloat(formStyles.gap)) : null,
      actionsGap: actionsStyles ? Math.round(Number.parseFloat(actionsStyles.gap)) : null,
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
    previousRefinedFormWidthPx: 864,
    ...metrics,
  });
  console.log(
    `saved ${file} ok=${ok} formWidth=${metrics.formWidth} serviceGap=${metrics.serviceColumnGap}`
  );
  await page.close();
}

const jsonPath = path.join(outDir, 'layout-measurements.json');
fs.writeFileSync(jsonPath, `${JSON.stringify(measurements, null, 2)}\n`);
console.log(`saved ${jsonPath}`);

await browser.close();

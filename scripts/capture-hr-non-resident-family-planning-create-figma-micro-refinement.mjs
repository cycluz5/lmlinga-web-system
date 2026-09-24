/**
 * Add Non Resident — Figma micro-refinement evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-non-resident-family-planning-create-figma-micro-refinement'
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

    const root = document.querySelector('[data-lml-hr-fp-nr-mode="create-client"]');
    const formPanel = document.querySelector('.lml-hr-fp-nr__form-panel');
    const personalSection = document.querySelector(
      '.lml-hr-fp-nr__section-box:not(.lml-hr-fp-nr__section-box--service)'
    );
    const serviceSection = document.querySelector('.lml-hr-fp-nr__section-box--service');
    const personalTitle = personalSection?.querySelector('.lml-hr-fp-nr__section-title');
    const personalGrid = personalSection?.querySelector('.lml-hr-fp-nr__field-grid');
    const banner = document.querySelector('.lml-hr-fp-nr__form-banner');

    const firstName = document.querySelector('#lml-hr-fp-nr-first-name');
    const middleName = document.querySelector('#lml-hr-fp-nr-middle-name');
    const lastName = document.querySelector('#lml-hr-fp-nr-last-name');
    const address = document.querySelector('#lml-hr-fp-nr-address');
    const barangay = document.querySelector('#lml-hr-fp-nr-barangay');
    const municipality = document.querySelector('#lml-hr-fp-nr-municipality');
    const sex = document.querySelector('#lml-hr-fp-nr-sex');
    const civilStatus = document.querySelector('#lml-hr-fp-nr-civil-status');
    const method = document.querySelector('#lml-hr-fp-nr-method');
    const commodity = document.querySelector('[data-hr-fp-nr-commodity-name]');

    const personalTitleStyles = personalTitle ? getComputedStyle(personalTitle) : null;
    const bannerStyles = banner ? getComputedStyle(banner) : null;
    const formPanelStyles = formPanel ? getComputedStyle(formPanel) : null;
    const personalStyles = personalSection ? getComputedStyle(personalSection) : null;
    const serviceStyles = serviceSection ? getComputedStyle(serviceSection) : null;
    const firstNameStyles = firstName ? getComputedStyle(firstName) : null;

    const personalTitleRect = personalTitle?.getBoundingClientRect();
    const personalGridRect = personalGrid?.getBoundingClientRect();

    const selectedOptionText = (select) => {
      if (!select) return null;
      return select.options[select.selectedIndex]?.text ?? null;
    };

    return {
      viewportWidth: window.innerWidth,
      pageOverflow,
      formWidth: formPanel ? Math.round(formPanel.getBoundingClientRect().width) : null,
      personalInfoHeadingSeparatorPresent:
        personalTitleStyles != null &&
        personalTitleStyles.borderBottomWidth !== '0px' &&
        personalTitleStyles.borderBottomStyle !== 'none',
      personalInfoHeadingToFieldsGap:
        personalTitleRect && personalGridRect
          ? Math.round(personalGridRect.top - personalTitleRect.bottom)
          : null,
      headerStripHeight: banner ? Math.round(banner.getBoundingClientRect().height) : null,
      headerStripPaddingTop: bannerStyles
        ? Math.round(Number.parseFloat(bannerStyles.paddingTop))
        : null,
      headerStripPaddingBottom: bannerStyles
        ? Math.round(Number.parseFloat(bannerStyles.paddingBottom))
        : null,
      outerCardBorderWidth: formPanelStyles?.borderTopWidth ?? null,
      outerCardBorderColor: formPanelStyles?.borderTopColor ?? null,
      personalInfoBorderWidth: personalStyles?.borderTopWidth ?? null,
      personalInfoBorderColor: personalStyles?.borderTopColor ?? null,
      serviceRecordBorderWidth: serviceStyles?.borderTopWidth ?? null,
      serviceRecordBorderColor: serviceStyles?.borderTopColor ?? null,
      firstNamePlaceholder: firstName?.getAttribute('placeholder') ?? null,
      middleNamePlaceholder: middleName?.getAttribute('placeholder') ?? null,
      lastNamePlaceholder: lastName?.getAttribute('placeholder') ?? null,
      addressPlaceholder: address?.getAttribute('placeholder') ?? null,
      barangayPlaceholder: barangay?.getAttribute('placeholder') ?? null,
      municipalityPlaceholder: municipality?.getAttribute('placeholder') ?? null,
      sexDefaultText: selectedOptionText(sex),
      civilStatusDefaultText: selectedOptionText(civilStatus),
      methodDefaultText: selectedOptionText(method),
      commodityDefaultText: selectedOptionText(commodity),
      placeholderComputedColor: firstNameStyles?.color ?? null,
      sexComputedColor: sex ? getComputedStyle(sex).color : null,
      birthdayComputedColor: document.querySelector('#lml-hr-fp-nr-birthday')
        ? getComputedStyle(document.querySelector('#lml-hr-fp-nr-birthday')).color
        : null,
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
  measurements.push({ viewport: vp.name, ok, ...metrics });
  console.log(
    `saved ${file} separator=${metrics.personalInfoHeadingSeparatorPresent} formWidth=${metrics.formWidth}`
  );
  await page.close();
}

const jsonPath = path.join(outDir, 'layout-measurements.json');
fs.writeFileSync(jsonPath, `${JSON.stringify(measurements, null, 2)}\n`);
console.log(`saved ${jsonPath}`);

await browser.close();

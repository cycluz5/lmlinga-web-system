/**
 * Maternal Care Phase 1 — evidence-only capture (NOT production application code).
 * Uses existing Playwright + local Laravel server. Session/preview model only.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const base = 'http://127.0.0.1:8765';
const hh = 'HH-151';
const mb = 'MB-001';
const roleQ = 'role=bhw&v=mc-p1-evidence';
const memberBase = `${base}/household-profiling/${hh}/members/${mb}`;

const urls = {
  member: `${memberBase}?${roleQ}`,
  maternal: `${memberBase}/maternal-care?${roleQ}`,
  register: `${memberBase}/maternal-care/register?${roleQ}`,
  prenatal: `${memberBase}/maternal-care/prenatal?${roleQ}`,
  immunizations: `${memberBase}/maternal-care/immunizations?${roleQ}`,
  supplementations: `${memberBase}/maternal-care/supplementations?${roleQ}`,
  laboratory: `${memberBase}/maternal-care/laboratory?${roleQ}`,
  delivery: `${memberBase}/maternal-care/delivery?${roleQ}`,
  postnatal: `${memberBase}/maternal-care/postnatal?${roleQ}`,
  history: `${memberBase}/maternal-care/history?${roleQ}`,
  familyPlanning: `${memberBase}/family-planning?${roleQ}`,
  riskAssessment: `${memberBase}/risk-assessment?${roleQ}`,
  childCare: `${memberBase}/child-immunization?${roleQ}`,
  dashboard: `${base}/dashboard?${roleQ}`,
};

const desktop = [
  { w: 1440, h: 900, dir: '01-desktop' },
  { w: 1366, h: 768, dir: '01-desktop' },
];
const tablet = [
  { w: 820, h: 1180, dir: '02-tablet' },
  { w: 768, h: 1024, dir: '02-tablet' },
];
const mobile = [
  { w: 390, h: 844, dir: '03-mobile' },
  { w: 360, h: 800, dir: '03-mobile' },
];
const allViewports = [...desktop, ...tablet, ...mobile];

const pages = [
  { key: 'overview', urlKey: 'maternal', filePrefix: 'maternal-overview', fullPageExtra: false },
  { key: 'prenatal', urlKey: 'prenatal', filePrefix: 'prenatal', fullPageExtra: true },
  { key: 'supplementations', urlKey: 'supplementations', filePrefix: 'supplementations', fullPageExtra: true },
  { key: 'laboratory', urlKey: 'laboratory', filePrefix: 'laboratory', fullPageExtra: false },
  { key: 'delivery', urlKey: 'delivery', filePrefix: 'delivery-outcome', fullPageExtra: true },
  { key: 'postnatal', urlKey: 'postnatal', filePrefix: 'postnatal', fullPageExtra: false },
];

const inventory = {
  screenshots: [],
  viewports: {},
  interactions: [],
  navigation: [],
  notes: [],
  errors: [],
};

function out(...parts) {
  return path.join(__dirname, ...parts);
}

async function installDeviceToolbar(page, w, h) {
  await page.evaluate(
    ({ w, h }) => {
      let bar = document.getElementById('lml-devtools-device-toolbar');
      if (!bar) {
        bar = document.createElement('div');
        bar.id = 'lml-devtools-device-toolbar';
        bar.setAttribute('aria-hidden', 'true');
        document.documentElement.appendChild(bar);
      }
      bar.style.cssText = [
        'position:fixed',
        'top:0',
        'left:0',
        'right:0',
        'z-index:2147483647',
        'display:flex',
        'align-items:center',
        'gap:12px',
        'height:36px',
        'padding:0 12px',
        'background:#202124',
        'color:#e8eaed',
        'font:600 12px/1 ui-sans-serif,system-ui,Segoe UI,sans-serif',
        'border-bottom:1px solid #3c4043',
        'pointer-events:none',
        'box-sizing:border-box',
      ].join(';');
      bar.innerHTML = `
        <span style="opacity:.85">Device toolbar</span>
        <span style="background:#3c4043;padding:3px 8px;border-radius:4px">Dimensions: ${w} × ${h}</span>
        <span style="opacity:.7">CSS px · Maternal Care evidence</span>
        <span style="margin-left:auto;opacity:.7">inner ${window.innerWidth}×${window.innerHeight}</span>
      `;

      let badge = document.getElementById('lml-viewport-evidence');
      if (!badge) {
        badge = document.createElement('div');
        badge.id = 'lml-viewport-evidence';
        badge.setAttribute('aria-hidden', 'true');
        document.documentElement.appendChild(badge);
      }
      badge.style.cssText =
        'position:fixed;bottom:10px;left:10px;z-index:2147483647;background:rgba(17,24,39,.92);color:#fff;padding:6px 10px;font:600 12px/1.2 ui-monospace,monospace;border-radius:6px;pointer-events:none;box-shadow:0 2px 8px rgba(0,0,0,.25)';
      badge.textContent = `viewport ${window.innerWidth}×${window.innerHeight} (CSS px)`;
    },
    { w, h }
  );
}

async function shot(page, relPath, w, h, { fullPage = false } = {}) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(250);
  if (!fullPage) {
    await page.evaluate(() => window.scrollTo(0, 0));
  }
  await installDeviceToolbar(page, w, h);
  const file = out(relPath);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.screenshot({ path: file, fullPage });
  inventory.screenshots.push(relPath.replace(/\\/g, '/'));
  return file;
}

async function ensureActivePregnancy(page) {
  await page.goto(urls.maternal, { waitUntil: 'networkidle' });
  const mode = await page.locator('[data-lml-mc]').getAttribute('data-lml-mc-mode');
  if (mode === 'overview') {
    inventory.notes.push({ step: 'active-pregnancy', status: 'already-present' });
    return;
  }

  await page.goto(urls.register, { waitUntil: 'networkidle' });
  await page.fill('#lml-mc-lmp', '2026-01-15');
  await page.fill('#lml-mc-gravida', '2');
  await page.fill('#lml-mc-parity', '1');
  await page.fill('#lml-mc-weight', '58');
  await page.fill('#lml-mc-height', '160');
  await page.fill('#lml-mc-bp', '110/70');
  await Promise.all([
    page.waitForURL(/maternal-care(?:\?|$)/),
    page.click('[data-mc-save]'),
  ]);
  await page.waitForSelector('[data-lml-mc-mode="overview"]', { timeout: 10000 });
  inventory.notes.push({ step: 'active-pregnancy', status: 'registered-via-session' });
}

async function captureResponsive(page) {
  for (const vp of allViewports) {
    const key = `${vp.w}x${vp.h}`;
    inventory.viewports[key] = { status: 'PASS', files: [] };
    for (const p of pages) {
      await page.goto(urls[p.urlKey], { waitUntil: 'networkidle' });
      const name = `${p.filePrefix}-${key}.png`;
      const rel = path.join(vp.dir, name);
      await shot(page, rel, vp.w, vp.h, { fullPage: false });
      inventory.viewports[key].files.push(rel.replace(/\\/g, '/'));

      if (p.fullPageExtra) {
        const fullName = `${p.filePrefix}-${key}-full.png`;
        const fullRel = path.join(vp.dir, fullName);
        await shot(page, fullRel, vp.w, vp.h, { fullPage: true });
        inventory.viewports[key].files.push(fullRel.replace(/\\/g, '/'));
      }
    }
  }
}

async function captureInteractions(page) {
  await page.setViewportSize({ width: 1440, height: 900 });

  // Immunizations icon on overview
  await page.goto(urls.maternal, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-mc-service="immunizations"]');
  const iconClass = await page
    .locator('[data-mc-service="immunizations"] .lml-mc__service-icon i')
    .getAttribute('class');
  inventory.notes.push({ step: 'immunizations-icon-class', iconClass });
  await shot(page, path.join('04-interactions', '00-immunizations-icon-fixed.png'), 1440, 900);

  // Prenatal edit / save-view
  await page.goto(urls.prenatal, { waitUntil: 'networkidle' });
  await page.click('[data-mc-edit-for="prenatal"]');
  await page.waitForTimeout(200);
  await page.locator('[data-mc-trimester="first"] [data-mc-accordion-trigger]').click();
  await page.waitForTimeout(150);
  await shot(page, path.join('04-interactions', '01-prenatal-edit.png'), 1440, 900);
  await page.fill('#mc-prenatal-t1_v1-date', '2026-02-10');
  await page.fill('#mc-prenatal-t1_v1-weight', '58');
  await page.fill('#mc-prenatal-t1_v1-bp', '110/70');
  await Promise.all([
    page.waitForURL(/maternal-care\/prenatal/),
    page.click('[data-mc-save-for="prenatal"]'),
  ]);
  await page.waitForTimeout(200);
  await shot(page, path.join('04-interactions', '02-prenatal-save-view.png'), 1440, 900);
  inventory.interactions.push('01-prenatal-edit.png', '02-prenatal-save-view.png');

  // Immunizations
  await page.goto(urls.immunizations, { waitUntil: 'networkidle' });
  await page.click('[data-mc-edit-for="immunizations"]');
  await page.waitForTimeout(150);
  await shot(page, path.join('04-interactions', '03-immunizations-edit.png'), 1440, 900);
  await page.fill('#mc-imm-td1', '2026-02-01');
  await Promise.all([
    page.waitForURL(/maternal-care\/immunizations/),
    page.click('[data-mc-save-for="immunizations"]'),
  ]);
  await page.waitForTimeout(200);
  await shot(page, path.join('04-interactions', '04-immunizations-save-view.png'), 1440, 900);
  inventory.interactions.push('03-immunizations-edit.png', '04-immunizations-save-view.png');

  // Supplementations
  await page.goto(urls.supplementations, { waitUntil: 'networkidle' });
  await page.click('[data-mc-edit-for="supplementations"]');
  await page.waitForTimeout(150);
  await shot(page, path.join('04-interactions', '05-supplementations-edit.png'), 1440, 900);

  await page.locator('[data-mc-supp="deworming"] [data-mc-accordion-trigger]').click();
  await page.waitForTimeout(120);
  await shot(page, path.join('04-interactions', '06-supplementations-expanded-deworming.png'), 1440, 900);

  await page.locator('[data-mc-supp="ifa"] [data-mc-accordion-trigger]').click();
  await page.waitForTimeout(120);
  await shot(page, path.join('04-interactions', '07-supplementations-expanded-iron-folic.png'), 1440, 900, {
    fullPage: true,
  });

  await page.locator('[data-mc-supp="mms"] [data-mc-accordion-trigger]').click();
  await page.waitForTimeout(120);
  await shot(page, path.join('04-interactions', '08-supplementations-expanded-micronutrient.png'), 1440, 900, {
    fullPage: true,
  });

  await page.locator('[data-mc-supp="calcium"] [data-mc-accordion-trigger]').click();
  await page.waitForTimeout(120);
  await shot(page, path.join('04-interactions', '09-supplementations-expanded-calcium.png'), 1440, 900);

  await page.fill('#mc-supp-deworming-date', '2026-03-01');
  await page.fill('#mc-supp-ifa-v1-date', '2026-02-15');
  await page.fill('#mc-supp-ifa-v1-tablets', '30');
  await Promise.all([
    page.waitForURL(/maternal-care\/supplementations/),
    page.click('[data-mc-save-for="supplementations"]'),
  ]);
  await page.waitForTimeout(200);
  await shot(page, path.join('04-interactions', '10-supplementations-save-view.png'), 1440, 900);
  inventory.interactions.push(
    '05-supplementations-edit.png',
    '06-supplementations-expanded-deworming.png',
    '07-supplementations-expanded-iron-folic.png',
    '08-supplementations-expanded-micronutrient.png',
    '09-supplementations-expanded-calcium.png',
    '10-supplementations-save-view.png'
  );

  // Laboratory
  await page.goto(urls.laboratory, { waitUntil: 'networkidle' });
  await page.click('[data-mc-edit-for="laboratory"]');
  await page.waitForTimeout(150);
  await shot(page, path.join('04-interactions', '11-laboratory-edit.png'), 1440, 900);

  await page.locator('#mc-lab-hepatitis_b-result').selectOption({ label: 'Reactive' });
  await shot(page, path.join('04-interactions', '12-laboratory-hepatitis-options.png'), 1440, 900);
  await page.locator('#mc-lab-cbc-result').selectOption({ label: 'With Anemia' });
  await shot(page, path.join('04-interactions', '13-laboratory-cbc-options.png'), 1440, 900);
  await page.locator('#mc-lab-gdm-result').selectOption({ label: 'Negative' });
  await shot(page, path.join('04-interactions', '14-laboratory-gdm-options.png'), 1440, 900);
  await page.fill('#mc-lab-hepatitis_b-date', '2026-02-20');
  await page.fill('#mc-lab-cbc-date', '2026-02-20');
  await page.fill('#mc-lab-gdm-date', '2026-02-20');
  await Promise.all([
    page.waitForURL(/maternal-care\/laboratory/),
    page.click('[data-mc-save-for="laboratory"]'),
  ]);
  await page.waitForTimeout(200);
  await shot(page, path.join('04-interactions', '15-laboratory-save-view.png'), 1440, 900);
  inventory.interactions.push(
    '11-laboratory-edit.png',
    '12-laboratory-hepatitis-options.png',
    '13-laboratory-cbc-options.png',
    '14-laboratory-gdm-options.png',
    '15-laboratory-save-view.png'
  );

  // Delivery
  await page.goto(urls.delivery, { waitUntil: 'networkidle' });
  await page.click('[data-mc-edit-for="delivery"]');
  await page.waitForTimeout(150);
  await shot(page, path.join('04-interactions', '16-delivery-edit.png'), 1440, 900, { fullPage: true });

  await page.locator('#lml-mc-delivery-type').selectOption('VD');
  await shot(page, path.join('04-interactions', '17-delivery-type-options.png'), 1440, 900);

  await page.locator('#lml-mc-birth-attendant').selectOption('MD');
  await shot(page, path.join('04-interactions', '18-birth-attendant-options.png'), 1440, 900);

  await page.locator('#lml-mc-birth-attendant').selectOption('Others');
  await page.waitForSelector('[data-mc-conditional="attendant-other"]:not([hidden])');
  await page.fill('#lml-mc-birth-attendant-other', 'Traditional Birth Attendant');
  await shot(page, path.join('04-interactions', '19-birth-attendant-others-state.png'), 1440, 900);

  await page.locator('[data-mc-outcome="FD"]').check();
  await page.waitForSelector('[data-mc-conditional="fd"]:not([hidden])');
  await page.fill('#lml-mc-fetal-death-date', '2026-06-01');
  await shot(page, path.join('04-interactions', '20-fetal-death-state.png'), 1440, 900);

  await page.locator('[data-mc-outcome="AB"]').check();
  await page.waitForSelector('[data-mc-conditional="ab"]:not([hidden])');
  await page.fill('#lml-mc-abortion-date', '2026-05-15');
  await shot(page, path.join('04-interactions', '21-abortion-state.png'), 1440, 900);

  await page.locator('[data-mc-outcome="FT"]').check();
  await page.locator('#lml-mc-delivery-type').selectOption('VD');
  await page.locator('#lml-mc-birth-attendant').selectOption('MW');
  await page.fill('#lml-mc-birth-weight', '3.2');
  await page.fill('#lml-mc-delivery-datetime', '2026-10-18T08:30');
  await page.locator('input[name="place"][value="public"]').check();
  await page.fill('#lml-mc-facility-name', 'La Medalla RHU');
  await page.locator('input[name="bemonc_cemonc"][value="Yes"]').check();
  await Promise.all([
    page.waitForURL(/maternal-care\/delivery/),
    page.click('[data-mc-save-for="delivery"]'),
  ]);
  await page.waitForTimeout(200);
  await shot(page, path.join('04-interactions', '22-delivery-save-view.png'), 1440, 900, { fullPage: true });
  inventory.interactions.push(
    '16-delivery-edit.png',
    '17-delivery-type-options.png',
    '18-birth-attendant-options.png',
    '19-birth-attendant-others-state.png',
    '20-fetal-death-state.png',
    '21-abortion-state.png',
    '22-delivery-save-view.png'
  );

  // Postnatal
  await page.goto(urls.postnatal, { waitUntil: 'networkidle' });
  await page.click('[data-mc-edit-for="postnatal"]');
  await page.waitForTimeout(150);
  await shot(page, path.join('04-interactions', '23-postnatal-edit.png'), 1440, 900);
  await page.fill('#mc-pn-c1', '2026-10-18');
  await page.fill('#mc-pp-v1-date', '2026-10-20');
  await page.fill('#mc-pp-v1-tablets', '30');
  await Promise.all([
    page.waitForURL(/maternal-care\/postnatal/),
    page.click('[data-mc-save-for="postnatal"]'),
  ]);
  await page.waitForTimeout(200);
  await shot(page, path.join('04-interactions', '24-postnatal-save-view.png'), 1440, 900);
  inventory.interactions.push('23-postnatal-edit.png', '24-postnatal-save-view.png');
}

async function captureNavigation(page) {
  await page.setViewportSize({ width: 1440, height: 900 });

  await page.goto(urls.member, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-hh-member-maternal-care]');
  await shot(page, path.join('05-navigation-regression', '01-household-member-maternal-care-link.png'), 1440, 900);

  await page.click('[data-hh-member-maternal-care]');
  await page.waitForURL(/maternal-care/);
  await page.waitForTimeout(200);
  const sidebarActive = await page
    .locator('.lml-sidebar__link--active .lml-sidebar__label')
    .textContent();
  inventory.notes.push({ step: 'sidebar-active', sidebarActive: (sidebarActive || '').trim() });
  await shot(page, path.join('05-navigation-regression', '02-maternal-household-profiling-active.png'), 1440, 900);

  await page.click('.lml-mc__back');
  await page.waitForURL(new RegExp(`/household-profiling/${hh}/members/${mb}(?:\\?|$)`));
  await page.waitForTimeout(200);
  const backUrl = page.url();
  inventory.notes.push({ step: 'return-navigation', backUrl });
  await shot(page, path.join('05-navigation-regression', '03-maternal-return-navigation.png'), 1440, 900);

  await page.goto(urls.familyPlanning, { waitUntil: 'networkidle' });
  await shot(page, path.join('05-navigation-regression', '04-family-planning-regression.png'), 1440, 900);

  await page.goto(urls.riskAssessment, { waitUntil: 'networkidle' });
  await shot(page, path.join('05-navigation-regression', '05-risk-assessment-regression.png'), 1440, 900);

  await page.goto(urls.childCare, { waitUntil: 'networkidle' });
  await shot(page, path.join('05-navigation-regression', '06-child-care-regression.png'), 1440, 900);

  await page.goto(urls.dashboard, { waitUntil: 'networkidle' });
  const hrToggle = page.locator('[data-lml-sidebar-collapse-toggle]').first();
  if (await hrToggle.count()) {
    const expanded = await hrToggle.getAttribute('aria-expanded');
    if (expanded !== 'true') {
      await hrToggle.click();
      await page.waitForTimeout(200);
    }
  }
  await shot(page, path.join('05-navigation-regression', '07-health-records-sidebar-regression.png'), 1440, 900);

  inventory.navigation.push(
    '01-household-member-maternal-care-link.png',
    '02-maternal-household-profiling-active.png',
    '03-maternal-return-navigation.png',
    '04-family-planning-regression.png',
    '05-risk-assessment-regression.png',
    '06-child-care-regression.png',
    '07-health-records-sidebar-regression.png'
  );
}

async function main() {
  const probe = await fetch(urls.maternal).then((r) => r.status).catch((e) => String(e));
  if (probe !== 200) {
    throw new Error(`Application not reachable at ${urls.maternal} (status=${probe})`);
  }

  const browser = await chromium.launch({
    headless: true,
    channel: 'chrome',
  });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    reducedMotion: 'reduce',
  });
  const page = await context.newPage();

  try {
    await ensureActivePregnancy(page);
    await captureResponsive(page);
    await captureInteractions(page);
    await captureNavigation(page);
  } catch (err) {
    inventory.errors.push(String(err && err.stack ? err.stack : err));
    throw err;
  } finally {
    fs.writeFileSync(out('08-cursor-reports', 'capture-inventory.json'), JSON.stringify(inventory, null, 2));
    await browser.close();
  }

  console.log(JSON.stringify({ ok: true, screenshots: inventory.screenshots.length, inventory }, null, 2));
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

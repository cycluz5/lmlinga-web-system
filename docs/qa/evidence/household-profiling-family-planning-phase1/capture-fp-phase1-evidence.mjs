/**
 * Family Planning Phase 1 — evidence-only capture (NOT production code).
 * Captures responsive + workflow + keyboard evidence with a visible
 * device-toolbar simulation so viewport dimensions are independently verifiable.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = __dirname;
const base = 'http://127.0.0.1:8765';
const memberParams = 'HH-151/members/MB-001';
const roleQ = 'role=bhw&v=fp-p1-evidence';

const urls = {
  member: `${base}/household-profiling/${memberParams}?${roleQ}`,
  main: `${base}/household-profiling/${memberParams}/family-planning?${roleQ}`,
  create: `${base}/household-profiling/${memberParams}/family-planning/create?${roleQ}`,
  show: `${base}/household-profiling/${memberParams}/family-planning/FP-001?${roleQ}`,
  edit: `${base}/household-profiling/${memberParams}/family-planning/FP-003/edit?${roleQ}`,
};

const mainViewports = [
  { w: 1440, h: 900, file: 'fp-main-1440x900.png' },
  { w: 1366, h: 768, file: 'fp-main-1366x768.png' },
  { w: 820, h: 1180, file: 'fp-main-820x1180.png' },
  { w: 768, h: 1024, file: 'fp-main-768x1024.png' },
  { w: 390, h: 844, file: 'fp-main-390x844.png' },
  { w: 360, h: 800, file: 'fp-main-360x800.png' },
];

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
        <span style="opacity:.7">CSS px · evidence capture</span>
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

async function measureOverflow(page) {
  return page.evaluate(() => {
    const root = document.documentElement;
    const tableScroll = document.querySelector('.lml-fp__table-scroll');
    const fp = document.querySelector('.lml-fp');
    return {
      clientWidth: root.clientWidth,
      scrollWidth: root.scrollWidth,
      pageOverflow: root.scrollWidth > root.clientWidth + 1,
      bodyOverflow: document.body.scrollWidth > root.clientWidth + 1,
      tableLocalScroll:
        !!tableScroll && tableScroll.scrollWidth > tableScroll.clientWidth + 1,
      fpScrollWidth: fp ? fp.scrollWidth : null,
      fpClientWidth: fp ? fp.clientWidth : null,
    };
  });
}

async function focusInfo(page) {
  return page.evaluate(() => {
    const ae = document.activeElement;
    if (!(ae instanceof HTMLElement)) return null;
    const cs = getComputedStyle(ae);
    return {
      tag: ae.tagName,
      type: ae.getAttribute('type'),
      name: ae.getAttribute('name'),
      href: ae.getAttribute('href'),
      aria: ae.getAttribute('aria-label'),
      text: (ae.innerText || ae.textContent || '').trim().slice(0, 80),
      className: String(ae.className || '').slice(0, 120),
      focusVisible: ae.matches(':focus-visible'),
      focus: ae.matches(':focus'),
      outline: cs.outline,
      outlineWidth: cs.outlineWidth,
      boxShadow: cs.boxShadow,
    };
  });
}

async function tabUntil(page, predicate, max = 50) {
  const trail = [];
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const info = await focusInfo(page);
    trail.push(info);
    if (info && predicate(info)) {
      return { info, trail, steps: i + 1, failed: false };
    }
  }
  return { info: await focusInfo(page), trail, steps: max, failed: true };
}

async function shot(page, file, w, h) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(280);
  await page.evaluate(() => window.scrollTo(0, 0));
  await installDeviceToolbar(page, w, h);
  await page.screenshot({ path: path.join(outDir, file), fullPage: false });
}

const overflowMatrix = [];
const keyboardLog = [];
const workflowNotes = [];

fs.mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  reducedMotion: 'reduce',
});
const page = await context.newPage();

// ── Responsive MAIN ──────────────────────────────────────────
await page.goto(urls.main + '&date=all', { waitUntil: 'networkidle' });
for (const vp of mainViewports) {
  await page.setViewportSize({ width: vp.w, height: vp.h });
  await page.waitForTimeout(250);
  const m = await measureOverflow(page);
  overflowMatrix.push({ page: 'main', file: vp.file, width: vp.w, height: vp.h, ...m });
  await shot(page, vp.file, vp.w, vp.h);
}

// ── Workflow desktop 1440 ────────────────────────────────────
await page.setViewportSize({ width: 1440, height: 900 });

await page.goto(urls.main + '&date=all', { waitUntil: 'networkidle' });
const allCount = await page.locator('[data-fp-row]').count();
workflowNotes.push({ case: 'all-dates-row-count', allCount });
await shot(page, 'fp-main-all-dates.png', 1440, 900);

await page.goto(urls.main + '&date=last_3_months', { waitUntil: 'networkidle' });
const last3Count = await page.locator('[data-fp-row]').count();
const last3Empty = await page.locator('[data-fp-empty-filtered]').count();
workflowNotes.push({ case: 'last-3-months', last3Count, last3Empty });
await shot(page, 'fp-main-last-3-months.png', 1440, 900);

await page.goto(
  urls.main + '&date=custom&from=2026-05-01&to=2026-06-08',
  { waitUntil: 'networkidle' }
);
const customVisible = await page.locator('[data-fp-custom-range]').isVisible();
const customCount = await page.locator('[data-fp-row]').count();
workflowNotes.push({ case: 'custom-range', customVisible, customCount });
await shot(page, 'fp-main-custom-range.png', 1440, 900);

// incomplete custom: from only — should not incorrectly hide (server leaves unfiltered)
await page.goto(urls.main + '&date=custom&from=2026-05-01', { waitUntil: 'networkidle' });
const incompleteCount = await page.locator('[data-fp-row]').count();
workflowNotes.push({ case: 'custom-incomplete-from-only', incompleteCount });

// invalid from > to — implemented safe behavior: leave unfiltered
await page.goto(
  urls.main + '&date=custom&from=2026-06-08&to=2026-05-01',
  { waitUntil: 'networkidle' }
);
const invalidCount = await page.locator('[data-fp-row]').count();
workflowNotes.push({ case: 'custom-invalid-from-gt-to', invalidCount });

await page.goto(urls.main + '&date=this_month', { waitUntil: 'networkidle' });
workflowNotes.push({
  case: 'this-month',
  count: await page.locator('[data-fp-row]').count(),
  emptyFiltered: await page.locator('[data-fp-empty-filtered]').count(),
});

await page.goto(urls.main + '&date=this_year', { waitUntil: 'networkidle' });
workflowNotes.push({
  case: 'this-year',
  count: await page.locator('[data-fp-row]').count(),
});

await page.goto(urls.create, { waitUntil: 'networkidle' });
await shot(page, 'fp-add-record.png', 1440, 900);

await page.goto(urls.show, { waitUntil: 'networkidle' });
await shot(page, 'fp-view-record.png', 1440, 900);

await page.goto(urls.edit, { waitUntil: 'networkidle' });
await shot(page, 'fp-edit-record.png', 1440, 900);

// Add commodity row on edit
await page.locator('[data-fp-commodity-add]').click();
await page.waitForTimeout(150);
const commodityRows = await page.locator('[data-fp-commodity-row]').count();
workflowNotes.push({ case: 'edit-add-commodity-row', commodityRows });
await shot(page, 'fp-edit-multiple-commodities.png', 1440, 900);

// Preview save toast
await page.locator('[data-fp-save]').click();
await page.waitForTimeout(200);
const toastVisible = await page.locator('[data-fp-toast]:not([hidden])').count();
const toastText = toastVisible
  ? await page.locator('[data-fp-toast]').innerText()
  : '';
workflowNotes.push({ case: 'preview-save', toastVisible, toastText });
await shot(page, 'fp-preview-save-status.png', 1440, 900);

// Mobile edit forms
await page.goto(urls.edit, { waitUntil: 'networkidle' });
const edit390 = await (async () => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(250);
  const m = await measureOverflow(page);
  overflowMatrix.push({ page: 'edit', file: 'fp-edit-390x844.png', width: 390, height: 844, ...m });
  await shot(page, 'fp-edit-390x844.png', 390, 844);
  return m;
})();
const edit360 = await (async () => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.waitForTimeout(250);
  const m = await measureOverflow(page);
  overflowMatrix.push({ page: 'edit', file: 'fp-edit-360x800.png', width: 360, height: 800, ...m });
  await shot(page, 'fp-edit-360x800.png', 360, 800);
  return m;
})();
workflowNotes.push({ case: 'mobile-edit-overflow', edit390, edit360 });

// ── Member navigation ────────────────────────────────────────
await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(urls.member, { waitUntil: 'networkidle' });
const fpLink = page.locator('[data-hh-member-family-planning]');
const fpHref = await fpLink.getAttribute('href');
const fpTag = await fpLink.evaluate((el) => el.tagName);
const maternalStub = await page.locator('[data-hh-member-view-record="Maternal"]').count();
const deathStub = await page.locator('[data-hh-member-view-record="Death"]').count();
const fpStubGone = await page.locator('[data-hh-member-view-record="Family Planning"]').count();
const sidebarActive = await page.locator('.lml-sidebar__link--active .lml-sidebar__label').innerText().catch(() => '');
workflowNotes.push({
  case: 'member-navigation',
  fpTag,
  fpHref,
  maternalStub,
  deathStub,
  fpStubGone,
  sidebarActive,
});
await installDeviceToolbar(page, 1440, 900);
await page.screenshot({
  path: path.join(outDir, 'fp-member-view-family-planning-link.png'),
  fullPage: false,
});

// Confirm toast does not fire when clicking FP (navigation)
await Promise.all([
  page.waitForURL(/family-planning/),
  fpLink.click(),
]);
const landed = page.url();
const toastAfterNav = await page.locator('[data-hh-member-view-toast]:not([hidden])').count().catch(() => 0);
workflowNotes.push({ case: 'member-fp-click-nav', landed, toastAfterNav });

// ── Keyboard evidence (desktop history + edit) ───────────────
await page.goto(urls.main + '&date=all', { waitUntil: 'networkidle' });
await page.setViewportSize({ width: 1440, height: 900 });
await page.locator('a.lml-fp__back').focus();
const toFilter = await tabUntil(
  page,
  (i) => i.tag === 'SELECT' && (i.name === 'date' || (i.aria || '').toLowerCase().includes('date'))
);
keyboardLog.push({ case: 'tab-to-date-filter', ...toFilter });
await installDeviceToolbar(page, 1440, 900);
await page.screenshot({
  path: path.join(outDir, 'fp-a11y-date-filter-focused.png'),
  fullPage: false,
});

const toAdd = await tabUntil(
  page,
  (i) => i.tag === 'A' && /add/i.test(i.text || '') && (i.href || '').includes('family-planning/create')
);
keyboardLog.push({ case: 'tab-to-add', ...toAdd });
await page.screenshot({
  path: path.join(outDir, 'fp-a11y-add-focused.png'),
  fullPage: false,
});

const toView = await tabUntil(
  page,
  (i) => i.tag === 'A' && /view/i.test(i.text || '') && (i.href || '').includes('/family-planning/FP-')
);
keyboardLog.push({ case: 'tab-to-view-link', ...toView });
await page.screenshot({
  path: path.join(outDir, 'fp-a11y-view-link-focused.png'),
  fullPage: false,
});

await page.goto(urls.edit, { waitUntil: 'networkidle' });
// Ensure a removable second row exists for keyboard remove-control evidence
await page.locator('[data-fp-commodity-add]').click();
await page.waitForTimeout(100);
await page.locator('a.lml-fp__back').focus();
const toDate = await tabUntil(page, (i) => i.tag === 'INPUT' && i.type === 'date' && i.name === 'visited_at');
keyboardLog.push({ case: 'edit-tab-to-date', ...toDate });
const toRemarks = await tabUntil(page, (i) => i.tag === 'TEXTAREA' && i.name === 'remarks');
keyboardLog.push({ case: 'edit-tab-to-remarks', ...toRemarks });
const toCommodity = await tabUntil(page, (i) => i.tag === 'SELECT' && (i.name || '').includes('commodities'));
keyboardLog.push({ case: 'edit-tab-to-commodity', ...toCommodity });
const toQty = await tabUntil(page, (i) => i.tag === 'INPUT' && i.type === 'number');
keyboardLog.push({ case: 'edit-tab-to-qty', ...toQty });
const toRemove = await tabUntil(
  page,
  (i) => i.tag === 'BUTTON' && /remove commodity/i.test(i.aria || '')
);
keyboardLog.push({ case: 'edit-tab-to-remove', ...toRemove });
const toAddCommodity = await tabUntil(
  page,
  (i) => i.tag === 'BUTTON' && /add another commodity/i.test(i.text || '')
);
keyboardLog.push({ case: 'edit-tab-to-add-commodity', ...toAddCommodity });
const toCancel = await tabUntil(
  page,
  (i) => i.tag === 'A' && /cancel/i.test(i.text || '')
);
keyboardLog.push({ case: 'edit-tab-to-cancel', ...toCancel });
const toSave = await tabUntil(
  page,
  (i) => i.tag === 'BUTTON' && /save/i.test(i.text || '') && i.type === 'submit'
);
keyboardLog.push({ case: 'edit-tab-to-save', ...toSave });
await installDeviceToolbar(page, 1440, 900);
await page.locator('[data-fp-save]').scrollIntoViewIfNeeded();
await page.screenshot({
  path: path.join(outDir, 'fp-a11y-edit-save-focused.png'),
  fullPage: false,
});

const semantics = await page.evaluate(() => ({
  addCommodityIsButton: !!document.querySelector('button[data-fp-commodity-add]'),
  saveIsButton: !!document.querySelector('button[data-fp-save]'),
  cancelIsAnchor: !!document.querySelector('a[data-fp-cancel]'),
  iconsAriaHidden: Array.from(document.querySelectorAll('.lml-fp i.bi')).every(
    (el) => el.closest('[aria-hidden="true"]') !== null
  ),
}));
keyboardLog.push({ case: 'semantics-edit-page', ...semantics });

await page.goto(urls.main + '&date=all', { waitUntil: 'networkidle' });
const mainSemantics = await page.evaluate(() => ({
  addIsAnchor: !!document.querySelector('a[data-fp-add]'),
  viewLinksAreAnchors: document.querySelectorAll('a[data-fp-view]').length,
}));
keyboardLog.push({ case: 'semantics-main-page', ...mainSemantics });

await page.goto(urls.show, { waitUntil: 'networkidle' });
const viewSemantics = await page.evaluate(() => ({
  readonlyInputs: document.querySelectorAll('input[readonly]').length,
  readonlyTextareas: document.querySelectorAll('textarea[readonly]').length,
  disabledFieldsets: document.querySelectorAll('fieldset[disabled]').length,
  editIsAnchor: !!document.querySelector('a[data-fp-edit]'),
}));
keyboardLog.push({ case: 'semantics-view-page', ...viewSemantics });

await page.goto(urls.main + '&date=all', { waitUntil: 'networkidle' });
const createHref = await page.locator('[data-fp-add]').getAttribute('href');
const showHref = await page.locator('[data-fp-view]').first().getAttribute('href');
await page.goto(urls.show, { waitUntil: 'networkidle' });
const editHref = await page.locator('[data-fp-edit]').getAttribute('href');
workflowNotes.push({ case: 'route-hrefs', createHref, showHref, editHref });

await browser.close();

fs.writeFileSync(
  path.join(outDir, 'fp-overflow-matrix.json'),
  JSON.stringify(overflowMatrix, null, 2)
);
fs.writeFileSync(
  path.join(outDir, 'fp-keyboard-log.json'),
  JSON.stringify(keyboardLog, null, 2)
);
fs.writeFileSync(
  path.join(outDir, 'fp-workflow-notes.json'),
  JSON.stringify(workflowNotes, null, 2)
);

const pageOverflows = overflowMatrix.filter((r) => r.pageOverflow || r.bodyOverflow);
console.log(JSON.stringify({
  outDir,
  screenshots: fs.readdirSync(outDir).filter((f) => f.endsWith('.png')).length,
  pageOverflows,
  keyboardFailed: keyboardLog.filter((k) => k.failed).map((k) => k.case),
  workflowNotes,
}, null, 2));

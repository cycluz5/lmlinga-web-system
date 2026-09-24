/**
 * RA-03 keyboard/focus evidence capture (evidence-only script; not production code).
 * Uses Playwright real keyboard events so :focus-visible rings appear.
 */
import { chromium } from 'playwright';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = __dirname;
const base = 'http://127.0.0.1:8765';
const historyUrl = `${base}/household-profiling/HH-151/members/MB-001/risk-assessment?role=bns&v=ra03pw`;
const createUrl = `${base}/household-profiling/HH-151/members/MB-001/risk-assessment/create?role=bns&v=ra03pw`;

async function focusInfo(page) {
  return page.evaluate(() => {
    const ae = document.activeElement;
    if (!(ae instanceof HTMLElement)) return null;
    const cs = getComputedStyle(ae);
    return {
      tag: ae.tagName,
      type: ae.getAttribute('type'),
      name: ae.getAttribute('name'),
      value: ae instanceof HTMLInputElement ? ae.value : undefined,
      aria: ae.getAttribute('aria-label'),
      text: (ae.innerText || ae.textContent || '').trim().slice(0, 80),
      className: ae.className,
      focusVisible: ae.matches(':focus-visible'),
      focus: ae.matches(':focus'),
      outline: cs.outline,
      outlineWidth: cs.outlineWidth,
      boxShadow: cs.boxShadow,
      border: cs.border,
    };
  });
}

async function tabUntil(page, predicate, max = 40) {
  const trail = [];
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const info = await focusInfo(page);
    trail.push(info);
    if (info && predicate(info)) return { info, trail, steps: i + 1 };
  }
  return { info: await focusInfo(page), trail, steps: max, failed: true };
}

const log = [];

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();

// --- History: date filter focus ---
await page.goto(historyUrl, { waitUntil: 'networkidle' });
await page.locator('a.lml-risk-assess__back').focus();
const hist = await tabUntil(
  page,
  (i) => i.tag === 'SELECT' && (i.name === 'date' || (i.aria || '').toLowerCase().includes('date')),
);
log.push({ case: 'history-tab-to-date-filter', ...hist });
await page.screenshot({
  path: path.join(outDir, 'RA-03-E1-history-date-filter-focused.png'),
  fullPage: false,
});

// Keyboard operate date filter
await page.keyboard.press('Alt+ArrowDown');
await page.keyboard.press('ArrowDown');
await page.keyboard.press('Enter');
const dateValue = await page.locator('select[name="date"]').inputValue();
log.push({ case: 'history-date-keyboard-change', dateValue });

// --- Wizard Step 1: checkbox focus + Space ---
await page.goto(createUrl, { waitUntil: 'networkidle' });
await page.locator('a.lml-risk-assess__back').focus();
const cb = await tabUntil(
  page,
  (i) => i.tag === 'INPUT' && i.type === 'checkbox' && i.value === 'chest_pain',
);
log.push({ case: 'wizard-tab-to-chest-pain', ...cb });
await page.locator('input[type="checkbox"][value="chest_pain"]').scrollIntoViewIfNeeded();
await page.screenshot({
  path: path.join(outDir, 'RA-03-E2-step1-checkbox-focused.png'),
  fullPage: false,
});
const before = await page.locator('input[type="checkbox"][value="chest_pain"]').isChecked();
await page.keyboard.press('Space');
const after = await page.locator('input[type="checkbox"][value="chest_pain"]').isChecked();
log.push({ case: 'wizard-space-toggle-checkbox', before, after });

// Next button focus
const next = await tabUntil(page, (i) => i.tag === 'BUTTON' && /next/i.test(i.text || ''));
log.push({ case: 'wizard-tab-to-next', ...next });
await page.locator('[data-risk-assess-next]').scrollIntoViewIfNeeded();
await page.screenshot({
  path: path.join(outDir, 'RA-03-E4-next-focused.png'),
  fullPage: false,
});
await page.keyboard.press('Enter');
await page.waitForTimeout(200);
const stepAfterNext = await page.locator('.lml-risk-assess').getAttribute('data-current-step');
log.push({ case: 'wizard-enter-next', stepAfterNext });

// Advance to step 4 via Next clicks (keyboard)
for (let s = Number(stepAfterNext || 2); s < 4; s++) {
  await page.locator('[data-risk-assess-next]').focus();
  await page.keyboard.press('Enter');
  await page.waitForTimeout(150);
}

// Step 4 radio focus
await page.locator('input[type="radio"]').first().focus();
// Prefer keyboard path: Tab within step
await page.locator('[data-risk-assess-step="4"] input[type="radio"]').first().evaluate((el) => {
  el.focus({ focusVisible: true });
});
// Move focus with Shift+Tab then Tab to re-trigger focus-visible
await page.keyboard.press('Shift+Tab');
await page.keyboard.press('Tab');
const radioInfo = await focusInfo(page);
log.push({ case: 'step4-radio-focus', radioInfo });
await page.locator('[data-risk-assess-step="4"] input[type="radio"]').first().scrollIntoViewIfNeeded();
await page.screenshot({
  path: path.join(outDir, 'RA-03-E3-step4-radio-focused.png'),
  fullPage: false,
});
const radioBefore = await page.locator('[data-risk-assess-step="4"] input[type="radio"]').first().isChecked();
await page.keyboard.press('Space');
const radioAfter = await page.locator('[data-risk-assess-step="4"] input[type="radio"]').first().isChecked();
log.push({ case: 'step4-radio-space', radioBefore, radioAfter });

// Step 5 Save focus
await page.locator('[data-risk-assess-next]').focus();
await page.keyboard.press('Enter');
await page.waitForTimeout(200);
await page.locator('[data-risk-assess-save]').focus();
await page.keyboard.press('Shift+Tab');
await page.keyboard.press('Tab');
const saveInfo = await focusInfo(page);
log.push({ case: 'step5-save-focus', saveInfo });
await page.locator('[data-risk-assess-save]').scrollIntoViewIfNeeded();
await page.screenshot({
  path: path.join(outDir, 'RA-03-E5-save-focused.png'),
  fullPage: false,
});

// Shift+Tab reverse check on step 5
const beforeShift = await focusInfo(page);
await page.keyboard.press('Shift+Tab');
const afterShift = await focusInfo(page);
log.push({ case: 'step5-shift-tab-reverse', beforeShift, afterShift });

fs.writeFileSync(path.join(outDir, 'RA-03-keyboard-log.json'), JSON.stringify(log, null, 2));
await browser.close();
console.log(JSON.stringify({ ok: true, cases: log.map((c) => c.case) }, null, 2));

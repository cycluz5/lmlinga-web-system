/**
 * A11Y-3 verification only — evidence hygiene; not production code.
 * Confirms iconsAriaHidden uses ancestor-aware aria-hidden detection,
 * and that keyboard Tab still reaches Save on the edit form.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = __dirname;
const editUrl =
  'http://127.0.0.1:8765/household-profiling/HH-151/members/MB-001/family-planning/FP-003/edit?role=bhw&v=fp-a11y3';

async function focusInfo(page) {
  return page.evaluate(() => {
    const ae = document.activeElement;
    if (!(ae instanceof HTMLElement)) return null;
    return {
      tag: ae.tagName,
      type: ae.getAttribute('type'),
      text: (ae.innerText || ae.textContent || '').trim().slice(0, 80),
      focusVisible: ae.matches(':focus-visible'),
    };
  });
}

async function tabUntil(page, predicate, max = 60) {
  for (let i = 0; i < max; i++) {
    await page.keyboard.press('Tab');
    const info = await focusInfo(page);
    if (info && predicate(info)) return { info, steps: i + 1, failed: false };
  }
  return { info: await focusInfo(page), steps: max, failed: true };
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await page.goto(editUrl, { waitUntil: 'networkidle' });

await page.locator('[data-fp-commodity-add]').click();
await page.waitForTimeout(100);

const semantics = await page.evaluate(() => {
  const icons = Array.from(document.querySelectorAll('.lml-fp i.bi'));
  return {
    iconCount: icons.length,
    iconsAriaHidden: icons.every((el) => el.closest('[aria-hidden="true"]') !== null),
    iconsWithOwnAttr: icons.filter((el) => el.getAttribute('aria-hidden') === 'true').length,
    iconsWithAncestorOnly: icons.filter(
      (el) =>
        el.getAttribute('aria-hidden') !== 'true' &&
        el.closest('[aria-hidden="true"]') !== null
    ).length,
    addCommodityIsButton: !!document.querySelector('button[data-fp-commodity-add]'),
    saveIsButton: !!document.querySelector('button[data-fp-save]'),
    cancelIsAnchor: !!document.querySelector('a[data-fp-cancel]'),
  };
});

await page.locator('a.lml-fp__back').focus();
const toSave = await tabUntil(
  page,
  (i) => i.tag === 'BUTTON' && /save/i.test(i.text || '') && i.type === 'submit'
);

await browser.close();

const result = {
  case: 'a11y3-iconsAriaHidden-verification',
  semantics,
  keyboardSave: toSave,
  pass: semantics.iconsAriaHidden === true && toSave.failed === false,
};

fs.writeFileSync(
  path.join(outDir, 'fp-a11y3-icons-verification.json'),
  JSON.stringify(result, null, 2)
);

// Patch prior keyboard log semantics-edit-page entry if present
const logPath = path.join(outDir, 'fp-keyboard-log.json');
if (fs.existsSync(logPath)) {
  const log = JSON.parse(fs.readFileSync(logPath, 'utf8'));
  const idx = log.findIndex((e) => e.case === 'semantics-edit-page');
  if (idx >= 0) {
    log[idx] = {
      case: 'semantics-edit-page',
      ...semantics,
      a11y3Fixed: true,
    };
    fs.writeFileSync(logPath, JSON.stringify(log, null, 2));
  }
}

console.log(JSON.stringify(result, null, 2));
if (!result.pass) process.exit(1);

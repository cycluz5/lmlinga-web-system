/**
 * Evidence-only: force server-side max:500 validation to verify aria-invalid / aria-describedby.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const out = path.resolve(__dirname, '..', '06-dom-verification');
const base = 'http://127.0.0.1:8765';
const hh = 'HH-151';
const mb = 'MB-002';
const q = 'role=bhw&v=death-validation-dom';

fs.mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
await page.setViewportSize({ width: 1440, height: 900 });

await page.goto(`${base}/household-profiling/${hh}/members/${mb}/death/create?${q}`, {
  waitUntil: 'networkidle',
});
await page.fill('#lml-death-cause', 'Valid cause');
await page.fill('#lml-death-date', '2026-05-01');
await Promise.all([
  page.waitForURL(/\/death(?:\?|$)/),
  page.click('[data-death-save]'),
]);

await page.goto(`${base}/household-profiling/${hh}/members/${mb}/death/edit?${q}`, {
  waitUntil: 'networkidle',
});

const long = 'X'.repeat(501);
await page.evaluate((val) => {
  const cause = document.querySelector('#lml-death-cause');
  cause.removeAttribute('maxlength');
  cause.value = val;
}, long);
await page.click('[data-death-save]');
await page.waitForTimeout(1500);

const validation = await page.evaluate(() => {
  const cause = document.querySelector('#lml-death-cause');
  const date = document.querySelector('#lml-death-date');
  return {
    url: location.href,
    mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
    cause: {
      ariaInvalid: cause?.getAttribute('aria-invalid'),
      ariaDescribedBy: cause?.getAttribute('aria-describedby'),
      errorEl: !!document.querySelector('#lml-death-cause-error'),
      errorText: document.querySelector('#lml-death-cause-error')?.textContent?.trim() || null,
      valueLength: cause?.value?.length ?? null,
    },
    date: {
      ariaInvalid: date?.getAttribute('aria-invalid'),
      ariaDescribedBy: date?.getAttribute('aria-describedby'),
      errorEl: !!document.querySelector('#lml-death-date-error'),
      errorText: document.querySelector('#lml-death-date-error')?.textContent?.trim() || null,
    },
  };
});

await page.screenshot({ path: path.join(out, 'validation-errors-1440x900.png'), fullPage: false });

const domPath = path.join(out, 'dom-verification.json');
const existing = JSON.parse(fs.readFileSync(domPath, 'utf8'));
existing.validationMaxLengthSubmit = validation;
existing.validationNote =
  'Triggered by removing maxlength and submitting 501-char cause_of_death against server max:500 rule.';
fs.writeFileSync(domPath, JSON.stringify(existing, null, 2));
fs.writeFileSync(path.join(out, 'validation-dom.txt'), JSON.stringify(validation, null, 2));
console.log(JSON.stringify(validation, null, 2));
await browser.close();

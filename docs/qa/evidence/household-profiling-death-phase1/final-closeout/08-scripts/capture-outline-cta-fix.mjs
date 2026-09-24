/**
 * Evidence-only: outline CTA contrast patch verification + 3 ALIVE screenshots.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');
const outDir = path.join(root, '09-outline-contrast-fix');
const base = 'http://127.0.0.1:8765';
const url = `${base}/household-profiling/HH-151/members/MB-001/death?role=bhw&v=outline-cta-patch`;

fs.mkdirSync(outDir, { recursive: true });

function srgbToLinear(c) {
  const v = c / 255;
  return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
}
function relativeLuminance(r, g, b) {
  return 0.2126 * srgbToLinear(r) + 0.7152 * srgbToLinear(g) + 0.0722 * srgbToLinear(b);
}
function parseCssColor(css) {
  const m = css?.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/i);
  if (!m) return null;
  return { r: Number(m[1]), g: Number(m[2]), b: Number(m[3]), a: m[4] !== undefined ? Number(m[4]) : 1 };
}
function rgbToHex({ r, g, b }) {
  const h = (n) => Math.round(n).toString(16).padStart(2, '0');
  return `#${h(r)}${h(g)}${h(b)}`.toUpperCase();
}
function blend(fg, bg) {
  if (fg.a >= 1) return fg;
  return {
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
    a: 1,
  };
}
function contrastRatio(fg, bg) {
  const L1 = relativeLuminance(fg.r, fg.g, fg.b);
  const L2 = relativeLuminance(bg.r, bg.g, bg.b);
  const lighter = Math.max(L1, L2);
  const darker = Math.min(L1, L2);
  return Number(((lighter + 0.05) / (darker + 0.05)).toFixed(2));
}
function evaluate(label, fgCss, bgCss, threshold) {
  const fg = parseCssColor(fgCss);
  const bg = parseCssColor(bgCss);
  if (!fg || !bg) return { label, result: 'FAIL — parse', threshold };
  const display = blend(fg, bg);
  const ratio = contrastRatio(display, bg);
  return {
    label,
    foregroundCss: fgCss,
    backgroundCss: bgCss,
    foregroundHex: rgbToHex(display),
    backgroundHex: rgbToHex(bg),
    ratio,
    threshold,
    result: ratio >= threshold ? 'PASS' : 'FAIL',
  };
}

async function readBtnColors(page, state) {
  if (state === 'hover') await page.hover('[data-death-record-cta]');
  if (state === 'focus') await page.focus('[data-death-record-cta]');
  if (state === 'normal') {
    await page.evaluate(() => document.activeElement?.blur?.());
    await page.mouse.move(0, 0);
  }
  await page.waitForTimeout(80);
  return page.evaluate(() => {
    const btn = document.querySelector('[data-death-record-cta]');
    const cs = getComputedStyle(btn);
    const icon = btn.querySelector('i');
    return {
      color: cs.color,
      background: cs.backgroundColor,
      border: cs.borderTopColor,
      outline: cs.outline,
      outlineColor: cs.outlineColor,
      outlineWidth: cs.outlineWidth,
      iconColor: icon ? getComputedStyle(icon).color : null,
    };
  });
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

await page.goto(url, { waitUntil: 'networkidle' });
const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
if (mode !== 'empty') {
  // Use create-flow member that is empty — try MB-005
  await page.goto(`${base}/household-profiling/HH-151/members/MB-005/death?role=bhw&v=outline-cta-patch`, {
    waitUntil: 'networkidle',
  });
}

await page.waitForSelector('[data-death-record-cta]', { timeout: 15000 });

const results = [];
const normal = await readBtnColors(page, 'normal');
results.push(evaluate('NORMAL text vs background', normal.color, normal.background, 4.5));
results.push(evaluate('NORMAL border vs background', normal.border, normal.background, 3.0));
if (normal.iconColor) {
  results.push(evaluate('NORMAL icon vs background', normal.iconColor, normal.background, 4.5));
}

const hover = await readBtnColors(page, 'hover');
results.push(evaluate('HOVER text vs background', hover.color, hover.background, 4.5));
results.push(evaluate('HOVER border vs background', hover.border, hover.background, 3.0));
if (hover.iconColor) {
  results.push(evaluate('HOVER icon vs background', hover.iconColor, hover.background, 4.5));
}

const focus = await readBtnColors(page, 'focus');
results.push(evaluate('FOCUS text vs background', focus.color, focus.background, 4.5));
results.push(evaluate('FOCUS border vs background', focus.border, focus.background, 3.0));
results.push(evaluate('FOCUS outline vs surrounding white', focus.outlineColor, 'rgb(255, 255, 255)', 3.0));

const viewports = [
  { w: 1440, h: 900 },
  { w: 820, h: 1180 },
  { w: 390, h: 844 },
];
const shotMeta = [];
for (const vp of viewports) {
  await page.setViewportSize({ width: vp.w, height: vp.h });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(120);
  const file = `alive-outline-cta-${vp.w}x${vp.h}.png`;
  await page.screenshot({ path: path.join(outDir, file), fullPage: false });
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  }));
  shotMeta.push({ file, ...vp, overflow, mode: await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode') });
}

const report = {
  previous: {
    border: '#9FDDAD (approx; rgba(81,194,105,0.55) on #FFFFFF)',
    borderContrast: 1.56,
    result: 'FAIL — WCAG 1.4.11',
  },
  computed: { normal, hover, focus },
  contrast: results,
  screenshots: shotMeta,
};
fs.writeFileSync(path.join(outDir, 'outline-contrast-after.json'), JSON.stringify(report, null, 2));
const md = [
  '# Outline CTA Contrast — Post-Fix',
  '',
  '## Previous',
  '- Border: `#9FDDAD` (rgba accent @0.55 on white)',
  '- Border contrast: **1.56:1** FAIL (threshold 3:1)',
  '',
  '## After (computed)',
  '',
  '| Check | FG hex | BG hex | Ratio | Threshold | Result |',
  '|---|---|---|---:|---:|---|',
  ...results.map(
    (c) =>
      `| ${c.label} | ${c.foregroundHex || '—'} | ${c.backgroundHex || '—'} | ${c.ratio ?? '—'} | ${c.threshold} | ${c.result} |`
  ),
  '',
].join('\n');
fs.writeFileSync(path.join(outDir, 'outline-contrast-after.md'), md);
// Also refresh accessibility contrast report slice for outline CTA
const a11yDir = path.join(root, '03-accessibility');
fs.writeFileSync(path.join(a11yDir, 'outline-cta-contrast-post-fix.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify({ contrast: results, screenshots: shotMeta.map((s) => s.file) }, null, 2));
await browser.close();

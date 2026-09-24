/**
 * Death No-Record / ALIVE panel — targeted evidence capture only.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outRoot = path.join(__dirname, 'alive-panel-refinement');
const base = 'http://127.0.0.1:8765';
const url = `${base}/household-profiling/HH-151/members/MB-002/death?role=bhw&v=death-alive-refine`;
const createUrl = `${base}/household-profiling/HH-151/members/MB-002/death/create?role=bhw&v=death-alive-refine`;
const viewports = [
  { w: 1440, h: 900, bucket: '01-desktop' },
  { w: 1366, h: 768, bucket: '01-desktop' },
  { w: 820, h: 1180, bucket: '02-tablet' },
  { w: 768, h: 1024, bucket: '02-tablet' },
  { w: 390, h: 844, bucket: '03-mobile' },
  { w: 360, h: 800, bucket: '03-mobile' },
];

const inventory = { screenshots: [], checks: {}, overflow: {}, errors: [] };

async function measure(page) {
  return page.evaluate(() => {
    const empty = document.querySelector('[data-death-no-record]');
    const cta = document.querySelector('[data-death-record-cta]');
    const head = document.querySelector('[data-death-empty] .lml-death__panel-title');
    const rect = empty?.getBoundingClientRect();
    const cs = empty ? getComputedStyle(empty) : null;
    const ctaCs = cta ? getComputedStyle(cta) : null;
    return {
      panelHeight: rect ? Math.round(rect.height) : null,
      panelWidth: rect ? Math.round(rect.width) : null,
      minHeight: cs?.minHeight ?? null,
      titleOutside: !!head && !empty?.contains(head),
      ctaBg: ctaCs?.backgroundColor ?? null,
      ctaColor: ctaCs?.color ?? null,
      ctaBorder: ctaCs?.borderTopColor ?? null,
      ctaClasses: cta?.className ?? null,
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
    };
  });
}

async function main() {
  fs.mkdirSync(outRoot, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  try {
    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.w, height: vp.h });
      await page.goto(url, { waitUntil: 'networkidle' });
      await page.waitForSelector('[data-death-no-record]');
      const key = `${vp.w}x${vp.h}`;
      const checks = await measure(page);
      inventory.checks[key] = checks;
      inventory.overflow[key] = { overflowX: checks.overflowX };
      const dir = path.join(outRoot, vp.bucket);
      fs.mkdirSync(dir, { recursive: true });
      const file = path.join(dir, `death-no-record-${key}.png`);
      await page.screenshot({ path: file, fullPage: false });
      inventory.screenshots.push(path.relative(outRoot, file).replace(/\\/g, '/'));
    }

    // Sanity: create surface still uses solid primary Save (not outline)
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(createUrl, { waitUntil: 'networkidle' });
    const createCheck = await page.evaluate(() => {
      const save = document.querySelector('[data-death-save]');
      const cs = save ? getComputedStyle(save) : null;
      return {
        mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
        saveClasses: save?.className ?? null,
        saveBg: cs?.backgroundColor ?? null,
        saveColor: cs?.color ?? null,
      };
    });
    inventory.checks.createSanity = createCheck;
    await page.screenshot({
      path: path.join(outRoot, '01-desktop', 'death-create-sanity-1440x900.png'),
      fullPage: false,
    });
    inventory.screenshots.push('01-desktop/death-create-sanity-1440x900.png');
  } catch (err) {
    inventory.errors.push(String(err?.stack || err));
    throw err;
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(outRoot, 'evidence-summary.json'), JSON.stringify(inventory, null, 2));
    console.log(JSON.stringify(inventory, null, 2));
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

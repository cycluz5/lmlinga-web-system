/**
 * Death Information Phase 1 — evidence-only capture (NOT production application code).
 * Uses Playwright + local Laravel server. Session/preview model only.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const base = 'http://127.0.0.1:8765';
const hh = 'HH-151';
const mb = 'MB-002';
const roleQ = 'role=bhw&v=death-p1-ui-refine';
const memberBase = `${base}/household-profiling/${hh}/members/${mb}`;

const urls = {
  member: `${memberBase}?${roleQ}`,
  death: `${memberBase}/death?${roleQ}`,
  create: `${memberBase}/death/create?${roleQ}`,
  edit: `${memberBase}/death/edit?${roleQ}`,
};

const viewports = [
  { w: 1440, h: 900, bucket: '01-desktop' },
  { w: 1366, h: 768, bucket: '01-desktop' },
  { w: 820, h: 1180, bucket: '02-tablet' },
  { w: 768, h: 1024, bucket: '02-tablet' },
  { w: 390, h: 844, bucket: '03-mobile' },
  { w: 360, h: 800, bucket: '03-mobile' },
];

const inventory = {
  screenshots: [],
  viewports: {},
  overflow: {},
  notes: [],
  errors: [],
  passLabel: 'death-phase1-ui-refinement',
};

function out(...parts) {
  return path.join(__dirname, 'ui-refinement', ...parts);
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
        <span style="opacity:.7">CSS px · Death Phase 1 evidence</span>
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
    const doc = document.documentElement;
    const body = document.body;
    const scrollWidth = Math.max(doc.scrollWidth, body?.scrollWidth || 0);
    const clientWidth = doc.clientWidth;
    return {
      scrollWidth,
      clientWidth,
      overflowX: scrollWidth > clientWidth + 1,
    };
  });
}

async function shot(page, relPath, w, h) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(200);
  await page.evaluate(() => window.scrollTo(0, 0));
  await installDeviceToolbar(page, w, h);
  const file = out(relPath);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.screenshot({ path: file, fullPage: false });
  inventory.screenshots.push(relPath.replace(/\\/g, '/'));
  const overflow = await measureOverflow(page);
  inventory.overflow[relPath.replace(/\\/g, '/')] = overflow;
  return file;
}

async function captureState(page, stateKey, url, filePrefix) {
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-death]', { timeout: 10000 });
  for (const vp of viewports) {
    const key = `${vp.w}x${vp.h}`;
    if (!inventory.viewports[key]) {
      inventory.viewports[key] = { status: 'PASS', files: [] };
    }
    const rel = path.join(vp.bucket, `${filePrefix}-${key}.png`);
    await shot(page, rel, vp.w, vp.h);
    inventory.viewports[key].files.push(rel.replace(/\\/g, '/'));
  }
  inventory.notes.push({ state: stateKey, url, status: 'captured' });
}

async function seedRecord(page) {
  await page.goto(urls.create, { waitUntil: 'networkidle' });
  const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
  if (mode === 'edit') {
    inventory.notes.push({ step: 'seed-record', status: 'already-present-edit-redirect' });
    await page.fill('#lml-death-cause', 'Pneumonia');
    await page.fill('#lml-death-date', '2026-03-15');
    await Promise.all([
      page.waitForURL(/\/death(?:\?|$)/),
      page.click('[data-death-save]'),
    ]);
    return;
  }

  await page.fill('#lml-death-cause', 'Pneumonia');
  await page.fill('#lml-death-date', '2026-03-15');
  await Promise.all([
    page.waitForURL(/\/death(?:\?|$)/),
    page.click('[data-death-save]'),
  ]);
  await page.waitForSelector('[data-lml-death-mode="view"]', { timeout: 10000 });
  inventory.notes.push({ step: 'seed-record', status: 'created-via-session' });
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  try {
    // Ensure empty session for no-record captures: use fresh context storage
    await page.goto(urls.death, { waitUntil: 'networkidle' });
    const startMode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
    inventory.notes.push({ step: 'initial-mode', mode: startMode });

    if (startMode !== 'empty') {
      // Fresh browser context should be empty; if not, still capture current empty-or-view later.
      inventory.notes.push({
        step: 'initial-mode-warning',
        message: 'Expected empty session for no-record capture; continuing with available state.',
      });
    }

    // NO RECORD — if not empty, open a different member that should be empty
    if (startMode === 'empty') {
      await captureState(page, 'no-record', urls.death, 'death-no-record');
    } else {
      const other = `${base}/household-profiling/${hh}/members/MB-001/death?${roleQ}`;
      await captureState(page, 'no-record-fallback-mb001', other, 'death-no-record');
    }

    // CREATE
    await captureState(page, 'create', urls.create, 'death-create');

    // Seed recorded view
    await seedRecord(page);

    // RECORDED / VIEW
    await captureState(page, 'view', urls.death, 'death-view');

    // EDIT
    await captureState(page, 'edit', urls.edit, 'death-edit');

    // Member link sanity
    await page.goto(urls.member, { waitUntil: 'networkidle' });
    const deathLink = page.locator('[data-hh-member-death]');
    const href = await deathLink.getAttribute('href');
    inventory.notes.push({ step: 'member-death-link', href });
    await page.setViewportSize({ width: 1440, height: 900 });
    await installDeviceToolbar(page, 1440, 900);
    await shot(page, path.join('04-interactions', 'member-view-death-link-1440x900.png'), 1440, 900);
  } catch (err) {
    inventory.errors.push(String(err && err.stack ? err.stack : err));
    throw err;
  } finally {
    await browser.close();
    const summaryPath = out('evidence-summary.json');
    fs.writeFileSync(summaryPath, JSON.stringify(inventory, null, 2));
    console.log(JSON.stringify(inventory, null, 2));
    console.log(`Wrote ${summaryPath}`);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});

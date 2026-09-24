/**
 * Death member-card + content-scale refinement evidence.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outRoot = path.join(__dirname, 'member-card-scale-refinement');
const base = 'http://127.0.0.1:8765';
const mb = 'MB-002';
const hh = 'HH-151';
const q = 'role=bhw&v=death-member-card-scale';
const urls = {
  death: `${base}/household-profiling/${hh}/members/${mb}/death?${q}`,
  create: `${base}/household-profiling/${hh}/members/${mb}/death/create?${q}`,
  edit: `${base}/household-profiling/${hh}/members/${mb}/death/edit?${q}`,
};
const viewports = [
  { w: 1440, h: 900, bucket: '01-desktop' },
  { w: 1366, h: 768, bucket: '01-desktop' },
  { w: 820, h: 1180, bucket: '02-tablet' },
  { w: 768, h: 1024, bucket: '02-tablet' },
  { w: 390, h: 844, bucket: '03-mobile' },
  { w: 360, h: 800, bucket: '03-mobile' },
];

const inventory = { screenshots: [], checks: {}, overflow: {}, errors: [] };

async function measureMemberCard(page) {
  return page.evaluate(() => {
    const dl = document.querySelector('[data-death-member-meta]');
    const items = [...(dl?.querySelectorAll('.lml-death__member-item') || [])];
    const avatar = document.querySelector('.lml-death__avatar');
    const name = document.querySelector('.lml-death__member-name');
    const cta = document.querySelector('[data-death-record-cta]');
    const empty = document.querySelector('[data-death-no-record]');
    const dlCs = dl ? getComputedStyle(dl) : null;
    const itemTops = items.map((el) => Math.round(el.getBoundingClientRect().top));
    const stacked =
      itemTops.length >= 3 &&
      itemTops[1] > itemTops[0] + 8 &&
      itemTops[2] > itemTops[1] + 8;
    const ctaCs = cta ? getComputedStyle(cta) : null;
    return {
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
      dlDirection: dlCs?.flexDirection ?? null,
      stacked,
      itemTops,
      avatarSize: avatar ? Math.round(avatar.getBoundingClientRect().height) : null,
      nameSize: name ? parseFloat(getComputedStyle(name).fontSize) : null,
      emptyHeight: empty ? Math.round(empty.getBoundingClientRect().height) : null,
      ctaBg: ctaCs?.backgroundColor ?? null,
      ctaColor: ctaCs?.color ?? null,
      ctaBorder: ctaCs?.borderTopColor ?? null,
      ctaClasses: cta?.className ?? null,
      overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
  });
}

async function shot(page, rel) {
  const file = path.join(outRoot, rel);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.screenshot({ path: file, fullPage: false });
  inventory.screenshots.push(rel.replace(/\\/g, '/'));
}

async function seedRecord(page) {
  await page.goto(urls.create, { waitUntil: 'networkidle' });
  const mode = await page.locator('[data-lml-death]').getAttribute('data-lml-death-mode');
  if (mode === 'edit' || mode === 'view') return;
  await page.fill('#lml-death-cause', 'Pneumonia');
  await page.fill('#lml-death-date', '2026-03-15');
  await Promise.all([page.waitForURL(/\/death(?:\?|$)/), page.click('[data-death-save]')]);
}

async function main() {
  fs.mkdirSync(outRoot, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  try {
    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.w, height: vp.h });
      await page.goto(urls.death, { waitUntil: 'networkidle' });
      await page.waitForSelector('[data-death-member-meta]');
      const key = `${vp.w}x${vp.h}`;
      const checks = await measureMemberCard(page);
      inventory.checks[`no-record-${key}`] = checks;
      inventory.overflow[`no-record-${key}`] = { overflowX: checks.overflowX };
      await shot(page, path.join(vp.bucket, `death-no-record-${key}.png`));
    }

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(urls.create, { waitUntil: 'networkidle' });
    await shot(page, '01-desktop/death-create-1440x900.png');
    inventory.checks.create = await measureMemberCard(page);

    await seedRecord(page);
    await page.goto(urls.death, { waitUntil: 'networkidle' });
    await shot(page, '01-desktop/death-view-1440x900.png');
    inventory.checks.view = await page.evaluate(() => ({
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
      hasEdit: !!document.querySelector('[data-death-edit]'),
      hasSave: !!document.querySelector('[data-death-save]'),
    }));

    await page.goto(urls.edit, { waitUntil: 'networkidle' });
    await shot(page, '01-desktop/death-edit-1440x900.png');
    inventory.checks.edit = await page.evaluate(() => ({
      mode: document.querySelector('[data-lml-death]')?.getAttribute('data-lml-death-mode'),
      hasSave: !!document.querySelector('[data-death-save]'),
    }));

    // tablet/mobile create sample
    for (const vp of [
      { w: 768, h: 1024, bucket: '02-tablet' },
      { w: 390, h: 844, bucket: '03-mobile' },
    ]) {
      await page.setViewportSize({ width: vp.w, height: vp.h });
      await page.goto(urls.create, { waitUntil: 'networkidle' });
      await shot(page, path.join(vp.bucket, `death-create-${vp.w}x${vp.h}.png`));
    }
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

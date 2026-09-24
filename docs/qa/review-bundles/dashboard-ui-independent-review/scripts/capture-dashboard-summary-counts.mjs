import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'screenshots', 'dashboard-indicators-width-align');
fs.mkdirSync(outDir, { recursive: true });

const dashUrl = 'http://127.0.0.1:8000/dashboard?role=bns';

const shots = [
  { file: 'desktop-1440x900.png', w: 1440, h: 900 },
  { file: 'laptop-1366x768.png', w: 1366, h: 768 },
  { file: 'tablet-820x1180.png', w: 820, h: 1180 },
  { file: 'mobile-390x844.png', w: 390, h: 844 },
];

const browser = await chromium.launch({
  headless: true,
  channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const report = [];

for (const shot of shots) {
  await page.setViewportSize({ width: shot.w, height: shot.h });
  await page.goto(dashUrl, { waitUntil: 'networkidle' });
  await page.waitForSelector('.lml-dash-home');
  await page.waitForSelector('.lml-dash-map .leaflet-container', { timeout: 15000 });
  await page.evaluate(() => window.scrollTo(0, 0));

  const metrics = await page.evaluate(() => {
    const home = document.querySelector('.lml-dash-home');
    const content = document.querySelector('.lml-dashboard__content');
    const rect = home?.getBoundingClientRect();
    const subtitle = document.querySelector('.lml-topbar__subtitle');
    const firstCard = document.querySelector('.lml-dash-count');
    const firstValue = firstCard?.querySelector('.lml-dash-count__value');
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      homeLeft: rect ? Math.round(rect.left) : null,
      homeRight: rect ? Math.round(rect.right) : null,
      homeWidth: rect ? Math.round(rect.width) : null,
      sidebarWidth: (() => {
        const el = document.querySelector('.lml-sidebar');
        if (!el || getComputedStyle(el).display === 'none') return 0;
        const vis = getComputedStyle(el).visibility;
        if (vis === 'hidden') return 0;
        return Math.round(el.getBoundingClientRect().width);
      })(),
      mainWidth: (() => {
        const el = document.querySelector('.lml-dashboard__main');
        return el ? Math.round(el.getBoundingClientRect().width) : null;
      })(),
      leftColWidth: (() => {
        const el = document.querySelector('.lml-dash-home__workspace-main');
        return el ? Math.round(el.getBoundingClientRect().width) : null;
      })(),
      rightColWidth: (() => {
        const el = document.querySelector('.lml-dash-home__workspace-side');
        return el ? Math.round(el.getBoundingClientRect().width) : null;
      })(),
      contentPaddingLeft: content ? getComputedStyle(content).paddingLeft : null,
      contentPaddingRight: content ? getComputedStyle(content).paddingRight : null,
      cardCount: document.querySelectorAll('[data-dash-count]').length,
      subtitleWhiteSpace: subtitle ? getComputedStyle(subtitle).whiteSpace : null,
      subtitleLineCount: subtitle
        ? Math.round(subtitle.getBoundingClientRect().height / parseFloat(getComputedStyle(subtitle).lineHeight))
        : null,
      hasLeaflet: Boolean(document.querySelector('.lml-dash-map .leaflet-container')),
      cardTextAlign: firstCard ? getComputedStyle(firstCard).textAlign : null,
      valueTextAlign: firstValue ? getComputedStyle(firstValue).textAlign : null,
      workspaceDisplay: (() => {
        const el = home?.querySelector('.lml-dash-home__workspace');
        return el ? getComputedStyle(el).display : null;
      })(),
      mapTop: (() => {
        const el = document.querySelector('.lml-dash-panel--map');
        return el ? Math.round(el.getBoundingClientRect().top) : null;
      })(),
      mapHeight: (() => {
        const el = document.querySelector('.lml-dash-map');
        return el ? Math.round(el.getBoundingClientRect().height) : null;
      })(),
      tableOverflow: (() => {
        const wrap = document.querySelector('.lml-dash-table-wrap');
        if (!wrap) return null;
        return wrap.scrollWidth > wrap.clientWidth + 1;
      })(),
      tableVerticalOverflow: (() => {
        const wrap = document.querySelector('.lml-dash-table-wrap');
        if (!wrap) return null;
        return wrap.scrollHeight > wrap.clientHeight + 1;
      })(),
      indicatorsHeight: (() => {
        const el = document.querySelector('.lml-dash-panel--indicators');
        return el ? Math.round(el.getBoundingClientRect().height) : null;
      })(),
      leftColumnHeight: (() => {
        const el = document.querySelector('.lml-dash-home__workspace-main');
        return el ? Math.round(el.getBoundingClientRect().height) : null;
      })(),
      summaryColumns: (() => {
        const el = document.querySelector('.lml-dash-home__grid--primary');
        return el ? getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length : null;
      })(),
      indicatorColumns: (() => {
        const el = document.querySelector('.lml-dash-indicators');
        return el ? getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length : null;
      })(),
      indicatorCount: document.querySelectorAll('[data-dash-indicator]').length,
      lastIndicatorSpan: (() => {
        const el = document.querySelector('.lml-dash-indicators .lml-dash-indicator:last-child');
        if (!el) return null;
        const cs = getComputedStyle(el);
        return cs.gridColumnStart === '1' && (cs.gridColumnEnd === '-1' || cs.gridColumnEnd === 'span 2');
      })(),
      summaryCardHeight: (() => {
        const el = document.querySelector('.lml-dash-count');
        return el ? Math.round(el.getBoundingClientRect().height) : null;
      })(),
      mapPanel: (() => {
        const el = document.querySelector('.lml-dash-panel--map');
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) };
      })(),
      tablePanel: (() => {
        const el = document.querySelector('.lml-dash-panel--table');
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) };
      })(),
      indicatorsPanel: (() => {
        const el = document.querySelector('.lml-dash-panel--indicators');
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) };
      })(),
    };
  });

  let mapPanned = false;
  if (shot.w === 1440) {
    const box = await page.locator('.lml-dash-map .leaflet-container').boundingBox();
    if (box) {
      const before = await page.evaluate(() => window.L
        ? null
        : document.querySelector('.leaflet-map-pane')?.style.transform || '');
      const paneBefore = await page.locator('.lml-dash-map .leaflet-map-pane').getAttribute('style');
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await page.mouse.down();
      await page.mouse.move(box.x + box.width / 2 + 80, box.y + box.height / 2, { steps: 8 });
      await page.mouse.up();
      const paneAfter = await page.locator('.lml-dash-map .leaflet-map-pane').getAttribute('style');
      mapPanned = paneBefore !== paneAfter;
      void before;
    }
  }

  await page.screenshot({
    path: path.join(outDir, shot.file),
    fullPage: shot.w < 500 || shot.h > 900,
  });

  report.push({ viewport: `${shot.w}x${shot.h}`, file: shot.file, mapPanned: shot.w === 1440 ? mapPanned : undefined, ...metrics });
  console.log('saved', shot.file, { ...metrics, mapPanned: shot.w === 1440 ? mapPanned : undefined });
}

if (shots.some((s) => s.w === 390)) {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(dashUrl, { waitUntil: 'networkidle' });
  await page.waitForSelector('.lml-dash-home');
  await page.waitForSelector('.lml-dash-map .leaflet-container', { timeout: 15000 });

  const clips = [
    { file: 'mobile-390-summary.png', sel: '.lml-dash-home__primary' },
    { file: 'mobile-390-map-table.png', sel: '.lml-dash-home__workspace-main' },
    { file: 'mobile-390-health-indicators.png', sel: '.lml-dash-panel--indicators' },
  ];

  for (const clip of clips) {
    const el = page.locator(clip.sel).first();
    await el.scrollIntoViewIfNeeded();
    await el.screenshot({ path: path.join(outDir, clip.file) });
    console.log('saved', clip.file);
  }
}

await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(dashUrl, { waitUntil: 'networkidle' });
await page.waitForSelector('.lml-dash-panel--indicators');
await page.locator('.lml-dash-panel--indicators').first().screenshot({
  path: path.join(outDir, 'desktop-1440-health-indicators.png'),
});
console.log('saved', 'desktop-1440-health-indicators.png');

await page.setViewportSize({ width: 390, height: 844 });
await page.goto(dashUrl, { waitUntil: 'networkidle' });
await page.waitForSelector('.lml-dash-home__primary');
const primary = page.locator('.lml-dash-home__primary');
const map = page.locator('.lml-dash-panel--map');
const box1 = await primary.boundingBox();
const box2 = await map.boundingBox();
if (box1 && box2) {
  const x = Math.min(box1.x, box2.x);
  const y = Math.min(box1.y, box2.y);
  const width = Math.max(box1.x + box1.width, box2.x + box2.width) - x;
  const height = Math.max(box1.y + box1.height, box2.y + box2.height) - y;
  await page.screenshot({
    path: path.join(outDir, 'mobile-390-overview-map.png'),
    clip: { x, y, width, height: Math.min(height, 844) },
  });
  console.log('saved', 'mobile-390-overview-map.png');
}

fs.writeFileSync(path.join(outDir, 'overflow-report.json'), JSON.stringify(report, null, 2));
await browser.close();

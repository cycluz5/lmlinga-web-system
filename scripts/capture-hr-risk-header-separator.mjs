/**
 * Health Records → Risk Assessment header-separator evidence.
 */
import { chromium } from 'playwright';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(
  __dirname,
  '../docs/qa/screenshots/health-records-risk-assessment-header-separator'
);
fs.mkdirSync(outDir, { recursive: true });

const base = process.env.RA_CAPTURE_BASE || 'http://127.0.0.1:8765';
const url = `${base}/health-records/risk-assessment`;

const viewports = [
  { name: '1440x900', width: 1440, height: 900 },
  { name: '820x1180', width: 820, height: 1180 },
  { name: '390x844', width: 390, height: 844 },
];

const browser = await chromium.launch({
  headless: true,
  channel: process.env.RA_CAPTURE_CHANNEL || 'msedge',
});
const page = await browser.newPage();

async function measure(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const ths = Array.from(document.querySelectorAll('.lml-hr-risk__table thead th'));

    const headers = ths.map((th) => {
      const main = th.querySelector('.lml-hr-risk__th-main');
      const sub = th.querySelector('.lml-hr-risk__th-sub');
      const subStyle = sub ? getComputedStyle(sub) : null;
      return {
        title: main?.textContent.trim() || '',
        hasSub: Boolean(sub),
        borderTop: subStyle ? `${subStyle.borderTopWidth} ${subStyle.borderTopStyle} ${subStyle.borderTopColor}` : null,
        paddingTop: subStyle?.paddingTop || null,
        marginTop: subStyle?.marginTop || null,
      };
    });

    return {
      scrollWidth: Math.max(doc.scrollWidth, body.scrollWidth),
      clientWidth: doc.clientWidth,
      overflow: Math.max(doc.scrollWidth, body.scrollWidth) > doc.clientWidth + 1,
      hasAdd: Boolean(document.querySelector('[data-hr-ra-add]')),
      hasExport: Boolean(document.querySelector('[data-hr-ra-export]')),
      headers,
    };
  });
}

const report = [];

for (const vp of viewports) {
  await page.setViewportSize({ width: vp.width, height: vp.height });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-lml-hr-risk]');
  const metrics = await measure(page);
  const file = `risk-assessment-${vp.name}-header-separator.png`;
  await page.screenshot({
    path: path.join(outDir, file),
    fullPage: false,
  });
  report.push({ viewport: vp.name, file, ...metrics });
  console.log(JSON.stringify({
    viewport: vp.name,
    overflow: metrics.overflow,
    hasAdd: metrics.hasAdd,
    fullNameHasSub: metrics.headers[0]?.hasSub,
    statusSeparators: metrics.headers.slice(1).map((h) => ({
      title: h.title,
      hasSub: h.hasSub,
      borderTop: h.borderTop,
    })),
  }));
}

fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));

const failing = report.filter((row) => {
  const fullNameOk = row.headers[0]?.title === 'Full Name' && row.headers[0]?.hasSub === false;
  const statusOk = row.headers.slice(1).every((h) => h.hasSub && h.borderTop?.startsWith('1px solid'));
  return row.overflow || row.hasAdd || !fullNameOk || !statusOk;
});

if (failing.length > 0) {
  console.error('HEADER SEPARATOR CHECK FAILED', JSON.stringify(failing, null, 2));
  await browser.close();
  process.exit(1);
}

console.log('saved', path.join(outDir, 'layout-measurements.json'));
await browser.close();

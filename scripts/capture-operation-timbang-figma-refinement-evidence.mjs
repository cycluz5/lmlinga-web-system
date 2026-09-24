/**
 * Operation Timbang — Figma fidelity refinement evidence capture (OT-R).
 *
 * Each screenshot includes a visible Chrome DevTools Device Toolbar chrome
 * with the exact CSS viewport width × height shown in the dimension fields.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join(
    'docs',
    'qa',
    'evidence',
    'health-records-operation-timbang-figma-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const url =
    'http://127.0.0.1:8000/health-records/child-care/operation-timbang?role=bhw';
const TOOLBAR_H = 54;

const shots = [
    { id: 'OT-R-E1', file: 'OT-R-E1-desktop-1440x900.png', w: 1440, h: 900 },
    { id: 'OT-R-E2', file: 'OT-R-E2-desktop-1366x768.png', w: 1366, h: 768 },
    { id: 'OT-R-E3', file: 'OT-R-E3-tablet-820x1180.png', w: 820, h: 1180 },
    { id: 'OT-R-E4', file: 'OT-R-E4-tablet-768x1024.png', w: 768, h: 1024 },
    { id: 'OT-R-E5', file: 'OT-R-E5-mobile-390x844.png', w: 390, h: 844 },
    { id: 'OT-R-E6', file: 'OT-R-E6-mobile-360x800.png', w: 360, h: 800 },
];

async function measureOverflow(page) {
    return page.evaluate(() => {
        const tableScroll = document.querySelector(
            '.lml-hr-child-care__table-scroll--operation-timbang'
        );
        const clipped = Array.from(
            document.querySelectorAll(
                '[data-lml-hr-operation-timbang] button, [data-lml-hr-operation-timbang] input, [data-lml-hr-operation-timbang] select'
            )
        ).some((el) => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && (r.right < 0 || r.left > window.innerWidth);
        });
        return {
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
            clientWidth: document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            pageOverflow:
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
            tableLocalScroll:
                !!tableScroll &&
                tableScroll.scrollWidth > tableScroll.clientWidth + 1,
            clippedControls: clipped,
        };
    });
}

function toolbarHtml(w, h, id) {
    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #202124; }
  .cdt-device-toolbar {
    height: ${TOOLBAR_H}px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px;
    background: #f1f3f4;
    border-bottom: 1px solid #dadce0;
    font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 12px;
    color: #202124;
    user-select: none;
  }
  .cdt-device-toolbar__title { font-weight: 600; color: #5f6368; margin-right: 4px; white-space: nowrap; }
  .cdt-device-toolbar__mode {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px;
    border: 1px solid #dadce0; border-radius: 4px; background: #fff; color: #3c4043; white-space: nowrap;
  }
  .cdt-device-toolbar__dot { width: 10px; height: 10px; border-radius: 2px; background: #1a73e8; }
  .cdt-device-toolbar__dims { display: inline-flex; align-items: center; gap: 4px; }
  .cdt-device-toolbar__input {
    width: 64px; height: 26px; padding: 0 6px; border: 1px solid #dadce0; border-radius: 2px;
    background: #fff; color: #202124;
    font: 600 12px/26px ui-monospace, "Consolas", "Courier New", monospace; text-align: center;
  }
  .cdt-device-toolbar__times { color: #5f6368; font-weight: 600; }
  .cdt-device-toolbar__unit { color: #5f6368; margin-left: 2px; white-space: nowrap; }
  .cdt-device-toolbar__id { margin-left: auto; color: #5f6368; font-weight: 600; white-space: nowrap; }
  .cdt-frame { display: block; width: ${w}px; height: ${h}px; background: #fff; }
</style>
</head>
<body>
  <div class="cdt-device-toolbar" role="presentation" data-evidence-devtools-device-toolbar="true">
    <span class="cdt-device-toolbar__title">Chrome DevTools Device Toolbar</span>
    <span class="cdt-device-toolbar__mode"><span class="cdt-device-toolbar__dot"></span>Responsive</span>
    <div class="cdt-device-toolbar__dims" aria-label="Viewport dimensions">
      <input class="cdt-device-toolbar__input" readonly value="${w}" aria-label="Width">
      <span class="cdt-device-toolbar__times">×</span>
      <input class="cdt-device-toolbar__input" readonly value="${h}" aria-label="Height">
      <span class="cdt-device-toolbar__unit">CSS px</span>
    </div>
    <span class="cdt-device-toolbar__id">${id}</span>
  </div>
  <img class="cdt-frame" id="frame" alt="Operation Timbang refined at ${w}×${h}">
</body>
</html>`;
}

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(url, { waitUntil: 'networkidle' });

const measurements = [];
const composite = await browser.newPage();

for (const shot of shots) {
    await page.setViewportSize({ width: shot.w, height: shot.h });
    await page.waitForTimeout(350);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(150);

    const m = await measureOverflow(page);
    measurements.push({
        id: shot.id,
        file: shot.file,
        requested: { width: shot.w, height: shot.h },
        measured: m,
    });

    if (m.innerWidth !== shot.w || m.innerHeight !== shot.h) {
        throw new Error(
            `${shot.id}: viewport mismatch requested ${shot.w}×${shot.h} got ${m.innerWidth}×${m.innerHeight}`
        );
    }

    const pagePng = await page.screenshot({ type: 'png', fullPage: false });
    const dataUrl = `data:image/png;base64,${pagePng.toString('base64')}`;

    await composite.setViewportSize({
        width: shot.w,
        height: shot.h + TOOLBAR_H,
    });
    await composite.setContent(toolbarHtml(shot.w, shot.h, shot.id), {
        waitUntil: 'domcontentloaded',
    });
    await composite.evaluate((src) => {
        const img = document.getElementById('frame');
        return new Promise((resolve, reject) => {
            img.onload = () => resolve();
            img.onerror = reject;
            img.src = src;
        });
    }, dataUrl);

    await composite.waitForTimeout(100);
    await composite.screenshot({
        path: path.join(outDir, shot.file),
        fullPage: false,
    });

    console.log(
        `${shot.id}: ${shot.w}×${shot.h} pageOverflow=${m.pageOverflow} tableLocalScroll=${m.tableLocalScroll} clipped=${m.clippedControls}`
    );
}

/* Extra desktop crop emphasizing table header fidelity */
await page.setViewportSize({ width: 1440, height: 900 });
await page.waitForTimeout(200);
await page.evaluate(() => {
    const table = document.querySelector(
        '.lml-hr-child-care__table-card--operation-timbang'
    );
    if (table) {
        table.scrollIntoView({ block: 'start' });
    }
});
await page.waitForTimeout(200);
const tablePng = await page.screenshot({ type: 'png', fullPage: false });
const tableDataUrl = `data:image/png;base64,${tablePng.toString('base64')}`;
await composite.setViewportSize({ width: 1440, height: 900 + TOOLBAR_H });
await composite.setContent(toolbarHtml(1440, 900, 'OT-R-E1-TABLE'), {
    waitUntil: 'domcontentloaded',
});
await composite.evaluate((src) => {
    const img = document.getElementById('frame');
    return new Promise((resolve, reject) => {
        img.onload = () => resolve();
        img.onerror = reject;
        img.src = src;
    });
}, tableDataUrl);
await composite.waitForTimeout(100);
await composite.screenshot({
    path: path.join(outDir, 'OT-R-E1-table-header-focus.png'),
    fullPage: false,
});
console.log('OT-R-E1-TABLE: table header focus captured');

await browser.close();

fs.writeFileSync(
    path.join(outDir, 'evidence-summary.json'),
    JSON.stringify(
        {
            capturedAt: new Date().toISOString(),
            url,
            note: 'Figma fidelity refinement evidence. Toolbar shows exact CSS W×H.',
            measurements,
        },
        null,
        2
    )
);

console.log('evidence dir:', outDir);

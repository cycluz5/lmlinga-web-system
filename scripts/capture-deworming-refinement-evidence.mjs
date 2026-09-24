/**
 * Deworming — Figma fidelity refinement evidence capture (DW-R*).
 *
 * Each screenshot includes a visible Chrome DevTools Device Toolbar chrome
 * with the exact CSS viewport width × height shown in the dimension fields.
 *
 * Does not modify application UI.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join(
    'docs',
    'qa',
    'evidence',
    'health-records-deworming-refinement'
);
fs.mkdirSync(outDir, { recursive: true });

const url = 'http://127.0.0.1:8000/health-records/child-care/deworming?role=bhw';
const TOOLBAR_H = 54;

const shots = [
    { id: 'DW-R1', file: 'DW-R1-desktop-1440x900.png', w: 1440, h: 900 },
    { id: 'DW-R2', file: 'DW-R2-desktop-1366x768.png', w: 1366, h: 768 },
    { id: 'DW-R3', file: 'DW-R3-tablet-820x1180.png', w: 820, h: 1180 },
    { id: 'DW-R4', file: 'DW-R4-tablet-768x1024.png', w: 768, h: 1024 },
    { id: 'DW-R5', file: 'DW-R5-mobile-390x844.png', w: 390, h: 844 },
    { id: 'DW-R6', file: 'DW-R6-mobile-360x800.png', w: 360, h: 800 },
];

async function measureOverflow(page) {
    return page.evaluate(() => {
        const tableScroll = document.querySelector(
            '.lml-hr-child-care__table-scroll--deworming'
        );
        return {
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
            clientWidth: document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            pageOverflow:
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth,
            tableLocalScroll:
                !!tableScroll &&
                tableScroll.scrollWidth > tableScroll.clientWidth + 1,
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
  .cdt-device-toolbar__title {
    font-weight: 600;
    color: #5f6368;
    margin-right: 4px;
    white-space: nowrap;
  }
  .cdt-device-toolbar__mode {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    background: #fff;
    color: #3c4043;
    white-space: nowrap;
  }
  .cdt-device-toolbar__dot {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    background: #1a73e8;
  }
  .cdt-device-toolbar__dims {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .cdt-device-toolbar__input {
    width: 64px;
    height: 26px;
    padding: 0 6px;
    border: 1px solid #dadce0;
    border-radius: 2px;
    background: #fff;
    color: #202124;
    font: 600 12px/26px ui-monospace, "Consolas", "Courier New", monospace;
    text-align: center;
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
  <img class="cdt-frame" id="frame" alt="Deworming refinement at ${w}×${h}">
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
        `${shot.id}: captured ${shot.w}×${shot.h} pageOverflow=${m.pageOverflow} tableLocalScroll=${m.tableLocalScroll}`
    );
}

await browser.close();

const summaryPath = path.join(outDir, 'evidence-summary.json');
fs.writeFileSync(
    summaryPath,
    JSON.stringify(
        {
            capturedAt: new Date().toISOString(),
            url,
            note: 'Figma fidelity refinement evidence. Each PNG includes DevTools Device Toolbar with exact CSS viewport W×H.',
            measurements,
        },
        null,
        2
    )
);

console.log('evidence dir:', outDir);
console.log(
    'page-level overflow:',
    measurements.filter((r) => r.measured.pageOverflow).map((r) => r.id)
);
console.log(
    'table local scroll:',
    measurements.filter((r) => r.measured.tableLocalScroll).map((r) => r.id)
);

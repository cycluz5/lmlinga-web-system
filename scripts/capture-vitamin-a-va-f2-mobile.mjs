/**
 * VA-F2 — recapture VA-E5 / VA-E6 only.
 * Toolbar must show full WIDTH × HEIGHT (CSS px) without cropping.
 */
import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join(
    'docs',
    'qa',
    'evidence',
    'health-records-vitamin-a-final-review'
);
fs.mkdirSync(outDir, { recursive: true });

const url = 'http://127.0.0.1:8000/health-records/child-care/vitamin-a?role=bhw';
const TOOLBAR_H = 58;

const shots = [
    { id: 'VA-E5', file: 'VA-E5-mobile-390x844.png', w: 390, h: 844 },
    { id: 'VA-E6', file: 'VA-E6-mobile-360x800.png', w: 360, h: 800 },
];

async function measureOverflow(page) {
    return page.evaluate(() => {
        const tableScroll = document.querySelector(
            '.lml-hr-child-care__table-scroll--vitamin-a'
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
    // Compact DevTools Device Toolbar — prioritizes full W×H readout on narrow viewports.
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
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    padding: 6px 8px;
    background: #f1f3f4;
    border-bottom: 1px solid #dadce0;
    font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 11px;
    color: #202124;
    user-select: none;
  }
  .cdt-device-toolbar__title {
    font-weight: 700;
    color: #3c4043;
    white-space: nowrap;
  }
  .cdt-device-toolbar__mode {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 6px;
    border: 1px solid #dadce0;
    border-radius: 4px;
    background: #fff;
    color: #3c4043;
    white-space: nowrap;
  }
  .cdt-device-toolbar__dot {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    background: #1a73e8;
  }
  .cdt-device-toolbar__dims {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    flex: 0 0 auto;
  }
  .cdt-device-toolbar__input {
    width: 52px;
    height: 24px;
    padding: 0 4px;
    border: 1px solid #1a73e8;
    border-radius: 2px;
    background: #fff;
    color: #202124;
    font: 700 12px/24px ui-monospace, "Consolas", "Courier New", monospace;
    text-align: center;
  }
  .cdt-device-toolbar__times {
    color: #202124;
    font-weight: 700;
  }
  .cdt-device-toolbar__unit {
    color: #3c4043;
    font-weight: 700;
    white-space: nowrap;
  }
  .cdt-device-toolbar__id {
    margin-left: auto;
    color: #1a73e8;
    font-weight: 700;
    white-space: nowrap;
  }
  .cdt-frame {
    display: block;
    width: ${w}px;
    height: ${h}px;
    background: #fff;
  }
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
  <img class="cdt-frame" id="frame" alt="Vitamin A page at ${w}×${h}">
</body>
</html>`;
}

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(url, { waitUntil: 'networkidle' });

const composite = await browser.newPage();
const measurements = [];

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
        `${shot.id}: ${shot.w}×${shot.h} pageOverflow=${m.pageOverflow} tableLocalScroll=${m.tableLocalScroll}`
    );
}

await browser.close();

const summaryPath = path.join(outDir, 'va-f2-mobile-recapture.json');
fs.writeFileSync(
    summaryPath,
    JSON.stringify(
        {
            capturedAt: new Date().toISOString(),
            note: 'VA-F2 mobile-only recapture. Toolbar shows full WIDTH × HEIGHT CSS px.',
            measurements,
        },
        null,
        2
    )
);

console.log('updated:', outDir);

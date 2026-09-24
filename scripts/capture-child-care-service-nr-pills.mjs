import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-service-nr-pills');
fs.mkdirSync(outDir, { recursive: true });

const pages = {
    'vitamin-a': 'http://127.0.0.1:8000/health-records/child-care/vitamin-a?role=bhw',
    deworming: 'http://127.0.0.1:8000/health-records/child-care/deworming?role=bhw',
    'operation-timbang': 'http://127.0.0.1:8000/health-records/child-care/operation-timbang?role=bhw',
};

const viewports = [
    { w: 1440, h: 900 },
    { w: 1366, h: 768 },
    { w: 820, h: 1180 },
    { w: 768, h: 1024 },
    { w: 390, h: 844 },
    { w: 360, h: 800 },
];

async function showViewportBadge(page) {
    await page.evaluate(() => {
        let el = document.getElementById('lml-viewport-evidence');
        if (!el) {
            el = document.createElement('div');
            el.id = 'lml-viewport-evidence';
            el.setAttribute('aria-hidden', 'true');
            el.style.cssText =
                'position:fixed;bottom:10px;left:10px;z-index:99999;background:rgba(17,24,39,.88);color:#fff;padding:6px 10px;font:600 12px/1.2 ui-monospace,monospace;border-radius:6px;pointer-events:none;box-shadow:0 2px 8px rgba(0,0,0,.25)';
            document.body.appendChild(el);
        }
        el.textContent = `viewport ${window.innerWidth}×${window.innerHeight} (CSS px)`;
    });
}

async function inspect(page) {
    return page.evaluate(() => {
        const pill = document.querySelector('[data-hr-cc-non-residents]');
        const title = document.querySelector('.lml-hr-child-care__title');
        const active = document.querySelector('.lml-hr-child-care__pill--active');
        const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
        const pr = pill?.getBoundingClientRect();
        const tr = title?.getBoundingClientRect();
        return {
            title: title?.textContent.trim() || null,
            pillText: pill?.textContent.replace(/\s+/g, ' ').trim() || null,
            pillHref: pill?.getAttribute('href') || null,
            pillTag: pill?.tagName || null,
            activeService: active?.textContent.replace(/\s+/g, ' ').trim() || null,
            inlineWithTitle: Boolean(pr && tr && Math.abs(pr.top - tr.top) < 16),
            overflow,
        };
    });
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const results = [];

for (const [name, url] of Object.entries(pages)) {
    await page.goto(url, { waitUntil: 'networkidle' });
    for (const vp of viewports) {
        await page.setViewportSize({ width: vp.w, height: vp.h });
        await page.waitForTimeout(200);
        const layout = await inspect(page);
        await showViewportBadge(page);
        const file = `${name}-${vp.w}x${vp.h}.png`;
        await page.screenshot({ path: path.join(outDir, file), fullPage: true });
        results.push({ page: name, file, ...vp, ...layout });
    }
}

await browser.close();
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(results, null, 2));
console.log(JSON.stringify({ overflowing: results.filter((r) => r.overflow), sample: results.filter((r) => r.w === 1440 || r.w === 820 || r.w === 390) }, null, 2));
if (results.some((r) => r.overflow)) {
    process.exitCode = 2;
}

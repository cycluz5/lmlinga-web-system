import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-label-cleanup');
fs.mkdirSync(outDir, { recursive: true });

const listingUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents?role=bhw';
const editUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents/crisley-f-fernando/edit?role=bhw';
const viewUrl = 'http://127.0.0.1:8000/health-records/child-care/non-residents/andrei-b-malaya?role=bhw';

const viewports = [
    { w: 1440, h: 900 },
    { w: 820, h: 1180 },
    { w: 390, h: 844 },
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

async function measureOverflow(page) {
    return page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    }));
}

async function shot(page, file, w, h) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(240);
    const overflow = await measureOverflow(page);
    await showViewportBadge(page);
    await page.screenshot({ path: path.join(outDir, file), fullPage: true });
    return { file, w, h, ...overflow };
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const overflowResults = [];

await page.goto(listingUrl, { waitUntil: 'networkidle' });
const listingDom = await page.evaluate(() => {
    const panel = document.querySelector('.lml-hr-cc-nr__panel');
    const topbar = document.querySelector('.lml-topbar__title');
    const breadcrumb = document.querySelector('.lml-hr-cc-nr__breadcrumb');
    const innerTitle = document.querySelector('h2.lml-hr-cc-nr__title');
    const add = document.querySelector('[data-hr-cc-nr-add]');
    const search = document.querySelector('[data-hr-cc-nr-search]');
    const barangay = document.querySelector('[data-hr-cc-nr-barangay]');
    const year = document.querySelector('[data-hr-cc-nr-year]');
    const table = document.querySelector('.lml-hr-cc-nr__table');
    const styles = panel ? getComputedStyle(panel) : null;
    return {
        shellTitle: topbar?.textContent.trim() || null,
        hasBreadcrumb: Boolean(breadcrumb),
        hasInnerTitle: Boolean(innerTitle),
        hasAdd: Boolean(add),
        hasSearch: Boolean(search),
        hasBarangay: Boolean(barangay),
        hasYear: Boolean(year),
        hasTable: Boolean(table),
        panelPaddingTop: styles?.paddingTop || null,
        panelFirstVisible: panel?.querySelector('.lml-hr-cc-nr__top, .lml-hr-cc-nr__filters')?.className || null,
    };
});
overflowResults.push({ page: 'listing', ...(await shot(page, '01-listing-1440.png', 1440, 900)) });
overflowResults.push({ page: 'listing', ...(await shot(page, '02-listing-820.png', 820, 1180)) });
overflowResults.push({ page: 'listing', ...(await shot(page, '03-listing-390.png', 390, 844)) });

await page.goto(editUrl, { waitUntil: 'networkidle' });
const editDom = await page.evaluate(() => {
    const banner = document.querySelector('.lml-hr-cc-nr__form-banner');
    const title = document.querySelector('#lml-hr-cc-nr-edit-title');
    const badge = banner?.querySelector('.lml-hr-cc-nr__nr-badge');
    const shell = document.querySelector('.lml-topbar__title');
    const br = banner?.getBoundingClientRect();
    const tr = title?.getBoundingClientRect();
    return {
        shellTitle: shell?.textContent.trim() || null,
        bannerText: banner?.innerText.replace(/\s+/g, ' ').trim() || null,
        hasBadge: Boolean(badge),
        titleCentered: Boolean(
            br && tr && Math.abs((tr.left + tr.width / 2) - (br.left + br.width / 2)) < 24
        ),
        hasCancel: Boolean(document.querySelector('[data-hr-cc-nr-cancel]')),
        hasSave: Boolean(document.querySelector('[data-hr-cc-nr-save]')),
        hasDelete: Boolean(document.querySelector('[data-hr-cc-nr-measure-delete], button')?.textContent.includes('Delete') && document.body.innerText.includes('>Delete<')),
    };
});
overflowResults.push({ page: 'edit', ...(await shot(page, '04-edit-1440.png', 1440, 900)) });
overflowResults.push({ page: 'edit', ...(await shot(page, '05-edit-820.png', 820, 1180)) });
overflowResults.push({ page: 'edit', ...(await shot(page, '06-edit-390.png', 390, 844)) });

await page.goto(viewUrl, { waitUntil: 'networkidle' });
const viewDom = await page.evaluate(() => ({
    hasProfileBadge: Boolean(document.querySelector('.lml-hr-cc-nr__nr-badge')),
    badgeText: document.querySelector('.lml-hr-cc-nr__nr-badge')?.textContent.trim() || null,
}));
overflowResults.push({ page: 'view', ...(await shot(page, '07-view-badge-1440.png', 1440, 900)) });

await browser.close();

const overflowing = overflowResults.filter((r) => r.overflow);
const report = { listingDom, editDom, viewDom, overflow: overflowResults, overflowing };
fs.writeFileSync(path.join(outDir, 'layout-measurements.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
console.log('screenshots:', outDir);
if (overflowing.length > 0) {
    process.exitCode = 2;
}

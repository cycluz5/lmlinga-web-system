/**
 * Final SBI evidence screenshots — required filenames for UI review package.
 * Viewport badge shows dimensions (Chrome DevTools-style evidence).
 */
const fs = require('fs');
const path = require('path');

const puppeteer = require('puppeteer-core');

const BASE =
    process.env.SBI_BASE_URL ||
    'http://127.0.0.1:8778/household-profiling/HH-151/members/MB-001/school-based-immunization';
const CHROME =
    process.env.CHROME_PATH ||
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT_DIR = path.join(
    __dirname,
    '..',
    'docs',
    'qa',
    'screenshots',
    'school-based-immunization-final'
);

async function badge(page, width, height) {
    await page.evaluate(
        (label) => {
            const existing = document.getElementById('lml-qa-viewport-badge');
            if (existing) existing.remove();
            const el = document.createElement('div');
            el.id = 'lml-qa-viewport-badge';
            el.textContent = label;
            el.setAttribute('aria-hidden', 'true');
            Object.assign(el.style, {
                position: 'fixed',
                top: '8px',
                right: '8px',
                zIndex: '2147483647',
                padding: '6px 10px',
                borderRadius: '6px',
                background: 'rgba(17, 24, 39, 0.88)',
                color: '#fff',
                font: '600 12px/1.2 Consolas, monospace',
                pointerEvents: 'none',
                boxShadow: '0 2px 8px rgba(0,0,0,0.25)',
            });
            document.body.appendChild(el);
        },
        `${width}×${height}`
    );
}

async function scrollTo(page, selector) {
    await page.$eval(selector, (el) => {
        el.scrollIntoView({ block: 'start', behavior: 'instant' });
    });
    await new Promise((r) => setTimeout(r, 250));
}

async function enterEdit(page) {
    await page.click('[data-sbi-edit]');
    await page.waitForSelector('[data-sbi-save]:not([hidden])', { timeout: 5000 });
}

async function savePreview(page) {
    await enterEdit(page);
    await page.click('[data-sbi-save]');
    await page.waitForFunction(() => {
        const t = document.querySelector('[data-sbi-toast]');
        return t && !t.hidden && (t.textContent || '').includes('Preview only');
    }, { timeout: 5000 });
}

async function measureOverflow(page) {
    return page.evaluate(() => {
        const doc = document.documentElement;
        const body = document.body;
        const scrollW = Math.max(doc.scrollWidth, body.scrollWidth);
        const clientW = doc.clientWidth;
        return {
            overflowX: scrollW > clientW + 1,
            scrollWidth: scrollW,
            clientWidth: clientW,
            hasFalseCompletion:
                !!document.querySelector('.lml-sbi__status--recorded') ||
                !!document.querySelector('.lml-sbi__hpv-badge') ||
                (document.body.innerText || '').includes('For 9 Years Old Female'),
            hpvHeading: (
                document.querySelector('#lml-sbi-hpv-heading')?.textContent || ''
            ).trim(),
            editVisible: !!document.querySelector('[data-sbi-edit]:not([hidden])'),
            saveHidden: !!document.querySelector('[data-sbi-save][hidden]'),
        };
    });
}

(async () => {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: true,
        defaultViewport: null,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const page = await browser.newPage();
    const checks = [];

    async function gotoFresh(width, height) {
        await page.setViewport({ width, height, deviceScaleFactor: 1 });
        await page.goto(`${BASE}?role=bhw`, {
            waitUntil: 'networkidle0',
            timeout: 60000,
        });
        await page.waitForSelector('[data-lml-sbi]', { timeout: 15000 });
    }

    async function shot(name, width, height, prepare) {
        await gotoFresh(width, height);
        if (typeof prepare === 'function') {
            await prepare(page);
            await new Promise((r) => setTimeout(r, 350));
        }
        await badge(page, width, height);
        const metrics = await measureOverflow(page);
        checks.push({ name, width, height, ...metrics });
        const file = path.join(OUT_DIR, name);
        await page.screenshot({ path: file, fullPage: false });
        console.log(`Wrote ${file}`);
    }

    // Desktop 1440×900
    await shot('desktop-1440-view.png', 1440, 900);
    await shot('desktop-1440-edit.png', 1440, 900, enterEdit);
    await shot('desktop-1440-after-save.png', 1440, 900, savePreview);

    // Tablet 820×1024
    await shot('tablet-820-top.png', 820, 1024);
    await shot('tablet-820-records.png', 820, 1024, async (p) => {
        await scrollTo(p, '.lml-sbi__records-head');
    });
    await shot('tablet-820-edit.png', 820, 1024, async (p) => {
        await enterEdit(p);
        await scrollTo(p, '.lml-sbi__records-head');
    });

    // Mobile 390×844
    await shot('mobile-390-summary.png', 390, 844);
    await shot('mobile-390-grade-1.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__grade-card--grade-1');
    });
    await shot('mobile-390-grade-7.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__grade-card--grade-7');
    });
    await shot('mobile-390-hpv.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__hpv-card');
    });
    await shot('mobile-390-vaccine-type.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__types-card');
    });
    await shot('mobile-390-edit.png', 390, 844, async (p) => {
        await enterEdit(p);
        await scrollTo(p, '.lml-sbi__records-head');
    });
    await shot('mobile-390-after-save.png', 390, 844, savePreview);

    await browser.close();

    const reportPath = path.join(
        __dirname,
        '..',
        'docs',
        'qa',
        'evidence',
        'school-based-immunization-refinement',
        '00-browser-checks.json'
    );
    fs.mkdirSync(path.dirname(reportPath), { recursive: true });
    fs.writeFileSync(reportPath, JSON.stringify(checks, null, 2));
    console.log('Wrote', reportPath);

    const bad = checks.filter((c) => c.overflowX || c.hasFalseCompletion);
    if (bad.length) {
        console.error('FAILED checks:', bad);
        process.exit(1);
    }
    console.log('All overflow/false-completion checks passed.');
})().catch((err) => {
    console.error(err);
    process.exit(1);
});

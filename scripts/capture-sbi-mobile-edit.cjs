/**
 * One-off: mobile 390 edit-mode evidence for SBI refine.
 */
const path = require('path');
const puppeteer = require('puppeteer-core');

const BASE =
    process.env.SBI_BASE_URL ||
    'http://127.0.0.1:8778/household-profiling/HH-151/members/MB-001/school-based-immunization';
const OUT = path.join(
    __dirname,
    '..',
    'docs',
    'qa',
    'screenshots',
    'school-based-immunization-refine',
    'mobile-390-edit.png'
);

(async () => {
    const browser = await puppeteer.launch({
        executablePath:
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        headless: true,
        args: ['--no-sandbox'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 1 });
    await page.goto(`${BASE}?role=bhw`, {
        waitUntil: 'networkidle0',
        timeout: 60000,
    });
    await page.waitForSelector('[data-lml-sbi]');
    await page.click('[data-sbi-edit]');
    await page.waitForSelector('[data-sbi-save]:not([hidden])');
    await page.evaluate(() => {
        const badge = document.createElement('div');
        badge.id = 'lml-qa-viewport-badge';
        badge.textContent = '390×844';
        badge.setAttribute('aria-hidden', 'true');
        Object.assign(badge.style, {
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
        });
        document.body.appendChild(badge);
    });
    await page.$eval('.lml-sbi__records-head', (el) =>
        el.scrollIntoView({ block: 'start' })
    );
    await page.screenshot({ path: OUT, fullPage: false });
    console.log('Wrote', OUT);
    await browser.close();
})().catch((err) => {
    console.error(err);
    process.exit(1);
});

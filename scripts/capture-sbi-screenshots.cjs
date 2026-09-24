/**
 * Capture School-Based Immunization QA screenshots (viewport evidence).
 * Uses system Chrome via puppeteer-core (no project dependency added).
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE =
    process.env.SBI_BASE_URL ||
    'http://127.0.0.1:8778/household-profiling/HH-151/members/MB-001/school-based-immunization';

const CHROME =
    process.env.CHROME_PATH ||
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

const OUT_SUBDIR = process.env.SBI_SHOT_DIR || 'school-based-immunization-refine';
const OUT_DIR = path.join(
    __dirname,
    '..',
    'docs',
    'qa',
    'screenshots',
    OUT_SUBDIR
);
async function main() {
    fs.mkdirSync(OUT_DIR, { recursive: true });

    let puppeteer;
    try {
        puppeteer = require('puppeteer-core');
    } catch {
        console.log('Installing puppeteer-core temporarily…');
        execSync('npm install --no-save puppeteer-core@23', {
            stdio: 'inherit',
            cwd: path.join(__dirname, '..'),
        });
        puppeteer = require('puppeteer-core');
    }

    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: true,
        defaultViewport: null,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const page = await browser.newPage();

    async function shot(name, width, height, prepare) {
        await page.setViewport({ width, height, deviceScaleFactor: 1 });
        await page.goto(`${BASE}?role=bhw`, {
            waitUntil: 'networkidle0',
            timeout: 60000,
        });
        await page.waitForSelector('[data-lml-sbi]', { timeout: 15000 });

        if (typeof prepare === 'function') {
            await prepare(page);
            await new Promise((r) => setTimeout(r, 400));
        }

        // Evidence overlay: visible viewport dimensions (not part of the app UI).
        await page.evaluate((label) => {
            const existing = document.getElementById('lml-qa-viewport-badge');
            if (existing) {
                existing.remove();
            }
            const badge = document.createElement('div');
            badge.id = 'lml-qa-viewport-badge';
            badge.textContent = label;
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
                boxShadow: '0 2px 8px rgba(0,0,0,0.25)',
            });
            document.body.appendChild(badge);
        }, `${width}×${height}`);

        const file = path.join(OUT_DIR, name);
        await page.screenshot({ path: file, fullPage: false });
        console.log(`Wrote ${file} (${width}x${height})`);
    }

    async function enterEdit(p) {
        await p.click('[data-sbi-edit]');
        await p.waitForSelector('[data-sbi-save]:not([hidden])', {
            timeout: 5000,
        });
    }

    async function savePreview(p) {
        await enterEdit(p);
        await p.click('[data-sbi-save]');
        await p.waitForFunction(() => {
            const t = document.querySelector('[data-sbi-toast]');
            return t && !t.hidden && (t.textContent || '').includes('Preview only');
        }, { timeout: 5000 });
    }

    async function scrollTo(p, selector) {
        await p.$eval(selector, (el) => {
            el.scrollIntoView({ block: 'start', behavior: 'instant' });
        });
    }

    // Desktop 1440
    await shot('desktop-1440-view.png', 1440, 900);
    await shot('desktop-1440-edit.png', 1440, 900, enterEdit);
    await shot('desktop-1440-hpv-vaccines-type.png', 1440, 900, async (p) => {
        await scrollTo(p, '.lml-sbi__hpv-card');
    });
    await shot('desktop-1440-after-save-toast.png', 1440, 900, savePreview);

    // Tablet 820
    await shot('tablet-820-edit.png', 820, 1024, enterEdit);
    await shot('tablet-820-hpv-vaccines-type.png', 820, 1024, async (p) => {
        await enterEdit(p);
        await scrollTo(p, '.lml-sbi__hpv-card');
    });

    // Mobile 390
    await shot('mobile-390-summary-start.png', 390, 844);
    await shot('mobile-390-edit.png', 390, 844, async (p) => {
        await enterEdit(p);
        await scrollTo(p, '.lml-sbi__records-head');
    });
    await shot('mobile-390-grades.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__grade-grid');
    });
    await shot('mobile-390-hpv.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__hpv-card');
    });
    await shot('mobile-390-vaccines-type.png', 390, 844, async (p) => {
        await scrollTo(p, '.lml-sbi__types-card');
    });
    await shot('mobile-390-after-save-toast.png', 390, 844, savePreview);

    await browser.close();
    console.log('Done.');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});

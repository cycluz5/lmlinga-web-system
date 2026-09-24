/**
 * Capture Death verification workflow screenshots (viewport evidence).
 * Uses system Chrome via puppeteer-core.
 */
const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');
const http = require('http');

const PORT = process.env.DEATH_SHOT_PORT || '8791';
const BASE = process.env.DEATH_SHOT_BASE || `http://127.0.0.1:${PORT}`;
const CHROME =
    process.env.CHROME_PATH ||
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT_DIR = path.join(
    __dirname,
    '..',
    'docs',
    'qa',
    'screenshots',
    'death-verification-workflow'
);
const PDF_PATH = path.join(OUT_DIR, '_upload-certificate.pdf');

const VIEWPORTS = [
    { name: '1440x900', width: 1440, height: 900 },
    { name: '1366x768', width: 1366, height: 768 },
    { name: '820x1180', width: 820, height: 1180 },
    { name: '390x844', width: 390, height: 844 },
];

function waitForHttp(url, timeoutMs = 20000) {
    const started = Date.now();
    return new Promise((resolve, reject) => {
        const ping = () => {
            http.get(url, (res) => {
                res.resume();
                resolve();
            }).on('error', () => {
                if (Date.now() - started > timeoutMs) {
                    reject(new Error('Timed out waiting for '+url));
                    return;
                }
                setTimeout(ping, 300);
            });
        };
        ping();
    });
}

async function main() {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    fs.writeFileSync(
        PDF_PATH,
        '%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'
    );

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

    const server = spawn('php', ['artisan', 'serve', `--port=${PORT}`], {
        cwd: path.join(__dirname, '..'),
        stdio: 'ignore',
        windowsHide: true,
    });

    try {
        await waitForHttp(BASE);
    } catch (err) {
        server.kill();
        throw err;
    }

    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: true,
        defaultViewport: null,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const page = await browser.newPage();
    const overflowLog = [];

    async function badge(label) {
        await page.evaluate((text) => {
            const existing = document.getElementById('lml-qa-viewport-badge');
            if (existing) {
                existing.remove();
            }
            const el = document.createElement('div');
            el.id = 'lml-qa-viewport-badge';
            el.textContent = text;
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
            });
            document.body.appendChild(el);
        }, label);
    }

    async function measureOverflow(state, vp) {
        const result = await page.evaluate(() => ({
            inner: window.innerWidth,
            scroll: document.documentElement.scrollWidth,
        }));
        overflowLog.push({
            state,
            viewport: vp.name,
            innerWidth: result.inner,
            scrollWidth: result.scroll,
            overflow: result.scroll > result.inner + 1,
        });
    }

    async function shot(state, vp) {
        await badge(`${vp.width}×${vp.height}`);
        await measureOverflow(state, vp);
        const file = path.join(OUT_DIR, `${state}-${vp.name}.png`);
        await page.screenshot({ path: file, fullPage: false });
        console.log('Wrote', file);
    }

    async function goto(url) {
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 60000 });
    }

    const deathForm = `${BASE}/health-records/death/HH-151/MB-001`;

    for (const vp of VIEWPORTS) {
        await page.setViewport({
            width: vp.width,
            height: vp.height,
            deviceScaleFactor: 1,
        });
        await goto(`${deathForm}?role=bhw`);
        await page.waitForSelector('[data-lml-hr-death-form]', { timeout: 15000 });
        await shot('A-bhw-form', vp);
        await shot('B-disabled-submit', vp);

        await page.$eval('[data-death-cause]', (el) => {
            el.value = 'Cardiac arrest';
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await page.$eval('[data-death-date]', (el) => {
            el.value = '2026-07-12';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await page.$eval('[data-death-certificate-no]', (el) => {
            el.value = '2026-RIC-004821';
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
        const input = await page.$('[data-death-certificate-input]');
        await input.uploadFile(PDF_PATH);
        await page.waitForFunction(() => {
            const btn = document.querySelector('[data-death-submit]');
            return btn && !btn.disabled;
        });
        await shot('C-completed-form', vp);

        await page.click('[data-death-submit]');
        await page.waitForSelector('[data-death-confirm]:not([hidden])');
        await shot('D-confirmation-modal', vp);
        await page.click('[data-death-confirm-cancel]');
    }

    // Submit once from desktop, then capture remaining states.
    await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
    await goto(`${deathForm}?role=bhw`);
    await page.waitForSelector('[data-death-cause]');
    await page.$eval('[data-death-cause]', (el) => {
        el.value = 'Cardiac arrest';
        el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await page.$eval('[data-death-date]', (el) => {
        el.value = '2026-07-12';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.$eval('[data-death-certificate-no]', (el) => {
        el.value = '2026-RIC-004821';
        el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await (await page.$('[data-death-certificate-input]')).uploadFile(PDF_PATH);
    await page.waitForFunction(() => {
        const btn = document.querySelector('[data-death-submit]');
        return btn && !btn.disabled;
    });
    await page.click('[data-death-submit]');
    await page.waitForSelector('[data-death-confirm]:not([hidden])');
    await page.click('[data-death-confirm-submit]');
    await page.waitForSelector('[data-death-pending-banner]', { timeout: 15000 });

    for (const vp of VIEWPORTS) {
        await page.setViewport({
            width: vp.width,
            height: vp.height,
            deviceScaleFactor: 1,
        });
        await goto(`${deathForm}?role=bhw`);
        await page.waitForSelector('[data-death-pending-banner]');
        await shot('E-pending-verification', vp);

        await goto(`${BASE}/death-requests?role=admin`);
        await page.waitForSelector('[data-lml-death-requests]');
        await shot('F-admin-list', vp);

        const review = await page.$('a.lml-hr-table__view-btn');
        if (review) {
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle0' }),
                review.click(),
            ]);
        } else {
            await goto(`${BASE}/death-requests/1?role=admin`);
        }
        await page.waitForSelector('[data-lml-death-verify]');
        await shot('G-admin-verify', vp);

        const rejectBtn = await page.$('[data-dr-open-reject]');
        if (rejectBtn) {
            await rejectBtn.click();
            await page.waitForSelector('[data-dr-reject]:not([hidden])');
            await shot('H-reject-reason', vp);
        }
    }

    fs.writeFileSync(
        path.join(OUT_DIR, 'overflow-report.json'),
        JSON.stringify(overflowLog, null, 2)
    );

    await browser.close();
    server.kill();
    console.log('Done.');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});

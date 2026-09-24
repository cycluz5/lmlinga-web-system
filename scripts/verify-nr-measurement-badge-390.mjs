import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const outDir = path.join('docs', 'qa', 'evidence', 'health-records-child-care-non-residents-view');
fs.mkdirSync(outDir, { recursive: true });

const pages = {
    add: {
        url: 'http://127.0.0.1:8000/health-records/child-care/non-residents/gabriel-allan-s-chua/nutrition/create?role=bhw',
        file: 'add-measurement-390.png',
    },
    edit: {
        url: 'http://127.0.0.1:8000/health-records/child-care/non-residents/gabriel-allan-s-chua/nutrition/NR-CC-NUT-GAB-003/edit?role=bhw',
        file: 'edit-measurement-390.png',
    },
};

async function inspectBadge(page) {
    return page.evaluate(() => {
        const badge = document.querySelector('.lml-hr-cc-nr__nr-badge');
        const profile = document.querySelector('.lml-hr-cc-nr__profile');
        const overflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
        if (!badge) {
            return {
                exists: false,
                text: null,
                visible: false,
                overflow,
                profileExists: Boolean(profile),
            };
        }
        const cs = getComputedStyle(badge);
        const rect = badge.getBoundingClientRect();
        const profileRect = profile?.getBoundingClientRect();
        const clippedByProfile = Boolean(
            profileRect &&
            (rect.right > profileRect.right + 1 || rect.bottom > profileRect.bottom + 1 || rect.left < profileRect.left - 1)
        );
        return {
            exists: true,
            text: badge.textContent.trim(),
            visible:
                cs.display !== 'none' &&
                cs.visibility !== 'hidden' &&
                Number(cs.opacity) > 0 &&
                rect.width > 0 &&
                rect.height > 0,
            display: cs.display,
            visibility: cs.visibility,
            opacity: cs.opacity,
            overflowHidden: cs.overflow,
            color: cs.color,
            background: cs.backgroundColor,
            rect: {
                x: Math.round(rect.x),
                y: Math.round(rect.y),
                width: Math.round(rect.width),
                height: Math.round(rect.height),
            },
            inViewport: rect.bottom > 0 && rect.top < window.innerHeight && rect.right > 0 && rect.left < window.innerWidth,
            clippedByProfile,
            overflow,
            formTitle: document.querySelector('#lml-hr-cc-nr-measure-title')?.textContent.replace(/\s+/g, ' ').trim() || null,
            childName: document.querySelector('#lml-hr-cc-nr-child-name')?.textContent.trim() || null,
        };
    });
}

const browser = await chromium.launch({
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const page = await browser.newPage();
const report = {};

for (const [key, spec] of Object.entries(pages)) {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(spec.url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    report[key] = {
        url: spec.url,
        ...(await inspectBadge(page)),
    };
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
    await page.screenshot({ path: path.join(outDir, spec.file), fullPage: true });
    report[key].file = path.join(outDir, spec.file);
}

await browser.close();
fs.writeFileSync(path.join(outDir, 'measurement-badge-390.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));

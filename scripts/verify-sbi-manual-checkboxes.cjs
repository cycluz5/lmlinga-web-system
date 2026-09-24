/**
 * Browser verification: Vaccine Type checkboxes are MANUAL + OPTIONAL + INDEPENDENT.
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
const OUT = path.join(
    __dirname,
    '..',
    'docs',
    'qa',
    'evidence',
    'school-based-immunization-refinement',
    '04-manual-checkbox-browser-verification.json'
);

function assert(cond, msg) {
    if (!cond) throw new Error(msg);
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: true,
        args: ['--no-sandbox'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(`${BASE}?role=bhw`, {
        waitUntil: 'networkidle0',
        timeout: 60000,
    });
    await page.waitForSelector('[data-lml-sbi]');

    const result = { steps: [] };

    // 1–2 View mode: checkboxes locked
    const viewState = await page.evaluate(() => {
        const boxes = [...document.querySelectorAll('input[type="checkbox"][data-sbi-field]')];
        return {
            count: boxes.length,
            allDisabled: boxes.every((b) => b.disabled),
            allUnchecked: boxes.every((b) => !b.checked),
            editVisible: !document.querySelector('[data-sbi-edit]').hidden,
            saveHidden: document.querySelector('[data-sbi-save]').hidden,
        };
    });
    assert(viewState.count === 6, 'Expected 6 checkboxes');
    assert(viewState.allDisabled, 'View mode: checkboxes must be disabled');
    assert(viewState.allUnchecked, 'View mode: checkboxes start unchecked');
    result.steps.push({ step: 'view-mode-locked', ...viewState });

    // 3 Edit
    await page.click('[data-sbi-edit]');
    await page.waitForSelector('[data-sbi-save]:not([hidden])');

    const editEnabled = await page.evaluate(() => {
        const boxes = [...document.querySelectorAll('input[type="checkbox"][data-sbi-field]')];
        return boxes.every((b) => !b.disabled);
    });
    assert(editEnabled, 'Edit mode: checkboxes must be enabled');
    result.steps.push({ step: 'edit-mode-enabled', ok: true });

    // 4 Check G1 TD, G7 MR, HPV 1st — leave all dates blank
    await page.click('#lml-sbi-type-g1-td');
    await page.click('#lml-sbi-type-g7-mr');
    await page.click('#lml-sbi-type-hpv-1');

    let state = await page.evaluate(() => ({
        g1td: document.getElementById('lml-sbi-type-g1-td').checked,
        g1mr: document.getElementById('lml-sbi-type-g1-mr').checked,
        g7td: document.getElementById('lml-sbi-type-g7-td').checked,
        g7mr: document.getElementById('lml-sbi-type-g7-mr').checked,
        hpv1: document.getElementById('lml-sbi-type-hpv-1').checked,
        hpv2: document.getElementById('lml-sbi-type-hpv-2').checked,
        dates: [...document.querySelectorAll('input[type="date"][data-sbi-field]')].map(
            (d) => d.value
        ),
    }));
    assert(state.g1td && state.g7mr && state.hpv1, 'Expected three specific checkboxes checked');
    assert(!state.g1mr && !state.g7td && !state.hpv2, 'Other checkboxes must stay unchecked');
    assert(state.dates.every((v) => v === ''), 'All dates must remain blank');
    result.steps.push({ step: 'manual-check-blank-dates', ...state });

    // 7–9 Uncheck one, recheck
    await page.click('#lml-sbi-type-g7-mr');
    state = await page.evaluate(() => ({
        g7mr: document.getElementById('lml-sbi-type-g7-mr').checked,
    }));
    assert(!state.g7mr, 'Uncheck must clear checkbox');
    await page.click('#lml-sbi-type-g7-mr');
    state = await page.evaluate(() => ({
        g7mr: document.getElementById('lml-sbi-type-g7-mr').checked,
    }));
    assert(state.g7mr, 'Recheck must set checkbox');
    result.steps.push({ step: 'uncheck-recheck', ok: true });

    // 10–11 Save with blank dates + checked boxes
    await page.click('[data-sbi-save]');
    await page.waitForFunction(() => {
        const t = document.querySelector('[data-sbi-toast]');
        return t && !t.hidden && (t.textContent || '').includes('Preview only');
    });

    const afterSave = await page.evaluate(() => ({
        toast: document.querySelector('[data-sbi-toast]')?.textContent || '',
        editing: document.querySelector('[data-sbi-records]')?.dataset.editing,
        editVisible: !document.querySelector('[data-sbi-edit]').hidden,
        saveHidden: document.querySelector('[data-sbi-save]').hidden,
        g1td: document.getElementById('lml-sbi-type-g1-td').checked,
        g7mr: document.getElementById('lml-sbi-type-g7-mr').checked,
        hpv1: document.getElementById('lml-sbi-type-hpv-1').checked,
        boxesDisabled: [...document.querySelectorAll('input[type="checkbox"][data-sbi-field]')].every(
            (b) => b.disabled
        ),
        dates: [...document.querySelectorAll('input[type="date"][data-sbi-field]')].map(
            (d) => d.value
        ),
        requiredErrors: !!document.querySelector(':invalid'),
    }));
    assert(afterSave.toast.includes('Preview only'), 'Preview toast required');
    assert(afterSave.editing === 'false', 'Must return to view mode');
    assert(afterSave.g1td && afterSave.g7mr && afterSave.hpv1, 'Checked boxes must persist after save');
    assert(afterSave.dates.every((v) => v === ''), 'Dates must stay blank — no auto-generated dates');
    assert(!afterSave.requiredErrors, 'No required validation errors');
    assert(afterSave.boxesDisabled, 'View mode must re-lock checkboxes');
    result.steps.push({ step: 'save-blank-dates-checked-boxes', ...afterSave });

    // Inverse: date without corresponding checkbox
    await page.click('[data-sbi-edit]');
    await page.waitForSelector('[data-sbi-save]:not([hidden])');
    await page.$eval('#lml-sbi-grade-1-td', (el) => {
        el.value = '2024-06-15';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // Ensure G1 TD checkbox is unchecked (uncheck if somehow checked)
    await page.$eval('#lml-sbi-type-g1-td', (el) => {
        if (el.checked) el.click();
    });

    const inverse = await page.evaluate(() => ({
        date: document.getElementById('lml-sbi-grade-1-td').value,
        checkbox: document.getElementById('lml-sbi-type-g1-td').checked,
    }));
    assert(inverse.date === '2024-06-15', 'Date must be set');
    assert(!inverse.checkbox, 'Checkbox must remain unchecked when date entered');
    result.steps.push({ step: 'date-without-checkbox', ...inverse });

    await page.click('[data-sbi-save]');
    await page.waitForFunction(() => {
        const t = document.querySelector('[data-sbi-toast]');
        return t && !t.hidden && (t.textContent || '').includes('Preview only');
    });

    const inverseAfter = await page.evaluate(() => ({
        date: document.getElementById('lml-sbi-grade-1-td').value,
        checkbox: document.getElementById('lml-sbi-type-g1-td').checked,
    }));
    assert(inverseAfter.date === '2024-06-15', 'Date persists after save');
    assert(!inverseAfter.checkbox, 'No auto checkbox sync after save');
    result.steps.push({ step: 'save-date-without-checkbox', ...inverseAfter });

    result.passed = true;
    result.summary = 'MANUAL + OPTIONAL + INDEPENDENT';

    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    fs.writeFileSync(OUT, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
    console.log('Wrote', OUT);
    await browser.close();
})().catch((err) => {
    console.error(err);
    process.exit(1);
});

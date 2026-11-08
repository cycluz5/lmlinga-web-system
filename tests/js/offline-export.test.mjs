/**
 * Offline export is blocked. Online server/map export stays unchanged.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createDocument } from './support/sidebar-mini-dom.mjs';

const exportSourcePath = path.resolve('resources/js/offline/offline-export.js');
const exportSource = readFileSync(exportSourcePath, 'utf8');
const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping-export.js'), 'utf8');
const appSource = readFileSync(path.resolve('resources/js/app.js'), 'utf8');
const hhSource = readFileSync(path.resolve('resources/js/pages/household-profiling.js'), 'utf8');

const {
    OFFLINE_EVENTS,
    OFFLINE_MESSAGES,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-status.js')).href);

const {
    EXPORT_UNAVAILABLE_MESSAGE,
    handleOfflineExportClick,
    syncExportControls,
} = await import(pathToFileURL(exportSourcePath).href);

function el(doc, tag, attrs = {}) {
    const node = doc.createElement(tag);
    Object.entries(attrs).forEach(([name, value]) => {
        if (name === 'type' || name.startsWith('data-') || name === 'href') {
            node.setAttribute(name, value);
        }
    });
    if (attrs.type) {
        node.setAttribute('type', attrs.type);
    }
    return node;
}

function mountDoc() {
    const doc = createDocument();
    const html = doc.createElement('html');
    doc.documentElement = html;
    const body = doc.createElement('body');
    html.appendChild(body);
    doc.body = body;
    return { doc, body };
}

function clickEvent(target) {
    return {
        target,
        defaultPrevented: false,
        stopped: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopImmediatePropagation() {
            this.stopped = true;
        },
        stopPropagation() {
            this.stopped = true;
        },
    };
}

function intercept(button, { online, notices = [], downloads = [] }) {
    return handleOfflineExportClick(clickEvent(button), {
        navigator: { onLine: online },
        downloadFn: (filename, csv) => downloads.push({ filename, csv }),
        window: {
            LmlingaOffline: {
                emit(name, detail) {
                    notices.push({ name, detail });
                },
            },
        },
    });
}

describe('offline export source contract', () => {
    it('does not generate files, read IndexedDB, or queue an export', () => {
        assert.match(appSource, /import '\.\/offline\/offline-export'/);
        assert.doesNotMatch(exportSource, /from ['"]\.\/offline-queue/);
        assert.doesNotMatch(exportSource, /enqueueOperation/);
        assert.doesNotMatch(exportSource, /listOperations/);
        assert.doesNotMatch(exportSource, /indexedDB/);
        assert.doesNotMatch(exportSource, /createObjectURL/);
        assert.doesNotMatch(exportSource, /new Blob/);
        assert.doesNotMatch(exportSource, /buildCsv/);
        assert.doesNotMatch(exportSource, /HOUSEHOLD_PROFILING_CSV/);
        assert.doesNotMatch(spotSource, /listOperations/);
        assert.doesNotMatch(spotSource, /enqueueOperation/);
        assert.doesNotMatch(spotSource, /exportMapTilesUnavailable/);
        assert.doesNotMatch(spotSource, /from ['"]\.\.\/offline\/offline-export/);
        assert.match(exportSource, /Export is available when online/);
        assert.match(spotSource, /OFFLINE_MESSAGES\.exportUnavailable/);
        assert.match(hhSource, /window\.location\.assign\(url\)/);
        assert.equal(OFFLINE_MESSAGES.exportUnavailable, 'Export is available when online.');
        assert.equal(EXPORT_UNAVAILABLE_MESSAGE, 'Export is available when online.');
    });
});

describe('offline export intercept', () => {
    it('does not intercept Household Profiling export while online', () => {
        const { doc, body } = mountDoc();
        const button = el(doc, 'button', { 'data-hh-export': '', type: 'button' });
        body.appendChild(button);
        const downloads = [];
        const result = intercept(button, { online: true, downloads });

        assert.equal(result.intercepted, false);
        assert.equal(downloads.length, 0);
        assert.equal(result.defaultPrevented, undefined);
    });

    it('blocks Household Profiling export while offline without a download', () => {
        const { doc, body } = mountDoc();
        const button = el(doc, 'button', { 'data-hh-export': '', type: 'button' });
        body.appendChild(button);
        const notices = [];
        const downloads = [];
        const event = clickEvent(button);
        const result = handleOfflineExportClick(event, {
            navigator: { onLine: false },
            downloadFn: (filename, csv) => downloads.push({ filename, csv }),
            window: {
                LmlingaOffline: {
                    emit(name, detail) {
                        notices.push({ name, detail });
                    },
                },
            },
        });

        assert.equal(result.intercepted, true);
        assert.equal(result.downloaded, false);
        assert.equal(downloads.length, 0);
        assert.equal(event.defaultPrevented, true);
        assert.equal(notices[0]?.name, OFFLINE_EVENTS.NOTICE);
        assert.equal(notices[0]?.detail?.message, 'Export is available when online.');
    });

    it('blocks Environmental Health, Health Records, and Spot Mapping exports while offline', () => {
        const { doc, body } = mountDoc();
        const controls = [
            el(doc, 'a', { 'data-eh-export': 'pdf', href: '/environmental-health/export?format=pdf' }),
            el(doc, 'button', { 'data-hr-cc-export': '', type: 'button' }),
            el(doc, 'button', { 'data-hr-ra-export': '', type: 'button' }),
            el(doc, 'button', { 'data-spot-map-export': 'png', type: 'button' }),
        ];
        controls.forEach((control) => body.appendChild(control));

        controls.forEach((control) => {
            const notices = [];
            const downloads = [];
            const result = intercept(control, { online: false, notices, downloads });
            assert.equal(result.intercepted, true, control.tagName);
            assert.equal(result.downloaded, false);
            assert.equal(downloads.length, 0);
            assert.equal(notices[0]?.detail?.message, 'Export is available when online.');
        });
    });

    it('does not use IndexedDB queue data while blocking export', () => {
        assert.doesNotMatch(exportSource, /lmlinga_offline/);
        assert.doesNotMatch(exportSource, /PLOT_HOUSEHOLD_WITH_HEAD/);
        const { doc, body } = mountDoc();
        const button = el(doc, 'button', { 'data-hh-export': '', type: 'button' });
        body.appendChild(button);
        const result = intercept(button, { online: false });
        assert.equal(result.intercepted, true);
        assert.equal(result.downloaded, false);
    });

    it('disables export controls while offline and restores them online', () => {
        const { doc, body } = mountDoc();
        const button = el(doc, 'button', { 'data-hh-export': '', type: 'button' });
        const link = el(doc, 'a', { 'data-eh-export': 'csv', href: '/environmental-health/export' });
        body.appendChild(button);
        body.appendChild(link);

        syncExportControls(body, true);
        assert.equal(button.disabled, true);
        assert.equal(button.getAttribute('aria-disabled'), 'true');
        assert.equal(link.getAttribute('aria-disabled'), 'true');
        assert.equal(link.getAttribute('tabindex'), '-1');

        syncExportControls(body, false);
        assert.equal(button.disabled, false);
        assert.equal(button.getAttribute('aria-disabled'), null);
        assert.equal(link.getAttribute('aria-disabled'), null);
        assert.equal(link.getAttribute('tabindex'), null);
    });
});

describe('spot mapping online export remains client-side capture', () => {
    it('refuses offline Save Map before capturing or downloading', () => {
        assert.match(spotSource, /if \(isClientOffline\(options\)\)/);
        assert.match(spotSource, /throw new Error\(OFFLINE_MESSAGES\.exportUnavailable\)/);
        assert.match(spotSource, /toPng/);
        assert.match(spotSource, /jsPDF/);
        assert.doesNotMatch(spotSource, /createObjectURL/);
        assert.doesNotMatch(spotSource, /exportSuccess/);
    });
});

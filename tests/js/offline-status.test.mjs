/**
 * Offline status presentation — resources/js/offline/offline-status.js
 *
 * Runtime: Node.js built-in test runner (node:test).
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createDocument } from './support/sidebar-mini-dom.mjs';
import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const dbModuleUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const queueModuleUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbModuleUrl);
const queue = await import(queueModuleUrl);

const sourcePath = path.resolve('resources/js/offline/offline-status.js');
const source = readFileSync(sourcePath, 'utf8');
const moduleUrl = pathToFileURL(sourcePath).href;

const {
    ATTENTION_MESSAGES,
    OFFLINE_EVENTS,
    OFFLINE_MESSAGES,
    attentionMessageForCode,
    composeAttentionDialogMessage,
    attentionAckStorageKey,
    initOfflineStatus,
    sanitizeVisibleText,
    unsyncedAttentionMessage,
    safeLocalMemberEditHref,
} = await import(moduleUrl);

const instances = [];

afterEach(() => {
    while (instances.length) {
        const api = instances.pop();
        api?.destroy?.();
    }
});

function bindHidden(el) {
    let hidden = el.hasAttribute('hidden');
    Object.defineProperty(el, 'hidden', {
        configurable: true,
        get() {
            return hidden || el.hasAttribute('hidden');
        },
        set(value) {
            hidden = Boolean(value);
            if (hidden) {
                el.setAttribute('hidden', '');
            } else {
                el.removeAttribute('hidden');
            }
        },
    });
    return el;
}

function createMemoryStorage() {
    const map = new Map();
    return {
        getItem(key) {
            const name = String(key);
            return map.has(name) ? map.get(name) : null;
        },
        setItem(key, value) {
            map.set(String(key), String(value));
        },
        removeItem(key) {
            map.delete(String(key));
        },
        clear() {
            map.clear();
        },
    };
}

function createWin(overrides = {}) {
    const listeners = new Map();
    return {
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        navigator: { onLine: true },
        addEventListener(type, fn) {
            if (!listeners.has(type)) {
                listeners.set(type, []);
            }
            listeners.get(type).push(fn);
        },
        removeEventListener(type, fn) {
            const list = listeners.get(type) || [];
            listeners.set(type, list.filter((handler) => handler !== fn));
        },
        dispatchEvent(event) {
            const list = listeners.get(event.type) || [];
            list.forEach((handler) => handler(event));
            return true;
        },
        _listeners: listeners,
        ...overrides,
    };
}

function el(doc, tag, attrs = {}) {
    const node = bindHidden(doc.createElement(tag));
    Object.entries(attrs).forEach(([name, value]) => {
        if (name === 'text') {
            node.textContent = value;
            return;
        }
        node.setAttribute(name, value);
    });
    if (Object.prototype.hasOwnProperty.call(attrs, 'hidden')) {
        node.hidden = true;
    }
    return node;
}

function mountOfflineFixture() {
    const doc = createDocument();
    const origCreate = doc.createElement.bind(doc);
    doc.createElement = (tag) => bindHidden(origCreate(tag));

    const root = el(doc, 'div', {
        'data-lml-offline-root': '',
        'data-lml-offline-state': 'online',
        'data-offline-status-url': '/offline/status',
        'data-offline-actor-id': '7',
        class: 'lml-dashboard',
    });

    const topbar = el(doc, 'span', {
        class: 'lml-topbar__status lml-topbar__status--online',
        'data-lml-offline-topbar': '',
        'data-connectivity': 'online',
    });
    const topbarIcon = el(doc, 'i', {
        class: 'bi bi-wifi',
        'data-lml-offline-topbar-icon': '',
    });
    const topbarLabel = el(doc, 'span', {
        'data-lml-offline-topbar-label': '',
        text: 'Online',
    });
    topbar.appendChild(topbarIcon);
    topbar.appendChild(topbarLabel);
    const pendingChip = el(doc, 'span', {
        'data-lml-offline-pending': '',
        hidden: '',
    });
    const pendingLabel = el(doc, 'span', { 'data-lml-offline-pending-label': '' });
    pendingChip.appendChild(pendingLabel);
    topbar.appendChild(pendingChip);

    const live = el(doc, 'div', { 'data-lml-offline-live': '' });
    const banner = el(doc, 'div', {
        class: 'lml-offline-banner',
        'data-lml-offline-banner': '',
        hidden: '',
    });
    const bannerIcon = el(doc, 'i', {
        class: 'bi bi-wifi-off',
        'data-lml-offline-banner-icon': '',
    });
    const bannerText = el(doc, 'p', { 'data-lml-offline-banner-text': '' });
    const bannerReview = el(doc, 'button', {
        type: 'button',
        'data-lml-offline-banner-review': '',
        hidden: '',
        text: 'Review',
    });
    banner.appendChild(bannerIcon);
    banner.appendChild(bannerText);
    banner.appendChild(bannerReview);

    const toast = el(doc, 'div', {
        class: 'lml-offline-toast',
        'data-lml-offline-toast': '',
        hidden: '',
    });

    const dialog = el(doc, 'div', {
        class: 'lml-offline-dialog lml-offline-changes',
        'data-lml-offline-dialog': '',
        'data-lml-offline-changes': '',
        hidden: '',
    });
    const backdrop = el(doc, 'div', { 'data-lml-offline-dialog-backdrop': '' });
    const title = el(doc, 'h2', {
        'data-lml-offline-dialog-title': '',
        text: 'Offline Changes',
    });
    const body = el(doc, 'p', { 'data-lml-offline-dialog-body': '' });
    const tabs = el(doc, 'div', { 'data-lml-offline-changes-tabs': '' });
    ['all', 'waiting', 'attention'].forEach((key) => {
        const tab = el(doc, 'button', {
            type: 'button',
            'data-lml-offline-changes-tab': key,
            text: key,
        });
        tab.appendChild(el(doc, 'span', { 'data-lml-offline-changes-count': key, text: '0' }));
        tabs.appendChild(tab);
    });
    const changesList = el(doc, 'div', { 'data-lml-offline-changes-list': '' });
    const dialogEdit = el(doc, 'a', {
        'data-lml-offline-dialog-edit': '',
        'data-hh-nav': 'edit-member',
        hidden: true,
        text: 'Review Record',
    });
    const dismiss = el(doc, 'button', {
        type: 'button',
        'data-lml-offline-dialog-dismiss': '',
        text: 'Close',
    });
    const done = el(doc, 'button', {
        type: 'button',
        'data-lml-offline-changes-done': '',
        text: 'Close',
    });
    const card = el(doc, 'div', { class: 'lml-offline-dialog__card lml-offline-changes__card' });
    card.appendChild(title);
    card.appendChild(body);
    card.appendChild(tabs);
    card.appendChild(changesList);
    card.appendChild(dialogEdit);
    card.appendChild(dismiss);
    card.appendChild(done);
    dialog.appendChild(backdrop);
    dialog.appendChild(card);

    const discardDialog = el(doc, 'div', {
        class: 'lml-offline-dialog lml-offline-discard',
        'data-lml-offline-discard-dialog': '',
        hidden: '',
    });
    const discardBackdrop = el(doc, 'div', { 'data-lml-offline-discard-backdrop': '' });
    const discardTitle = el(doc, 'h2', {
        'data-lml-offline-discard-title': '',
        text: "Don't sync this change?",
    });
    const discardBody = el(doc, 'div', { 'data-lml-offline-discard-body': '' });
    const discardKeep = el(doc, 'button', {
        type: 'button',
        'data-lml-offline-discard-keep': '',
        text: 'Keep Change',
    });
    const discardConfirm = el(doc, 'button', {
        type: 'button',
        'data-lml-offline-discard-confirm': '',
        text: "Don't Sync",
    });
    const discardCard = el(doc, 'div', { class: 'lml-offline-dialog__card lml-offline-discard__card' });
    discardCard.appendChild(discardTitle);
    discardCard.appendChild(discardBody);
    discardCard.appendChild(discardKeep);
    discardCard.appendChild(discardConfirm);
    discardDialog.appendChild(discardBackdrop);
    discardDialog.appendChild(discardCard);

    root.appendChild(topbar);
    root.appendChild(live);
    root.appendChild(banner);
    root.appendChild(toast);
    root.appendChild(dialog);
    root.appendChild(discardDialog);

    doc.documentElement = root;
    doc.body = root;
    root.ownerDocument = doc;

    return {
        doc,
        root,
        topbar,
        topbarLabel,
        banner,
        bannerText,
        bannerReview,
        pendingChip,
        pendingLabel,
        toast,
        dialog,
        body,
        dialogEdit,
        dismiss,
        changesList,
        live,
        discardDialog,
        discardTitle,
        discardBody,
        discardKeep,
        discardConfirm,
    };
}

function onlineFetch() {
    return async () => ({
        ok: true,
        status: 200,
        json: async () => ({ ok: true, is_active: true }),
    });
}

function boot(options = {}) {
    const fixture = mountOfflineFixture();
    const sessionStorage = options.sessionStorage || createMemoryStorage();
    const win = createWin({
        sessionStorage,
        ...(options.window || {}),
    });
    const api = initOfflineStatus(fixture.root, {
        window: win,
        navigator: options.navigator || win.navigator,
        fetch: Object.prototype.hasOwnProperty.call(options, 'fetch') ? options.fetch : onlineFetch(),
        statusUrl: '/offline/status',
        toastDurationMs: options.toastDurationMs ?? 0,
        statusTimeoutMs: options.statusTimeoutMs ?? 50,
        recoveryPollMs: options.recoveryPollMs ?? 0,
        assignLocation: options.assignLocation,
    });
    instances.push(api);
    return { ...fixture, win, api, sessionStorage };
}

async function flush() {
    await Promise.resolve();
    await Promise.resolve();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('offline status presentation', () => {
    it('does not claim Online before the status probe resolves', () => {
        const { api, banner, toast, dialog, topbarLabel, root } = boot({
            fetch: () => new Promise(() => {}),
        });

        assert.equal(api.getState().ui, 'checking');
        assert.equal(api.getState().connectivity, 'checking');
        assert.equal(banner.hidden, true);
        assert.equal(toast.hidden, true);
        assert.equal(dialog.hidden, true);
        assert.equal(topbarLabel.textContent, 'Checking');
        assert.equal(root.getAttribute('data-lml-offline-state'), 'checking');
        assert.equal(banner.textContent.includes(OFFLINE_MESSAGES.connectivityLost), false);
    });

    it('keeps the normal online shell hidden until connectivity changes', async () => {
        const { api, banner, toast, dialog, topbarLabel, root } = boot();
        await flush();

        assert.equal(api.getState().ui, 'online');
        assert.equal(banner.hidden, true);
        assert.equal(toast.hidden, true);
        assert.equal(dialog.hidden, true);
        assert.equal(topbarLabel.textContent, 'Online');
        assert.equal(root.getAttribute('data-lml-offline-state'), 'online');
        assert.equal(banner.textContent.includes(OFFLINE_MESSAGES.connectivityLost), false);
    });

    it('page boot with navigator offline shows Offline immediately', () => {
        const { api, banner, bannerText, topbarLabel } = boot({
            navigator: { onLine: false },
            fetch: () => new Promise(() => {}),
        });

        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(api.getState().ui, 'offline');
        assert.equal(banner.hidden, false);
        assert.equal(bannerText.textContent, OFFLINE_MESSAGES.connectivityLost);
        assert.equal(topbarLabel.textContent, 'Offline');
    });

    it('cached navigation while navigator reports online still stays Offline', async () => {
        const fixture = mountOfflineFixture();
        const marker = el(fixture.doc, 'meta', {
            name: 'lmlinga-offline-cache',
            content: '1',
        });
        fixture.root.appendChild(marker);
        const win = createWin({ navigator: { onLine: true } });
        const api = initOfflineStatus(fixture.root, {
            window: win,
            navigator: win.navigator,
            fetch: async () => {
                throw new Error('sw-offline');
            },
            statusUrl: '/offline/status',
            toastDurationMs: 0,
            statusTimeoutMs: 50,
            recoveryPollMs: 0,
        });
        instances.push(api);

        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(fixture.topbarLabel.textContent, 'Offline');
        assert.equal(fixture.banner.hidden, false);
        await flush();
        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(fixture.topbarLabel.textContent, 'Offline');
        assert.equal(fixture.banner.hidden, false);
    });

    it('network probe failure despite navigator online becomes Offline', async () => {
        const { api, banner, topbarLabel } = boot({
            navigator: { onLine: true },
            fetch: async () => {
                throw new Error('network down');
            },
        });
        await flush();

        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(topbarLabel.textContent, 'Offline');
        assert.equal(banner.hidden, false);
    });

    it('probe success after reconnect becomes Online and keeps a single banner', async () => {
        let fail = true;
        const fetchImpl = async () => {
            if (fail) {
                throw new Error('offline');
            }
            return {
                ok: true,
                status: 200,
                json: async () => ({ ok: true, is_active: true }),
            };
        };
        const { win, api, banner, toast, topbarLabel } = boot({
            fetch: fetchImpl,
            recoveryPollMs: 20,
        });
        await flush();
        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(topbarLabel.textContent, 'Offline');
        assert.equal(banner.hidden, false);

        fail = false;
        win.dispatchEvent({ type: 'online' });
        await flush();
        await new Promise((resolve) => setTimeout(resolve, 40));

        assert.equal(api.getState().connectivity, 'online');
        assert.equal(topbarLabel.textContent, 'Online');
        assert.equal(banner.hidden, true);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.backOnline);
    });

    it('does not accumulate listeners after a second init on the same root', async () => {
        const { root, win } = boot();
        await flush();
        const before = (win._listeners.get('offline') || []).length;
        const again = initOfflineStatus(root, {
            window: win,
            navigator: win.navigator,
            fetch: onlineFetch(),
            statusUrl: '/offline/status',
            toastDurationMs: 0,
            recoveryPollMs: 0,
        });
        instances.push(again);
        const after = (win._listeners.get('offline') || []).length;
        assert.equal(after, before);
        assert.equal(after, 1);
    });

    it('activates the persistent offline banner from the native offline event', () => {
        const { win, api, banner, bannerText, topbarLabel, toast } = boot();

        win.dispatchEvent({ type: 'offline' });

        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(api.getState().ui, 'offline');
        assert.equal(banner.hidden, false);
        assert.equal(bannerText.textContent, OFFLINE_MESSAGES.connectivityLost);
        assert.equal(topbarLabel.textContent, 'Offline');
        assert.equal(toast.hidden, true);
        assert.equal(banner.classList.contains('lml-offline-banner--offline'), true);
    });

    it('activates the same offline state from lmlinga:offline', () => {
        const { win, api, bannerText } = boot();

        win.dispatchEvent({ type: OFFLINE_EVENTS.OFFLINE, detail: {} });

        assert.equal(api.getState().ui, 'offline');
        assert.equal(bannerText.textContent, OFFLINE_MESSAGES.connectivityLost);
    });

    it('confirms /offline/status after the native online event before claiming online', async () => {
        const calls = [];
        const fetchImpl = async (url, init) => {
            calls.push({ url, init });
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    ok: true,
                    is_active: true,
                    csrf_token: 'must-not-render-this-token',
                    password: 'must-not-render-password',
                }),
            };
        };
        const { win, api, banner, toast, topbarLabel } = boot({
            fetch: fetchImpl,
        });
        await flush();
        const bootCalls = calls.length;

        win.dispatchEvent({ type: 'offline' });
        win.dispatchEvent({ type: 'online' });
        await flush();

        assert.ok(calls.length > bootCalls);
        assert.equal(calls[0].url, '/offline/status');
        assert.equal(calls[0].init.method, 'GET');
        assert.equal(calls[0].init.headers.Accept, 'application/json');
        assert.equal(api.getState().connectivity, 'online');
        assert.equal(banner.hidden, true);
        assert.equal(topbarLabel.textContent, 'Online');
        assert.equal(toast.hidden, false);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.backOnline);
        assert.equal(toast.textContent.includes('must-not-render-this-token'), false);
        assert.equal(toast.textContent.includes('must-not-render-password'), false);
    });

    it('shows a local-save confirmation from lmlinga:offline-saved', () => {
        const { win, toast, api } = boot();

        win.dispatchEvent({ type: OFFLINE_EVENTS.SAVED, detail: { source: 'household' } });

        assert.equal(toast.hidden, false);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.localSave);
        assert.equal(toast.classList.contains('lml-offline-toast--saved'), true);
        assert.equal(api.getState().pendingCount, 1);
    });

    it('shows export notices without using sync-success copy', () => {
        const { win, toast } = boot();

        win.dispatchEvent({
            type: OFFLINE_EVENTS.NOTICE,
            detail: { message: OFFLINE_MESSAGES.exportUnavailable, source: 'export' },
        });

        assert.equal(toast.hidden, false);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.exportUnavailable);
        assert.equal(toast.classList.contains('lml-offline-toast--notice'), true);
        assert.equal(toast.classList.contains('lml-offline-toast--success'), false);
        assert.notEqual(toast.textContent, OFFLINE_MESSAGES.syncComplete);
        assert.equal(OFFLINE_MESSAGES.exportUnavailable, 'Export is available when online.');
    });

    it('shows a syncing banner from lmlinga:sync-start', async () => {
        const { win, api, banner, bannerText, topbarLabel } = boot();
        await flush();

        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_START, detail: { pending: 2 } });

        assert.equal(api.getState().sync, 'syncing');
        assert.equal(api.getState().ui, 'syncing');
        assert.equal(banner.hidden, false);
        assert.equal(bannerText.textContent, OFFLINE_MESSAGES.syncing);
        assert.equal(banner.classList.contains('lml-offline-banner--syncing'), true);
        assert.equal(api.getState().pendingCount, 2);
        assert.equal(topbarLabel.textContent, 'Syncing…');
    });

    it('shows a success toast from lmlinga:sync-success', async () => {
        const { win, api, banner, toast } = boot();
        await flush();

        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_START, detail: { pending: 1 } });
        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_SUCCESS, detail: { pending: 0 } });

        assert.equal(api.getState().sync, 'idle');
        assert.equal(api.getState().ui, 'online');
        assert.equal(banner.hidden, true);
        assert.equal(toast.hidden, false);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.syncComplete);
        assert.equal(toast.classList.contains('lml-offline-toast--success'), true);
    });

    it('does not treat attention leftovers as all caught up', async () => {
        const { win, api, toast, banner } = boot();
        await flush();

        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_SUCCESS, detail: { pending: 1 } });

        assert.equal(api.getState().ui, 'attention');
        assert.equal(api.getState().attentionItems.length, 1);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.syncNeedsAttentionOne);
        assert.notEqual(toast.textContent, OFFLINE_MESSAGES.syncComplete);
        assert.equal(banner.hidden, false);
        assert.equal(banner.classList.contains('lml-offline-banner--attention'), true);
    });

    it('shows a retry state from lmlinga:sync-retry', async () => {
        const { win, api, banner, bannerText, toast } = boot();
        await flush();

        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_RETRY, detail: { code: 'RETRYABLE_ERROR' } });

        assert.equal(api.getState().sync, 'retry');
        assert.equal(api.getState().ui, 'retry');
        assert.equal(banner.hidden, false);
        assert.equal(bannerText.textContent, OFFLINE_MESSAGES.syncRetry);
        assert.equal(banner.classList.contains('lml-offline-banner--retry'), true);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.syncRetry);
        assert.equal(toast.classList.contains('lml-offline-toast--retry'), true);
    });

    it('opens a distinct attention dialog and keeps the queued item', async () => {
        const { win, api, banner, bannerText, dialog, body, bannerReview } = boot();
        await flush();

        win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: { code: 'TARGET_CHANGED', itemId: 'op-1' },
        });

        assert.equal(api.getState().ui, 'attention');
        assert.equal(api.getState().attentionItems.length, 1);
        assert.equal(dialog.hidden, false);
        assert.equal(dialog.classList.contains('is-open'), true);
        assert.equal(body.textContent, ATTENTION_MESSAGES.TARGET_CHANGED);
        assert.equal(body.textContent.includes('TARGET_CHANGED'), false);
        assert.equal(dialog.getAttribute('data-offline-code'), 'TARGET_CHANGED');
        assert.equal(banner.hidden, false);
        assert.equal(banner.classList.contains('lml-offline-banner--attention'), true);
        assert.equal(bannerText.textContent, unsyncedAttentionMessage(1));
        assert.equal(bannerReview.hidden, false);
        assert.notEqual(api.getState().ui, 'retry');
        assert.notEqual(api.getState().ui, 'syncing');
    });

    it('never renders secrets from event details or status payloads', async () => {
        const secret =
            'password=hunter2 csrf_token=abc APP_KEY=base64:secret LMLINGA_AT_REST_KEY=nope session_id=xyz';
        const fetchImpl = async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                ok: true,
                is_active: true,
                csrf_token: 'csrf-live-token',
                password: 'hashed-password-value',
            }),
        });
        const { win, toast, dialog, body, banner, live } = boot({ fetch: fetchImpl });

        win.dispatchEvent({
            type: OFFLINE_EVENTS.SAVED,
            detail: { message: secret, password: 'hunter2', csrf_token: 'abc' },
        });
        assert.equal(toast.textContent, OFFLINE_MESSAGES.localSave);
        assert.equal(toast.textContent.includes('hunter2'), false);
        assert.equal(toast.textContent.includes('csrf-live-token'), false);

        win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: {
                code: 'VALIDATION_FAILED',
                message: secret,
                itemId: secret,
            },
        });

        const visible = [toast.textContent, body.textContent, banner.textContent, live.textContent].join('\n');
        assert.equal(visible.includes('hunter2'), false);
        assert.equal(visible.includes('APP_KEY'), false);
        assert.equal(visible.includes('LMLINGA_AT_REST_KEY'), false);
        assert.equal(visible.includes('csrf-live-token'), false);
        assert.equal(visible.includes('session_id'), false);
        assert.equal(body.textContent, ATTENTION_MESSAGES.VALIDATION_FAILED);
        assert.equal(dialog.hidden, false);

        win.dispatchEvent({ type: 'offline' });
        win.dispatchEvent({ type: 'online' });
        await flush();
        const afterOnline = [toast.textContent, body.textContent, banner.textContent].join('\n');
        assert.equal(afterOnline.includes('csrf-live-token'), false);
        assert.equal(afterOnline.includes('hashed-password-value'), false);
    });

    it('does not treat a failed status probe as online', async () => {
        const fetchImpl = async () => {
            throw new Error('network down');
        };
        const { win, api, banner, topbarLabel } = boot({ fetch: fetchImpl });

        win.dispatchEvent({ type: 'offline' });
        win.dispatchEvent({ type: 'online' });
        await flush();

        assert.equal(api.getState().connectivity, 'offline');
        assert.equal(topbarLabel.textContent, 'Offline');
        assert.equal(banner.hidden, false);
        assert.equal(api.getState().sync, 'retry');
    });

    it('identifies the failed local member and correction in the Review dialog', async () => {
        const { win, api, body, dialogEdit, bannerReview } = boot();
        await flush();

        win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: {
                code: 'VALIDATION_FAILED',
                id: 'op-vicky',
                operation_type: 'RESIDENT_CREATE',
                household_no: '999',
                member_name: 'Vicky Morales',
                member_no: 'MB-L-vicky1',
                reason: 'Sex is required.',
                href: '/household-profiling/999/members/MB-L-vicky1/edit',
            },
        });

        assert.equal(api.getState().ui, 'attention');
        assert.equal(body.textContent.includes('Household 999'), true);
        assert.equal(body.textContent.includes('Vicky Morales'), true);
        assert.equal(body.textContent.includes('Add Household Member'), true);
        assert.equal(body.textContent.includes('Sex is required.'), true);
        assert.equal(dialogEdit.hidden, false);
        assert.equal(dialogEdit.getAttribute('href'), '/household-profiling/999/members/MB-L-vicky1/edit');
        assert.equal(bannerReview.hidden, false);
        assert.equal(
            composeAttentionDialogMessage({
                household_no: '999',
                member_name: 'Vicky Morales',
                operation_type: 'RESIDENT_CREATE',
                reason: 'Sex is required.',
            }),
            'Household 999. Vicky Morales. Add Household Member. Needs correction: Sex is required.',
        );
    });

    it('clears attention after a successful catch-up', async () => {
        const { win, api, dialog } = boot();
        await flush();

        win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: { code: 'VALIDATION_FAILED', id: 'op-vicky' },
        });
        assert.equal(api.getState().attentionItems.length, 1);

        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_SUCCESS, detail: { pending: 0 } });
        assert.equal(api.getState().attentionItems.length, 0);
        assert.equal(api.getState().ui, 'online');
        assert.equal(dialog.hidden, true);
    });

    it('drops a repaired attention item when the same operation is saved again', async () => {
        const { win, api, dialog } = boot();
        await flush();

        win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: { code: 'VALIDATION_FAILED', id: 'op-vicky', household_no: '999', member_name: 'Vicky' },
        });
        win.dispatchEvent({
            type: OFFLINE_EVENTS.SAVED,
            detail: { pending: 1, local_id: 'op-vicky', operation_type: 'RESIDENT_CREATE' },
        });
        assert.equal(api.getState().attentionItems.length, 0);
        assert.equal(dialog.hidden, true);
    });

    it('keeps the attention banner while not auto-opening after acknowledgement', async () => {
        const sessionStorage = createMemoryStorage();
        const vicky = {
            code: 'VALIDATION_FAILED',
            id: 'op-vicky',
            operation_id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            operation_type: 'RESIDENT_CREATE',
            household_no: '999',
            member_name: 'Vicky Morales',
            member_no: 'MB-L-vicky1',
            reason: 'Sex is required.',
            href: '/household-profiling/999/members/MB-L-vicky1/edit',
            pending: 1,
        };

        const first = boot({ sessionStorage });
        await flush();
        first.win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_ATTENTION, detail: vicky });

        assert.equal(first.banner.hidden, false);
        assert.equal(first.bannerText.textContent, unsyncedAttentionMessage(1));
        assert.equal(first.bannerReview.hidden, false);
        assert.equal(first.pendingChip.hidden, false);
        assert.equal(first.pendingLabel.textContent, OFFLINE_MESSAGES.pendingOne);
        assert.equal(first.dialog.hidden, false);
        assert.equal(first.api.getState().attentionItems.length, 1);
        assert.equal(
            first.sessionStorage.getItem(
                attentionAckStorageKey(7, vicky.operation_id, vicky.code),
            ),
            '1',
        );

        first.dismiss.click();
        assert.equal(first.dialog.hidden, true);
        assert.equal(first.banner.hidden, false);
        assert.equal(first.api.getState().attentionItems.length, 1);
        assert.equal(first.api.getState().pendingCount, 1);
        first.api.destroy();

        const second = boot({ sessionStorage });
        await flush();
        second.win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_ATTENTION, detail: vicky });
        assert.equal(second.banner.hidden, false);
        assert.equal(second.bannerReview.hidden, false);
        assert.equal(second.dialog.hidden, true);
        assert.equal(second.api.getState().attentionItems.length, 1);
        assert.equal(second.api.getState().pendingCount, 1);

        second.bannerReview.click();
        await flush();
        assert.equal(second.dialog.hidden, false);
        assert.equal(second.api.getState().changesOpen, true);
        const listHtml = String(second.changesList?.innerHTML || '');
        assert.equal(listHtml.includes('Vicky Morales'), true);
        assert.equal(listHtml.includes('Sex is required.'), true);
        assert.equal(listHtml.includes('Review Record'), true);
        assert.equal(listHtml.includes('/household-profiling/999/members/MB-L-vicky1/edit'), true);

        second.dismiss.click();
        assert.equal(second.dialog.hidden, true);
        assert.equal(second.api.getState().attentionItems.length, 1);

        second.win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: {
                ...vicky,
                id: 'op-miles',
                operation_id: 'ffffffff-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                member_name: 'Miles Morales',
                member_no: 'MB-L-miles1',
                href: '/household-profiling/999/members/MB-L-miles1/edit',
                pending: 2,
            },
        });
        assert.equal(second.dialog.hidden, false);
        assert.equal(second.body.textContent.includes('Miles Morales'), true);
        assert.equal(second.api.getState().attentionItems.length, 2);

        second.win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_SUCCESS, detail: { pending: 0 } });
        assert.equal(second.banner.hidden, true);
        assert.equal(second.dialog.hidden, true);
        assert.equal(second.pendingChip.hidden, true);
        assert.equal(second.api.getState().attentionItems.length, 0);
        assert.equal(second.api.getState().pendingCount, 0);
        assert.equal(source.includes('deleteOperation'), false);
    });

    it('navigates from Review Edit member and only dismisses on Got it', async () => {
        const assignments = [];
        const vicky = {
            code: 'VALIDATION_FAILED',
            id: 'op-vicky',
            operation_id: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            operation_type: 'RESIDENT_CREATE',
            household_no: '999',
            member_name: 'Vicky Morales',
            member_no: 'MB-L-331929318FDA',
            reason: 'Sex is required.',
            href: '/household-profiling/999/members/MB-L-331929318FDA/edit',
            pending: 1,
        };
        const { win, dialog, dialogEdit, dismiss, bannerReview } = boot({
            sessionStorage: createMemoryStorage(),
            assignLocation(url) {
                assignments.push(url);
            },
        });
        await flush();
        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_ATTENTION, detail: vicky });
        assert.equal(dialog.hidden, false);
        assert.equal(dialogEdit.hidden, false);
        assert.equal(
            dialogEdit.getAttribute('href'),
            '/household-profiling/999/members/MB-L-331929318FDA/edit',
        );
        assert.equal(dialogEdit.tagName, 'A');

        dialogEdit.click();
        assert.deepEqual(assignments, ['/household-profiling/999/members/MB-L-331929318FDA/edit']);
        assert.equal(dialog.hidden, true);
        assert.equal(
            dialogEdit.getAttribute('href'),
            '/household-profiling/999/members/MB-L-331929318FDA/edit',
        );

        assignments.length = 0;
        bannerReview.click();
        await flush();
        assert.equal(dialog.hidden, false);
        dismiss.click();
        assert.equal(dialog.hidden, true);
        assert.deepEqual(assignments, []);
        assert.equal(source.includes("on(dialogEdit, 'click', () => {"), false);
    });
});

describe('offline status helpers', () => {
    it('maps attention codes to friendly copy and strips sensitive text', () => {
        assert.equal(attentionMessageForCode('SESSION_EXPIRED'), ATTENTION_MESSAGES.SESSION_EXPIRED);
        assert.equal(attentionMessageForCode('CSRF_MISMATCH'), ATTENTION_MESSAGES.CSRF_MISMATCH);
        assert.equal(sanitizeVisibleText('password=secret', 'fallback'), 'fallback');
        assert.equal(sanitizeVisibleText('Household 12', 'fallback'), 'Household 12');
        assert.equal(source.includes("method: 'POST'"), false);
        assert.equal(source.includes('localStorage.setItem'), false);
        assert.equal(source.includes('sessionStorage'), true);
        assert.equal(attentionAckStorageKey(7, 'op-1', 'VALIDATION_FAILED'), 'offline-attention-ack:7:op-1:VALIDATION_FAILED');
        assert.equal(
            safeLocalMemberEditHref('http://127.0.0.1:8000/household-profiling/999/members/MB-L-331929318FDA/edit'),
            '/household-profiling/999/members/MB-L-331929318FDA/edit',
        );
        assert.equal(
            safeLocalMemberEditHref('/household-profiling/999/members/MB-L-331929318FDA/edit'),
            '/household-profiling/999/members/MB-L-331929318FDA/edit',
        );
        assert.match(source, /\/offline\/status/);
        assert.doesNotMatch(source, /['"]\/offline\/sync['"]/);
        Object.values(OFFLINE_EVENTS).forEach((name) => {
            assert.equal(source.includes(name), true);
        });
        assert.equal(OFFLINE_MESSAGES.connectivityLost, "Offline. Changes will sync when you're back online.");
        assert.equal(OFFLINE_MESSAGES.backOnlineWithPending, 'Back online. Syncing pending changes…');
        assert.equal(OFFLINE_MESSAGES.syncing, 'Back online. Syncing pending changes…');
        assert.equal(OFFLINE_MESSAGES.syncComplete, 'All changes are synced.');
        assert.equal(OFFLINE_MESSAGES.localSave, 'Offline changes saved. Waiting to sync.');
        assert.equal(OFFLINE_MESSAGES.syncRetry, "Some changes couldn't sync. We'll retry.");
        assert.equal(OFFLINE_MESSAGES.exportUnavailable, 'Export is available when online.');
        assert.equal(OFFLINE_MESSAGES.viewHouseholdOffline, 'Reconnect to open this household.');
        assert.equal(OFFLINE_MESSAGES.viewMemberOffline, 'Reconnect to open this member.');
        assert.equal(OFFLINE_MESSAGES.addMemberOffline, 'Reconnect to add a member.');
        assert.equal(OFFLINE_MESSAGES.editMemberOffline, 'Reconnect to edit this member.');
        assert.equal(OFFLINE_MESSAGES.amenitiesOffline, 'Reconnect to open household details.');
        assert.equal(OFFLINE_EVENTS.NOTICE, 'lmlinga:notice');
    });
});

describe("Don't Sync attention flow — durable delete then UI refresh", () => {
    const ACTOR = 7;

    async function settle() {
        for (let i = 0; i < 25; i += 1) {
            await new Promise((resolve) => setTimeout(resolve, 4));
        }
    }

    function discardClick(dialog, localId) {
        const button = { getAttribute: (name) => (name === 'data-offline-local-id' ? localId : null) };
        dialog.dispatchEvent({
            type: 'click',
            target: {
                closest: (selector) => (String(selector).includes('data-lml-offline-change-discard') ? button : null),
            },
            preventDefault() {},
            stopPropagation() {},
        });
    }

    async function seedAndBoot(extra = {}) {
        resetFakeIndexedDB();
        setIndexedDBFactory(createFakeIndexedDB());
        const update = await queue.enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'RESIDENT_UPDATE',
            payload: { first_name: 'Dan', middle_name: 'Divino', last_name: 'Labobu' },
            parent_server: { household_no: '561', member_no: 'MB-038', household_id: 5, resident_id: 38 },
        });
        await queue.markAttention(update.local_id, 'TARGET_CHANGED');
        const health = await queue.enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-038' },
            parent_server: { household_no: '561', member_no: 'MB-038', household_id: 5, resident_id: 38 },
        });
        await queue.markRetry(health.local_id, 'NETWORK_ERROR', 1);
        const fixture = boot();
        await flush();
        fixture.win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: {
                code: 'TARGET_CHANGED',
                id: update.local_id,
                operation_id: update.operation_id,
                operation_type: 'RESIDENT_UPDATE',
                member_name: 'Dan Divino Labobu',
                household_no: '561',
                pending: 2,
                ...extra,
            },
        });
        return { ...fixture, update, health };
    }

    afterEach(() => {
        resetIndexedDBFactory();
        resetFakeIndexedDB();
    });

    it('passes the durable local_id, deletes that row, clears the card and refreshes the counts', async () => {
        const { api, dialog, discardDialog, discardConfirm, toast, update, health } = await seedAndBoot();

        assert.equal(api.getState().attentionItems.length, 1);
        assert.equal(api.getState().changesItems[0].id, update.local_id);
        assert.notEqual(update.local_id, update.operation_id);

        discardClick(dialog, update.local_id);
        await settle();
        assert.equal(discardDialog.hidden, false);
        discardConfirm.click();
        await settle();

        assert.equal(await queue.getOperation(update.local_id), null);
        const remaining = await queue.getOperation(health.local_id);
        assert.equal(remaining.status, 'retry');
        assert.equal(api.getState().attentionItems.length, 0);
        assert.equal(api.getState().changesItems.length, 0);
        assert.equal(api.getState().pendingCount, 1);
        assert.equal(dialog.hidden, true);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.discardToast);
    });

    it('treats an already-absent durable row as removed and clears the stale card', async () => {
        const { api, dialog, discardDialog, toast, update, health } = await seedAndBoot();
        await queue.deleteOperation(update.local_id);

        discardClick(dialog, update.local_id);
        await settle();

        assert.equal(discardDialog.hidden, true);
        assert.equal(api.getState().attentionItems.length, 0);
        assert.equal(api.getState().pendingCount, 1);
        assert.equal(toast.textContent, OFFLINE_MESSAGES.discardToast);
        assert.ok(await queue.getOperation(health.local_id));
    });

    it("keeps the card and reports failure for another actor's row (safety rejection preserved)", async () => {
        const foreign = await (async () => {
            resetFakeIndexedDB();
            setIndexedDBFactory(createFakeIndexedDB());
            return queue.enqueueOperation({
                actor_id: 99,
                operation_type: 'RESIDENT_UPDATE',
                payload: { first_name: 'Other' },
                parent_server: { household_no: '900', member_no: 'MB-900', household_id: 9, resident_id: 90 },
            });
        })();
        const fixture = boot();
        await flush();
        fixture.win.dispatchEvent({
            type: OFFLINE_EVENTS.SYNC_ATTENTION,
            detail: { code: 'TARGET_CHANGED', id: foreign.local_id, operation_type: 'RESIDENT_UPDATE', member_name: 'Other' },
        });

        discardClick(fixture.dialog, foreign.local_id);
        await settle();

        assert.equal(fixture.toast.textContent, OFFLINE_MESSAGES.discardFailed);
        assert.ok(await queue.getOperation(foreign.local_id));
        assert.equal(fixture.api.getState().attentionItems.length, 1);
    });

    it('rejects a missing local_id without removing anything', async () => {
        const { api, dialog, toast, update } = await seedAndBoot();
        discardClick(dialog, '');
        await settle();
        assert.equal(toast.textContent, OFFLINE_MESSAGES.discardFailed);
        assert.ok(await queue.getOperation(update.local_id));
        assert.equal(api.getState().attentionItems.length, 1);
    });
});

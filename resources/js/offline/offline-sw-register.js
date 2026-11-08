/**
 * Progressive service-worker registration for OFFLINE-7.
 *
 * Does not replace OFFLINE-6. Registration failure must not break the app.
 * IndexedDB is never deleted here — queued operations stay actor-bound.
 */

import { MESSAGE_CLEAR_HTML, MESSAGE_SET_ACTOR } from './offline-sw-policy.js';
import { confirmSessionAndWarmCorePages } from './offline-sw-warmup.js';
import { bootOfflinePreparation, clearLocalPrepared } from './offline-prepare.js';

const SW_URL = '/sw.js';

function shouldRegisterReason(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    if (!win || !doc) {
        return 'no-window-or-document';
    }
    if (!('serviceWorker' in win.navigator)) {
        return 'no-serviceWorker-api';
    }
    if (typeof options.isProduction === 'boolean' ? !options.isProduction : !import.meta.env.PROD) {
        return 'non-prod (Vite DEV / import.meta.env.PROD=false)';
    }
    if (!doc.querySelector('[data-lml-offline-root]')) {
        return 'no [data-lml-offline-root]';
    }
    const path = win.location?.pathname || '';
    if (/^\/(login|register|forgot-password|reset-password|change-password|chatbot|landing)(\/|$)/.test(path)) {
        return 'auth-or-public-path';
    }
    return null;
}

function shouldRegister(options = {}) {
    return shouldRegisterReason(options) === null;
}

/**
 * True when the page is an offline-capable staff dashboard (same gates as
 * shouldRegister except production). Used to still boot prep against an
 * already-controlling SW when Laravel is accidentally serving Vite DEV.
 */
function canBootPrepWithExistingController(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    if (!win || !doc) {
        return false;
    }
    if (!('serviceWorker' in win.navigator)) {
        return false;
    }
    if (!doc.querySelector('[data-lml-offline-root]')) {
        return false;
    }
    const path = win.location?.pathname || '';
    if (/^\/(login|register|forgot-password|reset-password|change-password|chatbot|landing)(\/|$)/.test(path)) {
        return false;
    }
    return Boolean(win.navigator.serviceWorker.controller);
}

function readActor(doc) {
    const root = doc.querySelector('[data-lml-offline-root]');
    const id = Number(root?.getAttribute?.('data-offline-actor-id') || 0);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function readAvatarUrl(doc) {
    const root = doc.querySelector('[data-lml-offline-root]');
    return root?.getAttribute?.('data-offline-actor-avatar') || '';
}

export function bindTopbarAvatarFallback(doc) {
    if (!doc || typeof doc.querySelectorAll !== 'function') {
        return;
    }
    doc.querySelectorAll('[data-lml-topbar-avatar]').forEach((img) => {
        if (img.getAttribute('data-lml-avatar-bound') === '1') {
            return;
        }
        img.setAttribute('data-lml-avatar-bound', '1');
        const showFallback = () => {
            img.hidden = true;
            img.setAttribute('hidden', '');
            const fallback = img.nextElementSibling;
            if (fallback && fallback.classList?.contains('lml-topbar__avatar-fallback')) {
                fallback.removeAttribute('hidden');
                fallback.hidden = false;
            }
        };
        img.addEventListener('error', showFallback);
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) {
            showFallback();
        }
    });
}

function postToWorker(worker, payload) {
    if (!worker || typeof worker.postMessage !== 'function') {
        return Promise.resolve(false);
    }
    return new Promise((resolve) => {
        let settled = false;
        const finish = (value) => {
            if (settled) {
                return;
            }
            settled = true;
            resolve(value);
        };
        let port = null;
        try {
            if (typeof MessageChannel === 'function') {
                const channel = new MessageChannel();
                port = channel.port2;
                channel.port1.onmessage = () => finish(true);
            }
            worker.postMessage(payload, port ? [port] : undefined);
            if (!port) {
                finish(true);
            }
        } catch {
            finish(false);
            return;
        }
        setTimeout(() => finish(false), 1500);
    });
}

async function refreshCsrfFromStatus(win, doc) {
    const root = doc.querySelector('[data-lml-offline-root]');
    const statusUrl = root?.getAttribute?.('data-offline-status-url') || '/offline/status';
    if (typeof win.fetch !== 'function') {
        return;
    }
    try {
        const response = await win.fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            return;
        }
        const payload = await response.json();
        const token = typeof payload?.csrf_token === 'string' ? payload.csrf_token : '';
        if (!token) {
            return;
        }
        const meta = doc.querySelector('meta[name="csrf-token"]');
        if (meta) {
            meta.setAttribute('content', token);
        }
        doc.querySelectorAll('input[name="_token"]').forEach((input) => {
            input.value = token;
        });
    } catch {
        // Offline or session-expired; OFFLINE-6 replay already handles CSRF refresh.
    }
}

function bindLogoutCleanup(doc, getWorker, win = typeof window !== 'undefined' ? window : null) {
    const forms = Array.from(doc.querySelectorAll('form[action]')).filter((form) => {
        const action = form.getAttribute('action') || '';
        return /\/logout\/?$/.test(action) || action.endsWith('/logout');
    });
    forms.forEach((form) => {
        form.addEventListener('submit', () => {
            const actorId = readActor(doc);
            clearLocalPrepared(win?.localStorage || null, actorId);
            void postToWorker(getWorker(), { type: MESSAGE_CLEAR_HTML });
        });
    });
}

function requestCoreWarmup(win, doc, worker) {
    return confirmSessionAndWarmCorePages({
        window: win,
        document: doc,
        worker,
        navigator: win.navigator,
        fetch: typeof win.fetch === 'function' ? win.fetch.bind(win) : undefined,
        actorId: readActor(doc),
        avatarUrl: readAvatarUrl(doc),
        statusUrl: doc.querySelector('[data-lml-offline-root]')?.getAttribute?.('data-offline-status-url') || '/offline/status',
    });
}

function requestBlockingPreparation(win, doc, worker) {
    return bootOfflinePreparation({
        window: win,
        document: doc,
        worker,
        navigator: win.navigator,
        fetch: typeof win.fetch === 'function' ? win.fetch.bind(win) : undefined,
        storage: win.localStorage,
        actorId: readActor(doc),
        statusUrl: doc.querySelector('[data-lml-offline-root]')?.getAttribute?.('data-offline-status-url') || '/offline/status',
    });
}

/**
 * @param {object} [options]
 */
export function registerOfflineServiceWorker(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);

    const getWorker = () =>
        win?.navigator?.serviceWorker?.controller
        || null;

    // Same sync used by the production registration path — SET_ACTOR before any warmup.
    const syncActor = (worker) => {
        const actorId = readActor(doc);
        return postToWorker(worker || getWorker(), { type: MESSAGE_SET_ACTOR, actorId });
    };

    const skipReason = shouldRegisterReason(options);

    if (skipReason) {
        // Stale public/hot → Vite DEV bundle → shouldRegister false, but an
        // already-activated SW may still control the page. Boot prep against it.
        // Must sync actor into SW meta first (same as PROD path) or warmCoreNavigation
        // returns no-actor with paths: 0.
        if (canBootPrepWithExistingController({ window: win, document: doc })) {
            const existing = win.navigator.serviceWorker.controller;
            void syncActor(existing)
                .then(() => {
                    void requestBlockingPreparation(win, doc, existing);
                })
                .catch(() => {});
            return null;
        }
        return null;
    }

    if (!win || !doc) {
        return null;
    }

    const registerImpl = options.register || win.navigator.serviceWorker.register.bind(win.navigator.serviceWorker);

    let registrationPromise;
    try {
        registrationPromise = registerImpl(SW_URL, { scope: '/' });
    } catch {
        return null;
    }

    bindTopbarAvatarFallback(doc);

    // Cached HTML strips CSRF; refill as soon as the SW controls this page.
    const metaToken = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!String(metaToken).trim()) {
        void refreshCsrfFromStatus(win, doc);
    }

    void Promise.resolve(registrationPromise)
        .then(async (registration) => {
            if (!registration) {
                return;
            }
            const worker = registration.active || registration.waiting || registration.installing;
            await syncActor(worker);
            bindLogoutCleanup(doc, () => registration.active || getWorker(), win);

            if (win.navigator.serviceWorker.ready) {
                try {
                    const ready = await win.navigator.serviceWorker.ready;
                    await syncActor(ready.active);
                    const warmer = ready.active || getWorker();
                    if (warmer) {
                        void requestBlockingPreparation(win, doc, warmer);
                    }
                } catch {
                    // Ignore.
                }
            }
        })
        .catch(() => {});

    win.navigator.serviceWorker.addEventListener?.('controllerchange', () => {
        const worker = getWorker();
        void syncActor(worker).then(() => {
            if (worker) {
                // New SW / version — force preparation gate via storage miss or ready query.
                void requestBlockingPreparation(win, doc, worker);
            }
        });
    });

    win.addEventListener('online', () => {
        void refreshCsrfFromStatus(win, doc);
        const worker = getWorker();
        if (worker) {
            void requestBlockingPreparation(win, doc, worker);
        }
    });

    return registrationPromise;
}

if (typeof document !== 'undefined') {
    const start = () => {
        bindTopbarAvatarFallback(document);
        registerOfflineServiceWorker();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}

export { shouldRegister, SW_URL };

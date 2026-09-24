/**
 * Client-side offline navigation guard for User Management Health Worker GETs.
 *
 * Cache Storage only (current actor HTML cache). Never IndexedDB, queue, or sync.
 * Create/Add stays online-only in Phase 1 — blocked while offline even if cached.
 */

import { isClientOffline } from './offline-forms.js';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from './offline-status.js';
import {
    htmlCacheNameForActor,
    navigationCacheUrl,
    normalizePathname,
} from './offline-sw-policy.js';

export const UM_NAV_KINDS = Object.freeze({
    VIEW_WORKER: 'view-worker',
    EDIT_WORKER: 'edit-worker',
    ADD_WORKER: 'add-worker',
});

export const UM_NAV_SELECTOR = 'a[data-um-nav]';

const KIND_PATHS = {
    [UM_NAV_KINDS.VIEW_WORKER]: /^\/user-management\/health-workers\/(?:[0-9]+|w[a-z2-7]{12,})\/view$/,
    [UM_NAV_KINDS.EDIT_WORKER]: /^\/user-management\/health-workers\/(?:[0-9]+|w[a-z2-7]{12,})\/edit$/,
    [UM_NAV_KINDS.ADD_WORKER]: /^\/user-management\/health-workers\/create$/,
};

export function messageForUserManagementNavKind(kind) {
    if (kind === UM_NAV_KINDS.EDIT_WORKER) {
        return OFFLINE_MESSAGES.editHealthWorkerOffline;
    }
    if (kind === UM_NAV_KINDS.ADD_WORKER) {
        return OFFLINE_MESSAGES.addHealthWorkerOffline;
    }
    return OFFLINE_MESSAGES.viewHealthWorkerOffline;
}

export function isUserManagementNavPath(pathname, kind) {
    const path = normalizePathname(pathname);
    if (kind) {
        return Boolean(KIND_PATHS[kind]?.test(path));
    }
    return Object.values(KIND_PATHS).some((pattern) => pattern.test(path));
}

export function kindFromUserManagementNavLink(link) {
    const nav = link?.getAttribute?.('data-um-nav') || '';
    return KIND_PATHS[nav] ? nav : null;
}

function readPageActorId(root) {
    const host =
        root?.closest?.('[data-lml-offline-root]')
        || (typeof root?.querySelector === 'function' ? root.querySelector('[data-offline-actor-id]') : null)
        || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);

    const raw = host?.getAttribute?.('data-offline-actor-id') || '';
    const id = Number.parseInt(String(raw), 10);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function emitNotice(message, options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const detail = { message, source: 'user-management-nav' };
    if (win?.LmlingaOffline && typeof win.LmlingaOffline.emit === 'function') {
        win.LmlingaOffline.emit(OFFLINE_EVENTS.NOTICE, detail);
        return;
    }
    if (typeof win?.dispatchEvent === 'function') {
        const event =
            typeof CustomEvent === 'function'
                ? new CustomEvent(OFFLINE_EVENTS.NOTICE, { detail })
                : { type: OFFLINE_EVENTS.NOTICE, detail };
        win.dispatchEvent(event);
    }
}

function showLocalToast(root, message, selector) {
    if (!root || typeof root.querySelector !== 'function') {
        return;
    }

    const toast =
        (selector ? root.querySelector(selector) : null)
        || root.querySelector('[data-um-toast]')
        || root.querySelector('[data-hw-wizard-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    const timers = typeof window !== 'undefined' ? window : globalThis;
    if (typeof timers.clearTimeout === 'function') {
        timers.clearTimeout(showLocalToast._timer);
    }
    if (typeof timers.setTimeout === 'function') {
        showLocalToast._timer = timers.setTimeout(() => {
            toast.hidden = true;
            toast.textContent = '';
        }, 3600);
    }
}

/**
 * Actor-scoped Cache Storage lookup for a User Management navigation URL.
 * Fail closed: missing actor, caches API, origin mismatch, unknown path, or match error.
 *
 * @param {string} href
 * @param {object} [options]
 */
export async function hasCachedUserManagementNav(href, options = {}) {
    const kind = options.kind || null;
    if (kind === UM_NAV_KINDS.ADD_WORKER) {
        return false;
    }

    const actorId = Number(options.actorId) > 0 ? Number(options.actorId) : readPageActorId(options.root);
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cacheName) {
        return false;
    }

    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    if (!cachesApi || typeof cachesApi.open !== 'function') {
        return false;
    }

    let parsed;
    try {
        parsed = new URL(
            String(href || ''),
            options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost'),
        );
    } catch {
        return false;
    }

    const origin = options.origin || parsed.origin;
    if (parsed.origin !== origin) {
        return false;
    }

    const pathname = normalizePathname(parsed.pathname);
    if (!isUserManagementNavPath(pathname, kind)) {
        return false;
    }

    try {
        const cache = await cachesApi.open(cacheName);
        const hit = await cache.match(new Request(navigationCacheUrl(origin, pathname)));
        return Boolean(hit);
    } catch {
        return false;
    }
}

/**
 * @param {Event} event
 * @param {object} [options]
 */
export async function handleUserManagementNavClick(event, options = {}) {
    const link = options.link
        || (event?.target && typeof event.target.closest === 'function'
            ? event.target.closest(UM_NAV_SELECTOR)
            : null);
    if (!link) {
        return { intercepted: false };
    }

    const kind = options.kind || kindFromUserManagementNavLink(link);
    if (!kind || !KIND_PATHS[kind]) {
        return { intercepted: false };
    }

    if (!isClientOffline(options)) {
        return { intercepted: false, navigated: true, reason: 'online', kind };
    }

    event.preventDefault?.();
    event.stopPropagation?.();

    const href = link.getAttribute?.('href') || link.href || '';
    const message = messageForUserManagementNavKind(kind);

    if (kind === UM_NAV_KINDS.ADD_WORKER) {
        emitNotice(message, options);
        if (options.root) {
            showLocalToast(options.root, message, options.toastSelector);
        }
        return {
            intercepted: true,
            navigated: false,
            reason: 'uncached',
            kind,
            message,
        };
    }

    let cached = false;
    try {
        cached = await hasCachedUserManagementNav(href, { ...options, kind });
    } catch {
        cached = false;
    }

    if (cached) {
        const assign = options.assign || ((url) => {
            const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
            if (typeof win.location?.assign === 'function') {
                win.location.assign(url);
            }
        });
        assign(href);
        return { intercepted: true, navigated: true, reason: 'cached', kind };
    }

    emitNotice(message, options);
    if (options.root) {
        showLocalToast(options.root, message, options.toastSelector);
    }
    return {
        intercepted: true,
        navigated: false,
        reason: 'uncached',
        kind,
        message,
    };
}

export function bindUserManagementOfflineNav(options = {}) {
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    if (!doc || typeof doc.addEventListener !== 'function') {
        return;
    }

    doc.addEventListener('click', (event) => {
        const link = event.target?.closest?.(UM_NAV_SELECTOR);
        if (!link) {
            return;
        }

        const root =
            link.closest('[data-lml-user-mgmt], [data-lml-hw-wizard], [data-lml-hw-view]')
            || options.root
            || doc.querySelector('[data-lml-user-mgmt]');

        void handleUserManagementNavClick(event, {
            ...options,
            link,
            root,
            toastSelector: options.toastSelector || '[data-um-toast], [data-hw-wizard-toast]',
        });
    });
}

export function bindUserManagementSyncReload(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    if (!win || typeof win.addEventListener !== 'function' || !doc) {
        return;
    }

    win.addEventListener(OFFLINE_EVENTS.SYNC_SUCCESS, () => {
        if (!doc.querySelector('[data-lml-user-mgmt]')) {
            return;
        }
        if (win.__lmlUmReloading) {
            return;
        }
        win.__lmlUmReloading = true;
        if (typeof win.location?.reload === 'function') {
            win.location.reload();
        }
    });
}

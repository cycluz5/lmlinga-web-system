/**
 * Sidebar / module navigation guard while offline.
 *
 * Prefer in-app unavailable panel (shell preserved) over the full-document
 * SW fallback for recognized LMLinga routes. User Management index/create
 * stay online-only. Cached offline-capable pages may navigate normally.
 *
 * For offline-capable routes, a page-side Cache Storage miss is NOT final when
 * a controlling service worker exists — the SW owns matchNavigation and must
 * be allowed to serve (or fall back) via a real navigation request.
 */

import { isClientOffline } from './offline-forms.js';
import { showOfflineUnavailableInShell } from './offline-unavailable.js';
import {
    classifyOfflineNavigation,
    htmlCacheNameForActor,
    isUserManagementHealthWorkerWarmPath,
    navigationCacheUrl,
    normalizePathname,
} from './offline-sw-policy.js';

const SIDEBAR_LINK_SELECTOR = [
    '.lml-sidebar a.lml-sidebar__link[href]',
    '.lml-sidebar a.lml-sidebar__parent-link[href]',
    '.lml-sidebar a.lml-sidebar__sublink[href]',
    'a[data-lml-module-nav]',
].join(', ');

function readPageActorId(doc) {
    const host = doc?.querySelector?.('[data-lml-offline-root]');
    const id = Number.parseInt(String(host?.getAttribute?.('data-offline-actor-id') || ''), 10);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function hasControllingServiceWorker(win) {
    return Boolean(win?.navigator?.serviceWorker?.controller);
}

/**
 * Page-side actor HTML cache probe (same key contract as SW storeNavigation).
 * @returns {Promise<boolean>}
 */
export async function hasCachedNavigation(pathname, doc, options = {}) {
    const path = normalizePathname(pathname);
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    if (!cachesApi || typeof cachesApi.open !== 'function') {
        return false;
    }

    const actorId = Number(options.actorId || readPageActorId(doc) || 0);
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cacheName) {
        return false;
    }

    try {
        const cache = await cachesApi.open(cacheName);
        const origin = options.origin
            || (typeof window !== 'undefined' ? window.location.origin : '')
            || doc?.defaultView?.location?.origin
            || '';
        if (!origin) {
            return false;
        }
        const url = navigationCacheUrl(origin, path);
        const hit = await cache.match(new Request(url));
        return Boolean(hit);
    } catch {
        return false;
    }
}

function sameOriginPath(href, doc) {
    try {
        const base = doc?.defaultView?.location?.href || (typeof window !== 'undefined' ? window.location.href : 'http://localhost/');
        const parsed = new URL(String(href || ''), base);
        const current = new URL(base);
        if (parsed.origin !== current.origin) {
            return null;
        }
        return normalizePathname(parsed.pathname);
    } catch {
        return null;
    }
}

/**
 * @returns {Promise<boolean>} true when navigation was handled (blocked or panel shown)
 */
export async function handleModuleOfflineNavigation(link, options = {}) {
    const doc = options.document || link?.ownerDocument || (typeof document !== 'undefined' ? document : null);
    const win = options.window || doc?.defaultView || (typeof window !== 'undefined' ? window : null);

    if (!isClientOffline({ window: win, document: doc, navigator: options.navigator || win?.navigator })) {
        return false;
    }

    const path = sameOriginPath(link?.getAttribute?.('href') || link?.href, doc);
    if (!path || path === '#') {
        return false;
    }

    // Health Worker view/edit keep their dedicated UM cache guard.
    if (isUserManagementHealthWorkerWarmPath(path)) {
        return false;
    }

    const classification = classifyOfflineNavigation(path);

    if (classification.kind === 'online-only') {
        showOfflineUnavailableInShell(doc, classification);
        return true;
    }

    if (classification.kind === 'offline-capable') {
        const actorId = readPageActorId(doc);
        const cached = await hasCachedNavigation(path, doc, {
            caches: options.caches,
            origin: options.origin || win?.location?.origin,
            actorId,
        });
        if (cached) {
            // Page cache hit — allow normal navigation (caller assigns).
            return false;
        }

        // Page miss is not authoritative when the controlling SW can still
        // matchNavigation from the actor HTML cache (or show SW fallback).
        if (hasControllingServiceWorker(win) || options.allowServiceWorkerNavigation === true) {
            return false;
        }

        // No SW controller and no page cache — true miss for this tab.
        showOfflineUnavailableInShell(doc, classification);
        return true;
    }

    // Unknown: allow SW last-resort fallback if the browser navigates.
    return false;
}

export function bindModuleOfflineNav(doc = typeof document !== 'undefined' ? document : null) {
    if (!doc || typeof doc.addEventListener !== 'function') {
        return;
    }

    doc.addEventListener(
        'click',
        (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            const link = event.target?.closest?.(SIDEBAR_LINK_SELECTOR);
            if (!link || !doc.contains(link)) {
                return;
            }

            const win = doc.defaultView || (typeof window !== 'undefined' ? window : null);
            if (!isClientOffline({ window: win, document: doc })) {
                return;
            }

            event.preventDefault();
            void handleModuleOfflineNavigation(link, { document: doc, window: win }).then((handled) => {
                if (!handled && link.href && typeof window !== 'undefined') {
                    window.location.assign(link.href);
                }
            });
        },
        true,
    );
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bindModuleOfflineNav(document), { once: true });
    } else {
        bindModuleOfflineNav(document);
    }
}

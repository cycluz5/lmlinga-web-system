/**
 * Authenticated authorized-page HTML warmup for OFFLINE-7.
 *
 * Runs in the page after the service worker is active. Install itself never
 * precaches authenticated HTML. A confirmed GET /offline/status plus a known
 * actor id and role are required before the worker is asked to fill the
 * actor-scoped cache. The worker computes the role allowlist; this page
 * layer never sends an unvalidated path list.
 */

import {
    CACHE_VERSION,
    MESSAGE_WARMUP_CORE,
    avatarPathFromUrl,
    warmupPathsForRole,
} from './offline-sw-policy.js';

let inFlight = null;

export function resetCoreWarmupForTests() {
    inFlight = null;
}

function readActor(doc) {
    const root = doc?.querySelector?.('[data-lml-offline-root]');
    const id = Number(root?.getAttribute?.('data-offline-actor-id') || 0);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function readAvatarUrl(doc) {
    const root = doc?.querySelector?.('[data-lml-offline-root]');
    const raw = root?.getAttribute?.('data-offline-actor-avatar') || '';
    return avatarPathFromUrl(raw, 'http://localhost') ? raw : '';
}

function readStatusUrl(doc, fallback = '/offline/status') {
    const root = doc?.querySelector?.('[data-lml-offline-root]');
    return root?.getAttribute?.('data-offline-status-url') || fallback;
}

function postWarmup(worker, actorId, role, avatarUrl) {
    if (!worker || typeof worker.postMessage !== 'function') {
        return false;
    }
    try {
        const payload = {
            type: MESSAGE_WARMUP_CORE,
            actorId,
            role,
            version: CACHE_VERSION,
        };
        if (avatarUrl) {
            payload.avatarUrl = avatarUrl;
        }
        worker.postMessage(payload);
        return true;
    } catch {
        return false;
    }
}

export function canWarmCoreFieldPages(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const nav = options.navigator || win?.navigator || null;
    const actorId = Number(options.actorId || 0);
    const worker = options.worker || nav?.serviceWorker?.controller || null;

    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    if (nav && nav.onLine === false) {
        return false;
    }
    if (!worker) {
        return false;
    }
    return true;
}

/**
 * Confirm the live server session, then ask the controlling worker to warm
 * the actor's authorized GET shells. Duplicate callers share one in-flight run.
 *
 * @param {object} [options]
 */
export function confirmSessionAndWarmCorePages(options = {}) {
    if (inFlight) {
        return inFlight;
    }

    inFlight = (async () => {
        try {
            const win = options.window || (typeof window !== 'undefined' ? window : null);
            const doc = options.document || (typeof document !== 'undefined' ? document : null);
            const nav = options.navigator || win?.navigator || { onLine: true };
            const fetchImpl = options.fetch || win?.fetch;
            const actorId = Number(options.actorId || readActor(doc) || 0);
            const worker =
                options.worker
                || nav?.serviceWorker?.controller
                || null;
            const statusUrl = options.statusUrl || readStatusUrl(doc);

            if (!canWarmCoreFieldPages({ window: win, navigator: nav, actorId, worker })) {
                return { ok: false, reason: 'preconditions', warmed: [] };
            }
            if (typeof fetchImpl !== 'function') {
                return { ok: false, reason: 'no-fetch', warmed: [] };
            }

            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch {
                payload = null;
            }

            if (!response.ok || !payload || payload.ok !== true) {
                return { ok: false, reason: 'session', warmed: [] };
            }

            const statusActorId = Number(payload.user_id || 0);
            if (Number.isInteger(statusActorId) && statusActorId > 0 && statusActorId !== actorId) {
                return { ok: false, reason: 'actor-mismatch', warmed: [] };
            }

            const role = payload.role;
            const paths = warmupPathsForRole(role);
            if (!paths.length) {
                return { ok: false, reason: 'no-role', warmed: [] };
            }

            const avatarUrl = options.avatarUrl !== undefined
                ? options.avatarUrl
                : readAvatarUrl(doc);
            const posted = postWarmup(worker, actorId, role, avatarUrl || '');
            if (!posted) {
                return { ok: false, reason: 'no-worker', warmed: [] };
            }

            return {
                ok: true,
                reason: 'requested',
                actorId,
                role,
                paths,
            };
        } finally {
            inFlight = null;
        }
    })();

    return inFlight;
}

export { MESSAGE_WARMUP_CORE };

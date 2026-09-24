/**
 * User Management Health Worker warmup — listed View/Edit HTML plus
 * managed profile photos for the current actor.
 *
 * Runs only after /user-management loads while the server is reachable.
 * Never enqueues IndexedDB operations, never triggers offline sync, never
 * mutates workers, and never warms Add/Create.
 */

import {
    CACHE_VERSION,
    MESSAGE_WARMUP_UM_WORKERS,
    avatarPathFromUrl,
    isUserManagementHealthWorkerCreatePath,
    isUserManagementHealthWorkerEditPath,
    isUserManagementHealthWorkerViewPath,
    isUserManagementHealthWorkerWarmPath,
    normalizePathname,
} from './offline-sw-policy.js';

const NUMERIC_WORKER_ID = /^[0-9]+$/;

let inFlight = null;

export function resetUserManagementWarmupForTests() {
    inFlight = null;
}

function pageOrigin(options = {}) {
    if (options.origin) {
        return String(options.origin);
    }
    if (typeof location !== 'undefined' && location.origin) {
        return location.origin;
    }
    return 'http://localhost';
}

function pathFromHref(raw, origin) {
    if (raw == null || String(raw).trim() === '') {
        return '';
    }
    try {
        const parsed = new URL(String(raw), origin);
        return normalizePathname(parsed.pathname);
    } catch {
        return '';
    }
}

function readActor(doc) {
    const root = doc?.querySelector?.('[data-lml-offline-root]');
    const id = Number(root?.getAttribute?.('data-offline-actor-id') || 0);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function readStatusUrl(doc, fallback = '/offline/status') {
    const root = doc?.querySelector?.('[data-lml-offline-root]');
    return root?.getAttribute?.('data-offline-status-url') || fallback;
}

function uniquePush(list, value) {
    if (!value || list.includes(value)) {
        return;
    }
    list.push(value);
}

/**
 * Read currently rendered Health Worker cards. Numeric IDs only.
 */
export function discoverUserManagementWorkers(root, options = {}) {
    const origin = pageOrigin(options);
    const scope = root && typeof root.querySelectorAll === 'function' ? root : null;
    if (!scope) {
        return [];
    }

    const workers = [];
    scope.querySelectorAll('[data-hw-card]').forEach((card) => {
        const id = String(
            card.getAttribute('data-um-worker-id') || card.getAttribute('data-hw-id') || '',
        ).trim();
        if (!NUMERIC_WORKER_ID.test(id)) {
            return;
        }

        const viewAttr = pathFromHref(card.getAttribute('data-um-worker-view-url'), origin);
        const editAttr = pathFromHref(card.getAttribute('data-um-worker-edit-url'), origin);
        const viewUrl = isUserManagementHealthWorkerViewPath(viewAttr)
            ? viewAttr
            : `/user-management/health-workers/${id}/view`;
        const editUrl = isUserManagementHealthWorkerEditPath(editAttr)
            ? editAttr
            : `/user-management/health-workers/${id}/edit`;
        const avatarUrl = avatarPathFromUrl(card.getAttribute('data-um-worker-avatar-url') || '', origin);

        if (isUserManagementHealthWorkerCreatePath(viewUrl) || isUserManagementHealthWorkerCreatePath(editUrl)) {
            return;
        }

        workers.push({
            id,
            viewUrl: isUserManagementHealthWorkerWarmPath(viewUrl) ? viewUrl : null,
            editUrl: isUserManagementHealthWorkerWarmPath(editUrl) ? editUrl : null,
            avatarUrl,
        });
    });

    return workers;
}

export function collectUserManagementWarmupPayload(root, options = {}) {
    const paths = [];
    const avatars = [];
    const workers = discoverUserManagementWorkers(root, options);

    workers.forEach((worker) => {
        uniquePush(paths, worker.viewUrl);
        uniquePush(paths, worker.editUrl);
        uniquePush(avatars, worker.avatarUrl);
    });

    return {
        workers,
        paths: paths.filter((path) => isUserManagementHealthWorkerWarmPath(path)),
        avatars,
    };
}

export function canWarmUserManagementWorkers(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const nav = options.navigator || win?.navigator || null;
    const actorId = Number(options.actorId || 0);
    const worker = options.worker || nav?.serviceWorker?.controller || null;
    const payload = options.payload || { paths: [], avatars: [] };

    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    if (nav && nav.onLine === false) {
        return false;
    }
    if (!worker) {
        return false;
    }
    if (!payload.paths?.length && !payload.avatars?.length) {
        return false;
    }
    return true;
}

function postUmWarmup(worker, actorId, payload) {
    if (!worker || typeof worker.postMessage !== 'function') {
        return false;
    }
    try {
        worker.postMessage({
            type: MESSAGE_WARMUP_UM_WORKERS,
            actorId,
            version: CACHE_VERSION,
            paths: [...(payload.paths || [])],
            avatars: [...(payload.avatars || [])],
        });
        return true;
    } catch {
        return false;
    }
}

/**
 * Confirm the live session, then ask the controlling worker to refresh
 * listed Health Worker View/Edit pages and avatars for this actor.
 */
export function warmListedUserManagementWorkers(options = {}) {
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
            const controller =
                options.worker
                || nav?.serviceWorker?.controller
                || null;
            const statusUrl = options.statusUrl || readStatusUrl(doc);
            const root = options.root
                || doc?.querySelector?.('[data-lml-user-mgmt]')
                || doc;
            const payload = collectUserManagementWarmupPayload(root, {
                origin: pageOrigin(options),
            });

            if (!canWarmUserManagementWorkers({
                window: win,
                navigator: nav,
                actorId,
                worker: controller,
                payload,
            })) {
                return { ok: false, reason: 'preconditions', warmed: [], avatars: [] };
            }
            if (typeof fetchImpl !== 'function') {
                return { ok: false, reason: 'no-fetch', warmed: [], avatars: [] };
            }

            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            let statusPayload = null;
            try {
                statusPayload = await response.json();
            } catch {
                statusPayload = null;
            }

            if (!response.ok || !statusPayload || statusPayload.ok !== true) {
                return { ok: false, reason: 'session', warmed: [], avatars: [] };
            }

            const statusActorId = Number(statusPayload.user_id || 0);
            if (Number.isInteger(statusActorId) && statusActorId > 0 && statusActorId !== actorId) {
                return { ok: false, reason: 'actor-mismatch', warmed: [], avatars: [] };
            }

            const posted = postUmWarmup(controller, actorId, payload);
            if (!posted) {
                return { ok: false, reason: 'no-worker', warmed: [], avatars: [] };
            }

            return {
                ok: true,
                reason: 'requested',
                actorId,
                paths: payload.paths,
                avatars: payload.avatars,
            };
        } finally {
            inFlight = null;
        }
    })();

    return inFlight;
}

function applyAvatarFallback(img) {
    if (!img) {
        return;
    }
    img.hidden = true;
    img.setAttribute('hidden', '');
    const host = img.parentElement || img.parentNode;
    const fallback = host?.querySelector?.('[data-um-avatar-fallback]');
    if (fallback) {
        fallback.hidden = false;
        fallback.removeAttribute('hidden');
    }
}

/**
 * Hide a broken <img> and reveal the existing initials/person placeholder.
 * Does not invent a profile photo.
 */
export function bindManagedAvatarFallback(root) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : null;
    if (!scope) {
        return;
    }

    scope.querySelectorAll('[data-um-avatar]').forEach((img) => {
        if (img.getAttribute('data-um-avatar-bound') === '1') {
            return;
        }
        img.setAttribute('data-um-avatar-bound', '1');
        img.addEventListener('error', () => applyAvatarFallback(img));
        const src = String(img.getAttribute('src') || '').trim();
        if (img.complete && img.naturalWidth === 0 && src) {
            applyAvatarFallback(img);
        }
    });
}

export { MESSAGE_WARMUP_UM_WORKERS };

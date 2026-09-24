/**
 * Post-login offline preparation UI — blocking overlay with REAL warmup progress.
 *
 * Extends offline-7 actor-scoped core warmup. Does not invent a second cache system.
 */

import {
    CACHE_VERSION,
    MESSAGE_ENSURE_BUILD_ASSETS,
    MESSAGE_ENSURE_BUILD_ASSETS_RESULT,
    MESSAGE_WARMUP_COMPLETE,
    MESSAGE_WARMUP_CORE,
    MESSAGE_WARMUP_PROGRESS,
    MESSAGE_WARMUP_READY_QUERY,
    MESSAGE_WARMUP_READY_RESULT,
    documentUsesViteDevAssets,
    htmlCacheNameForActor,
    htmlUsesViteDevAssets,
    navigationCacheUrl,
    prepareStorageKey,
    warmupPathsForRole,
} from './offline-sw-policy.js';
import { OFFLINE_DB_VERSION } from './offline-db.js';
import { listOperationsForActor } from './offline-queue.js';
import { canWarmCoreFieldPages, confirmSessionAndWarmCorePages } from './offline-sw-warmup.js';
import {
    prepareStaffOfflineDataset,
    readHouseholdBootstrapUrl,
    verifyStaffOfflineDataset,
} from './offline-staff-dataset-prepare.js';

const PREPARE_TIMEOUT_MS = 300000;

/** Shell warmup contributes up to this share of the overall preparation bar. */
const SHELL_PROGRESS_WEIGHT = 20;

/** TEMP: count concurrent/duplicate prep boots for diagnostics. */
let prepPerfRunSeq = 0;

/**
 * Map shell warmup (0–100) into the first segment of overall preparation.
 * @param {number} shellPercent
 */
export function mapShellProgressToOverall(shellPercent) {
    const pct = Math.max(0, Math.min(100, Number(shellPercent) || 0));
    return Math.round((pct / 100) * SHELL_PROGRESS_WEIGHT);
}

/**
 * Map staff dataset phases into 20–100% of overall preparation.
 * @param {{ phase?: string, ready?: number, total?: number }} detail
 */
export function mapDatasetProgressToOverall(detail = {}) {
    const phase = detail.phase || '';
    if (phase === 'dataset-fetch') {
        return SHELL_PROGRESS_WEIGHT + 2;
    }
    if (phase === 'households') {
        const total = Number(detail.total) || 0;
        const ready = Number(detail.ready) || 0;
        if (total <= 0) {
            return SHELL_PROGRESS_WEIGHT + 25;
        }
        return SHELL_PROGRESS_WEIGHT + Math.round((ready / total) * 35);
    }
    if (phase === 'members') {
        return SHELL_PROGRESS_WEIGHT + 62;
    }
    if (phase === 'eh') {
        return SHELL_PROGRESS_WEIGHT + 68;
    }
    if (phase === 'health') {
        return SHELL_PROGRESS_WEIGHT + 74;
    }
    if (phase === 'views') {
        return SHELL_PROGRESS_WEIGHT + 78;
    }
    if (phase === 'verify') {
        return 98;
    }
    return SHELL_PROGRESS_WEIGHT + 20;
}

/**
 * Page-side verification that every role warmup path exists in the actor HTML
 * cache (same URL contract as SW storeNavigation / module nav guard).
 *
 * @param {number} actorId
 * @param {string} role
 * @param {{ caches?: CacheStorage, origin?: string, window?: Window }} [options]
 * @returns {Promise<{ ok: boolean, total: number, present: number, missing: string[], version: string, actorId: number|null }>}
 */
export async function verifyCoreNavigationCached(actorId, role, options = {}) {
    const paths = warmupPathsForRole(role);
    const id = Number(actorId);
    const empty = {
        ok: false,
        total: paths.length,
        present: 0,
        missing: paths.slice(),
        version: CACHE_VERSION,
        actorId: Number.isInteger(id) && id > 0 ? id : null,
    };
    if (!Number.isInteger(id) || id <= 0 || !paths.length) {
        return empty;
    }
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    if (!cachesApi || typeof cachesApi.open !== 'function') {
        return empty;
    }
    const cacheName = htmlCacheNameForActor(id);
    if (!cacheName) {
        return empty;
    }
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const origin = options.origin || win?.location?.origin || '';
    if (!origin) {
        return empty;
    }

    try {
        const cache = await cachesApi.open(cacheName);
        const missing = [];
        let present = 0;
        for (const path of paths) {
            const hit = await cache.match(new Request(navigationCacheUrl(origin, path)));
            // Stale DEV-generated HTML is not offline-ready in BUILD mode.
            if (hit && !htmlUsesViteDevAssets(await hit.text())) {
                present += 1;
            } else {
                missing.push(path);
            }
        }
        return {
            ok: missing.length === 0 && present === paths.length,
            total: paths.length,
            present,
            missing,
            version: CACHE_VERSION,
            actorId: id,
            cacheName,
        };
    } catch {
        return empty;
    }
}

/**
 * @param {Storage|null|undefined} storage
 * @param {number} actorId
 * @param {string} role
 */
export function readLocalPrepared(storage, actorId, role) {
    const key = prepareStorageKey(actorId);
    if (!key || !storage || typeof storage.getItem !== 'function') {
        return null;
    }
    try {
        const raw = storage.getItem(key);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }
        if (parsed.version !== CACHE_VERSION) {
            // Stale prepared marker from a previous cache generation.
            try {
                storage.removeItem(key);
            } catch {
                // Ignore.
            }
            return null;
        }
        if (role && parsed.role && parsed.role !== role) {
            return null;
        }
        return parsed;
    } catch {
        return null;
    }
}

/**
 * True when the durable prepared marker belongs to this authenticated login session.
 * Missing/mismatched loginSessionId → treat as not prepared for this login (force refresh).
 *
 * @param {object|null|undefined} local
 * @param {string|null|undefined} loginSessionId
 */
export function isPreparedForLoginSession(local, loginSessionId) {
    if (!local || typeof local !== 'object') {
        return false;
    }
    const expected = String(loginSessionId || '').trim();
    if (!expected) {
        // Offline / no status payload: allow legacy skip based on durable marker alone.
        return true;
    }
    const stored = String(local.loginSessionId || '').trim();
    return Boolean(stored) && stored === expected;
}

export function writeLocalPrepared(storage, actorId, role, meta = {}) {
    const key = prepareStorageKey(actorId);
    if (!key || !storage || typeof storage.setItem !== 'function') {
        return false;
    }
    const normalized = typeof meta === 'number'
        ? { pathCount: meta }
        : (meta && typeof meta === 'object' ? meta : {});
    try {
        storage.setItem(
            key,
            JSON.stringify({
                version: CACHE_VERSION,
                role: role || null,
                pathCount: Number(normalized.pathCount) || 0,
                preparedAt: new Date().toISOString(),
                generated_at: normalized.generated_at || null,
                householdCount: Number.isFinite(Number(normalized.householdCount))
                    ? Number(normalized.householdCount)
                    : null,
                memberCount: Number.isFinite(Number(normalized.memberCount))
                    ? Number(normalized.memberCount)
                    : null,
                dbVersion: OFFLINE_DB_VERSION,
                loginSessionId: typeof normalized.loginSessionId === 'string'
                    && normalized.loginSessionId.trim()
                    ? normalized.loginSessionId.trim()
                    : null,
            }),
        );
        return true;
    } catch {
        return false;
    }
}

export function clearLocalPrepared(storage, actorId) {
    const key = prepareStorageKey(actorId);
    if (!key || !storage || typeof storage.removeItem !== 'function') {
        return;
    }
    try {
        storage.removeItem(key);
    } catch {
        // Ignore.
    }
}

function readActor(doc) {
    const root = doc?.querySelector?.('[data-lml-offline-root]');
    const id = Number(root?.getAttribute?.('data-offline-actor-id') || 0);
    return Number.isInteger(id) && id > 0 ? id : null;
}

function readAvatarUrl(doc) {
    return doc?.querySelector?.('[data-lml-offline-root]')?.getAttribute?.('data-offline-actor-avatar') || '';
}

function readStatusUrl(doc) {
    return doc?.querySelector?.('[data-lml-offline-root]')?.getAttribute?.('data-offline-status-url') || '/offline/status';
}

function ensureModal(doc) {
    let overlay = doc.querySelector('[data-lml-offline-prepare]');
    if (overlay) {
        return overlay;
    }

    overlay = doc.createElement('div');
    overlay.className = 'lml-offline-prepare';
    overlay.setAttribute('data-lml-offline-prepare', '');
    overlay.setAttribute('hidden', '');
    overlay.innerHTML = `
<div class="lml-offline-prepare__backdrop" data-lml-offline-prepare-backdrop></div>
<div
    class="lml-offline-prepare__dialog"
    role="dialog"
    aria-modal="true"
    aria-labelledby="lml-offline-prepare-title"
    aria-describedby="lml-offline-prepare-desc"
    tabindex="-1"
    data-lml-offline-prepare-dialog
>
    <h2 id="lml-offline-prepare-title" class="lml-offline-prepare__title">Preparing Offline Access</h2>
    <p id="lml-offline-prepare-desc" class="lml-offline-prepare__lead">
        Preparing your authorized pages and data for unexpected connection loss.
    </p>
    <div class="lml-offline-prepare__status" data-lml-offline-prepare-status role="status" aria-live="polite">
        Starting offline preparation…
    </div>
    <div
        class="lml-offline-prepare__bar"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        aria-valuenow="0"
        aria-labelledby="lml-offline-prepare-title"
        data-lml-offline-prepare-bar
    >
        <div class="lml-offline-prepare__bar-fill" data-lml-offline-prepare-fill style="width: 0%"></div>
    </div>
    <p class="lml-offline-prepare__percent" data-lml-offline-prepare-percent>0% Complete</p>
    <p class="lml-offline-prepare__hint" data-lml-offline-prepare-hint>Please keep this page open.</p>
    <div class="lml-offline-prepare__actions" data-lml-offline-prepare-actions hidden>
        <button type="button" class="btn btn-primary lml-focus-ring" data-lml-offline-prepare-retry>Retry Preparation</button>
        <button type="button" class="btn btn-outline-primary lml-focus-ring" data-lml-offline-prepare-continue hidden>Continue with Available Offline Data</button>
    </div>
</div>`;
    (doc.body || doc.documentElement).appendChild(overlay);
    return overlay;
}

function setProgress(overlay, percentage, label) {
    const pct = Math.max(0, Math.min(100, Number(percentage) || 0));
    const fill = overlay.querySelector('[data-lml-offline-prepare-fill]');
    const bar = overlay.querySelector('[data-lml-offline-prepare-bar]');
    const percent = overlay.querySelector('[data-lml-offline-prepare-percent]');
    const status = overlay.querySelector('[data-lml-offline-prepare-status]');
    if (fill) {
        fill.style.width = `${pct}%`;
    }
    if (bar) {
        bar.setAttribute('aria-valuenow', String(pct));
    }
    if (percent) {
        percent.textContent = `${pct}% Complete`;
    }
    if (status && label) {
        status.textContent = label;
    }
}

function showFailure(overlay, message, { allowContinue = false } = {}) {
    const status = overlay.querySelector('[data-lml-offline-prepare-status]');
    const hint = overlay.querySelector('[data-lml-offline-prepare-hint]');
    const actions = overlay.querySelector('[data-lml-offline-prepare-actions]');
    const continueBtn = overlay.querySelector('[data-lml-offline-prepare-continue]');
    if (status) {
        status.textContent = message;
    }
    if (hint) {
        hint.textContent = 'You can retry when your connection is stable.';
    }
    if (actions) {
        actions.hidden = false;
    }
    if (continueBtn) {
        continueBtn.hidden = !allowContinue;
    }
}

function hideActions(overlay) {
    const actions = overlay.querySelector('[data-lml-offline-prepare-actions]');
    if (actions) {
        actions.hidden = true;
    }
}

function openOverlay(overlay, doc) {
    overlay.hidden = false;
    overlay.setAttribute('data-open', '1');
    doc.documentElement?.classList?.add('lml-offline-prepare-active');
    doc.body?.classList?.add('lml-offline-prepare-active');
    const dialog = overlay.querySelector('[data-lml-offline-prepare-dialog]');
    dialog?.focus?.();
}

function closeOverlay(overlay, doc) {
    overlay.hidden = true;
    overlay.removeAttribute('data-open');
    doc.documentElement?.classList?.remove('lml-offline-prepare-active');
    doc.body?.classList?.remove('lml-offline-prepare-active');
    hideActions(overlay);
}

function trapFocus(overlay, event) {
    if (event.key !== 'Tab' || overlay.hidden) {
        return;
    }
    const dialog = overlay.querySelector('[data-lml-offline-prepare-dialog]');
    if (!dialog) {
        return;
    }
    const focusable = Array.from(
        dialog.querySelectorAll('button:not([hidden]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
    ).filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
    if (!focusable.length) {
        event.preventDefault();
        dialog.focus();
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && docActive(overlay) === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && docActive(overlay) === last) {
        event.preventDefault();
        first.focus();
    }
}

function docActive(overlay) {
    return overlay.ownerDocument?.activeElement;
}

/**
 * Ask the controlling SW to precache + verify CURRENT /build/manifest.json assets.
 * Awaits MessageChannel (or broadcast) completion. Idempotent for existing ASSETS_CACHE.
 *
 * @param {ServiceWorker|null|undefined} worker
 * @param {{ window?: Window, timeoutMs?: number }} [options]
 * @returns {Promise<{ ok: boolean, reason?: string, checked?: string[], missing?: string[], version?: string }>}
 */
export function requestEnsureBuildAssets(worker, options = {}) {
    return new Promise((resolve) => {
        if (!worker || typeof worker.postMessage !== 'function') {
            resolve({ ok: false, reason: 'no-worker', checked: [], missing: [] });
            return;
        }
        const timeoutMs = options.timeoutMs || 60000;
        let settled = false;
        const channel = typeof MessageChannel === 'function' ? new MessageChannel() : null;
        const finish = (payload) => {
            if (settled) {
                return;
            }
            settled = true;
            resolve(payload && typeof payload === 'object'
                ? payload
                : { ok: false, reason: 'invalid-response', checked: [], missing: [] });
        };
        const timer = setTimeout(() => finish({ ok: false, reason: 'timeout', checked: [], missing: [] }), timeoutMs);
        const onMessage = (event) => {
            const data = event?.data;
            if (!data || typeof data !== 'object') {
                return;
            }
            const isResult = data.type === MESSAGE_ENSURE_BUILD_ASSETS_RESULT
                || (Array.isArray(data.checked) && Object.prototype.hasOwnProperty.call(data, 'missing'));
            if (!isResult) {
                return;
            }
            clearTimeout(timer);
            if (channel) {
                channel.port1.onmessage = null;
            }
            finish(data);
        };
        if (channel) {
            channel.port1.onmessage = onMessage;
            try {
                worker.postMessage({ type: MESSAGE_ENSURE_BUILD_ASSETS, version: CACHE_VERSION }, [channel.port2]);
                return;
            } catch {
                // Fall through to broadcast listener.
            }
        }
        const win = options.window || (typeof window !== 'undefined' ? window : null);
        const sw = win?.navigator?.serviceWorker;
        if (sw && typeof sw.addEventListener === 'function') {
            const handler = (event) => {
                onMessage(event);
                if (settled) {
                    sw.removeEventListener('message', handler);
                }
            };
            sw.addEventListener('message', handler);
            try {
                worker.postMessage({ type: MESSAGE_ENSURE_BUILD_ASSETS, version: CACHE_VERSION });
            } catch {
                clearTimeout(timer);
                sw.removeEventListener('message', handler);
                finish({ ok: false, reason: 'post-failed', checked: [], missing: [] });
            }
            return;
        }
        clearTimeout(timer);
        finish({ ok: false, reason: 'no-channel', checked: [], missing: [] });
    });
}

/**
 * Ask the SW whether all authorized core paths are present for this actor/role.
 */
export function queryWarmupReady(worker, actorId, role, options = {}) {
    return new Promise((resolve) => {
        if (!worker || typeof worker.postMessage !== 'function') {
            resolve({ ready: false, reason: 'no-worker' });
            return;
        }
        const timeoutMs = options.timeoutMs || 8000;
        let settled = false;
        const channel = typeof MessageChannel === 'function' ? new MessageChannel() : null;
        const finish = (payload) => {
            if (settled) {
                return;
            }
            settled = true;
            resolve(payload);
        };
        const timer = setTimeout(() => finish({ ready: false, reason: 'timeout' }), timeoutMs);
        const onMessage = (event) => {
            const data = event?.data;
            if (!data || data.type !== MESSAGE_WARMUP_READY_RESULT) {
                return;
            }
            // Controlling SW must agree on CACHE_VERSION or prepared is stale.
            if (data.version && data.version !== CACHE_VERSION) {
                clearTimeout(timer);
                if (channel) {
                    channel.port1.onmessage = null;
                }
                finish({ ready: false, reason: 'version-mismatch', ...data, expectedVersion: CACHE_VERSION });
                return;
            }
            clearTimeout(timer);
            if (channel) {
                channel.port1.onmessage = null;
            }
            finish(data);
        };
        if (channel) {
            channel.port1.onmessage = onMessage;
            try {
                worker.postMessage(
                    { type: MESSAGE_WARMUP_READY_QUERY, actorId, role, version: CACHE_VERSION },
                    [channel.port2],
                );
                return;
            } catch {
                // Fall through to broadcast listener.
            }
        }
        const win = options.window || (typeof window !== 'undefined' ? window : null);
        const sw = win?.navigator?.serviceWorker;
        if (sw && typeof sw.addEventListener === 'function') {
            const handler = (event) => {
                onMessage(event);
                if (settled) {
                    sw.removeEventListener('message', handler);
                }
            };
            sw.addEventListener('message', handler);
            try {
                worker.postMessage({ type: MESSAGE_WARMUP_READY_QUERY, actorId, role, version: CACHE_VERSION });
            } catch {
                clearTimeout(timer);
                sw.removeEventListener('message', handler);
                finish({ ready: false, reason: 'post-failed' });
            }
            return;
        }
        clearTimeout(timer);
        finish({ ready: false, reason: 'no-channel' });
    });
}

/**
 * Run core warmup and stream progress events to onProgress / onComplete.
 */
export function requestWarmupWithProgress(options = {}) {
    const {
        worker,
        actorId,
        role,
        avatarUrl = '',
        window: win = typeof window !== 'undefined' ? window : null,
        onProgress,
        onComplete,
    } = options;

    return new Promise((resolve) => {
        if (!worker || typeof worker.postMessage !== 'function') {
            const fail = { ok: false, reason: 'no-worker', completed: 0, total: 0, percentage: 0 };
            onComplete?.(fail);
            resolve(fail);
            return;
        }

        let settled = false;
        const finish = (payload) => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timer);
            sw?.removeEventListener?.('message', onSwMessage);
            if (channel) {
                channel.port1.onmessage = null;
            }
            onComplete?.(payload);
            resolve(payload);
        };

        const onData = (data) => {
            if (!data || typeof data !== 'object') {
                return;
            }
            if (data.type === MESSAGE_WARMUP_PROGRESS) {
                onProgress?.(data);
                return;
            }
            if (data.type === MESSAGE_WARMUP_COMPLETE) {
                finish(data);
            }
        };

        const channel = typeof MessageChannel === 'function' ? new MessageChannel() : null;
        const sw = win?.navigator?.serviceWorker;
        const onSwMessage = (event) => onData(event.data);
        if (sw && typeof sw.addEventListener === 'function') {
            sw.addEventListener('message', onSwMessage);
        }
        if (channel) {
            channel.port1.onmessage = (event) => onData(event.data);
        }

        const timer = setTimeout(() => {
            finish({ ok: false, reason: 'timeout', completed: 0, total: 0, percentage: 0 });
        }, options.timeoutMs || PREPARE_TIMEOUT_MS);

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
            if (channel) {
                worker.postMessage(payload, [channel.port2]);
            } else {
                worker.postMessage(payload);
            }
        } catch {
            finish({ ok: false, reason: 'post-failed', completed: 0, total: 0, percentage: 0 });
        }
    });
}

/**
 * Blocking preparation for the current dashboard actor when not yet ready.
 */
export async function runOfflinePreparation(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const nav = options.navigator || win?.navigator || null;
    const storage = options.storage || win?.localStorage || null;
    const fetchImpl = options.fetch || (typeof win?.fetch === 'function' ? win.fetch.bind(win) : null);
    const actorId = Number(options.actorId || readActor(doc) || 0);
    const worker = options.worker || nav?.serviceWorker?.controller || null;

    if (!doc || !Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false, reason: 'no-actor', blocked: false };
    }

    // Non-PROD / no SW: never trap the user behind a modal.
    if (!canWarmCoreFieldPages({ window: win, navigator: nav, actorId, worker }) && !options.force) {
        return { ok: false, reason: 'preconditions', blocked: false };
    }

    // Vite DEV HTML cannot be production-offline-ready (cross-origin /resources CSS).
    if (documentUsesViteDevAssets(doc) && !options.allowViteDevAssets) {
        return { ok: false, reason: 'vite-dev-assets', blocked: false, role: options.role || null, actorId };
    }

    const statusUrl = options.statusUrl || readStatusUrl(doc);
    let role = options.role || null;
    let sessionOk = false;
    let loginSessionId = typeof options.loginSessionId === 'string'
        ? options.loginSessionId.trim()
        : '';

    if (typeof fetchImpl === 'function' && nav?.onLine !== false) {
        try {
            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();
            if (response.ok && payload?.ok === true) {
                sessionOk = true;
                role = payload.role || role;
                if (typeof payload.login_session_id === 'string' && payload.login_session_id.trim()) {
                    loginSessionId = payload.login_session_id.trim();
                }
                if (Number(payload.user_id) > 0 && Number(payload.user_id) !== actorId) {
                    return { ok: false, reason: 'actor-mismatch', blocked: false };
                }
            }
        } catch {
            sessionOk = false;
        }
    }

    if (!role || !warmupPathsForRole(role).length) {
        return { ok: false, reason: 'no-role', blocked: false };
    }

    const local = readLocalPrepared(storage, actorId, role);
    // Preparing (and its blocking modal) belongs to a fresh login. A device already prepared for
    // THIS login session never re-prompts: any repair runs silently and keeps the marker.
    const silent = Boolean(
        local
        && !options.force
        && (!loginSessionId || isPreparedForLoginSession(local, loginSessionId)),
    );
    const dropMarker = () => {
        if (!silent) {
            clearLocalPrepared(storage, actorId);
        }
    };
    if (local && !options.force) {
        // Fresh login (new session id) must refresh the staff dataset even if
        // durable caches/markers still look ready from a previous login.
        if (loginSessionId && !isPreparedForLoginSession(local, loginSessionId)) {
            clearLocalPrepared(storage, actorId);
        } else {
            const ready = await queryWarmupReady(worker, actorId, role, { window: win, timeoutMs: 5000 });
            const versionOk = !ready?.version || ready.version === CACHE_VERSION;
            const dbVersionOk = !local.dbVersion || local.dbVersion === OFFLINE_DB_VERSION;
            if (ready?.ready === true && versionOk && dbVersionOk) {
                const verifiedAssets = await requestEnsureBuildAssets(worker, {
                    window: win,
                    timeoutMs: options.assetTimeoutMs || options.timeoutMs || 60000,
                });
                if (!verifiedAssets?.ok) {
                    dropMarker();
                } else {
                    const verifiedShells = await verifyCoreNavigationCached(actorId, role, {
                        window: win,
                        caches: options.caches,
                        origin: options.origin || win?.location?.origin,
                    });
                    const verifiedDataset = await verifyStaffOfflineDataset(actorId, {
                        caches: options.caches,
                        origin: options.origin || win?.location?.origin,
                    });
                    if (verifiedShells.ok && verifiedDataset.ok) {
                        void confirmSessionAndWarmCorePages({
                            window: win,
                            document: doc,
                            worker,
                            navigator: nav,
                            fetch: fetchImpl,
                            actorId,
                            avatarUrl: readAvatarUrl(doc),
                            statusUrl,
                        });
                        return {
                            ok: true,
                            reason: 'already-prepared',
                            blocked: false,
                            role,
                            actorId,
                            assets: verifiedAssets,
                        };
                    }
                    dropMarker();
                }
            } else {
                dropMarker();
            }
        }
    }

    if (nav?.onLine === false) {
        return { ok: false, reason: 'offline', blocked: false };
    }
    if (!sessionOk) {
        return { ok: false, reason: 'session', blocked: false };
    }
    if (!worker) {
        return { ok: false, reason: 'no-worker', blocked: false };
    }

    if (silent) {
        // Refreshing snapshots would overwrite edits that are still waiting to sync.
        try {
            const queued = await listOperationsForActor(actorId);
            if (queued.length > 0) {
                return { ok: true, reason: 'pending-sync', blocked: false, role, actorId };
            }
        } catch {
            return { ok: true, reason: 'pending-sync', blocked: false, role, actorId };
        }
    }

    const overlay = ensureModal(doc);
    if (!silent) {
        openOverlay(overlay, doc);
    }
    hideActions(overlay);
    setProgress(overlay, 0, 'Starting offline preparation…');

    const prepRunId = ++prepPerfRunSeq;
    const totalLabel = `[PREP PERF] TOTAL#${prepRunId}`;
    const shellsLabel = `[PREP PERF] core-shells#${prepRunId}`;
    console.log('[PREP PERF] preparation start', { runId: prepRunId, actorId, role });
    console.time(totalLabel);

    const onKeydown = (event) => {
        if (overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        trapFocus(overlay, event);
    };
    if (!silent) {
        doc.addEventListener('keydown', onKeydown, true);
    }

    const backdrop = overlay.querySelector('[data-lml-offline-prepare-backdrop]');
    const stopBackdrop = (event) => {
        event.preventDefault();
        event.stopPropagation();
    };
    backdrop?.addEventListener('click', stopBackdrop);

    const corePathResults = [];
    const runShellWarmup = () => {
        console.time(shellsLabel);
        return requestWarmupWithProgress({
            worker,
            actorId,
            role,
            avatarUrl: readAvatarUrl(doc),
            window: win,
            timeoutMs: options.timeoutMs || PREPARE_TIMEOUT_MS,
            onProgress: (data) => {
                if (data?.diag && data.url) {
                    corePathResults.push(data.diag);
                    console.log('[PREP PERF] core-path', data.diag);
                }
                const warmed = Number(data.completed) || 0;
                const total = Number(data.total) || 0;
                const failed = Number(data.failed) || 0;
                let label = data.label || 'Offline pages…';
                if (failed > 0 && total > 0) {
                    label = `Offline pages ${warmed} / ${total}`;
                }
                const shellPct = failed > 0 && Number(data.percentage) === 100
                    ? Math.min(99, Math.round((warmed / total) * 100))
                    : Number(data.percentage) || 0;
                setProgress(overlay, mapShellProgressToOverall(shellPct), label);
            },
        }).finally(() => {
            console.timeEnd(shellsLabel);
            console.log('[PREP PERF] core-shells summary', {
                runId: prepRunId,
                paths: corePathResults.length,
                failed: corePathResults.filter((row) => !row.success).map((row) => row.pathname),
            });
        });
    };

    const runStaffDataset = () => prepareStaffOfflineDataset({
        window: win,
        document: doc,
        navigator: nav,
        fetch: fetchImpl,
        actorId,
        origin: options.origin || win?.location?.origin,
        caches: options.caches,
        bootstrapUrl: options.bootstrapUrl || readHouseholdBootstrapUrl(doc),
        updatePageStatus: false,
        prepPerfRunId: prepRunId,
        onProgress: (detail) => {
            setProgress(
                overlay,
                mapDatasetProgressToOverall(detail),
                detail.label || 'Preparing household data…',
            );
        },
    });

    const runFullPreparation = async () => {
        setProgress(overlay, 2, 'Preparing offline styles and scripts…');
        const assets = await requestEnsureBuildAssets(worker, {
            window: win,
            timeoutMs: options.assetTimeoutMs || options.timeoutMs || 60000,
        });
        if (!assets?.ok) {
            return { ok: false, stage: 'assets', assets, shell: null, dataset: null };
        }

        const shell = await runShellWarmup();
        const shellOk = shell?.ok === true
            && Number(shell.percentage) === 100
            && (!Array.isArray(shell.failed) || shell.failed.length === 0);
        if (!shellOk) {
            return { ok: false, stage: 'shells', assets, shell, dataset: null };
        }

        setProgress(overlay, mapShellProgressToOverall(100), 'Offline pages ready.');
        const dataset = await runStaffDataset();
        if (!dataset?.ok) {
            return { ok: false, stage: 'dataset', assets, shell, dataset };
        }

        const verifyLabel = `[PREP PERF] verification#${prepRunId}`;
        console.time(verifyLabel);
        const verifiedShells = await verifyCoreNavigationCached(actorId, role, {
            window: win,
            caches: options.caches,
            origin: options.origin || win?.location?.origin,
        });
        const verifiedDataset = await verifyStaffOfflineDataset(actorId, {
            expectedGeneratedAt: dataset.generated_at || null,
            caches: options.caches,
            origin: options.origin || win?.location?.origin,
        });
        // Re-verify assets after shells/dataset so a concurrent build cannot leave Ready stale.
        const verifiedAssets = await requestEnsureBuildAssets(worker, {
            window: win,
            timeoutMs: options.assetTimeoutMs || options.timeoutMs || 60000,
        });
        console.timeEnd(verifyLabel);
        if (!verifiedShells.ok || !verifiedDataset.ok || !verifiedAssets?.ok) {
            return {
                ok: false,
                stage: !verifiedAssets?.ok ? 'assets' : 'verify',
                assets: verifiedAssets,
                shell,
                dataset,
                verifiedShells,
                verifiedDataset,
            };
        }

        return {
            ok: true,
            stage: 'complete',
            assets: verifiedAssets,
            shell,
            dataset,
            verifiedShells,
            verifiedDataset,
        };
    };

    let result = await runFullPreparation();

    const bindRecovery = () => {
        const retry = overlay.querySelector('[data-lml-offline-prepare-retry]');
        const cont = overlay.querySelector('[data-lml-offline-prepare-continue]');
        retry?.addEventListener('click', () => {
            hideActions(overlay);
            setProgress(overlay, 0, 'Retrying offline preparation…');
            void runFullPreparation().then((next) => {
                result = next;
                void finishFromResult(next);
            });
        }, { once: true });
        cont?.addEventListener('click', () => {
            doc.removeEventListener('keydown', onKeydown, true);
            closeOverlay(overlay, doc);
        }, { once: true });
    };

    const finishFromResult = async (next) => {
        if (next?.ok === true) {
            const shellTotal = next.shell?.total || warmupPathsForRole(role).length;
            writeLocalPrepared(storage, actorId, role, {
                pathCount: shellTotal,
                generated_at: next.dataset?.generated_at || next.verifiedDataset?.generated_at || null,
                householdCount: next.verifiedDataset?.householdCount ?? next.dataset?.householdCount,
                memberCount: next.verifiedDataset?.memberCount ?? next.dataset?.memberCount,
                loginSessionId: loginSessionId || null,
            });
            if (silent) {
                return true;
            }
            setProgress(overlay, 100, 'Offline access is ready.');
            const closer = typeof win?.setTimeout === 'function' ? win.setTimeout.bind(win) : setTimeout;
            closer(() => {
                doc.removeEventListener('keydown', onKeydown, true);
                closeOverlay(overlay, doc);
            }, 450);
            return true;
        }

        const lost = nav?.onLine === false || next?.shell?.reason === 'timeout';
        const partialShells = next?.shell?.ok === true || (Array.isArray(next?.shell?.warmed) && next.shell.warmed.length > 0);
        const partialDataset = next?.dataset?.bootstrap?.ready > 0 || next?.dataset?.verified?.householdCount > 0;
        const partial = partialShells || partialDataset;

        if (next?.stage === 'assets') {
            setProgress(overlay, 5, 'Offline styles and scripts failed');
        } else if (next?.stage === 'dataset') {
            setProgress(overlay, mapDatasetProgressToOverall({ phase: 'households', ready: 0, total: 0 }), 'Households failed');
        } else if (next?.stage === 'verify') {
            setProgress(overlay, 95, 'Verifying local data…');
        } else if (partialShells && next?.shell?.total > 0) {
            const warmedCount = Array.isArray(next.shell.warmed) ? next.shell.warmed.length : Number(next.shell.completed) || 0;
            setProgress(
                overlay,
                mapShellProgressToOverall(Math.round((warmedCount / next.shell.total) * 100)),
                `Offline pages ${warmedCount} / ${next.shell.total}`,
            );
        }

        if (silent) {
            // Background repair failed: keep working, try again on the next page load.
            return false;
        }
        showFailure(
            overlay,
            lost
                ? 'Connection lost during offline preparation.'
                : 'Offline preparation is incomplete.',
            { allowContinue: partial || sessionOk },
        );
        clearLocalPrepared(storage, actorId);
        bindRecovery();
        return false;
    };

    const preparedOk = await finishFromResult(result);
    const failureReason = preparedOk
        ? null
        : (result?.stage || result?.dataset?.reason || result?.shell?.reason || 'incomplete');
    if (!preparedOk) {
        const fill = overlay.querySelector('[data-lml-offline-prepare-percent]');
        const progressText = fill?.textContent || null;
        console.log('[PREP PERF] FAILURE', {
            runId: prepRunId,
            stage: result?.stage || null,
            reason: failureReason,
            shellReason: result?.shell?.reason || null,
            datasetReason: result?.dataset?.reason || null,
            progress: progressText,
            corePaths: corePathResults,
            firstFailedCore: corePathResults.find((row) => !row.success) || null,
        });
    }
    console.timeEnd(totalLabel);
    console.log('[PREP PERF] preparation end', {
        runId: prepRunId,
        ok: preparedOk,
        stage: result?.stage || null,
    });

    return {
        ok: preparedOk,
        reason: preparedOk ? 'complete' : failureReason,
        blocked: !silent,
        result,
        role,
        actorId,
    };
}

/**
 * Dashboard boot entry — call after SW is ready and actor is synced.
 */
export function bootOfflinePreparation(options = {}) {
    console.log('[PREP PERF] bootOfflinePreparation called', { seq: prepPerfRunSeq + 1 });
    return runOfflinePreparation(options);
}

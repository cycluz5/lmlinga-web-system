/**
 * Parent-page warmup for currently visible household, member, amenities,
 * environmental sanitation, and health-summary pages.
 *
 * GET-only. Never enqueues IndexedDB operations.
 * Household Profiling index uses JSON bootstrap instead of one HTML fetch
 * per listed household.
 */

import { publicPath } from './offline-url-keys.js';
import {
    CACHE_VERSION,
    MESSAGE_WARMUP_RELATED,
    isRelatedWarmPath,
    normalizePathname,
} from './offline-sw-policy.js';

let inFlight = null;

export function resetRelatedWarmupForTests() {
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

function pathFromHref(raw, origin) {
    if (raw == null || String(raw).trim() === '') {
        return '';
    }
    try {
        const parsed = new URL(String(raw), origin);
        const path = normalizePathname(parsed.pathname);
        const search = parsed.search || '';
        if (path === '/environmental-health/household-water-supply' && search) {
            return `${path}${search}`;
        }
        return path;
    } catch {
        return '';
    }
}

export function discoverRelatedWarmPaths(root, options = {}) {
    const origin = pageOrigin(options);
    const scope = root && typeof root.querySelectorAll === 'function' ? root : null;
    const paths = [];
    if (!scope) {
        return paths;
    }

    scope.querySelectorAll('a[href]').forEach((link) => {
        const href = pathFromHref(link.getAttribute('href'), origin);
        if (isRelatedWarmPath(href.split('?')[0])) {
            uniquePush(paths, href);
        }
    });

    const householdNo = String(
        scope.getAttribute?.('data-household-no')
        || scope.querySelector?.('[data-household-no]')?.getAttribute?.('data-household-no')
        || '',
    ).trim();
    const memberId = String(
        scope.getAttribute?.('data-member-id')
        || scope.querySelector?.('[data-member-id]')?.getAttribute?.('data-member-id')
        || '',
    ).trim();

    if (householdNo) {
        uniquePush(paths, `/household-profiling/${householdNo}`);
        uniquePush(paths, `/household-profiling/${householdNo}/edit`);
        uniquePush(paths, `/household-profiling/${householdNo}/amenities`);
        uniquePush(paths, `/household-profiling/${householdNo}/amenities/edit`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/create`);
        uniquePush(paths, `/environmental-health/household-water-supply?household=${encodeURIComponent(householdNo)}`);
        uniquePush(paths, `/environmental-health/household-water-supply/${householdNo}/step-2`);
        uniquePush(paths, `/environmental-health/household-water-supply/${householdNo}/step-3`);
        uniquePush(paths, `/environmental-health/household-water-supply/${householdNo}/step-4`);
    }

    if (householdNo && memberId && /^(?:MB-[0-9]+|MB-L-[A-Za-z0-9]+)$/.test(memberId)) {
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/edit`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/child-immunization`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/school-based-immunization`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/child-nutrition`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/nutritional-status`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/nutritional-status/create`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/deworming`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/risk-assessment`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/family-planning`);
        uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/maternal-care`);
        if (/^MB-[0-9]+$/.test(memberId)) {
            uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/child-immunization/birth-history/edit`);
            uniquePush(paths, `/household-profiling/${householdNo}/members/${memberId}/death`);
        }
    }

    // Synthesized paths use internal ids; the server and cache use the public keys.
    return [...new Set(paths.map((entry) => publicPath(entry)))]
        .filter((entry) => isRelatedWarmPath(String(entry).split('?')[0]));
}

export function canWarmRelatedPages(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const nav = options.navigator || win?.navigator || null;
    const actorId = Number(options.actorId || 0);
    const worker = options.worker || nav?.serviceWorker?.controller || null;
    const paths = options.paths || [];

    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    if (nav && nav.onLine === false) {
        return false;
    }
    if (!worker) {
        return false;
    }
    return Array.isArray(paths) && paths.length > 0;
}

export function warmRelatedPages(options = {}) {
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
            const root = options.root || doc;
            const paths = options.paths || discoverRelatedWarmPaths(root, {
                origin: pageOrigin(options),
            });

            if (!canWarmRelatedPages({
                window: win,
                navigator: nav,
                actorId,
                worker: controller,
                paths,
            })) {
                return { ok: false, reason: 'precondition' };
            }
            if (typeof fetchImpl !== 'function') {
                return { ok: false, reason: 'no-fetch' };
            }

            const response = await fetchImpl(statusUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response || !response.ok) {
                return { ok: false, reason: 'status' };
            }
            const body = await response.json();
            if (Number(body?.actor_id) !== actorId) {
                return { ok: false, reason: 'actor-mismatch' };
            }

            controller.postMessage({
                type: MESSAGE_WARMUP_RELATED,
                actorId,
                version: CACHE_VERSION,
                paths,
            });
            return { ok: true, paths };
        } catch {
            return { ok: false, reason: 'error' };
        } finally {
            inFlight = null;
        }
    })();

    return inFlight;
}

function boot() {
    if (typeof document === 'undefined') {
        return;
    }
    const root = document.querySelector('[data-lml-offline-root]');
    if (!root) {
        return;
    }
    const host = document.querySelector(
        '[data-lml-hh-profiling], [data-lml-hh-view], [data-lml-hh-member-view], [data-lml-hh-member-form], [data-lml-hws], [data-lml-amenities], [data-lml-fp], [data-lml-death], [data-child-imm], [data-child-nut], [data-lml-sbi], [data-lml-mc], [data-risk-assess-form], [data-lml-risk-assess], [data-lml-hr-dw-record]',
    );
    if (!host) {
        return;
    }
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        return;
    }
    void warmRelatedPages({ root: document });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}

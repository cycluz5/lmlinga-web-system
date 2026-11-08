/**
 * Client-side offline navigation guard for Household Profiling dynamic GET links.
 *
 * Cache Storage only (current actor HTML cache). Never IndexedDB, queue, or sync.
 * Does not change service-worker policy: it only stops uncached clicks from
 * reaching the generic offline fallback.
 */

import { isClientOffline } from './offline-forms.js';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from './offline-status.js';
import { internalIdFor, publicPath } from './offline-url-keys.js';
import {
    HOUSEHOLD_NO_PATTERN,
    LOCAL_MEMBER_NO_PATTERN,
    MEMBER_NO_PATTERN,
    htmlCacheNameForActor,
    isDeathCertificateWritePath,
    isRelatedWarmPath,
    navigationCacheUrl,
    normalizePathname,
} from './offline-sw-policy.js';
import {
    ensureHouseholdNavFromSnapshot,
    parseHouseholdProfilingPath,
    parseNutritionalStatusCanonicalPath,
    resolveNutritionalStatusMemberPath,
} from './offline-hp-hydrate.js';
import { ensureEhNavFromSnapshot } from './offline-eh-hydrate.js';
import { isLocalPendingHousehold } from './offline-local-household.js';

const HOUSEHOLD_NO = HOUSEHOLD_NO_PATTERN;
const MEMBER_NO = MEMBER_NO_PATTERN;
const LOCAL_MEMBER_NO = LOCAL_MEMBER_NO_PATTERN;
const MEMBER_ANY = `(?:${MEMBER_NO}|${LOCAL_MEMBER_NO})`;

export const HH_NAV_KINDS = Object.freeze({
    VIEW_HOUSEHOLD: 'view-household',
    ADD_MEMBER: 'add-member',
    VIEW_MEMBER: 'view-member',
    EDIT_MEMBER: 'edit-member',
    AMENITIES: 'amenities',
    AMENITIES_EDIT: 'amenities-edit',
    HOUSEHOLD_EDIT: 'household-edit',
    HEALTH_RECORD: 'health-record',
    EH_HOUSEHOLD: 'eh-household',
    DEATH_WRITE: 'death-write',
});

export const HH_NAV_SELECTOR = 'a[data-hh-nav], a[data-hh-view], a[data-offline-nav]';

const KIND_PATHS = {
    [HH_NAV_KINDS.VIEW_HOUSEHOLD]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}$`),
    [HH_NAV_KINDS.ADD_MEMBER]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/create$`),
    [HH_NAV_KINDS.VIEW_MEMBER]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}$`),
    [HH_NAV_KINDS.EDIT_MEMBER]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/members/${MEMBER_ANY}/edit$`),
    [HH_NAV_KINDS.AMENITIES]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities$`),
    [HH_NAV_KINDS.AMENITIES_EDIT]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/amenities/edit$`),
    [HH_NAV_KINDS.HOUSEHOLD_EDIT]: new RegExp(`^/household-profiling/${HOUSEHOLD_NO}/edit$`),
    [HH_NAV_KINDS.EH_HOUSEHOLD]: /^\/environmental-health\/household-water-supply(?:\/|$)/,
};

export function messageForHouseholdNavKind(kind) {
    if (kind === HH_NAV_KINDS.ADD_MEMBER) {
        return OFFLINE_MESSAGES.addMemberOffline;
    }
    if (kind === HH_NAV_KINDS.VIEW_MEMBER) {
        return OFFLINE_MESSAGES.viewMemberOffline;
    }
    if (kind === HH_NAV_KINDS.EDIT_MEMBER) {
        return OFFLINE_MESSAGES.editMemberOffline;
    }
    if (kind === HH_NAV_KINDS.AMENITIES || kind === HH_NAV_KINDS.AMENITIES_EDIT) {
        return OFFLINE_MESSAGES.amenitiesOffline;
    }
    if (kind === HH_NAV_KINDS.HOUSEHOLD_EDIT) {
        return OFFLINE_MESSAGES.editHouseholdOffline;
    }
    if (kind === HH_NAV_KINDS.HEALTH_RECORD) {
        return OFFLINE_MESSAGES.viewHealthRecordOffline;
    }
    if (kind === HH_NAV_KINDS.EH_HOUSEHOLD) {
        return OFFLINE_MESSAGES.viewEnvironmentalOffline;
    }
    if (kind === HH_NAV_KINDS.DEATH_WRITE) {
        return OFFLINE_MESSAGES.deathCertificateOffline;
    }
    return OFFLINE_MESSAGES.viewHouseholdOffline;
}

export function isHealthSummaryNavPath(pathname) {
    const path = normalizePathname(pathname);
    if (isDeathCertificateWritePath(path)) {
        return false;
    }
    // Canonical resident-based Nutritional Status (member card link on live pages).
    if (parseNutritionalStatusCanonicalPath(path)) {
        return isRelatedWarmPath(path);
    }
    return isRelatedWarmPath(path)
        && new RegExp(`/members/${MEMBER_ANY}/`).test(path)
        && !new RegExp(`/members/${MEMBER_ANY}/edit$`).test(path);
}

export function isHouseholdNavPath(pathname, kind) {
    const path = normalizePathname(pathname);
    if (kind === HH_NAV_KINDS.HEALTH_RECORD) {
        return isHealthSummaryNavPath(path);
    }
    if (kind === HH_NAV_KINDS.DEATH_WRITE) {
        return isDeathCertificateWritePath(path);
    }
    if (kind) {
        return Boolean(KIND_PATHS[kind]?.test(path));
    }
    if (isHealthSummaryNavPath(path) || isDeathCertificateWritePath(path)) {
        return true;
    }
    return Object.values(KIND_PATHS).some((pattern) => pattern.test(path));
}

export function isHouseholdViewPath(pathname) {
    return isHouseholdNavPath(pathname, HH_NAV_KINDS.VIEW_HOUSEHOLD);
}

export function readPageActorId(root) {
    const host =
        root?.closest?.('[data-lml-offline-root]')
        || (typeof root?.querySelector === 'function' ? root.querySelector('[data-offline-actor-id]') : null)
        || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);

    const raw = host?.getAttribute?.('data-offline-actor-id') || '';
    const id = Number.parseInt(String(raw), 10);
    return Number.isInteger(id) && id > 0 ? id : null;
}

export function kindFromHouseholdNavLink(link) {
    const nav = link?.getAttribute?.('data-hh-nav')
        || link?.getAttribute?.('data-offline-nav')
        || '';
    if (nav === 'edit-nutrition') {
        return HH_NAV_KINDS.HEALTH_RECORD;
    }
    if (nav && (KIND_PATHS[nav] || nav === HH_NAV_KINDS.HEALTH_RECORD || nav === HH_NAV_KINDS.DEATH_WRITE)) {
        return nav;
    }
    if (link?.hasAttribute?.('data-hh-view')) {
        return HH_NAV_KINDS.VIEW_HOUSEHOLD;
    }
    const href = link?.getAttribute?.('href') || '';
    try {
        const parsed = new URL(href, 'http://localhost');
        const path = normalizePathname(parsed.pathname);
        if (isDeathCertificateWritePath(path)) {
            return HH_NAV_KINDS.DEATH_WRITE;
        }
        if (isHealthSummaryNavPath(path)) {
            return HH_NAV_KINDS.HEALTH_RECORD;
        }
        for (const [kind, pattern] of Object.entries(KIND_PATHS)) {
            if (pattern.test(path)) {
                return kind;
            }
        }
    } catch {
        return null;
    }
    return null;
}

function emitNotice(message, options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const detail = { message, source: 'household-nav' };
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
        || root.querySelector('[data-hh-toast]')
        || root.querySelector('[data-hh-view-toast]')
        || root.querySelector('[data-hh-member-view-toast]')
        || root.querySelector('[data-hh-member-toast]');
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
 * Actor-scoped Cache Storage lookup for a Household Profiling navigation URL.
 * Fail closed: missing actor, caches API, origin mismatch, unknown path, or match error.
 *
 * @param {string} href
 * @param {object} [options]
 */
export async function hasCachedHouseholdNav(href, options = {}) {
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

    const publicUrl = new URL(publicPath(`${parsed.pathname}${parsed.search}`), parsed.origin);
    const pathname = normalizePathname(publicUrl.pathname);
    const kind = options.kind || null;
    if (kind === HH_NAV_KINDS.DEATH_WRITE || isDeathCertificateWritePath(pathname)) {
        return false;
    }
    if (!isHouseholdNavPath(pathname, kind)) {
        return false;
    }

    try {
        const cache = await cachesApi.open(cacheName);
        const hit = await cache.match(new Request(navigationCacheUrl(origin, pathname, publicUrl.search)));
        return Boolean(hit);
    } catch {
        return false;
    }
}

/**
 * @param {Event} event
 * @param {object} [options]
 */
export async function handleHouseholdNavClick(event, options = {}) {
    if (event?.lmlHouseholdNavHandled) {
        return { intercepted: true, reason: 'already-handled' };
    }

    const link = options.link
        || (event?.target && typeof event.target.closest === 'function'
            ? event.target.closest(HH_NAV_SELECTOR)
            : null);
    if (!link) {
        return { intercepted: false };
    }

    const kind = options.kind || kindFromHouseholdNavLink(link);
    if (!kind) {
        return { intercepted: false };
    }
    if (!KIND_PATHS[kind] && kind !== HH_NAV_KINDS.HEALTH_RECORD && kind !== HH_NAV_KINDS.DEATH_WRITE) {
        return { intercepted: false };
    }
    if (event && typeof event === 'object') {
        event.lmlHouseholdNavHandled = true;
    }

    const href = link.getAttribute?.('href') || link.href || '';
    const actorId = options.actorId || readPageActorId(options.root);

    // Local pending households must not hit Laravel (no MySQL row yet),
    // even while the browser reports online — including Add / View / Edit Member.
    let forceLocalShell = false;
    const localShellKinds = new Set([
        HH_NAV_KINDS.VIEW_HOUSEHOLD,
        HH_NAV_KINDS.HOUSEHOLD_EDIT,
        HH_NAV_KINDS.ADD_MEMBER,
        HH_NAV_KINDS.VIEW_MEMBER,
        HH_NAV_KINDS.EDIT_MEMBER,
        HH_NAV_KINDS.EH_HOUSEHOLD,
    ]);
    if (
        !isClientOffline(options)
        && localShellKinds.has(kind)
        && actorId
    ) {
        try {
            const parsedPath = (() => {
                try {
                    return new URL(href, options.origin || 'https://lmlinga.local').pathname;
                } catch {
                    return href;
                }
            })();
            if (kind === HH_NAV_KINDS.EH_HOUSEHOLD) {
                const match = String(parsedPath).match(/household=([^&]+)/i)
                    || String(href).match(/[?&]household=([^&]+)/i)
                    || String(parsedPath).match(/\/household-water-supply\/([^/]+)/i);
                const householdNo = match ? internalIdFor('h', decodeURIComponent(match[1])) : '';
                if (householdNo) {
                    forceLocalShell = await isLocalPendingHousehold(actorId, householdNo);
                }
            } else {
                const parsed = parseHouseholdProfilingPath(parsedPath);
                if (parsed?.householdNo) {
                    forceLocalShell = await isLocalPendingHousehold(actorId, parsed.householdNo);
                }
            }
        } catch {
            forceLocalShell = false;
        }
    }

    if (!isClientOffline(options) && !forceLocalShell) {
        return { intercepted: false, navigated: true, reason: 'online', kind };
    }

    event.preventDefault?.();
    event.stopPropagation?.();

    const message = messageForHouseholdNavKind(kind);

    if (kind === HH_NAV_KINDS.DEATH_WRITE) {
        emitNotice(message, options);
        if (options.root) {
            showLocalToast(options.root, message, options.toastSelector);
        }
        return {
            intercepted: true,
            navigated: false,
            reason: 'unsupported',
            kind,
            message,
        };
    }

    let cached = false;
    try {
        cached = await hasCachedHouseholdNav(href, { ...options, kind });
    } catch {
        cached = false;
    }

    // Canonical Nutritional Status link with no cached page: use this member's
    // prepared member-based page (resolved from this actor's snapshots only).
    let navHref = href;
    if (!cached && kind === HH_NAV_KINDS.HEALTH_RECORD) {
        try {
            const parsedHref = new URL(href, options.origin || 'https://lmlinga.local');
            if (parseNutritionalStatusCanonicalPath(normalizePathname(parsedHref.pathname))) {
                const resolve = options.resolveNutritionalStatusPath || resolveNutritionalStatusMemberPath;
                const memberPath = await resolve(
                    actorId || readPageActorId(options.root),
                    parsedHref.pathname,
                );
                if (memberPath) {
                    navHref = publicPath(memberPath);
                    cached = await hasCachedHouseholdNav(navHref, { ...options, kind });
                }
            }
        } catch {
            navHref = href;
            cached = false;
        }
    }

    if (cached) {
        const assign = options.assign || ((url) => {
            const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
            if (typeof win.location?.assign === 'function') {
                win.location.assign(url);
            }
        });
        assign(navHref);
        return { intercepted: true, navigated: true, reason: 'cached', kind };
    }

    let fromSnapshot = false;
    try {
        const ensureActor = actorId || readPageActorId(options.root);
        const ensure = options.ensureSnapshotCached || ensureHouseholdNavFromSnapshot;
        fromSnapshot = await ensure(navHref, { ...options, kind, actorId: ensureActor });
        if (!fromSnapshot) {
            const ensureEh = options.ensureEhSnapshotCached || ensureEhNavFromSnapshot;
            fromSnapshot = await ensureEh(navHref, { ...options, kind, actorId: ensureActor });
        }
    } catch {
        fromSnapshot = false;
    }

    if (fromSnapshot) {
        const assign = options.assign || ((url) => {
            const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
            if (typeof win.location?.assign === 'function') {
                win.location.assign(url);
            }
        });
        assign(navHref);
        return { intercepted: true, navigated: true, reason: 'snapshot', kind };
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

function bindGlobalHouseholdNav() {
    if (typeof document === 'undefined') {
        return;
    }
    document.addEventListener('click', (event) => {
        const link = event.target?.closest?.(HH_NAV_SELECTOR);
        if (!link) {
            return;
        }
        void handleHouseholdNavClick(event, {
            link,
            root: document.querySelector('[data-lml-offline-root]') || document,
        });
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindGlobalHouseholdNav);
    } else {
        bindGlobalHouseholdNav();
    }
}

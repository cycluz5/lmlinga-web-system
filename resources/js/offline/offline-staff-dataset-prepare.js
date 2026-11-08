/**
 * Staff-side structured offline dataset preparation (Admin / Health Worker).
 *
 * Reuses GET /offline/household-profiling-bootstrap and existing IDB merge.
 * Includes compact Health Summary metadata + supported health shells.
 */

import { OFFLINE_DB_VERSION } from './offline-db.js';
import {
    getMeta,
    listHouseholdSnapshots,
    listMemberSnapshots,
} from './offline-hp-store.js';
import { prepareHouseholdProfilingOffline } from './offline-hp-bootstrap.js';
import { hasCanonicalEhShells } from './offline-eh-hydrate.js';
import { hasCanonicalHealthShells, healthModulePath } from './offline-hp-hydrate.js';
import { internalIdFor, publicPath, urlKeyFor } from './offline-url-keys.js';
import {
    htmlCacheNameForActor,
    htmlUsesViteDevAssets,
    navigationCacheUrl,
} from './offline-sw-policy.js';

const DEFAULT_BOOTSTRAP_URL = '/offline/household-profiling-bootstrap';

/**
 * @param {Document|null} doc
 * @returns {string}
 */
export function readHouseholdBootstrapUrl(doc) {
    const host = doc?.querySelector?.('[data-lml-hh-profiling]');
    return host?.getAttribute?.('data-offline-hp-bootstrap-url') || DEFAULT_BOOTSTRAP_URL;
}

function isServerHousehold(row) {
    return !row?.local && !row?.pending_sync && !/^HH-L/i.test(String(row?.household_no || ''));
}

function isServerMember(row) {
    return !row?.local && !/^MB-L-/i.test(String(row?.member_no || ''));
}

/** Every resident id referenced by a cached page must be the page owner's. */
function referencesOtherResident(body, member) {
    const own = member?.resident_id != null ? String(member.resident_id) : '';
    const pattern = /\/households\/[^/"'\s?#]+\/residents\/([^/"'\s?#]+)\//g;
    let match = pattern.exec(body);
    while (match) {
        if (!own || internalIdFor('r', match[1]) !== own) {
            return true;
        }
        match = pattern.exec(body);
    }
    return false;
}

/** Every member id linked from a cached page must be the page owner's. */
function referencesOtherMember(body, householdNo, memberNo) {
    const pattern = /\/household-profiling\/([^/"'\s?#]+)\/members\/(MB-[A-Za-z0-9-]+|m[a-z2-7]{12,})/g;
    let match = pattern.exec(body);
    while (match) {
        if (internalIdFor('h', match[1]) !== householdNo || internalIdFor('m', match[2]) !== memberNo) {
            return true;
        }
        match = pattern.exec(body);
    }
    return false;
}

/**
 * Phase 1 record views: Household Record, member view/edit and Nutritional Status
 * must exist in this actor's HTML cache, belong to their own household/member,
 * and never carry donor record content or DEV assets.
 *
 * Skipped only when no Cache API/origin exists (non-browser environments).
 *
 * @param {number} actorId
 * @param {Array<object>} households
 * @param {Array<object>} members
 * @param {{ caches?: CacheStorage, origin?: string, requireHealth?: boolean }} [options]
 */
export async function verifyPhase1RecordPages(actorId, households, members, options = {}) {
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : '');
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cachesApi || typeof cachesApi.open !== 'function' || !origin || !cacheName) {
        return { ok: true, skipped: true, checked: 0, missing: [] };
    }

    const cache = await cachesApi.open(cacheName);
    const missing = [];
    let checked = 0;
    const read = async (path) => {
        checked += 1;
        const hit = await cache.match(new Request(navigationCacheUrl(origin, publicPath(path))));
        return hit ? hit.text() : null;
    };

    const serverHouseholds = (households || []).filter(isServerHousehold);
    const serverHouseholdNos = new Set(serverHouseholds.map((row) => String(row.household_no)));

    for (const household of serverHouseholds) {
        const householdNo = String(household.household_no);
        const path = `/household-profiling/${householdNo}`;
        const body = await read(path);
        if (body == null) {
            missing.push({ path, reason: 'missing' });
        } else if (!body.includes(`data-household-no="${householdNo}"`)) {
            missing.push({ path, reason: 'identity-mismatch' });
        } else if (htmlUsesViteDevAssets(body)) {
            missing.push({ path, reason: 'dev-assets' });
        }
    }

    for (const member of (members || []).filter(isServerMember)) {
        const householdNo = String(member.household_no);
        const memberNo = String(member.member_no);
        if (!serverHouseholdNos.has(householdNo)) {
            continue;
        }

        const viewPath = `/household-profiling/${householdNo}/members/${memberNo}`;
        const view = await read(viewPath);
        if (view == null) {
            missing.push({ path: viewPath, reason: 'missing' });
        } else if (
            !view.includes(`data-member-id="${memberNo}"`)
            || referencesOtherResident(view, member)
        ) {
            missing.push({ path: viewPath, reason: 'identity-mismatch' });
        } else if (htmlUsesViteDevAssets(view)) {
            missing.push({ path: viewPath, reason: 'dev-assets' });
        }

        const editPath = `${viewPath}/edit`;
        if ((await read(editPath)) == null) {
            missing.push({ path: editPath, reason: 'missing' });
        }

        if (options.requireHealth === false) {
            continue;
        }
        const nutritionPath = healthModulePath(householdNo, memberNo, 'nutritional-status');
        const nutrition = await read(nutritionPath);
        if (nutrition == null) {
            missing.push({ path: nutritionPath, reason: 'missing' });
        } else if (/data-reason="health-shell-missing"/i.test(nutrition)) {
            missing.push({ path: nutritionPath, reason: 'shell-missing' });
        } else if (
            !(nutrition.includes(`/members/${urlKeyFor('m', memberNo)}`) || nutrition.includes(`data-member-id="${memberNo}"`))
            || referencesOtherMember(nutrition, householdNo, memberNo)
            || referencesOtherResident(nutrition, member)
        ) {
            missing.push({ path: nutritionPath, reason: 'identity-mismatch' });
        } else if (
            /data-timbang-id=/i.test(nutrition)
            && member.health?.has_records?.['nutritional-status'] !== true
        ) {
            missing.push({ path: nutritionPath, reason: 'donor-content' });
        } else if (htmlUsesViteDevAssets(nutrition)) {
            missing.push({ path: nutritionPath, reason: 'dev-assets' });
        }
    }

    return { ok: missing.length === 0, skipped: false, checked, missing };
}

/**
 * Verify actor-scoped HP/EH/Health structured data is present after a successful bootstrap.
 *
 * @param {number} actorId
 * @param {{ expectedGeneratedAt?: string|null, requireHealth?: boolean }} [options]
 */
export async function verifyStaffOfflineDataset(actorId, options = {}) {
    const id = Number(actorId);
    const fail = (reason, extra = {}) => ({
        ok: false,
        reason,
        actorId: Number.isInteger(id) && id > 0 ? id : null,
        dbVersion: OFFLINE_DB_VERSION,
        ...extra,
    });

    if (!Number.isInteger(id) || id <= 0) {
        return fail('no-actor');
    }

    const generatedAt = await getMeta(id, 'generated_at');
    const status = await getMeta(id, 'status');
    const catalogs = await getMeta(id, 'catalogs');
    const resolvedGeneratedAt = generatedAt || status?.generated_at || null;

    if (resolvedGeneratedAt == null || resolvedGeneratedAt === '') {
        return fail('no-generated-at');
    }

    if (options.expectedGeneratedAt && options.expectedGeneratedAt !== resolvedGeneratedAt) {
        return fail('generated-at-mismatch', { expectedGeneratedAt: options.expectedGeneratedAt });
    }

    if (!catalogs || typeof catalogs !== 'object') {
        return fail('no-catalogs');
    }

    const ehShells = await hasCanonicalEhShells(id);
    if (!ehShells) {
        return fail('eh-shells');
    }

    const households = await listHouseholdSnapshots(id);
    const members = await listMemberSnapshots(id);

    for (const row of households) {
        if (Number(row.actor_id) !== id) {
            return fail('actor-mismatch-household', { household_no: row.household_no });
        }
    }
    for (const row of members) {
        if (Number(row.actor_id) !== id) {
            return fail('actor-mismatch-member', { member_no: row.member_no });
        }
    }

    const requireHealth = options.requireHealth !== false;
    if (requireHealth && households.length > 0) {
        const healthShells = await hasCanonicalHealthShells(id);
        if (!healthShells) {
            return fail('health-shells');
        }
        const withHealthMeta = members.filter((row) => row.health && typeof row.health === 'object');
        const serverMembers = members.filter((row) => !row.local && !/^MB-L-/i.test(String(row.member_no || '')));
        if (serverMembers.length > 0 && withHealthMeta.length === 0) {
            return fail('health-meta');
        }
    }

    // Offline member edits need each member's own conflict hash (older snapshots lack it).
    const withoutHash = members.filter((row) => (
        isServerMember(row) && !String(row.field_hash || '').trim()
    ));
    if (withoutHash.length > 0) {
        return fail('member-field-hash', { memberCount: withoutHash.length });
    }

    const recordPages = await verifyPhase1RecordPages(id, households, members, {
        caches: options.caches,
        origin: options.origin,
        requireHealth,
    });
    if (!recordPages.ok) {
        return fail('record-pages', {
            missingPages: recordPages.missing.slice(0, 20),
            missingPageCount: recordPages.missing.length,
        });
    }

    return {
        ok: true,
        actorId: id,
        dbVersion: OFFLINE_DB_VERSION,
        generated_at: resolvedGeneratedAt,
        householdCount: households.length,
        memberCount: members.length,
        status,
    };
}

/**
 * Download authorized HP/member/catalog data, EH shells, and hydrate offline views.
 *
 * @param {object} [options]
 */
export async function prepareStaffOfflineDataset(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || 0);

    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false, reason: 'no-actor' };
    }

    const bootstrapUrl = options.bootstrapUrl || readHouseholdBootstrapUrl(doc);
    const onProgress = typeof options.onProgress === 'function' ? options.onProgress : null;

    onProgress?.({
        phase: 'dataset-fetch',
        label: 'Downloading household data…',
    });

    const bootstrap = await prepareHouseholdProfilingOffline({
        ...options,
        window: win,
        document: doc,
        actorId,
        bootstrapUrl,
        updatePageStatus: false,
        prepareEh: options.prepareEh !== false,
        onProgress: (detail) => {
            if (detail.state === 'preparing' && detail.total > 0) {
                onProgress?.({
                    phase: 'households',
                    ready: detail.ready,
                    total: detail.total,
                    label: `Households ${detail.ready} / ${detail.total}`,
                });
                return;
            }
            if (detail.state === 'preparing' && detail.total === 0) {
                onProgress?.({
                    phase: 'households',
                    ready: 0,
                    total: 0,
                    label: 'Households 0 / 0',
                });
            }
        },
    });

    if (!bootstrap?.ok) {
        return {
            ok: false,
            reason: bootstrap?.reason || 'bootstrap-failed',
            bootstrap,
        };
    }

    const memberCount = Number(bootstrap.memberCount) || 0;
    onProgress?.({
        phase: 'members',
        ready: memberCount,
        total: memberCount,
        label: memberCount > 0
            ? `Household members ${memberCount} / ${memberCount}`
            : 'Household members 0 / 0',
    });

    onProgress?.({
        phase: 'eh',
        label: 'Environmental Health…',
    });

    onProgress?.({
        phase: 'health',
        label: bootstrap.healthWarmed
            ? `Health Summary ready (${bootstrap.healthWarmed} records warmed)`
            : 'Health Summary shells…',
    });

    onProgress?.({
        phase: 'views',
        label: 'Preparing offline views…',
    });

    console.time('[PREP PERF] verification-dataset');
    const verified = await verifyStaffOfflineDataset(actorId, {
        expectedGeneratedAt: bootstrap.generated_at || null,
        caches: options.caches,
        origin: options.origin,
    });
    console.timeEnd('[PREP PERF] verification-dataset');

    if (!verified.ok) {
        return {
            ok: false,
            reason: verified.reason || 'verify-failed',
            bootstrap,
            verified,
        };
    }

    onProgress?.({
        phase: 'verify',
        label: 'Verifying local data…',
    });

    return {
        ok: true,
        reason: 'ready',
        bootstrap,
        verified,
        householdCount: verified.householdCount,
        memberCount: verified.memberCount,
        generated_at: verified.generated_at,
    };
}

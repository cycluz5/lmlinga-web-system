/**
 * Promote a synced local member to the server identity without waiting for
 * the whole queue to finish. Does not touch CSRF or the operations schema.
 */

import { reconcileLocalMember, resolveMemberHref, readMemberIdentityMap } from './offline-identity-map.js';
import {
    getHouseholdSnapshot,
    listMemberSnapshots,
    promoteLocalMemberIdentity,
} from './offline-hp-store.js';
import { cacheHydratedHouseholdPages } from './offline-hp-hydrate.js';
import { readPageActorId } from './offline-nav-guard.js';
import { publicPath, registerUrlKeysFromIdentities } from './offline-url-keys.js';

export async function reconcileResidentCreateSuccess(row, options = {}) {
    if (!row || row.operation_type !== 'RESIDENT_CREATE') {
        return false;
    }
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    const origin = options.origin || win.location?.origin || 'http://localhost';
    const localId = String(row.payload?.client_local_member_id || '').trim();
    const serverId = String(row.identities?.member_no || '').trim();
    if (!localId || !serverId || !/^MB-\d+$/i.test(serverId)) {
        return false;
    }

    // Learn the server's URL codes for the new member before any link is rewritten.
    registerUrlKeysFromIdentities(row.body?.url_keys);
    registerUrlKeysFromIdentities(row.identities);

    await reconcileLocalMember(localId, serverId, {
        actorId,
        origin,
        caches: options.caches,
    });

    const householdNo = String(row.parent_server?.household_no || row.payload?.household_no || '').trim();
    if (householdNo && Number.isInteger(actorId) && actorId > 0) {
        try {
            await promoteLocalMemberIdentity(
                actorId,
                householdNo,
                localId,
                serverId,
                row.identities?.resident_pk || row.identities?.resident_id || null,
            );
            const household = await getHouseholdSnapshot(actorId, householdNo);
            const members = await listMemberSnapshots(actorId, householdNo);
            if (household) {
                await cacheHydratedHouseholdPages(actorId, household, members, {
                    actorId,
                    origin,
                    caches: options.caches,
                });
            }
        } catch {
            // Snapshot promotion is best-effort after a successful sync.
        }
    }

    const map = await readMemberIdentityMap({ actorId, origin, caches: options.caches });
    doc?.querySelectorAll?.('a[href*="/members/MB-L-"]').forEach((link) => {
        const next = resolveMemberHref(link.getAttribute('href'), map, origin);
        if (next && next !== link.getAttribute('href')) {
            link.setAttribute('href', next);
        }
    });

    const path = win.location?.pathname || '';
    const localMatch = path.match(/\/members\/(MB-L-[A-Za-z0-9]+)(\/.*)?$/i);
    if (localMatch) {
        const mapped = map[localMatch[1]] || map[Object.keys(map).find((key) => key.toUpperCase() === localMatch[1].toUpperCase())];
        const mappedServer = mapped?.member_no;
        if (mappedServer && localMatch[1].toUpperCase() === localId.toUpperCase()) {
            win.location?.assign?.(publicPath(path.replace(localMatch[1], mappedServer)));
        }
    }

    return true;
}

export async function reconcileSyncedResidentCreates(detail, options = {}) {
    const synced = Array.isArray(detail?.synced) ? detail.synced : [];
    let promoted = 0;
    for (const row of synced) {
        if (await reconcileResidentCreateSuccess(row, options)) {
            promoted += 1;
        }
    }
    return promoted;
}

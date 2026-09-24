/**
 * User-controlled "Don't Sync" — discard pending queue ops + reconcile local read models.
 *
 * Never issues server DELETE. Actor-scoped only. Dependency-aware cascades for
 * HOUSEHOLD_CREATE / RESIDENT_CREATE parents.
 */

import {
    applyEhPayloadToHousehold,
} from './offline-eh-hydrate.js';
import {
    deleteHealthSnapshot,
    deleteHealthSnapshotsForMember,
} from './offline-health-store.js';
import {
    clearHouseholdEhOverlay,
    clearHouseholdPendingFlags,
    deleteHouseholdSnapshot,
    deleteMemberSnapshot,
    getHouseholdSnapshot,
    listMemberSnapshots,
    putHouseholdSnapshot,
    refreshHouseholdMemberCount,
} from './offline-hp-store.js';
import {
    countPendingVisible,
    deleteOperation,
    getOperation,
    householdNoFromRecord,
    listOperationsForActor,
    localMemberIdFromRecord,
    QUEUE_STATUS,
    sameHouseholdNo,
    sameLocalMemberId,
    updateOperation,
} from './offline-queue.js';

const HOUSEHOLD_CREATE_TYPES = new Set([
    'HOUSEHOLD_CREATE',
    'PLOT_HOUSEHOLD_WITH_HEAD',
]);

const MEMBER_DEPENDENT_TYPES = new Set([
    'HEALTH_SERVICE_WRITE',
    'RESIDENT_UPDATE',
]);

/**
 * @param {object} record
 * @returns {boolean}
 */
export function isHouseholdCreateOperation(record) {
    return HOUSEHOLD_CREATE_TYPES.has(String(record?.operation_type || ''));
}

/**
 * @param {object} root
 * @param {object} candidate
 * @returns {boolean}
 */
export function isDiscardDependentOf(root, candidate) {
    if (!root || !candidate || root.local_id === candidate.local_id) {
        return false;
    }
    if (Number(root.actor_id) !== Number(candidate.actor_id)) {
        return false;
    }
    const rootType = String(root.operation_type || '');
    const candType = String(candidate.operation_type || '');

    if (HOUSEHOLD_CREATE_TYPES.has(rootType)) {
        const hh = householdNoFromRecord(root);
        return Boolean(hh) && sameHouseholdNo(householdNoFromRecord(candidate), hh);
    }

    if (rootType === 'RESIDENT_CREATE') {
        const memberId = localMemberIdFromRecord(root);
        if (!memberId) {
            return false;
        }
        if (!MEMBER_DEPENDENT_TYPES.has(candType)) {
            return false;
        }
        return sameLocalMemberId(localMemberIdFromRecord(candidate), memberId);
    }

    return false;
}

/**
 * Compute the full discard set for a queue row (root + dependents).
 *
 * @param {number} actorId
 * @param {string} localId
 * @returns {Promise<{
 *   ok: boolean,
 *   reason?: string,
 *   root?: object,
 *   operations?: object[],
 *   counts?: { members: number, health: number, eh: number, amenities: number, other: number },
 * }>}
 */
export async function collectDiscardPlan(actorId, localId) {
    const actor = Number(actorId);
    const id = String(localId || '').trim();
    if (!Number.isInteger(actor) || actor <= 0 || !id) {
        return { ok: false, reason: 'invalid-target' };
    }
    const root = await getOperation(id);
    if (!root) {
        // Nothing to delete: callers may treat the desired end state as already satisfied.
        return { ok: false, reason: 'not-found', already_absent: true };
    }
    if (Number(root.actor_id) !== actor) {
        return { ok: false, reason: 'not-found' };
    }
    if (root.status === QUEUE_STATUS.SYNCING) {
        return { ok: false, reason: 'syncing' };
    }

    const all = await listOperationsForActor(actor);
    const dependents = all.filter((row) => isDiscardDependentOf(root, row));
    const operations = [root, ...dependents];

    const memberIds = new Set();
    let health = 0;
    let eh = 0;
    let amenities = 0;
    let other = 0;
    operations.forEach((row) => {
        const type = String(row.operation_type || '');
        if (type === 'RESIDENT_CREATE') {
            const mid = localMemberIdFromRecord(row);
            if (mid) {
                memberIds.add(mid.toUpperCase());
            }
        } else if (type === 'HEALTH_SERVICE_WRITE') {
            health += 1;
        } else if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE') {
            eh += 1;
        } else if (type === 'HOUSEHOLD_AMENITIES_UPDATE') {
            amenities += 1;
        } else if (row.local_id !== root.local_id) {
            other += 1;
        }
    });

    return {
        ok: true,
        root,
        operations,
        counts: {
            members: memberIds.size,
            health,
            eh,
            amenities,
            other,
        },
    };
}

/**
 * Build confirmation copy for the discard modal.
 *
 * @param {{ root: object, counts: object, operations: object[] }} plan
 */
export function buildDiscardConfirmation(plan) {
    const root = plan?.root;
    const counts = plan?.counts || {};
    const type = String(root?.operation_type || '');
    const hh = householdNoFromRecord(root) || '';
    const memberName = [
        root?.payload?.first_name,
        root?.payload?.last_name,
    ].filter(Boolean).join(' ').trim()
        || String(root?.payload?.client_local_member_id || localMemberIdFromRecord(root) || 'member');

    if (HOUSEHOLD_CREATE_TYPES.has(type)) {
        const bullets = [];
        if (counts.members > 0) {
            bullets.push(`${counts.members} household member${counts.members === 1 ? '' : 's'}`);
        }
        if (counts.health > 0) {
            bullets.push(`${counts.health} health record${counts.health === 1 ? '' : 's'}`);
        }
        if (counts.eh > 0) {
            bullets.push(`${counts.eh} environmental health update${counts.eh === 1 ? '' : 's'}`);
        }
        if (counts.amenities > 0) {
            bullets.push(`${counts.amenities} amenities update${counts.amenities === 1 ? '' : 's'}`);
        }
        if (counts.other > 0) {
            bullets.push(`${counts.other} related local update${counts.other === 1 ? '' : 's'}`);
        }
        if (bullets.length) {
            return {
                title: `Don't sync Household ${hh || ''}?`.trim(),
                body: [
                    'This household has locally saved dependent records:',
                    '',
                    ...bullets.map((line) => `• ${line}`),
                    '',
                    'If you continue, these local records will also be removed and none of them will be synced.',
                ].join('\n'),
                keepLabel: 'Keep Changes',
                confirmLabel: "Don't Sync Household and Related Changes",
                cascade: true,
            };
        }
        return {
            title: `Don't sync Household ${hh || ''}?`.trim(),
            body: 'This locally saved household will be removed and will not be sent to the server.',
            keepLabel: 'Keep Change',
            confirmLabel: "Don't Sync",
            cascade: false,
        };
    }

    if (type === 'RESIDENT_CREATE' && (counts.health > 0 || counts.other > 0)) {
        const healthLine = counts.health > 0
            ? `${counts.health} health record${counts.health === 1 ? '' : 's'}`
            : null;
        return {
            title: `Don't sync ${memberName}?`,
            body: [
                'This locally saved member has dependent records:',
                '',
                healthLine ? `• ${healthLine}` : null,
                '',
                'If you continue, these local health records will also be removed and will not be synced.',
                'The household will remain.',
            ].filter((line) => line !== null).join('\n'),
            keepLabel: 'Keep Changes',
            confirmLabel: "Don't Sync Member and Related Changes",
            cascade: true,
        };
    }

    return {
        title: "Don't sync this change?",
        body: 'This locally saved change will be removed and will not be sent to the server.',
        keepLabel: 'Keep Change',
        confirmLabel: "Don't Sync",
        cascade: false,
    };
}

async function reconcileAfterDiscard(actorId, plan) {
    const root = plan.root;
    const type = String(root.operation_type || '');
    const hh = householdNoFromRecord(root);
    const ops = plan.operations || [];

    if (HOUSEHOLD_CREATE_TYPES.has(type) && hh) {
        const members = await listMemberSnapshots(actorId, hh);
        for (const member of members) {
            const memberNo = String(member.member_no || '').trim();
            if (memberNo) {
                await deleteHealthSnapshotsForMember(actorId, memberNo);
            }
            await deleteMemberSnapshot(actorId, hh, memberNo);
        }
        await deleteHouseholdSnapshot(actorId, hh);
        return;
    }

    if (type === 'RESIDENT_CREATE') {
        const memberId = localMemberIdFromRecord(root);
        if (memberId) {
            await deleteHealthSnapshotsForMember(actorId, memberId);
            if (hh) {
                await deleteMemberSnapshot(actorId, hh, memberId);
                await refreshHouseholdMemberCount(actorId, hh);
            }
        }
        return;
    }

    if (type === 'HEALTH_SERVICE_WRITE') {
        await deleteHealthSnapshot(actorId, root);
        return;
    }

    if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE' && hh) {
        const remainingEh = (await listOperationsForActor(actorId)).filter((row) => (
            row.operation_type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE'
            && sameHouseholdNo(householdNoFromRecord(row), hh)
            && !ops.some((discarded) => discarded.local_id === row.local_id)
        ));
        const household = await getHouseholdSnapshot(actorId, hh);
        if (!household) {
            return;
        }
        if (remainingEh.length === 0) {
            if (household.local && !household.household_id) {
                await putHouseholdSnapshot(actorId, {
                    ...household,
                    water: {},
                    sanitation: {},
                    eh: { completed_step: 0 },
                });
            } else {
                await clearHouseholdEhOverlay(actorId, hh);
            }
            return;
        }
        let next = {
            ...household,
            water: {},
            sanitation: {},
            eh: { completed_step: 0 },
        };
        remainingEh
            .slice()
            .sort((a, b) => Number(a.payload?._eh_step || 0) - Number(b.payload?._eh_step || 0))
            .forEach((row) => {
                next = applyEhPayloadToHousehold(next, row.payload || {});
            });
        await putHouseholdSnapshot(actorId, next);
        return;
    }

    if ((type === 'HOUSEHOLD_UPDATE' || type === 'HOUSEHOLD_AMENITIES_UPDATE') && hh) {
        await clearHouseholdPendingFlags(actorId, hh);
    }
}

/**
 * Execute a previously collected discard plan.
 * Deletes queue rows first (actor-checked), then reconciles local snapshots.
 *
 * @param {{ ok: boolean, root: object, operations: object[] }} plan
 * @param {{ actorId?: number }} [options]
 */
export async function executeDiscardPlan(plan, options = {}) {
    if (!plan?.ok || !plan.root || !Array.isArray(plan.operations)) {
        return { ok: false, reason: 'invalid-plan' };
    }
    const actorId = Number(options.actorId || plan.root.actor_id);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false, reason: 'actor-required' };
    }
    if (Number(plan.root.actor_id) !== actorId) {
        return { ok: false, reason: 'actor-mismatch' };
    }

    // Re-validate live rows still belong to this actor before delete.
    const live = [];
    const absentIds = [];
    let foreign = false;
    for (const op of plan.operations) {
        const current = await getOperation(op.local_id);
        if (!current) {
            absentIds.push(op.local_id);
            continue;
        }
        if (Number(current.actor_id) !== actorId) {
            foreign = true;
            continue;
        }
        if (current.status === QUEUE_STATUS.SYNCING) {
            return { ok: false, reason: 'syncing' };
        }
        live.push(current);
    }

    const finish = async (removedIds, extras = {}) => ({
        ok: true,
        removed_ids: removedIds,
        removed_count: removedIds.length,
        absent_ids: absentIds,
        pending: await countPendingVisible(actorId),
        root_local_id: plan.root.local_id,
        root_operation_type: plan.root.operation_type,
        ...extras,
    });

    if (!live.length) {
        // Another actor's row is never touched; a row that is already gone satisfies the goal.
        return foreign ? { ok: false, reason: 'not-found' } : finish([], { already_absent: true });
    }

    // One single-store delete per selected local_id (same path replay uses on success).
    const removedIds = [];
    for (const row of live) {
        await deleteOperation(row.local_id);
        if (await getOperation(row.local_id)) {
            return { ok: false, reason: 'delete-not-verified', removed_ids: removedIds };
        }
        removedIds.push(row.local_id);
    }

    // Snapshot reconciliation is best-effort once the queue rows are gone.
    let reconcileFailed = false;
    try {
        await reconcileAfterDiscard(actorId, { ...plan, operations: live });
    } catch {
        reconcileFailed = true;
    }

    return finish(removedIds, reconcileFailed ? { reconcile_failed: true } : {});
}

/**
 * Discard result for a local_id whose durable row no longer exists (idempotent success).
 */
export async function resolveAbsentDiscard(actorId, localId) {
    const actor = Number(actorId);
    const id = String(localId || '').trim();
    if (!Number.isInteger(actor) || actor <= 0 || !id) {
        return { ok: false, reason: 'invalid-target' };
    }
    if (await getOperation(id)) {
        return { ok: false, reason: 'not-absent' };
    }
    return {
        ok: true,
        removed_ids: [],
        removed_count: 0,
        absent_ids: [id],
        already_absent: true,
        pending: await countPendingVisible(actor),
        root_local_id: id,
        root_operation_type: '',
    };
}

/**
 * Collect + execute discard for one local operation.
 */
export async function discardQueuedOperation(actorId, localId, options = {}) {
    const plan = await collectDiscardPlan(actorId, localId);
    if (!plan.ok) {
        return plan;
    }
    if (options.confirm === false) {
        return { ok: false, reason: 'confirmation-required', plan };
    }
    return executeDiscardPlan(plan, { actorId });
}

/**
 * Move a RETRY row back to pending without minting a new operation_id.
 *
 * @param {number} actorId
 * @param {string} localId
 */
export async function retryQueuedOperation(actorId, localId) {
    const actor = Number(actorId);
    const id = String(localId || '').trim();
    const row = await getOperation(id);
    if (!row || Number(row.actor_id) !== actor) {
        return { ok: false, reason: 'not-found' };
    }
    if (row.status !== QUEUE_STATUS.RETRY) {
        return { ok: false, reason: 'not-retryable' };
    }
    const updated = await updateOperation(id, {
        status: QUEUE_STATUS.PENDING,
        next_retry_at: null,
        last_safe_error_code: null,
        last_sync_error: null,
    });
    return {
        ok: true,
        record: updated,
        operation_id: updated?.operation_id || row.operation_id,
        local_id: row.local_id,
        pending: await countPendingVisible(actor),
    };
}

/**
 * Action flags for Offline Changes row buttons.
 *
 * @param {object} describedItem  from describeOfflineChange
 */
export function offlineChangeActionFlags(describedItem) {
    const status = String(describedItem?.status_key || '');
    return {
        show_review: Boolean(describedItem?.href && describedItem?.cta_label),
        show_retry: status === 'retry',
        show_discard: status !== 'syncing' && Boolean(describedItem?.id),
    };
}

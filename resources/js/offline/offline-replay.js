/**
 * Single-operation replay coordinator for POST /offline/sync.
 *
 * Obtains a fresh CSRF token from GET /offline/status and never writes it
 * to IndexedDB. Actor-bound: another signed-in worker cannot replay a
 * different account's queued operations.
 */

import { OFFLINE_EVENTS, isAttentionCode, isRetryCode, unsyncedAttentionMessage } from './offline-status.js';
import {
    applyServerIdentityToDependents,
    applyHouseholdIdentityToDependents,
    attentionUiDetail,
    countPendingVisible,
    deleteOperation,
    householdNoFromRecord,
    getOperation,
    isReplayable,
    isUnresolvedLocalParent,
    isUnresolvedLocalHouseholdParent,
    listAttentionForActor,
    listOperations,
    listOperationsForActor,
    listReplayableForActor,
    localMemberIdFromRecord,
    markAttention,
    markRetry,
    markSyncing,
    recoverMalformedAttentionOnce,
    recoverStaleSyncing,
    toSyncEnvelope,
    updateOperation,
} from './offline-queue.js';
import { reconcileResidentCreateSuccess } from './offline-hp-reconcile.js';
import { setMemberFieldHash } from './offline-hp-store.js';
import { registerUrlKeysFromIdentities } from './offline-url-keys.js';
import { reconcileHouseholdCreateSuccess, markHouseholdCreateAttention } from './offline-local-household.js';
import {
    markHealthServiceAttention,
    promoteHealthAfterMemberSync,
    reconcileHealthServiceSuccess,
} from './offline-local-health.js';
import { isClientOffline } from './offline-forms.js';

const ATTENTION_CODES = new Set([
    'TARGET_CHANGED',
    'TARGET_MISSING',
    'VALIDATION_FAILED',
    'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'IDEMPOTENCY_ACTOR_MISMATCH',
    'FORBIDDEN',
    'ACCOUNT_INACTIVE',
    'UNKNOWN_OPERATION',
    'MALFORMED',
    'CONFLICT',
    'QUEUE_ACTOR_MISMATCH',
]);

const SUCCESS_CODES = new Set(['SYNCED', 'ALREADY_APPLIED']);

function syncedDetail(record, payload) {
    const body = payload && typeof payload === 'object' ? payload : {};
    const household = body.household && typeof body.household === 'object' ? body.household : {};
    const resident = body.resident && typeof body.resident === 'object' ? body.resident : {};
    registerUrlKeysFromIdentities(body.url_keys);
    return {
        operation_type: record.operation_type,
        operation_id: record.operation_id,
        payload: record.payload,
        parent_server: record.parent_server,
        identities: {
            household_no: household.household_no || body.household_no || null,
            member_no: resident.member_no || body.member_no || null,
            household_pk: household.id ?? null,
            resident_pk: resident.id ?? null,
            field_hash: resident.field_hash || body.field_hash || null,
        },
        body,
    };
}

async function refreshMemberFieldHash(record, detail) {
    if (record?.operation_type !== 'RESIDENT_UPDATE') {
        return;
    }
    const householdNo = householdNoFromRecord(record);
    const memberNo = String(record.parent_server?.member_no || localMemberIdFromRecord(record) || '').trim();
    try {
        await setMemberFieldHash(record.actor_id, householdNo, memberNo, detail?.identities?.field_hash);
    } catch {
        // Best-effort: a stale hash only causes a conflict prompt, never data loss.
    }
}

function attentionEventDetail(record, extras = {}) {
    return {
        ...attentionUiDetail(record),
        id: record?.local_id,
        operation_id: record?.operation_id || record?.local_id,
        ...extras,
    };
}

/**
 * Map a /offline/sync HTTP result onto queue handling.
 *
 * MALFORMED is reserved for structured envelope failures (missing/invalid
 * operation_id, schema_version, or payload). Unparseable bodies and 5xx
 * responses stay retryable so a later successful replay can still run.
 *
 * @param {{ response?: { ok?: boolean, status?: number }, payload?: object | null }} posted
 */
export function classifySyncFailure(posted) {
    const payload = posted && posted.payload && typeof posted.payload === 'object' ? posted.payload : null;
    const code = payload && typeof payload.code === 'string' ? payload.code : '';
    const status = Number(posted?.response?.status) || 0;

    if (SUCCESS_CODES.has(code) && posted?.response?.ok) {
        return { kind: 'success', code };
    }
    if (SUCCESS_CODES.has(code)) {
        return { kind: 'success', code };
    }
    if (code === 'CSRF_MISMATCH') {
        return { kind: 'csrf', code };
    }
    if (code === 'SESSION_EXPIRED' || status === 401) {
        return { kind: 'session', code: 'SESSION_EXPIRED' };
    }
    if (code === 'RETRYABLE_ERROR' || isRetryCode(code) || status >= 500) {
        return { kind: 'retry', code: code || 'RETRYABLE_ERROR' };
    }
    if (ATTENTION_CODES.has(code) || isAttentionCode(code)) {
        return { kind: 'attention', code, errors: payload?.errors || null };
    }
    if (code) {
        return { kind: 'attention', code, errors: payload?.errors || null };
    }

    const errors = payload && payload.errors && typeof payload.errors === 'object' ? payload.errors : null;
    if (status === 422 && errors && (errors.operation_id || errors.schema_version || errors.payload)) {
        return { kind: 'attention', code: 'MALFORMED', errors };
    }
    if (status === 422 && errors) {
        return { kind: 'attention', code: 'VALIDATION_FAILED', errors };
    }

    return { kind: 'retry', code: 'RETRYABLE_ERROR' };
}

/** @type {Promise<void> | null} */
let replayLock = null;

export function resetReplayLock() {
    replayLock = null;
}

/** @type {number} */
let retryTimer = 0;

/**
 * @param {object} [options]
 */
export function createReplayCoordinator(options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const fetchImpl = options.fetch || win.fetch;
    const root =
        options.root ||
        (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);

    const statusUrl =
        options.statusUrl ||
        root?.getAttribute?.('data-offline-status-url') ||
        '/offline/status';
    const syncUrl =
        options.syncUrl ||
        root?.getAttribute?.('data-offline-sync-url') ||
        '/offline/sync';

    let destroyed = false;
    let csrfRetries = 0;

    function emit(name, detail = {}) {
        if (typeof options.onEvent === 'function') {
            options.onEvent(name, detail);
        }
        if (typeof win.dispatchEvent !== 'function') {
            return;
        }
        const event =
            typeof CustomEvent === 'function'
                ? new CustomEvent(name, { detail })
                : { type: name, detail };
        win.dispatchEvent(event);
    }

    function currentActorFromPage() {
        const id = Number(root?.getAttribute?.('data-offline-actor-id') || 0);
        const username = root?.getAttribute?.('data-offline-actor-username') || '';
        return {
            actor_id: Number.isInteger(id) && id > 0 ? id : null,
            actor_username: username,
        };
    }

    async function pendingCountFor(actorId) {
        if (actorId) {
            return countPendingVisible(actorId);
        }
        return countPendingVisible();
    }

    async function probeStatus() {
        if (typeof fetchImpl !== 'function') {
            return { ok: false, reason: 'no-fetch' };
        }

        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? win.setTimeout(() => controller.abort(), options.statusTimeoutMs ?? 8000) : 0;

        try {
            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller ? controller.signal : undefined,
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch {
                payload = null;
            }

            if (!response.ok || !payload || payload.ok !== true) {
                const code = payload && typeof payload.code === 'string' ? payload.code : '';
                return { ok: false, reason: 'status', code, payload, http: response.status };
            }

            return {
                ok: true,
                actor_id: Number(payload.user_id) || null,
                actor_username: typeof payload.username === 'string' ? payload.username : '',
                csrf_token: typeof payload.csrf_token === 'string' ? payload.csrf_token : '',
                is_active: payload.is_active !== false,
                must_change_password: Boolean(payload.must_change_password),
            };
        } catch {
            return { ok: false, reason: 'network' };
        } finally {
            if (timer) {
                clearTimeout(timer);
            }
        }
    }

    async function postOperation(envelope, csrfToken) {
        const response = await fetchImpl(syncUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(envelope),
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        return { response, payload };
    }

    function scheduleRetry(delayMs) {
        if (retryTimer) {
            win.clearTimeout(retryTimer);
        }
        const wait = Math.max(250, Number(delayMs) || 1000);
        retryTimer = win.setTimeout(() => {
            retryTimer = 0;
            void replay();
        }, wait);
    }

    async function handleForeignQueue(currentActorId) {
        const all = await listOperations();
        const foreign = all.filter((item) => Number(item.actor_id) !== Number(currentActorId));
        if (!foreign.length) {
            return;
        }

        emit(OFFLINE_EVENTS.SYNC_ATTENTION, {
            code: 'QUEUE_ACTOR_MISMATCH',
            id: `queue-actor-${foreign[0].actor_id}`,
            message:
                'Waiting updates on this device belong to another account. They will not be sent until that account signs in.',
        });
    }

    async function runReplay() {
        if (destroyed) {
            return;
        }

        if (isClientOffline({ window: win, navigator: win.navigator, root })) {
            return;
        }

        await recoverStaleSyncing();
        await recoverMalformedAttentionOnce();

        const status = await probeStatus();
        if (!status.ok) {
            if (status.code === 'SESSION_EXPIRED' || status.http === 401) {
                const remaining = await pendingCountFor(currentActorFromPage().actor_id);
                emit(OFFLINE_EVENTS.SYNC_ATTENTION, {
                    code: 'SESSION_EXPIRED',
                    id: 'session:SESSION_EXPIRED',
                    pending: remaining,
                });
                return;
            }

            if (status.reason === 'network' || isClientOffline({ window: win, navigator: win.navigator, root })) {
                if (!isClientOffline({ window: win, navigator: win.navigator, root })) {
                    scheduleRetry(4000);
                }
                return;
            }

            emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor(currentActorFromPage().actor_id) });
            scheduleRetry(4000);
            return;
        }

        // Legacy must_change_password is informational only and must not block replay.
        if (status.is_active === false) {
            emit(OFFLINE_EVENTS.SYNC_ATTENTION, {
                code: 'ACCOUNT_INACTIVE',
                id: 'session:ACCOUNT_INACTIVE',
            });
            return;
        }

        const actorId = status.actor_id;
        if (!actorId) {
            emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor() });
            return;
        }

        await handleForeignQueue(actorId);

        let csrfToken = status.csrf_token;
        if (!csrfToken) {
            emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor(actorId) });
            scheduleRetry(4000);
            return;
        }

        const replayable = await listReplayableForActor(actorId);
        if (!replayable.length) {
            await emitCaughtUpOrAttention(actorId);
            return;
        }

        emit(OFFLINE_EVENTS.SYNC_START, { pending: await pendingCountFor(actorId) });

        const synced = [];
        let notifiedAttention = false;
        const actorRecords = await listOperationsForActor(actorId);
        for (const seed of replayable) {
            if (destroyed) {
                return;
            }
            const record = (await getOperation(seed.local_id)) || seed;
            if (!isReplayable(record)) {
                continue;
            }
            if (isUnresolvedLocalParent(record)) {
                continue;
            }
            if (isUnresolvedLocalHouseholdParent(record, actorRecords)) {
                continue;
            }

            await markSyncing(record.local_id);
            const envelope = toSyncEnvelope(record);

            let posted;
            try {
                posted = await postOperation(envelope, csrfToken);
            } catch {
                await updateOperation(record.local_id, {
                    status: 'pending',
                    last_safe_error_code: null,
                });
                if (!isClientOffline({ window: win, navigator: win.navigator, root })) {
                    scheduleRetry(4000);
                }
                return;
            }

            const classified = classifySyncFailure(posted);

            if (classified.kind === 'success') {
                const detail = syncedDetail(record, posted.payload);
                synced.push(detail);
                await deleteOperation(record.local_id);
                await refreshMemberFieldHash(record, detail);
                if (record.operation_type === 'RESIDENT_CREATE') {
                    const localId = localMemberIdFromRecord(record);
                    if (localId) {
                        await applyServerIdentityToDependents(actorId, localId, detail.identities);
                    }
                    try {
                        await reconcileResidentCreateSuccess(detail, {
                            window: win,
                            document: typeof document !== 'undefined' ? document : null,
                            root,
                            actorId,
                        });
                    } catch {
                        // Identity promotion must not stop remaining replay.
                    }
                    try {
                        const serverId = String(detail.identities?.member_no || detail.body?.resident?.member_no || '').trim();
                        if (localId && serverId) {
                            await promoteHealthAfterMemberSync(localId, serverId, {
                                window: win,
                                document: typeof document !== 'undefined' ? document : null,
                                root,
                                actorId,
                            });
                        }
                    } catch {
                        // Health rebind is best-effort.
                    }
                }
                if (record.operation_type === 'HEALTH_SERVICE_WRITE') {
                    try {
                        await reconcileHealthServiceSuccess(detail, {
                            window: win,
                            document: typeof document !== 'undefined' ? document : null,
                            root,
                            actorId,
                        });
                    } catch {
                        // Snapshot clear is best-effort.
                    }
                }
                if (record.operation_type === 'HOUSEHOLD_CREATE') {
                    try {
                        await reconcileHouseholdCreateSuccess(detail, {
                            window: win,
                            document: typeof document !== 'undefined' ? document : null,
                            root,
                            actorId,
                        });
                    } catch {
                        // Snapshot promotion must not stop remaining replay.
                    }
                    try {
                        const pk = Number(detail.identities?.household_pk || detail.body?.household?.id || 0);
                        const householdNo = String(
                            detail.identities?.household_no
                            || detail.body?.household?.household_no
                            || detail.payload?.household_no
                            || '',
                        ).trim();
                        if (householdNo && Number.isInteger(pk) && pk > 0) {
                            await applyHouseholdIdentityToDependents(actorId, householdNo, pk);
                            // Refresh local actorRecords so later RESIDENT_CREATE sees household_id.
                            const refreshed = await listOperationsForActor(actorId);
                            actorRecords.length = 0;
                            actorRecords.push(...refreshed);
                        }
                    } catch {
                        // Dependent rebind must not stop remaining replay.
                    }
                }
                csrfRetries = 0;
                continue;
            }

            if (classified.kind === 'csrf' && csrfRetries < 1) {
                csrfRetries += 1;
                await updateOperation(record.local_id, { status: 'pending' });
                const refreshed = await probeStatus();
                if (refreshed.ok && refreshed.csrf_token) {
                    csrfToken = refreshed.csrf_token;
                    const retryPost = await postSafely(envelope, csrfToken, record);
                    if (retryPost === 'stop') {
                        return;
                    }
                    if (retryPost && retryPost !== 'ok' && retryPost.synced) {
                        synced.push(retryPost.synced);
                    }
                    continue;
                }
                await markRetry(record.local_id, 'CSRF_MISMATCH', (record.retry_count || 0) + 1);
                emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor(actorId) });
                scheduleRetry(4000);
                return;
            }

            if (classified.kind === 'session') {
                await updateOperation(record.local_id, { status: 'pending' });
                emit(OFFLINE_EVENTS.SYNC_ATTENTION, {
                    code: 'SESSION_EXPIRED',
                    id: record.local_id,
                    pending: await pendingCountFor(actorId),
                });
                return;
            }

            if (classified.kind === 'retry' || classified.kind === 'csrf') {
                const nextCount = (record.retry_count || 0) + 1;
                const updated = await markRetry(
                    record.local_id,
                    classified.code || 'RETRYABLE_ERROR',
                    nextCount,
                );
                emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor(actorId) });
                scheduleRetry(backoffFrom(updated?.retry_count ?? nextCount));
                return;
            }

            await markAttention(
                record.local_id,
                classified.code || 'VALIDATION_FAILED',
                classified.errors || null,
            );
            const attended = await getOperation(record.local_id);
            if (record.operation_type === 'HOUSEHOLD_CREATE') {
                try {
                    await markHouseholdCreateAttention(
                        {
                            ...(attended || record),
                            last_safe_error_code: classified.code || 'VALIDATION_FAILED',
                        },
                        { window: win, document: typeof document !== 'undefined' ? document : null, root, actorId },
                    );
                } catch {
                    // Keep local snapshot best-effort.
                }
            }
            if (record.operation_type === 'HEALTH_SERVICE_WRITE') {
                try {
                    await markHealthServiceAttention(
                        {
                            ...(attended || record),
                            last_safe_error_code: classified.code || 'VALIDATION_FAILED',
                        },
                        { window: win, document: typeof document !== 'undefined' ? document : null, root, actorId },
                    );
                } catch {
                    // Keep local health snapshot best-effort.
                }
            }
            emit(OFFLINE_EVENTS.SYNC_ATTENTION, attentionEventDetail(attended || {
                ...record,
                last_safe_error_code: classified.code || 'VALIDATION_FAILED',
                last_validation_errors: classified.errors || null,
            }, {
                code: classified.code || 'VALIDATION_FAILED',
                pending: await pendingCountFor(actorId),
            }));
            notifiedAttention = true;
        }

        await emitCaughtUpOrAttention(actorId, notifiedAttention, synced);
    }

    async function emitCaughtUpOrAttention(actorId, alreadyNotified = false, synced = []) {
        const remainingReplayable = await listReplayableForActor(actorId);
        const remaining = await pendingCountFor(actorId);
        if (remainingReplayable.length) {
            emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: remaining });
            scheduleRetry(backoffFrom(remainingReplayable[0].retry_count));
            return;
        }
        if (remaining > 0) {
            if (!alreadyNotified) {
                const attentionRows = await listAttentionForActor(actorId);
                const first = attentionRows[0];
                if (first) {
                    emit(OFFLINE_EVENTS.SYNC_ATTENTION, attentionEventDetail(first, {
                        code: first.last_safe_error_code || 'QUEUE_NEEDS_ATTENTION',
                        pending: remaining,
                    }));
                } else {
                    emit(OFFLINE_EVENTS.SYNC_ATTENTION, {
                        code: 'QUEUE_NEEDS_ATTENTION',
                        id: 'queue:unsynced',
                        pending: remaining,
                        message: unsyncedAttentionMessage(remaining),
                    });
                }
            }
            return;
        }
        emit(OFFLINE_EVENTS.SYNC_SUCCESS, { pending: 0, synced });
    }

    async function postSafely(envelope, csrfToken, record) {
        try {
            const posted = await postOperation(envelope, csrfToken);
            const classified = classifySyncFailure(posted);
            if (classified.kind === 'success') {
                await deleteOperation(record.local_id);
                const detail = syncedDetail(record, posted.payload);
                await refreshMemberFieldHash(record, detail);
                if (record.operation_type === 'RESIDENT_CREATE') {
                    const localId = localMemberIdFromRecord(record);
                    if (localId) {
                        await applyServerIdentityToDependents(record.actor_id, localId, detail.identities);
                    }
                    try {
                        await reconcileResidentCreateSuccess(detail, {
                            window: win,
                            document: typeof document !== 'undefined' ? document : null,
                            root,
                            actorId: record.actor_id,
                        });
                    } catch {
                        // Identity promotion must not stop remaining replay.
                    }
                }
                if (record.operation_type === 'HOUSEHOLD_CREATE') {
                    try {
                        await reconcileHouseholdCreateSuccess(detail, {
                            window: win,
                            document: typeof document !== 'undefined' ? document : null,
                            root,
                            actorId: record.actor_id,
                        });
                    } catch {
                        // Snapshot promotion must not stop remaining replay.
                    }
                    try {
                        const pk = Number(detail.identities?.household_pk || detail.body?.household?.id || 0);
                        const householdNo = String(
                            detail.identities?.household_no
                            || detail.body?.household?.household_no
                            || detail.payload?.household_no
                            || '',
                        ).trim();
                        if (householdNo && Number.isInteger(pk) && pk > 0) {
                            await applyHouseholdIdentityToDependents(record.actor_id, householdNo, pk);
                        }
                    } catch {
                        // Dependent rebind must not stop remaining replay.
                    }
                }
                return { synced: detail };
            }
            if (classified.kind === 'csrf') {
                await markRetry(record.local_id, 'CSRF_MISMATCH', (record.retry_count || 0) + 1);
                emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor(record.actor_id) });
                return 'stop';
            }
            if (classified.kind === 'retry') {
                await markRetry(
                    record.local_id,
                    classified.code || 'RETRYABLE_ERROR',
                    (record.retry_count || 0) + 1,
                );
                emit(OFFLINE_EVENTS.SYNC_RETRY, { pending: await pendingCountFor(record.actor_id) });
                return 'stop';
            }
            if (classified.kind === 'session') {
                await updateOperation(record.local_id, { status: 'pending' });
                emit(OFFLINE_EVENTS.SYNC_ATTENTION, {
                    code: 'SESSION_EXPIRED',
                    id: record.local_id,
                });
                return 'stop';
            }
            await markAttention(
                record.local_id,
                classified.code || 'VALIDATION_FAILED',
                classified.errors || null,
            );
            const attended = await getOperation(record.local_id);
            if (record.operation_type === 'HOUSEHOLD_CREATE') {
                try {
                    await markHouseholdCreateAttention(
                        {
                            ...(attended || record),
                            last_safe_error_code: classified.code || 'VALIDATION_FAILED',
                        },
                        { window: win, document: typeof document !== 'undefined' ? document : null, root },
                    );
                } catch {
                    // Keep local snapshot best-effort.
                }
            }
            emit(OFFLINE_EVENTS.SYNC_ATTENTION, attentionEventDetail(attended || {
                ...record,
                last_safe_error_code: classified.code || 'VALIDATION_FAILED',
                last_validation_errors: classified.errors || null,
            }, {
                code: classified.code || 'VALIDATION_FAILED',
            }));
            return 'ok';
        } catch {
            await updateOperation(record.local_id, {
                status: 'pending',
                last_safe_error_code: null,
            });
            if (!isClientOffline({ window: win, navigator: win.navigator, root })) {
                scheduleRetry(4000);
            }
            return 'stop';
        }
    }

    function backoffFrom(retryCount) {
        const steps = [1000, 2000, 4000, 8000, 15000, 30000];
        const index = Math.max(0, Math.min(Number(retryCount) || 0, steps.length - 1));
        return steps[index];
    }

    function replay() {
        if (replayLock) {
            return replayLock;
        }

        replayLock = runReplay().finally(() => {
            replayLock = null;
        });

        return replayLock;
    }

    function destroy() {
        destroyed = true;
        if (retryTimer) {
            win.clearTimeout(retryTimer);
            retryTimer = 0;
        }
    }

    return {
        replay,
        probeStatus,
        destroy,
        isBusy() {
            return replayLock != null;
        },
    };
}

export function isReplayBusy() {
    return replayLock != null;
}

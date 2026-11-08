/**
 * OFFLINE-6 boot: durable queue + replay + supported-form interception.
 *
 * Presentation remains in offline-status.js. This module drives those events.
 */

import { OFFLINE_EVENTS } from './offline-status.js';
import { bindSupportedForms, isClientOffline, OPERATION_TYPES } from './offline-forms.js';
import { countPendingVisible, recoverStaleSyncing } from './offline-queue.js';
import { loadUrlKeysForActor } from './offline-url-keys.js';
import { createReplayCoordinator } from './offline-replay.js';
import { handleResidentCreateQueued, handleResidentUpdateQueued } from './offline-local-member.js';
import {
    handleHouseholdCreateQueued,
    handleHouseholdUpdateQueued,
    markHouseholdCreateAttention,
    reconcileHouseholdCreateSuccess,
} from './offline-local-household.js';
import {
    handleHealthServiceQueued,
    markHealthServiceAttention,
    promoteHealthAfterMemberSync,
    reconcileHealthServiceSuccess,
} from './offline-local-health.js';
import { reconcileSyncedResidentCreates } from './offline-hp-reconcile.js';
import {
    handleEnvironmentalStepQueued,
    handlePlotHouseholdQueued,
    handlePlotHouseholdSynced,
} from './offline-eh-hydrate.js';
import { localMemberIdFromRecord } from './offline-queue.js';

function boot() {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.querySelector('[data-lml-offline-root]');
    if (!root) {
        return;
    }

    const win = window;
    const coordinator = createReplayCoordinator({ window: win, root });
    const actorId = Number(root.getAttribute('data-offline-actor-id') || 0);
    if (actorId > 0) {
        // Opaque URL keys of the prepared records (offline links / cache keys are built from them).
        void loadUrlKeysForActor(actorId).catch(() => {});
    }

    bindSupportedForms(document, { window: win, root });

    const triggerReplay = () => {
        if (isClientOffline({ window: win, navigator: win.navigator, root })) {
            return;
        }
        void coordinator.replay();
    };

    win.addEventListener(OFFLINE_EVENTS.SAVED, (event) => {
        const detail = event?.detail && typeof event.detail === 'object' ? event.detail : {};
        void (async () => {
            const requestedHouseholdOp = detail.requested_operation_type || detail.operation_type;
            if (
                detail.operation_type === OPERATION_TYPES.HOUSEHOLD_CREATE
                && requestedHouseholdOp !== OPERATION_TYPES.HOUSEHOLD_UPDATE
            ) {
                await handleHouseholdCreateQueued(detail, {
                    window: win,
                    document,
                    root,
                    actorId,
                });
            }
            if (
                detail.operation_type === OPERATION_TYPES.HOUSEHOLD_UPDATE
                || requestedHouseholdOp === OPERATION_TYPES.HOUSEHOLD_UPDATE
            ) {
                await handleHouseholdUpdateQueued(
                    {
                        ...detail,
                        operation_type: OPERATION_TYPES.HOUSEHOLD_UPDATE,
                    },
                    {
                        window: win,
                        document,
                        root,
                        actorId,
                    },
                );
            }
            if (detail.operation_type === OPERATION_TYPES.RESIDENT_CREATE) {
                const localId = String(detail.payload?.client_local_member_id || '').trim();
                if (/^MB-L-[A-Za-z0-9]+$/i.test(localId)) {
                    await handleResidentUpdateQueued(detail, { window: win, document, root, navigate: false });
                }
                await handleResidentCreateQueued(detail, { window: win, document, root });
            }
            if (detail.operation_type === OPERATION_TYPES.RESIDENT_UPDATE) {
                await handleResidentUpdateQueued(detail, { window: win, document, root });
            }
            if (detail.operation_type === OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD) {
                await handlePlotHouseholdQueued(detail, { window: win, document, root, actorId });
            }
            if (detail.operation_type === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE) {
                await handleEnvironmentalStepQueued(detail, { window: win, document, root, actorId });
            }
            if (detail.operation_type === OPERATION_TYPES.HEALTH_SERVICE_WRITE) {
                await handleHealthServiceQueued(detail, { window: win, document, root, actorId });
            }
            triggerReplay();
        })();
    });

    win.addEventListener(OFFLINE_EVENTS.SYNC_SUCCESS, (event) => {
        const detail = event?.detail && typeof event.detail === 'object' ? event.detail : {};
        void reconcileSyncedIdentities(detail, { window: win, document, root, actorId });
    });

    win.addEventListener(OFFLINE_EVENTS.SYNC_ATTENTION, (event) => {
        const detail = event?.detail && typeof event.detail === 'object' ? event.detail : {};
        void (async () => {
            if (String(detail.operation_type || '') === OPERATION_TYPES.HOUSEHOLD_CREATE) {
                await markHouseholdCreateAttention(
                    {
                        operation_type: OPERATION_TYPES.HOUSEHOLD_CREATE,
                        payload: { household_no: detail.household_no },
                        parent_server: { household_no: detail.household_no },
                        last_safe_error_code: detail.code || null,
                    },
                    { window: win, document, root, actorId, code: detail.code },
                );
            }
            if (String(detail.operation_type || '') === OPERATION_TYPES.HEALTH_SERVICE_WRITE) {
                await markHealthServiceAttention(
                    {
                        operation_type: OPERATION_TYPES.HEALTH_SERVICE_WRITE,
                        payload: detail.payload || {
                            _health_action: detail.health_action,
                            client_local_member_id: detail.member_no,
                        },
                        parent_server: {
                            household_no: detail.household_no,
                            member_no: detail.member_no,
                        },
                        last_safe_error_code: detail.code || null,
                    },
                    { window: win, document, root, actorId, code: detail.code },
                );
            }
        })();
    });

    win.addEventListener(OFFLINE_EVENTS.DISCARDED, (event) => {
        const detail = event?.detail && typeof event.detail === 'object' ? event.detail : {};
        if (win.LmlingaOffline?.setPendingCount && detail.pending != null) {
            win.LmlingaOffline.setPendingCount(Number(detail.pending) || 0);
        }
    });

    win.addEventListener(OFFLINE_EVENTS.RETRY_REQUESTED, () => {
        triggerReplay();
    });

    win.addEventListener(OFFLINE_EVENTS.ONLINE, triggerReplay);
    win.addEventListener('online', triggerReplay);

    void (async () => {
        try {
            await recoverStaleSyncing();
            const pending = actorId > 0 ? await countPendingVisible(actorId) : await countPendingVisible();
            if (win.LmlingaOffline?.setPendingCount) {
                win.LmlingaOffline.setPendingCount(pending);
            }
            if (pending > 0 && !isClientOffline({ window: win, navigator: win.navigator, root })) {
                triggerReplay();
            }
        } catch {
            // IndexedDB may be blocked; forms will surface a safe error on submit.
        }
    })();
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}

async function reconcileSyncedIdentities(detail, options = {}) {
    await reconcileSyncedResidentCreates(detail, options);
    const synced = Array.isArray(detail?.synced) ? detail.synced : [];
    for (const row of synced) {
        try {
            if (row.operation_type === OPERATION_TYPES.HOUSEHOLD_CREATE) {
                await reconcileHouseholdCreateSuccess(row, options);
            }
            if (row.operation_type === OPERATION_TYPES.RESIDENT_CREATE) {
                const localId = localMemberIdFromRecord(row)
                    || String(row.payload?.client_local_member_id || '').trim();
                const serverId = String(row.identities?.member_no || row.body?.resident?.member_no || '').trim();
                if (localId && serverId) {
                    await promoteHealthAfterMemberSync(localId, serverId, options);
                }
            }
            if (row.operation_type === OPERATION_TYPES.HEALTH_SERVICE_WRITE) {
                await reconcileHealthServiceSuccess(row, options);
            }
            await handlePlotHouseholdSynced(row, options);
        } catch {
            // Snapshot promotion is best-effort after a successful sync.
        }
    }
}

export { boot };

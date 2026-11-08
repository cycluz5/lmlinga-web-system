/**
 * Offline Changes viewer — human-readable queue presentation.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const changesUrl = pathToFileURL(path.resolve('resources/js/offline/offline-changes.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { enqueueOperation, QUEUE_STATUS, markAttention, updateOperation, markRetry } = await import(queueUrl);
const {
    describeOfflineChange,
    offlineChangeTitle,
    offlineChangeModule,
    offlineChangeProblem,
    isSyntheticOfflineChangeItem,
    repairHrefForOperation,
    listOfflineChangesForActor,
    renderOfflineChangesListHtml,
    offlineChangesCounts,
} = await import(changesUrl);

const ACTOR = 7;

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

describe('offline changes presentation', () => {
    it('titles Household / Member / Health / EH from operation payload', () => {
        assert.equal(offlineChangeTitle({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128' },
            parent_server: { household_no: '128' },
        }), 'Household 128');
        assert.equal(offlineChangeTitle({
            operation_type: 'RESIDENT_CREATE',
            payload: { first_name: 'Maria', last_name: 'Santos', client_local_member_id: 'MB-L-1' },
            parent_server: { household_no: '128' },
        }), 'Maria Santos');
        assert.equal(offlineChangeTitle({
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'risk_assessment_store',
                first_name: 'Maria',
                last_name: 'Santos',
                client_local_member_id: 'MB-L-1',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-1' },
        }), 'Risk Assessment — Maria Santos');
        assert.equal(offlineChangeTitle({
            operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
            payload: { household_no: '128', _eh_step: 1 },
            parent_server: { household_no: '128' },
        }), 'Environmental Health — Household 128');
    });

    it('maps modules and repair hrefs without requiring server IDs', () => {
        assert.equal(offlineChangeModule({ operation_type: 'HOUSEHOLD_CREATE' }), 'Household Profiling');
        assert.equal(offlineChangeModule({
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'family_planning_store' },
        }), 'Family Planning');
        assert.equal(
            repairHrefForOperation({
                operation_type: 'HOUSEHOLD_CREATE',
                payload: { household_no: '128' },
                parent_server: { household_no: '128' },
            }),
            '/household-profiling/128/edit',
        );
        assert.equal(
            repairHrefForOperation({
                operation_type: 'RESIDENT_CREATE',
                payload: { client_local_member_id: 'MB-L-abc' },
                parent_server: { household_no: '128' },
            }),
            '/household-profiling/128/members/MB-L-abc/edit',
        );
        assert.equal(
            repairHrefForOperation({
                operation_type: 'HEALTH_SERVICE_WRITE',
                payload: { _health_action: 'risk_assessment_store', client_local_member_id: 'MB-L-abc' },
                parent_server: { household_no: '128', member_no: 'MB-L-abc' },
            }),
            '/household-profiling/128/members/MB-L-abc/risk-assessment',
        );
    });

    it('prefers real validation errors and never invents Sex for household ops', async () => {
        const hh = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128' },
            parent_server: { household_no: '128' },
        });
        await markAttention(hh.local_id, 'VALIDATION_FAILED', {
            zone: ['Zone is required.'],
        });
        const attended = (await listOfflineChangesForActor(ACTOR))[0];
        assert.equal(attended.problem.includes('Zone is required.'), true);
        assert.equal(attended.problem.includes('Sex is required'), false);
        assert.equal(attended.status_label, 'Needs attention');
        assert.equal(attended.cta_label, 'Review Record');
    });

    it('lists waiting dependents with Waiting for Household / Member status', async () => {
        await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: 'Zone 1', date_registered: '2026-09-22' },
            parent_server: { household_no: '128' },
            created_at_client: '2026-09-22T01:00:00.000Z',
        });
        await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-m1',
                first_name: 'Maria',
                last_name: 'Santos',
                sex: 'Female',
            },
            parent_server: { household_no: '128' },
            created_at_client: '2026-09-22T01:01:00.000Z',
        });
        await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                client_local_member_id: 'MB-L-m1',
                _health_action: 'risk_assessment_store',
                first_name: 'Maria',
                last_name: 'Santos',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-m1' },
            created_at_client: '2026-09-22T01:02:00.000Z',
        });
        const items = await listOfflineChangesForActor(ACTOR);
        assert.equal(items.length, 3);
        const health = items.find((row) => row.operation_type === 'HEALTH_SERVICE_WRITE');
        assert.equal(health.status_label, 'Waiting for Household');
        assert.match(health.problem, /Waiting for Household 128 to sync/i);
        const member = items.find((row) => row.operation_type === 'RESIDENT_CREATE');
        assert.equal(member.status_label, 'Waiting for Household');
        const counts = offlineChangesCounts(items);
        assert.equal(counts.waiting, 3);
        assert.equal(counts.attention, 0);
        const html = renderOfflineChangesListHtml(items, 'all');
        assert.match(html, /Waiting to Sync/);
        assert.match(html, /Risk Assessment — Maria Santos/);
        assert.doesNotMatch(html, /Sex is required/);
    });

    it('actor B cannot read actor A offline changes', async () => {
        await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128' },
            parent_server: { household_no: '128' },
        });
        assert.equal((await listOfflineChangesForActor(ACTOR)).length, 1);
        assert.equal((await listOfflineChangesForActor(9)).length, 0);
    });

    it('excludes synthetic QUEUE_NEEDS_ATTENTION / queue:unsynced from the viewer list helper', () => {
        assert.equal(isSyntheticOfflineChangeItem({
            id: 'queue:unsynced',
            code: 'QUEUE_NEEDS_ATTENTION',
            message: '1 offline update needs attention',
        }), true);
        assert.equal(isSyntheticOfflineChangeItem({
            id: 'op-real-1',
            operation_type: 'HEALTH_SERVICE_WRITE',
            code: 'RETRYABLE_ERROR',
        }), false);
    });

    it('shows safe retry reasons and keeps 422 field messages', async () => {
        const retry = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                newborn: { length: '50' },
                client_local_member_id: 'MB-030',
            },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        await markRetry(retry.local_id, 'RETRYABLE_ERROR', 1);
        const retryRow = (await listOfflineChangesForActor(ACTOR))[0];
        assert.equal(retryRow.status_label, 'Failed / Retry available');
        assert.match(retryRow.problem, /could not save this update temporarily/i);
        assert.doesNotMatch(retryRow.problem, /SQL|exception|stack/i);

        await markRetry(retry.local_id, 'CSRF_MISMATCH', 2);
        const csrfRow = (await listOfflineChangesForActor(ACTOR))[0];
        assert.match(csrfRow.problem, /session token changed/i);

        const attended = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'deworming_store',
                client_local_member_id: 'MB-031',
            },
            parent_server: { household_no: 'HH-1', member_no: 'MB-031' },
        });
        await markAttention(attended.local_id, 'VALIDATION_FAILED', {
            year: ['The year field is required.'],
        });
        const attention = (await listOfflineChangesForActor(ACTOR))
            .find((row) => row.id === attended.local_id);
        assert.match(attention.problem, /year field is required/i);
    });
});

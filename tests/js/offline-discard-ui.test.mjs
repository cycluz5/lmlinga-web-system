/**
 * Don't Sync UI wiring — CSS stacking + identifier + delegated click path.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { readFileSync } from 'node:fs';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const cssSource = readFileSync(path.resolve('resources/css/pages/offline-status.css'), 'utf8');
const statusSource = readFileSync(path.resolve('resources/js/offline/offline-status.js'), 'utf8');
const changesSource = readFileSync(path.resolve('resources/js/offline/offline-changes.js'), 'utf8');

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const changesUrl = pathToFileURL(path.resolve('resources/js/offline/offline-changes.js')).href;
const discardUrl = pathToFileURL(path.resolve('resources/js/offline/offline-discard.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { enqueueOperation, listOperationsForActor, markAttention, QUEUE_STATUS } = await import(queueUrl);
const {
    describeOfflineChange,
    renderOfflineChangesListHtml,
    listOfflineChangesForActor,
} = await import(changesUrl);
const { collectDiscardPlan, executeDiscardPlan, buildDiscardConfirmation } = await import(discardUrl);

const ACTOR = 7;

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

describe('Dont Sync UI wiring', () => {
    it('A: CSS keeps discard confirm above Offline Changes (root cause of invisible modal)', () => {
        assert.match(cssSource, /\.lml-offline-dialog\s*\{[^}]*z-index:\s*1090/s);
        assert.match(cssSource, /\.lml-offline-discard\s*\{[^}]*z-index:\s*1110/s);
        assert.match(
            cssSource,
            /\.lml-offline-discard\s+\.lml-offline-dialog__backdrop\s*\{[^}]*pointer-events:\s*auto/s,
        );
        // Guard against the original bug regressing.
        assert.doesNotMatch(cssSource, /\.lml-offline-discard\s*\{[^}]*z-index:\s*1080/s);
    });

    it('B: rendered Dont Sync button uses local_id expected by collectDiscardPlan', async () => {
        const op = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                newborn: { length: '50' },
                client_local_member_id: 'MB-030',
            },
            parent_server: {
                household_no: 'HH-1',
                member_no: 'MB-030',
                household_id: 11,
                resident_id: 30,
            },
        });
        const described = describeOfflineChange(op, [op]);
        assert.equal(described.id, op.local_id);
        assert.notEqual(described.id, '');
        const html = renderOfflineChangesListHtml([described], 'all');
        assert.match(html, /data-lml-offline-change-discard/);
        assert.match(html, /type="button"/);
        assert.match(html, new RegExp(`data-offline-local-id="${op.local_id}"`));
        assert.doesNotMatch(html, /data-operation-id=/);

        // Same id the delegated handler reads and collectDiscardPlan looks up.
        assert.match(statusSource, /data-offline-local-id/);
        assert.match(statusSource, /beginDiscardForLocalId/);
        assert.match(statusSource, /collectDiscardPlan\(actorId, localId\)/);
        assert.match(changesSource, /data-lml-offline-change-discard/);

        const plan = await collectDiscardPlan(ACTOR, op.local_id);
        assert.equal(plan.ok, true);
        assert.equal(plan.root.local_id, op.local_id);
        assert.equal(plan.operations.length, 1);
    });

    it('C+D: confirmation copy exists; Keep path leaves plan unused until execute', async () => {
        const op = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-030',
            },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        const plan = await collectDiscardPlan(ACTOR, op.local_id);
        const copy = buildDiscardConfirmation(plan);
        assert.match(copy.title, /Don't sync/i);
        assert.match(copy.confirmLabel, /Don't Sync/i);
        assert.match(copy.keepLabel, /Keep/i);
        // Cancel / Keep Change: do not execute.
        assert.equal((await listOperationsForActor(ACTOR)).length, 1);
    });

    it('E+F+G+H: confirm execute discards health only; counters empty; member remains conceptually', async () => {
        const op = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-030',
            },
            parent_server: {
                household_no: 'HH-1',
                member_no: 'MB-030',
                household_id: 11,
                resident_id: 30,
            },
        });
        const plan = await collectDiscardPlan(ACTOR, op.local_id);
        assert.equal(plan.operations.length, 1);
        const result = await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal(result.ok, true);
        assert.equal(result.pending, 0);
        assert.deepEqual(result.removed_ids, [op.local_id]);
        assert.equal((await listOfflineChangesForActor(ACTOR)).length, 0);
        assert.equal((await listOperationsForActor(ACTOR)).length, 0);
    });

    it('I: actor mismatch rejected by collectDiscardPlan', async () => {
        const op = await enqueueOperation({
            actor_id: 9,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        const plan = await collectDiscardPlan(ACTOR, op.local_id);
        assert.equal(plan.ok, false);
        assert.equal(plan.reason, 'not-found');
        assert.equal((await listOperationsForActor(9)).length, 1);
    });

    it('J: status module surfaces discard failure via toast path (no silent open)', () => {
        assert.match(statusSource, /if \(!openDiscardDialog\(plan\)\)/);
        assert.match(statusSource, /OFFLINE_MESSAGES\.discardFailed/);
        assert.match(statusSource, /data-lml-offline-discard-dialog/);
    });

    it('N: ATTENTION renders Dont Sync without Retry; retry rows include both', async () => {
        const attentionOp = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        await markAttention(attentionOp.local_id, 'VALIDATION_FAILED', { newborn: ['bad'] });
        const items = await listOfflineChangesForActor(ACTOR);
        const html = renderOfflineChangesListHtml(items, 'all');
        assert.match(html, /Don't Sync/);
        assert.doesNotMatch(html, /data-lml-offline-change-retry/);

        const retryOp = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'deworming_store', client_local_member_id: 'MB-031' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-031' },
        });
        const { markRetry } = await import(queueUrl);
        await markRetry(retryOp.local_id, 'RETRYABLE_ERROR', 1);
        const retryItems = await listOfflineChangesForActor(ACTOR);
        const retryHtml = renderOfflineChangesListHtml(
            retryItems.filter((row) => row.id === retryOp.local_id),
            'all',
        );
        assert.match(retryHtml, /data-lml-offline-change-retry/);
        assert.match(retryHtml, /Don't Sync/);
        assert.equal(QUEUE_STATUS.RETRY, 'retry');
    });
});

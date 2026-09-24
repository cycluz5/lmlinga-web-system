/**
 * Household shell validation + attention repair (no false Sex errors).
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    attentionCorrectionHint,
    attentionUiDetail,
    enqueueOperation,
    getOperation,
    listOperationsForActor,
    markAttention,
    reasonFromValidationErrors,
    sanitizeValidationErrors,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href);
const {
    householdShellPayloadErrors,
    OPERATION_TYPES,
    serializeSupportedForm,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href);
const { classifySyncFailure } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-replay.js')).href
);
const {
    composeAttentionDialogMessage,
    safeAttentionRepairHref,
    safeHouseholdEditHref,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-status.js')).href);
const hydrateMod = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href
);
const { htmlCacheNameForActor, navigationCacheUrl } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href
);

const ACTOR = 7;
const ORIGIN = 'https://lmlinga.test';

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

function shellForm(fields, operation = 'HOUSEHOLD_CREATE') {
    const attrs = {
        'data-offline-operation': operation,
    };
    if (operation === 'HOUSEHOLD_UPDATE') {
        attrs['data-offline-parent-household-no'] = fields.household_no || '128';
        attrs['data-offline-local-household'] = '1';
    }
    return {
        elements: Object.entries(fields).map(([name, value]) => ({
            name,
            value: value == null ? '' : String(value),
            disabled: false,
            type: name === 'zone' ? 'select-one' : 'text',
        })),
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
        },
        closest() {
            return null;
        },
        querySelector() {
            return null;
        },
    };
}

describe('Household attention + shell validation', () => {
    it('TEST A: HOUSEHOLD_CREATE attention never invents Sex is required', () => {
        const hh = {
            operation_type: 'HOUSEHOLD_CREATE',
            payload: {
                household_no: '128',
                zone: 'Zone 3',
                street: 'Layuan',
                date_registered: '2026-09-22',
            },
            last_safe_error_code: 'VALIDATION_FAILED',
        };
        assert.notEqual(attentionCorrectionHint(hh), 'Sex is required.');
        assert.doesNotMatch(attentionCorrectionHint(hh), /Sex/i);

        const resident = {
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-abc',
                first_name: 'Maria',
                last_name: 'Santos',
                sex: null,
            },
            parent_server: { household_no: '128' },
        };
        assert.equal(attentionCorrectionHint(resident), 'Sex is required.');

        const plot = {
            operation_type: 'PLOT_HOUSEHOLD_WITH_HEAD',
            payload: { household_no: '180', first_name: 'Ana', last_name: 'Cruz', sex: '' },
        };
        assert.equal(attentionCorrectionHint(plot), 'Sex is required.');
    });

    it('TEST B: missing shell fields are rejected before queue semantics', () => {
        assert.ok(householdShellPayloadErrors({
            household_no: '',
            zone: '',
            date_registered: '',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE).length >= 3);

        // Street is not required unless the live form includes a street input
        // (schema-gated online). Empty/absent street must queue when valid otherwise.
        assert.equal(
            householdShellPayloadErrors({
                household_no: '128',
                zone: 'Zone 3',
                date_registered: '2026-09-22',
            }, OPERATION_TYPES.HOUSEHOLD_CREATE).length,
            0,
        );
        assert.equal(
            householdShellPayloadErrors({
                household_no: '128',
                zone: 'Zone 3',
                street: '',
                date_registered: '2026-09-22',
            }, OPERATION_TYPES.HOUSEHOLD_CREATE).length,
            0,
        );

        const formWithStreet = {
            querySelector(selector) {
                return selector === '[name="street"]' ? { name: 'street' } : null;
            },
        };
        const missingStreet = householdShellPayloadErrors({
            household_no: '128',
            zone: 'Zone 3',
            street: '',
            date_registered: '2026-09-22',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE, { form: formWithStreet });
        assert.ok(missingStreet.some((row) => row.key === 'street'));

        assert.equal(
            householdShellPayloadErrors({
                household_no: '128',
                zone: 'Zone 3',
                street: 'Layuan',
                date_registered: '2026-09-22',
            }, OPERATION_TYPES.HOUSEHOLD_CREATE, { form: formWithStreet }).length,
            0,
        );
    });

    it('TEST B2: blank latitude/longitude accepted without street', () => {
        assert.equal(
            householdShellPayloadErrors({
                household_no: '451',
                zone: 'Zone 1',
                date_registered: '2026-09-22',
                latitude: '',
                longitude: null,
            }, OPERATION_TYPES.HOUSEHOLD_CREATE).length,
            0,
        );
    });

    it('TEST C: zone must be Zone N, not bare digit', () => {
        const bad = householdShellPayloadErrors({
            household_no: '128',
            zone: '3',
            street: 'Layuan',
            date_registered: '2026-09-22',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE);
        assert.ok(bad.some((row) => row.key === 'zone'));

        const good = householdShellPayloadErrors({
            household_no: '128',
            zone: 'Zone 3',
            street: 'Layuan',
            date_registered: '2026-09-22',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE);
        assert.equal(good.length, 0);
    });

    it('TEST D: date_registered rejects missing/invalid/future', () => {
        assert.ok(householdShellPayloadErrors({
            household_no: '128',
            zone: 'Zone 3',
            street: 'Layuan',
            date_registered: '',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE).some((row) => row.key === 'date_registered'));

        assert.ok(householdShellPayloadErrors({
            household_no: '128',
            zone: 'Zone 3',
            street: 'Layuan',
            date_registered: '22-09-2026',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE).some((row) => row.key === 'date_registered'));

        assert.ok(householdShellPayloadErrors({
            household_no: '128',
            zone: 'Zone 3',
            street: 'Layuan',
            date_registered: '2099-01-01',
        }, OPERATION_TYPES.HOUSEHOLD_CREATE).some((row) => row.key === 'date_registered'));
    });

    it('TEST E: server 422 street error surfaces instead of Sex', () => {
        const classified = classifySyncFailure({
            response: { ok: false, status: 422 },
            payload: {
                code: 'VALIDATION_FAILED',
                errors: { street: ['The street field is required.'] },
            },
        });
        assert.equal(classified.kind, 'attention');
        assert.equal(classified.code, 'VALIDATION_FAILED');
        assert.deepEqual(classified.errors.street, ['The street field is required.']);

        const reason = reasonFromValidationErrors(classified.errors);
        assert.match(reason, /street/i);
        assert.doesNotMatch(reason, /Sex/i);

        const detail = attentionUiDetail({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: 'Zone 3' },
            last_validation_errors: classified.errors,
        });
        assert.match(detail.reason, /street/i);
        assert.doesNotMatch(detail.reason, /Sex/i);
        assert.match(
            composeAttentionDialogMessage(detail),
            /Needs correction:.*street/i,
        );
    });

    it('TEST F: Review destination is Household Edit for HOUSEHOLD_CREATE', () => {
        const detail = attentionUiDetail({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: {
                household_no: '128',
                zone: 'Zone 3',
                street: 'Layuan',
                date_registered: '2026-09-22',
            },
            last_safe_error_code: 'VALIDATION_FAILED',
        });
        assert.equal(detail.href, '/household-profiling/128/edit');
        assert.equal(detail.nav_kind, 'household-edit');
        assert.equal(safeHouseholdEditHref(detail.href), '/household-profiling/128/edit');
        assert.equal(safeAttentionRepairHref(detail.href), '/household-profiling/128/edit');
    });

    it('TEST G: Edit coalesces into same attention HOUSEHOLD_CREATE', async () => {
        const created = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: {
                household_no: '128',
                zone: 'Zone 3',
                street: '',
                date_registered: '2026-09-22',
            },
        });
        await markAttention(created.local_id, 'VALIDATION_FAILED', {
            street: ['The street field is required.'],
        });
        const stuck = await getOperation(created.local_id);
        assert.equal(stuck.status, 'attention');
        assert.ok(stuck.last_validation_errors?.street);

        const repaired = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_UPDATE',
            payload: {
                zone: 'Zone 3',
                street: 'Layuan Street',
                date_registered: '2026-09-22',
            },
            parent_server: { household_no: '128' },
        });

        assert.equal(repaired.local_id, created.local_id);
        assert.equal(repaired.operation_id, created.operation_id);
        assert.equal(repaired.operation_type, 'HOUSEHOLD_CREATE');
        assert.equal(repaired.status, 'pending');
        assert.equal(repaired.payload.street, 'Layuan Street');
        assert.equal(repaired.last_safe_error_code, null);
        assert.equal(repaired.last_validation_errors, null);

        const all = await listOperationsForActor(ACTOR);
        assert.equal(all.filter((row) => row.operation_type === 'HOUSEHOLD_CREATE').length, 1);
        assert.equal(all.filter((row) => row.operation_type === 'HOUSEHOLD_UPDATE').length, 0);
    });

    it('old attention HOUSEHOLD_CREATE without stored errors does not invent Sex', () => {
        const detail = attentionUiDetail({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: {
                household_no: '128',
                zone: 'Zone 3',
                street: 'Layuan',
                date_registered: '2026-09-22',
            },
            last_safe_error_code: 'VALIDATION_FAILED',
            last_validation_errors: null,
        });
        assert.doesNotMatch(detail.reason || '', /Sex/i);
        assert.equal(detail.href, '/household-profiling/128/edit');
    });

    it('fallback Household Edit zone options use Zone N values', async () => {
        const caches = createMemoryCaches();
        await hydrateMod.cacheHydratedHouseholdPages(ACTOR, {
            household_no: '128',
            zone: 'Zone 3',
            street: 'Layuan',
            date_registered: '2026-09-22',
            local: true,
            pending_sync: true,
        }, [], { caches, origin: ORIGIN });
        const cached = await caches.match(
            new Request(navigationCacheUrl(ORIGIN, '/household-profiling/128/edit')),
            { cacheName: htmlCacheNameForActor(ACTOR) },
        );
        const html = cached ? await cached.text() : '';
        assert.match(html, /value="Zone 3"/);
        assert.doesNotMatch(html, /<option value="3"/);
    });

    it('sanitizeValidationErrors drops unsafe keys', () => {
        assert.equal(sanitizeValidationErrors({
            password: ['x'],
            street: ['Street is required.'],
        })?.street[0], 'Street is required.');
        assert.equal(sanitizeValidationErrors({ password: ['x'] }), null);
    });
});

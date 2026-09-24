/**
 * Offline form serialization and queue-on-save — resources/js/offline/offline-forms.js
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const formsSource = readFileSync(path.resolve('resources/js/offline/offline-forms.js'), 'utf8');
const queueSource = readFileSync(path.resolve('resources/js/offline/offline-queue.js'), 'utf8');
const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href
);
const { listOperations, enqueueOperation, markAttention, getOperation, countPendingVisible } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const {
    assignNestedFormValue,
    flattenPayloadToFormNames,
    parseFormFieldName,
    serializeSupportedForm,
    queueSupportedOperation,
    queuePlotNewHousehold,
    isClientOffline,
    shouldQueueSupportedForm,
    localUnsyncedMemberIdFromForm,
    parentServerFromForm,
    baseSnapshotFromForm,
    handleSupportedFormSubmit,
    residentMemberPayloadErrors,
    OPERATION_TYPES,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href);
const { createReplayCoordinator, resetReplayLock } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-replay.js')).href
);

function field(overrides) {
    return {
        name: '',
        type: 'text',
        value: '',
        disabled: false,
        readOnly: false,
        checked: false,
        ...overrides,
    };
}

function fakeForm(fields, attrs = {}) {
    return {
        elements: fields,
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
        },
    };
}

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    resetReplayLock();
});

describe('offline nested bracket form serialization', () => {
    it('parses field[value], field[subfield], field[], and deeper nesting', () => {
        assert.deepEqual(parseFormFieldName('newborn[length]'), ['newborn', 'length']);
        assert.deepEqual(parseFormFieldName('iron[1st]'), ['iron', '1st']);
        assert.deepEqual(parseFormFieldName('disability[]'), ['disability', '']);
        assert.deepEqual(parseFormFieldName('mam[cured][date]'), ['mam', 'cured', 'date']);
        assert.deepEqual(parseFormFieldName('last_name'), ['last_name']);
    });

    it('nests newborn, iron, and deeper fields like a Laravel form submission', () => {
        const payload = serializeSupportedForm(fakeForm([
            field({ name: 'newborn[length]', value: '50' }),
            field({ name: 'newborn[weight]', value: '3.2' }),
            field({ name: 'iron[1st]', value: '2026-09-20' }),
            field({ name: 'mam[cured][date]', value: '2026-09-21' }),
            field({ name: 'disability[]', type: 'checkbox', value: 'none', checked: true }),
            field({ name: 'last_name', value: 'Santos' }),
            field({ name: 'remarks', value: 'ok' }),
        ]));

        assert.deepEqual(payload.newborn, { length: '50', weight: '3.2' });
        assert.deepEqual(payload.iron, { '1st': '2026-09-20' });
        assert.deepEqual(payload.mam, { cured: { date: '2026-09-21' } });
        assert.deepEqual(payload.disability, ['none']);
        assert.equal(payload.last_name, 'Santos');
        assert.equal(payload.remarks, 'ok');
        assert.equal(payload['newborn[length]'], undefined);
        assert.equal(payload['iron[1st]'], undefined);
    });

    it('flattens nested payloads and preserves legacy flat bracket keys for form hydration', () => {
        assert.deepEqual(flattenPayloadToFormNames({
            newborn: { length: '50', weight: '3.2' },
            iron: { '1st': '2026-09-20' },
        }), {
            'newborn[length]': '50',
            'newborn[weight]': '3.2',
            'iron[1st]': '2026-09-20',
        });
        assert.deepEqual(flattenPayloadToFormNames({
            'newborn[length]': '50',
            'iron[1st]': '2026-09-20',
        }), {
            'newborn[length]': '50',
            'iron[1st]': '2026-09-20',
        });
    });

    it('builds Child Nutrition shaped payload accepted by StoreChildNutritionRequest keys', () => {
        const payload = serializeSupportedForm(fakeForm([
            field({ name: 'newborn[length]', value: '50' }),
            field({ name: 'newborn[weight]', value: '3.2' }),
            field({ name: 'newborn[breastfeeding_date]', value: '2026-01-02' }),
            field({ name: 'iron[1st]', value: '2026-09-20' }),
            field({ name: 'iron[2nd]', value: '' }),
            field({ name: 'vitamin_a[va-6-11]', value: '2026-02-01' }),
            field({ name: 'mnp[mnp-6-11]', value: '2026-03-01' }),
            field({ name: 'lns_sq[lns-6-11]', value: '2026-04-01' }),
            field({ name: 'mam[cured][date]', value: '2026-05-01' }),
            field({ name: 'mam_cured', type: 'radio', value: 'yes', checked: true }),
            field({ name: 'sam[died][date]', value: '' }),
        ]));

        assert.equal(typeof payload.newborn, 'object');
        assert.equal(payload.newborn.length, '50');
        assert.equal(payload.newborn.weight, '3.2');
        assert.equal(payload.iron['1st'], '2026-09-20');
        assert.equal(payload.vitamin_a['va-6-11'], '2026-02-01');
        assert.equal(payload.mnp['mnp-6-11'], '2026-03-01');
        assert.equal(payload.lns_sq['lns-6-11'], '2026-04-01');
        assert.equal(payload.mam.cured.date, '2026-05-01');
        assert.equal(payload.mam_cured, 'yes');
        assert.equal(payload.sam.died.date, null);

        const target = {};
        assignNestedFormValue(target, 'vaccines[bcg][0]', '2025-01-15');
        assert.deepEqual(target.vaccines.bcg, ['2025-01-15']);
    });

    it('nests other HEALTH_SERVICE_WRITE bracket fields (immunization, risk, FP)', () => {
        const payload = serializeSupportedForm(fakeForm([
            field({ name: 'vaccines[bcg][0]', value: '2025-01-15' }),
            field({ name: 'vaccines[hpv][1st]', value: '2025-02-01' }),
            field({ name: 'vaccine_types[]', type: 'checkbox', value: 'bcg', checked: true }),
            field({ name: 'red_flags[]', type: 'checkbox', value: 'fever', checked: true }),
            field({ name: 'past_medical[]', type: 'checkbox', value: 'none', checked: true }),
            field({ name: 'commodities[0][name]', value: 'Pills' }),
            field({ name: 'commodities[0][quantity]', value: '2' }),
            field({ name: 'visits[1st][date]', value: '2026-01-10' }),
            field({ name: 'vitamin_a[date]', value: '2026-02-01' }),
            field({ name: 'year', value: '2026' }),
        ]));

        assert.deepEqual(payload.vaccines.bcg, ['2025-01-15']);
        assert.equal(payload.vaccines.hpv['1st'], '2025-02-01');
        assert.deepEqual(payload.vaccine_types, ['bcg']);
        assert.deepEqual(payload.red_flags, ['fever']);
        assert.deepEqual(payload.past_medical, ['none']);
        assert.equal(payload.commodities[0].name, 'Pills');
        assert.equal(payload.commodities[0].quantity, '2');
        assert.equal(payload.visits['1st'].date, '2026-01-10');
        assert.equal(payload.vitamin_a.date, '2026-02-01');
        assert.equal(payload.year, '2026');
    });
});

describe('offline form serialization', () => {
    it('preserves checkboxes, radios, selects, and nulls while excluding secrets and files', () => {
        const payload = serializeSupportedForm({
            elements: [
                field({ name: '_token', value: 'csrf-live' }),
                field({ name: '_method', value: 'PUT' }),
                field({ name: 'password', type: 'password', value: 'secret' }),
                field({ name: 'photo', type: 'file', value: 'x.png' }),
                field({ name: 'id', value: '9' }),
                field({ name: 'household_id', value: '9' }),
                field({ name: 'member_no', value: 'MB-999' }),
                field({ name: 'last_name', value: 'Santos' }),
                field({ name: 'middle_name', value: '' }),
                field({ name: 'relation', type: 'select-one', value: 'Spouse' }),
                field({ name: 'sex', type: 'radio', value: 'Female', checked: true }),
                field({ name: 'sex', type: 'radio', value: 'Male', checked: false }),
                field({ name: 'disability[]', type: 'checkbox', value: 'none', checked: true }),
                field({ name: 'disability[]', type: 'checkbox', value: 'others', checked: false }),
                field({ name: 'fp_user', type: 'select-one', value: 'No' }),
                field({ name: 'household_no', value: '121', readOnly: true }),
                field({ name: 'submit', type: 'submit', value: 'Save' }),
            ],
        });

        assert.equal(payload.last_name, 'Santos');
        assert.equal(payload.middle_name, null);
        assert.equal(payload.relation, 'Spouse');
        assert.equal(payload.sex, 'Female');
        assert.deepEqual(payload.disability, ['none']);
        assert.equal(payload.fp_user, 'No');
        assert.equal(payload._token, undefined);
        assert.equal(payload.password, undefined);
        assert.equal(payload.photo, undefined);
        assert.equal(payload.id, undefined);
        assert.equal(payload.household_id, undefined);
        assert.equal(payload.member_no, undefined);
        assert.equal(payload.household_no, undefined);
        assert.equal(payload.submit, undefined);
    });

    it('reads parent_server and field hash from data attributes without inventing PKs', () => {
        const form = fakeForm([], {
            'data-offline-parent-household-id': '42',
            'data-offline-parent-household-no': '121',
            'data-offline-parent-resident-id': '9',
            'data-offline-parent-member-no': 'MB-003',
            'data-offline-field-hash': 'a'.repeat(64),
        });

        assert.deepEqual(parentServerFromForm(form), {
            household_id: 42,
            household_no: '121',
            resident_id: 9,
            member_no: 'MB-003',
        });
        assert.deepEqual(baseSnapshotFromForm(form), { field_hash: 'a'.repeat(64) });
        assert.equal(parentServerFromForm(form).household_id === 42, true);
    });
});

describe('offline form queueing', () => {
    it('emits offline-saved after a durable IndexedDB write', async () => {
        const events = [];
        const win = {
            dispatchEvent(event) {
                events.push(event);
                return true;
            },
        };
        const result = await queueSupportedOperation(
            {
                operation_type: 'HOUSEHOLD_CREATE',
                payload: { household_no: '121', zone: 'Zone 2' },
                actor_id: 7,
                actor_username: 'bhw.ana',
            },
            { window: win },
        );

        assert.equal(result.ok, true);
        assert.equal(events[0].type, 'lmlinga:offline-saved');
        assert.equal(events[0].detail.pending, 1);
        const stored = await listOperations();
        assert.equal(stored.length, 1);
        assert.equal(stored[0].payload.household_no, '121');
        assert.match(stored[0].operation_id, /^[0-9a-f-]{36}$/i);
        assert.equal(JSON.stringify(stored[0]).includes('_token'), false);
    });

    it('does not emit offline-saved when IndexedDB is unavailable', async () => {
        setIndexedDBFactory(null);
        const events = [];
        const win = {
            dispatchEvent(event) {
                events.push(event);
                return true;
            },
        };
        const result = await queueSupportedOperation(
            {
                operation_type: 'HOUSEHOLD_CREATE',
                payload: { household_no: '121', zone: 'Zone 2' },
                actor_id: 7,
                actor_username: 'bhw.ana',
            },
            { window: win },
        );

        assert.equal(result.ok, false);
        assert.equal(events.some((event) => event.type === 'lmlinga:offline-saved'), false);
        assert.equal(events.some((event) => event.type === 'lmlinga:offline-storage-failed'), true);
    });

    it('queues Plot New Household coordinates without fabricating an EH handoff', async () => {
        const events = [];
        const result = await queuePlotNewHousehold(
            {
                household_no: '121',
                first_name: 'Ana',
                last_name: 'Santos',
                lat: 13.3811,
                lng: 123.4306,
                consent: true,
                household_type: 'HHTS',
                zone: '1',
                handoff_token: 'should-not-persist',
                redirect_url: '/environmental-health/household-water-supply?handoff=nope',
                id: 99,
                member_no: 'MB-999',
            },
            {
                window: {
                    dispatchEvent(event) {
                        events.push(event);
                        return true;
                    },
                },
                root: {
                    getAttribute(name) {
                        return name === 'data-offline-actor-id' ? '7' : '';
                    },
                },
            },
        );

        assert.equal(result.ok, true);
        const stored = result.record;
        assert.equal(stored.operation_type, 'PLOT_HOUSEHOLD_WITH_HEAD');
        assert.equal(stored.payload.lat, 13.3811);
        assert.equal(stored.payload.lng, 123.4306);
        assert.equal(stored.payload.household_no, '121');
        assert.equal(stored.payload.handoff_token, undefined);
        assert.equal(stored.payload.redirect_url, undefined);
        assert.equal(stored.payload.id, undefined);
        assert.equal(stored.payload.member_no, undefined);
        assert.equal(events[0].type, 'lmlinga:offline-saved');
    });

    it('treats navigator.offline as the capture path', () => {
        assert.equal(isClientOffline({ navigator: { onLine: false } }), true);
        assert.equal(isClientOffline({ navigator: { onLine: true }, window: {} }), false);
    });

    it('mints a local member id for offline resident creates', async () => {
        const { mintLocalMemberId, OPERATION_TYPES } = await import(
            pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href
        );
        assert.match(mintLocalMemberId(), /^MB-L-[A-F0-9]+$/);
        assert.equal(OPERATION_TYPES.HOUSEHOLD_AMENITIES_UPDATE, 'HOUSEHOLD_AMENITIES_UPDATE');
        assert.equal(OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE, 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE');
        assert.equal(OPERATION_TYPES.HEALTH_SERVICE_WRITE, 'HEALTH_SERVICE_WRITE');
        const payload = serializeSupportedForm(fakeForm([
            field({ name: 'last_name', value: 'Santos' }),
            field({ name: 'first_name', value: 'Ana' }),
        ], { 'data-offline-operation': 'RESIDENT_CREATE' }));
        assert.equal(payload.client_local_member_id, undefined);
    });

    it('does not fabricate a Laravel EH handoff token on the offline plot path', () => {
        assert.equal(formsSource.includes('localStorage'), false);
        assert.equal(queueSource.includes('localStorage'), false);
        assert.match(spotSource, /queuePlotNewHousehold/);
        assert.match(spotSource, /isClientOffline/);
        assert.match(spotSource, /Stored on this device/);
        const offlineBranch = spotSource.slice(
            spotSource.indexOf('const finishLocalQueue'),
            spotSource.indexOf('const response = await fetch(createUrl'),
        );
        assert.equal(offlineBranch.includes('handoff'), false);
        assert.equal(offlineBranch.includes('location.replace'), false);
        assert.equal(offlineBranch.includes('lml_pending_water_supply_household'), false);
        assert.match(
            formsSource,
            /import \{ persistPlotHouseholdReadModel \} from '\.\/offline-hp-store\.js'/,
        );
        assert.equal(formsSource.includes("import('./offline-hp-store.js')"), false);
        assert.match(offlineBranch, /await persistPlotHouseholdReadModel\(queued\.record\.actor_id, queued\.record\.payload\)/);
        assert.match(spotSource, /import \{ persistPlotHouseholdReadModel \} from '\.\.\/offline\/offline-hp-store'/);
    });
});

function residentCreateFields(overrides = {}) {
    return [
        field({ name: 'last_name', value: 'Morales' }),
        field({ name: 'first_name', value: overrides.first_name || 'Vicky' }),
        field({ name: 'relation', type: 'select-one', value: 'Daughter' }),
        field({ name: 'birthday', value: '2016-11-17' }),
        field({ name: 'sex', type: 'select-one', value: Object.prototype.hasOwnProperty.call(overrides, 'sex') ? overrides.sex : 'Female' }),
        field({ name: 'relationship_status', type: 'select-one', value: 'Single' }),
        field({ name: 'occupation', type: 'select-one', value: 'Student' }),
        field({ name: 'monthly_income', type: 'select-one', value: 'None / N/A' }),
        field({ name: 'religion', type: 'select-one', value: 'Roman Catholic' }),
        field({ name: 'education', type: 'select-one', value: 'Elementary Level' }),
        field({ name: 'fp_user', type: 'select-one', value: 'N/A' }),
        field({ name: 'philhealth', value: '123456789101' }),
        field({ name: 'disability[]', type: 'checkbox', value: 'none', checked: true }),
        field({ name: 'medical_history[]', type: 'checkbox', value: 'none', checked: true }),
    ];
}

function residentSubmitForm(fields) {
    return {
        ...fakeForm(fields, { 'data-offline-operation': 'RESIDENT_CREATE' }),
        querySelector() {
            return null;
        },
        closest() {
            return null;
        },
        setAttribute() {},
    };
}

describe('offline resident required fields', () => {
    it('rejects missing Sex because the server requires Male or Female', async () => {
        const memberSource = readFileSync(path.resolve('resources/js/pages/household-member-form.js'), 'utf8');
        assert.match(memberSource, /form\.addEventListener\('submit', \(event\) => \{[\s\S]*?\}, true\);/);
        assert.equal(
            residentMemberPayloadErrors({ last_name: 'Morales', first_name: 'Vicky' }, OPERATION_TYPES.RESIDENT_CREATE)
                .some((item) => item.key === 'sex'),
            true,
        );

        const form = residentSubmitForm(residentCreateFields({ sex: '' }));
        const event = {
            currentTarget: form,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        const queued = await handleSupportedFormSubmit(event, {
            navigator: { onLine: false },
            root: {
                getAttribute(name) {
                    if (name === 'data-offline-actor-id') {
                        return '7';
                    }
                    return name === 'data-offline-actor-username' ? 'bhw.ana' : '';
                },
            },
        });
        assert.equal(queued, true);
        assert.equal((await listOperations()).length, 0);
    });

    it('queues Male and Female Sex on offline create', async () => {
        for (const sex of ['Male', 'Female']) {
            const form = residentSubmitForm(residentCreateFields({ sex, first_name: sex === 'Male' ? 'Victor' : 'Vicky' }));
            const event = {
                currentTarget: form,
                defaultPrevented: false,
                preventDefault() {
                    this.defaultPrevented = true;
                },
            };
            const result = await handleSupportedFormSubmit(event, {
                navigator: { onLine: false },
                root: {
                    getAttribute(name) {
                        if (name === 'data-offline-actor-id') {
                            return '7';
                        }
                        return name === 'data-offline-actor-username' ? 'bhw.ana' : '';
                    },
                },
            });
            assert.equal(result, true);
            const stored = (await listOperations()).at(-1);
            assert.equal(stored.payload.sex, sex);
            assert.equal(stored.payload.philhealth, '123456789101');
            assert.match(stored.payload.client_local_member_id, /^MB-L-[A-F0-9]+$/);
        }
        assert.equal((await listOperations()).length, 2);
    });

    it('rejects Educational Attainment N/A abbreviation', () => {
        const errors = residentMemberPayloadErrors(
            {
                last_name: 'Morales',
                first_name: 'Vicky',
                relation: 'Daughter',
                birthday: '2016-11-17',
                sex: 'Female',
                relationship_status: 'Single',
                occupation: 'Student',
                monthly_income: 'None / N/A',
                religion: 'Roman Catholic',
                education: 'N/A',
                fp_user: 'N/A',
                disability: ['none'],
                medical_history: ['none'],
            },
            OPERATION_TYPES.RESIDENT_CREATE,
        );
        assert.equal(errors.some((item) => item.key === 'education'), true);
        assert.equal(errors.some((item) => item.key === 'fp_user'), false);
        assert.equal(formsSource.includes("'Post-Graduate', 'N/A'"), false);
    });

    it('accepts Educational Attainment Not Applicable', () => {
        const errors = residentMemberPayloadErrors(
            {
                last_name: 'Morales',
                first_name: 'Vicky',
                relation: 'Daughter',
                birthday: '2024-11-17',
                sex: 'Female',
                relationship_status: 'Single',
                occupation: 'None / N/A',
                monthly_income: 'None / N/A',
                religion: 'Roman Catholic',
                education: 'Not Applicable',
                fp_user: 'N/A',
                disability: ['none'],
                medical_history: ['none'],
            },
            OPERATION_TYPES.RESIDENT_CREATE,
        );
        assert.equal(errors.some((item) => item.key === 'education'), false);
        assert.match(formsSource, /'College Graduate', 'Post-Graduate', 'Not Applicable'\],/);
    });
});

function actorRoot() {
    return {
        getAttribute(name) {
            if (name === 'data-offline-actor-id') {
                return '7';
            }
            return name === 'data-offline-actor-username' ? 'bhw.ana' : '';
        },
    };
}

function submitEvent(form) {
    return {
        currentTarget: form,
        target: form,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
}

function residentUpdateForm(memberNo, fields, extraAttrs = {}) {
    return {
        ...fakeForm(fields, {
            'data-offline-operation': 'RESIDENT_UPDATE',
            'data-offline-parent-household-id': '999',
            'data-offline-parent-household-no': '999',
            'data-offline-parent-member-no': memberNo,
            action: `/household-profiling/999/members/${memberNo}`,
            ...extraAttrs,
        }),
        querySelector() {
            return null;
        },
        closest() {
            return null;
        },
        setAttribute() {},
    };
}

function jsonResponse(body, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

describe('local unsynced member form interception', () => {
    it('lets an online server-member edit stay native', async () => {
        const form = residentUpdateForm('MB-123', residentCreateFields({ sex: 'Female' }), {
            'data-offline-parent-resident-id': '44',
            'data-offline-field-hash': 'a'.repeat(64),
        });
        assert.equal(shouldQueueSupportedForm(form, { navigator: { onLine: true } }), false);
        const event = submitEvent(form);
        const result = await handleSupportedFormSubmit(event, {
            navigator: { onLine: true },
            root: actorRoot(),
        });
        assert.equal(result, false);
        assert.equal(event.defaultPrevented, false);
        assert.equal((await listOperations()).length, 0);
    });

    it('intercepts an offline MB-L-* edit', async () => {
        const form = residentUpdateForm('MB-L-331929318FDA', residentCreateFields({ sex: 'Female' }));
        const event = submitEvent(form);
        const result = await handleSupportedFormSubmit(event, {
            navigator: { onLine: false },
            root: actorRoot(),
        });
        assert.equal(result, true);
        assert.equal(event.defaultPrevented, true);
        const stored = await listOperations();
        assert.equal(stored.length, 1);
        assert.equal(stored[0].operation_type, 'RESIDENT_UPDATE');
        assert.equal(stored[0].payload.sex, 'Female');
        assert.equal(stored[0].payload.client_local_member_id, 'MB-L-331929318FDA');
    });

    it('intercepts an online MB-L-* edit and never issues a PUT', async () => {
        const form = residentUpdateForm('MB-L-331929318FDA', residentCreateFields({ sex: 'Female' }));
        assert.equal(localUnsyncedMemberIdFromForm(form), 'MB-L-331929318FDA');
        assert.equal(shouldQueueSupportedForm(form, { navigator: { onLine: true } }), true);
        const event = submitEvent(form);
        let fetchCalls = 0;
        const result = await handleSupportedFormSubmit(event, {
            navigator: { onLine: true },
            fetch: async () => {
                fetchCalls += 1;
                throw new Error('native PUT must not run');
            },
            root: actorRoot(),
        });
        assert.equal(result, true);
        assert.equal(event.defaultPrevented, true);
        assert.equal(fetchCalls, 0);
        assert.equal(formsSource.includes("method: 'PUT'"), false);
        assert.match(formsSource, /shouldQueueSupportedForm/);
    });

    it('repairs an online attention CREATE instead of queueing UPDATE or PUT', async () => {
        const localId = 'MB-L-331929318FDA';
        const created = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: localId,
                first_name: 'Vicky',
                last_name: 'Morales',
                relation: 'Daughter',
                sex: null,
                birthday: '2016-11-17',
                relationship_status: 'Single',
                occupation: 'Student',
                monthly_income: 'None / N/A',
                religion: 'Roman Catholic',
                education: 'Elementary Level',
                fp_user: 'N/A',
                philhealth: '123456789101',
                disability: ['none'],
                medical_history: ['none'],
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        await markAttention(created.local_id, 'VALIDATION_FAILED');
        const originalId = created.operation_id;

        for (const sex of ['Female', 'Male']) {
            resetFakeIndexedDB();
            setIndexedDBFactory(createFakeIndexedDB());
            const seeded = await enqueueOperation({
                actor_id: 7,
                actor_username: 'bhw.ana',
                operation_type: 'RESIDENT_CREATE',
                operation_id: originalId,
                local_id: created.local_id,
                payload: {
                    ...created.payload,
                    sex: null,
                    philhealth: '123456789101',
                },
                parent_server: { household_id: 999, household_no: '999' },
            });
            await markAttention(seeded.local_id, 'VALIDATION_FAILED');
            const form = residentUpdateForm(localId, residentCreateFields({ sex, first_name: 'Vicky' }));
            const event = submitEvent(form);
            await handleSupportedFormSubmit(event, {
                navigator: { onLine: true },
                root: actorRoot(),
            });
            assert.equal(event.defaultPrevented, true);
            const queued = await listOperations();
            assert.equal(queued.length, 1);
            assert.equal(queued[0].operation_type, 'RESIDENT_CREATE');
            assert.equal(queued[0].operation_id, seeded.operation_id);
            assert.equal(queued[0].local_id, seeded.local_id);
            assert.equal(queued[0].status, 'pending');
            assert.equal(queued[0].payload.sex, sex);
            assert.equal(queued[0].payload.philhealth, '123456789101');
            assert.equal(queued[0].payload.first_name, 'Vicky');
            assert.equal(queued[0].payload.last_name, 'Morales');
        }
    });

    it('replays a repaired online local edit through POST /offline/sync', async () => {
        const localId = 'MB-L-331929318FDA';
        const created = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: localId,
                first_name: 'Vicky',
                last_name: 'Morales',
                relation: 'Daughter',
                sex: null,
                birthday: '2016-11-17',
                philhealth: '123456789101',
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        await markAttention(created.local_id, 'VALIDATION_FAILED');
        const form = residentUpdateForm(localId, residentCreateFields({ sex: 'Female', first_name: 'Vicky' }));
        const event = submitEvent(form);
        await handleSupportedFormSubmit(event, {
            navigator: { onLine: true },
            root: actorRoot(),
        });
        const repaired = await getOperation(created.local_id);
        assert.equal(repaired.status, 'pending');
        assert.equal(repaired.payload.sex, 'Female');

        assert.equal(await countPendingVisible(7), 1);

        const posts = [];
        const events = [];
        const coordinator = createReplayCoordinator({
            onEvent(name, detail) {
                events.push({ type: name, detail });
            },
            fetch: async (url, init) => {
                if (url === '/offline/status') {
                    return jsonResponse({
                        ok: true,
                        user_id: 7,
                        username: 'bhw.ana',
                        csrf_token: 'token',
                        is_active: true,
                    });
                }
                assert.equal(url, '/offline/sync');
                assert.equal(init.method, 'POST');
                const envelope = JSON.parse(init.body);
                posts.push(envelope);
                assert.equal(envelope.operation_id, created.operation_id);
                assert.equal(envelope.operation_type, 'RESIDENT_CREATE');
                assert.equal(envelope.payload.sex, 'Female');
                assert.equal(envelope.payload.philhealth, '123456789101');
                return jsonResponse({
                    ok: true,
                    code: 'SYNCED',
                    operation_id: envelope.operation_id,
                    resident: { id: 88, member_no: 'MB-088', field_hash: 'a'.repeat(64) },
                    household: { id: 999, household_no: '999' },
                });
            },
            root: actorRoot(),
            window: {
                navigator: { onLine: true },
                setTimeout() { return 1; },
                clearTimeout() {},
                addEventListener() {},
                dispatchEvent(event) {
                    events.push(event);
                    return true;
                },
            },
        });
        await coordinator.replay();
        assert.equal(posts.length, 1);
        assert.equal((await listOperations()).length, 0);
        assert.equal(await countPendingVisible(7), 0);
        const success = events.find((item) => item.type === 'lmlinga:sync-success');
        assert.equal(success.detail.pending, 0);
        assert.equal(success.detail.synced[0].identities.member_no, 'MB-088');
        assert.equal(events.some((item) => item.type === 'lmlinga:sync-attention'), false);
    });

    it('does not mutate an idempotency-mismatch attention create from an online local edit', async () => {
        const created = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-unsafe1',
                first_name: 'Ana',
                last_name: 'Santos',
                sex: 'Female',
                philhealth: '123456789101',
            },
            parent_server: { household_id: 8, household_no: 'HH-121' },
        });
        await markAttention(created.local_id, 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        const form = residentUpdateForm('MB-L-unsafe1', residentCreateFields({ sex: 'Female', first_name: 'Anita' }), {
            'data-offline-parent-household-no': 'HH-121',
            'data-offline-parent-household-id': '8',
            action: '/household-profiling/HH-121/members/MB-L-unsafe1',
        });
        const event = submitEvent(form);
        await handleSupportedFormSubmit(event, {
            navigator: { onLine: true },
            root: actorRoot(),
        });
        const rows = await listOperations();
        assert.equal(rows.length, 2);
        const original = await getOperation(created.local_id);
        assert.equal(original.status, 'attention');
        assert.equal(original.payload.first_name, 'Ana');
        assert.equal(rows.some((row) => row.operation_type === 'RESIDENT_UPDATE'), true);
    });

    it('keeps existing CREATE fields that the edit form does not resend', async () => {
        const created = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-331929318FDA',
                first_name: 'Vicky',
                last_name: 'Morales',
                relation: 'Daughter',
                sex: null,
                birthday: '2016-11-17',
                occupation: 'Student',
                monthly_income: 'None / N/A',
                religion: 'Roman Catholic',
                education: 'Elementary Level',
                philhealth: '123456789101',
                fp_user: 'N/A',
                disability: ['none'],
                medical_history: ['none'],
                legacy_marker: 'keep-me',
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        await markAttention(created.local_id, 'VALIDATION_FAILED');
        const form = residentUpdateForm('MB-L-331929318FDA', residentCreateFields({ sex: 'Female', first_name: 'Vicky' }));
        await handleSupportedFormSubmit(submitEvent(form), {
            navigator: { onLine: true },
            root: actorRoot(),
        });
        const repaired = await getOperation(created.local_id);
        assert.equal(repaired.payload.sex, 'Female');
        assert.equal(repaired.payload.occupation, 'Student');
        assert.equal(repaired.payload.monthly_income, 'None / N/A');
        assert.equal(repaired.payload.religion, 'Roman Catholic');
        assert.equal(repaired.payload.education, 'Elementary Level');
        assert.equal(repaired.payload.philhealth, '123456789101');
        assert.equal(repaired.payload.fp_user, 'N/A');
        assert.deepEqual(repaired.payload.disability, ['none']);
        assert.deepEqual(repaired.payload.medical_history, ['none']);
        assert.equal(repaired.payload.client_local_member_id, 'MB-L-331929318FDA');
        assert.equal(repaired.payload.legacy_marker, 'keep-me');
        assert.equal(repaired.operation_id, created.operation_id);
        assert.equal((await listOperations()).length, 1);
    });

    it('intercepts an online health write that still targets MB-L-*', () => {
        const form = fakeForm([], {
            'data-offline-operation': 'HEALTH_SERVICE_WRITE',
            'data-offline-parent-member-no': 'MB-L-331929318FDA',
            action: '/household-profiling/999/members/MB-L-331929318FDA/child-immunization',
        });
        assert.equal(shouldQueueSupportedForm(form, { navigator: { onLine: true } }), true);
        assert.equal(shouldQueueSupportedForm(form, { navigator: { onLine: false } }), true);
    });

    it('uses delegated bubble submit interception after capture validation', () => {
        const memberSource = readFileSync(path.resolve('resources/js/pages/household-member-form.js'), 'utf8');
        assert.match(memberSource, /form\.addEventListener\('submit', \(event\) => \{[\s\S]*?\}, true\);/);
        assert.match(memberSource, /\^MB-L-\[A-Za-z0-9\]\+\$\/i\.test\(memberId\)/);
        assert.match(memberSource, /handleSupportedFormSubmit/);
        assert.equal(formsSource.includes("form.addEventListener('submit', handler, true)"), false);
        assert.match(formsSource, /scope\.addEventListener\('submit', handler, true\);/);
        assert.match(
            formsSource,
            /if \(!shouldQueueSupportedForm\(form, options\)\) \{\s*return false;\s*\}\s*event\.preventDefault/,
        );
        assert.equal(formsSource.includes('if (!form || event?.defaultPrevented)'), false);
        assert.equal(formsSource.includes('const offline = isClientOffline(options)'), false);
    });

    it('still queues an online MB-L-* edit after capture validation preventDefault', async () => {
        const form = residentUpdateForm('MB-L-331929318FDA', residentCreateFields({ sex: 'Female' }));
        const event = submitEvent(form);
        event.preventDefault();
        const result = await handleSupportedFormSubmit(event, {
            navigator: { onLine: true },
            root: actorRoot(),
        });
        assert.equal(result, true);
        assert.equal(event.defaultPrevented, true);
        const stored = await listOperations();
        assert.equal(stored.length, 1);
        assert.equal(stored[0].payload.sex, 'Female');
        assert.equal(stored[0].payload.client_local_member_id, 'MB-L-331929318FDA');
    });
});

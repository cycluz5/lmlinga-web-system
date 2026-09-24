/**
 * Offline-first Household Profiling write-back: consecutive local creates/edits.
 */

import assert from 'node:assert/strict';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const storeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href;
const hydrateUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    replaceHouseholdProfilingSnapshots,
    appendLocalMemberToHousehold,
    applyMemberPayloadToSnapshot,
    listMemberSnapshots,
    getHouseholdSnapshot,
    getMemberSnapshot,
    promoteLocalMemberIdentity,
} = await import(storeUrl);
const {
    cacheHydratedHouseholdPages,
    ensureHouseholdNavFromSnapshot,
    hydrateHouseholdViewHtml,
} = await import(hydrateUrl);
const {
    enqueueOperation,
    listOperations,
    applyServerIdentityToDependents,
    markAttention,
} = await import(queueUrl);
const { handleSupportedFormSubmit } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href
);
const { handleResidentUpdateQueued } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-local-member.js')).href
);
const { reconcileResidentCreateSuccess } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-reconcile.js')).href
);

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

const payload = {
    generated_at: '2026-09-08T00:00:00Z',
    catalogs: { occupations: ['Farmer'] },
    households: [
        {
            household_id: 11,
            household_no: 'HH-200',
            display_no: 'HH 200',
            house_head: 'Ana Rivera',
            zone: 'Zone 1',
            member_count: 1,
            water: { level: 'Level II', status: 'Basic' },
            sanitation: { facility: 'Pour flush', status: 'Improved' },
        },
        {
            household_id: 12,
            household_no: 'HH-201',
            display_no: 'HH 201',
            house_head: 'Ben Cruz',
            zone: 'Zone 2',
            member_count: 1,
            water: { level: '—', status: 'Not recorded' },
            sanitation: { facility: '—', status: 'Not recorded' },
        },
    ],
    members: [
        {
            household_id: 11,
            household_no: 'HH-200',
            resident_id: 21,
            member_no: 'MB-021',
            name: 'Ana Rivera',
            relationship: 'Head',
            relation: 'Head',
            first_name: 'Ana',
            last_name: 'Rivera',
            sex: 'Female',
            birthday: '1990-01-01',
        },
        {
            household_id: 12,
            household_no: 'HH-201',
            resident_id: 22,
            member_no: 'MB-022',
            name: 'Ben Cruz',
            relationship: 'Head',
            relation: 'Head',
            first_name: 'Ben',
            last_name: 'Cruz',
            sex: 'Male',
            birthday: '1988-02-02',
        },
    ],
};

describe('household profiling offline write-back', () => {
    it('keeps consecutive local creates and edits visible before sync', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        const caches = createMemoryCaches();
        const origin = 'https://lmlinga.test';

        assert.equal(await ensureHouseholdNavFromSnapshot('/household-profiling/HH-201', {
            actorId: 7,
            caches,
            origin,
        }), true);
        assert.equal(await ensureHouseholdNavFromSnapshot('/household-profiling/HH-201/members/create', {
            actorId: 7,
            caches,
            origin,
        }), true);

        await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-offA',
                first_name: 'Offline',
                last_name: 'Alpha',
                relation: 'Daughter',
                sex: 'Female',
                birthday: '2018-05-01',
            },
            parent_server: { household_id: 12, household_no: 'HH-201' },
        });
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-offA',
            first_name: 'Offline',
            last_name: 'Alpha',
            relation: 'Daughter',
            sex: 'Female',
            birthday: '2018-05-01',
        });

        await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-offA',
                first_name: 'OfflineA',
                last_name: 'Alpha',
                relation: 'Daughter',
                sex: 'Female',
                birthday: '2018-05-01',
            },
            parent_server: { household_id: 12, household_no: 'HH-201', member_no: 'MB-L-offA' },
        });
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-offA',
            first_name: 'OfflineA',
            last_name: 'Alpha',
            relation: 'Daughter',
            sex: 'Female',
            birthday: '2018-05-01',
        });

        await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-offB',
                first_name: 'Offline',
                last_name: 'Bravo',
                relation: 'Son',
                sex: 'Male',
                birthday: '2016-03-01',
            },
            parent_server: { household_id: 12, household_no: 'HH-201' },
        });
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-offB',
            first_name: 'Offline',
            last_name: 'Bravo',
            relation: 'Son',
            sex: 'Male',
            birthday: '2016-03-01',
        });

        await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-offC',
                first_name: 'Offline',
                last_name: 'Charlie',
                relation: 'Son',
                sex: 'Male',
                birthday: '2014-04-01',
            },
            parent_server: { household_id: 12, household_no: 'HH-201' },
        });
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-offC',
            first_name: 'Offline',
            last_name: 'Charlie',
            relation: 'Son',
            sex: 'Male',
            birthday: '2014-04-01',
        });

        await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-hh200',
                first_name: 'Other',
                last_name: 'House',
                relation: 'Spouse',
                sex: 'Female',
                birthday: '1992-01-01',
            },
            parent_server: { household_id: 11, household_no: 'HH-200' },
        });
        await appendLocalMemberToHousehold(7, 'HH-200', {
            client_local_member_id: 'MB-L-hh200',
            first_name: 'Other',
            last_name: 'House',
            relation: 'Spouse',
            sex: 'Female',
            birthday: '1992-01-01',
        });

        await applyMemberPayloadToSnapshot(7, 'HH-201', 'MB-022', {
            first_name: 'Benjamin',
            last_name: 'Cruz',
            relation: 'Head',
            sex: 'Male',
        });

        const household = await getHouseholdSnapshot(7, 'HH-201');
        const members = await listMemberSnapshots(7, 'HH-201');
        await cacheHydratedHouseholdPages(7, household, members, { caches, origin, actorId: 7 });

        const html = hydrateHouseholdViewHtml(
            '<section class="lml-hh-view__members">x</section>',
            household,
            members,
            'HH-201',
        );
        assert.match(html, /OfflineA Alpha/);
        assert.match(html, /Offline Bravo/);
        assert.match(html, /Offline Charlie/);
        assert.match(html, /Waiting to sync/);
        assert.match(html, /Benjamin Cruz/);
        assert.match(html, /Household Members \(4\)/);
        assert.equal(household.member_count, 4);

        const cache = await caches.open(htmlCacheNameForActor(7));
        const viewA = await cache.match(new Request(navigationCacheUrl(origin, '/household-profiling/HH-201/members/MB-L-offA')));
        const editA = await cache.match(new Request(navigationCacheUrl(origin, '/household-profiling/HH-201/members/MB-L-offA/edit')));
        const healthA = await cache.match(new Request(navigationCacheUrl(origin, '/household-profiling/HH-201/members/MB-L-offA/child-immunization')));
        const amenities = await cache.match(new Request(navigationCacheUrl(origin, '/household-profiling/HH-201/amenities')));
        const serverEdit = await cache.match(new Request(navigationCacheUrl(origin, '/household-profiling/HH-201/members/MB-022/edit')));
        assert.equal(Boolean(viewA), true);
        assert.equal(Boolean(editA), true);
        assert.equal(Boolean(healthA), true);
        assert.equal(Boolean(amenities), true);
        assert.equal(Boolean(serverEdit), true);

        const queued = await listOperations();
        assert.equal(queued.filter((row) => row.operation_type === 'RESIDENT_CREATE').length, 4);
        assert.equal(queued.some((row) => row.operation_type === 'RESIDENT_UPDATE' && row.payload?.client_local_member_id === 'MB-L-offA'), false);
        assert.equal(queued.find((row) => row.payload?.client_local_member_id === 'MB-L-offA').payload.first_name, 'OfflineA');

        await applyServerIdentityToDependents(7, 'MB-L-offA', {
            resident_pk: 90,
            member_no: 'MB-090',
            field_hash: 'b'.repeat(64),
        });
        await promoteLocalMemberIdentity(7, 'HH-201', 'MB-L-offA', 'MB-090', 90);
        const afterSync = await listMemberSnapshots(7, 'HH-201');
        assert.equal(afterSync.some((row) => row.member_no === 'MB-090'), true);
        assert.equal(afterSync.some((row) => row.member_no === 'MB-L-offA'), false);
        assert.equal(afterSync.filter((row) => row.name === 'OfflineA Alpha').length, 1);

        const otherHouse = await listMemberSnapshots(7, 'HH-200');
        assert.equal(otherHouse.some((row) => row.member_no === 'MB-L-hh200'), true);
    });

    it('finds local member snapshots when the URL id case differs', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-abc12',
            first_name: 'Case',
            last_name: 'Check',
            relation: 'Son',
        });
        const found = await getMemberSnapshot(7, 'HH-201', 'MB-L-ABC12');
        assert.equal(found?.member_no, 'MB-L-abc12');
        assert.equal(found?.first_name, 'Case');
    });

    it('keeps local members and recounts after a later server bootstrap', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-keep1',
            first_name: 'Keep',
            last_name: 'One',
            relation: 'Son',
        });
        await replaceHouseholdProfilingSnapshots(7, payload);
        const members = await listMemberSnapshots(7, 'HH-201');
        const household = await getHouseholdSnapshot(7, 'HH-201');
        assert.equal(members.some((row) => row.member_no === 'MB-L-keep1'), true);
        assert.equal(members.some((row) => row.member_no === 'MB-022'), true);
        assert.equal(household.member_count, 2);
        assert.equal(members.filter((row) => row.name === 'Keep One' || row.first_name === 'Keep').length, 1);
    });

    it('promotes successful creates and keeps a failed local member visible', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-okA',
            first_name: 'Ok',
            last_name: 'Alpha',
            relation: 'Son',
        });
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-failC',
            first_name: 'Fail',
            last_name: 'Charlie',
            relation: 'Daughter',
        });
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-okD',
            first_name: 'Ok',
            last_name: 'Delta',
            relation: 'Son',
        });

        const caches = createMemoryCaches();
        await reconcileResidentCreateSuccess({
            operation_type: 'RESIDENT_CREATE',
            payload: { client_local_member_id: 'MB-L-okA' },
            parent_server: { household_no: 'HH-201' },
            identities: { member_no: 'MB-091', resident_pk: 91 },
        }, { actorId: 7, caches, origin: 'https://lmlinga.test', window: { location: { origin: 'https://lmlinga.test', pathname: '/' } } });
        await reconcileResidentCreateSuccess({
            operation_type: 'RESIDENT_CREATE',
            payload: { client_local_member_id: 'MB-L-okD' },
            parent_server: { household_no: 'HH-201' },
            identities: { member_no: 'MB-092', resident_pk: 92 },
        }, { actorId: 7, caches, origin: 'https://lmlinga.test', window: { location: { origin: 'https://lmlinga.test', pathname: '/' } } });

        const members = await listMemberSnapshots(7, 'HH-201');
        const names = members.map((row) => row.member_no).sort();
        assert.equal(members.some((row) => row.member_no === 'MB-091'), true);
        assert.equal(members.some((row) => row.member_no === 'MB-092'), true);
        assert.equal(members.some((row) => row.member_no === 'MB-L-failC'), true);
        assert.equal(members.some((row) => row.member_no === 'MB-L-okA'), false);
        assert.equal(members.some((row) => row.member_no === 'MB-L-okD'), false);
        assert.equal(members.filter((row) => row.member_no === 'MB-091').length, 1);
        assert.equal(new Set(names).size, names.length);

        const household = await getHouseholdSnapshot(7, 'HH-201');
        assert.equal(household.member_count, members.length);
        assert.equal(household.member_count, 4);
    });

    it('keeps a legacy attention member visible then promotes after sex is repaired', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-vicky1',
            first_name: 'Vicky',
            last_name: 'Morales',
            relation: 'Daughter',
            sex: null,
            philhealth: '123456789012',
        });
        const created = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-vicky1',
                first_name: 'Vicky',
                last_name: 'Morales',
                relation: 'Daughter',
                sex: null,
                philhealth: '123456789012',
            },
            parent_server: { household_id: 12, household_no: 'HH-201' },
        });
        await markAttention(created.local_id, 'VALIDATION_FAILED');
        assert.equal((await getMemberSnapshot(7, 'HH-201', 'MB-L-vicky1'))?.first_name, 'Vicky');

        await applyMemberPayloadToSnapshot(7, 'HH-201', 'MB-L-vicky1', {
            first_name: 'Vicky',
            last_name: 'Morales',
            sex: 'Male',
            philhealth: '123456789012',
        });
        const repaired = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-vicky1',
                first_name: 'Vicky',
                last_name: 'Morales',
                sex: 'Male',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 12, household_no: 'HH-201', member_no: 'MB-L-vicky1' },
        });
        assert.equal(repaired.local_id, created.local_id);
        assert.equal(repaired.status, 'pending');
        assert.equal((await listOperations()).length, 1);
        assert.equal((await getMemberSnapshot(7, 'HH-201', 'MB-L-vicky1'))?.sex, 'Male');

        const caches = createMemoryCaches();
        await reconcileResidentCreateSuccess({
            operation_type: 'RESIDENT_CREATE',
            payload: { client_local_member_id: 'MB-L-vicky1' },
            parent_server: { household_no: 'HH-201' },
            identities: { member_no: 'MB-088', resident_pk: 88 },
        }, { actorId: 7, caches, origin: 'https://lmlinga.test', window: { location: { origin: 'https://lmlinga.test', pathname: '/' } } });

        const members = await listMemberSnapshots(7, 'HH-201');
        assert.equal(members.some((row) => row.member_no === 'MB-088'), true);
        assert.equal(members.some((row) => row.member_no === 'MB-L-vicky1'), false);
        assert.equal(members.filter((row) => row.first_name === 'Vicky' || row.name === 'Vicky Morales').length, 1);
    });

    it('online Vicky MB-L-* sex repair updates the snapshot without a PUT', async () => {
        await replaceHouseholdProfilingSnapshots(7, {
            ...payload,
            households: [
                ...payload.households,
                {
                    household_id: 999,
                    household_no: '999',
                    display_no: 'HH 999',
                    house_head: 'Head',
                    zone: 'Zone 1',
                    member_count: 0,
                    water: { level: '—', status: 'Not recorded' },
                    sanitation: { facility: '—', status: 'Not recorded' },
                },
            ],
        });
        await appendLocalMemberToHousehold(7, '999', {
            client_local_member_id: 'MB-L-331929318FDA',
            first_name: 'Vicky',
            last_name: 'Morales',
            relation: 'Daughter',
            sex: null,
            philhealth: '123456789101',
        });
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

        const field = (overrides) => ({
            name: '',
            type: 'text',
            value: '',
            disabled: false,
            readOnly: false,
            checked: false,
            ...overrides,
        });
        const form = {
            elements: [
                field({ name: 'last_name', value: 'Morales' }),
                field({ name: 'first_name', value: 'Vicky' }),
                field({ name: 'relation', type: 'select-one', value: 'Daughter' }),
                field({ name: 'birthday', value: '2016-11-17' }),
                field({ name: 'sex', type: 'select-one', value: 'Female' }),
                field({ name: 'relationship_status', type: 'select-one', value: 'Single' }),
                field({ name: 'occupation', type: 'select-one', value: 'Student' }),
                field({ name: 'monthly_income', type: 'select-one', value: 'None / N/A' }),
                field({ name: 'religion', type: 'select-one', value: 'Roman Catholic' }),
                field({ name: 'education', type: 'select-one', value: 'Elementary Level' }),
                field({ name: 'fp_user', type: 'select-one', value: 'N/A' }),
                field({ name: 'philhealth', value: '123456789101' }),
                field({ name: 'disability[]', type: 'checkbox', value: 'none', checked: true }),
                field({ name: 'medical_history[]', type: 'checkbox', value: 'none', checked: true }),
            ],
            getAttribute(name) {
                const attrs = {
                    'data-offline-operation': 'RESIDENT_UPDATE',
                    'data-offline-parent-household-id': '999',
                    'data-offline-parent-household-no': '999',
                    'data-offline-parent-member-no': 'MB-L-331929318FDA',
                    'data-offline-local-member': '1',
                    action: '/household-profiling/999/members/MB-L-331929318FDA',
                };
                return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
            },
            querySelector() { return null; },
            closest() { return null; },
            setAttribute() {},
        };
        const event = {
            currentTarget: form,
            target: form,
            defaultPrevented: true,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        const result = await handleSupportedFormSubmit(event, {
            navigator: { onLine: true },
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
        const queued = await listOperations();
        assert.equal(queued.length, 1);
        assert.equal(queued[0].operation_type, 'RESIDENT_CREATE');
        assert.equal(queued[0].operation_id, created.operation_id);
        assert.equal(queued[0].status, 'pending');
        assert.equal(queued[0].payload.sex, 'Female');
        assert.equal(queued[0].payload.philhealth, '123456789101');

        await handleResidentUpdateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: queued[0].payload,
            parent_server: queued[0].parent_server,
        }, { actorId: 7, navigate: false });

        const snapshot = await getMemberSnapshot(7, '999', 'MB-L-331929318FDA');
        assert.equal(snapshot?.sex, 'Female');
        assert.equal(snapshot?.philhealth, '123456789101');
        assert.equal(snapshot?.first_name, 'Vicky');
    });
});

/**
 * Local MB-L member View hydration must use the queued snapshot, not the
 * first preloaded server-member HTML shell (Charlie Pot contamination).
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
const localUrl = pathToFileURL(path.resolve('resources/js/offline/offline-local-member.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const replayUrl = pathToFileURL(path.resolve('resources/js/offline/offline-replay.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    replaceHouseholdProfilingSnapshots,
    appendLocalMemberToHousehold,
    getMemberSnapshot,
    listMemberSnapshots,
    putMeta,
} = await import(storeUrl);
const { hydrateMemberViewHtml, hydrateMemberEditHtml, cacheHydratedHouseholdPages } = await import(hydrateUrl);
const { handleResidentCreateQueued, handleResidentUpdateQueued, fillLocalMemberViewRoot } = await import(localUrl);
const { enqueueOperation, listOperations } = await import(queueUrl);
const { createReplayCoordinator, resetReplayLock } = await import(replayUrl);

const ORIGIN = 'https://lmlinga.test';
const ACTOR = 7;
const LOCAL_ID = 'MB-L-0e308522f5f8';

const CHARLIE_SHELL = `<!DOCTYPE html><html><body>
<article data-lml-hh-member-view data-household-no="999" data-member-id="MB-001" data-member-name="Charlie Pot" aria-label="Delete Charlie Pot">
<h2 class="lml-hh-member-view__name">Charlie Pot</h2>
<p class="lml-hh-member-view__subtitle">Member Information</p>
<dl>
<div class="lml-hh-member-view__item"><dt>Full Name</dt><dd>Charlie Pot</dd></div>
<div class="lml-hh-member-view__item"><dt>Relation to Household Head</dt><dd>Household Head</dd></div>
<div class="lml-hh-member-view__item"><dt>Relationship Status</dt><dd>Married</dd></div>
<div class="lml-hh-member-view__item"><dt>Birthday</dt><dd>02/07/1984</dd></div>
<div class="lml-hh-member-view__item"><dt>Sex</dt><dd>Male</dd></div>
<div class="lml-hh-member-view__item"><dt>Occupation</dt><dd>Government Employee</dd></div>
<div class="lml-hh-member-view__item"><dt>Monthly Income</dt><dd>20,000–29,999</dd></div>
<div class="lml-hh-member-view__item"><dt>Religion</dt><dd>Catholic</dd></div>
<div class="lml-hh-member-view__item"><dt>Educational Attainment</dt><dd>Bachelor's Degree</dd></div>
<div class="lml-hh-member-view__item"><dt>PhilHealth Number</dt><dd>—</dd></div>
<div class="lml-hh-member-view__item"><dt>Family Planning</dt><dd>Yes</dd></div>
<div class="lml-hh-member-view__item"><dt>Disability Type</dt><dd>None</dd></div>
<div class="lml-hh-member-view__item"><dt>Medical History</dt><dd>None</dd></div>
</dl>
</article></body></html>`;

const CHARLIE_EDIT_SHELL = `<!DOCTYPE html><html><head><title>Edit Member - LMLinga</title></head><body>
<div class="lml-hh-member-form" data-lml-hh-member-form data-mode="edit" data-household-no="999" data-member-id="MB-001" data-member-name="Charlie Pot" data-view-url="/household-profiling/999/members/MB-001">
<p class="lml-hh-member-form__context">Editing <strong data-offline-local-field="name">Charlie Pot</strong> in household <strong>HH 999</strong>.</p>
<form method="post" action="/household-profiling/999/members/MB-001" data-hh-member-form-el data-offline-operation="RESIDENT_UPDATE" data-offline-parent-household-id="1" data-offline-parent-household-no="999" data-offline-parent-resident-id="77" data-offline-parent-member-no="MB-001" data-offline-field-hash="abc">
<input name="last_name" value="Pot">
<input name="first_name" value="Charlie">
<input name="birthday" value="1984-02-07">
<select name="relation"><option value="Head" selected>Head</option><option value="Daughter">Daughter</option></select>
<select name="sex"><option value="">Select</option><option value="Male" selected>Male</option><option value="Female">Female</option></select>
<select name="education"><option value="College Graduate" selected>College Graduate</option><option value="Elementary Level">Elementary Level</option></select>
<input name="philhealth" value="">
<a href="/household-profiling/999/members/MB-001" aria-label="Back to Charlie Pot">Back</a>
</form>
</div></body></html>`;

const vickyPayload = {
    client_local_member_id: 'MB-L-331929318FDA',
    last_name: 'Morales',
    first_name: 'Vicky',
    relation: 'Daughter',
    birthday: '2016-11-17',
    sex: 'Female',
    relationship_status: 'Single',
    occupation: 'Student',
    monthly_income: 'None / N/A',
    religion: 'Roman Catholic',
    education: 'Elementary Level',
    philhealth: '123456789101',
    fp_user: 'N/A',
    disability: ['none'],
    medical_history: ['none'],
};

const milesPayload = {
    client_local_member_id: LOCAL_ID,
    last_name: 'Morales',
    first_name: 'Miles',
    relation: 'Son',
    birthday: '2010-07-15',
    sex: 'Male',
    relationship_status: 'Single',
    occupation: 'Student',
    monthly_income: 'None / N/A',
    religion: 'Roman Catholic',
    education: 'High School Level',
    philhealth: '121234567890',
    fp_user: 'N/A',
    disability: ['none'],
    medical_history: ['none'],
};

const bootstrap = {
    generated_at: '2026-09-08T00:00:00Z',
    catalogs: {},
    households: [
        {
            household_id: 999,
            household_no: '999',
            display_no: 'HH 999',
            house_head: 'Charlie Pot',
            zone: 'Zone 1',
            member_count: 1,
            water: { level: '—', status: 'Not recorded' },
            sanitation: { facility: '—', status: 'Not recorded' },
        },
    ],
    members: [
        {
            household_id: 999,
            household_no: '999',
            resident_id: 1,
            member_no: 'MB-001',
            name: 'Charlie Pot',
            relationship: 'Head',
            relation: 'Head',
            first_name: 'Charlie',
            last_name: 'Pot',
            sex: 'Male',
            birthday: '1984-02-07',
            relationship_status: 'Married',
            occupation: 'Government Employee',
            monthly_income: '20,000–29,999',
            religion: 'Roman Catholic',
            education: 'College Graduate',
            philhealth: '',
            fp_user: 'Yes',
        },
    ],
};

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
    resetReplayLock();
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    resetReplayLock();
});

async function seedHouseholdAndShell() {
    await replaceHouseholdProfilingSnapshots(ACTOR, bootstrap);
    await putMeta(ACTOR, 'shell:member-view', CHARLIE_SHELL);
    await putMeta(ACTOR, 'shell:household_no', '999');
    await putMeta(ACTOR, 'shell:member_id', 'MB-001');
}

async function readCachedMember(caches, memberId) {
    const cache = await caches.open(htmlCacheNameForActor(ACTOR));
    const hit = await cache.match(new Request(
        navigationCacheUrl(ORIGIN, `/household-profiling/999/members/${memberId}`),
    ));
    assert.equal(Boolean(hit), true, `missing cached view for ${memberId}`);
    return hit.text();
}

describe('local member view hydration', () => {
    it('TEST 1 — local create read-your-write is Miles Morales, not Charlie Pot', async () => {
        await seedHouseholdAndShell();
        const caches = createMemoryCaches();
        const assigned = [];

        const result = await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        }, {
            actorId: ACTOR,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
        });

        assert.equal(result.ok, true);
        assert.equal(assigned[0], `/household-profiling/999/members/${LOCAL_ID}`);
        const html = await readCachedMember(caches, LOCAL_ID);
        assert.match(html, /Miles Morales/);
        assert.doesNotMatch(html, /Charlie Pot/);
        assert.match(html, /data-member-id="MB-L-0e308522f5f8"/);
    });

    it('TEST 2 — all major fields hydrate from the local snapshot', () => {
        const html = hydrateMemberViewHtml(CHARLIE_SHELL, bootstrap.households[0], {
            ...milesPayload,
            member_no: LOCAL_ID,
            name: 'Miles Morales',
            local: true,
        }, '999', 'MB-001');

        assert.match(html, />07\/15\/2010</);
        assert.match(html, />Son</);
        assert.match(html, />Male</);
        assert.match(html, />Student</);
        assert.match(html, />None \/ N\/A</);
        assert.match(html, />Catholic</);
        assert.match(html, />High School Level</);
        assert.match(html, />N\/A</);
        assert.match(html, />None</);
        assert.doesNotMatch(html, />Household Head</);
        assert.doesNotMatch(html, /Government Employee/);
        assert.doesNotMatch(html, /20,000/);
        assert.doesNotMatch(html, /Bachelor/);
        assert.doesNotMatch(html, />Married</);
        assert.doesNotMatch(html, />Yes</);
    });

    it('TEST 3 — PhilHealth create displays the exact queued value', async () => {
        await seedHouseholdAndShell();
        const caches = createMemoryCaches();
        await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });

        const snap = await getMemberSnapshot(ACTOR, '999', LOCAL_ID);
        assert.equal(snap.philhealth, '121234567890');
        const html = await readCachedMember(caches, LOCAL_ID);
        assert.match(html, />121234567890</);
        assert.doesNotMatch(html, /<dt>PhilHealth Number<\/dt><dd[^>]*>—<\/dd>/);
    });

    it('TEST 4 — PhilHealth update before sync merges into CREATE, snapshot, and View', async () => {
        await seedHouseholdAndShell();
        const caches = createMemoryCaches();

        await enqueueOperation({
            actor_id: ACTOR,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        });
        await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });

        const edited = await enqueueOperation({
            actor_id: ACTOR,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                ...milesPayload,
                philhealth: '091234567890',
            },
            parent_server: { household_id: 999, household_no: '999', member_no: LOCAL_ID },
        });
        assert.equal(edited.operation_type, 'RESIDENT_CREATE');
        assert.equal(edited.payload.philhealth, '091234567890');
        assert.equal(edited.payload.client_local_member_id, LOCAL_ID);
        assert.equal((await listOperations()).length, 1);

        await handleResidentUpdateQueued({
            operation_type: 'RESIDENT_UPDATE',
            payload: edited.payload,
            parent_server: { household_id: 999, household_no: '999', member_no: LOCAL_ID },
        }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });

        const snap = await getMemberSnapshot(ACTOR, '999', LOCAL_ID);
        assert.equal(snap.philhealth, '091234567890');
        assert.equal(snap.member_no, LOCAL_ID);
        const html = await readCachedMember(caches, LOCAL_ID);
        assert.match(html, />091234567890</);
        assert.doesNotMatch(html, /121234567890/);
        assert.match(html, /Miles Morales/);
    });

    it('TEST 5 — multiple local members in the same household do not contaminate each other', async () => {
        await seedHouseholdAndShell();
        const caches = createMemoryCaches();
        const people = [
            { id: 'MB-L-alpha1', first_name: 'Alpha', last_name: 'One', philhealth: '111111111111' },
            { id: 'MB-L-beta22', first_name: 'Beta', last_name: 'Two', philhealth: '222222222222' },
            { id: 'MB-L-gamma3', first_name: 'Gamma', last_name: 'Three', philhealth: '333333333333' },
        ];
        for (const person of people) {
            await handleResidentCreateQueued({
                operation_type: 'RESIDENT_CREATE',
                payload: {
                    ...milesPayload,
                    client_local_member_id: person.id,
                    first_name: person.first_name,
                    last_name: person.last_name,
                    philhealth: person.philhealth,
                },
                parent_server: { household_id: 999, household_no: '999' },
            }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });
        }

        const alpha = await readCachedMember(caches, 'MB-L-alpha1');
        const beta = await readCachedMember(caches, 'MB-L-beta22');
        const gamma = await readCachedMember(caches, 'MB-L-gamma3');
        assert.match(alpha, /Alpha One/);
        assert.match(alpha, />111111111111</);
        assert.doesNotMatch(alpha, /Beta Two|Gamma Three|Charlie Pot/);
        assert.match(beta, /Beta Two/);
        assert.match(beta, />222222222222</);
        assert.doesNotMatch(beta, /Alpha One|Gamma Three|Charlie Pot/);
        assert.match(gamma, /Gamma Three/);
        assert.match(gamma, />333333333333</);
        assert.doesNotMatch(gamma, /Alpha One|Beta Two|Charlie Pot/);
    });

    it('TEST 6 — refresh from IndexedDB/cache keeps the same local values', async () => {
        await seedHouseholdAndShell();
        const caches = createMemoryCaches();
        await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });

        const members = await listMemberSnapshots(ACTOR, '999');
        await cacheHydratedHouseholdPages(ACTOR, bootstrap.households[0], members, {
            caches,
            origin: ORIGIN,
            actorId: ACTOR,
        });

        const html = await readCachedMember(caches, LOCAL_ID);
        assert.match(html, /Miles Morales/);
        assert.match(html, />121234567890</);
        assert.doesNotMatch(html, /Charlie Pot/);
        const snap = await getMemberSnapshot(ACTOR, '999', LOCAL_ID);
        assert.equal(snap.first_name, 'Miles');
        assert.equal(snap.philhealth, '121234567890');
    });

    it('TEST 7 — no Charlie Pot template values survive in the local View', () => {
        const html = hydrateMemberViewHtml(CHARLIE_SHELL, bootstrap.households[0], {
            ...milesPayload,
            member_no: LOCAL_ID,
            name: 'Miles Morales',
            local: true,
        }, '999', 'MB-001');

        assert.doesNotMatch(html, /Charlie Pot/);
        assert.doesNotMatch(html, /MB-001/);
        assert.doesNotMatch(html, /02\/07\/1984/);
        assert.doesNotMatch(html, /Government Employee/);
        assert.match(html, /Miles Morales/);
        assert.match(html, /Waiting to sync/);
    });

    it('live merge replaces unlabeled dt/dd nodes from a Charlie Pot document', () => {
        const values = {};
        const items = [
            ['Full Name', 'Charlie Pot'],
            ['PhilHealth Number', '—'],
            ['Occupation', 'Government Employee'],
        ].map(([label, current]) => {
            const dd = {
                get textContent() { return values[label] ?? current; },
                set textContent(next) { values[label] = next; },
            };
            return {
                querySelector(sel) {
                    if (sel === 'dt') {
                        return { textContent: label };
                    }
                    if (sel === 'dd') {
                        return dd;
                    }
                    return null;
                },
            };
        });
        const root = {
            setAttribute() {},
            querySelector() { return { textContent: 'Charlie Pot' }; },
            querySelectorAll(selector) {
                if (selector === '[data-offline-local-field]') {
                    return [];
                }
                return items;
            },
        };
        fillLocalMemberViewRoot(root, milesPayload, '999', LOCAL_ID);
        assert.equal(values['Full Name'], 'Miles Morales');
        assert.equal(values['PhilHealth Number'], '121234567890');
        assert.equal(values.Occupation, 'Student');
    });

    it('maps philhealth_number onto the canonical philhealth snapshot key', async () => {
        await seedHouseholdAndShell();
        await appendLocalMemberToHousehold(ACTOR, '999', {
            client_local_member_id: 'MB-L-alias1',
            first_name: 'Alias',
            last_name: 'Member',
            philhealth_number: '121234567890',
        });
        const snap = await getMemberSnapshot(ACTOR, '999', 'MB-L-alias1');
        assert.equal(snap.philhealth, '121234567890');
    });

    it('TEST follow-up 1/2 — local Edit shell Charlie becomes Vicky with no stale identity', () => {
        const html = hydrateMemberEditHtml(CHARLIE_EDIT_SHELL, bootstrap.households[0], {
            ...vickyPayload,
            member_no: vickyPayload.client_local_member_id,
            name: 'Vicky Morales',
            local: true,
        }, '999', 'MB-001');

        assert.match(html, /Editing <strong[^>]*>Vicky Morales<\/strong> in household/);
        assert.match(html, /value="Vicky"/);
        assert.match(html, /value="Morales"/);
        assert.match(html, /data-member-id="MB-L-331929318FDA"/);
        assert.match(html, /data-offline-parent-member-no="MB-L-331929318FDA"/);
        assert.match(html, /action="\/household-profiling\/999\/members\/MB-L-331929318FDA"/);
        assert.match(html, /<option value="Female" selected>/);
        assert.doesNotMatch(html, /<option value="Male" selected>/);
        assert.match(html, /value="123456789101"/);
        assert.doesNotMatch(html, /Charlie Pot/);
        assert.doesNotMatch(html, /Charlie/);
        assert.doesNotMatch(html, /MB-001/);
        assert.doesNotMatch(html, /data-offline-parent-resident-id/);
        assert.doesNotMatch(html, /data-offline-field-hash/);
    });

    it('TEST follow-up 3 — local View shell Charlie becomes Vicky', () => {
        const html = hydrateMemberViewHtml(CHARLIE_SHELL, bootstrap.households[0], {
            ...vickyPayload,
            member_no: vickyPayload.client_local_member_id,
            name: 'Vicky Morales',
            local: true,
        }, '999', 'MB-001');
        assert.match(html, /Vicky Morales/);
        assert.match(html, />Female</);
        assert.match(html, />Daughter</);
        assert.match(html, />123456789101</);
        assert.doesNotMatch(html, /Charlie Pot/);
    });

    it('TEST follow-up 4-8 — Sex Male/Female survives create, View, Edit, and CREATE merge', async () => {
        await seedHouseholdAndShell();
        await putMeta(ACTOR, 'shell:member-edit', CHARLIE_EDIT_SHELL);
        const caches = createMemoryCaches();

        for (const sex of ['Male', 'Female']) {
            const localId = sex === 'Male' ? 'MB-L-sexmale1' : 'MB-L-sexfem01';
            const payload = { ...vickyPayload, client_local_member_id: localId, first_name: sex === 'Male' ? 'Victor' : 'Vicky', sex };
            await enqueueOperation({
                actor_id: ACTOR,
                actor_username: 'bhw.ana',
                operation_type: 'RESIDENT_CREATE',
                payload,
                parent_server: { household_id: 999, household_no: '999' },
            });
            await handleResidentCreateQueued({
                operation_type: 'RESIDENT_CREATE',
                payload,
                parent_server: { household_id: 999, household_no: '999' },
            }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });

            const snap = await getMemberSnapshot(ACTOR, '999', localId);
            assert.equal(snap.sex, sex);
            const view = await readCachedMember(caches, localId);
            assert.match(view, new RegExp(`>${sex}<`));
            const cache = await caches.open(htmlCacheNameForActor(ACTOR));
            const editHit = await cache.match(new Request(
                navigationCacheUrl(ORIGIN, `/household-profiling/999/members/${localId}/edit`),
            ));
            const edit = await editHit.text();
            assert.match(edit, new RegExp(`<option value="${sex}" selected>`));
            assert.doesNotMatch(edit, /Charlie Pot/);
        }

        const edited = await enqueueOperation({
            actor_id: ACTOR,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                ...vickyPayload,
                client_local_member_id: 'MB-L-sexfem01',
                sex: 'Female',
                education: 'Elementary Level',
                philhealth: '123456789101',
            },
            parent_server: { household_id: 999, household_no: '999', member_no: 'MB-L-sexfem01' },
        });
        assert.equal(edited.operation_type, 'RESIDENT_CREATE');
        assert.equal(edited.payload.sex, 'Female');
        assert.equal(edited.payload.philhealth, '123456789101');
    });
});

describe('offline intentional network failure semantics', () => {
    function createRoot() {
        return {
            getAttribute(name) {
                const attrs = {
                    'data-offline-status-url': '/offline/status',
                    'data-offline-sync-url': '/offline/sync',
                    'data-offline-actor-id': String(ACTOR),
                    'data-offline-actor-username': 'bhw.ana',
                };
                return attrs[name] || null;
            },
        };
    }

    it('TEST 8 — navigator offline keeps the queue pending without a sync-retry toast', async () => {
        await enqueueOperation({
            actor_id: ACTOR,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        });
        const events = [];
        const coordinator = createReplayCoordinator({
            fetch: async () => {
                throw new Error('should not fetch while navigator is offline');
            },
            root: createRoot(),
            window: {
                navigator: { onLine: false },
                setTimeout() { return 1; },
                clearTimeout() {},
                addEventListener() {},
                dispatchEvent() { return true; },
            },
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();
        const queued = await listOperations();
        assert.equal(queued.length, 1);
        assert.equal(queued[0].status, 'pending');
        assert.equal(queued[0].retry_count || 0, 0);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-retry'), false);
    });

    it('TEST 8b — blocked fetch while appearing online does not mark a hard retry', async () => {
        await enqueueOperation({
            actor_id: ACTOR,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: milesPayload,
            parent_server: { household_id: 999, household_no: '999' },
        });
        const events = [];
        const coordinator = createReplayCoordinator({
            fetch: async () => {
                throw new TypeError('Failed to fetch');
            },
            root: createRoot(),
            window: {
                navigator: { onLine: true },
                setTimeout() { return 1; },
                clearTimeout() {},
                addEventListener() {},
                dispatchEvent() { return true; },
            },
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();
        const queued = await listOperations();
        assert.equal(queued[0].status, 'pending');
        assert.equal(queued[0].retry_count || 0, 0);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-retry'), false);
    });
});

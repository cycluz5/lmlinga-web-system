/**
 * Panel refinement S-02: PhilHealth membership type in offline create, edit, view and queued payloads.
 * Synthetic data only.
 */

import assert from 'node:assert/strict';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';
import {
    PHILHEALTH_MEMBERSHIP_TYPES,
    philhealthMembershipTypeLabel,
} from '../../resources/js/offline/offline-philhealth-membership-types.js';

const url = (file) => pathToFileURL(path.resolve(`resources/js/offline/${file}`)).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(url('offline-db.js'));
const {
    replaceHouseholdProfilingSnapshots,
    getMemberSnapshot,
    applyMemberPayloadToSnapshot,
    putMeta,
} = await import(url('offline-hp-store.js'));
const { displayMemberField, hydrateMemberViewHtml, hydrateMemberEditHtml } = await import(url('offline-hp-hydrate.js'));
const { handleResidentCreateQueued } = await import(url('offline-local-member.js'));
const { resetReplayLock } = await import(url('offline-replay.js'));

const ORIGIN = 'https://lmlinga.test';
const ACTOR = 7;
const LOCAL_ID = 'MB-L-0e308522f5f8';

const VIEW_SHELL = `<!DOCTYPE html><html><body>
<article data-lml-hh-member-view data-household-no="999" data-member-id="MB-001" data-member-name="Test One">
<h2 class="lml-hh-member-view__name">Test One</h2>
<dl>
<div class="lml-hh-member-view__item"><dt>Full Name</dt><dd>Test One</dd></div>
<div class="lml-hh-member-view__item"><dt>PhilHealth Number</dt><dd>—</dd></div>
<div class="lml-hh-member-view__item"><dt>PhilHealth Membership Type</dt><dd data-offline-local-field="philhealth_membership_type">Listahanan</dd></div>
</dl></article></body></html>`;

const EDIT_SHELL = `<!DOCTYPE html><html><body>
<form data-hh-member-form data-household-no="999" data-member-id="MB-001">
<input name="philhealth" value="">
<select name="philhealth_membership_type">
<option value="">Select</option>
<optgroup label="Direct Contributor"><option value="kasambahay">Kasambahay</option></optgroup>
<optgroup label="Indirect Contributor"><option value="solo_parent">Solo Parent</option><option value="listahanan" selected>Listahanan</option></optgroup>
</select>
</form></body></html>`;

const household = {
    household_id: 999,
    household_no: '999',
    display_no: 'HH 999',
    house_head: 'Test One',
    zone: 'Zone 1',
    member_count: 1,
};

const serverMember = {
    household_id: 999,
    household_no: '999',
    resident_id: 1,
    member_no: 'MB-001',
    name: 'Test One',
    relation: 'Head',
    first_name: 'Test',
    last_name: 'One',
    philhealth: '',
    fp_user: 'Yes',
};

const newMember = {
    client_local_member_id: LOCAL_ID,
    last_name: 'Two',
    first_name: 'Test',
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

async function seed(member = serverMember) {
    await replaceHouseholdProfilingSnapshots(ACTOR, {
        generated_at: '2026-11-05T00:00:00Z',
        catalogs: {},
        households: [household],
        members: [member],
    });
    await putMeta(ACTOR, 'shell:member-view', VIEW_SHELL);
    await putMeta(ACTOR, 'shell:household_no', '999');
    await putMeta(ACTOR, 'shell:member_id', 'MB-001');
}

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

describe('offline PhilHealth membership type', () => {
    it('lists 22 types with Solo Parent and Employed Person with Disability as indirect', () => {
        assert.equal(PHILHEALTH_MEMBERSHIP_TYPES.length, 22);
        assert.equal(PHILHEALTH_MEMBERSHIP_TYPES.filter((t) => t.group === 'Direct Contributor').length, 10);
        assert.equal(PHILHEALTH_MEMBERSHIP_TYPES.filter((t) => t.group === 'Indirect Contributor').length, 12);
        for (const code of ['solo_parent', 'employed_person_with_disability']) {
            assert.equal(PHILHEALTH_MEMBERSHIP_TYPES.find((t) => t.code === code).group, 'Indirect Contributor');
        }
    });

    it('shows the label, and Not recorded for blank, missing or unknown values', () => {
        assert.equal(philhealthMembershipTypeLabel('solo_parent'), 'Solo Parent');
        for (const blank of ['', null, undefined, 'not_a_member']) {
            assert.equal(philhealthMembershipTypeLabel(blank), 'Not recorded');
        }
        assert.equal(displayMemberField({ philhealth_membership_type: 'kasambahay' }, 'philhealth_membership_type'), 'Kasambahay');
        assert.equal(displayMemberField({}, 'philhealth_membership_type'), 'Not recorded');
    });

    it('offline create keeps the chosen type in the snapshot and the cached view', async () => {
        await seed();
        const caches = createMemoryCaches();
        const result = await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: { ...newMember, philhealth_membership_type: 'solo_parent' },
            parent_server: { household_id: 999, household_no: '999' },
        }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });
        assert.equal(result.ok, true);

        const snap = await getMemberSnapshot(ACTOR, '999', LOCAL_ID);
        assert.equal(snap.philhealth_membership_type, 'solo_parent');
        assert.equal(snap.philhealth, '121234567890');

        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, `/household-profiling/999/members/${LOCAL_ID}`)));
        const html = await hit.text();
        assert.match(html, /PhilHealth Membership Type<\/dt><dd[^>]*>Solo Parent</);
        assert.doesNotMatch(html, />Listahanan</);
    });

    it('offline create without the field shows Not recorded, not the shell value', async () => {
        await seed();
        const caches = createMemoryCaches();
        await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: newMember,
            parent_server: { household_id: 999, household_no: '999' },
        }, { actorId: ACTOR, caches, origin: ORIGIN, assign() {} });

        const snap = await getMemberSnapshot(ACTOR, '999', LOCAL_ID);
        assert.equal(snap.philhealth_membership_type, '');
        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, `/household-profiling/999/members/${LOCAL_ID}`)));
        assert.match(await hit.text(), /PhilHealth Membership Type<\/dt><dd[^>]*>Not recorded</);
    });

    it('view hydration replaces the cached shell value', () => {
        const html = hydrateMemberViewHtml(VIEW_SHELL, household, { ...serverMember, philhealth_membership_type: 'kasambahay' }, '999', 'MB-001');
        assert.match(html, /PhilHealth Membership Type<\/dt><dd[^>]*>Kasambahay</);
        const blank = hydrateMemberViewHtml(VIEW_SHELL, household, serverMember, '999', 'MB-001');
        assert.match(blank, /PhilHealth Membership Type<\/dt><dd[^>]*>Not recorded</);
    });

    it('edit hydration selects the saved option inside the option groups', () => {
        const html = hydrateMemberEditHtml(EDIT_SHELL, household, { ...serverMember, philhealth_membership_type: 'solo_parent' }, '999', 'MB-001');
        assert.match(html, /<option value="solo_parent" selected>/);
        assert.doesNotMatch(html, /<option value="listahanan" selected>/);
        assert.match(html, /<optgroup label="Direct Contributor">/);

        const blank = hydrateMemberEditHtml(EDIT_SHELL, household, serverMember, '999', 'MB-001');
        assert.doesNotMatch(blank, /<option value="(solo_parent|listahanan|kasambahay)" selected>/);
    });

    it('server member edit can change and clear the type in the snapshot', async () => {
        const base = { ...serverMember, philhealth_membership_type: 'kasambahay' };
        await seed(base);
        await applyMemberPayloadToSnapshot(ACTOR, '999', 'MB-001', { philhealth_membership_type: 'solo_parent' });
        assert.equal((await getMemberSnapshot(ACTOR, '999', 'MB-001')).philhealth_membership_type, 'solo_parent');
        await applyMemberPayloadToSnapshot(ACTOR, '999', 'MB-001', { philhealth_membership_type: '' });
        assert.equal((await getMemberSnapshot(ACTOR, '999', 'MB-001')).philhealth_membership_type, '');
    });

    it('payloads from older offline forms keep the saved type', async () => {
        await seed({ ...serverMember, philhealth_membership_type: 'kasambahay' });
        await applyMemberPayloadToSnapshot(ACTOR, '999', 'MB-001', { first_name: 'Changed' });
        const next = await getMemberSnapshot(ACTOR, '999', 'MB-001');
        assert.equal(next.philhealth_membership_type, 'kasambahay');
        assert.equal(next.first_name, 'Changed');
    });

    it('members cached before this update hydrate as Not recorded without errors', async () => {
        await seed();
        const snap = await getMemberSnapshot(ACTOR, '999', 'MB-001');
        assert.equal(snap.philhealth_membership_type ?? '', '');
        assert.equal(displayMemberField(snap, 'philhealth_membership_type'), 'Not recorded');
    });
});

/**
 * Queued MB-L-* member Edit must hydrate from hp_members, not Blade placeholders.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    persistPlotHouseholdReadModel,
    getMemberSnapshot,
    applyMemberPayloadToSnapshot,
    putMemberSnapshot,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href);
const {
    applyMemberViewSnapshots,
    applyMemberEditSnapshots,
    fillMemberEditRoot,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-hp-live.js')).href);
const { hydrateMemberEditHtml, isoMemberBirthday, formRelationValue } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href
);

const ACTOR = 7;
const LOCAL_ID = 'MB-L-7817F81D56C5';

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    delete globalThis.document;
    delete globalThis.window;
    delete globalThis.navigator;
});

function el(doc, tag, attrs = {}) {
    const node = doc.createElement(tag);
    Object.entries(attrs).forEach(([name, value]) => {
        if (name === 'type') {
            node.type = value;
        }
        if (name === 'value') {
            node.value = value;
        }
        if (name === 'name') {
            node.name = value;
        }
        if (name === 'checked') {
            node.checked = Boolean(value);
            if (value) {
                node.setAttribute('checked', '');
            }
        }
        node.setAttribute(name, value === true ? '' : String(value));
    });
    return node;
}

function addSelect(doc, name, options, selected = '') {
    const select = el(doc, 'select', { name, type: 'select-one' });
    select.type = 'select-one';
    select.name = name;
    options.forEach((value) => {
        const option = el(doc, 'option', { value });
        option.value = value;
        option.textContent = value;
        if (value === selected) {
            option.setAttribute('selected', '');
            select.value = value;
        }
        select.appendChild(option);
    });
    if (selected) {
        select.value = selected;
    }
    return select;
}

function mountQueuedEdit(householdNo, memberId, { occupationSelected = 'Other', religionSelected = 'Other' } = {}) {
    const doc = createDocument();
    const page = el(doc, 'div', { 'data-lml-offline-root': '', 'data-offline-actor-id': String(ACTOR) });
    const host = el(doc, 'div', {
        'data-lml-hh-member-form': '',
        'data-mode': 'edit',
        'data-household-no': householdNo,
        'data-member-id': memberId,
    });
    const form = el(doc, 'form', {
        'data-hh-member-form-el': '',
        'data-offline-operation': 'RESIDENT_UPDATE',
        'data-offline-parent-household-no': householdNo,
        'data-offline-parent-member-no': memberId,
        'data-offline-local-member': '1',
    });
    const last = el(doc, 'input', { type: 'text', name: 'last_name', value: '' });
    const first = el(doc, 'input', { type: 'text', name: 'first_name', value: '' });
    const middle = el(doc, 'input', { type: 'text', name: 'middle_name', value: '' });
    const birthday = el(doc, 'input', { type: 'date', name: 'birthday', value: '' });
    birthday.type = 'date';
    const relation = addSelect(doc, 'relation', ['', 'Head', 'Spouse', 'Son', 'Daughter'], '');
    const sex = addSelect(doc, 'sex', ['', 'Male', 'Female'], '');
    const status = addSelect(doc, 'relationship_status', ['', 'Single', 'Married'], '');
    const occupation = addSelect(
        doc,
        'occupation',
        ['', 'None / N/A', 'Farmer', 'Other'],
        occupationSelected,
    );
    const religion = addSelect(
        doc,
        'religion',
        ['', 'Roman Catholic', 'Other', 'None'],
        religionSelected,
    );
    const education = addSelect(doc, 'education', ['', 'Elementary Level', 'College Graduate'], '');
    const income = addSelect(doc, 'monthly_income', ['', 'None / N/A', 'Below 5,000'], '');
    const fp = addSelect(doc, 'fp_user', ['', 'Yes', 'No', 'N/A'], '');
    const philhealth = el(doc, 'input', { type: 'text', name: 'philhealth', value: '' });
    const noneDisability = el(doc, 'input', { type: 'checkbox', name: 'disability[]', value: 'none' });
    noneDisability.type = 'checkbox';
    noneDisability.checked = false;
    noneDisability.name = 'disability[]';
    noneDisability.value = 'none';
    const noneMedical = el(doc, 'input', { type: 'checkbox', name: 'medical_history[]', value: 'none' });
    noneMedical.type = 'checkbox';
    noneMedical.checked = false;
    noneMedical.name = 'medical_history[]';
    noneMedical.value = 'none';
    const heading = el(doc, 'strong', { 'data-offline-local-field': 'name' });
    heading.textContent = 'Queued member';

    [
        last, first, middle, birthday, relation, sex, status, occupation, religion,
        education, income, fp, philhealth, noneDisability, noneMedical, heading,
    ].forEach((node) => form.appendChild(node));
    host.appendChild(form);
    page.appendChild(host);
    const root = el(doc, 'html');
    const body = el(doc, 'body');
    body.appendChild(page);
    root.appendChild(body);
    doc.documentElement = root;
    doc.body = body;
    globalThis.document = doc;

    return {
        doc,
        host,
        form,
        last,
        first,
        middle,
        birthday,
        relation,
        sex,
        status,
        occupation,
        religion,
        education,
        income,
        fp,
        philhealth,
        noneDisability,
        noneMedical,
        heading,
    };
}

function mountQueuedView(householdNo, memberId) {
    const doc = createDocument();
    const page = el(doc, 'div', { 'data-lml-offline-root': '', 'data-offline-actor-id': String(ACTOR) });
    const host = el(doc, 'article', {
        'data-lml-hh-member-view': '',
        'data-household-no': householdNo,
        'data-member-id': memberId,
        'data-offline-local-member': '1',
    });
    const name = el(doc, 'h2', { class: 'lml-hh-member-view__name' });
    name.classList.add('lml-hh-member-view__name');
    name.textContent = 'Queued member';
    host.appendChild(name);
    const item = el(doc, 'div');
    item.classList.add('lml-hh-member-view__item');
    const dt = el(doc, 'dt');
    dt.textContent = 'Relation to Household Head';
    const dd = el(doc, 'dd');
    dd.textContent = '—';
    item.appendChild(dt);
    item.appendChild(dd);
    host.appendChild(item);
    page.appendChild(host);
    const root = el(doc, 'html');
    const body = el(doc, 'body');
    body.appendChild(page);
    root.appendChild(body);
    doc.documentElement = root;
    globalThis.document = doc;
    return { doc, host, name, dd };
}

describe('queued member edit hydration', () => {
    it('queued household head can be viewed from the same hp_members row', async () => {
        const persisted = await persistPlotHouseholdReadModel(ACTOR, {
            first_name: 'Ricky',
            last_name: 'Ocampo',
            birthday: '1989-06-14',
            sex: 'Male',
            household_no: '701',
            zone: 2,
            household_type: 'HHTS',
            date_registered: '2026-09-09',
            lat: 13.37,
            lng: 123.42,
            consent: true,
        });
        const memberNo = persisted.member.member_no;
        const view = mountQueuedView('701', memberNo);
        const ok = await applyMemberViewSnapshots(view.host);
        assert.equal(ok, true);
        assert.equal(view.name.textContent, 'Ricky Ocampo');
        assert.equal(view.dd.textContent, 'Household Head');
        const row = await getMemberSnapshot(ACTOR, '701', memberNo);
        assert.equal(row.first_name, 'Ricky');
        assert.equal(row.last_name, 'Ocampo');
    });

    it('opening queued member Edit resolves the same hp_members row and restores stored fields', async () => {
        const persisted = await persistPlotHouseholdReadModel(ACTOR, {
            first_name: 'Ricky',
            last_name: 'Ocampo',
            birthday: '1989-06-14',
            sex: 'Male',
            civil_status: 'Married',
            household_no: '701',
            zone: 2,
            household_type: 'HHTS',
            date_registered: '2026-09-09',
            lat: 13.37,
            lng: 123.42,
            consent: true,
        });
        const memberNo = persisted.member.member_no;
        await applyMemberPayloadToSnapshot(ACTOR, '701', memberNo, {
            last_name: 'Ocampo',
            first_name: 'Ricky',
            relation: 'Head',
            birthday: '1989-06-14',
            sex: 'Male',
            relationship_status: 'Married',
            occupation: 'Farmer',
            religion: 'Roman Catholic',
            education: 'Elementary Level',
            monthly_income: 'None / N/A',
            fp_user: 'N/A',
            philhealth: '121234567890',
            disability: ['none'],
            medical_history: ['none'],
        });

        const fixture = mountQueuedEdit('701', memberNo);
        assert.equal(fixture.last.value, '');
        assert.equal(fixture.occupation.value, 'Other');
        assert.equal(isoMemberBirthday('06/14/1989'), '1989-06-14');
        assert.equal(formRelationValue('Household Head'), 'Head');

        const ok = await applyMemberEditSnapshots(fixture.host);
        assert.equal(ok, true);
        assert.equal(fixture.first.value, 'Ricky');
        assert.equal(fixture.last.value, 'Ocampo');
        assert.equal(fixture.birthday.value, '1989-06-14');
        assert.equal(fixture.sex.value, 'Male');
        assert.equal(fixture.relation.value, 'Head');
        assert.equal(fixture.status.value, 'Married');
        assert.equal(fixture.occupation.value, 'Farmer');
        assert.equal(fixture.religion.value, 'Roman Catholic');
        assert.equal(fixture.education.value, 'Elementary Level');
        assert.equal(fixture.philhealth.value, '121234567890');
        assert.equal(fixture.noneDisability.checked, true);
        assert.equal(fixture.heading.textContent, 'Ricky Ocampo');
    });

    it('update of one field preserves untouched queued fields on reload', async () => {
        const persisted = await persistPlotHouseholdReadModel(ACTOR, {
            first_name: 'Ricky',
            last_name: 'Ocampo',
            birthday: '1989-06-14',
            sex: 'Male',
            household_no: '701',
            zone: 2,
            household_type: 'HHTS',
            date_registered: '2026-09-09',
            lat: 13.37,
            lng: 123.42,
            consent: true,
        });
        const memberNo = persisted.member.member_no;
        await applyMemberPayloadToSnapshot(ACTOR, '701', memberNo, {
            last_name: 'Ocampo',
            first_name: 'Ricky',
            relation: 'Head',
            birthday: '1989-06-14',
            sex: 'Male',
            relationship_status: 'Married',
            occupation: 'Farmer',
            philhealth: '121234567890',
        });

        await applyMemberPayloadToSnapshot(ACTOR, '701', memberNo, {
            first_name: 'Ricardo',
            last_name: '',
            birthday: '',
            sex: '',
            relation: '',
            philhealth: '',
        });
        const row = await getMemberSnapshot(ACTOR, '701', memberNo);
        assert.equal(row.first_name, 'Ricardo');
        assert.equal(row.last_name, 'Ocampo');
        assert.equal(row.birthday, '1989-06-14');
        assert.equal(row.sex, 'Male');
        assert.equal(row.relation, 'Head');
        assert.equal(row.philhealth, '121234567890');

        const fixture = mountQueuedEdit('701', memberNo);
        await applyMemberEditSnapshots(fixture.host);
        assert.equal(fixture.first.value, 'Ricardo');
        assert.equal(fixture.last.value, 'Ocampo');
        assert.equal(fixture.birthday.value, '1989-06-14');
        assert.equal(fixture.sex.value, 'Male');
        assert.equal(fixture.relation.value, 'Head');
        assert.equal(fixture.philhealth.value, '121234567890');
    });

    it('local-ID style identifiers still resolve after a server member exists in the same household', async () => {
        const persisted = await persistPlotHouseholdReadModel(ACTOR, {
            first_name: 'Ricky',
            last_name: 'Ocampo',
            birthday: '1989-06-14',
            sex: 'Male',
            household_no: '701',
            zone: 2,
            household_type: 'HHTS',
            date_registered: '2026-09-09',
            lat: 13.37,
            lng: 123.42,
            consent: true,
        });
        const localId = persisted.member.member_no;
        await putMemberSnapshot(ACTOR, {
            household_no: '701',
            member_no: 'MB-0099',
            first_name: 'Server',
            last_name: 'Resident',
            name: 'Server Resident',
            relation: 'Spouse',
            sex: 'Female',
            birthday: '1990-01-01',
            local: false,
        });
        const row = await getMemberSnapshot(ACTOR, '701', localId);
        assert.equal(row.member_no, localId);
        assert.equal(row.first_name, 'Ricky');
        const fixture = mountQueuedEdit('701', localId);
        await applyMemberEditSnapshots(fixture.host);
        assert.equal(fixture.first.value, 'Ricky');
        assert.equal(fixture.last.value, 'Ocampo');
        assert.doesNotMatch(fixture.first.value, /Server/);
    });

    it('normal server-backed member edit stays on Blade values when no local snapshot exists', async () => {
        const fixture = mountQueuedEdit('999', 'MB-001', { occupationSelected: '', religionSelected: '' });
        fixture.last.value = 'Pot';
        fixture.first.value = 'Charlie';
        fixture.sex.value = 'Male';
        const ok = await applyMemberEditSnapshots(fixture.host);
        assert.equal(ok, false);
        assert.equal(fixture.first.value, 'Charlie');
        assert.equal(fixture.last.value, 'Pot');
        assert.equal(fixture.sex.value, 'Male');
    });

    it('cached edit HTML with value-before-name still receives the queued names', () => {
        const html = hydrateMemberEditHtml(
            `<div data-lml-hh-member-form data-mode="edit" data-household-no="1" data-member-id="MB-001">
<form data-hh-member-form-el>
<input value="" type="text" name="last_name">
<input value="" type="text" name="first_name">
<input type="date" name="birthday">
<select name="relation"><option value="Head">Head</option></select>
<select name="sex"><option value="Male">Male</option></select>
</form></div>`,
            { household_no: '701' },
            {
                member_no: LOCAL_ID,
                first_name: 'Ricky',
                last_name: 'Ocampo',
                name: 'Ricky Ocampo',
                birthday: '06/14/1989',
                relation: 'Household Head',
                sex: 'Male',
                local: true,
            },
            '1',
            'MB-001',
        );
        assert.match(html, /name="last_name"[^>]*value="Ocampo"|value="Ocampo"[^>]*name="last_name"/);
        assert.match(html, /name="first_name"[^>]*value="Ricky"|value="Ricky"[^>]*name="first_name"/);
        assert.match(html, /name="birthday"[^>]*value="1989-06-14"|value="1989-06-14"[^>]*name="birthday"/);
        assert.match(html, /data-member-id="MB-L-7817F81D56C5"/);
        assert.doesNotMatch(html, /MB-001/);
    });

    it('fillMemberEditRoot maps Household Head and US birthday onto production controls', () => {
        const fixture = mountQueuedEdit('701', LOCAL_ID);
        fillMemberEditRoot(fixture.host, {
            first_name: 'Ricky',
            last_name: 'Ocampo',
            birthday: '06/14/1989',
            sex: 'Male',
            relation: 'Household Head',
        });
        assert.equal(fixture.first.value, 'Ricky');
        assert.equal(fixture.last.value, 'Ocampo');
        assert.equal(fixture.birthday.value, '1989-06-14');
        assert.equal(fixture.relation.value, 'Head');
        assert.equal(fixture.sex.value, 'Male');
    });
});

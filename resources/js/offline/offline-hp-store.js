/**
 * Actor-scoped Household Profiling snapshots inside lmlinga_offline.
 * Does not touch the operations queue.
 */

import {
    HP_HOUSEHOLDS_STORE,
    HP_MEMBERS_STORE,
    HP_META_STORE,
    openOfflineDb,
    requestValue,
    withStore,
} from './offline-db.js';

export function householdRecordId(actorId, householdNo) {
    return `${Number(actorId)}:${String(householdNo || '').trim()}`;
}

export function memberRecordId(actorId, householdNo, memberNo) {
    return `${householdRecordId(actorId, householdNo)}:${String(memberNo || '').trim()}`;
}

export function metaRecordId(actorId, key) {
    return `${Number(actorId)}:${String(key || '').trim()}`;
}

async function withNamedStore(storeName, mode, work) {
    const db = await openOfflineDb();
    try {
        return await withStore(db, mode, work, storeName);
    } finally {
        db.close?.();
    }
}

export async function putHouseholdSnapshot(actorId, household) {
    const record = {
        ...household,
        id: householdRecordId(actorId, household.household_no),
        actor_id: Number(actorId),
    };
    await withNamedStore(HP_HOUSEHOLDS_STORE, 'readwrite', (store) => requestValue(store.put(record)));
    return record;
}

export async function putMemberSnapshot(actorId, member) {
    const record = {
        ...member,
        id: memberRecordId(actorId, member.household_no, member.member_no),
        actor_id: Number(actorId),
    };
    await withNamedStore(HP_MEMBERS_STORE, 'readwrite', (store) => requestValue(store.put(record)));
    return record;
}

/** Refresh the server conflict hash of an already-prepared member (after a successful update sync). */
export async function setMemberFieldHash(actorId, householdNo, memberNo, fieldHash) {
    const hash = String(fieldHash || '').trim();
    if (!hash) {
        return null;
    }
    const existing = await getMemberSnapshot(actorId, householdNo, memberNo);
    if (!existing) {
        return null;
    }
    return putMemberSnapshot(actorId, { ...existing, field_hash: hash });
}

export async function putMeta(actorId, key, value) {
    const record = {
        id: metaRecordId(actorId, key),
        actor_id: Number(actorId),
        key: String(key),
        value,
        updated_at: Date.now(),
    };
    await withNamedStore(HP_META_STORE, 'readwrite', (store) => requestValue(store.put(record)));
    return record;
}

/**
 * Delete a local household snapshot for this actor only.
 * @returns {Promise<boolean>}
 */
export async function deleteHouseholdSnapshot(actorId, householdNo) {
    const actor = Number(actorId);
    const no = String(householdNo || '').trim();
    if (!Number.isInteger(actor) || actor <= 0 || !no) {
        return false;
    }
    const id = householdRecordId(actor, no);
    const existing = await getHouseholdSnapshot(actor, no);
    if (!existing || Number(existing.actor_id) !== actor) {
        return false;
    }
    await withNamedStore(HP_HOUSEHOLDS_STORE, 'readwrite', (store) => requestValue(store.delete(id)));
    return true;
}

/**
 * Delete a local member snapshot for this actor only.
 * @returns {Promise<boolean>}
 */
export async function deleteMemberSnapshot(actorId, householdNo, memberNo) {
    const actor = Number(actorId);
    const hh = String(householdNo || '').trim();
    const mb = String(memberNo || '').trim();
    if (!Number.isInteger(actor) || actor <= 0 || !hh || !mb) {
        return false;
    }
    const id = memberRecordId(actor, hh, mb);
    const existing = await getMemberSnapshot(actor, hh, mb);
    if (!existing || Number(existing.actor_id) !== actor) {
        return false;
    }
    await withNamedStore(HP_MEMBERS_STORE, 'readwrite', (store) => requestValue(store.delete(id)));
    return true;
}

/**
 * Clear unsynced EH overlay fields on a household snapshot without inventing server values.
 */
export async function clearHouseholdEhOverlay(actorId, householdNo) {
    const existing = await getHouseholdSnapshot(actorId, householdNo);
    if (!existing || Number(existing.actor_id) !== Number(actorId)) {
        return null;
    }
    return putHouseholdSnapshot(actorId, {
        ...existing,
        water: {},
        sanitation: {},
        eh: { completed_step: 0 },
        pending_sync: Boolean(existing.local) ? true : false,
        sync_attention: false,
        last_sync_error: null,
    });
}

/**
 * Clear pending flags on a household update discard (server-backed row kept).
 */
export async function clearHouseholdPendingFlags(actorId, householdNo) {
    const existing = await getHouseholdSnapshot(actorId, householdNo);
    if (!existing || Number(existing.actor_id) !== Number(actorId)) {
        return null;
    }
    if (existing.local && !existing.household_id) {
        return existing;
    }
    return putHouseholdSnapshot(actorId, {
        ...existing,
        pending_sync: false,
        sync_attention: false,
        last_sync_error: null,
    });
}

export async function getHouseholdSnapshot(actorId, householdNo) {
    return withNamedStore(HP_HOUSEHOLDS_STORE, 'readonly', (store) => (
        requestValue(store.get(householdRecordId(actorId, householdNo)))
    ));
}

function memberIdMatches(row, needle) {
    const target = String(needle || '').trim().toLowerCase();
    if (!target) {
        return false;
    }
    return [row?.member_no, row?.client_local_member_id, row?.local_member_id]
        .some((value) => String(value || '').trim().toLowerCase() === target);
}

export async function getMemberSnapshot(actorId, householdNo, memberNo) {
    const needle = String(memberNo || '').trim();
    if (!needle) {
        return null;
    }
    const exact = await withNamedStore(HP_MEMBERS_STORE, 'readonly', (store) => (
        requestValue(store.get(memberRecordId(actorId, householdNo, needle)))
    ));
    if (exact) {
        return exact;
    }
    const inHousehold = await listMemberSnapshots(actorId, householdNo);
    const householdMatch = inHousehold.find((row) => memberIdMatches(row, needle));
    if (householdMatch) {
        return householdMatch;
    }
    if (!/^MB-L-[A-Za-z0-9]+$/i.test(needle) && !/^MB-\d+$/i.test(needle)) {
        return null;
    }
    const all = await listMemberSnapshots(actorId);
    return all.find((row) => memberIdMatches(row, needle)) || null;
}

export async function getMeta(actorId, key) {
    const row = await withNamedStore(HP_META_STORE, 'readonly', (store) => (
        requestValue(store.get(metaRecordId(actorId, key)))
    ));
    return row ? row.value : null;
}

export async function listHouseholdSnapshots(actorId) {
    const all = await withNamedStore(HP_HOUSEHOLDS_STORE, 'readonly', (store) => requestValue(store.getAll()));
    return (all || []).filter((row) => Number(row.actor_id) === Number(actorId));
}

export async function listMemberSnapshots(actorId, householdNo = null) {
    const all = await withNamedStore(HP_MEMBERS_STORE, 'readonly', (store) => requestValue(store.getAll()));
    return (all || []).filter((row) => {
        if (Number(row.actor_id) !== Number(actorId)) {
            return false;
        }
        if (householdNo && String(row.household_no) !== String(householdNo)) {
            return false;
        }
        return true;
    });
}

export async function refreshHouseholdMemberCount(actorId, householdNo) {
    const household = await getHouseholdSnapshot(actorId, householdNo);
    if (!household) {
        return null;
    }
    const members = await listMemberSnapshots(actorId, householdNo);
    return putHouseholdSnapshot(actorId, { ...household, member_count: members.length });
}

export function ageFromBirthday(birthday, now = new Date()) {
    const match = String(birthday || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) {
        return null;
    }
    const birth = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    if (Number.isNaN(birth.getTime())) {
        return null;
    }
    let age = now.getFullYear() - birth.getFullYear();
    const monthDelta = now.getMonth() - birth.getMonth();
    if (monthDelta < 0 || (monthDelta === 0 && now.getDate() < birth.getDate())) {
        age -= 1;
    }
    return age >= 0 ? age : null;
}

export function formatAccomplishedDate(value) {
    const iso = String(value || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (iso) {
        return `${iso[2]}/${iso[3]}/${iso[1]}`;
    }
    const us = String(value || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (us) {
        return `${us[1].padStart(2, '0')}/${us[2].padStart(2, '0')}/${us[3]}`;
    }
    const text = String(value || '').trim();
    return text === '' ? '—' : text;
}

export function zoneLabel(zone) {
    const raw = String(zone ?? '').trim();
    if (!raw) {
        return '';
    }
    return /^zone\s/i.test(raw) ? raw : `Zone ${raw}`;
}

function baseLocalHouseholdSnapshot(payload = {}, options = {}) {
    const householdNo = String(payload.household_no || '').trim();
    const first = String(payload.first_name || '').trim();
    const middle = String(payload.middle_name || '').trim();
    const last = String(payload.last_name || '').trim();
    const houseHead = [first, middle, last].filter(Boolean).join(' ')
        || String(payload.house_head || '').trim();
    return {
        household_id: null,
        household_no: householdNo,
        display_no: householdNo.replace(/^HH-/i, ''),
        house_head: houseHead || '—',
        zone: zoneLabel(payload.zone),
        purok: payload.zone,
        street: payload.street || '',
        address: payload.address || '',
        household_type: payload.household_type || '',
        date_registered: payload.date_registered || '',
        accomplished_date: formatAccomplishedDate(payload.date_registered),
        accomplished_by: payload.accomplished_by || '',
        latitude: payload.lat ?? payload.latitude ?? null,
        longitude: payload.lng ?? payload.longitude ?? null,
        member_count: Number(options.member_count ?? 0),
        local: true,
        pending_sync: true,
        sync_attention: false,
        last_sync_error: null,
        source: options.source || 'information',
        water: {},
        sanitation: {},
        eh: { completed_step: 0 },
        head: {
            first_name: first,
            middle_name: middle,
            last_name: last,
            sex: String(payload.sex || '').trim(),
            civil_status: String(payload.civil_status || '').trim(),
            birthday: String(payload.birthday || '').trim(),
        },
    };
}

/**
 * Information-first Household Profiling create (no Spot Mapping / Head required).
 */
export function householdSnapshotFromCreate(payload = {}) {
    return baseLocalHouseholdSnapshot(payload, { member_count: 0, source: 'information' });
}

export function householdSnapshotFromPlot(payload = {}) {
    return baseLocalHouseholdSnapshot(payload, { member_count: 1, source: 'plot' });
}

/**
 * Persist an information-first HOUSEHOLD_CREATE into hp_households.
 * Does not mint a Head member or queue RESIDENT_CREATE.
 */
export async function persistHouseholdCreateReadModel(actorId, payload = {}) {
    const actor = Number(actorId);
    if (!Number.isInteger(actor) || actor <= 0) {
        throw new Error('household-create-actor-required');
    }
    const snapshot = householdSnapshotFromCreate(payload);
    const householdNo = snapshot.household_no;
    if (!householdNo) {
        throw new Error('household-create-household-required');
    }
    const existing = await getHouseholdSnapshot(actor, householdNo);
    const members = await listMemberSnapshots(actor, householdNo);
    const next = {
        ...(existing || {}),
        ...snapshot,
        member_count: members.length || Number(existing?.member_count || 0),
        water: existing?.water && typeof existing.water === 'object' ? existing.water : {},
        sanitation: existing?.sanitation && typeof existing.sanitation === 'object' ? existing.sanitation : {},
        eh: existing?.eh && typeof existing.eh === 'object' ? existing.eh : { completed_step: 0 },
        local: true,
        pending_sync: true,
        sync_attention: false,
        last_sync_error: null,
        household_id: null,
        source: existing?.source === 'plot' ? 'plot' : 'information',
    };
    return putHouseholdSnapshot(actor, next);
}

/**
 * Apply shell-form fields onto an existing local household snapshot.
 */
export async function applyHouseholdPayloadToSnapshot(actorId, householdNo, payload = {}) {
    const no = String(householdNo || payload.household_no || '').trim();
    if (!no) {
        return null;
    }
    const existing = await getHouseholdSnapshot(actorId, no);
    if (!existing) {
        return persistHouseholdCreateReadModel(actorId, { ...payload, household_no: no });
    }
    const wasLocalPending = Boolean(existing.local || existing.pending_sync) || !existing.household_id;
    const mergedPayload = {
        ...existing,
        ...payload,
        household_no: no,
        zone: payload.zone != null && String(payload.zone).trim() !== '' ? payload.zone : existing.purok || existing.zone,
        street: payload.street != null ? payload.street : existing.street,
        address: payload.address != null ? payload.address : existing.address,
        date_registered: payload.date_registered != null ? payload.date_registered : existing.date_registered,
        household_type: payload.household_type != null ? payload.household_type : existing.household_type,
        accomplished_by: payload.accomplished_by != null ? payload.accomplished_by : existing.accomplished_by,
        latitude: payload.latitude ?? payload.lat ?? existing.latitude,
        longitude: payload.longitude ?? payload.lng ?? existing.longitude,
    };
    const next = {
        ...existing,
        ...householdSnapshotFromCreate(mergedPayload),
        member_count: existing.member_count,
        water: existing.water || {},
        sanitation: existing.sanitation || {},
        eh: existing.eh || { completed_step: 0 },
        head: existing.head || {},
        head_member_no: existing.head_member_no,
        source: existing.source || 'information',
    };
    if (wasLocalPending) {
        next.household_id = null;
        next.local = true;
        next.pending_sync = true;
        next.sync_attention = false;
        next.last_sync_error = null;
    } else {
        next.household_id = existing.household_id;
        next.local = false;
        next.pending_sync = Boolean(existing.pending_sync);
        next.sync_attention = Boolean(existing.sync_attention);
        next.last_sync_error = existing.last_sync_error;
    }
    return putHouseholdSnapshot(actorId, next);
}

export async function promoteLocalHouseholdIdentity(actorId, householdNo, householdPk = null) {
    const existing = await getHouseholdSnapshot(actorId, householdNo);
    if (!existing) {
        return null;
    }
    const pk = Number(householdPk);
    const next = await putHouseholdSnapshot(actorId, {
        ...existing,
        household_id: Number.isInteger(pk) && pk > 0 ? pk : existing.household_id,
        local: false,
        pending_sync: false,
        sync_attention: false,
        last_sync_error: null,
    });
    if (Number.isInteger(pk) && pk > 0) {
        await promoteMembersHouseholdId(actorId, householdNo, pk);
    }
    return next;
}

async function promoteMembersHouseholdId(actorId, householdNo, householdPk) {
    const members = await listMemberSnapshots(actorId, householdNo);
    for (const member of members) {
        await putMemberSnapshot(actorId, {
            ...member,
            household_id: householdPk,
            waiting_for_household: false,
        });
    }
}

export async function markHouseholdSyncAttention(actorId, householdNo, errorCode = null) {
    const existing = await getHouseholdSnapshot(actorId, householdNo);
    if (!existing) {
        return null;
    }
    const next = await putHouseholdSnapshot(actorId, {
        ...existing,
        local: true,
        pending_sync: true,
        sync_attention: true,
        last_sync_error: errorCode ? String(errorCode) : existing.last_sync_error,
    });
    const members = await listMemberSnapshots(actorId, householdNo);
    for (const member of members) {
        if (member.local || /^MB-L-/i.test(String(member.member_no || ''))) {
            await putMemberSnapshot(actorId, {
                ...member,
                waiting_for_household: true,
                sync_attention: true,
                last_sync_error: errorCode ? String(errorCode) : member.last_sync_error,
            });
        }
    }
    return next;
}

export function isHeadMember(member) {
    return String(member?.relation || member?.relationship || '').trim().toLowerCase() === 'head';
}

function plotHeadPayloadFromHousehold(household, payload = {}) {
    const head = household?.head && typeof household.head === 'object' ? household.head : {};
    const merged = { ...head, ...payload };
    let first = String(merged.first_name || '').trim();
    let last = String(merged.last_name || '').trim();
    const middle = String(merged.middle_name || '').trim();
    if (!first && !last) {
        const parts = String(household?.house_head || '').trim().split(/\s+/).filter(Boolean);
        first = parts[0] || '';
        last = parts.slice(1).join(' ');
    }
    return {
        first_name: first,
        middle_name: middle,
        last_name: last,
        sex: String(merged.sex || '').trim(),
        birthday: String(merged.birthday || '').trim(),
        civil_status: String(merged.civil_status || '').trim(),
        relation: 'Head',
        relationship: 'Head',
    };
}

/**
 * Canonical HP member read-model for a household.
 * If a Plot household has head fields but hp_members has no Head row yet,
 * materialize that Plot head here. Never enqueues RESIDENT_CREATE.
 */
export async function membersForHousehold(actorId, householdNo) {
    const no = String(householdNo || '').trim();
    let members = await listMemberSnapshots(actorId, no);
    if (members.some((row) => isHeadMember(row))) {
        return members;
    }
    const household = await getHouseholdSnapshot(actorId, no);
    if (!household) {
        return members;
    }
    // Only Plot-created households auto-materialize a Head row.
    // Information-first Households may have empty/placeholder house_head.
    if (String(household.source || '') !== 'plot' && !household.from_plot) {
        return members;
    }
    const payload = plotHeadPayloadFromHousehold(household);
    if (!payload.first_name && !payload.last_name) {
        return members;
    }
    if (payload.first_name === '—' || payload.last_name === '—') {
        return members;
    }
    await upsertPlotHeadMember(actorId, household, payload);
    return listMemberSnapshots(actorId, no);
}

export async function replaceHouseholdProfilingSnapshots(actorId, payload) {
    const households = Array.isArray(payload?.households) ? payload.households : [];
    const members = Array.isArray(payload?.members) ? payload.members : [];

    const existingHouseholds = await listHouseholdSnapshots(actorId);
    const keepHousehold = new Set(households.map((row) => householdRecordId(actorId, row.household_no)));
    for (const row of existingHouseholds) {
        if (!keepHousehold.has(row.id) && !row.local && !row.pending_sync) {
            await withNamedStore(HP_HOUSEHOLDS_STORE, 'readwrite', (store) => requestValue(store.delete(row.id)));
        }
    }

    for (const household of households) {
        await putHouseholdSnapshot(actorId, household);
    }

    const existingMembers = await listMemberSnapshots(actorId);
    const incomingIds = new Set(members.map((row) => memberRecordId(actorId, row.household_no, row.member_no)));
    for (const row of existingMembers) {
        const local = /^MB-L-/i.test(String(row.member_no || ''));
        if (!incomingIds.has(row.id) && !local) {
            await withNamedStore(HP_MEMBERS_STORE, 'readwrite', (store) => requestValue(store.delete(row.id)));
        }
    }

    for (const member of members) {
        await putMemberSnapshot(actorId, member);
    }

    await dropOrphanPlotHeads(actorId, members);

    const mergedHouseholds = await listHouseholdSnapshots(actorId);
    for (const household of mergedHouseholds) {
        await refreshHouseholdMemberCount(actorId, household.household_no);
    }

    await putMeta(actorId, 'catalogs', payload?.catalogs || {});
    await putMeta(actorId, 'generated_at', payload?.generated_at || null);
    await putMeta(actorId, 'status', {
        ready: households.length,
        total: households.length,
        generated_at: payload?.generated_at || null,
    });

    return { households: households.length, members: members.length };
}

function displayNameFromPayload(payload, fallback = '') {
    const name = [payload?.first_name, payload?.middle_name, payload?.last_name]
        .map((part) => String(part || '').trim())
        .filter(Boolean)
        .join(' ');
    return name || fallback || '';
}

function keepQueuedScalar(incoming, existing) {
    if (incoming == null) {
        return existing ?? '';
    }
    const text = String(incoming);
    if (text.trim() === '' && String(existing ?? '').trim() !== '') {
        return existing;
    }
    return text;
}

function keepQueuedList(incoming, existing) {
    if (!Array.isArray(incoming)) {
        return Array.isArray(existing) ? existing : [];
    }
    if (incoming.length === 0 && Array.isArray(existing) && existing.length > 0) {
        return existing;
    }
    return incoming;
}

export function memberFromPayload(household, payload, memberNo, existing = null) {
    const base = existing && typeof existing === 'object' ? existing : {};
    const local = /^MB-L-/i.test(String(memberNo || '')) || Boolean(base.local);
    const pick = (incoming, existingValue) => (
        local ? keepQueuedScalar(incoming, existingValue) : String(incoming ?? existingValue ?? '')
    );
    const relation = pick(payload?.relation || payload?.relationship, base.relation || base.relationship);
    const occupation = pick(payload?.occupation || payload?.occupation_select, base.occupation || base.occupation_select);
    const religion = pick(payload?.religion || payload?.religion_select, base.religion || base.religion_select);
    return {
        ...base,
        household_id: household?.household_id ?? base.household_id ?? null,
        household_no: String(household?.household_no || base.household_no || ''),
        resident_id: base.resident_id ?? null,
        member_no: String(memberNo || base.member_no || ''),
        name: displayNameFromPayload(payload, base.name),
        relationship: String(relation || ''),
        age: payload?.age ?? base.age ?? ageFromBirthday(payload?.birthday || base.birthday),
        sex: String(pick(payload?.sex, base.sex) || ''),
        occupation: String(occupation || ''),
        last_name: String(pick(payload?.last_name, base.last_name) || ''),
        first_name: String(pick(payload?.first_name, base.first_name) || ''),
        middle_name: String(pick(payload?.middle_name, base.middle_name) || ''),
        relation: String(relation || ''),
        birthday: String(pick(payload?.birthday, base.birthday) || ''),
        relationship_status: String(pick(
            payload?.relationship_status || payload?.civil_status,
            base.relationship_status,
        ) || ''),
        monthly_income: String(pick(payload?.monthly_income, base.monthly_income) || ''),
        religion: String(religion || ''),
        education: String(pick(payload?.education, base.education) || ''),
        philhealth: String(pick(
            payload?.philhealth ?? payload?.philhealth_number,
            base.philhealth,
        ) || '').replace(/\s+/g, ''),
        fp_user: String(pick(payload?.fp_user, base.fp_user) || ''),
        occupation_select: String(occupation || ''),
        occupation_other: String(pick(payload?.occupation_other, base.occupation_other) || ''),
        religion_select: String(religion || ''),
        religion_other: String(pick(payload?.religion_other, base.religion_other) || ''),
        disability: local ? keepQueuedList(payload?.disability, base.disability) : (payload?.disability ?? base.disability ?? []),
        disability_others: String(pick(payload?.disability_others, base.disability_others) || ''),
        medical_history: local
            ? keepQueuedList(payload?.medical_history, base.medical_history)
            : (payload?.medical_history ?? base.medical_history ?? []),
        medical_others: String(pick(payload?.medical_others, base.medical_others) || ''),
        local,
        from_plot: Boolean(base.from_plot),
        health: payload?.health || base.health || deriveLocalHealthMeta({
            sex: String(pick(payload?.sex, base.sex) || ''),
            birthday: String(pick(payload?.birthday, base.birthday) || ''),
            age: payload?.age ?? base.age ?? ageFromBirthday(payload?.birthday || base.birthday),
            local,
        }),
    };
}

/**
 * Compact Health Summary eligibility for offline-created MB-L-* members.
 */
export function deriveLocalHealthMeta(member = {}) {
    const sex = String(member.sex || '').trim();
    const age = Number(member.age);
    const female = /^female$/i.test(sex);
    const risk = Number.isFinite(age) && age >= 19;
    const adultImm = Number.isFinite(age) && age >= 18;
    const fp = female && Number.isFinite(age) && age >= 10;
    const maternalWorkflow = female && Number.isFinite(age) && age >= 10;
    return {
        eligible: {
            child_immunization: true,
            school_based_immunization: true,
            child_nutrition: true,
            deworming: true,
            nutritional_status: true,
            risk_assessment: risk,
            family_planning: fp,
            maternal_care: female,
            maternal_care_workflow: maternalWorkflow,
            adult_immunization: adultImm,
            death: false,
        },
        has_records: {
            'child-immunization': false,
            'birth-history': false,
            'school-based-immunization': false,
            'child-nutrition': false,
            'nutritional-status': false,
            deworming: false,
            'risk-assessment': false,
            'family-planning': false,
            'maternal-care': false,
        },
        warm_modules: [],
        nutrition_card: {
            weight: '—',
            height: '—',
            mode: 'bmi',
            bmi: '—',
            status: '—',
            muac_applicable: false,
            muac: '—',
            muac_status: '—',
        },
        maternal_care_has_history: false,
        member_age: Number.isFinite(age) ? age : null,
        member_sex: sex,
    };
}

function storedMemberNo(existing, fallback) {
    const stored = String(existing?.member_no || '').trim();
    return stored || String(fallback || '').trim();
}

export async function applyMemberPayloadToSnapshot(actorId, householdNo, memberNo, payload) {
    const household = await getHouseholdSnapshot(actorId, householdNo);
    if (!household || !memberNo) {
        return null;
    }
    const existing = await getMemberSnapshot(actorId, householdNo, memberNo);
    if (!existing && !/^MB-L-/i.test(String(memberNo))) {
        return putMemberSnapshot(actorId, memberFromPayload(household, payload, memberNo));
    }
    if (!existing) {
        return appendLocalMemberToHousehold(actorId, householdNo, {
            ...payload,
            client_local_member_id: memberNo,
        });
    }
    return putMemberSnapshot(actorId, memberFromPayload(household, payload, storedMemberNo(existing, memberNo), existing));
}

export async function promoteLocalMemberIdentity(actorId, householdNo, localMemberId, serverMemberNo, residentId = null) {
    const existing = await getMemberSnapshot(actorId, householdNo, localMemberId);
    if (!existing || !serverMemberNo) {
        return null;
    }
    const next = {
        ...existing,
        member_no: serverMemberNo,
        resident_id: residentId ?? existing.resident_id,
        local: false,
        from_plot: false,
        id: memberRecordId(actorId, householdNo, serverMemberNo),
    };
    await putMemberSnapshot(actorId, next);
    if (existing.id && existing.id !== next.id) {
        await withNamedStore(HP_MEMBERS_STORE, 'readwrite', (store) => (
            requestValue(store.delete(existing.id))
        ));
    }
    return next;
}

export async function appendLocalMemberToHousehold(actorId, householdNo, payload) {
    const household = await getHouseholdSnapshot(actorId, householdNo);
    if (!household) {
        return null;
    }
    const memberNo = String(payload.client_local_member_id || payload.member_no || '').trim();
    const existingMember = await getMemberSnapshot(actorId, householdNo, memberNo);
    if (existingMember) {
        const next = memberFromPayload(household, payload, storedMemberNo(existingMember, memberNo), existingMember);
        return putMemberSnapshot(actorId, next);
    }
    const member = await putMemberSnapshot(actorId, memberFromPayload(household, payload, memberNo));
    await refreshHouseholdMemberCount(actorId, householdNo);
    return member;
}

function nextLocalMemberId() {
    const bytes = new Uint8Array(6);
    const cryptoObj = typeof globalThis !== 'undefined' ? globalThis.crypto : null;
    if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
        cryptoObj.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i += 1) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('').toUpperCase();
    return `MB-L-${hex}`;
}

export async function upsertPlotHeadMember(actorId, household, payload = {}) {
    const householdNo = String(household?.household_no || '').trim();
    if (!householdNo || !Number.isInteger(Number(actorId)) || Number(actorId) <= 0) {
        return null;
    }
    const existing = await listMemberSnapshots(actorId, householdNo);
    const current = existing.find((row) => row.from_plot || (row.local && isHeadMember(row)));
    const memberNo = String(current?.member_no || household?.head_member_no || nextLocalMemberId()).trim();
    if (!memberNo) {
        return null;
    }
    const member = await putMemberSnapshot(actorId, memberFromPayload(
        household,
        plotHeadPayloadFromHousehold(household, payload),
        memberNo,
        { ...(current || {}), local: true, from_plot: true, resident_id: null },
    ));
    await refreshHouseholdMemberCount(actorId, householdNo);
    return member;
}

/**
 * Awaited Plot-button write: same IndexedDB connection for household + Head.
 * Throws on failure. Never queues RESIDENT_CREATE.
 */
export async function persistPlotHouseholdReadModel(actorId, payload = {}) {
    const actor = Number(actorId);
    if (!Number.isInteger(actor) || actor <= 0) {
        throw new Error('plot-head-actor-required');
    }
    const snapshot = householdSnapshotFromPlot(payload);
    const householdNo = snapshot.household_no;
    if (!householdNo) {
        throw new Error('plot-head-household-required');
    }

    const db = await openOfflineDb();
    try {
        if (!db.objectStoreNames.contains(HP_HOUSEHOLDS_STORE) || !db.objectStoreNames.contains(HP_MEMBERS_STORE)) {
            throw new Error('plot-head-stores-missing');
        }
        const allMembers = await withStore(
            db,
            'readonly',
            (store) => requestValue(store.getAll()),
            HP_MEMBERS_STORE,
        );
        const existing = (allMembers || []).filter((row) => (
            Number(row.actor_id) === actor && String(row.household_no) === householdNo
        ));
        const current = existing.find((row) => row.from_plot || (row.local && isHeadMember(row)));
        const memberNo = String(current?.member_no || snapshot.head_member_no || nextLocalMemberId()).trim();
        if (!memberNo) {
            throw new Error('plot-head-member-id-required');
        }

        const member = memberFromPayload(
            snapshot,
            plotHeadPayloadFromHousehold(snapshot, payload),
            memberNo,
            { ...(current || {}), local: true, from_plot: true, resident_id: null, zone: snapshot.zone },
        );
        member.zone = snapshot.zone;
        member.id = memberRecordId(actor, householdNo, memberNo);
        member.actor_id = actor;
        member.household_no = householdNo;
        if (!String(member.id || '').trim()) {
            throw new Error('plot-head-id-required');
        }

        snapshot.head_member_no = memberNo;
        snapshot.member_count = Math.max(existing.length, 1);
        const householdRecord = {
            ...snapshot,
            id: householdRecordId(actor, householdNo),
            actor_id: actor,
        };

        await withStore(
            db,
            'readwrite',
            (store) => requestValue(store.put(householdRecord)),
            HP_HOUSEHOLDS_STORE,
        );
        await withStore(
            db,
            'readwrite',
            (store) => requestValue(store.put(member)),
            HP_MEMBERS_STORE,
        );

        const counted = (await withStore(
            db,
            'readonly',
            (store) => requestValue(store.getAll()),
            HP_MEMBERS_STORE,
        ) || []).filter((row) => (
            Number(row.actor_id) === actor && String(row.household_no) === householdNo
        ));
        householdRecord.member_count = counted.length;
        await withStore(
            db,
            'readwrite',
            (store) => requestValue(store.put(householdRecord)),
            HP_HOUSEHOLDS_STORE,
        );

        return { household: householdRecord, member };
    } finally {
        db.close?.();
    }
}

export async function dropOrphanPlotHeads(actorId, incomingMembers) {
    const incoming = Array.isArray(incomingMembers) ? incomingMembers : [];
    const byHousehold = new Map();
    for (const row of incoming) {
        const householdNo = String(row?.household_no || '').trim();
        if (!householdNo) {
            continue;
        }
        if (!byHousehold.has(householdNo)) {
            byHousehold.set(householdNo, []);
        }
        byHousehold.get(householdNo).push(row);
    }

    for (const [householdNo, rows] of byHousehold) {
        const hasServerHead = rows.some((row) => (
            isHeadMember(row)
            && !/^MB-L-/i.test(String(row.member_no || ''))
            && (row.resident_id || row.household_id)
        ));
        if (!hasServerHead) {
            continue;
        }
        const household = await getHouseholdSnapshot(actorId, householdNo);
        if (household?.local || household?.pending_sync) {
            continue;
        }
        const locals = await listMemberSnapshots(actorId, householdNo);
        for (const row of locals) {
            const localId = String(row.member_no || '');
            if (!row.from_plot || !/^MB-L-/i.test(localId)) {
                continue;
            }
            if (rows.some((incomingRow) => String(incomingRow.member_no) === localId)) {
                continue;
            }
            await withNamedStore(HP_MEMBERS_STORE, 'readwrite', (store) => (
                requestValue(store.delete(row.id || memberRecordId(actorId, householdNo, localId)))
            ));
        }
    }
}


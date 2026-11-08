/**
 * Actor-scoped local Health Record read model (supported HEALTH_SERVICE_WRITE).
 */

import {
    HP_HEALTH_STORE,
    openOfflineDb,
    requestValue,
    withStore,
} from './offline-db.js';

export function healthRecordId(actorId, memberNo, action, section = '', visitId = '', assessmentId = '') {
    return [
        Number(actorId),
        String(memberNo || '').trim(),
        String(action || '').trim(),
        String(section || '').trim(),
        String(visitId || '').trim(),
        String(assessmentId || '').trim(),
    ].join(':');
}

function healthKeyFromPayload(payload = {}, parent = {}) {
    return {
        action: String(payload._health_action || '').trim(),
        section: String(payload._health_section || '').trim(),
        visitId: String(payload._health_visit_id || '').trim(),
        assessmentId: String(payload._health_assessment_id || '').trim(),
        memberNo: String(
            payload.client_local_member_id
            || parent.client_local_member_id
            || parent.member_no
            || '',
        ).trim(),
        householdNo: String(parent.household_no || payload.household_no || '').trim(),
    };
}

async function withHealthStore(mode, work) {
    const db = await openOfflineDb();
    try {
        return await withStore(db, mode, work, HP_HEALTH_STORE);
    } finally {
        db.close?.();
    }
}

export async function putHealthSnapshot(actorId, record) {
    const actor = Number(actorId);
    if (!Number.isInteger(actor) || actor <= 0) {
        throw new Error('health-actor-required');
    }
    const next = {
        ...record,
        id: record.id || healthRecordId(
            actor,
            record.member_no,
            record.health_action,
            record.health_section,
            record.health_visit_id,
            record.health_assessment_id,
        ),
        actor_id: actor,
        updated_at: record.updated_at || new Date().toISOString(),
    };
    await withHealthStore('readwrite', (store) => requestValue(store.put(next)));
    return next;
}

export async function getHealthSnapshot(actorId, id) {
    return withHealthStore('readonly', (store) => requestValue(store.get(id)));
}

export async function listHealthSnapshots(actorId, memberNo = null) {
    const actor = Number(actorId);
    const all = await withHealthStore('readonly', (store) => requestValue(store.getAll()));
    const rows = (Array.isArray(all) ? all : []).filter((row) => Number(row.actor_id) === actor);
    if (!memberNo) {
        return rows;
    }
    const needle = String(memberNo).trim().toUpperCase();
    return rows.filter((row) => String(row.member_no || '').trim().toUpperCase() === needle);
}

export function displayLabelForHealthAction(action) {
    const map = {
        child_immunization_store: 'Child Immunization',
        child_birth_history_store: 'Birth History',
        school_immunization_store: 'School-Based Immunization',
        child_nutrition_store: 'Child Nutrition',
        timbang_record_store: 'Nutritional Status / Timbang',
        deworming_store: 'Deworming',
        risk_assessment_store: 'Risk Assessment',
        risk_assessment_section_update: 'Risk Assessment',
        family_planning_store: 'Family Planning',
        family_planning_update: 'Family Planning',
        maternal_register: 'Maternal Care',
        maternal_section_update: 'Maternal Care',
    };
    return map[String(action || '')] || String(action || 'Health Record');
}

/**
 * Persist/update a local health snapshot after HEALTH_SERVICE_WRITE is queued.
 */
export async function persistHealthWriteReadModel(actorId, detail = {}) {
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const parent = detail.parent_server && typeof detail.parent_server === 'object' ? detail.parent_server : {};
    const key = healthKeyFromPayload(payload, parent);
    if (!key.action || !key.memberNo) {
        return null;
    }
    const id = healthRecordId(
        actorId,
        key.memberNo,
        key.action,
        key.section,
        key.visitId,
        key.assessmentId,
    );
    const existing = await getHealthSnapshot(actorId, id);
    const display = {
        date: payload.date || payload.measurement_date || payload.visit_date || payload.assessment_date || '',
        weight: payload.weight ?? payload.weight_kg ?? '',
        height: payload.height ?? payload.height_cm ?? '',
        muac: payload.muac ?? '',
        overall: payload.overall_nutritional_status || payload.overall_status || '',
        remarks: payload.remarks || '',
        summary: [
            payload.date || payload.measurement_date || payload.visit_date || '',
            payload.weight || payload.weight_kg || '',
            payload.height || payload.height_cm || '',
            payload.overall_nutritional_status || '',
        ].filter(Boolean).join(' · '),
    };
    return putHealthSnapshot(actorId, {
        ...(existing || {}),
        id,
        household_no: key.householdNo || existing?.household_no || '',
        member_no: key.memberNo,
        health_action: key.action,
        health_section: key.section,
        health_visit_id: key.visitId,
        health_assessment_id: key.assessmentId,
        label: displayLabelForHealthAction(key.action),
        payload: { ...payload },
        display,
        local: true,
        pending_sync: true,
        sync_attention: false,
        last_sync_error: null,
        created_at: existing?.created_at || new Date().toISOString(),
        queue_local_id: detail.local_id || existing?.queue_local_id || null,
    });
}

export async function promoteHealthSnapshotsForMember(actorId, localMemberId, serverMemberNo) {
    const localId = String(localMemberId || '').trim();
    const serverId = String(serverMemberNo || '').trim();
    if (!localId || !serverId || localId === serverId) {
        return [];
    }
    const rows = await listHealthSnapshots(actorId, localId);
    const promoted = [];
    for (const row of rows) {
        const nextId = healthRecordId(
            actorId,
            serverId,
            row.health_action,
            row.health_section,
            row.health_visit_id,
            row.health_assessment_id,
        );
        const next = {
            ...row,
            id: nextId,
            member_no: serverId,
            local: false,
            pending_sync: false,
            sync_attention: false,
            last_sync_error: null,
        };
        await putHealthSnapshot(actorId, next);
        if (row.id !== nextId) {
            await withHealthStore('readwrite', (store) => requestValue(store.delete(row.id)));
        }
        promoted.push(next);
    }
    return promoted;
}

export async function markHealthSyncAttention(actorId, detail = {}, code = null) {
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const parent = detail.parent_server && typeof detail.parent_server === 'object' ? detail.parent_server : {};
    const key = healthKeyFromPayload(payload, parent);
    if (!key.action || !key.memberNo) {
        return null;
    }
    const id = healthRecordId(
        actorId,
        key.memberNo,
        key.action,
        key.section,
        key.visitId,
        key.assessmentId,
    );
    const existing = await getHealthSnapshot(actorId, id);
    if (!existing) {
        return null;
    }
    return putHealthSnapshot(actorId, {
        ...existing,
        pending_sync: true,
        sync_attention: true,
        last_sync_error: code ? String(code) : existing.last_sync_error,
    });
}

export async function clearHealthPending(actorId, detail = {}) {
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const parent = detail.parent_server && typeof detail.parent_server === 'object' ? detail.parent_server : {};
    const key = healthKeyFromPayload(payload, parent);
    if (!key.action || !key.memberNo) {
        return null;
    }
    const id = healthRecordId(
        actorId,
        key.memberNo,
        key.action,
        key.section,
        key.visitId,
        key.assessmentId,
    );
    const existing = await getHealthSnapshot(actorId, id);
    if (!existing) {
        return null;
    }
    return putHealthSnapshot(actorId, {
        ...existing,
        local: false,
        pending_sync: false,
        sync_attention: false,
        last_sync_error: null,
    });
}

/**
 * Remove a local health snapshot for this actor (discard unsynced write).
 * @returns {Promise<boolean>}
 */
export async function deleteHealthSnapshot(actorId, idOrDetail) {
    const actor = Number(actorId);
    if (!Number.isInteger(actor) || actor <= 0) {
        return false;
    }
    let id = '';
    if (typeof idOrDetail === 'string') {
        id = idOrDetail;
    } else {
        const payload = idOrDetail?.payload && typeof idOrDetail.payload === 'object' ? idOrDetail.payload : {};
        const parent = idOrDetail?.parent_server && typeof idOrDetail.parent_server === 'object'
            ? idOrDetail.parent_server
            : {};
        const key = healthKeyFromPayload(payload, parent);
        if (!key.action || !key.memberNo) {
            return false;
        }
        id = healthRecordId(
            actor,
            key.memberNo,
            key.action,
            key.section,
            key.visitId,
            key.assessmentId,
        );
    }
    if (!id) {
        return false;
    }
    const existing = await getHealthSnapshot(actor, id);
    if (!existing || Number(existing.actor_id) !== actor) {
        return false;
    }
    await withHealthStore('readwrite', (store) => requestValue(store.delete(id)));
    return true;
}

/**
 * Delete all health snapshots for a member under this actor.
 * @returns {Promise<number>}
 */
export async function deleteHealthSnapshotsForMember(actorId, memberNo) {
    const rows = await listHealthSnapshots(actorId, memberNo);
    let removed = 0;
    for (const row of rows) {
        if (await deleteHealthSnapshot(actorId, row.id)) {
            removed += 1;
        }
    }
    return removed;
}

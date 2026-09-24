/**
 * Local Health Records continuum for supported HEALTH_SERVICE_WRITE ops.
 */

import { flattenPayloadToFormNames, OPERATION_TYPES } from './offline-forms.js';
import { readPageActorId } from './offline-nav-guard.js';
import {
    clearHealthPending,
    displayLabelForHealthAction,
    listHealthSnapshots,
    markHealthSyncAttention,
    persistHealthWriteReadModel,
    promoteHealthSnapshotsForMember,
} from './offline-health-store.js';

function memberNoFromDetail(detail) {
    const payload = detail?.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const parent = detail?.parent_server && typeof detail.parent_server === 'object' ? detail.parent_server : {};
    return String(
        payload.client_local_member_id
        || parent.client_local_member_id
        || parent.member_no
        || '',
    ).trim();
}

export async function handleHealthServiceQueued(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.HEALTH_SERVICE_WRITE) {
        return { ok: false };
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false, reason: 'actor' };
    }
    const snapshot = await persistHealthWriteReadModel(actorId, detail);
    if (!snapshot) {
        return { ok: false, reason: 'snapshot' };
    }
    if (options.document || typeof document !== 'undefined') {
        try {
            await mergeLocalHealthIntoDocument(options.document || document, {
                actorId,
                memberNo: snapshot.member_no,
            });
        } catch {
            // DOM merge is best-effort.
        }
    }
    return { ok: true, snapshot };
}

export async function reconcileHealthServiceSuccess(row, options = {}) {
    if (!row || row.operation_type !== 'HEALTH_SERVICE_WRITE') {
        return false;
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    await clearHealthPending(actorId, row);
    return true;
}

export async function markHealthServiceAttention(row, options = {}) {
    if (!row || row.operation_type !== 'HEALTH_SERVICE_WRITE') {
        return false;
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    await markHealthSyncAttention(actorId, row, row.last_safe_error_code || options.code || null);
    return true;
}

export async function promoteHealthAfterMemberSync(localMemberId, serverMemberNo, options = {}) {
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return [];
    }
    return promoteHealthSnapshotsForMember(actorId, localMemberId, serverMemberNo);
}

export async function mergeLocalHealthIntoDocument(doc, options = {}) {
    if (!doc?.querySelector) {
        return { merged: 0 };
    }
    const actorId = Number(options.actorId || readPageActorId(doc) || 0);
    const host = doc.querySelector('[data-lml-hh-member-view], [data-lml-hh-timbang], [data-child-nut], [data-child-imm], [data-lml-sbi], [data-lml-mc], [data-lml-risk-assess], [data-lml-fp]');
    const memberNo = String(
        options.memberNo
        || host?.getAttribute?.('data-member-id')
        || doc.querySelector?.('[data-offline-parent-member-no]')?.getAttribute?.('data-offline-parent-member-no')
        || '',
    ).trim();
    if (!actorId || !memberNo) {
        return { merged: 0 };
    }
    const rows = (await listHealthSnapshots(actorId, memberNo)).filter((row) => row.pending_sync || row.local);
    if (!rows.length) {
        return { merged: 0 };
    }

    let list = doc.querySelector('[data-lml-local-health-list]');
    if (!list && host) {
        list = doc.createElement('section');
        list.setAttribute('data-lml-local-health-list', '1');
        list.setAttribute('class', 'lml-hh-view__local-health');
        const title = doc.createElement('h3');
        title.textContent = 'Saved on this device';
        list.appendChild(title);
        const ul = doc.createElement('ul');
        ul.setAttribute('data-lml-local-health-items', '1');
        list.appendChild(ul);
        host.appendChild(list);
    }
    const items = list?.querySelector?.('[data-lml-local-health-items]') || list;
    if (!items) {
        return { merged: 0 };
    }
    while (items.firstChild) {
        items.removeChild(items.firstChild);
    }
    rows.forEach((row) => {
        const li = doc.createElement('li');
        li.setAttribute('data-local-health-id', row.id);
        const status = row.sync_attention ? 'Needs attention' : 'Waiting to sync';
        li.innerHTML = '';
        const strong = doc.createElement('strong');
        strong.textContent = row.label || displayLabelForHealthAction(row.health_action);
        li.appendChild(strong);
        const meta = doc.createElement('span');
        meta.textContent = ` — ${row.display?.summary || row.display?.date || 'Saved locally'} · ${status}`;
        li.appendChild(meta);
        items.appendChild(li);
    });

    // Prefer filling the open timbang/nutrition form with the latest local payload.
    // Supports nested payloads and legacy flat "newborn[length]" keys.
    const latest = rows[rows.length - 1];
    const form = doc.querySelector('form[data-offline-operation="HEALTH_SERVICE_WRITE"]');
    if (form && latest?.payload) {
        const flatNames = flattenPayloadToFormNames(latest.payload);
        Object.entries(flatNames).forEach(([name, value]) => {
            if (String(name).startsWith('_')) {
                return;
            }
            const escaped = String(name).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            const field = form.querySelector(`[name="${escaped}"]`);
            if (!field || field.type === 'checkbox' || field.type === 'radio') {
                return;
            }
            if (value != null && String(value) !== '') {
                field.value = String(value);
            }
        });
        let badge = form.querySelector('.lml-hh-view__sync-badge, [data-lml-offline-form-pending]');
        if (!badge) {
            badge = doc.createElement('p');
            badge.setAttribute('class', 'lml-hh-view__sync-badge');
            form.prepend(badge);
        }
        badge.hidden = false;
        badge.textContent = latest.sync_attention ? 'Needs attention' : 'Waiting to sync';
    }

    return { merged: rows.length };
}

export function bootLocalHealthMerge() {
    if (typeof document === 'undefined') {
        return;
    }
    void mergeLocalHealthIntoDocument(document);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootLocalHealthMerge);
    } else {
        bootLocalHealthMerge();
    }
}

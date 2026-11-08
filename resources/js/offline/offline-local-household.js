/**
 * Information-first offline Household Continuum — hp_households read model
 * for HOUSEHOLD_CREATE (not Spot Mapping plot).
 */

import { OPERATION_TYPES } from './offline-forms.js';
import { readPageActorId } from './offline-nav-guard.js';
import { publicPath } from './offline-url-keys.js';
import {
    applyHouseholdPayloadToSnapshot,
    getHouseholdSnapshot,
    listHouseholdSnapshots,
    listMemberSnapshots,
    markHouseholdSyncAttention,
    persistHouseholdCreateReadModel,
    promoteLocalHouseholdIdentity,
    putMeta,
} from './offline-hp-store.js';
import { cacheHydratedHouseholdPages } from './offline-hp-hydrate.js';
import {
    cacheEhWizardPages,
    ehStep1Url,
    hasCanonicalEhShells,
    prepareEnvironmentalHealthShells,
} from './offline-eh-hydrate.js';
import { sameHouseholdNo, applyHouseholdIdentityToDependents } from './offline-queue.js';

/** Mirrors EnvironmentalHealthReturnContext::SESSION_KEY for offline Step 4 → HH Information. */
export const EH_RETURN_TO_HOUSEHOLD_META = 'eh:return_to_household';

function householdNoFromDetail(detail) {
    const payload = detail?.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const parent = detail?.parent_server && typeof detail.parent_server === 'object' ? detail.parent_server : {};
    return String(
        payload.household_no
        || parent.household_no
        || detail?.identities?.household_no
        || detail?.body?.household?.household_no
        || '',
    ).trim();
}

async function recacheHousehold(actorId, householdNo, options = {}) {
    const household = await getHouseholdSnapshot(actorId, householdNo);
    if (!household) {
        return false;
    }
    const members = await listMemberSnapshots(actorId, householdNo);
    try {
        await cacheHydratedHouseholdPages(actorId, household, members, options);
        return true;
    } catch {
        return false;
    }
}

/**
 * After HOUSEHOLD_CREATE is queued: persist hp_households, then continue into
 * Environmental Health Step 1 — same user journey as online store() redirect
 * (HouseholdProfilingController → environmental-health.household-water-supply).
 * Does NOT open /household-profiling/{no} (raw/uncached server view).
 */
export async function handleHouseholdCreateQueued(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.HOUSEHOLD_CREATE) {
        return { ok: false };
    }
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const householdNo = householdNoFromDetail(detail);
    if (!householdNo) {
        return { ok: false, reason: 'household-no' };
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false, reason: 'actor' };
    }

    const snapshot = await persistHouseholdCreateReadModel(actorId, {
        ...payload,
        household_no: householdNo,
    });

    // Online: EnvironmentalHealthReturnContext::rememberForHouseholdProfilingCreate
    // so EH Step 4 returns to Household Information (not Spot Mapping).
    try {
        await putMeta(actorId, EH_RETURN_TO_HOUSEHOLD_META, householdNo);
    } catch {
        // Meta is best-effort; nextEhUrl already targets Household Information.
    }

    let ehCached = false;
    try {
        if (!options.shells && !(await hasCanonicalEhShells(actorId))) {
            await prepareEnvironmentalHealthShells(options);
        }
        ehCached = await cacheEhWizardPages(actorId, snapshot, options);
        if (!ehCached && options.navigate !== false && options.requireEhShell !== false) {
            await recacheHousehold(actorId, householdNo, options);
            return { ok: false, reason: 'eh-shell-missing', householdNo, snapshot };
        }
    } catch {
        if (options.navigate !== false && options.requireEhShell !== false) {
            await recacheHousehold(actorId, householdNo, options);
            return { ok: false, reason: 'eh-shell-missing', householdNo, snapshot };
        }
    }

    // Prefetch Household Information shells for EH Step 4 completion.
    await recacheHousehold(actorId, householdNo, options);

    const path = ehStep1Url(householdNo);
    if (options.navigate === false) {
        return { ok: true, path, householdNo, snapshot, ehCached };
    }
    const assign = options.assign || ((url) => {
        const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
        win.location?.assign?.(url);
    });
    assign(path);
    return { ok: true, path, householdNo, snapshot, ehCached };
}

/**
 * After HOUSEHOLD_UPDATE coalesced into create (or standalone): refresh snapshot.
 */
export async function handleHouseholdUpdateQueued(detail, options = {}) {
    if (
        detail?.operation_type !== OPERATION_TYPES.HOUSEHOLD_UPDATE
        && detail?.operation_type !== OPERATION_TYPES.HOUSEHOLD_CREATE
    ) {
        return { ok: false };
    }
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const householdNo = householdNoFromDetail(detail);
    if (!householdNo) {
        return { ok: false };
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { ok: false };
    }

    await applyHouseholdPayloadToSnapshot(actorId, householdNo, {
        ...payload,
        household_no: householdNo,
    });
    await recacheHousehold(actorId, householdNo, options);

    const path = publicPath(`/household-profiling/${householdNo}`);
    if (options.navigate === false) {
        return { ok: true, path, householdNo };
    }
    if (detail.operation_type === OPERATION_TYPES.HOUSEHOLD_UPDATE) {
        const assign = options.assign || ((url) => {
            const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
            win.location?.assign?.(url);
        });
        assign(path);
    }
    return { ok: true, path, householdNo };
}

export async function reconcileHouseholdCreateSuccess(row, options = {}) {
    if (!row || row.operation_type !== 'HOUSEHOLD_CREATE') {
        return false;
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    const householdNo = String(
        row.identities?.household_no
        || row.body?.household?.household_no
        || row.payload?.household_no
        || '',
    ).trim();
    if (!householdNo || !Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    const pk = Number(row.identities?.household_pk || row.body?.household?.id || 0);
    await promoteLocalHouseholdIdentity(
        actorId,
        householdNo,
        Number.isInteger(pk) && pk > 0 ? pk : null,
    );
    if (Number.isInteger(pk) && pk > 0) {
        try {
            await applyHouseholdIdentityToDependents(actorId, householdNo, pk);
        } catch {
            // Queue rebind is best-effort; replay path also applies it.
        }
    }
    await recacheHousehold(actorId, householdNo, options);
    return true;
}

export async function markHouseholdCreateAttention(row, options = {}) {
    if (!row || row.operation_type !== 'HOUSEHOLD_CREATE') {
        return false;
    }
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = Number(options.actorId || readPageActorId(options.root || doc) || 0);
    const householdNo = householdNoFromDetail(row);
    if (!householdNo || !Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    await markHouseholdSyncAttention(actorId, householdNo, row.last_safe_error_code || options.code || null);
    return true;
}

export async function isLocalPendingHousehold(actorId, householdNo) {
    const snap = await getHouseholdSnapshot(actorId, householdNo);
    return Boolean(snap && (snap.local || snap.pending_sync));
}

/**
 * Pending information-first households for the current actor (list merge).
 */
export async function listPendingLocalHouseholds(actorId) {
    const actor = Number(actorId);
    if (!Number.isInteger(actor) || actor <= 0) {
        return [];
    }
    const rows = await listHouseholdSnapshots(actor);
    return rows.filter((row) => Boolean(row.local || row.pending_sync));
}

function displayNo(householdNo) {
    return String(householdNo || '').replace(/^HH-/i, '');
}

function escapeAttr(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
}

function escapeText(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Build a profiling-list row for a local pending household (HTML string for tests).
 */
export function localPendingHouseholdRowHtml(household) {
    const hh = String(household.household_no || '').trim();
    const shown = escapeText(household.display_no || displayNo(hh));
    const head = escapeText(household.house_head || '—');
    const zone = escapeText(household.zone || '—');
    const members = Number(household.member_count || 0);
    const status = household.sync_attention
        ? 'Needs attention'
        : 'Waiting to sync';
    const hhAttr = escapeAttr(hh);
    return `<tr
        data-hh-row
        data-offline-local-household="1"
        data-id=""
        data-household-no="${hhAttr}"
        data-house-head="${escapeAttr(household.house_head || '—')}"
        data-zone="${escapeAttr(household.zone || '')}"
        data-street="${escapeAttr(household.street || '')}"
        data-members="${members}"
        data-male="0"
        data-female="0"
        data-source="local"
        data-pending-sync="1"
    >
        <td data-label="HH No.">
            <span class="lml-hh-profiling__hh-no">${shown}</span>
            <span class="lml-hh-profiling__sync-badge">${escapeText(status)}</span>
        </td>
        <td data-label="HH Head">${head}</td>
        <td data-label="Zone">${zone}</td>
        <td data-label="No. of Members">${members}</td>
        <td data-label="Actions">
            <div class="lml-hh-profiling__actions" role="group" aria-label="Actions for ${hhAttr}">
                <a
                    href="${publicPath(`/household-profiling/${encodeURIComponent(hh)}`)}"
                    class="lml-hh-profiling__action-btn lml-hh-profiling__action-btn--view lml-focus-ring"
                    data-hh-view
                    data-hh-nav="view-household"
                    aria-label="View ${hhAttr}"
                >
                    <i class="bi bi-eye-fill" aria-hidden="true"></i>
                    <span>View</span>
                </a>
            </div>
        </td>
    </tr>`;
}

export function buildLocalPendingHouseholdRow(doc, household) {
    if (!doc?.createElement) {
        return null;
    }
    const hh = String(household.household_no || '').trim();
    if (!hh) {
        return null;
    }
    const status = household.sync_attention ? 'Needs attention' : 'Waiting to sync';
    const row = doc.createElement('tr');
    row.setAttribute('data-hh-row', '');
    row.setAttribute('data-offline-local-household', '1');
    row.setAttribute('data-id', '');
    row.setAttribute('data-household-no', hh);
    row.setAttribute('data-house-head', household.house_head || '—');
    row.setAttribute('data-zone', household.zone || '');
    row.setAttribute('data-street', household.street || '');
    row.setAttribute('data-members', String(Number(household.member_count || 0)));
    row.setAttribute('data-male', '0');
    row.setAttribute('data-female', '0');
    row.setAttribute('data-source', 'local');
    row.setAttribute('data-pending-sync', '1');

    const tdNo = doc.createElement('td');
    tdNo.setAttribute('data-label', 'HH No.');
    const noSpan = doc.createElement('span');
    noSpan.setAttribute('class', 'lml-hh-profiling__hh-no');
    noSpan.textContent = household.display_no || displayNo(hh);
    const badge = doc.createElement('span');
    badge.setAttribute('class', 'lml-hh-profiling__sync-badge');
    badge.textContent = status;
    tdNo.appendChild(noSpan);
    tdNo.appendChild(badge);

    const tdHead = doc.createElement('td');
    tdHead.setAttribute('data-label', 'HH Head');
    tdHead.textContent = household.house_head || '—';

    const tdZone = doc.createElement('td');
    tdZone.setAttribute('data-label', 'Zone');
    tdZone.textContent = household.zone || '—';

    const tdMembers = doc.createElement('td');
    tdMembers.setAttribute('data-label', 'No. of Members');
    tdMembers.textContent = String(Number(household.member_count || 0));

    const tdActions = doc.createElement('td');
    tdActions.setAttribute('data-label', 'Actions');
    const actions = doc.createElement('div');
    actions.setAttribute('class', 'lml-hh-profiling__actions');
    actions.setAttribute('role', 'group');
    actions.setAttribute('aria-label', `Actions for ${hh}`);
    const view = doc.createElement('a');
    view.setAttribute('href', publicPath(`/household-profiling/${encodeURIComponent(hh)}`));
    view.setAttribute('class', 'lml-hh-profiling__action-btn lml-hh-profiling__action-btn--view lml-focus-ring');
    view.setAttribute('data-hh-view', '');
    view.setAttribute('data-hh-nav', 'view-household');
    view.setAttribute('aria-label', `View ${hh}`);
    const icon = doc.createElement('i');
    icon.setAttribute('class', 'bi bi-eye-fill');
    icon.setAttribute('aria-hidden', 'true');
    const label = doc.createElement('span');
    label.textContent = 'View';
    view.appendChild(icon);
    view.appendChild(label);
    actions.appendChild(view);
    tdActions.appendChild(actions);

    row.appendChild(tdNo);
    row.appendChild(tdHead);
    row.appendChild(tdZone);
    row.appendChild(tdMembers);
    row.appendChild(tdActions);
    return row;
}

/**
 * Merge actor-scoped pending local households into the profiling list tbody.
 * Skips rows already present (same household_no) so reconciled server rows
 * are not duplicated.
 */
export async function mergePendingLocalHouseholdsIntoList(root, options = {}) {
    if (!root || typeof root.querySelector !== 'function') {
        return { merged: 0 };
    }
    const tbody = root.querySelector('[data-hh-tbody]');
    if (!tbody) {
        return { merged: 0 };
    }
    const actorId = Number(
        options.actorId
        || readPageActorId(options.root || root)
        || 0,
    );
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { merged: 0 };
    }

    const pending = await listPendingLocalHouseholds(actorId);
    if (!pending.length) {
        return { merged: 0 };
    }

    const existing = Array.from(tbody.querySelectorAll('[data-hh-row]'));
    const existingNos = existing.map((row) => (
        row.getAttribute?.('data-household-no') || ''
    ));

    let merged = 0;
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    for (const household of pending) {
        const hh = String(household.household_no || '').trim();
        if (!hh) {
            continue;
        }
        if (existingNos.some((no) => sameHouseholdNo(no, hh))) {
            const match = existing.find((row) => sameHouseholdNo(
                row.getAttribute?.('data-household-no'),
                hh,
            ));
            if (match?.getAttribute?.('data-offline-local-household') === '1') {
                match.setAttribute('data-zone', household.zone || '');
                match.setAttribute('data-house-head', household.house_head || '—');
                match.setAttribute('data-street', household.street || '');
                const zoneCell = match.querySelector?.('[data-label="Zone"]');
                if (zoneCell) {
                    zoneCell.textContent = household.zone || '—';
                }
                const headCell = match.querySelector?.('[data-label="HH Head"]');
                if (headCell) {
                    headCell.textContent = household.house_head || '—';
                }
            }
            continue;
        }
        const row = buildLocalPendingHouseholdRow(doc, household);
        if (row && typeof tbody.appendChild === 'function') {
            tbody.appendChild(row);
            existingNos.push(hh);
            merged += 1;
        }
    }

    if (merged > 0) {
        const prev = Number(root.dataset?.total || root.getAttribute?.('data-total') || 0);
        const next = prev + merged;
        if (root.dataset) {
            root.dataset.total = String(next);
        }
        root.setAttribute?.('data-total', String(next));
    }

    return { merged, pending: pending.length };
}

/**
 * Merge actor-scoped pending local households into the EH dashboard list.
 */
export async function mergePendingLocalHouseholdsIntoEhList(root, options = {}) {
    if (!root || typeof root.querySelector !== 'function') {
        return { merged: 0 };
    }
    const tbody = root.querySelector('[data-eh-tbody]');
    if (!tbody) {
        return { merged: 0 };
    }
    const actorId = Number(
        options.actorId
        || readPageActorId(options.root || root)
        || 0,
    );
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return { merged: 0 };
    }

    const pending = await listPendingLocalHouseholds(actorId);
    if (!pending.length) {
        return { merged: 0 };
    }

    const existing = Array.from(tbody.querySelectorAll('[data-eh-row]'));
    const existingNos = existing.map((row) => row.getAttribute?.('data-household-no') || '');
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    let merged = 0;

    for (const household of pending) {
        const hh = String(household.household_no || '').trim();
        if (!hh) {
            continue;
        }
        if (existingNos.some((no) => sameHouseholdNo(no, hh))) {
            continue;
        }
        const row = buildLocalEhDashboardRow(doc, household);
        if (row && typeof tbody.appendChild === 'function') {
            tbody.appendChild(row);
            existingNos.push(hh);
            merged += 1;
        }
    }

    if (merged > 0) {
        const prev = Number(root.dataset?.total || root.getAttribute?.('data-total') || 0);
        const next = prev + merged;
        if (root.dataset) {
            root.dataset.total = String(next);
        }
        root.setAttribute?.('data-total', String(next));
    }
    return { merged, pending: pending.length };
}

function buildLocalEhDashboardRow(doc, household) {
    if (!doc?.createElement) {
        return null;
    }
    const hh = String(household.household_no || '').trim();
    const water = household.water || {};
    const sanitation = household.sanitation || {};
    const row = doc.createElement('tr');
    row.setAttribute('data-eh-row', '');
    row.setAttribute('data-offline-local-household', '1');
    row.setAttribute('data-household-no', hh);
    row.setAttribute('data-house-head', household.house_head || '—');
    row.setAttribute('data-zone', household.zone || '');
    row.setAttribute('data-street', household.street || '');
    row.setAttribute('data-water-supply', water.status || '');
    row.setAttribute('data-toilet-status', 'not_yet_determined');
    row.setAttribute('data-toilet-presence', 'unknown');
    row.setAttribute('data-sanitation', 'unknown');
    row.setAttribute('data-record-status', household.sync_attention ? 'attention' : 'pending');
    row.setAttribute('data-solid-waste', '');

    const tdNo = doc.createElement('td');
    tdNo.setAttribute('data-label', 'HH No.');
    const noSpan = doc.createElement('span');
    noSpan.setAttribute('class', 'lml-eh-dashboard__hh-no');
    noSpan.textContent = household.display_no || hh.replace(/^HH-/i, '');
    const badge = doc.createElement('span');
    badge.setAttribute('class', 'lml-hh-profiling__sync-badge');
    badge.textContent = household.sync_attention ? 'Needs attention' : 'Waiting to sync';
    tdNo.appendChild(noSpan);
    tdNo.appendChild(badge);

    const tdHead = doc.createElement('td');
    tdHead.setAttribute('data-label', 'HH Head');
    tdHead.textContent = household.house_head || '—';

    const tdWater = doc.createElement('td');
    tdWater.setAttribute('data-label', 'Water Supply Level');
    const level = doc.createElement('span');
    level.setAttribute('class', 'lml-eh-dashboard__level');
    level.textContent = water.level || water.status || '—';
    tdWater.appendChild(level);

    const tdSan = doc.createElement('td');
    tdSan.setAttribute('data-label', 'Sanitation Services');
    const dash = doc.createElement('span');
    dash.setAttribute('class', 'lml-eh-dashboard__dash');
    dash.textContent = sanitation.facility || sanitation.status || '—';
    tdSan.appendChild(dash);

    const tdActions = doc.createElement('td');
    tdActions.setAttribute('data-label', 'Actions');
    const actions = doc.createElement('div');
    actions.setAttribute('class', 'lml-eh-dashboard__actions');
    actions.setAttribute('role', 'group');
    const link = doc.createElement('a');
    link.setAttribute('href', publicPath(`/environmental-health/household-water-supply?household=${encodeURIComponent(hh)}`));
    link.setAttribute('class', 'lml-eh-dashboard__action-btn lml-eh-dashboard__action-btn--view lml-focus-ring');
    link.setAttribute('data-hh-nav', 'eh-household');
    link.setAttribute('data-offline-nav', 'eh-household');
    const label = doc.createElement('span');
    label.textContent = 'Open';
    link.appendChild(label);
    actions.appendChild(link);
    tdActions.appendChild(actions);

    row.appendChild(tdNo);
    row.appendChild(tdHead);
    row.appendChild(tdWater);
    row.appendChild(tdSan);
    row.appendChild(tdActions);
    return row;
}

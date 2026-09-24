/**
 * Merge IndexedDB Household Profiling snapshots into the current document.
 * Keeps household-view.js free of IndexedDB while still showing local writes.
 *
 * Persisted residents (MB-{digits}) keep server-rendered HTML while online.
 * Local queued members (MB-L-*) always hydrate from IndexedDB.
 */

import { isClientOffline } from './offline-forms.js';
import { readPageActorId } from './offline-nav-guard.js';
import { getHouseholdSnapshot, getMemberSnapshot, membersForHousehold } from './offline-hp-store.js';
import { formRelationValue, hydrateHouseholdViewHtml, householdMetaHtml, isoMemberBirthday } from './offline-hp-hydrate.js';
import { fillLocalMemberViewRoot } from './offline-local-member.js';
import { readMemberIdentityMap } from './offline-identity-map.js';

const PERSISTED_MEMBER_ID = /^MB-\d+$/i;
const LOCAL_MEMBER_ID = /^MB-L-[A-Za-z0-9]+$/i;

function isPersistedMemberId(memberId) {
    return PERSISTED_MEMBER_ID.test(String(memberId || '').trim());
}

function isLocalMemberId(memberId) {
    return LOCAL_MEMBER_ID.test(String(memberId || '').trim());
}

/**
 * Online DB residents must not be overwritten by stale snapshots.
 * Local MB-L-* members (and offline persisted shells) still hydrate.
 */
function shouldApplyMemberSnapshotHydration(memberId, options = {}) {
    if (isLocalMemberId(memberId)) {
        return true;
    }
    if (isPersistedMemberId(memberId) && !isClientOffline(options)) {
        return false;
    }
    return true;
}

async function actorAndHousehold(root) {
    const actorId = readPageActorId(root);
    const householdNo = String(root?.getAttribute?.('data-household-no') || '').trim();
    if (!actorId || !householdNo) {
        return null;
    }
    return { actorId, householdNo };
}

export async function applyHouseholdViewSnapshots(root, options = {}) {
    const host = root || (typeof document !== 'undefined' ? document.querySelector('[data-lml-hh-view]') : null);
    const ctx = await actorAndHousehold(host);
    if (!ctx || !host) {
        return false;
    }
    const household = await getHouseholdSnapshot(ctx.actorId, ctx.householdNo);
    if (!household) {
        return false;
    }
    const members = await membersForHousehold(ctx.actorId, ctx.householdNo);
    const section = host.querySelector('.lml-hh-view__members');
    if (!section) {
        return false;
    }
    const html = hydrateHouseholdViewHtml(
        '<section class="lml-hh-view__members">x</section>',
        household,
        members,
        ctx.householdNo,
    );
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const holder = doc?.createElement?.('div');
    if (!holder) {
        return false;
    }
    holder.innerHTML = html;
    const next = holder.querySelector('.lml-hh-view__members');
    if (!next) {
        return false;
    }
    section.replaceWith(next);
    const badge = host.querySelector('.lml-hh-view__members-badge span:last-child');
    if (badge) {
        badge.textContent = `${members.length} members`;
    }
    const headName = host.querySelector('.lml-hh-view__head-name');
    if (headName) {
        headName.textContent = household.house_head || '—';
    }
    const hhNo = host.querySelector('.lml-hh-view__hh-no');
    if (hhNo) {
        hhNo.textContent = household.display_no || household.household_no || '';
    }
    if (household.local || household.pending_sync) {
        host.setAttribute('data-offline-local-household', '1');
        let syncBadge = host.querySelector('.lml-hh-view__sync-badge');
        if (!syncBadge && hhNo?.insertAdjacentHTML) {
            hhNo.insertAdjacentHTML(
                'afterend',
                `<p class="lml-hh-view__sync-badge">${household.sync_attention ? 'Needs attention' : 'Waiting to sync'}</p>`,
            );
            syncBadge = host.querySelector('.lml-hh-view__sync-badge');
        }
        if (syncBadge) {
            syncBadge.textContent = household.sync_attention ? 'Needs attention' : 'Waiting to sync';
        }
    }
    const zoneNode = host.querySelector('[data-hh-view-zone]');
    if (zoneNode) {
        zoneNode.textContent = household.zone || '—';
    }
    const meta = host.querySelector('dl.lml-hh-view__meta');
    if (meta) {
        meta.outerHTML = householdMetaHtml(household);
    }
    const water = household.water || {};
    const sanitation = household.sanitation || {};
    const waterDetail = host.querySelector('.lml-hh-view__amenity--water .lml-hh-view__amenity-detail');
    if (waterDetail) {
        waterDetail.textContent = water.level || '—';
    }
    const waterBadge = host.querySelector('.lml-hh-view__amenity--water .lml-hh-view__amenity-badge');
    if (waterBadge) {
        waterBadge.textContent = water.status || 'Not recorded';
    }
    const sanitationDetail = host.querySelector('.lml-hh-view__amenity--sanitation .lml-hh-view__amenity-detail');
    if (sanitationDetail) {
        sanitationDetail.textContent = sanitation.facility || '—';
    }
    const sanitationBadge = host.querySelector('.lml-hh-view__amenity--sanitation .lml-hh-view__amenity-badge');
    if (sanitationBadge) {
        sanitationBadge.textContent = sanitation.status || 'Not recorded';
    }
    return true;
}

export async function applyMemberViewSnapshots(root, options = {}) {
    const host = root || (typeof document !== 'undefined' ? document.querySelector('[data-lml-hh-member-view]') : null);
    const ctx = await actorAndHousehold(host);
    const memberId = String(host?.getAttribute?.('data-member-id') || '').trim();
    if (!ctx || !host || !memberId) {
        return false;
    }
    const offlineRoot = options.root
        || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);
    if (!shouldApplyMemberSnapshotHydration(memberId, { ...options, root: offlineRoot })) {
        return false;
    }
    const member = await resolveMemberForEdit(ctx.actorId, ctx.householdNo, memberId);
    if (!member) {
        return false;
    }
    fillLocalMemberViewRoot(host, member, ctx.householdNo, memberId);
    return true;
}

function placeholderValue(value) {
    const text = String(value ?? '').trim();
    return text === '' || text === '—' ? '' : text;
}

function namesFromMember(member) {
    let first = placeholderValue(member?.first_name);
    let middle = placeholderValue(member?.middle_name);
    let last = placeholderValue(member?.last_name);
    if (!first && !last) {
        const parts = placeholderValue(member?.name).split(/\s+/).filter(Boolean);
        first = parts[0] || '';
        last = parts.slice(1).join(' ');
    }
    return { first, middle, last };
}

function setControlValue(host, name, value) {
    const field = host.querySelector(`[name="${name}"]`);
    if (!field) {
        return;
    }
    const type = String(field.type || '').toLowerCase();
    if (type === 'checkbox' || type === 'radio') {
        return;
    }
    const next = placeholderValue(value);
    if (type === 'date') {
        field.value = isoMemberBirthday(next) || next;
        return;
    }
    field.value = next;
}

function setCheckboxGroup(host, name, values) {
    const wanted = new Set((Array.isArray(values) ? values : []).map((item) => String(item)));
    const boxes = Array.from(host.querySelectorAll?.('input') || []).filter((input) => (
        String(input.getAttribute?.('name') || input.name || '') === `${name}[]`
    ));
    if (!boxes.length) {
        return;
    }
    boxes.forEach((input) => {
        input.checked = wanted.has(String(input.value || input.getAttribute?.('value') || ''));
    });
}

export function fillMemberEditRoot(host, member) {
    if (!host || !member) {
        return false;
    }
    const names = namesFromMember(member);
    setControlValue(host, 'last_name', names.last);
    setControlValue(host, 'first_name', names.first);
    setControlValue(host, 'middle_name', names.middle);
    setControlValue(host, 'birthday', member.birthday);
    setControlValue(host, 'relation', formRelationValue(member.relation || member.relationship));
    setControlValue(host, 'sex', member.sex);
    setControlValue(host, 'relationship_status', member.relationship_status);
    setControlValue(host, 'occupation', member.occupation_select || member.occupation);
    setControlValue(host, 'occupation_other', member.occupation_other);
    setControlValue(host, 'religion', member.religion_select || member.religion);
    setControlValue(host, 'religion_other', member.religion_other);
    setControlValue(host, 'education', member.education);
    setControlValue(host, 'monthly_income', member.monthly_income);
    setControlValue(host, 'fp_user', member.fp_user);
    setControlValue(host, 'philhealth', member.philhealth || member.philhealth_number);
    setCheckboxGroup(host, 'disability', member.disability);
    setControlValue(host, 'disability_others', member.disability_others);
    setCheckboxGroup(host, 'medical_history', member.medical_history);
    setControlValue(host, 'medical_others', member.medical_others);

    const name = [names.first, names.middle, names.last]
        .filter(Boolean)
        .join(' ') || placeholderValue(member.name);
    if (name) {
        host.setAttribute('data-member-name', name);
        host.querySelectorAll('[data-offline-local-field="name"]').forEach((node) => {
            node.textContent = name;
        });
    }
    if (typeof host.dispatchEvent === 'function') {
        const event = typeof CustomEvent === 'function'
            ? new CustomEvent('lmlinga:member-edit-hydrated', { bubbles: true })
            : { type: 'lmlinga:member-edit-hydrated', bubbles: true, target: host };
        host.dispatchEvent(event);
    }
    return true;
}

async function resolveMemberForEdit(actorId, householdNo, memberId, options = {}) {
    const found = await getMemberSnapshot(actorId, householdNo, memberId);
    if (found) {
        return found;
    }
    const map = options.identityMap || await readMemberIdentityMap({
        actorId,
        caches: options.caches,
        origin: options.origin,
    });
    const mapped = map?.[memberId];
    const mappedNo = String(mapped?.member_no || '').trim();
    const mappedHh = String(mapped?.household_no || householdNo).trim();
    if (mappedNo && mappedNo !== memberId) {
        return getMemberSnapshot(actorId, mappedHh, mappedNo);
    }
    return null;
}

export async function applyMemberEditSnapshots(root, options = {}) {
    const host = root || (typeof document !== 'undefined' ? document.querySelector('[data-lml-hh-member-form][data-mode="edit"]') : null);
    const ctx = await actorAndHousehold(host);
    const memberId = String(
        host?.getAttribute?.('data-member-id')
        || host?.querySelector?.('[data-offline-parent-member-no]')?.getAttribute?.('data-offline-parent-member-no')
        || '',
    ).trim();
    if (!ctx || !host || !memberId) {
        return false;
    }
    const offlineRoot = options.root
        || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);
    if (!shouldApplyMemberSnapshotHydration(memberId, { ...options, root: offlineRoot })) {
        return false;
    }
    const member = await resolveMemberForEdit(ctx.actorId, ctx.householdNo, memberId, options);
    if (!member) {
        return false;
    }
    return fillMemberEditRoot(host, member);
}

function zoneSelectValue(household) {
    return String(household?.purok || household?.zone || '')
        .replace(/^Zone\s+/i, '')
        .trim();
}

export function fillHouseholdEditRoot(host, household) {
    if (!host || !household) {
        return false;
    }
    if (household.local || household.pending_sync) {
        host.setAttribute('data-offline-local-household', '1');
        const form = host.matches?.('form') ? host : host.querySelector?.('form[data-offline-operation]');
        form?.setAttribute?.('data-offline-local-household', '1');
        form?.setAttribute?.('data-offline-parent-household-no', household.household_no || '');
    }
    setControlValue(host, 'zone', zoneSelectValue(household));
    setControlValue(host, 'street', household.street);
    setControlValue(host, 'address', household.address);
    setControlValue(host, 'date_registered', household.date_registered);
    setControlValue(host, 'latitude', household.latitude);
    setControlValue(host, 'longitude', household.longitude);
    setControlValue(host, 'household_type', household.household_type);
    const badge = host.querySelector?.('.lml-hh-view__sync-badge');
    if (badge && (household.local || household.pending_sync)) {
        badge.textContent = household.sync_attention ? 'Needs attention' : 'Waiting to sync';
    }
    return true;
}

export async function applyHouseholdEditSnapshots(root, options = {}) {
    const host = root || (typeof document !== 'undefined'
        ? document.querySelector('[data-lml-hh-shell-form][data-mode="edit"], form[data-offline-operation="HOUSEHOLD_UPDATE"]')
        : null);
    if (!host) {
        return false;
    }
    const actorId = readPageActorId(
        options.root
        || host.closest?.('[data-lml-offline-root]')
        || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null),
    );
    const householdNo = String(
        host.getAttribute?.('data-household-no')
        || host.getAttribute?.('data-offline-parent-household-no')
        || host.querySelector?.('[data-offline-parent-household-no]')?.getAttribute?.('data-offline-parent-household-no')
        || '',
    ).trim();
    if (!actorId || !householdNo) {
        return false;
    }
    const household = await getHouseholdSnapshot(actorId, householdNo);
    if (!household || !(household.local || household.pending_sync)) {
        return false;
    }
    return fillHouseholdEditRoot(host, household);
}

function boot() {
    if (typeof document === 'undefined') {
        return;
    }
    const view = document.querySelector('[data-lml-hh-view]');
    if (view) {
        void applyHouseholdViewSnapshots(view);
    }
    const memberView = document.querySelector('[data-lml-hh-member-view]');
    if (memberView) {
        void applyMemberViewSnapshots(memberView);
    }
    const memberEdit = document.querySelector('[data-lml-hh-member-form][data-mode="edit"]');
    if (memberEdit) {
        void applyMemberEditSnapshots(memberEdit);
    }
    const householdEdit = document.querySelector(
        '[data-lml-hh-shell-form][data-mode="edit"], form[data-offline-operation="HOUSEHOLD_UPDATE"][data-offline-local-household="1"]',
    );
    if (householdEdit) {
        void applyHouseholdEditSnapshots(householdEdit);
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}

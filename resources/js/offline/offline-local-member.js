/**
 * Cache a View Member page for a locally queued resident and hydrate it offline.
 */

import {
    htmlCacheNameForActor,
    isLocalMemberViewPath,
    navigationCacheUrl,
    sanitizeCachedHtml,
} from './offline-sw-policy.js';
import { OPERATION_TYPES } from './offline-forms.js';
import { rememberLocalMember } from './offline-identity-map.js';
import { readPageActorId } from './offline-nav-guard.js';
import { publicPath } from './offline-url-keys.js';
import { appendLocalMemberToHousehold, applyMemberPayloadToSnapshot, getHouseholdSnapshot, listMemberSnapshots } from './offline-hp-store.js';
import { cacheHydratedHouseholdPages, displayMemberField, MEMBER_VIEW_DT_FIELDS } from './offline-hp-hydrate.js';

function displayName(payload) {
    return [payload.first_name, payload.middle_name, payload.last_name]
        .map((part) => String(part || '').trim())
        .filter(Boolean)
        .join(' ') || 'New member';
}

export function localMemberViewPath(householdNo, localMemberId) {
    return publicPath(`/household-profiling/${householdNo}/members/${localMemberId}`);
}

export function fillLocalMemberViewRoot(root, payload, householdNo, localMemberId) {
    if (!root) {
        return;
    }
    const name = displayName(payload);
    const member = { ...payload, name };
    root.setAttribute('data-offline-local-member', '1');
    root.setAttribute('data-household-no', householdNo);
    root.setAttribute('data-member-id', localMemberId);
    root.setAttribute('data-member-name', name);
    const heading = root.querySelector('.lml-hh-member-view__name');
    if (heading) {
        heading.textContent = name;
    }
    root.querySelectorAll('[data-offline-local-field]').forEach((node) => {
        const key = node.getAttribute('data-offline-local-field');
        node.textContent = displayMemberField(member, key);
    });
    root.querySelectorAll('.lml-hh-member-view__item').forEach((item) => {
        const label = String(item.querySelector('dt')?.textContent || '').trim();
        const dd = item.querySelector('dd');
        const field = MEMBER_VIEW_DT_FIELDS.find((entry) => entry[0] === label);
        if (!field || !dd) {
            return;
        }
        dd.textContent = displayMemberField(member, field[1]);
    });
}

export async function cacheLocalMemberView(options = {}) {
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const actorId = options.actorId || readPageActorId(options.root || doc);
    const cacheName = htmlCacheNameForActor(actorId);
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');
    const householdNo = String(options.householdNo || '').trim();
    const localMemberId = String(options.localMemberId || '').trim();
    const payload = options.payload && typeof options.payload === 'object' ? options.payload : {};

    if (!cacheName || !cachesApi?.open || !householdNo || !isLocalMemberViewPath(localMemberViewPath(householdNo, localMemberId))) {
        return { ok: false };
    }

    const template = doc?.querySelector?.('[data-offline-local-member-template]');
    const html = template
        ? buildFromTemplate(doc, template, payload, householdNo, localMemberId)
        : buildFallbackHtml(payload, householdNo, localMemberId);

    try {
        const cache = await cachesApi.open(cacheName);
        const sanitized = sanitizeCachedHtml(html);
        const path = localMemberViewPath(householdNo, localMemberId);
        await cache.put(
            new Request(navigationCacheUrl(origin, path)),
            new Response(sanitized, {
                status: 200,
                headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-Lmlinga-Offline-Cache': '1' },
            }),
        );
        await rememberLocalMember(localMemberId, { ...payload, household_no: householdNo }, {
            actorId,
            caches: cachesApi,
            origin,
        });
        try {
            await appendLocalMemberToHousehold(actorId, householdNo, {
                ...payload,
                client_local_member_id: localMemberId,
            });
            const household = await getHouseholdSnapshot(actorId, householdNo);
            const members = await listMemberSnapshots(actorId, householdNo);
            if (household) {
                await cacheHydratedHouseholdPages(actorId, household, members, {
                    caches: cachesApi,
                    origin,
                    actorId,
                });
            }
        } catch {
            // Snapshot merge must not block the queued member view redirect.
        }
        return { ok: true, path };
    } catch {
        return { ok: false };
    }
}

function buildFromTemplate(doc, template, payload, householdNo, localMemberId) {
    const clone = template.content.cloneNode(true);
    const holder = doc.createElement('div');
    holder.appendChild(clone);
    fillLocalMemberViewRoot(holder.querySelector('[data-lml-hh-member-view]') || holder, payload, householdNo, localMemberId);
    const layout = doc.documentElement.cloneNode(true);
    const main = layout.querySelector('[data-lml-hh-member-form]') || layout.querySelector('main');
    if (main) {
        main.replaceWith(holder.firstElementChild || holder);
    }
    const title = layout.querySelector('title');
    if (title) {
        title.textContent = 'View Member Information - LMLinga';
    }
    return `<!DOCTYPE html>${layout.outerHTML}`;
}

function buildFallbackHtml(payload, householdNo, localMemberId) {
    const member = { ...payload, name: displayName(payload), member_no: localMemberId, local: true };
    const name = displayMemberField(member, 'name');
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>View Member Information - LMLinga</title></head><body data-lml-offline-root>
<article data-lml-hh-member-view data-offline-local-member="1" data-household-no="${escapeHtml(householdNo)}" data-member-id="${escapeHtml(localMemberId)}" data-member-name="${escapeHtml(name)}">
<h1>View Member Information</h1>
<h2 class="lml-hh-member-view__name" data-offline-local-field="name">${escapeHtml(name)}</h2>
<p class="lml-hh-view__sync-badge">Waiting to sync</p>
<dl>
<div class="lml-hh-member-view__item"><dt>Full Name</dt><dd data-offline-local-field="name">${escapeHtml(displayMemberField(member, 'name'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Relation to Household Head</dt><dd data-offline-local-field="relation">${escapeHtml(displayMemberField(member, 'relation'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Relationship Status</dt><dd data-offline-local-field="relationship_status">${escapeHtml(displayMemberField(member, 'relationship_status'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Birthday</dt><dd data-offline-local-field="birthday">${escapeHtml(displayMemberField(member, 'birthday'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Sex</dt><dd data-offline-local-field="sex">${escapeHtml(displayMemberField(member, 'sex'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Occupation</dt><dd data-offline-local-field="occupation">${escapeHtml(displayMemberField(member, 'occupation'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Monthly Income</dt><dd data-offline-local-field="monthly_income">${escapeHtml(displayMemberField(member, 'monthly_income'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Religion</dt><dd data-offline-local-field="religion">${escapeHtml(displayMemberField(member, 'religion'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Educational Attainment</dt><dd data-offline-local-field="education">${escapeHtml(displayMemberField(member, 'education'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>PhilHealth Number</dt><dd data-offline-local-field="philhealth">${escapeHtml(displayMemberField(member, 'philhealth'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Family Planning</dt><dd data-offline-local-field="fp_user">${escapeHtml(displayMemberField(member, 'fp_user'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Disability Type</dt><dd data-offline-local-field="disability">${escapeHtml(displayMemberField(member, 'disability'))}</dd></div>
<div class="lml-hh-member-view__item"><dt>Medical History</dt><dd data-offline-local-field="medical_history">${escapeHtml(displayMemberField(member, 'medical_history'))}</dd></div>
</dl>
</article></body></html>`);
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

export async function handleResidentCreateQueued(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.RESIDENT_CREATE) {
        return { ok: false };
    }
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const localMemberId = String(payload.client_local_member_id || '').trim();
    const householdNo = String(detail.parent_server?.household_no || payload.household_no || '').trim();
    if (!localMemberId || !householdNo) {
        return { ok: false };
    }
    const cached = await cacheLocalMemberView({
        ...options,
        householdNo,
        localMemberId,
        payload,
    });
    if (!cached.ok) {
        return cached;
    }
    if (options.navigate === false) {
        return cached;
    }
    const assign = options.assign || ((url) => {
        const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
        win.location?.assign?.(url);
    });
    assign(cached.path);
    return cached;
}

export async function handleResidentUpdateQueued(detail, options = {}) {
    if (detail?.operation_type !== OPERATION_TYPES.RESIDENT_UPDATE
        && detail?.operation_type !== OPERATION_TYPES.RESIDENT_CREATE) {
        return { ok: false };
    }
    const payload = detail.payload && typeof detail.payload === 'object' ? detail.payload : {};
    const householdNo = String(detail.parent_server?.household_no || payload.household_no || '').trim();
    const memberNo = String(
        payload.client_local_member_id
        || detail.parent_server?.member_no
        || '',
    ).trim();
    if (!householdNo || !memberNo) {
        return { ok: false };
    }
    const actorId = options.actorId || readPageActorId(options.root || options.document);
    try {
        if (/^MB-L-/i.test(memberNo)) {
            await appendLocalMemberToHousehold(actorId, householdNo, {
                ...payload,
                client_local_member_id: memberNo,
            });
            await rememberLocalMember(memberNo, { ...payload, household_no: householdNo }, {
                actorId,
                caches: options.caches,
                origin: options.origin,
            });
        } else {
            await applyMemberPayloadToSnapshot(actorId, householdNo, memberNo, payload);
        }
        const household = await getHouseholdSnapshot(actorId, householdNo);
        const members = await listMemberSnapshots(actorId, householdNo);
        if (household) {
            await cacheHydratedHouseholdPages(actorId, household, members, {
                caches: options.caches,
                origin: options.origin,
                actorId,
            });
        }
    } catch {
        return { ok: false };
    }
    const path = localMemberViewPath(householdNo, memberNo);
    if (options.navigate === false) {
        return { ok: true, path };
    }
    const assign = options.assign || ((url) => {
        const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
        win.location?.assign?.(url);
    });
    assign(path);
    return { ok: true, path };
}

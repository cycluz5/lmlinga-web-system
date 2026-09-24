/**
 * Hydrate reusable Household Profiling HTML shells from IndexedDB snapshots
 * into the actor Cache Storage. Never fetches one HTML page per household.
 */

import {
    htmlCacheNameForActor,
    navigationCacheUrl,
    sanitizeCachedHtml,
} from './offline-sw-policy.js';
import { internalIdFor, legacyPath, publicPath, urlKeyFor } from './offline-url-keys.js';
import {
    getHouseholdSnapshot,
    getMemberSnapshot,
    getMeta,
    listMemberSnapshots,
    membersForHousehold,
    formatAccomplishedDate,
    putMeta,
} from './offline-hp-store.js';

const KIND = {
    VIEW_HOUSEHOLD: 'view-household',
    EDIT_HOUSEHOLD: 'household-edit',
    ADD_MEMBER: 'add-member',
    VIEW_MEMBER: 'view-member',
    EDIT_MEMBER: 'edit-member',
    AMENITIES: 'amenities',
    AMENITIES_EDIT: 'amenities-edit',
    HEALTH_RECORD: 'health-record',
};

/** Supported offline Health Summary destinations (real Blade shells). */
export const SUPPORTED_HEALTH_MODULES = [
    'child-immunization',
    'school-based-immunization',
    'child-nutrition',
    'nutritional-status',
    'deworming',
    'risk-assessment',
    'family-planning',
    'maternal-care',
];

/** Online-only Health Summary destinations (in-shell unavailable, never raw HTML). */
export const ONLINE_ONLY_HEALTH_MODULES = [
    'adult-immunization',
    'death',
];

const HEALTH_SHELL_META_PREFIX = 'shell:health:';

/** Donor Add Measurement form shell (kept beside, not inside, SUPPORTED_HEALTH_MODULES). */
export const NUTRITION_CREATE_SHELL_KEY = 'nutritional-status-create';

const HOUSEHOLD_NO = '(?:HH-[0-9]+|[0-9]{3}|h[a-z2-7]{12,})';
const MEMBER_ANY = '(?:MB-(?:L-[A-Za-z0-9]+|[0-9]+)|m[a-z2-7]{12,})';

/** Escaped public (opaque-key) URL path for an internal-id path. */
function hpPath(path) {
    return escapeHtml(publicPath(path));
}

/** Swap the donor member's URL key and internal id for the target member's. */
function swapMemberIdentity(html, fromMember, toMember) {
    let next = String(html || '');
    const fromKey = urlKeyFor('m', fromMember);
    const toKey = urlKeyFor('m', toMember);
    if (fromMember && fromKey !== fromMember && fromKey !== toKey) {
        next = next.split(fromKey).join(toKey);
    }
    if (fromMember && toMember && fromMember !== toMember) {
        next = next.split(fromMember).join(toMember);
    }
    return next;
}

export function parseHouseholdProfilingPath(pathname) {
    const path = legacyPath(String(pathname || '').replace(/\/+$/, '') || '/');
    let match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/edit$`));
    if (match) {
        return { kind: KIND.EDIT_HOUSEHOLD, householdNo: match[1], memberId: null };
    }
    match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})$`));
    if (match) {
        return { kind: KIND.VIEW_HOUSEHOLD, householdNo: match[1], memberId: null };
    }
    match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/members/create$`));
    if (match) {
        return { kind: KIND.ADD_MEMBER, householdNo: match[1], memberId: null };
    }
    match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/members/(${MEMBER_ANY})/edit$`));
    if (match) {
        return { kind: KIND.EDIT_MEMBER, householdNo: match[1], memberId: match[2] };
    }
    match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/members/(${MEMBER_ANY})$`));
    if (match) {
        return { kind: KIND.VIEW_MEMBER, householdNo: match[1], memberId: match[2] };
    }
    match = path.match(
        new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/members/(${MEMBER_ANY})/(child-immunization|school-based-immunization|child-nutrition|nutritional-status|deworming|risk-assessment|family-planning|maternal-care)(?:/.*)?$`),
    );
    if (match) {
        return { kind: KIND.HEALTH_RECORD, householdNo: match[1], memberId: match[2], healthKey: match[3] };
    }
    match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/amenities/edit$`));
    if (match) {
        return { kind: KIND.AMENITIES_EDIT, householdNo: match[1], memberId: null };
    }
    match = path.match(new RegExp(`^/household-profiling/(${HOUSEHOLD_NO})/amenities$`));
    if (match) {
        return { kind: KIND.AMENITIES, householdNo: match[1], memberId: null };
    }
    return null;
}

const NUTRITION_CANONICAL_PATH = new RegExp(
    `^/households/(${HOUSEHOLD_NO})/residents/([0-9]+)/nutritional-status(/create)?$`,
);

/** Canonical resident-based Nutritional Status URL (member card link on live pages). */
export function parseNutritionalStatusCanonicalPath(pathname) {
    const path = legacyPath(String(pathname || '').replace(/\/+$/, '') || '/');
    const match = path.match(NUTRITION_CANONICAL_PATH);
    if (!match) {
        return null;
    }
    return { householdNo: match[1], residentId: match[2], create: Boolean(match[3]) };
}

/**
 * Map a canonical resident-based Nutritional Status URL to the prepared
 * member-based URL using this actor's member snapshots only.
 */
export async function resolveNutritionalStatusMemberPath(actorId, pathname) {
    const info = parseNutritionalStatusCanonicalPath(pathname);
    const id = Number(actorId);
    if (!info || !Number.isInteger(id) || id <= 0) {
        return null;
    }
    const members = await listMemberSnapshots(id, info.householdNo);
    const member = members.find(
        (row) => row.resident_id != null && String(row.resident_id) === info.residentId,
    );
    if (!member?.member_no) {
        return null;
    }
    return healthModulePath(info.householdNo, member.member_no, 'nutritional-status', info.create ? 'create' : '');
}

/** Donor household/member found inside a fetched shell (its own links win over caller hints). */
function detectShellIdentity(html) {
    const text = String(html || '');
    const path = text.match(
        new RegExp(`/household-profiling/(${HOUSEHOLD_NO})/members/(${MEMBER_ANY})(?=[/"'?#\\s<]|$)`),
    );
    if (path) {
        return { householdNo: internalIdFor('h', path[1]), memberId: internalIdFor('m', path[2]) };
    }
    const hh = text.match(/data-household-no="([^"]+)"/i);
    const mb = text.match(/data-member-id="([^"]+)"/i);
    if (hh?.[1] && mb?.[1]) {
        return { householdNo: hh[1], memberId: mb[1] };
    }
    return null;
}

/**
 * A hydrated member must only reference its own resident: rewrite canonical
 * Nutritional Status links to the member's prepared URL and replace or drop
 * any resident primary key copied from the donor shell.
 */
export function scrubResidentIdentity(html, household, member) {
    const memberNo = String(member?.member_no || '');
    const householdNo = String(household?.household_no || '');
    const isLocal = Boolean(member?.local) || /^MB-L-/i.test(memberNo);
    const residentId = !isLocal && member?.resident_id != null && member.resident_id !== ''
        ? String(member.resident_id)
        : '';
    let next = String(html || '');
    if (memberNo && householdNo) {
        next = next.replace(
            /\/households\/[^/"'\s?#]+\/residents\/[^/"'\s?#]+\/nutritional-status((?:\/(?:create|preview))?)/g,
            (match, suffix) => `${publicPath(`/household-profiling/${householdNo}/members/${memberNo}/nutritional-status`)}${suffix}`,
        );
    }
    ['data-resident-id', 'data-offline-parent-resident-id'].forEach((attr) => {
        if (residentId) {
            next = next.replace(new RegExp(`(${attr}=")[^"]*`, 'gi'), `$1${residentId}`);
        } else {
            next = next.replace(new RegExp(`\\s${attr}="[^"]*"`, 'gi'), '');
        }
    });
    return next;
}

const NUTRITION_EMPTY_BOX = `<div class="lml-hr-cc-nr__age-box" role="status" data-lml-timbang-empty>
<div class="lml-hr-cc-nr__empty">
<p class="lml-hr-cc-nr__empty-title">No nutritional measurements are recorded for this member.</p>
<p class="lml-hr-cc-nr__empty-hint">Add a record to start tracking growth.</p>
</div>
</div>`;

/** Nutritional Status pages carry no data-member-name; read the donor's name from their copy. */
function extractNutritionalStatusDonorName(html) {
    const text = String(html || '');
    const patterns = [
        /Track the growth of ([^<]+)</i,
        /Add Measurement for\s+([^<]+?)\s*</i,
        /<dt>\s*Resident\s*<\/dt>\s*<dd>([^<]+)</i,
        /Back to ([^<]+?)(?:'|&#0?39;|&#x27;)s profile/i,
    ];
    for (const pattern of patterns) {
        const match = text.match(pattern);
        const name = match?.[1] ? match[1].trim() : '';
        if (name) {
            return name;
        }
    }
    return '';
}

/**
 * Donor shell safety: drop every measurement row so a hydrated member never
 * shows another resident's Nutritional Status history.
 */
export function resetNutritionalStatusIndexShell(html) {
    let next = String(html || '');
    if (!/lml-hr-cc-nr__history-panel/i.test(next)) {
        return next;
    }
    next = next.replace(/<article\b[^>]*data-lml-hh-timbang-age-group[\s\S]*?<\/article>/gi, '');
    next = next.replace(/<li\b[^>]*data-timbang-id=[\s\S]*?<\/li>/gi, '');
    next = next.replace(/<p\b[^>]*class="lml-hh-timbang__status"[^>]*>[\s\S]*?<\/p>/gi, '');
    if (!/lml-hr-cc-nr__empty-title/i.test(next)) {
        next = next.replace(
            /(<section\b[^>]*lml-hr-cc-nr__history-panel[^>]*>[\s\S]*?)(<\/section>)/i,
            `$1${NUTRITION_EMPTY_BOX}$2`,
        );
    }
    return next;
}

/** Donor shell safety for the Add Measurement form (birthday, sex, computed values). */
export function resetNutritionalStatusCreateShell(html, member) {
    let next = String(html || '');
    if (!/data-lml-hh-timbang-form/i.test(next)) {
        return next;
    }
    next = next.replace(
        /(data-resident-birthday=")[^"]*/gi,
        `$1${escapeHtml(isoMemberBirthday(member?.birthday) || '')}`,
    );
    next = next.replace(
        /(<dt>\s*Sex\s*<\/dt>\s*<dd>)[^<]*/i,
        `$1${escapeHtml(String(member?.sex || '').trim() || '—')}`,
    );
    next = next.replace(/(data-timbang-age-label[^>]*>)[^<]*/i, '$1Age not recorded');
    next = next.replace(
        /<input\b[^>]*data-timbang-(?:wfa|hfa|muac-status|bmi|bmi-status|overall)-value[^>]*>/gi,
        (tag) => tag.replace(/\svalue="[^"]*"/i, ' value="—"'),
    );
    return next;
}

export function rewriteHouseholdTokens(html, fromNo, household) {
    const toNo = String(household.household_no || '');
    let next = String(html || '');
    const fromKey = urlKeyFor('h', fromNo);
    const toKey = urlKeyFor('h', toNo);
    if (fromNo && fromKey !== fromNo && fromKey !== toKey) {
        next = next.split(fromKey).join(toKey);
    }
    if (fromNo && toNo && fromNo !== toNo) {
        next = next.split(fromNo).join(toNo);
        const fromBare = String(fromNo).replace(/^HH-/, '');
        const toBare = String(toNo).replace(/^HH-/, '');
        if (fromBare && toBare && fromBare !== fromNo) {
            next = next.split(`HH ${fromBare}`).join(household.display_no || `HH ${toBare}`);
        }
    }
    return next;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

export function displayMemberCount(household, members) {
    if (Array.isArray(members)) {
        return members.length;
    }
    return Number(household?.member_count || 0);
}

function memberRowsHtml(household, members) {
    if (!members.length) {
        return `<div class="lml-hh-view__members-empty" role="status"><p class="lml-hh-view__members-empty-text">No household members are recorded for this household yet.</p></div>`;
    }
    const rows = members.map((member) => {
        const isHead = String(member.relationship || member.relation || '').toLowerCase() === 'head';
        const occupation = String(member.occupation || '');
        const occLabel = occupation === 'None / N/A' ? 'N/A' : occupation;
        const memberId = member.member_no;
        const hh = household.household_no;
        const pending = member.local || /^MB-L-/i.test(String(memberId || ''));
        const pendingLabel = member.waiting_for_household || member.sync_attention
            ? (member.sync_attention ? 'Needs attention' : 'Waiting for Household')
            : 'Waiting to sync';
        const pendingBadge = pending
            ? `<span class="lml-hh-view__sync-badge">${pendingLabel}</span>`
            : '';
        return `<tr>
<td data-label="Name"><span class="lml-hh-view__member-name-wrap"><span class="lml-hh-view__member-name">${escapeHtml(member.name)}</span>${isHead ? '<span class="lml-hh-view__head-badge">Head</span>' : ''}${pendingBadge}</span></td>
<td data-label="Relationship">${escapeHtml(member.relationship || member.relation || '')}</td>
<td data-label="Age">${member.age == null ? '—' : escapeHtml(member.age)}</td>
<td data-label="Sex">${escapeHtml(member.sex)}</td>
<td data-label="Occupation">${escapeHtml(occLabel)}</td>
<td data-label="Actions"><div class="lml-hh-view__actions" role="group">
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}`)}" class="lml-hh-view__action-btn lml-hh-view__action-btn--view lml-focus-ring" data-hh-nav="view-member"><span>View</span></a>
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}/edit`)}" class="lml-hh-view__action-btn lml-hh-view__action-btn--edit lml-focus-ring" data-hh-nav="edit-member"><span>Edit</span></a>
</div></td>
</tr>`;
    }).join('');
    return `<div class="lml-hh-view__table-scroll"><table class="lml-hh-view__table"><tbody>${rows}</tbody></table></div>`;
}

export function householdMetaHtml(household) {
    const zone = String(household?.zone || '').trim() || '—';
    const date = String(household?.accomplished_date || '').trim()
        || formatAccomplishedDate(household?.date_registered);
    return `<dl class="lml-hh-view__meta">
<div class="lml-hh-view__meta-item">
<dt>
<i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
<span>Zone</span>
</dt>
<dd data-hh-view-zone>${escapeHtml(zone)}</dd>
</div>
<div class="lml-hh-view__meta-item">
<dt>
<i class="bi bi-calendar3" aria-hidden="true"></i>
<span>Accomplished Date</span>
</dt>
<dd data-hh-view-date>${escapeHtml(date)}</dd>
</div>
</dl>`;
}

export function hydrateHouseholdViewHtml(shellHtml, household, members, fromNo) {
    const count = displayMemberCount(household, members);
    const displayNo = escapeHtml(household.display_no || household.household_no || '');
    let html = rewriteHouseholdTokens(shellHtml, fromNo, household);
    html = html.replace(
        /(data-household-no=")[^"]*/gi,
        `$1${escapeHtml(household.household_no || '')}`,
    );
    if (household.local || household.pending_sync) {
        html = html.replace(
            /(data-lml-hh-view)([^>]*>)/i,
            `$1 data-offline-local-household="1"$2`,
        );
    }
    html = html.replace(
        /(<h2[^>]*class="lml-hh-view__hh-no"[^>]*>)[\s\S]*?<\/h2>/i,
        `$1${displayNo}</h2>`,
    );
    if ((household.local || household.pending_sync) && !/Waiting to sync/i.test(html)) {
        html = html.replace(
            /(<h2[^>]*class="lml-hh-view__hh-no"[^>]*>[\s\S]*?<\/h2>)/i,
            `$1<p class="lml-hh-view__sync-badge">Waiting to sync</p>`,
        );
    }
    html = html.replace(
        /(<span class="lml-hh-view__head-name">)[^<]*/i,
        `$1${escapeHtml(household.house_head || '—')}`,
    );
    html = html.replace(
        /<dl class="lml-hh-view__meta">[\s\S]*?<\/dl>/i,
        householdMetaHtml(household),
    );
    html = html.replace(
        /(lml-hh-view__members-badge"[^>]*>[\s\S]*?<span>)[^<]*/i,
        `$1${count} members`,
    );
    html = html.replace(
        /(Household Members \()[^)]*/i,
        `$1${count}`,
    );
    const water = household.water || {};
    const sanitation = household.sanitation || {};
    html = html.replace(
        /(<article class="lml-hh-view__amenity lml-hh-view__amenity--water">[\s\S]*?<p class="lml-hh-view__amenity-detail">)[^<]*/i,
        `$1${escapeHtml(water.level || '—')}`,
    );
    html = html.replace(
        /(<article class="lml-hh-view__amenity lml-hh-view__amenity--water">[\s\S]*?<span class="lml-hh-view__amenity-badge[^"]*">)[^<]*/i,
        `$1${escapeHtml(water.status || 'Not recorded')}`,
    );
    html = html.replace(
        /(<article class="lml-hh-view__amenity lml-hh-view__amenity--sanitation">[\s\S]*?<p class="lml-hh-view__amenity-detail">)[^<]*/i,
        `$1${escapeHtml(sanitation.facility || '—')}`,
    );
    html = html.replace(
        /(<article class="lml-hh-view__amenity lml-hh-view__amenity--sanitation">[\s\S]*?<span class="lml-hh-view__amenity-badge[^"]*">)[^<]*/i,
        `$1${escapeHtml(sanitation.status || 'Not recorded')}`,
    );
    const membersBlock = memberRowsHtml(household, members);
    html = html.replace(
        /<section class="lml-hh-view__members"[\s\S]*?<\/section>/i,
        `<section class="lml-hh-view__members" aria-labelledby="lml-hh-view-members-title">
<div class="lml-hh-view__members-header">
<h2 id="lml-hh-view-members-title" class="lml-hh-view__members-title">Household Members (${count})</h2>
<a href="${hpPath(`/household-profiling/${household.household_no}/members/create`)}" class="lml-hh-view__add-member lml-focus-ring" data-hh-nav="add-member"><span>Add Household Member</span></a>
</div>
${membersBlock}
</section>`,
    );
    return sanitizeCachedHtml(html);
}

export function hydrateAddMemberHtml(shellHtml, household, fromNo) {
    let html = rewriteHouseholdTokens(shellHtml, fromNo, household);
    html = html.replace(
        /(Adding to household <strong>)[^<]*/i,
        `$1${escapeHtml(household.display_no || household.household_no)}`,
    );
    const householdIdAttr = household.household_id != null && household.household_id !== ''
        ? String(household.household_id)
        : '';
    html = html.replace(
        /(data-offline-parent-household-id=")[^"]*/i,
        `$1${escapeHtml(householdIdAttr)}`,
    );
    html = html.replace(
        /(data-offline-parent-household-no=")[^"]*/i,
        `$1${escapeHtml(household.household_no)}`,
    );
    if ((household.local || household.pending_sync) && !/data-offline-local-household="1"/i.test(html)) {
        html = html.replace(
            /(data-offline-operation="RESIDENT_CREATE")/i,
            '$1 data-offline-local-household="1"',
        );
    }
    return sanitizeCachedHtml(html);
}

export function isoMemberBirthday(value) {
    const raw = String(value || '').trim();
    const iso = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (iso) {
        return `${iso[1]}-${iso[2]}-${iso[3]}`;
    }
    const us = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (us) {
        return `${us[3]}-${us[1].padStart(2, '0')}-${us[2].padStart(2, '0')}`;
    }
    return '';
}

export function formRelationValue(value) {
    const raw = String(value || '').trim();
    if (!raw || raw === '—') {
        return '';
    }
    if (/^household\s*head$/i.test(raw) || /^head$/i.test(raw)) {
        return 'Head';
    }
    return raw;
}

export function formatMemberBirthday(value) {
    const iso = isoMemberBirthday(value);
    if (iso) {
        const match = iso.match(/^(\d{4})-(\d{2})-(\d{2})/);
        return `${match[2]}/${match[3]}/${match[1]}`;
    }
    const text = String(value || '').trim();
    return text === '' || text === '—' ? '—' : text;
}

function formatMemberList(value, member, key) {
    if (!Array.isArray(value) || value.length === 0) {
        return '—';
    }
    if (value.length === 1 && String(value[0]).toLowerCase() === 'none') {
        return 'None';
    }
    return value.map((item) => {
        if (item === 'others') {
            const extra = key === 'disability' ? member?.disability_others : member?.medical_others;
            return String(extra || 'Others').trim() || 'Others';
        }
        return String(item);
    }).join(', ');
}

export function displayMemberField(member, key) {
    const row = member && typeof member === 'object' ? member : {};
    if (key === 'name') {
        const name = String(row.name || '').trim() || [row.first_name, row.middle_name, row.last_name]
            .map((part) => String(part || '').trim())
            .filter(Boolean)
            .join(' ');
        return name || '—';
    }
    if (key === 'relation') {
        const relation = String(row.relation || row.relationship || '').trim();
        if (relation === 'Head') {
            return 'Household Head';
        }
        return relation || '—';
    }
    if (key === 'birthday') {
        return formatMemberBirthday(row.birthday);
    }
    if (key === 'philhealth') {
        const raw = String(row.philhealth ?? row.philhealth_number ?? '').replace(/\s+/g, '');
        return raw === '' ? '—' : raw;
    }
    if (key === 'occupation') {
        const occupation = String(row.occupation_select || row.occupation || '').trim();
        if (occupation === 'Other') {
            return String(row.occupation_other || 'Other').trim() || 'Other';
        }
        if (occupation === 'None / N/A') {
            return 'N/A';
        }
        return occupation || '—';
    }
    if (key === 'religion') {
        const religion = String(row.religion_select || row.religion || '').trim();
        if (religion === 'Other') {
            return String(row.religion_other || 'Other').trim() || 'Other';
        }
        if (religion === 'Roman Catholic') {
            return 'Catholic';
        }
        return religion || '—';
    }
    if (key === 'education') {
        const education = String(row.education || '').trim();
        if (education === 'College Graduate') {
            return "Bachelor's Degree";
        }
        return education || '—';
    }
    if (key === 'disability' || key === 'medical_history') {
        return formatMemberList(row[key], row, key);
    }
    const text = String(row[key] ?? '').trim();
    return text === '' ? '—' : text;
}

export const MEMBER_VIEW_DT_FIELDS = [
    ['Full Name', 'name'],
    ['Relation to Household Head', 'relation'],
    ['Relationship Status', 'relationship_status'],
    ['Birthday', 'birthday'],
    ['Sex', 'sex'],
    ['Occupation', 'occupation'],
    ['Monthly Income', 'monthly_income'],
    ['Religion', 'religion'],
    ['Educational Attainment', 'education'],
    ['PhilHealth Number', 'philhealth'],
    ['Family Planning', 'fp_user'],
    ['Disability Type', 'disability'],
    ['Medical History', 'medical_history'],
];

function replaceDefinitionValue(html, label, value) {
    const safeLabel = String(label).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const safe = escapeHtml(value);
    return html.replace(
        new RegExp(`(<dt[^>]*>\\s*${safeLabel}\\s*</dt>\\s*<dd[^>]*>)[\\s\\S]*?(</dd>)`, 'i'),
        `$1${safe}$2`,
    );
}

function extractTemplateMemberName(html) {
    const attr = String(html || '').match(/data-member-name="([^"]*)"/i);
    if (attr?.[1]) {
        return attr[1];
    }
    const heading = String(html || '').match(/<h2 class="lml-hh-member-view__name"[^>]*>([^<]*)/i);
    return heading?.[1] ? String(heading[1]).trim() : '';
}

export function hydrateMemberViewHtml(shellHtml, household, member, fromHouseholdNo, fromMemberId) {
    let html = rewriteHouseholdTokens(shellHtml, fromHouseholdNo, household);
    const templateName = extractTemplateMemberName(html);
    html = swapMemberIdentity(html, fromMemberId, member.member_no);
    html = html.replace(/(data-household-no=")[^"]*/i, `$1${escapeHtml(household.household_no || '')}`);
    html = html.replace(/(data-member-id=")[^"]*/gi, `$1${escapeHtml(member.member_no || '')}`);
    html = html.replace(/(data-member-name=")[^"]*/gi, `$1${escapeHtml(displayMemberField(member, 'name'))}`);
    if (member.local || /^MB-L-/i.test(String(member.member_no || ''))) {
        if (!/data-offline-local-member=/i.test(html)) {
            html = html.replace(
                /(<[^>]*data-lml-hh-member-view[^>]*)(>)/i,
                '$1 data-offline-local-member="1"$2',
            );
        }
    }
    html = html.replace(
        /(<h2 class="lml-hh-member-view__name"[^>]*>)[^<]*/i,
        `$1${escapeHtml(displayMemberField(member, 'name'))}`,
    );
    MEMBER_VIEW_DT_FIELDS.forEach(([label, key]) => {
        html = replaceDefinitionValue(html, label, displayMemberField(member, key));
    });
    const fieldKeys = [
        'name', 'last_name', 'first_name', 'middle_name', 'relation', 'sex', 'birthday',
        'relationship_status', 'occupation', 'monthly_income', 'religion', 'education',
        'philhealth', 'fp_user', 'disability', 'medical_history',
    ];
    fieldKeys.forEach((key) => {
        const safe = escapeHtml(displayMemberField(member, key));
        html = html.replace(
            new RegExp(`(data-offline-local-field="${key}">)[^<]*`, 'gi'),
            `$1${safe}`,
        );
    });
    if (member.local || /^MB-L-/i.test(String(member.member_no || ''))) {
        if (!/Waiting to sync/i.test(html)) {
            html = html.replace(
                /<\/h2>(\s*<p class="lml-hh-member-view__subtitle">)/i,
                `</h2><p class="lml-hh-view__sync-badge">Waiting to sync</p>$1`,
            );
        }
    }
    const localName = displayMemberField(member, 'name');
    if (templateName && localName && templateName !== localName) {
        html = html.split(templateName).join(localName);
    }
    html = applyHealthSummaryPanel(html, household, member);
    html = scrubResidentIdentity(html, household, member);
    return sanitizeCachedHtml(html);
}

/**
 * Preserve the real Health Summary Records panel while adjusting eligibility
 * links/nutrition card from compact prepared health metadata.
 */
export function applyHealthSummaryPanel(html, household, member) {
    let next = String(html || '');
    const health = member?.health && typeof member.health === 'object' ? member.health : null;
    const card = health?.nutrition_card;
    if (card && typeof card === 'object') {
        next = replaceNutritionCardValues(next, card);
    }
    const eligible = health?.eligible && typeof health.eligible === 'object' ? health.eligible : null;
    if (eligible) {
        if (eligible.family_planning === false) {
            next = next.replace(
                /<li class="lml-hh-member-view__record">\s*<span>Family Planning<\/span>[\s\S]*?<\/li>/i,
                '',
            );
        }
        if (eligible.maternal_care === false) {
            next = next.replace(
                /<li class="lml-hh-member-view__record">\s*<span>Maternal<\/span>[\s\S]*?<\/li>/i,
                '',
            );
        }
        if (eligible.risk_assessment === false) {
            next = next.replace(
                /(data-hh-member-risk-assessment[\s\S]*?<\/a>)/i,
                (match) => match.replace(/<\/a>/i, ''),
            );
            if (!/data-hh-member-risk-assessment-unavailable/i.test(next)) {
                next = next.replace(
                    /(<span>Risk Assessment<\/span>\s*)(<a[\s\S]*?data-hh-member-risk-assessment[\s\S]*?<\/a>)/i,
                    `$1<p class="lml-hh-member-view__record-unavailable" data-hh-member-risk-assessment-unavailable>Available for residents 19 years old and above.</p>`,
                );
            }
        }
    }
    // Keep Death / Adult Immunization links; offline nav guard blocks writes.
    void household;
    return next;
}

function replaceNutritionCardValues(html, card) {
    let next = String(html || '');
    const rows = [
        ['Weight', `${card.weight ?? '—'} kg`],
        ['Height', `${card.height ?? '—'} cm`],
    ];
    if ((card.mode || 'bmi') === 'bmi') {
        rows.push(['BMI', String(card.bmi ?? '—')]);
    } else {
        rows.push(['Nutritional Status', String(card.status ?? '—')]);
    }
    rows.forEach(([label, value]) => {
        const safeLabel = String(label).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        next = next.replace(
            new RegExp(
                `(<div class="lml-hh-member-view__nutrition-row">\\s*<dt>${safeLabel}</dt>\\s*<dd>)[^<]*`,
                'i',
            ),
            `$1${escapeHtml(value)}`,
        );
    });
    return next;
}

export function healthModulePath(householdNo, memberNo, healthKey, suffix = '') {
    const base = `/household-profiling/${householdNo}/members/${memberNo}/${healthKey}`;
    return suffix ? `${base}/${suffix}` : base;
}

export function hydrateHealthModuleHtml(shellHtml, household, member, healthKey, fromHouseholdNo, fromMemberId) {
    // Nutritional Status shells may come from a different donor than the other
    // health modules, so trust the identity found inside the shell itself.
    let fromNo = fromHouseholdNo;
    let fromMember = fromMemberId;
    if (healthKey === 'nutritional-status') {
        const detected = detectShellIdentity(shellHtml);
        if (detected) {
            fromNo = detected.householdNo;
            fromMember = detected.memberId;
        }
    }
    let html = rewriteHouseholdTokens(shellHtml, fromNo, household);
    const toMember = String(member.member_no || '');
    html = swapMemberIdentity(html, fromMember, toMember);
    html = html.replace(/(data-household-no=")[^"]*/gi, `$1${escapeHtml(household.household_no || '')}`);
    html = html.replace(/(data-member-id=")[^"]*/gi, `$1${escapeHtml(toMember)}`);
    html = html.replace(/(data-member-name=")[^"]*/gi, `$1${escapeHtml(displayMemberField(member, 'name'))}`);
    const templateName = extractTemplateMemberName(html)
        || (healthKey === 'nutritional-status' ? extractNutritionalStatusDonorName(html) : '');
    const localName = displayMemberField(member, 'name');
    if (templateName && localName && templateName !== localName) {
        html = html.split(templateName).join(localName);
    }
    if (member.local || /^MB-L-/i.test(toMember)) {
        if (!/data-offline-local-member=/i.test(html)) {
            html = html.replace(
                /(<[^>]*(?:data-lml-child-imm|data-lml-sbi|data-child-nut|data-lml-hh-timbang|data-lml-risk-assess|data-lml-fp|data-lml-mc|data-lml-deworm)[^>]*)(>)/i,
                '$1 data-offline-local-member="1"$2',
            );
        }
    }
    // Ensure HEALTH_SERVICE_WRITE forms retain parent identity for queue.
    html = html.replace(
        /(data-offline-parent-household-no=")[^"]*/gi,
        `$1${escapeHtml(household.household_no || '')}`,
    );
    html = html.replace(
        /(data-offline-parent-member-no=")[^"]*/gi,
        `$1${escapeHtml(toMember)}`,
    );
    html = scrubResidentIdentity(html, household, member);
    if (healthKey === 'nutritional-status') {
        html = resetNutritionalStatusIndexShell(html);
        html = resetNutritionalStatusCreateShell(html, member);
    }
    return sanitizeCachedHtml(html);
}

export async function hasCanonicalHealthShells(actorId) {
    for (const key of SUPPORTED_HEALTH_MODULES) {
        const html = await getMeta(actorId, `${HEALTH_SHELL_META_PREFIX}${key}`);
        if (!html || String(html).length < 200) {
            return false;
        }
        if (/data-lml-offline-root/i.test(String(html)) === false
            && /lml-dashboard|lml-sidebar|lml-hh-|data-lml-|data-household-no/i.test(String(html)) === false) {
            return false;
        }
    }
    return true;
}

export function isSupportedHealthModule(healthKey) {
    return SUPPORTED_HEALTH_MODULES.includes(String(healthKey || ''));
}

export function isOnlineOnlyHealthModule(healthKey) {
    return ONLINE_ONLY_HEALTH_MODULES.includes(String(healthKey || ''));
}

function setInputValue(html, name, value) {
    const safeName = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const safeValue = escapeHtml(value || '');
    return String(html || '').replace(/<input\b([^>]*?)>/gi, (full, attrs) => {
        if (!new RegExp(`\\bname="${safeName}"`, 'i').test(attrs)) {
            return full;
        }
        if (/\bvalue="/i.test(attrs)) {
            return '<input' + attrs.replace(/\bvalue="[^"]*"/i, 'value="' + safeValue + '"') + '>';
        }
        return '<input' + attrs + ' value="' + safeValue + '">';
    });
}

function setSelectValue(html, name, value) {
    const safeName = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return html.replace(
        new RegExp(`(<select[^>]*name="${safeName}"[^>]*>)([\\s\\S]*?)(</select>)`, 'i'),
        (full, open, inner, close) => {
            let next = inner.replace(/\sselected(?:=["'][^"']*["'])?/gi, '');
            const raw = String(value || '').trim();
            if (!raw) {
                return `${open}${next}${close}`;
            }
            const safeValue = raw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const withValue = new RegExp(`(<option(?=[^>]*value="${safeValue}")[^>]*)(>)`, 'i');
            if (withValue.test(next)) {
                next = next.replace(withValue, '$1 selected$2');
            } else {
                const withText = new RegExp(`(<option(?![^>]*value=)[^>]*>\\s*${safeValue}\\s*)(</option>)`, 'i');
                next = next.replace(withText, '$1 selected$2');
            }
            return `${open}${next}${close}`;
        },
    );
}

function extractEditTemplateMemberName(html) {
    const context = String(html || '').match(/Editing\s*<strong[^>]*>([^<]+)/i);
    if (context?.[1] && String(context[1]).trim()) {
        return String(context[1]).trim();
    }
    return extractTemplateMemberName(html);
}

export function hydrateMemberEditHtml(shellHtml, household, member, fromHouseholdNo, fromMemberId) {
    let html = rewriteHouseholdTokens(shellHtml, fromHouseholdNo, household);
    const templateName = extractEditTemplateMemberName(html);
    const localName = displayMemberField(member, 'name');
    html = swapMemberIdentity(html, fromMemberId, member.member_no);
    html = html.replace(/(data-mode=")[^"]*/i, '$1edit');
    html = html.replace(/data-offline-operation="RESIDENT_CREATE"/i, 'data-offline-operation="RESIDENT_UPDATE"');
    html = html.replace(/(data-household-no=")[^"]*/i, `$1${escapeHtml(household.household_no || '')}`);
    html = html.replace(/(data-member-id=")[^"]*/gi, `$1${escapeHtml(member.member_no || '')}`);
    html = html.replace(/(data-member-name=")[^"]*/gi, `$1${escapeHtml(localName)}`);
    html = html.replace(/(data-offline-parent-household-id=")[^"]*/i, `$1${escapeHtml(household.household_id || '')}`);
    html = html.replace(/(data-offline-parent-household-no=")[^"]*/i, `$1${escapeHtml(household.household_no || '')}`);
    html = html.replace(/(data-offline-parent-member-no=")[^"]*/i, `$1${escapeHtml(member.member_no || '')}`);
    if (member.local || /^MB-L-/i.test(String(member.member_no || '')) || !member.resident_id) {
        html = html.replace(/\sdata-offline-parent-resident-id="[^"]*"/gi, '');
        html = html.replace(/\sdata-offline-field-hash="[^"]*"/gi, '');
    } else {
        html = html.replace(/(data-offline-parent-resident-id=")[^"]*/i, `$1${escapeHtml(member.resident_id)}`);
        // The shell comes from a donor member: use THIS member's conflict hash, never the donor's.
        const fieldHash = String(member.field_hash || '').trim();
        html = fieldHash
            ? html.replace(/(data-offline-field-hash=")[^"]*/i, `$1${escapeHtml(fieldHash)}`)
            : html.replace(/\sdata-offline-field-hash="[^"]*"/gi, '');
    }
    html = html.replace(
        /(Editing\s*<strong[^>]*>)[^<]*(<\/strong>\s*in household)/i,
        `$1${escapeHtml(localName)}$2`,
    );
    html = html.replace(
        new RegExp(`(data-offline-local-field="name">)[^<]*`, 'gi'),
        `$1${escapeHtml(localName)}`,
    );
    html = setInputValue(html, 'last_name', member.last_name);
    html = setInputValue(html, 'first_name', member.first_name);
    html = setInputValue(html, 'middle_name', member.middle_name);
    html = setInputValue(html, 'birthday', isoMemberBirthday(member.birthday) || member.birthday);
    html = setSelectValue(html, 'relation', formRelationValue(member.relation || member.relationship));
    html = setSelectValue(html, 'sex', member.sex);
    html = setSelectValue(html, 'relationship_status', member.relationship_status);
    html = setSelectValue(html, 'occupation', member.occupation_select || member.occupation);
    html = setSelectValue(html, 'religion', member.religion_select || member.religion);
    html = setSelectValue(html, 'education', member.education);
    html = setSelectValue(html, 'monthly_income', member.monthly_income);
    html = setSelectValue(html, 'fp_user', member.fp_user);
    html = setInputValue(html, 'philhealth', member.philhealth || member.philhealth_number || '');
    if (templateName && localName && templateName !== localName) {
        html = html.split(templateName).join(localName);
    }
    return sanitizeCachedHtml(html);
}

export function hydrateAmenitiesHtml(shellHtml, household, fromNo) {
    let html = rewriteHouseholdTokens(shellHtml, fromNo, household);
    const water = household.water || {};
    const sanitation = household.sanitation || {};
    html = html.replace(/(Access to Safe Water[\s\S]*?<p[^>]*>)[^<]*/i, `$1${escapeHtml(water.level || '—')}`);
    html = html.replace(/(Sanitation Services[\s\S]*?<p[^>]*>)[^<]*/i, `$1${escapeHtml(sanitation.facility || '—')}`);
    return sanitizeCachedHtml(html);
}

function fallbackHouseholdViewHtml(household, members) {
    const hh = escapeHtml(household.household_no);
    const pending = household.local || household.pending_sync;
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${hh} - LMLinga</title></head>
<body data-lml-offline-root>
<article class="lml-hh-view" data-lml-hh-view data-household-no="${hh}"${pending ? ' data-offline-local-household="1"' : ''}>
<h1 class="lml-hh-view__hh-no">${escapeHtml(household.display_no || household.household_no)}</h1>
${pending ? '<p class="lml-hh-view__sync-badge">Waiting to sync</p>' : ''}
<p>Head of Household: <span class="lml-hh-view__head-name">${escapeHtml(household.house_head || '—')}</span></p>
<p>Zone: <span data-hh-view-zone>${escapeHtml(household.zone || '—')}</span></p>
<p data-hh-view-date>Accomplished Date: ${escapeHtml(household.accomplished_date || formatAccomplishedDate(household.date_registered))}</p>
<p><a href="${hpPath(`/household-profiling/${hh}/edit`)}" data-hh-nav="household-edit" class="lml-focus-ring">Edit Household</a></p>
<p>${escapeHtml((household.water || {}).level || '—')} / ${escapeHtml((household.water || {}).status || 'Not recorded')}</p>
<p>${escapeHtml((household.sanitation || {}).facility || '—')} / ${escapeHtml((household.sanitation || {}).status || 'Not recorded')}</p>
<section class="lml-hh-view__members">
<h2>Household Members (${displayMemberCount(household, members)})</h2>
<a href="${hpPath(`/household-profiling/${hh}/members/create`)}" data-hh-nav="add-member">Add Household Member</a>
${memberRowsHtml(household, members)}
</section>
</article></body></html>`);
}

function selectOption(value, label, selected) {
    const optionValue = String(value);
    const selectedRaw = String(selected || '').trim();
    const selectedZone = /^zone\s+\d+$/i.test(selectedRaw)
        ? selectedRaw.replace(/^(zone)\s+/i, 'Zone ')
        : (/^\d+$/.test(selectedRaw) ? `Zone ${selectedRaw}` : selectedRaw);
    const isSelected = selectedZone === optionValue || selectedRaw === optionValue;
    return `<option value="${escapeHtml(optionValue)}"${isSelected ? ' selected' : ''}>${escapeHtml(label)}</option>`;
}

function fallbackHouseholdEditHtml(household) {
    const hh = escapeHtml(household.household_no);
    const zoneSelected = String(household.zone || household.purok || '').trim();
    const date = escapeHtml(household.date_registered || '');
    const address = escapeHtml(household.address || '');
    const lat = household.latitude == null ? '' : escapeHtml(household.latitude);
    const lng = household.longitude == null ? '' : escapeHtml(household.longitude);
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Edit Household - LMLinga</title></head>
<body data-lml-offline-root>
<div class="lml-hh-member-form" data-lml-hh-shell-form data-mode="edit" data-offline-local-household="1" data-household-no="${hh}">
<a href="${hpPath(`/household-profiling/${hh}`)}" class="lml-hh-member-form__back">Back to Household</a>
<div class="lml-hh-member-form__card">
<h2 class="lml-hh-member-form__title">Edit Household</h2>
<p class="lml-hh-view__sync-badge">Waiting to sync</p>
<p class="lml-hh-member-form__context">Editing household <strong>${escapeHtml(household.display_no || household.household_no)}</strong>.</p>
<form class="lml-hh-member-form__form" method="post" action="${hpPath(`/household-profiling/${hh}`)}" data-offline-operation="HOUSEHOLD_UPDATE" data-offline-parent-household-no="${hh}" data-offline-local-household="1">
<p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
<input type="hidden" name="_method" value="PUT">
<label>Household No. <input value="${hh}" readonly></label>
<label>Zone <select name="zone" required>
${selectOption('Zone 1', 'Zone 1', zoneSelected)}
${selectOption('Zone 2', 'Zone 2', zoneSelected)}
${selectOption('Zone 3', 'Zone 3', zoneSelected)}
${selectOption('Zone 4', 'Zone 4', zoneSelected)}
${selectOption('Zone 5', 'Zone 5', zoneSelected)}
</select></label>
<label>Date Registered <input type="date" name="date_registered" value="${date}" required></label>
<label>Address <input name="address" value="${address}"></label>
<label>Latitude <input type="number" name="latitude" step="any" value="${lat}"></label>
<label>Longitude <input type="number" name="longitude" step="any" value="${lng}"></label>
<button type="submit">Save</button>
</form>
</div>
</div></body></html>`);
}

function fallbackAddMemberHtml(household) {
    const hh = escapeHtml(household.household_no);
    const pending = household.local || household.pending_sync;
    const householdIdAttr = household.household_id != null && household.household_id !== ''
        ? escapeHtml(household.household_id)
        : '';
    const localAttr = pending ? ' data-offline-local-household="1"' : '';
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Add New Member - LMLinga</title></head>
<body data-lml-offline-root>
<div class="lml-hh-member-form" data-lml-hh-member-form data-mode="create" data-persistable="1" data-household-no="${hh}"${pending ? ' data-offline-local-household="1"' : ''}>
<a href="${hpPath(`/household-profiling/${hh}`)}" class="lml-hh-member-form__back">Back to Household</a>
<h1>Add New Member</h1>
${pending ? '<p class="lml-hh-view__sync-badge">Waiting to sync</p>' : ''}
<p>Adding to household <strong>${escapeHtml(household.display_no || household.household_no)}</strong> (${escapeHtml(household.house_head || '')}).</p>
<form method="post" action="${hpPath(`/household-profiling/${hh}/members`)}" data-hh-member-form-el data-offline-operation="RESIDENT_CREATE"${localAttr} data-offline-parent-household-id="${householdIdAttr}" data-offline-parent-household-no="${hh}">
<p class="lml-offline-form-pending" data-lml-offline-form-pending hidden>Waiting to sync</p>
<label>Last Name <input name="last_name" required></label>
<label>First Name <input name="first_name" required></label>
<label>Relation <select name="relation" required><option value="">Select</option><option>Spouse</option><option>Son</option><option>Daughter</option><option>Parent</option><option>Sibling</option><option>Grandchild</option><option>Other Relative</option><option>Non-Relative</option></select></label>
<label>Birthday <input type="date" name="birthday" required></label>
<label>Sex <select name="sex" required><option value="">Select</option><option>Male</option><option>Female</option></select></label>
<button type="submit" data-hh-member-save>Save</button>
</form>
<template data-offline-local-member-template>
<div data-lml-hh-member-view data-offline-local-member="1"><h2 data-offline-local-field="name">Queued member</h2></div>
</template>
</div></body></html>`);
}

function fallbackMemberViewHtml(household, member) {
    const hh = escapeHtml(household.household_no);
    const memberId = escapeHtml(member.member_no);
    const pending = member.local || /^MB-L-/i.test(String(member.member_no || ''));
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>View Member Information - LMLinga</title></head>
<body data-lml-offline-root>
<article data-lml-hh-member-view data-household-no="${hh}" data-member-id="${memberId}"${pending ? ' data-offline-local-member="1"' : ''}>
<h1>View Member Information</h1>
<h2 class="lml-hh-member-view__name">${escapeHtml(member.name || '')}</h2>
${pending ? '<p class="lml-hh-view__sync-badge">Waiting to sync</p>' : ''}
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}/edit`)}" data-hh-nav="edit-member">Edit</a>
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
<nav>
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}/child-immunization`)}" data-hh-nav="health-record">Child Immunization</a>
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}/child-nutrition`)}" data-hh-nav="health-record">Child Nutrition</a>
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}/deworming`)}" data-hh-nav="health-record">Deworming</a>
<a href="${hpPath(`/household-profiling/${hh}/members/${memberId}/risk-assessment`)}" data-hh-nav="health-record">Risk Assessment</a>
</nav>
</article></body></html>`);
}

function fallbackMemberEditHtml(household, member) {
    const hh = escapeHtml(household.household_no);
    const memberId = escapeHtml(member.member_no);
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Edit Member - LMLinga</title></head>
<body data-lml-offline-root>
<div class="lml-hh-member-form" data-lml-hh-member-form data-mode="edit" data-persistable="1" data-household-no="${hh}" data-member-id="${memberId}" data-member-name="${escapeHtml(member.name || '')}">
<h1>Edit Member</h1>
<p class="lml-hh-member-form__context">Editing <strong data-offline-local-field="name">${escapeHtml(member.name || '')}</strong> in household <strong>${escapeHtml(household.display_no || household.household_no)}</strong>.</p>
<form method="post" action="${hpPath(`/household-profiling/${hh}/members/${memberId}`)}" data-hh-member-form-el data-offline-operation="RESIDENT_UPDATE" data-offline-parent-household-id="${escapeHtml(household.household_id || '')}" data-offline-parent-household-no="${hh}" data-offline-parent-member-no="${memberId}"${member.resident_id ? ` data-offline-parent-resident-id="${escapeHtml(member.resident_id)}"` : ''}${member.field_hash ? ` data-offline-field-hash="${escapeHtml(member.field_hash)}"` : ''}>
<label>Last Name <input name="last_name" required value="${escapeHtml(member.last_name || '')}"></label>
<label>First Name <input name="first_name" required value="${escapeHtml(member.first_name || '')}"></label>
<label>Middle Name <input name="middle_name" value="${escapeHtml(member.middle_name || '')}"></label>
<label>Relation <select name="relation" required><option value="${escapeHtml(member.relation || member.relationship || '')}" selected>${escapeHtml(member.relation || member.relationship || '')}</option></select></label>
<label>Birthday <input type="date" name="birthday" required value="${escapeHtml(member.birthday || '')}"></label>
<label>Sex <select name="sex" required><option value="${escapeHtml(member.sex || '')}" selected>${escapeHtml(member.sex || '')}</option></select></label>
<label>PhilHealth Number <input name="philhealth" value="${escapeHtml(member.philhealth || member.philhealth_number || '')}"></label>
<button type="submit" data-hh-member-save>Update</button>
</form>
</div></body></html>`);
}

function fallbackAmenitiesHtml(household) {
    const hh = escapeHtml(household.household_no);
    const water = household.water || {};
    const sanitation = household.sanitation || {};
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Household Details - LMLinga</title></head>
<body data-lml-offline-root>
<article data-lml-amenities data-household-no="${hh}">
<h1>Household Amenities</h1>
<p>Access to Safe Water</p><p>${escapeHtml(water.level || '—')}</p>
<p>Sanitation Services</p><p>${escapeHtml(sanitation.facility || '—')}</p>
</article></body></html>`);
}

/**
 * Last-resort failure screen only — never the normal offline health destination.
 */
function fallbackHealthHtml(household, member, healthKey) {
    const hh = escapeHtml(household.household_no);
    const memberId = escapeHtml(member.member_no);
    const title = String(healthKey || 'Health record').replace(/-/g, ' ');
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${escapeHtml(title)} - LMLinga</title>
<link rel="stylesheet" href="/build/assets/app.css">
</head>
<body data-lml-offline-root class="lml-dashboard">
<main id="main-content" class="lml-dashboard__content">
<section class="lml-offline-unavailable" data-lml-offline-unavailable data-reason="health-shell-missing" aria-live="polite">
<div class="lml-offline-unavailable__card">
<i class="bi bi-wifi-off lml-offline-unavailable__icon" aria-hidden="true"></i>
<h1 class="lml-offline-unavailable__title">This health page could not be prepared offline.</h1>
<p class="lml-offline-unavailable__body">Reconnect and run Preparing Offline Access again, then open household ${hh} / member ${memberId}.</p>
</div>
</section>
</main>
</body></html>`);
}

function onlineOnlyHealthUnavailableHtml(household, member, healthKey, shellHtml) {
    const title = healthKey === 'death' ? 'Death' : 'Adult Immunization';
    const panel = `
<section class="lml-offline-unavailable" data-lml-offline-unavailable data-reason="online-only" aria-live="polite">
<div class="lml-offline-unavailable__card">
<i class="bi bi-wifi-off lml-offline-unavailable__icon" aria-hidden="true"></i>
<h1 class="lml-offline-unavailable__title">${escapeHtml(title)} isn’t available while you’re offline.</h1>
<p class="lml-offline-unavailable__body">This feature requires an internet connection. Reconnect to continue.</p>
</div>
</section>`;
    if (shellHtml && /#main-content|lml-dashboard__content/i.test(shellHtml)) {
        let html = hydrateHealthModuleHtml(
            shellHtml,
            household,
            member,
            healthKey,
            null,
            null,
        );
        if (/id="main-content"/i.test(html)) {
            return sanitizeCachedHtml(html.replace(
                /(<main[^>]*id="main-content"[^>]*>)[\s\S]*?(<\/main>)/i,
                `$1${panel}$2`,
            ));
        }
        if (/class="[^"]*lml-dashboard__content/i.test(html)) {
            return sanitizeCachedHtml(html.replace(
                /(<[^>]*class="[^"]*lml-dashboard__content[^"]*"[^>]*>)[\s\S]*?(<\/div>|<\/main>)/i,
                `$1${panel}$2`,
            ));
        }
    }
    return sanitizeCachedHtml(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${escapeHtml(title)} - LMLinga</title>
<link rel="stylesheet" href="/build/assets/app.css">
</head>
<body data-lml-offline-root class="lml-dashboard">
<aside class="lml-sidebar" aria-label="Sidebar"></aside>
<main id="main-content" class="lml-dashboard__content">${panel}</main>
</body></html>`);
}

async function putHtml(cachesApi, actorId, origin, pathname, html) {
    const cacheName = htmlCacheNameForActor(actorId);
    if (!cacheName || !cachesApi?.open) {
        return false;
    }
    const cache = await cachesApi.open(cacheName);
    await cache.put(
        new Request(navigationCacheUrl(origin, publicPath(pathname))),
        new Response(html, {
            status: 200,
            headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-Lmlinga-Offline-Cache': '1' },
        }),
    );
    return true;
}

async function resolveHealthShells(actorId, options = {}) {
    const shells = options.healthShells || {};
    const resolved = {};
    for (const key of SUPPORTED_HEALTH_MODULES) {
        if (shells[key]) {
            resolved[key] = shells[key];
            continue;
        }
        resolved[key] = await getMeta(actorId, `${HEALTH_SHELL_META_PREFIX}${key}`);
    }
    return {
        modules: resolved,
        shellHouseholdNo: options.shellHouseholdNo
            || shells.householdNo
            || await getMeta(actorId, 'shell:health:household_no')
            || await getMeta(actorId, 'shell:household_no')
            || '',
        shellMemberId: options.shellMemberId
            || shells.memberId
            || await getMeta(actorId, 'shell:health:member_id')
            || await getMeta(actorId, 'shell:member_id')
            || '',
        memberView: options.memberViewShell
            || await getMeta(actorId, 'shell:member-view')
            || '',
        nutritionCreate: shells[NUTRITION_CREATE_SHELL_KEY]
            || await getMeta(actorId, `${HEALTH_SHELL_META_PREFIX}${NUTRITION_CREATE_SHELL_KEY}`)
            || '',
    };
}

export async function cacheHydratedHouseholdPages(actorId, household, members, options = {}) {
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');
    const shells = options.shells || {
        view: await getMeta(actorId, 'shell:view'),
        create: await getMeta(actorId, 'shell:create'),
        memberView: await getMeta(actorId, 'shell:member-view'),
        memberEdit: await getMeta(actorId, 'shell:member-edit'),
        amenities: await getMeta(actorId, 'shell:amenities'),
        shellHouseholdNo: await getMeta(actorId, 'shell:household_no'),
        shellMemberId: await getMeta(actorId, 'shell:member_id'),
        health: options.healthShells || null,
    };
    const fromNo = shells.shellHouseholdNo || '';
    const fromMember = shells.shellMemberId || '';
    const hh = household.household_no;
    let memberList = Array.isArray(members) ? members : [];
    if (memberList.length === 0 && hh) {
        memberList = await membersForHousehold(actorId, hh);
    }
    const viewHtml = shells.view
        ? hydrateHouseholdViewHtml(shells.view, household, memberList, fromNo)
        : fallbackHouseholdViewHtml(household, memberList);
    await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}`, viewHtml);
    await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/edit`, fallbackHouseholdEditHtml(household));
    if (shells.create) {
        await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/members/create`, hydrateAddMemberHtml(shells.create, household, fromNo));
    } else {
        await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/members/create`, fallbackAddMemberHtml(household));
    }
    if (shells.amenities) {
        await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/amenities`, hydrateAmenitiesHtml(shells.amenities, household, fromNo));
    } else {
        await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/amenities`, fallbackAmenitiesHtml(household));
    }

    const healthBundle = await resolveHealthShells(actorId, {
        healthShells: shells.health || options.healthShells,
        shellHouseholdNo: fromNo,
        shellMemberId: fromMember,
        memberViewShell: shells.memberView,
    });
    const healthFromNo = healthBundle.shellHouseholdNo || fromNo;
    const healthFromMember = healthBundle.shellMemberId || fromMember;

    for (const member of memberList) {
        const memberHtml = shells.memberView
            ? hydrateMemberViewHtml(shells.memberView, household, member, fromNo, fromMember)
            : fallbackMemberViewHtml(household, member);
        await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/members/${member.member_no}`, memberHtml);
        const editHtml = shells.memberEdit
            ? hydrateMemberEditHtml(shells.memberEdit, household, member, fromNo, fromMember)
            : fallbackMemberEditHtml(household, member);
        await putHtml(cachesApi, actorId, origin, `/household-profiling/${hh}/members/${member.member_no}/edit`, editHtml);

        for (const healthKey of SUPPORTED_HEALTH_MODULES) {
            const shell = healthBundle.modules[healthKey];
            const path = healthModulePath(hh, member.member_no, healthKey);
            if (shell && String(shell).length > 200) {
                await putHtml(
                    cachesApi,
                    actorId,
                    origin,
                    path,
                    hydrateHealthModuleHtml(shell, household, member, healthKey, healthFromNo, healthFromMember),
                );
            } else {
                await putHtml(
                    cachesApi,
                    actorId,
                    origin,
                    path,
                    fallbackHealthHtml(household, member, healthKey),
                );
            }
        }

        // Birth-history edit reuses child-immunization shell when a dedicated shell is absent.
        const birthShell = healthBundle.modules['child-immunization'];
        if (birthShell && String(birthShell).length > 200) {
            await putHtml(
                cachesApi,
                actorId,
                origin,
                healthModulePath(hh, member.member_no, 'child-immunization', 'birth-history/edit'),
                hydrateHealthModuleHtml(
                    birthShell,
                    household,
                    member,
                    'child-immunization',
                    healthFromNo,
                    healthFromMember,
                ),
            );
        }

        // Add Measurement needs its own donor form shell; the index shell is never reused for /create.
        const nutritionCreateShell = healthBundle.nutritionCreate;
        if (nutritionCreateShell && String(nutritionCreateShell).length > 200) {
            await putHtml(
                cachesApi,
                actorId,
                origin,
                healthModulePath(hh, member.member_no, 'nutritional-status', 'create'),
                hydrateHealthModuleHtml(
                    nutritionCreateShell,
                    household,
                    member,
                    'nutritional-status',
                    healthFromNo,
                    healthFromMember,
                ),
            );
        }

        for (const onlineKey of ONLINE_ONLY_HEALTH_MODULES) {
            await putHtml(
                cachesApi,
                actorId,
                origin,
                healthModulePath(hh, member.member_no, onlineKey),
                onlineOnlyHealthUnavailableHtml(
                    household,
                    member,
                    onlineKey,
                    shells.memberView || healthBundle.memberView,
                ),
            );
        }
    }
    return true;
}

export async function rememberShells(actorId, shells) {
    if (shells.view) {
        await putMeta(actorId, 'shell:view', shells.view);
    }
    if (shells.create) {
        await putMeta(actorId, 'shell:create', shells.create);
    }
    if (shells.memberView) {
        await putMeta(actorId, 'shell:member-view', shells.memberView);
    }
    if (shells.memberEdit) {
        await putMeta(actorId, 'shell:member-edit', shells.memberEdit);
    }
    if (shells.amenities) {
        await putMeta(actorId, 'shell:amenities', shells.amenities);
    }
    if (shells.householdNo) {
        await putMeta(actorId, 'shell:household_no', shells.householdNo);
    }
    if (shells.memberId) {
        await putMeta(actorId, 'shell:member_id', shells.memberId);
    }
    if (shells.health && typeof shells.health === 'object') {
        for (const key of SUPPORTED_HEALTH_MODULES) {
            if (shells.health[key]) {
                await putMeta(actorId, `${HEALTH_SHELL_META_PREFIX}${key}`, shells.health[key]);
            }
        }
        if (shells.health[NUTRITION_CREATE_SHELL_KEY]) {
            await putMeta(
                actorId,
                `${HEALTH_SHELL_META_PREFIX}${NUTRITION_CREATE_SHELL_KEY}`,
                shells.health[NUTRITION_CREATE_SHELL_KEY],
            );
        }
        if (shells.health.householdNo) {
            await putMeta(actorId, 'shell:health:household_no', shells.health.householdNo);
        }
        if (shells.health.memberId) {
            await putMeta(actorId, 'shell:health:member_id', shells.health.memberId);
        }
    }
}

/**
 * Overwrite a hydrated health destination with a prepared server page (existing records).
 */
export async function cachePreparedHealthPage(actorId, pathname, html, options = {}) {
    const cachesApi = options.caches || (typeof caches !== 'undefined' ? caches : null);
    const origin = options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost');
    return putHtml(cachesApi, actorId, origin, pathname, sanitizeCachedHtml(html));
}

export async function ensureHouseholdNavFromSnapshot(href, options = {}) {
    const actorId = Number(options.actorId || 0);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        return false;
    }
    let parsed;
    try {
        parsed = new URL(
            String(href || ''),
            options.origin || (typeof location !== 'undefined' ? location.origin : 'http://localhost'),
        );
    } catch {
        return false;
    }
    const info = parseHouseholdProfilingPath(parsed.pathname);
    if (!info) {
        return false;
    }
    if (info.kind === KIND.AMENITIES_EDIT) {
        return false;
    }
    const household = await getHouseholdSnapshot(actorId, info.householdNo);
    if (!household) {
        return false;
    }
    const members = await membersForHousehold(actorId, info.householdNo);
    if (info.kind === KIND.VIEW_MEMBER || info.kind === KIND.EDIT_MEMBER || info.kind === KIND.HEALTH_RECORD) {
        const member = await getMemberSnapshot(actorId, info.householdNo, info.memberId);
        if (!member) {
            return false;
        }
        if (info.kind === KIND.HEALTH_RECORD) {
            if (isOnlineOnlyHealthModule(info.healthKey)) {
                return false;
            }
            if (!isSupportedHealthModule(info.healthKey)) {
                return false;
            }
            const hasShell = await getMeta(actorId, `${HEALTH_SHELL_META_PREFIX}${info.healthKey}`);
            if (!hasShell || String(hasShell).length < 200) {
                return false;
            }
        }
    }
    try {
        await cacheHydratedHouseholdPages(actorId, household, members, options);
        return true;
    } catch {
        return false;
    }
}

/**
 * Offline Changes / Sync Issues viewer — human-readable queue presentation.
 * Does not own the queue; reads actor-scoped IndexedDB operations.
 */

import {
    QUEUE_STATUS,
    attentionCorrectionHint,
    householdNoFromRecord,
    isUnresolvedLocalHouseholdParent,
    isUnresolvedLocalParent,
    listOperationsForActor,
    localMemberIdFromRecord,
    reasonFromValidationErrors,
    sanitizeValidationErrors,
} from './offline-queue.js';
import { displayLabelForHealthAction } from './offline-health-store.js';
import { publicPath } from './offline-url-keys.js';

const HEALTH_ACTION_PATH = {
    child_immunization_store: 'child-immunization',
    child_birth_history_store: 'child-immunization/birth-history/edit',
    school_immunization_store: 'school-based-immunization',
    child_nutrition_store: 'child-nutrition',
    timbang_record_store: 'nutritional-status',
    deworming_store: 'deworming',
    risk_assessment_store: 'risk-assessment',
    risk_assessment_section_update: 'risk-assessment',
    family_planning_store: 'family-planning',
    family_planning_update: 'family-planning',
    maternal_register: 'maternal-care',
    maternal_section_update: 'maternal-care',
};

const ACTION_LABELS = {
    HOUSEHOLD_CREATE: 'Create Household',
    HOUSEHOLD_UPDATE: 'Update Household',
    RESIDENT_CREATE: 'Create Member',
    RESIDENT_UPDATE: 'Update Member',
    PLOT_HOUSEHOLD_WITH_HEAD: 'Plot Household',
    HOUSEHOLD_AMENITIES_UPDATE: 'Update Amenities',
    ENVIRONMENTAL_WATER_SUPPLY_UPDATE: 'Update Environmental Health',
    HEALTH_SERVICE_WRITE: 'Health Record Write',
    HEALTH_WORKER_UPDATE: 'Update Health Worker',
};

/**
 * @param {string} value
 */
export function safeOfflineChangeHref(value) {
    const raw = String(value || '').trim();
    if (!raw || /^(javascript|data|vbscript):/i.test(raw)) {
        return '';
    }
    let path = raw;
    try {
        if (/^[a-z][a-z0-9+.-]*:/i.test(raw) || raw.startsWith('//')) {
            const parsed = new URL(raw, 'http://localhost');
            path = `${parsed.pathname || ''}${parsed.search || ''}`;
        }
    } catch {
        return '';
    }
    const normalized = String(path).replace(/\/+$/, '') || '/';
    const patterns = [
        /^\/household-profiling\/[A-Za-z0-9-]+\/edit$/i,
        /^\/household-profiling\/[A-Za-z0-9-]+\/amenities$/i,
        /^\/household-profiling\/[A-Za-z0-9-]+\/members\/(?:MB-(?:L-[A-Za-z0-9]+|\d+)|m[a-z2-7]{12,})\/edit$/i,
        /^\/household-profiling\/[A-Za-z0-9-]+\/members\/(?:MB-(?:L-[A-Za-z0-9]+|\d+)|m[a-z2-7]{12,})\/(?:child-immunization(?:\/birth-history\/edit)?|school-based-immunization|child-nutrition|nutritional-status(?:\/create)?|deworming|risk-assessment|family-planning|maternal-care)(?:\/.*)?$/i,
        /^\/environmental-health\/household-water-supply(?:\/[A-Za-z0-9-]+\/step-[234])?(?:\?household=[A-Za-z0-9-]+)?$/i,
    ];
    return patterns.some((re) => re.test(normalized) || re.test(path)) ? path : '';
}

function memberDisplayName(record) {
    const payload = record?.payload && typeof record.payload === 'object' ? record.payload : {};
    const named = [payload.first_name, payload.middle_name, payload.last_name]
        .map((part) => String(part || '').trim())
        .filter(Boolean)
        .join(' ');
    if (named) {
        return named;
    }
    const memberNo = localMemberIdFromRecord(record)
        || String(record?.parent_server?.member_no || '').trim();
    return memberNo || '';
}

function healthModuleLabel(record) {
    const action = String(record?.payload?._health_action || '').trim();
    return displayLabelForHealthAction(action) || 'Health Record';
}

export function offlineChangeTitle(record) {
    const type = String(record?.operation_type || '');
    const householdNo = householdNoFromRecord(record) || String(record?.payload?.household_no || '').trim();
    const name = memberDisplayName(record);

    if (type === 'HOUSEHOLD_CREATE' || type === 'HOUSEHOLD_UPDATE' || type === 'PLOT_HOUSEHOLD_WITH_HEAD') {
        return householdNo ? `Household ${householdNo}` : 'Household';
    }
    if (type === 'HOUSEHOLD_AMENITIES_UPDATE') {
        return householdNo ? `Amenities — Household ${householdNo}` : 'Household Amenities';
    }
    if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE') {
        return householdNo ? `Environmental Health — Household ${householdNo}` : 'Environmental Health';
    }
    if (type === 'RESIDENT_CREATE' || type === 'RESIDENT_UPDATE') {
        return name || 'Household Member';
    }
    if (type === 'HEALTH_SERVICE_WRITE') {
        const module = healthModuleLabel(record);
        return name ? `${module} — ${name}` : module;
    }
    if (type === 'HEALTH_WORKER_UPDATE') {
        return name || 'Health Worker';
    }
    return ACTION_LABELS[type] || 'Offline update';
}

export function offlineChangeModule(record) {
    const type = String(record?.operation_type || '');
    if (type === 'HOUSEHOLD_CREATE' || type === 'HOUSEHOLD_UPDATE' || type === 'PLOT_HOUSEHOLD_WITH_HEAD') {
        return 'Household Profiling';
    }
    if (type === 'HOUSEHOLD_AMENITIES_UPDATE') {
        return 'Household Amenities';
    }
    if (type === 'RESIDENT_CREATE' || type === 'RESIDENT_UPDATE') {
        return 'Household Member';
    }
    if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE') {
        return 'Environmental Health';
    }
    if (type === 'HEALTH_SERVICE_WRITE') {
        return healthModuleLabel(record);
    }
    if (type === 'HEALTH_WORKER_UPDATE') {
        return 'User Management';
    }
    return 'Offline';
}

export function offlineChangeActionLabel(record) {
    const type = String(record?.operation_type || '');
    if (type === 'HEALTH_SERVICE_WRITE') {
        const action = String(record?.payload?._health_action || '');
        if (/_update$|_section_update$/.test(action) || action === 'maternal_section_update') {
            return `Update ${healthModuleLabel(record)}`;
        }
        return `Add ${healthModuleLabel(record)}`;
    }
    return ACTION_LABELS[type] || 'Update';
}

/** Record link the UI opens (public form: opaque URL keys where the server issued them). */
export function repairHrefForOperation(record) {
    return publicPath(repairPathForOperation(record));
}

function repairPathForOperation(record) {
    const type = String(record?.operation_type || '');
    const householdNo = householdNoFromRecord(record) || String(record?.payload?.household_no || '').trim();
    const memberNo = localMemberIdFromRecord(record)
        || String(record?.parent_server?.member_no || '').trim();
    const safeHouse = /^[A-Za-z0-9-]+$/.test(householdNo) ? householdNo : '';
    const safeMember = /^MB-(?:L-[A-Za-z0-9]+|\d+)$/i.test(memberNo) ? memberNo : '';

    if ((type === 'HOUSEHOLD_CREATE' || type === 'HOUSEHOLD_UPDATE' || type === 'PLOT_HOUSEHOLD_WITH_HEAD') && safeHouse) {
        return `/household-profiling/${safeHouse}/edit`;
    }
    if (type === 'HOUSEHOLD_AMENITIES_UPDATE' && safeHouse) {
        return `/household-profiling/${safeHouse}/amenities`;
    }
    if ((type === 'RESIDENT_CREATE' || type === 'RESIDENT_UPDATE') && safeHouse && safeMember) {
        return `/household-profiling/${safeHouse}/members/${safeMember}/edit`;
    }
    if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE' && safeHouse) {
        const step = Number(record?.payload?._eh_step || 1);
        if (step >= 2 && step <= 4) {
            return `/environmental-health/household-water-supply/${safeHouse}/step-${step}`;
        }
        return `/environmental-health/household-water-supply?household=${encodeURIComponent(safeHouse)}`;
    }
    if (type === 'HEALTH_SERVICE_WRITE' && safeHouse && safeMember) {
        const action = String(record?.payload?._health_action || '').trim();
        const path = HEALTH_ACTION_PATH[action] || 'child-immunization';
        return `/household-profiling/${safeHouse}/members/${safeMember}/${path}`;
    }
    return '';
}

const SAFE_RETRY_MESSAGES = Object.freeze({
    CSRF_MISMATCH: 'Your session token changed. Reconnect and retry.',
    RETRYABLE_ERROR: 'The server could not save this update temporarily. Retry is available.',
    NETWORK_ERROR: 'Network error while syncing. Retry is available.',
    SERVER_ERROR: 'The server could not save this update temporarily. Retry is available.',
    TIMEOUT: 'The sync request timed out. Retry is available.',
});

/**
 * Safe, user-facing retry reason from stored queue metadata.
 * Never exposes SQL, stack traces, or credentials.
 *
 * @param {object | null | undefined} record
 * @returns {string}
 */
export function safeRetryProblem(record) {
    const code = String(record?.last_safe_error_code || '').trim();
    if (code && SAFE_RETRY_MESSAGES[code]) {
        return SAFE_RETRY_MESSAGES[code];
    }
    const syncError = String(record?.last_sync_error || '').trim();
    if (
        syncError
        && syncError.length <= 180
        && !/password|csrf|token|secret|sql|exception|stack|trace|query exception|PDO/i.test(syncError)
        && !/^[A-Z][A-Z0-9_]+$/.test(syncError)
    ) {
        return syncError;
    }
    if (code && !/password|csrf|token|secret|sql|exception|stack|trace/i.test(code) && SAFE_RETRY_MESSAGES[code] === undefined) {
        // Unknown safe code — generic retry copy, not the raw code string.
        return 'The server could not save this update temporarily. Retry is available.';
    }
    return 'Sync failed temporarily. Retry available.';
}

export function offlineChangeProblem(record, actorRecords = []) {
    const validation = reasonFromValidationErrors(record?.last_validation_errors);
    if (validation) {
        return validation;
    }
    const syncError = String(record?.last_sync_error || record?.last_safe_error_code || '').trim();
    if (record?.status === QUEUE_STATUS.ATTENTION) {
        const hint = attentionCorrectionHint(record);
        if (hint) {
            return hint;
        }
        if (syncError && !/password|csrf|token|secret|sql|exception|stack|trace/i.test(syncError)) {
            return syncError;
        }
    }
    if (isUnresolvedLocalHouseholdParent(record, actorRecords)) {
        const hh = householdNoFromRecord(record) || 'household';
        return `Waiting for Household ${hh} to sync.`;
    }
    if (isUnresolvedLocalParent(record)) {
        const name = memberDisplayName(record) || 'member';
        return `Waiting for ${name} to sync.`;
    }
    if (record?.status === QUEUE_STATUS.RETRY) {
        return safeRetryProblem(record);
    }
    return '';
}

/**
 * Synthetic summary attention prompts must not appear as Offline Changes rows.
 * @param {object | null | undefined} item
 * @returns {boolean}
 */
export function isSyntheticOfflineChangeItem(item) {
    const id = String(item?.id || item?.operation_id || '').trim();
    const code = String(item?.code || item?.last_safe_error_code || '').trim();
    if (id === 'queue:unsynced' || id.startsWith('queue:unsynced')) {
        return true;
    }
    if (code === 'QUEUE_NEEDS_ATTENTION') {
        return true;
    }
    return false;
}

export function offlineChangeStatus(record, actorRecords = []) {
    const status = String(record?.status || '');
    if (status === QUEUE_STATUS.ATTENTION) {
        return { key: 'attention', label: 'Needs attention' };
    }
    if (status === QUEUE_STATUS.SYNCING) {
        return { key: 'syncing', label: 'Syncing' };
    }
    if (status === QUEUE_STATUS.RETRY) {
        return { key: 'retry', label: 'Failed / Retry available' };
    }
    if (isUnresolvedLocalHouseholdParent(record, actorRecords)) {
        return { key: 'waiting-household', label: 'Waiting for Household' };
    }
    if (isUnresolvedLocalParent(record)) {
        return { key: 'waiting-member', label: 'Waiting for Member' };
    }
    return { key: 'waiting', label: 'Waiting to sync' };
}

export function describeOfflineChange(record, actorRecords = []) {
    const status = offlineChangeStatus(record, actorRecords);
    const problem = offlineChangeProblem(record, actorRecords);
    const href = safeOfflineChangeHref(repairHrefForOperation(record));
    const attention = status.key === 'attention' || status.key === 'retry';
    const ctaLabel = attention && href ? 'Review Record' : (href ? 'View' : '');
    return {
        id: String(record?.local_id || record?.operation_id || ''),
        operation_id: String(record?.operation_id || record?.local_id || ''),
        operation_type: String(record?.operation_type || ''),
        title: offlineChangeTitle(record),
        module: offlineChangeModule(record),
        action: offlineChangeActionLabel(record),
        status_key: status.key,
        status_label: status.label,
        problem,
        validation_errors: sanitizeValidationErrors(record?.last_validation_errors),
        href,
        cta_label: ctaLabel,
        show_review: Boolean(href && ctaLabel),
        show_retry: status.key === 'retry',
        show_discard: status.key !== 'syncing',
        group: attention ? 'attention' : 'waiting',
        household_no: householdNoFromRecord(record) || '',
        member_no: localMemberIdFromRecord(record) || String(record?.parent_server?.member_no || ''),
        member_name: memberDisplayName(record),
        created_at_client: record?.created_at_client || null,
    };
}

export async function listOfflineChangesForActor(actorId) {
    const rows = await listOperationsForActor(actorId);
    const unfinished = rows.filter((row) => (
        row
        && row.status !== 'synced'
        && [
            QUEUE_STATUS.PENDING,
            QUEUE_STATUS.SYNCING,
            QUEUE_STATUS.RETRY,
            QUEUE_STATUS.ATTENTION,
        ].includes(row.status)
    ));
    unfinished.sort((a, b) => {
        const aAtt = a.status === QUEUE_STATUS.ATTENTION ? 0 : 1;
        const bAtt = b.status === QUEUE_STATUS.ATTENTION ? 0 : 1;
        if (aAtt !== bAtt) {
            return aAtt - bAtt;
        }
        return String(a.created_at_client || '').localeCompare(String(b.created_at_client || ''));
    });
    return unfinished.map((row) => describeOfflineChange(row, unfinished));
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function problemHtml(problem) {
    const text = String(problem || '').trim();
    if (!text) {
        return '';
    }
    if (text.includes('• ')) {
        const items = text.split(/\s*•\s*/).map((part) => part.trim()).filter(Boolean);
        return `<ul class="lml-offline-changes__problems">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`;
    }
    return `<p class="lml-offline-changes__problem">${escapeHtml(text)}</p>`;
}

/**
 * @param {Array<object>} items
 * @param {'all'|'waiting'|'attention'} filter
 */
export function renderOfflineChangesListHtml(items, filter = 'all') {
    const rows = Array.isArray(items) ? items : [];
    const filtered = rows.filter((item) => {
        if (filter === 'attention') {
            return item.group === 'attention';
        }
        if (filter === 'waiting') {
            return item.group === 'waiting';
        }
        return true;
    });

    if (!filtered.length) {
        return `<p class="lml-offline-changes__empty" data-lml-offline-changes-empty>No offline changes in this list.</p>`;
    }

    const attention = filtered.filter((item) => item.group === 'attention');
    const waiting = filtered.filter((item) => item.group === 'waiting');
    const sections = [];

    const renderSection = (title, list) => {
        if (!list.length) {
            return;
        }
        sections.push(`<section class="lml-offline-changes__section" data-section="${escapeHtml(title)}">
<h3 class="lml-offline-changes__section-title">${escapeHtml(title)} (${list.length})</h3>
<ul class="lml-offline-changes__items">
${list.map((item) => {
    const iconClass = item.group === 'attention' ? 'bi-exclamation-circle' : 'bi-clock';
    const actions = [];
    if (item.show_review !== false && item.href && item.cta_label) {
        actions.push(`<a class="btn btn-outline-primary btn-sm lml-focus-ring lml-offline-changes__cta" href="${escapeHtml(item.href)}" data-lml-offline-change-cta data-hh-nav="offline-change" data-offline-local-id="${escapeHtml(item.id)}">${escapeHtml(item.cta_label)}</a>`);
    }
    if (item.show_retry) {
        actions.push(`<button type="button" class="btn btn-outline-secondary btn-sm lml-focus-ring lml-offline-changes__retry" data-lml-offline-change-retry data-offline-local-id="${escapeHtml(item.id)}">Retry</button>`);
    }
    if (item.show_discard !== false && item.id) {
        actions.push(`<button type="button" class="btn btn-outline-danger btn-sm lml-focus-ring lml-offline-changes__discard" data-lml-offline-change-discard data-offline-local-id="${escapeHtml(item.id)}">Don't Sync</button>`);
    }
    const actionsHtml = actions.length
        ? `<div class="lml-offline-changes__actions-row">${actions.join('')}</div>`
        : '';
    return `<li class="lml-offline-changes__item" data-offline-change-id="${escapeHtml(item.id)}" data-group="${escapeHtml(item.group)}" data-status="${escapeHtml(item.status_key)}">
<div class="lml-offline-changes__item-head">
<i class="bi ${iconClass} lml-offline-changes__icon" aria-hidden="true"></i>
<div class="lml-offline-changes__meta">
<p class="lml-offline-changes__title">${escapeHtml(item.title)}</p>
<p class="lml-offline-changes__module">${escapeHtml(item.module)}</p>
<p class="lml-offline-changes__action">${escapeHtml(item.action)}</p>
<p class="lml-offline-changes__status" data-status="${escapeHtml(item.status_key)}">${escapeHtml(item.status_label)}</p>
</div>
</div>
${item.problem ? `<div class="lml-offline-changes__problem-block"><span class="lml-offline-changes__problem-label">Problem</span>${problemHtml(item.problem)}</div>` : ''}
${actionsHtml}
</li>`;
}).join('')}
</ul>
</section>`);
    };

    if (filter === 'all') {
        if (attention.length) {
            renderSection('Needs Attention', attention);
        }
        if (waiting.length) {
            renderSection('Waiting to Sync', waiting);
        }
    } else if (filter === 'attention') {
        renderSection('Needs Attention', attention);
    } else {
        renderSection('Waiting to Sync', waiting);
    }

    return sections.join('') || `<p class="lml-offline-changes__empty">No offline changes in this list.</p>`;
}

export function offlineChangesCounts(items) {
    const rows = Array.isArray(items) ? items : [];
    return {
        all: rows.length,
        waiting: rows.filter((item) => item.group === 'waiting').length,
        attention: rows.filter((item) => item.group === 'attention').length,
    };
}

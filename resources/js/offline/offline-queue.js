/**
 * Durable offline operation queue.
 *
 * Actor-bound. CSRF tokens are never persisted. Authoritative server PKs
 * and member numbers are never minted here.
 */

import { openOfflineDb, requestValue, withStore } from './offline-db.js';
import { publicPath } from './offline-url-keys.js';

export const QUEUE_STATUS = {
    PENDING: 'pending',
    SYNCING: 'syncing',
    RETRY: 'retry',
    ATTENTION: 'attention',
};

const SECRET_KEY_PATTERN =
    /password|passwd|secret|token|csrf|_token|session|cookie|authorization|bearer|app[_-]?key|lmlinga_at_rest|otp|reset/i;

const AUTHORITATIVE_PAYLOAD_KEYS = new Set([
    'id',
    'household_id',
    'resident_id',
    'member_no',
    'worker_id',
    'user_id',
    'appointment_id',
    'worker_appointment_zone_id',
    'zone_id',
]);

const STALE_SYNCING_MS = 15_000;

const BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000];

/**
 * @param {unknown} value
 */
export function createOperationId(value) {
    if (typeof value === 'string' && isUuid(value)) {
        return value.toLowerCase();
    }

    const cryptoObj = typeof globalThis !== 'undefined' ? globalThis.crypto : null;
    if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
        return cryptoObj.randomUUID();
    }

    return fallbackUuid();
}

function fallbackUuid() {
    const bytes = new Uint8Array(16);
    const cryptoObj = typeof globalThis !== 'undefined' ? globalThis.crypto : null;
    if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
        cryptoObj.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i += 1) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function isUuid(value) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
        String(value || '').trim(),
    );
}

export function backoffMsForRetry(retryCount) {
    const index = Math.max(0, Math.min(Number(retryCount) || 0, BACKOFF_MS.length - 1));
    return BACKOFF_MS[index];
}

/**
 * @param {unknown} value
 * @returns {unknown}
 */
export function stripSecrets(value) {
    if (Array.isArray(value)) {
        return value.map((item) => stripSecrets(item));
    }

    if (!value || typeof value !== 'object') {
        return value;
    }

    const out = {};
    Object.keys(value).forEach((key) => {
        if (SECRET_KEY_PATTERN.test(key)) {
            return;
        }
        out[key] = stripSecrets(value[key]);
    });
    return out;
}

function stripAuthoritativeIds(payload) {
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return {};
    }

    const out = {};
    Object.keys(payload).forEach((key) => {
        if (AUTHORITATIVE_PAYLOAD_KEYS.has(key)) {
            return;
        }
        out[key] = payload[key];
    });
    return out;
}

function nowIso() {
    return new Date().toISOString();
}

function nowMs() {
    return Date.now();
}

function cloneRecord(record) {
    return record ? JSON.parse(JSON.stringify(record)) : null;
}

function assertNoSecrets(record) {
    const walk = (value, keyHint = '') => {
        if (value == null) {
            return;
        }
        if (typeof value === 'string') {
            // Error codes like CSRF_MISMATCH are safe metadata; only key names
            // (and password-like values under secret keys) are refused.
            if (SECRET_KEY_PATTERN.test(keyHint) && value.trim() !== '') {
                throw new Error('queue-refused-secret');
            }
            return;
        }
        if (Array.isArray(value)) {
            value.forEach((item) => walk(item, keyHint));
            return;
        }
        if (typeof value === 'object') {
            Object.entries(value).forEach(([key, child]) => {
                if (SECRET_KEY_PATTERN.test(key)) {
                    throw new Error('queue-refused-secret');
                }
                walk(child, key);
            });
        }
    };
    walk(record);
}

export function logicalDedupeKey(record) {
    if (!record || typeof record !== 'object') {
        return '';
    }
    const type = String(record.operation_type || '');
    const parent = record.parent_server && typeof record.parent_server === 'object'
        ? record.parent_server
        : {};
    const payload = record.payload && typeof record.payload === 'object'
        ? record.payload
        : {};

    if (type === 'HOUSEHOLD_CREATE') {
        const householdNo = String(payload.household_no || parent.household_no || '').trim();
        return householdNo ? `${type}:${householdNo}` : '';
    }
    if (type === 'HOUSEHOLD_UPDATE') {
        return `${type}:${parent.household_id || parent.household_no || payload.household_no || ''}`;
    }
    if (type === 'RESIDENT_UPDATE') {
        const localMemberId = localMemberIdFromParts(payload, parent);
        return `${type}:${canonicalLocalMemberId(localMemberId) || parent.resident_id || parent.member_no || ''}`;
    }
    if (type === 'HEALTH_WORKER_UPDATE') {
        return `${type}:${parent.user_id || ''}`;
    }
    if (type === 'HOUSEHOLD_AMENITIES_UPDATE') {
        return `${type}:${parent.household_id || parent.household_no || payload.household_no || ''}`;
    }
    if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE') {
        return `${type}:${parent.household_id || parent.household_no || payload.household_no || ''}:${payload._eh_step || ''}`;
    }
    if (type === 'HEALTH_SERVICE_WRITE') {
        const localMemberId = localMemberIdFromParts(payload, parent);
        return [
            type,
            canonicalLocalMemberId(localMemberId) || parent.resident_id || parent.member_no || '',
            payload._health_action || '',
            payload._health_section || '',
            payload._health_visit_id || '',
            payload._health_assessment_id || '',
        ].join(':');
    }
    if (type === 'RESIDENT_CREATE' && payload.client_local_member_id) {
        return `${type}:${canonicalLocalMemberId(payload.client_local_member_id) || payload.client_local_member_id}`;
    }
    return '';
}

const DEDUPE_STATUSES = new Set([
    QUEUE_STATUS.PENDING,
    QUEUE_STATUS.RETRY,
    QUEUE_STATUS.SYNCING,
    QUEUE_STATUS.ATTENTION,
]);

const REPAIRABLE_ATTENTION_CODES = new Set(['', 'VALIDATION_FAILED', 'MALFORMED']);

const UNSAFE_ATTENTION_CODES = new Set([
    'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'IDEMPOTENCY_ACTOR_MISMATCH',
]);

/**
 * ATTENTION rows may participate in logical repair/coalesce only when safe.
 * @param {object | null | undefined} row
 * @returns {boolean}
 */
function canCoalesceExistingRow(row) {
    if (!row) {
        return false;
    }
    if (row.status !== QUEUE_STATUS.ATTENTION) {
        return true;
    }
    const code = String(row.last_safe_error_code || '').trim();
    if (UNSAFE_ATTENTION_CODES.has(code)) {
        return false;
    }
    return REPAIRABLE_ATTENTION_CODES.has(code) || code === '';
}

/**
 * @param {object} input
 */
export async function enqueueOperation(input) {
    const actorId = Number(input.actor_id);
    if (!Number.isInteger(actorId) || actorId <= 0) {
        throw new Error('queue-actor-required');
    }

    const operationType = String(input.operation_type || '');
    if (!operationType) {
        throw new Error('queue-operation-type-required');
    }

    const payload = stripAuthoritativeIds(stripSecrets(input.payload || {}));
    const baseSnapshot = input.base_snapshot
        ? stripSecrets(input.base_snapshot)
        : null;
    const parentServer = input.parent_server
        ? stripSecrets(input.parent_server)
        : null;

    const record = {
        local_id: createOperationId(input.local_id),
        operation_id: createOperationId(input.operation_id),
        schema_version: 1,
        operation_type: operationType,
        payload,
        base_snapshot: baseSnapshot,
        parent_server: parentServer,
        actor_id: actorId,
        actor_username: typeof input.actor_username === 'string' ? input.actor_username : '',
        created_at_client: input.created_at_client || nowIso(),
        updated_at_client: nowIso(),
        retry_count: 0,
        next_retry_at: null,
        status: QUEUE_STATUS.PENDING,
        last_safe_error_code: null,
    };

    assertNoSecrets(record);

    if (operationType === 'RESIDENT_UPDATE') {
        const mergedCreate = await mergeResidentUpdateIntoPendingCreate(actorId, record);
        if (mergedCreate) {
            return mergedCreate;
        }
    }

    if (operationType === 'HOUSEHOLD_UPDATE') {
        const mergedCreate = await mergeHouseholdUpdateIntoPendingCreate(actorId, record);
        if (mergedCreate) {
            return mergedCreate;
        }
    }

    const dedupeKey = logicalDedupeKey(record);
    if (dedupeKey) {
        const existing = (await listOperationsForActor(actorId)).find(
            (row) => DEDUPE_STATUSES.has(row.status)
                && canCoalesceExistingRow(row)
                && logicalDedupeKey(row) === dedupeKey,
        );
        if (existing) {
            const updated = await updateOperation(existing.local_id, {
                payload: record.payload,
                base_snapshot: record.base_snapshot,
                parent_server: record.parent_server,
                status: QUEUE_STATUS.PENDING,
                retry_count: 0,
                next_retry_at: null,
                last_safe_error_code: null,
                last_validation_errors: null,
                last_sync_error: null,
            });
            return updated || cloneRecord(existing);
        }
    }

    const db = await openOfflineDb();
    try {
        await withStore(db, 'readwrite', (store) => requestValue(store.put(record)));
    } finally {
        db.close?.();
    }

    return cloneRecord(record);
}

export async function getOperation(localId) {
    const db = await openOfflineDb();
    try {
        const record = await withStore(db, 'readonly', (store) => requestValue(store.get(localId)));
        return cloneRecord(record || null);
    } finally {
        db.close?.();
    }
}

export async function listOperations() {
    const db = await openOfflineDb();
    try {
        const records = await withStore(db, 'readonly', (store) => requestValue(store.getAll()));
        const list = Array.isArray(records) ? records.map((item) => cloneRecord(item)) : [];
        list.sort((a, b) => String(a.created_at_client).localeCompare(String(b.created_at_client)));
        return list;
    } finally {
        db.close?.();
    }
}

export async function listOperationsForActor(actorId) {
    const id = Number(actorId);
    const all = await listOperations();
    return all.filter((item) => Number(item.actor_id) === id);
}

export function isReplayable(record, atMs = nowMs()) {
    if (!record) {
        return false;
    }
    if (record.status === QUEUE_STATUS.PENDING) {
        return true;
    }
    if (record.status === QUEUE_STATUS.RETRY) {
        const next = Number(record.next_retry_at || 0);
        return !next || next <= atMs;
    }
    return false;
}

export function compareReplayOrder(a, b) {
    const rank = (record) => {
        const type = String(record?.operation_type || '');
        // Household / plot creates must precede dependent member creates.
        if (type === 'HOUSEHOLD_CREATE' || type === 'PLOT_HOUSEHOLD_WITH_HEAD') {
            return 0;
        }
        if (type === 'RESIDENT_CREATE') {
            return 1;
        }
        if (type === 'RESIDENT_UPDATE' || type === 'HOUSEHOLD_UPDATE' || type === 'HEALTH_WORKER_UPDATE') {
            return 2;
        }
        if (type === 'HEALTH_SERVICE_WRITE') {
            return 3;
        }
        // EH and amenities after household resolution (blocked separately if needed).
        return 4;
    };
    const diff = rank(a) - rank(b);
    if (diff !== 0) {
        return diff;
    }
    return String(a?.created_at_client || '').localeCompare(String(b?.created_at_client || ''));
}

export async function listReplayableForActor(actorId, atMs = nowMs()) {
    const records = await listOperationsForActor(actorId);
    return records.filter((item) => isReplayable(item, atMs)).sort(compareReplayOrder);
}

export function canonicalLocalMemberId(value) {
    const id = String(value || '').trim();
    return /^MB-L-[A-Za-z0-9]+$/i.test(id) ? id.toUpperCase() : '';
}

export function sameLocalMemberId(a, b) {
    const left = canonicalLocalMemberId(a);
    const right = canonicalLocalMemberId(b);
    return Boolean(left) && left === right;
}

export function localMemberIdFromParts(payload, parentServer) {
    const candidates = [
        payload?.client_local_member_id,
        parentServer?.client_local_member_id,
        parentServer?.local_member_id,
        parentServer?.member_no,
    ];
    for (const value of candidates) {
        if (/^MB-L-[A-Za-z0-9]+$/i.test(String(value || '').trim())) {
            return String(value).trim();
        }
    }
    return '';
}

export function localMemberIdFromRecord(record) {
    return localMemberIdFromParts(record?.payload, record?.parent_server);
}

export function isUnresolvedLocalParent(record) {
    if (!record || record.operation_type === 'RESIDENT_CREATE') {
        return false;
    }
    const localId = localMemberIdFromRecord(record);
    if (!localId) {
        return false;
    }
    const residentId = Number(record.parent_server?.resident_id || 0);
    const memberNo = String(record.parent_server?.member_no || '').trim();
    if (Number.isInteger(residentId) && residentId > 0 && /^MB-\d+$/i.test(memberNo)) {
        return false;
    }
    return true;
}

const HOUSEHOLD_PARENT_DEPENDENT_TYPES = new Set([
    'RESIDENT_CREATE',
    'RESIDENT_UPDATE',
    'HEALTH_SERVICE_WRITE',
    'HOUSEHOLD_AMENITIES_UPDATE',
    'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
]);

const UNRESOLVED_HOUSEHOLD_CREATE_STATUSES = new Set([
    QUEUE_STATUS.PENDING,
    QUEUE_STATUS.RETRY,
    QUEUE_STATUS.SYNCING,
    QUEUE_STATUS.ATTENTION,
]);

/**
 * Block member (and related) ops until the parent HOUSEHOLD_CREATE / plot create
 * for the same household_no has left the queue with a real household_id rebound.
 * Does not apply when parent_server already carries a positive household_id.
 */
export function isUnresolvedLocalHouseholdParent(record, actorRecords = []) {
    if (!record || !HOUSEHOLD_PARENT_DEPENDENT_TYPES.has(String(record.operation_type || ''))) {
        return false;
    }
    const householdId = Number(record.parent_server?.household_id || 0);
    if (Number.isInteger(householdId) && householdId > 0) {
        return false;
    }
    const householdNo = householdNoFromRecord(record);
    if (!householdNo) {
        return false;
    }
    const rows = Array.isArray(actorRecords) ? actorRecords : [];
    return rows.some((row) => (
        row
        && row.local_id !== record.local_id
        && (row.operation_type === 'HOUSEHOLD_CREATE' || row.operation_type === 'PLOT_HOUSEHOLD_WITH_HEAD')
        && UNRESOLVED_HOUSEHOLD_CREATE_STATUSES.has(row.status)
        && sameHouseholdNo(householdNoFromRecord(row), householdNo)
    ));
}

export function isRepairableAttentionCreate(row) {
    if (!row || row.status !== QUEUE_STATUS.ATTENTION || row.operation_type !== 'RESIDENT_CREATE') {
        return false;
    }
    const code = String(row.last_safe_error_code || '').trim();
    if (UNSAFE_ATTENTION_CODES.has(code)) {
        return false;
    }
    return REPAIRABLE_ATTENTION_CODES.has(code);
}

export function isRepairableAttentionHouseholdCreate(row) {
    if (!row || row.status !== QUEUE_STATUS.ATTENTION || row.operation_type !== 'HOUSEHOLD_CREATE') {
        return false;
    }
    const code = String(row.last_safe_error_code || '').trim();
    if (UNSAFE_ATTENTION_CODES.has(code)) {
        return false;
    }
    return REPAIRABLE_ATTENTION_CODES.has(code);
}

function isMergeableResidentCreate(row) {
    if (!row) {
        return false;
    }
    if (row.status === QUEUE_STATUS.ATTENTION) {
        return isRepairableAttentionCreate(row);
    }
    return DEDUPE_STATUSES.has(row.status);
}

function isMergeableHouseholdCreate(row) {
    if (!row) {
        return false;
    }
    if (row.status === QUEUE_STATUS.ATTENTION) {
        return isRepairableAttentionHouseholdCreate(row);
    }
    return DEDUPE_STATUSES.has(row.status);
}

export function sameHouseholdNo(a, b) {
    const left = String(a || '').trim().replace(/^HH-/i, '');
    const right = String(b || '').trim().replace(/^HH-/i, '');
    return Boolean(left) && left === right;
}

export function householdNoFromRecord(record) {
    const payload = record?.payload && typeof record.payload === 'object' ? record.payload : {};
    const parent = record?.parent_server && typeof record.parent_server === 'object' ? record.parent_server : {};
    return String(payload.household_no || parent.household_no || '').trim();
}

async function mergeHouseholdUpdateIntoPendingCreate(actorId, record) {
    const householdNo = householdNoFromRecord(record);
    if (!householdNo) {
        return null;
    }
    const existing = (await listOperationsForActor(actorId)).find((row) => (
        isMergeableHouseholdCreate(row)
        && row.operation_type === 'HOUSEHOLD_CREATE'
        && sameHouseholdNo(row.payload?.household_no, householdNo)
    ));
    if (!existing) {
        return null;
    }
    const updated = await updateOperation(existing.local_id, {
        payload: {
            ...existing.payload,
            ...record.payload,
            household_no: existing.payload?.household_no || householdNo,
        },
        status: QUEUE_STATUS.PENDING,
        retry_count: 0,
        next_retry_at: null,
        last_safe_error_code: null,
        last_validation_errors: null,
    });
    return updated || cloneRecord(existing);
}

/**
 * Keep only safe field → message[] maps from a Laravel-style 422 errors object.
 * @param {unknown} errors
 * @returns {Record<string, string[]> | null}
 */
export function sanitizeValidationErrors(errors) {
    if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
        return null;
    }
    const out = {};
    for (const [rawKey, rawMessages] of Object.entries(errors)) {
        const key = String(rawKey || '').trim();
        if (!/^[A-Za-z0-9_.-]{1,64}$/.test(key)) {
            continue;
        }
        if (/password|token|csrf|secret|authorization|cookie/i.test(key)) {
            continue;
        }
        const list = Array.isArray(rawMessages) ? rawMessages : [rawMessages];
        const messages = [];
        for (const item of list) {
            if (typeof item !== 'string') {
                continue;
            }
            const trimmed = item.trim().replace(/\s+/g, ' ').slice(0, 200);
            if (!trimmed || /password|csrf|token|secret|LMLINGA_AT_REST/i.test(trimmed)) {
                continue;
            }
            messages.push(trimmed);
            if (messages.length >= 3) {
                break;
            }
        }
        if (messages.length) {
            out[key] = messages;
        }
    }
    return Object.keys(out).length ? out : null;
}

export function reasonFromValidationErrors(errors) {
    const cleaned = sanitizeValidationErrors(errors);
    if (!cleaned) {
        return '';
    }
    const lines = [];
    for (const messages of Object.values(cleaned)) {
        for (const message of messages) {
            lines.push(message);
            if (lines.length >= 4) {
                break;
            }
        }
        if (lines.length >= 4) {
            break;
        }
    }
    if (!lines.length) {
        return '';
    }
    if (lines.length === 1) {
        return lines[0];
    }
    return lines.map((line) => `• ${line}`).join(' ');
}

const HOUSEHOLD_ZONES = Object.freeze(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']);

function householdShellCorrectionHint(payload) {
    const data = payload && typeof payload === 'object' ? payload : {};
    const text = (key) => String(data[key] ?? '').trim();
    if (!text('zone')) {
        return 'Zone is required.';
    }
    if (!HOUSEHOLD_ZONES.includes(text('zone'))) {
        return 'Please select a valid zone.';
    }
    // Do not invent "Street is required" — street is schema-gated online and
    // may be absent from the create form. Server validation errors still surface
    // via last_validation_errors when street truly is required.
    if (text('street').length > 150) {
        return 'Street may not be greater than 150 characters.';
    }
    if (!text('date_registered')) {
        return 'Date registered is required.';
    }
    return '';
}

function residentCorrectionHint(payload) {
    const data = payload && typeof payload === 'object' ? payload : {};
    const sex = data.sex;
    if (sex == null || String(sex).trim() === '') {
        return 'Sex is required.';
    }
    if (data.first_name == null || String(data.first_name).trim() === '') {
        return 'First name is required.';
    }
    if (data.last_name == null || String(data.last_name).trim() === '') {
        return 'Last name is required.';
    }
    return '';
}

export function attentionCorrectionHint(record) {
    const fromServer = reasonFromValidationErrors(record?.last_validation_errors);
    if (fromServer) {
        return fromServer;
    }
    const type = String(record?.operation_type || '');
    const payload = record?.payload && typeof record.payload === 'object' ? record.payload : {};
    if (type === 'HOUSEHOLD_CREATE' || type === 'HOUSEHOLD_UPDATE') {
        // Never invent resident "Sex is required" for Household shell ops.
        return householdShellCorrectionHint(payload);
    }
    if (
        type === 'RESIDENT_CREATE'
        || type === 'RESIDENT_UPDATE'
        || type === 'PLOT_HOUSEHOLD_WITH_HEAD'
    ) {
        return residentCorrectionHint(payload);
    }
    return '';
}

export function attentionUiDetail(record) {
    const payload = record?.payload && typeof record.payload === 'object' ? record.payload : {};
    const parent = record?.parent_server && typeof record.parent_server === 'object' ? record.parent_server : {};
    const householdNo = String(parent.household_no || payload.household_no || '').trim();
    const memberNo = localMemberIdFromRecord(record) || String(parent.member_no || '').trim();
    const memberName = [payload.first_name, payload.middle_name, payload.last_name]
        .map((part) => String(part || '').trim())
        .filter(Boolean)
        .join(' ');
    const safeHouse = /^[A-Za-z0-9-]+$/.test(householdNo) ? householdNo : '';
    const safeMember = /^MB-(?:L-[A-Za-z0-9]+|\d+)$/i.test(memberNo) ? memberNo : '';
    const type = String(record?.operation_type || '');
    let href = '';
    let navKind = '';
    if ((type === 'HOUSEHOLD_CREATE' || type === 'HOUSEHOLD_UPDATE' || type === 'PLOT_HOUSEHOLD_WITH_HEAD') && safeHouse) {
        href = `/household-profiling/${safeHouse}/edit`;
        navKind = 'household-edit';
    } else if (type === 'HOUSEHOLD_AMENITIES_UPDATE' && safeHouse) {
        href = `/household-profiling/${safeHouse}/amenities`;
        navKind = 'amenities';
    } else if (type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE' && safeHouse) {
        const step = Number(payload._eh_step || 1);
        href = step >= 2 && step <= 4
            ? `/environmental-health/household-water-supply/${safeHouse}/step-${step}`
            : `/environmental-health/household-water-supply?household=${encodeURIComponent(safeHouse)}`;
        navKind = 'environmental-health';
    } else if (type === 'HEALTH_SERVICE_WRITE' && safeHouse && safeMember) {
        const action = String(payload._health_action || '').trim();
        const pathMap = {
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
        href = `/household-profiling/${safeHouse}/members/${safeMember}/${pathMap[action] || 'child-immunization'}`;
        navKind = 'health-record';
    } else if (safeHouse && safeMember) {
        href = `/household-profiling/${safeHouse}/members/${safeMember}/edit`;
        navKind = 'edit-member';
    }
    return {
        operation_type: type,
        household_no: householdNo,
        member_no: memberNo,
        member_name: memberName,
        href: publicPath(href),
        nav_kind: navKind,
        reason: attentionCorrectionHint(record),
        validation_errors: sanitizeValidationErrors(record?.last_validation_errors),
    };
}

export async function listAttentionForActor(actorId) {
    return (await listOperationsForActor(actorId)).filter((item) => item.status === QUEUE_STATUS.ATTENTION);
}

async function mergeResidentUpdateIntoPendingCreate(actorId, record) {
    const localId = localMemberIdFromRecord(record);
    if (!localId) {
        return null;
    }
    const existing = (await listOperationsForActor(actorId)).find((row) => (
        isMergeableResidentCreate(row)
        && row.operation_type === 'RESIDENT_CREATE'
        && sameLocalMemberId(row.payload?.client_local_member_id, localId)
    ));
    if (!existing) {
        return null;
    }
    const updated = await updateOperation(existing.local_id, {
        payload: {
            ...existing.payload,
            ...record.payload,
            client_local_member_id: existing.payload?.client_local_member_id || localId,
        },
        status: QUEUE_STATUS.PENDING,
        retry_count: 0,
        next_retry_at: null,
        last_safe_error_code: null,
        last_validation_errors: null,
    });
    return updated || cloneRecord(existing);
}

export async function applyServerIdentityToDependents(actorId, localMemberId, identities = {}) {
    const localId = String(localMemberId || '').trim();
    if (!/^MB-L-[A-Za-z0-9]+$/i.test(localId)) {
        return [];
    }
    const residentId = Number(identities.resident_pk || identities.resident_id || 0);
    const memberNo = String(identities.member_no || '').trim();
    const fieldHash = typeof identities.field_hash === 'string' ? identities.field_hash.trim() : '';
    if ((!Number.isInteger(residentId) || residentId <= 0) && !/^MB-\d+$/i.test(memberNo)) {
        return [];
    }

    const updated = [];
    const records = await listOperationsForActor(actorId);
    for (const row of records) {
        if (row.operation_type === 'RESIDENT_CREATE') {
            continue;
        }
        if (!sameLocalMemberId(localMemberIdFromRecord(row), localId)) {
            continue;
        }
        const parent = { ...(row.parent_server || {}) };
        if (Number.isInteger(residentId) && residentId > 0) {
            parent.resident_id = residentId;
        }
        if (/^MB-\d+$/i.test(memberNo)) {
            parent.member_no = memberNo;
        }
        const patch = { parent_server: parent };
        if (fieldHash && row.operation_type === 'RESIDENT_UPDATE') {
            patch.base_snapshot = { ...(row.base_snapshot || {}), field_hash: fieldHash };
        }
        const next = await updateOperation(row.local_id, patch);
        if (next) {
            updated.push(next);
        }
    }
    return updated;
}

/**
 * After HOUSEHOLD_CREATE (or plot) succeeds: stamp real household_id onto
 * every pending dependent op that still keys only by household_no.
 */
export async function applyHouseholdIdentityToDependents(actorId, householdNo, householdPk) {
    const no = String(householdNo || '').trim();
    const pk = Number(householdPk);
    if (!no || !Number.isInteger(pk) || pk <= 0) {
        return [];
    }
    const updated = [];
    const records = await listOperationsForActor(actorId);
    for (const row of records) {
        if (row.operation_type === 'HOUSEHOLD_CREATE' || row.operation_type === 'PLOT_HOUSEHOLD_WITH_HEAD') {
            continue;
        }
        if (!HOUSEHOLD_PARENT_DEPENDENT_TYPES.has(String(row.operation_type || ''))) {
            continue;
        }
        if (!sameHouseholdNo(householdNoFromRecord(row), no)) {
            continue;
        }
        const parent = { ...(row.parent_server || {}), household_id: pk, household_no: no };
        const next = await updateOperation(row.local_id, { parent_server: parent });
        if (next) {
            updated.push(next);
        }
    }
    return updated;
}

export async function countPendingVisible(actorId = null) {
    const records = actorId == null ? await listOperations() : await listOperationsForActor(actorId);
    return records.filter((item) => item.status !== 'completed').length;
}

export async function updateOperation(localId, patch) {
    const db = await openOfflineDb();
    try {
        const updated = await withStore(db, 'readwrite', async (store) => {
            const current = await requestValue(store.get(localId));
            if (!current) {
                return null;
            }
            const next = {
                ...current,
                ...patch,
                operation_id: current.operation_id,
                local_id: current.local_id,
                actor_id: current.actor_id,
                updated_at_client: nowIso(),
            };
            const cleaned = stripSecrets(next);
            assertNoSecrets(cleaned);
            await requestValue(store.put(cleaned));
            return cleaned;
        });
        return cloneRecord(updated);
    } finally {
        db.close?.();
    }
}

export async function deleteOperation(localId) {
    const db = await openOfflineDb();
    try {
        await withStore(db, 'readwrite', (store) => requestValue(store.delete(localId)));
    } finally {
        db.close?.();
    }
}

export async function markSyncing(localId) {
    return updateOperation(localId, {
        status: QUEUE_STATUS.SYNCING,
        last_safe_error_code: null,
    });
}

export async function markRetry(localId, code, retryCount) {
    const nextCount = Number(retryCount) || 0;
    return updateOperation(localId, {
        status: QUEUE_STATUS.RETRY,
        retry_count: nextCount,
        next_retry_at: nowMs() + backoffMsForRetry(nextCount),
        last_safe_error_code: safeErrorCode(code),
    });
}

export async function markAttention(localId, code, validationErrors = null) {
    const patch = {
        status: QUEUE_STATUS.ATTENTION,
        next_retry_at: null,
        last_safe_error_code: safeErrorCode(code),
    };
    const cleaned = sanitizeValidationErrors(validationErrors);
    patch.last_validation_errors = cleaned;
    return updateOperation(localId, patch);
}

export async function recoverStaleSyncing(_maxAgeMs = STALE_SYNCING_MS) {
    const records = await listOperations();
    const recovered = [];

    for (const record of records) {
        if (record.status !== QUEUE_STATUS.SYNCING) {
            continue;
        }
        recovered.push(
            await updateOperation(record.local_id, {
                status: QUEUE_STATUS.PENDING,
                last_safe_error_code: null,
            }),
        );
    }

    return recovered;
}

/**
 * Give attention/MALFORMED rows one automatic retry after a client or server
 * classification fix. Truly malformed envelopes fail closed again and stay put.
 */
export async function recoverMalformedAttentionOnce() {
    const records = await listOperations();
    const recovered = [];

    for (const record of records) {
        if (record.status !== QUEUE_STATUS.ATTENTION) {
            continue;
        }
        if (record.last_safe_error_code !== 'MALFORMED') {
            continue;
        }
        if (record.malformed_auto_retried) {
            continue;
        }

        recovered.push(
            await updateOperation(record.local_id, {
                status: QUEUE_STATUS.PENDING,
                next_retry_at: null,
                last_safe_error_code: null,
                malformed_auto_retried: true,
            }),
        );
    }

    return recovered;
}

const KNOWN_SAFE_ERROR_CODES = new Set([
    'CSRF_MISMATCH',
    'RETRYABLE_ERROR',
    'NETWORK_ERROR',
    'SERVER_ERROR',
    'TIMEOUT',
    'VALIDATION_FAILED',
    'MALFORMED',
    'TARGET_CHANGED',
    'SESSION_EXPIRED',
    'QUEUE_NEEDS_ATTENTION',
    'QUEUE_ACTOR_MISMATCH',
    'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'IDEMPOTENCY_ACTOR_MISMATCH',
    'UNKNOWN_OPERATION',
]);

function safeErrorCode(code) {
    if (typeof code !== 'string') {
        return null;
    }
    const trimmed = code.trim();
    if (!trimmed) {
        return null;
    }
    if (KNOWN_SAFE_ERROR_CODES.has(trimmed)) {
        return trimmed.slice(0, 64);
    }
    if (SECRET_KEY_PATTERN.test(trimmed)) {
        return null;
    }
    return trimmed.slice(0, 64);
}

export function toSyncEnvelope(record) {
    return {
        operation_id: record.operation_id,
        schema_version: 1,
        operation_type: record.operation_type,
        payload: record.payload || {},
        base_snapshot: record.base_snapshot || null,
        parent_server: record.parent_server || null,
    };
}

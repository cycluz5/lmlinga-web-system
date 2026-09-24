/**
 * Supported-form interception for Household Profiling and Plot New Household.
 *
 * Online submissions stay native except writes that target an unsynced
 * MB-L-* local member. Those must stay in the IndexedDB queue until the
 * original RESIDENT_CREATE is synced — navigator.onLine does not mean the
 * member exists on the server.
 */

import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from './offline-status.js';
import { countPendingVisible, enqueueOperation } from './offline-queue.js';
import { persistPlotHouseholdReadModel } from './offline-hp-store.js';

export const OPERATION_TYPES = {
    HOUSEHOLD_CREATE: 'HOUSEHOLD_CREATE',
    HOUSEHOLD_UPDATE: 'HOUSEHOLD_UPDATE',
    RESIDENT_CREATE: 'RESIDENT_CREATE',
    RESIDENT_UPDATE: 'RESIDENT_UPDATE',
    PLOT_HOUSEHOLD_WITH_HEAD: 'PLOT_HOUSEHOLD_WITH_HEAD',
    HEALTH_WORKER_UPDATE: 'HEALTH_WORKER_UPDATE',
    HOUSEHOLD_AMENITIES_UPDATE: 'HOUSEHOLD_AMENITIES_UPDATE',
    ENVIRONMENTAL_WATER_SUPPLY_UPDATE: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
    HEALTH_SERVICE_WRITE: 'HEALTH_SERVICE_WRITE',
};

const EXCLUDED_NAMES = new Set([
    '_token',
    '_method',
    'from',
    'password',
    'password_confirmation',
    'current_password',
    'csrf',
    'csrf_token',
    'hw_password',
    'hw_password_confirmation',
    'hw_photo',
    'hw_remove_photo',
]);

const AUTHORITATIVE_NAMES = new Set([
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

const STORAGE_FAILED_MESSAGE =
    'This update could not be stored on this device. Keep this page open and try again when a connection is available.';

/**
 * Parse HTML form field names into path segments.
 * Examples:
 *   last_name → ['last_name']
 *   newborn[length] → ['newborn', 'length']
 *   disability[] → ['disability', '']
 *   vaccines[bcg][0] → ['vaccines', 'bcg', '0']
 *   mam[cured][date] → ['mam', 'cured', 'date']
 *
 * @param {string} name
 * @returns {string[]}
 */
export function parseFormFieldName(name) {
    const raw = String(name || '');
    if (!raw) {
        return [];
    }
    const rootMatch = raw.match(/^[^\[\]]+/);
    if (!rootMatch) {
        return [];
    }
    const segments = [rootMatch[0]];
    const bracketRe = /\[([^\]]*)\]/g;
    bracketRe.lastIndex = rootMatch[0].length;
    let match = bracketRe.exec(raw);
    while (match) {
        segments.push(match[1]);
        match = bracketRe.exec(raw);
    }
    return segments;
}

/**
 * Assign a value into a nested payload object using PHP/Laravel field naming.
 *
 * @param {Record<string, unknown>} target
 * @param {string} name
 * @param {unknown} value
 */
export function assignNestedFormValue(target, name, value) {
    const segments = parseFormFieldName(name);
    if (!segments.length) {
        return;
    }
    const root = segments[0];
    if (AUTHORITATIVE_NAMES.has(root)) {
        return;
    }

    let cursor = target;
    for (let i = 0; i < segments.length; i += 1) {
        const key = segments[i];
        const next = segments[i + 1];
        const isLast = i === segments.length - 1;

        if (key === '') {
            // Push onto array owned by previous segment (name ends with []).
            if (!Array.isArray(cursor)) {
                return;
            }
            if (isLast) {
                cursor.push(value);
            }
            return;
        }

        if (isLast) {
            cursor[key] = value;
            return;
        }

        if (next === '') {
            if (!Array.isArray(cursor[key])) {
                cursor[key] = [];
            }
            cursor = cursor[key];
            continue;
        }

        const nextIsIndex = /^\d+$/.test(next);
        if (cursor[key] == null || typeof cursor[key] !== 'object') {
            cursor[key] = nextIsIndex ? [] : {};
        } else if (nextIsIndex && !Array.isArray(cursor[key])) {
            const asObj = cursor[key];
            const arr = [];
            Object.keys(asObj).forEach((k) => {
                if (/^\d+$/.test(k)) {
                    arr[Number(k)] = asObj[k];
                }
            });
            cursor[key] = arr;
        } else if (!nextIsIndex && Array.isArray(cursor[key])) {
            cursor[key] = { ...cursor[key] };
        }
        cursor = cursor[key];
    }
}

/**
 * Flatten nested payload keys back to HTML name="a[b][c]" form for hydration.
 * Also passes through legacy flat "a[b]" keys unchanged.
 *
 * @param {Record<string, unknown>} payload
 * @returns {Record<string, unknown>}
 */
export function flattenPayloadToFormNames(payload) {
    const out = {};
    if (!payload || typeof payload !== 'object') {
        return out;
    }

    function walk(value, prefix) {
        if (value === null || value === undefined) {
            if (prefix) {
                out[prefix] = value;
            }
            return;
        }
        if (Array.isArray(value)) {
            value.forEach((item, index) => {
                walk(item, prefix ? `${prefix}[${index}]` : String(index));
            });
            return;
        }
        if (typeof value === 'object') {
            Object.entries(value).forEach(([key, child]) => {
                if (String(key).startsWith('_') && !prefix) {
                    return;
                }
                walk(child, prefix ? `${prefix}[${key}]` : key);
            });
            return;
        }
        if (prefix) {
            out[prefix] = value;
        }
    }

    Object.entries(payload).forEach(([key, value]) => {
        if (String(key).startsWith('_')) {
            return;
        }
        // Legacy offline payloads already used literal bracket keys.
        if (String(key).includes('[')) {
            out[key] = value;
            return;
        }
        walk(value, key);
    });

    return out;
}

/**
 * @param {ParentNode | null | undefined} form
 * @returns {Record<string, unknown>}
 */
export function serializeSupportedForm(form) {
    const payload = {};
    if (!form || !form.elements) {
        return payload;
    }

    const elements = Array.from(form.elements);
    elements.forEach((el) => {
        if (!el || !el.name || el.disabled) {
            return;
        }

        const rawName = String(el.name);
        const rootName = parseFormFieldName(rawName)[0] || rawName.replace(/\[.*$/, '');
        if (EXCLUDED_NAMES.has(rawName) || EXCLUDED_NAMES.has(rootName) || EXCLUDED_NAMES.has(rawName.replace(/\[\]$/, ''))) {
            return;
        }

        const type = String(el.type || '').toLowerCase();
        if (type === 'password' || type === 'file' || type === 'submit' || type === 'button' || type === 'image' || type === 'reset') {
            return;
        }

        if (el.readOnly) {
            return;
        }

        if (AUTHORITATIVE_NAMES.has(rootName)) {
            return;
        }

        if (type === 'checkbox') {
            if (rawName.endsWith('[]')) {
                const segments = parseFormFieldName(rawName);
                const arrKey = segments[0];
                if (!Array.isArray(payload[arrKey])) {
                    payload[arrKey] = [];
                }
                if (el.checked) {
                    payload[arrKey].push(scalarValue(el.value));
                }
                return;
            }
            assignNestedFormValue(payload, rawName, el.checked ? scalarValue(el.value || '1') : null);
            return;
        }

        if (type === 'radio') {
            if (!el.checked) {
                const segments = parseFormFieldName(rawName);
                if (segments.length === 1 && !Object.prototype.hasOwnProperty.call(payload, segments[0])) {
                    payload[segments[0]] = null;
                }
                return;
            }
            assignNestedFormValue(payload, rawName, scalarValue(el.value));
            return;
        }

        if (type === 'select-multiple') {
            assignNestedFormValue(
                payload,
                rawName,
                Array.from(el.selectedOptions || [])
                    .map((option) => scalarValue(option.value))
                    .filter((value) => value !== null),
            );
            return;
        }

        assignNestedFormValue(payload, rawName, scalarValue(el.value));
    });

    return payload;
}

function presentResidentMemberErrors(form, errors) {
    const host = form?.closest?.('[data-lml-hh-member-form]') || form;
    if (!host || typeof host.querySelector !== 'function' || !errors.length) {
        return;
    }
    errors.forEach(({ key, message }) => {
        const node = host.querySelector(`#err-${key}`)
            || host.querySelector(`[data-field="${key}"] .lml-hh-member-form__error`);
        if (node) {
            node.hidden = false;
            node.textContent = message;
        }
    });
    const summary = host.querySelector('[data-hh-member-summary]');
    const list = host.querySelector('[data-hh-member-summary-list]');
    if (summary && list) {
        list.textContent = '';
        errors.forEach(({ message }) => {
            const li = (host.ownerDocument || (typeof document !== 'undefined' ? document : null))
                ?.createElement?.('li');
            if (!li) {
                return;
            }
            li.textContent = message;
            list.appendChild(li);
        });
        summary.hidden = false;
    }
}

const HOUSEHOLD_ZONE_VALUES = Object.freeze(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']);

/**
 * Client-side shell validation mirroring ValidatesHouseholdShell / StoreHouseholdRequest.
 * Server remains authoritative; this blocks obviously invalid offline queues.
 *
 * Street is only required when the live form includes a street input (schema has
 * households.street). ERD deployments without that column omit the field online
 * and must not invent an offline-only Street requirement.
 *
 * @param {object} payload
 * @param {string} operationType
 * @param {{ form?: HTMLFormElement|null, requireStreet?: boolean }} [options]
 */
export function householdShellPayloadErrors(payload, operationType, options = {}) {
    if (
        operationType !== OPERATION_TYPES.HOUSEHOLD_CREATE
        && operationType !== OPERATION_TYPES.HOUSEHOLD_UPDATE
    ) {
        return [];
    }
    const errors = [];
    const text = (key) => {
        const value = payload?.[key];
        if (value == null) {
            return '';
        }
        return String(value).trim();
    };
    if (operationType === OPERATION_TYPES.HOUSEHOLD_CREATE) {
        const householdNo = text('household_no');
        if (!householdNo) {
            errors.push({ key: 'household_no', message: 'Household No. is required.' });
        } else if (!/^[0-9]{3}$/.test(householdNo)) {
            errors.push({ key: 'household_no', message: 'Household No. must be exactly 3 digits.' });
        }
    }
    const zone = text('zone');
    if (!zone) {
        errors.push({ key: 'zone', message: 'Zone is required.' });
    } else if (!HOUSEHOLD_ZONE_VALUES.includes(zone)) {
        errors.push({ key: 'zone', message: 'Please select a valid zone.' });
    }

    const form = options.form || null;
    const formHasStreet = Boolean(
        form
        && typeof form.querySelector === 'function'
        && form.querySelector('[name="street"]'),
    );
    const requireStreet = options.requireStreet === true || formHasStreet;
    const street = text('street');
    if (requireStreet) {
        if (!street) {
            errors.push({ key: 'street', message: 'Street is required.' });
        } else if (street.length > 150) {
            errors.push({ key: 'street', message: 'Street may not be greater than 150 characters.' });
        }
    } else if (street.length > 150) {
        errors.push({ key: 'street', message: 'Street may not be greater than 150 characters.' });
    }

    const dateRegistered = text('date_registered');
    if (!dateRegistered) {
        errors.push({ key: 'date_registered', message: 'Date registered is required.' });
    } else if (!/^\d{4}-\d{2}-\d{2}$/.test(dateRegistered)) {
        errors.push({ key: 'date_registered', message: 'Date registered must be a valid date.' });
    } else {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const todayIso = `${yyyy}-${mm}-${dd}`;
        if (dateRegistered > todayIso) {
            errors.push({ key: 'date_registered', message: 'Date registered may not be a future date.' });
        }
    }
    return errors;
}

function presentHouseholdShellErrors(form, errors) {
    const host = form?.closest?.('[data-lml-hh-shell-form]') || form;
    if (!host || typeof host.querySelector !== 'function' || !errors.length) {
        return;
    }
    const doc = host.ownerDocument || (typeof document !== 'undefined' ? document : null);
    errors.forEach(({ key, message }) => {
        const field = host.querySelector(`[data-field="${key}"]`);
        let node = field?.querySelector?.('.lml-hh-member-form__error')
            || host.querySelector(`#err-${key}`);
        if (!node && field && doc?.createElement) {
            node = doc.createElement('p');
            node.className = 'lml-hh-member-form__error';
            node.setAttribute('role', 'alert');
            field.appendChild(node);
        }
        if (node) {
            node.hidden = false;
            node.removeAttribute('hidden');
            node.textContent = message;
        }
    });
    let summary = host.querySelector('.lml-hh-member-form__summary');
    if (!summary && doc?.createElement) {
        const card = host.querySelector('.lml-hh-member-form__card') || host;
        summary = doc.createElement('div');
        summary.className = 'lml-hh-member-form__summary';
        summary.setAttribute('role', 'alert');
        summary.tabIndex = -1;
        const text = doc.createElement('p');
        text.className = 'lml-hh-member-form__summary-text';
        text.textContent = 'Please review the information below.';
        const list = doc.createElement('ul');
        list.className = 'lml-hh-member-form__summary-list';
        summary.appendChild(text);
        summary.appendChild(list);
        const formEl = host.querySelector('form') || form;
        card.insertBefore(summary, formEl || card.firstChild);
    }
    const list = summary?.querySelector?.('.lml-hh-member-form__summary-list');
    if (summary && list) {
        list.textContent = '';
        errors.forEach(({ message }) => {
            const li = doc?.createElement?.('li');
            if (!li) {
                return;
            }
            li.textContent = message;
            list.appendChild(li);
        });
        summary.hidden = false;
        summary.removeAttribute('hidden');
    }
}

export function residentMemberPayloadErrors(payload, operationType) {
    if (operationType !== OPERATION_TYPES.RESIDENT_CREATE && operationType !== OPERATION_TYPES.RESIDENT_UPDATE) {
        return [];
    }
    const errors = [];
    const text = (key) => {
        const value = payload?.[key];
        if (value == null) {
            return '';
        }
        return String(value).trim();
    };
    const needText = (key, message) => {
        if (!text(key)) {
            errors.push({ key, message });
        }
    };
    const needAllowed = (key, allowed, message) => {
        const value = text(key);
        if (!value || !allowed.includes(value)) {
            errors.push({ key, message });
        }
    };
    needText('last_name', 'Last Name is required.');
    needText('first_name', 'First Name is required.');
    needAllowed(
        'relation',
        ['Head', 'Spouse', 'Son', 'Daughter', 'Parent', 'Sibling', 'Grandchild', 'Other Relative', 'Non-Relative'],
        'Please select a relationship to the household head.',
    );
    needText('birthday', 'Birthday is required.');
    needAllowed('sex', ['Male', 'Female'], 'Please select a sex.');
    needAllowed(
        'relationship_status',
        ['Single', 'Married', 'Widowed', 'Separated', 'Live-in'],
        'Relationship Status is required.',
    );
    needAllowed(
        'occupation',
        ['None / N/A', 'Farmer', 'Fisherfolk', 'Vendor', 'Teacher', 'Nurse', 'Driver', 'Construction Worker', 'Government Employee', 'Private Employee', 'Self-employed', 'Student', 'Homemaker', 'Unemployed', 'Other'],
        'Occupation is required.',
    );
    if (text('occupation') === 'Other' && !text('occupation_other')) {
        errors.push({ key: 'occupation_other', message: 'Please specify the occupation.' });
    }
    needAllowed(
        'monthly_income',
        ['None / N/A', 'Below 5,000', '5,000 – 9,999', '10,000 – 19,999', '20,000 – 29,999', '30,000 – 49,999', '50,000 and above'],
        'Monthly Income is required.',
    );
    needAllowed(
        'religion',
        ['Roman Catholic', 'Iglesia ni Cristo', 'Protestant', 'Islam', 'Born Again', 'Other', 'None'],
        'Religion is required.',
    );
    if (text('religion') === 'Other' && !text('religion_other')) {
        errors.push({ key: 'religion_other', message: 'Please specify the religion.' });
    }
    needAllowed(
        'education',
        ['No Formal Education', 'Elementary Level', 'Elementary Graduate', 'High School Level', 'High School Graduate', 'Vocational', 'College Level', 'College Graduate', 'Post-Graduate', 'Not Applicable'],
        'Educational Attainment is required.',
    );
    needAllowed('fp_user', ['Yes', 'No', 'N/A'], 'Please select a Family Planning (FP) User option.');
    const disability = Array.isArray(payload?.disability) ? payload.disability.filter((item) => item != null && String(item).trim() !== '') : [];
    if (!disability.length) {
        errors.push({ key: 'disability', message: 'Please choose at least one disability option or None.' });
    }
    const medical = Array.isArray(payload?.medical_history) ? payload.medical_history.filter((item) => item != null && String(item).trim() !== '') : [];
    if (!medical.length) {
        errors.push({ key: 'medical_history', message: 'Please choose at least one medical history option or None.' });
    }
    return errors;
}

function scalarValue(value) {
    if (value == null) {
        return null;
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }
    const trimmed = String(value).trim();
    return trimmed === '' ? null : trimmed;
}

const LOCAL_MEMBER_ID_PATTERN = /^MB-L-[A-Za-z0-9]+$/i;

export function localUnsyncedMemberIdFromForm(form, options = {}) {
    if (!form?.getAttribute) {
        return '';
    }
    const parent = parentServerFromForm(form) || {};
    const host = form.closest?.('[data-member-id], [data-lml-hh-member-form], [data-lml-hh-member-view]');
    const pathname = String(
        options.pathname
        || options.window?.location?.pathname
        || (typeof window !== 'undefined' ? window.location?.pathname : '')
        || '',
    );
    const candidates = [
        parent.client_local_member_id,
        parent.local_member_id,
        parent.member_no,
        form.getAttribute('data-offline-parent-member-no'),
        form.getAttribute('data-offline-local-member-id'),
        host?.getAttribute?.('data-member-id'),
        host?.getAttribute?.('data-offline-parent-member-no'),
    ];
    for (const value of candidates) {
        const id = String(value || '').trim();
        if (LOCAL_MEMBER_ID_PATTERN.test(id)) {
            return id;
        }
    }
    const action = String(form.getAttribute('action') || form.action || '');
    const actionMatch = action.match(/\/members\/(MB-L-[A-Za-z0-9]+)(?:\/|$|\?)/i);
    if (actionMatch) {
        return actionMatch[1];
    }
    const pathMatch = pathname.match(/\/members\/(MB-L-[A-Za-z0-9]+)(?:\/|$)/i);
    return pathMatch ? pathMatch[1] : '';
}

export function shouldQueueSupportedForm(form, options = {}) {
    const operationType = form?.getAttribute?.('data-offline-operation');
    if (!operationType || !OPERATION_TYPES[operationType]) {
        return false;
    }
    if (isClientOffline(options)) {
        return true;
    }
    if (
        operationType === OPERATION_TYPES.RESIDENT_UPDATE
        || operationType === OPERATION_TYPES.HEALTH_SERVICE_WRITE
    ) {
        return Boolean(localUnsyncedMemberIdFromForm(form, options));
    }
    if (operationType === OPERATION_TYPES.HOUSEHOLD_UPDATE) {
        return Boolean(localUnsyncedHouseholdNoFromForm(form, options));
    }
    // Add Member under a still-local information-first Household.
    if (operationType === OPERATION_TYPES.RESIDENT_CREATE) {
        return Boolean(localUnsyncedHouseholdNoFromForm(form, options));
    }
    // EH Water Supply under a still-local Household (explicit marker only —
    // normal online server EH forms stay native even without a field hash).
    if (operationType === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE) {
        if (!isExplicitLocalHouseholdForm(form)) {
            return false;
        }
        return Boolean(localUnsyncedHouseholdNoFromForm(form, options));
    }
    return false;
}

function isExplicitLocalHouseholdForm(form) {
    if (!form) {
        return false;
    }
    if (form.getAttribute?.('data-offline-local-household') === '1') {
        return true;
    }
    const host = form.closest?.(
        '[data-offline-local-household="1"], [data-lml-hws][data-offline-local-household="1"]',
    );
    return host?.getAttribute?.('data-offline-local-household') === '1';
}

/**
 * Detect edits targeting an unsynced information-first household (no server PK).
 */
export function localUnsyncedHouseholdNoFromForm(form, options = {}) {
    if (!form) {
        return '';
    }
    const parent = parentServerFromForm(form) || {};
    const householdId = Number(parent.household_id || 0);
    if (Number.isInteger(householdId) && householdId > 0) {
        return '';
    }
    const householdNo = String(
        parent.household_no
        || form.getAttribute?.('data-offline-parent-household-no')
        || '',
    ).trim();
    if (!householdNo) {
        return '';
    }
    // Explicit local marker from offline edit shells.
    if (form.getAttribute?.('data-offline-local-household') === '1') {
        return householdNo;
    }
    const host = form.closest?.('[data-lml-hh-shell-form], [data-lml-hh-view]');
    if (host?.getAttribute?.('data-offline-local-household') === '1') {
        return householdNo;
    }
    // Missing server PK + household_no present ⇒ treat as local pending when
    // the page itself was served from a local shell (no field hash).
    const hash = String(form.getAttribute?.('data-offline-field-hash') || '').trim();
    if (!hash && householdNo) {
        return householdNo;
    }
    return '';
}

export function isClientOffline(options = {}) {
    const nav = options.navigator || (typeof navigator !== 'undefined' ? navigator : { onLine: true });
    if (nav.onLine === false) {
        return true;
    }

    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const state = win.LmlingaOffline?.getState?.();
    if (state && state.connectivity && state.connectivity !== 'online' && state.connectivity !== 'checking') {
        return true;
    }

    const root = options.root || (typeof document !== 'undefined' ? document.querySelector('[data-lml-offline-root]') : null);
    const ui = root?.getAttribute?.('data-lml-offline-state');
    return ui === 'offline';
}

export function isNetworkFailure(error) {
    if (!error) {
        return false;
    }
    if (error.name === 'TypeError' || error.name === 'AbortError' || error.name === 'NetworkError') {
        return true;
    }
    const message = String(error.message || '');
    return /failed to fetch|networkerror|load failed|offline/i.test(message);
}

function readActor(root) {
    const id = Number(root?.getAttribute?.('data-offline-actor-id') || 0);
    return {
        actor_id: Number.isInteger(id) && id > 0 ? id : 0,
        actor_username: root?.getAttribute?.('data-offline-actor-username') || '',
    };
}

function emit(win, name, detail) {
    if (win?.LmlingaOffline?.emit) {
        win.LmlingaOffline.emit(name, detail);
        return;
    }
    if (typeof win?.dispatchEvent !== 'function') {
        return;
    }
    const event =
        typeof CustomEvent === 'function' ? new CustomEvent(name, { detail }) : { type: name, detail };
    win.dispatchEvent(event);
}

function showQueuedOnForm(form) {
    if (!form) {
        return;
    }
    form.setAttribute('data-offline-queued', '1');
    const submit = form.querySelector('[type="submit"], [data-hh-member-save]');
    if (submit) {
        submit.disabled = true;
        submit.setAttribute('aria-disabled', 'true');
    }
    const chip = form.querySelector('[data-lml-offline-form-pending]')
        || form.parentElement?.querySelector?.('[data-lml-offline-form-pending]');
    if (chip) {
        chip.hidden = false;
        chip.removeAttribute('hidden');
        if (!chip.textContent) {
            chip.textContent = 'Waiting to sync';
        }
    }
}

/**
 * @param {object} input
 */
export async function queueSupportedOperation(input, options = {}) {
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const actor = input.actor_id
        ? { actor_id: input.actor_id, actor_username: input.actor_username || '' }
        : readActor(options.root);

    if (!actor.actor_id) {
        emit(win, OFFLINE_EVENTS.STORAGE_FAILED, { message: STORAGE_FAILED_MESSAGE });
        return { ok: false, reason: 'actor' };
    }

    try {
        const record = await enqueueOperation({
            operation_type: input.operation_type,
            payload: input.payload,
            base_snapshot: input.base_snapshot || null,
            parent_server: input.parent_server || null,
            actor_id: actor.actor_id,
            actor_username: actor.actor_username,
        });
        if (typeof options.afterEnqueue === 'function') {
            await options.afterEnqueue(record);
        }
        const pending = await countPendingVisible(actor.actor_id);
        emit(win, OFFLINE_EVENTS.SAVED, {
            pending,
            operation_type: record.operation_type,
            requested_operation_type: input.operation_type,
            local_id: record.local_id,
            payload: record.payload,
            parent_server: record.parent_server,
        });
        if (typeof options.onQueued === 'function') {
            options.onQueued(record);
        }
        return { ok: true, record };
    } catch {
        emit(win, OFFLINE_EVENTS.STORAGE_FAILED, { message: STORAGE_FAILED_MESSAGE });
        return { ok: false, reason: 'storage' };
    }
}

export function parentServerFromForm(form) {
    if (!form?.getAttribute) {
        return null;
    }

    const householdId = positiveInt(form.getAttribute('data-offline-parent-household-id'));
    const householdNo = (form.getAttribute('data-offline-parent-household-no') || '').trim();
    const residentId = positiveInt(form.getAttribute('data-offline-parent-resident-id'));
    const memberNo = (form.getAttribute('data-offline-parent-member-no') || '').trim();
    const userId = positiveInt(form.getAttribute('data-offline-parent-user-id'));

    const parent = {};
    if (householdId) {
        parent.household_id = householdId;
    }
    if (householdNo) {
        parent.household_no = householdNo;
    }
    if (residentId) {
        parent.resident_id = residentId;
    }
    if (memberNo) {
        parent.member_no = memberNo;
    }
    if (userId) {
        parent.user_id = userId;
    }

    if (!parent.household_no || !parent.member_no) {
        const host = form.closest?.('[data-household-no]')
            || form.closest?.('[data-lml-hh-member-form]')
            || form.closest?.('[data-lml-hh-member-view]');
        if (host) {
            if (!parent.household_no) {
                const fromHost = (host.getAttribute('data-household-no') || '').trim();
                if (fromHost) {
                    parent.household_no = fromHost;
                }
            }
            if (!parent.member_no) {
                const fromHost = (
                    host.getAttribute('data-member-id')
                    || host.getAttribute('data-member-no')
                    || ''
                ).trim();
                if (fromHost) {
                    parent.member_no = fromHost;
                }
            }
        }
    }

    return Object.keys(parent).length ? parent : null;
}

export function baseSnapshotFromForm(form) {
    const hash = (form?.getAttribute?.('data-offline-field-hash') || '').trim();
    if (!hash) {
        return null;
    }
    return { field_hash: hash };
}

export function mintLocalMemberId(randomSource) {
    const cryptoObj = randomSource || (typeof globalThis !== 'undefined' ? globalThis.crypto : null);
    const bytes = new Uint8Array(6);
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

function positiveInt(value) {
    if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
        return value;
    }
    if (typeof value === 'string' && /^[0-9]+$/.test(value)) {
        const parsed = Number(value);
        return parsed > 0 ? parsed : null;
    }
    return null;
}

function submitFormFromEvent(event) {
    const target = event?.target;
    if (target && typeof target.closest === 'function') {
        const closest = target.closest('form');
        if (closest) {
            return closest;
        }
    }
    const current = event?.currentTarget;
    if (current && typeof current.getAttribute === 'function') {
        if (!current.tagName || current.tagName === 'FORM' || current.getAttribute('data-offline-operation')) {
            return current;
        }
    }
    if (target && typeof target.getAttribute === 'function' && target.getAttribute('data-offline-operation')) {
        return target;
    }
    return current || target || null;
}

export async function handleSupportedFormSubmit(event, options = {}) {
    const form = submitFormFromEvent(event);
    if (!form || typeof form.getAttribute !== 'function') {
        return false;
    }

    if (form.getAttribute('data-offline-queued') === '1' || form.getAttribute('data-offline-handling') === '1') {
        event.preventDefault?.();
        return true;
    }

    const operationType = form.getAttribute('data-offline-operation');
    if (!operationType || !OPERATION_TYPES[operationType]) {
        return false;
    }

    if (!shouldQueueSupportedForm(form, options)) {
        return false;
    }

    event.preventDefault?.();
    form.setAttribute?.('data-offline-handling', '1');
    try {
        const payload = serializeSupportedForm(form);
        const memberErrors = residentMemberPayloadErrors(payload, operationType);
        if (memberErrors.length) {
            presentResidentMemberErrors(form, memberErrors);
            return true;
        }
        const shellErrors = householdShellPayloadErrors(payload, operationType, { form });
        if (shellErrors.length) {
            presentHouseholdShellErrors(form, shellErrors);
            return true;
        }
        const healthAction = (form.getAttribute('data-offline-health-action') || '').trim();
        if (healthAction) {
            payload._health_action = healthAction;
        }
        const healthSection = (form.getAttribute('data-offline-health-section') || '').trim();
        if (healthSection) {
            payload._health_section = healthSection;
        }
        const healthVisitId = (form.getAttribute('data-offline-health-visit-id') || '').trim();
        if (healthVisitId) {
            payload._health_visit_id = healthVisitId;
        }
        const healthAssessmentId = (form.getAttribute('data-offline-health-assessment-id') || '').trim();
        if (healthAssessmentId) {
            payload._health_assessment_id = healthAssessmentId;
        }
        const ehStep = Number(form.getAttribute('data-offline-eh-step') || 0);
        if (Number.isInteger(ehStep) && ehStep > 0) {
            payload._eh_step = ehStep;
        }
        if (operationType === OPERATION_TYPES.RESIDENT_CREATE && !payload.client_local_member_id) {
            payload.client_local_member_id = mintLocalMemberId();
        }
        const parentServer = parentServerFromForm(form);
        const localMemberId = String(payload.client_local_member_id || parentServer?.member_no || '').trim();
        if (
            (operationType === OPERATION_TYPES.RESIDENT_UPDATE || operationType === OPERATION_TYPES.HEALTH_SERVICE_WRITE)
            && /^MB-L-[A-Za-z0-9]+$/i.test(localMemberId)
        ) {
            payload.client_local_member_id = localMemberId;
            if (parentServer) {
                parentServer.client_local_member_id = localMemberId;
            }
        }
        const result = await queueSupportedOperation(
            {
                operation_type: operationType,
                payload,
                base_snapshot: baseSnapshotFromForm(form),
                parent_server: parentServer,
            },
            options,
        );

        if (result.ok) {
            showQueuedOnForm(form);
        }

        return true;
    } finally {
        form.removeAttribute?.('data-offline-handling');
    }
}

/**
 * Plot New Household — queue without fabricating an EH handoff token.
 *
 * @param {Record<string, unknown>} payload
 */
export async function queuePlotNewHousehold(payload, options = {}) {
    const clean = { ...payload };
    delete clean.id;
    delete clean.household_id;
    delete clean.resident_id;
    delete clean.member_no;
    delete clean.handoff_token;
    delete clean.redirect_url;

    const result = await queueSupportedOperation(
        {
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: clean,
            base_snapshot: null,
            parent_server: null,
        },
        {
            ...options,
            afterEnqueue: async (record) => {
                await persistPlotHouseholdReadModel(record.actor_id, record.payload);
            },
        },
    );

    return result;
}

export function bindSupportedForms(root, options = {}) {
    const scope = root || (typeof document !== 'undefined' ? document : null);
    if (!scope || typeof scope.addEventListener !== 'function') {
        return [];
    }

    const handler = (event) => {
        const form = submitFormFromEvent(event);
        if (!form?.getAttribute?.('data-offline-operation')) {
            return;
        }
        if (scope !== form && typeof scope.contains === 'function' && !scope.contains(form)) {
            return;
        }
        void handleSupportedFormSubmit(event, {
            ...options,
            root: options.root || form.closest?.('[data-lml-offline-root]') || options.root,
        });
    };
    scope.addEventListener('submit', handler, true);
    return [[scope, handler]];
}

export { STORAGE_FAILED_MESSAGE, OFFLINE_MESSAGES };

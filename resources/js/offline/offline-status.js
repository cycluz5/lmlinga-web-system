/**
 * LMLinga client offline status + local-save feedback.
 *
 * Presentation layer only. OFFLINE-6 should dispatch the exported events
 * (or call window.LmlingaOffline.emit) when a queue exists. This module
 * does not store operations, post queued writes, or mint IDs.
 */

import { documentHasSwCacheMarker } from './offline-sw-policy.js';
import {
    isSyntheticOfflineChangeItem,
    listOfflineChangesForActor,
    offlineChangesCounts,
    renderOfflineChangesListHtml,
    safeOfflineChangeHref,
} from './offline-changes.js';
import {
    buildDiscardConfirmation,
    collectDiscardPlan,
    executeDiscardPlan,
    resolveAbsentDiscard,
    retryQueuedOperation,
} from './offline-discard.js';

export const OFFLINE_EVENTS = {
    OFFLINE: 'lmlinga:offline',
    ONLINE: 'lmlinga:online',
    SAVED: 'lmlinga:offline-saved',
    STORAGE_FAILED: 'lmlinga:offline-storage-failed',
    SYNC_START: 'lmlinga:sync-start',
    SYNC_SUCCESS: 'lmlinga:sync-success',
    SYNC_RETRY: 'lmlinga:sync-retry',
    SYNC_ATTENTION: 'lmlinga:sync-attention',
    NOTICE: 'lmlinga:notice',
    DISCARDED: 'lmlinga:offline-discarded',
    RETRY_REQUESTED: 'lmlinga:offline-retry-requested',
};

export const OFFLINE_MESSAGES = {
    connectivityLost: "Offline. Changes will sync when you're back online.",
    connectivityLostWithPending: "Offline • {count} changes waiting to sync",
    connectivityLostWithPendingOne: 'Offline • 1 change waiting to sync',
    localSave: 'Offline changes saved. Waiting to sync.',
    backOnline: 'You are back online.',
    backOnlineWithPending: 'Back online. Syncing pending changes…',
    syncing: 'Back online. Syncing pending changes…',
    syncComplete: 'All changes are synced.',
    syncNeedsAttentionOne: '1 offline update needs attention and was not saved yet.',
    syncNeedsAttentionMany: '{count} offline updates need attention and were not saved yet.',
    syncRetry: "Some changes couldn't sync. We'll retry.",
    attentionTitle: 'Offline Changes',
    attentionAcknowledge: 'Close',
    attentionFallback:
        'This update could not be sent automatically. Please review it before trying again.',
    attentionReview: 'Review',
    offlineChangesEmpty: 'No offline changes waiting on this device.',
    offlineChangesLoading: 'Loading offline changes…',
    offlineChangesFailed: 'Could not load offline changes from this device.',
    discardToast: 'Local change removed. It will not sync.',
    discardToastMany: '{count} local changes removed. They will not sync.',
    discardFailed: 'Could not remove this local change. Keep this page open and try again.',
    retryQueued: 'Retry queued. Syncing when online…',
    retryFailed: 'Could not retry this change right now.',
    storageFailed:
        'This update could not be stored on this device. Keep this page open and try again when a connection is available.',
    pendingOne: '1 waiting',
    pendingMany: '{count} waiting',
    exportUnavailable: 'Export is available when online.',
    viewHouseholdOffline: 'Reconnect to open this household.',
    viewMemberOffline: 'Reconnect to open this member.',
    addMemberOffline: 'Reconnect to add a member.',
    editMemberOffline: 'Reconnect to edit this member.',
    amenitiesOffline: 'Reconnect to open household details.',
    editHouseholdOffline: 'Reconnect to edit this household.',
    viewHealthRecordOffline: 'Reconnect to open this health record.',
    viewEnvironmentalOffline: 'Reconnect to open environmental sanitation.',
    deathCertificateOffline: 'Death certificate uploads require a connection.',
    viewHealthWorkerOffline: 'Reconnect to open this health worker.',
    editHealthWorkerOffline: 'Reconnect to edit this health worker.',
    addHealthWorkerOffline: 'Reconnect to add a health worker.',
    passwordRequiresConnection: 'Password changes require a connection.',
    photoRequiresConnection: 'Profile photo changes require a connection.',
    healthWorkerOfflineSaved: 'Changes saved offline. Waiting to sync.',
    userManagementOffline:
        "User Management isn’t available while you’re offline. Reconnect to the internet to view and manage user accounts.",
    pageNotCachedOffline:
        'This page isn’t available offline yet. Reconnect to the internet and open this page once to make it available for offline use.',
};

export const ATTENTION_MESSAGES = {
    TARGET_CHANGED:
        'This record was changed on the server while you were offline. Please review it before trying again.',
    TARGET_MISSING:
        'This record is no longer available on the server. Please review the saved update before continuing.',
    IDEMPOTENCY_PAYLOAD_MISMATCH:
        'This update does not match an earlier submission. It needs a review before it can be sent.',
    IDEMPOTENCY_ACTOR_MISMATCH:
        'This update was already submitted by another account. Please review it before continuing.',
    VALIDATION_FAILED:
        'Some details could not be accepted. Please review the saved update and correct it.',
    ACCOUNT_INACTIVE:
        'Your account is inactive, so this update could not be sent. Contact an administrator for help.',
    FORBIDDEN:
        'You do not have permission to send this update. Please review it or ask an administrator for help.',
    SESSION_EXPIRED: 'Your session has ended. Sign in again to send waiting updates.',
    CSRF_MISMATCH: 'Your sign-in token is out of date. Refresh the page, then try sending again.',
    PASSWORD_CHANGE_REQUIRED: 'You need to change your password before this update can be sent.',
    UNKNOWN_OPERATION: 'This saved update is not supported. Please review it before continuing.',
    MALFORMED: 'This saved update is incomplete. Please review it before trying again.',
    QUEUE_ACTOR_MISMATCH:
        'Waiting updates on this device belong to another account. They will not be sent until that account signs in.',
    QUEUE_NEEDS_ATTENTION: '1 offline update needs attention and was not saved yet.',
};

const SENSITIVE_PATTERN =
    /password|passwd|secret|token|csrf|session[_-]?id|app[_-]?key|lmlinga_at_rest|authorization|bearer|cookie/i;

const ATTENTION_CODES = new Set(Object.keys(ATTENTION_MESSAGES));

const RETRY_CODES = new Set(['RETRYABLE_ERROR']);

/**
 * @param {unknown} value
 * @param {string} fallback
 */
export function sanitizeVisibleText(value, fallback = '') {
    if (typeof value !== 'string') {
        return fallback;
    }

    const trimmed = value.trim();
    if (!trimmed || SENSITIVE_PATTERN.test(trimmed)) {
        return fallback;
    }

    if (/base64:[A-Za-z0-9+/=]{12,}/.test(trimmed) || /LMLINGA_AT_REST_KEY/i.test(trimmed)) {
        return fallback;
    }

    return trimmed.replace(/\s+/g, ' ').slice(0, 280);
}

export function safeLocalMemberEditHref(value) {
    const raw = String(value || '').trim();
    if (!raw || /^(javascript|data|vbscript):/i.test(raw)) {
        return '';
    }
    let path = raw;
    try {
        if (/^[a-z][a-z0-9+.-]*:/i.test(raw) || raw.startsWith('//')) {
            const parsed = new URL(raw, 'http://localhost');
            path = parsed.pathname || '';
        }
    } catch {
        return '';
    }
    const normalized = String(path).replace(/\/+$/, '');
    const match = normalized.match(
        /^\/household-profiling\/([A-Za-z0-9-]+)\/members\/(MB-L-[A-Za-z0-9]+)\/edit$/i,
    );
    if (!match) {
        return '';
    }
    return `/household-profiling/${match[1]}/members/${match[2]}/edit`;
}

export function safeHouseholdEditHref(value) {
    const raw = String(value || '').trim();
    if (!raw || /^(javascript|data|vbscript):/i.test(raw)) {
        return '';
    }
    let path = raw;
    try {
        if (/^[a-z][a-z0-9+.-]*:/i.test(raw) || raw.startsWith('//')) {
            const parsed = new URL(raw, 'http://localhost');
            path = parsed.pathname || '';
        }
    } catch {
        return '';
    }
    const normalized = String(path).replace(/\/+$/, '');
    const match = normalized.match(/^\/household-profiling\/([A-Za-z0-9-]+)\/edit$/i);
    if (!match) {
        return '';
    }
    return `/household-profiling/${match[1]}/edit`;
}

export function safeAttentionRepairHref(value) {
    return safeOfflineChangeHref(value) || safeHouseholdEditHref(value) || safeLocalMemberEditHref(value);
}

export function composeAttentionDialogMessage(item) {
    if (!item || typeof item !== 'object') {
        return '';
    }
    const parts = [];
    const householdNo = sanitizeVisibleText(item.household_no, '');
    const memberName = sanitizeVisibleText(item.member_name, '');
    const operationType = sanitizeVisibleText(item.operation_type, '');
    const reason = sanitizeVisibleText(item.reason, '');
    if (householdNo) {
        parts.push(`Household ${householdNo}`);
    }
    if (memberName) {
        parts.push(memberName);
    }
    if (operationType === 'RESIDENT_CREATE') {
        parts.push('Add Household Member');
    }
    if (operationType === 'RESIDENT_UPDATE') {
        parts.push('Edit Household Member');
    }
    if (reason) {
        parts.push(`Needs correction: ${reason}`);
    }
    if (!parts.length) {
        return '';
    }
    const text = parts.join('. ');
    return /[.!?]$/.test(text) ? text : `${text}.`;
}

/**
 * @param {string | null | undefined} code
 */
export function attentionMessageForCode(code) {
    if (typeof code !== 'string' || code === '') {
        return OFFLINE_MESSAGES.attentionFallback;
    }

    return ATTENTION_MESSAGES[code] || OFFLINE_MESSAGES.attentionFallback;
}

/**
 * @param {string | null | undefined} code
 */
export function isAttentionCode(code) {
    return typeof code === 'string' && ATTENTION_CODES.has(code);
}

/**
 * @param {string | null | undefined} code
 */
export function isRetryCode(code) {
    return typeof code === 'string' && RETRY_CODES.has(code);
}

/**
 * @param {number} count
 */
export function unsyncedAttentionMessage(count) {
    const n = Math.max(1, Number(count) || 1);
    if (n <= 1) {
        return OFFLINE_MESSAGES.syncNeedsAttentionOne;
    }

    return OFFLINE_MESSAGES.syncNeedsAttentionMany.replace('{count}', String(n));
}

/**
 * @param {number} count
 */
export function attentionBannerText(count) {
    return unsyncedAttentionMessage(count);
}

export function attentionAckStorageKey(actorId, operationId, errorCode) {
    const actor = String(Number(actorId) || 0);
    const op = String(operationId || '')
        .trim()
        .slice(0, 80)
        .replace(/[^A-Za-z0-9._:-]/g, '') || 'unknown';
    const code = String(errorCode || '')
        .trim()
        .slice(0, 80)
        .replace(/[^A-Za-z0-9._-]/g, '') || 'UNKNOWN';
    return `offline-attention-ack:${actor}:${op}:${code}`;
}

function setHidden(el, hidden) {
    if (!el) {
        return;
    }

    el.hidden = hidden;
    if (hidden) {
        el.setAttribute('hidden', '');
        el.setAttribute('aria-hidden', 'true');
    } else {
        el.removeAttribute('hidden');
        el.removeAttribute('aria-hidden');
    }
}

function setText(el, value) {
    if (!el) {
        return;
    }

    el.textContent = value == null ? '' : String(value);
}

function replaceBannerMode(banner, mode) {
    if (!banner || !banner.classList) {
        return;
    }

    ['offline', 'syncing', 'retry', 'attention'].forEach((name) => {
        banner.classList.remove(`lml-offline-banner--${name}`);
    });

    if (mode) {
        banner.classList.add(`lml-offline-banner--${mode}`);
    }
}

function replaceToastMode(toast, mode) {
    if (!toast || !toast.classList) {
        return;
    }

    ['saved', 'online', 'success', 'retry', 'notice'].forEach((name) => {
        toast.classList.remove(`lml-offline-toast--${name}`);
    });

    if (mode) {
        toast.classList.add(`lml-offline-toast--${mode}`);
    }
}

function readDetail(event) {
    return event && event.detail && typeof event.detail === 'object' ? event.detail : {};
}

/**
 * @param {ParentNode} root
 * @param {object} [options]
 */
export function initOfflineStatus(root, options = {}) {
    if (!root || typeof root.querySelector !== 'function') {
        return null;
    }

    const doc = root.ownerDocument || root;
    const win = options.window || (typeof window !== 'undefined' ? window : globalThis);
    const nav = options.navigator || win.navigator || { onLine: true };
    const fetchImpl = options.fetch || win.fetch;
    const statusUrl =
        options.statusUrl ||
        root.getAttribute?.('data-offline-status-url') ||
        '/offline/status';
    const toastDurationMs = options.toastDurationMs ?? 4200;
    const statusTimeoutMs = options.statusTimeoutMs ?? 8000;
    const recoveryPollMs = options.recoveryPollMs ?? 4000;

    if (root.lmlingaOffline && typeof root.lmlingaOffline.destroy === 'function') {
        root.lmlingaOffline.destroy();
    }

    const banner = root.querySelector('[data-lml-offline-banner]');
    const bannerText = root.querySelector('[data-lml-offline-banner-text]');
    const bannerIcon = root.querySelector('[data-lml-offline-banner-icon]');
    const bannerReview = root.querySelector('[data-lml-offline-banner-review]');
    const toast = root.querySelector('[data-lml-offline-toast]');
    const dialog = root.querySelector('[data-lml-offline-dialog]');
    const dialogTitle = root.querySelector('[data-lml-offline-dialog-title]');
    const dialogBody = root.querySelector('[data-lml-offline-dialog-body]');
    const dialogDismiss = root.querySelector('[data-lml-offline-dialog-dismiss]');
    const dialogDone = root.querySelector('[data-lml-offline-changes-done]');
    const dialogEdit = root.querySelector('[data-lml-offline-dialog-edit]');
    const dialogBackdrop = root.querySelector('[data-lml-offline-dialog-backdrop]');
    const changesList = root.querySelector('[data-lml-offline-changes-list]');
    const changesTabs = root.querySelector('[data-lml-offline-changes-tabs]');
    const discardDialog = root.querySelector('[data-lml-offline-discard-dialog]');
    const discardTitle = root.querySelector('[data-lml-offline-discard-title]');
    const discardBody = root.querySelector('[data-lml-offline-discard-body]');
    const discardKeep = root.querySelector('[data-lml-offline-discard-keep]');
    const discardConfirm = root.querySelector('[data-lml-offline-discard-confirm]');
    const discardBackdrop = root.querySelector('[data-lml-offline-discard-backdrop]');
    const live = root.querySelector('[data-lml-offline-live]');
    const topbar = root.querySelector('[data-lml-offline-topbar]');
    const topbarLabel = root.querySelector('[data-lml-offline-topbar-label]');
    const topbarIcon = root.querySelector('[data-lml-offline-topbar-icon]');
    const pendingChip = root.querySelector('[data-lml-offline-pending]');
    const pendingLabel = root.querySelector('[data-lml-offline-pending-label]');

    const state = {
        connectivity: 'checking',
        sync: 'idle',
        pendingCount: 0,
        attentionItems: [],
        dialogItemId: null,
        previousFocus: null,
        changesFilter: 'all',
        changesItems: [],
        changesOpen: false,
        discardPlan: null,
        discardOpen: false,
    };

    let toastTimer = 0;
    let sessionProbe = 0;
    let recoveryTimer = 0;
    let destroyed = false;
    const listeners = [];

    function on(target, type, handler) {
        if (!target || typeof target.addEventListener !== 'function') {
            return;
        }
        target.addEventListener(type, handler);
        listeners.push([target, type, handler]);
    }

    function announce(message) {
        if (!live || !message) {
            return;
        }
        setText(live, message);
    }

    function setRootState(value) {
        root.setAttribute?.('data-lml-offline-state', value);
    }

    function currentUiState() {
        if (state.connectivity === 'offline') {
            return 'offline';
        }
        if (state.connectivity === 'checking') {
            return 'checking';
        }
        if (state.sync === 'syncing') {
            return 'syncing';
        }
        if (state.sync === 'retry') {
            return 'retry';
        }
        if (state.attentionItems.length > 0) {
            return 'attention';
        }
        return 'online';
    }

    function renderTopbar() {
        const connectivity = state.connectivity;
        const syncing = connectivity === 'online' && state.sync === 'syncing';
        const online = connectivity === 'online';
        const offline = connectivity === 'offline';
        const checking = connectivity === 'checking';
        if (topbar?.classList) {
            topbar.classList.toggle('lml-topbar__status--online', online);
            topbar.classList.toggle('lml-topbar__status--offline', offline);
            topbar.classList.toggle('lml-topbar__status--checking', checking);
        }
        if (topbar) {
            const label =
                online ? 'online' : offline ? 'offline' : 'checking';
            topbar.setAttribute('data-connectivity', label);
            topbar.setAttribute(
                'aria-label',
                offline
                    ? 'Connection status: offline'
                    : checking
                      ? 'Connection status: checking'
                      : syncing
                        ? 'Connection status: syncing'
                        : 'Connection status: online',
            );
        }
        setText(
            topbarLabel,
            offline ? 'Offline' : checking ? 'Checking' : syncing ? 'Syncing…' : 'Online',
        );
        if (topbarIcon?.classList) {
            topbarIcon.classList.remove('bi-wifi', 'bi-wifi-off', 'bi-arrow-repeat');
            topbarIcon.classList.add(
                offline ? 'bi-wifi-off' : checking || syncing ? 'bi-arrow-repeat' : 'bi-wifi',
            );
        }

        const waiting = Number(state.pendingCount) || 0;
        if (pendingChip) {
            setHidden(pendingChip, waiting <= 0);
            pendingChip.setAttribute('data-pending-count', String(waiting));
        }
        if (pendingLabel) {
            setText(
                pendingLabel,
                waiting <= 1 ? OFFLINE_MESSAGES.pendingOne : OFFLINE_MESSAGES.pendingMany.replace('{count}', String(waiting)),
            );
        }
    }

    function renderBanner() {
        const ui = currentUiState();
        setRootState(ui);

        if (ui === 'online' || ui === 'checking') {
            replaceBannerMode(banner, null);
            setHidden(banner, true);
            setHidden(bannerReview, true);
            setText(bannerText, '');
            return;
        }

        setHidden(banner, false);
        replaceBannerMode(banner, ui);

        if (ui === 'offline') {
            const waiting = Number(state.pendingCount) || 0;
            const offlineBanner =
                waiting <= 0
                    ? OFFLINE_MESSAGES.connectivityLost
                    : waiting === 1
                      ? OFFLINE_MESSAGES.connectivityLostWithPendingOne
                      : OFFLINE_MESSAGES.connectivityLostWithPending.replace('{count}', String(waiting));
            setText(bannerText, offlineBanner);
            if (bannerIcon?.classList) {
                bannerIcon.classList.remove('bi-cloud-arrow-up', 'bi-exclamation-circle', 'bi-arrow-repeat');
                bannerIcon.classList.add('bi-wifi-off');
            }
        } else if (ui === 'syncing') {
            setText(bannerText, OFFLINE_MESSAGES.syncing);
            if (bannerIcon?.classList) {
                bannerIcon.classList.remove('bi-wifi-off', 'bi-exclamation-circle', 'bi-arrow-repeat');
                bannerIcon.classList.add('bi-cloud-arrow-up');
            }
        } else if (ui === 'retry') {
            setText(bannerText, OFFLINE_MESSAGES.syncRetry);
            if (bannerIcon?.classList) {
                bannerIcon.classList.remove('bi-wifi-off', 'bi-cloud-arrow-up', 'bi-exclamation-circle');
                bannerIcon.classList.add('bi-arrow-repeat');
            }
        } else {
            setText(bannerText, attentionBannerText(state.attentionItems.length));
            if (bannerIcon?.classList) {
                bannerIcon.classList.remove('bi-wifi-off', 'bi-cloud-arrow-up', 'bi-arrow-repeat');
                bannerIcon.classList.add('bi-exclamation-circle');
            }
        }

        const showReview = state.attentionItems.length > 0 || Number(state.pendingCount) > 0;
        setHidden(bannerReview, !showReview);
        if (bannerReview) {
            setText(bannerReview, OFFLINE_MESSAGES.attentionReview);
        }
    }

    function hideToast() {
        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = 0;
        }
        setHidden(toast, true);
        setText(toast, '');
        replaceToastMode(toast, null);
    }

    function showToast(message, mode) {
        if (!toast || !message) {
            return;
        }

        setText(toast, message);
        replaceToastMode(toast, mode);
        setHidden(toast, false);
        announce(message);

        if (toastTimer) {
            clearTimeout(toastTimer);
        }

        if (toastDurationMs > 0) {
            toastTimer = win.setTimeout(() => {
                hideToast();
            }, toastDurationMs);
        }
    }

    function ackStore() {
        try {
            const store = win.sessionStorage;
            if (!store || typeof store.getItem !== 'function' || typeof store.setItem !== 'function') {
                return null;
            }
            return store;
        } catch {
            return null;
        }
    }

    function readActorId() {
        return String(root.getAttribute?.('data-offline-actor-id') || '0');
    }

    function attentionAckKeyFor(item) {
        const operationId = item?.operation_id || item?.id || '';
        return attentionAckStorageKey(readActorId(), operationId, item?.code || '');
    }

    function hasAttentionAck(key) {
        if (!key) {
            return false;
        }
        try {
            return ackStore()?.getItem(key) === '1';
        } catch {
            return false;
        }
    }

    function writeAttentionAck(key) {
        if (!key) {
            return;
        }
        try {
            ackStore()?.setItem(key, '1');
        } catch {
            // Private mode or missing sessionStorage: Review still works.
        }
    }

    function clearAttentionAck(key) {
        if (!key) {
            return;
        }
        try {
            ackStore()?.removeItem(key);
        } catch {
            // Ignore storage failures.
        }
    }

    function hideDialog(options = {}) {
        closeDiscardDialog();
        setHidden(dialog, true);
        if (dialog?.classList) {
            dialog.classList.remove('is-open');
        }
        if (dialog) {
            dialog.setAttribute('aria-hidden', 'true');
        }
        state.dialogItemId = null;
        state.changesOpen = false;
        if (options.resetEdit !== false && dialogEdit) {
            dialogEdit.removeAttribute('href');
            dialogEdit.removeAttribute('data-hh-nav');
            setHidden(dialogEdit, true);
        }
        const restore = state.previousFocus;
        state.previousFocus = null;
        if (options.restoreFocus !== false && restore && typeof restore.focus === 'function') {
            restore.focus();
        }
    }

    function closeDialog() {
        const openItem = state.attentionItems.find((item) => item.id === state.dialogItemId);
        if (openItem) {
            const key = openItem.ackKey || attentionAckKeyFor(openItem);
            openItem.ackKey = key;
            writeAttentionAck(key);
        }
        hideDialog();
    }

    function updateChangesTabUi() {
        if (!changesTabs) {
            return;
        }
        const counts = offlineChangesCounts(state.changesItems);
        changesTabs.querySelectorAll('[data-lml-offline-changes-tab]').forEach((tab) => {
            const filter = tab.getAttribute('data-lml-offline-changes-tab') || 'all';
            const selected = filter === state.changesFilter;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        ['all', 'waiting', 'attention'].forEach((key) => {
            const node = root.querySelector(`[data-lml-offline-changes-count="${key}"]`);
            if (node) {
                setText(node, String(counts[key] || 0));
            }
        });
    }

    function paintChangesList() {
        if (!changesList) {
            return;
        }
        changesList.innerHTML = renderOfflineChangesListHtml(state.changesItems, state.changesFilter);
        updateChangesTabUi();
    }

    function closeDiscardDialog() {
        state.discardPlan = null;
        state.discardOpen = false;
        if (discardDialog) {
            setHidden(discardDialog, true);
            discardDialog.classList?.remove('is-open');
            discardDialog.setAttribute('aria-hidden', 'true');
        }
    }

    function openDiscardDialog(plan) {
        if (!plan?.ok) {
            return false;
        }
        if (!discardDialog) {
            return false;
        }
        const copy = buildDiscardConfirmation(plan);
        state.discardPlan = plan;
        state.discardOpen = true;
        setText(discardTitle, copy.title);
        setText(discardBody, copy.body);
        if (discardKeep) {
            setText(discardKeep, copy.keepLabel || 'Keep Change');
        }
        if (discardConfirm) {
            setText(discardConfirm, copy.confirmLabel || "Don't Sync");
        }
        setHidden(discardDialog, false);
        discardDialog.classList?.add('is-open');
        discardDialog.setAttribute('aria-hidden', 'false');
        const focusTarget = discardKeep || discardConfirm;
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }
        return true;
    }

    async function beginDiscardForLocalId(localId) {
        const actorId = Number(readActorId()) || 0;
        if (!Number.isInteger(actorId) || actorId <= 0 || !localId) {
            showToast(OFFLINE_MESSAGES.discardFailed, 'attention');
            return;
        }
        let plan;
        try {
            plan = await collectDiscardPlan(actorId, localId);
        } catch {
            showToast(OFFLINE_MESSAGES.discardFailed, 'attention');
            return;
        }
        if (plan?.already_absent) {
            // The durable row is already gone: the desired end state is satisfied.
            let absent;
            try {
                absent = await resolveAbsentDiscard(actorId, localId);
            } catch {
                absent = { ok: false };
            }
            if (absent?.ok) {
                await applyDiscardResult(absent);
                return;
            }
        }
        if (!plan?.ok) {
            showToast(OFFLINE_MESSAGES.discardFailed, 'attention');
            return;
        }
        if (!openDiscardDialog(plan)) {
            showToast(OFFLINE_MESSAGES.discardFailed, 'attention');
        }
    }

    async function confirmDiscard() {
        const plan = state.discardPlan;
        const actorId = Number(readActorId()) || 0;
        if (!plan?.ok || !Number.isInteger(actorId) || actorId <= 0) {
            closeDiscardDialog();
            return;
        }
        let result;
        try {
            result = await executeDiscardPlan(plan, { actorId });
        } catch {
            result = { ok: false };
        }
        closeDiscardDialog();
        if (!result?.ok) {
            showToast(OFFLINE_MESSAGES.discardFailed, 'attention');
            return;
        }
        await applyDiscardResult(result);
    }

    async function applyDiscardResult(result) {
        const removed = Array.isArray(result.removed_ids) ? result.removed_ids : [];
        const gone = new Set([
            ...removed,
            ...(Array.isArray(result.absent_ids) ? result.absent_ids : []),
            result.root_local_id,
        ].filter(Boolean).map(String));
        // Only reached after the durable rows are verified gone (or already absent).
        state.attentionItems = state.attentionItems.filter((item) => {
            const id = String(item.id || item.operation_id || '');
            return id && !gone.has(id) && !isSyntheticOfflineChangeItem(item);
        });
        state.changesItems = state.changesItems.filter((item) => !gone.has(String(item.id || '')));
        state.pendingCount = Number(result.pending) || 0;
        const toastMsg = removed.length > 1
            ? OFFLINE_MESSAGES.discardToastMany.replace('{count}', String(removed.length))
            : OFFLINE_MESSAGES.discardToast;
        showToast(toastMsg, 'saved');
        emitEvent(OFFLINE_EVENTS.DISCARDED, {
            pending: state.pendingCount,
            removed_ids: removed,
            root_local_id: result.root_local_id,
            root_operation_type: result.root_operation_type,
        });
        render();
        if (state.changesOpen) {
            await loadAndRenderChanges();
        } else if (isShown(dialog)) {
            // Single-item prompt: repaint the remaining cards or close when none are left.
            if (state.changesItems.length === 0) {
                hideDialog();
            } else {
                paintChangesList();
            }
        }
    }

    async function handleRetryClick(localId) {
        const actorId = Number(readActorId()) || 0;
        if (!Number.isInteger(actorId) || actorId <= 0 || !localId) {
            showToast(OFFLINE_MESSAGES.retryFailed, 'attention');
            return;
        }
        let result;
        try {
            result = await retryQueuedOperation(actorId, localId);
        } catch {
            result = { ok: false };
        }
        if (!result?.ok) {
            showToast(OFFLINE_MESSAGES.retryFailed, 'attention');
            return;
        }
        state.pendingCount = Number(result.pending) || state.pendingCount;
        showToast(OFFLINE_MESSAGES.retryQueued, 'syncing');
        emitEvent(OFFLINE_EVENTS.RETRY_REQUESTED, {
            pending: state.pendingCount,
            local_id: result.local_id,
            operation_id: result.operation_id,
        });
        render();
        if (state.changesOpen) {
            await loadAndRenderChanges();
        }
    }

    function emitEvent(name, detail = {}) {
        const event =
            typeof CustomEvent === 'function'
                ? new CustomEvent(name, { detail })
                : { type: name, detail };
        if (typeof win.dispatchEvent === 'function') {
            win.dispatchEvent(event);
        }
    }

    function openChangesPanel(options = {}) {
        if (!dialog) {
            return;
        }
        const active = doc.activeElement;
        if (active && typeof active.focus === 'function') {
            state.previousFocus = active;
        }
        state.changesOpen = true;
        state.changesFilter = options.filter || state.changesFilter || 'all';
        setText(dialogTitle, OFFLINE_MESSAGES.attentionTitle);
        setText(dialogBody, OFFLINE_MESSAGES.offlineChangesLoading);
        if (changesList) {
            changesList.innerHTML = `<p class="lml-offline-changes__empty">${OFFLINE_MESSAGES.offlineChangesLoading}</p>`;
        }
        if (dialogEdit) {
            setHidden(dialogEdit, true);
        }
        if (dialog) {
            dialog.setAttribute('data-offline-code', 'OFFLINE_CHANGES');
            dialog.setAttribute('data-offline-item', 'changes-panel');
            dialog.classList?.add('is-open');
        }
        setHidden(dialog, false);
        if (dialog) {
            dialog.setAttribute('aria-hidden', 'false');
        }
        state.dialogItemId = 'changes-panel';
        void loadAndRenderChanges();
        const focusTarget = dialogDismiss || dialogDone;
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }
    }

    async function loadAndRenderChanges() {
        const actorId = Number(readActorId()) || 0;
        let items = [];
        try {
            if (Number.isInteger(actorId) && actorId > 0) {
                items = await listOfflineChangesForActor(actorId);
            }
        } catch {
            items = [];
        }
        if (!state.changesOpen) {
            return;
        }
        // Merge in-memory attention prompts (validation UI events) when not yet
        // represented by an IndexedDB row — keeps Review useful after a soft prompt.
        // Exclude synthetic banner summaries (queue:unsynced / QUEUE_NEEDS_ATTENTION).
        const known = new Set(items.map((item) => item.id));
        state.attentionItems.forEach((item) => {
            if (isSyntheticOfflineChangeItem(item)) {
                return;
            }
            const id = String(item.id || item.operation_id || '');
            if (!id || known.has(id)) {
                return;
            }
            known.add(id);
            items.push({
                id,
                operation_id: item.operation_id || id,
                operation_type: item.operation_type || '',
                title: item.member_name
                    || (item.household_no ? `Household ${item.household_no}` : 'Offline update'),
                module: item.operation_type === 'RESIDENT_CREATE' || item.operation_type === 'RESIDENT_UPDATE'
                    ? 'Household Member'
                    : (item.operation_type === 'HOUSEHOLD_CREATE' || item.operation_type === 'HOUSEHOLD_UPDATE'
                        ? 'Household Profiling'
                        : 'Offline'),
                action: item.operation_type === 'RESIDENT_CREATE'
                    ? 'Create Member'
                    : (item.operation_type === 'HOUSEHOLD_CREATE' ? 'Create Household' : (item.operation_type || 'Update')),
                status_key: 'attention',
                status_label: 'Needs attention',
                problem: item.reason || item.message || '',
                href: safeAttentionRepairHref(item.href),
                cta_label: safeAttentionRepairHref(item.href) ? 'Review Record' : '',
                show_review: Boolean(safeAttentionRepairHref(item.href)),
                show_retry: false,
                show_discard: Boolean(id) && !isSyntheticOfflineChangeItem(item),
                group: 'attention',
                household_no: item.household_no || '',
                member_no: item.member_no || '',
                member_name: item.member_name || '',
            });
        });
        state.changesItems = items;
        const counts = offlineChangesCounts(items);
        if (counts.attention > 0) {
            setText(
                dialogBody,
                counts.attention === 1
                    ? OFFLINE_MESSAGES.syncNeedsAttentionOne
                    : OFFLINE_MESSAGES.syncNeedsAttentionMany.replace('{count}', String(counts.attention)),
            );
        } else if (counts.all > 0) {
            setText(
                dialogBody,
                counts.all === 1
                    ? OFFLINE_MESSAGES.connectivityLostWithPendingOne
                    : OFFLINE_MESSAGES.connectivityLostWithPending.replace('{count}', String(counts.all)),
            );
        } else {
            setText(dialogBody, OFFLINE_MESSAGES.offlineChangesEmpty);
        }
        paintChangesList();
        announce(dialogBody?.textContent || OFFLINE_MESSAGES.attentionTitle);
    }

    function openDialog(item) {
        // Single-item auto-prompt still supported; Review opens the full list.
        if (!dialog || !item) {
            return;
        }

        const active = doc.activeElement;
        if (active && typeof active.focus === 'function') {
            state.previousFocus = active;
        }

        state.changesOpen = false;
        setText(dialogTitle, OFFLINE_MESSAGES.attentionTitle);
        setText(dialogBody, item.message);
        if (changesList) {
            const composed = {
                id: item.id,
                title: item.member_name || (item.household_no ? `Household ${item.household_no}` : 'Offline update'),
                module: item.operation_type || 'Offline',
                action: item.operation_type || '',
                status_key: 'attention',
                status_label: 'Needs attention',
                problem: item.reason || item.message,
                href: item.href || '',
                cta_label: item.href ? 'Review Record' : '',
                show_review: Boolean(item.href),
                show_retry: false,
                show_discard: Boolean(item.id),
                group: 'attention',
            };
            changesList.innerHTML = renderOfflineChangesListHtml([composed], 'attention');
            state.changesItems = [composed];
            state.changesFilter = 'attention';
            updateChangesTabUi();
        }
        if (dialogEdit) {
            const href = safeAttentionRepairHref(item.href)
                || safeAttentionRepairHref(dialogEdit.getAttribute('href'))
                || safeAttentionRepairHref(dialogEdit.href);
            if (href) {
                dialogEdit.setAttribute('href', href);
                const navKind = item.nav_kind
                    || (safeHouseholdEditHref(href) ? 'household-edit' : 'edit-member');
                dialogEdit.setAttribute('data-hh-nav', navKind);
                setHidden(dialogEdit, false);
            } else {
                dialogEdit.removeAttribute('href');
                dialogEdit.removeAttribute('data-hh-nav');
                setHidden(dialogEdit, true);
            }
        }
        if (dialog) {
            dialog.setAttribute('data-offline-code', item.code || '');
            dialog.setAttribute('data-offline-item', item.id || '');
            dialog.classList?.add('is-open');
        }
        setHidden(dialog, false);
        if (dialog) {
            dialog.setAttribute('aria-hidden', 'false');
        }
        state.dialogItemId = item.id;
        if (dialogDismiss && typeof dialogDismiss.focus === 'function') {
            dialogDismiss.focus();
        }
        announce(item.message);
    }

    function render() {
        renderTopbar();
        renderBanner();
    }

    function stopRecoveryPolling() {
        if (recoveryTimer) {
            win.clearInterval(recoveryTimer);
            recoveryTimer = 0;
        }
    }

    function startRecoveryPolling() {
        if (recoveryTimer || destroyed || recoveryPollMs <= 0) {
            return;
        }
        recoveryTimer = win.setInterval(() => {
            if (destroyed || state.connectivity !== 'offline') {
                return;
            }
            void confirmServerSession({ silent: false, keepOfflineUntilSuccess: true });
        }, recoveryPollMs);
    }

    function emitRestored() {
        if (typeof win.dispatchEvent !== 'function') {
            return;
        }
        const event =
            typeof CustomEvent === 'function'
                ? new CustomEvent(OFFLINE_EVENTS.ONLINE, { detail: { pending: state.pendingCount } })
                : { type: OFFLINE_EVENTS.ONLINE, detail: { pending: state.pendingCount } };
        win.dispatchEvent(event);
    }

    function enterOffline(options = {}) {
        const wasOnline = state.connectivity === 'online';
        state.connectivity = 'offline';
        if (state.sync === 'syncing') {
            state.sync = 'retry';
        }
        render();
        startRecoveryPolling();
        if (wasOnline && options.announce !== false) {
            announce(OFFLINE_MESSAGES.connectivityLost);
        }
    }

    function markOnlineUi(pendingCount, options = {}) {
        const wasOffline = state.connectivity === 'offline';
        state.connectivity = 'online';
        if (state.sync === 'retry') {
            state.sync = 'idle';
        }
        if (typeof pendingCount === 'number' && pendingCount >= 0) {
            state.pendingCount = pendingCount;
        }
        stopRecoveryPolling();
        render();
        if (options.silent && !wasOffline) {
            return;
        }
        const message =
            state.pendingCount > 0
                ? OFFLINE_MESSAGES.backOnlineWithPending
                : OFFLINE_MESSAGES.backOnline;
        showToast(message, 'online');
        if (wasOffline) {
            emitRestored();
        }
    }

    async function confirmServerSession(options = {}) {
        if (destroyed) {
            return { ok: false, reason: 'destroyed' };
        }

        const keepOfflineUntilSuccess = options.keepOfflineUntilSuccess === true || state.connectivity === 'offline';
        if (!keepOfflineUntilSuccess) {
            state.connectivity = 'checking';
        }
        sessionProbe += 1;
        const probeId = sessionProbe;

        if (typeof fetchImpl !== 'function') {
            state.sync = 'retry';
            state.connectivity = 'offline';
            render();
            startRecoveryPolling();
            return { ok: false, reason: 'no-fetch' };
        }

        const controller =
            typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller
            ? win.setTimeout(() => controller.abort(), statusTimeoutMs)
            : 0;

        try {
            const response = await fetchImpl(statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller ? controller.signal : undefined,
            });

            if (destroyed || probeId !== sessionProbe) {
                return { ok: false, reason: 'stale' };
            }

            let payload = null;
            try {
                payload = await response.json();
            } catch {
                payload = null;
            }

            if (!response.ok || !payload || payload.ok !== true) {
                const code = payload && typeof payload.code === 'string' ? payload.code : '';
                if (isAttentionCode(code)) {
                    addAttention({
                        code,
                        id: `session:${code}`,
                    });
                    state.connectivity = 'online';
                    stopRecoveryPolling();
                    render();
                    return { ok: false, reason: 'attention', code };
                }

                state.connectivity = 'offline';
                state.sync = 'retry';
                render();
                startRecoveryPolling();
                return { ok: false, reason: 'unreachable' };
            }

            if (payload.is_active === false) {
                addAttention({
                    code: 'ACCOUNT_INACTIVE',
                    id: 'session:ACCOUNT_INACTIVE',
                });
            }

            markOnlineUi(state.pendingCount, { silent: options.silent === true });
            return { ok: true };
        } catch {
            if (destroyed || probeId !== sessionProbe) {
                return { ok: false, reason: 'stale' };
            }
            state.connectivity = 'offline';
            state.sync = 'retry';
            render();
            startRecoveryPolling();
            return { ok: false, reason: 'network' };
        } finally {
            if (timer) {
                clearTimeout(timer);
            }
        }
    }

    function addAttention(raw) {
        const code = typeof raw.code === 'string' ? raw.code : '';
        const id =
            sanitizeVisibleText(raw.id, '') ||
            sanitizeVisibleText(raw.itemId, '') ||
            `attention-${state.attentionItems.length + 1}`;
        const mapped = attentionMessageForCode(code);
        const custom = sanitizeVisibleText(raw.message, '');
        const existing = state.attentionItems.find((item) => item.id === id);
        const item = existing || { id, code, message: mapped };
        item.code = code;
        item.operation_id = sanitizeVisibleText(raw.operation_id, '') || item.operation_id || id;
        item.household_no = sanitizeVisibleText(raw.household_no, '');
        item.member_name = sanitizeVisibleText(raw.member_name, '');
        item.member_no = sanitizeVisibleText(raw.member_no, '');
        item.operation_type = sanitizeVisibleText(raw.operation_type, '');
        item.reason = sanitizeVisibleText(raw.reason, '');
        item.href = safeAttentionRepairHref(raw.href);
        item.nav_kind = sanitizeVisibleText(raw.nav_kind, '')
            || (safeHouseholdEditHref(item.href) ? 'household-edit' : (item.href ? 'edit-member' : ''));
        const composed = composeAttentionDialogMessage(item);
        item.message = composed || (custom && !SENSITIVE_PATTERN.test(custom) ? custom : mapped);
        item.ackKey = attentionAckKeyFor(item);

        if (!existing) {
            state.attentionItems.push(item);
        }

        if (typeof raw.pending === 'number') {
            state.pendingCount = raw.pending;
        }

        state.sync = 'idle';
        render();

        const store = ackStore();
        const alreadyShown = store ? hasAttentionAck(item.ackKey) : true;
        if (!alreadyShown) {
            writeAttentionAck(item.ackKey);
            openDialog(item);
            if (code === 'QUEUE_NEEDS_ATTENTION' && !composed) {
                showToast(item.message, 'retry');
            }
        }
        return item;
    }

    function handleSaved(detail) {
        showToast(OFFLINE_MESSAGES.localSave, 'saved');
        if (typeof detail.pending === 'number') {
            state.pendingCount = detail.pending;
        } else {
            state.pendingCount += 1;
        }
        const savedId = sanitizeVisibleText(detail.local_id, '');
        if (savedId) {
            const removed = state.attentionItems.filter((item) => item.id === savedId);
            state.attentionItems = state.attentionItems.filter((item) => item.id !== savedId);
            removed.forEach((item) => clearAttentionAck(item.ackKey || attentionAckKeyFor(item)));
            if (state.attentionItems.length === 0) {
                closeDialog();
            }
        }
    }

    function handleSyncStart(detail) {
        if (typeof detail.pending === 'number') {
            state.pendingCount = detail.pending;
        }
        if (state.connectivity === 'online') {
            state.sync = 'syncing';
            render();
            announce(OFFLINE_MESSAGES.syncing);
        }
    }

    function handleSyncSuccess(detail) {
        const pending = typeof detail.pending === 'number' ? detail.pending : 0;
        state.pendingCount = pending;
        if (pending === 0) {
            state.attentionItems.forEach((item) => {
                clearAttentionAck(item.ackKey || attentionAckKeyFor(item));
            });
            state.attentionItems = [];
            closeDialog();
            state.sync = 'idle';
            render();
            showToast(OFFLINE_MESSAGES.syncComplete, 'success');
            return;
        }
        if (state.attentionItems.length > 0) {
            render();
            return;
        }
        addAttention({
            code: 'QUEUE_NEEDS_ATTENTION',
            id: 'queue:unsynced',
            pending,
            message: unsyncedAttentionMessage(pending),
        });
    }

    function handleSyncRetry(detail) {
        if (isAttentionCode(detail.code)) {
            addAttention(detail);
            return;
        }
        state.sync = 'retry';
        render();
        showToast(OFFLINE_MESSAGES.syncRetry, 'retry');
    }

    function handleNativeOffline() {
        enterOffline();
    }

    function handleNativeOnline() {
        void confirmServerSession();
    }

    function handleCustomOffline() {
        enterOffline();
    }

    function handleCustomOnline(event) {
        const detail = readDetail(event);
        if (typeof detail.pending === 'number') {
            state.pendingCount = detail.pending;
        }
        if (state.connectivity === 'online') {
            render();
            return;
        }
        void confirmServerSession();
    }

    function handleCustomSaved(event) {
        handleSaved(readDetail(event));
        render();
    }

    function handleStorageFailed(event) {
        const detail = readDetail(event);
        const message =
            sanitizeVisibleText(detail.message, '') || OFFLINE_MESSAGES.storageFailed;
        showToast(message, 'retry');
    }

    function handleCustomSyncStart(event) {
        handleSyncStart(readDetail(event));
    }

    function handleCustomSyncSuccess(event) {
        handleSyncSuccess(readDetail(event));
    }

    function handleCustomSyncRetry(event) {
        handleSyncRetry(readDetail(event));
    }

    function handleCustomAttention(event) {
        addAttention(readDetail(event));
    }

    function handleNotice(event) {
        const detail = readDetail(event);
        const message = sanitizeVisibleText(detail.message, '');
        if (!message) {
            return;
        }
        showToast(message, 'notice');
    }

    function isShown(el) {
        if (!el) {
            return false;
        }
        if (el.hidden === true) {
            return false;
        }
        if (typeof el.hasAttribute === 'function' && el.hasAttribute('hidden')) {
            return false;
        }
        return true;
    }

    function handleKeydown(event) {
        if (!event || event.key !== 'Escape') {
            return;
        }
        if (state.discardOpen || isShown(discardDialog)) {
            closeDiscardDialog();
            return;
        }
        if (isShown(dialog)) {
            closeDialog();
        }
    }

    on(win, 'offline', handleNativeOffline);
    on(win, 'online', handleNativeOnline);
    on(win, 'focus', () => {
        if (state.connectivity === 'offline') {
            void confirmServerSession({ keepOfflineUntilSuccess: true });
        }
    });
    on(win, OFFLINE_EVENTS.OFFLINE, handleCustomOffline);
    on(win, OFFLINE_EVENTS.ONLINE, handleCustomOnline);
    on(win, OFFLINE_EVENTS.SAVED, handleCustomSaved);
    on(win, OFFLINE_EVENTS.STORAGE_FAILED, handleStorageFailed);
    on(win, OFFLINE_EVENTS.SYNC_START, handleCustomSyncStart);
    on(win, OFFLINE_EVENTS.SYNC_SUCCESS, handleCustomSyncSuccess);
    on(win, OFFLINE_EVENTS.SYNC_RETRY, handleCustomSyncRetry);
    on(win, OFFLINE_EVENTS.SYNC_ATTENTION, handleCustomAttention);
    on(win, OFFLINE_EVENTS.NOTICE, handleNotice);
    on(win, 'keydown', handleKeydown);
    function navigateTo(url) {
        if (typeof options.assignLocation === 'function') {
            options.assignLocation(url);
            return;
        }
        if (typeof win.location?.assign === 'function') {
            win.location.assign(url);
            return;
        }
        if (win.location) {
            win.location.href = url;
        }
    }

    on(dialogDismiss, 'click', closeDialog);
    on(dialogDone, 'click', closeDialog);
    on(dialogEdit, 'click', (event) => {
        const href = safeAttentionRepairHref(dialogEdit.getAttribute('href'))
            || safeAttentionRepairHref(dialogEdit.href);
        if (!href) {
            event?.preventDefault?.();
            closeDialog();
            return;
        }
        if (event?.metaKey || event?.ctrlKey || event?.shiftKey || event?.altKey) {
            return;
        }
        if (typeof event?.button === 'number' && event.button !== 0) {
            return;
        }
        event?.preventDefault?.();
        event?.stopPropagation?.();
        hideDialog({ resetEdit: false, restoreFocus: false });
        navigateTo(href);
    });
    on(changesTabs, 'click', (event) => {
        const tab = event.target?.closest?.('[data-lml-offline-changes-tab]');
        if (!tab) {
            return;
        }
        const filter = tab.getAttribute('data-lml-offline-changes-tab') || 'all';
        state.changesFilter = filter;
        paintChangesList();
    });
    on(dialog, 'click', (event) => {
        const discardBtn = event.target?.closest?.('[data-lml-offline-change-discard]');
        if (discardBtn) {
            event?.preventDefault?.();
            event?.stopPropagation?.();
            const localId = discardBtn.getAttribute('data-offline-local-id') || '';
            void beginDiscardForLocalId(localId);
            return;
        }
        const retryBtn = event.target?.closest?.('[data-lml-offline-change-retry]');
        if (retryBtn) {
            event?.preventDefault?.();
            event?.stopPropagation?.();
            const localId = retryBtn.getAttribute('data-offline-local-id') || '';
            void handleRetryClick(localId);
            return;
        }
        const cta = event.target?.closest?.('[data-lml-offline-change-cta]');
        if (cta) {
            const href = safeAttentionRepairHref(cta.getAttribute('href'))
                || safeAttentionRepairHref(cta.href);
            if (!href) {
                event?.preventDefault?.();
                return;
            }
            if (event?.metaKey || event?.ctrlKey || event?.shiftKey || event?.altKey) {
                return;
            }
            if (typeof event?.button === 'number' && event.button !== 0) {
                return;
            }
            event?.preventDefault?.();
            event?.stopPropagation?.();
            hideDialog({ resetEdit: false, restoreFocus: false });
            navigateTo(href);
            return;
        }
        const card = dialog.querySelector?.('.lml-offline-dialog__card');
        if (card && typeof card.contains === 'function' && card.contains(event.target)) {
            return;
        }
        if (event.target === dialogBackdrop || event.target === dialog) {
            closeDialog();
        }
    });
    on(discardKeep, 'click', () => {
        closeDiscardDialog();
    });
    on(discardConfirm, 'click', () => {
        void confirmDiscard();
    });
    on(discardDialog, 'click', (event) => {
        const card = discardDialog.querySelector?.('.lml-offline-dialog__card');
        if (card && typeof card.contains === 'function' && card.contains(event.target)) {
            return;
        }
        if (event.target === discardBackdrop || event.target === discardDialog) {
            closeDiscardDialog();
        }
    });
    on(bannerReview, 'click', () => {
        void openChangesPanel({
            filter: state.attentionItems.length > 0 ? 'attention' : 'all',
        });
    });
    on(pendingChip, 'click', () => {
        void openChangesPanel({ filter: 'all' });
    });

    const fromSwCache = documentHasSwCacheMarker(root);
    if (nav.onLine === false || fromSwCache) {
        enterOffline({ announce: nav.onLine === false });
    } else {
        render();
    }
    void confirmServerSession({
        silent: true,
        keepOfflineUntilSuccess: nav.onLine === false || fromSwCache,
    });

    const api = {
        events: OFFLINE_EVENTS,
        messages: OFFLINE_MESSAGES,
        attentionMessageForCode,
        getState() {
            return {
                connectivity: state.connectivity,
                sync: state.sync,
                pendingCount: state.pendingCount,
                ui: currentUiState(),
                attentionItems: state.attentionItems.map((item) => ({ ...item })),
                changesItems: state.changesItems.map((item) => ({ ...item })),
                changesFilter: state.changesFilter,
                changesOpen: state.changesOpen,
            };
        },
        openChangesPanel,
        refreshChanges: loadAndRenderChanges,
        setPendingCount(count) {
            state.pendingCount = Number(count) || 0;
            render();
        },
        confirmServerSession,
        emit(name, detail = {}) {
            const event =
                typeof CustomEvent === 'function'
                    ? new CustomEvent(name, { detail })
                    : { type: name, detail };
            if (typeof win.dispatchEvent === 'function') {
                win.dispatchEvent(event);
            }
        },
        destroy() {
            destroyed = true;
            sessionProbe += 1;
            stopRecoveryPolling();
            hideToast();
            listeners.forEach(([target, type, handler]) => {
                if (typeof target.removeEventListener === 'function') {
                    target.removeEventListener(type, handler);
                }
            });
            listeners.length = 0;
            if (root.lmlingaOffline === api) {
                root.lmlingaOffline = null;
            }
        },
    };

    root.lmlingaOffline = api;

    if (win) {
        win.LmlingaOffline = {
            events: OFFLINE_EVENTS,
            messages: OFFLINE_MESSAGES,
            attentionMessageForCode,
            emit: api.emit,
            getState: api.getState,
            setPendingCount: api.setPendingCount,
            confirmServerSession: api.confirmServerSession,
        };
    }

    return api;
}

if (typeof document !== 'undefined') {
    const bootRoot = document.querySelector('[data-lml-offline-root]');
    if (bootRoot) {
        initOfflineStatus(bootRoot);
    }
}

/**
 * Staff login: keep identity and password visually empty until the user types.
 *
 * Laravel already renders empty values. Chrome Password Manager may inject
 * saved credentials after paint. This routine clears those injections without
 * reading session, storage, cookies, query params, or the database.
 *
 * Clearing stops after genuine typing/paste so legitimate input is not erased.
 */

/** Short post-render windows — not an infinite timer. */
export const STAFF_LOGIN_CLEAR_DELAYS_MS = [0, 50, 150, 400, 800];

export function clearStaffLoginFields(form) {
    if (!form) {
        return;
    }

    const email = form.querySelector('#email, [name="email"]');
    const password = form.querySelector('#password, [name="password"]');

    if (email) {
        email.value = '';
    }
    if (password) {
        password.value = '';
    }
}

function isGenuineTyping(event) {
    if (!event || event.isTrusted === false) {
        return false;
    }

    if (event.type === 'paste') {
        return true;
    }

    if (event.type !== 'keydown') {
        return false;
    }

    if (event.metaKey || event.ctrlKey || event.altKey) {
        return false;
    }

    const key = event.key;
    if (
        !key ||
        key === 'Shift' ||
        key === 'Control' ||
        key === 'Alt' ||
        key === 'Meta' ||
        key === 'Tab' ||
        key === 'Escape' ||
        key === 'Enter'
    ) {
        return false;
    }

    return true;
}

function cancelScheduledClears(state, clearTimeoutFn) {
    state.clearTimeouts.forEach((id) => clearTimeoutFn(id));
    state.clearTimeouts.length = 0;
}

function bindUserEditGuard(form, state, clearTimeoutFn) {
    const markEdited = (event) => {
        if (!isGenuineTyping(event)) {
            return;
        }
        state.userHasInteracted = true;
        cancelScheduledClears(state, clearTimeoutFn);
    };

    form.addEventListener('keydown', markEdited);
    form.addEventListener('paste', markEdited);
}

function scheduleDelayedClears(form, state, setTimeoutFn) {
    STAFF_LOGIN_CLEAR_DELAYS_MS.forEach((delay) => {
        const id = setTimeoutFn(() => {
            if (state.userHasInteracted) {
                return;
            }
            clearStaffLoginFields(form);
        }, delay);
        state.clearTimeouts.push(id);
    });
}

function bindPageLifecycle(form, state, win, doc, setTimeoutFn, clearTimeoutFn) {
    const clearIfPristine = () => {
        if (state.userHasInteracted) {
            return;
        }
        clearStaffLoginFields(form);
        scheduleDelayedClears(form, state, setTimeoutFn);
    };

    if (doc?.addEventListener) {
        doc.addEventListener('DOMContentLoaded', clearIfPristine);
    }

    if (!win?.addEventListener) {
        return;
    }

    win.addEventListener('load', clearIfPristine);
    win.addEventListener('pageshow', (event) => {
        if (event?.persisted) {
            state.userHasInteracted = false;
            cancelScheduledClears(state, clearTimeoutFn);
        }
        clearIfPristine();
    });
}

/**
 * @param {ParentNode} root
 * @param {{ window?: Window, document?: Document, setTimeout?: typeof setTimeout }} [options]
 */
export function initStaffLoginEmptyFields(root = document, options = {}) {
    const forms = root.querySelectorAll?.('[data-lml-staff-login]') || [];
    const win = options.window ?? (typeof window !== 'undefined' ? window : null);
    const doc = options.document ?? (typeof document !== 'undefined' ? document : null);
    const setTimeoutFn = options.setTimeout ?? setTimeout;
    const clearTimeoutFn = options.clearTimeout ?? clearTimeout;

    forms.forEach((form) => {
        const state = {
            userHasInteracted: false,
            clearTimeouts: [],
        };

        clearStaffLoginFields(form);
        bindUserEditGuard(form, state, clearTimeoutFn);
        scheduleDelayedClears(form, state, setTimeoutFn);
        bindPageLifecycle(form, state, win, doc, setTimeoutFn, clearTimeoutFn);
    });
}

if (typeof document !== 'undefined' && document.querySelector('[data-lml-staff-login]')) {
    initStaffLoginEmptyFields(document);
}

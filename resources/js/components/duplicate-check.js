/**
 * Live possible-duplicate check for the member forms (kind "member") and the Spot Mapping head fields (kind "head").
 *
 * Opt-in markup:
 *   the first-name input carries  data-lml-duplicate-check="member|head"
 *                                 data-lml-duplicate-check-url="<route('staff.duplicate-check')>"
 *                                 data-lml-dup-first
 *   (member only)                 data-lml-dup-household-no="…", and on edit data-lml-dup-exclude-member="…"
 *   the last-name and birthday inputs carry  data-lml-dup-last  /  data-lml-dup-birthday
 *   optional: an element with data-lml-dup-slot receives the panel; data-lml-dup-scope marks the field container
 *   when there is no <form> (Spot Mapping).
 *
 * Once first name, last name and birthday are filled the staff endpoint is asked for matches. If any come back, a
 * warning panel (same classes as the server-rendered components/lml/possible-duplicate-panel.blade.php) lists them
 * with a "this is a different person/household" checkbox, and submitting is blocked until it is ticked. A
 * server-rendered panel already in the form is reused, never duplicated. The save-time server check always applies,
 * so every failure here (offline, errors, bad JSON) simply shows nothing.
 */

export const DEBOUNCE_MS = 300;

const MIN_NAME_LENGTH = 2;
const PANEL_SELECTOR = '[data-lml-possible-duplicate-panel]';
const CHECKBOX_SELECTOR = 'input[name="confirm_possible_duplicate"]';

const trimmed = (value) => String(value ?? '').trim();

/** values: {kind, firstName, lastName, birthday, householdNo, excludeMember} → query string. */
export function buildDuplicateQuery(values) {
    const params = new URLSearchParams();
    const kind = values?.kind === 'head' ? 'head' : 'member';
    params.set('kind', kind);
    params.set('first_name', trimmed(values?.firstName));
    params.set('last_name', trimmed(values?.lastName));
    params.set('birthday', trimmed(values?.birthday));
    if (kind === 'member') {
        params.set('household_no', trimmed(values?.householdNo));
        if (trimmed(values?.excludeMember) !== '') {
            params.set('exclude_member', trimmed(values.excludeMember));
        }
    }

    return params.toString();
}

export function shouldCheck(values) {
    const first = trimmed(values?.firstName).replace(/\s+/g, '');
    const last = trimmed(values?.lastName).replace(/\s+/g, '');
    if (first.length < MIN_NAME_LENGTH || last.length < MIN_NAME_LENGTH) return false;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed(values?.birthday))) return false;
    if (values?.kind !== 'head' && trimmed(values?.householdNo) === '') return false;

    return true;
}

/** Submit is held back only while matches are shown and not yet confirmed. */
export function canSubmit(state) {
    return !state?.hasMatches || Boolean(state?.confirmed);
}

/** Only same-origin http(s) links are followed. */
export function safeMatchUrl(url, origin) {
    if (url === null || url === undefined || url === '') return null;
    try {
        const parsed = new URL(String(url), origin);

        return parsed.origin === origin && /^https?:$/.test(parsed.protocol) ? parsed.toString() : null;
    } catch {
        return null;
    }
}

const controllers = new WeakMap();

/** The controller attached to a scope element (form / data-lml-dup-scope), or null. */
export function getDuplicateCheck(scope) {
    return scope && typeof scope === 'object' ? controllers.get(scope) ?? null : null;
}

export function attachDuplicateCheck(scope, win = window) {
    const first = scope.querySelector('[data-lml-duplicate-check]');
    if (!first || controllers.has(scope)) return null;
    const last = scope.querySelector('[data-lml-dup-last]');
    const birthday = scope.querySelector('[data-lml-dup-birthday]');
    const endpoint = first.dataset.lmlDuplicateCheckUrl;
    if (!last || !birthday || !endpoint) return null;

    const doc = scope.ownerDocument;
    const kind = first.dataset.lmlDuplicateCheck === 'head' ? 'head' : 'member';
    const confirmLabel = kind === 'head' ? 'This is a different household' : 'This is a different person';
    const message = kind === 'head'
        ? 'Possible duplicate: this head may already be registered. Confirm it is a different household to continue.'
        : 'Possible duplicate: a record with the same name and birthday already exists. Confirm it is a different person to continue.';

    const ownPanel = doc.createElement('div');
    ownPanel.className = 'lml-possible-duplicate';
    ownPanel.hidden = true;
    ownPanel.setAttribute('role', 'alert');
    ownPanel.setAttribute('tabindex', '-1');
    ownPanel.setAttribute('data-lml-possible-duplicate-panel', '');

    const state = {
        serverPanel: scope.querySelector(PANEL_SELECTOR) ?? null,
        ownMatches: false,
        timer: null,
        controller: null,
        requestId: 0,
        placed: false,
    };
    if (state.serverPanel && !state.serverPanel.getAttribute('tabindex')) {
        state.serverPanel.setAttribute('tabindex', '-1');
    }

    const activePanel = () => state.serverPanel ?? ownPanel;
    const confirmBox = () => (state.serverPanel ? state.serverPanel.querySelector(CHECKBOX_SELECTOR) : ownPanel.confirmBox ?? null);
    const hasMatches = () => (state.serverPanel ? Boolean(confirmBox()) : state.ownMatches);
    const isConfirmed = () => Boolean(confirmBox()?.checked);
    const isPending = () => !canSubmit({ hasMatches: hasMatches(), confirmed: isConfirmed() });

    function values() {
        return {
            kind,
            firstName: first.value,
            lastName: last.value,
            birthday: birthday.value,
            householdNo: first.dataset.lmlDupHouseholdNo,
            excludeMember: first.dataset.lmlDupExcludeMember,
        };
    }

    function place() {
        if (state.placed) return;
        const slot = scope.querySelector('[data-lml-dup-slot]');
        if (slot) {
            slot.append(ownPanel);
        } else {
            (birthday.parentElement || birthday).insertAdjacentElement('afterend', ownPanel);
        }
        state.placed = true;
    }

    function clearOwn() {
        state.ownMatches = false;
        ownPanel.confirmBox = null;
        ownPanel.replaceChildren();
        ownPanel.hidden = true;
    }

    /** Identity changed: any warning (live or server-rendered) is stale, and so is its confirmation. */
    function clear() {
        state.requestId += 1;
        state.controller?.abort();
        win.clearTimeout(state.timer);
        if (state.serverPanel) {
            const box = state.serverPanel.querySelector(CHECKBOX_SELECTOR);
            if (box) box.checked = false;
            state.serverPanel.remove();
            state.serverPanel = null;
        }
        clearOwn();
    }

    function render(items, forcedMessage = null) {
        const matches = Array.isArray(items) ? items.filter((i) => i && typeof i === 'object') : [];
        if (matches.length === 0 && !forcedMessage) {
            clearOwn();
            return;
        }
        const origin = win.location.origin;
        const text = doc.createElement('p');
        text.className = 'lml-possible-duplicate__message';
        text.textContent = forcedMessage || message;

        const list = doc.createElement('ul');
        list.className = 'lml-possible-duplicate__list';
        matches.forEach((item) => {
            const li = doc.createElement('li');
            const href = safeMatchUrl(item.url, origin);
            const name = String(item.name ?? '');
            if (href) {
                const a = doc.createElement('a');
                a.href = href;
                a.setAttribute('target', '_blank');
                a.setAttribute('rel', 'noopener');
                a.textContent = name;
                li.append(a);
            } else {
                const span = doc.createElement('span');
                span.textContent = name;
                li.append(span);
            }
            const relation = String(item.relation ?? '').trim();
            const detail = doc.createElement('span');
            detail.textContent = `${relation !== '' ? ` (${relation})` : ''} – Household ${String(item.household_no ?? '')}`;
            li.append(detail);
            list.append(li);
        });

        const label = doc.createElement('label');
        label.className = 'lml-possible-duplicate__confirm';
        const box = doc.createElement('input');
        box.type = 'checkbox';
        box.name = 'confirm_possible_duplicate';
        box.value = '1';
        box.checked = false;
        const caption = doc.createElement('span');
        caption.textContent = confirmLabel;
        label.append(box, caption);

        ownPanel.replaceChildren(...(matches.length > 0 ? [text, list, label] : [text, label]));
        ownPanel.confirmBox = box;
        state.ownMatches = true;
        place();
        ownPanel.hidden = false;
    }

    async function runCheck() {
        if (state.serverPanel) return; // the server already decided; its panel stays until a field changes
        const current = values();
        if (!shouldCheck(current) || win.navigator?.onLine === false) {
            clearOwn();
            return;
        }
        state.controller?.abort();
        state.controller = typeof win.AbortController === 'function' ? new win.AbortController() : null;
        state.requestId += 1;
        const mine = state.requestId;
        try {
            const url = new URL(endpoint, win.location.origin);
            url.search = buildDuplicateQuery(current);
            const response = await win.fetch(url.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: state.controller?.signal,
            });
            if (!response.ok) {
                if (mine === state.requestId) clearOwn();
                return;
            }
            const payload = await response.json();
            if (mine !== state.requestId) return; // fields changed while waiting
            render(payload?.items);
        } catch {
            if (mine === state.requestId) clearOwn(); // offline, aborted or malformed: the server check still applies
        }
    }

    /**
     * The server rejected a save with errors.possible_duplicate (Spot Mapping, JSON). Show the matches if the live
     * check finds them, otherwise a panel built from the server message, so staff can always confirm and resubmit.
     * Returns true when the error was handled here (the caller should not also show a generic error).
     */
    async function handleServerDuplicate(body) {
        const errors = body?.errors?.possible_duplicate;
        if (!Array.isArray(errors) || errors.length === 0) return false;
        if (!hasMatches()) {
            await runCheck();
            if (!hasMatches()) {
                const serverMessage = typeof errors[0] === 'string' && errors[0].trim() !== '' ? errors[0].trim() : message;
                render([], serverMessage);
            }
        }
        activePanel().focus?.();

        return true;
    }

    const identityKey = () => [first.value, last.value, birthday.value].map(trimmed).join('|');
    let lastKey = identityKey();

    function onIdentityChange() {
        const key = identityKey();
        if (key === lastKey) return; // e.g. `change` after `input`, or blur caused by clicking the confirm checkbox
        lastKey = key;
        clear();
        state.timer = win.setTimeout(() => { void runCheck(); }, DEBOUNCE_MS);
    }

    [first, last, birthday].forEach((input) => {
        input.addEventListener('input', onIdentityChange);
        input.addEventListener('change', onIdentityChange);
    });

    // Capture phase + stopImmediatePropagation so the form's own (offline-aware) submit handlers never see a blocked submit.
    scope.addEventListener('submit', (event) => {
        if (!isPending()) return;
        event.preventDefault();
        event.stopImmediatePropagation?.();
        activePanel().focus?.();
        activePanel().scrollIntoView?.({ block: 'center' });
    }, true);

    /**
     * Programmatic value changes (e.g. Spot Mapping filling the head fields for another plot) fire no input events:
     * drop any warning and tick, and treat the current values as the baseline for change detection.
     */
    function reset() {
        clear();
        lastKey = identityKey();
    }

    const api = {
        reset,
        get panel() { return activePanel(); },
        state,
        runCheck,
        handleServerDuplicate,
        isPending,
        isConfirmed,
        hasMatches,
        focusPanel: () => activePanel().focus?.(),
    };
    controllers.set(scope, api);

    return api;
}

export function initDuplicateCheck(doc = document, win = window) {
    doc.querySelectorAll('[data-lml-duplicate-check]').forEach((input) => {
        const scope = input.closest('[data-lml-dup-scope]') || input.form || input.closest('form');
        if (scope) attachDuplicateCheck(scope, win);
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initDuplicateCheck());
    } else {
        initDuplicateCheck();
    }
}

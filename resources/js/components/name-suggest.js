/**
 * Batch 4 — scoped name suggestions for staff name-entry fields.
 *
 * Opt-in: name inputs that belong together (first / middle / last) carry the same
 *   data-lml-name-suggest="<group>"  and  data-lml-name-suggest-url="<route('staff.name-suggestions')>".
 * Optional on any input of the group: data-lml-name-suggest-heading="…".
 *
 * While the user types, registered residents with a similar name are listed as "possible existing records".
 * Choosing one OPENS that record (new tab, so the form is kept) instead of filling the form: this avoids
 * creating a duplicate person, and manual entry of a genuinely new person is never blocked or changed.
 * The server decides who may see what (staff only, assigned zones, 8 results, name/zone/household only).
 *
 * Keyboard: ArrowDown/ArrowUp move, Enter opens the highlighted record (Enter without a highlight keeps the
 * form's normal behaviour), Escape closes. Mouse/touch: tap a suggestion. ARIA combobox + listbox pattern.
 */

export const MIN_QUERY_LENGTH = 2;
export const DEBOUNCE_MS = 250;

/** "  Juan ", "", "Dela  Cruz" → "Juan Dela Cruz" */
export function buildQuery(values) {
    return values
        .map((v) => String(v ?? '').trim())
        .filter((v) => v !== '')
        .join(' ')
        .replace(/\s+/g, ' ')
        .slice(0, 100);
}

export function shouldQuery(query) {
    return String(query ?? '').replace(/\s+/g, '').length >= MIN_QUERY_LENGTH;
}

/** Wrap-around keyboard movement; -1 means "no highlight". */
export function nextIndex(current, delta, length) {
    if (length <= 0) return -1;
    if (current < 0) return delta > 0 ? 0 : length - 1;

    return (current + delta + length) % length;
}

/** Secondary line, e.g. "Zone 2 · HH-0012". */
export function describeItem(item) {
    return [item?.zone, item?.household_no].filter((v) => typeof v === 'string' && v.trim() !== '').join(' · ');
}

/** Only same-origin http(s) links are followed. */
export function safeUrl(url, origin) {
    try {
        const parsed = new URL(String(url), origin);

        return parsed.origin === origin && /^https?:$/.test(parsed.protocol) ? parsed.toString() : null;
    } catch {
        return null;
    }
}

let listCounter = 0;

export function attachNameSuggest(form, group, win = window) {
    const doc = form.ownerDocument;
    const inputs = Array.from(form.querySelectorAll(`[data-lml-name-suggest="${group}"]`));
    if (inputs.length === 0 || inputs[0].dataset.lmlNameSuggestReady === '1') return null;
    inputs.forEach((input) => { input.dataset.lmlNameSuggestReady = '1'; });

    const endpoint = inputs.map((i) => i.dataset.lmlNameSuggestUrl).find(Boolean);
    if (!endpoint) return null;
    const heading = inputs.map((i) => i.dataset.lmlNameSuggestHeading).find(Boolean)
        || 'Possible existing records — open one to avoid a duplicate';

    listCounter += 1;
    const listId = `lml-name-suggest-${listCounter}`;
    const panel = doc.createElement('div');
    panel.className = 'lml-name-suggest';
    panel.hidden = true;
    const title = doc.createElement('p');
    title.className = 'lml-name-suggest__title';
    title.id = `${listId}-title`;
    title.textContent = heading;
    const list = doc.createElement('ul');
    list.className = 'lml-name-suggest__list';
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-labelledby', title.id);
    const more = doc.createElement('p');
    more.className = 'lml-name-suggest__more';
    more.textContent = 'More matches exist — type more of the name to narrow them.';
    more.hidden = true;
    panel.append(title, list, more);

    inputs.forEach((input) => {
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', listId);
        input.setAttribute('aria-expanded', 'false');
    });

    const state = { items: [], active: -1, owner: inputs[0], timer: null, controller: null, lastQuery: '' };

    /** The list is shown directly under the field being typed in (normal flow: no overlap on phones). */
    function placeAfter(input) {
        state.owner = input;
        if (panel.previousElementSibling !== input) {
            input.insertAdjacentElement('afterend', panel);
        }
    }

    function close() {
        panel.hidden = true;
        state.active = -1;
        inputs.forEach((i) => {
            i.setAttribute('aria-expanded', 'false');
            i.removeAttribute('aria-activedescendant');
        });
    }

    function highlight(index) {
        state.active = index;
        Array.from(list.children).forEach((li, i) => li.setAttribute('aria-selected', i === index ? 'true' : 'false'));
        if (index >= 0) {
            state.owner.setAttribute('aria-activedescendant', `${listId}-opt-${index}`);
            list.children[index]?.scrollIntoView?.({ block: 'nearest' });
        } else {
            state.owner.removeAttribute('aria-activedescendant');
        }
    }

    function open(index) {
        const item = state.items[index];
        const url = item ? safeUrl(item.url, win.location.origin) : null;
        if (!url) return;
        win.open(url, '_blank', 'noopener');
        close();
    }

    function render(payload) {
        state.items = Array.isArray(payload?.items) ? payload.items : [];
        list.replaceChildren();
        if (state.items.length === 0) {
            close();
            return;
        }
        state.items.forEach((item, index) => {
            const li = doc.createElement('li');
            li.id = `${listId}-opt-${index}`;
            li.className = 'lml-name-suggest__option';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            const name = doc.createElement('span');
            name.className = 'lml-name-suggest__name';
            name.textContent = String(item.name ?? '');
            const meta = doc.createElement('span');
            meta.className = 'lml-name-suggest__meta';
            meta.textContent = describeItem(item);
            li.append(name, meta);
            // mousedown (not click) so the input does not lose the list before the choice registers.
            li.addEventListener('mousedown', (event) => {
                event.preventDefault();
                open(index);
            });
            list.append(li);
        });
        more.hidden = !payload?.more;
        panel.hidden = false;
        state.owner.setAttribute('aria-expanded', 'true');
        highlight(-1);
    }

    async function fetchSuggestions(query) {
        state.controller?.abort();
        state.controller = typeof win.AbortController === 'function' ? new win.AbortController() : null;
        try {
            const url = new URL(endpoint, win.location.origin);
            url.searchParams.set('q', query);
            const response = await win.fetch(url.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: state.controller?.signal,
            });
            if (!response.ok) {
                close();
                return;
            }
            render(await response.json());
        } catch {
            close(); // offline, aborted or blocked: the form keeps working without suggestions
        }
    }

    function schedule() {
        const query = buildQuery(inputs.map((i) => i.value));
        win.clearTimeout(state.timer);
        if (!shouldQuery(query) || win.navigator?.onLine === false) {
            state.controller?.abort();
            state.lastQuery = '';
            list.replaceChildren();
            close();
            return;
        }
        if (query === state.lastQuery && !panel.hidden) return;
        state.timer = win.setTimeout(() => {
            state.lastQuery = query;
            fetchSuggestions(query);
        }, DEBOUNCE_MS);
    }

    inputs.forEach((input) => {
        input.addEventListener('input', () => {
            placeAfter(input);
            schedule();
        });
        input.addEventListener('focus', () => placeAfter(input));
        input.addEventListener('blur', () => win.setTimeout(close, 150));
        input.addEventListener('keydown', (event) => {
            if (panel.hidden || state.items.length === 0) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(nextIndex(state.active, event.key === 'ArrowDown' ? 1 : -1, state.items.length));
            } else if (event.key === 'Enter' && state.active >= 0) {
                event.preventDefault();
                open(state.active);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                close();
            }
        });
    });

    return { panel, list, close, render, schedule, state };
}

export function initNameSuggest(doc = document, win = window) {
    // attachNameSuggest marks its inputs, so each (form, group) pair is attached once.
    doc.querySelectorAll('[data-lml-name-suggest]').forEach((input) => {
        const form = input.form || input.closest('form') || doc.body;
        attachNameSuggest(form, input.dataset.lmlNameSuggest, win);
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initNameSuggest());
    } else {
        initNameSuggest();
    }
}

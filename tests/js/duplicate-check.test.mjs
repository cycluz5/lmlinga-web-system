import assert from 'node:assert/strict';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const mod = await import(pathToFileURL(path.resolve('resources/js/components/duplicate-check.js')).href);
const { buildDuplicateQuery, shouldCheck, canSubmit, safeMatchUrl, attachDuplicateCheck, getDuplicateCheck } = mod;

const MEMBER = {
    kind: 'member',
    firstName: ' Juan ',
    lastName: 'Dela Cruz',
    birthday: '2000-01-31',
    householdNo: 'HH-0001',
    excludeMember: '',
};

describe('pure helpers', () => {
    it('builds the member query with household and exclude_member only when set', () => {
        const q = new URLSearchParams(buildDuplicateQuery(MEMBER));
        assert.equal(q.get('kind'), 'member');
        assert.equal(q.get('first_name'), 'Juan');
        assert.equal(q.get('last_name'), 'Dela Cruz');
        assert.equal(q.get('birthday'), '2000-01-31');
        assert.equal(q.get('household_no'), 'HH-0001');
        assert.equal(q.has('exclude_member'), false);

        const edit = new URLSearchParams(buildDuplicateQuery({ ...MEMBER, excludeMember: 'MB-007' }));
        assert.equal(edit.get('exclude_member'), 'MB-007');
    });

    it('builds the head query without household fields', () => {
        const q = new URLSearchParams(buildDuplicateQuery({ ...MEMBER, kind: 'head', householdNo: 'HH-0009' }));
        assert.equal(q.get('kind'), 'head');
        assert.equal(q.has('household_no'), false);
        assert.equal(q.has('exclude_member'), false);
    });

    it('checks only when first, last and a full birthday are present', () => {
        assert.equal(shouldCheck(MEMBER), true);
        assert.equal(shouldCheck({ ...MEMBER, firstName: ' ' }), false);
        assert.equal(shouldCheck({ ...MEMBER, firstName: 'J' }), false);
        assert.equal(shouldCheck({ ...MEMBER, lastName: '' }), false);
        assert.equal(shouldCheck({ ...MEMBER, birthday: '2000-01' }), false);
        assert.equal(shouldCheck({ ...MEMBER, birthday: '' }), false);
        assert.equal(shouldCheck({ ...MEMBER, householdNo: '' }), false, 'member needs a household');
        assert.equal(shouldCheck({ ...MEMBER, kind: 'head', householdNo: '' }), true, 'head needs none');
    });

    it('blocks submit only while matches are shown and unconfirmed', () => {
        assert.equal(canSubmit({ hasMatches: false, confirmed: false }), true);
        assert.equal(canSubmit({ hasMatches: true, confirmed: false }), false);
        assert.equal(canSubmit({ hasMatches: true, confirmed: true }), true);
    });

    it('follows only same-origin http(s) links', () => {
        assert.equal(safeMatchUrl('/household-profiling/HH-1/members/MB-1', 'http://lml.test'), 'http://lml.test/household-profiling/HH-1/members/MB-1');
        assert.equal(safeMatchUrl('https://evil.example/x', 'http://lml.test'), null);
        assert.equal(safeMatchUrl('javascript:alert(1)', 'http://lml.test'), null);
        assert.equal(safeMatchUrl(null, 'http://lml.test'), null);
    });
});

// ---------------------------------------------------------------- fake DOM

function fakeEl(tag = 'div') {
    const listeners = {};
    const el = {
        tagName: tag.toUpperCase(),
        hidden: false,
        textContent: '',
        value: '',
        checked: false,
        type: '',
        name: '',
        href: '',
        className: '',
        dataset: {},
        attrs: {},
        children: [],
        removed: false,
        focused: false,
        setAttribute(k, v) { this.attrs[k] = String(v); },
        getAttribute(k) { return this.attrs[k] ?? null; },
        removeAttribute(k) { delete this.attrs[k]; },
        append(...nodes) { this.children.push(...nodes); },
        replaceChildren(...nodes) { this.children = [...nodes]; },
        remove() { this.removed = true; },
        focus() { this.focused = true; },
        insertAdjacentElement(where, node) { this.inserted = { where, node }; },
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        fire(type, extra = {}) {
            const event = {
                defaultPrevented: false,
                stopped: false,
                preventDefault() { this.defaultPrevented = true; },
                stopImmediatePropagation() { this.stopped = true; },
                ...extra,
            };
            (listeners[type] || []).forEach((fn) => fn(event));
            return event;
        },
        walk() { return this.children.flatMap((c) => [c, ...(c.walk ? c.walk() : [])]); },
    };
    return el;
}

function setup(items, { online = true, ok = true, kind = 'member', existingPanel = false, slot: withSlotEl = true, json } = {}) {
    const doc = { createElement: (t) => fakeEl(t) };
    const first = fakeEl('input');
    const last = fakeEl('input');
    const birthday = fakeEl('input');
    const parent = fakeEl('div');
    birthday.parentElement = parent;
    const slot = fakeEl('div');
    Object.assign(first.dataset, {
        lmlDuplicateCheck: kind,
        lmlDuplicateCheckUrl: '/staff/duplicate-check',
        lmlDupHouseholdNo: 'HH-0001',
        lmlDupExcludeMember: 'MB-007',
    });

    let serverPanel = null;
    let serverBox = null;
    if (existingPanel) {
        serverPanel = fakeEl('div');
        serverBox = fakeEl('input');
        serverBox.type = 'checkbox';
        serverBox.name = 'confirm_possible_duplicate';
        serverPanel.checkbox = serverBox;
    }

    const bySelector = {
        '[data-lml-duplicate-check]': first,
        '[data-lml-dup-first]': first,
        '[data-lml-dup-last]': last,
        '[data-lml-dup-birthday]': birthday,
        '[data-lml-dup-slot]': withSlotEl ? slot : null,
    };
    const scope = Object.assign(fakeEl('form'), {
        ownerDocument: doc,
        querySelector(sel) {
            if (sel === '[data-lml-possible-duplicate-panel]') return serverPanel && !serverPanel.removed ? serverPanel : null;
            return bySelector[sel] ?? null;
        },
    });
    if (serverPanel) {
        serverPanel.querySelector = (sel) => (sel.includes('confirm_possible_duplicate') ? serverBox : null);
    }

    const calls = { fetch: [], abort: 0 };
    const win = {
        location: { origin: 'http://lml.test' },
        navigator: { onLine: online },
        AbortController: class { constructor() { this.signal = {}; } abort() { calls.abort += 1; } },
        setTimeout: (fn) => { fn(); return 1; },
        clearTimeout() {},
        fetch: async (url, init) => {
            calls.fetch.push({ url, init });
            if (json === 'throw') return { ok, json: async () => { throw new Error('bad json'); } };
            return { ok, json: async () => json ?? { items } };
        },
    };
    const api = attachDuplicateCheck(scope, win);
    return { api, scope, first, last, birthday, parent, slot, serverPanel, serverBox, calls, win };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));
const fill = (t) => {
    t.first.value = 'Juan';
    t.last.value = 'Dela Cruz';
    t.birthday.value = '2000-01-31';
};
const typeAll = async (t) => {
    fill(t);
    t.birthday.fire('input');
    await flush();
};
const ITEMS = [
    { name: 'Dela Cruz, Juan A.', relation: 'Son', household_no: 'HH-0001', url: '/household-profiling/HH-0001/members/MB-001' },
    { name: 'Dela Cruz, Juan', relation: '', household_no: 'HH-0001', url: 'https://evil.example/x' },
];
const find = (root, pred) => root.walk().filter(pred);
const checkboxes = (panel) => find(panel, (n) => n.type === 'checkbox');

describe('live check (fake DOM)', () => {
    it('fetches once all three fields are filled, with the contract headers', async () => {
        const t = setup([]);
        t.first.value = 'Juan';
        t.first.fire('input');
        await flush();
        assert.equal(t.calls.fetch.length, 0, 'not yet complete');

        await typeAll(t);
        assert.equal(t.calls.fetch.length, 1);
        const { url, init } = t.calls.fetch[0];
        assert.match(url, /^http:\/\/lml\.test\/staff\/duplicate-check\?/);
        const q = new URL(url).searchParams;
        assert.equal(q.get('kind'), 'member');
        assert.equal(q.get('household_no'), 'HH-0001');
        assert.equal(q.get('exclude_member'), 'MB-007');
        assert.equal(init.credentials, 'same-origin');
        assert.equal(init.headers.Accept, 'application/json');
        assert.equal(init.headers['X-Requested-With'], 'XMLHttpRequest');
    });

    it('renders an alert panel with matches, a safe link and the member confirm checkbox', async () => {
        const t = setup(ITEMS);
        await typeAll(t);
        const panel = t.api.panel;
        assert.equal(panel.hidden, false);
        assert.equal(panel.getAttribute('role'), 'alert');
        assert.match(panel.className, /lml-possible-duplicate/);
        const links = find(panel, (n) => n.tagName === 'A');
        assert.equal(links.length, 1, 'cross-origin url gets no link');
        assert.equal(links[0].href, 'http://lml.test/household-profiling/HH-0001/members/MB-001');
        assert.equal(links[0].getAttribute('rel'), 'noopener');
        assert.equal(links[0].getAttribute('target'), '_blank');
        const text = panel.walk().map((n) => n.textContent).join(' ');
        assert.match(text, /Dela Cruz, Juan A\./);
        assert.match(text, /Son/);
        assert.match(text, /HH-0001/);
        assert.match(text, /This is a different person/);
        const boxes = checkboxes(panel);
        assert.equal(boxes.length, 1);
        assert.equal(boxes[0].name, 'confirm_possible_duplicate');
        assert.equal(boxes[0].value, '1');
        assert.equal(boxes[0].checked, false);
    });

    it('uses the household wording for head checks', async () => {
        const t = setup(ITEMS, { kind: 'head' });
        await typeAll(t);
        const text = t.api.panel.walk().map((n) => n.textContent).join(' ');
        assert.match(text, /This is a different household/);
        assert.equal(new URL(t.calls.fetch[0].url).searchParams.get('kind'), 'head');
    });

    it('places the panel in the slot when the form provides one, else after the birthday field', async () => {
        const withSlot = setup(ITEMS);
        await typeAll(withSlot);
        assert.equal(withSlot.slot.children.includes(withSlot.api.panel), true);

        const noSlot = setup(ITEMS, { slot: false });
        await typeAll(noSlot);
        assert.equal(noSlot.parent.inserted?.where, 'afterend');
    });

    it('blocks submit until the checkbox is ticked, then allows it', async () => {
        const t = setup(ITEMS);
        await typeAll(t);
        const blocked = t.scope.fire('submit');
        assert.equal(blocked.defaultPrevented, true);
        assert.equal(blocked.stopped, true);
        assert.equal(t.api.panel.focused, true);

        checkboxes(t.api.panel)[0].checked = true;
        const allowed = t.scope.fire('submit');
        assert.equal(allowed.defaultPrevented, false);
    });

    it('with no matches: no checkbox exists and submit is never touched', async () => {
        const t = setup([]);
        await typeAll(t);
        assert.equal(t.api.panel.hidden, true);
        assert.equal(checkboxes(t.api.panel).length, 0);
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
    });

    it('clears the panel and confirm checkbox when an identity field changes', async () => {
        const t = setup(ITEMS);
        await typeAll(t);
        checkboxes(t.api.panel)[0].checked = true;
        t.last.value = 'Santos';
        t.last.fire('input');
        assert.equal(t.api.panel.hidden, true);
        assert.equal(checkboxes(t.api.panel).length, 0);
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
    });

    it('aborts the stale request when the user keeps typing', async () => {
        const t = setup([]);
        await typeAll(t);
        t.birthday.value = '2000-02-01';
        t.birthday.fire('input');
        await flush();
        assert.equal(t.calls.fetch.length, 2);
        assert.ok(t.calls.abort >= 1);
    });

    it('ignores a stale response that arrives after the fields changed', async () => {
        const t = setup(ITEMS);
        let release;
        t.win.fetch = (url, init) => new Promise((resolve) => {
            release = () => resolve({ ok: true, json: async () => ({ items: ITEMS }) });
        });
        fill(t);
        t.birthday.fire('input');
        t.last.value = '';
        t.last.fire('input');
        release();
        await flush();
        assert.equal(t.api.panel.hidden, true);
    });
});

describe('fail soft', () => {
    it('does nothing offline', async () => {
        const t = setup(ITEMS, { online: false });
        await typeAll(t);
        assert.equal(t.calls.fetch.length, 0);
        assert.equal(t.api.panel.hidden, true);
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
    });

    it('shows no panel on a non-OK response', async () => {
        const t = setup(ITEMS, { ok: false });
        await typeAll(t);
        assert.equal(t.api.panel.hidden, true);
    });

    it('shows no panel and does not throw on malformed JSON or network failure', async () => {
        const bad = setup(ITEMS, { json: 'throw' });
        await typeAll(bad);
        assert.equal(bad.api.panel.hidden, true);

        const down = setup(ITEMS);
        down.win.fetch = async () => { throw new TypeError('Failed to fetch'); };
        await typeAll(down);
        assert.equal(down.api.panel.hidden, true);

        const junk = setup(ITEMS, { json: { items: 'nope' } });
        await typeAll(junk);
        assert.equal(junk.api.panel.hidden, true);
    });
});

describe('server-rendered panel reuse', () => {
    it('reuses the existing panel and checkbox: no second panel or checkbox', async () => {
        const t = setup(ITEMS, { existingPanel: true });
        assert.equal(t.api.panel, t.serverPanel);
        fill(t);
        await t.api.runCheck();
        assert.equal(t.calls.fetch.length, 0, 'server panel is already authoritative until a field changes');
        assert.equal(checkboxes(t.api.panel).length, 0, 'no checkbox added next to the server one');
        assert.equal(t.slot.children.length, 0);
    });

    it('guards submit with the server checkbox', () => {
        const t = setup([], { existingPanel: true });
        assert.equal(t.scope.fire('submit').defaultPrevented, true);
        assert.equal(t.serverPanel.focused, true);
        t.serverBox.checked = true;
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
    });

    it('drops the stale server panel when an identity field changes', async () => {
        const t = setup([], { existingPanel: true });
        t.serverBox.checked = true;
        t.first.value = 'Juana';
        t.first.fire('input');
        assert.equal(t.serverPanel.removed, true);
        assert.equal(t.serverBox.checked, false);
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
    });
});

describe('programmatic API (Spot Mapping)', () => {
    it('runCheck shows the panel and isPending/confirmed reflect the checkbox', async () => {
        const t = setup(ITEMS, { kind: 'head' });
        fill(t);
        assert.equal(t.api.isPending(), false);
        await t.api.runCheck();
        assert.equal(t.api.isPending(), true);
        assert.equal(t.api.isConfirmed(), false);
        checkboxes(t.api.panel)[0].checked = true;
        assert.equal(t.api.isPending(), false);
        assert.equal(t.api.isConfirmed(), true);
    });

    it('getDuplicateCheck returns the attached controller for a scope', () => {
        const t = setup([]);
        assert.equal(getDuplicateCheck(t.scope), t.api);
        assert.equal(getDuplicateCheck(null), null);
    });
});

const MSG_422 = 'Possible duplicate: this head is already registered in household HH-0009. Confirm it is a different household to continue.';
const BODY_422 = { message: MSG_422, errors: { possible_duplicate: [MSG_422] } };

describe('Spot Mapping 422 possible_duplicate flow', () => {
    it('422 -> live check -> panel -> confirm -> resubmit is allowed', async () => {
        const t = setup(ITEMS, { kind: 'head' });
        fill(t);
        assert.equal(await t.api.handleServerDuplicate(BODY_422), true);
        assert.equal(t.api.panel.hidden, false);
        assert.equal(checkboxes(t.api.panel).length, 1);
        assert.equal(t.api.isPending(), true, 'resubmit is held until confirmed');
        checkboxes(t.api.panel)[0].checked = true;
        assert.equal(t.api.isPending(), false);
        assert.equal(t.api.isConfirmed(), true);
    });

    for (const [label, opts] of [
        ['the live check returns no matches', { json: { items: [] } }],
        ['the live check fails', { ok: false }],
        ['the browser is offline', { online: false }],
    ]) {
        it(`still builds a confirmable panel from the server message when ${label}`, async () => {
            const t = setup([], { kind: 'head', ...opts });
            fill(t);
            assert.equal(await t.api.handleServerDuplicate(BODY_422), true);
            const text = t.api.panel.walk().map((n) => n.textContent).join(' ');
            assert.equal(t.api.panel.hidden, false);
            assert.match(text, /already registered in household HH-0009/);
            assert.match(text, /This is a different household/);
            assert.equal(checkboxes(t.api.panel).length, 1);
            assert.equal(t.api.isPending(), true);
            checkboxes(t.api.panel)[0].checked = true;
            assert.equal(t.api.isConfirmed(), true);
            assert.equal(t.api.isPending(), false);
        });
    }

    it('ignores a 422 without possible_duplicate so other errors keep the overlay', async () => {
        const t = setup([], { kind: 'head' });
        fill(t);
        assert.equal(await t.api.handleServerDuplicate({ message: 'x', errors: { zone: ['bad'] } }), false);
        assert.equal(await t.api.handleServerDuplicate(null), false);
        assert.equal(t.api.panel.hidden, true);
    });

    it('does not duplicate the checkbox when a panel is already pending', async () => {
        const t = setup(ITEMS, { kind: 'head' });
        await typeAll(t);
        assert.equal(await t.api.handleServerDuplicate(BODY_422), true);
        assert.equal(checkboxes(t.api.panel).length, 1);
    });
});

describe('change event without a value change', () => {
    it('keeps the panel and the tick', async () => {
        const t = setup(ITEMS);
        await typeAll(t);
        checkboxes(t.api.panel)[0].checked = true;
        t.last.fire('change');
        t.birthday.fire('change');
        assert.equal(t.calls.fetch.length, 1);
        assert.equal(checkboxes(t.api.panel).length, 1);
        assert.equal(checkboxes(t.api.panel)[0].checked, true);
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
    });
});

describe('reset()', () => {
    it('drops the warning and tick without an input event, and does not refetch', async () => {
        const t = setup(ITEMS);
        await typeAll(t);
        checkboxes(t.api.panel)[0].checked = true;
        assert.equal(t.api.hasMatches(), true);

        t.first.value = 'Maria'; // programmatic change: no event fired
        t.api.reset();

        assert.equal(t.api.panel.hidden, true);
        assert.equal(t.api.hasMatches(), false);
        assert.equal(t.api.isPending(), false);
        assert.equal(t.scope.fire('submit').defaultPrevented, false);
        assert.equal(t.calls.fetch.length, 1);

        // baseline moved: a change event with the same values is a no-op, a real edit still checks
        t.last.fire('change');
        assert.equal(t.calls.fetch.length, 1);
        t.last.value = 'Santos';
        t.last.fire('input');
        await flush();
        assert.equal(t.calls.fetch.length, 2);
    });

    it('also removes a server-rendered panel', () => {
        const t = setup([], { existingPanel: true });
        assert.equal(t.api.hasMatches(), true);
        t.api.reset();
        assert.equal(t.api.hasMatches(), false);
    });
});

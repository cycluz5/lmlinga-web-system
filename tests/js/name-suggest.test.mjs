import assert from 'node:assert/strict';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const mod = await import(pathToFileURL(path.resolve('resources/js/components/name-suggest.js')).href);
const { buildQuery, shouldQuery, nextIndex, describeItem, safeUrl, attachNameSuggest } = mod;

describe('pure helpers', () => {
    it('builds one query from the name fields', () => {
        assert.equal(buildQuery(['  Juan ', '', 'Dela  Cruz']), 'Juan Dela Cruz');
        assert.equal(buildQuery(['', ' ', null]), '');
    });

    it('needs at least two non-space characters', () => {
        assert.equal(shouldQuery('J'), false);
        assert.equal(shouldQuery(' J '), false);
        assert.equal(shouldQuery('Ju'), true);
        assert.equal(shouldQuery('J u'), true);
    });

    it('moves the highlight with wrap-around', () => {
        assert.equal(nextIndex(-1, 1, 3), 0);
        assert.equal(nextIndex(-1, -1, 3), 2);
        assert.equal(nextIndex(2, 1, 3), 0);
        assert.equal(nextIndex(0, -1, 3), 2);
        assert.equal(nextIndex(0, 1, 0), -1);
    });

    it('describes a suggestion with zone and household only', () => {
        assert.equal(describeItem({ zone: 'Zone 2', household_no: 'HH-0012' }), 'Zone 2 · HH-0012');
        assert.equal(describeItem({ zone: '', household_no: 'HH-0012' }), 'HH-0012');
    });

    it('follows only same-origin links', () => {
        assert.equal(safeUrl('/household-profiling/HH-1/members/MB-001', 'http://lml.test'), 'http://lml.test/household-profiling/HH-1/members/MB-001');
        assert.equal(safeUrl('https://evil.example/x', 'http://lml.test'), null);
        assert.equal(safeUrl('javascript:alert(1)', 'http://lml.test'), null);
    });
});

// ---------------------------------------------------------------- fake DOM

function fakeEl(tag = 'div') {
    const listeners = {};
    return {
        tagName: tag.toUpperCase(),
        hidden: false,
        textContent: '',
        value: '',
        dataset: {},
        attrs: {},
        children: [],
        id: '',
        className: '',
        setAttribute(k, v) { this.attrs[k] = String(v); },
        getAttribute(k) { return this.attrs[k] ?? null; },
        removeAttribute(k) { delete this.attrs[k]; },
        append(...nodes) { this.children.push(...nodes); },
        replaceChildren(...nodes) { this.children = [...nodes]; },
        insertAdjacentElement() {},
        scrollIntoView() {},
        addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
        fire(type, extra = {}) {
            const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
            (listeners[type] || []).forEach((fn) => fn(event));
            return event;
        },
    };
}

function setup(items, { online = true, ok = true } = {}) {
    const doc = { createElement: (t) => fakeEl(t) };
    const inputs = ['first', 'middle', 'last'].map(() => Object.assign(fakeEl('input'), {
        ownerDocument: doc,
        dataset: { lmlNameSuggest: 'person', lmlNameSuggestUrl: '/staff/name-suggestions' },
    }));
    const form = { ownerDocument: doc, querySelectorAll: () => inputs };
    const calls = { fetch: [], open: [] };
    const win = {
        location: { origin: 'http://lml.test' },
        navigator: { onLine: online },
        AbortController: class { constructor() { this.signal = {}; } abort() { this.aborted = true; } },
        setTimeout: (fn) => { fn(); return 1; },
        clearTimeout() {},
        fetch: async (url) => {
            calls.fetch.push(url);
            return { ok, json: async () => ({ items, more: false }) };
        },
        open: (url, target, features) => calls.open.push({ url, target, features }),
    };
    const api = attachNameSuggest(form, 'person', win);
    return { api, inputs, calls };
}
const flush = () => new Promise((resolve) => setImmediate(resolve));
const ITEMS = [
    { name: 'Delacruz, Juan', zone: 'Zone 1', household_no: 'HH-0001', url: '/household-profiling/HH-0001/members/MB-001' },
    { name: 'Delacruz, Juan', zone: 'Zone 1', household_no: 'HH-0002', url: '/household-profiling/HH-0002/members/MB-002' },
];

describe('attachNameSuggest (fake DOM)', () => {
    it('lists matches with combobox semantics after typing two characters', async () => {
        const { api, inputs, calls } = setup(ITEMS);
        inputs[0].value = 'Ju';
        inputs[0].fire('input');
        await flush();
        assert.equal(calls.fetch.length, 1);
        assert.match(calls.fetch[0], /q=Ju/);
        assert.equal(api.panel.hidden, false);
        assert.equal(api.list.children.length, 2);
        assert.equal(api.list.children[0].getAttribute('role'), 'option');
        assert.equal(inputs[0].getAttribute('role'), 'combobox');
        assert.equal(inputs[0].getAttribute('aria-expanded'), 'true');
    });

    it('ArrowDown + Enter opens the highlighted record in a new tab and keeps the form', async () => {
        const { inputs, calls } = setup(ITEMS);
        inputs[2].value = 'Delacruz';
        inputs[2].fire('input');
        await flush();
        inputs[2].fire('keydown', { key: 'ArrowDown' });
        inputs[2].fire('keydown', { key: 'ArrowDown' });
        assert.equal(inputs[2].getAttribute('aria-activedescendant').endsWith('-opt-1'), true);
        const enter = inputs[2].fire('keydown', { key: 'Enter' });
        assert.equal(enter.defaultPrevented, true);
        assert.deepEqual(calls.open, [{ url: 'http://lml.test/household-profiling/HH-0002/members/MB-002', target: '_blank', features: 'noopener' }]);
        assert.equal(inputs[2].value, 'Delacruz', 'typed name is not replaced');
    });

    it('Enter with no highlighted suggestion keeps the normal form behaviour', async () => {
        const { inputs, calls } = setup(ITEMS);
        inputs[0].value = 'Juan';
        inputs[0].fire('input');
        await flush();
        const enter = inputs[0].fire('keydown', { key: 'Enter' });
        assert.equal(enter.defaultPrevented, false);
        assert.equal(calls.open.length, 0);
    });

    it('mouse/touch selection opens the record', async () => {
        const { api, inputs, calls } = setup(ITEMS);
        inputs[0].value = 'Juan';
        inputs[0].fire('input');
        await flush();
        api.list.children[0].fire('mousedown');
        assert.equal(calls.open[0].url, 'http://lml.test/household-profiling/HH-0001/members/MB-001');
    });

    it('Escape closes the list', async () => {
        const { api, inputs } = setup(ITEMS);
        inputs[0].value = 'Juan';
        inputs[0].fire('input');
        await flush();
        inputs[0].fire('keydown', { key: 'Escape' });
        assert.equal(api.panel.hidden, true);
        assert.equal(inputs[0].getAttribute('aria-expanded'), 'false');
    });

    it('clearing the input closes the list without asking the server again', async () => {
        const { api, inputs, calls } = setup(ITEMS);
        inputs[0].value = 'Juan';
        inputs[0].fire('input');
        await flush();
        inputs[0].value = '';
        inputs[0].fire('input');
        await flush();
        assert.equal(calls.fetch.length, 1);
        assert.equal(api.panel.hidden, true);
        assert.equal(api.list.children.length, 0);
    });

    it('no matches keeps the list closed', async () => {
        const { api, inputs } = setup([]);
        inputs[0].value = 'Zzyzx';
        inputs[0].fire('input');
        await flush();
        assert.equal(api.panel.hidden, true);
    });

    it('does nothing offline and closes on server refusal', async () => {
        const offline = setup(ITEMS, { online: false });
        offline.inputs[0].value = 'Juan';
        offline.inputs[0].fire('input');
        await flush();
        assert.equal(offline.calls.fetch.length, 0);

        const refused = setup(ITEMS, { ok: false });
        refused.inputs[0].value = 'Juan';
        refused.inputs[0].fire('input');
        await flush();
        assert.equal(refused.api.panel.hidden, true);
    });

    it('ignores suggestions whose link is not same-origin', async () => {
        const { api, inputs, calls } = setup([{ name: 'X, Y', zone: 'Zone 1', household_no: 'HH-9', url: 'https://evil.example/' }]);
        inputs[0].value = 'Xy';
        inputs[0].fire('input');
        await flush();
        api.list.children[0].fire('mousedown');
        assert.equal(calls.open.length, 0);
    });
});

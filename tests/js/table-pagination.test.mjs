import assert from 'node:assert/strict';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const {
    DEFAULT_PAGE_SIZE,
    OFF_PAGE_CLASS,
    computePage,
    pageWindow,
    readPageParam,
    withPageParam,
    applyPage,
    visibleSignature,
} = await import(pathToFileURL(path.resolve('resources/js/components/table-pagination.js')).href);

/** Minimal row stand-in: `hidden` = filtered out by the page's own filter; classList tracks paging. */
function makeRows(n, hiddenIndexes = []) {
    return Array.from({ length: n }, (_, i) => {
        const classes = new Set();
        return {
            id: i + 1,
            hidden: hiddenIndexes.includes(i),
            classList: {
                toggle(name, force) {
                    if (force) classes.add(name);
                    else classes.delete(name);
                },
                contains: (name) => classes.has(name),
            },
        };
    });
}
const shownIds = (rows) => rows.filter((r) => !r.hidden && !r.classList.contains(OFF_PAGE_CLASS)).map((r) => r.id);

describe('computePage', () => {
    it('first, middle and last pages of 25 rows (size 10)', () => {
        assert.deepEqual(computePage(25, 1, 10), { page: 1, pageCount: 3, start: 0, end: 10, from: 1, to: 10, total: 25 });
        assert.deepEqual(computePage(25, 2, 10), { page: 2, pageCount: 3, start: 10, end: 20, from: 11, to: 20, total: 25 });
        assert.deepEqual(computePage(25, 3, 10), { page: 3, pageCount: 3, start: 20, end: 25, from: 21, to: 25, total: 25 });
    });

    it('clamps out-of-range and invalid pages', () => {
        assert.equal(computePage(25, 99, 10).page, 3);
        assert.equal(computePage(25, 0, 10).page, 1);
        assert.equal(computePage(25, Number.NaN, 10).page, 1);
        assert.equal(computePage(25, 1, 0).pageCount, Math.ceil(25 / DEFAULT_PAGE_SIZE));
    });

    it('empty dataset is a single empty page', () => {
        assert.deepEqual(computePage(0, 3, 10), { page: 1, pageCount: 1, start: 0, end: 0, from: 0, to: 0, total: 0 });
    });
});

describe('applyPage', () => {
    it('shows only the requested page and never counts filtered-out rows', () => {
        const rows = makeRows(30, [0, 1, 2, 3, 4]); // a filter hid 5 rows → 25 visible
        const info = applyPage(rows, 3, 10);
        assert.equal(info.total, 25);
        assert.deepEqual(shownIds(rows), [26, 27, 28, 29, 30]);
        assert.ok(rows.slice(0, 5).every((r) => !r.classList.contains(OFF_PAGE_CLASS)), 'filtered rows are left to the filter');
    });

    it('a filter that leaves fewer rows clamps to the last available page', () => {
        const rows = makeRows(30);
        applyPage(rows, 3, 10);
        rows.forEach((r, i) => { r.hidden = i >= 12; }); // filter → 12 visible
        const info = applyPage(rows, 3, 10);
        assert.equal(info.page, 2);
        assert.deepEqual(shownIds(rows), [11, 12]);
    });

    it('a filter with no matches shows nothing and reports zero', () => {
        const rows = makeRows(8, [0, 1, 2, 3, 4, 5, 6, 7]);
        const info = applyPage(rows, 1, 10);
        assert.equal(info.total, 0);
        assert.deepEqual(shownIds(rows), []);
    });

    it('after deleting rows on the last page, the page is clamped', () => {
        let rows = makeRows(21);
        assert.equal(applyPage(rows, 3, 10).page, 3);
        rows = rows.slice(0, 20); // one record deleted
        const info = applyPage(rows, 3, 10);
        assert.equal(info.page, 2);
        assert.deepEqual(shownIds(rows), [11, 12, 13, 14, 15, 16, 17, 18, 19, 20]);
    });

    it('after creating a record, it appears on the page it belongs to', () => {
        const rows = makeRows(20);
        rows.push(...makeRows(1).map((r) => ({ ...r, id: 21 })));
        const info = applyPage(rows, 3, 10);
        assert.equal(info.pageCount, 3);
        assert.deepEqual(shownIds(rows), [21]);
    });

    it('a dataset that fits on one page shows every row', () => {
        const rows = makeRows(7);
        applyPage(rows, 1, 10);
        assert.deepEqual(shownIds(rows), [1, 2, 3, 4, 5, 6, 7]);
    });
});

describe('visibleSignature', () => {
    it('changes only when the filter result changes', () => {
        const rows = makeRows(4);
        const before = visibleSignature(rows);
        applyPage(rows, 2, 2); // paging alone must not look like a filter change
        assert.equal(visibleSignature(rows), before);
        rows[1].hidden = true;
        assert.notEqual(visibleSignature(rows), before);
    });
});

describe('pageWindow', () => {
    it('lists every page when there are few', () => {
        assert.deepEqual(pageWindow(2, 5), [1, 2, 3, 4, 5]);
    });

    it('compacts long ranges around the current page', () => {
        assert.deepEqual(pageWindow(1, 20), [1, 2, 3, 4, 5, 6, '…', 20]);
        assert.deepEqual(pageWindow(10, 20), [1, '…', 8, 9, 10, 11, 12, '…', 20]);
        assert.deepEqual(pageWindow(20, 20), [1, '…', 15, 16, 17, 18, 19, 20]);
    });
});

// ---------------------------------------------------------------- DOM wiring with a tiny fake DOM

const { attachPagination, rowSelector } = await import(pathToFileURL(path.resolve('resources/js/components/table-pagination.js')).href);

describe('rowSelector', () => {
    it('builds the row selector from the bare attribute name', () => {
        assert.equal(rowSelector('hh-row'), '[data-hh-row]');
        assert.equal(rowSelector('hw-card'), '[data-hw-card]');
        assert.equal(rowSelector('[data-row]'), '[data-row]');
        assert.equal(rowSelector(''), ':scope > tr');
        assert.equal(rowSelector('bad value!'), ':scope > tr');
    });
});

function fakeEl(tag = 'div') {
    const classes = new Set();
    const listeners = {};
    const el = {
        tagName: tag.toUpperCase(),
        hidden: false,
        disabled: false,
        textContent: '',
        dataset: {},
        attrs: {},
        children: [],
        className: '',
        classList: {
            toggle(name, force) { if (force) classes.add(name); else classes.delete(name); },
            contains: (name) => classes.has(name),
        },
        setAttribute(k, v) { this.attrs[k] = String(v); },
        getAttribute(k) { return this.attrs[k] ?? null; },
        append(...nodes) { this.children.push(...nodes); },
        replaceChildren(...nodes) { this.children = [...nodes]; },
        addEventListener(type, fn) { listeners[type] = fn; },
        click() { if (!this.disabled && listeners.click) listeners.click(); },
    };
    return el;
}

function fakeTable(rowCount, search = '') {
    const doc = { createElement: (t) => fakeEl(t) };
    const rows = Array.from({ length: rowCount }, (_, i) => Object.assign(fakeEl('tr'), { id: i + 1 }));
    const inserted = [];
    const table = Object.assign(fakeEl('table'), {
        parentElement: { children: [1, 2] },
        insertAdjacentElement: (_pos, node) => inserted.push(node),
    });
    const tbody = Object.assign(fakeEl('tbody'), {
        ownerDocument: doc,
        dataset: { lmlPaginate: '[data-row]', lmlPaginateSize: '10', lmlPaginateLabel: 'records' },
        closest: (sel) => (sel === 'table' ? table : null),
        querySelectorAll: () => rows,
    });
    let observerCb = null;
    const win = {
        location: { search, href: `http://x.test/list${search}` },
        history: { state: null, replaceState: (_s, _t, url) => { win.location.href = url; win.location.search = new URL(url).search; } },
        MutationObserver: class { constructor(cb) { observerCb = cb; } observe() {} disconnect() {} },
        queueMicrotask: (fn) => fn(),
    };
    const api = attachPagination(tbody, win);
    return { api, rows, nav: inserted[0], win, fireFilterChange: () => observerCb([{ type: 'attributes' }]) };
}
const visibleIds = (rows) => rows.filter((r) => !r.hidden && !r.classList.contains(OFF_PAGE_CLASS)).map((r) => r.id);
const pageButtons = (nav) => nav.children[1].children.filter((c) => /^Page /.test(c.getAttribute('aria-label') ?? ''));

describe('attachPagination (fake DOM)', () => {
    it('inserts a nav after the table, shows page 1 and a summary', () => {
        const { nav, rows } = fakeTable(25);
        assert.equal(nav.hidden, false);
        assert.equal(nav.children[0].textContent, 'Showing 1–10 of 25 records');
        assert.deepEqual(visibleIds(rows), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        assert.equal(pageButtons(nav).length, 3);
    });

    it('clicking page 3 shows the last rows and records ?page=3 in the URL', () => {
        const { nav, rows, win } = fakeTable(25, '?zone=2');
        pageButtons(nav)[2].click();
        assert.deepEqual(visibleIds(rows), [21, 22, 23, 24, 25]);
        assert.equal(new URL(win.location.href).searchParams.get('page'), '3');
        assert.equal(new URL(win.location.href).searchParams.get('zone'), '2');
    });

    it('opens on the page stored in the URL', () => {
        const { rows } = fakeTable(25, '?page=2');
        assert.deepEqual(visibleIds(rows), [11, 12, 13, 14, 15, 16, 17, 18, 19, 20]);
    });

    it('a filter change returns to page 1 and recounts', () => {
        const { nav, rows, fireFilterChange } = fakeTable(25, '?page=3');
        rows.forEach((r, i) => { r.hidden = i % 2 === 1; }); // filter keeps odd ids → 13 rows
        fireFilterChange();
        assert.equal(nav.children[0].textContent, 'Showing 1–10 of 13 records');
        assert.deepEqual(visibleIds(rows), [1, 3, 5, 7, 9, 11, 13, 15, 17, 19]);
    });

    it('hides the nav when everything fits on one page or the dataset is empty', () => {
        assert.equal(fakeTable(10).nav.hidden, true);
        const empty = fakeTable(0);
        assert.equal(empty.nav.hidden, true);
        assert.equal(empty.nav.children[0].textContent, '');
    });
});

describe('URL page parameter', () => {
    it('reads the page, defaulting to 1', () => {
        assert.equal(readPageParam('?zone=2&page=3', 'page'), 3);
        assert.equal(readPageParam('?page=abc', 'page'), 1);
        assert.equal(readPageParam('', 'page'), 1);
        assert.equal(readPageParam('?workers_page=4&page=2', 'workers_page'), 4);
    });

    it('keeps every other query parameter and drops the param on page 1', () => {
        const next = withPageParam('http://x.test/household-requests?zone=2&status=pending', 'page', 3);
        const params = new URL(next).searchParams;
        assert.equal(params.get('zone'), '2');
        assert.equal(params.get('status'), 'pending');
        assert.equal(params.get('page'), '3');
        assert.equal(new URL(withPageParam(next, 'page', 1)).searchParams.has('page'), false);
    });
});

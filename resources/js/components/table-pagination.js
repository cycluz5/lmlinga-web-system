/**
 * Batch 3 — shared listing pagination (panel refinement: table pagination).
 *
 * Opt-in: put `data-lml-paginate="<row attribute without data->"` on a <tbody> (or a card grid), e.g.
 * data-lml-paginate="hh-row" paginates the `[data-hh-row]` rows. (A value starting with "[" is used as a raw
 * selector.) The bare name is used on purpose so the literal row attribute never appears in the markup unless
 * real rows exist — existing tests count rows with substr_count('data-hh-row'). Optional:
 *   data-lml-paginate-size="10"      rows per page
 *   data-lml-paginate-key="page"     URL query parameter that remembers the page (other params are kept)
 *   data-lml-paginate-label="records" noun used in "Showing 1–10 of 42 records"
 *
 * Works WITH the existing client-side filters instead of replacing them: every listing script already marks
 * filtered-out rows with the `hidden` attribute. Pagination only considers rows that are not `hidden`, and hides
 * off-page rows with its own class (`lml-paginate-off`), so filter state, "N records" counts, empty states,
 * exports and offline hydration that read `row.hidden` are unaffected. A MutationObserver re-paginates when a
 * filter changes `hidden` (back to page 1) or when rows are added/removed (page clamped), e.g. after offline
 * hydration, record creation or deletion.
 *
 * Client-side by design: these listings already render the role/zone-scoped rows the user may see, filter them in
 * the browser and are cached for offline use; paging does not change which records are sent or authorized.
 */

export const DEFAULT_PAGE_SIZE = 10;
export const OFF_PAGE_CLASS = 'lml-paginate-off';

/**
 * @returns {{ page: number, pageCount: number, start: number, end: number, from: number, to: number, total: number }}
 *   start/end are 0-based slice bounds into the visible rows; from/to are 1-based for display (0 when empty).
 */
export function computePage(total, requestedPage, size = DEFAULT_PAGE_SIZE) {
    const safeSize = Number.isFinite(size) && size > 0 ? Math.floor(size) : DEFAULT_PAGE_SIZE;
    const safeTotal = Number.isFinite(total) && total > 0 ? Math.floor(total) : 0;
    const pageCount = Math.max(1, Math.ceil(safeTotal / safeSize));
    const requested = Number.isFinite(requestedPage) ? Math.floor(requestedPage) : 1;
    const page = Math.min(Math.max(1, requested), pageCount);
    const start = (page - 1) * safeSize;
    const end = Math.min(start + safeSize, safeTotal);

    return {
        page,
        pageCount,
        start,
        end,
        from: safeTotal === 0 ? 0 : start + 1,
        to: end,
        total: safeTotal,
    };
}

/** Compact page list, e.g. [1, '…', 4, 5, 6, '…', 12]. */
export function pageWindow(page, pageCount, maxNumbers = 5) {
    if (pageCount <= maxNumbers + 2) {
        return Array.from({ length: pageCount }, (_, i) => i + 1);
    }
    const half = Math.floor(maxNumbers / 2);
    let first = Math.max(2, page - half);
    let last = Math.min(pageCount - 1, page + half);
    if (page - half < 2) {
        last = Math.min(pageCount - 1, last + (2 - (page - half)));
    }
    if (page + half > pageCount - 1) {
        first = Math.max(2, first - (page + half - (pageCount - 1)));
    }
    const out = [1];
    if (first > 2) out.push('…');
    for (let n = first; n <= last; n += 1) out.push(n);
    if (last < pageCount - 1) out.push('…');
    out.push(pageCount);

    return out;
}

/** Page number from a query string; 1 when absent or invalid. */
export function readPageParam(search, key = 'page') {
    const value = new URLSearchParams(search || '').get(key);
    const n = Number.parseInt(value ?? '', 10);

    return Number.isFinite(n) && n > 0 ? n : 1;
}

/** URL with the page parameter set (removed for page 1); every other parameter is preserved. */
export function withPageParam(href, key, page) {
    const url = new URL(href);
    if (page > 1) {
        url.searchParams.set(key, String(page));
    } else {
        url.searchParams.delete(key);
    }

    return url.toString();
}

/**
 * Apply a page to row-like objects ({ hidden, classList.toggle }). Filtered-out rows (hidden) are skipped and
 * never counted. Returns the computed page info.
 */
export function applyPage(rows, requestedPage, size = DEFAULT_PAGE_SIZE) {
    const visible = rows.filter((row) => !row.hidden);
    const info = computePage(visible.length, requestedPage, size);
    visible.forEach((row, index) => {
        row.classList.toggle(OFF_PAGE_CLASS, index < info.start || index >= info.end);
    });
    rows.forEach((row) => {
        if (row.hidden) row.classList.toggle(OFF_PAGE_CLASS, false);
    });

    return info;
}

/** Identity of the current filter result, used to detect "filter changed" (→ back to page 1). */
export function visibleSignature(rows) {
    let sig = '';
    rows.forEach((row, index) => {
        if (!row.hidden) sig += `${index},`;
    });

    return sig;
}

/** "hh-row" → "[data-hh-row]"; "[...]" is used as-is; empty → direct child rows. */
export function rowSelector(value) {
    const v = String(value ?? '').trim();
    if (v === '') return ':scope > tr';
    if (v.startsWith('[')) return v;

    return /^[a-z0-9-]+$/i.test(v) ? `[data-${v}]` : ':scope > tr';
}

function resolveAnchor(container) {
    const explicit = container.closest('[data-lml-paginate-anchor]');
    if (explicit) return explicit;
    const table = container.tagName === 'TBODY' ? container.closest('table') : null;
    if (!table) return container;
    const parent = table.parentElement;

    return parent && parent.children.length === 1 ? parent : table;
}

function button(doc, label, ariaLabel, onClick, { disabled = false, current = false } = {}) {
    const el = doc.createElement('button');
    el.type = 'button';
    el.className = `lml-pagination__btn lml-focus-ring${current ? ' lml-pagination__btn--current' : ''}`;
    el.textContent = label;
    el.setAttribute('aria-label', ariaLabel);
    if (current) el.setAttribute('aria-current', 'page');
    el.disabled = disabled;
    el.addEventListener('click', onClick);

    return el;
}

export function attachPagination(container, win = window) {
    if (container.dataset.lmlPaginateReady === '1') return null;
    container.dataset.lmlPaginateReady = '1';

    const doc = container.ownerDocument;
    const selector = rowSelector(container.dataset.lmlPaginate);
    const size = Number.parseInt(container.dataset.lmlPaginateSize || '', 10) || DEFAULT_PAGE_SIZE;
    const key = container.dataset.lmlPaginateKey || 'page';
    const label = container.dataset.lmlPaginateLabel || 'records';

    const nav = doc.createElement('nav');
    nav.className = 'lml-pagination';
    nav.setAttribute('aria-label', `${label} pagination`);
    nav.dataset.lmlPagination = key;
    nav.hidden = true;
    const summary = doc.createElement('p');
    summary.className = 'lml-pagination__summary';
    summary.setAttribute('role', 'status');
    summary.setAttribute('aria-live', 'polite');
    const controls = doc.createElement('div');
    controls.className = 'lml-pagination__controls';
    nav.append(summary, controls);
    resolveAnchor(container).insertAdjacentElement('afterend', nav);

    const state = { page: readPageParam(win.location.search, key), signature: null };

    const rows = () => Array.from(container.querySelectorAll(selector));

    function goTo(page) {
        state.page = page;
        render();
        try {
            win.history.replaceState(win.history.state, '', withPageParam(win.location.href, key, state.page));
        } catch {
            // Offline/cached pages may not allow history writes; paging still works.
        }
    }

    function render() {
        const all = rows();
        state.signature = visibleSignature(all);
        const info = applyPage(all, state.page, size);
        state.page = info.page;

        nav.hidden = info.total <= size;
        summary.textContent = info.total === 0
            ? ''
            : `Showing ${info.from}–${info.to} of ${info.total} ${label}`;

        controls.replaceChildren();
        if (nav.hidden) return;
        controls.append(button(doc, '‹', 'Previous page', () => goTo(info.page - 1), { disabled: info.page <= 1 }));
        pageWindow(info.page, info.pageCount).forEach((item) => {
            if (item === '…') {
                const gap = doc.createElement('span');
                gap.className = 'lml-pagination__gap';
                gap.textContent = '…';
                gap.setAttribute('aria-hidden', 'true');
                controls.append(gap);
            } else {
                controls.append(button(doc, String(item), `Page ${item}`, () => goTo(item), { current: item === info.page }));
            }
        });
        controls.append(button(doc, '›', 'Next page', () => goTo(info.page + 1), { disabled: info.page >= info.pageCount }));
    }

    let scheduled = false;
    const observer = new win.MutationObserver((mutations) => {
        const filterChanged = mutations.some((m) => m.type === 'attributes')
            && visibleSignature(rows()) !== state.signature;
        if (filterChanged) state.page = 1;
        if (scheduled) return;
        scheduled = true;
        win.queueMicrotask(() => {
            scheduled = false;
            render();
        });
    });
    observer.observe(container, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });

    render();

    return { render, goTo, nav, disconnect: () => observer.disconnect() };
}

export function initTablePagination(doc = document, win = window) {
    doc.querySelectorAll('[data-lml-paginate]').forEach((container) => attachPagination(container, win));
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initTablePagination());
    } else {
        initTablePagination();
    }
}

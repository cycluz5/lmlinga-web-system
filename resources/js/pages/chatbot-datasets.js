/**
 * Chatbot Datasets — list filters (search, category, status).
 * Same approach as the other admin listings: rows that do not match get the `hidden` attribute and the shared
 * pagination component (data-lml-paginate) re-pages the rest, going back to page 1.
 */

/** @param {{ title?: string, source?: string, category?: string, status?: string }} row */
export function matchesFilters(row, { query = '', category = 'all', status = 'all' } = {}) {
    const q = String(query ?? '').trim().toLowerCase();
    const text = `${row.title ?? ''} ${row.source ?? ''}`.toLowerCase();

    return (q === '' || text.includes(q))
        && (!category || category === 'all' || row.category === category)
        && (!status || status === 'all' || row.status === status);
}

function applyFilters(root) {
    const rows = Array.from(root.querySelectorAll('[data-lml-dataset-row]'));
    const empty = root.querySelector('[data-cd-empty]');
    const wrap = root.querySelector('[data-cd-table-wrap]');
    const filters = {
        query: root.querySelector('[data-cd-search]')?.value ?? '',
        category: root.querySelector('[data-cd-category]')?.value ?? 'all',
        status: root.querySelector('[data-cd-status]')?.value ?? 'all',
    };

    let visible = 0;
    rows.forEach((row) => {
        const show = matchesFilters({
            title: row.dataset.cdTitle,
            source: row.dataset.cdSource,
            category: row.dataset.cdCategory,
            status: row.dataset.cdStatus,
        }, filters);
        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }
    if (wrap) {
        wrap.hidden = rows.length > 0 && visible === 0;
    }
}

function init(root) {
    root.querySelector('[data-cd-search]')?.addEventListener('input', () => applyFilters(root));
    root.querySelector('[data-cd-category]')?.addEventListener('change', () => applyFilters(root));
    root.querySelector('[data-cd-status]')?.addEventListener('change', () => applyFilters(root));
    applyFilters(root);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-lml-chatbot-datasets]').forEach(init);
}

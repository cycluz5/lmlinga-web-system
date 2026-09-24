/**
 * Household Profiling — Family Planning.
 *
 * - History date filter (GET form / client auto-submit)
 * - Multi-commodity row add/remove on create/edit forms
 * - DB-13: forms with a real action POST/PUT to Laravel; empty action stays preview-only
 */

const PREVIEW_SAVE_MESSAGE =
    'Preview only: Family Planning visit was kept in this browser session and was not permanently saved.';

/**
 * Pure Visit Date filter for Family Planning history.
 * Mirrors App\Support\DemoFamilyPlanning::filterByDate (inclusive bounds).
 *
 * @param {Array<{visited_at?: string, visitedAt?: string}>} rows
 * @param {string|null|undefined} filterValue
 * @param {{ from?: string|null, to?: string|null, today?: string|Date|null }} [options]
 */
export function filterVisitsByDate(rows, filterValue, options = {}) {
    const list = Array.isArray(rows) ? rows : [];
    const filter = typeof filterValue === 'string' ? filterValue.trim() : '';
    if (!filter || filter === 'all') {
        return list.slice();
    }

    const today = resolveToday(options?.today);
    const visitedOf = (row) =>
        String(row?.visitedAt || row?.visited_at || '').trim();

    if (filter === 'this_month') {
        const start = `${today.getFullYear()}-${pad2(today.getMonth() + 1)}-01`;
        const endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        const end = formatYmd(endDate);
        return list.filter((row) => inInclusiveRange(visitedOf(row), start, end));
    }

    if (filter === 'last_3_months') {
        const start = formatYmd(subMonthsNoOverflow(today, 3));
        const end = formatYmd(today);
        return list.filter((row) => inInclusiveRange(visitedOf(row), start, end));
    }

    if (filter === 'this_year') {
        const start = `${today.getFullYear()}-01-01`;
        const end = `${today.getFullYear()}-12-31`;
        return list.filter((row) => inInclusiveRange(visitedOf(row), start, end));
    }

    if (filter === 'custom') {
        const from = typeof options?.from === 'string' ? options.from.trim() : '';
        const to = typeof options?.to === 'string' ? options.to.trim() : '';
        if (!from || !to || from > to) {
            return list.slice();
        }
        return list.filter((row) => inInclusiveRange(visitedOf(row), from, to));
    }

    return list.slice();
}

export function isCustomRangeReady(fromValue, toValue) {
    const from = typeof fromValue === 'string' ? fromValue.trim() : '';
    const to = typeof toValue === 'string' ? toValue.trim() : '';
    return Boolean(from && to && from <= to);
}

function pad2(n) {
    return String(n).padStart(2, '0');
}

function formatYmd(date) {
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function subMonthsNoOverflow(date, months) {
    const year = date.getFullYear();
    const monthIndex = date.getMonth() - months;
    const day = date.getDate();
    const anchor = new Date(year, monthIndex, 1);
    const lastDay = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0).getDate();
    anchor.setDate(Math.min(day, lastDay));
    return anchor;
}

function resolveToday(value) {
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return new Date(value.getFullYear(), value.getMonth(), value.getDate());
    }
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const [y, m, d] = value.split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
}

function inInclusiveRange(visited, from, to) {
    return visited !== '' && visited >= from && visited <= to;
}

function showToast(root, message) {
    const toast = root.querySelector('[data-fp-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 4200);
}

function setCustomRangeVisible(root, visible) {
    const panel = root.querySelector('[data-fp-custom-range]');
    const fromInput = root.querySelector('[data-fp-date-from]');
    const toInput = root.querySelector('[data-fp-date-to]');
    const funnel = root.querySelector('[data-fp-date-icon-funnel]');
    const calendar = root.querySelector('[data-fp-date-icon-calendar]');

    if (panel instanceof HTMLElement) {
        panel.hidden = !visible;
    }
    if (fromInput instanceof HTMLInputElement) {
        fromInput.disabled = !visible;
        if (!visible) {
            fromInput.value = '';
        }
    }
    if (toInput instanceof HTMLInputElement) {
        toInput.disabled = !visible;
        if (!visible) {
            toInput.value = '';
        }
    }
    if (funnel instanceof HTMLElement) {
        funnel.hidden = visible;
    }
    if (calendar instanceof HTMLElement) {
        calendar.hidden = !visible;
    }
}

function initHistory(root) {
    const select = root.querySelector('[data-fp-date-select]');
    const form = root.querySelector('[data-fp-date-filter]');
    const fromInput = root.querySelector('[data-fp-date-from]');
    const toInput = root.querySelector('[data-fp-date-to]');
    if (!(select instanceof HTMLSelectElement) || !(form instanceof HTMLFormElement)) {
        return;
    }

    const submitIfCustomReady = () => {
        if (select.value !== 'custom') {
            return;
        }
        const from =
            fromInput instanceof HTMLInputElement ? fromInput.value : '';
        const to = toInput instanceof HTMLInputElement ? toInput.value : '';
        if (!isCustomRangeReady(from, to)) {
            return;
        }
        form.requestSubmit();
    };

    select.addEventListener('change', () => {
        if (select.value === 'custom') {
            setCustomRangeVisible(root, true);
            return;
        }
        setCustomRangeVisible(root, false);
        form.requestSubmit();
    });

    fromInput?.addEventListener('change', submitIfCustomReady);
    toInput?.addEventListener('change', submitIfCustomReady);
}

function reindexCommodityRows(list) {
    const rows = Array.from(list.querySelectorAll('[data-fp-commodity-row]'));
    rows.forEach((row, index) => {
        const name = row.querySelector('[data-fp-commodity-name]');
        const qty = row.querySelector('[data-fp-commodity-qty]');
        const nameLabel = row.querySelector('.lml-fp__field label');
        const qtyLabel = row.querySelector('.lml-fp__field--qty label');
        const nameId = `lml-fp-commodity-${index}`;
        const qtyId = `lml-fp-qty-${index}`;

        if (name instanceof HTMLSelectElement) {
            name.id = nameId;
            name.name = `commodities[${index}][name]`;
        }
        if (qty instanceof HTMLInputElement) {
            qty.id = qtyId;
            qty.name = `commodities[${index}][quantity]`;
        }
        if (nameLabel instanceof HTMLLabelElement) {
            nameLabel.setAttribute('for', nameId);
        }
        if (qtyLabel instanceof HTMLLabelElement) {
            qtyLabel.setAttribute('for', qtyId);
        }
    });

    rows.forEach((row) => {
        const removeBtn = row.querySelector('[data-fp-commodity-remove]');
        if (removeBtn instanceof HTMLElement) {
            removeBtn.hidden = rows.length <= 1;
        }
    });
}

function initForm(root) {
    const form = root.querySelector('[data-fp-form]');
    const list = root.querySelector('[data-fp-commodity-list]');
    const template = root.querySelector('[data-fp-commodity-template]');
    const addBtn = root.querySelector('[data-fp-commodity-add]');

    if (!(form instanceof HTMLFormElement) || !(list instanceof HTMLElement)) {
        return;
    }

    addBtn?.addEventListener('click', () => {
        if (!(template instanceof HTMLTemplateElement)) {
            return;
        }
        const fragment = template.content.cloneNode(true);
        list.appendChild(fragment);
        reindexCommodityRows(list);
        const rows = list.querySelectorAll('[data-fp-commodity-row]');
        const last = rows[rows.length - 1];
        const focusTarget = last?.querySelector('[data-fp-commodity-name]');
        if (focusTarget instanceof HTMLElement) {
            focusTarget.focus();
        }
    });

    list.addEventListener('click', (event) => {
        const removeBtn = event.target.closest('[data-fp-commodity-remove]');
        if (!(removeBtn instanceof HTMLElement) || !list.contains(removeBtn)) {
            return;
        }
        const row = removeBtn.closest('[data-fp-commodity-row]');
        const rows = list.querySelectorAll('[data-fp-commodity-row]');
        if (!(row instanceof HTMLElement) || rows.length <= 1) {
            return;
        }
        row.remove();
        reindexCommodityRows(list);
    });

    form.addEventListener('submit', (event) => {
        // DB-13: forms with a real action submit to Laravel; do not intercept.
        const action = (form.getAttribute('action') || '').trim();
        if (action !== '') {
            return;
        }

        event.preventDefault();
        showToast(root, PREVIEW_SAVE_MESSAGE);
    });

    reindexCommodityRows(list);
}

function initFamilyPlanning(root) {
    const mode = root.getAttribute('data-lml-fp-mode');
    if (mode === 'history') {
        initHistory(root);
        return;
    }

    if (mode === 'create' || mode === 'edit') {
        initForm(root);
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-lml-fp]').forEach((root) => {
            initFamilyPlanning(root);
        });
    });
}

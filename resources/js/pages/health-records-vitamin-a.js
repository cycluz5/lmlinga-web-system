/**
 * Health Records → Child Care → Vitamin A monitoring summary.
 * Zone/year/month changes reload the page so PHP remains the coverage authority.
 */

function showVitaminAToast(root, message) {
    const toast = root.querySelector('[data-hr-va-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showVitaminAToast._timer);
    showVitaminAToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

export function vitaminAMonitoringUrl(pageUrl, zone, origin = 'http://127.0.0.1', year = 'all', month = 'all') {
    const url = new URL(pageUrl, origin);

    if (zone && zone !== 'all') {
        url.searchParams.set('zone', zone);
    } else {
        url.searchParams.delete('zone');
    }

    if (year && year !== 'all') {
        url.searchParams.set('year', year);
    } else {
        url.searchParams.delete('year');
    }

    if (month && month !== 'all') {
        url.searchParams.set('month', month);
    } else {
        url.searchParams.delete('month');
    }

    return `${url.pathname}${url.search}`;
}

function selectedOptionLabel(select) {
    const value = select?.value || 'all';
    if (value === 'all') {
        return null;
    }

    return select.options[select.selectedIndex]?.text || value;
}

function updateZoneStatus(root) {
    const zoneSelect = root.querySelector('[data-hr-va-zone]');
    const yearSelect = root.querySelector('[data-hr-va-year]');
    const monthSelect = root.querySelector('[data-hr-va-month]');
    const status = root.querySelector('[data-hr-va-zone-status]');
    if (!status) {
        return;
    }

    const labels = [
        selectedOptionLabel(zoneSelect) || 'All Zones',
        selectedOptionLabel(monthSelect),
        selectedOptionLabel(yearSelect),
    ].filter(Boolean);

    status.textContent = `Selected: ${labels.join(' ')}. Coverage table is filtered on the server.`;
}

/**
 * Column order matches the table's rendered <td data-value> cells left to
 * right (Target, 100k Male/Female/Total, 200k Male/Female/Total, %).
 */
const HRVA_METRIC_KEYS = [
    'target', 'va_100k_male', 'va_100k_female', 'va_100k_total',
    'va_200k_male', 'va_200k_female', 'va_200k_total', 'percentage',
];

function vaCellValue(row, key) {
    const index = HRVA_METRIC_KEYS.indexOf(key);
    if (index === -1) {
        return null;
    }

    const cell = row.querySelectorAll('td[data-value]')[index];
    if (!cell) {
        return null;
    }

    const raw = String(cell.dataset.value || '').replace('%', '').trim();
    return raw === '' ? null : Number(raw);
}

function stampVitaminASortIndex(root) {
    const tbody = root.querySelector('tbody');
    if (!tbody) {
        return;
    }

    Array.from(tbody.querySelectorAll('[data-hr-va-row]')).forEach((row, index) => {
        row.dataset.sortIndex = String(index);
    });
}

function applyVitaminASearch(root) {
    const tbody = root.querySelector('tbody');
    const searchInput = root.querySelector('[data-hr-va-search]');
    const results = root.querySelector('[data-hr-va-results]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-va-row]'));
    const query = (searchInput?.value || '').trim().toLowerCase();

    let visible = 0;
    let total = 0;

    rows.forEach((row) => {
        if (row.classList.contains('lml-hr-va-row--total')) {
            row.hidden = false;
            return;
        }

        total += 1;
        const label = (row.querySelector('.lml-hr-va-cell--age')?.textContent || '').trim().toLowerCase();
        const show = !query || label.includes(query);
        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = query ? `Showing ${visible} of ${total} age groups` : '';
        results.classList.toggle('visually-hidden', !query);
    }
}

/**
 * Sorts the age-band rows by a numeric column, blanks last; the Total row
 * always stays pinned at the bottom. direction 'none' restores the
 * original server-rendered (age-ascending) order.
 */
function sortVitaminARows(root, key, direction) {
    const tbody = root.querySelector('tbody');
    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-va-row]'));
    const totalRow = rows.find((row) => row.classList.contains('lml-hr-va-row--total')) || null;
    const metricRows = rows.filter((row) => row !== totalRow);

    if (direction === 'none') {
        metricRows.sort((a, b) => Number(a.dataset.sortIndex || 0) - Number(b.dataset.sortIndex || 0));
    } else {
        metricRows.sort((a, b) => {
            const av = vaCellValue(a, key);
            const bv = vaCellValue(b, key);
            if (av === null && bv === null) {
                return 0;
            }
            if (av === null) {
                return 1;
            }
            if (bv === null) {
                return -1;
            }
            return direction === 'asc' ? av - bv : bv - av;
        });
    }

    metricRows.forEach((row) => tbody.appendChild(row));
    if (totalRow) {
        tbody.appendChild(totalRow);
    }
}

function initVitaminASort(root) {
    const buttons = Array.from(root.querySelectorAll('[data-hr-va-sort]'));
    let activeKey = null;
    let activeDirection = 'none';

    const updateAriaSort = () => {
        buttons.forEach((btn) => {
            const th = btn.closest('th');
            if (!th) {
                return;
            }

            const key = btn.dataset.hrVaSort;
            const isActive = key === activeKey && activeDirection !== 'none';
            th.setAttribute('aria-sort', isActive ? (activeDirection === 'asc' ? 'ascending' : 'descending') : 'none');
            btn.classList.toggle('is-active', isActive);
        });
    };

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.hrVaSort;

            if (activeKey !== key) {
                activeKey = key;
                activeDirection = 'asc';
            } else if (activeDirection === 'asc') {
                activeDirection = 'desc';
            } else if (activeDirection === 'desc') {
                activeDirection = 'none';
                activeKey = null;
            } else {
                activeDirection = 'asc';
            }

            sortVitaminARows(root, key, activeKey === key ? activeDirection : 'none');
            updateAriaSort();
        });
    });
}

function initHealthRecordsVitaminA(root, locationAssign = (href) => window.location.assign(href)) {
    const exportBtn = root.querySelector('[data-hr-va-export]');
    const zoneSelect = root.querySelector('[data-hr-va-zone]');
    const yearSelect = root.querySelector('[data-hr-va-year]');
    const monthSelect = root.querySelector('[data-hr-va-month]');
    const searchInput = root.querySelector('[data-hr-va-search]');

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showVitaminAToast(root, 'Export is not available.');
            return;
        }
        locationAssign(exportUrl);
    });

    const reloadWithFilters = () => {
        const pageUrl = root.getAttribute('data-page-url') || window.location.pathname;
        locationAssign(vitaminAMonitoringUrl(
            pageUrl,
            zoneSelect?.value || 'all',
            window.location.origin,
            yearSelect?.value || 'all',
            monthSelect?.value || 'all'
        ));
    };

    zoneSelect?.addEventListener('change', reloadWithFilters);
    yearSelect?.addEventListener('change', reloadWithFilters);
    monthSelect?.addEventListener('change', reloadWithFilters);

    searchInput?.addEventListener('input', () => applyVitaminASearch(root));

    updateZoneStatus(root);
    stampVitaminASortIndex(root);
    initVitaminASort(root);
    applyVitaminASearch(root);
}

/**
 * Vitamin A Report Builder — filters reload this same page server-side
 * (see the module docblock: aggregates, not per-resident rows, so there is
 * no client-side JSON payload to re-filter). The Export PDF link's href is
 * already server-rendered with the current filters; only the filter
 * selects themselves need a change handler.
 */
function initVitaminAReportBuilder(root, locationAssign = (href) => window.location.assign(href)) {
    const zoneSelect = root.querySelector('[data-hrva-report-zone]');
    const yearSelect = root.querySelector('[data-hrva-report-year]');
    const monthSelect = root.querySelector('[data-hrva-report-month]');

    const reload = () => {
        const reportUrl = root.getAttribute('data-report-url') || window.location.pathname;
        locationAssign(vitaminAMonitoringUrl(
            reportUrl,
            zoneSelect?.value || 'all',
            window.location.origin,
            yearSelect?.value || 'all',
            monthSelect?.value || 'all'
        ));
    };

    zoneSelect?.addEventListener('change', reload);
    yearSelect?.addEventListener('change', reload);
    monthSelect?.addEventListener('change', reload);
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-lml-hr-vitamin-a]').forEach((root) => {
            initHealthRecordsVitaminA(root);
        });
        document.querySelectorAll('[data-hrva-report]').forEach((root) => {
            initVitaminAReportBuilder(root);
        });
    });
}

export { applyVitaminASearch, sortVitaminARows, vaCellValue, initVitaminAReportBuilder };

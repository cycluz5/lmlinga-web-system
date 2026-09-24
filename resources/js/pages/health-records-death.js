/**
 * Health Records → Death barangay-wide listing.
 * Listing filters submit through GET and Laravel paginates 7 records per page.
 * Resident search runs on the dedicated Select a resident page.
 */

function initListingFilters(root) {
    const form = root.querySelector('[data-hr-death-filter-form]');
    if (!form) {
        return;
    }

    const searchInput = form.querySelector('[data-hr-death-search]');
    const selects = form.querySelectorAll('select[data-hr-death-zone], select[data-hr-death-cause], select[data-hr-death-sex], select[data-hr-death-year], select[data-hr-death-month]');
    let searchTimer;

    const submitFilters = () => {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };

    selects.forEach((select) => {
        select.addEventListener('change', submitFilters);
    });

    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(submitFilters, 350);
    });

    searchInput?.addEventListener('search', submitFilters);
}

function initResidentSearch(root) {
    const input = root.querySelector('[data-hr-death-resident-search]');
    const zoneSelect = root.querySelector('[data-hr-death-resident-zone]');
    const statusSelect = root.querySelector('[data-hr-death-resident-status]');
    const rows = Array.from(root.querySelectorAll('[data-hr-death-resident-row]'));
    if (!input || rows.length === 0) {
        return;
    }

    const apply = () => {
        const query = input.value.trim().toLowerCase();
        const zone = (zoneSelect?.value || 'all').trim();
        const status = (statusSelect?.value || 'all').trim();

        rows.forEach((row) => {
            const name = row.dataset.name || '';
            const rowZone = row.dataset.zone || '';
            const rowStatus = row.dataset.statusLabel || '';
            const matchesName = !query || name.includes(query);
            const matchesZone = zone === 'all' || rowZone === zone;
            const matchesStatus = status === 'all' || rowStatus === status;
            row.hidden = !(matchesName && matchesZone && matchesStatus);
        });
    };

    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
    zoneSelect?.addEventListener('change', apply);
    statusSelect?.addEventListener('change', apply);
}

function initHealthRecordsDeath(root) {
    initListingFilters(root);
    initResidentSearch(root);
}

/**
 * Report Builder — same behavior as Environmental Health's and Household
 * Profiling's: zone/year/month filters, a live preview built as landscape
 * "page" cards (a single field-header row, one row per verified death
 * record), then a real PDF export reflecting the current filters.
 */

const HRD_COLUMNS = [
    { key: 'full_name', label: 'Name' },
    { key: 'age', label: 'Age' },
    { key: 'birthday', label: 'Birthday' },
    { key: 'sex', label: 'Sex' },
    { key: 'cause_of_death', label: 'Cause of Death' },
];

const HRD_MONTH_LABELS = {
    '01': 'January', '02': 'February', '03': 'March', '04': 'April',
    '05': 'May', '06': 'June', '07': 'July', '08': 'August',
    '09': 'September', '10': 'October', '11': 'November', '12': 'December',
};

function hrdParseJsonScript(root, selector) {
    const el = root.querySelector(selector);
    if (!el) {
        return null;
    }
    try {
        return JSON.parse(el.textContent || 'null');
    } catch {
        return null;
    }
}

function hrdReadReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hrd-report-zone]')?.value || 'all'),
        year: String(reportRoot.querySelector('[data-hrd-report-year]')?.value || 'all'),
        month: String(reportRoot.querySelector('[data-hrd-report-month]')?.value || 'all'),
    };
}

function hrdRowMatchesReport(row, options) {
    if (options.zone !== 'all' && String(row.zone || '') !== options.zone) {
        return false;
    }
    if (options.year !== 'all' && String(row.year || '') !== options.year) {
        return false;
    }
    if (options.month !== 'all' && String(row.month || '') !== options.month) {
        return false;
    }

    return true;
}

function hrdPeriodLabel(options) {
    if (options.year === 'all' || !options.year) {
        return 'All Time';
    }
    if (options.month !== 'all' && options.month) {
        return `${HRD_MONTH_LABELS[options.month] || options.month} ${options.year}`;
    }

    return `Year ${options.year}`;
}

function hrdBuildExportParams(options) {
    const params = new URLSearchParams();
    if (options.zone && options.zone !== 'all') {
        params.set('zone', options.zone);
    }
    if (options.year && options.year !== 'all') {
        params.set('year', options.year);
    }
    if (options.month && options.month !== 'all') {
        params.set('month', options.month);
    }
    return params;
}

function hrdBuildDataTableHead() {
    const thead = document.createElement('thead');
    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hrd-report__field-row';
    HRD_COLUMNS.forEach((column) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);
    return thead;
}

function hrdBuildDataRow(row) {
    const tr = document.createElement('tr');
    HRD_COLUMNS.forEach((column) => {
        const td = document.createElement('td');
        td.textContent = row[column.key] || '—';
        tr.appendChild(td);
    });
    return tr;
}

function hrdPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders.
 */
function renderHrdZonePages(host, zoneName, zoneRows, overallCount, periodLabel) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hrd-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hrd-report__page';
        content = document.createElement('div');
        content.className = 'lml-hrd-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hrd-report__page-footer';
        footer.innerHTML = '<span class="lml-hrd-report__page-footer-zone"></span><span class="lml-hrd-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hrd-report__data-table';
        table.appendChild(hrdBuildDataTableHead());
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hrd-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hrd-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hrd-report__stats-line';
        stats.textContent = `Overall Death Count : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hrd-report__stats-line lml-hrd-report__stats-line--period';
        period.textContent = periodLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) =>
        String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' })
    );

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrdBuildDataRow(row);
        tbody.appendChild(tr);

        if (hrdPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hrd-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hrd-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hrd-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrdReportPreview(reportRoot, rows, options) {
    const host = reportRoot.querySelector('[data-hrd-report-preview]');
    const empty = reportRoot.querySelector('[data-hrd-report-empty]');
    const count = reportRoot.querySelector('[data-hrd-report-count]');
    const meta = reportRoot.querySelector('[data-hrd-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrdRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const periodLabel = hrdPeriodLabel(options);

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderHrdZonePages(host, zoneName, byZone[zoneName], visibleRows.length, periodLabel);
    });

    if (empty) {
        empty.hidden = visibleRows.length > 0;
    }
    if (count) {
        count.textContent = `${visibleRows.length} record${visibleRows.length === 1 ? '' : 's'} in preview`;
    }
    if (meta) {
        const zoneLabel = options.zone !== 'all' ? options.zone : 'All Zones';
        meta.textContent = `Scope: ${zoneLabel} · ${periodLabel} · ${visibleRows.length} record(s)`;
    }

    const params = hrdBuildExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hrd-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initDeathReportBuilder(reportRoot) {
    const rows = hrdParseJsonScript(reportRoot, '[data-hrd-report-rows]') || [];

    const refresh = () => {
        renderHrdReportPreview(reportRoot, rows, hrdReadReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hrd-report-zone]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrd-report-year]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrd-report-month]')?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-death]').forEach((root) => {
        initHealthRecordsDeath(root);
    });
    document.querySelectorAll('[data-hrd-report]').forEach((root) => {
        initDeathReportBuilder(root);
    });
});

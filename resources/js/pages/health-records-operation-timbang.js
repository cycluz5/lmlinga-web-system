/**
 * Health Records → Child Care → Operation Timbang monitoring summary.
 * Month/year session switching reloads the page with year/month query params
 * so the server can filter persisted measurements. Zone/sex/status/name filters
 * operate on the server-rendered session rows. Export remains a UI-phase toast.
 */

function showOperationTimbangToast(root, message) {
    const toast = root.querySelector('[data-hr-ot-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showOperationTimbangToast._timer);
    showOperationTimbangToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function applyOperationTimbangFilters(root) {
    const tbody = root.querySelector('[data-hr-ot-tbody]');
    const empty = root.querySelector('[data-hr-ot-empty]');
    const results = root.querySelector('[data-hr-ot-results]');
    const tableScroll = root.querySelector(
        '.lml-hr-child-care__table-scroll--operation-timbang'
    );
    const searchInput = root.querySelector('[data-hr-ot-search]');
    const zoneSelect = root.querySelector('[data-hr-ot-zone]');
    const sexSelect = root.querySelector('[data-hr-ot-sex]');
    const statusSelect = root.querySelector('[data-hr-ot-status]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-ot-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const sex = sexSelect?.value || 'all';
    const status = statusSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowSex = row.dataset.sex || '';
        const rowStatus = row.dataset.status || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesSex = sex === 'all' || rowSex === sex;
        const matchesStatus = status === 'all' || rowStatus === status;
        const show = matchesSearch && matchesZone && matchesSex && matchesStatus;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} children`;
    }

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function navigateOperationTimbangSession(root, year, month) {
    const baseUrl = root.dataset.otPageUrl || window.location.pathname;
    const params = new URLSearchParams();
    params.set('year', String(year));
    params.set('month', String(month));
    window.location.assign(`${baseUrl}?${params.toString()}`);
}

function initHealthRecordsOperationTimbang(root) {
    const exportBtn = root.querySelector('[data-hr-ot-export]');
    const searchInput = root.querySelector('[data-hr-ot-search]');
    const zoneSelect = root.querySelector('[data-hr-ot-zone]');
    const sexSelect = root.querySelector('[data-hr-ot-sex]');
    const statusSelect = root.querySelector('[data-hr-ot-status]');
    const yearSelect = root.querySelector('[data-hr-ot-year]');
    const monthList = root.querySelector('[data-hr-ot-month-list]');

    const refresh = () => applyOperationTimbangFilters(root);

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showOperationTimbangToast(root, 'Export is not available.');
            return;
        }
        // Carries the currently viewed session forward so the Report
        // Builder opens on the same weigh-in period, not the default.
        const params = new URLSearchParams();
        const year = root.dataset.selectedYear || '';
        const month = root.dataset.selectedMonth || '';
        if (year) {
            params.set('year', year);
        }
        if (month) {
            params.set('month', month);
        }
        const query = params.toString();
        window.location.assign(query ? `${exportUrl}?${query}` : exportUrl);
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    sexSelect?.addEventListener('change', refresh);
    statusSelect?.addEventListener('change', refresh);

    monthList?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-hr-ot-month]');
        if (!button || !monthList.contains(button) || button.disabled) {
            return;
        }
        const month = Number(button.dataset.month || root.dataset.selectedMonth || 1);
        const year = Number(button.dataset.year || root.dataset.selectedYear || new Date().getFullYear());
        navigateOperationTimbangSession(root, year, month);
    });

    yearSelect?.addEventListener('change', () => {
        const year = Number(yearSelect.value || root.dataset.selectedYear || new Date().getFullYear());
        const month = Number(root.dataset.selectedMonth || 1);
        navigateOperationTimbangSession(root, year, month);
    });

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-operation-timbang]').forEach((root) => {
        initHealthRecordsOperationTimbang(root);
    });
    document.querySelectorAll('[data-hrot-report]').forEach((root) => {
        initOperationTimbangReportBuilder(root);
    });
});

const HROT_COLUMNS = [
    { key: 'full_name', label: 'Name' },
    { key: 'age_label', label: 'Age' },
    { key: 'weight', label: 'Weight' },
    { key: 'height', label: 'Height' },
    { key: 'muac', label: 'MUAC' },
    { key: 'status_label', label: 'Status' },
];

function hrotParseJsonScript(root, selector) {
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

function hrotReadReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hrot-report-zone]')?.value || 'all'),
        sex: String(reportRoot.querySelector('[data-hrot-report-sex]')?.value || 'all'),
        status: String(reportRoot.querySelector('[data-hrot-report-status]')?.value || 'all'),
    };
}

function hrotRowMatchesReport(row, options) {
    if (options.zone !== 'all' && String(row.zone || '') !== options.zone) {
        return false;
    }
    if (options.sex !== 'all' && String(row.sex || '') !== options.sex) {
        return false;
    }
    if (options.status !== 'all' && String(row.status || '') !== options.status) {
        return false;
    }

    return true;
}

/**
 * Reload the report-builder page with a new Year/Month session, carrying
 * the current Zone/Sex/Status filter selections forward (only the session
 * itself needs a server round-trip — see the module docblock).
 */
function hrotSessionReloadUrl(reportRoot, year, month) {
    const reportUrl = reportRoot.getAttribute('data-report-url') || window.location.pathname;
    const url = new URL(reportUrl, window.location.origin);
    url.searchParams.set('year', String(year));
    url.searchParams.set('month', String(month));

    const options = hrotReadReportOptions(reportRoot);
    if (options.zone && options.zone !== 'all') {
        url.searchParams.set('zone', options.zone);
    }
    if (options.sex && options.sex !== 'all') {
        url.searchParams.set('sex', options.sex);
    }
    if (options.status && options.status !== 'all') {
        url.searchParams.set('status', options.status);
    }

    return `${url.pathname}${url.search}`;
}

function hrotBuildExportParams(reportRoot, options) {
    const params = new URLSearchParams();
    const yearSelect = reportRoot.querySelector('[data-hrot-report-year]');
    const monthSelect = reportRoot.querySelector('[data-hrot-report-month]');
    if (yearSelect?.value) {
        params.set('year', yearSelect.value);
    }
    if (monthSelect?.value) {
        params.set('month', monthSelect.value);
    }
    if (options.zone && options.zone !== 'all') {
        params.set('zone', options.zone);
    }
    if (options.sex && options.sex !== 'all') {
        params.set('sex', options.sex);
    }
    if (options.status && options.status !== 'all') {
        params.set('status', options.status);
    }
    return params;
}

function hrotBuildDataTableHead() {
    const thead = document.createElement('thead');
    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hr-ot-report__field-row';
    HROT_COLUMNS.forEach((column) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);
    return thead;
}

function hrotBuildDataRow(row) {
    const tr = document.createElement('tr');
    HROT_COLUMNS.forEach((column) => {
        const td = document.createElement('td');
        td.textContent = row[column.key] || '—';
        tr.appendChild(td);
    });
    return tr;
}

function hrotPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders.
 */
function renderHrotZonePages(host, zoneName, zoneRows, overallCount, sessionLabel) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hr-ot-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hr-ot-report__page';
        content = document.createElement('div');
        content.className = 'lml-hr-ot-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hr-ot-report__page-footer';
        footer.innerHTML = '<span class="lml-hr-ot-report__page-footer-zone"></span><span class="lml-hr-ot-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hr-ot-report__data-table';
        table.appendChild(hrotBuildDataTableHead());
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hr-ot-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hr-ot-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hr-ot-report__stats-line';
        stats.textContent = `Overall Weigh-In Population : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hr-ot-report__stats-line lml-hr-ot-report__stats-line--period';
        period.textContent = sessionLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) =>
        String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' })
    );

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrotBuildDataRow(row);
        tbody.appendChild(tr);

        if (hrotPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hr-ot-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hr-ot-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hr-ot-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrotReportPreview(reportRoot, rows, options) {
    const host = reportRoot.querySelector('[data-hrot-report-preview]');
    const empty = reportRoot.querySelector('[data-hrot-report-empty]');
    const count = reportRoot.querySelector('[data-hrot-report-count]');
    const meta = reportRoot.querySelector('[data-hrot-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrotRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const monthSelect = reportRoot.querySelector('[data-hrot-report-month]');
    const yearSelect = reportRoot.querySelector('[data-hrot-report-year]');
    const sessionLabel = monthSelect && yearSelect
        ? `${monthSelect.options[monthSelect.selectedIndex]?.text || ''} ${yearSelect.value}`.trim()
        : '';

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderHrotZonePages(host, zoneName, byZone[zoneName], visibleRows.length, sessionLabel);
    });

    if (empty) {
        empty.hidden = visibleRows.length > 0;
    }
    if (count) {
        count.textContent = `${visibleRows.length} record${visibleRows.length === 1 ? '' : 's'} in preview`;
    }
    if (meta) {
        const zoneLabel = options.zone !== 'all' ? options.zone : 'All Zones';
        meta.textContent = `Scope: ${zoneLabel} · ${sessionLabel} · ${visibleRows.length} record(s)`;
    }

    const params = hrotBuildExportParams(reportRoot, options);
    const exportLink = reportRoot.querySelector('[data-hrot-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initOperationTimbangReportBuilder(reportRoot, locationAssign = (href) => window.location.assign(href)) {
    const rows = hrotParseJsonScript(reportRoot, '[data-hrot-report-rows]') || [];
    const yearSelect = reportRoot.querySelector('[data-hrot-report-year]');
    const monthSelect = reportRoot.querySelector('[data-hrot-report-month]');

    const refreshPreview = () => {
        renderHrotReportPreview(reportRoot, rows, hrotReadReportOptions(reportRoot));
    };

    const reloadSession = () => {
        const year = yearSelect?.value || new Date().getFullYear();
        const month = monthSelect?.value || 1;
        locationAssign(hrotSessionReloadUrl(reportRoot, year, month));
    };

    yearSelect?.addEventListener('change', reloadSession);
    monthSelect?.addEventListener('change', reloadSession);

    reportRoot.querySelector('[data-hrot-report-zone]')?.addEventListener('change', refreshPreview);
    reportRoot.querySelector('[data-hrot-report-sex]')?.addEventListener('change', refreshPreview);
    reportRoot.querySelector('[data-hrot-report-status]')?.addEventListener('change', refreshPreview);

    refreshPreview();
}

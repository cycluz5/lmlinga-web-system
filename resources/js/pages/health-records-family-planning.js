/**
 * Health Records → Family Planning barangay-wide summary.
 * Filters operate on displayed rows only.
 */

function showFamilyPlanningToast(root, message) {
    const toast = root.querySelector('[data-hr-fp-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showFamilyPlanningToast._timer);
    showFamilyPlanningToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function updateFamilyPlanningEmptyState(root, empty, visible) {
    if (!empty) {
        return;
    }

    const hasRecords = root.dataset.hasRecords === '1';
    const noRecordsTitle = empty.querySelector('[data-hr-fp-empty-no-records]');
    const filteredTitle = empty.querySelector('[data-hr-fp-empty-filtered]');
    const hint = empty.querySelector('[data-hr-fp-empty-hint]');
    const icon = empty.querySelector('.lml-hr-fp__empty-icon i');

    if (!hasRecords) {
        empty.hidden = false;
        if (noRecordsTitle) {
            noRecordsTitle.hidden = false;
        }
        if (filteredTitle) {
            filteredTitle.hidden = true;
        }
        if (hint) {
            hint.hidden = true;
        }
        if (icon) {
            icon.className = 'bi bi-inbox';
        }

        return;
    }

    empty.hidden = visible > 0;
    if (noRecordsTitle) {
        noRecordsTitle.hidden = true;
    }
    if (filteredTitle) {
        filteredTitle.hidden = visible > 0;
    }
    if (hint) {
        hint.hidden = visible > 0;
    }
    if (icon) {
        icon.className = 'bi bi-search';
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function updateFamilyPlanningStats(root, rows) {
    let total = 0;
    const methodCounts = new Map();

    rows.forEach((row) => {
        if (row.hidden) {
            return;
        }

        total += 1;
        const method = (row.dataset.method || '').trim();
        if (!method || method === '—') {
            return;
        }

        const key = method.toLowerCase();
        const existing = methodCounts.get(key);
        if (existing) {
            existing.count += 1;
        } else {
            methodCounts.set(key, { name: method, count: 1 });
        }
    });

    const totalEl = root.querySelector('[data-fp-stat="total"]');
    if (totalEl) {
        totalEl.textContent = String(total);
    }

    const commoditiesEl = root.querySelector('[data-fp-stat="commodities"]');
    if (!commoditiesEl) {
        return;
    }

    const commodities = Array.from(methodCounts.values()).sort((a, b) => {
        if (b.count !== a.count) {
            return b.count - a.count;
        }

        return a.name.localeCompare(b.name);
    });

    if (commodities.length === 0) {
        commoditiesEl.innerHTML = '<p class="lml-hr-fp__commodities-empty">No commodities recorded</p>';
        return;
    }

    commoditiesEl.innerHTML = `<ul class="lml-hr-fp__commodities-list">${commodities
        .map(
            (item) => `<li class="lml-hr-fp__commodities-item">
                <span class="lml-hr-fp__commodities-name">${escapeHtml(item.name)}</span>
                <span class="lml-hr-fp__commodities-count">${item.count}</span>
            </li>`
        )
        .join('')}</ul>`;
}

function applyFamilyPlanningFilters(root) {
    const tbody = root.querySelector('[data-hr-fp-tbody]');
    const empty = root.querySelector('[data-hr-fp-empty]');
    const results = root.querySelector('[data-hr-fp-results]');
    const tableScroll = root.querySelector('.lml-hr-fp__table-scroll');
    const searchInput = root.querySelector('[data-hr-fp-search]');
    const zoneSelect = root.querySelector('[data-hr-fp-zone]');
    const yearSelect = root.querySelector('[data-hr-fp-year]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-fp-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const year = yearSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowYear = row.dataset.year || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesYear = year === 'all' || rowYear === year;
        const show = matchesSearch && matchesZone && matchesYear;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} family planning patients`;
    }

    updateFamilyPlanningStats(root, rows);

    if (empty) {
        updateFamilyPlanningEmptyState(root, empty, visible);
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsFamilyPlanning(root) {
    const exportBtn = root.querySelector('[data-hr-fp-export]');
    const searchInput = root.querySelector('[data-hr-fp-search]');
    const zoneSelect = root.querySelector('[data-hr-fp-zone]');
    const yearSelect = root.querySelector('[data-hr-fp-year]');

    const refresh = () => applyFamilyPlanningFilters(root);

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showFamilyPlanningToast(root, 'Export is not available.');
            return;
        }
        window.location.assign(exportUrl);
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    yearSelect?.addEventListener('change', refresh);

    refresh();
}

/**
 * Report Builder — same behavior as Environmental Health's, Household
 * Profiling's, Death's, and Maternal Care's: zone/year/month filters, a
 * live preview built as landscape "page" cards (a single field-header
 * row, one row per commodity given), then a real PDF export reflecting
 * the current filters.
 */

const HRFP_COLUMNS = [
    { key: 'full_name', label: 'Name' },
    { key: 'visit_date', label: 'Visit Date' },
    { key: 'commodity', label: 'Commodity' },
    { key: 'quantity', label: 'Quantity' },
];

const HRFP_MONTH_LABELS = {
    '01': 'January', '02': 'February', '03': 'March', '04': 'April',
    '05': 'May', '06': 'June', '07': 'July', '08': 'August',
    '09': 'September', '10': 'October', '11': 'November', '12': 'December',
};

function hrfpParseJsonScript(root, selector) {
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

function hrfpReadReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hrfp-report-zone]')?.value || 'all'),
        year: String(reportRoot.querySelector('[data-hrfp-report-year]')?.value || 'all'),
        month: String(reportRoot.querySelector('[data-hrfp-report-month]')?.value || 'all'),
    };
}

function hrfpRowMatchesReport(row, options) {
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

function hrfpPeriodLabel(options) {
    if (options.year === 'all' || !options.year) {
        return 'All Time';
    }
    if (options.month !== 'all' && options.month) {
        return `${HRFP_MONTH_LABELS[options.month] || options.month} ${options.year}`;
    }

    return `Year ${options.year}`;
}

function hrfpBuildExportParams(options) {
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

function hrfpBuildDataTableHead() {
    const thead = document.createElement('thead');
    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hr-fp-report__field-row';
    HRFP_COLUMNS.forEach((column) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);
    return thead;
}

function hrfpBuildDataRow(row) {
    const tr = document.createElement('tr');
    HRFP_COLUMNS.forEach((column) => {
        const td = document.createElement('td');
        td.textContent = row[column.key] || '—';
        tr.appendChild(td);
    });
    return tr;
}

function hrfpPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders.
 */
function renderHrfpZonePages(host, zoneName, zoneRows, overallCount, periodLabel) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hr-fp-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hr-fp-report__page';
        content = document.createElement('div');
        content.className = 'lml-hr-fp-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hr-fp-report__page-footer';
        footer.innerHTML = '<span class="lml-hr-fp-report__page-footer-zone"></span><span class="lml-hr-fp-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hr-fp-report__data-table';
        table.appendChild(hrfpBuildDataTableHead());
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hr-fp-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hr-fp-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hr-fp-report__stats-line';
        stats.textContent = `Overall Commodities Given : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hr-fp-report__stats-line lml-hr-fp-report__stats-line--period';
        period.textContent = periodLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) => {
        const byName = String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' });
        return byName !== 0 ? byName : String(a.visit_date || '').localeCompare(String(b.visit_date || ''));
    });

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrfpBuildDataRow(row);
        tbody.appendChild(tr);

        if (hrfpPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hr-fp-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hr-fp-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hr-fp-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrfpReportPreview(reportRoot, rows, options) {
    const host = reportRoot.querySelector('[data-hrfp-report-preview]');
    const empty = reportRoot.querySelector('[data-hrfp-report-empty]');
    const count = reportRoot.querySelector('[data-hrfp-report-count]');
    const meta = reportRoot.querySelector('[data-hrfp-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrfpRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const periodLabel = hrfpPeriodLabel(options);

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderHrfpZonePages(host, zoneName, byZone[zoneName], visibleRows.length, periodLabel);
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

    const params = hrfpBuildExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hrfp-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initFamilyPlanningReportBuilder(reportRoot) {
    const rows = hrfpParseJsonScript(reportRoot, '[data-hrfp-report-rows]') || [];

    const refresh = () => {
        renderHrfpReportPreview(reportRoot, rows, hrfpReadReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hrfp-report-zone]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrfp-report-year]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrfp-report-month]')?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-fp]').forEach((root) => {
        initHealthRecordsFamilyPlanning(root);
    });
    document.querySelectorAll('[data-hrfp-report]').forEach((root) => {
        initFamilyPlanningReportBuilder(root);
    });
});

export { applyFamilyPlanningFilters, updateFamilyPlanningStats };

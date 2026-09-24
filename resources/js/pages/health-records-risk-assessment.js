/**
 * Health Records → Risk Assessment barangay-wide summary.
 * Filters operate on displayed rows. Export downloads a PDF.
 */

function showRiskAssessmentToast(root, message) {
    const toast = root.querySelector('[data-hr-ra-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showRiskAssessmentToast._timer);
    showRiskAssessmentToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function updateRiskAssessmentEmptyState(root, empty, visible) {
    if (!empty) {
        return;
    }

    const hasRecords = root.dataset.hasRecords === '1';
    const noRecordsTitle = empty.querySelector('[data-hr-ra-empty-no-records]');
    const filteredTitle = empty.querySelector('[data-hr-ra-empty-filtered]');
    const hint = empty.querySelector('[data-hr-ra-empty-hint]');
    const icon = empty.querySelector('.lml-hr-risk__empty-icon i');

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

function updateRiskAssessmentStats(root, rows) {
    const currentYear = root.dataset.currentYear || '';
    const currentMonth = root.dataset.currentMonth || '';
    let thisYear = 0;
    let thisMonth = 0;

    rows.forEach((row) => {
        if (row.hidden) {
            return;
        }

        const year = row.dataset.year || '';
        const month = row.dataset.month || '';

        if (year === currentYear) {
            thisYear += 1;
            if (month === currentMonth) {
                thisMonth += 1;
            }
        }
    });

    const yearEl = root.querySelector('[data-ra-stat="this-year"]');
    const monthEl = root.querySelector('[data-ra-stat="this-month"]');

    if (yearEl) {
        yearEl.textContent = String(thisYear);
    }
    if (monthEl) {
        monthEl.textContent = String(thisMonth);
    }
}

function applyRiskAssessmentFilters(root) {
    const tbody = root.querySelector('[data-hr-ra-tbody]');
    const empty = root.querySelector('[data-hr-ra-empty]');
    const results = root.querySelector('[data-hr-ra-results]');
    const tableScroll = root.querySelector('.lml-hr-risk__table-scroll');
    const searchInput = root.querySelector('[data-hr-ra-search]');
    const zoneSelect = root.querySelector('[data-hr-ra-zone]');
    const yearSelect = root.querySelector('[data-hr-ra-year]');
    const monthSelect = root.querySelector('[data-hr-ra-month]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-ra-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const year = yearSelect?.value || 'all';
    const month = monthSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowYear = row.dataset.year || '';
        const rowMonth = row.dataset.month || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesYear = year === 'all' || rowYear === year;
        const matchesMonth = month === 'all' || rowMonth === month;
        const show = matchesSearch && matchesZone && matchesYear && matchesMonth;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} assessed clients`;
    }

    updateRiskAssessmentStats(root, rows);

    if (empty) {
        updateRiskAssessmentEmptyState(root, empty, visible);
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsRiskAssessment(root) {
    const exportBtn = root.querySelector('[data-hr-ra-export]');
    const searchInput = root.querySelector('[data-hr-ra-search]');
    const zoneSelect = root.querySelector('[data-hr-ra-zone]');
    const yearSelect = root.querySelector('[data-hr-ra-year]');
    const monthSelect = root.querySelector('[data-hr-ra-month]');

    const refresh = () => applyRiskAssessmentFilters(root);

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showRiskAssessmentToast(root, 'Export is not available.');
            return;
        }
        window.location.assign(exportUrl);
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    yearSelect?.addEventListener('change', refresh);
    monthSelect?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-risk]').forEach((root) => {
        initHealthRecordsRiskAssessment(root);
    });
    document.querySelectorAll('[data-hrra-report]').forEach((root) => {
        initRiskAssessmentReportBuilder(root);
    });
});

const HRRA_MONTH_LABELS = {
    '01': 'January', '02': 'February', '03': 'March', '04': 'April',
    '05': 'May', '06': 'June', '07': 'July', '08': 'August',
    '09': 'September', '10': 'October', '11': 'November', '12': 'December',
};

function hrraParseJsonScript(root, selector) {
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

function hrraReadReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hrra-report-zone]')?.value || 'all'),
        year: String(reportRoot.querySelector('[data-hrra-report-year]')?.value || 'all'),
        month: String(reportRoot.querySelector('[data-hrra-report-month]')?.value || 'all'),
    };
}

function hrraRowMatchesReport(row, options) {
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

function hrraPeriodLabel(options) {
    if (options.year === 'all' || !options.year) {
        return 'All Time';
    }
    if (options.month !== 'all' && options.month) {
        return `${HRRA_MONTH_LABELS[options.month] || options.month} ${options.year}`;
    }

    return `Year ${options.year}`;
}

function hrraBuildExportParams(options) {
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

/**
 * Two-level header: a section group row (one <th colspan> per section),
 * then the individual field labels below — mirrors RiskAssessmentPdf's
 * grouped table header. `sections` is one entry from
 * HealthRecordsRiskAssessment::columnPages() (already includes the
 * repeated anchor columns); `fields` is its flattened field list.
 */
function hrraBuildDataTableHead(sections, fields) {
    const thead = document.createElement('thead');

    const groupRow = document.createElement('tr');
    groupRow.className = 'lml-hr-ra-report__group-row';
    sections.forEach((section) => {
        const th = document.createElement('th');
        th.scope = 'colgroup';
        th.colSpan = section.fields.length;
        th.textContent = section.title;
        groupRow.appendChild(th);
    });
    thead.appendChild(groupRow);

    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hr-ra-report__field-row';
    fields.forEach((field) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = field.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);

    return thead;
}

function hrraBuildDataRow(fields, row) {
    const tr = document.createElement('tr');
    fields.forEach((field) => {
        const td = document.createElement('td');
        td.textContent = row[field.key] || '';
        tr.appendChild(td);
    });
    return tr;
}

function hrraFlattenFields(sections) {
    return sections.flatMap((section) => section.fields);
}

function hrraPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders —
 * one straight row per risk assessment record, every field always present.
 */
function renderHrraZonePages(host, zoneName, zoneRows, overallCount, periodLabel, sections, fields, groupTitle, partNumber, partCount) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hr-ra-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hr-ra-report__page';
        content = document.createElement('div');
        content.className = 'lml-hr-ra-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hr-ra-report__page-footer';
        footer.innerHTML = '<span class="lml-hr-ra-report__page-footer-zone"></span><span class="lml-hr-ra-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hr-ra-report__data-table';
        table.appendChild(hrraBuildDataTableHead(sections, fields));
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    const partSuffix = ` · ${groupTitle}${partCount > 1 ? ` (PART ${partNumber} OF ${partCount})` : ''}`;

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hr-ra-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT${partSuffix}`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hr-ra-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hr-ra-report__stats-line';
        stats.textContent = `Overall Assessed Clients : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hr-ra-report__stats-line lml-hr-ra-report__stats-line--period';
        period.textContent = periodLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) =>
        String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' })
    );

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrraBuildDataRow(fields, row);
        tbody.appendChild(tr);

        if (hrraPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hr-ra-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hr-ra-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hr-ra-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrraReportPreview(reportRoot, rows, columnPages, options) {
    const host = reportRoot.querySelector('[data-hrra-report-preview]');
    const empty = reportRoot.querySelector('[data-hrra-report-empty]');
    const count = reportRoot.querySelector('[data-hrra-report-count]');
    const meta = reportRoot.querySelector('[data-hrra-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrraRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const periodLabel = hrraPeriodLabel(options);

    host.replaceChildren();

    columnPages.forEach((page) => {
        const fields = hrraFlattenFields(page.sections);
        zoneNames.forEach((zoneName) => {
            renderHrraZonePages(
                host, zoneName, byZone[zoneName], visibleRows.length, periodLabel,
                page.sections, fields, page.groupTitle, page.part, page.partsInGroup
            );
        });
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

    const params = hrraBuildExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hrra-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initRiskAssessmentReportBuilder(reportRoot) {
    const rows = hrraParseJsonScript(reportRoot, '[data-hrra-report-rows]') || [];
    const columnPages = hrraParseJsonScript(reportRoot, '[data-hrra-report-column-pages]') || [];

    const refresh = () => {
        renderHrraReportPreview(reportRoot, rows, columnPages, hrraReadReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hrra-report-zone]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrra-report-year]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrra-report-month]')?.addEventListener('change', refresh);

    refresh();
}

export { applyRiskAssessmentFilters, updateRiskAssessmentStats };

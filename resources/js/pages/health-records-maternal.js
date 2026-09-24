/**
 * Health Records → Maternal Care listings.
 * Filters operate on displayed rows only. Export downloads a PDF.
 */

function showMaternalToast(root, message) {
    const toast = root.querySelector('[data-hr-mc-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showMaternalToast._timer);
    showMaternalToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function updateMaternalEmptyState(root, empty, visible) {
    if (!empty) {
        return;
    }

    const hasRecords = root.dataset.hasRecords === '1';
    const noRecordsTitle = empty.querySelector('[data-hr-mc-empty-no-records]');
    const filteredTitle = empty.querySelector('[data-hr-mc-empty-filtered]');
    const hint = empty.querySelector('[data-hr-mc-empty-hint]');
    const icon = empty.querySelector('.lml-hr-mc__empty-icon i');

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

function updateMaternalStats(root, rows) {
    let total = 0;
    let delivered = 0;

    rows.forEach((row) => {
        if (row.hidden) {
            return;
        }

        total += 1;
        if (row.dataset.deliveredThisMonth === '1') {
            delivered += 1;
        }
    });

    const totalEl = root.querySelector('[data-mc-stat="total"]');
    const deliveredEl = root.querySelector('[data-mc-stat="delivered"]');

    if (totalEl) {
        totalEl.textContent = String(total);
    }
    if (deliveredEl) {
        deliveredEl.textContent = String(delivered);
    }
}

function applyMaternalFilters(root) {
    const tbody = root.querySelector('[data-hr-mc-tbody]');
    const empty = root.querySelector('[data-hr-mc-empty]');
    const results = root.querySelector('[data-hr-mc-results]');
    const tableScroll = root.querySelector('.lml-hr-mc__table-scroll');
    const searchInput = root.querySelector('[data-hr-mc-search]');
    const zoneSelect = root.querySelector('[data-hr-mc-zone]');
    const barangaySelect = root.querySelector('[data-hr-mc-barangay]');
    const yearSelect = root.querySelector('[data-hr-mc-year]');
    const monthSelect = root.querySelector('[data-hr-mc-month]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-mc-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const barangay = barangaySelect?.value || 'all';
    const year = yearSelect?.value || 'all';
    const month = monthSelect?.value || 'all';
    const mode = root.dataset.lmlHrMcMode || 'resident';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowBarangay = row.dataset.barangay || '';
        const rowYear = row.dataset.year || '';
        const rowMonth = row.dataset.month || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesBarangay = barangay === 'all' || rowBarangay === barangay;
        const matchesYear = year === 'all' || rowYear === year;
        const matchesMonth = month === 'all' || rowMonth === month;
        const show = matchesSearch && matchesZone && matchesBarangay && matchesYear && matchesMonth;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        const label = mode === 'non-resident'
            ? 'non-resident maternal care clients'
            : 'maternal care clients';
        results.textContent = `Showing ${visible} of ${total} ${label}`;
    }

    updateMaternalStats(root, rows);

    if (empty) {
        updateMaternalEmptyState(root, empty, visible);
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsMaternal(root) {
    const exportBtn = root.querySelector('[data-hr-mc-export]');

    const refresh = () => applyMaternalFilters(root);

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showMaternalToast(root, 'Export is not available.');
            return;
        }
        window.location.assign(exportUrl);
    });

    root.querySelector('[data-hr-mc-search]')?.addEventListener('input', refresh);
    root.querySelector('[data-hr-mc-zone]')?.addEventListener('change', refresh);
    root.querySelector('[data-hr-mc-barangay]')?.addEventListener('change', refresh);
    root.querySelector('[data-hr-mc-year]')?.addEventListener('change', refresh);
    root.querySelector('[data-hr-mc-month]')?.addEventListener('change', refresh);

    root.querySelector('[data-hr-mc-nr-add-record]:not([disabled])')?.addEventListener('click', () => {
        showMaternalToast(
            root,
            'Adding a non-resident pregnancy record is not available yet. This does not save into Resident Maternal Care.'
        );
    });

    refresh();
}

/**
 * Report Builder — same behavior as Environmental Health's, Household
 * Profiling's, and Death's: zone/year/month filters, a live preview built
 * as landscape "page" cards (a single field-header row, one row per
 * maternal care record), then a real PDF export reflecting the current
 * filters.
 */

/**
 * Column pages come straight from the server (HealthRecordsMaternal::
 * columnPages(), via the data-hrmc-report-column-pages script tag) rather
 * than being duplicated here — the full ~100-field column set never fits
 * one legible page width, so it's split into several column pages, each
 * repeating Name / Age / Birthday / Registered Date as anchor columns.
 * Reading the same computed structure the PDF uses guarantees this
 * preview always matches MaternalCarePdf's actual output.
 */
const HRMC_MONTH_LABELS = {
    '01': 'January', '02': 'February', '03': 'March', '04': 'April',
    '05': 'May', '06': 'June', '07': 'July', '08': 'August',
    '09': 'September', '10': 'October', '11': 'November', '12': 'December',
};

function hrmcParseJsonScript(root, selector) {
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

function hrmcReadReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hrmc-report-zone]')?.value || 'all'),
        year: String(reportRoot.querySelector('[data-hrmc-report-year]')?.value || 'all'),
        month: String(reportRoot.querySelector('[data-hrmc-report-month]')?.value || 'all'),
    };
}

function hrmcRowMatchesReport(row, options) {
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

function hrmcPeriodLabel(options) {
    if (options.year === 'all' || !options.year) {
        return 'All Time';
    }
    if (options.month !== 'all' && options.month) {
        return `${HRMC_MONTH_LABELS[options.month] || options.month} ${options.year}`;
    }

    return `Year ${options.year}`;
}

function hrmcBuildExportParams(options) {
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
 * then the individual field labels below — mirrors MaternalCarePdf's
 * grouped table header (and HouseholdProfilingMemberPdf's). `sections` is
 * one entry from HealthRecordsMaternal::columnPages() (already includes
 * the repeated anchor columns); `fields` is its flattened field list.
 */
function hrmcBuildDataTableHead(sections, fields) {
    const thead = document.createElement('thead');

    const groupRow = document.createElement('tr');
    groupRow.className = 'lml-hr-mc-report__group-row';
    sections.forEach((section) => {
        const th = document.createElement('th');
        th.scope = 'colgroup';
        th.colSpan = section.fields.length;
        th.textContent = section.title;
        groupRow.appendChild(th);
    });
    thead.appendChild(groupRow);

    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hr-mc-report__field-row';
    fields.forEach((field) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = field.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);

    return thead;
}

function hrmcBuildDataRow(fields, row) {
    const tr = document.createElement('tr');
    fields.forEach((field) => {
        const td = document.createElement('td');
        td.textContent = row[field.key] || '';
        tr.appendChild(td);
    });
    return tr;
}

function hrmcFlattenFields(sections) {
    return sections.flatMap((section) => section.fields);
}

function hrmcPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders —
 * one straight row per maternal care record, every field always present.
 */
function renderHrmcZonePages(host, zoneName, zoneRows, overallCount, periodLabel, sections, fields, groupTitle, partNumber, partCount) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hr-mc-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hr-mc-report__page';
        content = document.createElement('div');
        content.className = 'lml-hr-mc-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hr-mc-report__page-footer';
        footer.innerHTML = '<span class="lml-hr-mc-report__page-footer-zone"></span><span class="lml-hr-mc-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hr-mc-report__data-table';
        table.appendChild(hrmcBuildDataTableHead(sections, fields));
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    const partSuffix = ` · ${groupTitle}${partCount > 1 ? ` (PART ${partNumber} OF ${partCount})` : ''}`;

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hr-mc-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT${partSuffix}`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hr-mc-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hr-mc-report__stats-line';
        stats.textContent = `Overall Maternal Count : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hr-mc-report__stats-line lml-hr-mc-report__stats-line--period';
        period.textContent = periodLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) =>
        String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' })
    );

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrmcBuildDataRow(fields, row);
        tbody.appendChild(tr);

        if (hrmcPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hr-mc-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hr-mc-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hr-mc-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrmcReportPreview(reportRoot, rows, columnPages, options) {
    const host = reportRoot.querySelector('[data-hrmc-report-preview]');
    const empty = reportRoot.querySelector('[data-hrmc-report-empty]');
    const count = reportRoot.querySelector('[data-hrmc-report-count]');
    const meta = reportRoot.querySelector('[data-hrmc-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrmcRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const periodLabel = hrmcPeriodLabel(options);

    host.replaceChildren();

    columnPages.forEach((page) => {
        const fields = hrmcFlattenFields(page.sections);
        zoneNames.forEach((zoneName) => {
            renderHrmcZonePages(
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

    const params = hrmcBuildExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hrmc-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initMaternalReportBuilder(reportRoot) {
    const rows = hrmcParseJsonScript(reportRoot, '[data-hrmc-report-rows]') || [];
    const columnPages = hrmcParseJsonScript(reportRoot, '[data-hrmc-report-column-pages]') || [];

    const refresh = () => {
        renderHrmcReportPreview(reportRoot, rows, columnPages, hrmcReadReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hrmc-report-zone]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrmc-report-year]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrmc-report-month]')?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-mc]').forEach((root) => {
        initHealthRecordsMaternal(root);
    });
    document.querySelectorAll('[data-hrmc-report]').forEach((root) => {
        initMaternalReportBuilder(root);
    });
});

export { applyMaternalFilters, updateMaternalStats };

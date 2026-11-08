/**
 * Health Records → Child Care → Deworming monitoring summary.
 * Client filters hide recorded rows; empty copy distinguishes no records vs no matches.
 */

function showDewormingToast(root, message) {
    const toast = root.querySelector('[data-hr-dw-toast], [data-hr-dw-record-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showDewormingToast._timer);
    showDewormingToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function applyDewormingFilters(root) {
    const tbody = root.querySelector('[data-hr-dw-tbody]');
    const empty = root.querySelector('[data-hr-dw-empty]');
    const results = root.querySelector('[data-hr-dw-results]');
    const tableScroll = root.querySelector('.lml-hr-child-care__table-scroll--deworming');
    const searchInput = root.querySelector('[data-hr-dw-search]');
    const zoneSelect = root.querySelector('[data-hr-dw-zone]');
    const sexSelect = root.querySelector('[data-hr-dw-sex]');
    const statusSelect = root.querySelector('[data-hr-dw-status]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-dw-row]'));
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
        results.textContent = `Showing ${visible} of ${total} members`;
    }

    if (empty) {
        empty.hidden = visible > 0;
        const title = empty.querySelector('[data-hr-dw-empty-title]');
        const hint = empty.querySelector('[data-hr-dw-empty-hint]');
        if (rows.length === 0) {
            if (title) {
                title.textContent = 'No Deworming records found.';
            }
            if (hint) {
                hint.textContent = 'Child Care Deworming administrations recorded this year will appear here.';
            }
        } else {
            if (title) {
                title.textContent = 'No matching members';
            }
            if (hint) {
                hint.textContent = 'Try adjusting search or filters.';
            }
        }
    }

    if (tableScroll) {
        tableScroll.hidden = visible === 0;
    }
}

function initHealthRecordsDeworming(root) {
    const exportBtn = root.querySelector('[data-hr-dw-export]');
    const searchInput = root.querySelector('[data-hr-dw-search]');
    const zoneSelect = root.querySelector('[data-hr-dw-zone]');
    const sexSelect = root.querySelector('[data-hr-dw-sex]');
    const statusSelect = root.querySelector('[data-hr-dw-status]');

    const refresh = () => applyDewormingFilters(root);

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showDewormingToast(root, 'Export is not available.');
            return;
        }
        window.location.assign(exportUrl);
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    sexSelect?.addEventListener('change', refresh);
    statusSelect?.addEventListener('change', refresh);

    refresh();
}

function initDewormingRecordForm(root) {
    const form = root.querySelector('[data-hr-dw-deworming-form]');
    if (!form) {
        return;
    }

    const persistence = form.getAttribute('data-persistence') || 'preview';
    if (persistence === 'db') {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const message =
            form.getAttribute('data-hr-dw-preview-save') ||
            'Deworming record preview saved for this UI phase.';
        showDewormingToast(root, message);

        const returnUrl = form.getAttribute('data-hr-dw-return') || '';
        window.clearTimeout(initDewormingRecordForm._returnTimer);
        initDewormingRecordForm._returnTimer = window.setTimeout(() => {
            if (returnUrl) {
                window.location.assign(returnUrl);
            }
        }, 900);
    });
}

const HRDW_COLUMNS = [
    { key: 'full_name', label: 'Name' },
    { key: 'age_label', label: 'Age' },
    { key: 'july_round', label: 'July Round (Date)' },
    { key: 'january_round', label: 'January Round (Date)' },
];

function hrdwParseJsonScript(root, selector) {
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

function hrdwReadReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hrdw-report-zone]')?.value || 'all'),
        sex: String(reportRoot.querySelector('[data-hrdw-report-sex]')?.value || 'all'),
        status: String(reportRoot.querySelector('[data-hrdw-report-status]')?.value || 'all'),
    };
}

function hrdwRowMatchesReport(row, options) {
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

function hrdwBuildExportParams(options) {
    const params = new URLSearchParams();
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

function hrdwBuildDataTableHead() {
    const thead = document.createElement('thead');
    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hr-dw-report__field-row';
    HRDW_COLUMNS.forEach((column) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);
    return thead;
}

function hrdwBuildDataRow(row) {
    const tr = document.createElement('tr');
    HRDW_COLUMNS.forEach((column) => {
        const td = document.createElement('td');
        td.textContent = row[column.key] || '—';
        tr.appendChild(td);
    });
    return tr;
}

function hrdwPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders.
 */
function renderHrdwZonePages(host, zoneName, zoneRows, overallCount, scopeLabel) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hr-dw-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hr-dw-report__page';
        content = document.createElement('div');
        content.className = 'lml-hr-dw-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hr-dw-report__page-footer';
        footer.innerHTML = '<span class="lml-hr-dw-report__page-footer-zone"></span><span class="lml-hr-dw-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hr-dw-report__data-table';
        table.appendChild(hrdwBuildDataTableHead());
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hr-dw-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hr-dw-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hr-dw-report__stats-line';
        stats.textContent = `Overall Deworming Population : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hr-dw-report__stats-line lml-hr-dw-report__stats-line--period';
        period.textContent = scopeLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) =>
        String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' })
    );

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrdwBuildDataRow(row);
        tbody.appendChild(tr);

        if (hrdwPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hr-dw-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hr-dw-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hr-dw-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrdwReportPreview(reportRoot, rows, options) {
    const host = reportRoot.querySelector('[data-hrdw-report-preview]');
    const empty = reportRoot.querySelector('[data-hrdw-report-empty]');
    const count = reportRoot.querySelector('[data-hrdw-report-count]');
    const meta = reportRoot.querySelector('[data-hrdw-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrdwRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const scopeLabel = 'This Year';

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderHrdwZonePages(host, zoneName, byZone[zoneName], visibleRows.length, scopeLabel);
    });

    if (empty) {
        empty.hidden = visibleRows.length > 0;
    }
    if (count) {
        count.textContent = `${visibleRows.length} record${visibleRows.length === 1 ? '' : 's'} in preview`;
    }
    if (meta) {
        const zoneLabel = options.zone !== 'all' ? options.zone : 'All Zones';
        meta.textContent = `Scope: ${zoneLabel} · ${scopeLabel} · ${visibleRows.length} record(s)`;
    }

    const params = hrdwBuildExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hrdw-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initDewormingReportBuilder(reportRoot) {
    const rows = hrdwParseJsonScript(reportRoot, '[data-hrdw-report-rows]') || [];

    const refresh = () => {
        renderHrdwReportPreview(reportRoot, rows, hrdwReadReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hrdw-report-zone]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrdw-report-sex]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrdw-report-status]')?.addEventListener('change', refresh);

    refresh();
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-lml-hr-deworming]').forEach((root) => {
            initHealthRecordsDeworming(root);
        });

        document.querySelectorAll('[data-lml-hr-dw-record]').forEach((root) => {
            initDewormingRecordForm(root);
        });

        document.querySelectorAll('[data-hrdw-report]').forEach((root) => {
            initDewormingReportBuilder(root);
        });
    });
}

export { applyDewormingFilters, initHealthRecordsDeworming, initDewormingReportBuilder };

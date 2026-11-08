/**
 * Health Records → Child Care summary — client-side filters.
 */

function showToast(root, message) {
    const toast = root.querySelector('[data-hr-cc-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 3600);
}

function matchesAgeBand(ageMonths, band) {
    const months = Number(ageMonths);
    if (Number.isNaN(months)) {
        return true;
    }

    switch (band) {
        case '0-5':
            return months >= 0 && months <= 5;
        case '6-11':
            return months >= 6 && months <= 11;
        case '12-23':
            return months >= 12 && months <= 23;
        case '24-59':
            return months >= 24 && months <= 59;
        case '5-9':
            return months >= 60 && months <= 119;
        case '10-14':
            return months >= 120 && months <= 179;
        case '15-18':
            return months >= 180 && months <= 227;
        case '0-11m':
            return months >= 0 && months <= 11;
        case '1-4':
            return months >= 12 && months <= 59;
        default:
            return true;
    }
}

function parseAgeYearsInput(raw, maxAgeYears) {
    const value = String(raw ?? '').trim();
    if (value === '') {
        return null;
    }

    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return null;
    }

    return Math.min(maxAgeYears, Math.max(0, Math.trunc(parsed)));
}

function matchesAgeYears(ageYears, minYears, maxYears) {
    const years = Number(ageYears);
    if (Number.isNaN(years)) {
        return minYears === null && maxYears === null;
    }

    if (minYears !== null && years < minYears) {
        return false;
    }

    if (maxYears !== null && years > maxYears) {
        return false;
    }

    return true;
}

function syncCustomAgeVisibility(root) {
    const ageSelect = root.querySelector('[data-hr-cc-age]');
    const customWrap = root.querySelector('[data-hr-cc-age-custom]');
    const ageMinInput = root.querySelector('[data-hr-cc-age-min]');
    const ageMaxInput = root.querySelector('[data-hr-cc-age-max]');
    const isCustom = (ageSelect?.value || 'all') === 'custom';

    if (customWrap) {
        customWrap.hidden = !isCustom;
    }

    if (ageMinInput) {
        ageMinInput.disabled = !isCustom;
        if (!isCustom) {
            ageMinInput.value = '';
        }
    }

    if (ageMaxInput) {
        ageMaxInput.disabled = !isCustom;
        if (!isCustom) {
            ageMaxInput.value = '';
        }
    }
}

function updateChildCareEmptyState(root, empty, visible) {
    if (!empty) {
        return;
    }

    const hasRecords = root.dataset.hasRecords === '1';
    const noRecordsTitle = empty.querySelector('[data-hr-cc-empty-no-records]');
    const filteredTitle = empty.querySelector('[data-hr-cc-empty-filtered]');
    const hint = empty.querySelector('[data-hr-cc-empty-hint]');
    const icon = empty.querySelector('.lml-hr-child-care__empty-icon i');

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

function updateChildCareStats(root, rows) {
    let total = 0;
    let female = 0;
    let male = 0;

    rows.forEach((row) => {
        if (row.hidden) {
            return;
        }

        total += 1;
        const sex = (row.dataset.sex || '').toLowerCase();
        if (sex === 'female') {
            female += 1;
        } else if (sex === 'male') {
            male += 1;
        }
    });

    const totalEl = root.querySelector('[data-stat="total"]');
    const femaleEl = root.querySelector('[data-stat="female"]');
    const maleEl = root.querySelector('[data-stat="male"]');

    if (totalEl) {
        totalEl.textContent = String(total);
    }
    if (femaleEl) {
        femaleEl.textContent = String(female);
    }
    if (maleEl) {
        maleEl.textContent = String(male);
    }
}

function applyFilters(root) {
    const tbody = root.querySelector('[data-hr-cc-tbody]');
    const empty = root.querySelector('[data-hr-cc-empty]');
    const results = root.querySelector('[data-hr-cc-results]');
    const tableScroll = root.querySelector('.lml-hr-child-care__table-scroll');
    const searchInput = root.querySelector('[data-hr-cc-search]');
    const zoneSelect = root.querySelector('[data-hr-cc-zone]');
    const ageSelect = root.querySelector('[data-hr-cc-age]');
    const ageMinInput = root.querySelector('[data-hr-cc-age-min]');
    const ageMaxInput = root.querySelector('[data-hr-cc-age-max]');
    const sexSelect = root.querySelector('[data-hr-cc-sex]');

    if (!tbody) {
        return;
    }

    syncCustomAgeVisibility(root);

    const rows = Array.from(tbody.querySelectorAll('[data-hr-cc-row]'));
    const total = Number(root.dataset.total || rows.length);
    const maxAgeYears = Number(root.dataset.maxAgeYears || 18);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const ageBand = ageSelect?.value || 'all';
    const sex = sexSelect?.value || 'all';

    let minYears = null;
    let maxYears = null;
    if (ageBand === 'custom') {
        minYears = parseAgeYearsInput(ageMinInput?.value, maxAgeYears);
        maxYears = parseAgeYearsInput(ageMaxInput?.value, maxAgeYears);

        if (minYears !== null && maxYears !== null && minYears > maxYears) {
            const swapped = minYears;
            minYears = maxYears;
            maxYears = swapped;
        }
    }

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowSex = row.dataset.sex || '';
        const ageMonths = row.dataset.ageMonths || '';
        const ageYears = row.dataset.ageYears || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesSex = sex === 'all' || rowSex === sex;
        const matchesAge = ageBand === 'custom'
            ? matchesAgeYears(ageYears, minYears, maxYears)
            : matchesAgeBand(ageMonths, ageBand);
        const show = matchesSearch && matchesZone && matchesSex && matchesAge;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} residents`;
    }

    updateChildCareStats(root, rows);

    if (empty) {
        updateChildCareEmptyState(root, empty, visible);
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsChildCare(root) {
    const exportBtn = root.querySelector('[data-hr-cc-export]');
    const searchInput = root.querySelector('[data-hr-cc-search]');
    const zoneSelect = root.querySelector('[data-hr-cc-zone]');
    const ageSelect = root.querySelector('[data-hr-cc-age]');
    const ageMinInput = root.querySelector('[data-hr-cc-age-min]');
    const ageMaxInput = root.querySelector('[data-hr-cc-age-max]');
    const sexSelect = root.querySelector('[data-hr-cc-sex]');

    const refresh = () => applyFilters(root);

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.getAttribute('data-export-url') || '';
        if (!exportUrl) {
            showToast(root, 'Export is not available.');
            return;
        }
        window.location.assign(exportUrl);
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    ageSelect?.addEventListener('change', refresh);
    ageMinInput?.addEventListener('input', refresh);
    ageMaxInput?.addEventListener('input', refresh);
    sexSelect?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-child-care]').forEach((root) => {
        initHealthRecordsChildCare(root);
    });
    document.querySelectorAll('[data-hrcc-report]').forEach((root) => {
        initChildCareReportBuilder(root);
    });
});

const HRCC_COLUMNS = [
    { key: 'full_name', label: 'Name' },
    { key: 'age_label', label: 'Age' },
    { key: 'birthday', label: 'Birthday' },
    { key: 'sex', label: 'Sex' },
];

const HRCC_AGE_BAND_LABELS = {
    all: 'All Ages',
    '0-5': '0–5 months',
    '6-11': '6–11 months',
    '12-23': '12–23 months',
    '24-59': '24–59 months',
    '5-9': '5–9 years',
    '10-14': '10–14 years',
    '15-18': '15–18 years',
};

function hrccParseJsonScript(root, selector) {
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

function hrccReadReportOptions(reportRoot) {
    const maxAgeYears = Number(reportRoot.dataset.maxAgeYears || 18);
    const ageBand = String(reportRoot.querySelector('[data-hrcc-report-age]')?.value || 'all');

    let ageMin = null;
    let ageMax = null;
    if (ageBand === 'custom') {
        ageMin = parseAgeYearsInput(reportRoot.querySelector('[data-hrcc-report-age-min]')?.value, maxAgeYears);
        ageMax = parseAgeYearsInput(reportRoot.querySelector('[data-hrcc-report-age-max]')?.value, maxAgeYears);

        if (ageMin !== null && ageMax !== null && ageMin > ageMax) {
            const swapped = ageMin;
            ageMin = ageMax;
            ageMax = swapped;
        }
    }

    return {
        zone: String(reportRoot.querySelector('[data-hrcc-report-zone]')?.value || 'all'),
        age: ageBand,
        ageMin,
        ageMax,
        sex: String(reportRoot.querySelector('[data-hrcc-report-sex]')?.value || 'all'),
    };
}

function hrccRowMatchesReport(row, options) {
    if (options.zone !== 'all' && String(row.zone || '') !== options.zone) {
        return false;
    }
    if (options.sex !== 'all' && String(row.sex_normalized || '') !== options.sex) {
        return false;
    }
    if (options.age === 'custom') {
        if (!matchesAgeYears(row.age_years, options.ageMin, options.ageMax)) {
            return false;
        }
    } else if (options.age !== 'all' && !matchesAgeBand(row.age_months, options.age)) {
        return false;
    }

    return true;
}

function hrccAgeFilterLabel(options) {
    if (options.age === 'custom') {
        if (options.ageMin !== null && options.ageMax !== null) {
            return `Custom ${options.ageMin}–${options.ageMax} yrs`;
        }
        if (options.ageMin !== null) {
            return `Custom ${options.ageMin}+ yrs`;
        }
        if (options.ageMax !== null) {
            return `Custom up to ${options.ageMax} yrs`;
        }
        return 'Custom';
    }

    return HRCC_AGE_BAND_LABELS[options.age] || 'All Ages';
}

function hrccSexFilterLabel(options) {
    if (options.sex === 'female') {
        return 'Female';
    }
    if (options.sex === 'male') {
        return 'Male';
    }
    return 'All Sexes';
}

function hrccScopeLabel(options) {
    return `${hrccAgeFilterLabel(options)} · ${hrccSexFilterLabel(options)}`;
}

function hrccBuildExportParams(options) {
    const params = new URLSearchParams();
    if (options.zone && options.zone !== 'all') {
        params.set('zone', options.zone);
    }
    if (options.age && options.age !== 'all') {
        params.set('age', options.age);
    }
    if (options.age === 'custom') {
        if (options.ageMin !== null) {
            params.set('age_min', String(options.ageMin));
        }
        if (options.ageMax !== null) {
            params.set('age_max', String(options.ageMax));
        }
    }
    if (options.sex && options.sex !== 'all') {
        params.set('sex', options.sex);
    }
    return params;
}

function hrccBuildDataTableHead() {
    const thead = document.createElement('thead');
    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hr-cc-report__field-row';
    HRCC_COLUMNS.forEach((column) => {
        const th = document.createElement('th');
        th.scope = 'col';
        th.textContent = column.label;
        fieldRow.appendChild(th);
    });
    thead.appendChild(fieldRow);
    return thead;
}

function hrccBuildDataRow(row) {
    const tr = document.createElement('tr');
    HRCC_COLUMNS.forEach((column) => {
        const td = document.createElement('td');
        td.textContent = row[column.key] || '—';
        tr.appendChild(td);
    });
    return tr;
}

function hrccPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as the other Report Builders.
 */
function renderHrccZonePages(host, zoneName, zoneRows, overallCount, scopeLabel) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hr-cc-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hr-cc-report__page';
        content = document.createElement('div');
        content.className = 'lml-hr-cc-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hr-cc-report__page-footer';
        footer.innerHTML = '<span class="lml-hr-cc-report__page-footer-zone"></span><span class="lml-hr-cc-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hr-cc-report__data-table';
        table.appendChild(hrccBuildDataTableHead());
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hr-cc-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hr-cc-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const stats = document.createElement('p');
        stats.className = 'lml-hr-cc-report__stats-line';
        stats.textContent = `Overall Child Care Population : ${overallCount}`;
        const period = document.createElement('p');
        period.className = 'lml-hr-cc-report__stats-line lml-hr-cc-report__stats-line--period';
        period.textContent = scopeLabel;
        c.appendChild(stats);
        c.appendChild(period);
    });

    const orderedRows = zoneRows.slice().sort((a, b) =>
        String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' })
    );

    orderedRows.forEach((row) => {
        const name = row.full_name || '—';

        const tr = hrccBuildDataRow(row);
        tbody.appendChild(tr);

        if (hrccPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hr-cc-report__continuation-strip';
                strip.textContent = `${zoneName} · ${name} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hr-cc-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hr-cc-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHrccReportPreview(reportRoot, rows, options) {
    const host = reportRoot.querySelector('[data-hrcc-report-preview]');
    const empty = reportRoot.querySelector('[data-hrcc-report-empty]');
    const count = reportRoot.querySelector('[data-hrcc-report-count]');
    const meta = reportRoot.querySelector('[data-hrcc-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => hrccRowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const scopeLabel = hrccScopeLabel(options);

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderHrccZonePages(host, zoneName, byZone[zoneName], visibleRows.length, scopeLabel);
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

    const params = hrccBuildExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hrcc-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initChildCareReportBuilder(reportRoot) {
    const rows = hrccParseJsonScript(reportRoot, '[data-hrcc-report-rows]') || [];

    const syncAgeCustom = () => {
        const ageSelect = reportRoot.querySelector('[data-hrcc-report-age]');
        const customWrap = reportRoot.querySelector('[data-hrcc-report-age-custom]');
        const minInput = reportRoot.querySelector('[data-hrcc-report-age-min]');
        const maxInput = reportRoot.querySelector('[data-hrcc-report-age-max]');
        const isCustom = (ageSelect?.value || 'all') === 'custom';

        if (customWrap) {
            customWrap.hidden = !isCustom;
        }
        if (minInput) {
            minInput.disabled = !isCustom;
        }
        if (maxInput) {
            maxInput.disabled = !isCustom;
        }
    };

    const refresh = () => {
        syncAgeCustom();
        renderHrccReportPreview(reportRoot, rows, hrccReadReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hrcc-report-zone]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrcc-report-age]')?.addEventListener('change', refresh);
    reportRoot.querySelector('[data-hrcc-report-age-min]')?.addEventListener('input', refresh);
    reportRoot.querySelector('[data-hrcc-report-age-max]')?.addEventListener('input', refresh);
    reportRoot.querySelector('[data-hrcc-report-sex]')?.addEventListener('change', refresh);

    refresh();
}

export {
    applyFilters,
    updateChildCareStats,
    matchesAgeBand,
    matchesAgeYears,
    parseAgeYearsInput,
    syncCustomAgeVisibility,
};

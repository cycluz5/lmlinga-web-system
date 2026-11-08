/**
 * Environmental Health Dashboard filters + Report Builder (sectioned preview).
 */

import { mergePendingLocalHouseholdsIntoEhList } from '../offline/offline-local-household.js';
import { readPageActorId } from '../offline/offline-nav-guard.js';

function readFilters(root) {
    const form = root.querySelector('[data-eh-filters]');
    if (!form) {
        return {};
    }

    const data = new FormData(form);
    return {
        household_no: String(data.get('household_no') || '').trim().toLowerCase(),
        zone: String(data.get('zone') || 'all'),
        street: String(data.get('street') || 'all'),
    };
}

function rowMatches(row, filters) {
    const hh = (row.dataset.householdNo || '').toLowerCase();

    if (filters.household_no && !hh.includes(filters.household_no)) {
        return false;
    }
    if (filters.zone !== 'all' && (row.dataset.zone || '') !== filters.zone) {
        return false;
    }
    if (filters.street !== 'all' && (row.dataset.street || '') !== filters.street) {
        return false;
    }

    return true;
}

function setStat(root, key, value) {
    const el = root.querySelector(`[data-stat="${key}"]`);
    if (el) {
        el.textContent = String(value);
    }
}

function recalculateStats(root, visibleRows) {
    const water = { level_i: 0, level_ii: 0, level_iii: 0, others: 0 };
    const sanitation = { sanitary: 0, unsanitary: 0, pending: 0 };
    const presence = { with_toilet: 0, without_toilet: 0, unknown: 0 };
    let completed = 0;
    let pending = 0;
    let validated = 0;
    let waste = 0;

    visibleRows.forEach((row) => {
        const level = row.dataset.waterSupply || '';
        if (Object.prototype.hasOwnProperty.call(water, level)) {
            water[level] += 1;
        }

        const toilet = row.dataset.toiletStatus || 'not_yet_determined';
        if (toilet === 'sanitary') {
            sanitation.sanitary += 1;
        } else if (toilet === 'unsanitary') {
            sanitation.unsanitary += 1;
        } else {
            sanitation.pending += 1;
        }

        const toiletPresence = row.dataset.toiletPresence || 'unknown';
        if (Object.prototype.hasOwnProperty.call(presence, toiletPresence)) {
            presence[toiletPresence] += 1;
        } else {
            presence.unknown += 1;
        }

        if ((row.dataset.recordStatus || '') === 'completed') {
            completed += 1;
        } else {
            pending += 1;
        }

        if ((row.dataset.solidWaste || '') === 'good_practice') {
            waste += 1;
        }
    });

    setStat(root, 'water-level_i', water.level_i);
    setStat(root, 'water-level_ii', water.level_ii);
    setStat(root, 'water-level_iii', water.level_iii);
    setStat(root, 'water-others', water.others);
    setStat(root, 'sanitation-with', presence.with_toilet);
    setStat(root, 'sanitation-without', presence.without_toilet);
    setStat(root, 'sanitation-sanitary', sanitation.sanitary);
    setStat(root, 'sanitation-unsanitary', sanitation.unsanitary);
    setStat(root, 'sanitation-pending', sanitation.pending);
    setStat(root, 'overview-total', visibleRows.length);
    setStat(root, 'overview-completed', completed);
    setStat(root, 'overview-pending', pending);
    setStat(root, 'overview-validated', validated);
    setStat(root, 'overview-waste', waste);
}

function applyFilters(root) {
    const tbody = root.querySelector('[data-eh-tbody]');
    const empty = root.querySelector('[data-eh-empty]');
    const results = root.querySelector('[data-eh-results]');
    const tableScroll = root.querySelector('.lml-eh-dashboard__table-scroll');
    const total = Number(root.dataset.total || 0);

    if (!tbody) {
        return;
    }

    const filters = readFilters(root);
    const rows = Array.from(tbody.querySelectorAll('[data-eh-row]'));
    const visible = [];

    rows.forEach((row) => {
        const show = rowMatches(row, filters);
        row.hidden = !show;
        if (show) {
            visible.push(row);
        }
    });

    recalculateStats(root, visible);

    if (results) {
        results.textContent = `Showing ${visible.length} of ${total} household amenities records`;
    }

    if (empty) {
        empty.hidden = visible.length > 0;
    }

    if (tableScroll) {
        tableScroll.hidden = visible.length === 0;
    }

    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value && value !== 'all' && value !== '') {
            params.set(key, value);
        }
    });
    const query = params.toString();
    const nextUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname;
    window.history.replaceState({}, '', nextUrl);
}

function parseJsonScript(root, selector) {
    const node = root.querySelector(selector);
    if (!node) {
        return null;
    }
    try {
        return JSON.parse(node.textContent || 'null');
    } catch (error) {
        return null;
    }
}

function monthLabel(value) {
    const labels = {
        '01': 'January', '02': 'February', '03': 'March', '04': 'April',
        '05': 'May', '06': 'June', '07': 'July', '08': 'August',
        '09': 'September', '10': 'October', '11': 'November', '12': 'December',
    };
    return labels[value] || value;
}

function readReportOptions(reportRoot) {
    const scope = String(reportRoot.querySelector('[data-eh-report-scope]')?.value || 'all_zones');
    const zone = String(reportRoot.querySelector('[data-eh-report-zone]')?.value || 'all');
    const periodMode = String(reportRoot.querySelector('[data-eh-report-period-mode]')?.value || 'all');
    const year = String(reportRoot.querySelector('[data-eh-report-year]')?.value || '');
    const month = String(reportRoot.querySelector('[data-eh-report-month]')?.value || '');
    const banner = String(reportRoot.querySelector('[data-eh-report-banner]')?.value || '').trim();

    return {
        scope,
        zone,
        period_mode: periodMode,
        year,
        month,
        program_banner: banner,
    };
}

function syncReportFilterVisibility(reportRoot, options, periods) {
    const zoneWrap = reportRoot.querySelector('[data-eh-report-zone-wrap]');
    const yearWrap = reportRoot.querySelector('[data-eh-report-year-wrap]');
    const monthWrap = reportRoot.querySelector('[data-eh-report-month-wrap]');
    const monthSelect = reportRoot.querySelector('[data-eh-report-month]');

    if (zoneWrap) {
        zoneWrap.hidden = options.scope !== 'zone';
    }
    if (yearWrap) {
        yearWrap.hidden = options.period_mode !== 'year' && options.period_mode !== 'month';
    }
    if (monthWrap) {
        monthWrap.hidden = options.period_mode !== 'month';
    }

    if (monthSelect && options.period_mode === 'month') {
        const months = (periods?.months_by_year && periods.months_by_year[options.year]) || [];
        const current = options.month;
        monthSelect.replaceChildren();
        if (months.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No survey months available';
            monthSelect.appendChild(opt);
        } else {
            months.forEach((value) => {
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = monthLabel(value);
                if (value === current) {
                    opt.selected = true;
                }
                monthSelect.appendChild(opt);
            });
            if (!months.includes(current) && months[0]) {
                monthSelect.value = months[0];
            }
        }
    }
}

function rowMatchesReport(row, options) {
    if (options.scope === 'zone') {
        if (!options.zone || options.zone === 'all') {
            return false;
        }
        if ((row._zone || '') !== options.zone) {
            return false;
        }
    }

    const date = String(row._date_surveyed || '');
    if (options.period_mode === 'year') {
        if (!options.year || !date.startsWith(`${options.year}-`)) {
            return false;
        }
    } else if (options.period_mode === 'month') {
        if (!options.year || !options.month || !date.startsWith(`${options.year}-${options.month}-`)) {
            return false;
        }
    }

    return true;
}

function buildExportParams(options) {
    const params = new URLSearchParams();
    params.set('scope', options.scope === 'zone' ? 'zone' : 'all_zones');
    if (options.scope === 'zone' && options.zone && options.zone !== 'all') {
        params.set('zone', options.zone);
    }
    params.set('period_mode', options.period_mode || 'all');
    if (options.period_mode === 'year' || options.period_mode === 'month') {
        if (options.year) {
            params.set('year', options.year);
        }
    }
    if (options.period_mode === 'month' && options.month) {
        params.set('month', options.month);
    }
    if (options.program_banner) {
        params.set('program_banner', options.program_banner);
    }
    return params;
}

function scopeLabel(options) {
    if (options.scope === 'zone' && options.zone && options.zone !== 'all') {
        return options.zone;
    }
    return 'All Zones';
}

function periodLabel(options) {
    if (options.period_mode === 'year' && options.year) {
        return `Year ${options.year}`;
    }
    if (options.period_mode === 'month' && options.year && options.month) {
        return `${monthLabel(options.month)} ${options.year}`;
    }
    return 'All Time';
}

function buildDataTableHead(sections) {
    const thead = document.createElement('thead');

    const groupRow = document.createElement('tr');
    groupRow.className = 'lml-eh-report__group-row';
    (sections || []).forEach((section) => {
        const th = document.createElement('th');
        th.colSpan = (section.fields || []).length;
        th.textContent = section.title;
        groupRow.appendChild(th);
    });
    thead.appendChild(groupRow);

    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-eh-report__field-row';
    (sections || []).forEach((section) => {
        (section.fields || []).forEach((field) => {
            const th = document.createElement('th');
            th.scope = 'col';
            th.textContent = field.label;
            fieldRow.appendChild(th);
        });
    });
    thead.appendChild(fieldRow);

    return thead;
}

function buildDataRow(sections, row) {
    const tr = document.createElement('tr');
    (sections || []).forEach((section) => {
        (section.fields || []).forEach((field) => {
            const td = document.createElement('td');
            // previewPayload stores values under field.key
            td.textContent = row[field.key] || '—';
            tr.appendChild(td);
        });
    });
    return tr;
}

function pageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, each
 * holding one merged table (group header row + field header row, one row
 * per household — the header repeats on every page). Rows fill a page
 * until the next one would overflow; the table then continues on a new
 * page behind a small strip identifying the zone and the household the
 * table resumes at, and every page gets a "Page n/total" footer once the
 * zone's page count is known.
 *
 * `host` must already be attached to the document: overflow is measured
 * via clientHeight/scrollHeight, which are 0 on a detached subtree, so
 * the zone wrapper is appended to `host` before any page is built rather
 * than built off-DOM and appended once finished.
 */
function renderZonePages(host, zoneName, zoneRows, sections) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-eh-report__zone';
    host.appendChild(zoneWrap);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-eh-report__page';
        content = document.createElement('div');
        content.className = 'lml-eh-report__page-content';
        // Inline (not stylesheet-driven) so the fixed page height that
        // overflow measurement depends on is guaranteed to apply the
        // instant this element exists — no race with the external
        // stylesheet still loading. Narrow viewports keep the CSS
        // fallback (height:auto, no pagination) from the file's own
        // max-width:640px rule.
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-eh-report__page-footer';
        footer.innerHTML = '<span class="lml-eh-report__page-footer-zone"></span><span class="lml-eh-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const hint = document.createElement('p');
        hint.className = 'lml-eh-report__page-hint';
        hint.textContent = 'Scroll to see all fields — every column here is included in the exported file.';
        content.appendChild(hint);

        const table = document.createElement('table');
        table.className = 'lml-eh-report__data-table';
        table.appendChild(buildDataTableHead(sections));
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-eh-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-eh-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);
    });

    zoneRows.forEach((row) => {
        const hhNo = row.household_no || '—';
        const head = row.house_head || '—';

        const tr = buildDataRow(sections, row);
        tbody.appendChild(tr);

        if (pageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-eh-report__continuation-strip';
                strip.textContent = `${zoneName} · HH No. ${hhNo} · ${head} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-eh-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-eh-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });
}

function renderZoneTablePreview(reportRoot, rows, sections, options) {
    const host = reportRoot.querySelector('[data-eh-report-preview]');
    const empty = reportRoot.querySelector('[data-eh-report-empty]');
    const count = reportRoot.querySelector('[data-eh-report-count]');
    const meta = reportRoot.querySelector('[data-eh-report-preview-meta]');
    const bannerPreview = reportRoot.querySelector('[data-eh-report-preview-banner]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => rowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || row._zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    Object.values(byZone).forEach((zoneRows) => {
        zoneRows.sort((a, b) => String(a.house_head || '').localeCompare(String(b.house_head || ''), undefined, { sensitivity: 'base' }));
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderZonePages(host, zoneName, byZone[zoneName], sections || []);
    });

    if (empty) {
        empty.hidden = visibleRows.length > 0;
    }
    if (count) {
        count.textContent = `${visibleRows.length} household${visibleRows.length === 1 ? '' : 's'} in preview`;
    }
    if (meta) {
        meta.textContent = `Scope: ${scopeLabel(options)} · Period: ${periodLabel(options)} · ${visibleRows.length} household record(s)`;
    }
    if (bannerPreview && options.program_banner) {
        bannerPreview.textContent = options.program_banner;
    }

    const zoneScopeIncomplete = options.scope === 'zone' && (!options.zone || options.zone === 'all');
    const params = buildExportParams(options);
    reportRoot.querySelectorAll('[data-eh-export]').forEach((link) => {
        const format = link.getAttribute('data-eh-export') || 'pdf';
        if (zoneScopeIncomplete) {
            link.setAttribute('href', '#');
            link.setAttribute('aria-disabled', 'true');
            link.classList.add('is-disabled');
            return;
        }
        link.removeAttribute('aria-disabled');
        link.classList.remove('is-disabled');
        const next = new URLSearchParams(params);
        next.set('format', format);
        if (exportBase) {
            link.setAttribute('href', `${exportBase}?${next.toString()}`);
        }
    });
}

function initReportBuilder(reportRoot) {
    const rows = parseJsonScript(reportRoot, '[data-eh-report-rows]') || [];
    const sections = parseJsonScript(reportRoot, '[data-eh-report-sections]') || [];
    const periods = parseJsonScript(reportRoot, '[data-eh-report-periods]') || { years: [], months_by_year: {} };

    const refresh = () => {
        const options = readReportOptions(reportRoot);
        syncReportFilterVisibility(reportRoot, options, periods);
        const nextOptions = readReportOptions(reportRoot);
        renderZoneTablePreview(reportRoot, rows, sections, nextOptions);
    };

    reportRoot.querySelectorAll('select, input[type="text"]').forEach((el) => {
        const eventName = el.matches('input[type="text"]') ? 'input' : 'change';
        el.addEventListener(eventName, refresh);
    });

    refresh();
}

function initDashboard(root) {
    const form = root.querySelector('[data-eh-filters]');
    let debounceTimer = null;

    const run = () => applyFilters(root);

    void mergePendingLocalHouseholdsIntoEhList(root, {
        actorId: readPageActorId(root),
        document: typeof document !== 'undefined' ? document : null,
    }).then(run).catch(run);

    form?.querySelectorAll('select[data-eh-filter]').forEach((el) => {
        el.addEventListener('change', run);
    });

    form?.querySelectorAll('input[data-eh-filter]').forEach((el) => {
        el.addEventListener('input', () => {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(run, 160);
        });
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        run();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-eh-dashboard]').forEach((root) => {
        initDashboard(root);
    });
    document.querySelectorAll('[data-eh-report]').forEach((root) => {
        initReportBuilder(root);
    });
});

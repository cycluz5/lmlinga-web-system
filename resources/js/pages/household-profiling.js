/**
 * Household Profiling — list UI interactions, plus the Report Builder page
 * (choices before export: zone/street/search filters, a live preview, then
 * a real PDF download — same pattern as Environmental Health's).
 * Demo-only Add keeps a preview toast; DB Add uses a real create link.
 * DB Delete opens the confirm dialog and submits a Laravel DELETE form (R02-A).
 */

import {
    HH_NAV_KINDS,
    HH_NAV_SELECTOR,
    handleHouseholdNavClick,
    hasCachedHouseholdNav,
    isHouseholdViewPath,
    readPageActorId,
} from '../offline/offline-nav-guard.js';
import { mergePendingLocalHouseholdsIntoList } from '../offline/offline-local-household.js';

export { isHouseholdViewPath, readPageActorId };

/**
 * Visible HH No. on the Household Profiling list. Strips a leading HH- only.
 * Stored household_no, routes, and IndexedDB keys stay unchanged.
 * Never Number()/parseInt() — "001" must remain "001".
 */
export function displayHouseholdNo(value) {
    const raw = String(value ?? '').trim();
    if (!raw) {
        return '';
    }
    return raw.replace(/^HH-/i, '');
}

export function applyListHouseholdNoDisplay(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }
    root.querySelectorAll('[data-hh-row]').forEach((row) => {
        const stored = row.getAttribute?.('data-household-no') || '';
        const cell = row.querySelector?.('.lml-hh-profiling__hh-no');
        if (cell) {
            cell.textContent = displayHouseholdNo(stored);
        }
    });
}

export async function hasCachedHouseholdView(href, options = {}) {
    return hasCachedHouseholdNav(href, { ...options, kind: HH_NAV_KINDS.VIEW_HOUSEHOLD });
}

export async function handleHouseholdViewClick(event, options = {}) {
    return handleHouseholdNavClick(event, { ...options, kind: HH_NAV_KINDS.VIEW_HOUSEHOLD });
}

function showToast(root, message) {
    const toast = root.querySelector('[data-hh-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    const timers = typeof window !== 'undefined' ? window : globalThis;
    if (typeof timers.clearTimeout === 'function') {
        timers.clearTimeout(showToast._timer);
    }
    if (typeof timers.setTimeout === 'function') {
        showToast._timer = timers.setTimeout(() => {
            toast.hidden = true;
            toast.textContent = '';
        }, 3600);
    }
}

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function setStat(root, key, value) {
    const el = root.querySelector(`[data-stat="${key}"]`);
    if (el) {
        el.textContent = String(value);
    }
}

function recalculateSummaryStats(root, visibleRows) {
    let respondents = 0;
    let male = 0;
    let female = 0;

    visibleRows.forEach((row) => {
        respondents += Number(row.members || 0);
        male += Number(row.male || 0);
        female += Number(row.female || 0);
    });

    setStat(root, 'households', visibleRows.length);
    setStat(root, 'respondents', respondents);
    setStat(root, 'male', male);
    setStat(root, 'female', female);
}

export function applyFilters(root) {
    const tbody = root.querySelector('[data-hh-tbody]');
    const empty = root.querySelector('[data-hh-empty]');
    const results = root.querySelector('[data-hh-results]');
    const tableScroll = root.querySelector('.lml-hh-profiling__table-scroll');
    const searchInput = root.querySelector('[data-hh-search]');
    const zoneSelect = root.querySelector('[data-hh-zone]');
    const streetSelect = root.querySelector('[data-hh-street]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hh-row]'));
    const total = Number(
        (root.dataset && root.dataset.total) || root.getAttribute?.('data-total') || rows.length
    );
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const street = streetSelect?.value || 'all';

    const visibleRows = [];

    rows.forEach((row) => {
        const houseHead = (
            (row.dataset && row.dataset.houseHead) || row.getAttribute?.('data-house-head') || ''
        ).toLowerCase();
        const householdNo = (
            (row.dataset && row.dataset.householdNo) || row.getAttribute?.('data-household-no') || ''
        ).toLowerCase();
        const rowZone = (row.dataset && row.dataset.zone) || row.getAttribute?.('data-zone') || '';
        const rowStreet = (row.dataset && row.dataset.street) || row.getAttribute?.('data-street') || '';
        const members = Number((row.dataset && row.dataset.members) || row.getAttribute?.('data-members') || 0);
        const male = Number((row.dataset && row.dataset.male) || row.getAttribute?.('data-male') || 0);
        const female = Number((row.dataset && row.dataset.female) || row.getAttribute?.('data-female') || 0);

        const matchesSearch = !query
            || houseHead.includes(query)
            || householdNo.includes(query)
            || householdNo.replace(/^hh-/, '').includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesStreet = street === 'all' || rowStreet === street;
        const show = matchesSearch && matchesZone && matchesStreet;

        row.hidden = !show;
        if (show) {
            visibleRows.push({ members, male, female });
        }
    });

    recalculateSummaryStats(root, visibleRows);

    if (results) {
        results.textContent = `Showing ${visibleRows.length} of ${total} households`;
    }

    if (empty) {
        empty.hidden = visibleRows.length > 0;
    }

    if (tableScroll) {
        tableScroll.hidden = visibleRows.length === 0;
    }
}

function lockPageScroll() {
    document.body.dataset.hhScrollLocked = 'true';
    document.body.style.overflow = 'hidden';
}

function unlockPageScroll() {
    if (document.body.dataset.hhScrollLocked !== 'true') {
        return;
    }

    delete document.body.dataset.hhScrollLocked;
    document.body.style.overflow = '';
}

function destroyUrlFor(root, householdNo) {
    const template = root.dataset.hhDestroyTemplate || '';
    if (!template || !householdNo) {
        return '';
    }

    return template.replace('__HH__', encodeURIComponent(householdNo));
}

function openDialog(root, householdNo, returnFocusEl, householdKey = '') {
    const backdrop = root.querySelector('[data-hh-dialog]');
    const panel = root.querySelector('[data-hh-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-dialog-cancel]');
    const form = root.querySelector('[data-hh-delete-form]');
    if (!backdrop || !panel) {
        return;
    }

    const action = destroyUrlFor(root, householdKey || householdNo);
    if (!action || !form) {
        return;
    }

    form.setAttribute('action', action);
    root._hhDeleteTarget = householdNo;
    root._hhReturnFocus = returnFocusEl || null;

    backdrop.hidden = false;
    lockPageScroll();

    // Cancel is the default focus target when the dialog opens.
    if (cancelBtn) {
        cancelBtn.focus();
    } else {
        panel.focus();
    }
}

function closeDialog(root, { restoreFocus = true } = {}) {
    const backdrop = root.querySelector('[data-hh-dialog]');
    const form = root.querySelector('[data-hh-delete-form]');
    if (!backdrop) {
        return;
    }

    backdrop.hidden = true;
    unlockPageScroll();
    root._hhDeleteTarget = null;
    if (form) {
        form.setAttribute('action', '#');
    }

    if (restoreFocus && root._hhReturnFocus instanceof HTMLElement) {
        root._hhReturnFocus.focus();
    }

    root._hhReturnFocus = null;
}

function trapFocus(event, panel) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusable(panel);
    if (!focusables.length) {
        event.preventDefault();
        panel.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
        return;
    }

    if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function initHouseholdProfiling(root) {
    applyListHouseholdNoDisplay(root);

    const exportBtn = root.querySelector('[data-hh-export]');
    const searchInput = root.querySelector('[data-hh-search]');
    const zoneSelect = root.querySelector('[data-hh-zone]');
    const streetSelect = root.querySelector('[data-hh-street]');
    const dialog = root.querySelector('[data-hh-dialog]');
    const dialogPanel = root.querySelector('[data-hh-dialog-panel]');
    const cancelBtn = root.querySelector('[data-hh-dialog-cancel]');
    const form = root.querySelector('[data-hh-delete-form]');

    const refresh = () => applyFilters(root);

    void mergePendingLocalHouseholdsIntoList(root, {
        actorId: readPageActorId(root),
        document: typeof document !== 'undefined' ? document : null,
    }).then(() => {
        applyListHouseholdNoDisplay(root);
        refresh();
    }).catch(() => {
        // IndexedDB may be blocked; server rows still work.
    });

    exportBtn?.addEventListener('click', () => {
        const exportUrl = root.dataset.hhExportUrl || '';
        if (!exportUrl) {
            showToast(root, 'Export is not available.');
            return;
        }

        const params = new URLSearchParams();
        const query = (searchInput?.value || '').trim();
        const zone = zoneSelect?.value || 'all';
        const street = streetSelect?.value || 'all';

        if (query) {
            params.set('search', query);
        }
        if (zone && zone !== 'all') {
            params.set('zone', zone);
        }
        if (street && street !== 'all') {
            params.set('street', street);
        }

        const url = params.toString() ? `${exportUrl}?${params}` : exportUrl;
        window.location.assign(url);
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    streetSelect?.addEventListener('change', refresh);

    root.addEventListener('click', (event) => {
        const navLink = event.target.closest(HH_NAV_SELECTOR);
        if (navLink && root.contains(navLink)) {
            void handleHouseholdNavClick(event, {
                root,
                link: navLink,
                toastSelector: '[data-hh-toast]',
            });
            return;
        }

        const actionBtn = event.target.closest('[data-hh-action]');
        if (actionBtn && root.contains(actionBtn)) {
            const action = actionBtn.getAttribute('data-hh-action');
            const householdNo = actionBtn.getAttribute('data-household-no') || 'this household';
            const householdKey = actionBtn.getAttribute('data-household-key') || '';

            if (action === 'add') {
                showToast(
                    root,
                    `Add member to ${householdNo} — demo preview only. Nothing is saved.`
                );
                return;
            }

            if (action === 'delete') {
                openDialog(root, householdNo, actionBtn, householdKey);
            }

            return;
        }

        if (event.target === dialog) {
            closeDialog(root);
        }
    });

    cancelBtn?.addEventListener('click', () => {
        closeDialog(root);
    });

    form?.addEventListener('submit', () => {
        // Allow normal Laravel DELETE form post; unlock scroll before navigation.
        unlockPageScroll();
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && dialog && !dialog.hidden) {
            event.preventDefault();
            closeDialog(root);
            return;
        }

        if (dialog && !dialog.hidden && dialogPanel) {
            trapFocus(event, dialogPanel);
        }
    });

    refresh();
}

/**
 * Report Builder — same behavior as Environmental Health's: zone/search
 * filters, a live preview built as landscape "page" cards (group header
 * row + field header row, one row per household member), then a real PDF
 * export reflecting the current filters.
 */

function parseJsonScript(root, selector) {
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

function readReportOptions(reportRoot) {
    return {
        zone: String(reportRoot.querySelector('[data-hp-report-zone]')?.value || 'all'),
    };
}

function rowMatchesReport(row, options) {
    return options.zone === 'all' || String(row.zone || '') === options.zone;
}

function buildHpExportParams(options) {
    const params = new URLSearchParams();
    if (options.zone && options.zone !== 'all') {
        params.set('zone', options.zone);
    }
    return params;
}

/**
 * Household members ordered Head first, then the rest oldest to youngest,
 * grouped by household_no — mirrors
 * HouseholdProfilingMemberPdf::orderByHouseholdHeadThenAge().
 */
function orderByHouseholdHeadThenAge(zoneRows) {
    const byHousehold = new Map();
    zoneRows.forEach((row) => {
        const key = String(row.household_no || '');
        if (!byHousehold.has(key)) {
            byHousehold.set(key, []);
        }
        byHousehold.get(key).push(row);
    });

    const householdKeys = Array.from(byHousehold.keys()).sort((a, b) =>
        a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })
    );

    const ordered = [];
    householdKeys.forEach((key) => {
        const members = byHousehold.get(key);
        members.sort((a, b) => {
            const aHead = String(a.relationship || '').toLowerCase() === 'head';
            const bHead = String(b.relationship || '').toLowerCase() === 'head';
            if (aHead !== bHead) {
                return aHead ? -1 : 1;
            }
            const aAge = a.age !== '' && Number.isFinite(Number(a.age)) ? Number(a.age) : -1;
            const bAge = b.age !== '' && Number.isFinite(Number(b.age)) ? Number(b.age) : -1;
            return bAge - aAge;
        });
        ordered.push(...members);
    });

    return ordered;
}

function buildHpDataTableHead(sections) {
    const thead = document.createElement('thead');

    const groupRow = document.createElement('tr');
    groupRow.className = 'lml-hp-report__group-row';
    (sections || []).forEach((section) => {
        const th = document.createElement('th');
        th.colSpan = (section.fields || []).length;
        th.textContent = section.title;
        groupRow.appendChild(th);
    });
    thead.appendChild(groupRow);

    const fieldRow = document.createElement('tr');
    fieldRow.className = 'lml-hp-report__field-row';
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

function buildHpDataRow(sections, row) {
    const tr = document.createElement('tr');
    (sections || []).forEach((section) => {
        (section.fields || []).forEach((field) => {
            const td = document.createElement('td');
            td.textContent = row[field.key] || '—';
            tr.appendChild(td);
        });
    });
    return tr;
}

function hpPageContentOverflows(content) {
    return content.scrollHeight > content.clientHeight + 1;
}

/**
 * Renders a zone as a stack of fixed-height, landscape "page" cards, same
 * pagination/continuation-strip behavior as Environmental Health's preview.
 */
function hpHouseholdAndPopulationCounts(rows) {
    const households = new Set(rows.map((row) => String(row.household_no || '')));
    return { households: households.size, population: rows.length };
}

function renderHpZonePages(host, zoneName, zoneRows, sections, overallStats) {
    const zoneWrap = document.createElement('section');
    zoneWrap.className = 'lml-hp-report__zone';
    host.appendChild(zoneWrap);

    const zoneStats = hpHouseholdAndPopulationCounts(zoneRows);

    const zonePages = [];
    let content = null;
    let tbody = null;

    const startPage = (headerBuilder) => {
        const page = document.createElement('div');
        page.className = 'lml-hp-report__page';
        content = document.createElement('div');
        content.className = 'lml-hp-report__page-content';
        if (window.innerWidth > 640) {
            content.style.height = '700px';
            content.style.overflowX = 'auto';
            content.style.overflowY = 'hidden';
        }
        const footer = document.createElement('div');
        footer.className = 'lml-hp-report__page-footer';
        footer.innerHTML = '<span class="lml-hp-report__page-footer-zone"></span><span class="lml-hp-report__page-footer-count"></span>';
        page.appendChild(content);
        page.appendChild(footer);
        zoneWrap.appendChild(page);
        zonePages.push(footer);

        headerBuilder(content);

        const table = document.createElement('table');
        table.className = 'lml-hp-report__data-table';
        table.appendChild(buildHpDataTableHead(sections));
        tbody = document.createElement('tbody');
        table.appendChild(tbody);
        content.appendChild(table);
    };

    startPage((c) => {
        const heading = document.createElement('h3');
        heading.className = 'lml-hp-report__zone-title';
        heading.textContent = `${zoneName.toUpperCase()} REPORT`;
        const rule = document.createElement('hr');
        rule.className = 'lml-hp-report__zone-rule';
        c.appendChild(heading);
        c.appendChild(rule);

        const overallLine = document.createElement('p');
        overallLine.className = 'lml-hp-report__stats-line';
        overallLine.textContent = `Overall Total Household : ${overallStats.households}  ·  Overall Total Population : ${overallStats.population}`;
        c.appendChild(overallLine);

        const zoneNameLine = document.createElement('p');
        zoneNameLine.className = 'lml-hp-report__stats-line lml-hp-report__stats-line--zone-name';
        zoneNameLine.textContent = zoneName;
        c.appendChild(zoneNameLine);

        const zoneStatsLine = document.createElement('p');
        zoneStatsLine.className = 'lml-hp-report__stats-line';
        zoneStatsLine.textContent = `Total Household : ${zoneStats.households}  ·  Total Population : ${zoneStats.population}`;
        c.appendChild(zoneStatsLine);
    });

    const orderedRows = orderByHouseholdHeadThenAge(zoneRows);

    orderedRows.forEach((row) => {
        const hhNo = row.household_no || '—';
        const memberName = row.member_name || '—';

        const tr = buildHpDataRow(sections, row);
        tbody.appendChild(tr);

        if (hpPageContentOverflows(content)) {
            tbody.removeChild(tr);
            startPage((c) => {
                const strip = document.createElement('div');
                strip.className = 'lml-hp-report__continuation-strip';
                strip.textContent = `${zoneName} · HH No. ${hhNo} · ${memberName} (continued)`;
                c.appendChild(strip);
            });
            tbody.appendChild(tr);
        }
    });

    zonePages.forEach((footer, idx) => {
        footer.querySelector('.lml-hp-report__page-footer-zone').textContent = zoneName;
        footer.querySelector('.lml-hp-report__page-footer-count').textContent = `Page ${idx + 1}/${zonePages.length}`;
    });

    return zoneWrap;
}

function renderHpReportPreview(reportRoot, rows, sections, options) {
    const host = reportRoot.querySelector('[data-hp-report-preview]');
    const empty = reportRoot.querySelector('[data-hp-report-empty]');
    const count = reportRoot.querySelector('[data-hp-report-count]');
    const meta = reportRoot.querySelector('[data-hp-report-preview-meta]');
    const exportBase = reportRoot.dataset.exportBase || '';

    if (!host) {
        return;
    }

    const visibleRows = rows.filter((row) => rowMatchesReport(row, options));
    const byZone = {};
    visibleRows.forEach((row) => {
        const zone = String(row.zone || 'Unassigned Zone').trim() || 'Unassigned Zone';
        if (!byZone[zone]) {
            byZone[zone] = [];
        }
        byZone[zone].push(row);
    });

    const zoneNames = Object.keys(byZone).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    const overallStats = hpHouseholdAndPopulationCounts(visibleRows);

    host.replaceChildren();

    zoneNames.forEach((zoneName) => {
        renderHpZonePages(host, zoneName, byZone[zoneName], sections || [], overallStats);
    });

    if (empty) {
        empty.hidden = visibleRows.length > 0;
    }
    if (count) {
        count.textContent = `${visibleRows.length} member${visibleRows.length === 1 ? '' : 's'} in preview`;
    }
    if (meta) {
        const zoneLabel = options.zone !== 'all' ? options.zone : 'All Zones';
        meta.textContent = `Scope: ${zoneLabel} · ${visibleRows.length} member record(s)`;
    }

    const params = buildHpExportParams(options);
    const exportLink = reportRoot.querySelector('[data-hp-export]');
    if (exportLink && exportBase) {
        const query = params.toString();
        exportLink.setAttribute('href', query ? `${exportBase}?${query}` : exportBase);
    }
}

function initHouseholdProfilingReport(reportRoot) {
    const rows = parseJsonScript(reportRoot, '[data-hp-report-rows]') || [];
    const sections = parseJsonScript(reportRoot, '[data-hp-report-sections]') || [];

    const refresh = () => {
        renderHpReportPreview(reportRoot, rows, sections, readReportOptions(reportRoot));
    };

    reportRoot.querySelector('[data-hp-report-zone]')?.addEventListener('change', refresh);

    refresh();
}

if (typeof document !== 'undefined') {
    const boot = () => {
        document.querySelectorAll('[data-lml-hh-profiling]').forEach((root) => {
            initHouseholdProfiling(root);
        });
        document.querySelectorAll('[data-hp-report]').forEach((root) => {
            initHouseholdProfilingReport(root);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}

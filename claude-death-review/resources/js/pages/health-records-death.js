/**
 * Health Records → Death barangay-wide listing.
 * Filters operate on UI-phase fixture rows already rendered in the table.
 * Export is disabled in markup (no destination).
 */

function applyDeathFilters(root) {
    const tbody = root.querySelector('[data-hr-death-tbody]');
    const empty = root.querySelector('[data-hr-death-empty]');
    const emptyTitle = root.querySelector('[data-hr-death-empty-title]');
    const emptyHint = root.querySelector('[data-hr-death-empty-hint]');
    const results = root.querySelector('[data-hr-death-results]');
    const tableScroll = root.querySelector('.lml-hr-death__table-scroll');
    const searchInput = root.querySelector('[data-hr-death-search]');
    const zoneSelect = root.querySelector('[data-hr-death-zone]');
    const causeSelect = root.querySelector('[data-hr-death-cause]');
    const sexSelect = root.querySelector('[data-hr-death-sex]');
    const yearSelect = root.querySelector('[data-hr-death-year]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-death-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const cause = causeSelect?.value || 'all';
    const sex = sexSelect?.value || 'all';
    const year = yearSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowCause = row.dataset.cause || '';
        const rowSex = row.dataset.sex || '';
        const rowYear = row.dataset.year || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesCause = cause === 'all' || rowCause === cause;
        const matchesSex = sex === 'all' || rowSex === sex;
        const matchesYear = year === 'all' || rowYear === year;
        const show = matchesSearch && matchesZone && matchesCause && matchesSex && matchesYear;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} death records`;
    }

    const noDataset = rows.length === 0;
    const noMatches = rows.length > 0 && visible === 0;

    if (emptyTitle) {
        emptyTitle.textContent = noDataset
            ? 'No death records have been recorded yet.'
            : 'No death records match the selected filters.';
    }

    if (emptyHint) {
        emptyHint.textContent = noDataset
            ? 'Select a resident below to submit a death record for Admin verification.'
            : 'Try adjusting search, zone, cause, sex, or year.';
    }

    if (empty) {
        empty.hidden = !(noDataset || noMatches);
    }

    if (tableScroll) {
        tableScroll.hidden = visible === 0;
    }
}

function initResidentSearch(root) {
    const input = root.querySelector('[data-hr-death-resident-search]');
    const rows = Array.from(root.querySelectorAll('[data-hr-death-resident-row]'));
    if (!input || rows.length === 0) {
        return;
    }

    const apply = () => {
        const query = input.value.trim().toLowerCase();
        rows.forEach((row) => {
            const haystack = row.dataset.search || row.dataset.name || '';
            row.hidden = Boolean(query) && !haystack.includes(query);
        });
    };

    input.addEventListener('input', apply);
}

function initHealthRecordsDeath(root) {
    const searchInput = root.querySelector('[data-hr-death-search]');
    const zoneSelect = root.querySelector('[data-hr-death-zone]');
    const causeSelect = root.querySelector('[data-hr-death-cause]');
    const sexSelect = root.querySelector('[data-hr-death-sex]');
    const yearSelect = root.querySelector('[data-hr-death-year]');

    const refresh = () => applyDeathFilters(root);

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    causeSelect?.addEventListener('change', refresh);
    sexSelect?.addEventListener('change', refresh);
    yearSelect?.addEventListener('change', refresh);

    refresh();
    initResidentSearch(root);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-death]').forEach((root) => {
        initHealthRecordsDeath(root);
    });
});

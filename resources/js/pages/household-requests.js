/**
 * Household Requests — Admin list filters (UI only).
 * Demo-only client filtering. View navigates to the details page.
 */

function applyHouseholdRequestFilters(root) {
    const rows = Array.from(root.querySelectorAll('[data-hr-row]'));
    const empty = root.querySelector('[data-hr-empty]');
    const wrap = root.querySelector('[data-hr-table-wrap]');
    const searchInput = root.querySelector('[data-hr-search]');
    const zoneSelect = root.querySelector('[data-hr-zone]');
    const statusSelect = root.querySelector('[data-hr-status]');
    const hasRecords = root.dataset.hasRecords === '1';

    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const status = statusSelect?.value || 'all';
    const normalizedZone = !zone || zone === 'all' ? 'all' : zone;
    const normalizedStatus = !status || status === 'all' ? 'all' : status;

    let visible = 0;

    rows.forEach((row) => {
        const fullName = (row.dataset.hrName || '').toLowerCase();
        const firstName = (row.dataset.hrFirst || '').toLowerCase();
        const middleName = (row.dataset.hrMiddle || '').toLowerCase();
        const lastName = (row.dataset.hrLast || '').toLowerCase();
        const rowZone = row.dataset.hrZone || '';
        const rowStatus = row.dataset.hrStatus || '';

        const matchesSearch = !query
            || fullName.includes(query)
            || firstName.includes(query)
            || middleName.includes(query)
            || lastName.includes(query);
        const matchesZone = normalizedZone === 'all' || rowZone === normalizedZone;
        const matchesStatus = normalizedStatus === 'all' || rowStatus === normalizedStatus;
        const show = matchesSearch && matchesZone && matchesStatus;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (empty) {
        if (!hasRecords) {
            empty.hidden = false;
        } else {
            empty.hidden = visible > 0;
        }
    }

    if (wrap) {
        wrap.hidden = !hasRecords || visible === 0;
    }
}

function initHouseholdRequests(root) {
    const searchInput = root.querySelector('[data-hr-search]');
    const zoneSelect = root.querySelector('[data-hr-zone]');
    const statusSelect = root.querySelector('[data-hr-status]');

    searchInput?.addEventListener('input', () => applyHouseholdRequestFilters(root));
    zoneSelect?.addEventListener('change', () => applyHouseholdRequestFilters(root));
    statusSelect?.addEventListener('change', () => applyHouseholdRequestFilters(root));

    applyHouseholdRequestFilters(root);
}

document.querySelectorAll('[data-lml-household-requests]').forEach((root) => {
    initHouseholdRequests(root);
});

/**
 * Health Records → Family Planning barangay-wide summary.
 * Filters operate on displayed UI-phase fixture rows only.
 * Add / Export use UI-phase toasts (no create route / no download).
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

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsFamilyPlanning(root) {
    const addBtn = root.querySelector('[data-hr-fp-add]');
    const exportBtn = root.querySelector('[data-hr-fp-export]');
    const searchInput = root.querySelector('[data-hr-fp-search]');
    const zoneSelect = root.querySelector('[data-hr-fp-zone]');
    const yearSelect = root.querySelector('[data-hr-fp-year]');

    const refresh = () => applyFamilyPlanningFilters(root);

    addBtn?.addEventListener('click', () => {
        showFamilyPlanningToast(
            root,
            'Individual Family Planning visits are recorded through Household Profiling → selected household member. No barangay-level create route.'
        );
    });

    exportBtn?.addEventListener('click', () => {
        showFamilyPlanningToast(root, 'Export is not available during the UI phase.');
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    yearSelect?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-fp]').forEach((root) => {
        initHealthRecordsFamilyPlanning(root);
    });
});

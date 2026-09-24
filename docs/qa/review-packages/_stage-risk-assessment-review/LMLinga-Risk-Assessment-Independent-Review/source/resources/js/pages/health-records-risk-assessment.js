/**
 * Health Records → Risk Assessment barangay-wide summary.
 * Filters operate on displayed UI-phase fixture rows only.
 * Add / Export use UI-phase toasts (no create route / no download).
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

function applyRiskAssessmentFilters(root) {
    const tbody = root.querySelector('[data-hr-ra-tbody]');
    const empty = root.querySelector('[data-hr-ra-empty]');
    const results = root.querySelector('[data-hr-ra-results]');
    const tableScroll = root.querySelector('.lml-hr-risk__table-scroll');
    const searchInput = root.querySelector('[data-hr-ra-search]');
    const zoneSelect = root.querySelector('[data-hr-ra-zone]');
    const yearSelect = root.querySelector('[data-hr-ra-year]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-ra-row]'));
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
        results.textContent = `Showing ${visible} of ${total} assessed clients`;
    }

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsRiskAssessment(root) {
    const addBtn = root.querySelector('[data-hr-ra-add]');
    const exportBtn = root.querySelector('[data-hr-ra-export]');
    const searchInput = root.querySelector('[data-hr-ra-search]');
    const zoneSelect = root.querySelector('[data-hr-ra-zone]');
    const yearSelect = root.querySelector('[data-hr-ra-year]');

    const refresh = () => applyRiskAssessmentFilters(root);

    addBtn?.addEventListener('click', () => {
        showRiskAssessmentToast(
            root,
            'Individual Risk Assessments are conducted through Household Profiling → selected household member. No barangay-level create route.'
        );
    });

    exportBtn?.addEventListener('click', () => {
        showRiskAssessmentToast(root, 'Export is not available during the UI phase.');
    });

    searchInput?.addEventListener('input', refresh);
    zoneSelect?.addEventListener('change', refresh);
    yearSelect?.addEventListener('change', refresh);

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-risk]').forEach((root) => {
        initHealthRecordsRiskAssessment(root);
    });
});

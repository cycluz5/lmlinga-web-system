/**
 * Health Records → Maternal Care listings.
 * Filters operate on displayed rows only. Add / Export use UI-phase toasts.
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

function applyMaternalFilters(root) {
    const tbody = root.querySelector('[data-hr-mc-tbody]');
    const empty = root.querySelector('[data-hr-mc-empty]');
    const results = root.querySelector('[data-hr-mc-results]');
    const tableScroll = root.querySelector('.lml-hr-mc__table-scroll');
    const searchInput = root.querySelector('[data-hr-mc-search]');
    const zoneSelect = root.querySelector('[data-hr-mc-zone]');
    const barangaySelect = root.querySelector('[data-hr-mc-barangay]');
    const yearSelect = root.querySelector('[data-hr-mc-year]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-mc-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = (searchInput?.value || '').trim().toLowerCase();
    const zone = zoneSelect?.value || 'all';
    const barangay = barangaySelect?.value || 'all';
    const year = yearSelect?.value || 'all';
    const mode = root.dataset.lmlHrMcMode || 'resident';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const rowZone = row.dataset.zone || '';
        const rowBarangay = row.dataset.barangay || '';
        const rowYear = row.dataset.year || '';

        const matchesSearch = !query || name.includes(query);
        const matchesZone = zone === 'all' || rowZone === zone;
        const matchesBarangay = barangay === 'all' || rowBarangay === barangay;
        const matchesYear = year === 'all' || rowYear === year;
        const show = matchesSearch && matchesZone && matchesBarangay && matchesYear;

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

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsMaternal(root) {
    const addBtn = root.querySelector('[data-hr-mc-add]');
    const exportBtn = root.querySelector('[data-hr-mc-export]');

    const refresh = () => applyMaternalFilters(root);

    addBtn?.addEventListener('click', (event) => {
        if (addBtn.tagName === 'A' && addBtn.getAttribute('href')) {
            return;
        }
        event.preventDefault();
        showMaternalToast(
            root,
            'Individual Maternal Care records are recorded through Household Profiling → selected household member. No barangay-level create route.'
        );
    });

    exportBtn?.addEventListener('click', () => {
        showMaternalToast(root, 'Export is not available during the UI phase.');
    });

    root.querySelector('[data-hr-mc-search]')?.addEventListener('input', refresh);
    root.querySelector('[data-hr-mc-zone]')?.addEventListener('change', refresh);
    root.querySelector('[data-hr-mc-barangay]')?.addEventListener('change', refresh);
    root.querySelector('[data-hr-mc-year]')?.addEventListener('change', refresh);

    root.querySelector('[data-hr-mc-nr-add-record]:not([disabled])')?.addEventListener('click', () => {
        showMaternalToast(
            root,
            'Adding a non-resident pregnancy record is not available yet. This does not save into Resident Maternal Care.'
        );
    });

    refresh();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-mc]').forEach((root) => {
        initHealthRecordsMaternal(root);
    });
});

/**
 * Health Records → Child Care → Deworming monitoring summary + record workflow.
 * Filters operate on displayed UI-phase preview rows only.
 * Export / summary Add use UI-phase toasts.
 * Add Record Save uses preview toast (no persistence route yet).
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
        results.textContent = `Showing ${visible} of ${total} children`;
    }

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function initHealthRecordsDeworming(root) {
    const exportBtn = root.querySelector('[data-hr-dw-export]');
    const addBtn = root.querySelector('[data-hr-dw-add]');
    const searchInput = root.querySelector('[data-hr-dw-search]');
    const zoneSelect = root.querySelector('[data-hr-dw-zone]');
    const sexSelect = root.querySelector('[data-hr-dw-sex]');
    const statusSelect = root.querySelector('[data-hr-dw-status]');

    const refresh = () => applyDewormingFilters(root);

    addBtn?.addEventListener('click', () => {
        showDewormingToast(
            root,
            'Open a child\'s Deworming record with View, then use Add Record.'
        );
    });

    exportBtn?.addEventListener('click', () => {
        showDewormingToast(root, 'Export is not available during the UI phase.');
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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-deworming]').forEach((root) => {
        initHealthRecordsDeworming(root);
    });

    document.querySelectorAll('[data-lml-hr-dw-record]').forEach((root) => {
        initDewormingRecordForm(root);
    });
});

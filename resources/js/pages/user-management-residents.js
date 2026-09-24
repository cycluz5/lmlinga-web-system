/**
 * User Management — Residents tab (Admin account CRUD UI).
 * Search/zone filters, client-side pagination, and delete confirmation modal.
 */

const PAGE_SIZE = 5;
const STATE_KEY = 'lml.ra.listState';

function normalizeQuery(value) {
    return (value || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function rowMatches(row, query, zone) {
    const fullName = (row.dataset.residentName || '').toLowerCase();
    const firstName = (row.dataset.residentFirst || '').toLowerCase();
    const middleName = (row.dataset.residentMiddle || '').toLowerCase();
    const lastName = (row.dataset.residentLast || '').toLowerCase();
    const email = (row.dataset.residentEmail || '').toLowerCase();
    const rowZone = row.dataset.residentZone || '';
    const rowZoneLower = rowZone.toLowerCase();

    const matchesSearch = !query
        || fullName.includes(query)
        || firstName.includes(query)
        || middleName.includes(query)
        || lastName.includes(query)
        || email.includes(query)
        || rowZoneLower.includes(query);
    const matchesZone = zone === 'all' || rowZone === zone;

    return matchesSearch && matchesZone;
}

function getMatchingRows(root) {
    const searchInput = root.querySelector('[data-resident-search]');
    const zoneSelect = root.querySelector('[data-resident-zone]');
    const query = normalizeQuery(searchInput?.value || '');
    const zoneValue = zoneSelect?.value || 'all';
    const zone = !zoneValue || zoneValue === 'all' ? 'all' : zoneValue;

    return Array.from(root.querySelectorAll('[data-resident-row]'))
        .filter((row) => rowMatches(row, query, zone));
}

function readPersistedState() {
    try {
        const raw = window.sessionStorage.getItem(STATE_KEY);
        if (!raw) {
            return null;
        }
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function persistState(root, page) {
    const searchInput = root.querySelector('[data-resident-search]');
    const zoneSelect = root.querySelector('[data-resident-zone]');

    try {
        window.sessionStorage.setItem(STATE_KEY, JSON.stringify({
            page,
            search: searchInput?.value || '',
            zone: zoneSelect?.value || 'all',
        }));
    } catch {
        // Ignore storage failures.
    }
}

function setControlEnabled(button, enabled) {
    if (!button) {
        return;
    }

    button.disabled = !enabled;
    if (enabled) {
        button.removeAttribute('aria-disabled');
        button.tabIndex = 0;
    } else {
        button.setAttribute('aria-disabled', 'true');
        button.tabIndex = -1;
    }
}

function renderPagination(root, total, page) {
    const pagination = root.querySelector('[data-resident-pagination]');
    const summary = root.querySelector('[data-resident-page-summary]');
    const numbers = root.querySelector('[data-resident-page-numbers]');
    const prevBtn = root.querySelector('[data-resident-page-prev]');
    const nextBtn = root.querySelector('[data-resident-page-next]');

    if (!pagination || !summary || !numbers) {
        return;
    }

    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const showPagination = total > 0 && totalPages > 1;

    pagination.hidden = !showPagination;

    if (total === 0) {
        summary.textContent = '';
        numbers.innerHTML = '';
        setControlEnabled(prevBtn, false);
        setControlEnabled(nextBtn, false);
        return;
    }

    const start = (page - 1) * PAGE_SIZE + 1;
    const end = Math.min(page * PAGE_SIZE, total);
    summary.textContent = `Showing ${start}–${end} of ${total} residents`;

    if (!showPagination) {
        numbers.innerHTML = '';
        setControlEnabled(prevBtn, false);
        setControlEnabled(nextBtn, false);
        return;
    }

    numbers.innerHTML = '';
    for (let pageNumber = 1; pageNumber <= totalPages; pageNumber += 1) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lml-ra-pagination__page lml-focus-ring';
        button.textContent = String(pageNumber);
        button.dataset.residentPage = String(pageNumber);
        button.setAttribute('aria-label', `Go to residents page ${pageNumber}`);

        if (pageNumber === page) {
            button.classList.add('lml-ra-pagination__page--active');
            button.setAttribute('aria-current', 'page');
        }

        numbers.appendChild(button);
    }

    setControlEnabled(prevBtn, page > 1);
    setControlEnabled(nextBtn, page < totalPages);
}

function applyResidentList(root, { resetPage = false, preferredPage = null } = {}) {
    const rows = Array.from(root.querySelectorAll('[data-resident-row]'));
    const matching = getMatchingRows(root);
    const empty = root.querySelector('[data-resident-empty]');
    const wrap = root.querySelector('[data-resident-table-wrap]');
    const seedEmpty = root.querySelector('[data-resident-seed-empty]');

    let page = preferredPage ?? Number(root.dataset.residentCurrentPage || 1);
    if (resetPage) {
        page = 1;
    }

    const total = matching.length;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (page > totalPages) {
        page = totalPages;
    }
    if (page < 1) {
        page = 1;
    }

    root.dataset.residentCurrentPage = String(page);

    const startIndex = (page - 1) * PAGE_SIZE;
    const endIndex = startIndex + PAGE_SIZE;
    const pageRows = new Set(matching.slice(startIndex, endIndex));

    rows.forEach((row) => {
        row.hidden = !pageRows.has(row);
    });

    if (seedEmpty) {
        seedEmpty.hidden = rows.length > 0 || total > 0;
    }

    if (empty) {
        empty.hidden = total > 0 || rows.length === 0;
    }

    if (wrap) {
        wrap.hidden = total === 0 && rows.length > 0;
        if (rows.length === 0) {
            wrap.hidden = false;
        }
    }

    renderPagination(root, total, page);
    persistState(root, page);
}

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((el) => !el.hasAttribute('disabled') && el.getAttribute('aria-hidden') !== 'true');
}

function initDeleteModal(root) {
    const modal = root.querySelector('[data-resident-delete-modal]');
    const panel = root.querySelector('[data-resident-delete-panel]');
    const form = root.querySelector('[data-resident-delete-form]');
    const nameTarget = modal.querySelector('[data-resident-delete-name-label]');
    const confirmBtn = root.querySelector('[data-resident-delete-confirm]');
    const backdrop = root.querySelector('[data-resident-delete-backdrop]');
    const cancelButtons = Array.from(root.querySelectorAll('[data-resident-delete-cancel]'));

    if (!modal || !panel || !form) {
        return;
    }

    let lastTrigger = null;
    let submitting = false;

    const closeModal = ({ restoreFocus = true } = {}) => {
        modal.hidden = true;
        submitting = false;
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }
        if (restoreFocus && lastTrigger) {
            lastTrigger.focus();
        }
        lastTrigger = null;
    };

    const openModal = (trigger) => {
        const residentId = trigger.dataset.residentDeleteId || '';
        const residentName = trigger.dataset.residentDeleteName || 'this resident';

        lastTrigger = trigger;
        submitting = false;
        if (confirmBtn) {
            confirmBtn.disabled = false;
        }

        form.action = `${form.dataset.residentDestroyBase || '/user-management/residents'}/${encodeURIComponent(residentId)}`;
        if (nameTarget) {
            nameTarget.textContent = residentName;
        }

        modal.hidden = false;
        panel.focus();
    };

    root.addEventListener('click', (event) => {
        const deleteBtn = event.target.closest('[data-resident-delete]');
        if (deleteBtn && root.contains(deleteBtn)) {
            event.preventDefault();
            openModal(deleteBtn);
        }
    });

    cancelButtons.forEach((btn) => {
        btn.addEventListener('click', () => closeModal({ restoreFocus: true }));
    });

    backdrop?.addEventListener('click', () => closeModal({ restoreFocus: true }));

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        if (confirmBtn) {
            confirmBtn.disabled = true;
        }

        // Persist list state so pagination/filters recover after server delete redirect.
        persistState(root, Number(root.dataset.residentCurrentPage || 1));
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal({ restoreFocus: true });
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = getFocusable(panel);
        if (focusable.length === 0) {
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}

function restorePersistedFilters(root) {
    const saved = readPersistedState();
    if (!saved) {
        return 1;
    }

    const searchInput = root.querySelector('[data-resident-search]');
    const zoneSelect = root.querySelector('[data-resident-zone]');

    if (searchInput && typeof saved.search === 'string') {
        searchInput.value = saved.search;
    }
    if (zoneSelect && typeof saved.zone === 'string') {
        zoneSelect.value = saved.zone || 'all';
    }

    const page = Number(saved.page || 1);
    return Number.isFinite(page) && page > 0 ? page : 1;
}

function initResidentManagement(root) {
    const searchInput = root.querySelector('[data-resident-search]');
    const zoneSelect = root.querySelector('[data-resident-zone]');
    const pagination = root.querySelector('[data-resident-pagination]');
    const prevBtn = root.querySelector('[data-resident-page-prev]');
    const nextBtn = root.querySelector('[data-resident-page-next]');
    const numbers = root.querySelector('[data-resident-page-numbers]');

    const preferredPage = restorePersistedFilters(root);

    searchInput?.addEventListener('input', () => {
        applyResidentList(root, { resetPage: true });
    });

    zoneSelect?.addEventListener('change', () => {
        applyResidentList(root, { resetPage: true });
    });

    prevBtn?.addEventListener('click', () => {
        if (prevBtn.disabled) {
            return;
        }
        const page = Number(root.dataset.residentCurrentPage || 1);
        applyResidentList(root, { preferredPage: page - 1 });
    });

    nextBtn?.addEventListener('click', () => {
        if (nextBtn.disabled) {
            return;
        }
        const page = Number(root.dataset.residentCurrentPage || 1);
        applyResidentList(root, { preferredPage: page + 1 });
    });

    numbers?.addEventListener('click', (event) => {
        const pageBtn = event.target.closest('[data-resident-page]');
        if (!pageBtn || !numbers.contains(pageBtn)) {
            return;
        }
        const page = Number(pageBtn.dataset.residentPage || 1);
        applyResidentList(root, { preferredPage: page });
    });

    pagination?.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) {
            return;
        }

        const page = Number(root.dataset.residentCurrentPage || 1);
        if (event.key === 'ArrowLeft' && page > 1) {
            event.preventDefault();
            applyResidentList(root, { preferredPage: page - 1 });
        }
        if (event.key === 'ArrowRight') {
            const total = getMatchingRows(root).length;
            const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
            if (page < totalPages) {
                event.preventDefault();
                applyResidentList(root, { preferredPage: page + 1 });
            }
        }
    });

    initDeleteModal(root);
    applyResidentList(root, { preferredPage });
}

document.querySelectorAll('[data-lml-resident-management]').forEach((root) => {
    initResidentManagement(root);
});

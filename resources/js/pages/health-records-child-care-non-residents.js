/**
 * Health Records → Child Care → Non-Residents (UI preview).
 */

const PREVIEW_SAVE_MESSAGE =
    'Preview only: this non-resident child was not saved to the database. Backend persistence is not yet implemented.';

function showCcNrToast(root, message, warn = false) {
    const toast = root.querySelector('[data-hr-cc-nr-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;
    toast.classList.toggle('lml-hr-cc-nr__toast--warn', Boolean(warn));

    window.clearTimeout(showCcNrToast._timer);
    showCcNrToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
        toast.classList.remove('lml-hr-cc-nr__toast--warn');
    }, 4800);
}

function normalizeFullName(value) {
    return String(value || '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase();
}

function applyListingFilters(root) {
    const tbody = root.querySelector('[data-hr-cc-nr-tbody]');
    const empty = root.querySelector('[data-hr-cc-nr-empty]');
    const results = root.querySelector('[data-hr-cc-nr-results]');
    const tableScroll = root.querySelector('.lml-hr-cc-nr__table-scroll');
    const searchInput = root.querySelector('[data-hr-cc-nr-search]');
    const barangaySelect = root.querySelector('[data-hr-cc-nr-barangay]');
    const yearSelect = root.querySelector('[data-hr-cc-nr-year]');

    if (!tbody) {
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('[data-hr-cc-nr-row]'));
    const total = Number(root.dataset.total || rows.length);
    const query = normalizeFullName(searchInput?.value || '');
    const barangay = barangaySelect?.value || 'all';
    const year = yearSelect?.value || 'all';

    let visible = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const matchesSearch = !query || name.includes(query);
        const matchesBarangay = barangay === 'all' || (row.dataset.barangay || '') === barangay;
        const matchesYear = year === 'all' || (row.dataset.year || '') === year;
        const show = matchesSearch && matchesBarangay && matchesYear;

        row.hidden = !show;
        if (show) {
            visible += 1;
        }
    });

    if (results) {
        results.textContent = `Showing ${visible} of ${total} non-resident children`;
    }

    if (empty) {
        empty.hidden = visible > 0 || rows.length === 0;
    }

    if (tableScroll) {
        tableScroll.hidden = rows.length > 0 && visible === 0;
    }
}

function residentLookup(root) {
    const node = root.querySelector('[data-hr-cc-nr-residents]');
    if (!node) {
        return [];
    }

    try {
        const parsed = JSON.parse(node.textContent || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function findResidentMatch(root, fullName) {
    const normalized = normalizeFullName(fullName);
    if (!normalized) {
        return null;
    }

    return residentLookup(root).find((item) => item.normalized === normalized) || null;
}

function initListing(root) {
    const refresh = () => applyListingFilters(root);
    root.querySelector('[data-hr-cc-nr-search]')?.addEventListener('input', refresh);
    root.querySelector('[data-hr-cc-nr-barangay]')?.addEventListener('change', refresh);
    root.querySelector('[data-hr-cc-nr-year]')?.addEventListener('change', refresh);
    refresh();
}

const PREVIEW_MEASURE_SAVE =
    'Preview only: this measurement was not saved to the database.';

const PREVIEW_DEWORMING_SAVE = 'Deworming record preview saved for this UI phase.';

const PREVIEW_PROFILE_SAVE =
    "Preview only: this child's personal information was not saved to the database.";

function previewReturnUrl(form, preview) {
    const raw = form.getAttribute('data-hr-cc-nr-return') || '';
    try {
        const url = new URL(raw, window.location.origin);
        url.searchParams.set('preview', preview);
        return url.toString();
    } catch {
        return raw;
    }
}

function initPreviewActions(root) {
    root.querySelectorAll('[data-hr-cc-nr-edit], [data-hr-cc-nr-delete]').forEach((button) => {
        button.addEventListener('click', () => {
            const message = button.getAttribute('data-hr-cc-nr-preview') || PREVIEW_SAVE_MESSAGE;
            showCcNrToast(root, message, button.hasAttribute('data-hr-cc-nr-delete'));
        });
    });
}

function initMeasurement(root) {
    const form = root.querySelector('[data-hr-cc-nr-measure-form]');
    if (form instanceof HTMLFormElement) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const message = form.getAttribute('data-hr-cc-nr-preview-save') || PREVIEW_MEASURE_SAVE;
            const next = previewReturnUrl(form, 'saved');
            if (next) {
                window.location.assign(next);
                return;
            }
            showCcNrToast(root, message);
        });
    }

    root.querySelector('[data-hr-cc-nr-measure-delete]')?.addEventListener('click', () => {
        const button = root.querySelector('[data-hr-cc-nr-measure-delete]');
        const message = button?.getAttribute('data-hr-cc-nr-preview') || PREVIEW_MEASURE_SAVE;
        const form = root.querySelector('[data-hr-cc-nr-measure-form]');
        const next = form instanceof HTMLFormElement ? previewReturnUrl(form, 'deleted') : '';
        if (next) {
            window.location.assign(next);
            return;
        }
        showCcNrToast(root, message, true);
    });
}

function initNutritionPreview(root) {
    const params = new URLSearchParams(window.location.search);
    const preview = params.get('preview');
    if (preview === 'saved') {
        showCcNrToast(root, PREVIEW_MEASURE_SAVE);
    }
    if (preview === 'deleted') {
        showCcNrToast(
            root,
            'Preview only: this measurement was not deleted. Backend persistence is not yet implemented.',
            true
        );
    }
}

function initDewormingCreate(root) {
    const form = root.querySelector('[data-hr-cc-nr-deworming-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const next = previewReturnUrl(form, 'saved');
        if (next) {
            window.location.assign(next);
            return;
        }
        showCcNrToast(root, form.getAttribute('data-hr-cc-nr-preview-save') || PREVIEW_DEWORMING_SAVE);
    });
}

function initDewormingPreview(root) {
    const params = new URLSearchParams(window.location.search);
    if (params.get('preview') === 'saved') {
        showCcNrToast(root, PREVIEW_DEWORMING_SAVE);
    }
}

function initEditProfile(root) {
    const form = root.querySelector('[data-hr-cc-nr-edit-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const next = previewReturnUrl(form, 'saved');
        if (next) {
            window.location.assign(next);
            return;
        }
        showCcNrToast(root, form.getAttribute('data-hr-cc-nr-preview-save') || PREVIEW_PROFILE_SAVE);
    });
}

function initShowPreview(root) {
    const params = new URLSearchParams(window.location.search);
    if (params.get('preview') === 'saved') {
        showCcNrToast(root, PREVIEW_PROFILE_SAVE);
    }
}

function initCreate(root) {
    const form = root.querySelector('[data-hr-cc-nr-create-form]');
    const duplicate = root.querySelector('[data-hr-cc-nr-duplicate]');
    const hint = root.querySelector('[data-hr-cc-nr-duplicate-hint]');
    const link = root.querySelector('[data-hr-cc-nr-duplicate-link]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const first = root.querySelector('[data-hr-cc-nr-first-name]')?.value || '';
        const middle = root.querySelector('[data-hr-cc-nr-middle-name]')?.value || '';
        const last = root.querySelector('[data-hr-cc-nr-last-name]')?.value || '';
        const fullName = [first, middle, last]
            .map((part) => String(part).trim())
            .filter(Boolean)
            .join(' ');

        const match = findResidentMatch(root, fullName);

        if (duplicate) {
            duplicate.hidden = !match;
        }

        if (match) {
            if (hint) {
                hint.textContent = `Matched household member “${match.full_name}”. A duplicate non-resident record was not created.`;
            }
            if (link instanceof HTMLAnchorElement) {
                if (match.view_url) {
                    link.href = match.view_url;
                    link.hidden = false;
                } else {
                    link.hidden = true;
                }
            }
            showCcNrToast(
                root,
                'This child appears to already exist in Household Profiling. No non-resident record was created.',
                true
            );
            return;
        }

        if (duplicate) {
            duplicate.hidden = true;
        }
        showCcNrToast(root, PREVIEW_SAVE_MESSAGE);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hr-cc-nr]').forEach((root) => {
        const mode = root.getAttribute('data-lml-hr-cc-nr-mode');
        if (mode === 'listing') {
            initListing(root);
        }
        if (mode === 'create') {
            initCreate(root);
        }
        if (mode === 'edit-profile') {
            initEditProfile(root);
        }
        if (mode === 'show') {
            initShowPreview(root);
        }
        if (
            mode === 'show'
            || mode === 'nutrition'
            || mode === 'measurement'
            || mode === 'deworming'
            || mode === 'deworming-create'
            || mode === 'immunization'
            || mode === 'sbi'
            || mode === 'child-nutrition'
            || mode === 'birth-history'
        ) {
            initPreviewActions(root);
        }
        if (mode === 'nutrition') {
            initNutritionPreview(root);
        }
        if (mode === 'measurement') {
            initMeasurement(root);
        }
        if (mode === 'deworming') {
            initDewormingPreview(root);
        }
        if (mode === 'deworming-create') {
            initDewormingCreate(root);
        }
    });
});

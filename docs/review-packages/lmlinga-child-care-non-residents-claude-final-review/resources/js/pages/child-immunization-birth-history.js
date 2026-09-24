/**
 * Birth History dedicated edit page — preview-safe Save (no server write).
 *
 * Saves optional field values to sessionStorage for the current browser session,
 * then returns to Child Immunization. Close is a named-route link (no save).
 *
 * Escape matches Close: navigate via the Close link href without writing
 * sessionStorage or submitting the form (discard unsaved edits).
 */

const STORAGE_PREFIX = 'lml.birthHistory.preview.';
const PREVIEW_SAVE_MESSAGE =
    'Preview only: Birth History changes were not permanently saved.';
const EMPTY_RECORD = 'No record';

const PCAB_LABELS = {
    at_least_2_doses_1_month_prior:
        'At least 2 doses received at least 1 month prior to delivery',
    tt3_td3_to_tt5_td5_prior:
        'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
};

function storageKey(householdNo, memberId) {
    return `${STORAGE_PREFIX}${householdNo}.${memberId}`;
}

function readPreview(householdNo, memberId) {
    try {
        const raw = window.sessionStorage.getItem(storageKey(householdNo, memberId));
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

function writePreview(householdNo, memberId, values, { announce = false } = {}) {
    try {
        window.sessionStorage.setItem(
            storageKey(householdNo, memberId),
            JSON.stringify({
                weight: String(values.weight ?? ''),
                length: String(values.length ?? ''),
                pcab: String(values.pcab ?? ''),
                breastfeeding_date: String(values.breastfeeding_date ?? ''),
                announce: Boolean(announce),
                message: PREVIEW_SAVE_MESSAGE,
            })
        );
    } catch {
        // sessionStorage may be unavailable; navigation still proceeds.
    }
}

function snapshotBirthForm(form) {
    /** @type {Record<string, string>} */
    const values = {};
    form.querySelectorAll('[data-child-imm-birth-field]').forEach((field) => {
        const key = field.getAttribute('data-child-imm-birth-field');
        if (!key) {
            return;
        }
        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
            values[key] = String(field.value ?? '');
        }
    });
    return values;
}

function applyPreviewToForm(form, preview) {
    if (!preview) {
        return;
    }

    form.querySelectorAll('[data-child-imm-birth-field]').forEach((field) => {
        const key = field.getAttribute('data-child-imm-birth-field');
        if (!key || !(key in preview)) {
            return;
        }
        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement) {
            field.value = String(preview[key] ?? '');
        }
    });
}

function displayOrEmpty(value) {
    const trimmed = String(value ?? '').trim();
    return trimmed === '' ? EMPTY_RECORD : trimmed;
}

function formatPcabSummary(value) {
    const trimmed = String(value ?? '').trim();
    if (trimmed === '') {
        return EMPTY_RECORD;
    }
    return PCAB_LABELS[trimmed] || trimmed;
}

function applyPreviewToSummary(root, preview) {
    if (!preview) {
        return;
    }

    const summary = root.querySelector('[data-child-imm-birth-summary]');
    if (!summary) {
        return;
    }

    const weight = summary.querySelector('[data-birth-summary="weight"]');
    const length = summary.querySelector('[data-birth-summary="length"]');
    const pcab = summary.querySelector('[data-birth-summary="pcab"]');

    if (weight) {
        weight.textContent = displayOrEmpty(preview.weight);
    }
    if (length) {
        length.textContent = displayOrEmpty(preview.length);
    }
    if (pcab) {
        pcab.textContent = formatPcabSummary(preview.pcab);
    }
}

/**
 * Navigate using the Close control’s existing href (same as Close; no save).
 * @param {HTMLElement} root
 */
function navigateClose(root) {
    const closeLink = root.querySelector('a[data-child-imm-birth-close]');
    if (!(closeLink instanceof HTMLAnchorElement) || !closeLink.href) {
        return;
    }
    window.location.assign(closeLink.href);
}

function initBirthHistoryEdit(root) {
    if (!(root instanceof HTMLElement) || root.dataset.bhEditReady === 'true') {
        return;
    }
    root.dataset.bhEditReady = 'true';

    const householdNo = root.getAttribute('data-household-no') || '';
    const memberId = root.getAttribute('data-member-id') || '';
    const returnUrl = root.getAttribute('data-return-url') || '';
    const form = root.querySelector('[data-child-imm-birth-form]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const preview = readPreview(householdNo, memberId);
    applyPreviewToForm(form, preview);
    applyPreviewToSummary(root, preview);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const values = snapshotBirthForm(form);
        writePreview(householdNo, memberId, values, { announce: true });

        if (returnUrl) {
            window.location.assign(returnUrl);
        }
    });

    // Escape ≡ Close: discard unsaved edits (no sessionStorage write / no submit).
    // Ignore modifiers, IME composition, key-repeat, and focused native <select>.
    const onKeyDown = (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (event.repeat || event.isComposing) {
            return;
        }
        if (event.ctrlKey || event.altKey || event.metaKey) {
            return;
        }
        if (!document.body.contains(root)) {
            return;
        }
        if (document.activeElement instanceof HTMLSelectElement) {
            return;
        }

        event.preventDefault();
        navigateClose(root);
    };

    document.addEventListener('keydown', onKeyDown);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-bh-edit]').forEach(initBirthHistoryEdit);
});

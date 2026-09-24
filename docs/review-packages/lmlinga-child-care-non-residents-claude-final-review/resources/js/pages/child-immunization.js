/**
 * Child Immunization destination — inline Immunization edit mode (UI preview).
 *
 * Persistence is intentionally out of scope: there is no approved save
 * endpoint or data model for this page. Save keeps values in the current
 * browser-page session only and shows the project toast. It does not claim
 * server persistence.
 *
 * Birth History editing lives on a dedicated page. This script only restores
 * preview summary values from sessionStorage after a preview Save return.
 *
 * Does not sync Vaccines Type checkboxes with date inputs.
 */

const PREVIEW_SAVE_MESSAGE =
    'Preview only: immunization changes were kept on this page and were not permanently saved.';
const BIRTH_HISTORY_PREVIEW_MESSAGE =
    'Preview only: Birth History changes were not permanently saved.';
const EMPTY_RECORD = 'No record';
const BIRTH_STORAGE_PREFIX = 'lml.birthHistory.preview.';

const PCAB_LABELS = {
    at_least_2_doses_1_month_prior:
        'At least 2 doses received at least 1 month prior to delivery',
    tt3_td3_to_tt5_td5_prior:
        'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
};

function showToast(root, message) {
    const toast = root.querySelector('[data-child-imm-toast]');
    if (!toast) {
        return;
    }

    toast.textContent = message;
    toast.hidden = false;

    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => {
        toast.hidden = true;
        toast.textContent = '';
    }, 4200);
}

function getImmunizationForm(root) {
    return root.querySelector('[data-child-imm-immunization]');
}

function getEditableFields(form) {
    return form.querySelectorAll('[data-child-imm-field]');
}

/**
 * Prefer the dashboard content scroller when present so Edit/Save
 * does not jump the page when the nested panel owns overflow.
 */
function captureScrollPosition() {
    const content = document.querySelector('.lml-dashboard__content');
    if (content instanceof HTMLElement && content.scrollHeight > content.clientHeight + 2) {
        return { type: 'element', el: content, top: content.scrollTop };
    }

    return {
        type: 'window',
        el: null,
        top: window.scrollY || document.documentElement.scrollTop || 0,
    };
}

function restoreScrollPosition(snapshot) {
    if (!snapshot) {
        return;
    }

    if (snapshot.type === 'element' && snapshot.el instanceof HTMLElement) {
        snapshot.el.scrollTop = snapshot.top;
        return;
    }

    window.scrollTo(0, snapshot.top);
}

function setFieldsEditable(form, enabled) {
    getEditableFields(form).forEach((field) => {
        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        field.disabled = !enabled;
        if (field.type === 'date') {
            field.readOnly = !enabled;
            if (enabled) {
                field.removeAttribute('readonly');
            } else {
                field.setAttribute('readonly', 'readonly');
            }
        }
    });
}

function setEditMode(form, editing) {
    const editBtn = form.querySelector('[data-child-imm-edit="immunization"]');
    const saveBtn = form.querySelector('[data-child-imm-save]');

    form.dataset.editing = editing ? 'true' : 'false';
    setFieldsEditable(form, editing);

    if (editBtn instanceof HTMLElement) {
        editBtn.hidden = editing;
    }

    if (saveBtn instanceof HTMLElement) {
        saveBtn.hidden = !editing;
    }
}

function enterEditMode(form) {
    const scroll = captureScrollPosition();
    setEditMode(form, true);

    const saveBtn = form.querySelector('[data-child-imm-save]');
    if (saveBtn instanceof HTMLElement) {
        saveBtn.focus({ preventScroll: true });
    }

    restoreScrollPosition(scroll);
}

function exitEditMode(form, { focusEdit = true } = {}) {
    const scroll = captureScrollPosition();
    setEditMode(form, false);

    if (focusEdit) {
        const editBtn = form.querySelector('[data-child-imm-edit="immunization"]');
        if (editBtn instanceof HTMLElement) {
            editBtn.focus({ preventScroll: true });
        }
    }

    restoreScrollPosition(scroll);
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

function updateBirthSummary(root, values) {
    const summary = root.querySelector('[data-child-imm-birth-summary]');
    if (!summary || !values) {
        return;
    }

    const weight = summary.querySelector('[data-birth-summary="weight"]');
    const length = summary.querySelector('[data-birth-summary="length"]');
    const pcab = summary.querySelector('[data-birth-summary="pcab"]');

    if (weight) {
        weight.textContent = displayOrEmpty(values.weight);
    }
    if (length) {
        length.textContent = displayOrEmpty(values.length);
    }
    if (pcab) {
        pcab.textContent = formatPcabSummary(values.pcab);
    }
}

function readBirthPreview(householdNo, memberId) {
    try {
        const raw = window.sessionStorage.getItem(
            `${BIRTH_STORAGE_PREFIX}${householdNo}.${memberId}`
        );
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

function clearBirthPreviewAnnounce(householdNo, memberId, preview) {
    if (!preview) {
        return;
    }

    try {
        window.sessionStorage.setItem(
            `${BIRTH_STORAGE_PREFIX}${householdNo}.${memberId}`,
            JSON.stringify({
                ...preview,
                announce: false,
            })
        );
    } catch {
        // ignore
    }
}

function applyBirthPreviewSummary(root) {
    const householdNo = root.getAttribute('data-household-no') || '';
    const memberId = root.getAttribute('data-member-id') || '';
    const preview = readBirthPreview(householdNo, memberId);
    if (!preview) {
        return;
    }

    updateBirthSummary(root, preview);

    if (preview.announce) {
        showToast(root, preview.message || BIRTH_HISTORY_PREVIEW_MESSAGE);
        clearBirthPreviewAnnounce(householdNo, memberId, preview);
    }
}

function initChildImmunization(root) {
    if (!(root instanceof HTMLElement) || root.dataset.childImmReady === 'true') {
        return;
    }
    root.dataset.childImmReady = 'true';

    const form = getImmunizationForm(root);
    if (form instanceof HTMLFormElement) {
        setEditMode(form, false);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            showToast(root, PREVIEW_SAVE_MESSAGE);
            exitEditMode(form, { focusEdit: true });
        });
    }

    root.addEventListener('click', (event) => {
        const editBtn = event.target.closest('[data-child-imm-edit]');
        if (!editBtn || !root.contains(editBtn)) {
            return;
        }

        const target = editBtn.getAttribute('data-child-imm-edit') || '';

        if (target === 'immunization' && form instanceof HTMLFormElement) {
            enterEditMode(form);
        }
    });

    applyBirthPreviewSummary(root);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-child-imm]').forEach(initChildImmunization);
});

/**
 * Child Nutrition destination — inline Edit/Save (UI preview).
 *
 * Persistence is intentionally out of scope: there is no approved save
 * endpoint or data model for this page. Save keeps values in the current
 * browser-page session only and shows the project toast. It does not claim
 * server persistence.
 *
 * Birth History editing lives on the dedicated Child Immunization Birth
 * History page. This script only restores preview summary values from
 * sessionStorage after a preview Save return.
 *
 * New Born anthropometric status IS derived locally from member sex +
 * Weight at Birth + Length at Birth using approved age-0 thresholds.
 * It does NOT auto-derive Iron/Vitamin A/MNP/LNS-SQ COMPLETED badges,
 * MAM/SAM states, or the Child Nutrition Status panel.
 * Does not sync the status panel from form fields.
 * Does not auto-fill New Born fields from Birth History.
 */

const PREVIEW_SAVE_MESSAGE =
    'Preview only: child nutrition changes were kept on this page and were not permanently saved.';
const BIRTH_HISTORY_PREVIEW_MESSAGE =
    'Preview only: Birth History changes were not permanently saved.';
const EMPTY_RECORD = 'No record';
const BIRTH_STORAGE_PREFIX = 'lml.birthHistory.preview.';

/** Approved age-0 continuous thresholds (kg / cm). */
export const NEWBORN_THRESHOLDS = {
    male: {
        weight: { belowMax: 2.5, aboveMin: 4.4 },
        height: { belowMax: 46.1, aboveMin: 53.7 },
    },
    female: {
        weight: { belowMax: 2.4, aboveMin: 4.2 },
        height: { belowMax: 45.4, aboveMin: 52.9 },
    },
};

const PCAB_LABELS = {
    at_least_2_doses_1_month_prior:
        'At least 2 doses received at least 1 month prior to delivery',
    tt3_td3_to_tt5_td5_prior:
        'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
};

const NEWBORN_STATUS_UI = {
    normal: {
        result: 'normal',
        label: 'NORMAL',
        className: 'lml-child-nut__demo-status--normal',
        icon: 'bi-check-lg',
    },
    below_normal: {
        result: 'below_normal',
        label: 'BELOW NORMAL',
        className: 'lml-child-nut__demo-status--below',
        icon: 'bi-exclamation-lg',
    },
    above_normal: {
        result: 'above_normal',
        label: 'ABOVE NORMAL',
        className: 'lml-child-nut__demo-status--above',
        icon: 'bi-exclamation-lg',
    },
    no_record: {
        result: 'no_record',
        label: 'No record',
        className: 'lml-child-nut__demo-status--empty',
        icon: 'bi-dash-lg',
    },
};

/**
 * Map member sex display values to threshold keys.
 * Unknown / empty → null (never guess Male/Female).
 */
export function normalizeNewbornSex(sex) {
    const raw = String(sex ?? '')
        .trim()
        .toLowerCase();
    if (raw === 'male' || raw === 'm' || raw === 'boy' || raw === 'boys') {
        return 'male';
    }
    if (raw === 'female' || raw === 'f' || raw === 'girl' || raw === 'girls') {
        return 'female';
    }
    return null;
}

/**
 * Parse a numeric anthropometric field. Empty / non-numeric → null.
 */
export function parseAnthropometricValue(raw) {
    if (raw === null || raw === undefined) {
        return null;
    }
    const trimmed = String(raw).trim();
    if (trimmed === '') {
        return null;
    }
    const value = Number(trimmed);
    if (!Number.isFinite(value)) {
        return null;
    }
    return value;
}

/**
 * Classify a single metric against inclusive Normal band.
 * belowMax is exclusive lower bound for Normal (value < belowMax → Below).
 * aboveMin is inclusive upper bound for Normal (value > aboveMin → Above).
 */
export function classifyNewbornMetric(value, band) {
    if (value === null) {
        return null;
    }
    if (value < band.belowMax) {
        return 'below_normal';
    }
    if (value > band.aboveMin) {
        return 'above_normal';
    }
    return 'normal';
}

/**
 * Overall result precedence:
 * 1. any Below → Below Normal
 * 2. else any Above → Above Normal
 * 3. else both Normal → Normal
 */
export function combineNewbornStatuses(weightStatus, heightStatus) {
    if (!weightStatus || !heightStatus) {
        return 'no_record';
    }
    if (weightStatus === 'below_normal' || heightStatus === 'below_normal') {
        return 'below_normal';
    }
    if (weightStatus === 'above_normal' || heightStatus === 'above_normal') {
        return 'above_normal';
    }
    return 'normal';
}

/**
 * Derive New Born statuses from sex + weight (kg) + height/length (cm).
 * Missing sex or either measurement → overall no_record.
 */
export function deriveNewbornStatus({ sex, weightKg, heightCm }) {
    const sexKey = normalizeNewbornSex(sex);
    const weight = parseAnthropometricValue(weightKg);
    const height = parseAnthropometricValue(heightCm);

    if (!sexKey || weight === null || height === null) {
        return {
            sexKey,
            weightStatus: sexKey && weight !== null
                ? classifyNewbornMetric(weight, NEWBORN_THRESHOLDS[sexKey].weight)
                : null,
            heightStatus: sexKey && height !== null
                ? classifyNewbornMetric(height, NEWBORN_THRESHOLDS[sexKey].height)
                : null,
            overall: 'no_record',
        };
    }

    const bands = NEWBORN_THRESHOLDS[sexKey];
    const weightStatus = classifyNewbornMetric(weight, bands.weight);
    const heightStatus = classifyNewbornMetric(height, bands.height);

    return {
        sexKey,
        weightStatus,
        heightStatus,
        overall: combineNewbornStatuses(weightStatus, heightStatus),
    };
}

function showToast(root, message) {
    const toast = root.querySelector('[data-child-nut-toast]');
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

function getRecordsForm(root) {
    return root.querySelector('[data-child-nut-records]');
}

function getEditableFields(form) {
    return form.querySelectorAll('[data-child-nut-field]');
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

        if (field.type === 'date' || field.type === 'number' || field.type === 'text') {
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
    const editBtn = form.querySelector('[data-child-nut-edit]');
    const saveBtn = form.querySelector('[data-child-nut-save]');

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

    const saveBtn = form.querySelector('[data-child-nut-save]');
    if (saveBtn instanceof HTMLElement) {
        saveBtn.focus({ preventScroll: true });
    }

    restoreScrollPosition(scroll);
}

function exitEditMode(form, { focusEdit = true } = {}) {
    const scroll = captureScrollPosition();
    setEditMode(form, false);

    if (focusEdit) {
        const editBtn = form.querySelector('[data-child-nut-edit]');
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
    const summary = root.querySelector('[data-child-nut-birth-summary]');
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

function getNewbornStatusUi(overall) {
    return NEWBORN_STATUS_UI[overall] || NEWBORN_STATUS_UI.no_record;
}

/**
 * Paint the New Born status chip from current field values + member sex.
 * Does not announce on every keystroke (no live-region spam).
 */
export function updateNewbornStatusDisplay(root) {
    const chip = root.querySelector('[data-child-nut-newborn-status]');
    if (!(chip instanceof HTMLElement)) {
        return;
    }

    const weightInput = root.querySelector('#lml-child-nut-nb-weight');
    const heightInput = root.querySelector('#lml-child-nut-nb-length');
    const sex = root.getAttribute('data-member-sex') || '';

    const derived = deriveNewbornStatus({
        sex,
        weightKg: weightInput instanceof HTMLInputElement ? weightInput.value : '',
        heightCm: heightInput instanceof HTMLInputElement ? heightInput.value : '',
    });

    const ui = getNewbornStatusUi(derived.overall);
    chip.dataset.result = ui.result;
    chip.className = `lml-child-nut__demo-status ${ui.className}`;

    let icon = chip.querySelector('i[aria-hidden="true"]');
    if (!(icon instanceof HTMLElement)) {
        icon = document.createElement('i');
        icon.setAttribute('aria-hidden', 'true');
        chip.prepend(icon);
    }
    icon.className = `bi ${ui.icon}`;

    let label = chip.querySelector('[data-child-nut-newborn-status-label]');
    if (!(label instanceof HTMLElement)) {
        label = document.createElement('span');
        label.setAttribute('data-child-nut-newborn-status-label', '');
        chip.appendChild(label);
    }
    label.textContent = ui.label;
}

function bindNewbornStatusUpdates(root) {
    const weightInput = root.querySelector('#lml-child-nut-nb-weight');
    const heightInput = root.querySelector('#lml-child-nut-nb-length');

    const refresh = () => updateNewbornStatusDisplay(root);

    [weightInput, heightInput].forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
    });

    refresh();
}

function initChildNutrition(root) {
    if (!(root instanceof HTMLElement) || root.dataset.childNutReady === 'true') {
        return;
    }
    root.dataset.childNutReady = 'true';

    const form = getRecordsForm(root);
    if (form instanceof HTMLFormElement) {
        setEditMode(form, false);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            showToast(root, PREVIEW_SAVE_MESSAGE);
            exitEditMode(form, { focusEdit: true });
            updateNewbornStatusDisplay(root);
        });
    }

    root.addEventListener('click', (event) => {
        const editBtn = event.target.closest('[data-child-nut-edit]');
        if (!editBtn || !root.contains(editBtn)) {
            return;
        }

        if (form instanceof HTMLFormElement) {
            enterEditMode(form);
        }
    });

    bindNewbornStatusUpdates(root);
    applyBirthPreviewSummary(root);
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-lml-child-nut]').forEach(initChildNutrition);
    });
}

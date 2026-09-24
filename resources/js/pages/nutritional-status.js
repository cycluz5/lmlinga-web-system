/**
 * Household Profiling — Nutritional Status "Add Measurement" form.
 *
 * Two layers:
 *   1. Instant, purely client-side band preview (age label, MUAC/BMI
 *      enable-disable and section visibility) — mirrors the age-band
 *      boundaries used by NutritionAssessmentService (0-5 months, 6-59
 *      months, 5-19 years, adult/elderly at 19y+) so the form reacts the
 *      instant the operator changes the date, with no round trip.
 *   2. A debounced fetch to the server's read-only preview endpoint
 *      (data-preview-url), which reuses the SAME authoritative
 *      NutritionAssessmentService::assess() used on save, to fill in the
 *      actual Weight-for-Age / MUAC status / BMI status / Overall status
 *      values. Nothing is ever classified in this file — layer 2 only
 *      displays what the server computed. Saving always re-derives
 *      everything server-side regardless of what was last previewed here.
 */

export const ADULT_MIN_AGE_YEARS = 19; // mirrors HealthRecordsRiskAssessment::MIN_AGE_YEARS

export const BAND_INFANT = '0-5m';
export const BAND_CHILD = '6-59m';
export const BAND_ADOLESCENT = '5-19y';
export const BAND_ADULT = 'adult';

const PREVIEW_DEBOUNCE_MS = 350;

export function parseIsoDate(value) {
    if (!value || typeof value !== 'string') {
        return null;
    }
    const match = value.trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) {
        return null;
    }
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(date.getTime()) ? null : date;
}

export function completedMonths(birth, on) {
    if (!birth || !on || birth > on) {
        return null;
    }
    let months = (on.getFullYear() - birth.getFullYear()) * 12 + (on.getMonth() - birth.getMonth());
    if (on.getDate() < birth.getDate()) {
        months -= 1;
    }
    return Math.max(0, months);
}

export function completedYears(birth, on) {
    if (!birth || !on || birth > on) {
        return null;
    }
    let years = on.getFullYear() - birth.getFullYear();
    const beforeAnniversary =
        on.getMonth() < birth.getMonth() ||
        (on.getMonth() === birth.getMonth() && on.getDate() < birth.getDate());
    if (beforeAnniversary) {
        years -= 1;
    }
    return Math.max(0, years);
}

export function ageBand(ageMonths, ageYears) {
    if (ageMonths == null) {
        return null;
    }
    if (ageMonths < 6) {
        return BAND_INFANT;
    }
    if (ageMonths < 60) {
        return BAND_CHILD;
    }
    return ageYears != null && ageYears >= ADULT_MIN_AGE_YEARS ? BAND_ADULT : BAND_ADOLESCENT;
}

export function formatAgeLabel(ageMonths, ageYears) {
    if (ageMonths == null) {
        return 'Age not recorded';
    }
    if (ageMonths < 24) {
        return `${ageMonths} ${ageMonths === 1 ? 'Month' : 'Months'}`;
    }
    const years = ageYears != null ? ageYears : Math.floor(ageMonths / 12);
    return `${years} ${years === 1 ? 'Year' : 'Years'}`;
}

export function computeBmi(weightKg, heightCm) {
    const weight = Number(weightKg);
    const height = Number(heightCm);
    if (!Number.isFinite(weight) || !Number.isFinite(height) || weight <= 0 || height <= 0) {
        return null;
    }
    const heightM = height / 100;
    return Math.round((weight / (heightM * heightM)) * 10) / 10;
}

function setValue(root, selector, value) {
    const el = root.querySelector(selector);
    if (el) {
        el.value = value == null || value === '' ? '—' : String(value);
    }
}

function applyBand(root, band) {
    const muacInput = root.querySelector('[data-timbang-muac]');
    const muacHelper = root.querySelector('[data-timbang-muac-helper]');
    const muacStatusField = root.querySelector('[data-timbang-muac-status-field]');
    const childIndicators = root.querySelector('[data-timbang-child-indicators]');
    const bmiField = root.querySelector('[data-timbang-bmi-field]');
    const bmiPendingNote = root.querySelector('[data-timbang-bmi-pending]');

    const muacApplicable = band === BAND_CHILD;
    if (muacInput) {
        muacInput.disabled = !muacApplicable;
        if (!muacApplicable && muacInput.value !== '') {
            muacInput.value = '';
        }
    }
    if (muacHelper) {
        if (band === BAND_INFANT) {
            muacHelper.textContent = 'Not applicable below 6 months.';
            muacHelper.hidden = false;
        } else if (band === BAND_ADOLESCENT || band === BAND_ADULT) {
            muacHelper.textContent = 'Not applicable for residents 5 years and older — BMI is used instead.';
            muacHelper.hidden = false;
        } else if (band === BAND_CHILD) {
            muacHelper.textContent = '';
            muacHelper.hidden = true;
        } else {
            muacHelper.textContent = 'MUAC becomes available at 6 months.';
            muacHelper.hidden = false;
        }
    }
    if (muacStatusField) {
        muacStatusField.hidden = !muacApplicable;
    }

    // Weight-for-Age/Height-for-Age apply 0-59 months; BMI applies 5-19y/adult.
    // Mutually exclusive by band — never both shown at once.
    const childApplicable = band === BAND_INFANT || band === BAND_CHILD;
    if (childIndicators) {
        childIndicators.hidden = !childApplicable;
    }

    const bmiApplicable = band === BAND_ADOLESCENT || band === BAND_ADULT;
    if (bmiField) {
        bmiField.hidden = !bmiApplicable;
    }
    if (bmiPendingNote) {
        bmiPendingNote.hidden = band !== BAND_ADOLESCENT;
    }
}

function refreshBmiPreview(root) {
    const weightInput = root.querySelector('[name="weight_kg"]');
    const heightInput = root.querySelector('[name="height_cm"]');
    const bmiOutput = root.querySelector('[data-timbang-bmi-value]');
    if (!bmiOutput) {
        return;
    }
    const bmi = computeBmi(weightInput?.value, heightInput?.value);
    bmiOutput.value = bmi == null ? '—' : bmi.toFixed(1);
}

function currentBand(root, birth) {
    const dateInput = root.querySelector('[name="measurement_date"]');
    const on = parseIsoDate(dateInput?.value) || new Date();
    const months = completedMonths(birth, on);
    const years = completedYears(birth, on);
    return { band: ageBand(months, years), months, years };
}

function refreshAgePreview(root, birth) {
    const ageLabelEl = root.querySelector('[data-timbang-age-label]');
    const { band, months, years } = currentBand(root, birth);

    if (ageLabelEl) {
        ageLabelEl.textContent = formatAgeLabel(months, years);
    }
    applyBand(root, band);
}

async function fetchAssessmentPreview(root) {
    const previewUrl = root.getAttribute('data-preview-url');
    if (!previewUrl) {
        return;
    }

    const dateInput = root.querySelector('[name="measurement_date"]');
    const weightInput = root.querySelector('[name="weight_kg"]');
    const heightInput = root.querySelector('[name="height_cm"]');
    const muacInput = root.querySelector('[data-timbang-muac]');

    const params = new URLSearchParams();
    if (dateInput?.value) {
        params.set('measurement_date', dateInput.value);
    }
    if (weightInput?.value) {
        params.set('weight_kg', weightInput.value);
    }
    if (heightInput?.value) {
        params.set('height_cm', heightInput.value);
    }
    if (muacInput?.value && !muacInput.disabled) {
        params.set('muac_cm', muacInput.value);
    }

    let response;
    try {
        response = await fetch(`${previewUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
    } catch {
        return; // offline or network error — leave last-known values in place
    }
    if (!response.ok) {
        return;
    }

    let data;
    try {
        data = await response.json();
    } catch {
        return;
    }
    if (!data || data.found === false) {
        return;
    }

    setValue(root, '[data-timbang-wfa-value]', data.weight_for_age);
    setValue(root, '[data-timbang-hfa-value]', data.height_for_age);
    setValue(root, '[data-timbang-muac-status-value]', data.muac_status);
    setValue(root, '[data-timbang-bmi-value]', data.bmi_value);
    setValue(root, '[data-timbang-bmi-status-value]', data.bmi_status);
    setValue(root, '[data-timbang-overall-value]', data.overall_nutritional_status);
}

function debounce(fn, wait) {
    let timer = null;
    return (...args) => {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(() => fn(...args), wait);
    };
}

export function initNutritionalStatusForm(root) {
    if (!root) {
        return;
    }

    const birth = parseIsoDate(root.getAttribute('data-resident-birthday'));
    const dateInput = root.querySelector('[name="measurement_date"]');
    const weightInput = root.querySelector('[name="weight_kg"]');
    const heightInput = root.querySelector('[name="height_cm"]');
    const muacInput = root.querySelector('[data-timbang-muac]');

    const debouncedPreview = debounce(() => fetchAssessmentPreview(root), PREVIEW_DEBOUNCE_MS);

    const refresh = () => {
        refreshAgePreview(root, birth);
        refreshBmiPreview(root);
        debouncedPreview();
    };

    dateInput?.addEventListener('change', refresh);
    weightInput?.addEventListener('input', refresh);
    heightInput?.addEventListener('input', refresh);
    muacInput?.addEventListener('input', refresh);

    refreshAgePreview(root, birth);
    refreshBmiPreview(root);
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-lml-hh-timbang-form]').forEach(initNutritionalStatusForm);
    });
}

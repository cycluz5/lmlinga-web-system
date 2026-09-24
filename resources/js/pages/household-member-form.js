/**
 * Household Profiling — Add/Edit Member form (DB-05 Phase 4).
 * Client-side validation. Real MB-* members persist through Laravel.
 * Unsynced MB-L-* members stay in the offline queue even when online.
 */

import { handleSupportedFormSubmit } from '../offline/offline-forms.js';

const FIELD_LABELS = {
    last_name: 'Last Name',
    first_name: 'First Name',
    relation: 'Relation to Household Head',
    birthday: 'Birthday',
    sex: 'Sex',
    relationship_status: 'Relationship Status',
    occupation: 'Occupation',
    occupation_other: 'Occupation — Other',
    monthly_income: 'Monthly Income',
    religion: 'Religion',
    religion_other: 'Religion — Other',
    education: 'Educational Attainment',
    philhealth: 'PhilHealth Number',
    fp_user: 'Family Planning (FP) User',
    disability: 'Disability Type',
    disability_others: 'Disability — Others',
    medical_history: 'Medical History',
    medical_others: 'Medical History — Others',
};

function showToast(root, message) {
    const toast = root.querySelector('[data-hh-member-toast]');
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

function getFocusable(container) {
    return Array.from(
        container.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function lockScroll() {
    document.body.dataset.hhMemberScrollLocked = 'true';
    document.body.style.overflow = 'hidden';
}

function unlockScroll() {
    if (document.body.dataset.hhMemberScrollLocked !== 'true') {
        return;
    }
    delete document.body.dataset.hhMemberScrollLocked;
    document.body.style.overflow = '';
}

function todayIso() {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

function trimValue(value) {
    return String(value ?? '').trim();
}

function clearFieldError(root, key) {
    const wrap = root.querySelector(`[data-field="${key}"]`);
    const err = root.querySelector(`#err-${key}`);
    if (wrap) {
        wrap.classList.remove('is-invalid-group');
    }
    if (err) {
        err.hidden = true;
        err.textContent = '';
    }

    const control = wrap?.querySelector('.lml-hh-member-form__control, select, input[type="text"], input[type="date"]');
    if (control) {
        control.classList.remove('is-invalid');
        control.removeAttribute('aria-invalid');
        const describedby = control.getAttribute('aria-describedby');
        if (describedby) {
            const next = describedby
                .split(/\s+/)
                .filter((id) => id && id !== `err-${key}`)
                .join(' ');
            if (next) {
                control.setAttribute('aria-describedby', next);
            } else {
                control.removeAttribute('aria-describedby');
            }
        }
    }

    wrap?.querySelectorAll('input[type="checkbox"]').forEach((box) => {
        box.removeAttribute('aria-invalid');
    });
}

function setFieldError(root, key, message) {
    const wrap = root.querySelector(`[data-field="${key}"]`);
    const err = root.querySelector(`#err-${key}`);
    if (!err) {
        return;
    }

    err.textContent = message;
    err.hidden = false;

    const control = wrap?.querySelector(
        'input.lml-hh-member-form__control, select.lml-hh-member-form__control, input[type="text"], input[type="date"], select'
    );

    if (key === 'disability' || key === 'medical_history') {
        wrap?.classList.add('is-invalid-group');
        wrap?.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.setAttribute('aria-invalid', 'true');
        });
        return;
    }

    if (control) {
        control.classList.add('is-invalid');
        control.setAttribute('aria-invalid', 'true');
        const existing = control.getAttribute('aria-describedby');
        const ids = new Set((existing || '').split(/\s+/).filter(Boolean));
        ids.add(`err-${key}`);
        control.setAttribute('aria-describedby', Array.from(ids).join(' '));
    }
}

function checkedValues(root, name) {
    return Array.from(root.querySelectorAll(`input[name="${name}[]"]:checked`)).map((el) => el.value);
}

function isFormDirty(form, baseline = null) {
    if (baseline !== null) {
        return serializeFormState(form) !== baseline;
    }

    const fields = Array.from(form.elements).filter(
        (el) => el.name && !el.disabled && el.type !== 'submit' && el.type !== 'button'
    );

    return fields.some((el) => {
        if (el.type === 'checkbox' || el.type === 'radio') {
            return el.checked;
        }
        return trimValue(el.value) !== '';
    });
}

/**
 * Stable serialization for dirty comparison (edit mode baseline).
 */
function serializeFormState(form) {
    const parts = [];

    Array.from(form.elements)
        .filter((el) => el.name && !el.disabled && el.type !== 'submit' && el.type !== 'button')
        .forEach((el) => {
            if (el.type === 'checkbox' || el.type === 'radio') {
                parts.push(`${el.name}=${el.checked ? el.value : ''}`);
                return;
            }
            parts.push(`${el.name}=${trimValue(el.value)}`);
        });

    return parts.join('&');
}

function syncSelectOthersVisibility(root, select, { clearHidden = true } = {}) {
    const othersKey = select.getAttribute('data-hh-other-select');
    const othersWrap = othersKey ? root.querySelector(`[data-field="${othersKey}"]`) : null;
    if (!othersWrap) {
        return;
    }

    const show = trimValue(select.value) === 'Other';
    othersWrap.hidden = !show;
    const input = othersWrap.querySelector('input');
    if (input) {
        input.disabled = !show;
        if (!show && clearHidden) {
            input.value = '';
        }
    }
    if (!show) {
        clearFieldError(root, othersKey);
    }
}

function syncOthersVisibility(root, groupName, { clearHidden = true } = {}) {
    const othersBox = root.querySelector(
        `[data-hh-check-group="${groupName}"] input[data-hh-others-toggle]`
    );
    const othersKey = othersBox?.getAttribute('data-hh-others-toggle');
    const othersWrap = othersKey ? root.querySelector(`[data-field="${othersKey}"]`) : null;
    if (!othersWrap) {
        return;
    }

    const show = Boolean(othersBox?.checked);
    othersWrap.hidden = !show;
    const input = othersWrap.querySelector('input');
    if (input) {
        input.disabled = !show;
        if (!show && clearHidden) {
            input.value = '';
        }
    }
    if (!show) {
        clearFieldError(root, othersKey);
    }
}

function handleNoneExclusive(fieldset, changed) {
    const none = fieldset.querySelector('input[data-hh-none]');
    const others = Array.from(fieldset.querySelectorAll('input[type="checkbox"]')).filter(
        (el) => el !== none
    );

    if (!none) {
        return;
    }

    if (changed === none && none.checked) {
        others.forEach((el) => {
            el.checked = false;
        });
        return;
    }

    if (changed !== none && changed.checked) {
        none.checked = false;
    }
}

function validateForm(root, form) {
    const errors = [];

    const requireText = (key, message) => {
        const el = form.elements.namedItem(key);
        const value = trimValue(el?.value);
        if (el && 'value' in el) {
            el.value = value;
        }
        if (!value) {
            errors.push({ key, message });
        }
    };

    const requireSelect = (key, message) => {
        const el = form.elements.namedItem(key);
        if (!trimValue(el?.value)) {
            errors.push({ key, message });
        }
    };

    requireText('last_name', 'Last Name is required.');
    requireText('first_name', 'First Name is required.');
    requireSelect('relation', 'Please select a relationship to the household head.');

    const birthday = form.elements.namedItem('birthday');
    const birthdayVal = trimValue(birthday?.value);
    if (!birthdayVal) {
        errors.push({ key: 'birthday', message: 'Birthday is required.' });
    } else if (birthdayVal > todayIso()) {
        errors.push({ key: 'birthday', message: 'Birthday cannot be a future date.' });
    }

    requireSelect('sex', 'Please select a sex.');
    requireSelect('relationship_status', 'Relationship Status is required.');
    requireSelect('occupation', 'Occupation is required.');
    const occupation = trimValue(form.elements.namedItem('occupation')?.value);
    if (occupation === 'Other') {
        const other = trimValue(form.elements.namedItem('occupation_other')?.value);
        const otherEl = form.elements.namedItem('occupation_other');
        if (otherEl) {
            otherEl.value = other;
        }
        if (!other) {
            errors.push({ key: 'occupation_other', message: 'Please specify the occupation.' });
        }
    }

    requireSelect('monthly_income', 'Monthly Income is required.');
    requireSelect('religion', 'Religion is required.');
    const religion = trimValue(form.elements.namedItem('religion')?.value);
    if (religion === 'Other') {
        const other = trimValue(form.elements.namedItem('religion_other')?.value);
        const otherEl = form.elements.namedItem('religion_other');
        if (otherEl) {
            otherEl.value = other;
        }
        if (!other) {
            errors.push({ key: 'religion_other', message: 'Please specify the religion.' });
        }
    }
    requireSelect('education', 'Educational Attainment is required.');

    const philhealthEl = form.elements.namedItem('philhealth');
    const philhealth = trimValue(philhealthEl?.value).replace(/\s+/g, '');
    if (philhealthEl) {
        philhealthEl.value = philhealth;
    }
    if (philhealth !== '' && !/^\d{12}$/.test(philhealth)) {
        errors.push({
            key: 'philhealth',
            message: 'PhilHealth Number should be 12 digits for this UI preview.',
        });
    }

    requireSelect('fp_user', 'Please select a Family Planning (FP) User option.');

    const disability = checkedValues(root, 'disability');
    if (!disability.length) {
        errors.push({
            key: 'disability',
            message: 'Please choose at least one disability option or None.',
        });
    } else if (disability.includes('others')) {
        const other = trimValue(form.elements.namedItem('disability_others')?.value);
        const otherEl = form.elements.namedItem('disability_others');
        if (otherEl) {
            otherEl.value = other;
        }
        if (!other) {
            errors.push({ key: 'disability_others', message: 'Please specify the disability type.' });
        }
    }

    const medical = checkedValues(root, 'medical_history');
    if (!medical.length) {
        errors.push({
            key: 'medical_history',
            message: 'Please choose at least one medical history option or None.',
        });
    } else if (medical.includes('others')) {
        const other = trimValue(form.elements.namedItem('medical_others')?.value);
        const otherEl = form.elements.namedItem('medical_others');
        if (otherEl) {
            otherEl.value = other;
        }
        if (!other) {
            errors.push({
                key: 'medical_others',
                message: 'Please specify the medical history condition.',
            });
        }
    }

    return errors;
}

function clearAllErrors(root) {
    Object.keys(FIELD_LABELS).forEach((key) => clearFieldError(root, key));
    const summary = root.querySelector('[data-hh-member-summary]');
    const list = root.querySelector('[data-hh-member-summary-list]');
    if (summary) {
        summary.hidden = true;
    }
    if (list) {
        list.innerHTML = '';
    }
}

function showErrors(root, errors) {
    clearAllErrors(root);

    errors.forEach(({ key, message }) => setFieldError(root, key, message));

    const summary = root.querySelector('[data-hh-member-summary]');
    const list = root.querySelector('[data-hh-member-summary-list]');
    if (summary && list) {
        list.innerHTML = '';
        errors.forEach(({ key, message }) => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = `#${focusTargetId(root, key)}`;
            a.textContent = message;
            a.addEventListener('click', (event) => {
                event.preventDefault();
                focusField(root, key);
            });
            li.appendChild(a);
            list.appendChild(li);
        });
        summary.hidden = false;
        summary.focus();
    }

    if (errors[0]) {
        // Keep summary focused first; field remains easy via summary links.
    }
}

function focusTargetId(root, key) {
    const map = {
        last_name: 'lml-hh-last-name',
        first_name: 'lml-hh-first-name',
        middle_name: 'lml-hh-middle-name',
        relation: 'lml-hh-relation',
        birthday: 'lml-hh-birthday',
        sex: 'lml-hh-sex',
        relationship_status: 'lml-hh-relationship-status',
        occupation: 'lml-hh-occupation',
        occupation_other: 'lml-hh-occupation-other',
        monthly_income: 'lml-hh-monthly-income',
        religion: 'lml-hh-religion',
        religion_other: 'lml-hh-religion-other',
        education: 'lml-hh-education',
        philhealth: 'lml-hh-philhealth',
        fp_user: 'lml-hh-fp-user',
        disability: 'lml-hh-disability-first',
        disability_others: 'lml-hh-disability-others',
        medical_history: 'lml-hh-medical-history-first',
        medical_others: 'lml-hh-medical-others',
    };
    return map[key] || '';
}

/**
 * True when the element can meaningfully receive keyboard focus
 * (native controls, links with href, or explicit tabindex — including -1).
 * Do not treat "typeof el.focus === 'function'" as sufficient: every HTMLElement has .focus().
 */
function isMeaningfullyFocusable(el) {
    if (!(el instanceof HTMLElement)) {
        return false;
    }

    if (el.hasAttribute('disabled') || el.getAttribute('aria-disabled') === 'true') {
        return false;
    }

    const tag = el.tagName;
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'BUTTON') {
        return true;
    }

    if (tag === 'A' && el.hasAttribute('href')) {
        return true;
    }

    if (el.hasAttribute('tabindex')) {
        const ti = Number(el.getAttribute('tabindex'));
        return Number.isFinite(ti);
    }

    return false;
}

function focusField(root, key) {
    const id = focusTargetId(root, key);
    const el = id ? document.getElementById(id) : null;
    if (isMeaningfullyFocusable(el)) {
        el.focus();
        return;
    }

    const wrap = root.querySelector(`[data-field="${key}"]`);
    if (!wrap) {
        return;
    }

    const groupControl = wrap.querySelector(
        'input[type="checkbox"], input[type="radio"], input, select, textarea, button, a[href], [tabindex]'
    );
    if (isMeaningfullyFocusable(groupControl)) {
        groupControl.focus();
    }
}

function openDiscardDialog(root, returnFocusEl) {
    const backdrop = root.querySelector('[data-hh-member-dialog]');
    const panel = root.querySelector('[data-hh-member-dialog-panel]');
    const continueBtn = root.querySelector('[data-hh-member-dialog-continue]');
    if (!backdrop || !panel) {
        return;
    }

    root._hhMemberReturnFocus = returnFocusEl || null;
    backdrop.hidden = false;
    lockScroll();
    continueBtn?.focus();
}

function closeDiscardDialog(root, { restoreFocus = true } = {}) {
    const backdrop = root.querySelector('[data-hh-member-dialog]');
    if (!backdrop) {
        return;
    }

    backdrop.hidden = true;
    unlockScroll();

    if (restoreFocus && root._hhMemberReturnFocus instanceof HTMLElement) {
        root._hhMemberReturnFocus.focus();
    }
    root._hhMemberReturnFocus = null;
}

function trapFocus(event, panel) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusables = getFocusable(panel);
    if (!focusables.length) {
        event.preventDefault();
        panel.focus();
        return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
        return;
    }

    if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function leaveToView(root) {
    const url = root.dataset.viewUrl;
    if (url) {
        window.location.href = url;
    }
}

function initMemberForm(root) {
    const form = root.querySelector('[data-hh-member-form-el]');
    if (!form) {
        return;
    }

    const mode = root.dataset.mode === 'edit' ? 'edit' : 'create';
    const cancelBtn = root.querySelector('[data-hh-member-cancel]');
    const backLink = root.querySelector('[data-hh-member-back]');
    const dialog = root.querySelector('[data-hh-member-dialog]');
    const dialogPanel = root.querySelector('[data-hh-member-dialog-panel]');
    const continueBtn = root.querySelector('[data-hh-member-dialog-continue]');
    const discardBtn = root.querySelector('[data-hh-member-dialog-discard]');
    const birthday = root.querySelector('[data-hh-max-today]');

    if (birthday) {
        birthday.setAttribute('max', todayIso());
    }

    // Preserve prefilled "Others" text when syncing visibility on edit load.
    root.querySelectorAll('[data-hh-check-group]').forEach((fieldset) => {
        const group = fieldset.getAttribute('data-hh-check-group');
        if (group) {
            syncOthersVisibility(root, group, { clearHidden: false });
        }
    });

    root.querySelectorAll('[data-hh-other-select]').forEach((select) => {
        syncSelectOthersVisibility(root, select, { clearHidden: false });
        select.addEventListener('change', () => {
            syncSelectOthersVisibility(root, select);
        });
    });

    const dirtyBaselineHolder = { value: mode === 'edit' ? serializeFormState(form) : null };
    root._hhMemberDirtyBaseline = dirtyBaselineHolder;

    root.addEventListener('lmlinga:member-edit-hydrated', () => {
        root.querySelectorAll('[data-hh-other-select]').forEach((select) => {
            syncSelectOthersVisibility(root, select, { clearHidden: false });
        });
        root.querySelectorAll('[data-hh-check-group]').forEach((fieldset) => {
            const group = fieldset.getAttribute('data-hh-check-group');
            if (group) {
                syncOthersVisibility(root, group, { clearHidden: false });
            }
        });
        if (mode === 'edit') {
            dirtyBaselineHolder.value = serializeFormState(form);
            root._hhMemberDirtyBaseline = dirtyBaselineHolder;
        }
    });

    root.querySelectorAll('[data-hh-check-group]').forEach((fieldset) => {
        fieldset.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox') {
                return;
            }

            handleNoneExclusive(fieldset, target);
            const group = fieldset.getAttribute('data-hh-check-group');
            if (group) {
                syncOthersVisibility(root, group);
                clearFieldError(root, group);
            }
        });
    });

    form.addEventListener('input', (event) => {
        const el = event.target;
        if (!(el instanceof HTMLElement)) {
            return;
        }
        const wrap = el.closest('[data-field]');
        const key = wrap?.getAttribute('data-field');
        if (key) {
            clearFieldError(root, key);
        }
    });

    form.addEventListener('change', (event) => {
        const el = event.target;
        if (!(el instanceof HTMLElement)) {
            return;
        }
        const wrap = el.closest('[data-field]');
        const key = wrap?.getAttribute('data-field');
        if (key) {
            clearFieldError(root, key);
        }
    });

    form.addEventListener('submit', (event) => {
        const errors = validateForm(root, form);
        if (errors.length) {
            event.preventDefault();
            showErrors(root, errors);
            return;
        }

        clearAllErrors(root);
        const memberId = String(
            root.getAttribute('data-member-id')
            || form.getAttribute('data-offline-parent-member-no')
            || '',
        ).trim();
        if (/^MB-L-[A-Za-z0-9]+$/i.test(memberId)) {
            event.preventDefault();
            void handleSupportedFormSubmit(event, {
                window,
                navigator,
                root: form.closest('[data-lml-offline-root]') || root,
            });
        }
    }, true);

    const requestLeave = (triggerEl) => {
        if (isFormDirty(form, dirtyBaselineHolder.value)) {
            openDiscardDialog(root, triggerEl);
            return;
        }
        leaveToView(root);
    };

    cancelBtn?.addEventListener('click', () => requestLeave(cancelBtn));

    backLink?.addEventListener('click', (event) => {
        if (isFormDirty(form, dirtyBaselineHolder.value)) {
            event.preventDefault();
            requestLeave(backLink);
        }
    });

    continueBtn?.addEventListener('click', () => closeDiscardDialog(root));
    discardBtn?.addEventListener('click', () => {
        closeDiscardDialog(root, { restoreFocus: false });
        leaveToView(root);
    });

    root.addEventListener('click', (event) => {
        if (event.target === dialog) {
            closeDiscardDialog(root);
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && dialog && !dialog.hidden) {
            event.preventDefault();
            closeDiscardDialog(root);
            return;
        }

        if (dialog && !dialog.hidden && dialogPanel) {
            trapFocus(event, dialogPanel);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-lml-hh-member-form]').forEach((root) => {
        initMemberForm(root);
    });
});
